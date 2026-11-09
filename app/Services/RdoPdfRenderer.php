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
use App\Support\RdoPdfTemplates;
use App\Support\RdoWorkflow;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class RdoPdfRenderer
{
    private \WeakMap $placements;

    public function __construct(private readonly StoredFileMaterializer $fileMaterializer)
    {
        $this->placements = new \WeakMap;
    }

    public function render(Tenant $tenant, RdoDiario $rdo, ?RdoPdfPreview $preview = null, array $export = [])
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

        $parties = RdoWorkflow::parties($rdo);
        if (isset($export['stage'])) {
            $position = array_search($export['stage'], $parties, true);
            if ($position === false) throw new \InvalidArgumentException('Etapa inválida para este RDO.');
            $parties = array_slice($parties, 0, $position + 1);
        }
        $obras = $rdo->configuracao?->obras ?? collect([$rdo->obra])->filter();
        if (isset($export['obra_ids'])) $obras = $obras->whereIn('id', $export['obra_ids']);
        $obraIds = $obras->pluck('id')->all();
        $inScope = fn ($record) => ! $record->obra_id || in_array((int) $record->obra_id, array_map('intval', $obraIds), true);
        $records = $rdo->secoes->filter($inScope)->filter(fn ($record) => in_array($record->filling_party ?? 'construtora', $parties, true));

        $sections = RdoWorkflow::sections($rdo, RdoWorkflow::fillParty($rdo))
            ->filter($inScope)
            ->groupBy('obra_id')
            ->map(fn ($items) => $items->mapWithKeys(fn (RdoSecaoRegistro $section) => [
                $section->secao => $section->dados,
            ]));

        $documentConfiguration = RdoPdfTemplates::forDiary($rdo);
        $signatureParties = $parties;
        $logoService = app(RdoDocumentLogos::class);
        $logoImages = isset($documentConfiguration['logos']) ? collect($documentConfiguration['logos'])->map(fn ($logo) => $logo['data_uri'] ?? $logoService->dataUri($logo))->all() : [
            'cliente' => $this->companyLogoDataUri($rdo->contract?->clienteEmpresa),
            'gerenciadora' => $this->companyLogoDataUri($rdo->contract?->gerenciadoraEmpresa),
            'construtora' => $this->companyLogoDataUri($rdo->contract?->construtoraEmpresa),
        ];

        $pdf = Pdf::loadView(RdoPdfTemplates::VIEWS[$documentConfiguration['layout']], [
            'documentConfiguration' => $documentConfiguration,
            'referencePreview' => $preview?->reference($rdo) ?? [],
            'signatureParties' => $signatureParties,
            'includeWorkflowAppendix' => ! isset($export['stage']),
            'tenant' => $tenant,
            'rdo' => $rdo,
            'contract' => $rdo->contract,
            'obras' => $obras,
            'sections' => $sections,
            'fillingParties' => array_values(array_intersect(
                RdoWorkflow::hasParallelManagerFilling($rdo) && $records->contains('filling_party', 'gerenciadora')
                    ? RdoWorkflow::fieldParties($rdo) : RdoWorkflow::fillParties($rdo),
                $parties,
            )),
            'sectionsByParty' => $records->groupBy(fn ($section) => $section->filling_party ?? 'construtora')->map(fn ($records) => $records->groupBy('obra_id')->map(fn ($items) => $items->mapWithKeys(fn ($section) => [$section->secao => $section->dados]))),
            'analyses' => $rdo->analises->filter($inScope)->whereIn('etapa', $parties)->sortBy([['created_at', 'asc'], ['id', 'asc']])->values(),
            'versions' => $rdo->versoes->sortBy('version_number')->values(),
            'inspections' => (in_array('gerenciadora', $parties, true) ? $rdo->fiscalizacaoRegistros->filter($inScope) : collect())->sortBy(fn ($record) => sprintf('%06d-%010d', $record->version_number, $record->obra_id))->values(),
            'divergences' => (in_array('gerenciadora', $parties, true) ? $rdo->divergencias->filter($inScope) : collect())->sortBy('created_at')->values(),
            'logos' => $logoImages,
            'inspectionImage' => fn ($evidence) => $preview && ($evidence['path'] ?? '') === '__rdo_preview__/registro' ? $preview->photo() : $this->inspectionImage($tenant, $rdo, $evidence),
            'photoDataUri' => fn (?string $path): ?string => $preview && $path === '__rdo_preview__/registro' ? $preview->photo() : $this->photoDataUri($path),
            'catalogs' => $preview?->catalogs($rdo) ?? [
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
        $dom = $pdf->getDomPDF();
        $reference = \WeakReference::create($dom);
        $dom->setCallbacks([['event' => 'end_frame', 'f' => function ($frame, $canvas) use ($reference, $signatureParties) {
            $node = $frame->get_node();
            if (! $node instanceof \DOMElement || $node->getAttribute('id') !== 'rdo-final-signature-table') {
                return;
            }
            [$x, $y, $width] = $frame->get_border_box();
            $widgets = [];
            foreach ($signatureParties as $index => $role) {
                $widgets[$role] = ['x' => round($x + $index * $width / count($signatureParties) + ($width / count($signatureParties) - 112) / 2, 2), 'y' => round($y + 5, 2), 'w' => 112, 'h' => 34];
            }
            $this->placements[$reference->get()] = ['signature_page' => $canvas->get_page_number(), 'signature_layout' => 'flow_end', 'signature_widgets' => $widgets];
        }]]);

        return $pdf;
    }

    public function signaturePlacement($pdf): array
    {
        return $this->placements[$pdf->getDomPDF()] ?? throw new \RuntimeException('Não foi possível localizar as assinaturas no documento gerado.');
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

    private function inspectionImage(Tenant $tenant, RdoDiario $rdo, array $file): ?string
    {
        $disk = $file['disk'] ?? 'public';
        if (! in_array($disk, ['public', 'local'], true) || ! str_starts_with($file['mime_type'] ?? '', 'image/') || ! str_starts_with($file['path'] ?? '', "rdo/{$tenant->id}/{$rdo->id}/") || ! Storage::disk($disk)->exists($file['path'])) {
            return null;
        }

        return $this->fileMaterializer->withLocalPath($disk, $file['path'], fn ($local) => $this->containedPdfImageDataUri($local, 800, 480));
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
