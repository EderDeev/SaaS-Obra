<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $demoPassword = Hash::make('Senha1!');

        $platformAdmin = User::updateOrCreate(
            ['email' => 'admin@obras.test'],
            [
                'name' => 'Admin Plataforma',
                'password' => $demoPassword,
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Gerenciadora Demo',
                'cnpj' => '00.000.000/0001-00',
                'plan' => 'starter',
                'status' => 'active',
                'branding' => ['primary_color' => '#111827'],
                'settings' => [],
            ],
        );

        $owner = User::updateOrCreate(
            ['email' => 'owner@demo.test'],
            [
                'name' => 'Owner Demo',
                'password' => $demoPassword,
                'email_verified_at' => now(),
            ],
        );

        $engineer = User::updateOrCreate(
            ['email' => 'engenheiro@demo.test'],
            [
                'name' => 'Engenheira da Obra',
                'password' => $demoPassword,
                'email_verified_at' => now(),
            ],
        );

        $client = User::updateOrCreate(
            ['email' => 'cliente@demo.test'],
            [
                'name' => 'Cliente Aprovador',
                'password' => $demoPassword,
                'email_verified_at' => now(),
            ],
        );

        $contractor = User::updateOrCreate(
            ['email' => 'construtora@demo.test'],
            [
                'name' => 'Líder da Construtora',
                'password' => $demoPassword,
                'email_verified_at' => now(),
            ],
        );

        $tenant->memberships()->updateOrCreate(
            ['user_id' => $owner->id],
            ['role' => 'tenant_owner', 'status' => 'active', 'joined_at' => now()],
        );

        $tenant->memberships()->updateOrCreate(
            ['user_id' => $engineer->id],
            ['role' => 'engenheiro_campo', 'status' => 'active', 'joined_at' => now()],
        );

        $contract = $tenant->contracts()->updateOrCreate(
            ['code' => 'CT-2026-001'],
            [
                'name' => 'Residencial Jardim Central',
                'client_company_name' => 'Cliente Demo Ltda.',
                'contractor_company_name' => 'Construtora Exemplo S.A.',
                'total_value' => 1250000,
                'currency' => 'BRL',
                'city' => 'São Paulo',
                'state' => 'SP',
                'starts_at' => now()->toDateString(),
                'ends_at' => now()->addMonths(10)->toDateString(),
                'status' => 'active',
            ],
        );

        $contract->participants()->updateOrCreate(
            ['user_id' => $owner->id, 'side' => 'manager'],
            ['tenant_id' => $tenant->id, 'role' => 'manager', 'status' => 'active', 'joined_at' => now()],
        );

        $contract->participants()->updateOrCreate(
            ['user_id' => $engineer->id, 'side' => 'manager'],
            ['tenant_id' => $tenant->id, 'role' => 'team_member', 'status' => 'active', 'joined_at' => now()],
        );

        $contract->participants()->updateOrCreate(
            ['user_id' => $client->id, 'side' => 'client'],
            ['tenant_id' => $tenant->id, 'role' => 'client_approver', 'status' => 'active', 'joined_at' => now()],
        );

        $contract->participants()->updateOrCreate(
            ['user_id' => $contractor->id, 'side' => 'contractor'],
            ['tenant_id' => $tenant->id, 'role' => 'contractor_lead', 'status' => 'active', 'joined_at' => now()],
        );

        $platformAdmin->tenants()->syncWithoutDetaching([
            $tenant->id => ['role' => 'tenant_admin', 'status' => 'active', 'joined_at' => now()],
        ]);
    }
}
