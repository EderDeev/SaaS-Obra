<?php

namespace App\Support;

use App\Models\{Activity, BoletimMedicao, Contract, FolhaRosto, GedDocument, InstrucaoCampo, MedicaoItem, Orcamento, OrdemServico, OsDnitOrder, ProjectDocument, RdoDiario, RelatorioNaoConformidade, Tenant, User};

class ContractModuleSummary
{
    public static function forUser(User $user, Tenant $tenant, Contract $contract): array
    {
        $definitions = [
            'activities' => [Activity::class, ActivityPermissions::class, 'Atividades', 'tenant.activities.index'],
            'projects' => [ProjectDocument::class, ProjectPermissions::class, 'Projetos', 'tenant.projects.index'],
            'rncs' => [RelatorioNaoConformidade::class, RncPermissions::class, 'RNCs', 'tenant.qualidade.rnc.index'],
            'os' => [OsDnitOrder::class, OsDnitPermissions::class, 'Ordens de Serviço', 'tenant.os-dnit.orders.index'],
            'rdos' => [RdoDiario::class, DiarioObraPermissions::class, 'RDOs', 'tenant.diario-obra.rdo.calendar'],
            'ics' => [InstrucaoCampo::class, IcPermissions::class, 'ICs', 'tenant.qualidade.ic.index'],
            'documents' => [GedDocument::class, DocumentationPermissions::class, 'Documentação', 'tenant.ged.index'],
            'serviceOrders' => [OrdemServico::class, OrdemServicoPermissions::class, 'Liberação de Serviço', 'tenant.ordem-servico.os.index'],
        ];
        $modules = [];

        foreach ($definitions as $key => [$model, $permissions, $label, $route]) {
            if (! $permissions::can($user, $tenant, $permissions::VIEW, $permissions === OsDnitPermissions::class ? $contract->id : $contract)) {
                continue;
            }

            $query = $model::query()->where('tenant_id', $tenant->id)->where('contract_id', $contract->id);
            if ($model === Activity::class) {
                $query->visibleTo($user);
            } elseif ($model === GedDocument::class) {
                $query = DocumentationPermissions::scopeReadableDocuments($query, $user, $tenant);
            }

            $count = $query->count();
            if ($count > 0) {
                $modules[$key] = [...compact('label', 'route', 'count'), ...self::operationalSummary($key, $query, $count, $tenant->id)];
            }
        }

        if (MedicaoPermissions::can($user, $tenant, MedicaoPermissions::VIEW, $contract)) {
            $measurement = self::measurementSummary($tenant, $contract);
            if ($measurement['count'] > 0) {
                $modules['measurements'] = $measurement;
            }
        }

        if (BudgetPermissions::can($user, $tenant, BudgetPermissions::VIEW)) {
            $budgets = BudgetPermissions::scopeVisibleTo(Orcamento::query()->usedInContract($contract), $user, $tenant);
            $count = $budgets->count();
            if ($count > 0) {
                $modules['budgets'] = [
                    'label' => 'Orçamentos',
                    'route' => 'tenant.orcamentos.index',
                    'count' => $count,
                    ...self::operationalSummary('budgets', $budgets, $count, $tenant->id),
                ];
            }
        }

        return $modules;
    }

