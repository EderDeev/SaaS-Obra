<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BoletimMedicao;
use App\Models\Empresa;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoItem;
use App\Models\MedicaoItem;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicaoRelatorioController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        $selectedContractId = $request->integer('contract_id') ?: null;
        $selectedReport = $request->string('relatorio')->toString() ?: 'pleito_preliminar';

        $contracts = $tenant->contracts()
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $boletins = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->when(! $selectedContractId, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('periodo')
            ->orderByDesc('id')
            ->get(['id', 'contract_id', 'codigo', 'periodo', 'tipo', 'status'])
            ->map(fn (BoletimMedicao $boletim): array => $this->serializeBoletim($boletim))
            ->values();

        $selectedBoletimId = $request->integer('boletim_id') ?: null;

        $boletim = $selectedBoletimId
            ? BoletimMedicao::query()
                ->where('tenant_id', $tenant->id)
                ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
                ->with($this->boletimReportRelations())
                ->find($selectedBoletimId)
            : null;

        return Inertia::render('Tenant/Medicao/Relatorios/Index', [
            'selectedContractId' => $selectedContractId,
            'selectedBoletimId' => $boletim?->id,
            'selectedReport' => $selectedReport,
            'contracts' => $contracts,
            'boletins' => $boletins,
            'reports' => [
                ['value' => 'pleito_preliminar', 'label' => 'Pleito preliminar'],
                ['value' => 'analise_pleito', 'label' => 'An?lise do Pleito'],
                ['value' => 'sintetico', 'label' => 'Sint?tico'],
                ['value' => 'por_fr', 'label' => 'Por FR'],
                ['value' => 'resumo', 'label' => 'Resumo'],
            ],
            'boletim' => $boletim ? $this->serializeBoletim($boletim) : null,
            'reportData' => $this->reportData($tenant, $boletim, $selectedReport),
        ]);
    }

    public function exportPleitoPreliminarExcel(Request $request, Tenant $tenant): StreamedResponse
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->pleitoPreliminarHeaders();
        $rows = $this->pleitoPreliminarRows($tenant, $boletim);
        $spreadsheet = $this->buildPleitoPreliminarSpreadsheet($boletim, $headers, $rows, 'Pleito preliminar', $this->reportTotals($headers, $rows));

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $this->exportFileName($boletim, 'pleito-preliminar', 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportPleitoPreliminarPdf(Request $request, Tenant $tenant)
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->pleitoPreliminarHeaders();
        $rows = $this->pleitoPreliminarRows($tenant, $boletim);
        $totals = $this->reportTotals($headers, $rows);

        $pdf = Pdf::loadView('pdf.medicao-pleito-preliminar', [
            'title' => 'Pleito preliminar',
            'boletim' => $this->serializeBoletim($boletim),
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $totals,
        ])->setPaper('a3', 'landscape');

        return $pdf->download($this->exportFileName($boletim, 'pleito-preliminar', 'pdf'));
    }

    public function exportAnalisePleitoExcel(Request $request, Tenant $tenant): StreamedResponse
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->analisePleitoHeaders();
        $rows = $this->analisePleitoRows($tenant, $boletim);
        $spreadsheet = $this->buildPleitoPreliminarSpreadsheet($boletim, $headers, $rows, 'An?lise do Pleito', $this->reportTotals($headers, $rows));

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $this->exportFileName($boletim, 'analise-pleito', 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportAnalisePleitoPdf(Request $request, Tenant $tenant)
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->analisePleitoHeaders();
        $rows = $this->analisePleitoRows($tenant, $boletim);

        $pdf = Pdf::loadView('pdf.medicao-pleito-preliminar', [
            'title' => 'An?lise do Pleito',
            'boletim' => $this->serializeBoletim($boletim),
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $this->reportTotals($headers, $rows),
        ])->setPaper('a3', 'landscape');

        return $pdf->download($this->exportFileName($boletim, 'analise-pleito', 'pdf'));
    }

    public function exportSinteticoExcel(Request $request, Tenant $tenant): StreamedResponse
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->sinteticoHeaders();
        $rows = $this->sinteticoRows($tenant, $boletim);
        $spreadsheet = $this->buildPleitoPreliminarSpreadsheet($boletim, $headers, $rows, 'Sint?tico', $this->reportTotals($headers, $rows));

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $this->exportFileName($boletim, 'sintetico', 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportSinteticoPdf(Request $request, Tenant $tenant)
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->sinteticoHeaders();
        $rows = $this->sinteticoRows($tenant, $boletim);

        $pdf = Pdf::loadView('pdf.medicao-pleito-preliminar', [
            'title' => 'Sint?tico',
            'boletim' => $this->serializeBoletim($boletim),
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $this->reportTotals($headers, $rows),
        ])->setPaper('a3', 'landscape');

        return $pdf->download($this->exportFileName($boletim, 'sintetico', 'pdf'));
    }

    public function exportPorFrExcel(Request $request, Tenant $tenant): StreamedResponse
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->porFrHeaders();
        $rows = $this->porFrRows($tenant, $boletim);
        $spreadsheet = $this->buildPleitoPreliminarSpreadsheet($boletim, $headers, $rows, 'Por FR', $this->reportTotals($headers, $rows));

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $this->exportFileName($boletim, 'por-fr', 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportPorFrPdf(Request $request, Tenant $tenant)
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->porFrHeaders();
        $rows = $this->porFrRows($tenant, $boletim);

        $pdf = Pdf::loadView('pdf.medicao-pleito-preliminar', [
            'title' => 'Por FR',
            'boletim' => $this->serializeBoletim($boletim),
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $this->reportTotals($headers, $rows),
        ])->setPaper('a3', 'landscape');

        return $pdf->download($this->exportFileName($boletim, 'por-fr', 'pdf'));
    }

    public function exportResumoExcel(Request $request, Tenant $tenant): StreamedResponse
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->resumoHeaders();
        $rows = $this->resumoRows($tenant, $boletim);
        $spreadsheet = $this->buildPleitoPreliminarSpreadsheet($boletim, $headers, $rows, 'Resumo', $this->reportTotals($headers, $rows));

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $this->exportFileName($boletim, 'resumo', 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportResumoPdf(Request $request, Tenant $tenant)
    {
        $boletim = $this->resolveBoletimForExport($request, $tenant);
        $headers = $this->resumoHeaders();
        $rows = $this->resumoRows($tenant, $boletim);

        $pdf = Pdf::loadView('pdf.medicao-resumo', [
            'title' => 'Resumo',
            'boletim' => $this->serializeBoletim($boletim),
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $this->reportTotals($headers, $rows),
        ])->setPaper('a3', 'landscape');

        return $pdf->download($this->exportFileName($boletim, 'resumo', 'pdf'));
    }

    /**
     * @return array<int, array{key: string, label: string, numeric?: bool, money?: bool}>
     */
    private function pleitoPreliminarHeaders(): array
    {
        return [
            ['key' => 'item', 'label' => 'Item'],
            ['key' => 'codigo_item', 'label' => 'C?digo item'],
            ['key' => 'descricao', 'label' => "Descri\u{00E7}\u{00E3}o"],
            ['key' => 'unidade', 'label' => 'Unidade'],
            ['key' => 'quantidade_pleiteada', 'label' => 'Qtd. pleiteada', 'numeric' => true],
            ['key' => 'preco_unitario_p0', 'label' => 'Pre?o unit?rio P0', 'numeric' => true, 'money' => true],
            ['key' => 'preco_unitario_reajustado', 'label' => 'Pre?o unit?rio reaj.', 'numeric' => true, 'money' => true],
            ['key' => 'total_p0', 'label' => 'Total P0', 'numeric' => true, 'money' => true],
            ['key' => 'total_reajustado', 'label' => 'Total reaj.', 'numeric' => true, 'money' => true],
            ['key' => 'referencia', 'label' => 'Refer\u{00EA}ncia'],
            ['key' => 'obra', 'label' => 'Obra'],
            ['key' => 'frs', 'label' => 'FR(s)'],
        ];
    }

    private function analisePleitoHeaders(): array
    {
        return [
            ['key' => 'item', 'label' => 'Item'],
            ['key' => 'codigo_item', 'label' => 'C?digo item'],
            ['key' => 'descricao', 'label' => "Descri\u{00E7}\u{00E3}o"],
            ['key' => 'unidade', 'label' => 'Unidade'],
            ['key' => 'quantidade_pleiteada', 'label' => 'Qtd. pleiteada', 'numeric' => true],
            ['key' => 'quantidade_aprovada_medicao', 'label' => 'Qtd. aprovada medi??o', 'numeric' => true],
            ['key' => 'preco_unitario_p0', 'label' => 'Pre?o unit?rio P0', 'numeric' => true, 'money' => true],
            ['key' => 'preco_unitario_reajustado', 'label' => 'Pre?o unit?rio reaj.', 'numeric' => true, 'money' => true],
            ['key' => 'total_p0', 'label' => 'Total P0', 'numeric' => true, 'money' => true],
            ['key' => 'total_pleiteado_reajustado', 'label' => 'Total pleiteado reaj.', 'numeric' => true, 'money' => true],
            ['key' => 'total_aprovado_medicao', 'label' => 'Total aprovado medi??o', 'numeric' => true, 'money' => true],
            ['key' => 'diferenca_valor', 'label' => 'Diferen?a de Valor', 'numeric' => true, 'money' => true],
            ['key' => 'comentarios_medicao', 'label' => 'Coment?rios medi??o'],
            ['key' => 'referencia', 'label' => 'Refer\u{00EA}ncia'],
            ['key' => 'obra', 'label' => 'Obra'],
            ['key' => 'frs', 'label' => 'FR(s)'],
        ];
    }

    private function sinteticoHeaders(): array
    {
        return [
            ['key' => 'item', 'label' => 'Item'],
            ['key' => 'descricao', 'label' => "Descri\u{00E7}\u{00E3}o"],
            ['key' => 'unidade', 'label' => 'Unidade'],
            ['key' => 'quantidade_total', 'label' => 'Quantidade total', 'numeric' => true],
            ['key' => 'preco_unitario_p0', 'label' => 'Pre?o unit?rio P0', 'numeric' => true, 'money' => true],
            ['key' => 'preco_total_p0', 'label' => 'Pre?o total P0', 'numeric' => true, 'money' => true],
            ['key' => 'qtd_acumulado_anterior', 'label' => 'Qtd. acumulado anterior', 'numeric' => true],
            ['key' => 'qtd_no_periodo', 'label' => 'Qtd. no per?odo', 'numeric' => true],
            ['key' => 'qtd_acumulado_atual', 'label' => 'Qtd. acumulado atual', 'numeric' => true],
            ['key' => 'valor_acumulado_anterior', 'label' => 'Valor acumulado anterior', 'numeric' => true, 'money' => true],
            ['key' => 'valor_no_periodo', 'label' => 'Valor no per?odo', 'numeric' => true, 'money' => true],
            ['key' => 'valor_acumulado_atual', 'label' => 'Valor acumulado atual', 'numeric' => true, 'money' => true],
            ['key' => 'saldo', 'label' => 'Saldo', 'numeric' => true],
            ['key' => 'executado_acumulado_percentual', 'label' => 'Executado acumulado (%)', 'numeric' => true, 'percent' => true],
            ['key' => 'setor_reajuste', 'label' => 'Setor'],
            ['key' => 'indice_reajuste', 'label' => '?ndice', 'numeric' => true, 'percent' => true],
            ['key' => 'valor_reajuste_periodo', 'label' => 'Valor reajuste no per?odo', 'numeric' => true, 'money' => true],
        ];
    }

    private function porFrHeaders(): array
    {
        return $this->sinteticoHeaders();
    }

    private function resumoHeaders(): array
    {
        return [
            ['key' => 'descricao', 'label' => "Descri\u{00E7}\u{00E3}o"],
            ['key' => 'moeda_p0', 'label' => 'Moeda (R$) P0', 'numeric' => true, 'money' => true],
            ['key' => 'percentual_do_item', 'label' => 'Percentual do item (%)', 'numeric' => true, 'percent' => true],
            ['key' => 'acumulado_anterior_p0', 'label' => 'Acumulado anterior (R$) P0', 'numeric' => true, 'money' => true],
            ['key' => 'no_periodo_p0', 'label' => "No per\u{00ED}odo (R$) P0", 'numeric' => true, 'money' => true],
            ['key' => 'acumulado_atual_p0', 'label' => 'Acumulado atual (R$) P0', 'numeric' => true, 'money' => true],
            ['key' => 'acumulado_atual_percentual', 'label' => 'Acumulado atual (%)', 'numeric' => true, 'percent' => true],
            ['key' => 'valor_reajuste_periodo', 'label' => 'Valor do reajuste', 'numeric' => true, 'money' => true],
            ['key' => 'saldo_p0', 'label' => 'Saldo (R$) P0', 'numeric' => true, 'money' => true],
        ];
    }

    private function reportData(Tenant $tenant, ?BoletimMedicao $boletim, string $selectedReport): array
    {
        $headers = $this->headersForReport($selectedReport);

        if (! $boletim) {
            return [
                'title' => $this->titleForReport($selectedReport),
                'description' => $this->descriptionForReport($selectedReport),
                'headers' => $headers,
                'rows' => [],
                'totals' => [],
            ];
        }

        $rows = $this->rowsForReport($tenant, $boletim, $selectedReport);

        return [
            'title' => $this->titleForReport($selectedReport),
            'description' => $this->descriptionForReport($selectedReport),
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $this->reportTotals($headers, $rows),
        ];
    }

    private function headersForReport(string $report): array
    {
        return match ($report) {
            'analise_pleito' => $this->analisePleitoHeaders(),
            'sintetico' => $this->sinteticoHeaders(),
            'por_fr' => $this->porFrHeaders(),
            'resumo' => $this->resumoHeaders(),
            default => $this->pleitoPreliminarHeaders(),
        };
    }

    private function rowsForReport(Tenant $tenant, BoletimMedicao $boletim, string $report): array
    {
        return match ($report) {
            'analise_pleito' => $this->analisePleitoRows($tenant, $boletim),
            'sintetico' => $this->sinteticoRows($tenant, $boletim),
            'por_fr' => $this->porFrRows($tenant, $boletim),
            'resumo' => $this->resumoRows($tenant, $boletim),
            default => $this->pleitoPreliminarRows($tenant, $boletim),
        };
    }

    private function titleForReport(string $report): string
    {
        return match ($report) {
            'analise_pleito' => 'An?lise do Pleito',
            'sintetico' => 'Sint?tico',
            'por_fr' => 'Por FR',
            'resumo' => 'Resumo',
            default => 'Pleito preliminar',
        };
    }

    private function descriptionForReport(string $report): string
    {
        return match ($report) {
            'analise_pleito' => 'Consolida somente FRs finalizadas e compara o pleito com os quantitativos aprovados pela medi??o.',
            'sintetico' => 'Apresenta acumulados anteriores, execu??o no per?odo, acumulado atual, saldo e reajustamento somente de FRs finalizadas.',
            'por_fr' => 'Detalha os itens medidos no BM selecionado, separados pela Folha de Rosto de origem.',
            'resumo' => "Resume o valor P0 geral do contrato e a evolu\u{00E7}\u{00E3}o por planilha no BM selecionado.",
            default => 'Consolida os quantitativos pleiteados pela construtora no BM selecionado.',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pleitoPreliminarRows(Tenant $tenant, BoletimMedicao $boletim): array
    {
        $folhas = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->where('boletim_medicao_id', $boletim->id)
            ->where('status', 'analisada')
            ->with([
                'obra:id,codigo,nome',
                'itens.ordemServicoItem.medicaoItem.versions',
                'itens.ordemServicoItem.medicaoItem.reajusteIndice.indice.competencias',
            ])
            ->orderBy('codigo')
            ->get();

        return $folhas
            ->flatMap(fn (FolhaRosto $folha): Collection => $folha->itens->map(fn (FolhaRostoItem $item): array => [
                'folha' => $folha,
                'item' => $item,
            ]))
            ->groupBy(function (array $row): string {
                /** @var FolhaRosto $folha */
                $folha = $row['folha'];
                /** @var FolhaRostoItem $item */
                $item = $row['item'];

                return implode('|', [
                    $folha->obra_id ?: 'sem-obra',
                    $item->ordem_servico_item_id ?: 'sem-item',
                ]);
            })
            ->map(function (Collection $rows) use ($boletim): array {
                $first = $rows->first();
                /** @var FolhaRosto $folha */
                $folha = $first['folha'];
                /** @var FolhaRostoItem $item */
                $item = $first['item'];
                $ordemItem = $item->ordemServicoItem;
                $medicaoItem = $ordemItem?->medicaoItem;
                $quantity = (float) $rows->sum(fn (array $row): float => (float) $row['item']->quantidade_pleiteada);
                $valoresItem = $this->effectiveMedicaoItemValues($medicaoItem, $boletim->periodo, $ordemItem);
                $precoUnitarioP0 = $valoresItem['preco_unitario_p0'];
                    $precoUnitarioReajustado = $this->adjustedValue($precoUnitarioP0, $medicaoItem, $boletim->periodo);
                $frCodes = $rows
                    ->map(fn (array $row): string => $row['folha']->codigo)
                    ->unique()
                    ->sort()
                    ->values();

                return [
                    'item' => $medicaoItem?->item,
                    'codigo_item' => $medicaoItem?->codigo,
                    'descricao' => $medicaoItem?->descricao,
                    'unidade' => $medicaoItem?->unidade,
                    'quantidade_pleiteada' => $quantity,
                    'preco_unitario_p0' => round($precoUnitarioP0, 6),
                    'preco_unitario_reajustado' => round($precoUnitarioReajustado, 6),
                    'total_p0' => round($quantity * $precoUnitarioP0, 2),
                    'total_reajustado' => round($quantity * $precoUnitarioReajustado, 2),
                    'referencia' => $boletim->periodo?->format('m/y'),
                    'obra' => trim(($folha->obra?->codigo ?? '').' - '.($folha->obra?->nome ?? ''), ' -'),
                    'frs' => $frCodes->implode(', '),
                ];
            })
            ->sortBy([
                ['item', 'asc'],
                ['obra', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function analisePleitoRows(Tenant $tenant, BoletimMedicao $boletim): array
    {
        $folhas = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->where('boletim_medicao_id', $boletim->id)
            ->with([
                'obra:id,codigo,nome',
                'itens.analises' => fn ($query) => $query->where('setor', 'medicao'),
                'itens.ordemServicoItem.medicaoItem.versions',
                'itens.ordemServicoItem.medicaoItem.reajusteIndice.indice.competencias',
            ])
            ->orderBy('codigo')
            ->get();

        return $folhas
            ->flatMap(fn (FolhaRosto $folha): Collection => $folha->itens->map(fn (FolhaRostoItem $item): array => [
                'folha' => $folha,
                'item' => $item,
            ]))
            ->groupBy(function (array $row): string {
                /** @var FolhaRosto $folha */
                $folha = $row['folha'];
                /** @var FolhaRostoItem $item */
                $item = $row['item'];

                return implode('|', [
                    $folha->obra_id ?: 'sem-obra',
                    $item->ordem_servico_item_id ?: 'sem-item',
                ]);
            })
            ->map(function (Collection $rows) use ($boletim): array {
                $first = $rows->first();
                /** @var FolhaRosto $folha */
                $folha = $first['folha'];
                /** @var FolhaRostoItem $item */
                $item = $first['item'];
                $ordemItem = $item->ordemServicoItem;
                $medicaoItem = $ordemItem?->medicaoItem;
                $quantity = (float) $rows->sum(fn (array $row): float => (float) $row['item']->quantidade_pleiteada);
                $approvedQuantity = (float) $rows->sum(function (array $row): float {
                    /** @var FolhaRostoItem $item */
                    $item = $row['item'];

                    return (float) ($item->analises->first()?->quantidade_aprovada ?? 0);
                });
                $valoresItem = $this->effectiveMedicaoItemValues($medicaoItem, $boletim->periodo, $ordemItem);
                $precoUnitarioP0 = $valoresItem['preco_unitario_p0'];
                $precoUnitarioReajustado = $this->adjustedValue($precoUnitarioP0, $medicaoItem, $boletim->periodo);
                $totalP0 = round($quantity * $precoUnitarioP0, 2);
                $totalPleiteado = round($quantity * $precoUnitarioReajustado, 2);
                $totalAprovado = round($approvedQuantity * $precoUnitarioReajustado, 2);
                $frCodes = $rows
                    ->map(fn (array $row): string => $row['folha']->codigo)
                    ->unique()
                    ->sort()
                    ->values();
                $comments = $rows
                    ->map(function (array $row): ?string {
                        /** @var FolhaRosto $folha */
                        $folha = $row['folha'];
                        /** @var FolhaRostoItem $item */
                        $item = $row['item'];
                        $comment = trim((string) ($item->analises->first()?->comentario ?? ''));

                        return $comment !== '' ? $comment : null;
                    })
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'item' => $medicaoItem?->item,
                    'codigo_item' => $medicaoItem?->codigo,
                    'descricao' => $medicaoItem?->descricao,
                    'unidade' => $medicaoItem?->unidade,
                    'quantidade_pleiteada' => $quantity,
                    'quantidade_aprovada_medicao' => $approvedQuantity,
                    'preco_unitario_p0' => round($precoUnitarioP0, 6),
                    'preco_unitario_reajustado' => round($precoUnitarioReajustado, 6),
                    'total_p0' => $totalP0,
                    'total_pleiteado_reajustado' => $totalPleiteado,
                    'total_aprovado_medicao' => $totalAprovado,
                    'diferenca_valor' => round($totalAprovado - $totalPleiteado, 2),
                    'comentarios_medicao' => $comments->implode(' | '),
                    'referencia' => $boletim->periodo?->format('m/y'),
                    'obra' => trim(($folha->obra?->codigo ?? '').' - '.($folha->obra?->nome ?? ''), ' -'),
                    'frs' => $frCodes->implode(', '),
                ];
            })
            ->sortBy([
                ['item', 'asc'],
                ['obra', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function sinteticoRows(Tenant $tenant, BoletimMedicao $boletim): array
    {
        $boletimIdsAteAtual = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $boletim->contract_id)
            ->where(function ($query) use ($boletim): void {
                $query
                    ->where('periodo', '<', $boletim->periodo)
                    ->orWhere(function ($query) use ($boletim): void {
                        $query
                            ->where('periodo', $boletim->periodo)
                            ->where('id', '<=', $boletim->id);
                    });
            })
            ->pluck('id')
            ->all();

        $folhas = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('boletim_medicao_id', $boletimIdsAteAtual)
            ->where('status', 'analisada')
            ->with([
                'boletimMedicao:id,periodo',
                'itens.analises' => fn ($query) => $query->where('setor', 'medicao'),
                'itens.ordemServicoItem.medicaoItem.versions',
                'itens.ordemServicoItem.medicaoItem.reajusteIndice.indice.competencias',
            ])
            ->get();

        return $folhas
            ->flatMap(fn (FolhaRosto $folha): Collection => $folha->itens->map(fn (FolhaRostoItem $item): array => [
                'folha' => $folha,
                'item' => $item,
            ]))
            ->filter(fn (array $row): bool => $row['item']->analises->first()?->quantidade_aprovada !== null)
            ->groupBy(fn (array $row): string => (string) ($row['item']->ordem_servico_item_id ?: 'sem-item'))
            ->map(function (Collection $rows) use ($boletim): array {
                $first = $rows->first();
                /** @var FolhaRosto $folha */
                $folha = $first['folha'];
                /** @var FolhaRostoItem $item */
                $item = $first['item'];
                $ordemItem = $item->ordemServicoItem;
                $medicaoItem = $ordemItem?->medicaoItem;
                $valoresItem = $this->effectiveMedicaoItemValues($medicaoItem, $boletim->periodo, $ordemItem);
                $quantidadeTotal = $valoresItem['quantidade_total'];
                $precoUnitarioP0 = $valoresItem['preco_unitario_p0'];
                $precoUnitarioReajustadoPeriodo = $this->adjustedValue($precoUnitarioP0, $medicaoItem, $boletim->periodo);
                $indicePercentual = $this->adjustmentPercentage($medicaoItem, $boletim->periodo);

                $qtdAnterior = (float) $rows->sum(function (array $row) use ($boletim): float {
                    /** @var FolhaRosto $folha */
                    $folha = $row['folha'];
                    /** @var FolhaRostoItem $item */
                    $item = $row['item'];

                    if ($folha->boletim_medicao_id === $boletim->id) {
                        return 0;
                    }

                    return (float) ($item->analises->first()?->quantidade_aprovada ?? 0);
                });
                $qtdPeriodo = (float) $rows->sum(function (array $row) use ($boletim): float {
                    /** @var FolhaRosto $folha */
                    $folha = $row['folha'];
                    /** @var FolhaRostoItem $item */
                    $item = $row['item'];

                    if ($folha->boletim_medicao_id !== $boletim->id) {
                        return 0;
                    }

                    return (float) ($item->analises->first()?->quantidade_aprovada ?? 0);
                });
                $qtdAtual = $qtdAnterior + $qtdPeriodo;
                $saldo = max(0, $quantidadeTotal - $qtdAtual);

                $valorAcumuladoAnterior = (float) $rows->sum(function (array $row) use ($boletim, $precoUnitarioP0, $medicaoItem): float {
                    /** @var FolhaRosto $folha */
                    $folha = $row['folha'];
                    /** @var FolhaRostoItem $item */
                    $item = $row['item'];

                    if ($folha->boletim_medicao_id === $boletim->id) {
                        return 0;
                    }

                    $precoReajustado = $this->adjustedValue($precoUnitarioP0, $medicaoItem, $folha->boletimMedicao?->periodo);

                    return (float) ($item->analises->first()?->quantidade_aprovada ?? 0) * $precoReajustado;
                });
                $valorNoPeriodo = $qtdPeriodo * $precoUnitarioReajustadoPeriodo;

                return [
                    'item' => $medicaoItem?->item,
                    'descricao' => $medicaoItem?->descricao,
                    'unidade' => $medicaoItem?->unidade,
                    'quantidade_total' => round($quantidadeTotal, 4),
                    'preco_unitario_p0' => round($precoUnitarioP0, 6),
                    'preco_total_p0' => round($quantidadeTotal * $precoUnitarioP0, 2),
                    'qtd_acumulado_anterior' => round($qtdAnterior, 4),
                    'qtd_no_periodo' => round($qtdPeriodo, 4),
                    'qtd_acumulado_atual' => round($qtdAtual, 4),
                    'valor_acumulado_anterior' => round($valorAcumuladoAnterior, 2),
                    'valor_no_periodo' => round($valorNoPeriodo, 2),
                    'valor_acumulado_atual' => round($valorAcumuladoAnterior + $valorNoPeriodo, 2),
                    'saldo' => round($saldo, 4),
                    'executado_acumulado_percentual' => $quantidadeTotal > 0 ? round(($qtdAtual / $quantidadeTotal) * 100, 2) : 0,
                    'setor_reajuste' => $medicaoItem?->reajusteIndice?->indice?->codigo,
                    'indice_reajuste' => round($indicePercentual, 2),
                    'valor_reajuste_periodo' => round($qtdPeriodo * max(0, $precoUnitarioReajustadoPeriodo - $precoUnitarioP0), 2),
                ];
            })
            ->sortBy('item')
            ->values()
            ->all();
    }

    private function porFrRows(Tenant $tenant, BoletimMedicao $boletim): array
    {
        $boletimIdsAnteriores = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $boletim->contract_id)
            ->where(function ($query) use ($boletim): void {
                $query
                    ->where('periodo', '<', $boletim->periodo)
                    ->orWhere(function ($query) use ($boletim): void {
                        $query
                            ->where('periodo', $boletim->periodo)
                            ->where('id', '<', $boletim->id);
                    });
            })
            ->pluck('id')
            ->all();

        $folhasAnteriores = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('boletim_medicao_id', $boletimIdsAnteriores)
            ->where('status', 'analisada')
            ->with([
                'boletimMedicao:id,periodo',
                'itens.analises' => fn ($query) => $query->where('setor', 'medicao'),
                'itens.ordemServicoItem.medicaoItem.versions',
                'itens.ordemServicoItem.medicaoItem.reajusteIndice.indice.competencias',
            ])
            ->get();

        $qtdAnteriorPorItem = $folhasAnteriores
            ->flatMap(fn (FolhaRosto $folha): Collection => $folha->itens->map(fn (FolhaRostoItem $item): FolhaRostoItem => $item))
            ->filter(fn (FolhaRostoItem $item): bool => $item->analises->first()?->quantidade_aprovada !== null)
            ->groupBy(fn (FolhaRostoItem $item): string => (string) ($item->ordem_servico_item_id ?: 'sem-item'))
            ->map(fn (Collection $itens): float => (float) $itens->sum(fn (FolhaRostoItem $item): float => (float) ($item->analises->first()?->quantidade_aprovada ?? 0)));
        $valorAnteriorPorItem = $folhasAnteriores
            ->flatMap(fn (FolhaRosto $folha): Collection => $folha->itens->map(fn (FolhaRostoItem $item): array => [
                'folha' => $folha,
                'item' => $item,
            ]))
            ->filter(fn (array $row): bool => $row['item']->analises->first()?->quantidade_aprovada !== null)
            ->groupBy(fn (array $row): string => (string) ($row['item']->ordem_servico_item_id ?: 'sem-item'))
            ->map(function (Collection $rows): float {
                return (float) $rows->sum(function (array $row): float {
                    /** @var FolhaRosto $folha */
                    $folha = $row['folha'];
                    /** @var FolhaRostoItem $item */
                    $item = $row['item'];
                    $ordemItem = $item->ordemServicoItem;
                    $medicaoItem = $ordemItem?->medicaoItem;
                    $valoresItem = $this->effectiveMedicaoItemValues($medicaoItem, $folha->boletimMedicao?->periodo, $ordemItem);
                    $precoUnitarioP0 = $valoresItem['preco_unitario_p0'];
                    $precoReajustado = $this->adjustedValue($precoUnitarioP0, $medicaoItem, $folha->boletimMedicao?->periodo);

                    return (float) ($item->analises->first()?->quantidade_aprovada ?? 0) * $precoReajustado;
                });
            });

        $folhas = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->where('boletim_medicao_id', $boletim->id)
            ->where('status', 'analisada')
            ->with([
                'boletimMedicao:id,periodo',
                'itens.analises' => fn ($query) => $query->where('setor', 'medicao'),
                'itens.ordemServicoItem.medicaoItem.versions',
                'itens.ordemServicoItem.medicaoItem.reajusteIndice.indice.competencias',
            ])
            ->orderBy('codigo')
            ->get();

        $runningPeriodoPorItem = [];

        return $folhas
            ->flatMap(fn (FolhaRosto $folha): Collection => $folha->itens->map(fn (FolhaRostoItem $item): array => [
                'folha' => $folha,
                'item' => $item,
            ]))
            ->filter(fn (array $row): bool => $row['item']->analises->first()?->quantidade_aprovada !== null)
            ->groupBy(function (array $row): string {
                /** @var FolhaRosto $folha */
                $folha = $row['folha'];
                /** @var FolhaRostoItem $item */
                $item = $row['item'];

                return implode('|', [
                    $folha->id,
                    $item->ordem_servico_item_id ?: 'sem-item',
                ]);
            })
            ->sortKeys()
            ->map(function (Collection $rows) use (&$runningPeriodoPorItem, $qtdAnteriorPorItem, $valorAnteriorPorItem): array {
                $first = $rows->first();
                /** @var FolhaRosto $folha */
                $folha = $first['folha'];
                /** @var FolhaRostoItem $item */
                $item = $first['item'];
                $ordemItem = $item->ordemServicoItem;
                $medicaoItem = $ordemItem?->medicaoItem;
                $itemKey = (string) ($item->ordem_servico_item_id ?: 'sem-item');
                $valoresItem = $this->effectiveMedicaoItemValues($medicaoItem, $folha->boletimMedicao?->periodo, $ordemItem);
                $quantidadeTotal = $valoresItem['quantidade_total'];
                $precoUnitarioP0 = $valoresItem['preco_unitario_p0'];
                $precoUnitarioReajustadoPeriodo = $this->adjustedValue($precoUnitarioP0, $medicaoItem, $folha->boletimMedicao?->periodo);
                $indicePercentual = $this->adjustmentPercentage($medicaoItem, $folha->boletimMedicao?->periodo);
                $qtdAnterior = (float) ($qtdAnteriorPorItem->get($itemKey, 0) ?? 0);
                $qtdPeriodo = (float) $rows->sum(function (array $row): float {
                    /** @var FolhaRostoItem $item */
                    $item = $row['item'];

                    return (float) ($item->analises->first()?->quantidade_aprovada ?? 0);
                });

                $runningPeriodoPorItem[$itemKey] = (float) (($runningPeriodoPorItem[$itemKey] ?? 0) + $qtdPeriodo);
                $qtdAtual = $qtdAnterior + $runningPeriodoPorItem[$itemKey];
                $saldo = max(0, $quantidadeTotal - $qtdAtual);

                $valorAcumuladoAnterior = (float) ($valorAnteriorPorItem->get($itemKey, 0) ?? 0);
                $valorNoPeriodo = $qtdPeriodo * $precoUnitarioReajustadoPeriodo;

                return [
                    'fr' => $folha->codigo,
                    'fr_comentario' => $folha->comentario,
                    'item' => $medicaoItem?->item,
                    'descricao' => $medicaoItem?->descricao,
                    'unidade' => $medicaoItem?->unidade,
                    'quantidade_total' => round($quantidadeTotal, 4),
                    'preco_unitario_p0' => round($precoUnitarioP0, 6),
                    'preco_total_p0' => round($quantidadeTotal * $precoUnitarioP0, 2),
                    'qtd_acumulado_anterior' => round($qtdAnterior, 4),
                    'qtd_no_periodo' => round($qtdPeriodo, 4),
                    'qtd_acumulado_atual' => round($qtdAtual, 4),
                    'valor_acumulado_anterior' => round($valorAcumuladoAnterior, 2),
                    'valor_no_periodo' => round($valorNoPeriodo, 2),
                    'valor_acumulado_atual' => round($valorAcumuladoAnterior + $valorNoPeriodo, 2),
                    'saldo' => round($saldo, 4),
                    'executado_acumulado_percentual' => $quantidadeTotal > 0 ? round(($qtdAtual / $quantidadeTotal) * 100, 2) : 0,
                    'setor_reajuste' => $medicaoItem?->reajusteIndice?->indice?->codigo,
                    'indice_reajuste' => round($indicePercentual, 2),
                    'valor_reajuste_periodo' => round($qtdPeriodo * max(0, $precoUnitarioReajustadoPeriodo - $precoUnitarioP0), 2),
                ];
            })
            ->sortBy([
                ['fr', 'asc'],
                ['item', 'asc'],
            ])
            ->groupBy('fr')
            ->flatMap(function (Collection $rows, string $fr): Collection {
                $first = $rows->first();
                $comentario = trim((string) ($first['fr_comentario'] ?? ''));
                $totalRow = [
                    '_is_fr_total' => true,
                    'item' => '',
                    'descricao' => 'Total da FR',
                    'unidade' => '',
                    'quantidade_total' => null,
                    'preco_unitario_p0' => null,
                    'preco_total_p0' => null,
                    'qtd_acumulado_anterior' => null,
                    'qtd_no_periodo' => null,
                    'qtd_acumulado_atual' => null,
                    'valor_acumulado_anterior' => null,
                    'valor_no_periodo' => round((float) $rows->sum('valor_no_periodo'), 2),
                    'valor_acumulado_atual' => null,
                    'saldo' => null,
                    'executado_acumulado_percentual' => null,
                    'setor_reajuste' => '',
                    'indice_reajuste' => null,
                    'valor_reajuste_periodo' => round((float) $rows->sum('valor_reajuste_periodo'), 2),
                ];

                return collect([
                    [
                        '_is_group' => true,
                        'group_title' => $comentario !== '' ? "{$fr} - {$comentario}" : $fr,
                    ],
                ])->merge($rows)->push($totalRow);
            })
            ->values()
            ->all();
    }

    private function resumoRows(Tenant $tenant, BoletimMedicao $boletim): array
    {
        $itensContrato = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $boletim->contract_id)
            ->where('item_type', '!=', 'etapa')
            ->get(['id', 'contract_id', 'item', 'item_type', 'descricao', 'valor_total']);

        $etapasByPlanilha = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $boletim->contract_id)
            ->where('item_type', 'etapa')
            ->get(['id', 'item', 'descricao'])
            ->groupBy(fn (MedicaoItem $item): string => $this->planilhaKeyFromItem($item->item))
            ->map(fn (Collection $items): string => (string) ($items->sortBy('item')->first()?->descricao ?? ''));

        $planilhas = $itensContrato
            ->groupBy(fn (MedicaoItem $item): string => $this->planilhaKeyFromItem($item->item))
            ->map(function (Collection $items, string $planilha) use ($etapasByPlanilha): array {
                return [
                    'planilha' => $planilha,
                    'descricao_base' => $etapasByPlanilha->get($planilha) ?: $this->planilhaDescricao($planilha),
                    'moeda_p0' => round((float) $items->sum(fn (MedicaoItem $item): float => (float) $item->valor_total), 2),
                ];
            });

        $totalGeralP0 = (float) $planilhas->sum('moeda_p0');

        $boletimIdsAteAtual = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $boletim->contract_id)
            ->where(function ($query) use ($boletim): void {
                $query
                    ->where('periodo', '<', $boletim->periodo)
                    ->orWhere(function ($query) use ($boletim): void {
                        $query
                            ->where('periodo', $boletim->periodo)
                            ->where('id', '<=', $boletim->id);
                    });
            })
            ->pluck('id')
            ->all();

        $folhas = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('boletim_medicao_id', $boletimIdsAteAtual)
            ->where('status', 'analisada')
            ->with([
                'itens.analises' => fn ($query) => $query->where('setor', 'medicao'),
                'itens.ordemServicoItem.medicaoItem.versions',
                'itens.ordemServicoItem.medicaoItem.reajusteIndice.indice.competencias',
            ])
            ->get();

        $medicoes = $folhas
            ->flatMap(fn (FolhaRosto $folha): Collection => $folha->itens->map(fn (FolhaRostoItem $item): array => [
                'folha' => $folha,
                'item' => $item,
            ]))
            ->filter(fn (array $row): bool => $row['item']->analises->first()?->quantidade_aprovada !== null)
            ->groupBy(function (array $row): string {
                /** @var FolhaRostoItem $item */
                $item = $row['item'];

                return $this->planilhaKeyFromItem($item->ordemServicoItem?->medicaoItem?->item);
            })
            ->map(function (Collection $rows) use ($boletim): array {
                $anterior = 0.0;
                $periodo = 0.0;
                $reajustePeriodo = 0.0;

                foreach ($rows as $row) {
                    /** @var FolhaRosto $folha */
                    $folha = $row['folha'];
                    /** @var FolhaRostoItem $item */
                    $item = $row['item'];
                    $ordemItem = $item->ordemServicoItem;
                    $medicaoItem = $ordemItem?->medicaoItem;
                    $valoresItem = $this->effectiveMedicaoItemValues($medicaoItem, $folha->boletimMedicao?->periodo ?? $boletim->periodo, $ordemItem);
                    $precoUnitarioP0 = $valoresItem['preco_unitario_p0'];
                    $precoUnitarioReajustado = $this->adjustedValue($precoUnitarioP0, $medicaoItem, $boletim->periodo);
                    $quantidadeAprovada = (float) ($item->analises->first()?->quantidade_aprovada ?? 0);
                    $valor = $quantidadeAprovada * $precoUnitarioP0;

                    if ((int) $folha->boletim_medicao_id === (int) $boletim->id) {
                        $periodo += $valor;
                        $reajustePeriodo += $quantidadeAprovada * max(0, $precoUnitarioReajustado - $precoUnitarioP0);
                    } else {
                        $anterior += $valor;
                    }
                }

                return [
                    'acumulado_anterior_p0' => round($anterior, 2),
                    'no_periodo_p0' => round($periodo, 2),
                    'valor_reajuste_periodo' => round($reajustePeriodo, 2),
                ];
            });

        $rows = $planilhas
            ->map(function (array $planilha) use ($medicoes, $totalGeralP0): array {
                $medicao = $medicoes->get($planilha['planilha'], []);
                $anterior = (float) ($medicao['acumulado_anterior_p0'] ?? 0);
                $periodo = (float) ($medicao['no_periodo_p0'] ?? 0);
                $reajustePeriodo = (float) ($medicao['valor_reajuste_periodo'] ?? 0);
                $atual = $anterior + $periodo;
                $totalPlanilha = (float) $planilha['moeda_p0'];

                return [
                    '_planilha' => $planilha['planilha'],
                    'descricao' => "Planilha N.{$planilha['planilha']}: {$planilha['descricao_base']}",
                    'moeda_p0' => round($totalPlanilha, 2),
                    'percentual_do_item' => $totalGeralP0 > 0 ? round(($totalPlanilha / $totalGeralP0) * 100, 2) : 0,
                    'acumulado_anterior_p0' => round($anterior, 2),
                    'no_periodo_p0' => round($periodo, 2),
                    'acumulado_atual_p0' => round($atual, 2),
                    'acumulado_atual_percentual' => $totalPlanilha > 0 ? round(($atual / $totalPlanilha) * 100, 2) : 0,
                    'valor_reajuste_periodo' => round($reajustePeriodo, 2),
                    'saldo_p0' => round(max(0, $totalPlanilha - $atual), 2),
                ];
            })
            ->sortBy(fn (array $row): string => $this->planilhaSortKey((string) $row['_planilha']))
            ->values();

        $subtotal = [
            '_is_summary' => true,
            '_planilha' => null,
            'descricao' => '(A) Total Geral:',
            'moeda_p0' => round((float) $rows->sum('moeda_p0'), 2),
            'percentual_do_item' => 100,
            'acumulado_anterior_p0' => round((float) $rows->sum('acumulado_anterior_p0'), 2),
            'no_periodo_p0' => round((float) $rows->sum('no_periodo_p0'), 2),
            'acumulado_atual_p0' => round((float) $rows->sum('acumulado_atual_p0'), 2),
            'acumulado_atual_percentual' => $totalGeralP0 > 0 ? round(((float) $rows->sum('acumulado_atual_p0') / $totalGeralP0) * 100, 2) : 0,
            'valor_reajuste_periodo' => round((float) $rows->sum('valor_reajuste_periodo'), 2),
            'saldo_p0' => round((float) $rows->sum('saldo_p0'), 2),
        ];

        return $rows
            ->prepend($subtotal)
            ->values()
            ->all();
    }

    /**
     * @return array{quantidade_total: float, preco_unitario_p0: float, valor_total_p0: float}
     */
    private function effectiveMedicaoItemValues(?MedicaoItem $item, mixed $competencia = null, mixed $ordemItem = null): array
    {
        if (! $item) {
            $quantidade = (float) ($ordemItem?->quantidade_solicitada ?? 0);
            $valorTotal = (float) ($ordemItem?->valor_previsto ?? 0);

            return [
                'quantidade_total' => $quantidade,
                'preco_unitario_p0' => $quantidade > 0 ? $valorTotal / $quantidade : 0.0,
                'valor_total_p0' => $valorTotal,
            ];
        }

        $competenciaReferencia = $competencia ? \Illuminate\Support\Carbon::parse($competencia)->endOfMonth() : null;
        $versions = $item->relationLoaded('versions') ? $item->versions : $item->versions()->get();
        $version = $competenciaReferencia
            ? $versions
                ->filter(fn ($version): bool => ! $version->starts_at || $version->starts_at->lte($competenciaReferencia))
                ->sortByDesc('version_number')
                ->first()
            : $versions->sortByDesc('version_number')->first();

        $quantidade = (float) ($version?->quantidade_prevista ?? $item->quantidade_prevista ?? $ordemItem?->quantidade_solicitada ?? 0);
        $precoUnitario = (float) ($version?->valor_com_bdi ?? $item->valor_com_bdi ?? 0);
        $valorTotal = (float) ($version?->valor_total ?? ($quantidade * $precoUnitario));

        return [
            'quantidade_total' => $quantidade,
            'preco_unitario_p0' => $precoUnitario,
            'valor_total_p0' => $valorTotal,
        ];
    }

    private function adjustedValue(float $baseValue, ?MedicaoItem $item, mixed $competencia = null): float
    {
        return round($baseValue * (1 + ($this->adjustmentPercentage($item, $competencia) / 100)), 6);
    }

    private function planilhaKeyFromItem(?string $item): string
    {
        $value = trim((string) $item);

        if ($value === '') {
            return '0';
        }

        return explode('.', $value)[0] ?: '0';
    }

    private function planilhaDescricao(string $planilha): string
    {
        return "Planilha {$planilha}";
    }

    private function planilhaSortKey(string $planilha): string
    {
        return collect(explode('.', $planilha))
            ->map(fn (string $part): string => str_pad((string) ((int) $part), 6, '0', STR_PAD_LEFT))
            ->implode('.');
    }

    private function adjustmentPercentage(?MedicaoItem $item, mixed $competencia = null): float
    {
        $indice = $item?->reajusteIndice?->indice;

        if (! $indice || (float) $indice->indice_base <= 0) {
            return 0.0;
        }

        $competenciaReferencia = $competencia ? \Illuminate\Support\Carbon::parse($competencia)->startOfMonth() : null;
        $latestCompetencia = $indice->competencias
            ->when($competenciaReferencia, fn (Collection $competencias): Collection => $competencias
                ->filter(fn ($competencia): bool => $competencia->competencia && $competencia->competencia->startOfMonth()->lte($competenciaReferencia)))
            ->sortByDesc('competencia')
            ->first();

        $currentIndex = $latestCompetencia
            ? (float) $latestCompetencia->valor_indice
            : ($competenciaReferencia ? (float) $indice->indice_base : (float) $indice->indice_atual);

        return (($currentIndex - (float) $indice->indice_base) / (float) $indice->indice_base) * 100;
    }

    private function resolveBoletimForExport(Request $request, Tenant $tenant): BoletimMedicao
    {
        $boletimId = $request->integer('boletim_id');

        abort_unless($boletimId, 404);

        return BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->with($this->boletimReportRelations())
            ->findOrFail($boletimId);
    }

    /**
     * @param array<int, array{key: string, label: string, numeric?: bool, money?: bool}> $headers
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildPleitoPreliminarSpreadsheet(BoletimMedicao $boletim, array $headers, array $rows, string $title = 'Pleito preliminar', array $totals = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(Str::limit($title, 31, ''));

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', "{$boletim->codigo} | Refer\u{00EA}ncia {$boletim->periodo?->format('m/y')} | {$boletim->tipo}");
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(10);

        foreach ($headers as $columnIndex => $header) {
            $column = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $sheet->setCellValue("{$column}4", $header['label']);
        }

        $rowNumber = 5;
        foreach ($rows as $row) {
            if ($row['_is_group'] ?? false) {
                $sheet->setCellValueExplicit("A{$rowNumber}", (string) ($row['group_title'] ?? ''), DataType::TYPE_STRING);
                $sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setBold(true);
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D1D5DB');
                $rowNumber++;

                continue;
            }

            foreach ($headers as $columnIndex => $header) {
                $column = Coordinate::stringFromColumnIndex($columnIndex + 1);
                $value = $row[$header['key']] ?? null;

                if ($header['numeric'] ?? false) {
                    $sheet->setCellValue("{$column}{$rowNumber}", is_numeric($value) ? (float) $value : null);
                } else {
                    $sheet->setCellValueExplicit("{$column}{$rowNumber}", (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }

            if ($row['_is_summary'] ?? false) {
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setBold(true);
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
            }

            if ($row['_is_fr_total'] ?? false) {
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setBold(true);
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
            }

            $rowNumber++;
        }

        $totalRow = $rowNumber;
        $sheet->setCellValue("A{$totalRow}", 'Total');
        $firstNumericIndex = collect($headers)->search(fn (array $header): bool => (bool) ($header['numeric'] ?? false));
        $nonNumericColumns = $firstNumericIndex === false ? 1 : max(1, (int) $firstNumericIndex);

        if ($nonNumericColumns > 1) {
            $mergeEndColumn = Coordinate::stringFromColumnIndex($nonNumericColumns);
            $sheet->mergeCells("A{$totalRow}:{$mergeEndColumn}{$totalRow}");
        }

        foreach ($headers as $columnIndex => $header) {
            $key = $header['key'];
            if (! array_key_exists($key, $totals)) {
                continue;
            }

            $column = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $sheet->setCellValue("{$column}{$totalRow}", (float) $totals[$key]);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastColumn}".max(4, $rowNumber - 1));

        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '111827']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
        ]);
        $sheet->getStyle("A5:{$lastColumn}{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');

        foreach ($headers as $columnIndex => $header) {
            $column = Coordinate::stringFromColumnIndex($columnIndex + 1);

            if ($header['numeric'] ?? false) {
                $sheet->getStyle("{$column}5:{$column}{$totalRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } else {
                $sheet->getStyle("{$column}5:{$column}{$totalRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle("{$column}5:{$column}{$totalRow}")
                    ->getNumberFormat()
                    ->setFormatCode('@');
            }

            if ($header['money'] ?? false) {
                $sheet->getStyle("{$column}5:{$column}{$totalRow}")
                    ->getNumberFormat()
                    ->setFormatCode('"R$" #,##0.00');
            } elseif ($header['percent'] ?? false) {
                $sheet->getStyle("{$column}5:{$column}{$totalRow}")
                    ->getNumberFormat()
                    ->setFormatCode('0.00"%"');
            } elseif ($header['numeric'] ?? false) {
                $sheet->getStyle("{$column}5:{$column}{$totalRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.0000');
            }

            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function reportTotals(array $headers, array $rows): array
    {
        $totalRows = array_values(array_filter($rows, fn (array $row): bool => ! (bool) ($row['_is_summary'] ?? false) && ! (bool) ($row['_is_group'] ?? false) && ! (bool) ($row['_is_fr_total'] ?? false)));

        return collect($headers)
            ->filter(fn (array $header): bool => (bool) ($header['numeric'] ?? false))
            ->filter(function (array $header): bool {
                $key = (string) $header['key'];

                return str_starts_with($key, 'quantidade_')
                    || str_starts_with($key, 'qtd_')
                    || str_starts_with($key, 'valor_')
                    || str_starts_with($key, 'total_')
                    || str_starts_with($key, 'acumulado_')
                    || str_starts_with($key, 'diferenca_')
                    || str_starts_with($key, 'saldo')
                    || $key === 'moeda_p0'
                    || $key === 'no_periodo_p0'
                    || $key === 'percentual_do_item';
            })
            ->mapWithKeys(fn (array $header): array => [
                $header['key'] => array_sum(array_map(fn (array $row): float => (float) ($row[$header['key']] ?? 0), $totalRows)),
            ])
            ->all();
    }

    private function exportFileName(BoletimMedicao $boletim, string $report, string $extension): string
    {
        return Str::slug("{$report}-{$boletim->codigo}-{$boletim->periodo?->format('m-y')}").".{$extension}";
    }

    private function serializeBoletim(BoletimMedicao $boletim): array
    {
        $contract = $boletim->relationLoaded('contract') ? $boletim->contract : null;

        return [
            'id' => $boletim->id,
            'contract_id' => $boletim->contract_id,
            'codigo' => $boletim->codigo,
            'periodo' => $boletim->periodo?->format('Y-m-d'),
            'periodo_formatado' => $boletim->periodo?->format('m/y'),
            'tipo' => $boletim->tipo,
            'tipo_label' => match ($boletim->tipo) {
                'reequilibrio' => "Reequil\u{00ED}brio",
                'contingencia' => "Conting\u{00EA}ncia",
                default => 'Normal',
            },
            'status' => $boletim->status,
            'status_label' => match ($boletim->status) {
                'aberto_lancamento' => "Aberto para lan\u{00E7}amento",
                'congelado' => 'Congelado',
                'finalizado' => 'Finalizado',
                default => $boletim->status,
            },
            'contract' => $contract ? [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
                'cliente_empresa' => $this->serializeEmpresa($contract->clienteEmpresa),
                'construtora_empresa' => $this->serializeEmpresa($contract->construtoraEmpresa),
                'gerenciadora_empresa' => $this->serializeEmpresa($contract->gerenciadoraEmpresa),
            ] : null,
        ];
    }

    private function serializeEmpresa(?Empresa $empresa): ?array
    {
        if (! $empresa) {
            return null;
        }

        return [
            'id' => $empresa->id,
            'nome' => $empresa->nome,
            'sigla' => $empresa->sigla,
        ];
    }

    private function boletimReportRelations(): array
    {
        return [
            'contract:id,code,name,cliente_empresa_id,construtora_empresa_id,fiscalizadora_empresa_id',
            'contract.clienteEmpresa:id,nome,sigla',
            'contract.construtoraEmpresa:id,nome,sigla',
            'contract.gerenciadoraEmpresa:id,nome,sigla',
        ];
    }
}
