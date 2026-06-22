<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Empresa;
use App\Models\MedicaoItem;
use App\Models\Obra;
use App\Models\OrdemServico;
use App\Models\OrdemServicoAnalise;
use App\Models\OrdemServicoObraResponsavel;
use App\Models\ProjectDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrdemServicoApprovalDecisionNotification;
use App\Notifications\OrdemServicoReadyForApprovalNotification;
use App\Notifications\OrdemServicoSubmittedForReviewNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrdemServicoController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        $selectedContractId = $request->integer('contract_id') ?: $tenant->contracts()->orderBy('code')->value('id');

        $contracts = $tenant->contracts()
            ->with('obra')
            ->orderBy('code')
            ->get()
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
                'obra_id' => $contract->obra_id,
            ]);

        $ordens = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->with([
                'contract:id,code,name',
                'obra:id,nome,codigo',
                'projectDocument:id,title,code',
                'projectDocuments:id,title,code',
                'gerenciadoraEmpresa:id,nome,sigla,tipo_empresa_id',
                'construtoraEmpresa:id,nome,sigla,tipo_empresa_id',
                'creator:id,name,email,avatar_url',
                'itens.medicaoItem:id,item,codigo,descricao,unidade,valor_com_bdi,valor_total',
                'itens.medicaoItem.reajusteIndice.indice.competencias',
                'responsaveis.user:id,name,email,avatar_url',
                'documentos:id,ordem_servico_id,nome_original,size',
                'submittedBy:id,name,email',
                'analyzedBy:id,name,email',
                'approvalDecidedBy:id,name,email',
            ])
            ->latest('id')
            ->get()
            ->map(fn (OrdemServico $ordem): array => $this->serializeOrdem($ordem));

        return Inertia::render('Tenant/OrdemServico/Index', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contracts,
            'ordens' => $ordens,
            'options' => [
                'obras' => $this->obraOptions($tenant, $selectedContractId),
                'projects' => $this->projectOptions($tenant, $selectedContractId),
                'items' => $this->itemOptions($tenant, $selectedContractId),
                'empresas' => $this->empresaOptions($tenant, $selectedContractId),
            ],
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'obra_id' => ['required', Rule::exists('obras', 'id')->where('tenant_id', $tenant->id)],
            'project_document_id' => ['nullable', Rule::exists('project_documents', 'id')->where('tenant_id', $tenant->id)],
            'project_document_ids' => ['nullable', 'array'],
            'project_document_ids.*' => ['integer', Rule::exists('project_documents', 'id')->where('tenant_id', $tenant->id)],
            'gerenciadora_empresa_id' => ['required', Rule::exists('empresas', 'id')->where('tenant_id', $tenant->id)],
            'construtora_empresa_id' => ['required', Rule::exists('empresas', 'id')->where('tenant_id', $tenant->id)],
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'prazo_execucao' => ['nullable', 'date'],
            'custo_previsto' => ['nullable', 'string', 'max:50'],
            'custo_observacao' => ['nullable', 'string'],
            'item_ids' => ['array'],
            'item_ids.*' => ['integer', Rule::exists('medicao_itens', 'id')->where('tenant_id', $tenant->id)],
            'documentos' => ['array'],
            'documentos.*' => ['file', 'max:30720'],
        ]);

        $contract = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($validated['contract_id']);

        $obra = Obra::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->findOrFail($validated['obra_id']);

        $requestedProjectIds = collect($validated['project_document_ids'] ?? [])
            ->when(! empty($validated['project_document_id']), fn ($collection) => $collection->push($validated['project_document_id']))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $projects = ProjectDocument::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('obra_id', $obra->id)
            ->whereIn('id', $requestedProjectIds)
            ->get(['id', 'obra_id']);

        abort_if(
            $projects->count() !== $requestedProjectIds->count(),
            422,
            'Um ou mais projetos selecionados nao pertencem ao contrato/obra da OS.'
        );

        foreach (['gerenciadora_empresa_id', 'construtora_empresa_id'] as $empresaField) {
            abort_unless(
                Empresa::query()
                    ->where('tenant_id', $tenant->id)
                    ->where(function ($query) use ($contract) {
                        $query->whereNull('contract_id')
                            ->orWhere('contract_id', $contract->id);
                    })
                    ->whereKey($validated[$empresaField])
                    ->exists(),
                422,
                'A empresa selecionada nao pertence ao contrato.'
            );
        }

        $requestedItemIds = collect($validated['item_ids'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $items = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('item_type', '!=', 'etapa')
            ->whereNotNull('codigo')
            ->where('codigo', '!=', '')
            ->whereIn('id', $requestedItemIds)
            ->get();

        abort_if(
            $items->count() !== $requestedItemIds->count(),
            422,
            'Itens de etapa/cabecalho nao podem ser vinculados a uma OS.'
        );

        DB::transaction(function () use ($request, $tenant, $contract, $obra, $validated, $items, $requestedProjectIds): void {
            [$codigo, $sequencial] = $this->nextCode($tenant, $contract, $obra);

            $ordem = OrdemServico::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'project_document_id' => $requestedProjectIds->first(),
                'gerenciadora_empresa_id' => $validated['gerenciadora_empresa_id'],
                'construtora_empresa_id' => $validated['construtora_empresa_id'],
                'created_by_id' => $request->user()?->id,
                'codigo' => $codigo,
                'sequencial' => $sequencial,
                'titulo' => $validated['titulo'],
                'descricao' => $validated['descricao'] ?? null,
                'prazo_execucao' => $validated['prazo_execucao'] ?? null,
                'custo_previsto' => $this->parseDecimal($validated['custo_previsto'] ?? null) ?? $items->sum(fn (MedicaoItem $item): float => (float) $item->valor_total),
                'custo_observacao' => $validated['custo_observacao'] ?? null,
                'status' => 'rascunho',
            ]);

            $ordem->projectDocuments()->sync($requestedProjectIds->all());

            foreach ($items as $item) {
                $ordem->itens()->create([
                    'medicao_item_id' => $item->id,
                    'quantidade_solicitada' => $item->quantidade_prevista,
                    'valor_previsto' => $item->valor_total,
                ]);
            }

            foreach ($request->file('documentos', []) as $file) {
                $path = $file->store("tenant-{$tenant->id}/ordens-servico/os-{$ordem->id}", 'public');

                $ordem->documentos()->create([
                    'uploaded_by_id' => $request->user()?->id,
                    'nome_original' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?: 0,
                ]);
            }
        });

        return back()->with('success', 'Ordem de servico criada com sucesso.');
    }

    public function submitForAnalysis(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        if ($ordem->status !== 'rascunho') {
            throw ValidationException::withMessages([
                'status' => 'Somente OS em rascunho podem ser enviadas para análise.',
            ]);
        }

        if (! $ordem->obra_id) {
            throw ValidationException::withMessages([
                'obra_id' => 'Vincule uma obra antes de enviar a OS para análise.',
            ]);
        }

        $fiscais = $this->responsaveisDaObra($tenant, $ordem, 'fiscal');

        if ($fiscais->isEmpty()) {
            throw ValidationException::withMessages([
                'responsaveis' => 'Cadastre ao menos um fiscal responsável pela obra antes de enviar para análise.',
            ]);
        }

        DB::transaction(function () use ($request, $ordem): void {
            $ordem->forceFill([
                'status' => 'em_analise',
                'submitted_for_review_at' => now(),
                'submitted_for_review_by_id' => $request->user()?->id,
            ])->save();

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'analise',
                'decisao' => 'enviada',
                'observacao' => 'OS enviada para análise.',
            ]);
        });

        $ordem->refresh()->loadMissing(['tenant', 'contract', 'obra']);
        $actor = $request->user();

        if ($actor) {
            $fiscais->each(fn (User $user) => $user->notify(new OrdemServicoSubmittedForReviewNotification($ordem, $actor)));
        }

        return back()->with('success', 'OS enviada para análise. Os fiscais da obra foram notificados.');
    }

    public function analise(Request $request, Tenant $tenant): Response
    {
        $selectedContractId = $request->integer('contract_id') ?: $tenant->contracts()->orderBy('code')->value('id');

        $contracts = $tenant->contracts()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
            ]);

        $ordens = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->whereIn('status', ['em_analise', 'em_aprovacao', 'aprovada', 'recusada'])
            ->with([
                'contract:id,code,name',
                'obra:id,nome,codigo',
                'creator:id,name,email,avatar_url',
            ])
            ->latest('id')
            ->get()
            ->map(fn (OrdemServico $ordem): array => array_merge($this->serializeOrdemAnalysisSummary($ordem), [
                'can_analyze' => $this->canActOnOrdem($request, $tenant, $ordem, 'fiscal'),
                'can_approve' => $this->canActOnOrdem($request, $tenant, $ordem, 'aprovador'),
            ]));

        return Inertia::render('Tenant/OrdemServico/Analise', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contracts,
            'ordens' => $ordens,
        ]);
    }

    public function analiseDetalhes(Request $request, Tenant $tenant, OrdemServico $ordem): JsonResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        $ordem->load([
            'contract:id,code,name',
            'obra:id,nome,codigo',
            'projectDocument:id,tenant_id,contract_id,obra_id,title,code,status',
            'projectDocument.openRncs:id,tenant_id,project_document_id,sequence_number,sequence_year,opened_at,status',
            'projectDocument.latestApprovedVersion',
            'projectDocument.latestVersion',
            'projectDocuments:id,tenant_id,contract_id,obra_id,title,code,status',
            'projectDocuments.openRncs:id,tenant_id,project_document_id,sequence_number,sequence_year,opened_at,status',
            'projectDocuments.latestApprovedVersion',
            'projectDocuments.latestVersion',
            'gerenciadoraEmpresa:id,nome,sigla',
            'construtoraEmpresa:id,nome,sigla',
            'creator:id,name,email,avatar_url',
            'itens.medicaoItem:id,item,codigo,descricao,unidade,valor_com_bdi,valor_total',
            'itens.medicaoItem.reajusteIndice.indice.competencias',
            'documentos:id,ordem_servico_id,nome_original,size',
            'responsaveis.user:id,name,email,avatar_url',
            'submittedBy:id,name,email',
            'analyzedBy:id,name,email',
            'approvalDecidedBy:id,name,email',
            'analises.user:id,name,email',
        ]);

        return response()->json([
            'ordem' => array_merge($this->serializeOrdem($ordem), [
                'projects' => $this->serializeAnalysisProjects($tenant, $ordem),
                'can_analyze' => $this->canActOnOrdem($request, $tenant, $ordem, 'fiscal'),
                'can_approve' => $this->canActOnOrdem($request, $tenant, $ordem, 'aprovador'),
            ]),
        ]);
    }

    public function analyze(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);
        $this->authorizeOrdemAction($request, $tenant, $ordem, 'fiscal');

        if ($ordem->status !== 'em_analise') {
            throw ValidationException::withMessages([
                'status' => 'Somente OS em análise podem ser enviadas para aprovação.',
            ]);
        }

        $validated = $request->validate([
            'observacao' => ['nullable', 'string'],
        ]);

        $aprovadores = $this->responsaveisDaObra($tenant, $ordem, 'aprovador');

        if ($aprovadores->isEmpty()) {
            throw ValidationException::withMessages([
                'responsaveis' => 'Cadastre ao menos um aprovador responsável pela obra antes de enviar para aprovação.',
            ]);
        }

        DB::transaction(function () use ($request, $ordem, $validated): void {
            $ordem->forceFill([
                'status' => 'em_aprovacao',
                'analyzed_at' => now(),
                'analyzed_by_id' => $request->user()?->id,
                'analysis_observation' => $validated['observacao'] ?? null,
            ])->save();

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'analise',
                'decisao' => 'analisada',
                'observacao' => $validated['observacao'] ?? null,
            ]);
        });

        $ordem->refresh()->loadMissing(['tenant', 'contract', 'obra']);
        $actor = $request->user();

        if ($actor) {
            $aprovadores->each(fn (User $user) => $user->notify(new OrdemServicoReadyForApprovalNotification($ordem, $actor)));
        }

        return back()->with('success', 'Análise registrada e OS enviada para aprovação.');
    }

    public function approve(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);
        $this->authorizeOrdemAction($request, $tenant, $ordem, 'aprovador');

        if ($ordem->status !== 'em_aprovacao') {
            throw ValidationException::withMessages([
                'status' => 'Somente OS em aprovação podem ser aprovadas ou recusadas.',
            ]);
        }

        $validated = $request->validate([
            'decisao' => ['required', Rule::in(['aprovar', 'recusar'])],
            'observacao' => ['nullable', 'required_if:decisao,recusar', 'string'],
        ]);

        $status = $validated['decisao'] === 'aprovar' ? 'aprovada' : 'recusada';

        DB::transaction(function () use ($request, $ordem, $validated, $status): void {
            $ordem->forceFill([
                'status' => $status,
                'approval_decided_at' => now(),
                'approval_decided_by_id' => $request->user()?->id,
                'approval_observation' => $validated['observacao'] ?? null,
            ])->save();

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'aprovacao',
                'decisao' => $status,
                'observacao' => $validated['observacao'] ?? null,
            ]);
        });

        $ordem->refresh()->loadMissing(['tenant', 'contract', 'obra', 'creator']);
        $actor = $request->user();

        if ($actor) {
            $notifiables = $this->responsaveisDaObra($tenant, $ordem, 'fiscal')
                ->push($ordem->creator)
                ->filter()
                ->unique('id');

            $notifiables->each(fn (User $user) => $user->notify(new OrdemServicoApprovalDecisionNotification(
                $ordem,
                $actor,
                $status,
                $validated['observacao'] ?? null
            )));
        }

        return back()->with('success', $status === 'aprovada' ? 'OS aprovada com sucesso.' : 'OS recusada. O solicitante foi notificado.');
    }

    public function responsaveis(Request $request, Tenant $tenant): Response
    {
        $selectedContractId = $request->integer('contract_id') ?: $tenant->contracts()->orderBy('code')->value('id');

        $contracts = $tenant->contracts()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
            ]);

        $responsaveis = OrdemServicoObraResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->with(['contract:id,code,name', 'obra:id,codigo,nome', 'user:id,name,email,avatar_url'])
            ->orderBy('tipo')
            ->latest('id')
            ->get()
            ->map(fn (OrdemServicoObraResponsavel $responsavel): array => [
                'id' => $responsavel->id,
                'tipo' => $responsavel->tipo,
                'tipo_label' => $responsavel->tipo === 'aprovador' ? 'Aprovador' : 'Fiscal',
                'contract' => $responsavel->contract ? [
                    'id' => $responsavel->contract->id,
                    'code' => $responsavel->contract->code,
                    'name' => $responsavel->contract->name,
                ] : null,
                'obra' => $responsavel->obra ? [
                    'id' => $responsavel->obra->id,
                    'codigo' => $responsavel->obra->codigo,
                    'nome' => $responsavel->obra->nome,
                ] : null,
                'user' => $responsavel->user ? [
                    'id' => $responsavel->user->id,
                    'name' => $responsavel->user->name,
                    'email' => $responsavel->user->email,
                    'avatar_url' => $responsavel->user->avatar_url,
                ] : null,
            ]);

        return Inertia::render('Tenant/OrdemServico/Responsaveis', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contracts,
            'obras' => $this->obraOptions($tenant, $selectedContractId),
            'users' => $this->userOptions($tenant, $selectedContractId),
            'responsaveis' => $responsaveis,
        ]);
    }

    public function storeResponsavel(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'obra_id' => ['required', Rule::exists('obras', 'id')->where('tenant_id', $tenant->id)],
            'user_id' => ['required', Rule::exists('users', 'id')],
            'tipo' => ['required', Rule::in(['fiscal', 'aprovador'])],
        ]);

        abort_unless(
            Obra::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $validated['contract_id'])
                ->whereKey($validated['obra_id'])
                ->exists(),
            422,
            'A obra selecionada não pertence ao contrato.'
        );

        abort_unless(
            $this->userHasContractAccess($tenant, (int) $validated['contract_id'], (int) $validated['user_id']),
            422,
            'O usuário selecionado não possui vínculo com este contrato.'
        );

        $responsavel = OrdemServicoObraResponsavel::withTrashed()->firstOrNew([
            'tenant_id' => $tenant->id,
            'obra_id' => $validated['obra_id'],
            'user_id' => $validated['user_id'],
            'tipo' => $validated['tipo'],
        ]);

        $responsavel->fill([
            'contract_id' => $validated['contract_id'],
            'created_by_id' => $request->user()?->id,
            'status' => 'active',
        ]);

        if ($responsavel->trashed()) {
            $responsavel->restore();
        }

        $responsavel->save();

        return back()->with('success', 'Responsável da obra cadastrado com sucesso.');
    }

    public function destroyResponsavel(Tenant $tenant, OrdemServicoObraResponsavel $responsavel): RedirectResponse
    {
        abort_unless($responsavel->tenant_id === $tenant->id, 404);

        $responsavel->forceFill(['status' => 'inactive'])->save();
        $responsavel->delete();

        return back()->with('success', 'Responsável removido da obra.');
    }

    /**
     * @return array{string, int}
     */
    private function nextCode(Tenant $tenant, Contract $contract, Obra $obra): array
    {
        Obra::query()
            ->whereKey($obra->id)
            ->lockForUpdate()
            ->firstOrFail();

        $next = OrdemServico::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('obra_id', $obra->id)
            ->max('sequencial') + 1;

        $codigo = collect([$contract->code, $obra->codigo, 'OS', str_pad((string) $next, 3, '0', STR_PAD_LEFT)])
            ->map(fn (?string $part): string => mb_strtoupper((string) $part))
            ->map(fn (string $part): string => preg_replace('/\s+/', '', trim($part)) ?? '')
            ->map(fn (string $part): string => preg_replace('/[^A-Z0-9]/', '', $part) ?? '')
            ->filter()
            ->implode('-');

        return [$codigo, $next];
    }

    private function parseDecimal(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        $normalized = preg_replace('/[^\d,.-]/', '', $normalized) ?? '';

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = strrpos($normalized, ',') > strrpos($normalized, '.')
                ? str_replace('.', '', $normalized)
                : str_replace(',', '', $normalized);
        }

        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function serializeOrdemAnalysisSummary(OrdemServico $ordem): array
    {
        return [
            'id' => $ordem->id,
            'codigo' => $ordem->codigo,
            'titulo' => $ordem->titulo,
            'status' => $ordem->status,
            'custo_previsto' => (float) $ordem->custo_previsto,
            'dias_desde_submissao' => $this->reviewAgeInDays($ordem),
            'contract' => $ordem->contract ? [
                'id' => $ordem->contract->id,
                'code' => $ordem->contract->code,
                'name' => $ordem->contract->name,
            ] : null,
            'obra' => $ordem->obra ? [
                'id' => $ordem->obra->id,
                'codigo' => $ordem->obra->codigo,
                'nome' => $ordem->obra->nome,
            ] : null,
            'solicitante' => $ordem->creator ? [
                'id' => $ordem->creator->id,
                'name' => $ordem->creator->name,
                'email' => $ordem->creator->email,
                'avatar_url' => $ordem->creator->avatar_url,
            ] : null,
        ];
    }

    private function serializeAnalysisProjects(Tenant $tenant, OrdemServico $ordem): array
    {
        $projects = $ordem->projectDocuments->isNotEmpty()
            ? $ordem->projectDocuments
            : collect($ordem->projectDocument ? [$ordem->projectDocument] : []);

        return $projects
            ->map(function (ProjectDocument $project) use ($tenant): array {
                $version = $project->latestApprovedVersion ?: $project->latestVersion;
                $openRncs = $project->openRncs;
                $firstOpenRnc = $openRncs->first();

                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                    'status' => $project->status,
                    'open_rncs_count' => $openRncs->count(),
                    'first_open_rnc' => $firstOpenRnc ? [
                        'id' => $firstOpenRnc->id,
                        'number' => $firstOpenRnc->formatted_number,
                        'url' => route('tenant.qualidade.rnc.show', [$tenant, $firstOpenRnc]),
                    ] : null,
                    'url' => $version
                        ? route('tenant.projects.viewer', [$tenant, $version]).'?workspace=visualizar&origin=ordem-servico'
                        : route('tenant.projects.index', $tenant),
                ];
            })
            ->values()
            ->all();
    }

    private function serializeOrdem(OrdemServico $ordem): array
    {
        return [
            'id' => $ordem->id,
            'codigo' => $ordem->codigo,
            'sequencial' => $ordem->sequencial,
            'titulo' => $ordem->titulo,
            'descricao' => $ordem->descricao,
            'status' => $ordem->status,
            'prazo_execucao' => $ordem->prazo_execucao?->format('Y-m-d'),
            'prazo_execucao_label' => $ordem->prazo_execucao?->format('d/m/Y'),
            'custo_previsto' => (float) $ordem->custo_previsto,
            'contract' => $ordem->contract ? [
                'id' => $ordem->contract->id,
                'code' => $ordem->contract->code,
                'name' => $ordem->contract->name,
            ] : null,
            'obra' => $ordem->obra ? [
                'id' => $ordem->obra->id,
                'codigo' => $ordem->obra->codigo,
                'nome' => $ordem->obra->nome,
            ] : null,
            'project' => $ordem->projectDocument ? [
                'id' => $ordem->projectDocument->id,
                'code' => $ordem->projectDocument->code,
                'title' => $ordem->projectDocument->title,
            ] : null,
            'projects' => ($ordem->relationLoaded('projectDocuments') && $ordem->projectDocuments->isNotEmpty()
                ? $ordem->projectDocuments
                : collect($ordem->projectDocument ? [$ordem->projectDocument] : []))
                ->map(fn (ProjectDocument $project): array => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                ])
                ->values(),
            'gerenciadora_empresa' => $ordem->gerenciadoraEmpresa ? [
                'id' => $ordem->gerenciadoraEmpresa->id,
                'nome' => $ordem->gerenciadoraEmpresa->nome,
                'sigla' => $ordem->gerenciadoraEmpresa->sigla,
            ] : null,
            'construtora_empresa' => $ordem->construtoraEmpresa ? [
                'id' => $ordem->construtoraEmpresa->id,
                'nome' => $ordem->construtoraEmpresa->nome,
                'sigla' => $ordem->construtoraEmpresa->sigla,
            ] : null,
            'solicitante' => $ordem->creator ? [
                'id' => $ordem->creator->id,
                'name' => $ordem->creator->name,
                'email' => $ordem->creator->email,
                'avatar_url' => $ordem->creator->avatar_url,
            ] : null,
            'itens' => $ordem->itens->map(fn ($item): array => [
                'id' => $item->id,
                'item' => $item->medicaoItem?->item,
                'codigo' => $item->medicaoItem?->codigo,
                'descricao' => $item->medicaoItem?->descricao,
                'valor_previsto' => (float) $item->valor_previsto,
                'valor_reajustado' => $this->adjustedValue(
                    (float) $item->valor_previsto,
                    $item->medicaoItem
                ),
                'percentual_reajuste' => $this->adjustmentPercentage($item->medicaoItem),
            ])->values(),
            'responsaveis' => $ordem->responsaveis->map(fn ($responsavel): array => [
                'id' => $responsavel->id,
                'name' => $responsavel->user?->name,
                'email' => $responsavel->user?->email,
                'avatar_url' => $responsavel->user?->avatar_url,
            ])->values(),
            'submitted_for_review_at' => $ordem->submitted_for_review_at?->format('d/m/Y H:i'),
            'dias_desde_submissao' => $this->reviewAgeInDays($ordem),
            'submitted_by' => $ordem->submittedBy ? [
                'id' => $ordem->submittedBy->id,
                'name' => $ordem->submittedBy->name,
                'email' => $ordem->submittedBy->email,
            ] : null,
            'analyzed_at' => $ordem->analyzed_at?->format('d/m/Y H:i'),
            'analyzed_by' => $ordem->analyzedBy ? [
                'id' => $ordem->analyzedBy->id,
                'name' => $ordem->analyzedBy->name,
                'email' => $ordem->analyzedBy->email,
            ] : null,
            'analysis_observation' => $ordem->analysis_observation,
            'approval_decided_at' => $ordem->approval_decided_at?->format('d/m/Y H:i'),
            'approval_decided_by' => $ordem->approvalDecidedBy ? [
                'id' => $ordem->approvalDecidedBy->id,
                'name' => $ordem->approvalDecidedBy->name,
                'email' => $ordem->approvalDecidedBy->email,
            ] : null,
            'approval_observation' => $ordem->approval_observation,
            'analises' => $ordem->relationLoaded('analises') ? $ordem->analises->map(fn (OrdemServicoAnalise $analise): array => [
                'id' => $analise->id,
                'tipo' => $analise->tipo,
                'decisao' => $analise->decisao,
                'observacao' => $analise->observacao,
                'created_at' => $analise->created_at?->format('d/m/Y H:i'),
                'user' => $analise->user ? [
                    'id' => $analise->user->id,
                    'name' => $analise->user->name,
                    'email' => $analise->user->email,
                ] : null,
            ])->values() : [],
            'documentos_count' => $ordem->documentos->count(),
            'created_at' => $ordem->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function reviewAgeInDays(OrdemServico $ordem): int
    {
        if (! $ordem->submitted_for_review_at) {
            return 0;
        }

        return (int) max(
            0,
            $ordem->submitted_for_review_at
                ->copy()
                ->startOfDay()
                ->diffInDays(now()->startOfDay())
        );
    }

    private function obraOptions(Tenant $tenant, ?int $contractId): array
    {
        return Obra::query()
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where('contract_id', $contractId))
            ->orderBy('codigo')
            ->get(['id', 'contract_id', 'codigo', 'nome'])
            ->map(fn (Obra $obra): array => [
                'id' => $obra->id,
                'contract_id' => $obra->contract_id,
                'label' => trim(($obra->codigo ? "{$obra->codigo} - " : '').$obra->nome),
            ])
            ->values()
            ->all();
    }

    private function projectOptions(Tenant $tenant, ?int $contractId): array
    {
        return ProjectDocument::query()
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where('contract_id', $contractId))
            ->where('status', 'ativo')
            ->whereNull('inactive_at')
            ->orderBy('code')
            ->get(['id', 'contract_id', 'obra_id', 'code', 'title'])
            ->map(fn (ProjectDocument $project): array => [
                'id' => $project->id,
                'contract_id' => $project->contract_id,
                'obra_id' => $project->obra_id,
                'label' => trim(($project->code ? "{$project->code} - " : '').$project->title),
            ])
            ->values()
            ->all();
    }

    private function itemOptions(Tenant $tenant, ?int $contractId): array
    {
        return MedicaoItem::query()
            ->with('reajusteIndice.indice.competencias')
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where('contract_id', $contractId))
            ->where('item_type', '!=', 'etapa')
            ->whereNotNull('codigo')
            ->where('codigo', '!=', '')
            ->orderByRaw("string_to_array(item, '.')::int[]")
            ->get(['id', 'contract_id', 'item', 'item_type', 'codigo', 'descricao', 'unidade', 'quantidade_prevista', 'valor_com_bdi', 'valor_total'])
            ->map(fn (MedicaoItem $item): array => [
                'id' => $item->id,
                'contract_id' => $item->contract_id,
                'item' => $item->item,
                'item_type' => $item->item_type,
                'planilha' => explode('.', (string) $item->item)[0] ?: null,
                'codigo' => $item->codigo,
                'descricao' => $item->descricao,
                'unidade' => $item->unidade,
                'quantidade_prevista' => (float) $item->quantidade_prevista,
                'valor_com_bdi' => (float) $item->valor_com_bdi,
                'valor_total' => (float) $item->valor_total,
                'valor_total_p0' => (float) $item->valor_total,
                'valor_total_reajustado' => $this->adjustedValue((float) $item->valor_total, $item),
                'percentual_reajuste' => $this->adjustmentPercentage($item),
                'label' => trim("{$item->item} - {$item->codigo} - {$item->descricao}"),
            ])
            ->values()
            ->all();
    }

    private function adjustedValue(float $baseValue, ?MedicaoItem $item): float
    {
        return round($baseValue * (1 + ($this->adjustmentPercentage($item) / 100)), 2);
    }

    private function adjustmentPercentage(?MedicaoItem $item): float
    {
        $indice = $item?->reajusteIndice?->indice;

        if (! $indice || (float) $indice->indice_base <= 0) {
            return 0.0;
        }

        $latestCompetencia = $indice->competencias
            ->sortByDesc('competencia')
            ->first();

        $currentIndex = $latestCompetencia
            ? (float) $latestCompetencia->valor_indice
            : (float) $indice->indice_atual;

        return (($currentIndex - (float) $indice->indice_base) / (float) $indice->indice_base) * 100;
    }

    private function empresaOptions(Tenant $tenant, ?int $contractId): array
    {
        return Empresa::query()
            ->with('tipoEmpresa:id,nome')
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where(function ($subquery) use ($contractId) {
                $subquery->whereNull('contract_id')
                    ->orWhere('contract_id', $contractId);
            }))
            ->orderBy('nome')
            ->get(['id', 'tenant_id', 'contract_id', 'tipo_empresa_id', 'nome', 'sigla'])
            ->map(fn (Empresa $empresa): array => [
                'id' => $empresa->id,
                'contract_id' => $empresa->contract_id,
                'nome' => $empresa->nome,
                'sigla' => $empresa->sigla,
                'tipo_nome' => $empresa->tipoEmpresa?->nome,
                'tipo_slug' => (string) str($empresa->tipoEmpresa?->nome ?? '')->lower()->ascii()->slug('_'),
                'label' => trim(($empresa->sigla ? "{$empresa->sigla} - " : '').$empresa->nome),
            ])
            ->values()
            ->all();
    }

    private function userOptions(Tenant $tenant, ?int $contractId): array
    {
        if (! $contractId) {
            return [];
        }

        $tenantUserIds = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->pluck('user_id');

        $contractUserIds = DB::table('contract_participants')
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('user_id');

        return User::query()
            ->whereIn('id', $tenantUserIds->merge($contractUserIds)->unique()->values())
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar_url'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'label' => "{$user->name} - {$user->email}",
            ])
            ->values()
            ->all();
    }

    private function ensureTenantOrdem(Tenant $tenant, OrdemServico $ordem): void
    {
        abort_unless($ordem->tenant_id === $tenant->id, 404);
    }

    private function authorizeOrdemAction(Request $request, Tenant $tenant, OrdemServico $ordem, string $tipo): void
    {
        abort_unless($this->canActOnOrdem($request, $tenant, $ordem, $tipo), 403);
    }

    private function canActOnOrdem(Request $request, Tenant $tenant, OrdemServico $ordem, string $tipo): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->is_platform_admin) {
            return true;
        }

        $tenantRole = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');

        if (in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        if (! $ordem->obra_id) {
            return false;
        }

        return OrdemServicoObraResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $ordem->contract_id)
            ->where('obra_id', $ordem->obra_id)
            ->where('user_id', $user->id)
            ->where('tipo', $tipo)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return Collection<int, User>
     */
    private function responsaveisDaObra(Tenant $tenant, OrdemServico $ordem, string $tipo): Collection
    {
        if (! $ordem->obra_id) {
            return collect();
        }

        return User::query()
            ->whereIn('id', OrdemServicoObraResponsavel::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $ordem->contract_id)
                ->where('obra_id', $ordem->obra_id)
                ->where('tipo', $tipo)
                ->where('status', 'active')
                ->select('user_id'))
            ->orderBy('name')
            ->get();
    }

    private function userHasContractAccess(Tenant $tenant, int $contractId, int $userId): bool
    {
        $isTenantUser = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();

        if ($isTenantUser) {
            return true;
        }

        return DB::table('contract_participants')
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
    }

}
