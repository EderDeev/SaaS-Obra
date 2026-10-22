<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OsDnitProduct;
use App\Models\Tenant;
use App\Support\OsDnitPermissions as Permissions;
use App\Models\OsDnitOrder;
use App\Models\OsDnitOrderItem;
use Illuminate\Support\Facades\DB;
use App\Support\OsDnitContractAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OsDnitProductController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        $contracts = OsDnitContractAccess::contracts($request->user(), $tenant, Permissions::VIEW_PRODUCTS)->orderBy('code')->get(['id', 'code', 'name']);
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer', Rule::in($contracts->pluck('id')->all())],
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(array_keys(OsDnitProduct::CATEGORIES))],
        ]);

        $products = OsDnitProduct::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->with('contract:id,code,name')
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when(trim($filters['q'] ?? ''), function ($query, $term) {
                $term = '%'.mb_strtolower($term).'%';
                $query->where(fn ($query) => $query
                    ->whereRaw('LOWER(code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$term]));
            })
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString()->through(fn ($product) => $product
                ->setAttribute('can_edit', Permissions::can($request->user(), $tenant, Permissions::EDIT_PRODUCTS, $product->contract_id))
                ->setAttribute('can_delete', Permissions::can($request->user(), $tenant, Permissions::DELETE_PRODUCTS, $product->contract_id)));

        return Inertia::render('Tenant/OsDnit/Products', [
            'products' => $products,
            'filters' => $filters,
            'contracts' => $contracts->map(fn ($contract) => $contract->setAttribute('can_create', Permissions::can($request->user(), $tenant, Permissions::CREATE_PRODUCTS, $contract->id))),
            'categories' => OsDnitProduct::CATEGORIES,
            'frequencies' => OsDnitProduct::FREQUENCIES,
            'canCreate' => Permissions::canAny($request->user(), $tenant, Permissions::CREATE_PRODUCTS),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $this->validatedProduct($request, $tenant);

        try {
            OsDnitProduct::create([...$data, 'tenant_id' => $tenant->id]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Já existe um produto com este código neste contrato.']);
        }

        return to_route('tenant.os-dnit.products.index', $tenant->slug)
            ->with('success', 'Produto cadastrado com sucesso.');
    }
    public function update(Request $request, Tenant $tenant, OsDnitProduct $product): RedirectResponse
    {
        $data = $this->validatedProduct($request, $tenant, $product);
        try {
            $product->update($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Já existe um produto com este código neste contrato.']);
        }
        return back()->with('success', 'Produto atualizado. As OSs existentes preservam seus registros.');
    }

    public function destroy(Request $request, Tenant $tenant, OsDnitProduct $product): RedirectResponse
    {
        try {
            DB::transaction(function () use ($tenant, $product) {
                $locked = OsDnitProduct::where('tenant_id', $tenant->id)->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $inReview = OsDnitOrder::withTrashed()->where('tenant_id', $tenant->id)->where('contract_id', $locked->contract_id)
                    ->whereNotNull('review_items')->select('review_items')->cursor()
                    ->contains(fn ($order) => collect($order->review_items)->contains(fn ($item) => (int) $item['product_id'] === (int) $locked->id));
                if (OsDnitOrderItem::where('product_id', $locked->id)->exists() || $inReview) {
                    throw ValidationException::withMessages(['resource' => 'Este produto está vinculado a uma OS e não pode ser excluído.']);
                }
                $locked->delete();
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23503', '23000'], true)) {
                throw ValidationException::withMessages(['resource' => 'Este produto está vinculado a uma OS e não pode ser excluído.']);
            }
            throw $exception;
        }
        return back()->with('success', 'Produto excluído.');
    }

    private function validatedProduct(Request $request, Tenant $tenant, ?OsDnitProduct $product = null): array
    {
        $contractIds = OsDnitContractAccess::contracts($request->user(), $tenant, $product ? Permissions::EDIT_PRODUCTS : Permissions::CREATE_PRODUCTS)->pluck('id')->all();
        $request->validate(['contract_id' => ['required', 'integer', Rule::in($product ? [$product->contract_id] : $contractIds)]]);
        $request->merge([
            'code' => mb_strtoupper(trim((string) $request->input('code', ''))),
            'name' => trim((string) $request->input('name', '')),
            'unit' => trim((string) $request->input('unit', '')),
        ]);

        return $request->validate([
            'contract_id' => ['required', 'integer', Rule::in($product ? [$product->contract_id] : $contractIds)],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('os_dnit_products', 'code')->where('tenant_id', $tenant->id)->where('contract_id', $request->input('contract_id'))->ignore($product?->id)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(OsDnitProduct::CATEGORIES))],
            'unit' => ['required', 'string', 'max:20'],
            'frequency' => ['required', Rule::in(array_keys(OsDnitProduct::FREQUENCIES))],
        ], [
            'code.unique' => 'Já existe um produto com este código neste contrato.',
            'code.regex' => 'Use letras, números, ponto, hífen ou sublinhado no código.',
            'category.in' => 'Selecione uma categoria da lista.',
            'frequency.in' => 'Selecione Mensal ou Por demanda.',
        ], [
            'code' => 'código', 'name' => 'nome', 'category' => 'categoria',
            'unit' => 'unidade de medida', 'frequency' => 'frequência',
        ]);

    }

}
