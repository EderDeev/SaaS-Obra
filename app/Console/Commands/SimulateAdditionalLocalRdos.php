<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Mobile\MobileRdoController;
use App\Http\Controllers\Tenant\RdoController;
use App\Models\{Tenant, User, Empresa, TipoEmpresa, RdoConfiguracao, RdoDiario, RdoResponsavel, RdoMaoObraCadastro, RdoEquipamentoCadastro, RdoSubcontratadaCadastro, RdoPdfSetting};
use App\Services\{RdoDailyGenerator, RdoPdfPreview, RdoPdfRenderer};
use App\Support\{RdoWorkflow, RdoPdfTemplates};
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Console\Command;
use Illuminate\Http\{Request, UploadedFile};
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\{Auth, DB, Event, Http, Storage};
use Illuminate\Support\Str;

class SimulateAdditionalLocalRdos extends Command
{
    protected $signature = 'rdo:simulate-additional-local {--resume}';
    protected $description = 'Adds exactly two 90-day scenarios to the existing local homologation tenant.';
    private Tenant $tenant;
    private array $actors = [];
    private string $image;
    private array $counts = ['web_saves' => 0, 'mobile_saves' => 0, 'returns' => 0, 'reservations' => 0];

    public function handle(): int
    {
        $db = DB::connection()->getConfig();
        $this->line(json_encode(['environment' => app()->environment(), 'driver' => $db['driver'], 'host' => $db['host'] ?? null, 'database' => $db['database']]));
        abort_unless(app()->environment('local') && $db['driver'] === 'pgsql' && $db['database'] === 'deming' && ($db['host'] ?? '') === 'host.docker.internal', 403, 'Only the authorized local database is allowed.');
        $this->tenant = Tenant::where('slug', 'rdo-homologacao-20261028')->firstOrFail();
        abort_unless((int) $this->tenant->id === 126, 403);
        $codes = ['HML-RDO-004/2026', 'HML-RDO-005/2026'];
        abort_if($this->tenant->contracts()->whereIn('code', $codes)->exists() && !$this->option('resume'), 422, 'Scenarios already exist; use --resume.');
        foreach (['owner', 'construtora', 'gerenciadora', 'cliente'] as $role) $this->actors[$role] = User::where('email', $role.'.'.$this->tenant->slug.'@example.test')->firstOrFail();
        $this->tenant->contracts()->whereIn('code', $codes)->get()->each(fn ($contract) => RdoConfiguracao::where('contract_id', $contract->id)->update(['active' => false]));
        $before = $this->preservationSnapshot();
        config(['queue.default' => 'sync', 'mail.default' => 'array', 'session.driver' => 'array']);
        Http::preventStrayRequests();
        Event::listen(NotificationSending::class, fn ($event) => $event->channel === 'mail' ? false : null);
        $this->image = storage_path('app/private/rdo-additional-illustration.png');
        file_put_contents($this->image, base64_decode(explode(',', app(RdoPdfPreview::class)->photo(), 2)[1]));
        $start = CarbonImmutable::parse('2026-08-02', 'America/Sao_Paulo');
        $end = CarbonImmutable::parse('2026-10-30', 'America/Sao_Paulo');
        $report = ['tenant_id' => $this->tenant->id, 'slug' => $this->tenant->slug, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'contracts' => []];
        try {
            foreach (['gerenciadora_construtora', 'gerenciadora_cliente'] as $index => $model) {
                [$contract, $config, $fronts, $labor, $equipment, $subcontractor] = $this->setup($index, $model, $codes[$index], $start, $end);
                for ($n = 1; $n <= 90; $n++) {
                    $date = $start->addDays($n - 1);
                    Carbon::setTestNow($date->setTime(16, 30)); CarbonImmutable::setTestNow($date->setTime(16, 30));
                    $rdo = $this->generateIsolated($config, $date)
                        ?? $config->diarios()->whereDate('reference_date', $date->toDateString())->firstOrFail();
                    if ($rdo->status === 'arquivado' || ($n === 87 && $rdo->status === 'devolvido_construtora') || ($n === 88 && $rdo->status === 'pendente_comprovacao')) continue;
                    $party = RdoWorkflow::fillParty($rdo);
                    if (in_array($rdo->status, RdoWorkflow::FILL_STATUSES, true)) {
                        foreach ($fronts as $f => $front) {
                            if ($n === 90 && $f === 1) continue;
                            $sections = $this->sections($date, $n, $f, $party, $labor, $equipment, $subcontractor);
                            $photo = !$date->isSunday() && $n % 9 !== 0;
                            $files = $photo ? ['fotos' => [$this->upload()]] : [];
                            $holiday = in_array($date->toDateString(), ['2026-09-07', '2026-10-12'], true);
                            if (($n + $f) % 2 === 0) {
                                $request = $this->request($this->actors[$party], ['local_uuid' => Str::uuid()->toString(), 'rdo_diario_id' => $rdo->id, 'obra_id' => $front->id, 'reference_date' => $date->toDateString(), 'is_holiday' => $holiday, 'filling_party' => $party, 'revision' => MobileRdoController::revision($rdo->fresh(), $front->id, $party), 'secoes' => $sections], $files);
                                $request->attributes->set('mobile_tenant', $this->tenant);
                                app(MobileRdoController::class)->storeOffline($request); $this->counts['mobile_saves']++;
                            } else {
                                app(RdoController::class)->saveAllSections($this->request($this->actors[$party], ['obra_id' => $front->id, 'is_holiday' => $holiday, 'filling_party' => $party, 'secoes' => $sections], $files), $this->tenant, $rdo->fresh());
                                $this->counts['web_saves']++;
                            }
                        }
                    }
                    if (in_array($n, [85, 90], true)) continue;
                    if (in_array($rdo->fresh()->status, RdoWorkflow::FILL_STATUSES, true)) $this->flow($rdo, $party, 'submit', 'Registros das duas obras conferidos e encaminhados para análise.');
                    if ($n === 86) continue;
                    $review = RdoWorkflow::reviews($rdo)[0];
                    if ($review === 'gerenciadora') foreach ($fronts as $front) {
                        app(RdoController::class)->saveInspection($this->request($this->actors[$review], ['obra_id' => $front->id, 'atividades' => [['titulo' => 'Conferência dos serviços executados', 'ocorrencia' => 'Verificados alinhamento e cotas da frente '.$front->codigo.'. Equipe orientada sobre proteção das escavações e liberação do trecho.']], 'comentario' => $date->isSunday() ? 'Frente sem expediente. Acessos e sinalização preservados.' : 'Acompanhamento realizado por Camila Ferreira. Serviços compatíveis com o registro da construtora.', 'clima' => ['manha' => 'ensolarado', 'tarde' => $n % 6 === 0 ? 'chuvoso' : 'nublado', 'precipitacao_tarde_mm' => $n % 6 === 0 ? 8.5 : 0], 'status' => 'concluido'], $n % 9 === 0 ? [] : ['evidencias' => [$this->upload()]]), $this->tenant, $rdo->fresh());
                    }
                    if ($n === 89) continue;
                    if ($n === 87) {
                        $this->flow($rdo, $review, 'return', 'Complementar a identificação do trecho e a conferência dos serviços da segunda obra.', ['return_to' => $party]);
                        $this->counts['returns']++;
                    } elseif ($n === 88) {
                        $this->flow($rdo, $review, 'approve_with_reservations', 'Registro conferido com ressalva sobre a documentação fotográfica da frente.', ['reservation_obra_ids' => [$fronts[0]->id], 'reservations_by_obra' => [$fronts[0]->id => ['obligation' => 'Complementar o registro fotográfico do trecho executado e identificar a estaca.', 'responsible' => $party === 'construtora' ? 'Construtora' : 'Gerenciadora', 'due_date' => $end->addDays(3)->toDateString()]]]);
                        $this->counts['reservations']++;
                    } else $this->flow($rdo, $review, 'approve', $review === 'cliente' ? 'Registros de engenharia, segurança e meio ambiente conferidos. De acordo com o acompanhamento realizado.' : 'Verificação das duas obras concluída. Quantidades e condições de execução compatíveis com o registro.');
                    if ($n % 15 === 0) $this->line($contract->code.': '.$n.' dias processados');
                }
                $all = $config->diarios()->orderBy('reference_date')->get();
                abort_unless($all->count() === 90 && $all->pluck('reference_date')->map->toDateString()->unique()->count() === 90, 500, 'Expected exactly 90 distinct diary dates.');
                $lastApproved = $all->where('status', 'arquivado')->last();
                $pdf = app(RdoPdfRenderer::class)->render($this->tenant, $lastApproved);
                file_put_contents(storage_path('app/private/rdo-90-days-'.($index + 4).'.pdf'), $pdf->output());
                $report['contracts'][] = ['id' => $contract->id, 'code' => $contract->code, 'name' => $contract->name, 'workflow' => $model, 'layout' => $index ? 'concremat_diary' : 'standard', 'fronts' => collect($fronts)->map(fn ($f) => ['id' => $f->id, 'code' => $f->codigo, 'name' => $f->nome])->all(), 'rdos' => $all->count(), 'statuses' => $all->countBy('status')->all(), 'first_rdo_id' => $all->first()->id, 'last_rdo_id' => $all->last()->id, 'approved_sample_id' => $lastApproved->id];
            }
            foreach ($before as $table => $snapshot) {
                $after = DB::table($table)->whereIn('id', $snapshot['ids'])->orderBy('id')->get()->toJson();
                abort_unless(hash('sha256', $after) === $snapshot['hash'], 500, 'Previous records changed: '.$table);
            }
            RdoConfiguracao::where('tenant_id', $this->tenant->id)->whereIn('contract_id', collect($report['contracts'])->pluck('id'))->update(['active' => true]);
            $report += ['previous_records_preserved' => true, 'counts' => $this->counts, 'email_delivery' => 'suppressed', 'signatures' => 'Approved records await signatures; no real signature emails were sent.', 'images' => 'Illustrative images, not field evidence.'];
            file_put_contents(storage_path('app/private/rdo-additional-90-days-report.json'), json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } finally {
            Carbon::setTestNow(); CarbonImmutable::setTestNow();
        }
        return self::SUCCESS;
    }

    private function setup($index, $model, $code, $start, $end): array
    {
        if ($contract = $this->tenant->contracts()->where('code', $code)->first()) return [$contract, RdoConfiguracao::where('contract_id', $contract->id)->firstOrFail(), $contract->obras()->orderBy('id')->get()->all(), RdoMaoObraCadastro::where('contract_id', $contract->id)->get(), RdoEquipamentoCadastro::where('contract_id', $contract->id)->get(), RdoSubcontratadaCadastro::where('contract_id', $contract->id)->firstOrFail()];
        return DB::transaction(function () use ($index, $model, $code, $start, $end) {
            $contract = $this->tenant->contracts()->create(['code' => $code, 'name' => $index ? 'Gerenciamento das rampas e passarelas da Ponta da Espera' : 'Adequação viária e drenagem dos acessos da BR-135', 'description' => $index ? 'Gerenciamento e assistência técnica das obras de alargamento das rampas e duplicação das passarelas do Terminal da Ponta da Espera, em São Luís – MA.' : 'Execução e supervisão dos serviços de drenagem, terraplenagem e pavimentação dos acessos viários da BR-135, em São Luís – MA.', 'city' => 'São Luís', 'state' => 'MA', 'starts_at' => '2026-06-11', 'ends_at' => '2027-05-11', 'status' => 'active', 'total_value' => $index ? 7200000 : 18400000]);
            foreach (RdoWorkflow::parties(new RdoDiario(['workflow_snapshot' => RdoWorkflow::forModel($model)])) as $role) {
                $names = ['cliente' => ['Empresa Maranhense de Administração Portuária', 'EMAP'], 'gerenciadora' => ['Concremat Engenharia e Tecnologia', 'CONCREMAT'], 'construtora' => ['Costa Infraestrutura Ltda.', 'CIL']];
                $company = Empresa::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => $role])->id, 'nome' => $names[$role][0], 'sigla' => $names[$role][1], 'cnpj' => sprintf('93.%03d.%03d/0001-00', $this->tenant->id, ($index + 4) * 3 + array_search($role, ['cliente', 'gerenciadora', 'construtora']))]);
                if ($role === 'gerenciadora') {
                    $path = 'rdo-homologacao-90dias/concremat.png';
                    Storage::disk('public')->put($path, file_get_contents(public_path('brand/concremat-logo.png')));
                    $company->update(['logo_path' => $path]);
                }
                $contract->update([($role === 'gerenciadora' ? 'fiscalizadora' : $role).'_empresa_id' => $company->id]);
            }
            $fronts = [];
            foreach ($index ? ['Alargamento das rampas de embarque', 'Duplicação e adequação das passarelas'] : ['Drenagem e dispositivos de concreto', 'Terraplenagem e pavimentação dos acessos'] as $i => $name) {
                $front = $this->tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => ($index + 4).'0'.($i + 1), 'nome' => $name, 'tipo' => 'pai']); $fronts[] = $front;
                foreach (RdoWorkflow::parties(new RdoDiario(['workflow_snapshot' => RdoWorkflow::forModel($model)])) as $role) RdoResponsavel::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'obra_id' => $front->id, 'user_id' => $this->actors[$role]->id, 'modulo' => 'rdo', 'etapa' => $role, 'status' => 'active']);
            }
            $labor = collect(['Engenheiro civil', 'Técnico de segurança do trabalho', 'Técnico de meio ambiente', 'Topógrafo', 'Pedreiro de obras de infraestrutura'])->map(fn ($name) => RdoMaoObraCadastro::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'descricao' => $name, 'tipo' => $name === 'Pedreiro de obras de infraestrutura' ? 'direta' : 'indireta', 'unidade' => 'pessoa', 'active' => true]));
            $equipment = collect(['Caminhonete 4 x 4', 'Escavadeira hidráulica 20 t', 'Estação total'])->map(fn ($name, $i) => RdoEquipamentoCadastro::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'codigo' => 'EQ-00'.($i + 1), 'descricao' => $name, 'propriedade' => 'locado', 'unidade' => 'un', 'active' => true]));
            $subcontractor = RdoSubcontratadaCadastro::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'razao_social' => 'Maranhão Serviços de Infraestrutura Ltda.', 'cnpj' => '98.765.432/0001-00', 'responsavel' => 'Bruno Martins', 'active' => true]);
            $config = RdoConfiguracao::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'obra_id' => $fronts[0]->id, 'created_by_id' => $this->actors['owner']->id, 'responsible_user_id' => $this->actors[$index ? 'gerenciadora' : 'construtora']->id, 'workflow' => RdoWorkflow::forModel($model), 'start_date' => $start, 'end_date' => $end, 'timezone' => 'America/Sao_Paulo', 'generation_weekdays' => [0,1,2,3,4,5,6], 'copy_previous_day' => true, 'copy_workforce' => true, 'copy_equipment' => true, 'copy_pending_activities' => false, 'require_photos' => true, 'digital_signature_enabled' => true, 'submission_deadline_days' => 7, 'active' => false]);
            $config->obras()->attach(collect($fronts)->pluck('id'));
            RdoPdfSetting::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'configuration' => RdoPdfTemplates::configuration(['layout' => $index ? 'concremat_diary' : 'standard', 'title' => $index ? 'Diário de Obra' : 'Relatório Diário de Obra', 'color' => $index ? '#2f75b5' : '#23466b', 'title_color' => $index ? '#ffffff' : '#23466b'])]);
            return [$contract, $config, $fronts, $labor, $equipment, $subcontractor];
        });
    }

    private function generateIsolated(RdoConfiguracao $config, CarbonImmutable $date): ?RdoDiario
    {
        return DB::transaction(function () use ($config, $date) {
            $config->refresh()->update(['active' => true]);
            $rdo = app(RdoDailyGenerator::class)->generateForConfiguration($config->fresh(), $date, true, $this->actors['owner']->id);
            $config->update(['active' => false]);
            return $rdo;
        }, 3);
    }

    private function sections($date, $n, $front, $party, $labor, $equipment, $subcontractor): array
    {
        $off = $date->isSunday(); $rain = $n % 6 === 0;
        return ['clima' => ['manha' => $rain ? 'nublado' : 'ensolarado', 'tarde' => $rain ? 'chuvoso' : 'ensolarado', 'noite' => 'nao_aplicavel', 'precipitacao_tarde_mm' => $rain ? 12.5 : 0, 'observacoes' => $rain ? 'Chuva durante a tarde. Serviços externos interrompidos às 15h40, mantendo isolamento e proteção dos materiais.' : 'Condições favoráveis aos serviços previstos no planejamento diário.'],
            'mao_obra' => ['efetivos' => $off ? [] : $labor->mapWithKeys(fn ($item, $i) => [$item->id => $i === 4 ? 6 + $n % 3 : 1])->all(), 'subcontratadas' => $off ? [] : [$subcontractor->id => 3]],
            'equipamentos' => ['registros' => $off ? [] : $equipment->mapWithKeys(fn ($item) => [$item->id => ['quantidade' => 1, 'situacao' => $rain && str_contains($item->descricao, 'Escavadeira') ? 'parado' : 'operando']])->all()],
            'atividades' => ['atividades' => $off ? [['assunto' => 'engenharia', 'titulo' => 'Sem atividade programada', 'ocorrencia' => 'Domingo sem expediente na obra. Frentes protegidas, sinalização mantida e equipamentos estacionados em área delimitada.']] : [
                ['assunto' => 'engenharia', 'titulo' => $front ? 'Conferência das formas, armação e concretagem' : 'Acompanhamento dos serviços de drenagem e fundação', 'ocorrencia' => 'Trecho entre as estacas '.(100 + $n).' e '.(102 + $n).'. Acompanhados '.(18 + $n % 9).' m de serviços e conferidas cotas, alinhamento e condições de execução. Registro elaborado por '.($party === 'gerenciadora' ? 'Camila Ferreira' : 'Rafael Costa').'.'],
                ['assunto' => 'seguranca_trabalho', 'titulo' => 'Inspeção das condições de segurança', 'ocorrencia' => 'DDS às 07h00, verificação de EPIs, isolamento das escavações e controle da circulação de pessoas na área de movimentação de cargas. Sem acidentes registrados.'],
                ['assunto' => 'meio_ambiente', 'titulo' => 'Controle ambiental da frente', 'ocorrencia' => 'Verificadas segregação dos resíduos, proteção dos pontos de drenagem e organização das áreas de apoio. Materiais excedentes encaminhados ao local autorizado.'],
            ]], 'comentarios' => [$party => $off ? 'Sem expediente na obra. Não foram mobilizados efetivos ou equipamentos.' : 'Equipe orientada e serviços acompanhados conforme o planejamento. Pendências de acabamento relacionadas para conferência na próxima jornada.'], 'fotos' => []];
    }

    private function flow($rdo, $role, $action, $comment, array $extra = []): void
    {
        $comments = $rdo->configuracao->obras->mapWithKeys(fn ($front) => [$front->id => $comment])->all();
        app(RdoController::class)->changeFlow($this->request($this->actors[$role], ['action' => $action, 'comment' => $comment, 'filling_party' => RdoWorkflow::fillParty($rdo), 'comments_by_obra' => $comments, ...$extra]), $this->tenant, $rdo->fresh());
    }

    private function request(User $user, array $payload, array $files = []): Request
    {
        Auth::setUser($user);
        $request = Request::create('/local-additional-rdo-simulation', 'POST', $payload, [], $files, ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $user); $request->setLaravelSession(app('session')->driver()); app()->instance('request', $request);
        return $request;
    }

    private function upload(): UploadedFile { return new UploadedFile($this->image, 'registro-ilustrativo-de-campo.png', 'image/png', null, true); }

    private function preservationSnapshot(): array
    {
        $result = [];
        foreach (['contracts', 'obras', 'tenant_users', 'rdo_configuracoes', 'rdo_pdf_settings', 'rdo_responsaveis', 'rdo_diarios', 'rdo_secao_registros', 'rdo_analises', 'rdo_versoes', 'rdo_fiscalizacao_registros', 'rdo_mao_obra_cadastros', 'rdo_equipamento_cadastros', 'rdo_subcontratada_cadastros', 'empresas'] as $table) {
            $query = DB::table($table)->where('tenant_id', $this->tenant->id);
            $newContracts = $this->tenant->contracts()->whereIn('code', ['HML-RDO-004/2026', 'HML-RDO-005/2026'])->pluck('id');
            if ($table === 'contracts') $query->whereNotIn('id', $newContracts);
            elseif (\Illuminate\Support\Facades\Schema::hasColumn($table, 'contract_id')) $query->whereNotIn('contract_id', $newContracts);
            elseif (\Illuminate\Support\Facades\Schema::hasColumn($table, 'rdo_diario_id')) $query->whereNotIn('rdo_diario_id', RdoDiario::whereIn('contract_id', $newContracts)->pluck('id'));
            $rows = $query->orderBy('id')->get();
            $result[$table] = ['ids' => $rows->pluck('id')->all(), 'hash' => hash('sha256', $rows->toJson())];
        }
        return $result;
    }
}
