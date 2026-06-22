<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\FolhaRosto;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class MedicaoBiController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant): Response
    {
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer'],
            'boletim_id' => ['nullable', 'integer'],
        ]);

        $contractId = $filters['contract_id'] ?? null;
        $boletimId = $filters['boletim_id'] ?? null;

        $contracts = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
            ])
            ->values();

        $boletins = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where('contract_id', $contractId))
            ->orderByDesc('periodo')
            ->orderByDesc('sequencial')
            ->get(['id', 'contract_id', 'codigo', 'periodo', 'tipo', 'status'])
            ->map(fn (BoletimMedicao $boletim): array => [
                'id' => $boletim->id,
                'contract_id' => $boletim->contract_id,
                'codigo' => $boletim->codigo,
                'periodo' => $boletim->periodo?->format('m/y'),
                'tipo' => $this->labelTipoBoletim($boletim->tipo),
                'status' => $this->labelStatusBoletim($boletim->status),
            ])
            ->values();

        $folhas = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where('contract_id', $contractId))
            ->when($boletimId, fn ($query) => $query->where('boletim_medicao_id', $boletimId))
            ->with([
                'obra:id,codigo,nome',
                'boletimMedicao:id,codigo,periodo,tipo,status',
                'construtoraEmpresa:id,nome,sigla',
                'itens:id,folha_rosto_id,ordem_servico_item_id,quantidade_pleiteada,valor_pleiteado',
                'itens.analises:id,folha_rosto_item_id,setor,quantidade_aprovada',
                'itens.ordemServicoItem:id,medicao_item_id',
                'itens.ordemServicoItem.medicaoItem:id,item,codigo,descricao,unidade',
            ])
            ->latest('created_at')
            ->get();

        return Inertia::render('Tenant/Medicao/BI/Index', [
            'filters' => [
                'contract_id' => $contractId ? (int) $contractId : null,
                'boletim_id' => $boletimId ? (int) $boletimId : null,
            ],
            'contracts' => $contracts,
            'boletins' => $boletins,
            'dashboard' => $this->buildDashboard($folhas),
        ]);
    }

    private function buildDashboard(Collection $folhas): array
    {
        $totalPleiteado = $folhas->sum(fn (FolhaRosto $folha): float => $this->valorPleiteado($folha));
        $totalFiscal = $folhas->sum(fn (FolhaRosto $folha): float => $this->valorAprovadoPorSetor($folha, 'fiscal'));
        $totalQualidade = $folhas->sum(fn (FolhaRosto $folha): float => $this->valorAprovadoPorSetor($folha, 'qualidade'));
        $totalMedicao = $folhas->sum(fn (FolhaRosto $folha): float => $this->valorAprovadoPorSetor($folha, 'medicao'));
        $emAnalise = $folhas->filter(fn (FolhaRosto $folha): bool => str_starts_with((string) $folha->status, 'analise_'));
        $frsAtrasadas = $emAnalise->filter(fn (FolhaRosto $folha): bool => $this->diasEmAnalise($folha) > 10)->count();
        $itens = $this->itemAnalytics($folhas, $totalPleiteado);

        return [
            'cards' => [
                'total_frs' => $folhas->count(),
                'total_pleiteado' => round($totalPleiteado, 2),
                'total_aprovado_fiscal' => round($totalFiscal, 2),
                'total_aprovado_qualidade' => round($totalQualidade, 2),
                'total_aprovado_medicao' => round($totalMedicao, 2),
                'frs_em_analise' => $emAnalise->count(),
                'frs_atrasadas' => $frsAtrasadas,
                'prazo_medio_analise' => round($emAnalise->avg(fn (FolhaRosto $folha): int => $this->diasEmAnalise($folha)) ?? 0, 1),
                'itens_pleiteados' => $itens['cards']['itens_pleiteados'],
                'item_maior_impacto' => $itens['cards']['item_maior_impacto'],
                'concentracao_top_10' => $itens['cards']['concentracao_top_10'],
                'classe_a_itens' => $itens['cards']['classe_a_itens'],
                'classe_a_percentual' => $itens['cards']['classe_a_percentual'],
            ],
            'charts' => [
                'status' => $this->seriesStatus($folhas),
                'obras' => $this->seriesValorPorGrupo($folhas, fn (FolhaRosto $folha): string => $this->obraLabel($folha)),
                'construtoras' => $this->seriesValorPorGrupo($folhas, fn (FolhaRosto $folha): string => $this->construtoraLabel($folha)),
                'boletins' => $this->seriesValorPorGrupo($folhas, fn (FolhaRosto $folha): string => $this->boletimLabel($folha)),
                'evolucao_mensal' => $this->seriesEvolucaoMensal($folhas),
                'analise_setor' => $this->seriesAnaliseSetor($folhas),
                'itens_top' => $itens['charts']['itens_top'],
                'itens_abc' => $itens['charts']['itens_abc'],
                'itens_unidades' => $itens['charts']['itens_unidades'],
            ],
            'itens' => $itens['table'],
            'recentes' => $folhas
                ->take(8)
                ->map(fn (FolhaRosto $folha): array => [
                    'codigo' => $folha->codigo,
                    'comentario' => $folha->comentario,
                    'status' => $this->labelStatusFolha($folha->status),
                    'obra' => $this->obraLabel($folha),
                    'boletim' => $this->boletimLabel($folha),
                    'construtora' => $this->construtoraLabel($folha),
                    'valor' => round($this->valorPleiteado($folha), 2),
                    'dias_analise' => str_starts_with((string) $folha->status, 'analise_') ? $this->diasEmAnalise($folha) : null,
                ])
                ->values(),
        ];
    }

    private function seriesStatus(Collection $folhas): array
    {
        return $folhas
            ->groupBy(fn (FolhaRosto $folha): string => $this->labelStatusFolha($folha->status))
            ->map(fn (Collection $grupo, string $status): array => [
                'name' => $status,
                'value' => $grupo->count(),
            ])
            ->values()
            ->all();
    }

    private function seriesValorPorGrupo(Collection $folhas, callable $labelResolver): array
    {
        return $folhas
            ->groupBy(fn (FolhaRosto $folha): string => $labelResolver($folha))
            ->map(fn (Collection $grupo, string $label): array => [
                'name' => $label,
                'value' => round($grupo->sum(fn (FolhaRosto $folha): float => $this->valorPleiteado($folha)), 2),
                'count' => $grupo->count(),
            ])
            ->sortByDesc('value')
            ->take(10)
            ->values()
            ->all();
    }

    private function seriesEvolucaoMensal(Collection $folhas): array
    {
        return $folhas
            ->groupBy(fn (FolhaRosto $folha): string => $folha->boletimMedicao?->periodo?->format('Y-m') ?? $folha->created_at?->format('Y-m') ?? 'sem-periodo')
            ->map(fn (Collection $grupo, string $periodo): array => [
                'periodo' => $periodo === 'sem-periodo' ? 'Sem período' : Carbon::createFromFormat('Y-m', $periodo)->format('m/y'),
                'valor' => round($grupo->sum(fn (FolhaRosto $folha): float => $this->valorPleiteado($folha)), 2),
                'frs' => $grupo->count(),
            ])
            ->sortBy('periodo')
            ->values()
            ->all();
    }

    private function seriesAnaliseSetor(Collection $folhas): array
    {
        $setores = [
            'analise_fiscal' => 'Fiscal',
            'analise_qualidade' => 'Qualidade',
            'analise_medicao' => 'Medição',
        ];

        return collect($setores)
            ->map(fn (string $label, string $status): array => [
                'name' => $label,
                'value' => $folhas->where('status', $status)->count(),
            ])
            ->values()
            ->all();
    }

    private function itemAnalytics(Collection $folhas, float $totalPleiteado): array
    {
        $linhas = $folhas
            ->flatMap(fn (FolhaRosto $folha): Collection => $folha->itens->map(function ($item) use ($folha): array {
                $medicaoItem = $item->ordemServicoItem?->medicaoItem;
                $key = $medicaoItem?->id
                    ? 'medicao-'.$medicaoItem->id
                    : 'os-item-'.$item->ordem_servico_item_id;

                return [
                    'key' => $key,
                    'item' => $medicaoItem?->item ?: 'Sem item',
                    'codigo' => $medicaoItem?->codigo ?: 'Sem código',
                    'descricao' => $medicaoItem?->descricao ?: 'Sem descrição',
                    'unidade' => $medicaoItem?->unidade ?: 'S/U',
                    'quantidade' => (float) $item->quantidade_pleiteada,
                    'valor' => (float) $item->valor_pleiteado,
                    'folha_id' => $folha->id,
                ];
            }));

        $acumulado = 0.0;

        $itens = $linhas
            ->groupBy('key')
            ->map(function (Collection $grupo): array {
                $primeiro = $grupo->first();

                return [
                    'item' => $primeiro['item'],
                    'codigo' => $primeiro['codigo'],
                    'descricao' => $primeiro['descricao'],
                    'unidade' => $primeiro['unidade'],
                    'quantidade' => round($grupo->sum('quantidade'), 4),
                    'valor' => round($grupo->sum('valor'), 2),
                    'frs' => $grupo->pluck('folha_id')->unique()->count(),
                ];
            })
            ->sortByDesc('valor')
            ->values()
            ->map(function (array $item) use (&$acumulado, $totalPleiteado): array {
                $percentual = $totalPleiteado > 0 ? ($item['valor'] / $totalPleiteado) * 100 : 0;
                $acumuladoAntes = $acumulado;
                $acumulado += $percentual;
                $classe = $acumulado <= 80 || $acumuladoAntes < 80 ? 'A' : ($acumulado <= 95 || $acumuladoAntes < 95 ? 'B' : 'C');

                return [
                    ...$item,
                    'percentual' => round($percentual, 2),
                    'acumulado' => round(min($acumulado, 100), 2),
                    'classe' => $classe,
                ];
            });

        $top10Valor = (float) $itens->take(10)->sum('valor');
        $classeA = $itens->where('classe', 'A');
        $maiorImpacto = $itens->first();

        return [
            'cards' => [
                'itens_pleiteados' => $itens->count(),
                'item_maior_impacto' => $maiorImpacto ? [
                    'item' => $maiorImpacto['item'],
                    'codigo' => $maiorImpacto['codigo'],
                    'descricao' => $maiorImpacto['descricao'],
                    'valor' => $maiorImpacto['valor'],
                    'percentual' => $maiorImpacto['percentual'],
                ] : null,
                'concentracao_top_10' => $totalPleiteado > 0 ? round(($top10Valor / $totalPleiteado) * 100, 2) : 0,
                'classe_a_itens' => $classeA->count(),
                'classe_a_percentual' => round((float) $classeA->sum('percentual'), 2),
            ],
            'charts' => [
                'itens_top' => $itens
                    ->take(12)
                    ->map(fn (array $item): array => [
                        'name' => $this->itemShortLabel($item),
                        'value' => $item['valor'],
                        'quantidade' => $item['quantidade'],
                        'unidade' => $item['unidade'],
                        'classe' => $item['classe'],
                    ])
                    ->values()
                    ->all(),
                'itens_abc' => $itens
                    ->take(20)
                    ->map(fn (array $item): array => [
                        'name' => $this->itemShortLabel($item),
                        'valor' => $item['valor'],
                        'acumulado' => $item['acumulado'],
                        'classe' => $item['classe'],
                    ])
                    ->values()
                    ->all(),
                'itens_unidades' => $itens
                    ->groupBy('unidade')
                    ->map(fn (Collection $grupo, string $unidade): array => [
                        'name' => $unidade,
                        'value' => round((float) $grupo->sum('valor'), 2),
                        'count' => $grupo->count(),
                    ])
                    ->sortByDesc('value')
                    ->take(10)
                    ->values()
                    ->all(),
            ],
            'table' => $itens
                ->take(30)
                ->values()
                ->all(),
        ];
    }

    private function valorPleiteado(FolhaRosto $folha): float
    {
        return (float) $folha->itens->sum(fn ($item): float => (float) $item->valor_pleiteado);
    }

    private function itemShortLabel(array $item): string
    {
        $itemNumber = $item['item'] !== 'Sem item' ? $item['item'] : $item['codigo'];

        return trim((string) $itemNumber) ?: 'Sem item';
    }

    private function valorAprovadoPorSetor(FolhaRosto $folha, string $setor): float
    {
        return (float) $folha->itens->sum(function ($item) use ($setor): float {
            $quantidadePleiteada = (float) $item->quantidade_pleiteada;
            $valorPleiteado = (float) $item->valor_pleiteado;
            $unitario = $quantidadePleiteada > 0 ? $valorPleiteado / $quantidadePleiteada : 0;
            $analise = $item->analises->firstWhere('setor', $setor);

            return $analise?->quantidade_aprovada !== null
                ? (float) $analise->quantidade_aprovada * $unitario
                : 0;
        });
    }

    private function diasEmAnalise(FolhaRosto $folha): int
    {
        $inicio = $folha->submitted_for_analysis_at ?? $folha->updated_at ?? $folha->created_at;

        return $inicio ? max(0, $inicio->startOfDay()->diffInDays(now()->startOfDay())) : 0;
    }

    private function obraLabel(FolhaRosto $folha): string
    {
        $codigo = $folha->obra?->codigo;
        $nome = $folha->obra?->nome;

        return trim(collect([$codigo, $nome])->filter()->implode(' - ')) ?: 'Sem obra';
    }

    private function construtoraLabel(FolhaRosto $folha): string
    {
        return $folha->construtoraEmpresa?->sigla
            ?: $folha->construtoraEmpresa?->nome
            ?: 'Sem construtora';
    }

    private function boletimLabel(FolhaRosto $folha): string
    {
        $boletim = $folha->boletimMedicao;

        if (! $boletim) {
            return 'Sem BM';
        }

        return sprintf('%s · %s', $boletim->codigo, $boletim->periodo?->format('m/y') ?? 'sem período');
    }

    private function labelStatusFolha(?string $status): string
    {
        return [
            'rascunho' => 'Rascunho',
            'retornada' => 'Retornada',
            'analise_fiscal' => 'Análise fiscal',
            'analise_qualidade' => 'Análise qualidade',
            'analise_medicao' => 'Análise medição',
            'finalizada' => 'Finalizada',
            'aberta' => 'Aberta',
        ][$status] ?? ucfirst((string) ($status ?: 'sem status'));
    }

    private function labelTipoBoletim(?string $tipo): string
    {
        return [
            'normal' => 'Normal',
            'reequilibrio' => 'Reequilíbrio',
            'contingencia' => 'Contingência',
        ][$tipo] ?? ucfirst((string) ($tipo ?: 'normal'));
    }

    private function labelStatusBoletim(?string $status): string
    {
        return [
            'aberto' => 'Aberto para lançamento',
            'congelado' => 'Congelado',
            'finalizado' => 'Finalizado',
        ][$status] ?? ucfirst((string) ($status ?: 'aberto'));
    }
}
