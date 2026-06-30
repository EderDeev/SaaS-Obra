<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\RdaApontamento;
use App\Models\RdoDiario;
use App\Models\RdoEquipamentoCadastro;
use App\Models\RdoMaoObraCadastro;
use App\Models\RdoResponsavel;
use App\Models\RdoSubcontratadaCadastro;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileRdaController extends Controller
{
    public function storeOffline(Request $request): JsonResponse
    {
        $tenant = $this->mobileTenant($request);

        $data = $request->validate([
            'local_uuid' => ['required', 'string', 'max:120'],
            'rdo_diario_id' => ['required', Rule::exists('rdo_diarios', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'obra_id' => ['required', Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'reference_date' => ['required', 'date_format:Y-m-d'],
            'dados' => ['nullable', 'array'],
            'dados.atividades' => ['nullable', 'array'],
            'dados.atividades.*.titulo' => ['nullable', 'string', 'max:180'],
            'dados.atividades.*.ocorrencia' => ['nullable', 'string', 'max:2000'],
            'dados.clima' => ['nullable', 'array'],
            'dados.clima.manha' => ['nullable', 'string', 'max:80'],
            'dados.clima.tarde' => ['nullable', 'string', 'max:80'],
            'dados.clima.noite' => ['nullable', 'string', 'max:80'],
            'dados.clima.precipitacao_manha_mm' => ['nullable', 'numeric', 'min:0'],
            'dados.clima.precipitacao_tarde_mm' => ['nullable', 'numeric', 'min:0'],
            'dados.clima.precipitacao_noite_mm' => ['nullable', 'numeric', 'min:0'],
            'dados.clima.dia_impraticavel' => ['nullable', 'boolean'],
            'dados.mao_obra' => ['nullable', 'array'],
            'dados.mao_obra.*.cadastro_id' => ['nullable', 'integer'],
            'dados.mao_obra.*.descricao' => ['nullable', 'string', 'max:255'],
            'dados.mao_obra.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'dados.equipamentos' => ['nullable', 'array'],
            'dados.equipamentos.*.cadastro_id' => ['nullable', 'integer'],
            'dados.equipamentos.*.descricao' => ['nullable', 'string', 'max:255'],
            'dados.equipamentos.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'dados.subcontratadas' => ['nullable', 'array'],
            'dados.subcontratadas.*.cadastro_id' => ['nullable', 'integer'],
            'dados.subcontratadas.*.descricao' => ['nullable', 'string', 'max:255'],
            'dados.subcontratadas.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'dados.fotos' => ['nullable', 'array'],
            'photos' => ['nullable', 'array', 'max:20'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'photo_comments' => ['nullable', 'array'],
            'photo_comments.*' => ['nullable', 'string', 'max:1000'],
            'photo_positions' => ['nullable', 'array'],
            'photo_positions.*' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $rdo = RdoDiario::query()
            ->with('configuracao')
            ->where('tenant_id', $tenant->id)
            ->whereKey($data['rdo_diario_id'])
            ->firstOrFail();

        abort_unless((int) $rdo->obra_id === (int) $data['obra_id'], 422, 'A obra informada não pertence ao RDO selecionado.');
        abort_unless($rdo->reference_date?->format('Y-m-d') === $data['reference_date'], 422, 'A data informada não pertence ao RDO selecionado.');
        abort_unless($this->canFillRda($request, $tenant, $rdo), 403, 'Usuário sem responsabilidade para preencher o RDA desta frente.');

        $dados = $this->normalizeData($data['dados'] ?? []);

        $rda = DB::transaction(function () use ($tenant, $request, $rdo, $dados): RdaApontamento {
            $apontamento = RdaApontamento::query()->firstOrNew([
                'tenant_id' => $tenant->id,
                'contract_id' => $rdo->contract_id,
                'obra_id' => $rdo->obra_id,
                'reference_date' => $rdo->reference_date?->format('Y-m-d'),
            ]);

            abort_if($apontamento->exists && $apontamento->status === 'publicado', 422, 'Este RDA já foi publicado no sistema web.');

            $apontamento->fill([
                'rdo_configuracao_id' => $rdo->rdo_configuracao_id,
                'rdo_diario_id' => $rdo->id,
                'created_by_id' => $apontamento->created_by_id ?: $request->user()?->id,
                'updated_by_id' => $request->user()?->id,
                'status' => 'rascunho',
                'dados' => $dados,
            ]);
            $apontamento->save();

            return $apontamento;
        });

        $dados['fotos'] = $this->preparePhotoData(
            $tenant,
            $rda,
            $dados['fotos'] ?? $this->emptyPhotoData(),
            $request->file('photos', []),
            $request->input('photo_comments', []),
            $request->input('photo_positions', []),
        );

        $rda->update([
            'dados' => $dados,
            'updated_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'RDA sincronizado com sucesso.',
            'rda' => [
                'id' => $rda->id,
                'status' => $rda->status,
                'photos_count' => count($dados['fotos']['arquivos'] ?? []),
            ],
        ], 201);
    }

    public static function bootstrapPayload(Tenant $tenant, mixed $user): array
    {
        $responsaveis = RdoResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('modulo', 'rda')
            ->where('etapa', 'campo')
            ->where('status', 'active')
            ->where('user_id', $user?->id)
            ->get(['contract_id', 'obra_id']);

        $obraIds = $responsaveis->pluck('obra_id')->unique()->values();
        $contractIds = $responsaveis->pluck('contract_id')->unique()->values();

        $rdos = RdoDiario::query()
            ->with(['obra:id,codigo,nome', 'contract:id,code,name'])
            ->where('tenant_id', $tenant->id)
            ->whereIn('obra_id', $obraIds)
            ->whereIn('contract_id', $contractIds)
            ->whereBetween('reference_date', [now()->subDays(15)->toDateString(), now()->addDays(15)->toDateString()])
            ->orderByDesc('reference_date')
            ->get(['id', 'tenant_id', 'rdo_configuracao_id', 'contract_id', 'obra_id', 'code', 'reference_date', 'status'])
            ->map(fn (RdoDiario $rdo): array => [
                'id' => $rdo->id,
                'rdo_configuracao_id' => $rdo->rdo_configuracao_id,
                'contract_id' => $rdo->contract_id,
                'obra_id' => $rdo->obra_id,
                'code' => $rdo->code,
                'reference_date' => $rdo->reference_date?->format('Y-m-d'),
                'status' => $rdo->status,
                'obra_label' => trim(($rdo->obra?->codigo ? "{$rdo->obra->codigo} - " : '').($rdo->obra?->nome ?? '')),
                'contract_label' => trim(($rdo->contract?->code ? "{$rdo->contract->code} - " : '').($rdo->contract?->name ?? '')),
            ]);

        return [
            'rda_rdos' => $rdos,
            'rda_mao_obra' => RdoMaoObraCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->where('active', true)
                ->orderBy('tipo')
                ->orderBy('descricao')
                ->get(['id', 'descricao', 'tipo', 'unidade'])
                ->map(fn (RdoMaoObraCadastro $item): array => [
                    'id' => $item->id,
                    'label' => $item->descricao,
                    'meta' => ucfirst($item->tipo).' · '.$item->unidade,
                ]),
            'rda_equipamentos' => RdoEquipamentoCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->where('active', true)
                ->orderBy('descricao')
                ->get(['id', 'codigo', 'descricao', 'unidade', 'propriedade'])
                ->map(fn (RdoEquipamentoCadastro $item): array => [
                    'id' => $item->id,
                    'label' => trim(($item->codigo ? "{$item->codigo} - " : '').$item->descricao),
                    'meta' => ucfirst($item->propriedade).' · '.$item->unidade,
                ]),
            'rda_subcontratadas' => RdoSubcontratadaCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->where('active', true)
                ->orderBy('razao_social')
                ->get(['id', 'razao_social', 'nome_fantasia', 'cnpj'])
                ->map(fn (RdoSubcontratadaCadastro $item): array => [
                    'id' => $item->id,
                    'label' => $item->nome_fantasia ?: $item->razao_social,
                    'meta' => $item->cnpj,
                ]),
        ];
    }

    private function normalizeData(array $data): array
    {
        return [
            'clima' => [
                'manha' => trim((string) data_get($data, 'clima.manha', '')),
                'tarde' => trim((string) data_get($data, 'clima.tarde', '')),
                'noite' => trim((string) data_get($data, 'clima.noite', '')),
                'precipitacao_manha_mm' => data_get($data, 'clima.precipitacao_manha_mm', ''),
                'precipitacao_tarde_mm' => data_get($data, 'clima.precipitacao_tarde_mm', ''),
                'precipitacao_noite_mm' => data_get($data, 'clima.precipitacao_noite_mm', ''),
                'dia_impraticavel' => (bool) data_get($data, 'clima.dia_impraticavel', false),
            ],
            'atividades' => collect($data['atividades'] ?? [])
                ->map(fn ($item) => [
                    'titulo' => trim((string) ($item['titulo'] ?? '')),
                    'ocorrencia' => trim((string) ($item['ocorrencia'] ?? '')),
                ])
                ->filter(fn ($item) => $item['titulo'] !== '' || $item['ocorrencia'] !== '')
                ->values()
                ->all(),
            'mao_obra' => $this->normalizeResourceItems($data['mao_obra'] ?? []),
            'equipamentos' => $this->normalizeResourceItems($data['equipamentos'] ?? []),
            'subcontratadas' => $this->normalizeResourceItems($data['subcontratadas'] ?? []),
            'fotos' => $data['fotos'] ?? $this->emptyPhotoData(),
        ];
    }

    private function normalizeResourceItems(array $items): array
    {
        return collect($items)
            ->map(fn ($item) => [
                'cadastro_id' => filled($item['cadastro_id'] ?? null) ? (int) $item['cadastro_id'] : null,
                'descricao' => trim((string) ($item['descricao'] ?? '')),
                'quantidade' => (float) ($item['quantidade'] ?? 0),
            ])
            ->filter(fn ($item) => $item['cadastro_id'] || $item['descricao'] !== '' || $item['quantidade'] > 0)
            ->values()
            ->all();
    }

    private function preparePhotoData(Tenant $tenant, RdaApontamento $rda, array $data, array $uploads, array $comments, array $positions): array
    {
        $existing = collect($data['arquivos'] ?? [])
            ->filter(fn ($photo) => ! empty($photo['path']))
            ->values()
            ->all();

        foreach ($uploads as $index => $upload) {
            if (! $upload instanceof UploadedFile) {
                continue;
            }

            $path = $upload->store("tenant-{$tenant->id}/rda/{$rda->id}/fotos", 'public');
            $existing[] = [
                'path' => $path,
                'nome' => $upload->getClientOriginalName(),
                'comment' => $comments[$index] ?? '',
                'legenda' => $comments[$index] ?? '',
                'uploaded_at' => now()->toDateTimeString(),
                'position' => (int) ($positions[$index] ?? count($existing) + 1),
            ];
        }

        usort($existing, fn ($a, $b) => (int) ($a['position'] ?? 999) <=> (int) ($b['position'] ?? 999));

        return [
            'arquivos' => collect($existing)->values()->map(fn ($photo, $index) => [
                ...$photo,
                'position' => $index + 1,
            ])->all(),
            'novas_fotos' => [],
            'ordem_fotos' => collect($existing)->pluck('path')->map(fn ($path) => 'existing:'.$path)->values()->all(),
        ];
    }

    private function emptyPhotoData(): array
    {
        return ['arquivos' => [], 'novas_fotos' => [], 'ordem_fotos' => []];
    }

    private function canFillRda(Request $request, Tenant $tenant, RdoDiario $rdo): bool
    {
        return RdoResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('modulo', 'rda')
            ->where('etapa', 'campo')
            ->where('status', 'active')
            ->where('user_id', $request->user()?->id)
            ->where('contract_id', $rdo->contract_id)
            ->where('obra_id', $rdo->obra_id)
            ->exists();
    }

    private function mobileTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('mobile_tenant');

        abort_unless($tenant instanceof Tenant, 401, 'Ambiente mobile não identificado.');

        return $tenant;
    }
}
