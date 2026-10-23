<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\OsDnitOrderEvent;
use App\Models\OsDnitResponsible;
use App\Models\OsDnitSupervisionLot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitDashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
    }

    public function test_cards_and_pending_orders_only_include_accessible_contracts_even_for_admin(): void
    {
        [$tenant, $user, $contract, $road] = $this->context();
        foreach (array_keys(OsDnitOrder::STATUSES) as $status) {
            $this->order($contract, $road, $user, $status);
        }
        [$hidden, $hiddenRoad] = $this->contract($tenant, null);
        $private = $this->order($hidden, $hiddenRoad, $user, 'awaiting_concremat');
        $this->event($private, 'submitted', '2026-10-11', 'draft', 'awaiting_concremat');
        [$otherTenant, $otherUser, $otherContract, $otherRoad] = $this->context();
        $foreign = $this->order($otherContract, $otherRoad, $otherUser, 'approved');
        $this->event($foreign, 'approved', '2026-10-12', 'awaiting_contract', 'approved');

        $this->actingAs($user)->get($this->url($tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/OsDnit/Dashboard')->has('contracts', 1)->has('highways', 1)
            ->where('stats', ['total' => 7, 'draft' => 1, 'concremat' => 1, 'inspector' => 1, 'approved' => 1, 'completed' => 1,
                'returned' => 2, 'returned_analyst' => 1, 'returned_concremat' => 1])
            ->has('pending.data', 6)->where('pending.total', 6)->where('monthly.0.submitted', 0)->where('monthly.0.approved', 0)
            ->where('pending.data.0.can_analyze', false));
        $this->get($this->url($tenant, ['contract_id' => $hidden->id]))->assertSessionHasErrors('contract_id');
        $this->get($this->url($tenant, ['highway_id' => $hiddenRoad->id]))->assertSessionHasErrors('highway_id');
        $this->get($this->url($otherTenant))->assertForbidden();
    }

    public function test_contract_road_and_opening_period_filters_apply_to_cards_and_pending(): void
    {
        [$tenant, $user, $contract, $road] = $this->context();
        $otherRoad = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'BR-307/AC', 'active' => false]);
        [$otherContract, $thirdRoad] = $this->contract($tenant, $user);
        $matching = $this->order($contract, $road, $user, 'awaiting_contract', '2026-10-10');
        $this->order($contract, $road, $user, 'approved', '2026-09-30');
        $this->order($contract, $otherRoad, $user, 'draft');
        $this->order($otherContract, $thirdRoad, $user, 'draft');
        $this->actingAs($user)->get($this->url($tenant, ['contract_id' => $contract->id, 'highway_id' => $road->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats.total', 1)->where('stats.inspector', 1)
            ->has('pending.data', 1)->where('pending.data.0.id', $matching->id)->has('highways', 2));
        $this->get($this->url($tenant, ['contract_id' => $contract->id, 'highway_id' => $thirdRoad->id]))->assertSessionHasErrors('highway_id');
        $this->get($this->url($tenant, ['highway_id' => $otherRoad->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.total', 1)->where('stats.draft', 1));
    }

    public function test_monthly_chart_uses_event_dates_fills_zero_months_and_deduplicates_resubmissions(): void
    {
        [$tenant, $user, $contract, $road] = $this->context();
        $old = $this->order($contract, $road, $user, 'approved', '2026-08-01');
        $this->event($old, 'submitted', '2026-09-02', 'draft', 'awaiting_concremat');
        $this->event($old, 'approved', '2026-10-02', 'awaiting_contract', 'approved');
        $this->event($old, 'approved', '2026-10-03', 'awaiting_contract', 'approved');
        $current = $this->order($contract, $road, $user, 'awaiting_concremat');
        $this->event($current, 'submitted', '2026-10-11', 'draft', 'awaiting_concremat');
        $this->event($current, 'submitted', '2026-10-12', 'returned_analyst', 'awaiting_concremat');
        $this->actingAs($user)->get($this->url($tenant, ['date_from' => '2026-09-01']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.total', 1)->where('stats.approved', 0)->has('monthly', 2)
                ->where('monthly.0.month', '2026-09')->where('monthly.0.submitted', 1)->where('monthly.0.approved', 0)
                ->where('monthly.1.month', '2026-10')->where('monthly.1.submitted', 1)->where('monthly.1.approved', 1));
        $this->get($this->url($tenant, ['date_from' => '2026-07-01']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('monthly', 4)->where('monthly.0.submitted', 0)->where('monthly.1.approved', 0));
    }

    public function test_pending_wait_ignores_saved_analysis_and_only_authorized_roles_can_analyze(): void
    {
        [$tenant, $user, $contract, $road] = $this->context();
        OsDnitResponsible::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'role' => 'concremat', 'active' => true]);
        $review = $this->order($contract, $road, $user, 'awaiting_concremat', '2026-10-01');
        $this->event($review, 'submitted', '2026-10-04 12:00', 'draft', 'awaiting_concremat');
        $this->event($review, 'analysis_saved', '2026-10-20 12:00', 'awaiting_concremat', 'awaiting_concremat');
        $inspector = $this->order($contract, $road, $user, 'awaiting_contract', '2026-10-02');
        $this->event($inspector, 'forwarded', '2026-10-10 12:00', 'awaiting_concremat', 'awaiting_contract');
        $legacy = $this->order($contract, $road, $user, 'draft', '2026-10-03 12:00');
        $this->actingAs($user)->get($this->url($tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pending.data.0.id', $legacy->id)->where('pending.data.0.waiting_days', 18)
            ->where('pending.data.1.id', $review->id)->where('pending.data.1.waiting_days', 17)->where('pending.data.1.can_analyze', true)
            ->where('pending.data.2.id', $inspector->id)->where('pending.data.2.waiting_days', 11)->where('pending.data.2.can_analyze', false));
    }

    public function test_empty_dashboard_and_inactive_participation_do_not_expose_data(): void
    {
        [$tenant, $user, $contract, $road] = $this->context();
        $this->order($contract, $road, $user, 'draft');
        ContractParticipant::where('user_id', $user->id)->update(['status' => 'inactive']);
        $this->actingAs($user)->get($this->url($tenant))->assertForbidden();
    }

    public function test_pending_orders_are_paginated_and_keep_filters(): void
    {
        [$tenant, $user, $contract, $road] = $this->context();
        for ($day = 1; $day <= 13; $day++) {
            $this->order($contract, $road, $user, 'draft', sprintf('2026-10-%02d', $day));
        }
        $this->actingAs($user)->get($this->url($tenant, ['contract_id' => $contract->id, 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pending.total', 13)->where('pending.per_page', 10)->where('pending.current_page', 2)
                ->has('pending.data', 3)->where('pending.data.0.request_number', '011/2026')->where('stats.total', 13)
                ->where('pending.first_page_url', fn ($url) => str_contains($url, 'contract_id='.$contract->id)));
    }

    public function test_lot_filter_applies_to_metrics_movements_and_paginated_pending_orders(): void
    {
        [$tenant, $user, $contract, $road] = $this->context();
        $lot = OsDnitSupervisionLot::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'Lote renomeado', 'active' => false]);
        $otherLot = OsDnitSupervisionLot::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'Lote 02', 'active' => true]);
        for ($i = 0; $i < 12; $i++) {
            $matching = $this->order($contract, $road, $user, 'draft');
            $matching->update(['supervision_lot_id' => $lot->id]);
        }
        $this->event($matching, 'submitted', '2026-10-12', 'draft', 'awaiting_concremat');
        $older = $this->order($contract, $road, $user, 'approved', '2026-09-10');
        $older->update(['supervision_lot_id' => $lot->id]);
        $this->event($older, 'approved', '2026-10-12', 'awaiting_contract', 'approved');
        $excluded = $this->order($contract, $road, $user, 'approved');
        $excluded->update(['supervision_lot_id' => $otherLot->id]);
        $this->event($excluded, 'approved', '2026-10-12', 'awaiting_contract', 'approved');
        [$secondContract, $secondRoad] = $this->contract($tenant, $user);
        $sameName = OsDnitSupervisionLot::create(['tenant_id' => $tenant->id, 'contract_id' => $secondContract->id, 'name' => $lot->name, 'active' => true]);
        $this->order($secondContract, $secondRoad, $user, 'draft')->update(['supervision_lot_id' => $sameName->id]);

        $this->actingAs($user)->get($this->url($tenant, ['supervision_lot_id' => $lot->id, 'page' => 2]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('lots', 3)
                ->where('filters.supervision_lot_id', (string) $lot->id)
                ->where('stats.total', 12)->where('stats.approved', 0)
                ->where('monthly.0.submitted', 1)->where('monthly.0.approved', 1)
                ->where('pending.total', 12)->where('pending.current_page', 2)->has('pending.data', 2)
                ->where('pending.first_page_url', fn ($url) => str_contains($url, 'supervision_lot_id='.$lot->id)));
        $this->get($this->url($tenant, ['contract_id' => $contract->id, 'highway_id' => $road->id, 'supervision_lot_id' => $lot->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('lots', 2)->where('stats.total', 12));
    }

    public function test_lot_options_and_validation_respect_tenant_contract_and_dashboard_access(): void
    {
        [$tenant, $user, $contract] = $this->context();
        $visible = OsDnitSupervisionLot::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'Lote público', 'active' => true]);
        [$otherContract] = $this->contract($tenant, $user);
        $other = OsDnitSupervisionLot::create(['tenant_id' => $tenant->id, 'contract_id' => $otherContract->id, 'name' => 'Outro contrato', 'active' => true]);
        [$hiddenContract] = $this->contract($tenant, null);
        $hidden = OsDnitSupervisionLot::create(['tenant_id' => $tenant->id, 'contract_id' => $hiddenContract->id, 'name' => 'Privado', 'active' => true]);
        [$foreignTenant, , $foreignContract] = $this->context();
        $foreign = OsDnitSupervisionLot::create(['tenant_id' => $foreignTenant->id, 'contract_id' => $foreignContract->id, 'name' => 'Outra empresa', 'active' => true]);
        $this->actingAs($user)->get($this->url($tenant, ['contract_id' => $contract->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('lots', 1)->where('lots.0.id', $visible->id));
        foreach ([$hidden->id, $foreign->id, 999999, 'invalid'] as $id) {
            $this->get($this->url($tenant, ['supervision_lot_id' => $id]))->assertSessionHasErrors('supervision_lot_id');
        }
        $this->get($this->url($tenant, ['contract_id' => $contract->id, 'supervision_lot_id' => $other->id]))
            ->assertSessionHasErrors('supervision_lot_id');
    }

    public function test_default_period_and_invalid_date_ranges(): void
    {
        [$tenant, $user] = $this->context();
        $this->actingAs($user)->get(route('tenant.os-dnit.dashboard', $tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.date_from', '2025-11-01')->where('filters.date_to', '2026-10-21')->has('monthly', 12));
        $this->get($this->url($tenant, ['date_from' => 'invalid']))->assertSessionHasErrors('date_from');
        $this->get($this->url($tenant, ['date_from' => '2026-10-22']))->assertSessionHasErrors('date_to');
        $this->get($this->url($tenant, ['date_from' => '2024-01-01']))->assertSessionHasErrors('date_from');
    }

    private function context(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa DNIT', 'slug' => 'dash-'.str()->random(8), 'status' => 'active', 'plan' => 'starter']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        [$contract, $road] = $this->contract($tenant, $user);

        return [$tenant, $user, $contract, $road];
    }

    private function contract(Tenant $tenant, ?User $user): array
    {
        $contract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-'.str()->random(6), 'name' => 'Contrato DNIT', 'status' => 'planning']);
        if ($user) {
            ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'client', 'role' => 'manager', 'status' => 'active']);
        }
        $road = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'BR-364/AC', 'active' => true]);

        return [$contract, $road];
    }

    private function order(Contract $contract, OsDnitHighway $road, User $user, string $status, string $created = '2026-10-10'): OsDnitOrder
    {
        $order = OsDnitOrder::create(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'highway_id' => $road->id,
            'created_by_id' => $user->id, 'request_number' => sprintf('%03d/2026', ++$this->sequence), 'supervision_lot' => 'Lote 01',
            'highway_name' => $road->name, 'km_start' => 1, 'km_end' => 2, 'subject' => 'Solicitação de serviço',
            'desired_deadline' => '2026-11-01', 'technical_need' => 'Descrição técnica.', 'justification' => 'Justificativa.', 'status' => $status]);

        return $order->forceFill(['created_at' => Carbon::parse($created), 'updated_at' => Carbon::parse($created)])->save() ? $order : throw new \RuntimeException('Order fixture failed.');
    }

    private function event(OsDnitOrder $order, string $action, string $date, string $from, string $to): void
    {
        $event = new OsDnitOrderEvent(['order_id' => $order->id, 'actor_id' => $order->created_by_id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'snapshot' => []]);
        $event->created_at = Carbon::parse($date);
        $event->updated_at = Carbon::parse($date);
        $event->save();
    }

    private function url(Tenant $tenant, array $filters = []): string
    {
        return route('tenant.os-dnit.dashboard', [$tenant->slug, ...['date_from' => '2026-10-01', 'date_to' => '2026-10-21', ...$filters]]);
    }
}