    private static function operationalSummary(string $key, \Illuminate\Database\Eloquent\Builder $query, int $total, int $tenantId): array
    {
        $statusExpression = $key === 'rncs' ? RncListing::statusSql() : 'status';
        $counts = (clone $query)->selectRaw("({$statusExpression}) as current_status, COUNT(*) as total")
            ->groupByRaw("({$statusExpression})")->pluck('total', 'current_status');
        $sum = fn (array $statuses): int => (int) $counts->only($statuses)->sum();
        $metric = fn (string $label, int $value, string $tone = 'neutral'): array => compact('label', 'value', 'tone');

        [$headline, $pending, $metrics] = match ($key) {
            'rdos' => ['aguardando preenchimento ou análise', $total - $sum(['arquivado']), [
                $metric('Em preenchimento', $sum(['rascunho']), 'orange'),
                $metric('Análise da construtora', $sum(['em_analise_construtora']), 'orange'),
                $metric('Análise da gerenciadora', $sum(['em_analise_gerenciadora']), 'blue'),
                $metric('Aguardando cliente', $sum(['em_aprovacao_cliente']), 'green'),
                $metric('Devolvidos para ajustes', $sum(['devolvido_construtora']), 'red'),
                $metric('Comprovações pendentes', $sum(['pendente_comprovacao']), 'amber'),
            ]],
            'activities' => ['atividades em aberto', $total - $sum(['done']), [
                $metric('A fazer', $sum(['todo'])),
                $metric('Em andamento', $sum(['in_progress']), 'blue'),
                $metric('Em revisão', $sum(['review']), 'amber'),
                $metric('Concluídas', $sum(['done']), 'green'),
            ]],
            'projects' => ['projetos aguardando análise ou aprovação', $sum(['em_analise', 'em_aprovacao']), [
                $metric('Em análise', $sum(['em_analise']), 'blue'),
                $metric('Em aprovação', $sum(['em_aprovacao']), 'amber'),
                $metric('Aprovados', $sum(['ativo']), 'green'),
                $metric('Reprovados', $sum(['reprovado']), 'red'),
                $metric('Inativos', $sum(['inativo'])),
            ]],
            'rncs' => ['não conformidades pendentes', $total - $sum(['completed']), [
                $metric('Aguardando envio', $sum(['draft'])),
                $metric('Propostas pendentes', $sum(['awaiting_action']), 'orange'),
                $metric('Propostas devolvidas', $sum(['returned']), 'red'),
                $metric('Propostas em análise', $sum(['awaiting_review']), 'blue'),
                $metric('Aguardando evidências', $sum(['awaiting_evidence']), 'amber'),
                $metric('Aguardando assinaturas', $sum(['awaiting_signature']), 'purple'),
                $metric('Finalizadas', $sum(['completed']), 'green'),
            ]],
            'os' => ['ordens de serviço em andamento', $total - $sum(['completed']), [
                $metric('Demandas', $sum(['draft', 'returned_analyst']), 'orange'),
                $metric('Análise Supervisão', $sum(['awaiting_concremat', 'returned_concremat']), 'blue'),
                $metric('Fiscal do contrato', $sum(['awaiting_contract']), 'amber'),
                $metric('Aprovadas, aguardando conclusão', $sum(['approved']), 'green'),
                $metric('Finalizadas', $sum(['completed']), 'green'),
            ]],
            'ics' => ['instruções de campo pendentes', $total - $sum(['closed']), [
                $metric('Aguardando comunicação', $sum(['draft'])),
                $metric('Aguardando evidências e finalização', $sum(['notified']), 'amber'),
                $metric('Finalizadas', $sum(['closed']), 'green'),
            ]],
            'documents' => ['documentos aguardando processamento ou revisão', $total - $sum(['indexed']), [
                $metric('Disponíveis para consulta', $sum(['indexed']), 'green'),
                $metric('Em processamento', $sum(['uploaded', 'processing', 'pending']), 'blue'),
                $metric('Falhas de processamento', $sum(['failed', 'missing_engine']), 'red'),
            ]],
            'budgets' => ['orçamentos usados na medição', $total, [
                $metric('Em elaboração', $sum(['draft']), 'amber'),
                $metric('Finalizados', $sum(['closed']), 'green'),
            ]],
            'serviceOrders' => ['liberações em andamento', $total - $sum(['concluida', 'cancelada', 'recusada']), [
                $metric('Em preparação', $sum(['rascunho'])),
                $metric('Em análise ou aprovação', $sum(['em_analise', 'em_aprovacao']), 'blue'),
                $metric('Aprovadas ou em execução', $sum(['aprovada', 'em_execucao']), 'green'),
                $metric('Concluídas', $sum(['concluida']), 'green'),
                $metric('Recusadas ou canceladas', $sum(['recusada', 'cancelada']), 'red'),
            ]],
        };

        $attention = null;
        if ($key === 'activities') {
            $overdue = (clone $query)->where('status', '!=', 'done')->whereDate('due_date', '<', today())->count();
            if ($overdue) $attention = "{$overdue} atividade(s) com prazo vencido.";
        }
        if ($key === 'os') {
            $overdue = (clone $query)->whereNotIn('status', ['draft', 'completed'])
                ->whereRaw('COALESCE(estimated_deadline, desired_deadline) < ?', [today()->toDateString()])->count();
            if ($overdue) $attention = "{$overdue} ordem(ns) com prazo vencido.";
        }
        $latestDate = null;
        if ($key === 'rdos') {
            $savedWithoutApproval = (clone $query)->where('status', 'arquivado')->get(['workflow_snapshot'])
                ->filter(fn (RdoDiario $rdo) => RdoWorkflow::savesWithoutApproval($rdo))->count();
            $metrics[] = $metric('Salvos sem fluxo de aprovação', $savedWithoutApproval, 'green');
            $metrics[] = $metric('Aprovados', $sum(['arquivado']) - $savedWithoutApproval, 'green');
            // A new request supersedes previous attempts. Count documents, never signers or works.
            $latestRequests = \App\Models\RdoSignatureRequest::query()
                ->whereIn('rdo_diario_id', (clone $query)->where('status', 'arquivado')->select('id'))
                ->where('tenant_id', $tenantId)
                ->selectRaw('MAX(id)')->groupBy('rdo_diario_id');
            $signatures = \App\Models\RdoSignatureRequest::query()->whereIn('id', $latestRequests);
            $waiting = (clone $signatures)->whereIn('status', ['draft', 'pending', 'sent'])->count();
            $failed = (clone $signatures)->where('status', 'failed')->count();
            $metrics[] = $metric('Em assinatura', $waiting, 'purple');
            $metrics[] = $metric('Falhas no envio para assinatura', $failed, 'red');
            if ($failed) $attention = "{$failed} RDO(s) com falha no envio para assinatura.";
            elseif ($sum(['devolvido_construtora'])) $attention = 'Há RDOs devolvidos que precisam de ajustes antes do reenvio.';
            elseif ($sum(['pendente_comprovacao'])) $attention = 'Há ressalvas aguardando comprovação.';
            $latestDate = (clone $query)->max('reference_date');
        }

        return ['headline' => $headline, 'pending' => $pending,
            'metrics' => array_values(array_filter($metrics, fn ($item) => $item['value'] > 0)),
            'attention' => $attention, 'latest_date' => $latestDate];
    }

