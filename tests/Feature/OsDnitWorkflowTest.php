<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\OsDnitOrderEvent;
use App\Models\OsDnitProduct;
use App\Models\OsDnitResponsible;
use App\Models\OsDnitSupervisionLot;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OsDnitFlowChangedNotification;
use App\Services\OsDnitFlowRecipients;
use App\Services\OsDnitPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

class OsDnitWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Contract $contract;

    private User $analyst;

    private User $concremat;

    private User $inspector;

    private User $viewer;

    private OsDnitProduct $product;

    private OsDnitProduct $extra;

    private array $demand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'workflow', 'name' => 'Empresa', 'plan' => 'starter', 'status' => 'active']);
        $this->contract = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'CT-1', 'name' => 'Contrato', 'status' => 'active']);
        $this->analyst = $this->actor('analyst');
        $this->concremat = $this->actor('concremat');
        $this->inspector = $this->actor('contract_inspector');
        $this->viewer = $this->actor(null);
        $road = OsDnitHighway::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'name' => 'BR-364/AC', 'active' => true]);
        $this->product = $this->product('02', 'Controle de Obras');
        $this->extra = $this->product('18D', 'Levantamentos Topográficos');
        $this->demand = ['contract_id' => $this->contract->id, 'supervision_lot_id' => OsDnitSupervisionLot::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'name' => '1', 'active' => true])->id, 'highway_id' => $road->id,
            'km_start' => 1, 'km_end' => 2, 'subject' => 'Pedido inicial', 'desired_deadline' => '2026-12-01',
            'technical_need' => 'Necessidade técnica', 'justification' => 'Resultado esperado', 'sei_reference' => 'SEI-123'];
    }

    public function test_full_approval_preserves_the_analyst_request_and_tracks_product_and_deadline_changes(): void
    {
        $order = $this->draft();
        $this->assertSame('draft', $order->status);
        $this->assertSame('created', $order->events->sole()->action);
        $this->get(route('tenant.os-dnit.orders.index', $this->tenant))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('orders.data.0.can_submit', true));
        $this->actingAs($this->analyst)->get($this->url('show', $order))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('capabilities.edit', true)->where('capabilities.review', false)->where('capabilities.decide', false));
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $this->assertSame('awaiting_concremat', $order->fresh()->status);
        $this->get(route('tenant.os-dnit.orders.index', $this->tenant))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('orders.data.0.can_submit', false));
        $analysis = $this->analysis('save');
        $this->act('review', $order, $this->concremat, $analysis)->assertSessionHasNoErrors();
        $this->assertSame('awaiting_concremat', $order->fresh()->status);
        $this->assertSame(0, $order->items()->count());
        $this->assertSame('2.000', $order->fresh()->review_items[0]['quantity']);
        $this->act('review', $order, $this->concremat, [...$analysis, 'mode' => 'forward'])->assertSessionHasNoErrors();
        $this->actingAs($this->inspector)->get($this->url('show', $order))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('order.status', 'awaiting_contract')->has('order.items', 0)
            ->where('order.review_items.0.product_id', $this->extra->id)->where('order.review_items.0.quantity', '2.000')
            ->where('order.review_comment', 'Produto substituído após análise técnica.')
            ->where('capabilities.decide', true)->where('capabilities.review', false));
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Serviço autorizado.'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $events = $order->events()->get();
        $this->assertSame(['created', 'submitted', 'analysis_saved', 'forwarded', 'approved'], $events->pluck('action')->all());
        $this->assertSame([], $events->last()->snapshot['items']);
        $this->assertSame($this->extra->id, $events->last()->snapshot['review_items'][0]['product_id']);
        $this->assertSame($this->inspector->id, $events->last()->actor_id);
        $this->assertSame('2026-12-10', $events->last()->snapshot['estimated_deadline']);
        $this->actingAs($this->analyst)->get($this->url('edit', $order))->assertForbidden();
        $this->act('review', $order, $this->concremat, $analysis)->assertSessionHasErrors('status');
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve'])->assertSessionHasErrors('status');
        $this->assertSame(5, $order->events()->count());
    }

    public function test_decision_requires_comment_and_rejection_requires_destination_and_can_return_to_concremat(): void
    {
        $order = $this->awaitingDecision();
        $version = $order->workflow_version;
        $events = $order->events()->count();
        foreach (['approve', 'return'] as $decision) {
            $message = $decision === 'return' ? 'Informe o motivo da reprovação.' : 'Informe o comentário da decisão.';
            $this->act('decide', $order, $this->inspector, ['decision' => $decision, 'return_to' => 'concremat'])
                ->assertSessionHasErrors(['comment' => $message]);
            foreach ([null, '', " \t\n ", ['inválido']] as $comment) {
                $this->act('decide', $order, $this->inspector, ['decision' => $decision, 'return_to' => 'concremat', 'comment' => $comment])
                    ->assertSessionHasErrors('comment');
            }
        }
        $this->assertSame($version, $order->fresh()->workflow_version);
        $this->assertSame($events, $order->events()->count());
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'comment' => '   ', 'return_to' => 'concremat'])->assertSessionHasErrors('comment');
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'comment' => ['inválido'], 'return_to' => 'concremat'])->assertSessionHasErrors('comment');
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'comment' => 'Ajustar prazo'])->assertSessionHasErrors('return_to');
        $this->assertSame('awaiting_contract', $order->fresh()->status);
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'comment' => 'Rever quantidade e prazo.', 'return_to' => 'concremat'])->assertSessionHasNoErrors();
        $this->assertSame('returned_concremat', $order->fresh()->status);
        $this->assertSame('Rever quantidade e prazo.', $order->events()->get()->last()->comment);
        $this->actingAs($this->analyst)->get($this->url('edit', $order))->assertForbidden();
        $this->act('review', $order, $this->concremat, [...$this->analysis(), 'estimated_deadline' => '2026-12-05',
            'items' => [['product_id' => $this->product->id, 'quantity' => '9.500'], ['product_id' => $this->extra->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Serviço autorizado.'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame('9.500', $order->fresh()->review_items[0]['quantity']);
        $this->assertSame(0, $order->items()->count());
    }

    public function test_return_to_analyst_keeps_request_locked_and_allows_resending_for_another_analysis(): void
    {
        $order = $this->awaitingDecision();
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'return_to' => 'analyst', 'comment' => 'Esclarecer necessidade técnica.'])->assertSessionHasNoErrors();
        $this->assertSame('returned_analyst', $order->fresh()->status);
        $this->actingAs($this->analyst)->get($this->url('edit', $order))->assertForbidden();
        $this->patch($this->url('update', $order), [...$this->demand, 'version' => $order->fresh()->workflow_version,
            'subject' => 'Pedido corrigido', 'items' => [['product_id' => $this->product->id, 'quantity' => 8]]])->assertForbidden();
        $this->assertSame('Pedido inicial', $order->fresh()->subject);
        $this->assertNotNull($order->fresh()->review_items);
        $this->assertSame(0, $order->items()->count());
        $this->get($this->url('show', $order))->assertOk()->assertInertia(fn (Assert $p) => $p->where('capabilities.edit', false)->where('capabilities.submit', true));
        $this->assertSame('Pedido inicial', $order->events()->first()->snapshot['subject']);
        $return = $order->events()->where('action', 'returned')->sole();
        $this->assertSame([], $return->snapshot['items']);
        $this->assertNotNull($return->snapshot['review_items']);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasErrors('status');
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve'])->assertSessionHasErrors('status');
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Serviço autorizado.'])->assertSessionHasNoErrors();
    }

    public function test_wrong_roles_other_authors_and_admins_cannot_skip_workflow_permissions(): void
    {
        $order = $this->draft();
        $this->actingAs($this->viewer)->post(route('tenant.os-dnit.orders.store', $this->tenant), $this->demand)->assertSessionHasErrors('contract_id');
        $this->act('submit', $order, $this->viewer)->assertForbidden();
        $this->get(route('tenant.os-dnit.orders.index', $this->tenant))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('orders.data.0.can_submit', false));
        $this->act('review', $order, $this->analyst, $this->analysis())->assertForbidden();
        $this->act('decide', $order, $this->concremat, ['decision' => 'approve'])->assertForbidden();
        $otherAnalyst = $this->actor('analyst');
        $this->actingAs($otherAnalyst)->patch($this->url('update', $order), [...$this->demand, 'version' => 1])->assertForbidden();
        $this->act('submit', $order, $otherAnalyst)->assertForbidden();
        // Even a platform administrator with contract membership needs the workflow role.
        $this->viewer->update(['is_platform_admin' => true]);
        $this->act('decide', $order, $this->viewer, ['decision' => 'approve'])->assertForbidden();
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_stale_forms_and_repeated_submissions_do_not_overwrite_or_duplicate_history(): void
    {
        $order = $this->draft();
        $version = $order->workflow_version;
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post($this->url('submit', $order), ['version' => $version])->assertSessionHasErrors('version');
        $this->patch($this->url('update', $order), [...$this->demand, 'version' => $version])->assertForbidden();
        $version = $order->fresh()->workflow_version;
        $this->act('review', $order, $this->concremat, $this->analysis('save'))->assertSessionHasNoErrors();
        $this->actingAs($this->concremat)->post($this->url('review', $order), [...$this->analysis(), 'version' => $version])->assertSessionHasErrors('version');
        $this->assertSame('awaiting_concremat', $order->fresh()->status);
        $this->assertSame(3, $order->events()->count());
    }

    public function test_validation_rejects_foreign_products_invalid_quantities_and_missing_analysis(): void
    {
        $order = $this->draft();
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $other = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'CT-OTHER', 'name' => 'Outro contrato', 'status' => 'active']);
        $foreign = OsDnitProduct::create(['tenant_id' => $this->tenant->id, 'contract_id' => $other->id, 'code' => 'FOREIGN', 'name' => 'Outro', 'unit' => 'km', 'category' => 'gestao_controle', 'frequency' => 'on_demand']);
        $this->act('review', $order, $this->concremat, [...$this->analysis(), 'items' => [['product_id' => $foreign->id, 'quantity' => 1]]])->assertSessionHasErrors('items.0.product_id');
        $this->act('review', $order, $this->concremat, [...$this->analysis(), 'estimated_deadline' => null])->assertSessionHasErrors('estimated_deadline');
        $this->act('review', $order, $this->concremat, [...$this->analysis(), 'review_comment' => ['inválido']])->assertSessionHasErrors('review_comment');
        foreach (['save', 'forward'] as $mode) {
            $missingComment = $this->analysis($mode);
            unset($missingComment['review_comment']);
            $this->act('review', $order, $this->concremat, $missingComment)->assertSessionHasErrors('review_comment');
            foreach ([null, '', " \t\n "] as $comment) {
                $this->act('review', $order, $this->concremat, [...$this->analysis($mode), 'review_comment' => $comment])
                    ->assertSessionHasErrors(['review_comment' => 'Informe o comentário da análise.']);
            }
        }
        $this->act('review', $order, $this->concremat, [...$this->analysis(), 'items' => []])->assertSessionHasErrors('items');
        $this->act('review', $order, $this->concremat, [...$this->analysis(), 'items' => [['product_id' => $this->product->id, 'quantity' => 0]]])->assertSessionHasErrors('items.0.quantity');
        $this->act('review', $order, $this->concremat, [...$this->analysis(), 'items' => [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->product->id, 'quantity' => 2]]])->assertSessionHasErrors('items.0.product_id');
        $this->assertSame(2, $order->events()->count());
        $this->assertNull($order->fresh()->review_items);
    }

    public function test_contract_membership_and_active_workflow_roles_are_checked_on_every_action(): void
    {
        $order = $this->draft();
        $outsider = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $outsider->id, 'role' => 'tenant_admin', 'status' => 'active']);
        OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'user_id' => $outsider->id, 'role' => 'concremat', 'active' => true]);
        foreach (['submit', 'review', 'decide'] as $action) {
            $this->act($action, $order, $outsider, $this->analysis())->assertNotFound();
        }
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        OsDnitResponsible::where('user_id', $this->concremat->id)->update(['active' => false]);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertForbidden();
        OsDnitResponsible::where('user_id', $this->concremat->id)->update(['active' => true]);
        ContractParticipant::where('user_id', $this->concremat->id)->update(['status' => 'inactive']);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertNotFound();
    }

    public function test_unavailable_destination_blocks_sending_without_losing_the_draft(): void
    {
        $order = $this->draft();
        ContractParticipant::where('user_id', $this->concremat->id)->delete();
        $this->act('submit', $order, $this->analyst)->assertSessionHasErrors('workflow');
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(1, $order->events()->count());
    }

    public function test_draft_edit_preserves_number_original_attachment_and_historical_request(): void
    {
        Storage::fake('local');
        $this->actingAs($this->analyst)->post(route('tenant.os-dnit.orders.store', $this->tenant), [...$this->demand,
            'attachments' => [UploadedFile::fake()->create('original.pdf', 2, 'application/pdf')]])->assertSessionHasNoErrors();
        $order = OsDnitOrder::sole();
        $originalPath = $order->attachments()->sole()->path;
        $this->post($this->url('update', $order), [...$this->demand, '_method' => 'patch', 'version' => 1,
            'request_number' => '999/1999', 'status' => 'approved', 'subject' => 'Pedido atualizado',
            'attachments' => [UploadedFile::fake()->create('complemento.pdf', 2, 'application/pdf')]])->assertSessionHasNoErrors();
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame($order->request_number, $order->fresh()->request_number);
        $this->assertSame('Pedido atualizado', $order->fresh()->subject);
        $this->assertSame(2, $order->attachments()->count());
        Storage::disk('local')->assertExists($originalPath);
        $this->assertSame('Pedido inicial', $order->events()->first()->snapshot['subject']);
        $this->assertCount(2, $order->events()->get()->last()->snapshot['attachments']);
        $this->patch($this->url('update', $order), [...$this->demand, 'version' => 1])->assertSessionHasErrors('version');
        $other = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'CT-2', 'name' => 'Outro contrato', 'status' => 'active']);
        $this->patch($this->url('update', $order), [...$this->demand, 'version' => 2, 'contract_id' => $other->id])->assertSessionHasErrors('contract_id');
        $this->assertSame('Pedido atualizado', $order->fresh()->subject);
        $this->assertSame(2, $order->events()->count());
    }

    public function test_request_editing_is_blocked_in_every_stage_after_submission(): void
    {
        $order = $this->draft();
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        foreach (['awaiting_concremat', 'awaiting_contract', 'returned_concremat', 'returned_analyst', 'approved', 'draft'] as $status) {
            // Even a mistakenly restored draft status must not unlock an already sent request.
            $order->update(['status' => $status]);
            $this->actingAs($this->analyst)->get($this->url('edit', $order))->assertForbidden();
            $this->patch($this->url('update', $order), [...$this->demand, 'version' => $order->fresh()->workflow_version,
                'subject' => 'Alteração proibida', 'items' => [['product_id' => $this->extra->id, 'quantity' => 9]]])->assertForbidden();
            $this->get($this->url('show', $order))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->where('capabilities.edit', false));
        }
        $this->assertSame('Pedido inicial', $order->fresh()->subject);
        $this->assertSame(0, $order->items()->count());
        $this->assertSame(0, $order->items()->count());
        $this->assertSame(2, $order->events()->count());
    }

    public function test_responsible_configuration_requires_admin_and_active_participants_of_same_contract(): void
    {
        $url = route('tenant.os-dnit.responsibles.store', $this->tenant);
        $data = ['contract_id' => $this->contract->id, 'assignments' => [['role' => 'concremat', 'user_id' => $this->viewer->id]]];
        $this->actingAs($this->concremat)->post($url, $data)->assertForbidden();
        $this->actingAs($this->analyst)->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('os_dnit_responsibles', ['user_id' => $this->viewer->id, 'role' => 'concremat', 'active' => true]);
        $this->assertDatabaseHas('os_dnit_responsibles', ['user_id' => $this->concremat->id, 'active' => false]);
        $outsider = User::factory()->create();
        $this->post($url, [...$data, 'assignments' => [['role' => 'concremat', 'user_id' => $outsider->id]]])->assertSessionHasErrors('assignments.0.user_id');
        ContractParticipant::where('user_id', $this->viewer->id)->delete();
        $this->post($url, $data)->assertSessionHasErrors('assignments.0.user_id');
        $this->post($url, ['contract_id' => $this->contract->id, 'assignments' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, OsDnitResponsible::where('active', true)->count());
    }

    public function test_every_workflow_transition_notifies_its_responsibles_and_author_but_not_draft_saves(): void
    {
        Notification::fake();
        $anotherAnalyst = $this->actor('analyst');
        $order = $this->draft();
        Notification::assertNothingSent();
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        Notification::assertSentTo([$this->analyst, $this->concremat], OsDnitFlowChangedNotification::class);
        Notification::assertNotSentTo([$this->inspector, $this->viewer, $anotherAnalyst], OsDnitFlowChangedNotification::class);
        Notification::assertCount(2);
        $this->actingAs($this->analyst)->post($this->url('submit', $order), ['version' => 1])->assertSessionHasErrors('version');
        Notification::assertCount(2);
        $this->act('review', $order, $this->concremat, $this->analysis('save'))->assertSessionHasNoErrors();
        Notification::assertCount(2);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        Notification::assertCount(5);
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'return_to' => 'concremat', 'comment' => 'Rever quantidade.'])->assertSessionHasNoErrors();
        Notification::assertCount(8);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        Notification::assertCount(11);
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'return_to' => 'analyst', 'comment' => 'Rever pedido.'])->assertSessionHasNoErrors();
        Notification::assertCount(14);
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        Notification::assertCount(16);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Serviço autorizado.'])->assertSessionHasNoErrors();
        Notification::assertCount(22);
        Notification::assertSentTo($this->analyst, OsDnitFlowChangedNotification::class,
            fn ($notification) => OsDnitOrderEvent::find($notification->eventId)->action === 'approved');
        Notification::assertNotSentTo([$this->viewer, $anotherAnalyst], OsDnitFlowChangedNotification::class);
    }

    public function test_mail_jobs_and_event_roll_back_together_when_the_workflow_transaction_is_cancelled(): void
    {
        $order = $this->draft();
        $this->assertDatabaseCount('jobs', 0);
        DB::beginTransaction();
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame('awaiting_concremat', $order->fresh()->status);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(1, $order->events()->count());
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_mail_is_delivered_from_the_immutable_event_even_if_the_os_has_already_advanced(): void
    {
        $order = $this->awaitingDecision();
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Autorizado.'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('jobs', 8);
        $this->deliverPendingEmails();
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(8, $messages);
        $first = $messages->first()->getOriginalMessage();
        $this->assertStringContainsString('OS enviada para análise Supervisão', $first->getSubject());
        $this->assertStringContainsString('Em análise Supervisão', $first->getTextBody());
        $this->assertStringNotContainsString('Demanda aprovada', $first->getTextBody());
        $last = $messages->last()->getOriginalMessage();
        $this->assertStringContainsString('Demanda aprovada', $last->getSubject());
        $this->assertStringContainsString($this->url('show', $order), $last->getHtmlBody());
        $this->assertStringContainsString($this->url('show', $order), $last->getTextBody());
        $this->assertStringContainsString('Autorizado.', $last->getTextBody());
        $this->assertStringContainsString('CT-1', $last->getHtmlBody());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_rejection_emails_include_the_correct_destination_and_escaped_justification(): void
    {
        $order = $this->awaitingDecision();
        $comment = 'Rever prazo <script>alert("x")</script>';
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'return_to' => 'analyst', 'comment' => $comment])->assertSessionHasNoErrors();
        $this->deliverPendingEmails();
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringContainsString('OS reprovada e devolvida ao analista DNIT', $message->getSubject());
        $this->assertStringContainsString('Justificativa da reprovação / devolução', $message->getTextBody());
        $this->assertStringNotContainsString('<script>', $message->getHtmlBody());
        $this->assertStringContainsString('&lt;script&gt;', $message->getHtmlBody());
        $this->assertStringContainsString('bloqueado para edição', $message->getTextBody());
    }

    public function test_revoked_membership_or_workflow_role_prevents_delivery_of_previously_queued_mail(): void
    {
        $order = $this->draft();
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $event = $order->events()->where('action', 'submitted')->sole();
        $notification = new OsDnitFlowChangedNotification($event->id);
        $this->assertTrue($notification->shouldSend($this->concremat, 'mail'));
        $this->assertTrue($notification->shouldSend($this->concremat, 'database'));
        $this->assertFalse($notification->shouldSend($this->viewer, 'mail'));
        $this->assertFalse($notification->shouldSend($this->viewer, 'database'));
        OsDnitResponsible::where('user_id', $this->concremat->id)->update(['active' => false]);
        $this->assertFalse($notification->shouldSend($this->concremat, 'mail'));
        $this->assertFalse($notification->shouldSend($this->concremat, 'database'));
        OsDnitResponsible::where('user_id', $this->concremat->id)->update(['active' => true]);
        ContractParticipant::where('user_id', $this->concremat->id)->delete();
        $this->deliverPendingEmails();
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame($this->analyst->email, $messages->first()->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_recipient_selection_is_scoped_to_contract_and_deduplicates_multiple_roles(): void
    {
        $order = $this->awaitingDecision();
        $event = $order->events()->where('action', 'forwarded')->sole();
        OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'user_id' => $this->concremat->id, 'role' => 'contract_inspector', 'active' => true]);
        $other = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'OUT', 'name' => 'Outro contrato', 'status' => 'active']);
        OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $other->id,
            'user_id' => $this->viewer->id, 'role' => 'concremat', 'active' => true]);
        $ids = app(OsDnitFlowRecipients::class)->forEvent($event)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->analyst->id, $this->concremat->id, $this->inspector->id], $ids);
        $this->assertCount(3, $ids);
    }

    public function test_mail_failure_keeps_the_workflow_saved_and_the_job_can_be_retried(): void
    {
        $order = $this->draft();
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $notificationIds = DB::table('notifications')->pluck('id')->sort()->values()->all();
        $this->assertCount(2, $notificationIds);
        $mailer = Mail::mailer();
        $originalTransport = $mailer->getSymfonyTransport();
        $transport = \Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->andThrow(new TransportException('Temporary provider failure'));
        $mailer->setSymfonyTransport($transport);
        $job = Queue::connection('os_dnit_mail')->pop('os-dnit-mail');
        $this->assertSame(5, $job->payload()['maxTries']);
        $this->assertSame(60, $job->payload()['timeout']);
        try {
            $job->fire();
            $this->fail('Provider failure must reach the worker for retry.');
        } catch (TransportException $exception) {
            $job->release(0);
        } finally {
            $mailer->setSymfonyTransport($originalTransport);
        }
        $this->assertSame('awaiting_concremat', $order->fresh()->status);
        $this->assertDatabaseCount('jobs', 2);
        $this->deliverPendingEmails();
        $this->assertCount(2, $originalTransport->messages());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(2, $order->events()->count());
        $this->assertSame($notificationIds, DB::table('notifications')->pluck('id')->sort()->values()->all());
    }

    public function test_bell_notifications_are_immediate_and_cover_the_full_workflow_for_eligible_responsibles(): void
    {
        $anotherAnalyst = $this->actor('analyst');
        OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'user_id' => $this->concremat->id, 'role' => 'contract_inspector', 'active' => true]);
        $order = $this->draft();
        $this->assertDatabaseCount('notifications', 0);
        $assertRecipients = function (string $action, int $total, bool $inspector = true) use ($order) {
            $event = $order->events()->where('action', $action)->reorder('id', 'desc')->firstOrFail();
            $recipients = DB::table('notifications')->where('type', OsDnitFlowChangedNotification::class)
                ->where('data->os_dnit_event_id', $event->id)->pluck('notifiable_id')->all();
            $expected = [$this->analyst->id, $this->concremat->id];
            if ($inspector) {
                $expected[] = $this->inspector->id;
            }
            $this->assertEqualsCanonicalizing($expected, $recipients);
            $this->assertDatabaseCount('notifications', $total);
        };
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $assertRecipients('submitted', 2, false);
        $notice = $this->concremat->unreadNotifications()->sole();
        $this->assertSame($this->tenant->id, $notice->data['tenant_id']);
        $this->assertSame($this->contract->id, $notice->data['contract_id']);
        $this->assertSame($order->id, $notice->data['os_dnit_order_id']);
        $this->assertSame('CT-1', $notice->data['contract']);
        $this->assertStringContainsString($order->request_number, $notice->data['title']);
        $this->assertStringContainsString('OS enviada para análise Supervisão', $notice->data['title']);
        $this->assertStringContainsString($this->analyst->name, $notice->data['body']);
        $this->assertSame(route('tenant.os-dnit.orders.show', [$this->tenant, $order], false), $notice->data['url']);
        $this->actingAs($this->concremat)->get(route('tenant.os-dnit.orders.index', $this->tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('notifications.unread_count', 1)
                ->where('notifications.items.0.id', $notice->id)->where('notifications.items.0.title', $notice->data['title']));
        $this->act('submit', $order, $this->analyst, ['version' => 1])->assertSessionHasErrors('version');
        $this->act('review', $order, $this->concremat, $this->analysis('save'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notifications', 2);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        $assertRecipients('forwarded', 5);
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'return_to' => 'concremat', 'comment' => 'Rever quantidade.'])->assertSessionHasNoErrors();
        $assertRecipients('returned', 8);
        $this->assertStringContainsString('devolvida à Supervisão', $this->analyst->notifications()->where('data->os_dnit_event_id', $order->events()->reorder('id', 'desc')->value('id'))->sole()->data['title']);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        $assertRecipients('forwarded', 11);
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'return_to' => 'analyst', 'comment' => 'Rever pedido.'])->assertSessionHasNoErrors();
        $assertRecipients('returned', 14);
        $this->assertStringContainsString('devolvida ao analista DNIT', $this->analyst->notifications()->where('data->os_dnit_event_id', $order->events()->reorder('id', 'desc')->value('id'))->sole()->data['title']);
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $assertRecipients('submitted', 16, false);
        $this->assertStringContainsString('reenviada', $this->analyst->notifications()->where('data->os_dnit_event_id', $order->events()->reorder('id', 'desc')->value('id'))->sole()->data['title']);
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        $assertRecipients('forwarded', 19);
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Autorizado.'])->assertSessionHasNoErrors();
        $assertRecipients('approved', 22);
        $this->act('finalize', $order, $this->inspector)->assertSessionHasNoErrors();
        $assertRecipients('completed', 25);
        $this->assertStringContainsString('OS finalizada', $this->analyst->notifications()->where('data->os_dnit_event_id', $order->events()->reorder('id', 'desc')->value('id'))->sole()->data['title']);
        $this->assertSame(0, $anotherAnalyst->notifications()->count());
        $this->assertSame(0, $this->viewer->notifications()->count());
        $this->assertSame('awaiting_concremat', $notice->fresh()->data['status']);
        $this->assertDatabaseCount('jobs', 25);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
        $this->deliverPendingEmails();
        $this->assertDatabaseCount('notifications', 25);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(25, Mail::mailer()->getSymfonyTransport()->messages());
    }

    private function deliverPendingEmails(): void
    {
        $queue = Queue::connection('os_dnit_mail');
        while ($job = $queue->pop('os-dnit-mail')) {
            $job->fire();
        }
    }

    public function test_analysis_menu_count_and_list_follow_each_users_role_and_workflow_stage(): void
    {
        $order = $this->draft();
        $index = route('tenant.os-dnit.orders.index', [$this->tenant, 'pending' => 1]);
        $this->actingAs($this->concremat)->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('filters.pending', true)->has('orders.data', 0)->where('osDnitNavigation.pending_analysis_count', 0));
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $this->actingAs($this->concremat)->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('orders.data', 1)->where('orders.data.0.id', $order->id)->where('osDnitNavigation.pending_analysis_count', 1));
        $this->get(route('tenant.os-dnit.products.index', $this->tenant))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('osDnitNavigation.pending_analysis_count', 1));
        foreach ([$this->analyst, $this->inspector, $this->viewer] as $user) {
            $this->actingAs($user)->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->has('orders.data', 0)->where('osDnitNavigation.pending_analysis_count', 0));
        }
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        $this->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('orders.data', 0)->where('osDnitNavigation.pending_analysis_count', 0));
        $this->actingAs($this->inspector)->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('orders.data', 1)->where('osDnitNavigation.pending_analysis_count', 1));
        $this->act('decide', $order, $this->inspector, ['decision' => 'return', 'return_to' => 'concremat', 'comment' => 'Rever prazo.'])->assertSessionHasNoErrors();
        $this->actingAs($this->concremat)->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('orders.data.0.status', 'returned_concremat')->where('osDnitNavigation.pending_analysis_count', 1));
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Serviço autorizado.'])->assertSessionHasNoErrors();
        $this->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('orders.data', 0)->where('osDnitNavigation.pending_analysis_count', 0));
    }

    public function test_analysis_access_requires_role_and_membership_without_admin_bypass(): void
    {
        $order = $this->draft();
        $detail = route('tenant.os-dnit.analysis.show', [$this->tenant, $order]);
        $index = route('tenant.os-dnit.orders.index', [$this->tenant, 'pending' => 1]);
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $this->actingAs($this->concremat)->get($detail)->assertRedirect(route('tenant.os-dnit.orders.index', [$this->tenant, 'analysis_order_id' => $order->id]));
        $this->get(route('tenant.os-dnit.orders.index', [$this->tenant, 'analysis_order_id' => $order->id]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Tenant/OsDnit/Orders')->where('analysisOrderId', $order->id));
        $this->getJson($detail)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('capabilities.review', true)->assertJsonPath('order.id', $order->id)
            ->assertJsonCount(0, 'order.items');
        $this->viewer->update(['is_platform_admin' => true]);
        foreach ([$this->analyst, $this->viewer] as $user) {
            $this->actingAs($user)->get($detail)->assertForbidden();
            $this->getJson($detail)->assertForbidden();
        }
        OsDnitResponsible::where('user_id', $this->concremat->id)->update(['active' => false]);
        $this->actingAs($this->concremat)->get($detail)->assertForbidden();
        $this->getJson($detail)->assertForbidden();
        $this->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('orders.data', 0)->where('osDnitNavigation.pending_analysis_count', 0));
        OsDnitResponsible::where('user_id', $this->concremat->id)->update(['active' => true]);
        ContractParticipant::where('user_id', $this->concremat->id)->delete();
        $this->get($detail)->assertNotFound();
        $this->getJson($detail)->assertNotFound();
        $this->get($index)->assertForbidden();
    }

    public function test_analysis_list_paginates_filters_and_counts_each_order_once_for_multiple_roles(): void
    {
        $order = $this->draft();
        $order->update(['status' => 'awaiting_concremat', 'submitted_at' => now()]);
        for ($i = 2; $i <= 12; $i++) {
            $copy = $order->replicate();
            $copy->request_number = sprintf('%03d/%d', $i, now()->year);
            $copy->status = $i === 12 ? 'awaiting_contract' : 'awaiting_concremat';
            $copy->save();
        }
        OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'user_id' => $this->concremat->id, 'role' => 'contract_inspector', 'active' => true]);
        $index = route('tenant.os-dnit.orders.index', [$this->tenant, 'pending' => 1]);
        $this->actingAs($this->concremat)->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('orders.data', 10)->where('orders.total', 12)->where('orders.per_page', 10)
            ->where('osDnitNavigation.pending_analysis_count', 12));
        $this->get($index.'&page=2')->assertOk()->assertInertia(fn (Assert $p) => $p->has('orders.data', 2));
        $this->get($index.'&status=awaiting_contract')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('orders.data', 1)->where('osDnitNavigation.pending_analysis_count', 12));
        $this->get($index.'&q=001')->assertOk()->assertInertia(fn (Assert $p) => $p->has('orders.data', 1));
        $this->get($index, ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'Tenant/OsDnit/Orders', 'X-Inertia-Partial-Data' => 'orders'])
            ->assertOk()->assertJsonPath('props.osDnitNavigation.pending_analysis_count', 12);
        $other = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'UNLINKED', 'name' => 'Sem vínculo', 'status' => 'active']);
        $copy = $order->replicate();
        $copy->contract_id = $other->id;
        $copy->save();
        OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $other->id,
            'user_id' => $this->concremat->id, 'role' => 'concremat', 'active' => true]);
        $this->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p->where('orders.total', 12)->where('osDnitNavigation.pending_analysis_count', 12));
        $this->get(route('tenant.os-dnit.analysis.show', [$this->tenant, $copy]))->assertNotFound();
    }

    public function test_analysis_modal_saves_and_forwards_from_list_and_updates_pending_count(): void
    {
        $order = $this->draft();
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $index = route('tenant.os-dnit.orders.index', [$this->tenant, 'pending' => 1]);
        $detail = route('tenant.os-dnit.analysis.show', [$this->tenant, $order]);
        $this->actingAs($this->concremat)->from($index);
        $this->post($this->url('review', $order), [...$this->analysis('save'), 'version' => $order->fresh()->workflow_version])
            ->assertSessionHasNoErrors()->assertRedirect($index);
        $this->getJson($detail)->assertOk()->assertJsonPath('order.workflow_version', 3)
            ->assertJsonPath('order.review_items.0.product_id', $this->extra->id)
            ->assertJsonCount(0, 'order.items')
            ->assertJsonPath('capabilities.review', true);
        $this->post($this->url('review', $order), [...$this->analysis(), 'version' => 3])->assertSessionHasNoErrors()->assertRedirect($index);
        $this->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 0)->where('osDnitNavigation.pending_analysis_count', 0));
        $this->actingAs($this->inspector)->getJson($detail)->assertOk()
            ->assertJsonPath('capabilities.decide', true)->assertJsonPath('capabilities.review', false)
            ->assertJsonPath('order.review_items.0.quantity', '2.000')->assertJsonCount(0, 'order.items');
        $this->from($index)->post($this->url('decide', $order), ['version' => 4, 'decision' => 'return', 'return_to' => 'concremat', 'comment' => ''])
            ->assertSessionHasErrors('comment');
        $this->post($this->url('decide', $order), ['version' => 4, 'decision' => 'approve', 'comment' => 'Serviço autorizado.'])
            ->assertSessionHasNoErrors()->assertRedirect($index);
        $this->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 0)->where('osDnitNavigation.pending_analysis_count', 0));
        $this->getJson($detail)->assertOk()->assertJsonPath('order.status', 'approved')->assertJsonPath('capabilities.decide', false);
    }

    public function test_order_list_exposes_analysis_actions_only_to_current_responsibles_and_keeps_all_orders(): void
    {
        $order = $this->draft();
        $index = route('tenant.os-dnit.orders.index', $this->tenant);
        $this->actingAs($this->concremat)->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.can_analyze', false)->where('filters.pending', false));
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $this->actingAs($this->concremat)->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.can_analyze', true)->where('osDnitNavigation.pending_analysis_count', 1));
        foreach ([$this->viewer, $this->analyst, $this->inspector] as $user) {
            $this->actingAs($user)->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)->where('orders.data.0.can_analyze', false));
        }
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();
        $this->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.can_analyze', false));
        $this->actingAs($this->inspector)->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.can_analyze', true));
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Serviço autorizado.'])->assertSessionHasNoErrors();
        $this->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.status', 'approved')->where('orders.data.0.can_analyze', false)
            ->where('osDnitNavigation.pending_analysis_count', 0));
        $this->get(route('tenant.os-dnit.analysis.index', $this->tenant))
            ->assertRedirect(route('tenant.os-dnit.orders.index', [$this->tenant, 'pending' => 1]));
        $this->get($index.'?pending=0')->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.pending', false));
    }

    public function test_stage_filters_and_counters_group_returns_and_respect_contract_and_tenant_access(): void
    {
        $order = $this->draft();
        foreach (['returned_analyst', 'awaiting_concremat', 'returned_concremat', 'awaiting_contract', 'approved'] as $i => $status) {
            $copy = $order->replicate();
            $copy->request_number = sprintf('%03d/%d', $i + 2, now()->year);
            $copy->status = $status;
            $copy->save();
        }
        $other = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'HIDDEN', 'name' => 'Sem vínculo', 'status' => 'active']);
        $copy = $order->replicate();
        $copy->contract_id = $other->id;
        $copy->save();
        $foreignTenant = Tenant::create(['slug' => 'foreign', 'name' => 'Outra empresa', 'plan' => 'starter', 'status' => 'active']);
        $foreign = $order->replicate();
        $foreign->tenant_id = $foreignTenant->id;
        $foreign->request_number = '999/'.now()->year;
        $foreign->save();

        $index = route('tenant.os-dnit.orders.index', $this->tenant);
        // Stage lists are visible to contract members, even without an analysis role.
        $this->actingAs($this->viewer)->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.stage', 'all')->where('orders.total', 6)
            ->where('stageCounts', ['all' => 6, 'analyst' => 2, 'concremat' => 2, 'contract_inspector' => 1, 'approved' => 1, 'completed' => 0]));
        foreach ([
            'analyst' => ['returned_analyst', 'draft'],
            'concremat' => ['returned_concremat', 'awaiting_concremat'],
            'contract_inspector' => ['awaiting_contract'],
            'approved' => ['approved'], 'completed' => [],
        ] as $stage => $statuses) {
            $this->get($index.'?stage='.$stage)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.stage', $stage)->where('orders.total', count($statuses))
                ->where('orders.data', fn ($rows) => $rows->pluck('status')->all() === $statuses)
                ->where('stageCounts.all', 6));
        }
        $this->get($index.'?stage=concremat&q=003')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.total', 1)->where('stageCounts', ['all' => 1, 'analyst' => 0, 'concremat' => 1, 'contract_inspector' => 0, 'approved' => 0, 'completed' => 0]));
        $this->get($index.'?contract_id='.$other->id)->assertSessionHasErrors('contract_id');
        ContractParticipant::create(['tenant_id' => $this->tenant->id, 'contract_id' => $other->id,
            'user_id' => $this->viewer->id, 'side' => 'client', 'role' => 'manager', 'status' => 'active']);
        $this->get($index.'?contract_id='.$other->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.total', 1)->where('orders.data.0.id', $copy->id)
            ->where('stageCounts', ['all' => 1, 'analyst' => 1, 'concremat' => 0, 'contract_inspector' => 0, 'approved' => 0, 'completed' => 0]));
        $this->get($index.'?stage=invalid')->assertSessionHasErrors('stage');
    }

    public function test_supervision_lots_are_scoped_and_fill_sei_without_changing_existing_documents(): void
    {
        $this->actingAs($this->analyst);
        $this->post(route('tenant.os-dnit.settings.lots.store', $this->tenant), ['contract_id' => $this->contract->id, 'name' => 'Lote novo', 'sei_reference' => 'SEI-LOTE'])->assertSessionHasNoErrors();
        $lot = OsDnitSupervisionLot::where('name', 'Lote novo')->sole();
        $this->get(route('tenant.os-dnit.orders.create', $this->tenant))->assertInertia(fn (Assert $p) => $p
            ->has('lots', 2)->missing('products'));
        $this->post(route('tenant.os-dnit.orders.store', $this->tenant), [...$this->demand, 'supervision_lot_id' => $lot->id, 'sei_reference' => 'Tentativa de sobrescrever'])->assertSessionHasNoErrors();
        $order = OsDnitOrder::sole();
        $this->assertSame('SEI-LOTE', $order->sei_reference);
        $this->assertSame('Lote novo', $order->supervision_lot);
        $this->assertSame(0, $order->items()->count());
        $this->patch(route('tenant.os-dnit.settings.lots.update', [$this->tenant, $lot]), ['contract_id' => $this->contract->id, 'name' => 'Lote renomeado', 'sei_reference' => 'SEI-NOVA', 'active' => true])->assertSessionHasNoErrors();
        $this->assertSame('Lote novo', $order->fresh()->supervision_lot);
        $this->assertSame('SEI-LOTE', $order->fresh()->sei_reference);
        $this->delete(route('tenant.os-dnit.settings.lots.destroy', [$this->tenant, $lot]))->assertSessionHasErrors('resource');
        $this->post(route('tenant.os-dnit.orders.store', $this->tenant), [...$this->demand, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertSessionHasErrors('items');
        $lot->update(['active' => false]);
        $this->post(route('tenant.os-dnit.orders.store', $this->tenant), [...$this->demand, 'supervision_lot_id' => $lot->id])->assertSessionHasErrors('supervision_lot_id');
        $foreignContract = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'CT-LOT-OUTRO', 'name' => 'Outro', 'status' => 'active']);
        $foreign = OsDnitSupervisionLot::create(['tenant_id' => $this->tenant->id, 'contract_id' => $foreignContract->id, 'name' => 'Privado', 'active' => true]);
        $this->post(route('tenant.os-dnit.orders.store', $this->tenant), [...$this->demand, 'supervision_lot_id' => $foreign->id])->assertSessionHasErrors('supervision_lot_id');
        $this->get(route('tenant.os-dnit.settings.index', $this->tenant))->assertInertia(fn (Assert $p) => $p->has('lots.data', 2));
        $this->delete(route('tenant.os-dnit.settings.lots.destroy', [$this->tenant, $foreign]))->assertNotFound();
        $this->actingAs($this->viewer)->post(route('tenant.os-dnit.settings.lots.store', $this->tenant), ['contract_id' => $this->contract->id, 'name' => 'Negado'])->assertForbidden();
    }

    public function test_only_the_contract_inspector_can_finalize_an_approved_order_with_history_and_notifications(): void
    {
        Notification::fake();
        $order = $this->awaitingDecision();
        $this->act('finalize', $order, $this->inspector)->assertSessionHasErrors('status');
        $this->act('decide', $order, $this->inspector, ['decision' => 'approve', 'comment' => 'Autorizado.'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertNull($order->fresh()->finalized_at);
        $this->get($this->url('show', $order))->assertInertia(fn (Assert $p) => $p->where('capabilities.finalize', true));
        foreach ([$this->analyst, $this->concremat, $this->viewer] as $u) {
            $this->act('finalize', $order, $u)->assertForbidden();
        }
        $this->act('finalize', $order, $this->inspector, ['version' => 1])->assertSessionHasErrors('version');
        $this->act('finalize', $order, $this->inspector)->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->finalized_at);
        $this->assertSame($this->inspector->id, $order->fresh()->finalized_by_id);
        $event = $order->events()->where('action', 'completed')->sole();
        $this->assertSame('approved', $event->from_status);
        $this->assertSame('completed', $event->to_status);
        foreach ([$this->analyst, $this->concremat, $this->inspector] as $u) {
            Notification::assertSentTo($u, OsDnitFlowChangedNotification::class, fn ($notification) => $notification->eventId === $event->id);
        }
        $this->act('finalize', $order, $this->inspector)->assertSessionHasErrors('status');
        $this->assertSame(1, $order->events()->where('action', 'completed')->count());
        $this->get(route('tenant.os-dnit.orders.index', [$this->tenant, 'stage' => 'completed']))->assertInertia(fn (Assert $p) => $p
            ->where('orders.total', 1)->where('stageCounts.completed', 1)->where('stageCounts.approved', 0)->where('orders.data.0.can_finalize', false));
        $renderer = app(OsDnitPdfRenderer::class);
        $html = view('pdf.os-dnit', $renderer->data($this->tenant, $order->fresh()))->render();
        $this->assertStringContainsString('OS FINALIZADA', $html);
        $this->assertStringContainsString('Análise Supervisão', $html);
        $this->assertStringNotContainsString('solicitação do analista ×', $html);
        if (getenv('OS_PDF_QA') === '1') {
            $path = base_path('tmp/pdfs');
            if (! is_dir($path)) {
                mkdir($path, 0777, true);
            }
            file_put_contents($path.'/ordem-servico-concluida.pdf', $renderer->render($renderer->data($this->tenant, $order->fresh()))->output());
        }
    }

    private function actor(?string $role): User
    {
        $user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $user->id, 'role' => $role === 'analyst' ? 'tenant_owner' : 'viewer', 'status' => 'active']);
        ContractParticipant::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'user_id' => $user->id, 'side' => 'client', 'role' => 'manager', 'status' => 'active']);
        if ($role) {
            OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        }

        return $user;
    }

    private function product(string $code, string $name): OsDnitProduct
    {
        return OsDnitProduct::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'code' => $code, 'name' => $name, 'category' => 'gestao_controle', 'unit' => 'mês', 'frequency' => 'monthly']);
    }

    private function draft(): OsDnitOrder
    {
        $this->actingAs($this->analyst)->post(route('tenant.os-dnit.orders.store', $this->tenant), $this->demand)->assertSessionHasNoErrors();

        return OsDnitOrder::sole();
    }

    private function awaitingDecision(): OsDnitOrder
    {
        $order = $this->draft();
        $this->act('submit', $order, $this->analyst)->assertSessionHasNoErrors();
        $this->act('review', $order, $this->concremat, $this->analysis())->assertSessionHasNoErrors();

        return $order->fresh();
    }

    private function analysis(string $mode = 'forward'): array
    {
        return ['mode' => $mode, 'review_comment' => 'Produto substituído após análise técnica.', 'estimated_deadline' => '2026-12-10',
            'items' => [['product_id' => $this->extra->id, 'quantity' => 2]]];
    }

    private function url(string $action, OsDnitOrder $order): string
    {
        return route("tenant.os-dnit.orders.{$action}", [$this->tenant, $order]);
    }

    private function act(string $action, OsDnitOrder $order, User $user, array $data = [])
    {
        return $this->actingAs($user)->post($this->url($action, $order), ['version' => $order->fresh()->workflow_version, ...$data]);
    }
}
