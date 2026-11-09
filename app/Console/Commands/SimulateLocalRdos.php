<?php

namespace App\Console\Commands;

use App\Http\Controllers\Tenant\RdoController;
use App\Http\Controllers\Api\Mobile\MobileRdoController;
use App\Models\{Tenant, User, Empresa, TipoEmpresa, RdoConfiguracao, RdoDiario, RdoResponsavel, RdoMaoObraCadastro, RdoEquipamentoCadastro, RdoSubcontratadaCadastro, RdoPdfSetting};
use App\Services\{RdoDailyGenerator, RdoPdfPreview, RdoSignatureService};
use App\Support\{RdoWorkflow, DiarioObraPermissions};
use Carbon\CarbonImmutable;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Event, Http, Storage};
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Str;

class SimulateLocalRdos extends Command
{
    protected $signature = 'rdo:simulate-local {--slug=rdo-homologacao-20261028} {--days=60} {--resume}';
    protected $description = 'Creates a persistent local-only RDO homologation tenant and exercises application controllers.';
    private array $checks = [];
    private Tenant $tenant;
    private array $actors = [];
    private int $webSaves = 0;
    private int $mobileSaves = 0;
    private int $returns = 0;
    private string $imagePath;

    public function handle(): int
    {
        $db = DB::connection()->getConfig();
        $this->line(json_encode(['environment' => app()->environment(), 'driver' => $db['driver'], 'host' => $db['host'] ?? null, 'database' => $db['database']]));
        abort_unless(app()->environment('local') && $db['driver'] === 'pgsql' && ($db['host'] ?? '') === 'host.docker.internal', 403, 'Local database only.');
        $slug = (string) $this->option('slug');
        abort_if(Tenant::where('slug', $slug)->exists() && !$this->option('resume'), 422, 'Tenant already exists; use a new slug to preserve all prior data.');
        $days = max(1, min(62, (int) $this->option('days')));
        $end = CarbonImmutable::today('America/Sao_Paulo');
        $start = $end->subDays($days - 1);
        config(['signatures.driver' => 'local', 'session.driver' => 'array']);
        Http::preventStrayRequests();
        Event::listen(NotificationSending::class, fn ($event) => $event->channel === 'mail' ? false : null);
        $this->tenant = Tenant::firstOrCreate(['slug' => $slug], [ 'name' => 'Homologação RDO - 3 modelos / 60 dias', 'status' => 'active', 'plan' => 'enterprise']);
        foreach (User::where('is_platform_admin', true)->get() as $admin) {
            $this->tenant->memberships()->firstOrCreate(['user_id' => $admin->id], [ 'role' => 'tenant_owner', 'status' => 'active']);
        }
        foreach (['owner' => 'Eduardo Almeida', 'construtora' => 'Rafael Costa', 'gerenciadora' => 'Camila Ferreira', 'cliente' => 'Mariana Oliveira'] as $role => $name) {
            $user = User::firstOrCreate(['email' => $role.'.'.$slug.'@example.test'], ['name' => $name, 'password' => Str::random(48)]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->actors[$role] = $user;
            $this->tenant->memberships()->firstOrCreate(['user_id' => $user->id], [ 'role' => $role === 'owner' ? 'tenant_owner' : 'engenheiro_campo', 'status' => 'active', 'diario_obra_permissions' => DiarioObraPermissions::all()]);
        }
        $uri = app(RdoPdfPreview::class)->photo();
        $this->imagePath = storage_path('app/private/'.$slug.'-ilustracao.png');
        file_put_contents($this->imagePath, base64_decode(explode(',', $uri, 2)[1]));
        $summaries = [];
        try {
            foreach (array_keys(RdoWorkflow::MODELS) as $index => $model) {
                $this->info('Simulating '.$model);
                [$contract, $fronts, $config, $labor, $equipment, $subcontractor] = $this->createContractScenario($index, $model, $start, $end);
                $created = 0;
                for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                    $this->clock($date->setTime(7, 30));
                    $rdo = app(RdoDailyGenerator::class)->generateForConfiguration($config->fresh(), $date, true, $this->actors['owner']->id);
                    $rdo ??= $config->diarios()->whereDate('reference_date', $date->format('Y-m-d'))->first();
                    if (!$rdo) continue;
                    if ($rdo->status === 'arquivado') { $created++; continue; }
                    $created++;
                    $this->check('generation_idempotent', app(RdoDailyGenerator::class)->generateForConfiguration($config, $date, true) === null);
                    $party = RdoWorkflow::fillParty($rdo);
                    if (in_array($rdo->status, RdoWorkflow::FILL_STATUSES, true)) foreach ($fronts as $frontIndex => $front) {
                        $sections = $this->sections($date, $frontIndex, $party, $labor, $equipment, $subcontractor);
                        $mobile = ($created + $frontIndex) % 2 === 0;
                        $this->fill($rdo->fresh(), $front, $party, $sections, $mobile, true);
                    }
                    $rdo->refresh();
                    if (in_array($rdo->status, RdoWorkflow::FILL_STATUSES, true)) $this->flow($rdo, $party, 'submit');
                    if (!RdoWorkflow::savesWithoutApproval($rdo)) {
                        if ($created % 11 === 0) {
                            $review = RdoWorkflow::reviews($rdo)[0];
                            $this->flow($rdo, $review, 'return', 'Complementar o registro da compactação e identificar o trecho executado.');
                            $this->returns++;
                            $this->fill($rdo->fresh(), $fronts[0], $party, $this->sections($date, 0, $party, $labor, $equipment, $subcontractor), true, false);
                            $this->flow($rdo, $party, 'submit', 'Registro complementado com identificação do trecho e conferência de campo.');
                        }
                        foreach (RdoWorkflow::reviews($rdo) as $review) {
                            if (RdoWorkflow::activeParty($rdo->fresh()) !== $review) continue;
                            if ($review === 'gerenciadora') foreach ($fronts as $front) {
                                $request = $this->request($this->actors[$review], ['obra_id' => $front->id, 'resumo_fiscalizacao' => 'Conferidos alinhamento, cotas e condições de segurança. Equipe e equipamentos compatíveis com o registro.', 'servicos_atestados' => 'Serviços de drenagem e regularização da plataforma acompanhados em campo.', 'orientacoes' => 'Manter sinalização e conferir compactação antes da próxima camada.', 'status' => 'concluido'], ['evidencias' => [$this->upload()]]);
                                app(RdoController::class)->saveInspection($request, $this->tenant, $rdo->fresh());
                            }
                            $this->flow($rdo, $review, 'approve', $review === 'cliente' ? 'Registro e parecer conferidos. De acordo com os serviços e quantitativos informados.' : 'Inspeção concluída. Registro compatível com as atividades acompanhadas nas frentes.');
                        }
                    }
                    $this->check('archived_'.$model, $rdo->fresh()->status === 'arquivado');
                    if ($created % 15 === 0) $this->line($model.': '.$created.' dias concluídos');
                }
                $this->clock($end->setTime(18, 0));
                $last = $config->diarios()->where('status', 'arquivado')->latest('reference_date')->firstOrFail();
                $signature = $last->signatureRequests()->whereIn('status', ['sent', 'pending', 'completed'])->latest('id')->first() ?? app(RdoSignatureService::class)->createAndSend($this->tenant, $last, $this->actors['owner']);
                $this->check('signature_parties_'.$model, $signature->signers->pluck('role')->sort()->values()->all() === collect(RdoWorkflow::parties($last))->sort()->values()->all());
                // Leave accessible pending examples too, without altering completed histories.
                $this->leavePending($config, $fronts, $party, $labor, $equipment, $subcontractor, $end, $index);
                $summaries[] = ['contract_id' => $contract->id, 'code' => $contract->code, 'model' => $model, 'generated_days' => $created, 'rdos' => $config->diarios()->count(), 'signature_request_id' => $signature->id, 'status' => $config->diarios()->select('status')->get()->countBy('status')->all()];
            }
            $this->clock($end->setTime(18, 30));
            $this->testDeadlineAndMobileConflicts($summaries, $end);
            $result = ['tenant_id' => $this->tenant->id, 'slug' => $slug, 'period' => [$start->format('Y-m-d'), $end->format('Y-m-d')], 'contracts' => $summaries, 'web_saves' => $this->webSaves, 'mobile_saves' => $this->mobileSaves, 'returns' => $this->returns, 'checks' => $this->checks, 'limitations' => ['Mobile controllers and offline protocol exercised; no physical phone or emulator.', 'Signature provider local; no external emails or legally signed PDFs.', 'Illustrative construction images; realistic synthetic people and data.', 'Fixed national holidays only; municipal/state calendars are not configured.']];
            Storage::disk('local')->put($slug.'/resultado.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
        } finally {
            Carbon::setTestNow(); CarbonImmutable::setTestNow();
        }
        return self::SUCCESS;
    }

    private function createContractScenario(int $index, string $model, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($existing = $this->tenant->contracts()->where('code', 'HML-RDO-00'.($index + 1).'/2026')->first()) {
            return [$existing, $existing->obras()->orderBy('id')->get()->all(), RdoConfiguracao::where('contract_id', $existing->id)->firstOrFail(), RdoMaoObraCadastro::where('contract_id', $existing->id)->firstOrFail(), RdoEquipamentoCadastro::where('contract_id', $existing->id)->firstOrFail(), RdoSubcontratadaCadastro::where('contract_id', $existing->id)->firstOrFail()];
        }
        $cities = [['Belém', 'PA', 'Duplicação e drenagem da BR-316'], ['Recife', 'PE', 'Supervisão de obras de mobilidade urbana'], ['Fortaleza', 'CE', 'Inspeção e acompanhamento de infraestrutura']];
        [$city, $state, $name] = $cities[$index];
        $contract = $this->tenant->contracts()->create(['code' => 'HML-RDO-00'.($index + 1).'/2026', 'name' => $name, 'status' => 'active', 'city' => $city, 'state' => $state, 'total_value' => [28500000, 6800000, 2450000][$index], 'starts_at' => $start->subMonths(2), 'ends_at' => $end->addMonths(8)]);
        foreach (['cliente' => ['Departamento de Infraestrutura Urbana', 'DIU'], 'gerenciadora' => ['Almeida & Ferreira Engenharia', 'AFE'], 'construtora' => ['Costa Infraestrutura Ltda.', 'CIL']] as $role => [$name, $sigla]) {
            $type = TipoEmpresa::firstOrCreate(['nome' => $role]);
            $company = Empresa::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'tipo_empresa_id' => $type->id, 'nome' => $name, 'sigla' => $sigla, 'cnpj' => sprintf('90.%03d.%03d/0001-00', $this->tenant->id, $index * 3 + array_search($role, ['cliente', 'gerenciadora', 'construtora']))]);
            $contract->update([($role === 'gerenciadora' ? 'fiscalizadora' : $role).'_empresa_id' => $company->id]);
            $this->tenant->memberships()->where('user_id', $this->actors[$role]->id)->update(['empresa_id' => $company->id]);
        }
        $fronts = [];
        foreach (['Drenagem e dispositivos de concreto', 'Terraplenagem e pavimentação'] as $i => $name) {
            $front = $this->tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => ($index + 1).'0'.($i + 1), 'nome' => $name, 'tipo' => 'pai']);
            $fronts[] = $front;
            foreach (RdoWorkflow::parties(new RdoDiario(['workflow_snapshot' => RdoWorkflow::forModel($model)])) as $role) RdoResponsavel::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'obra_id' => $front->id, 'user_id' => $this->actors[$role]->id, 'modulo' => 'rdo', 'etapa' => $role, 'status' => 'active']);
        }
        $labor = RdoMaoObraCadastro::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'descricao' => 'Pedreiro de obras de infraestrutura', 'tipo' => 'direta', 'unidade' => 'pessoa', 'active' => true]);
        $equipment = RdoEquipamentoCadastro::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'codigo' => 'EQ-001', 'descricao' => 'Escavadeira hidráulica 20 t', 'propriedade' => 'locado', 'unidade' => 'un', 'active' => true]);
        $subcontractor = RdoSubcontratadaCadastro::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'razao_social' => 'Pavimentação Nordeste Serviços Ltda.', 'cnpj' => '98.765.432/0001-00', 'responsavel' => 'Bruno Martins', 'active' => true]);
        $config = RdoConfiguracao::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'obra_id' => $fronts[0]->id, 'created_by_id' => $this->actors['owner']->id, 'responsible_user_id' => $this->actors[$index === 0 ? 'construtora' : 'gerenciadora']->id, 'workflow' => RdoWorkflow::forModel($model), 'start_date' => $start, 'end_date' => $end->addDays(7), 'generation_time' => '00:05', 'timezone' => 'America/Sao_Paulo', 'generation_weekdays' => $index === 0 ? [0,1,2,3,4,5,6] : [1,2,3,4,5], 'generate_on_holidays' => $index === 0, 'copy_previous_day' => $index !== 2, 'copy_workforce' => true, 'copy_equipment' => $index === 0, 'copy_pending_activities' => true, 'require_photos' => true, 'digital_signature_enabled' => true, 'submission_deadline_days' => [2,5,7][$index], 'active' => true]);
        $config->obras()->attach(collect($fronts)->pluck('id'));
        RdoPdfSetting::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'configuration' => ['layout' => 'standard', 'title' => 'Relatório Diário de Obra', 'color' => ['#23466b','#25715d','#526175'][$index], 'title_color' => ['#23466b','#722d58','#2b3646'][$index]]]);
        return [$contract, $fronts, $config, $labor, $equipment, $subcontractor];
    }

    private function sections($date, $front, $party, $labor, $equipment, $subcontractor): array
    {
        $rain = $date->day % 6 === 0;
        return ['clima' => ['manha' => $rain ? 'nublado' : 'ensolarado', 'tarde' => $rain ? 'chuvoso' : 'nublado', 'noite' => 'nublado', 'precipitacao_manha_mm' => 0, 'precipitacao_tarde_mm' => $rain ? 12.5 : 0, 'observacoes' => $rain ? 'Chuva no período da tarde. Serviços externos interrompidos às 15h40; mantida a sinalização.' : 'Condições favoráveis à execução e inspeção dos serviços.'],
            'mao_obra' => ['efetivos' => [$labor->id => 6 + $date->day % 4], 'subcontratadas' => [$subcontractor->id => 3]],
            'equipamentos' => ['registros' => [$equipment->id => ['quantidade' => 1, 'situacao' => 'operando']]],
            'atividades' => ['atividades' => [['titulo' => $front === 0 ? 'Execução de drenagem longitudinal' : 'Regularização e compactação da plataforma', 'ocorrencia' => $front === 0 ? 'Escavação de 24 m de vala, assentamento de tubos de concreto DN 600 e recomposição em camadas. Conferidos alinhamento e declividade.' : 'Regularização de 320 m² e compactação em camadas de 20 cm. Controle topográfico e inspeção visual da umidade.', 'status' => 'pendente']]],
            'comentarios' => [$party => 'DDS realizado às 07h00. Equipe orientada quanto ao isolamento das frentes, uso de EPIs e circulação de equipamentos. Sem acidente registrado.'], 'fotos' => []];
    }

    private function fill($rdo, $front, $party, $sections, bool $mobile, bool $photo): void
    {
        $user = $this->actors[$party];
        if (!$photo) $sections['fotos'] = $rdo->secoes()->where('obra_id', $front->id)->where('filling_party', $party)->where('secao', 'fotos')->first()?->dados ?? [];
        $files = $photo ? ['fotos' => [$this->upload()]] : [];
        if ($mobile) {
            $payload = ['local_uuid' => Str::uuid()->toString(), 'rdo_diario_id' => $rdo->id, 'obra_id' => $front->id, 'reference_date' => $rdo->reference_date->format('Y-m-d'), 'filling_party' => $party, 'revision' => MobileRdoController::revision($rdo, $front->id, $party), 'secoes' => $sections];
            $request = $this->request($user, $payload, $files); $request->attributes->set('mobile_tenant', $this->tenant);
            app(MobileRdoController::class)->storeOffline($request); $this->mobileSaves++;
        } else {
            app(RdoController::class)->saveAllSections($this->request($user, ['obra_id' => $front->id, 'filling_party' => $party, 'secoes' => $sections], $files), $this->tenant, $rdo); $this->webSaves++;
        }
    }

    private function flow($rdo, $party, $action, $comment = ''): void
    {
        $rdo->refresh();
        $comments = $rdo->configuracao->obras->mapWithKeys(fn ($front) => [$front->id => $comment])->all();
        app(RdoController::class)->changeFlow($this->request($this->actors[$party], ['action' => $action, 'filling_party' => RdoWorkflow::fillParty($rdo), 'comment' => $comment, 'comments_by_obra' => $comments]), $this->tenant, $rdo);
    }

    private function request(User $user, array $payload, array $files = []): Request
    {
        Auth::setUser($user);
        $request = Request::create('/local-rdo-simulation', 'POST', $payload, [], $files, ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session')->driver());
        app()->instance('request', $request);
        return $request;
    }
    private function upload(): UploadedFile { return new UploadedFile($this->imagePath, 'registro-ilustrativo-de-campo.png', 'image/png', null, true); }
    private function clock($date): void { Carbon::setTestNow($date); CarbonImmutable::setTestNow($date); }
    private function check(string $key, bool $ok): void { if (!$ok) throw new \RuntimeException('Simulation check failed: '.$key); $this->checks[$key] = ($this->checks[$key] ?? 0) + 1; }

    private function leavePending($config, $fronts, $party, $labor, $equipment, $subcontractor, $end, $index): void
    {
        // Future reference dates are explicitly labeled by their calendar date, for manual exploration.
        for ($i = 1; $i <= 3; $i++) {
            $date = $end->addDays($i);
            $rdo = app(RdoDailyGenerator::class)->generateForConfiguration($config, $date, false, $this->actors['owner']->id);
            if (!$rdo || $index === 2 || $i === 1) continue;
            foreach ($fronts as $f => $front) $this->fill($rdo, $front, $party, $this->sections($date, $f, $party, $labor, $equipment, $subcontractor), true, true);
            $this->flow($rdo, $party, 'submit');
        }
    }

    private function testDeadlineAndMobileConflicts(array $summaries, $end): void
    {
        $rdo = RdoDiario::where('contract_id', $summaries[0]['contract_id'])->where('status', 'rascunho')->firstOrFail();
        $front = $rdo->configuracao->obras->first();
        $party = RdoWorkflow::fillParty($rdo);
        $user = $this->actors[$party];
        $payload = ['local_uuid' => Str::uuid()->toString(), 'rdo_diario_id' => $rdo->id, 'obra_id' => $front->id, 'reference_date' => $rdo->reference_date->format('Y-m-d'), 'filling_party' => $party, 'revision' => MobileRdoController::revision($rdo, $front->id, $party), 'secoes' => array_fill_keys(['clima','mao_obra','equipamentos','atividades','fotos','comentarios'], [])];
        $request = $this->request($user, $payload); $request->attributes->set('mobile_tenant', $this->tenant);
        app(MobileRdoController::class)->storeOffline($request);
        $request = $this->request($user, $payload); $request->attributes->set('mobile_tenant', $this->tenant);
        app(MobileRdoController::class)->storeOffline($request);
        $this->check('mobile_idempotency', DB::table('rdo_mobile_syncs')->where('local_uuid', $payload['local_uuid'])->count() === 1);
        try {
            $request = $this->request($user, [...$payload, 'local_uuid' => Str::uuid()->toString()]); $request->attributes->set('mobile_tenant', $this->tenant);
            app(MobileRdoController::class)->storeOffline($request);
            $this->check('mobile_conflict', false);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->check('mobile_conflict', $e->getStatusCode() === 409); }
        $this->clock($rdo->reference_date->toImmutable()->addDays(4)->setTime(12,0));
        try {
            app(RdoController::class)->saveSection($this->request($user, ['obra_id' => $front->id, 'filling_party' => $party, 'dados' => ['manha' => 'ensolarado']]), $this->tenant, $rdo->fresh(), 'clima');
            $this->check('expired_deadline', false);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->check('expired_deadline', $e->getStatusCode() === 422); }
        app(RdoController::class)->reopen($this->request($this->actors['owner'], []), $this->tenant, $rdo->fresh());
        app(RdoController::class)->saveSection($this->request($user, ['obra_id' => $front->id, 'filling_party' => $party, 'dados' => ['manha' => 'ensolarado']]), $this->tenant, $rdo->fresh(), 'clima');
        $this->check('reopened_deadline', true);
        $this->clock($end->setTime(18,30));
    }
}
