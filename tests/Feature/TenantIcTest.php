<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\InstrucaoCampo;
use App\Models\InstrucaoCampoResponsavel;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Notifications\IcFlowNotification;
use App\Support\IcPermissions as P;
use App\Support\RncPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantIcTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Contract $contract;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->tenant = Tenant::create(['name' => 'IC testes', 'slug' => 'ic-testes', 'status' => 'active', 'plan' => 'starter']);
        $this->owner = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $this->owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->contract = $this->tenant->contracts()->create(['code' => 'IC-CT1', 'name' => 'Contrato IC', 'status' => 'active']);
        $obra = $this->tenant->obras()->create(['contract_id' => $this->contract->id, 'codigo' => 'IC-OB1', 'nome' => 'Obra IC', 'tipo' => 'pai']);
        $disciplina = $this->tenant->disciplinas()->create(['contract_id' => $this->contract->id, 'nome' => 'Qualidade', 'sigla' => 'QUA', 'cor' => '#2563eb']);
        $company = $this->tenant->empresas()->create(['contract_id' => $this->contract->id, 'nome' => 'Empresa IC', 'sigla' => 'IC', 'cnpj' => '11.111.111/0001-11', 'tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id]);
        $this->payload = ['contract_id' => $this->contract->id, 'obra_id' => $obra->id, 'disciplina_id' => $disciplina->id,
            'contratante_empresa_id' => $company->id, 'contratada_empresa_id' => $company->id, 'opened_at' => '2026-10-22',
            'assunto' => 'Acabamento do concreto', 'gravidade' => 'Leve', 'instrucao' => 'Corrigir o acabamento do concreto.', 'latitude' => 0, 'longitude' => 0];
        $this->actingAs($this->owner);
    }

    private function url(string $action, ?InstrucaoCampo $ic = null): string
    {
        return route('tenant.qualidade.ic.'.$action, $ic ? [$this->tenant, $ic] : $this->tenant);
    }

    private function draft(): InstrucaoCampo
    {
        $this->post($this->url('store'), $this->payload)->assertSessionHasNoErrors()->assertRedirect();

        return InstrucaoCampo::latest('id')->firstOrFail();
    }

    private function responsible(string $type): User
    {
        $u = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $u->id, 'role' => 'team_member', 'status' => 'active']);
        $this->contract->participants()->create(['tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'side' => 'contractor', 'role' => 'team_member', 'status' => 'active']);
        InstrucaoCampoResponsavel::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'user_id' => $u->id, 'status' => 'active', 'responsibility_type' => $type, 'permissions' => P::permissionsForResponsibility($type)]);

        return $u;
    }

    private function people(): array
    {
        return [$this->responsible('operational'), $this->responsible('contractor'), $this->responsible('monitoring')];
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ict').'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        return new UploadedFile($path, 'evidencia.png', 'image/png', null, true);
    }

    public function test_subject_is_saved_updated_and_searchable_independently_of_the_instruction(): void
    {
        $ic = $this->draft();
        $this->assertSame($this->payload['assunto'], $ic->assunto);
        $this->patch($this->url('update', $ic), array_replace($this->payload, ['assunto' => 'Junta de dilatação']))->assertSessionHasNoErrors();
        $this->get(route('tenant.qualidade.ic.index', [$this->tenant, 'q' => 'dilatação']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('ics.total', 1)->where('ics.data.0.assunto', 'Junta de dilatação'));
        $this->post($this->url('store'), array_replace($this->payload, ['assunto' => str_repeat('x', 256)]))->assertSessionHasErrors('assunto');
    }

    public function test_supervision_evidence_screen_supports_private_documents_and_images(): void
    {
        [$engineer, $contractor, $client] = $this->people();
        $ic = $this->draft();
        $this->actingAs($engineer)->get($this->url('evidence.create', $ic))->assertStatus(409);
        $this->post($this->url('notify', $ic))->assertSessionHasNoErrors();
        foreach ([$contractor, $client] as $user) {
            $this->actingAs($user)->get($this->url('evidence.create', $ic))->assertForbidden();
            $this->get($this->url('show', $ic))->assertOk();
        }
        $this->actingAs($engineer)->get($this->url('evidence.create', $ic))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Tenant/Qualidade/InstrucaoCampo/Evidence')->where('ic.assunto', $ic->assunto)->where('resolutionOptions', InstrucaoCampo::RESOLUTION_RESULTS));
        $this->post($this->url('finalize', $ic), ['resolution_result' => 'partial', 'comment' => 'Reparo parcialmente concluído.', 'attachments' => [
            UploadedFile::fake()->createWithContent('plano.pdf', "%PDF-1.4\nPlano de reparo"),
            UploadedFile::fake()->create('memorial.docx', 8, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            $this->photo(),
        ]])->assertSessionHasNoErrors()->assertRedirect($this->url('show', $ic));
        $event = $ic->events()->where('type', 'finalized')->sole();
        $this->assertSame(3, $ic->attachments()->where('event_id', $event->id)->count());
        $this->assertSame('closed', $ic->fresh()->status);
        $pdf = $ic->attachments()->where('original_name', 'plano.pdf')->sole();
        Storage::disk('local')->assertExists($pdf->path);
        $download = route('tenant.qualidade.ic.attachment', [$this->tenant, $ic, $pdf]);
        $this->get($download)->assertOk()->assertDownload('plano.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($this->url('show', $ic))->assertOk()->assertInertia(fn (Assert $p) => $p->has('ic.attachments', 3)->missing('ic.attachments.0.path')->where('ic.resolution_result', 'partial'));
        $this->get($this->url('evidence.create', $ic))->assertStatus(409);
        $this->post($this->url('finalize', $ic), ['resolution_result' => 'yes', 'attachments' => [$this->photo()]])->assertStatus(409);
        $this->assertSame('partial', $ic->fresh()->resolution_result);
        $this->assertSame(1, $ic->events()->where('type', 'finalized')->count());
        $this->actingAs($this->owner);
        $another = $this->draft();
        $this->get(route('tenant.qualidade.ic.attachment', [$this->tenant, $another, $pdf]))->assertNotFound();
        $unrelated = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $unrelated->id, 'role' => 'team_member', 'status' => 'active']);
        $this->actingAs($unrelated)->get($download)->assertForbidden();
    }

    public function test_evidence_photos_keep_their_order_and_captions_alongside_documents(): void
    {
        [$engineer] = $this->people();
        $ic = $this->draft();
        $this->actingAs($engineer)->post($this->url('notify', $ic))->assertSessionHasNoErrors();
        $this->post($this->url('finalize', $ic), [
            'resolution_result' => 'yes',
            'photos' => [$this->photo(), $this->photo()],
            'photo_comments' => ['Vista geral da correção.', 'Detalhe do acabamento.'],
            'photo_positions' => [2, 1],
            'attachments' => [UploadedFile::fake()->createWithContent('relatorio.pdf', "%PDF-1.4\nRelatório de campo")],
        ])->assertSessionHasNoErrors()->assertRedirect($this->url('show', $ic));

        $event = $ic->events()->where('type', 'finalized')->sole();
        $photos = $ic->photos()->where('event_id', $event->id)->get();
        $this->assertSame(['Detalhe do acabamento.', 'Vista geral da correção.'], $photos->pluck('comment')->all());
        $this->assertSame([1, 2], $photos->pluck('position')->all());
        foreach ($photos as $photo) {
            Storage::disk('local')->assertExists($photo->path);
        }
        $this->assertSame(1, $ic->attachments()->where('event_id', $event->id)->count());
        $this->get($this->url('show', $ic))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('ic.photos', 2)->where('ic.photos.0.comment', 'Detalhe do acabamento.')
            ->has('ic.attachments', 1)->where('ic.resolution_result', 'yes'));
    }

    public function test_finalization_requires_valid_evidence_and_an_explicit_questionnaire_answer(): void
    {
        [$engineer] = $this->people();
        $ic = $this->draft();
        $this->actingAs($engineer)->post($this->url('notify', $ic))->assertSessionHasNoErrors();
        foreach ([
            ['attachments' => [$this->photo()]],
            ['resolution_result' => 'maybe', 'attachments' => [$this->photo()]],
            ['resolution_result' => 'yes', 'attachments' => []],
            ['resolution_result' => 'no', 'attachments' => [UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;')]],
            ['resolution_result' => 'partial', 'attachments' => [UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf')]],
        ] as $payload) {
            $this->post($this->url('finalize', $ic), $payload)->assertSessionHasErrors();
        }
        $this->assertSame('notified', $ic->fresh()->status);
        $this->assertNull($ic->fresh()->resolution_result);
        $this->assertSame(0, $ic->attachments()->count());
        $this->assertSame(0, $ic->events()->where('type', 'finalized')->count());
    }

    public function test_evidence_modal_loads_authorized_data_and_returns_to_the_filtered_list_after_finalization(): void
    {
        [$engineer, $contractor] = $this->people();
        $ic = $this->draft();
        $this->actingAs($engineer)->post($this->url('notify', $ic))->assertSessionHasNoErrors();
        $this->getJson($this->url('evidence.create', $ic))->assertOk()->assertJsonPath('ic.id', $ic->id)
            ->assertJsonPath('ic.instrucao', $ic->instrucao)->assertJsonPath('resolutionOptions.partial', 'Parcialmente')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($contractor)->getJson($this->url('evidence.create', $ic))->assertForbidden();
        $parent = route('tenant.qualidade.ic.index', [$this->tenant, 'status' => 'notified', 'q' => 'concreto']);
        $this->actingAs($engineer)->from($parent)->post($this->url('finalize', $ic), [
            'from_modal' => true, 'resolution_result' => 'yes', 'attachments' => [$this->photo()],
        ])->assertSessionHasNoErrors()->assertRedirect($parent);
        $this->assertSame('closed', $ic->fresh()->status);
        $this->getJson($this->url('evidence.create', $ic))->assertStatus(409);
        $this->from($parent)->post($this->url('finalize', $ic), [
            'from_modal' => true, 'resolution_result' => 'no', 'attachments' => [$this->photo()],
        ])->assertSessionHasErrors('ic')->assertRedirect($parent);
        $this->assertSame('yes', $ic->fresh()->resolution_result);
        $this->assertSame(1, $ic->events()->where('type', 'finalized')->count());
    }

    public function test_creation_has_only_instruction_and_automatic_numbers_that_are_not_reused_after_soft_delete(): void
    {
        $ic = $this->draft();
        $this->assertSame('001/2026', $ic->formatted_number);
        $this->assertSame('draft', $ic->status);
        $this->assertSame('created', $ic->events()->first()->type);
        $this->assertDatabaseMissing('relatorio_nao_conformidades', ['tenant_id' => $this->tenant->id]);
        $this->delete($this->url('destroy', $ic))->assertRedirect();
        $this->assertSoftDeleted($ic);
        $second = $this->draft();
        $this->assertSame('002/2026', $second->formatted_number);
        $this->payload['opened_at'] = '2027-01-01';
        $this->assertSame('001/2027', $this->draft()->formatted_number);
        $this->get($this->url('create'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Tenant/Qualidade/InstrucaoCampo/Create')->has('obras', 1));
        foreach (['assunto' => ' ', 'instrucao' => ' ', 'project_document_id' => 9, 'prazo_resposta_acao_corretiva' => '2026-11-01'] as $key => $value) {
            $this->post($this->url('store'), array_replace($this->payload, [$key => $value]))->assertSessionHasErrors($key);
        }
    }

    public function test_supervision_finalizes_each_questionnaire_outcome_directly_and_notifies_all_parties(): void
    {
        [$engineer, $contractor, $client] = $this->people();
        foreach (InstrucaoCampo::RESOLUTION_RESULTS as $answer => $label) {
            $this->actingAs($this->owner)->post($this->url('store'), $this->payload + ['photos' => [$this->photo()]])->assertSessionHasNoErrors();
            $ic = InstrucaoCampo::latest('id')->firstOrFail();
            $this->actingAs($engineer)->post($this->url('notify', $ic))->assertSessionHasNoErrors();
            foreach ([$contractor, $client] as $user) {
                $this->actingAs($user)->post($this->url('finalize', $ic), ['resolution_result' => $answer, 'photos' => [$this->photo()]])->assertForbidden();
            }
            $this->actingAs($engineer)->get($this->url('evidence.create', $ic))->assertOk()->assertInertia(fn (Assert $p) => $p->has('ic.photos', 1));
            $this->post($this->url('finalize', $ic), ['resolution_result' => $answer, 'photos' => [$this->photo()]])->assertSessionHasNoErrors()->assertRedirect($this->url('show', $ic));
            $this->assertSame('closed', $ic->fresh()->status);
            $this->assertSame($answer, $ic->fresh()->resolution_result);
            $this->assertSame($engineer->id, $ic->fresh()->closed_by_id);
            $this->assertNotNull($ic->fresh()->closed_at);
            $event = $ic->events()->where('type', 'finalized')->sole();
            $this->assertSame(1, $ic->photos()->where('event_id', $event->id)->count());
            $this->assertSame(['created', 'notified', 'finalized'], $ic->events()->pluck('type')->all());
            foreach ([$engineer, $contractor, $client] as $user) {
                Notification::assertSentTo($user, IcFlowNotification::class, fn ($n) => $n->event === 'finalized' && $n->ic->id === $ic->id);
            }
            $notification = new IcFlowNotification($ic->fresh(), $engineer, 'finalized');
            $this->assertStringContainsString($label, $notification->toArray($client)['body']);
        }
        $this->assertDatabaseCount('relatorio_nao_conformidades', 0);
    }

    public function test_communication_requires_active_responsibles_and_cannot_be_repeated(): void
    {
        $ic = $this->draft();
        $this->people();
        $r = InstrucaoCampoResponsavel::where('responsibility_type', 'monitoring')->first();
        $this->contract->participants()->where('user_id', $r->user_id)->update(['status' => 'inactive']);
        $this->post($this->url('notify', $ic))->assertSessionHasErrors('responsaveis');
        $this->assertSame('draft', $ic->fresh()->status);
        Notification::assertNothingSent();
        $this->contract->participants()->where('user_id', $r->user_id)->update(['status' => 'active']);
        $this->post($this->url('notify', $ic))->assertSessionHasNoErrors();
        $this->post($this->url('notify', $ic))->assertStatus(409);
        $this->assertSame(1, $ic->events()->where('type', 'notified')->count());
    }

    public function test_legacy_proposal_and_analysis_endpoints_cannot_restore_the_old_workflow(): void
    {
        [$engineer, $contractor] = $this->people();
        $ic = $this->draft();
        $this->actingAs($engineer)->post($this->url('notify', $ic))->assertSessionHasNoErrors();
        $this->actingAs($contractor)->post($this->url('correction', $ic), ['comment' => 'Proposta'])->assertForbidden();
        $this->get($this->url('correction.create', $ic))->assertForbidden();
        $this->actingAs($engineer)->post($this->url('close', $ic), ['comment' => 'Análise antiga', 'decision' => 'resolved'])->assertSessionHasErrors('resolution_result');
        $this->assertSame('notified', $ic->fresh()->status);
        $this->post($this->url('finalize', $ic), ['resolution_result' => 'no', 'attachments' => [$this->photo()]])->assertSessionHasNoErrors();
        $this->assertSame('closed', $ic->fresh()->status);
        $this->assertSame('no', $ic->fresh()->resolution_result);
        $this->assertDatabaseCount('relatorio_nao_conformidades', 0);
        $this->assertSame(0, $ic->events()->whereIn('type', ['correction_submitted', 'returned', 'rnc_required'])->count());
    }

    public function test_only_drafts_can_be_edited_deleted_and_photos_removed(): void
    {
        $this->people();
        $ic = $this->draft();
        $this->patch($this->url('update', $ic), $this->payload + ['photos' => [$this->photo()]])->assertSessionHasNoErrors();
        $photo = $ic->photos()->first();
        Storage::disk('local')->assertExists($photo->path);
        $this->get(route('tenant.qualidade.ic.photo', [$this->tenant, $ic, $photo]))->assertOk();
        $this->patch($this->url('update', $ic), $this->payload + ['remove_photos' => [99999]])->assertSessionHasErrors('remove_photos');
        $this->patch($this->url('update', $ic), $this->payload + ['remove_photos' => [$photo->id]])->assertSessionHasNoErrors();
        $this->assertSame(0, $ic->photos()->count());
        $this->post($this->url('notify', $ic));
        $this->get($this->url('edit', $ic))->assertStatus(409);
        $this->patch($this->url('update', $ic), $this->payload)->assertStatus(409);
        $this->delete($this->url('destroy', $ic))->assertStatus(409);
        $this->assertNotNull(InstrucaoCampo::find($ic->id));
    }

    public function test_contract_and_tenant_scoping_applies_to_options_records_photos_and_mutations(): void
    {
        $ic = $this->draft();
        $user = $this->responsible('operational');
        $other = $this->tenant->contracts()->create(['code' => 'CT2', 'name' => 'Privado', 'status' => 'active']);
        $ic2 = InstrucaoCampo::create(array_replace($ic->getAttributes(), ['id' => null, 'contract_id' => $other->id, 'sequence_number' => 2]));
        $this->actingAs($user)->get($this->url('index'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('ics.total', 1)->has('contracts', 1)->where('counts.all', 1));
        $this->get($this->url('show', $ic2))->assertForbidden();
        $this->post($this->url('notify', $ic2))->assertForbidden();
        $this->post($this->url('store'), array_replace($this->payload, ['contract_id' => $other->id]))->assertSessionHasErrors('contract_id');
        $wrongObra = $this->tenant->obras()->create(['contract_id' => $other->id, 'nome' => 'Outra', 'codigo' => 'OUTRA', 'tipo' => 'pai']);
        $this->post($this->url('store'), array_replace($this->payload, ['obra_id' => $wrongObra->id]))->assertSessionHasErrors('obra_id');
        $this->contract->participants()->where('user_id', $user->id)->update(['status' => 'inactive']);
        $this->get($this->url('index'))->assertForbidden();
        $this->get($this->url('show', $ic))->assertForbidden();
        $tenant2 = Tenant::create(['name' => 'Outro tenant', 'slug' => 'outro-ic', 'plan' => 'starter', 'status' => 'active']);
        $tenant2->memberships()->create(['user_id' => $this->owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->actingAs($this->owner)->get(route('tenant.qualidade.ic.show', [$tenant2, $ic]))->assertNotFound();
    }

    public function test_dynamic_filters_pagination_and_counters(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->draft();
        }
        $ic = InstrucaoCampo::first();
        $ic->update(['status' => 'closed']);
        $this->get($this->url('index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('ics.data', 10)->where('ics.total', 13)->where('counts.all', 13)->where('counts.closed', 1));
        $this->get($this->url('index').'?page=2')->assertInertia(fn (Assert $p) => $p->has('ics.data', 3));
        $this->get($this->url('index').'?status=closed&q=concreto')->assertInertia(fn (Assert $p) => $p->where('ics.total', 1)->where('counts.all', 13));
        $this->get($this->url('index').'?q='.urlencode('IC 001/2026'))->assertInertia(fn (Assert $p) => $p->where('ics.total', 1)->where('ics.data.0.id', $ic->id));
    }

    public function test_photographic_register_preserves_comments_and_order_when_existing_and_new_photos_are_mixed(): void
    {
        $this->post($this->url('store'), $this->payload + ['photos' => [$this->photo(), $this->photo()],
            'photo_comments' => ['Antes', 'Detalhe'], 'photo_positions' => [2, 1]])->assertSessionHasNoErrors();
        $ic = InstrucaoCampo::first();
        $original = $ic->photos()->get();
        $this->assertSame(['Detalhe', 'Antes'], $original->pluck('comment')->all());
        $this->patch($this->url('update', $ic), $this->payload + ['photos' => [$this->photo()],
            'photo_comments' => ['Nova foto no meio'], 'photo_positions' => [2],
            'existing_photos' => [
                ['id' => $original[0]->id, 'comment' => 'Detalhe revisado', 'position' => 3],
                ['id' => $original[1]->id, 'comment' => 'Agora primeira', 'position' => 1],
            ]])->assertSessionHasNoErrors();
        $photos = $ic->photos()->get();
        $this->assertSame(['Agora primeira', 'Nova foto no meio', 'Detalhe revisado'], $photos->pluck('comment')->all());
        $this->assertSame([1, 2, 3], $photos->pluck('position')->all());
        $this->patch($this->url('update', $ic), $this->payload + ['remove_photos' => [$photos[1]->id],
            'existing_photos' => [
                ['id' => $photos[2]->id, 'comment' => 'Primeira após remoção', 'position' => 1],
                ['id' => $photos[0]->id, 'comment' => 'Segunda após remoção', 'position' => 2],
            ]])->assertSessionHasNoErrors();
        $this->assertSame(['Primeira após remoção', 'Segunda após remoção'], $ic->photos()->pluck('comment')->all());
        $other = $this->draft();
        $this->patch($this->url('update', $other), $this->payload + ['existing_photos' => [
            ['id' => $photos[0]->id, 'comment' => 'Não permitido', 'position' => 1],
        ]])->assertSessionHasErrors('photos');
        $this->assertSame('Segunda após remoção', $photos[0]->fresh()->comment);
        $this->patch($this->url('update', $ic), $this->payload + ['photos' => [$this->photo()], 'photo_positions' => [1]])->assertSessionHasErrors('photos');
        $this->assertSame(2, $ic->photos()->count());
    }

    public function test_private_photos_cannot_be_read_through_another_ic_or_contract(): void
    {
        $this->post($this->url('store'), $this->payload + ['photos' => [$this->photo()]])->assertSessionHasNoErrors();
        $ic = InstrucaoCampo::first();
        $photo = $ic->photos()->first();
        $another = $this->draft();
        $this->get(route('tenant.qualidade.ic.photo', [$this->tenant, $another, $photo]))->assertNotFound();
        $user = $this->responsible('monitoring');
        $this->contract->participants()->where('user_id', $user->id)->update(['status' => 'inactive']);
        $this->actingAs($user)->get(route('tenant.qualidade.ic.photo', [$this->tenant, $ic, $photo]))->assertForbidden();
    }

    public function test_user_permissions_can_be_granted_preserved_and_revoked_without_granting_rnc(): void
    {
        $u = $this->responsible('monitoring');
        $membership = $this->tenant->memberships()->where('user_id', $u->id)->first();
        $payload = ['name' => $u->name, 'email' => $u->email, 'empresa_id' => $this->payload['contratada_empresa_id'], 'role' => 'engineer',
            'contract_accesses' => [['contract_id' => $this->contract->id, 'side' => 'manager', 'role' => 'team_member', 'ic_permissions' => [P::VIEW, P::CREATE]]]];
        $this->patch(route('tenant.users.update', [$this->tenant, $membership]), $payload)->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([P::VIEW, P::CREATE], P::permissionsFor($u, $this->tenant, $this->contract));
        $this->assertDatabaseCount('relatorio_nao_conformidade_responsaveis', 0);
        unset($payload['contract_accesses'][0]['ic_permissions']);
        $this->patch(route('tenant.users.update', [$this->tenant, $membership]), $payload)->assertSessionHasNoErrors();
        $this->assertContains(P::CREATE, P::permissionsFor($u, $this->tenant, $this->contract));
        $payload['contract_accesses'][0]['ic_permissions'] = [];
        $this->patch(route('tenant.users.update', [$this->tenant, $membership]), $payload)->assertSessionHasNoErrors();
        $this->assertSame([], P::permissionsFor($u, $this->tenant, $this->contract));
    }

    public function test_notifications_enqueue_both_channels_and_mail_links_to_the_correct_tenant(): void
    {
        $people = $this->people();
        $ic = $this->draft();
        Notification::swap(new ChannelManager($this->app));
        Queue::fake();
        $this->post($this->url('notify', $ic))->assertSessionHasNoErrors();
        Queue::assertPushed(SendQueuedNotifications::class, 6);
        Queue::assertPushed(SendQueuedNotifications::class, function ($job) {
            $this->assertSame('database', $job->connection);
            $this->assertSame('default', $job->queue);
            $this->assertFalse($job->afterCommit);

            return true;
        });
        $notification = new IcFlowNotification($ic->fresh(), $this->owner, 'notified');
        $this->assertSame(['database', 'mail'], $notification->via($people[0]));
        $this->assertSame($this->url('show', $ic), $notification->toMail($people[0])->actionUrl);
        $this->assertStringContainsString('001/2026', $notification->toMail($people[0])->subject);
        $this->assertTrue($notification->shouldSend($people[0], 'mail'));
        $this->contract->participants()->where('user_id', $people[0]->id)->update(['status' => 'inactive']);
        $this->assertFalse($notification->shouldSend($people[0], 'mail'));
    }

    public function test_ic_is_a_valid_login_destination_for_users_who_only_have_access_to_this_module(): void
    {
        $u = $this->responsible('monitoring');
        $fields = array_fill_keys(['activity_permissions', 'contract_permissions', 'budget_permissions', 'user_permissions',
            'documentation_permissions', 'diario_obra_permissions', 'ordem_servico_permissions', 'medicao_permissions', 'project_permissions', 'navigation_permissions'], []);
        $this->tenant->memberships()->where('user_id', $u->id)->first()->update($fields);
        $this->contract->participants()->where('user_id', $u->id)->first()->update(array_intersect_key($fields, array_flip([
            'activity_permissions', 'contract_permissions', 'documentation_permissions', 'diario_obra_permissions', 'ordem_servico_permissions', 'medicao_permissions', 'project_permissions',
        ])) + ['os_dnit_permissions' => []]);
        $this->actingAs($u)->get(route('dashboard'))->assertRedirect($this->url('index'));
    }

    public function test_dashboard_counts_filters_months_and_options_follow_the_authorized_scope(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));
        foreach ([['draft', null], ['notified', null], ['closed', 'yes'], ['closed', 'partial'], ['closed', 'no']] as [$status, $answer]) {
            $this->draft()->update(['status' => $status, 'resolution_result' => $answer]);
        }
        $old = $this->draft();
        $old->update(['opened_at' => '2025-01-01']);
        $deleted = $this->draft();
        $deleted->delete();
        $other = $this->tenant->contracts()->create(['code' => 'PRIVATE', 'name' => 'Outro contrato', 'status' => 'active']);
        $ic = InstrucaoCampo::create(array_replace($old->getAttributes(), ['id' => null, 'contract_id' => $other->id, 'sequence_number' => 100, 'opened_at' => '2026-10-22', 'status' => 'closed', 'resolution_result' => 'no']));
        $this->get($this->url('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Tenant/Qualidade/InstrucaoCampo/Dashboard')->where('counts.all', 7)->where('counts.draft', 2)
            ->where('counts.resolved_no', 2)->missing('recent')->has('contracts', 2)->has('months', 6)->has('obras', 1)->has('disciplinas', 1)
            ->where('months', fn ($months) => collect($months)->sum('total') === 6)
            ->where('byContract.0.total', 6));
        $this->get($this->url('dashboard').'?contract_id='.$this->contract->id)->assertInertia(fn (Assert $p) => $p
            ->where('counts.all', 6)->where('counts.resolved_no', 1)->where('months', fn ($months) => collect($months)->sum('total') === 5)
            ->has('byContract', 1));
        $u = $this->responsible('monitoring');
        $this->actingAs($u)->get($this->url('dashboard'))->assertForbidden();
        InstrucaoCampoResponsavel::where('user_id', $u->id)->first()->update(['permissions' => [P::DASHBOARD]]);
        $this->get($this->url('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('counts.all', 6)->has('contracts', 1)->where('icPermissions.can.dashboard_ic', true)
            ->where('icPermissions.can.view_ic', false)->missing('recent')->has('obras', 1)->has('disciplinas', 1));
        $this->get($this->url('dashboard').'?contract_id='.$other->id)->assertSessionHasErrors('contract_id');
        $tenant2 = Tenant::create(['name' => 'Outro IC dashboard', 'slug' => 'outro-ic-dashboard', 'status' => 'active', 'plan' => 'starter']);
        $tenant2->memberships()->create(['user_id' => $this->owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->actingAs($this->owner)->get(route('tenant.qualidade.ic.dashboard', $tenant2))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('counts.all', 0)->missing('recent')->has('contracts', 0)->has('byContract', 0)->has('obras', 0)->has('disciplinas', 0));
    }

    public function test_dashboard_filters_combine_and_apply_to_every_metric_and_chart(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));
        $obra = $this->tenant->obras()->create(['contract_id' => $this->contract->id, 'codigo' => 'OB2', 'nome' => 'Segunda obra', 'tipo' => 'pai']);
        $disciplina = $this->tenant->disciplinas()->create(['contract_id' => $this->contract->id, 'nome' => 'Estrutura', 'sigla' => 'EST', 'cor' => '#000000']);
        foreach (['yes', 'partial', 'no'] as $answer) {
            $ic = $this->draft();
            $ic->update(['obra_id' => $obra->id, 'disciplina_id' => $disciplina->id, 'gravidade' => 'Grave',
                'opened_at' => '2026-10-10', 'status' => 'closed', 'resolution_result' => $answer]);
        }
        foreach ([['obra_id' => $this->payload['obra_id']], ['disciplina_id' => $this->payload['disciplina_id']],
            ['gravidade' => 'Leve'], ['opened_at' => '2026-10-09'], ['opened_at' => '2026-10-11']] as $difference) {
            $ic = $this->draft();
            $ic->update(array_replace(['obra_id' => $obra->id, 'disciplina_id' => $disciplina->id,
                'gravidade' => 'Grave', 'opened_at' => '2026-10-10', 'status' => 'closed', 'resolution_result' => 'no'], $difference));
        }
        $query = ['contract_id' => $this->contract->id, 'obra_id' => $obra->id, 'disciplina_id' => $disciplina->id,
            'gravidade' => 'Grave', 'date_from' => '2026-10-10', 'date_to' => '2026-10-10'];
        $this->get($this->url('dashboard').'?'.http_build_query($query))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('counts.all', 3)->where('counts.closed', 3)->where('counts.resolved_yes', 1)
            ->where('counts.resolved_partial', 1)->where('counts.resolved_no', 1)
            ->where('months', fn ($months) => collect($months)->sum('total') === 3)
            ->where('byContract.0.total', 3)->where('filters.gravidade', 'Grave')->missing('recent'));
        foreach (['obra_id', 'disciplina_id', 'gravidade', 'date_from', 'date_to'] as $filter) {
            $this->get($this->url('dashboard').'?'.http_build_query([$filter => $query[$filter]]))->assertOk();
        }
        $this->get($this->url('dashboard').'?'.http_build_query(array_replace($query, ['date_from' => '2026-11-01', 'date_to' => '2026-11-30'])))
            ->assertInertia(fn (Assert $p) => $p->where('counts.all', 0)->where('counts.resolved_yes', 0)
                ->where('counts.resolved_partial', 0)->where('counts.resolved_no', 0)->has('byContract', 0));
        $this->get($this->url('dashboard').'?date_from=2026-10-20&date_to=2026-10-10')->assertSessionHasErrors('date_to');
        $this->get($this->url('dashboard').'?date_from=invalid')->assertSessionHasErrors('date_from');
        $this->get($this->url('dashboard').'?gravidade=invalid')->assertSessionHasErrors('gravidade');
    }

    public function test_dashboard_rejects_work_and_discipline_filters_outside_the_authorized_contracts(): void
    {
        $this->draft();
        $other = $this->tenant->contracts()->create(['code' => 'PRIVATE-FILTERS', 'name' => 'Outro contrato', 'status' => 'active']);
        $obra = $this->tenant->obras()->create(['contract_id' => $other->id, 'codigo' => 'PRIVATE-OB', 'nome' => 'Obra privada', 'tipo' => 'pai']);
        $disciplina = $this->tenant->disciplinas()->create(['contract_id' => $other->id, 'nome' => 'Disciplina privada', 'sigla' => 'PRV', 'cor' => '#000000']);
        foreach (['obra_id' => $obra->id, 'disciplina_id' => $disciplina->id] as $key => $value) {
            $this->get($this->url('dashboard').'?'.http_build_query(['contract_id' => $this->contract->id, $key => $value]))->assertSessionHasErrors($key);
        }
        $user = $this->responsible('operational');
        InstrucaoCampoResponsavel::where('user_id', $user->id)->first()->update(['permissions' => [P::DASHBOARD]]);
        $this->actingAs($user)->get($this->url('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('contracts', 1)->has('obras', 1)->has('disciplinas', 1)->where('counts.all', 1));
        foreach (['obra_id' => $obra->id, 'disciplina_id' => $disciplina->id] as $key => $value) {
            $this->get($this->url('dashboard').'?'.http_build_query([$key => $value]))->assertSessionHasErrors($key);
        }
    }

    public function test_responsibles_and_permission_screens_include_the_new_module(): void
    {
        $user = $this->responsible('monitoring');
        $this->post(route('tenant.qualidade.ic.responsaveis.store', $this->tenant), ['contract_id' => $this->contract->id, 'user_id' => $user->id, 'responsibility_type' => 'operational'])->assertSessionHasNoErrors();
        $this->assertTrue(P::can($user, $this->tenant, P::REVIEW, $this->contract));
        $this->get(route('tenant.permissions.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $p) => $p->where('permissionGroups.ic.permissions.view_ic', 'Visualizar IC'));
        $this->get(route('tenant.users.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $p) => $p->where('contractPermissionGroups.ic_permissions.permissions.view_ic', 'Visualizar IC'));
        $rncResponsible = RelatorioNaoConformidadeResponsavel::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'user_id' => $user->id, 'status' => 'active', 'permissions' => [RncPermissions::VIEW]]);
        $this->patch(route('tenant.permissions.update', $this->tenant), ['user_id' => $user->id, 'contract_id' => $this->contract->id, 'ic_permissions' => [P::VIEW]])->assertSessionHasNoErrors();
        $this->assertSame([RncPermissions::VIEW], $rncResponsible->fresh()->permissions);
        $this->assertSame([P::VIEW], P::permissionsFor($user, $this->tenant, $this->contract));
        $this->actingAs($user)->get(route('tenant.qualidade.ic.responsaveis.index', $this->tenant))->assertForbidden();
    }
}
