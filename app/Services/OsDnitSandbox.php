<?php

namespace App\Services;

use App\Http\Controllers\Tenant\{ActivityController, OsDnitOrderController, OsDnitProductController, OsDnitResponsibleController, OsDnitSettingsController, OsDnitWorkflowController};
use App\Models\{Activity, Contract, OsDnitHighway, OsDnitOrder, OsDnitOrderEvent, OsDnitProduct, Tenant, User};
use App\Support\{ActivityPermissions as AP, OsDnitContractAccess, OsDnitPermissions as P};
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\{ArraySessionHandler, Store};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Auth, DB, Hash, Mail, Queue};
use Illuminate\Queue\{QueueManager, QueueServiceProvider};
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Carga aditiva local. Não é uma suíte PHPUnit e nunca limpa um banco existente. */
class OsDnitSandbox
{
    public const MARKER = 'os-dnit-sandbox-v1';
    public const CONTRACTS = 4;
    public const ORDERS_PER_CONTRACT = 30;

    private Tenant $tenant;
    private User $manager;
    private Store $session;
    private array $contexts = [];
    private array $accounts = [];
    private array $checks = [];
    private int $actions = 0;

    public static function assertEnvironment(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $localPg = app()->environment('local') && $driver === 'pgsql'
            && in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true);
        $isolatedTests = app()->environment('testing') && $driver === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        if ((!$localPg && !$isolatedTests) || config('database.protect_destructive_commands', false)
            || filter_var(env('DB_PROTECT_DESTRUCTIVE', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Carga permitida somente no PostgreSQL local em loopback ou no SQLite :memory: de testing, sem marcador de produção.');
        }
        foreach (['RAILWAY_ENVIRONMENT_ID', 'RAILWAY_PROJECT_ID', 'RAILWAY_SERVICE_ID'] as $marker) {
            if (env($marker)) throw new RuntimeException('Carga bloqueada: marcador de ambiente publicado presente.');
        }
    }

    public function create(string $slug, User $viewer, string $password): array
    {
        self::assertEnvironment();
        if (!preg_match('/^teste-os-dnit(?:-[a-z0-9-]+)?$/D', $slug)) throw new RuntimeException('Use um slug de testes iniciado por teste-os-dnit.');
        if (!$viewer->is_platform_admin) throw new RuntimeException('O usuário de visualização deve ser o administrador da plataforma.');
        if ($existing = Tenant::where('slug', $slug)->first()) {
            if (data_get($existing->settings, 'sandbox.marker') !== self::MARKER) throw new RuntimeException('O slug já pertence a outro ambiente; nada foi alterado.');
            return ['created' => false, 'tenant' => $existing, 'report' => data_get($existing->settings, 'sandbox.report', []), 'accounts' => []];
        }

        $this->session = new Store('os_dnit_sandbox', new ArraySessionHandler(120));
        $this->session->start();
        $originalRequest = request();
        $originalUser = Auth::user();
        $originalQueue = app('queue');
        $configKeys = ['mail.default', 'mail.mailers.sandbox_memory', 'queue.default', 'queue.connections.os_dnit_mail', 'session.driver'];
        $originalConfig = collect($configKeys)->mapWithKeys(fn ($key) => [$key => config($key)])->all();
        $originalNow = Carbon::getTestNow();
        $baseTime = now()->toImmutable();
        config(['mail.default' => 'sandbox_memory', 'mail.mailers.sandbox_memory' => ['transport' => 'array'],
            'queue.default' => 'sync', 'queue.connections.os_dnit_mail' => ['driver' => 'sync'], 'session.driver' => 'array']);
        // Conexão exclusiva desta execução; o worker normal não recebe jobs da carga.
        $sandboxQueue = new QueueManager(app());
        (new QueueServiceProvider(app()))->registerConnectors($sandboxQueue);
        Queue::swap($sandboxQueue);
        app('redirect')->setSession($this->session);

        try {
            if (!(Mail::mailer('sandbox_memory')->getSymfonyTransport() instanceof \Illuminate\Mail\Transport\ArrayTransport)
                || !(app('queue')->connection('os_dnit_mail') instanceof \Illuminate\Queue\SyncQueue)) {
                throw new RuntimeException('A simulação exige transporte de e-mail em memória e fila síncrona.');
            }
            $result = DB::transaction(function () use ($slug, $viewer, $password, $baseTime) {
                $this->tenant = Tenant::create(['slug' => $slug, 'name' => 'Laboratório OS DNIT', 'plan' => 'enterprise', 'status' => 'active',
                    'settings' => ['sandbox' => ['marker' => self::MARKER, 'synthetic_data' => true]]]);
                $hash = Hash::make($password);
                $this->manager = $this->user($slug, 'gestor', 'Gestor do Laboratório', $hash, 'tenant_owner');
                $this->tenant->memberships()->create(['user_id' => $viewer->id, 'role' => 'tenant_admin', 'status' => 'active', 'joined_at' => now()]);
                $definitions = [
                    ['AC-001', 'Supervisão BR-364 — Acre', 'AC', 'Rio Branco', ['BR-364/AC', 'BR-317/AC'], 'recuperação de pavimento'],
                    ['RO-002', 'Conservação BR-364 — Rondônia', 'RO', 'Porto Velho', ['BR-364/RO', 'BR-319/RO'], 'drenagem e conservação'],
                    ['MT-003', 'Projetos BR-163 — Mato Grosso', 'MT', 'Cuiabá', ['BR-163/MT', 'BR-070/MT'], 'levantamentos e projetos'],
                    ['GO-004', 'Fiscalização BR-153 — Goiás', 'GO', 'Goiânia', ['BR-153/GO', 'BR-060/GO'], 'controle tecnológico e ambiental'],
                ];
                foreach ($definitions as $index => $definition) $this->prepareContract($slug, $definition, $index, $hash, $viewer, $baseTime);

                $scenarios = ['draft', 'concremat', 'concremat_saved', 'inspector', 'returned_concremat', 'returned_analyst',
                    'approved', 'approved_changes', 'approved_after_concremat', 'approved_after_analyst'];
                foreach ($this->contexts as $ci => &$context) {
                    for ($i = 0; $i < self::ORDERS_PER_CONTRACT; $i++) {
                        // Datas fictícias em meses diferentes alimentam os gráficos de evolução.
                        Carbon::setTestNow($baseTime->subDays(118 - $i * 3)->setTime(9 + $ci, 0));
                        $context['orders'][] = $this->order($context, $scenarios[$i % count($scenarios)], $i);
                    }
                    for ($i = 0; $i < 12; $i++) {
                        Carbon::setTestNow($baseTime->subDays(25 - $i)->setTime(14, 0));
                        $this->activity($context, $i);
                    }
                }
                unset($context);
                Carbon::setTestNow($baseTime);
                $this->verifyIsolation();
                $mailCount = Mail::mailer('sandbox_memory')->getSymfonyTransport()->messages()->count();
                $report = $this->report($mailCount);
                $this->tenant->update(['settings' => ['sandbox' => ['marker' => self::MARKER, 'synthetic_data' => true, 'report' => $report]]]);
                return ['created' => true, 'tenant' => $this->tenant, 'report' => $report, 'accounts' => $this->accounts];
            });
            return $result;
        } finally {
            Carbon::setTestNow($originalNow);
            app()->instance('request', $originalRequest);
            app('url')->setRequest($originalRequest);
            Auth::forgetGuards();
            if ($originalUser) Auth::setUser($originalUser);
            config($originalConfig);
            Queue::swap($originalQueue);
            Mail::purge('sandbox_memory');
        }
    }

    private function user(string $slug, string $key, string $name, string $hash, string $role = 'engenheiro_campo'): User
    {
        $email = "{$key}@{$slug}.test";
        if (User::where('email', $email)->exists()) throw new RuntimeException("Conta de exemplo já existe: {$email}. Nenhuma senha foi alterada.");
        $user = User::create(['name' => $name, 'email' => $email, 'password' => $hash, 'must_change_password' => false, 'is_platform_admin' => false]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->tenant->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active', 'joined_at' => now(),
            'activity_permissions' => AP::all()]);
        $this->accounts[] = ['name' => $name, 'email' => $email, 'profile' => $role, 'key' => $key];
        return $user;
    }

