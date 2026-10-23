<?php

namespace App\Support;

use App\Models\RelatorioNaoConformidadeAcaoCorretiva;

class RncListing
{
    public const STATUSES = [
        'draft' => 'Rascunho',
        'awaiting_action' => 'Aguardando proposta',
        'returned' => 'Proposta devolvida',
        'awaiting_review' => 'Em análise da proposta',
        'awaiting_evidence' => 'Aguardando evidências',
        'completed' => 'Finalizada',
    ];

    public const STAGES = [
        'draft' => ['draft'],
        'corrective_action' => ['awaiting_action', 'returned'],
        'analysis' => ['awaiting_review'],
        'evidence' => ['awaiting_evidence'],
        'completed' => ['completed'],
    ];

    public static function statusSql(): string
    {
        $latestAction = RelatorioNaoConformidadeAcaoCorretiva::query()->select('status')
            ->whereColumn('relatorio_nao_conformidade_id', 'relatorio_nao_conformidades.id')
            ->whereColumn('tenant_id', 'relatorio_nao_conformidades.tenant_id')
            ->orderByDesc('submitted_at')->orderByDesc('id')->limit(1)->toSql();

        // Derive the current step from the existing workflow, including legacy
        // finalized records and evidence, without introducing a new stored status.
        return "CASE
            WHEN relatorio_nao_conformidades.status = 'finalizada'
                OR relatorio_nao_conformidades.finalized_at IS NOT NULL
                OR EXISTS (SELECT 1 FROM relatorio_nao_conformidade_evidencias e
                    WHERE e.relatorio_nao_conformidade_id = relatorio_nao_conformidades.id
                      AND e.tenant_id = relatorio_nao_conformidades.tenant_id) THEN 'completed'
            WHEN relatorio_nao_conformidades.notified_at IS NULL THEN 'draft'
            WHEN ({$latestAction}) = 'pending' THEN 'awaiting_review'
            WHEN ({$latestAction}) = 'approved' THEN 'awaiting_evidence'
            WHEN ({$latestAction}) = 'rejected' THEN 'returned'
            ELSE 'awaiting_action' END";
    }
}
