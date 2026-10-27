<?php

namespace App\Services;

use App\Models\RelatorioNaoConformidade;
use App\Models\Tenant;
use App\Support\RncPdfTemplates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class RncPdfRenderer
{
    public const SCOPES = ['opening' => 'Abertura', 'analysis' => 'Abertura + proposta + análise', 'evidence' => 'Abertura + proposta + análise + evidência', 'signed' => 'PDF completo assinado'];
    private const PDF_PHOTO_WIDTH = 1000;
    private const PDF_PHOTO_HEIGHT = 680;
    private const PDF_LOGO_WIDTH = 480;
    private const PDF_LOGO_HEIGHT = 180;

    public function render(Tenant $tenant, RelatorioNaoConformidade $rnc, string $scope = 'evidence', array $signatureSigners = [], ?array $template = null, array $logoUploads = [])
    {
        abort_unless((int) $rnc->tenant_id === (int) $tenant->id, 404);
        abort_unless(in_array($scope, ['opening', 'analysis', 'evidence'], true), 422);
        $template ??= RncPdfTemplates::forContract($tenant, $rnc->contract_id);
        if ($rnc->exists && ! ($template['preview'] ?? false)) $template = app(RncDocumentSnapshots::class)->forRnc($tenant, $rnc);
        $configuration = RncPdfTemplates::configuration($template['layout'] ?? 'standard', $template['options'] ?? [], $template['field_definitions'] ?? null);
        $options = $configuration['options'];
        $values = $rnc->model_field_values ?? ($rnc->exists ? app(RncDocumentSnapshots::class)->legacyValues($tenant, $rnc) : []);
        $modelFields = \App\Support\RncModelFields::rows($configuration['field_definitions'], $values);
        $modelHeaderFields = array_map(function ($field, $row) use ($values) {
            $choices = match ($field['type']) {
                'select' => array_map(fn ($value) => ['value' => $value, 'label' => $value], $field['options']),
                'boolean' => [['value' => 'yes', 'label' => 'Sim'], ['value' => 'no', 'label' => 'Não']],
                default => [],
            };
            return [...$row, 'choices' => array_map(fn ($choice) => [...$choice, 'checked' => ($values[$field['key']] ?? null) === $choice['value']], $choices)];
        }, $configuration['field_definitions'], $modelFields);
        $standardHeaderFields = \App\Support\RncPdfHeaderFields::boundedFields($modelHeaderFields, false);
        $inspectionHeaderFields = \App\Support\RncPdfHeaderFields::boundedFields(array_values(array_filter($modelHeaderFields, fn ($field) => !in_array($field['key'], ['work_type', 'pavement_type', 'document_contract', 'document_km', 'document_location'], true))));
        $hasDocumentContract = in_array('document_contract', array_column($configuration['field_definitions'], 'key'), true);
        $inspectionContract = $hasDocumentContract ? ($values['document_contract'] ?? '') : ($rnc->contract?->code ?? '');
        $hasDocumentKm = in_array('document_km', array_column($configuration['field_definitions'], 'key'), true);
        $inspectionKm = $hasDocumentKm ? ($values['document_km'] ?? '') : (string) ($rnc->km ?? '');
        $hasDocumentLocation = in_array('document_location', array_column($configuration['field_definitions'], 'key'), true);
        $inspectionLocation = $hasDocumentLocation ? ($values['document_location'] ?? '') : ($rnc->localizacao ?: ($rnc->obra?->nome ?? ''));
        $additionalHeaderHeight = $configuration['layout'] === 'inspection_form'
            ? \App\Support\RncPdfHeaderFields::inspectionHeight($inspectionHeaderFields, Pdf::getDomPDF()->getFontMetrics())
                + \App\Support\RncPdfHeaderFields::contractExtraHeight($inspectionContract, Pdf::getDomPDF()->getFontMetrics())
                + max(\App\Support\RncPdfHeaderFields::contractExtraHeight($inspectionKm, Pdf::getDomPDF()->getFontMetrics(), 78),
                    \App\Support\RncPdfHeaderFields::contractExtraHeight($inspectionLocation, Pdf::getDomPDF()->getFontMetrics(), 105)) : 0;
        foreach (['work_type', 'pavement_type'] as $key) $options[$key] = $values[$key] ?? ($options[$key] ?? '');
        $logoSnapshot = $rnc->exists && ! ($template['preview'] ?? false)
            ? app(RncPdfLogoSnapshots::class)->forRnc($tenant, $rnc) : null;
        if ($logoSnapshot) {
            foreach (RncPdfTemplates::LOGO_OPTIONS as $key) $options[$key] = $logoSnapshot[$key] ?? '';
            $logoUploads = [];
        }
        $logoContractId = (int) ($logoSnapshot['contract_id'] ?? $rnc->contract_id);
        $rnc->loadMissing(['tenant', 'contract', 'obra', 'disciplina', 'contratante', 'contratada', 'creator', 'finalizedBy', 'instrucaoCampo',
            'projectDocuments.latestVersion', 'projectDocument.latestVersion', 'photos', 'acoesCorretivas.user', 'acoesCorretivas.reviewer', 'evidencias.user', 'evidencias.photos', 'signatureRequests.signers']);
        $latestAction = $rnc->acoesCorretivas->sortByDesc(fn ($action) => sprintf('%s-%020d', $action->submitted_at, $action->id))->first();
        $latestEvidence = $rnc->evidencias->sortByDesc('id')->first();
        $raster = $this->canRasterizePdfImages();
        $rows = $this->rncFlowRows($rnc, $latestAction, $latestEvidence);
        $rows = array_slice($rows, 0, $scope === 'opening' ? 2 : ($scope === 'analysis' ? 4 : 6));
        if ($signatureSigners) $rows = array_slice($rows, 0, 5);
        $logo = function (string $side) use ($tenant, $logoContractId, $logoSnapshot, $options, $logoUploads): ?string {
            if ($logoSnapshot && (int) ($logoSnapshot['tenant_id'] ?? 0) !== (int) $tenant->id) return null;
            $upload = $logoUploads[$side] ?? null;
            if ($upload instanceof UploadedFile) return $this->containedPdfImageDataUri($upload->getRealPath(), self::PDF_LOGO_WIDTH, self::PDF_LOGO_HEIGHT);
            $path = $options[$side.'_logo_path'];
            if (! RncPdfTemplates::validLogoPath($tenant, $logoContractId, $path) || ! Storage::disk('local')->exists($path)) return null;
            return $this->containedPdfImageDataUri(Storage::disk('local')->path($path), self::PDF_LOGO_WIDTH, self::PDF_LOGO_HEIGHT);
        };
        return Pdf::loadView(RncPdfTemplates::VIEWS[$configuration['layout']], [
            'tenant' => $tenant, 'rnc' => $rnc, 'contratanteLogo' => $logo('left'),
            'contratadaLogo' => $logo('right'), 'photos' => $this->formatPdfPhotos($rnc->photos, $raster, $configuration['layout'] === 'inspection_form'),
            'leftLogoName' => $logoSnapshot['left_logo_label'] ?? '', 'rightLogoName' => $logoSnapshot['right_logo_label'] ?? '',
            'pdfOptions' => $options, 'isPreview' => $template['preview'] ?? false,
            'modelFields' => $modelFields,
            'modelHeaderFields' => $modelHeaderFields, 'inspectionHeaderFields' => $inspectionHeaderFields,
            'standardHeaderFields' => $standardHeaderFields,
            'inspectionContract' => $inspectionContract,
            'inspectionKm' => $inspectionKm,
            'inspectionLocation' => $inspectionLocation,
            'additionalHeaderHeight' => $additionalHeaderHeight,
            'approvedAction' => $latestAction, 'latestAction' => $latestAction,
            'latestEvidence' => $latestEvidence, 'evidencePhotos' => $latestEvidence ? $this->formatPdfPhotos($latestEvidence->photos, $raster, $configuration['layout'] === 'inspection_form') : collect(),
            'flowRows' => $rows, 'canRasterizePdfImages' => $raster, 'signatureSigners' => $signatureSigners,
            'includeAnalysis' => $scope !== 'opening', 'includeEvidence' => $scope === 'evidence',
        ])->setPaper('a4');
    }

    private function formatPdfPhotos(Collection $photos, bool $canRasterizePdfImages, bool $contain = false): Collection
    {
        return $photos->map(function ($photo) use ($canRasterizePdfImages, $contain): array {
            $dataUri = null;
            $canEmbedImage = false;
            $mime = $photo->mime_type ?: (Storage::disk('public')->exists($photo->path) ? Storage::disk('public')->mimeType($photo->path) : null);

            if ($mime) {
                $canEmbedImage = in_array(strtolower($mime), ['image/jpeg', 'image/jpg'], true) || $canRasterizePdfImages;
            }

            if ($canEmbedImage && Storage::disk('public')->exists($photo->path)) {
                $absolutePath = Storage::disk('public')->path($photo->path);
                $dataUri = ($contain ? $this->containedPdfImageDataUri($absolutePath, self::PDF_PHOTO_WIDTH, self::PDF_PHOTO_HEIGHT) : $this->standardizedPdfPhotoDataUri($absolutePath))
                    ?: 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($photo->path));
            }

            return [
                'position' => $photo->position,
                'comment' => $photo->comment ?? null,
                'original_name' => $photo->original_name,
                'data_uri' => $dataUri,
                'mime_type' => $mime,
                'needs_raster_extension' => ! $dataUri && ! in_array(strtolower((string) $mime), ['image/jpeg', 'image/jpg'], true),
            ];
        })->values();
    }

    private function rncFlowRows(RelatorioNaoConformidade $rnc, $latestAction, $latestEvidence): array
    {
        $proposalReview = $latestAction
            ? match ($latestAction->status) {
                'approved' => [
                    'status' => 'Aprovada',
                    'data' => $latestAction->reviewed_at?->format('d/m/Y H:i') ?: '-',
                    'detalhe' => 'Aprovada por '.($latestAction->reviewer?->name ?: 'usuario responsavel').'. Pode iniciar o processo corretivo.',
                ],
                'rejected' => [
                    'status' => 'Reprovada',
                    'data' => $latestAction->reviewed_at?->format('d/m/Y H:i') ?: '-',
                    'detalhe' => 'Reprovada por '.($latestAction->reviewer?->name ?: 'usuario responsavel').'. Motivo: '.($latestAction->review_observation ?: '-'),
                ],
                default => [
                    'status' => 'Em analise',
                    'data' => '-',
                    'detalhe' => 'Aguardando analise da proposta enviada.',
                ],
            }
        : [
            'status' => 'Pendente',
            'data' => '-',
            'detalhe' => 'Aguardando envio da proposta de acao corretiva.',
        ];

        return [
            [
                'etapa' => 'Abertura da RNC',
                'status' => 'Concluida',
                'data' => $rnc->opened_at?->format('d/m/Y') ?: '-',
                'responsavel' => $rnc->creator?->name ?: '-',
                'detalhe' => 'Registro inicial da nao conformidade.',
            ],
            [
                'etapa' => 'Notificacao aos responsaveis',
                'status' => $rnc->notified_at ? 'Concluida' : 'Pendente',
                'data' => $rnc->notified_at?->format('d/m/Y H:i') ?: '-',
                'responsavel' => 'Responsaveis da RNC',
                'detalhe' => $rnc->notified_at ? 'Responsaveis notificados por email e alerta interno.' : 'Aguardando envio da notificacao.',
            ],
            [
                'etapa' => 'Proposta de acao corretiva',
                'status' => $latestAction ? 'Enviada' : 'Pendente',
                'data' => $latestAction?->submitted_at?->format('d/m/Y H:i') ?: '-',
                'responsavel' => $latestAction?->user?->name ?: '-',
                'detalhe' => $latestAction
                    ? 'Prazo proposto: '.($latestAction->prazo_execucao_proposto?->format('d/m/Y') ?: '-')
                    : 'Aguardando proposta do responsavel.',
            ],
            [
                'etapa' => 'Analise da proposta',
                'status' => $proposalReview['status'],
                'data' => $proposalReview['data'],
                'responsavel' => $latestAction?->reviewer?->name ?: '-',
                'detalhe' => $proposalReview['detalhe'],
            ],
            [
                'etapa' => 'Evidencias da correcao',
                'status' => $latestEvidence ? 'Registradas' : ($latestAction?->status === 'approved' ? 'Pendente' : 'Aguardando'),
                'data' => $latestEvidence?->submitted_at?->format('d/m/Y H:i') ?: ($rnc->finalized_at?->format('d/m/Y H:i') ?: '-'),
                'responsavel' => $latestEvidence?->user?->name ?: ($rnc->finalizedBy?->name ?: '-'),
                'detalhe' => $latestEvidence
                    ? 'Evidências registradas. Resultado: '.($latestEvidence->resolution_label).'.'
                    : ($latestAction?->status === 'approved' ? 'Aguardando envio das evidencias da correcao.' : 'Aguardando aprovacao da proposta.'),
            ],
            [
                'etapa' => 'Assinaturas das partes',
                'status' => $rnc->finalized_at ? 'Concluída' : 'Aguardando',
                'data' => $rnc->finalized_at?->format('d/m/Y H:i') ?: '-',
                'responsavel' => 'Partes selecionadas para assinatura',
                'detalhe' => $rnc->finalized_at ? 'Documento final assinado pelas partes.' : 'A RNC será finalizada após as assinaturas e o recebimento do PDF assinado.',
            ],
        ];
    }

    private function canRasterizePdfImages(): bool
    {
        return extension_loaded('imagick')
            || (function_exists('imagepng') && function_exists('imagecreatefrompng'));
    }

    private function standardizedPdfPhotoDataUri(string $sourcePath): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg') || ! function_exists('imagecopyresampled')) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($sourcePath));

        if (! $source) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $canvas = imagecreatetruecolor(self::PDF_PHOTO_WIDTH, self::PDF_PHOTO_HEIGHT);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);

        $targetRatio = self::PDF_PHOTO_WIDTH / self::PDF_PHOTO_HEIGHT;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $cropX = 0;
            $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $cropX,
            $cropY,
            self::PDF_PHOTO_WIDTH,
            self::PDF_PHOTO_HEIGHT,
            $cropWidth,
            $cropHeight,
        );

        ob_start();
        imagejpeg($canvas, null, 88);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if (! $jpeg) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
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

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $canvas = imagecreatetruecolor($targetCanvasWidth, $targetCanvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);

        $scale = min($targetCanvasWidth / $sourceWidth, $targetCanvasHeight / $sourceHeight);
        $targetWidth = (int) round($sourceWidth * $scale);
        $targetHeight = (int) round($sourceHeight * $scale);
        $targetX = (int) floor(($targetCanvasWidth - $targetWidth) / 2);
        $targetY = (int) floor(($targetCanvasHeight - $targetHeight) / 2);

        imagecopyresampled(
            $canvas,
            $source,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if (! $jpeg) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

}
