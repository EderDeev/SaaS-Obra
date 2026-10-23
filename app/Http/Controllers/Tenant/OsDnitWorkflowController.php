<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OsDnitOrder;
use App\Models\OsDnitProduct;
use App\Models\Tenant;
use App\Services\OsDnitOrderHistory;
use App\Support\OsDnitContractAccess;
use App\Support\OsDnitPermissions;
use App\Support\OsDnitWorkflowAccess as Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OsDnitWorkflowController extends Controller
{
    public function submit(Request $request, Tenant $tenant, OsDnitOrder $order)
    {
        return $this->mutate($request, $tenant, $order, function ($order) use ($request, $tenant) {
            abort_unless(Access::canSubmit($request->user(), $tenant, $order), 403);
            $this->recipient($tenant, $order, 'concremat');
            $from = $order->status;
            $order->update(['status' => 'awaiting_concremat', 'submitted_at' => now(),
                'review_comment' => null, 'estimated_deadline' => null, 'review_items' => null, 'analyzed_by_id' => null, 'reviewed_at' => null]);
            $this->record($order, $request, 'submitted', $from);
        }, 'OS enviada à Supervisão.');
    }

    public function review(Request $request, Tenant $tenant, OsDnitOrder $order)
    {
        return $this->mutate($request, $tenant, $order, function ($order) use ($request, $tenant) {
            abort_unless(Access::hasRole($request->user(), $tenant, $order->contract_id, 'concremat'), 403);
            $this->state($order, ['awaiting_concremat', 'returned_concremat']);
            if (is_string($request->input('review_comment'))) {
                $request->merge(['review_comment' => trim($request->input('review_comment'))]);
            }
            $data = $request->validate([
                'mode' => ['required', Rule::in(['save', 'forward'])],
                'review_comment' => ['required', 'string', 'max:20000'],
                'estimated_deadline' => ['required', 'date_format:Y-m-d'],
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('os_dnit_products', 'id')
                    ->where('tenant_id', $tenant->id)->where('contract_id', $order->contract_id)],
                'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999999.999'],
            ], ['items.required' => 'Mantenha pelo menos um produto na análise.',
                'review_comment.required' => 'Informe o comentário da análise.',
                'items.*.product_id.exists' => 'Selecione um produto deste contrato.',
                'estimated_deadline.required' => 'Informe o prazo estimado pela Supervisão.']);
            if ($data['mode'] === 'forward') {
                $this->recipient($tenant, $order, 'contract_inspector');
            }
            $products = OsDnitProduct::where('tenant_id', $tenant->id)->where('contract_id', $order->contract_id)
                ->whereIn('id', array_column($data['items'], 'product_id'))->sharedLock()->get()->keyBy('id');
            if ($products->count() !== count($data['items'])) {
                throw ValidationException::withMessages(['items' => 'Um produto não está mais disponível. Atualize a análise.']);
            }
            $items = array_map(function ($item) use ($products, $order) {
                $product = $products->get($item['product_id']);
                // Preserve the proposal's labels when only its quantity changes.
                $original = collect($order->review_items ?? [])->firstWhere('product_id', $product->id);

                return ['product_id' => $product->id, 'product_code' => $original['product_code'] ?? $product->code,
                    'product_name' => $original['product_name'] ?? $product->name, 'unit' => $original['unit'] ?? $product->unit,
                    'frequency' => $original['frequency'] ?? $product->frequency,
                    'quantity' => number_format((float) $item['quantity'], 3, '.', '')];
            }, $data['items']);
            $from = $order->status;
            $order->update(['review_comment' => $data['review_comment'] ?? null, 'estimated_deadline' => $data['estimated_deadline'],
                'review_items' => $items, 'analyzed_by_id' => $request->user()->id, 'reviewed_at' => now(),
                'status' => $data['mode'] === 'forward' ? 'awaiting_contract' : $from]);
            $this->record($order, $request, $data['mode'] === 'forward' ? 'forwarded' : 'analysis_saved', $from, $data['review_comment'] ?? null);
        }, $request->input('mode') === 'forward' ? 'Análise encaminhada ao fiscal do contrato.' : 'Análise salva.');
    }

    public function decide(Request $request, Tenant $tenant, OsDnitOrder $order)
    {
        return $this->mutate($request, $tenant, $order, function ($order) use ($request, $tenant) {
            abort_unless(Access::hasRole($request->user(), $tenant, $order->contract_id, 'contract_inspector'), 403);
            $this->state($order, ['awaiting_contract']);
            if (is_string($request->input('comment'))) {
                $request->merge(['comment' => trim($request->input('comment'))]);
            }
            $data = $request->validate([
                'decision' => ['required', Rule::in(['approve', 'return'])],
                'return_to' => ['required_if:decision,return', 'nullable', Rule::in(['concremat', 'analyst'])],
                'comment' => ['required', 'string', 'max:20000'],
            ], ['comment.required' => $request->input('decision') === 'return' ? 'Informe o motivo da reprovação.' : 'Informe o comentário da decisão.',
                'return_to.required_if' => 'Selecione para quem a OS será devolvida.']);
            if ($data['decision'] === 'return') {
                $this->recipient($tenant, $order, $data['return_to']);
            }
            $from = $order->status;
            $order->update(['status' => $data['decision'] === 'approve' ? 'approved'
                : ($data['return_to'] === 'concremat' ? 'returned_concremat' : 'returned_analyst')]);
            $this->record($order, $request, $data['decision'] === 'approve' ? 'approved' : 'returned', $from, $data['comment'] ?? null);
        }, $request->input('decision') === 'approve' ? 'Serviço aprovado.' : 'OS devolvida com justificativa.');
    }

    public function finalize(Request $request, Tenant $tenant, OsDnitOrder $order)
    {
        return $this->mutate($request, $tenant, $order, function ($order) use ($request, $tenant) {
            abort_unless(Access::hasRole($request->user(), $tenant, $order->contract_id, 'contract_inspector'), 403);
            $this->state($order, ['approved']);
            $order->update(['status' => 'completed', 'finalized_at' => now(), 'finalized_by_id' => $request->user()->id]);
            $this->record($order, $request, 'completed', 'approved', 'Serviço concluído. OS finalizada pelo fiscal do contrato.');
        }, 'OS finalizada. Serviço concluído.');
    }

    private function mutate(Request $request, Tenant $tenant, OsDnitOrder $order, callable $action, string $message)
    {
        abort_unless((int) $order->tenant_id === (int) $tenant->id
            && OsDnitContractAccess::contracts($request->user(), $tenant, OsDnitPermissions::VIEW)->whereKey($order->contract_id)->exists(), 404);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($order, $data, $action) {
            $locked = OsDnitOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->workflow_version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Esta OS foi alterada por outra pessoa. Atualize a página antes de continuar.']);
            }
            $action($locked);
        });

        return back()->with('success', $message);
    }

    private function record(OsDnitOrder $order, Request $request, string $action, string $from, ?string $comment = null): void
    {
        $order->increment('workflow_version');
        app(OsDnitOrderHistory::class)->record($order, $request->user(), $action, $from, $comment);
    }

    private function state(OsDnitOrder $order, array $states): void
    {
        if (! in_array($order->status, $states, true)) {
            throw ValidationException::withMessages(['status' => 'Esta ação não está disponível na etapa atual da OS. Atualize a página.']);
        }
    }

    private function recipient(Tenant $tenant, OsDnitOrder $order, string $role): void
    {
        $exists = $role === 'analyst'
            ? Access::contracts($order->creator, $tenant, 'analyst')->whereKey($order->contract_id)->exists()
            : Access::hasRecipient($tenant, $order->contract_id, $role);
        if (! $exists) {
            throw ValidationException::withMessages(['workflow' => 'Cadastre um responsável com vínculo ativo para a etapa de destino em Responsáveis.']);
        }
    }
}
