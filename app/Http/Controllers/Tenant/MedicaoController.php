<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\MedicaoIndiceReajuste;
use App\Models\MedicaoIndiceReajusteCompetencia;
use App\Models\MedicaoItemAdditive;
use App\Models\MedicaoItemAdditiveItem;
use App\Models\MedicaoItem;
use App\Models\MedicaoItemReajusteIndice;
use App\Models\MedicaoItemVersion;
use App\Models\Orcamento;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MedicaoController extends Controller
{
    public function item(Request $request, Tenant $tenant): Response
    {
        $contracts = $this->accessibleContracts($request, $tenant)
            ->orderBy('code')
            ->orderBy('name')
            ->get();

        $selectedContractId = $request->integer('contract_id') ?: $contracts->first()?->id;

        if ($selectedContractId && ! $contracts->contains('id', $selectedContractId)) {
            $selectedContractId = $contracts->first()?->id;
        }

        $filters = [
            'item_code' => trim((string) $request->input('item_code', '')),
            'sheet_item' => trim((string) $request->input('sheet_item', '')),
            'additive' => in_array($request->input('additive'), ['base', 'aditivo'], true)
                ? $request->input('additive')
                : '',
            'price_order' => in_array($request->input('price_order'), ['asc', 'desc'], true)
                ? $request->input('price_order')
                : '',
        ];

        $items = $selectedContractId
            ? MedicaoItem::query()
                ->with(['latestVersion', 'versions', 'additiveItems.additive'])
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $selectedContractId)
                ->when($filters['item_code'] !== '', function (Builder $query) use ($filters): void {
                    $term = $filters['item_code'];

                    $query->where(function (Builder $query) use ($term): void {
                        $query->where('item', 'like', $term.'%')
                            ->orWhere('codigo', 'like', $term.'%');
                    });
                })
                ->when($filters['sheet_item'] !== '', function (Builder $query) use ($filters): void {
                    $sheetItem = rtrim($filters['sheet_item'], '.');

                    $query->where(function (Builder $query) use ($sheetItem): void {
                        $query->where('item', $sheetItem)
                            ->orWhere('item', 'like', $sheetItem.'.%');
                    });
                })
                ->when($filters['additive'] === 'base', function (Builder $query): void {
                    $query->where('source_type', '!=', 'aditivo')
                        ->whereDoesntHave('versions', function (Builder $query): void {
                            $query->where('version_number', '>', 1);
                        });
                })
                ->when($filters['additive'] === 'aditivo', function (Builder $query): void {
                    $query->where(function (Builder $query): void {
                        $query->where('source_type', 'aditivo')
                            ->orWhereHas('versions', function (Builder $query): void {
                                $query->where('version_number', '>', 1);
                            });
                    });
                })
                ->orderBy('id')
                ->get()
                ->map(fn (MedicaoItem $item): array => $this->serializeItem($item))
                ->values()
            : collect();

        $items = $this->applyHeaderTotals($items);

        if ($filters['price_order'] === 'asc') {
            $items = $items->sortBy(fn (array $item): float => (float) ($item['valor_total'] ?? 0))->values();
        } elseif ($filters['price_order'] === 'desc') {
            $items = $items->sortByDesc(fn (array $item): float => (float) ($item['valor_total'] ?? 0))->values();
        }

        $orcamentos = $tenant->orcamentos()
            ->withCount(['etapas', 'itens'])
            ->where('status', 'closed')
            ->orderByDesc('created_at')
            ->get(['id', 'codigo', 'descricao', 'encargos_sociais', 'status', 'closed_at', 'valor_nao_desonerado', 'valor_desonerado'])
            ->map(fn (Orcamento $orcamento): array => [
                'id' => $orcamento->id,
                'codigo' => $orcamento->codigo,
                'descricao' => $orcamento->descricao,
                'status' => $orcamento->status,
                'closed_at' => $orcamento->closed_at?->format('d/m/Y H:i'),
                'encargos_sociais' => $orcamento->encargos_sociais,
                'etapas_count' => $orcamento->etapas_count,
                'itens_count' => $orcamento->itens_count,
                'valor_referencia' => $orcamento->encargos_sociais === 'nao_desonerado'
                    ? $orcamento->valor_nao_desonerado
                    : $orcamento->valor_desonerado,
            ]);

        $additives = $selectedContractId
            ? MedicaoItemAdditive::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $selectedContractId)
                ->withCount('items')
                ->orderByDesc('number')
                ->limit(8)
                ->get()
                ->map(fn (MedicaoItemAdditive $additive): array => [
                    'id' => $additive->id,
                    'number' => $additive->number,
                    'title' => $additive->title,
                    'reason' => $additive->reason,
                    'source_type' => $additive->source_type,
                    'items_count' => $additive->items_count,
                    'effective_at' => $additive->effective_at?->format('d/m/Y'),
                    'created_at' => $additive->created_at?->format('d/m/Y H:i'),
                ])
            : collect();

        return Inertia::render('Tenant/Medicao/Itens', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
                'status' => $contract->status,
            ])->values(),
            'orcamentos' => $orcamentos->values(),
            'selectedContractId' => $selectedContractId,
            'filters' => $filters,
            'items' => $items,
            'additives' => $additives->values(),
            'stats' => [
                'total_items' => $items->count(),
                'total_value' => $this->totalValueForItems($items),
            ],
        ]);
    }

    public function indiceReajuste(Request $request, Tenant $tenant): Response
    {
        $contracts = $this->accessibleContracts($request, $tenant)
            ->orderBy('code')
            ->orderBy('name')
            ->get();

        $selectedContractId = $request->integer('contract_id') ?: $contracts->first()?->id;

        if ($selectedContractId && ! $contracts->contains('id', $selectedContractId)) {
            $selectedContractId = $contracts->first()?->id;
        }

        $indices = $selectedContractId
            ? MedicaoIndiceReajuste::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $selectedContractId)
                ->with(['creator:id,name', 'competencias.creator:id,name'])
                ->orderByDesc('data_atual')
                ->orderBy('nome')
                ->get()
                ->map(fn (MedicaoIndiceReajuste $indice): array => $this->serializeIndiceReajuste($indice))
            : collect();

        $itensReajuste = $selectedContractId
            ? MedicaoItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $selectedContractId)
                ->where('item_type', '!=', 'etapa')
                ->with('reajusteIndice.indice:id,nome,codigo')
                ->orderBy('id')
                ->get()
                ->map(fn (MedicaoItem $item): array => $this->serializeItemReajusteLink($item))
            : collect();

        return Inertia::render('Tenant/Medicao/IndicesReajuste', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
                'status' => $contract->status,
            ])->values(),
            'selectedContractId' => $selectedContractId,
            'indices' => $indices->values(),
            'itensReajuste' => $itensReajuste->values(),
        ]);
    }

    public function storeIndiceReajuste(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->merge([
            'indice_base' => $this->normalizeDecimalInput($request->input('indice_base')),
            'indice_atual' => $this->normalizeDecimalInput($request->input('indice_atual')),
        ]);

        $data = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id),
            ],
            'nome' => ['required', 'string', 'max:180'],
            'codigo' => ['nullable', 'string', 'max:60'],
            'indice_base' => ['required', 'numeric', 'gt:0'],
            'data_base' => ['required', 'date'],
            'indice_atual' => ['required', 'numeric', 'gte:0'],
            'data_atual' => ['required', 'date'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);

        MedicaoIndiceReajuste::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $request->user()->id,
            'nome' => trim($data['nome']),
            'codigo' => $this->blankToNull($data['codigo'] ?? null),
            'indice_base' => $this->decimalFromFloat((float) $data['indice_base']),
            'data_base' => $data['data_base'],
            'indice_atual' => $this->decimalFromFloat((float) $data['indice_atual']),
            'data_atual' => $data['data_atual'],
            'observacao' => $this->blankToNull($data['observacao'] ?? null),
        ]);

        return back()->with('success', 'Indice de reajuste criado com sucesso.');
    }

    public function destroyIndiceReajuste(Request $request, Tenant $tenant, MedicaoIndiceReajuste $indice): RedirectResponse
    {
        abort_unless((int) $indice->tenant_id === (int) $tenant->id, 404);

        $this->contractForRequest($request, $tenant, (int) $indice->contract_id);

        $indice->delete();

        return back()->with('success', 'Indice de reajuste removido com sucesso.');
    }

    public function storeIndiceReajusteCompetencia(Request $request, Tenant $tenant, MedicaoIndiceReajuste $indice): RedirectResponse
    {
        abort_unless((int) $indice->tenant_id === (int) $tenant->id, 404);

        $this->contractForRequest($request, $tenant, (int) $indice->contract_id);

        $request->merge([
            'valor_indice' => $this->normalizeDecimalInput($request->input('valor_indice')),
            'competencia' => $this->normalizeCompetenciaInput($request->input('competencia')),
        ]);

        $data = $request->validate([
            'competencia' => ['required', 'date'],
            'valor_indice' => ['required', 'numeric', 'gt:0'],
            'data_publicacao' => ['nullable', 'date'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $competencia = MedicaoIndiceReajusteCompetencia::withTrashed()->firstOrNew([
            'medicao_indice_reajuste_id' => $indice->id,
            'competencia' => $data['competencia'],
        ]);

        $competencia->fill([
            'tenant_id' => $tenant->id,
            'contract_id' => $indice->contract_id,
            'created_by_id' => $request->user()->id,
            'valor_indice' => $this->decimalFromFloat((float) $data['valor_indice']),
            'data_publicacao' => $data['data_publicacao'] ?? null,
            'observacao' => $this->blankToNull($data['observacao'] ?? null),
        ]);

        if ($competencia->trashed()) {
            $competencia->restore();
        }

        $competencia->save();

        return back()->with('success', 'Competencia do indice atualizada com sucesso.');
    }

    public function destroyIndiceReajusteCompetencia(
        Request $request,
        Tenant $tenant,
        MedicaoIndiceReajuste $indice,
        MedicaoIndiceReajusteCompetencia $competencia
    ): RedirectResponse {
        abort_unless((int) $indice->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $competencia->medicao_indice_reajuste_id === (int) $indice->id, 404);

        $this->contractForRequest($request, $tenant, (int) $indice->contract_id);

        $competencia->delete();

        return back()->with('success', 'Competencia removida com sucesso.');
    }

    public function storeItemIndiceReajusteVinculos(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id),
            ],
            'links' => ['required', 'array'],
            'links.*.item_id' => ['required', 'integer'],
            'links.*.indice_id' => ['nullable', 'integer'],
        ]);

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);

        $items = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('item_type', '!=', 'etapa')
            ->whereIn('id', collect($data['links'])->pluck('item_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $indices = MedicaoIndiceReajuste::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->get()
            ->keyBy('id');

        $updated = 0;
        $removed = 0;
        $invalid = 0;

        foreach ($data['links'] as $link) {
            $item = $items->get((int) $link['item_id']);

            if (! $item) {
                $invalid++;
                continue;
            }

            $indiceId = $link['indice_id'] ?? null;

            if (! $indiceId) {
                $existing = MedicaoItemReajusteIndice::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $contract->id)
                    ->where('medicao_item_id', $item->id)
                    ->first();

                if ($existing) {
                    $existing->delete();
                    $removed++;
                }

                continue;
            }

            $indice = $indices->get((int) $indiceId);

            if (! $indice) {
                $invalid++;
                continue;
            }

            $vinculo = MedicaoItemReajusteIndice::withTrashed()->firstOrNew([
                'medicao_item_id' => $item->id,
            ]);

            $vinculo->fill([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'medicao_indice_reajuste_id' => $indice->id,
                'created_by_id' => $request->user()->id,
                'item_codigo' => $item->item,
                'indice_codigo' => $indice->codigo,
                'source_type' => 'manual',
            ]);

            if ($vinculo->trashed()) {
                $vinculo->restore();
            }

            $vinculo->save();
            $updated++;
        }

        return back()->with('success', "Vinculos atualizados: {$updated} salvo(s), {$removed} removido(s), {$invalid} invalido(s).");
    }

    public function importItemIndiceReajusteVinculos(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id),
            ],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:51200'],
            'first_item_row' => ['required', 'integer', 'min:1'],
            'last_item_row' => ['nullable', 'integer', 'gte:first_item_row'],
            'item_column' => ['required', 'string', 'max:6'],
            'indice_codigo_column' => ['required', 'string', 'max:6'],
        ]);

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);
        $columns = [
            'item_column' => $this->columnLetterToIndex($data['item_column']),
            'indice_codigo_column' => $this->columnLetterToIndex($data['indice_codigo_column']),
        ];

        $items = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('item_type', '!=', 'etapa')
            ->get()
            ->keyBy(fn (MedicaoItem $item): string => (string) $item->item);

        $indices = MedicaoIndiceReajuste::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->whereNotNull('codigo')
            ->get()
            ->keyBy(fn (MedicaoIndiceReajuste $indice): string => Str::upper((string) $indice->codigo));

        $handle = fopen($request->file('file')->getRealPath(), 'rb');

        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'Nao foi possivel ler o arquivo enviado.']);
        }

        $firstLine = fgets($handle) ?: '';
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $createdOrUpdated = 0;
        $invalid = 0;
        $read = 0;
        $lineNumber = 0;

        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;

                if ($lineNumber < (int) $data['first_item_row']) {
                    continue;
                }

                if (! empty($data['last_item_row']) && $lineNumber > (int) $data['last_item_row']) {
                    break;
                }

                if ($this->isBlankCsvRow($row)) {
                    continue;
                }

                $read++;
                $itemCode = $this->normalizeCsvValue((string) ($row[$columns['item_column']] ?? ''));
                $indiceCode = Str::upper($this->normalizeCsvValue((string) ($row[$columns['indice_codigo_column']] ?? '')));

                $item = $items->get($itemCode);
                $indice = $indices->get($indiceCode);

                if (! $item || ! $indice) {
                    $invalid++;
                    continue;
                }

                $vinculo = MedicaoItemReajusteIndice::withTrashed()->firstOrNew([
                    'medicao_item_id' => $item->id,
                ]);

                $vinculo->fill([
                    'tenant_id' => $tenant->id,
                    'contract_id' => $contract->id,
                    'medicao_indice_reajuste_id' => $indice->id,
                    'created_by_id' => $request->user()->id,
                    'item_codigo' => $item->item,
                    'indice_codigo' => $indice->codigo,
                    'source_type' => 'import',
                ]);

                if ($vinculo->trashed()) {
                    $vinculo->restore();
                }

                $vinculo->save();
                $createdOrUpdated++;
            }
        } finally {
            fclose($handle);
        }

        return back()->with('success', "Importacao concluida: {$createdOrUpdated} vinculo(s) salvo(s), {$invalid} invalido(s), {$read} linha(s) lida(s).");
    }

    public function storeManual(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id),
            ],
            'item' => ['nullable', 'string', 'max:40'],
            'codigo' => ['nullable', 'string', 'max:80'],
            'banco' => ['nullable', 'string', 'max:40'],
            'descricao' => ['required', 'string', 'max:500'],
            'unidade' => ['nullable', 'string', 'max:30'],
            'quantidade_prevista' => ['nullable', 'string', 'max:30'],
            'valor_unitario' => ['nullable', 'string', 'max:30'],
            'valor_com_bdi' => ['nullable', 'string', 'max:30'],
            'valor_total' => ['nullable', 'string', 'max:30'],
        ]);

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);

        $quantity = $this->parseDecimal($data['quantidade_prevista'] ?? null) ?? '0.000000';
        $unitValue = $this->parseDecimal($data['valor_unitario'] ?? null) ?? '0.000000';
        $bdiValue = $this->parseDecimal($data['valor_com_bdi'] ?? null) ?? $unitValue;
        $totalValue = $this->parseDecimal($data['valor_total'] ?? null)
            ?? $this->decimalFromFloat(((float) $quantity) * ((float) $bdiValue));

        MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $request->user()->id,
            'source_type' => 'manual',
            'item' => $this->blankToNull($data['item'] ?? null),
            'nivel' => str_contains((string) ($data['item'] ?? ''), '.') ? 2 : 1,
            'item_type' => 'manual',
            'codigo' => $this->blankToNull($data['codigo'] ?? null),
            'banco' => $this->blankToNull($data['banco'] ?? null),
            'descricao' => trim($data['descricao']),
            'unidade' => $this->blankToNull($data['unidade'] ?? null),
            'quantidade_prevista' => $quantity,
            'valor_unitario' => $unitValue,
            'valor_com_bdi' => $bdiValue,
            'valor_total' => $totalValue,
            'meta' => ['created_from' => 'manual_form'],
        ]);

        return back()->with('success', 'Item de medição criado com sucesso.');
    }

    public function storeFromOrcamento(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id),
            ],
            'orcamento_id' => [
                'required',
                'integer',
                Rule::exists('orcamentos', 'id')
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'closed'),
            ],
        ]);

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);
        $orcamento = Orcamento::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'closed')
            ->with(['etapas.itens'])
            ->findOrFail($data['orcamento_id']);

        $created = 0;
        $skipped = 0;
        $useNaoDesonerado = $orcamento->encargos_sociais === 'nao_desonerado';

        DB::transaction(function () use ($tenant, $contract, $request, $orcamento, $useNaoDesonerado, &$created, &$skipped): void {
            foreach ($orcamento->etapas as $etapa) {
                $stageExists = MedicaoItem::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $contract->id)
                    ->where('source_orcamento_etapa_id', $etapa->id)
                    ->whereNull('source_orcamento_item_id')
                    ->exists();

                $stageQuantity = $this->decimalFromFloat(max((float) $etapa->quantidade, 1));
                $stageTotal = $etapa->itens->sum(function ($item) use ($useNaoDesonerado): float {
                    $quantity = (float) $item->quantidade;
                    $valueWithBdi = (float) ($useNaoDesonerado
                        ? $item->valor_com_bdi_nao_desonerado
                        : $item->valor_com_bdi_desonerado);

                    return $quantity * $valueWithBdi;
                });

                if (! $stageExists) {
                    MedicaoItem::create([
                        'tenant_id' => $tenant->id,
                        'contract_id' => $contract->id,
                        'created_by_id' => $request->user()->id,
                        'source_type' => 'orcamento',
                        'source_orcamento_id' => $orcamento->id,
                        'source_orcamento_etapa_id' => $etapa->id,
                        'item' => (string) $etapa->ordem,
                        'nivel' => 1,
                        'item_type' => 'etapa',
                        'descricao' => $etapa->descricao,
                        'quantidade_prevista' => $stageQuantity,
                        'valor_unitario' => '0.000000',
                        'valor_com_bdi' => '0.000000',
                        'valor_total' => $this->decimalFromFloat($stageTotal),
                        'meta' => ['orcamento_codigo' => $orcamento->codigo],
                    ]);

                    $created++;
                } else {
                    $skipped++;
                }

                foreach ($etapa->itens as $item) {
                    $itemExists = MedicaoItem::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('contract_id', $contract->id)
                        ->where('source_orcamento_item_id', $item->id)
                        ->exists();

                    if ($itemExists) {
                        $skipped++;
                        continue;
                    }

                    $unitValue = (float) ($useNaoDesonerado
                        ? $item->valor_unitario_nao_desonerado
                        : $item->valor_unitario_desonerado);
                    $valueWithBdi = (float) ($useNaoDesonerado
                        ? $item->valor_com_bdi_nao_desonerado
                        : $item->valor_com_bdi_desonerado);
                    $quantity = (float) $item->quantidade;

                    MedicaoItem::create([
                        'tenant_id' => $tenant->id,
                        'contract_id' => $contract->id,
                        'created_by_id' => $request->user()->id,
                        'source_type' => 'orcamento',
                        'source_orcamento_id' => $orcamento->id,
                        'source_orcamento_etapa_id' => $etapa->id,
                        'source_orcamento_item_id' => $item->id,
                        'item' => $etapa->ordem.'.'.$item->ordem,
                        'nivel' => 2,
                        'item_type' => $item->item_type,
                        'codigo' => $item->codigo,
                        'banco' => $item->banco,
                        'descricao' => $item->descricao,
                        'unidade' => $item->unidade,
                        'quantidade_prevista' => $this->decimalFromFloat($quantity),
                        'valor_unitario' => $this->decimalFromFloat($unitValue),
                        'valor_com_bdi' => $this->decimalFromFloat($valueWithBdi),
                        'valor_total' => $this->decimalFromFloat($quantity * $valueWithBdi),
                        'meta' => [
                            'orcamento_codigo' => $orcamento->codigo,
                            'orcamento_encargos_sociais' => $orcamento->encargos_sociais,
                        ],
                    ]);

                    $created++;
                }
            }
        });

        return back()->with(
            'success',
            "Itens importados do orçamento: {$created} criado(s), {$skipped} já existente(s)."
        );
    }

    public function importItems(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id),
            ],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:51200'],
            'first_item_row' => ['required', 'integer', 'min:1'],
            'last_item_row' => ['required', 'integer', 'gte:first_item_row'],
            'item_column' => ['required', 'string', 'max:6'],
            'codigo_column' => ['nullable', 'string', 'max:6'],
            'banco_column' => ['nullable', 'string', 'max:6'],
            'descricao_column' => ['required', 'string', 'max:6'],
            'unidade_column' => ['nullable', 'string', 'max:6'],
            'quantidade_column' => ['nullable', 'string', 'max:6'],
            'valor_unitario_column' => ['nullable', 'string', 'max:6'],
            'valor_com_bdi_column' => ['nullable', 'string', 'max:6'],
            'valor_total_column' => ['nullable', 'string', 'max:6'],
        ]);

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);
        $columns = $this->resolveColumnIndexes($data);
        $filePath = $request->file('file')->getRealPath();

        if (! $filePath) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo enviado.']);
        }

        $handle = fopen($filePath, 'rb');

        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'Não foi possível abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = $this->detectCsvDelimiter($firstLine);
        $rowNumber = 0;
        $created = 0;
        $duplicates = 0;
        $invalid = 0;
        $payloads = [];
        $seenKeys = [];
        $now = now();

        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;

                if ($this->isBlankCsvRow($row)) {
                    continue;
                }

                if ($rowNumber < (int) $data['first_item_row']) {
                    continue;
                }

                if ($rowNumber > (int) $data['last_item_row']) {
                    break;
                }

                $value = fn (string $field): string => $this->columnValue($row, $columns, $field);
                $description = $value('descricao_column');

                if ($description === '') {
                    $invalid++;
                    continue;
                }

                $itemCode = $this->blankToNull($value('item_column'));
                $codigo = $this->blankToNull($value('codigo_column'));
                $banco = $this->blankToNull($value('banco_column'));
                $unidade = $this->blankToNull($value('unidade_column'));
                $quantity = $this->parseDecimal($value('quantidade_column')) ?? '0.000000';
                $unitValue = $this->parseDecimal($value('valor_unitario_column')) ?? '0.000000';
                $valueWithBdi = $this->parseDecimal($value('valor_com_bdi_column')) ?? $unitValue;
                $totalValue = $this->parseDecimal($value('valor_total_column'))
                    ?? $this->decimalFromFloat(((float) $quantity) * ((float) $valueWithBdi));
                $isHeader = ! str_contains((string) $itemCode, '.');

                $duplicateKey = implode('|', [
                    $contract->id,
                    Str::lower((string) $itemCode),
                    Str::lower((string) $codigo),
                    Str::lower($description),
                ]);

                if (isset($seenKeys[$duplicateKey])) {
                    $duplicates++;
                    continue;
                }

                $alreadyExists = MedicaoItem::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $contract->id)
                    ->where('source_type', 'import')
                    ->where('item', $itemCode)
                    ->where('codigo', $codigo)
                    ->where('descricao', $description)
                    ->exists();

                if ($alreadyExists) {
                    $duplicates++;
                    continue;
                }

                $seenKeys[$duplicateKey] = true;

                $payloads[] = [
                    'tenant_id' => $tenant->id,
                    'contract_id' => $contract->id,
                    'created_by_id' => $request->user()->id,
                    'source_type' => 'import',
                    'item' => $itemCode,
                    'nivel' => $isHeader ? 1 : 2,
                    'item_type' => $isHeader ? 'etapa' : 'importado',
                    'codigo' => $codigo,
                    'banco' => $banco,
                    'descricao' => $description,
                    'unidade' => $unidade,
                    'quantidade_prevista' => $quantity,
                    'valor_unitario' => $isHeader ? '0.000000' : $unitValue,
                    'valor_com_bdi' => $isHeader ? '0.000000' : $valueWithBdi,
                    'valor_total' => $totalValue,
                    'meta' => json_encode(['created_from' => 'csv_import'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($payloads) >= 500) {
                    MedicaoItem::insert($payloads);
                    $created += count($payloads);
                    $payloads = [];
                }
            }

            if ($payloads !== []) {
                MedicaoItem::insert($payloads);
                $created += count($payloads);
            }
        } finally {
            fclose($handle);
        }

        return back()->with(
            'success',
            "Importação concluída: {$created} criado(s), {$duplicates} duplicado(s) ignorado(s), {$invalid} inválido(s)."
        );
    }

    public function storeAdditiveFromOrcamento(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate($this->additiveRules($tenant, [
            'orcamento_id' => [
                'required',
                'integer',
                Rule::exists('orcamentos', 'id')
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'closed'),
            ],
        ]));

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);
        $orcamento = Orcamento::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'closed')
            ->with(['etapas.itens'])
            ->findOrFail($data['orcamento_id']);

        $counts = $this->processAdditivePayloads(
            $request,
            $tenant,
            $contract,
            $this->payloadsFromOrcamento($orcamento),
            'orcamento',
            $orcamento,
            $data
        );

        return back()->with('success', $this->additiveSuccessMessage($counts));
    }

    public function importAdditiveItems(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate($this->additiveRules($tenant, [
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:51200'],
            'first_item_row' => ['required', 'integer', 'min:1'],
            'last_item_row' => ['required', 'integer', 'gte:first_item_row'],
            'item_column' => ['required', 'string', 'max:6'],
            'codigo_column' => ['nullable', 'string', 'max:6'],
            'banco_column' => ['nullable', 'string', 'max:6'],
            'descricao_column' => ['required', 'string', 'max:6'],
            'unidade_column' => ['nullable', 'string', 'max:6'],
            'quantidade_column' => ['nullable', 'string', 'max:6'],
            'valor_unitario_column' => ['nullable', 'string', 'max:6'],
            'valor_com_bdi_column' => ['nullable', 'string', 'max:6'],
            'valor_total_column' => ['nullable', 'string', 'max:6'],
        ]));

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);
        $payloads = $this->payloadsFromCsv($request, $data, $contract);

        $counts = $this->processAdditivePayloads(
            $request,
            $tenant,
            $contract,
            $payloads['items'],
            'csv',
            null,
            $data,
            $payloads['duplicates'],
            $payloads['invalid']
        );

        return back()->with('success', $this->additiveSuccessMessage($counts));
    }

    public function storeAdditiveManual(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate($this->additiveRules($tenant, [
            'item' => ['nullable', 'string', 'max:40'],
            'codigo' => ['nullable', 'string', 'max:80'],
            'banco' => ['nullable', 'string', 'max:40'],
            'descricao' => ['required', 'string', 'max:500'],
            'unidade' => ['nullable', 'string', 'max:30'],
            'quantidade_prevista' => ['nullable', 'string', 'max:30'],
            'valor_unitario' => ['nullable', 'string', 'max:30'],
            'valor_com_bdi' => ['nullable', 'string', 'max:30'],
            'valor_total' => ['nullable', 'string', 'max:30'],
        ]));

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);

        $counts = $this->processAdditivePayloads(
            $request,
            $tenant,
            $contract,
            [$this->manualPayload($data)],
            'manual',
            null,
            $data
        );

        return back()->with('success', $this->additiveSuccessMessage($counts));
    }

    private function additiveRules(Tenant $tenant, array $extraRules = []): array
    {
        return array_merge([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id),
            ],
            'additive_title' => ['nullable', 'string', 'max:180'],
            'additive_reason' => ['nullable', 'string', 'max:1000'],
            'effective_at' => ['nullable', 'date'],
        ], $extraRules);
    }

    private function processAdditivePayloads(
        Request $request,
        Tenant $tenant,
        Contract $contract,
        array $payloads,
        string $sourceType,
        ?Orcamento $orcamento,
        array $data,
        int $duplicates = 0,
        int $invalid = 0
    ): array {
        return DB::transaction(function () use ($request, $tenant, $contract, $payloads, $sourceType, $orcamento, $data, $duplicates, $invalid): array {
            $lastNumber = MedicaoItemAdditive::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->orderByDesc('number')
                ->lockForUpdate()
                ->value('number');
            $number = ((int) $lastNumber) + 1;

            foreach ($payloads as $payload) {
                $existingItem = $this->findMatchingMedicaoItem($tenant, $contract, $payload);

                if ($existingItem) {
                    $this->assertAdditiveQuantityDoesNotReduceMeasuredBalance($existingItem, $payload);
                }
            }

            $additive = MedicaoItemAdditive::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $request->user()->id,
                'source_orcamento_id' => $orcamento?->id,
                'number' => $number,
                'title' => $this->blankToNull($data['additive_title'] ?? null) ?? "Aditivo {$number}",
                'reason' => $this->blankToNull($data['additive_reason'] ?? null),
                'source_type' => $sourceType,
                'status' => 'applied',
                'effective_at' => $this->blankToNull($data['effective_at'] ?? null) ?? now(),
                'applied_at' => now(),
                'meta' => [
                    'source' => $sourceType,
                    'orcamento_codigo' => $orcamento?->codigo,
                ],
            ]);

            $counts = [
                'new' => 0,
                'changed' => 0,
                'unchanged' => 0,
                'duplicates' => $duplicates,
                'invalid' => $invalid,
                'number' => $number,
            ];

            foreach ($payloads as $payload) {
                $existingItem = $this->findMatchingMedicaoItem($tenant, $contract, $payload);

                if (! $existingItem) {
                    $newItem = MedicaoItem::create(array_merge($payload, [
                        'tenant_id' => $tenant->id,
                        'contract_id' => $contract->id,
                        'created_by_id' => $request->user()->id,
                        'source_type' => 'aditivo',
                        'meta' => array_merge($payload['meta'] ?? [], [
                            'additive_id' => $additive->id,
                            'additive_number' => $additive->number,
                        ]),
                    ]));

                    $version = $this->createItemVersion($newItem, $additive, $request, 1, "Aditivo {$additive->number}", 'new', $payload);
                    $this->createAdditiveItemSnapshot($additive, $newItem, $version, 'novo', null, $payload);
                    $counts['new']++;

                    continue;
                }

                $baseVersion = $this->ensureBaseVersion($existingItem, $request);

                if (! $this->itemPayloadChanged($existingItem, $payload)) {
                    $this->createAdditiveItemSnapshot($additive, $existingItem, $baseVersion, 'sem_alteracao', $this->snapshotFromItem($existingItem), $payload);
                    $counts['unchanged']++;

                    continue;
                }

                $oldSnapshot = $this->snapshotFromItem($existingItem);
                $versionNumber = ((int) $existingItem->versions()->max('version_number')) + 1;
                $version = $this->createItemVersion($existingItem, $additive, $request, $versionNumber, "Aditivo {$additive->number}", 'changed', $payload);

                $existingItem->fill([
                    'source_orcamento_id' => $payload['source_orcamento_id'] ?? $existingItem->source_orcamento_id,
                    'source_orcamento_etapa_id' => $payload['source_orcamento_etapa_id'] ?? $existingItem->source_orcamento_etapa_id,
                    'source_orcamento_item_id' => $payload['source_orcamento_item_id'] ?? $existingItem->source_orcamento_item_id,
                    'item' => $payload['item'] ?? $existingItem->item,
                    'nivel' => $payload['nivel'] ?? $existingItem->nivel,
                    'item_type' => $payload['item_type'] ?? $existingItem->item_type,
                    'codigo' => $payload['codigo'] ?? null,
                    'banco' => $payload['banco'] ?? null,
                    'descricao' => $payload['descricao'],
                    'unidade' => $payload['unidade'] ?? null,
                    'quantidade_prevista' => $payload['quantidade_prevista'],
                    'valor_unitario' => $payload['valor_unitario'],
                    'valor_com_bdi' => $payload['valor_com_bdi'],
                    'valor_total' => $payload['valor_total'],
                    'meta' => array_merge($existingItem->meta ?? [], $payload['meta'] ?? [], [
                        'latest_additive_id' => $additive->id,
                        'latest_additive_number' => $additive->number,
                    ]),
                ])->save();

                $this->createAdditiveItemSnapshot($additive, $existingItem, $version, 'alterado', $oldSnapshot, $payload);
                $counts['changed']++;
            }

            return $counts;
        });
    }

    private function assertAdditiveQuantityDoesNotReduceMeasuredBalance(MedicaoItem $item, array $payload): void
    {
        $novaQuantidade = (float) ($payload['quantidade_prevista'] ?? 0);
        $quantidadeMedida = $this->measuredQuantityForItem($item);

        if ($novaQuantidade + 0.000001 >= $quantidadeMedida) {
            return;
        }

        throw ValidationException::withMessages([
            'quantidade_prevista' => sprintf(
                'O item %s não pode ser reduzido para %s, pois já possui %s medido/aprovado pela medição.',
                $item->item ?: $item->codigo ?: $item->id,
                number_format($novaQuantidade, 4, ',', '.'),
                number_format($quantidadeMedida, 4, ',', '.')
            ),
        ]);
    }

    private function measuredQuantityForItem(MedicaoItem $item): float
    {
        return (float) DB::table('folha_rosto_item_analises as analises')
            ->join('folha_rosto_itens as fri', 'fri.id', '=', 'analises.folha_rosto_item_id')
            ->join('folhas_rosto as fr', 'fr.id', '=', 'fri.folha_rosto_id')
            ->join('ordem_servico_itens as osi', 'osi.id', '=', 'fri.ordem_servico_item_id')
            ->where('osi.medicao_item_id', $item->id)
            ->where('analises.setor', 'medicao')
            ->where('fr.status', 'analisada')
            ->whereNull('fr.deleted_at')
            ->sum('analises.quantidade_aprovada');
    }

    private function payloadsFromOrcamento(Orcamento $orcamento): array
    {
        $payloads = [];
        $useNaoDesonerado = $orcamento->encargos_sociais === 'nao_desonerado';

        foreach ($orcamento->etapas as $etapa) {
            $stageTotal = $etapa->itens->sum(function ($item) use ($useNaoDesonerado): float {
                $quantity = (float) $item->quantidade;
                $valueWithBdi = (float) ($useNaoDesonerado
                    ? $item->valor_com_bdi_nao_desonerado
                    : $item->valor_com_bdi_desonerado);

                return $quantity * $valueWithBdi;
            });

            $payloads[] = [
                'source_orcamento_id' => $orcamento->id,
                'source_orcamento_etapa_id' => $etapa->id,
                'source_orcamento_item_id' => null,
                'item' => (string) $etapa->ordem,
                'nivel' => 1,
                'item_type' => 'etapa',
                'codigo' => null,
                'banco' => null,
                'descricao' => $etapa->descricao,
                'unidade' => null,
                'quantidade_prevista' => '1.000000',
                'valor_unitario' => '0.000000',
                'valor_com_bdi' => '0.000000',
                'valor_total' => $this->decimalFromFloat($stageTotal),
                'meta' => ['orcamento_codigo' => $orcamento->codigo],
            ];

            foreach ($etapa->itens as $item) {
                $unitValue = (float) ($useNaoDesonerado
                    ? $item->valor_unitario_nao_desonerado
                    : $item->valor_unitario_desonerado);
                $valueWithBdi = (float) ($useNaoDesonerado
                    ? $item->valor_com_bdi_nao_desonerado
                    : $item->valor_com_bdi_desonerado);
                $quantity = (float) $item->quantidade;

                $payloads[] = [
                    'source_orcamento_id' => $orcamento->id,
                    'source_orcamento_etapa_id' => $etapa->id,
                    'source_orcamento_item_id' => $item->id,
                    'item' => $etapa->ordem.'.'.$item->ordem,
                    'nivel' => 2,
                    'item_type' => $item->item_type,
                    'codigo' => $item->codigo,
                    'banco' => $item->banco,
                    'descricao' => $item->descricao,
                    'unidade' => $item->unidade,
                    'quantidade_prevista' => $this->decimalFromFloat($quantity),
                    'valor_unitario' => $this->decimalFromFloat($unitValue),
                    'valor_com_bdi' => $this->decimalFromFloat($valueWithBdi),
                    'valor_total' => $this->decimalFromFloat($quantity * $valueWithBdi),
                    'meta' => [
                        'orcamento_codigo' => $orcamento->codigo,
                        'orcamento_encargos_sociais' => $orcamento->encargos_sociais,
                    ],
                ];
            }
        }

        return $payloads;
    }

    private function payloadsFromCsv(Request $request, array $data, Contract $contract): array
    {
        $columns = $this->resolveColumnIndexes($data);
        $filePath = $request->file('file')?->getRealPath();

        if (! $filePath) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo enviado.']);
        }

        $handle = fopen($filePath, 'rb');

        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'Não foi possível abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = $this->detectCsvDelimiter($firstLine);
        $rowNumber = 0;
        $duplicates = 0;
        $invalid = 0;
        $payloads = [];
        $seenKeys = [];

        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;

                if ($this->isBlankCsvRow($row) || $rowNumber < (int) $data['first_item_row']) {
                    continue;
                }

                if ($rowNumber > (int) $data['last_item_row']) {
                    break;
                }

                $value = fn (string $field): string => $this->columnValue($row, $columns, $field);
                $description = $value('descricao_column');

                if ($description === '') {
                    $invalid++;
                    continue;
                }

                $itemCode = $this->blankToNull($value('item_column'));
                $codigo = $this->blankToNull($value('codigo_column'));
                $banco = $this->blankToNull($value('banco_column'));
                $unidade = $this->blankToNull($value('unidade_column'));
                $quantity = $this->parseDecimal($value('quantidade_column')) ?? '0.000000';
                $unitValue = $this->parseDecimal($value('valor_unitario_column')) ?? '0.000000';
                $valueWithBdi = $this->parseDecimal($value('valor_com_bdi_column')) ?? $unitValue;
                $totalValue = $this->parseDecimal($value('valor_total_column'))
                    ?? $this->decimalFromFloat(((float) $quantity) * ((float) $valueWithBdi));
                $isHeader = ! str_contains((string) $itemCode, '.');

                $duplicateKey = implode('|', [
                    $contract->id,
                    Str::lower((string) $itemCode),
                    Str::lower((string) $codigo),
                    Str::lower($description),
                ]);

                if (isset($seenKeys[$duplicateKey])) {
                    $duplicates++;
                    continue;
                }

                $seenKeys[$duplicateKey] = true;

                $payloads[] = [
                    'source_orcamento_id' => null,
                    'source_orcamento_etapa_id' => null,
                    'source_orcamento_item_id' => null,
                    'item' => $itemCode,
                    'nivel' => $isHeader ? 1 : 2,
                    'item_type' => $isHeader ? 'etapa' : 'importado',
                    'codigo' => $codigo,
                    'banco' => $banco,
                    'descricao' => $description,
                    'unidade' => $unidade,
                    'quantidade_prevista' => $quantity,
                    'valor_unitario' => $isHeader ? '0.000000' : $unitValue,
                    'valor_com_bdi' => $isHeader ? '0.000000' : $valueWithBdi,
                    'valor_total' => $totalValue,
                    'meta' => ['created_from' => 'csv_additive'],
                ];
            }
        } finally {
            fclose($handle);
        }

        return [
            'items' => $payloads,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
        ];
    }

    private function manualPayload(array $data): array
    {
        $quantity = $this->parseDecimal($data['quantidade_prevista'] ?? null) ?? '0.000000';
        $unitValue = $this->parseDecimal($data['valor_unitario'] ?? null) ?? '0.000000';
        $bdiValue = $this->parseDecimal($data['valor_com_bdi'] ?? null) ?? $unitValue;
        $totalValue = $this->parseDecimal($data['valor_total'] ?? null)
            ?? $this->decimalFromFloat(((float) $quantity) * ((float) $bdiValue));
        $itemCode = $this->blankToNull($data['item'] ?? null);
        $isHeader = $itemCode !== null && ! str_contains($itemCode, '.');

        return [
            'source_orcamento_id' => null,
            'source_orcamento_etapa_id' => null,
            'source_orcamento_item_id' => null,
            'item' => $itemCode,
            'nivel' => $isHeader ? 1 : 2,
            'item_type' => $isHeader ? 'etapa' : 'manual',
            'codigo' => $this->blankToNull($data['codigo'] ?? null),
            'banco' => $this->blankToNull($data['banco'] ?? null),
            'descricao' => trim($data['descricao']),
            'unidade' => $this->blankToNull($data['unidade'] ?? null),
            'quantidade_prevista' => $quantity,
            'valor_unitario' => $isHeader ? '0.000000' : $unitValue,
            'valor_com_bdi' => $isHeader ? '0.000000' : $bdiValue,
            'valor_total' => $totalValue,
            'meta' => ['created_from' => 'manual_additive_form'],
        ];
    }

    private function findMatchingMedicaoItem(Tenant $tenant, Contract $contract, array $payload): ?MedicaoItem
    {
        $query = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id);

        if ($this->blankToNull($payload['item'] ?? null) !== null) {
            return $query->where('item', $payload['item'])->first();
        }

        if ($this->blankToNull($payload['codigo'] ?? null) !== null) {
            return $query
                ->where('codigo', $payload['codigo'])
                ->where('descricao', $payload['descricao'])
                ->first();
        }

        return $query->where('descricao', $payload['descricao'])->first();
    }

    private function ensureBaseVersion(MedicaoItem $item, Request $request): MedicaoItemVersion
    {
        $existing = $item->versions()->orderBy('version_number')->first();

        if ($existing) {
            return $existing;
        }

        return $this->createItemVersion($item, null, $request, 1, 'Base', 'base', $this->snapshotFromItem($item));
    }

    private function createItemVersion(
        MedicaoItem $item,
        ?MedicaoItemAdditive $additive,
        Request $request,
        int $versionNumber,
        string $versionLabel,
        string $changeType,
        array $payload
    ): MedicaoItemVersion {
        return MedicaoItemVersion::create([
            'tenant_id' => $item->tenant_id,
            'contract_id' => $item->contract_id,
            'medicao_item_id' => $item->id,
            'additive_id' => $additive?->id,
            'created_by_id' => $request->user()->id,
            'version_number' => $versionNumber,
            'version_label' => $versionLabel,
            'change_type' => $changeType,
            'quantidade_prevista' => $payload['quantidade_prevista'],
            'valor_unitario' => $payload['valor_unitario'],
            'valor_com_bdi' => $payload['valor_com_bdi'],
            'valor_total' => $payload['valor_total'],
            'starts_at' => $additive?->effective_at ?? $item->created_at ?? now(),
            'snapshot' => $payload,
        ]);
    }

    private function createAdditiveItemSnapshot(
        MedicaoItemAdditive $additive,
        MedicaoItem $item,
        ?MedicaoItemVersion $version,
        string $status,
        ?array $old,
        array $new
    ): void {
        MedicaoItemAdditiveItem::create([
            'tenant_id' => $additive->tenant_id,
            'contract_id' => $additive->contract_id,
            'additive_id' => $additive->id,
            'medicao_item_id' => $item->id,
            'medicao_item_version_id' => $version?->id,
            'status' => $status,
            'item' => $new['item'] ?? $item->item,
            'codigo' => $new['codigo'] ?? null,
            'banco' => $new['banco'] ?? null,
            'descricao' => $new['descricao'] ?? null,
            'unidade' => $new['unidade'] ?? null,
            'quantidade_anterior' => $old['quantidade_prevista'] ?? null,
            'quantidade_nova' => $new['quantidade_prevista'] ?? null,
            'valor_unitario_anterior' => $old['valor_unitario'] ?? null,
            'valor_unitario_novo' => $new['valor_unitario'] ?? null,
            'valor_com_bdi_anterior' => $old['valor_com_bdi'] ?? null,
            'valor_com_bdi_novo' => $new['valor_com_bdi'] ?? null,
            'valor_total_anterior' => $old['valor_total'] ?? null,
            'valor_total_novo' => $new['valor_total'] ?? null,
            'meta' => [
                'old' => $old,
                'new' => $new,
            ],
        ]);
    }

    private function itemPayloadChanged(MedicaoItem $item, array $payload): bool
    {
        foreach (['item', 'codigo', 'banco', 'descricao', 'unidade'] as $field) {
            if ((string) ($item->{$field} ?? '') !== (string) ($payload[$field] ?? '')) {
                return true;
            }
        }

        foreach (['quantidade_prevista', 'valor_unitario', 'valor_com_bdi', 'valor_total'] as $field) {
            if ($this->decimalFromFloat((float) ($item->{$field} ?? 0)) !== $this->decimalFromFloat((float) ($payload[$field] ?? 0))) {
                return true;
            }
        }

        return false;
    }

    private function snapshotFromItem(MedicaoItem $item): array
    {
        return [
            'source_orcamento_id' => $item->source_orcamento_id,
            'source_orcamento_etapa_id' => $item->source_orcamento_etapa_id,
            'source_orcamento_item_id' => $item->source_orcamento_item_id,
            'item' => $item->item,
            'nivel' => $item->nivel,
            'item_type' => $item->item_type,
            'codigo' => $item->codigo,
            'banco' => $item->banco,
            'descricao' => $item->descricao,
            'unidade' => $item->unidade,
            'quantidade_prevista' => $item->quantidade_prevista,
            'valor_unitario' => $item->valor_unitario,
            'valor_com_bdi' => $item->valor_com_bdi,
            'valor_total' => $item->valor_total,
            'meta' => $item->meta ?? [],
        ];
    }

    private function additiveSuccessMessage(array $counts): string
    {
        return "Aditivo {$counts['number']} aplicado: {$counts['new']} novo(s), {$counts['changed']} alterado(s), {$counts['unchanged']} sem alteração, {$counts['duplicates']} duplicado(s), {$counts['invalid']} inválido(s).";
    }

    private function accessibleContracts(Request $request, Tenant $tenant)
    {
        $query = $tenant->contracts();
        $tenantRole = $request->user()->tenantRole($tenant);

        if (! in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
            $query->whereHas('participants', function (Builder $query) use ($request): void {
                $query->where('user_id', $request->user()->id)
                    ->where('status', 'active');
            });
        }

        return $query;
    }

    private function contractForRequest(Request $request, Tenant $tenant, int $contractId): Contract
    {
        $contract = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($contractId);

        abort_unless($this->accessibleContracts($request, $tenant)->whereKey($contract->id)->exists(), 403);

        return $contract;
    }

    private function applyHeaderTotals(Collection $items): Collection
    {
        return $items
            ->map(function (array $item) use ($items): array {
                if (! $this->isHeaderItem($item)) {
                    $item['is_header'] = false;

                    return $item;
                }

                $childrenTotal = $items
                    ->filter(fn (array $candidate): bool => $this->belongsToHeader(
                        $candidate['item'] ?? null,
                        $item['item'] ?? null
                    ) && $this->itemContributesToTotal($candidate))
                    ->sum(fn (array $candidate): float => (float) ($candidate['valor_total'] ?? 0));

                $item['is_header'] = true;
                $item['codigo'] = null;
                $item['banco'] = null;
                $item['unidade'] = null;
                $item['valor_unitario'] = null;
                $item['valor_com_bdi'] = null;
                $item['valor_total'] = $this->decimalFromFloat($childrenTotal);

                return $item;
            })
            ->values();
    }

    private function totalValueForItems(Collection $items): float
    {
        return $items
            ->filter(fn (array $item): bool => $this->itemContributesToTotal($item))
            ->sum(fn (array $item): float => (float) ($item['valor_total'] ?? 0));
    }

    private function isHeaderItem(array $item): bool
    {
        return ($item['item_type'] ?? null) === 'etapa';
    }

    private function belongsToHeader(mixed $candidateItem, mixed $headerItem): bool
    {
        if ($candidateItem === null || $headerItem === null) {
            return false;
        }

        $candidate = (string) $candidateItem;
        $header = (string) $headerItem;

        return $candidate !== $header && str_starts_with($candidate, $header.'.');
    }

    private function itemContributesToTotal(array $item): bool
    {
        return ! $this->isHeaderItem($item)
            && (
                (float) ($item['valor_unitario'] ?? 0) > 0
                || (float) ($item['valor_com_bdi'] ?? 0) > 0
            );
    }

    private function serializeItem(MedicaoItem $item): array
    {
        return [
            'id' => $item->id,
            'source_type' => $item->source_type,
            'source_label' => match ($item->source_type) {
                'orcamento' => 'Orçamento',
                'import' => 'Importação',
                'aditivo' => 'Aditivo',
                default => 'Manual',
            },
            'version_label' => $item->latestVersion?->version_label ?? 'Base',
            'version_number' => $item->latestVersion?->version_number ?? 1,
            'item' => $item->item,
            'nivel' => $item->nivel,
            'item_type' => $item->item_type,
            'is_header' => $item->item_type === 'etapa',
            'codigo' => $item->codigo,
            'banco' => $item->banco,
            'descricao' => $item->descricao,
            'unidade' => $item->unidade,
            'quantidade_prevista' => $item->quantidade_prevista,
            'valor_unitario' => $item->valor_unitario,
            'valor_com_bdi' => $item->valor_com_bdi,
            'valor_total' => $item->valor_total,
            'base_history' => $this->serializeBaseHistory($item),
            'additive_history' => $item->additiveItems
                ->sortBy(fn (MedicaoItemAdditiveItem $additiveItem): int => $additiveItem->additive?->number ?? 0)
                ->map(fn (MedicaoItemAdditiveItem $additiveItem): array => [
                    'id' => $additiveItem->id,
                    'additive_id' => $additiveItem->additive_id,
                    'number' => $additiveItem->additive?->number,
                    'title' => $additiveItem->additive?->title,
                    'reason' => $additiveItem->additive?->reason,
                    'status' => $additiveItem->status,
                    'status_label' => match ($additiveItem->status) {
                        'novo' => 'Novo item',
                        'alterado' => 'Alterado',
                        'sem_alteracao' => 'Sem alteração',
                        default => Str::headline((string) $additiveItem->status),
                    },
                    'source_type' => $additiveItem->additive?->source_type,
                    'effective_at' => $additiveItem->additive?->effective_at?->format('d/m/Y'),
                    'applied_at' => $additiveItem->additive?->applied_at?->format('d/m/Y H:i'),
                    'created_at' => $additiveItem->created_at?->format('d/m/Y H:i'),
                    'quantidade_anterior' => $additiveItem->quantidade_anterior,
                    'quantidade_nova' => $additiveItem->quantidade_nova,
                    'valor_total_anterior' => $additiveItem->valor_total_anterior,
                    'valor_total_novo' => $additiveItem->valor_total_novo,
                ])
                ->values(),
            'created_at' => $item->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function serializeBaseHistory(MedicaoItem $item): array
    {
        $baseVersion = $item->versions
            ->sortBy('version_number')
            ->first(fn (MedicaoItemVersion $version): bool => (int) $version->version_number === 1);

        return [
            'label' => 'Base',
            'status_label' => 'Primeira importação',
            'created_at' => $baseVersion?->created_at?->format('d/m/Y H:i') ?? $item->created_at?->format('d/m/Y H:i'),
            'quantidade' => $baseVersion?->quantidade_prevista ?? $item->quantidade_prevista,
            'valor_unitario' => $baseVersion?->valor_unitario ?? $item->valor_unitario,
            'valor_com_bdi' => $baseVersion?->valor_com_bdi ?? $item->valor_com_bdi,
            'valor_total' => $baseVersion?->valor_total ?? $item->valor_total,
        ];
    }

    private function serializeIndiceReajuste(MedicaoIndiceReajuste $indice): array
    {
        $indiceBase = (float) $indice->indice_base;
        $indiceAtual = (float) $indice->indice_atual;
        $fator = $indiceBase > 0 ? (($indiceAtual - $indiceBase) / $indiceBase) : 0.0;

        return [
            'id' => $indice->id,
            'nome' => $indice->nome,
            'codigo' => $indice->codigo,
            'indice_base' => $indice->indice_base,
            'data_base' => $indice->data_base?->format('Y-m-d'),
            'data_base_label' => $indice->data_base?->format('d/m/Y'),
            'indice_atual' => $indice->indice_atual,
            'data_atual' => $indice->data_atual?->format('Y-m-d'),
            'data_atual_label' => $indice->data_atual?->format('d/m/Y'),
            'fator_reajuste' => $fator,
            'percentual_reajuste' => $fator * 100,
            'observacao' => $indice->observacao,
            'created_by' => $indice->creator?->name,
            'created_at' => $indice->created_at?->format('d/m/Y H:i'),
            'competencias' => $indice->competencias
                ->sortByDesc('competencia')
                ->map(function (MedicaoIndiceReajusteCompetencia $competencia) use ($indice): array {
                    $indiceBase = (float) $indice->indice_base;
                    $valor = (float) $competencia->valor_indice;
                    $fator = $indiceBase > 0 ? (($valor - $indiceBase) / $indiceBase) : 0.0;

                    return [
                        'id' => $competencia->id,
                        'competencia' => $competencia->competencia?->format('Y-m'),
                        'competencia_label' => $competencia->competencia?->format('m/Y'),
                        'valor_indice' => $competencia->valor_indice,
                        'data_publicacao' => $competencia->data_publicacao?->format('Y-m-d'),
                        'data_publicacao_label' => $competencia->data_publicacao?->format('d/m/Y'),
                        'fator_reajuste' => $fator,
                        'percentual_reajuste' => $fator * 100,
                        'observacao' => $competencia->observacao,
                        'created_by' => $competencia->creator?->name,
                        'created_at' => $competencia->created_at?->format('d/m/Y H:i'),
                    ];
                })
                ->values(),
        ];
    }

    private function serializeItemReajusteLink(MedicaoItem $item): array
    {
        return [
            'id' => $item->id,
            'item' => $item->item,
            'codigo' => $item->codigo,
            'descricao' => $item->descricao,
            'unidade' => $item->unidade,
            'valor_com_bdi' => $item->valor_com_bdi,
            'indice_id' => $item->reajusteIndice?->medicao_indice_reajuste_id,
            'indice_codigo' => $item->reajusteIndice?->indice?->codigo,
            'indice_nome' => $item->reajusteIndice?->indice?->nome,
            'source_type' => $item->reajusteIndice?->source_type,
            'updated_at' => $item->reajusteIndice?->updated_at?->format('d/m/Y H:i'),
        ];
    }

    private function resolveColumnIndexes(array $data): array
    {
        $fields = [
            'item_column',
            'codigo_column',
            'banco_column',
            'descricao_column',
            'unidade_column',
            'quantidade_column',
            'valor_unitario_column',
            'valor_com_bdi_column',
            'valor_total_column',
        ];

        $columns = [];

        foreach ($fields as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $columns[$field] = $this->columnLetterToIndex($value);
        }

        return $columns;
    }

    private function columnLetterToIndex(string $letter): int
    {
        $letter = strtoupper(trim($letter));

        if (! preg_match('/^[A-Z]+$/', $letter)) {
            throw ValidationException::withMessages(['file' => 'Informe as colunas usando letras, como A, B, C ou AA.']);
        }

        $index = 0;

        foreach (str_split($letter) as $char) {
            $index = ($index * 26) + (ord($char) - 64);
        }

        return $index - 1;
    }

    private function columnValue(array $row, array $columns, string $field): string
    {
        if (! array_key_exists($field, $columns)) {
            return '';
        }

        return $this->normalizeCsvValue((string) ($row[$columns[$field]] ?? ''));
    }

    private function detectCsvDelimiter(string $line): string
    {
        $line = $this->normalizeCsvValue($line);
        $separatorDirective = Str::of($line)->lower()->trim();

        if ($separatorDirective->startsWith('sep=')) {
            $separator = $separatorDirective->after('sep=')->substr(0, 1)->toString();

            return $separator === '\t' ? "\t" : ($separator ?: ',');
        }

        $scores = collect([';', "\t", ',', '|'])
            ->mapWithKeys(function (string $delimiter) use ($line): array {
                $columns = str_getcsv($line, $delimiter);
                $filledColumns = collect($columns)
                    ->filter(fn ($column): bool => trim((string) $column) !== '')
                    ->count();

                return [$delimiter => (count($columns) * 10) + $filledColumns];
            });

        $delimiter = (string) $scores->sortDesc()->keys()->first();

        return (($scores[$delimiter] ?? 0) > 11) ? $delimiter : ',';
    }

    private function isBlankCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeCsvValue((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCsvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            $value = substr($value, 3);
        }

        if (str_starts_with($value, "\xFF\xFE")) {
            $value = (string) @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($value, "\xFE\xFF")) {
            $value = (string) @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE');
        } elseif (! mb_check_encoding($value, 'UTF-8')) {
            foreach (['Windows-1252', 'ISO-8859-1', 'CP850'] as $encoding) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

                if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                    $value = $converted;
                    break;
                }
            }
        }

        return trim(str_replace("\xC2\xA0", ' ', $value));
    }

    private function parseDecimal(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(['R$', ' '], '', $value);
        $value = preg_replace('/[^\d,.\-]/', '', $value) ?? '';

        if ($value === '' || $value === '-') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? $this->decimalFromFloat((float) $value) : null;
    }

    private function normalizeDecimalInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = str_replace(['R$', ' '], '', trim($value));

        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return $value;
    }

    private function normalizeCompetenciaInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $value.'-01';
        }

        return $value;
    }

    private function decimalFromFloat(float $value): string
    {
        return number_format($value, 6, '.', '');
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
