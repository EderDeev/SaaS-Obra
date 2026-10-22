<?php

namespace Tests\Feature;

use App\Models\{Contract, ContractParticipant, OsDnitHighway, OsDnitOrder, OsDnitProduct, OsDnitResponsible, Tenant, User};
use App\Services\{OsDnitOrderHistory, OsDnitOrderNumber};
use App\Support\OsDnitPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private User $owner;
    private Contract $a;
    private Contract $b;
    private OsDnitHighway $road;
    private OsDnitProduct $product;
    private OsDnitOrder $order;
    private ContractParticipant $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 21));
        $this->tenant = Tenant::create(['name' => 'Permissões DNIT', 'slug' => 'dnit-permissions', 'plan' => 'starter', 'status' => 'active']);
        $this->user = User::factory()->create();
        $this->owner = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $this->user->id, 'role' => 'engineer', 'status' => 'active']);
        $this->tenant->memberships()->create(['user_id' => $this->owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        foreach (['a', 'b'] as $key) {
            $this->$key = Contract::create(['tenant_id' => $this->tenant->id, 'code' => strtoupper($key), 'name' => 'Contrato '.$key, 'status' => 'active']);
            $link = ContractParticipant::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->$key->id, 'user_id' => $this->user->id,
                'side' => 'client', 'role' => 'team_member', 'status' => 'active', 'os_dnit_permissions' => []]);
            if ($key === 'a') $this->link = $link;
        }
        $this->road = OsDnitHighway::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id, 'name' => 'BR-364/AC', 'active' => true]);
        $this->product = OsDnitProduct::create(['tenant_id' => $this->tenant->id, ...$this->productData()]);
        OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id, 'user_id' => $this->user->id, 'role' => 'analyst', 'active' => true]);
        $this->order = OsDnitOrder::create(['tenant_id' => $this->tenant->id, 'created_by_id' => $this->user->id, 'request_number' => '001/2026',
            ...collect($this->demand())->except(['items'])->all(), 'highway_name' => $this->road->name]);
        $this->order->items()->create(['product_id' => $this->product->id, 'product_code' => $this->product->code, 'product_name' => $this->product->name,
            'unit' => $this->product->unit, 'frequency' => $this->product->frequency, 'quantity' => 1]);
        app(OsDnitOrderHistory::class)->record($this->order, $this->user, 'created', null);
        $this->actingAs($this->user);
    }

    public function test_no_permission_hides_navigation_flags_and_blocks_routes_even_with_workflow_roles(): void
    {
        foreach (['orders.index', 'orders.create', 'products.index', 'dashboard', 'settings.index', 'responsibles.index', 'tour-preview'] as $route) {
            $this->get($this->url($route))->assertForbidden();
        }
        $this->get($this->url('orders.show', $this->order))->assertForbidden();
        $this->get($this->url('orders.pdf', $this->order))->assertForbidden();
        $this->post($this->url('orders.store'), $this->demand())->assertForbidden();
        $this->patch($this->url('orders.update', $this->order), [...$this->demand(), 'version' => 1])->assertForbidden();
        $this->delete($this->url('orders.destroy', $this->order), ['version' => 1])->assertForbidden();
        $this->get(route('tenant.dashboard', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('osDnitPermissions.can.view_orders', false)->where('osDnitPermissions.can.create_orders', false)
            ->where('osDnitNavigation.pending_analysis_count', 0));
        $this->assertFalse($this->order->fresh()->trashed());
    }

    public function test_view_only_is_read_only_and_cannot_access_other_submenus_or_contracts(): void
    {
        $this->grant([P::VIEW]);
        $this->get($this->url('tour-preview'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/OsDnit/TourPreview')->where('tenant.slug', $this->tenant->slug)
            ->missing('orders')->missing('products')->missing('responsibles'));
        $this->get($this->url('orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canCreate', false)->where('orders.data.0.can_submit', false)->where('orders.data.0.can_delete', false)
            ->has('contracts', 1)->where('osDnitPermissions.can.view_products', false));
        $this->get($this->url('orders.show', $this->order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('capabilities.edit', false)->where('capabilities.delete', false)->has('products', 0));
        foreach (['orders.create', 'products.index', 'dashboard', 'responsibles.index'] as $route) $this->get($this->url($route))->assertForbidden();
        $this->get($this->url('settings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreate', false)->where('highways.data.0.can_edit', false));
        $this->get(route('tenant.os-dnit.orders.index', [$this->tenant, 'contract_id' => $this->b->id]))->assertSessionHasErrors('contract_id');
    }

    public function test_create_and_edit_are_independent_and_still_require_the_analyst_role(): void
    {
        $this->grant([P::CREATE]);
        $this->get($this->url('orders.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('contracts', 1));
        $this->post($this->url('orders.store'), $this->demand())->assertSessionHasNoErrors();
        $this->assertSame('002/2026', OsDnitOrder::latest('id')->first()->request_number);
        $this->patch($this->url('orders.update', $this->order), [...$this->demand(), 'version' => 1])->assertForbidden();
        $this->post($this->url('orders.store'), [...$this->demand(), 'contract_id' => $this->b->id])->assertSessionHasErrors('contract_id');

        $this->grant([P::EDIT]);
        $this->get($this->url('orders.create'))->assertForbidden();
        $this->patch($this->url('orders.update', $this->order), [...$this->demand(), 'subject' => 'Pedido corrigido', 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Pedido corrigido', $this->order->fresh()->subject);
        $this->order->update(['status' => 'awaiting_concremat', 'submitted_at' => now()]);
        $this->patch($this->url('orders.update', $this->order), [...$this->demand(), 'version' => 2])->assertForbidden();
    }

    public function test_delete_preserves_history_files_and_numbers_and_rejects_sent_or_stale_orders(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private-anexo.pdf', 'original');
        $this->order->attachments()->create(['original_name' => 'anexo.pdf', 'storage_disk' => 'local', 'path' => 'private-anexo.pdf', 'size_bytes' => 8]);
        $this->grant([P::DELETE]);
        $this->delete($this->url('orders.destroy', $this->order), ['version' => 9])->assertSessionHasErrors('version');
        $this->order->update(['status' => 'awaiting_concremat', 'submitted_at' => now()]);
        $this->delete($this->url('orders.destroy', $this->order), ['version' => 1])->assertSessionHasErrors('order');
        $this->order->update(['status' => 'draft', 'submitted_at' => null]);
        $this->delete($this->url('orders.destroy', $this->order), ['version' => 1])->assertSessionHasNoErrors();
        $this->assertSoftDeleted('os_dnit_orders', ['id' => $this->order->id]);
        $this->assertDatabaseHas('os_dnit_order_events', ['order_id' => $this->order->id, 'actor_id' => $this->user->id, 'action' => 'deleted']);
        $this->assertDatabaseCount('os_dnit_order_items', 1);
        Storage::disk('local')->assertExists('private-anexo.pdf');
        $this->get($this->url('orders.show', $this->order))->assertNotFound();
        $this->assertSame('002/2026', app(OsDnitOrderNumber::class)->previews($this->user, $this->tenant)[$this->a->id]);
    }

    public function test_product_permissions_are_independent_and_existing_order_labels_are_preserved(): void
    {
        $this->grant([P::VIEW_PRODUCTS]);
        $this->get($this->url('products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canCreate', false)->where('products.data.0.can_edit', false)->where('products.data.0.can_delete', false));
        $this->post($this->url('products.store'), $this->productData('03'))->assertForbidden();
        $this->patch($this->url('products.update', $this->product), $this->productData())->assertForbidden();
        $this->delete($this->url('products.destroy', $this->product))->assertForbidden();
        $this->grant([P::CREATE_PRODUCTS]);
        $this->post($this->url('products.store'), $this->productData('03'))->assertSessionHasNoErrors();
        $this->patch($this->url('products.update', $this->product), $this->productData())->assertForbidden();
        $this->post($this->url('products.store'), [...$this->productData('04'), 'contract_id' => $this->b->id])->assertSessionHasErrors('contract_id');
        $this->grant([P::EDIT_PRODUCTS]);
        $this->patch($this->url('products.update', $this->product), [...$this->productData(), 'name' => 'Produto atualizado'])->assertSessionHasNoErrors();
        $this->assertSame('Produto atualizado', $this->product->fresh()->name);
        $this->assertSame('Controle de Obras', $this->order->items()->sole()->product_name);
        $this->patch($this->url('products.update', $this->product), [...$this->productData(), 'contract_id' => $this->b->id])->assertSessionHasErrors('contract_id');
    }

    public function test_used_products_and_highways_cannot_be_deleted_but_unused_records_can(): void
    {
        $this->grant([P::DELETE_PRODUCTS, P::DELETE_SETTINGS]);
        $unused = OsDnitProduct::create(['tenant_id' => $this->tenant->id, ...$this->productData('EXTRA')]);
        $this->order->update(['review_items' => [['product_id' => $unused->id, 'product_code' => $unused->code, 'product_name' => $unused->name, 'unit' => 'mês', 'quantity' => '1.000']]]);
        $this->delete($this->url('products.destroy', $unused))->assertSessionHasErrors('resource');
        $this->delete($this->url('products.destroy', $this->product))->assertSessionHasErrors('resource');
        $this->delete($this->url('settings.highways.destroy', $this->road))->assertSessionHasErrors('resource');
        $this->order->update(['review_items' => null]);
        $this->delete($this->url('products.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('os_dnit_products', ['id' => $unused->id]);
        $unusedRoad = OsDnitHighway::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id, 'name' => 'BR-TESTE', 'active' => true]);
        $this->delete($this->url('settings.highways.destroy', $unusedRoad))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('os_dnit_highways', ['id' => $unusedRoad->id]);
    }

    public function test_settings_dashboard_and_responsibles_are_separately_protected(): void
    {
        $this->grant([P::CREATE_SETTINGS]);
        $this->post($this->url('settings.highways.store'), ['contract_id' => $this->a->id, 'name' => 'BR-NOVA'])->assertSessionHasNoErrors();
        $this->patch($this->url('settings.highways.update', $this->road), ['contract_id' => $this->a->id, 'name' => 'BR-EDITADA', 'active' => true])->assertForbidden();
        $this->grant([P::EDIT_SETTINGS]);
        $this->patch($this->url('settings.highways.update', $this->road), ['contract_id' => $this->a->id, 'name' => 'BR-EDITADA', 'active' => true])->assertSessionHasNoErrors();
        $this->assertSame('BR-364/AC', $this->order->fresh()->highway_name);
        $this->grant([P::DASHBOARD]);
        $this->get($this->url('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats.total', 1)->has('contracts', 1));
        $this->get($this->url('responsibles.index'))->assertForbidden();
        $this->grant([P::RESPONSIBLES]);
        $this->get($this->url('responsibles.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', true)->has('contracts', 1));
        $this->post($this->url('responsibles.store'), ['contract_id' => $this->a->id, 'assignments' => [['user_id' => $this->user->id, 'role' => 'concremat']]])->assertSessionHasNoErrors();
        $this->post($this->url('responsibles.store'), ['contract_id' => $this->b->id, 'assignments' => []])->assertSessionHasErrors('contract_id');
    }

    public function test_permissions_screen_registers_thirteen_keys_and_saves_only_the_target_contract(): void
    {
        $this->link->update(['project_permissions' => ['view_projects']]);
        $this->actingAs($this->owner)->get(route('tenant.permissions.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('permissionGroups.os_dnit.permissions', 13)->where("osDnitPermissionsByUserContract.{$this->user->id}:{$this->a->id}", []));
        $url = route('tenant.permissions.update', $this->tenant);
        $this->patch($url, ['user_id' => $this->user->id, 'contract_id' => $this->a->id, 'os_dnit_permissions' => [P::CREATE_PRODUCTS]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([P::VIEW, P::VIEW_PRODUCTS, P::CREATE_PRODUCTS], $this->link->fresh()->os_dnit_permissions);
        $this->assertSame(['view_projects'], $this->link->fresh()->project_permissions);
        $this->assertSame([], ContractParticipant::where('contract_id', $this->b->id)->where('user_id', $this->user->id)->sole()->os_dnit_permissions);
        $this->patch($url, ['user_id' => $this->user->id, 'contract_id' => $this->a->id, 'os_dnit_permissions' => ['invalid']])->assertSessionHasErrors('os_dnit_permissions.0');
        $this->patch($url, ['user_id' => $this->user->id, 'contract_id' => $this->a->id, 'os_dnit_permissions' => []])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->get($this->url('orders.index'))->assertForbidden();
    }

    public function test_tenant_admin_permissions_are_contract_specific_and_inactive_memberships_do_not_grant_access(): void
    {
        $this->tenant->memberships()->where('user_id', $this->user->id)->update(['role' => 'tenant_admin']);
        $this->grant([P::VIEW]);
        $this->get($this->url('products.index'))->assertForbidden();
        $this->get($this->url('orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('contracts', 1));
        $this->link->update(['status' => 'inactive']);
        $this->get($this->url('orders.show', $this->order))->assertNotFound();
        $this->get($this->url('orders.index'))->assertForbidden();
    }

    public function test_revocation_blocks_analysis_and_removes_email_recipients_even_with_an_assigned_role(): void
    {
        OsDnitResponsible::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id, 'user_id' => $this->user->id, 'role' => 'concremat', 'active' => true]);
        $this->order->update(['status' => 'awaiting_concremat', 'submitted_at' => now()]);
        $event = $this->order->events()->create(['actor_id' => $this->user->id, 'action' => 'submitted', 'from_status' => 'draft', 'to_status' => 'awaiting_concremat', 'snapshot' => []]);
        $recipients = app(\App\Services\OsDnitFlowRecipients::class);
        $this->grant([P::VIEW]);
        $this->getJson($this->url('analysis.show', $this->order))->assertOk()->assertJsonPath('capabilities.review', true);
        $this->assertSame([$this->user->id], $recipients->forEvent($event)->modelKeys());
        $this->grant([]);
        $this->getJson($this->url('analysis.show', $this->order))->assertForbidden();
        $this->post($this->url('orders.review', $this->order), ['version' => 1, 'mode' => 'forward'])->assertForbidden();
        $this->assertCount(0, $recipients->forEvent($event));
        $this->assertSame('awaiting_concremat', $this->order->fresh()->status);
    }

    public function test_permission_updates_preserve_other_groups_and_reject_missing_contract_links(): void
    {
        $url = route('tenant.permissions.update', $this->tenant);
        $this->actingAs($this->owner)->patch($url, ['user_id' => $this->user->id, 'contract_id' => $this->a->id,
            'project_permissions' => ['view_projects'], 'os_dnit_permissions' => [P::EDIT_PRODUCTS]])->assertSessionHasNoErrors();
        $this->assertSame(['view_projects'], $this->link->fresh()->project_permissions);
        $saved = $this->link->fresh()->os_dnit_permissions;
        $this->patch($url, ['user_id' => $this->user->id, 'contract_id' => $this->a->id, 'project_permissions' => []])->assertSessionHasNoErrors();
        $this->assertSame($saved, $this->link->fresh()->os_dnit_permissions);
        $this->link->update(['status' => 'inactive']);
        $this->patch($url, ['user_id' => $this->user->id, 'contract_id' => $this->a->id, 'os_dnit_permissions' => [P::VIEW]])->assertSessionHasErrors('contract_id');
        $this->assertSame('inactive', $this->link->fresh()->status);
        $this->assertSame($saved, $this->link->fresh()->os_dnit_permissions);
    }

    private function grant(array $permissions): void { $this->link->update(['os_dnit_permissions' => P::normalize($permissions)]); }
    private function url(string $route, mixed $model = null): string { return route('tenant.os-dnit.'.$route, $model ? [$this->tenant, $model] : [$this->tenant]); }
    private function productData(string $code = '02'): array { return ['contract_id' => $this->a->id, 'code' => $code, 'name' => 'Controle de Obras', 'category' => 'gestao_controle', 'unit' => 'mês', 'frequency' => 'monthly']; }
    private function demand(): array { return ['contract_id' => $this->a->id, 'highway_id' => $this->road->id, 'supervision_lot' => '01', 'km_start' => 1, 'km_end' => 2,
        'subject' => 'Serviço de teste', 'technical_need' => 'Necessidade técnica', 'justification' => 'Justificativa', 'desired_deadline' => '2026-12-01', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]]; }
}
