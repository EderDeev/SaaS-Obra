<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\RdoConfiguracao;
use App\Models\RdoResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RdoDailyGenerator;
use App\Support\DiarioObraPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDiarioObraPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_macro_permissions_are_split_and_scoped_by_contract(): void
    {
        [$tenant, $user, $contractA, $contractB] = $this->context([
            DiarioObraPermissions::VIEW,
            DiarioObraPermissions::FILL_RDA,
        ]);

        ContractParticipant::query()
            ->where('contract_id', $contractB->id)
            ->where('user_id', $user->id)
            ->update(['diario_obra_permissions' => []]);

        $this->actingAs($user)
            ->get(route('tenant.diario-obra.rdo.calendar', [
                'tenant' => $tenant,
                'contract_id' => $contractA->id,
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.diario-obra.rda.index', [
                'tenant' => $tenant,
                'contract_id' => $contractA->id,
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.diario-obra.rdo.calendar', [
                'tenant' => $tenant,
                'contract_id' => $contractB->id,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.diario-obra.rdo.dashboard', [
                'tenant' => $tenant,
                'contract_id' => $contractA->id,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.diario-obra.rdo.settings', [
                'tenant' => $tenant,
                'contract_id' => $contractA->id,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.diario-obra.rdo.responsaveis.index', [
                'tenant' => $tenant,
                'contract_id' => $contractA->id,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.diario-obra.rdo.cadastros.index', $tenant))
            ->assertForbidden();
    }

    public function test_permission_screen_saves_contract_permissions_and_adds_view_automatically(): void
    {
        [$tenant, $user, $contract] = $this->context([], true);
        $owner = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->patch(route('tenant.permissions.update', $tenant), [
                'user_id' => $user->id,
                'contract_id' => $contract->id,
                'diario_obra_permissions' => [DiarioObraPermissions::FILL_RDO],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $participant = ContractParticipant::query()
            ->where('contract_id', $contract->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame([
            DiarioObraPermissions::VIEW,
            DiarioObraPermissions::FILL_RDO,
        ], $participant->diario_obra_permissions);
    }

    public function test_operational_responsibility_does_not_bypass_revoked_macro_permission(): void
    {
        [$tenant, $user, $contract] = $this->context([DiarioObraPermissions::VIEW], true);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Frente principal',
            'tipo' => 'pai',
        ]);
        $configuration = RdoConfiguracao::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'responsible_user_id' => $user->id,
            'created_by_id' => $user->id,
            'start_date' => '2026-01-01',
            'generation_time' => '00:00',
            'timezone' => 'America/Sao_Paulo',
            'generation_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'generate_on_holidays' => true,
            'copy_previous_day' => false,
            'copy_workforce' => true,
            'copy_equipment' => true,
            'copy_pending_activities' => true,
            'require_photos' => false,
            'digital_signature_enabled' => true,
            'submission_deadline_days' => 7,
            'active' => true,
        ]);
        $configuration->obras()->attach($obra->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::now('America/Sao_Paulo')->startOfDay(),
            false,
            $user->id,
        );
        RdoResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'user_id' => $user->id,
            'created_by_id' => $user->id,
            'modulo' => 'rdo',
            'etapa' => 'construtora',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'submit',
            ])
            ->assertForbidden();
    }

    private function context(array $permissions, bool $oneContract = false): array
    {
        $tenant = Tenant::create([
            'slug' => 'diario-obra-'.str()->lower(str()->random(8)),
            'name' => 'Tenant Diario de Obra',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'diario_obra_permissions' => $permissions,
        ]);
        $contractA = $this->contract($tenant, 'CT-RDO-A');
        $this->participant($tenant, $contractA, $user, $permissions);

        if ($oneContract) {
            return [$tenant, $user, $contractA];
        }

        $contractB = $this->contract($tenant, 'CT-RDO-B');
        $this->participant($tenant, $contractB, $user, $permissions);

        return [$tenant, $user, $contractA, $contractB];
    }

    private function contract(Tenant $tenant, string $code): Contract
    {
        return Contract::create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'name' => "Contrato {$code}",
            'status' => 'active',
        ]);
    }

    private function participant(Tenant $tenant, Contract $contract, User $user, array $permissions): ContractParticipant
    {
        return ContractParticipant::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'diario_obra_permissions' => $permissions,
        ]);
    }
}
