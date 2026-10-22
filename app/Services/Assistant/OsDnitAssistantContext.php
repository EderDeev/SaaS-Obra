<?php

namespace App\Services\Assistant;

use App\Models\{OsDnitHighway, OsDnitOrder, OsDnitProduct, OsDnitResponsible, Tenant, User};
use App\Support\{ActivityPermissions, OsDnitContractAccess, OsDnitPermissions as P, OsDnitWorkflowAccess as Workflow};
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OsDnitAssistantContext
{
    public function sources(User $user, Tenant $tenant, string $question, ?string $path): Collection
    {
        $contracts = OsDnitContractAccess::contracts($user, $tenant, P::VIEW)->orderBy('code')->get(['id', 'code']);
        if ($contracts->isEmpty()) return collect();
        $needle = Str::lower(Str::ascii($question.' '.$path));
        if (!preg_match('/dnit|concremat|os-dnit|analista|fiscal do contrato|\d+\/\d{4}/', $needle)) return collect();
        $sources = collect();
        $scope = $contracts;
        if (str_contains($needle, 'contrato')) {
            $matched = $contracts->filter(fn ($contract) => str_contains($needle, Str::lower(Str::ascii($contract->code))));
            if ($matched->count() === 1) $scope = $matched;
        }
        foreach ($this->guides() as [$title, $route, $permission, $aliases, $content]) {
            if (!P::canAny($user, $tenant, $permission)) continue;
            if ($route === 'tenant.activities.index' && !ActivityPermissions::canAny($user, $tenant, ActivityPermissions::VIEW)) continue;
            $score = collect($aliases)->sum(fn ($alias) => str_contains($needle, $alias) ? 1 : 0);
            if ($score || $title === 'OS DNIT: criação e regras do pedido') $sources->push($this->source('Ajuda', $title, route($route, $tenant), $content, 940 + $score * 20));
        }

        $lines = $contracts->take(10)->map(function ($contract) use ($user, $tenant) {
            $permissions = array_map(fn ($key) => P::LABELS[$key], P::matrix($user, $tenant)[$contract->id]);
            $roles = OsDnitResponsible::where('tenant_id', $tenant->id)->where('contract_id', $contract->id)
                ->where('user_id', $user->id)->where('active', true)->pluck('role')
                ->map(fn ($role) => OsDnitResponsible::ROLES[$role])->implode(', ');
            return "Contrato {$contract->code}: ".implode('; ', $permissions).'. Papéis no fluxo: '.($roles ?: 'nenhum').'.';
        })->implode("\n");
        $sources->push($this->source('Acesso', 'Seu acesso ao OS DNIT por contrato', route('tenant.os-dnit.orders.index', $tenant),
            "OS DNIT exige vínculo ativo e permissões por contrato; não há dispensa para administradores. Ações de fluxo também exigem o papel correspondente.\n".$lines, 1010));

        $base = OsDnitOrder::where('tenant_id', $tenant->id)->whereIn('contract_id', $scope->pluck('id'));
        $counts = (clone $base)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $sources->push($this->source('Visão geral', 'Resumo OS DNIT autorizado', route('tenant.os-dnit.orders.index', $tenant),
            'Tenant atual, contratos '.$scope->pluck('code')->implode(', ').', com visualizar OS e participação ativa. Total: '.$counts->sum().'. '.
            $counts->map(fn ($total, $status) => (OsDnitOrder::STATUSES[$status] ?? $status).': '.$total)->implode('; ').
            '. Minhas pendências de análise: '.Workflow::pendingOrders($user, $tenant)->whereIn('contract_id', $scope->pluck('id'))->count().'. Aprovada significa fluxo concluído, não execução física.', 1020));
        $query = clone $base;
        if (preg_match('/\b(\d+\/\d{4})\b/', $question, $match)) {
            $query->where('request_number', $match[1]);
        } elseif (preg_match('/rascunho|analise concremat|fiscal do contrato|finalizad|aprova[dt]/', $needle)) {
            $states = match (true) {
                str_contains($needle, 'rascunho') => ['draft'],
                str_contains($needle, 'analise concremat') => ['awaiting_concremat', 'returned_concremat'],
                str_contains($needle, 'fiscal do contrato') => ['awaiting_contract'],
                default => ['approved'],
            };
            $query->whereIn('status', $states);
        }
        $isTutorial = preg_match('/\b(como|tutorial|funciona|ensine|explique|passo a passo)\b|produt|rodovia|responsave|dashboard|pdf/', $needle) && !preg_match('/\d+\/\d{4}/', $question);
        $orders = $isTutorial ? collect() : $query->with(['contract:id,code', 'items', 'events.actor:id,name'])->latest('updated_at')->limit(3)->get();
        foreach ($orders as $order) {
            $events = $order->events->sortByDesc('id');
            $decision = $events->first(fn ($event) => in_array($event->action, ['approved', 'returned'], true));
            $content = implode("\n", [
                "OS DNIT {$order->request_number}: {$order->subject}. Contrato {$order->contract->code}.",
                'Situação: '.(OsDnitOrder::STATUSES[$order->status] ?? $order->status).'.',
                "Rodovia: {$order->highway_name}; km {$order->km_start} a {$order->km_end}; lote {$order->supervision_lot}.",
                'Prazo solicitado: '.$order->desired_deadline->format('d/m/Y').'; prazo estimado: '.($order->estimated_deadline?->format('d/m/Y') ?? 'não informado').'.',
                'Pode editar: '.(Workflow::canEdit($user, $tenant, $order) ? 'sim' : 'não').'; pode enviar: '.(Workflow::canSubmit($user, $tenant, $order) ? 'sim' : 'não').'.',
                'Produtos solicitados (até 10 de '.$order->items->count().'): '.$order->items->take(10)->map(fn ($item) => "{$item->product_code} {$item->product_name}: {$item->quantity} {$item->unit}")->implode('; '),
                'Análise Concremat: '.($order->review_comment ?: 'ainda não registrada').'.',
                'Produtos da análise: '.collect($order->review_items)->take(10)->map(fn ($item) => "{$item['product_code']}: {$item['quantity']} {$item['unit']}")->implode('; '),
                'Última decisão registrada: '.($decision ? $decision->actor?->name.'; '.$decision->created_at->format('d/m/Y H:i').'; '.$decision->comment : 'nenhuma').'.',
                'Necessidade técnica: '.Str::limit($order->technical_need, 250),
                'Justificativa: '.Str::limit($order->justification, 250),
            ]);
            $sources->push($this->source('OS DNIT', 'OS DNIT '.$order->request_number.' — '.$order->subject, route('tenant.os-dnit.orders.show', [$tenant, $order]), $content, 1040));
        }
        $helpRequested = preg_match('/\b(como|tutorial|funciona|ensine|explique|passo a passo)\b/', Str::lower(Str::ascii($question)));
        if (str_contains($needle, 'produt') && (!$helpRequested || preg_match('/(?:produto|codigo)\s+[0-9]/i', $question))) {
            $ids = array_intersect(P::contractIds($user, $tenant, P::VIEW_PRODUCTS), $scope->pluck('id')->all());
            $products = OsDnitProduct::where('tenant_id', $tenant->id)->whereIn('contract_id', $ids)->with('contract:id,code');
            if (preg_match('/(?:produto|codigo)\s+([0-9][a-z0-9._-]*)\b/i', Str::ascii($question), $match)) $products->where('code', Str::upper($match[1]));
            foreach ($products->orderBy('code')->limit(12)->get() as $product) {
                $sources->push($this->source('OS DNIT · Produtos', $product->code.' — '.$product->name, route('tenant.os-dnit.products.index', [$tenant, 'contract_id' => $product->contract_id]),
                    "Contrato {$product->contract->code}. Produto {$product->code}: {$product->name}. Categoria: ".OsDnitProduct::CATEGORIES[$product->category].". Unidade: {$product->unit}. Frequência: ".OsDnitProduct::FREQUENCIES[$product->frequency].'. Amostra do catálogo autorizado, não contagem total.', 1060));
            }
        }
        if (str_contains($needle, 'rodovia')) {
            foreach (OsDnitHighway::where('tenant_id', $tenant->id)->whereIn('contract_id', $scope->pluck('id'))->with('contract:id,code')->orderBy('name')->limit(10)->get() as $road) {
                $sources->push($this->source('OS DNIT · Parametrização', 'Rodovia '.$road->name, route('tenant.os-dnit.settings.index', [$tenant, 'contract_id' => $road->contract_id]),
                    "Rodovia {$road->name}; contrato {$road->contract->code}; ativa: ".($road->active ? 'sim' : 'não').'.', 950));
            }
        }
        if (preg_match('/responsave|email|fiscal/', $needle)) {
            $ids = array_intersect(P::contractIds($user, $tenant, P::RESPONSIBLES), $scope->pluck('id')->all());
            $assignments = OsDnitResponsible::where('tenant_id', $tenant->id)->whereIn('contract_id', $ids)->where('active', true)
                ->whereHas('user.contractParticipations', fn ($query) => $query->where('tenant_id', $tenant->id)->where('status', 'active')
                    ->whereColumn('contract_participants.contract_id', 'os_dnit_responsibles.contract_id'))
                ->with('user:id,name')->limit(20)->get();
            if ($ids) $sources->push($this->source('OS DNIT · Responsáveis', 'Responsáveis ativos do OS DNIT', route('tenant.os-dnit.responsibles.index', $tenant),
                $assignments->isEmpty() ? 'Nenhum responsável ativo nos contratos autorizados.' : $assignments->map(fn ($assignment) => 'Contrato '.$scope->firstWhere('id', $assignment->contract_id)->code.': '.OsDnitResponsible::ROLES[$assignment->role].' — '.$assignment->user->name)->implode("\n"), 1060));
        }
        return $sources;
    }

    private function guides(): array
    {
        return [
            ['OS DNIT: criação e regras do pedido', 'tenant.os-dnit.orders.index', P::VIEW, ['criar', 'rascunho', 'enviar', 'os-dnit'],
                'OS DNIT é separado de Liberação de Serviço. Menu: Ordem de Serviço, Produtos, Dashboard, Parametrização e Responsáveis, conforme permissões. No contrato, cadastre produtos, rodovias e responsáveis. Em Ordem de Serviço, Criar OS: contrato, lote, rodovia, km inicial/final (duas casas decimais), assunto, prazo pretendido, necessidade técnica, justificativa, referência SEI/processo e anexos. Selecione produtos pela busca e informe quantidades. O número sequencial/ano é automático por contrato; a prévia se confirma ao salvar. Salvar cria um rascunho, não envia. O autor com papel Analista DNIT e permissão pode editar somente rascunho nunca enviado. Depois de enviado não pode editar, inclusive se devolvido. Enviar para análise exige confirmação e responsável Concremat com vínculo e acesso. Excluir OS só é possível com permissão e em rascunho nunca enviado; histórico e arquivos são preservados.'],
            ['OS DNIT: análise Concremat e decisão do fiscal', 'tenant.os-dnit.orders.index', P::VIEW, ['analis', 'concremat', 'fiscal', 'reprov', 'devol', 'fluxo'],
                'Fluxo: Analista DNIT cria rascunho → envia ao Fiscal Concremat → este analisa e encaminha ao Fiscal do contrato → aprova ou devolve. Não existe submenu separado Análise: use Analisar OS na listagem; abre modal. Fiscal Concremat mantém a solicitação original e registra proposta separada com comentário obrigatório, prazo estimado e produtos/quantidades. Pode salvar a análise ou encaminhá-la, confirmando o envio. O Fiscal do contrato compara Pedido do analista e Análise Concremat; diferenças de produtos e quantidades ficam destacadas. Comentário é obrigatório na decisão, inclusive aprovação. Para reprovar, informe motivo e destino: Concremat para produtos/quantidades/prazo; Analista DNIT para o pedido. Há confirmação antes da decisão. Devolução não libera edição do pedido original já enviado. Cada transição registra histórico e dispara avisos aos responsáveis autorizados; não se pode afirmar entrega de e-mail sem evidência. Papéis são definidos por contrato em Responsáveis; permissão de visualizar sozinha não autoriza analisar ou decidir.'],
            ['OS DNIT: baixar PDF e consultar o fluxo', 'tenant.os-dnit.orders.index', P::VIEW, ['pdf', 'download', 'baixar', 'historico', 'imprimir'],
                'Na listagem ou ao acessar a OS, use Baixar PDF. Opções cumulativas: solicitação do analista; solicitação + análise Concremat; solicitação + análise + decisão do fiscal; documento completo com fluxo. O PDF inicia com cabeçalho e identificação, descrição/justificativa e produtos solicitados. Análise Concremat e decisão fiscal têm páginas próprias com cabeçalho repetido; o fluxo fica na última página do completo. Conteúdo longo continua em outras páginas. O arquivo tem formato A4; no celular usa zoom do leitor, não reorganização HTML. Ao acessar a OS, o quadro Fluxo da OS reúne eventos, responsáveis, datas e comentários. Consultar ou baixar exige visualizar a OS no contrato. Não use PDF de Liberação de Serviço para OS DNIT.'],
            ['OS DNIT: produtos e rodovias', 'tenant.os-dnit.products.index', P::VIEW_PRODUCTS, ['produt', 'categori', 'quantidad', 'catalogo'],
                'Produtos é um catálogo por contrato: código, nome, categoria, unidade de medida e frequência Mensal ou Por demanda. Novo produto abre modal. Categorias: Gestão e controle, Supervisão de obras, Controle tecnológico, Controle executivo, Levantamentos e estudos, Sondagens, Projetos e inspeções, Ambiental. Busca, categoria e contrato filtram dinamicamente, com 15 itens por página. Criar, editar e excluir dependem de permissões próprias; editar preserva os dados já salvos nas OSs. Produtos usados em OSs não podem ser excluídos. Em Parametrização, cadastre rodovias como texto livre (ex.: BR-364/AC), sempre por contrato; pode editar e ativar/inativar. Rodovias usadas não podem ser excluídas. Na criação/análise de OS, selecione apenas produtos e rodovias do mesmo contrato.'],
            ['OS DNIT: dashboard', 'tenant.os-dnit.dashboard', P::DASHBOARD, ['dashboard', 'metric', 'indicador', 'grafico'],
                'Dashboard fica depois de Produtos. Exige permissão Visualizar dashboard e vínculo contratual. Mostra total, rascunhos, análise Concremat, fiscal do contrato, aprovadas e devolvidas; os grupos são exclusivos. Contrato, período e rodovia filtram dinamicamente. Cartões, distribuição e pendências usam data de abertura e situação atual. A evolução mensal usa eventos de envio e aprovação no período, contando cada OS uma vez por ação/mês, mesmo se aberta antes. Aprovação finaliza o fluxo, não comprova execução do serviço. As pendências priorizam maior espera na etapa; salvar análise não reinicia essa espera. Não somar unidades diferentes dos produtos nem confundir prazo solicitado, prazo estimado e conclusão real.'],
            ['OS DNIT: responsáveis, permissões e avisos', 'tenant.os-dnit.orders.index', P::VIEW, ['responsave', 'permiss', 'acesso', 'email', 'aviso'],
                'Tudo é separado por tenant e contrato. Só participantes ativos recebem acesso, inclusive administradores. Em /permissoes, há 13 opções OS DNIT: visualizar/criar/editar/excluir OS; visualizar/criar/editar/excluir produtos; visualizar dashboard; criar/editar/excluir parametrização; gerenciar responsáveis. Visualizar OS faz o menu aparecer e é base das outras permissões. Em Responsáveis, por contrato, atribua Analista DNIT, Fiscal Concremat e Fiscal do contrato. Esses papéis são adicionais às permissões. Revogar visualizar bloqueia fluxo e avisos. As transições geram e-mail para responsáveis autorizados; não confundir fila/envio com confirmação de recebimento. Para investigar ausência, confira contrato, responsável, vínculo ativo e permissão; registros de entrega e credenciais de e-mail não estão disponíveis ao assistente.'],
            ['Atividades: vínculo com OS DNIT', 'tenant.activities.index', P::VIEW, ['atividade', 'tarefa', 'vincul'],
                'Em Atividades, a categoria Ordem de Serviço DNIT abre seletor pesquisável por número ou assunto. Selecione a OS do mesmo contrato; o vínculo é obrigatório nessa categoria, fica salvo e pode ser alterado na edição. Trocar contrato limpa a seleção; trocar categoria remove o vínculo ao salvar. A busca exclui OSs apagadas e respeita acesso. O detalhe da atividade mostra link da OS quando autorizado. Liberação de Serviço é outra categoria. Criar atividade não cria OS nem aprova serviço. As permissões de atividades continuam necessárias.'],
        ];
    }

    private function source(string $module, string $title, string $url, string $content, int $priority): array
    {
        return ['module' => $module, 'title' => $title, 'url' => $url, 'excerpt' => Str::limit($content, 180), 'content' => $content, '_priority' => $priority];
    }
}
