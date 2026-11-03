<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\RdoDiario;
use App\Models\Tenant;
use App\Support\RdoPdfTemplates;
use App\Support\RdoWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RdoDocumentLogos
{
    public static function rules(): array
    {
        $rules = [];
        foreach (array_keys(RdoWorkflow::PARTIES) as $role) {
            $rules['logo_'.$role] = ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'];
            $rules['remove_logo_'.$role] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    public function current(Tenant $tenant, Contract $contract): array
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        $config = RdoPdfTemplates::forContract($tenant, $contract->id);
        if (isset($config['logos'])) {
            return $config['logos'];
        }
        $contract->loadMissing(['clienteEmpresa', 'gerenciadoraEmpresa', 'construtoraEmpresa']);

        return collect(['cliente' => $contract->clienteEmpresa, 'gerenciadora' => $contract->gerenciadoraEmpresa, 'construtora' => $contract->construtoraEmpresa])
            ->map(fn ($company) => $company && (int) $company->tenant_id === (int) $tenant->id && $company->logo_path
                ? ['disk' => 'public', 'path' => $company->logo_path, 'name' => $company->nome] : null)->all();
    }

    public function snapshot(Tenant $tenant, Contract $contract): array
    {
        $logos = $this->current($tenant, $contract);
        foreach ($logos as $role => $logo) {
            $bytes = $this->bytes($logo);
            $logos[$role] = $bytes ? $this->store($tenant, $contract->id, $bytes, $logo['name'] ?? $role) : null;
        }

        return $logos;
    }

    private function store(Tenant $tenant, int $contractId, string $bytes, string $name): array
    {
        $info = @getimagesizefromstring($bytes);
        $ext = match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', default => null
        };
        if (! $ext) {
            throw new \RuntimeException('A logo deve ser uma imagem PNG, JPG ou WebP válida.');
        }
        $path = 'rdo/pdf-logos/tenant-'.$tenant->id.'/contract-'.$contractId.'/'.hash('sha256', $bytes).'.'.$ext;
        if (! Storage::disk('local')->exists($path) && ! Storage::disk('local')->put($path, $bytes)) {
            throw new \RuntimeException('Não foi possível preservar a logo do RDO.');
        }

        return ['disk' => 'local', 'path' => $path, 'name' => $name];
    }

    public function bytes(?array $logo): ?string
    {
        if (! $logo || empty($logo['path']) || ! in_array($logo['disk'] ?? '', ['public', 'local'], true)) {
            return null;
        }

        return Storage::disk($logo['disk'])->exists($logo['path']) ? Storage::disk($logo['disk'])->get($logo['path']) : null;
    }

    public function dataUri(?array $logo): ?string
    {
        $bytes = $this->bytes($logo);
        $info = $bytes ? @getimagesizefromstring($bytes) : false;

        return $info ? 'data:'.$info['mime'].';base64,'.base64_encode($bytes) : null;
    }

    public function apply(Request $request, Tenant $tenant, Contract $contract, bool $persist): array
    {
        $logos = $persist ? $this->snapshot($tenant, $contract) : $this->current($tenant, $contract);
        foreach (array_keys(RdoWorkflow::PARTIES) as $role) {
            if ($request->boolean('remove_logo_'.$role)) {
                $logos[$role] = null;
            }
            if ($file = $request->file('logo_'.$role)) {
                $bytes = file_get_contents($file->getRealPath());
                $logos[$role] = $persist ? $this->store($tenant, $contract->id, $bytes, $file->getClientOriginalName())
                    : ['data_uri' => 'data:'.$file->getMimeType().';base64,'.base64_encode($bytes), 'name' => $file->getClientOriginalName()];
            }
        }

        return $logos;
    }

    public function freezeContract(Tenant $tenant, Contract $contract): void
    {
        $snapshot = null;
        RdoDiario::withTrashed()->where('tenant_id', $tenant->id)->where('contract_id', $contract->id)->orderBy('id')->chunkById(100, function ($diaries) use ($tenant, $contract, &$snapshot) {
            foreach ($diaries as $rdo) {
                if (array_key_exists('logos', $rdo->pdf_configuration_snapshot ?? [])) {
                    continue;
                }
                $snapshot ??= $this->snapshot($tenant, $contract);
                $configuration = [...RdoPdfTemplates::forDiary($rdo), 'logos' => $snapshot];
                DB::table('rdo_diarios')->where('id', $rdo->id)->where('tenant_id', $tenant->id)->update(['pdf_configuration_snapshot' => json_encode($configuration, JSON_THROW_ON_ERROR)]);
            }
        });
    }
}
