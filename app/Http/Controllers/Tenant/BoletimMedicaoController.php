<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BoletimMedicaoController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        $selectedContractId = $request->integer('contract_id') ?: $tenant->contracts()->orderBy('code')->value('id');

        $contracts = $tenant->contracts()
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $boletins = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->with(['contract:id,code,name'])
            ->withCount([
                'folhasRosto as folhas_rosto_total',
                'folhasRosto as folhas_rosto_abertas' => fn ($query) => $query->where('status', 'aberta'),
            ])
            ->latest('periodo')
            ->latest('id')
            ->get()
            ->map(fn (BoletimMedicao $boletim): array => [
                'id' => $boletim->id,
                'codigo' => $boletim->codigo,
                'periodo' => $boletim->periodo?->format('Y-m-d'),
                'periodo_formatado' => $boletim->periodo?->format('m/y'),
                'tipo' => $boletim->tipo,
                'tipo_label' => $this->tipoLabel($boletim->tipo),
                'status' => $boletim->status,
                'status_label' => $this->statusLabel($boletim->status),
                'folhas_rosto_total' => $boletim->folhas_rosto_total,
                'folhas_rosto_abertas' => $boletim->folhas_rosto_abertas,
                'contract' => $boletim->contract ? [
                    'id' => $boletim->contract->id,
                    'code' => $boletim->contract->code,
                    'name' => $boletim->contract->name,
                ] : null,
            ]);

        return Inertia::render('Tenant/Medicao/BoletimMedicao/Index', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contracts,
            'boletins' => $boletins,
            'tipos' => [
                ['value' => 'normal', 'label' => 'Normal'],
                ['value' => 'reequilibrio', 'label' => 'Reequilíbrio'],
                ['value' => 'contingencia', 'label' => 'Contingência'],
            ],
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'periodo_referencia' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'tipo' => ['required', Rule::in(['normal', 'reequilibrio', 'contingencia'])],
        ]);

        [$month, $year] = explode('/', $validated['periodo_referencia']);
        $periodo = sprintf('20%s-%s-01', $year, $month);

        DB::transaction(function () use ($request, $tenant, $validated, $periodo): void {
            Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $contract = Contract::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($validated['contract_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $next = BoletimMedicao::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->max('sequencial') + 1;

            BoletimMedicao::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $request->user()?->id,
                'codigo' => 'BM-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT),
                'sequencial' => $next,
                'periodo' => $periodo,
                'tipo' => $validated['tipo'],
                'status' => 'aberto_lancamento',
            ]);
        });

        return back()->with('success', 'Boletim de Medição criado com sucesso.');
    }

    public function freeze(Tenant $tenant, BoletimMedicao $boletim): RedirectResponse
    {
        $this->ensureTenantBoletim($tenant, $boletim);

        if ($boletim->status !== 'finalizado') {
            $boletim->forceFill(['status' => 'congelado'])->save();
        }

        return back()->with('success', 'Boletim congelado. O envio de Folhas de Rosto foi pausado.');
    }

    public function finish(Tenant $tenant, BoletimMedicao $boletim): RedirectResponse
    {
        $this->ensureTenantBoletim($tenant, $boletim);

        $boletim->forceFill(['status' => 'finalizado'])->save();

        return back()->with('success', 'Boletim finalizado. O envio de Folhas de Rosto foi pausado.');
    }

    public function reopen(Tenant $tenant, BoletimMedicao $boletim): RedirectResponse
    {
        $this->ensureTenantBoletim($tenant, $boletim);

        $boletim->forceFill(['status' => 'aberto_lancamento'])->save();

        return back()->with('success', 'Boletim reaberto. O envio de Folhas de Rosto foi liberado.');
    }

    private function tipoLabel(string $tipo): string
    {
        return match ($tipo) {
            'reequilibrio' => 'Reequilíbrio',
            'contingencia' => 'Contingência',
            default => 'Normal',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'aberto_lancamento' => 'Aberto para lançamento',
            'congelado' => 'Congelado',
            'finalizado' => 'Finalizado',
            default => $status,
        };
    }

    private function ensureTenantBoletim(Tenant $tenant, BoletimMedicao $boletim): void
    {
        abort_unless((int) $boletim->tenant_id === (int) $tenant->id, 404);
    }
}