    private function prepareContract(string $slug, array $definition, int $index, string $hash, User $viewer, $baseTime): void
    {
        [$code, $name, $state, $city, $roads, $theme] = $definition;
        $contract = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'LAB-'.$code, 'name' => $name,
            'description' => 'Dados fictícios para validação de OS DNIT e Atividades.', 'status' => 'active', 'measurement_mode' => 'simple',
            'starts_at' => $baseTime->subMonths(6)->toDateString(), 'ends_at' => $baseTime->addYear()->toDateString(),
            'total_value' => 1500000 + $index * 550000, 'currency' => 'BRL', 'state' => $state, 'city' => $city,
            'client_company_name' => 'Cliente de demonstração', 'contractor_company_name' => 'Executora de demonstração']);
        $number = $index + 1;
        $actors = [];
        foreach (['analyst' => ['analista', 'Analista DNIT'], 'concremat' => ['concremat', 'Fiscal Concremat'],
            'contract_inspector' => ['fiscal', 'Fiscal do contrato'], 'viewer' => ['leitor', 'Leitor']] as $role => [$key, $label]) {
            $actors[$role] = $this->user($slug, $key.$number, "{$label} · Contrato {$number}", $hash);
            $readOnly = $role === 'viewer';
            $contract->participants()->create(['tenant_id' => $this->tenant->id, 'user_id' => $actors[$role]->id,
                'side' => in_array($role, ['analyst', 'contract_inspector']) ? 'client' : 'manager', 'role' => 'team_member', 'status' => 'active',
                'joined_at' => now(), 'os_dnit_permissions' => $readOnly ? [P::VIEW, P::VIEW_PRODUCTS, P::DASHBOARD] : P::all(),
                'activity_permissions' => $readOnly ? [AP::VIEW, AP::VIEW_METRICS] : AP::all()]);
        }
        foreach ([$this->manager, $viewer] as $admin) $contract->participants()->create(['tenant_id' => $this->tenant->id,
            'user_id' => $admin->id, 'side' => 'manager', 'role' => 'manager', 'status' => 'active', 'joined_at' => now(),
            'os_dnit_permissions' => P::all(), 'activity_permissions' => AP::all()]);

        $assignments = [];
        foreach (['analyst', 'concremat', 'contract_inspector'] as $role) {
            $assignments[] = ['user_id' => $actors[$role]->id, 'role' => $role];
            $assignments[] = ['user_id' => $this->manager->id, 'role' => $role];
        }
        $this->call(OsDnitResponsibleController::class, 'store', $this->manager, ['contract_id' => $contract->id, 'assignments' => $assignments]);
        $highways = [];
        foreach ($roads as $road) {
            $this->call(OsDnitSettingsController::class, 'store', $this->manager, ['contract_id' => $contract->id, 'name' => $road]);
            $highways[] = OsDnitHighway::where('contract_id', $contract->id)->where('name', $road)->sole();
        }
        $catalog = [
            ['01', 'Coordenação-Geral', 'gestao_controle', 'mês', 'monthly'], ['02', 'Controle de Obras', 'gestao_controle', 'mês', 'monthly'],
            ['02.PATO', 'Plano de Trabalho e Orçamento — '.$state, 'gestao_controle', 'unid', 'on_demand'],
            ['02.ASB', 'As Built dos trechos — '.$state, 'gestao_controle', 'km', 'on_demand'],
            ['03', 'Supervisão Ambiental — '.$city, 'ambiental', 'km', 'on_demand'],
            ['04A', 'Supervisão de conservação de rodovias pavimentadas', 'supervisao_obras', 'km', 'monthly'],
            ['04B', 'Supervisão de conservação de rodovias não pavimentadas', 'supervisao_obras', 'km', 'monthly'],
            ['05A', 'Soluções com intervenção na base do pavimento', 'supervisao_obras', 'm²', 'on_demand'],
            ['06', 'Controle tecnológico de solos — '.$state, 'controle_tecnologico', 'ensaio', 'on_demand'],
            ['07', 'Controle tecnológico de concreto', 'controle_tecnologico', 'ensaio', 'on_demand'],
            ['08', 'Controle tecnológico de revestimento asfáltico', 'controle_tecnologico', 't', 'on_demand'],
            ['09', 'Controle executivo da terraplenagem', 'controle_executivo', 'm³', 'on_demand'],
            ['10', 'Controle executivo de drenagem', 'controle_executivo', 'm', 'on_demand'],
            ['11', 'Levantamento de sinalização — '.$roads[0], 'levantamentos_estudos', 'km', 'on_demand'],
            ['12', 'Estudo de tráfego — '.$city, 'levantamentos_estudos', 'unid', 'on_demand'],
            ['15', 'Sondagem a percussão', 'sondagens', 'm', 'on_demand'],
            ['16', 'Sondagem rotativa — '.$state, 'sondagens', 'm', 'on_demand'],
            ['18D', 'Levantamentos Topográficos — '.$roads[0], 'levantamentos_estudos', 'm²', 'on_demand'],
            ['18E', 'Levantamentos Topográficos em Ponto Localizado', 'levantamentos_estudos', 'unid', 'on_demand'],
            ['21G', 'Projetos Básico e Executivo de Pontos Críticos — '.$state, 'projetos_inspecoes', 'unid', 'on_demand'],
        ];
        $products = [];
        foreach ($catalog as [$code, $productName, $category, $unit, $frequency]) {
            $this->call(OsDnitProductController::class, 'store', $this->manager, ['contract_id' => $contract->id,
                'code' => $code, 'name' => $productName, 'category' => $category, 'unit' => $unit, 'frequency' => $frequency]);
            $products[] = OsDnitProduct::where('contract_id', $contract->id)->where('code', $code)->sole();
        }
        $this->contexts[] = compact('contract', 'actors', 'highways', 'products', 'theme') + ['orders' => []];
    }

    private function demand(array $context, int $index): array
    {
        $products = $context['products'];
        $start = round(4.5 + $index * 3.25, 2);
        $labels = ['Levantamento de ponto crítico', 'Avaliação de drenagem', 'Controle de pavimentação', 'Inspeção de conservação', 'Estudo técnico de trecho'];
        return ['contract_id' => $context['contract']->id, 'supervision_lot' => 'Lote '.str_pad((string)($index % 3 + 1), 2, '0', STR_PAD_LEFT),
            'highway_id' => $context['highways'][$index % 2]->id, 'km_start' => $start, 'km_end' => round($start + 1.35, 2),
            'subject' => sprintf('%02d · %s — %s', $index + 1, $labels[$index % 5], $context['contract']->code),
            'desired_deadline' => now()->addDays(15 + $index % 20)->toDateString(),
            'technical_need' => "Realizar {$context['theme']} entre os quilômetros {$start} e ".($start + 1.35).'. Avaliar as condições de campo, registrar os quantitativos e subsidiar a definição da intervenção. Dados fictícios de laboratório.',
            'justification' => 'Priorizar os pontos com maior necessidade de intervenção e documentar a solução técnica. Espera-se relatório, memória de cálculo e indicação dos serviços necessários.',
            'sei_reference' => 'LAB-SEI-'.($index + 1000), 'items' => array_map(fn ($offset) => ['product_id' => $products[($index + $offset) % count($products)]->id,
                'quantity' => number_format(($index + 1) * ($offset + 1) + 0.25, 3, '.', '')], range(0, 1 + $index % 3))];
    }

    private function order(array $context, string $scenario, int $index): OsDnitOrder
    {
        $data = $this->demand($context, $index);
        $this->call(OsDnitOrderController::class, 'store', $context['actors']['analyst'], $data);
        $order = OsDnitOrder::where('contract_id', $context['contract']->id)->latest('id')->firstOrFail();
        if ($scenario === 'draft') return $order;
        $this->transition($context, $order, 'submit', 'analyst');
        if ($scenario === 'concremat') return $order->refresh();
        $items = $data['items'];
        $changed = in_array($scenario, ['approved_changes', 'approved_after_concremat', 'returned_concremat', 'inspector', 'concremat_saved'], true);
        if ($changed) {
            $items[0]['quantity'] = number_format((float)$items[0]['quantity'] * 1.2, 3, '.', '');
            array_pop($items);
            $extra = collect($context['products'])->first(fn ($product) => !in_array($product->id, array_column($data['items'], 'product_id')));
            $items[] = ['product_id' => $extra->id, 'quantity' => '3.500'];
        }
        $analysis = ['mode' => $scenario === 'concremat_saved' ? 'save' : 'forward', 'items' => $items,
            'estimated_deadline' => now()->addDays($changed ? 28 : 20)->toDateString(),
            'review_comment' => $changed ? 'Análise técnica: ampliar o quantitativo do primeiro produto e substituir o último serviço após inspeção do trecho. Prazo ajustado para mobilização da equipe.'
                : 'Análise técnica concluída. Produtos e quantidades coerentes com a necessidade; execução compatível com o prazo estimado.'];
        $this->transition($context, $order, 'review', 'concremat', $analysis);
        if (in_array($scenario, ['concremat_saved', 'inspector'], true)) return $order->refresh();
        if (in_array($scenario, ['returned_concremat', 'returned_analyst', 'approved_after_concremat', 'approved_after_analyst'], true)) {
            $toAnalyst = in_array($scenario, ['returned_analyst', 'approved_after_analyst'], true);
            $this->transition($context, $order, 'decide', 'contract_inspector', ['decision' => 'return', 'return_to' => $toAnalyst ? 'analyst' : 'concremat',
                'comment' => $toAnalyst ? 'Esclarecer a necessidade e a prioridade do serviço solicitado. Consultar as observações antes de reenviar.' : 'Reavaliar o quantitativo e o prazo da análise para compatibilizar a mobilização das equipes.']);
            if (str_starts_with($scenario, 'returned_')) return $order->refresh();
            if ($toAnalyst) $this->transition($context, $order, 'submit', 'analyst');
            $this->transition($context, $order, 'review', 'concremat', [...$analysis, 'mode' => 'forward', 'review_comment' => 'Reanálise concluída após devolução. Mantido o pedido original; esclarecimentos e ajustes técnicos registrados nesta análise.']);
        }
        $this->transition($context, $order, 'decide', 'contract_inspector', ['decision' => 'approve', 'comment' => 'Serviço autorizado conforme parecer técnico e quantitativos da Análise Concremat. Acompanhar a execução e registrar as evidências.']);
        $original = $order->items()->get()->sortBy('product_id')->map(fn ($item) => [(int)$item->product_id, number_format((float)$item->quantity, 3, '.', '')])->values()->all();
        $expected = collect($data['items'])->sortBy('product_id')->map(fn ($item) => [(int)$item['product_id'], number_format((float)$item['quantity'], 3, '.', '')])->values()->all();
        if ($original !== $expected) {
            throw new RuntimeException('Divergência no pedido original: '.json_encode(['scenario' => $scenario, 'actual' => $original, 'expected' => $expected]));
        }
        return $order->refresh();
    }

    private function transition(array $context, OsDnitOrder $order, string $action, string $role, array $data = []): void
    {
        $order->refresh();
        $this->call(OsDnitWorkflowController::class, $action, $context['actors'][$role], ['version' => $order->workflow_version, ...$data], [$order]);
        $order->refresh();
    }

    private function activity(array $context, int $index): void
    {
        $order = $context['orders'][$index * 2];
        $isChecklist = in_array($index, [0, 1, 3, 6, 8, 11], true);
        $status = ['todo', 'in_progress', 'review', 'done'][$index % 4];
        $actor = $context['actors']['analyst'];
        $data = ['contract_id' => $context['contract']->id, 'assigned_to_ids' => [$actor->id, $context['actors']['concremat']->id],
            'title' => ($isChecklist ? 'Checklist' : 'Atividade').' · '.['Vistoriar trecho', 'Revisar quantitativos', 'Consolidar relatório', 'Validar entrega'][$index % 4].' · '.$context['contract']->code.' · '.($index + 1),
            'description' => 'Acompanhar os serviços do trecho, conferir dados técnicos e registrar pendências. Registro fictício para explorar o módulo de Atividades.',
            'activity_type' => $isChecklist ? 'checklist' : 'activity',
            'checklist_items' => $isChecklist ? ['Conferir dados e documentos', 'Verificar quantitativos de campo', 'Registrar evidências', 'Consolidar conclusão'] : [],
            'category' => $index < 8 ? 'os_dnit' : ['field', 'measurement', 'documentation', 'project'][$index % 4],
            'os_dnit_order_id' => $index < 8 ? $order->id : null, 'visibility' => $index % 3 === 0 ? 'restricted' : 'public',
            'priority' => ['low', 'normal', 'high', 'urgent'][$index % 4], 'due_date' => now()->addDays($index % 2 ? 35 : 5)->toDateString()];
        $this->call(ActivityController::class, 'store', $actor, $data);
        $activity = Activity::where('contract_id', $context['contract']->id)->latest('id')->firstOrFail();
        if ($status !== 'todo') $this->call(ActivityController::class, 'update', $actor, ['status' => $status], [$activity]);
        foreach ($activity->checklistItems()->take($status === 'done' ? 4 : ($status === 'review' ? 3 : 1))->get() as $item) {
            $this->call(ActivityController::class, 'updateChecklistItem', $context['actors']['concremat'], ['is_completed' => true], [$activity, $item]);
        }
        $this->call(ActivityController::class, 'storeComment', $context['actors']['concremat'], ['body' => 'Registro de acompanhamento: conferir o levantamento e consolidar os quantitativos antes do fechamento. Comentário de demonstração.'], [$activity]);
    }

    private function verifyIsolation(): void
    {
        foreach ($this->contexts as $i => $context) {
            $other = $this->contexts[($i + 1) % count($this->contexts)];
            $contract = $context['contract'];
            $analyst = $context['actors']['analyst'];
            if (OsDnitContractAccess::contracts($analyst, $this->tenant)->pluck('id')->all() !== [$contract->id]) throw new RuntimeException('Falha no isolamento contratual.');
            $this->checks[] = $contract->code.': escopo restrito ao próprio contrato';
            $draft = $context['orders'][0];
            $sent = $context['orders'][1];
            $demand = $this->demand($context, 0);
            $this->reject('Produto de outro contrato', fn () => $this->call(OsDnitOrderController::class, 'store', $analyst,
                [...$demand, 'items' => [['product_id' => $other['products'][0]->id, 'quantity' => 1]]]), 'items.0.product_id');
            $this->reject('Rodovia de outro contrato', fn () => $this->call(OsDnitOrderController::class, 'store', $analyst,
                [...$demand, 'highway_id' => $other['highways'][0]->id]), 'highway_id');
            $this->reject('Acesso à OS de outro contrato', fn () => $this->call(OsDnitOrderController::class, 'show', $analyst, [], [$other['orders'][0]], 'GET'), 404);
            $this->reject('Edição após envio', fn () => $this->call(OsDnitOrderController::class, 'update', $analyst,
                [...$demand, 'version' => $sent->workflow_version], [$sent]), 403);
            $this->reject('Envio por leitor', fn () => $this->call(OsDnitWorkflowController::class, 'submit', $context['actors']['viewer'], ['version' => $draft->workflow_version], [$draft]), 403);
            $this->reject('Versão desatualizada', fn () => $this->call(OsDnitWorkflowController::class, 'review', $context['actors']['concremat'], ['version' => 1], [$sent]), 'version');
            $this->reject('Análise sem comentário', fn () => $this->call(OsDnitWorkflowController::class, 'review', $context['actors']['concremat'],
                ['version' => $sent->workflow_version, 'mode' => 'forward', 'review_comment' => '', 'estimated_deadline' => now()->addDays(5)->toDateString(), 'items' => $demand['items']], [$sent]), 'review_comment');
            $decision = $context['orders'][3];
            $this->reject('Aprovação sem comentário', fn () => $this->call(OsDnitWorkflowController::class, 'decide', $context['actors']['contract_inspector'],
                ['version' => $decision->workflow_version, 'decision' => 'approve'], [$decision]), 'comment');
            $this->reject('Atividade vinculada à OS de outro contrato', fn () => $this->call(ActivityController::class, 'store', $analyst,
                ['contract_id' => $contract->id, 'title' => 'Referência incompatível', 'priority' => 'normal', 'category' => 'os_dnit', 'os_dnit_order_id' => $other['orders'][0]->id]), 'os_dnit_order_id');
        }
        $expected = ['draft' => 12, 'awaiting_concremat' => 24, 'awaiting_contract' => 12, 'returned_concremat' => 12, 'returned_analyst' => 12, 'approved' => 48];
        $actual = OsDnitOrder::where('tenant_id', $this->tenant->id)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($count) => (int)$count)->all();
        ksort($expected); ksort($actual);
        if ($actual !== $expected) throw new RuntimeException('A distribuição final de OSs divergiu dos cenários planejados.');
        $this->checks[] = '120 OSs nas etapas previstas; 48 aprovações com pedido original preservado';
    }

    private function reject(string $label, callable $operation, string|int $expected): void
    {
        try { $operation(); }
        catch (ValidationException $exception) {
            if (is_string($expected) && array_key_exists($expected, $exception->errors())) { $this->checks[] = $label.': bloqueado'; return; }
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === $expected) { $this->checks[] = $label.': bloqueado'; return; }
            throw $exception;
        }
        throw new RuntimeException($label.': a operação deveria ter sido bloqueada.');
    }

    private function call(string $controller, string $method, User $actor, array $data, array $models = [], string $verb = 'POST')
    {
        $request = Request::create(rtrim(config('app.url'), '/').'/t/'.$this->tenant->slug.'/sandbox', $verb, $data);
        $request->headers->set('referer', route('tenant.os-dnit.orders.index', $this->tenant->slug));
        $request->setLaravelSession($this->session);
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('tenant', $this->tenant);
        $request->setRouteResolver(fn () => (new Route([$verb], 'sandbox', fn () => null))->name('sandbox'));
        app()->instance('request', $request);
        app('url')->setRequest($request);
        Auth::setUser($actor);
        $response = app($controller)->$method($request, $this->tenant, ...$models);
        $this->actions++;
        return $response;
    }

    private function report(int $mailCount): array
    {
        return ['generated_at' => now()->toIso8601String(), 'synthetic_dates' => true, 'tenant_slug' => $this->tenant->slug,
            'contracts' => collect($this->contexts)->map(fn ($context) => ['code' => $context['contract']->code, 'name' => $context['contract']->name])->all(),
            'users_created' => count($this->accounts), 'products' => OsDnitProduct::where('tenant_id', $this->tenant->id)->count(),
            'highways' => OsDnitHighway::where('tenant_id', $this->tenant->id)->count(),
            'orders' => OsDnitOrder::where('tenant_id', $this->tenant->id)->count(),
            'order_statuses' => OsDnitOrder::where('tenant_id', $this->tenant->id)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(),
            'order_events' => OsDnitOrderEvent::whereHas('order', fn ($query) => $query->where('tenant_id', $this->tenant->id))->count(),
            'activities' => Activity::where('tenant_id', $this->tenant->id)->count(),
            'activity_statuses' => Activity::where('tenant_id', $this->tenant->id)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(),
            'controller_actions' => $this->actions, 'mail_messages_in_memory' => $mailCount, 'external_emails' => 0, 'checks' => $this->checks];
    }
}
