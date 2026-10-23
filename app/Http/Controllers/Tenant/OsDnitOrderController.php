<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\OsDnitOrderAttachment;
use App\Models\OsDnitProduct;
use App\Models\OsDnitSupervisionLot;
use App\Models\Tenant;
use App\Services\OsDnitOrderHistory;
use App\Services\OsDnitOrderNumber;
use App\Services\OsDnitPdfRenderer;
use App\Support\ContractPermissions;
use App\Support\OsDnitContractAccess;
use App\Support\OsDnitPermissions as Permissions;
use App\Support\OsDnitWorkflowAccess as Workflow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OsDnitOrderController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response|RedirectResponse
    {
        if ($request->routeIs('tenant.os-dnit.analysis.index')) {
            return to_route('tenant.os-dnit.orders.index', [$tenant->slug, ...$request->only(['q', 'contract_id', 'supervision_lot_id', 'highway_id', 'status', 'page']), 'pending' => 1]);
        }
        $contracts = $this->contracts($request, $tenant)->orderBy('code')->get(['id', 'code', 'name', 'status']);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'contract_id' => ['nullable', Rule::in($contracts->pluck('id')->all())], 'status' => ['nullable', Rule::in(array_keys(OsDnitOrder::STATUSES))], 'pending' => ['nullable', 'boolean'], 'stage' => ['nullable', Rule::in(['all', ...array_keys(OsDnitOrder::STAGES)])]]);
        $lots = OsDnitSupervisionLot::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->with('contract:id,code')->orderBy('name')->get(['id', 'contract_id', 'name']);
        $highways = OsDnitHighway::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->with('contract:id,code')->orderBy('name')->get(['id', 'contract_id', 'name']);
        $filters += $request->validate([
            'supervision_lot_id' => ['nullable', 'integer', Rule::in($lots->pluck('id')->all())],
            'highway_id' => ['nullable', 'integer', Rule::in($highways->pluck('id')->all())],
        ], [], ['supervision_lot_id' => 'lote de supervisão', 'highway_id' => 'rodovia']);
        $filters['pending'] = $request->boolean('pending');
        $filters['stage'] = $filters['stage'] ?? 'all';
        $request->validate(['analysis_order_id' => ['nullable', 'integer', 'min:1']]);
        $analysisIds = Workflow::pendingOrders($request->user(), $tenant)->pluck('id')->all();
        $orders = OsDnitOrder::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->when($filters['supervision_lot_id'] ?? null, fn ($query, $id) => $query->where('supervision_lot_id', $id))
            ->when($filters['highway_id'] ?? null, fn ($query, $id) => $query->where('highway_id', $id))
            ->when(trim($filters['q'] ?? ''), fn ($query, $term) => $query->where(fn ($query) => $query
                ->whereRaw('LOWER(request_number) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->orWhereRaw('LOWER(subject) LIKE ?', ['%'.mb_strtolower($term).'%'])));
        $statusCounts = (clone $orders)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $stageCounts = ['all' => (int) $statusCounts->sum()];
        foreach (OsDnitOrder::STAGES as $stage => $states) {
            $stageCounts[$stage] = (int) $statusCounts->only($states)->sum();
        }

        return Inertia::render('Tenant/OsDnit/Orders', [
            'orders' => $orders
                ->when($filters['pending'] ?? false, fn ($query) => $query->whereIn('id', $analysisIds))
                ->when($filters['stage'] !== 'all', fn ($query) => $query->whereIn('status', OsDnitOrder::STAGES[$filters['stage']]))
                ->with('contract:id,code,name')->withCount(['items', 'attachments'])
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->latest('id')->paginate(10)->withQueryString()
                ->through(fn (OsDnitOrder $order) => $order->setAttribute('can_submit', Workflow::canSubmit($request->user(), $tenant, $order))
                    ->setAttribute('products_count', count($order->review_items ?? []))
                    ->setAttribute('can_finalize', Workflow::canFinalize($request->user(), $tenant, $order))
                    ->setAttribute('can_analyze', in_array($order->id, $analysisIds, true))
                    ->setAttribute('can_delete', $this->canDelete($request, $tenant, $order))),
            'contracts' => $contracts, 'lots' => $lots, 'highways' => $highways, 'filters' => $filters,
            'emptyTenantSetup' => Permissions::canSetUpEmptyTenant($request->user(), $tenant),
            'canCreateContract' => ContractPermissions::canAny($request->user(), $tenant, ContractPermissions::CREATE),
            'stageCounts' => $stageCounts,
            'analysisOrderId' => $request->filled('analysis_order_id') ? (int) $request->input('analysis_order_id') : null,
            'canCreate' => Workflow::contracts($request->user(), $tenant, 'analyst')->whereIn('id', Permissions::contractIds($request->user(), $tenant, Permissions::CREATE))->exists(),
            'statuses' => OsDnitOrder::STATUSES,
        ]);
    }

    public function create(Request $request, Tenant $tenant): Response
    {
        $contracts = Workflow::contracts($request->user(), $tenant, 'analyst')->whereIn('id', Permissions::contractIds($request->user(), $tenant, Permissions::CREATE))->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('Tenant/OsDnit/CreateOrder', $this->formData($request, $tenant, $contracts));
    }

    public function edit(Request $request, Tenant $tenant, OsDnitOrder $order): Response
    {
        $this->authorizeOrder($request, $tenant, $order);
        abort_unless(Workflow::canEdit($request->user(), $tenant, $order), 403);
        $contracts = $this->contracts($request, $tenant)->whereKey($order->contract_id)->get(['id', 'code', 'name']);

        return Inertia::render('Tenant/OsDnit/CreateOrder', [
            ...$this->formData($request, $tenant, $contracts), 'order' => $order->load(['items', 'attachments']),
        ]);
    }

    private function formData(Request $request, Tenant $tenant, $contracts): array
    {
        return [
            'contracts' => $contracts,
            'numberPreviews' => array_intersect_key(app(OsDnitOrderNumber::class)->previews($request->user(), $tenant), array_fill_keys($contracts->pluck('id')->all(), true)),
            'highways' => OsDnitHighway::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))->where('active', true)->orderBy('name')->get(['id', 'contract_id', 'name']),
            'lots' => OsDnitSupervisionLot::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))->where('active', true)->orderBy('name')->get(['id', 'contract_id', 'name', 'sei_reference']),
        ];
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $contractIds = Workflow::contracts($request->user(), $tenant, 'analyst')->whereIn('id', Permissions::contractIds($request->user(), $tenant, Permissions::CREATE))->pluck('id')->all();
        $request->validate(['contract_id' => ['required', 'integer', Rule::in($contractIds)]]);
        $contractId = (int) $request->input('contract_id');
        $data = $this->validateDemand($request, $tenant, $contractId, $contractIds);

        $disk = (string) config('os_dnit.attachment_disk', 'local');
        $storedPaths = [];
        try {
            $order = DB::transaction(function () use ($request, $tenant, $data, $disk, &$storedPaths) {
                $orderData = $this->withLotSnapshot($tenant, collect($data)->except(['attachments'])->all());
                $requestNumber = app(OsDnitOrderNumber::class)->next($request->user(), $tenant, (int) $data['contract_id']);
                $order = OsDnitOrder::create([
                    ...$orderData, 'tenant_id' => $tenant->id, 'created_by_id' => $request->user()->id,
                    'request_number' => $requestNumber,
                    'highway_name' => OsDnitHighway::where('tenant_id', $tenant->id)->where('contract_id', $data['contract_id'])->findOrFail($data['highway_id'])->name,
                    'status' => 'draft', 'workflow_version' => 1,
                ]);
                foreach ($request->file('attachments', []) as $file) {
                    $path = $file->store("os-dnit/{$tenant->id}/{$order->id}", $disk);
                    if ($path === false) {
                        throw new \RuntimeException('Não foi possível salvar o anexo. Tente novamente.');
                    }
                    $storedPaths[] = $path;
                    $order->attachments()->create([
                        'original_name' => $file->getClientOriginalName(), 'storage_disk' => $disk, 'path' => $path,
                        'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                    ]);
                }

                app(OsDnitOrderHistory::class)->record($order, $request->user(), 'created', null);

                return $order;
            });
        } catch (Throwable $exception) {
            if ($storedPaths) {
                Storage::disk($disk)->delete($storedPaths);
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['request_number' => 'Não foi possível gerar o número da solicitação. Tente salvar novamente.']);
            }
            throw $exception;
        }

        return to_route('tenant.os-dnit.orders.show', [$tenant->slug, $order])
            ->with('success', 'Demanda da OS salva. Confira a solicitação e envie à Supervisão.');
    }

    public function update(Request $request, Tenant $tenant, OsDnitOrder $order): RedirectResponse
    {
        $this->authorizeOrder($request, $tenant, $order);
        abort_unless(Workflow::canEdit($request->user(), $tenant, $order), 403);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $data = $this->validateDemand($request, $tenant, $order->contract_id, [$order->contract_id]);
        $storedPaths = [];
        $disk = (string) config('os_dnit.attachment_disk', 'local');
        try {
            DB::transaction(function () use ($request, $tenant, $order, $version, $data, $disk, &$storedPaths) {
                $locked = OsDnitOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless(Workflow::canEdit($request->user(), $tenant, $locked), 403);
                if ($locked->workflow_version !== (int) $version) {
                    throw ValidationException::withMessages(['version' => 'Esta OS foi alterada. Atualize a página antes de salvar.']);
                }
                if ($locked->attachments()->count() + count($request->file('attachments', [])) > 10) {
                    throw ValidationException::withMessages(['attachments' => 'A OS pode ter no máximo 10 anexos.']);
                }
                if (! $locked->events()->exists()) {
                    app(OsDnitOrderHistory::class)->record($locked, $request->user(), 'legacy_draft', null);
                }
                $from = $locked->status;
                $locked->update([...$this->withLotSnapshot($tenant, collect($data)->except(['attachments'])->all()),
                    'highway_name' => OsDnitHighway::where('tenant_id', $tenant->id)->where('contract_id', $locked->contract_id)->findOrFail($data['highway_id'])->name,
                    'review_comment' => null, 'estimated_deadline' => null, 'review_items' => null, 'analyzed_by_id' => null, 'reviewed_at' => null,
                    'workflow_version' => $locked->workflow_version + 1]);
                foreach ($request->file('attachments', []) as $file) {
                    $path = $file->store("os-dnit/{$tenant->id}/{$locked->id}", $disk);
                    if ($path === false) {
                        throw new \RuntimeException('Não foi possível salvar o anexo.');
                    }
                    $storedPaths[] = $path;
                    $locked->attachments()->create(['original_name' => $file->getClientOriginalName(), 'storage_disk' => $disk,
                        'path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize()]);
                }
                app(OsDnitOrderHistory::class)->record($locked, $request->user(), 'draft_updated', $from);
            });
        } catch (Throwable $exception) {
            if ($storedPaths) {
                Storage::disk($disk)->delete($storedPaths);
            }
            throw $exception;
        }

        return to_route('tenant.os-dnit.orders.show', [$tenant->slug, $order])->with('success', 'Alterações da solicitação salvas.');
    }

    private function canDelete(Request $request, Tenant $tenant, OsDnitOrder $order): bool
    {
        return $order->status === 'draft' && $order->submitted_at === null
            && Permissions::can($request->user(), $tenant, Permissions::DELETE, $order->contract_id);
    }

    public function destroy(Request $request, Tenant $tenant, OsDnitOrder $order): RedirectResponse
    {
        $this->authorizeOrder($request, $tenant, $order);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        DB::transaction(function () use ($request, $tenant, $order, $version) {
            $locked = OsDnitOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless(Permissions::can($request->user(), $tenant, Permissions::DELETE, $locked->contract_id), 403);
            if ($locked->status !== 'draft' || $locked->submitted_at !== null) {
                throw ValidationException::withMessages(['order' => 'Somente demandas ainda não enviados podem ser excluídos.']);
            }
            if ($locked->workflow_version !== (int) $version) {
                throw ValidationException::withMessages(['version' => 'Esta OS foi alterada. Atualize a página antes de excluir.']);
            }
            app(OsDnitOrderHistory::class)->record($locked, $request->user(), 'deleted', $locked->status, 'Demanda excluído.');
            $locked->delete();
        });

        return to_route('tenant.os-dnit.orders.index', $tenant->slug)->with('success', 'Demanda excluído.');
    }

    public function pdf(Request $request, Tenant $tenant, OsDnitOrder $order, OsDnitPdfRenderer $renderer): \Illuminate\Http\Response
    {
        $this->authorizeOrder($request, $tenant, $order);
        $scope = $request->validate(['scope' => ['nullable', 'string', Rule::in(array_keys(OsDnitPdfRenderer::SCOPES))]])['scope'] ?? 'complete';
        $data = DB::transaction(function () use ($request, $tenant, $order, $renderer, $scope) {
            $locked = OsDnitOrder::whereKey($order->id)->sharedLock()->firstOrFail();
            $this->authorizeOrder($request, $tenant, $locked);

            return $renderer->data($tenant, $locked, $scope);
        });
        $filename = 'os-dnit-'.str($data['order']->request_number)->replace('/', '-')->slug().'-'.OsDnitPdfRenderer::SCOPES[$scope].'.pdf';

        return $renderer->render($data)->download($filename)
            ->header('Cache-Control', 'private, no-store, max-age=0')->header('X-Content-Type-Options', 'nosniff');
    }

    public function show(Request $request, Tenant $tenant, OsDnitOrder $order): Response|JsonResponse|RedirectResponse
    {
        $this->authorizeOrder($request, $tenant, $order);
        $analysisMode = $request->routeIs('tenant.os-dnit.analysis.*');
        if ($analysisMode) {
            abort_unless(Workflow::hasRole($request->user(), $tenant, $order->contract_id, 'concremat')
                || Workflow::hasRole($request->user(), $tenant, $order->contract_id, 'contract_inspector'), 403);
        }
        if ($analysisMode && ! $request->expectsJson()) {
            return to_route('tenant.os-dnit.orders.index', [$tenant->slug, 'analysis_order_id' => $order->id]);
        }
        $order->load(['contract:id,code,name', 'creator:id,name', 'items', 'attachments', 'analystReview:id,name', 'events.actor:id,name']);

        $data = [
            'order' => $order, 'statuses' => OsDnitOrder::STATUSES, 'analysisMode' => $analysisMode,
            'capabilities' => [
                'finalize' => Workflow::canFinalize($request->user(), $tenant, $order),
                'delete' => $this->canDelete($request, $tenant, $order),
                'edit' => Workflow::canEdit($request->user(), $tenant, $order),
                'submit' => Workflow::canSubmit($request->user(), $tenant, $order),
                'review' => in_array($order->status, ['awaiting_concremat', 'returned_concremat'], true) && Workflow::hasRole($request->user(), $tenant, $order->contract_id, 'concremat'),
                'decide' => $order->status === 'awaiting_contract' && Workflow::hasRole($request->user(), $tenant, $order->contract_id, 'contract_inspector'),
            ],
            'products' => ! Workflow::hasRole($request->user(), $tenant, $order->contract_id, 'concremat') ? [] : OsDnitProduct::where('tenant_id', $tenant->id)->where('contract_id', $order->contract_id)->orderBy('code')->get(['id', 'code', 'name', 'unit', 'category', 'frequency']),
            'categories' => OsDnitProduct::CATEGORIES,
            'frequencies' => OsDnitProduct::FREQUENCIES,
            'attachmentLinks' => $order->attachments->mapWithKeys(fn ($attachment) => [$attachment->id => route('tenant.os-dnit.orders.attachments.download', [$tenant->slug, $order, $attachment])]),
        ];

        return $analysisMode ? response()->json($data)->header('Cache-Control', 'private, no-store')
            : Inertia::render('Tenant/OsDnit/ShowOrder', $data);
    }

    public function download(Request $request, Tenant $tenant, OsDnitOrder $order, OsDnitOrderAttachment $attachment): StreamedResponse
    {
        $this->authorizeOrder($request, $tenant, $order);
        abort_unless((int) $attachment->order_id === (int) $order->id, 404);
        $disk = Storage::disk($attachment->storage_disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function validateDemand(Request $request, Tenant $tenant, int $contractId, array $contractIds): array
    {
        foreach (['supervision_lot', 'subject', 'technical_need', 'justification', 'sei_reference'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        $data = $request->validate([
            'contract_id' => ['required', 'integer', Rule::in($contractIds)],
            'supervision_lot_id' => ['required', 'integer', Rule::exists('os_dnit_supervision_lots', 'id')->where('tenant_id', $tenant->id)->where('contract_id', $contractId)->where('active', true)],
            'highway_id' => ['required', 'integer', Rule::exists('os_dnit_highways', 'id')->where('tenant_id', $tenant->id)->where('contract_id', $contractId)->where('active', true)],
            'km_start' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999.999'],
            'km_end' => ['required', 'numeric', 'decimal:0,3', 'gte:km_start', 'max:9999999.999'],
            'subject' => ['required', 'string', 'max:255'],
            'desired_deadline' => ['required', 'date_format:Y-m-d'],
            'technical_need' => ['required', 'string', 'max:20000'],
            'justification' => ['required', 'string', 'max:20000'],
            'sei_reference' => ['nullable', 'string', 'max:255'],
            'items' => ['prohibited'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip', 'max:10240'],
        ], [
            'contract_id.in' => 'Selecione um contrato ao qual você tenha acesso.',
            'highway_id.exists' => 'Selecione uma rodovia ativa do contrato da OS.',
            'km_end.gte' => 'O km final deve ser maior ou igual ao km inicial.',
            'items.prohibited' => 'Os produtos são definidos somente na Análise Supervisão.',
            'supervision_lot_id.required' => 'Selecione um lote de supervisão.',
            'supervision_lot_id.exists' => 'Selecione um lote ativo deste contrato.',
            'items.*.product_id.distinct' => 'Não repita o mesmo produto na solicitação.',
            'items.*.product_id.exists' => 'Selecione um produto do contrato da OS.',
            'items.*.quantity.min' => 'A quantidade deve ser maior que zero.',
            'attachments.array' => 'Anexos: selecione uma lista de arquivos válidos.',
            'attachments.max' => 'Anexos: selecione no máximo 10 arquivos.',
            'attachments.*.max' => 'Cada anexo pode ter no máximo 10 MB.',
        ], [
            'request_number' => 'número da solicitação', 'contract_id' => 'contrato de supervisão',
            'supervision_lot_id' => 'lote de supervisão', 'highway_id' => 'rodovia',
            'km_start' => 'km inicial', 'km_end' => 'km final', 'subject' => 'assunto',
            'desired_deadline' => 'prazo pretendido', 'technical_need' => 'descrição da necessidade técnica',
            'justification' => 'justificativa / resultado esperado', 'items.*.quantity' => 'quantidade',
            'attachments' => 'Anexos', 'attachments.*' => 'Anexos',
        ]);
        unset($data['items']);

        return $data;
    }

    private function withLotSnapshot(Tenant $tenant, array $data): array
    {
        $lot = OsDnitSupervisionLot::where('tenant_id', $tenant->id)->where('contract_id', $data['contract_id'])
            ->where('active', true)->whereKey($data['supervision_lot_id'])->sharedLock()->first();
        if (! $lot) {
            throw ValidationException::withMessages(['supervision_lot_id' => 'O lote selecionado não está mais disponível.']);
        }

        return [...$data, 'supervision_lot' => $lot->name, 'sei_reference' => filled($lot->sei_reference) ? $lot->sei_reference : ($data['sei_reference'] ?? null)];
    }

    private function contracts(Request $request, Tenant $tenant)
    {
        return OsDnitContractAccess::contracts($request->user(), $tenant, Permissions::VIEW);
    }

    private function authorizeOrder(Request $request, Tenant $tenant, OsDnitOrder $order): void
    {
        abort_unless((int) $order->tenant_id === (int) $tenant->id && $this->contracts($request, $tenant)->whereKey($order->contract_id)->exists(), 404);
    }
}
