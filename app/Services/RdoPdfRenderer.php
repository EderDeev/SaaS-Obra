<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\RdoDiario;
use App\Models\RdoEquipamentoCadastro;
use App\Models\RdoMaoObraCadastro;
use App\Models\RdoSecaoRegistro;
use App\Models\RdoSubcontratadaCadastro;
use App\Models\Tenant;
use App\Services\Storage\StoredFileMaterializer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class RdoPdfRenderer
{
    public function __construct(private readonly StoredFileMaterializer $fileMaterializer)
    {
    }

    public function render(Tenant $tenant, RdoDiario $rdo)
    {
        $rdo->loadMissing([
            'contract:id,code,name,description,client_company_name,contractor_company_name,total_value,city,state,starts_at,ends_at,cliente_empresa_id,construtora_empresa_id,fiscalizadora_empresa_id',
            'contract.clienteEmpresa:id,nome,sigla,cnpj,logo_path',
            'contract.construtoraEmpresa:id,nome,sigla,cnpj,logo_path',
            'contract.gerenciadoraEmpresa:id,nome,sigla,cnpj,logo_path',
            'obra:id,nome,codigo',
            'responsible:id,name,email',
            'configuracao.obras:id,nome,codigo',
            'secoes',
            'analises.user:id,name,email',
            'analises.empresa:id,nome,sigla',
            'analises.obra:id,codigo,nome',
            'versoes.createdBy:id,name,email',
            'fiscalizacaoRegistros.user:id,name,email',
            'fiscalizacaoRegistros.empresa:id,nome,sigla',
            'fiscalizacaoRegistros.obra:id,codigo,nome',
            'divergencias.obra:id,codigo,nome',
            'divergencias.createdBy:id,name,email',
            'divergencias.respondedBy:id,name,email',
            'divergencias.concludedBy:id,name,email',
            'divergencias.clientDecidedBy:id,name,email',
        ]);

        $sections = $rdo->secoes
            ->groupBy('obra_id')
            ->map(fn ($items) => $items->mapWithKeys(fn (RdoSecaoRegistro $section) => [
                $section->secao => $section->dados,
            ]));

        return Pdf::loadView('pdf.rdo', [
            'tenant' => $tenant,
            'rdo' => $rdo,
            'contract' => $rdo->contract,
            'obras' => $rdo->configuracao?->obras ?? collect([$rdo->obra])->filter(),
            'sections' => $sections,
            'analyses' => $rdo->analises->sortBy('created_at')->values(),
            'versions' => $rdo->versoes->sortBy('version_number')->values(),
            'inspections' => $rdo->fiscalizacaoRegistros->sortBy(fn ($record) => sprintf('%06d-%010d', $record->version_number, $record->obra_id))->values(),
            'divergences' => $rdo->divergencias->sortBy('created_at')->values(),
            'logos' => [
                'cliente' => $this->companyLogoDataUri($rdo->contract?->clienteEmpresa),
                'gerenciadora' => $this->companyLogoDataUri($rdo->contract?->gerenciadoraEmpresa),
                'construtora' => $this->companyLogoDataUri($rdo->contract?->construtoraEmpresa),
            ],
            'photoDataUri' => fn (?string $path): ?string => $this->photoDataUri($path),
            'catalogs' => [
                'mao_obra' => RdoMaoObraCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->get()
                    ->keyBy('id')
                    ->toArray(),
                'equipamentos' => RdoEquipamentoCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->get()
                    ->keyBy('id')
                    ->toArray(),
                'subcontratadas' => RdoSubcontratadaCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->get()
                    ->keyBy('id')
                    ->toArray(),
            ],
        ])->setPaper('a4', 'portrait');
    }

    private function companyLogoDataUri(?Empresa $empresa): ?string
    {
        if (! $empresa?->logo_path || ! Storage::disk('public')->exists($empresa->logo_path)) {
            return null;
        }

        return $this->fileMaterializer->withLocalPath(
            'public',
            $empresa->logo_path,
            fn (string $localPath): ?string => $this->containedPdfImageDataUri($localPath, 180, 62),
        );
    }

    private function photoDataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);
        $mimeType = Storage::disk('public')->mimeType($path) ?: 'image/jpeg';

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    private function containedPdfImageDataUri(string $sourcePath, int $targetCanvasWidth, int $targetCanvasHeight): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg') || ! function_exists('imagecopyresampled')) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($sourcePath));

        if (! $source) {
            return null;
        }

        $sourceWidth = max(imagesx($source), 1);
        $sourceHeight = max(imagesy($source), 1);
        $canvas = imagecreatetruecolor($targetCanvasWidth, $targetCanvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);

        $scale = min($targetCanvasWidth / $sourceWidth, $targetCanvasHeight / $sourceHeight);
        $targetWidth = (int) round($sourceWidth * $scale);
        $targetHeight = (int) round($sourceHeight * $scale);
        $targetX = (int) floor(($targetCanvasWidth - $targetWidth) / 2);
        $targetY = (int) floor(($targetCanvasHeight - $targetHeight) / 2);

        imagecopyresampled($canvas, $source, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return $jpeg ? 'data:image/jpeg;base64,'.base64_encode($jpeg) : null;
    }
}
