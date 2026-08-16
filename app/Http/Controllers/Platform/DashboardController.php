<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $tenantStatuses = Tenant::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tenantPlans = Tenant::query()
            ->selectRaw('plan, COUNT(*) as total')
            ->groupBy('plan')
            ->pluck('total', 'plan');

        $storageUsage = $this->storageUsageByTenant();

        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'tenants' => Tenant::count(),
                'active_tenants' => Tenant::where('status', 'active')->count(),
                'contracts' => Contract::count(),
                'users' => User::count(),
                'storage_bytes' => $storageUsage->sum('total_bytes'),
            ],
            'tenantStatuses' => collect(['active', 'trial', 'suspended'])
                ->map(fn (string $status): array => [
                    'key' => $status,
                    'total' => (int) ($tenantStatuses[$status] ?? 0),
                ])
                ->values(),
            'tenantPlans' => collect(['starter', 'growth', 'enterprise'])
                ->map(fn (string $plan): array => [
                    'key' => $plan,
                    'total' => (int) ($tenantPlans[$plan] ?? 0),
                ])
                ->values(),
            'storageUsage' => $storageUsage,
        ]);
    }

    private function storageUsageByTenant(): Collection
    {
        $moduleKeys = ['documentation', 'projects', 'rnc', 'activities', 'contracts', 'measurements', 'service_orders'];
        $totals = [];

        $addTotals = function (string $module, $query, string $tenantColumn, string $sizeColumn) use (&$totals): void {
            $rows = $query
                ->selectRaw("{$tenantColumn} AS tenant_id, COALESCE(SUM({$sizeColumn}), 0) AS total")
                ->groupBy($tenantColumn)
                ->get();

            foreach ($rows as $row) {
                $tenantId = (int) $row->tenant_id;
                $totals[$tenantId][$module] = ($totals[$tenantId][$module] ?? 0) + (int) $row->total;
            }
        };

        $addTotals(
            'documentation',
            DB::table('ged_document_versions')->join('ged_documents', 'ged_documents.id', '=', 'ged_document_versions.document_id'),
            'ged_documents.tenant_id',
            'ged_document_versions.size_bytes',
        );
        $addTotals(
            'documentation',
            DB::table('ged_document_attachments')->join('ged_documents', 'ged_documents.id', '=', 'ged_document_attachments.document_id'),
            'ged_documents.tenant_id',
            'ged_document_attachments.size_bytes',
        );
        $addTotals('projects', DB::table('project_document_versions'), 'tenant_id', 'file_size');
        $addTotals('rnc', DB::table('relatorio_nao_conformidade_photos'), 'tenant_id', 'size');
        $addTotals('rnc', DB::table('relatorio_nao_conformidade_acoes_corretivas'), 'tenant_id', 'attachment_size');
        $addTotals('rnc', DB::table('relatorio_nao_conformidade_evidencias'), 'tenant_id', 'attachment_size');
        $addTotals('rnc', DB::table('relatorio_nao_conformidade_evidencia_photos'), 'tenant_id', 'size');
        $addTotals('activities', DB::table('activity_files'), 'tenant_id', 'size');
        $addTotals('contracts', DB::table('contracts'), 'tenant_id', 'base_document_size');
        $addTotals('contracts', DB::table('contract_additives'), 'tenant_id', 'attachment_size');
        $addTotals('measurements', DB::table('folhas_rosto'), 'tenant_id', 'memoria_calculo_size');
        $addTotals(
            'service_orders',
            DB::table('ordem_servico_documentos')->join('ordem_servicos', 'ordem_servicos.id', '=', 'ordem_servico_documentos.ordem_servico_id'),
            'ordem_servicos.tenant_id',
            'ordem_servico_documentos.size',
        );

        return Tenant::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(function (Tenant $tenant) use ($moduleKeys, $totals): array {
                $modules = collect($moduleKeys)
                    ->mapWithKeys(fn (string $module): array => [$module => (int) ($totals[$tenant->id][$module] ?? 0)])
                    ->all();

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'modules' => $modules,
                    'total_bytes' => array_sum($modules),
                ];
            })
            ->sortByDesc('total_bytes')
            ->values();
    }
}