    private static function measurementSummary(Tenant $tenant, Contract $contract): array
    {
        $boletins = BoletimMedicao::query()->where('tenant_id', $tenant->id)->where('contract_id', $contract->id)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $folhas = FolhaRosto::query()->where('tenant_id', $tenant->id)->where('contract_id', $contract->id)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = MedicaoItem::query()->where('tenant_id', $tenant->id)->where('contract_id', $contract->id)
            ->where('item_type', '!=', 'etapa')->count();
        $boletinsTotal = (int) $boletins->sum();
        $folhasTotal = (int) $folhas->sum();
        $inReview = (int) $folhas->only(['analise_fiscal', 'analise_qualidade', 'analise_medicao'])->sum();
        $returned = (int) $folhas->get('retornada', 0);
        $awaitingSubmission = (int) $folhas->only(['rascunho', 'aberta'])->sum();
        $metric = fn (string $label, int $value, string $tone): array => compact('label', 'value', 'tone');

        return [
            'label' => 'Medição',
            'route' => match (true) {
                $boletinsTotal > 0 => 'tenant.medicao.boletim-medicao.index',
                $folhasTotal > 0 => 'tenant.medicao.folha-rosto.index',
                default => 'tenant.medicao.item.index',
            },
            'count' => $boletinsTotal + $folhasTotal + $items,
            'headline' => $folhasTotal > 0 ? 'folhas de rosto em aberto' : ($boletinsTotal > 0 ? 'boletins ainda não finalizados' : 'itens na base de medição'),
            'pending' => $folhasTotal > 0
                ? $inReview + $returned + $awaitingSubmission
                : ($boletinsTotal > 0 ? $boletinsTotal - (int) $boletins->get('finalizado', 0) : $items),
            'metrics' => array_values(array_filter([
                $metric('Folhas de rosto aguardando envio', $awaitingSubmission, 'amber'),
                $metric('Folhas de rosto em análise', $inReview, 'blue'),
                $metric('Folhas de rosto devolvidas', $returned, 'red'),
                $metric('Folhas de rosto analisadas', (int) $folhas->get('analisada', 0), 'green'),
                $metric('Boletins abertos para lançamento', (int) $boletins->get('aberto_lancamento', 0), 'blue'),
                $metric('Boletins congelados', (int) $boletins->get('congelado', 0), 'amber'),
                $metric('Boletins finalizados', (int) $boletins->get('finalizado', 0), 'green'),
                $metric('Itens na base de medição', $boletinsTotal || $folhasTotal ? $items : 0, 'neutral'),
            ], fn ($metric) => $metric['value'] > 0)),
        ];
    }
}
