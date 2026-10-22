<?php

namespace App\Services;

use App\Models\OsDnitOrder;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Carbon;

class OsDnitPdfRenderer
{
    public const SCOPES = [
        'analyst' => 'solicitacao-analista',
        'concremat' => 'solicitacao-analise-concremat',
        'inspector' => 'solicitacao-analises',
        'complete' => 'completo',
    ];

    public const ACTIONS = [
        'created' => 'Rascunho criado', 'legacy_draft' => 'Rascunho anterior preservado',
        'draft_updated' => 'Rascunho atualizado', 'submitted' => 'Enviada à Concremat',
        'analysis_saved' => 'Análise salva', 'forwarded' => 'Encaminhada ao fiscal do contrato',
        'returned' => 'Reprovada e devolvida', 'approved' => 'Serviço aprovado',
    ];

    /** Build the document while the controller holds a shared order lock. */
    public function data(Tenant $tenant, OsDnitOrder $order, string $scope = 'complete'): array
    {
        $order->load(['contract', 'creator', 'items', 'attachments', 'analystReview', 'events.actor']);
        $events = $order->events;
        $approval = $order->status === 'approved' ? $events->last(fn ($event) => $event->action === 'approved') : null;
        $decision = $events->last(fn ($event) => in_array($event->action, ['returned', 'approved'], true));
        $requested = $order->items->map->only(['product_id', 'product_code', 'product_name', 'unit', 'quantity'])->values()->all();
        $reviewed = $approval ? ($approval->snapshot['review_items'] ?? []) : ($order->review_items ?? []);
        $reviewedDeadline = $approval ? ($approval->snapshot['estimated_deadline'] ?? null) : $order->estimated_deadline?->format('Y-m-d');
        $reviewComment = $approval ? ($approval->snapshot['review_comment'] ?? null) : $order->review_comment;
        $hasReview = $order->status === 'approved' || $order->review_items !== null;
        $records = [];
        $lastAnalysis = null;
        $currentAnalysis = $hasReview ? ['items' => $reviewed, 'deadline' => $reviewedDeadline, 'comment' => $reviewComment] : null;
        $printedComments = array_filter([$reviewComment, $approval?->comment], fn ($value) => $value !== null && $value !== '');
        foreach ($events as $event) {
            $snapshot = $event->snapshot ?? [];
            $analysis = isset($snapshot['review_items']) ? [
                'items' => $snapshot['review_items'], 'deadline' => $snapshot['estimated_deadline'] ?? null,
                'comment' => $snapshot['review_comment'] ?? null,
            ] : null;
            $analysisChanged = $analysis !== null && $analysis !== $lastAnalysis;
            $previousAnalysis = $analysisChanged && $analysis !== $currentAnalysis;
            $newComment = $event->comment !== null && $event->comment !== '' && !in_array($event->comment, $printedComments, true);
            if ($newComment || $previousAnalysis || in_array($event->action, ['draft_updated', 'returned'], true)) {
                $records[] = ['event' => $event, 'analysisChanged' => $previousAnalysis,
                    'comparison' => $previousAnalysis ? $this->compare($snapshot['items'] ?? [], $analysis['items']) : [],
                    'deadline' => $analysis['deadline'] ?? null];
                if ($event->comment !== null && $event->comment !== '') $printedComments[] = $event->comment;
            }
            $lastAnalysis = $analysis;
        }

        $logoPath = public_path('brand/concremat-logo.png');

        return [
            'tenant' => $tenant, 'order' => $order, 'approved' => $order->status === 'approved',
            'scope' => $scope, 'includeConcremat' => $scope !== 'analyst',
            'includeInspector' => in_array($scope, ['inspector', 'complete'], true), 'includeFlow' => $scope === 'complete',
            'decision' => $decision, 'decisionItems' => $decision?->snapshot['review_items'] ?? [],
            'decisionDeadline' => $decision?->snapshot['estimated_deadline'] ?? null,
            'approval' => $approval, 'requested' => $requested, 'reviewed' => $reviewed,
            'hasReview' => $hasReview, 'reviewedDeadline' => $reviewedDeadline, 'reviewComment' => $reviewComment,
            'summaryItems' => $hasReview ? $reviewed : $requested,
            'comparison' => $this->compare($requested, $reviewed), 'events' => $events, 'records' => $records,
            'flowRecords' => collect($records)->keyBy(fn ($record) => $record['event']->id),
            'statuses' => OsDnitOrder::STATUSES, 'actions' => self::ACTIONS,
            'generatedAt' => now()->timezone(config('app.timezone', 'America/Sao_Paulo')),
            'logo' => is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null,
            'number' => fn ($value, bool $fixed = false) => $fixed ? number_format((float) $value, 3, ',', '.')
                : rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ','),
            'date' => fn ($value) => $value ? Carbon::parse($value)->format('d/m/Y') : 'Não informado',
            'time' => fn ($value) => $value ? Carbon::parse($value)->timezone(config('app.timezone', 'America/Sao_Paulo'))->format('d/m/Y H:i') : 'Não registrado',
        ];
    }

    public function compare(array $requested, array $reviewed): array
    {
        $original = collect($requested)->keyBy(fn ($item) => (string) $item['product_id']);
        $analysis = collect($reviewed)->keyBy(fn ($item) => (string) $item['product_id']);

        return $original->keys()->merge($analysis->keys())->unique()->map(function ($id) use ($original, $analysis) {
            $a = $original->get($id);
            $b = $analysis->get($id);
            $changed = !$a || !$b || (float) $a['quantity'] !== (float) $b['quantity'] || $a['unit'] !== $b['unit'];

            return ['product' => $a ?? $b, 'requested' => $a, 'reviewed' => $b, 'changed' => $changed,
                'label' => !$a ? 'Incluído' : (!$b ? 'Retirado' : ($a['unit'] !== $b['unit'] ? 'Unidade alterada' : ($changed ? 'Quantidade alterada' : 'Sem alteração')))];
        })->values()->all();
    }

    public function render(array $data): PdfDocument
    {
        $pdf = Pdf::loadView('pdf.os-dnit', $data)->setPaper('a4', 'portrait')->setOptions([
            'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
        ], true);
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $dompdf->getCanvas()->page_text(436, 816, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7, [.34, .40, .47]);

        return $pdf;
    }
}
