<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ActivityPermissionScenarioSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Este cenário visual só pode ser criado no ambiente local.');
        }

        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => 'teste-atividades'],
            [
                'name' => 'Teste Visual de Atividades',
                'plan' => 'starter',
                'status' => 'active',
            ],
        );

        $contractA = Contract::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'ATV-001'],
            ['name' => 'Cenários de Permissão', 'status' => 'active'],
        );
        $contractB = Contract::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'ATV-002'],
            ['name' => 'Isolamento por Contrato', 'status' => 'active'],
        );

        $profiles = [
            'admin.atividades@obras.test' => [
                'name' => 'Admin Atividades',
                'role' => 'tenant_owner',
                'permissions' => ActivityPermissions::all(),
            ],
            'visualizador.atividades@obras.test' => [
                'name' => 'Visualizador Atividades',
                'role' => 'engineer',
                'permissions' => [ActivityPermissions::VIEW],
            ],
            'criador.atividades@obras.test' => [
                'name' => 'Criador Atividades',
                'role' => 'engineer',
                'permissions' => [ActivityPermissions::VIEW, ActivityPermissions::CREATE],
            ],
            'editor.atividades@obras.test' => [
                'name' => 'Editor Atividades',
                'role' => 'engineer',
                'permissions' => [ActivityPermissions::VIEW, ActivityPermissions::EDIT],
            ],
            'exclusao.atividades@obras.test' => [
                'name' => 'Exclusão Atividades',
                'role' => 'engineer',
                'permissions' => [ActivityPermissions::VIEW, ActivityPermissions::DELETE],
            ],
            'metricas.atividades@obras.test' => [
                'name' => 'Métricas Atividades',
                'role' => 'engineer',
                'permissions' => [ActivityPermissions::VIEW, ActivityPermissions::VIEW_METRICS],
            ],
            'responsavel.atividades@obras.test' => [
                'name' => 'Responsável Atividades',
                'role' => 'engineer',
                'permissions' => [ActivityPermissions::VIEW],
            ],
            'sempermissao.atividades@obras.test' => [
                'name' => 'Sem Permissão Atividades',
                'role' => 'engineer',
                'permissions' => [],
            ],
        ];

        $users = [];

        foreach ($profiles as $email => $profile) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $profile['name'],
                    'password' => Hash::make('Senha1!'),
                    'email_verified_at' => now(),
                ],
            );
            $users[$email] = $user;

            $tenant->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => $profile['role'],
                    'status' => 'active',
                    'activity_permissions' => $profile['permissions'],
                ],
            );

            foreach ([$contractA, $contractB] as $contract) {
                $contract->participants()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'side' => 'manager',
                        'role' => 'team_member',
                        'status' => 'active',
                        'activity_permissions' => $profile['permissions'],
                    ],
                );
            }
        }

        $admin = $users['admin.atividades@obras.test'];
        $creator = $users['criador.atividades@obras.test'];
        $responsible = $users['responsavel.atividades@obras.test'];

        $this->upsertActivity($tenant, $contractA, $admin, [
            'title' => '[TESTE] Pública criada por outro usuário',
            'description' => 'Valida visualização e bloqueio de edição/exclusão sem permissão.',
            'category' => 'administrative',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        $ownActivity = $this->upsertActivity($tenant, $contractA, $creator, [
            'title' => '[TESTE] Criador pode editar e excluir',
            'description' => 'O perfil Criador deve gerenciar esta atividade mesmo sem permissões gerais de edição e exclusão.',
            'category' => 'project',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'in_progress',
            'priority' => 'high',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $assignedActivity = $this->upsertActivity($tenant, $contractA, $admin, [
            'title' => '[TESTE] Responsável pode movimentar',
            'description' => 'O responsável pode movimentar, comentar e anexar, mas não editar os detalhes nem excluir.',
            'category' => 'construction_diary',
            'visibility' => Activity::VISIBILITY_RESTRICTED,
            'status' => 'review',
            'priority' => 'urgent',
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $assignedActivity->update(['assigned_to_id' => $responsible->id]);
        $assignedActivity->assignees()->sync([$responsible->id]);

        $this->upsertActivity($tenant, $contractB, $admin, [
            'title' => '[TESTE] Permissões isoladas no segundo contrato',
            'description' => 'Usada para verificar que permissões não vazam entre contratos.',
            'category' => 'measurement',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
            'due_date' => now()->addDays(20)->toDateString(),
        ]);

        $this->upsertActivity($tenant, $contractA, $admin, [
            'title' => '[TESTE] Concluída no prazo para métricas',
            'description' => 'Registro preservado para conferência do dashboard.',
            'category' => 'documentation',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'done',
            'priority' => 'normal',
            'due_date' => now()->subDays(3)->toDateString(),
            'completed_at' => now()->subDays(4),
        ]);

        $ownActivity->assignees()->syncWithoutDetaching([$creator->id]);
    }

    private function upsertActivity(
        Tenant $tenant,
        Contract $contract,
        User $creator,
        array $attributes,
    ): Activity {
        return Activity::withTrashed()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'title' => $attributes['title'],
            ],
            [
                ...$attributes,
                'created_by_id' => $creator->id,
                'position' => 1,
                'deleted_at' => null,
            ],
        );
    }
}
