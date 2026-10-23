<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\OsDnitProduct;
use App\Models\OsDnitResponsible;
use App\Models\OsDnitSupervisionLot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_creation_saves_demand_product_quantities_and_private_attachments(): void
    {
        Storage::fake('local');
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        [$otherTenant] = $this->context();
        $file = UploadedFile::fake()->create('demanda.pdf', 10, 'application/pdf');

        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), [
            ...$this->payload($contract, $highway, $product), 'tenant_id' => $otherTenant->id,
            'created_by_id' => 9999, 'status' => 'approved', 'attachments' => [$file],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = OsDnitOrder::with(['items', 'attachments'])->sole();
        $this->assertSame($tenant->id, $order->tenant_id);
        $this->assertSame($user->id, $order->created_by_id);
        $this->assertSame('draft', $order->status);
        $this->assertCount(0, $order->items);
        Storage::disk('local')->assertExists($order->attachments->sole()->path);

        $product->update(['name' => 'Nome atualizado', 'unit' => 'km']);
        $highway->update(['name' => 'Nome atualizado da rodovia']);
        $this->get(route('tenant.os-dnit.orders.show', [$tenant, $order]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenant/OsDnit/ShowOrder')
                ->where('order.highway_name', 'BR-364/AC')->has('order.items', 0)->missing('order.attachments.0.path'));
        $this->get(route('tenant.os-dnit.orders.attachments.download', [$tenant, $order, $order->attachments->sole()]))
            ->assertOk()->assertDownload('demanda.pdf');
    }

    public function test_request_numbers_are_unique_per_contract(): void
    {
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $payload = $this->payload($contract, $highway, $product);
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        $year = now()->year;
        $this->assertSame([sprintf('001/%d', $year), sprintf('002/%d', $year)], OsDnitOrder::where('contract_id', $contract->id)->orderBy('id')->pluck('request_number')->all());
        $otherContract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-OTHER', 'name' => 'Outro contrato', 'status' => 'active']);
        $this->link($tenant, $otherContract, $user ?? $admin);
        [$otherHighway, $otherProduct] = $this->catalog($otherContract);
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $this->payload($otherContract, $otherHighway, $otherProduct))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('os_dnit_orders', 3);
        $this->assertSame(sprintf('001/%d', now()->year), OsDnitOrder::where('contract_id', $otherContract->id)->sole()->request_number);
        $this->get(route('tenant.os-dnit.orders.create', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("numberPreviews.{$contract->id}", sprintf('003/%d', $year))
                ->where("numberPreviews.{$otherContract->id}", sprintf('002/%d', $year)));
    }

    public function test_number_preview_does_not_consume_or_reserve_a_number(): void
    {
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $this->actingAs($user);
        for ($i = 0; $i < 2; $i++) {
            $this->get(route('tenant.os-dnit.orders.create', $tenant))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where("numberPreviews.{$contract->id}", sprintf('001/%d', now()->year)));
        }
        $this->assertDatabaseCount('os_dnit_orders', 0);
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $this->payload($contract, $highway, $product))->assertSessionHasNoErrors();
        $this->assertSame(sprintf('001/%d', now()->year), OsDnitOrder::sole()->request_number);
        $this->get(route('tenant.os-dnit.orders.create', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where("numberPreviews.{$contract->id}", sprintf('002/%d', now()->year)));
    }

    public function test_invalid_ranges_quantities_and_repeated_products_are_rejected(): void
    {
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $payload = $this->payload($contract, $highway, $product);
        $this->actingAs($user);
        $url = route('tenant.os-dnit.orders.store', $tenant);
        $this->post($url, [...$payload, 'km_end' => '0'])->assertSessionHasErrors('km_end');
        $this->post($url, [...$payload, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertSessionHasErrors('items');
        $this->post($url, [...$payload, 'items' => [['product_id' => $product->id, 'quantity' => 0]]])->assertSessionHasErrors('items');
        $this->post($url, [...$payload, 'items' => [['product_id' => $product->id, 'quantity' => '0.0001']]])->assertSessionHasErrors('items');
        $this->post($url, [...$payload, 'items' => [['product_id' => $product->id, 'quantity' => 1], ['product_id' => $product->id, 'quantity' => 2]]])->assertSessionHasErrors('items');
        $this->post($url, [...$payload, 'desired_deadline' => 'invalid'])->assertSessionHasErrors('desired_deadline');
        $this->assertDatabaseCount('os_dnit_orders', 0);
        $this->assertDatabaseCount('os_dnit_order_items', 0);
    }

    public function test_cross_tenant_references_and_inactive_highways_are_rejected(): void
    {
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        [$otherTenant, $otherUser, $otherContract, $otherHighway, $otherProduct] = $this->context();
        $payload = $this->payload($contract, $highway, $product);
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), [
            ...$payload, 'contract_id' => $otherContract->id, 'highway_id' => $otherHighway->id,
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('contract_id');
        $this->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload, 'highway_id' => $otherHighway->id, 'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]]])->assertSessionHasErrors(['highway_id', 'items']);
        $highway->update(['active' => false]);
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasErrors('highway_id');
        $this->get(route('tenant.os-dnit.orders.index', $otherTenant))->assertForbidden();
        $this->assertDatabaseCount('os_dnit_orders', 0);
    }

    public function test_contract_participants_cannot_read_or_create_orders_for_other_contracts(): void
    {
        Storage::fake('local');
        [$tenant, $admin, $contract, $highway, $product] = $this->context();
        $otherContract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-OTHER', 'name' => 'Outro contrato', 'status' => 'active']);
        $this->link($tenant, $otherContract, $user ?? $admin);
        [$otherHighway, $otherProduct] = $this->catalog($otherContract);
        $this->actingAs($admin)->post(route('tenant.os-dnit.orders.store', $tenant), $this->payload($contract, $highway, $product));
        $firstOrder = OsDnitOrder::sole();
        $this->post(route('tenant.os-dnit.orders.store', $tenant), [
            ...$this->payload($otherContract, $otherHighway, $otherProduct), 'attachments' => [UploadedFile::fake()->create('privado.pdf', 5, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $hiddenOrder = OsDnitOrder::with('attachments')->latest('id')->first();
        $user = User::factory()->create();
        ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'client', 'role' => 'team_member', 'status' => 'active']);
        $this->actingAs($user)->get(route('tenant.os-dnit.orders.index', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.id', $firstOrder->id));
        $this->get(route('tenant.os-dnit.orders.show', [$tenant, $hiddenOrder]))->assertNotFound();
        $this->get(route('tenant.os-dnit.orders.attachments.download', [$tenant, $hiddenOrder, $hiddenOrder->attachments->sole()]))->assertNotFound();
        $this->get(route('tenant.os-dnit.orders.attachments.download', [$tenant, $firstOrder, $hiddenOrder->attachments->sole()]))->assertNotFound();
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $this->payload($otherContract, $highway, $product))->assertSessionHasErrors('contract_id');
    }

    public function test_attachment_validation_blocks_invalid_files_without_creating_an_order(): void
    {
        Storage::fake('local');
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $payload = $this->payload($contract, $highway, $product);
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload,
            'attachments' => [UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')],
        ])->assertSessionHasErrors('attachments.0');
        $this->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload,
            'attachments' => [UploadedFile::fake()->create('script.php', 1, 'text/x-php')],
        ])->assertSessionHasErrors('attachments.0');
        $this->assertStringContainsString('Anexos', session('errors')->first('attachments.0'));
        $this->assertStringNotContainsString('attachments', session('errors')->first('attachments.0'));
        $this->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload,
            'attachments' => 'invalid',
        ])->assertSessionHasErrors('attachments');
        $this->assertStringContainsString('Anexos', session('errors')->first('attachments'));
        $this->assertStringNotContainsString('attachments', session('errors')->first('attachments'));
        $this->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload,
            'attachments' => array_map(fn ($i) => UploadedFile::fake()->create("anexo-{$i}.pdf", 1, 'application/pdf'), range(1, 11)),
        ])->assertSessionHasErrors(['attachments' => 'Anexos: selecione no máximo 10 arquivos.']);
        $this->assertDatabaseCount('os_dnit_orders', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_orders_are_paginated_with_ten_items_and_searchable(): void
    {
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $this->actingAs($user);
        for ($i = 1; $i <= 16; $i++) {
            $this->post(route('tenant.os-dnit.orders.store', $tenant), [
                ...$this->payload($contract, $highway, $product), 'request_number' => "OS-{$i}",
            ])->assertSessionHasNoErrors();
        }
        $this->get(route('tenant.os-dnit.orders.index', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenant/OsDnit/Orders')->has('orders.data', 10)->where('orders.total', 16)->where('orders.per_page', 10));
        $this->get(route('tenant.os-dnit.orders.index', [$tenant, 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 6)->where('orders.current_page', 2)->where('orders.data.0.request_number', sprintf('006/%d', now()->year)));
        $this->get(route('tenant.os-dnit.orders.index', [$tenant, 'q' => sprintf('016/%d', now()->year)]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.request_number', sprintf('016/%d', now()->year)));
    }

    public function test_listing_combines_lot_road_search_stage_and_pagination_filters(): void
    {
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $payload = $this->payload($contract, $highway, $product);
        $lot = OsDnitSupervisionLot::findOrFail($payload['supervision_lot_id']);
        $otherLot = OsDnitSupervisionLot::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'Lote 02', 'active' => true]);
        $otherRoad = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'BR-307/AC', 'active' => true]);
        $this->actingAs($user);
        for ($i = 0; $i < 12; $i++) {
            $this->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        }
        $approved = OsDnitOrder::first()->replicate();
        $approved->fill(['request_number' => '013/'.now()->year, 'status' => 'approved'])->save();
        $this->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload, 'supervision_lot_id' => $otherLot->id])->assertSessionHasNoErrors();
        $this->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload, 'highway_id' => $otherRoad->id])->assertSessionHasNoErrors();
        $deleted = OsDnitOrder::first()->replicate();
        $deleted->fill(['request_number' => '099/'.now()->year])->save();
        $deleted->delete();
        $lot->update(['name' => 'Lote renomeado', 'active' => false]);
        $highway->update(['name' => 'Rodovia renomeada', 'active' => false]);
        $index = route('tenant.os-dnit.orders.index', $tenant);
        $filters = ['contract_id' => $contract->id, 'supervision_lot_id' => $lot->id, 'highway_id' => $highway->id, 'q' => 'SUPERVISÃO'];

        $this->get($index.'?'.http_build_query([...$filters, 'stage' => 'analyst', 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('lots', 2)->has('highways', 2)
                ->where('filters.supervision_lot_id', (string) $lot->id)->where('filters.highway_id', (string) $highway->id)
                ->where('stageCounts.all', 13)->where('stageCounts.analyst', 12)->where('stageCounts.approved', 1)
                ->where('orders.total', 12)->where('orders.current_page', 2)->has('orders.data', 2)
                ->where('orders.first_page_url', fn ($url) => str_contains($url, 'supervision_lot_id='.$lot->id) && str_contains($url, 'highway_id='.$highway->id)));
        $this->get($index.'?'.http_build_query([...$filters, 'status' => 'approved']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('orders.total', 1)->where('orders.data.0.id', $approved->id));
        $this->get($index.'?'.http_build_query(['supervision_lot_id' => $lot->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('orders.total', 14));
        $this->get($index.'?'.http_build_query(['highway_id' => $highway->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('orders.total', 14));
        $redirect = $this->get(route('tenant.os-dnit.analysis.index', [$tenant, ...$filters]))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $redirectFilters);
        $this->assertEquals([...$filters, 'pending' => 1], $redirectFilters);
        $this->assertSame(parse_url($index, PHP_URL_PATH), parse_url($redirect->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_listing_lot_and_road_options_and_filters_are_scoped_to_accessible_contracts(): void
    {
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $lot = OsDnitSupervisionLot::findOrFail($this->payload($contract, $highway, $product)['supervision_lot_id']);
        $hiddenContract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'HIDDEN', 'name' => 'Sem acesso', 'status' => 'active']);
        [$hiddenRoad, $hiddenProduct] = $this->catalog($hiddenContract);
        $hiddenLot = OsDnitSupervisionLot::findOrFail($this->payload($hiddenContract, $hiddenRoad, $hiddenProduct)['supervision_lot_id']);
        [$foreignTenant, , $foreignContract, $foreignRoad, $foreignProduct] = $this->context();
        $foreignLot = OsDnitSupervisionLot::findOrFail($this->payload($foreignContract, $foreignRoad, $foreignProduct)['supervision_lot_id']);
        $index = route('tenant.os-dnit.orders.index', $tenant);
        $this->actingAs($user)->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('lots', 1)->where('lots.0.id', $lot->id)->has('highways', 1)->where('highways.0.id', $highway->id));
        foreach ([$hiddenLot->id, $foreignLot->id, 999999, 'invalid'] as $id) {
            $this->get($index.'?'.http_build_query(['supervision_lot_id' => $id]))->assertSessionHasErrors('supervision_lot_id');
        }
        foreach ([$hiddenRoad->id, $foreignRoad->id, 999999, 'invalid'] as $id) {
            $this->get($index.'?'.http_build_query(['highway_id' => $id]))->assertSessionHasErrors('highway_id');
        }
        $this->link($tenant, $hiddenContract, $user);
        $this->get($index.'?'.http_build_query(['contract_id' => $contract->id, 'supervision_lot_id' => $hiddenLot->id, 'highway_id' => $hiddenRoad->id]))
            ->assertSessionHasErrors(['supervision_lot_id', 'highway_id']);
        $this->get($index.'?'.http_build_query(['contract_id' => $contract->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('lots', 1)->where('lots.0.id', $lot->id)->has('highways', 1)->where('highways.0.id', $highway->id));
    }

    public function test_existing_accessible_contracts_are_available_regardless_of_status(): void
    {
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $contract->update(['status' => 'planning']);
        $this->actingAs($user)->get(route('tenant.os-dnit.orders.create', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('contracts', 1)->where('contracts.0.id', $contract->id));
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $this->payload($contract, $highway, $product))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('os_dnit_orders', 1);
    }

    public function test_request_number_is_generated_even_when_client_sends_a_different_number(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $payload = $this->payload($contract, $highway, $product);
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload, 'request_number' => '999/1999'])->assertSessionHasNoErrors();
        $this->assertSame('001/2026', OsDnitOrder::sole()->request_number);
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        $this->assertSame('002/2026', OsDnitOrder::latest('id')->first()->request_number);
    }

    public function test_numbering_restarts_in_a_new_year_and_preserves_previous_orders(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 31)->setTime(23, 59));
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $payload = $this->payload($contract, $highway, $product);
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(0, 1));
        $this->get(route('tenant.os-dnit.orders.create', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where("numberPreviews.{$contract->id}", '001/2027'));
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        $this->assertSame(['001/2026', '002/2026', '001/2027'], OsDnitOrder::orderBy('id')->pluck('request_number')->all());
    }

    public function test_numbering_continues_from_existing_numbers_without_renumbering_them(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
        [$tenant, $user, $contract, $highway, $product] = $this->context();
        $payload = $this->payload($contract, $highway, $product);
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        OsDnitOrder::sole()->update(['request_number' => '010/2026']);
        $this->get(route('tenant.os-dnit.orders.create', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where("numberPreviews.{$contract->id}", '011/2026'));
        $this->post(route('tenant.os-dnit.orders.store', $tenant), [...$payload, 'subject' => ''])->assertSessionHasErrors('subject');
        $this->post(route('tenant.os-dnit.orders.store', $tenant), $payload)->assertSessionHasNoErrors();
        $this->assertSame(['010/2026', '011/2026'], OsDnitOrder::orderBy('id')->pluck('request_number')->all());
    }

    private function link(Tenant $tenant, Contract $contract, User $user): void
    {
        ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'client', 'role' => 'manager', 'status' => 'active']);
        OsDnitResponsible::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'role' => 'analyst', 'active' => true]);
    }

    private function catalog(Contract $contract): array
    {
        $highway = OsDnitHighway::create(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'name' => 'BR-364/AC', 'active' => true]);
        $product = OsDnitProduct::create(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'code' => '02', 'name' => 'Controle de Obras', 'category' => 'gestao_controle', 'unit' => 'mês', 'frequency' => 'monthly']);

        return [$highway, $product];
    }

    private function context(): array
    {
        $tenant = Tenant::create(['slug' => 'orders-'.str()->lower(str()->random(8)), 'name' => 'Empresa DNIT', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $contract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-01', 'name' => 'Supervisão', 'status' => 'active']);
        $highway = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'BR-364/AC', 'active' => true]);
        $product = OsDnitProduct::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'code' => '02', 'name' => 'Controle de Obras', 'category' => 'gestao_controle', 'unit' => 'mês', 'frequency' => 'monthly']);

        $this->link($tenant, $contract, $user);

        return [$tenant, $user, $contract, $highway, $product];
    }

    private function payload(Contract $contract, OsDnitHighway $highway, OsDnitProduct $product): array
    {
        return ['contract_id' => $contract->id, 'supervision_lot_id' => OsDnitSupervisionLot::firstOrCreate(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'name' => 'Lote 01'], ['active' => true])->id, 'highway_id' => $highway->id,
            'km_start' => '10.500', 'km_end' => '25.750', 'subject' => 'Supervisão de trecho', 'desired_deadline' => '2026-12-01',
            'technical_need' => 'Levantamento das condições do trecho.', 'justification' => 'Subsidiar a execução dos serviços.', 'sei_reference' => 'SEI 123'];
    }
}
