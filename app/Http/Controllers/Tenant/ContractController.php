<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Support\ActivityPermissions;
use App\Support\ContractPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    private const BRAZILIAN_STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
        'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        $contracts = $this->accessibleContracts($request, $tenant);
        $canCreateContracts = ContractPermissions::canAny($request->user(), $tenant, ContractPermissions::CREATE);
        $contractCollection = $contracts
            ->with([
                'obra',
                'clienteEmpresa',
                'construtoraEmpresa',
                'gerenciadoraEmpresa',
                'latestAdditive',
                'contractAdditives' => fn ($query) => $query->with('user:id,name')->latest('sequence_number'),
            ])
            ->withCount([
                'participants',
                'contractAdditives',
                'activities as open_activities_count' => fn (Builder $query): Builder => $query
                    ->visibleTo($request->user())
                    ->where('status', '!=', 'done'),
                'activities as overdue_activities_count' => fn (Builder $query): Builder => $query
                    ->visibleTo($request->user())
                    ->where('status', '!=', 'done')
                    ->whereDate('due_date', '<', today()),
                'relatorioNaoConformidades as open_rncs_count' => fn (Builder $query): Builder => $query->where('status', 'aberta'),
                'projectDocuments as pending_projects_count' => fn (Builder $query): Builder => $query->whereIn('status', ['em_analise', 'em_aprovacao']),
            ])
            ->latest()
            ->get();
        $contractIds = $contractCollection->pluck('id');

        return Inertia::render('Tenant/Contracts/Index', [
            'tenant' => $tenant,
            'contracts' => $contractCollection,
            'statuses' => ['planning', 'active', 'paused', 'completed', 'cancelled'],
            'canCreateContracts' => $canCreateContracts,
            'contractCapabilities' => $contractCollection->mapWithKeys(fn (Contract $contract): array => [
                $contract->id => [
                    'parametrize' => ContractPermissions::can($request->user(), $tenant, ContractPermissions::PARAMETRIZE, $contract),
                    'additives' => ContractPermissions::can($request->user(), $tenant, ContractPermissions::ADDITIVES, $contract),
                ],
            ]),
            'parametrizacao' => [
                'empresas' => $tenant->empresas()
                    ->whereIn('contract_id', $contractIds)
                    ->with('tipoEmpresa:id,nome')
                    ->orderBy('nome')
                    ->get(['id', 'tenant_id', 'contract_id', 'tipo_empresa_id', 'nome', 'cnpj', 'sigla', 'logo_path'])
                    ->groupBy('contract_id'),
                'obras' => $tenant->obras()
                    ->whereIn('contract_id', $contractIds)
                    ->orderBy('codigo')
                    ->get(['id', 'tenant_id', 'contract_id', 'obra_pai_id', 'codigo', 'nome', 'tipo'])
                    ->groupBy('contract_id'),
                'trechos' => $tenant->trechos()
                    ->whereHas('obra', fn (Builder $query): Builder => $query->whereIn('contract_id', $contractIds))
                    ->with('obra:id,contract_id,codigo,nome')
                    ->orderBy('obra_id')
                    ->orderByDesc('is_default')
                    ->orderBy('codigo')
                    ->get(['id', 'tenant_id', 'obra_id', 'codigo', 'nome', 'is_default'])
                    ->groupBy(fn ($trecho) => $trecho->obra->contract_id),
                'disciplinas' => $tenant->disciplinas()
                    ->whereIn('contract_id', $contractIds)
                    ->orderBy('nome')
                    ->get(['id', 'tenant_id', 'contract_id', 'nome', 'sigla', 'cor'])
                    ->groupBy('contract_id'),
                'tiposEmpresa' => TipoEmpresa::allowedCompanyTypeOptions(),
            ],
        ]);
    }

    public function tourPreview(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Contracts/TourPreview', [
            'tenant' => $tenant,
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->authorizeManageContracts($request, $tenant, ContractPermissions::CREATE);

        $request->merge([
            'code' => mb_strtoupper((string) $request->input('code', '')),
            'city' => trim((string) $request->input('city', '')),
            'state' => mb_strtoupper((string) $request->input('state', '')),
        ]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('contracts', 'code')->where('tenant_id', $tenant->id)],
            'total_value' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', Rule::in(['BRL', 'USD', 'JPY', 'CNY', 'EUR'])],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'base_document' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip', 'max:30720'],
        ], [
            'base_document.mimes' => 'O documento deve ser PDF, Word, Excel, imagem ou ZIP.',
            'base_document.max' => 'O documento pode ter no maximo 30 MB.',
        ]);

        $contract = $tenant->contracts()->create([
            ...collect($data)->except('base_document')->all(),
            'name' => 'Contrato '.$data['code'],
            'status' => 'planning',
        ]);

        if ($request->hasFile('base_document')) {
            $file = $request->file('base_document');
            $path = $file->store("tenant-{$tenant->id}/contracts/{$contract->id}/base", 'public');

            $contract->update([
                'base_document_path' => $path,
                'base_document_original_name' => $file->getClientOriginalName(),
                'base_document_mime_type' => $file->getClientMimeType(),
                'base_document_size' => $file->getSize(),
            ]);
        }

        $contract->participants()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'side' => 'manager',
            ],
            [
                'tenant_id' => $tenant->id,
                'role' => 'manager',
                'status' => 'active',
                'joined_at' => now(),
            ],
        );

        return redirect()->route('tenant.contracts.show', [$tenant, $contract])->with('success', 'Contrato criado.');
    }

    public function downloadBaseDocument(Request $request, Tenant $tenant, Contract $contract): StreamedResponse
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);
        abort_unless($contract->base_document_path && Storage::disk('public')->exists($contract->base_document_path), 404);

        return Storage::disk('public')->download(
            $contract->base_document_path,
            $contract->base_document_original_name ?: "contrato-{$contract->code}.pdf",
            ['Content-Type' => $contract->base_document_mime_type ?: 'application/octet-stream'],
        );
    }

    public function show(Request $request, Tenant $tenant, Contract $contract): Response
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);
        $canParametrize = ContractPermissions::can($request->user(), $tenant, ContractPermissions::PARAMETRIZE, $contract);
        $canManageAdditives = ContractPermissions::can($request->user(), $tenant, ContractPermissions::ADDITIVES, $contract);
        $canManageParticipants = ContractPermissions::can($request->user(), $tenant, ContractPermissions::PARTICIPANTS, $contract);

        $contract->load([
            'participants.user',
            'obra',
            'clienteEmpresa',
            'construtoraEmpresa',
            'gerenciadoraEmpresa',
            'latestAdditive',
        ])->loadCount([
            'contractAdditives',
            'activities as open_activities_count' => fn (Builder $query): Builder => $query
                ->visibleTo($request->user())
                ->where('status', '!=', 'done'),
            'activities as overdue_activities_count' => fn (Builder $query): Builder => $query
                ->visibleTo($request->user())
                ->where('status', '!=', 'done')
                ->whereDate('due_date', '<', today()),
            'relatorioNaoConformidades as open_rncs_count' => fn (Builder $query): Builder => $query->where('status', 'aberta'),
            'projectDocuments as pending_projects_count' => fn (Builder $query): Builder => $query->whereIn('status', ['em_analise', 'em_aprovacao']),
            'projectDocuments as approved_projects_count' => fn (Builder $query): Builder => $query->where('status', 'ativo'),
        ]);

        return Inertia::render('Tenant/Contracts/Show', [
            'tenant' => $tenant,
            'contract' => $contract,
            'recentActivities' => $contract->activities()
                ->visibleTo($request->user())
                ->with('assignees:id,name,avatar_url')
                ->latest()
                ->limit(5)
                ->get(['id', 'contract_id', 'created_by_id', 'assigned_to_id', 'title', 'category', 'visibility', 'status', 'priority', 'due_date', 'created_at']),
            'recentRncs' => $contract->relatorioNaoConformidades()
                ->with('disciplina:id,nome,sigla')
                ->latest()
                ->limit(5)
                ->get(['id', 'contract_id', 'disciplina_id', 'sequence_number', 'sequence_year', 'gravidade', 'status', 'opened_at']),
            'recentProjects' => $contract->projectDocuments()
                ->with([
                    'disciplina:id,nome,sigla',
                    'latestVersion' => fn ($query) => $query->select([
                        'project_document_versions.id',
                        'project_document_versions.project_document_id',
                        'project_document_versions.revision',
                        'project_document_versions.derivative_status',
                    ]),
                ])
                ->latest()
                ->limit(5)
                ->get(['id', 'contract_id', 'disciplina_id', 'title', 'code', 'status', 'created_at']),
            'additives' => $contract->contractAdditives()
                ->with('user:id,name')
                ->latest('sequence_number')
                ->get(),
            'capabilities' => [
                'viewActivities' => ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::VIEW, $contract),
                'createActivity' => ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::CREATE, $contract),
                'viewProjects' => ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::VIEW, $contract),
                'uploadProject' => ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::UPLOAD, $contract),
                'viewRncs' => RncPermissions::can($request->user(), $tenant, RncPermissions::VIEW, $contract),
                'createRnc' => RncPermissions::can($request->user(), $tenant, RncPermissions::CREATE, $contract),
                'manageContracts' => $canParametrize || $canManageAdditives,
                'parametrizeContract' => $canParametrize,
                'manageAdditives' => $canManageAdditives,
                'manageParticipants' => $canManageParticipants,
            ],
            'parametrizacao' => [
                'empresas' => $contract->empresas()
                    ->with('tipoEmpresa:id,nome')
                    ->orderBy('nome')
                    ->get(['id', 'tenant_id', 'contract_id', 'tipo_empresa_id', 'nome', 'cnpj', 'sigla', 'logo_path']),
                'obras' => $contract->obras()
                    ->orderBy('codigo')
                    ->get(['id', 'tenant_id', 'contract_id', 'obra_pai_id', 'codigo', 'nome', 'tipo']),
                'trechos' => $tenant->trechos()
                    ->whereHas('obra', fn ($query) => $query->where('contract_id', $contract->id))
                    ->with('obra:id,contract_id,codigo,nome')
                    ->orderBy('obra_id')
                    ->orderByDesc('is_default')
                    ->orderBy('codigo')
                    ->get(['id', 'tenant_id', 'obra_id', 'codigo', 'nome', 'is_default']),
                'disciplinas' => $tenant->disciplinas()
                    ->where('contract_id', $contract->id)
                    ->orderBy('nome')
                    ->get(['id', 'tenant_id', 'contract_id', 'nome', 'sigla', 'cor']),
                'tiposEmpresa' => TipoEmpresa::allowedCompanyTypeOptions(),
            ],
        ]);
    }

    public function parametrize(Request $request, Tenant $tenant, Contract $contract): RedirectResponse
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);
        $this->authorizeManageContracts($request, $tenant, ContractPermissions::PARAMETRIZE, $contract);

        $clienteTipoIds = TipoEmpresa::query()->whereIn('nome', ['cliente'])->pluck('id');
        $construtoraTipoIds = TipoEmpresa::query()->whereIn('nome', ['construtora'])->pluck('id');
        $gerenciadoraTipoIds = TipoEmpresa::query()->whereIn('nome', ['gerenciadora'])->pluck('id');

        $data = $request->validate([
            'obra_id' => [
                'sometimes',
                'nullable',
                Rule::exists('obras', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $contract->id)
                    ->where('tipo', 'pai')),
            ],
            'cliente_empresa_id' => [
                'sometimes',
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $contract->id)
                    ->when($clienteTipoIds->isNotEmpty(), fn ($query) => $query->whereIn('tipo_empresa_id', $clienteTipoIds))),
            ],
            'construtora_empresa_id' => [
                'sometimes',
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $contract->id)
                    ->when($construtoraTipoIds->isNotEmpty(), fn ($query) => $query->whereIn('tipo_empresa_id', $construtoraTipoIds))),
            ],
            'gerenciadora_empresa_id' => [
                'sometimes',
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $contract->id)
                    ->when($gerenciadoraTipoIds->isNotEmpty(), fn ($query) => $query->whereIn('tipo_empresa_id', $gerenciadoraTipoIds))),
            ],
            'measurement_mode' => ['sometimes', 'required', 'string', Rule::in(['simple', 'controlled'])],
        ], [
            'obra_id.exists' => 'Selecione uma obra pai deste contrato como obra principal.',
            'cliente_empresa_id.exists' => 'A empresa cliente selecionada não pertence ao contrato ou não é do tipo cliente.',
            'construtora_empresa_id.exists' => 'A construtora selecionada não pertence ao contrato ou não é do tipo construtora.',
            'gerenciadora_empresa_id.exists' => 'A gerenciadora selecionada não pertence ao contrato ou não é do tipo gerenciadora.',
            'measurement_mode.required' => 'Escolha o tipo de medição deste contrato.',
        ]);

        if (
            array_key_exists('measurement_mode', $data)
            && $contract->measurement_mode
            && $contract->measurement_mode !== $data['measurement_mode']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'measurement_mode' => 'O tipo de medição não pode ser alterado depois de definido.',
            ]);
        }

        $updates = [];

        if (array_key_exists('obra_id', $data)) {
            $updates['obra_id'] = $data['obra_id'];
            $updates['name'] = $this->obraNome($tenant, $data['obra_id']) ?? $contract->name;
        }

        if (array_key_exists('cliente_empresa_id', $data)) {
            $updates['cliente_empresa_id'] = $data['cliente_empresa_id'];
            $updates['client_company_name'] = $this->empresaNome($tenant, $data['cliente_empresa_id']);
        }

        if (array_key_exists('construtora_empresa_id', $data)) {
            $updates['construtora_empresa_id'] = $data['construtora_empresa_id'];
            $updates['contractor_company_name'] = $this->empresaNome($tenant, $data['construtora_empresa_id']);
        }

        if (array_key_exists('gerenciadora_empresa_id', $data)) {
            $updates['fiscalizadora_empresa_id'] = $data['gerenciadora_empresa_id'];
        }

        if (array_key_exists('measurement_mode', $data)) {
            $updates['measurement_mode'] = $contract->measurement_mode ?: $data['measurement_mode'];
        }

        $contract->update($updates);

        return back()->with('success', 'Parametrização do contrato atualizada.');
    }

    private function accessibleContracts(Request $request, Tenant $tenant)
    {
        $query = $tenant->contracts();
        $contractIds = ContractPermissions::contractIdsFor($request->user(), $tenant, ContractPermissions::VIEW);

        if ($contractIds !== null) {
            $query->whereKey($contractIds);
        }

        return $query;
    }

    private function canAccessContract(Request $request, Tenant $tenant, Contract $contract): bool
    {
        return ContractPermissions::can($request->user(), $tenant, ContractPermissions::VIEW, $contract);
    }

    private function authorizeManageContracts(Request $request, Tenant $tenant, string $permission, ?Contract $contract = null): void
    {
        abort_unless($contract
            ? ContractPermissions::can($request->user(), $tenant, $permission, $contract)
            : ContractPermissions::canAny($request->user(), $tenant, $permission), 403);
    }

    private function empresaNome(Tenant $tenant, ?int $empresaId): ?string
    {
        if (! $empresaId) {
            return null;
        }

        return $tenant->empresas()->whereKey($empresaId)->value('nome');
    }

    private function obraNome(Tenant $tenant, ?int $obraId): ?string
    {
        if (! $obraId) {
            return null;
        }

        return $tenant->obras()->whereKey($obraId)->value('nome');
    }

}
