<?php

namespace App\Services\Assistant;

use App\Models\Activity;
use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\FolhaRosto;
use App\Models\GedDocument;
use App\Models\Orcamento;
use App\Models\OrdemServico;
use App\Models\ProjectDocument;
use App\Models\RdaApontamento;
use App\Models\RdoDiario;
use App\Models\RelatorioNaoConformidade;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BudgetPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssistantRetriever
{
    private const STOP_WORDS = [
        'a', 'ao', 'aos', 'as', 'com', 'como', 'da', 'das', 'de', 'do', 'dos', 'e', 'em',
        'essa', 'esse', 'esta', 'este', 'eu', 'me', 'na', 'nas', 'no', 'nos', 'o', 'os',
        'para', 'por', 'qual', 'que', 'quais', 'se', 'sem', 'tem', 'um', 'uma', 'voce',
    ];

    public function __construct(
        private readonly AssistantAccessScope $access,
        private readonly AssistantContextBuilder $context
    ) {}

    /**
     * @return array<int, array{id:string,module:string,title:string,url:string,excerpt:string,content:string}>
     */
    public function retrieve(User $user, Tenant $tenant, string $question, ?string $currentPath = null): array
    {
        $terms = $this->terms($question);
        $contractIds = $this->access->contractIds($user, $tenant);
        $sources = collect();

        $this->context
            ->sources($user, $tenant, $contractIds, $question, $currentPath)
            ->each(fn (array $source) => $sources->push($source));

        $this->contractSources($tenant, $contractIds, $terms)->each(fn (array $source) => $sources->push($source));
        $this->activitySources($user, $tenant, $terms)->each(fn (array $source) => $sources->push($source));
        $this->projectSources($user, $tenant, $terms)->each(fn (array $source) => $sources->push($source));
        $this->rncSources($user, $tenant, $terms)->each(fn (array $source) => $sources->push($source));
        $this->documentSources($user, $tenant, $contractIds, $terms)->each(fn (array $source) => $sources->push($source));
        $this->budgetSources($user, $tenant, $terms)->each(fn (array $source) => $sources->push($source));
        $this->fieldSources($tenant, $contractIds, $terms)->each(fn (array $source) => $sources->push($source));
        $this->commercialSources($tenant, $contractIds, $terms)->each(fn (array $source) => $sources->push($source));

        $ranked = $sources
            ->map(function (array $source) use ($terms, $currentPath): array {
                $haystack = Str::lower($source['title'].' '.$source['content']);
                $score = collect($terms)->sum(fn (string $term): int => substr_count($haystack, Str::lower($term)));
                $sourcePath = parse_url($source['url'], PHP_URL_PATH);

                if ($currentPath && $sourcePath && rtrim($currentPath, '/') === rtrim($sourcePath, '/')) {
                    $score += 20;
                }

                $score += (int) ($source['_priority'] ?? 0);
                $source['_score'] = $score;

                return $source;
            })
            ->sortByDesc('_score')
            ->values();

        $maxSources = max(1, (int) config('services.openai.rag_max_sources', 8));
        $sourceLimit = max(300, (int) config('services.openai.rag_source_character_limit', 1600));
        $remainingCharacters = max($sourceLimit, (int) config('services.openai.rag_context_character_limit', 12000));
        $selected = collect();

        foreach ($ranked as $source) {
            if ($selected->count() >= $maxSources || $remainingCharacters <= 0) {
                break;
            }

            $allowedCharacters = min($sourceLimit, $remainingCharacters);
            $source['content'] = Str::limit((string) $source['content'], $allowedCharacters, '');
            $remainingCharacters -= mb_strlen($source['content']);
            unset($source['_score'], $source['_priority']);
            $source['id'] = 'S'.($selected->count() + 1);
            $selected->push($source);
        }

        return $selected->all();
    }

    /** @param Collection<int, int> $contractIds */
    private function contractSources(Tenant $tenant, Collection $contractIds, array $terms): Collection
    {
        $query = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $contractIds)
            ->with(['latestAdditive', 'obra', 'clienteEmpresa', 'construtoraEmpresa', 'gerenciadoraEmpresa']);

        $this->applySearch($query, ['contracts.code', 'contracts.name', 'contracts.description', 'contracts.city', 'contracts.state'], $terms);

        return $query->latest('updated_at')->limit(8)->get()->map(function (Contract $contract) use ($tenant): array {
            $additive = $contract->latestAdditive;
            $content = implode("\n", array_filter([
                "Contrato: {$contract->code} - {$contract->name}",
                "Descrição: {$contract->description}",
                'Obra principal: '.($contract->obra ? "{$contract->obra->codigo} - {$contract->obra->nome}" : 'não informada'),
                'Cliente: '.($contract->clienteEmpresa?->nome ?? $contract->client_company_name ?? 'não informado'),
                'Construtora: '.($contract->construtoraEmpresa?->nome ?? $contract->contractor_company_name ?? 'não informada'),
                'Gerenciadora: '.($contract->gerenciadoraEmpresa?->nome ?? 'não informada'),
                "Local: {$contract->city}/{$contract->state}",
                'Valor: '.($contract->total_value !== null ? 'R$ '.number_format((float) $contract->total_value, 2, ',', '.') : 'não informado'),
                'Vigência: '.($contract->starts_at?->format('d/m/Y') ?? 'não informada').' a '.($contract->ends_at?->format('d/m/Y') ?? 'não informada'),
                "Modo de medição: {$contract->measurement_mode}",
                'Último aditivo: '.($additive ? "nº {$additive->sequence_number}, {$additive->title}, tipo {$additive->type}" : 'nenhum'),
            ]));

            return $this->source('Contratos', "{$contract->code} - {$contract->name}", route('tenant.contracts.show', [$tenant->slug, $contract]), $content);
        });
    }

    private function activitySources(User $user, Tenant $tenant, array $terms): Collection
    {
        $ids = $this->access->activityContractIds($user, $tenant);

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = Activity::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $ids)
            ->visibleTo($user)
            ->with([
                'contract:id,code,name',
                'assignee:id,name',
                'assignees:id,name',
                'creator:id,name',
                'comments' => fn ($query) => $query->with('user:id,name')->latest('id')->limit(3),
            ])
            ->withCount(['comments', 'files']);
        $this->applySearch($query, ['activities.title', 'activities.description', 'activities.category', 'activities.status', 'activities.priority'], $terms);

        return $query->latest('updated_at')->limit(8)->get()->map(function (Activity $activity) use ($tenant): array {
            $content = implode("\n", [
                "Atividade: {$activity->title}",
                "Contrato: {$activity->contract?->code} - {$activity->contract?->name}",
                "Descrição: {$activity->description}",
                "Categoria: {$activity->category}; status: {$activity->status}; prioridade: {$activity->priority}",
                "Visibilidade: {$activity->visibility}",
                'Prazo: '.($activity->due_date?->format('d/m/Y') ?? 'não informado'),
                'Criada por: '.($activity->creator?->name ?? 'não informado'),
                'Responsáveis: '.($activity->assignees->pluck('name')->prepend($activity->assignee?->name)->filter()->unique()->implode(', ') ?: 'não informados'),
                "Comentários: {$activity->comments_count}; anexos: {$activity->files_count}",
                'Comentários recentes: '.($activity->comments->map(fn ($comment): string => ($comment->user?->name ?? 'Usuário').': '.$comment->body)->implode(' | ') ?: 'nenhum'),
            ]);

            return $this->source('Atividades', $activity->title, route('tenant.activities.index', $tenant->slug), $content);
        });
    }

    private function projectSources(User $user, Tenant $tenant, array $terms): Collection
    {
        $ids = $this->access->projectContractIds($user, $tenant);

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = ProjectDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $ids)
            ->with(['contract:id,code,name', 'disciplina:id,sigla,nome', 'phase:id,code,name', 'latestVersion']);
        $this->applySearch($query, ['project_documents.title', 'project_documents.code', 'project_documents.document_number', 'project_documents.document_type', 'project_documents.status'], $terms);

        return $query->latestSubmissionFirst()->limit(8)->get()->map(function (ProjectDocument $document) use ($tenant): array {
            $version = $document->latestVersion;
            $eap = $document->eap($version?->revision);
            $content = implode("\n", [
                "Projeto: {$document->title}",
                "EAP: {$eap}",
                "Contrato: {$document->contract?->code} - {$document->contract?->name}",
                "Disciplina: {$document->disciplina?->sigla} - {$document->disciplina?->nome}",
                "Fase: {$document->phase?->code} - {$document->phase?->name}",
                "Status: {$document->status}; revisão: {$version?->revision}",
                "Resumo da revisão: {$version?->revision_change_summary}",
            ]);
            $url = $version
                ? route('tenant.projects.viewer', [$tenant->slug, $version])
                : route('tenant.projects.visualizar.index', $tenant->slug);

            return $this->source('Projetos', "{$eap} - {$document->title}", $url, $content);
        });
    }

    private function rncSources(User $user, Tenant $tenant, array $terms): Collection
    {
        $ids = $this->access->rncContractIds($user, $tenant);

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = RelatorioNaoConformidade::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $ids)
            ->with(['contract:id,code,name', 'obra:id,codigo,nome', 'disciplina:id,sigla,nome']);
        $this->applySearch($query, ['relatorio_nao_conformidades.descricao_problema', 'relatorio_nao_conformidades.observacao', 'relatorio_nao_conformidades.acoes_corretivas_recomendadas', 'relatorio_nao_conformidades.status', 'relatorio_nao_conformidades.gravidade'], $terms);

        return $query->latest('updated_at')->limit(8)->get()->map(function (RelatorioNaoConformidade $rnc) use ($tenant): array {
            $content = implode("\n", [
                "RNC: RNC-{$rnc->formatted_number}",
                "Contrato: {$rnc->contract?->code} - {$rnc->contract?->name}",
                "Obra: {$rnc->obra?->codigo} - {$rnc->obra?->nome}",
                "Disciplina: {$rnc->disciplina?->sigla} - {$rnc->disciplina?->nome}",
                "Status: {$rnc->status}; gravidade: {$rnc->gravidade}; natureza: {$rnc->natureza}",
                "Problema: {$rnc->descricao_problema}",
                "Observação: {$rnc->observacao}",
                "Ação recomendada: {$rnc->acoes_corretivas_recomendadas}",
            ]);

            return $this->source('Qualidade', "RNC-{$rnc->formatted_number}", route('tenant.qualidade.rnc.show', [$tenant->slug, $rnc]), $content);
        });
    }

    /** @param Collection<int, int> $contractIds */
    private function documentSources(User $user, Tenant $tenant, Collection $contractIds, array $terms): Collection
    {
        if ($contractIds->isEmpty()) {
            return collect();
        }

        $query = GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->with([
                'contract:id,code,name',
                'type:id,name',
                'attachments:id,document_id,title,original_filename,notes,ocr_status,extracted_text',
            ]);
        $this->applySearch(
            $query,
            ['ged_documents.title', 'ged_documents.document_number', 'ged_documents.description', 'ged_documents.extracted_text', 'ged_documents.original_filename'],
            $terms,
            function (Builder $search, string $term): void {
                $search->orWhereHas('attachments', function (Builder $attachments) use ($term): void {
                    $attachments->where(function (Builder $attachmentSearch) use ($term): void {
                        foreach (['title', 'original_filename', 'notes', 'extracted_text'] as $column) {
                            $attachmentSearch->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.Str::lower($term).'%']);
                        }
                    });
                });
            }
        );

        return $query->latest('updated_at')->limit(100)->get()
            ->filter(fn (GedDocument $document): bool => $this->access->canReadDocument($user, $tenant, $document))
            ->take(8)
            ->map(function (GedDocument $document) use ($tenant): array {
                $content = implode("\n", [
                    "Documento: {$document->title}",
                    "Número: {$document->document_number}; arquivo: {$document->original_filename}",
                    "Contrato: {$document->contract?->code} - {$document->contract?->name}",
                    "Tipo: {$document->type?->name}; data: ".($document->document_date?->format('d/m/Y') ?? 'não informada'),
                    "Descrição: {$document->description}",
                    'Conteúdo OCR: '.Str::limit((string) $document->extracted_text, 1400),
                    'Anexos: '.($document->attachments->map(function ($attachment): string {
                        $name = $attachment->title ?: $attachment->original_filename;
                        $ocr = filled($attachment->extracted_text)
                            ? ' OCR: '.Str::limit((string) $attachment->extracted_text, 500)
                            : '';

                        return "{$name}; observação: {$attachment->notes}; status OCR: {$attachment->ocr_status}.{$ocr}";
                    })->take(8)->implode(' | ') ?: 'nenhum'),
                ]);

                return $this->source('Documentação', $document->title, route('tenant.ged.details', [$tenant->slug, $document]), $content);
            })
            ->values();
    }

    private function budgetSources(User $user, Tenant $tenant, array $terms): Collection
    {
        if (! BudgetPermissions::can($user, $tenant, BudgetPermissions::VIEW)) {
            return collect();
        }

        $query = BudgetPermissions::scopeVisibleTo(
            Orcamento::query()->where('tenant_id', $tenant->id),
            $user,
            $tenant
        );
        $this->applySearch($query, ['orcamentos.codigo', 'orcamentos.descricao', 'orcamentos.categoria', 'orcamentos.status'], $terms);

        return $query->latest('updated_at')->limit(8)->get()->map(function (Orcamento $budget) use ($tenant): array {
            $content = implode("\n", [
                "Orçamento: {$budget->codigo} - {$budget->descricao}",
                "Categoria: {$budget->categoria}; status: {$budget->status}",
                'Prazo: '.($budget->prazo_entrega_at?->format('d/m/Y') ?? 'não informado'),
                'BDI: '.number_format((float) $budget->bdi_percentual, 2, ',', '.').'%',
                'Valor não desonerado: R$ '.number_format((float) $budget->valor_nao_desonerado, 2, ',', '.'),
                'Valor desonerado: R$ '.number_format((float) $budget->valor_desonerado, 2, ',', '.'),
            ]);

            return $this->source('Orçamentos', "{$budget->codigo} - {$budget->descricao}", route('tenant.orcamentos.show', [$tenant->slug, $budget]), $content);
        });
    }

    /** @param Collection<int, int> $contractIds */
    private function fieldSources(Tenant $tenant, Collection $contractIds, array $terms): Collection
    {
        if ($contractIds->isEmpty()) {
            return collect();
        }

        $rdoQuery = RdoDiario::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)->with('contract:id,code,name');
        $this->applySearch($rdoQuery, ['rdo_diarios.code', 'rdo_diarios.status'], $terms);
        $rdos = $rdoQuery->latest('reference_date')->limit(5)->get()->map(function (RdoDiario $rdo) use ($tenant): array {
            $content = "RDO: {$rdo->code}\nContrato: {$rdo->contract?->code} - {$rdo->contract?->name}\nData: {$rdo->reference_date?->format('d/m/Y')}\nStatus: {$rdo->status}";

            return $this->source('Diário de Obra', $rdo->code, route('tenant.diario-obra.rdo.show', [$tenant->slug, $rdo]), $content);
        });

        $rdaQuery = RdaApontamento::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)->with('contract:id,code,name');
        $this->applySearch($rdaQuery, ['rda_apontamentos.status'], $terms);
        $rdas = $rdaQuery->latest('reference_date')->limit(4)->get()->map(function (RdaApontamento $rda) use ($tenant): array {
            $content = "RDA nº {$rda->id}\nContrato: {$rda->contract?->code} - {$rda->contract?->name}\nData: {$rda->reference_date?->format('d/m/Y')}\nStatus: {$rda->status}";

            return $this->source('Diário de Obra', "RDA {$rda->reference_date?->format('d/m/Y')}", route('tenant.diario-obra.rda.show', [$tenant->slug, $rda]), $content);
        });

        return collect($rdos->all())->concat($rdas->all());
    }

    /** @param Collection<int, int> $contractIds */
    private function commercialSources(Tenant $tenant, Collection $contractIds, array $terms): Collection
    {
        if ($contractIds->isEmpty()) {
            return collect();
        }

        $osQuery = OrdemServico::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)->with('contract:id,code,name');
        $this->applySearch($osQuery, ['ordem_servicos.codigo', 'ordem_servicos.titulo', 'ordem_servicos.descricao', 'ordem_servicos.status'], $terms);
        $orders = $osQuery->latest('updated_at')->limit(6)->get()->map(function (OrdemServico $order) use ($tenant): array {
            $content = implode("\n", [
                "OS: {$order->codigo} - {$order->titulo}",
                "Contrato: {$order->contract?->code} - {$order->contract?->name}",
                "Status: {$order->status}",
                'Início previsto: '.($order->prazo_inicio?->format('d/m/Y') ?? 'não informado'),
                'Finalização prevista: '.($order->prazo_finalizacao?->format('d/m/Y') ?? 'não informada'),
                'Custo previsto: R$ '.number_format((float) $order->custo_previsto, 2, ',', '.'),
                "Descrição: {$order->descricao}",
            ]);

            return $this->source('Ordem de Serviço', "{$order->codigo} - {$order->titulo}", route('tenant.ordem-servico.os.index', $tenant->slug), $content);
        });

        $bulletinQuery = BoletimMedicao::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)->with('contract:id,code,name');
        $this->applySearch($bulletinQuery, ['boletins_medicao.codigo', 'boletins_medicao.tipo', 'boletins_medicao.status'], $this->bulletinTerms($terms));
        $bulletins = $bulletinQuery->latest('periodo')->limit(6)->get()->map(function (BoletimMedicao $bulletin) use ($tenant): array {
            $content = implode("\n", [
                "Boletim: {$bulletin->codigo}",
                "Contrato: {$bulletin->contract?->code} - {$bulletin->contract?->name}",
                "Período: {$bulletin->periodo?->format('m/Y')}",
                "Tipo: {$bulletin->tipo}; status: {$bulletin->status}",
                $this->bulletinItemsContent($bulletin),
            ]);

            return $this->source('Medição', $bulletin->codigo, route('tenant.medicao.boletim-medicao.index', $tenant->slug), $content);
        });

        $coverQuery = FolhaRosto::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)->with('contract:id,code,name');
        $this->applySearch($coverQuery, ['folhas_rosto.codigo', 'folhas_rosto.comentario', 'folhas_rosto.status'], $terms);
        $covers = $coverQuery->latest('updated_at')->limit(5)->get()->map(function (FolhaRosto $cover) use ($tenant): array {
            $content = "Folha de rosto: {$cover->codigo}\nContrato: {$cover->contract?->code} - {$cover->contract?->name}\nStatus: {$cover->status}\nComentário: {$cover->comentario}";

            return $this->source('Medição', $cover->codigo, route('tenant.medicao.folha-rosto.index', $tenant->slug), $content);
        });

        return collect($orders->all())
            ->concat($bulletins->all())
            ->concat($covers->all());
    }

    private function bulletinItemsContent(BoletimMedicao $bulletin): string
    {
        $items = DB::table('folhas_rosto as fr')
            ->join('folha_rosto_itens as fri', 'fri.folha_rosto_id', '=', 'fr.id')
            ->leftJoin('medicao_itens as mi', 'mi.id', '=', 'fri.medicao_item_id')
            ->leftJoin('folha_rosto_item_analises as fia', function ($join): void {
                $join->on('fia.folha_rosto_item_id', '=', 'fri.id')
                    ->where('fia.setor', '=', 'medicao');
            })
            ->where('fr.boletim_medicao_id', $bulletin->id)
            ->whereNull('fr.deleted_at')
            ->select([
                'mi.id as medicao_item_id',
                'mi.item',
                'mi.codigo',
                'mi.descricao',
                'mi.unidade',
            ])
            ->selectRaw('COALESCE(SUM(fri.quantidade_pleiteada), 0) as quantidade_pleiteada')
            ->selectRaw('COALESCE(SUM(fri.valor_pleiteado), 0) as valor_pleiteado')
            ->selectRaw("COALESCE(SUM(CASE WHEN fr.status = 'analisada' AND fia.quantidade_aprovada IS NOT NULL THEN fia.quantidade_aprovada ELSE 0 END), 0) as quantidade_medida")
            ->selectRaw("COALESCE(SUM(CASE WHEN fr.status = 'analisada' AND fia.quantidade_aprovada IS NOT NULL THEN fia.quantidade_aprovada * CASE WHEN fri.quantidade_pleiteada > 0 THEN fri.valor_pleiteado / fri.quantidade_pleiteada ELSE 0 END ELSE 0 END), 0) as valor_medido")
            ->groupBy('mi.id', 'mi.item', 'mi.codigo', 'mi.descricao', 'mi.unidade')
            ->orderByDesc('valor_medido')
            ->orderByDesc('valor_pleiteado')
            ->limit(8)
            ->get();

        if ($items->isEmpty()) {
            return 'Itens do boletim: nenhum item vinculado.';
        }

        return "Itens consolidados do boletim, ordenados pelo maior valor medido (P0):\n".$items
            ->map(function (object $item): string {
                $identifier = collect([$item->item, $item->codigo])->filter()->implode(' - ');

                return implode(' | ', [
                    'Item: '.($identifier ?: $item->medicao_item_id),
                    'Descrição: '.Str::limit((string) $item->descricao, 140),
                    'Unidade: '.($item->unidade ?: 'não informada'),
                    'Qtd. pleiteada: '.number_format((float) $item->quantidade_pleiteada, 4, ',', '.'),
                    'Valor pleiteado: R$ '.number_format((float) $item->valor_pleiteado, 2, ',', '.'),
                    'Qtd. medida/aprovada: '.number_format((float) $item->quantidade_medida, 4, ',', '.'),
                    'Valor medido (P0): R$ '.number_format((float) $item->valor_medido, 2, ',', '.'),
                ]);
            })
            ->implode("\n");
    }

    private function bulletinTerms(array $terms): array
    {
        return collect($terms)
            ->flatMap(function (string $term): array {
                if (preg_match('/^bm-?0*(\d+)$/i', $term, $matches)) {
                    return [$term, 'bm-'.str_pad($matches[1], 3, '0', STR_PAD_LEFT)];
                }

                return [$term];
            })
            ->unique()
            ->values()
            ->all();
    }

    private function applySearch(Builder $query, array $columns, array $terms, ?callable $relatedSearch = null): void
    {
        if ($terms === []) {
            return;
        }

        $query->where(function (Builder $search) use ($columns, $terms, $relatedSearch): void {
            foreach ($terms as $term) {
                foreach ($columns as $column) {
                    $search->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.Str::lower($term).'%']);
                }

                if ($relatedSearch) {
                    $relatedSearch($search, $term);
                }
            }
        });
    }

    private function terms(string $question): array
    {
        preg_match_all('/[\pL\pN][\pL\pN_-]*/u', Str::lower($question), $matches);

        return collect($matches[0] ?? [])
            ->filter(fn (string $term): bool => mb_strlen($term) >= 3 && ! in_array(Str::ascii($term), self::STOP_WORDS, true))
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    private function source(string $module, string $title, string $url, string $content): array
    {
        return [
            'module' => $module,
            'title' => trim($title),
            'url' => $url,
            'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $content) ?: '', 180),
            'content' => Str::limit($content, 1800),
        ];
    }
}
