<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\MedicaoItem;
use App\Models\Orcamento;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $items = $selectedContractId
            ? MedicaoItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $selectedContractId)
                ->orderBy('id')
                ->get()
                ->map(fn (MedicaoItem $item): array => $this->serializeItem($item))
                ->values()
            : collect();

        $orcamentos = $tenant->orcamentos()
            ->withCount(['etapas', 'itens'])
            ->orderByDesc('created_at')
            ->get(['id', 'codigo', 'descricao', 'encargos_sociais', 'status', 'valor_nao_desonerado', 'valor_desonerado'])
            ->map(fn (Orcamento $orcamento): array => [
                'id' => $orcamento->id,
                'codigo' => $orcamento->codigo,
                'descricao' => $orcamento->descricao,
                'status' => $orcamento->status,
                'encargos_sociais' => $orcamento->encargos_sociais,
                'etapas_count' => $orcamento->etapas_count,
                'itens_count' => $orcamento->itens_count,
                'valor_referencia' => $orcamento->encargos_sociais === 'nao_desonerado'
                    ? $orcamento->valor_nao_desonerado
                    : $orcamento->valor_desonerado,
            ]);

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
            'items' => $items,
            'stats' => [
                'total_items' => $items->count(),
                'total_value' => $items->sum(fn (array $item): float => (float) $item['valor_total']),
            ],
        ]);
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
                Rule::exists('orcamentos', 'id')->where('tenant_id', $tenant->id),
            ],
        ]);

        $contract = $this->contractForRequest($request, $tenant, (int) $data['contract_id']);
        $orcamento = Orcamento::query()
            ->where('tenant_id', $tenant->id)
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
                        'valor_unitario' => $this->decimalFromFloat($stageTotal / max((float) $stageQuantity, 1)),
                        'valor_com_bdi' => $this->decimalFromFloat($stageTotal / max((float) $stageQuantity, 1)),
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
                    'nivel' => str_contains((string) $itemCode, '.') ? 2 : 1,
                    'item_type' => str_contains((string) $itemCode, '.') ? 'importado' : 'etapa',
                    'codigo' => $codigo,
                    'banco' => $banco,
                    'descricao' => $description,
                    'unidade' => $unidade,
                    'quantidade_prevista' => $quantity,
                    'valor_unitario' => $unitValue,
                    'valor_com_bdi' => $valueWithBdi,
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

    private function serializeItem(MedicaoItem $item): array
    {
        return [
            'id' => $item->id,
            'source_type' => $item->source_type,
            'source_label' => match ($item->source_type) {
                'orcamento' => 'Orçamento',
                'import' => 'Importação',
                default => 'Manual',
            },
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
            'created_at' => $item->created_at?->format('d/m/Y H:i'),
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
