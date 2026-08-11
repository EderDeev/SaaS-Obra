<?php

namespace Tests\Feature;

use App\Models\BoletimMedicao;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoAnaliseResponsavel;
use App\Models\FolhaRostoFluxoHistorico;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantMedicaoFlowReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_fr_flow_is_audited_and_reported_by_bm(): void
    {
        Notification::fake();

        $tenant = Tenant::create([
            'slug' => 'fluxo-fr',
            'name' => 'Fluxo FR',
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $submitter = $this->owner($tenant, 'Construtora');
        $fiscal = $this->owner($tenant, 'Fiscal');
        $qualidade = $this->owner($tenant, 'Qualidade');
        $medicao = $this->owner($tenant, 'Medição');

        $contract = $tenant->contracts()->create([
            'code' => 'CT-FLUXO',
            'name' => 'Contrato fluxo',
            'status' => 'active',
            'measurement_mode' => 'simple',
        ]);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '001',
            'nome' => 'Obra fluxo',
            'tipo' => 'pai',
        ]);
        $boletim = BoletimMedicao::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $submitter->id,
            'codigo' => 'BM-001',
            'sequencial' => 1,
            'periodo' => '2026-08-01',
            'tipo' => 'normal',
            'status' => 'aberto_lancamento',
        ]);
        $folha = FolhaRosto::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'boletim_medicao_id' => $boletim->id,
            'created_by_id' => $submitter->id,
            'codigo' => 'FR-001',
            'sequencial' => 1,
            'comentario' => 'Pleito para auditoria do fluxo.',
            'status' => 'rascunho',
        ]);

        foreach ([
            'fiscal' => $fiscal,
            'qualidade' => $qualidade,
            'medicao' => $medicao,
        ] as $etapa => $responsavel) {
            FolhaRostoAnaliseResponsavel::create([
                'tenant_id' => $tenant->id,
                'user_id' => $responsavel->id,
                'created_by_id' => $submitter->id,
                'etapa' => $etapa,
                'status' => 'active',
            ]);
        }

        $this->actingAs($submitter)
            ->patch(route('tenant.medicao.folha-rosto.submit-analysis', [$tenant, $folha]))
            ->assertRedirect();

        $submission = FolhaRostoFluxoHistorico::query()->where('acao', 'submeter_analise')->firstOrFail();
        $this->assertSame($submitter->id, $submission->user_id);
        $this->assertSame('analise_fiscal', $submission->status_destino);
        $this->assertSame($fiscal->id, $submission->responsaveis_snapshot[0]['id']);

        $this->saveAnalysis($tenant, $folha->refresh(), $fiscal, 'fiscal', 'Fiscal conferiu o pleito.');
        $this->moveFlow($tenant, $folha->refresh(), $fiscal, 'qualidade');
        $this->saveAnalysis($tenant, $folha->refresh(), $qualidade, 'qualidade', 'Qualidade validou os serviços.');
        $this->moveFlow($tenant, $folha->refresh(), $qualidade, 'medicao');
        $this->saveAnalysis($tenant, $folha->refresh(), $medicao, 'medicao', 'Medição validou os quantitativos.');
        $this->moveFlow($tenant, $folha->refresh(), $medicao, 'finalizar');

        $this->assertSame('analisada', $folha->refresh()->status);
        $this->assertSame(7, $folha->fluxoHistoricos()->count());

        $qualityMovement = $folha->fluxoHistoricos()->where('acao', 'qualidade')->firstOrFail();
        $this->assertSame($qualidade->id, $qualityMovement->responsaveis_snapshot[0]['id']);

        $this->actingAs($submitter)
            ->get(route('tenant.medicao.relatorios.index', [
                'tenant' => $tenant,
                'contract_id' => $contract->id,
                'boletim_id' => $boletim->id,
                'relatorio' => 'fluxo_fr',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Medicao/Relatorios/Index')
                ->where('selectedReport', 'fluxo_fr')
                ->where('reportData.title', 'Fluxo das FRs')
                ->where('reportData.rows', function ($rows) use ($submitter, $fiscal, $qualidade, $medicao): bool {
                    $events = collect($rows)->reject(fn (array $row): bool => (bool) ($row['_is_group'] ?? false));

                    return $events->count() === 7
                        && $events->contains(fn (array $row): bool => $row['evento'] === 'Enviada para análise'
                            && str_contains($row['executado_por'], $submitter->email)
                            && str_contains($row['responsaveis'], $fiscal->email))
                        && $events->contains(fn (array $row): bool => $row['etapa'] === 'Qualidade'
                            && str_contains($row['responsaveis'], $qualidade->email))
                        && $events->contains(fn (array $row): bool => $row['evento'] === 'Finalizada'
                            && str_contains($row['executado_por'], $medicao->email));
                }));

        $this->actingAs($submitter)
            ->get(route('tenant.medicao.relatorios.fluxo-fr.excel', [
                'tenant' => $tenant,
                'boletim_id' => $boletim->id,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($submitter)
            ->get(route('tenant.medicao.relatorios.fluxo-fr.pdf', [
                'tenant' => $tenant,
                'boletim_id' => $boletim->id,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function owner(Tenant $tenant, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);

        return $user;
    }

    private function saveAnalysis(Tenant $tenant, FolhaRosto $folha, User $actor, string $sector, string $comment): void
    {
        $this->actingAs($actor)
            ->post(route('tenant.medicao.analisar-pleito.analise.store', [$tenant, $folha]), [
                'setores' => [
                    $sector => [
                        'comentario_geral' => $comment,
                        'itens' => [],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    private function moveFlow(Tenant $tenant, FolhaRosto $folha, User $actor, string $action): void
    {
        $this->actingAs($actor)
            ->patch(route('tenant.medicao.analisar-pleito.fluxo', [$tenant, $folha]), [
                'action' => $action,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
