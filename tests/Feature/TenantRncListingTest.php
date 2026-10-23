<?php

namespace Tests\Feature;

use App\Models\{Contract, ContractParticipant, Empresa, Obra, RelatorioNaoConformidade, RelatorioNaoConformidadeResponsavel, Tenant, TipoEmpresa, User};
use App\Support\{RncListing, RncPermissions};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantRncListingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Contract $a;
    private Contract $b;
    private Obra $obraA;
    private Obra $obraB;
    private Empresa $empresa;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'RNC filtros', 'slug' => 'rnc-filtros', 'plan' => 'starter', 'status' => 'active']);
        $this->user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $this->user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        foreach (['a', 'b'] as $key) {
            $this->$key = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'CT-'.strtoupper($key), 'name' => 'Contrato '.$key, 'status' => 'active']);
            $field = 'obra'.strtoupper($key);
            $this->$field = Obra::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->$key->id,
                'codigo' => 'OB-'.strtoupper($key), 'nome' => 'Obra '.$key, 'tipo' => 'pai']);
        }
        $this->empresa = Empresa::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id,
            'tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id,
            'nome' => 'Empresa teste', 'cnpj' => '11.111.111/0001-11', 'sigla' => 'ET']);
        $this->actingAs($this->user);
    }

    public function test_tabs_classify_the_existing_workflow_using_only_the_latest_proposal(): void
    {
        $expected = [];
        foreach (array_keys(RncListing::STATUSES) as $status) $expected[$status] = $this->rnc($status);
        $this->proposal($expected['awaiting_review'], 'approved', now()->subDay());
        // Same submission timestamp: higher ID wins, matching the list actions.
        $this->proposal($expected['awaiting_review'], 'rejected', now()->subDays(2));
        $this->proposal($expected['awaiting_review'], 'pending', now());
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stageCounts.all', 7)->where('stageCounts.draft', 1)->where('stageCounts.corrective_action', 2)
            ->where('stageCounts.analysis', 1)->where('stageCounts.evidence', 1)->where('stageCounts.signatures', 1)->where('stageCounts.completed', 1)
            ->where('rncs.data', function ($records) use ($expected) {
                foreach ($expected as $status => $rnc) $this->assertSame($status, collect($records)->firstWhere('id', $rnc->id)['listing_status']);
                return true;
            }));
        foreach (RncListing::STAGES as $stage => $statuses) {
            $this->get($this->url(['stage' => $stage]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('rncs.total', count($statuses))->where('stageCounts.all', 7)
                ->where('rncs.data', function ($records) use ($statuses) {
                    $this->assertEqualsCanonicalizing($statuses, collect($records)->pluck('listing_status')->all());
                    return true;
                }));
        }
        $this->get($this->url(['stage' => 'analysis']))->assertInertia(fn (Assert $page) => $page
            ->where('rncs.data.0.acoes_corretivas.0.status', 'pending'));
    }

    public function test_completion_markers_override_notification_and_proposal_states_and_soft_deleted_records_are_excluded(): void
    {
        $legacy = $this->rnc('draft');
        $legacy->update(['finalized_at' => now()]);
        $withEvidence = $this->rnc('awaiting_evidence');
        $action = $withEvidence->acoesCorretivas()->first();
        $withEvidence->evidencias()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'relatorio_nao_conformidade_acao_corretiva_id' => $action->id, 'attachment_path' => 'test.zip',
            'attachment_original_name' => 'evidencias.zip', 'submitted_at' => now()]);
        $deleted = $this->rnc('completed');
        $deleted->delete();
        $this->get($this->url(['stage' => 'completed']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rncs.total', 1)->where('stageCounts.all', 2)->where('stageCounts.completed', 1)->where('stageCounts.signatures', 1)
            ->where('rncs.data', fn ($records) => !collect($records)->contains('id', $deleted->id)));
    }

    public function test_search_contract_obra_and_situation_filters_combine_and_counters_follow_the_search_scope(): void
    {
        $draft = $this->rnc('draft');
        $draft->update(['descricao_problema' => 'Ensaio especial do concreto']);
        $review = $this->rnc('awaiting_review');
        $review->update(['descricao_problema' => 'Ensaio especial do concreto']);
        $otherObra = $this->rnc('awaiting_review', true);
        $otherObra->update(['descricao_problema' => 'Ensaio especial do concreto']);
        $this->rnc('awaiting_review');
        $filters = ['q' => 'ENSAIO especial', 'contract_id' => $this->a->id, 'obra_id' => $this->obraA->id, 'status' => 'awaiting_review'];
        $this->get($this->url($filters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rncs.total', 1)->where('rncs.data.0.id', $review->id)
            ->where('stageCounts.all', 2)->where('stageCounts.draft', 1)->where('stageCounts.analysis', 1)
            ->where('filters.q', 'ENSAIO especial'));
        foreach ([$review->formatted_number, 'RNC '.$review->formatted_number, str_replace('-', '/', $review->formatted_number)] as $number) {
            $this->get($this->url(['q' => $number]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('rncs.total', 1)->where('rncs.data.0.id', $review->id));
        }
        $this->get($this->url(['q' => 'OB-B']))->assertInertia(fn (Assert $page) => $page->where('rncs.total', 1)->where('rncs.data.0.id', $otherObra->id));
        $this->get($this->url(['contract_id' => $this->a->id, 'obra_id' => $this->obraB->id]))->assertSessionHasErrors('obra_id');
        $this->get($this->url(['status' => 'invalid']))->assertSessionHasErrors('status');
        $this->get($this->url(['stage' => 'invalid']))->assertSessionHasErrors('stage');
    }

    public function test_pagination_returns_ten_records_and_preserves_filters_in_page_links(): void
    {
        for ($i = 0; $i < 23; $i++) $this->rnc('draft');
        $this->get($this->url(['stage' => 'draft', 'q' => 'Concreto', 'contract_id' => $this->a->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('rncs.data', 10)->where('rncs.total', 23)->where('rncs.last_page', 3)->where('rncs.per_page', 10)
            ->where('rncs.next_page_url', fn ($url) => str_contains($url, 'stage=draft') && str_contains($url, 'q=Concreto') && str_contains($url, 'page=2')));
        $this->get($this->url(['stage' => 'draft', 'q' => 'Concreto', 'page' => 3]))->assertInertia(fn (Assert $page) => $page
            ->has('rncs.data', 3)->where('rncs.current_page', 3)->where('rncs.from', 21)->where('rncs.to', 23));
        $this->get($this->url(['q' => 'inexistente']))->assertInertia(fn (Assert $page) => $page
            ->has('rncs.data', 0)->where('rncs.total', 0)->where('stageCounts.all', 0));
    }

    public function test_data_counts_and_filter_options_are_limited_to_the_users_permitted_contracts_and_tenant(): void
    {
        $visible = $this->rnc('draft');
        $this->rnc('awaiting_review', true);
        $member = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $member->id, 'role' => 'engineer', 'status' => 'active']);
        ContractParticipant::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id, 'user_id' => $member->id,
            'side' => 'manager', 'role' => 'team_member', 'status' => 'active']);
        RelatorioNaoConformidadeResponsavel::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id,
            'user_id' => $member->id, 'created_by_id' => $this->user->id, 'status' => 'active', 'permissions' => [RncPermissions::VIEW]]);
        $otherTenant = Tenant::create(['name' => 'Outro tenant', 'slug' => 'outro-rnc-filtros', 'plan' => 'starter', 'status' => 'active']);
        $this->actingAs($member)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rncs.total', 1)->where('rncs.data.0.id', $visible->id)->where('rncs.data.0.user_permissions', [RncPermissions::VIEW])
            ->where('stageCounts.all', 1)->where('stageCounts.analysis', 0)->has('contracts', 1)->has('obras', 1)
            ->where('contracts.0.id', $this->a->id)->where('obras.0.id', $this->obraA->id));
        $this->get($this->url(['contract_id' => $this->b->id]))->assertSessionHasErrors('contract_id');
        $this->get($this->url(['obra_id' => $this->obraB->id]))->assertSessionHasErrors('obra_id');
        $this->get(route('tenant.qualidade.rnc.index', $otherTenant))->assertForbidden();
    }

    public function test_dashboard_counts_latest_evidence_results_separately_from_signature_completion(): void
    {
        $expected = [];
        foreach (array_keys(RncListing::STATUSES) as $status) $expected[$status] = $this->rnc($status);
        $expected['awaiting_signature']->evidencias()->first()->update(['resolution_result' => 'yes']);
        $partial = $this->rnc('awaiting_signature');
        $partial->evidencias()->first()->update(['resolution_result' => 'yes']);
        $partial->evidencias()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'relatorio_nao_conformidade_acao_corretiva_id' => $partial->acoesCorretivas()->first()->id,
            'resolution_result' => 'partial', 'submitted_at' => now()]);
        $this->rnc('awaiting_signature')->evidencias()->first()->update(['resolution_result' => 'no']);
        $this->rnc('awaiting_signature')->delete();
        $this->get(route('tenant.qualidade.rnc.dashboard', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total', 9)->where('metrics.finalizadas', 1)->where('metrics.emAnalise', 1)
            ->where('metrics.aguardandoEvidencia', 1)->where('metrics.aguardandoAssinaturas', 3)
            ->where('metrics.resolvidas', 1)->where('metrics.parcialmenteResolvidas', 1)->where('metrics.naoResolvidas', 1)
            ->where('resolutionCounts', ['yes' => 1, 'partial' => 1, 'no' => 1])
            ->where('statusCounts.Aguardando assinaturas', 3)->where('statusCounts.Finalizada', 1)
            ->missing('recentRncs')->has('contracts', 2));
        $this->get(route('tenant.qualidade.rnc.dashboard', [$this->tenant, 'resolution_result' => 'partial']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('metrics.total', 1)
                ->where('metrics.parcialmenteResolvidas', 1)->where('metrics.resolvidas', 0));
    }

    public function test_dashboard_filters_combine_and_reject_mismatched_options_and_dates(): void
    {
        $discipline = $this->tenant->disciplinas()->create(['contract_id' => $this->a->id, 'nome' => 'Estruturas', 'sigla' => 'EST', 'cor' => '#123456']);
        $otherDiscipline = $this->tenant->disciplinas()->create(['contract_id' => $this->a->id, 'nome' => 'Drenagem', 'sigla' => 'DRE', 'cor' => '#123456']);
        $otherObra = Obra::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id, 'codigo' => 'OB-C', 'nome' => 'Obra C', 'tipo' => 'pai']);
        $target = $this->rnc('awaiting_signature');
        $target->update(['disciplina_id' => $discipline->id]);
        $target->evidencias()->first()->update(['resolution_result' => 'yes']);
        foreach ([['opened_at' => '2026-09-01'], ['opened_at' => '2026-11-01'], ['gravidade' => 'Leve'],
            ['disciplina_id' => $otherDiscipline->id], ['obra_id' => $otherObra->id],
            ['contract_id' => $this->b->id, 'obra_id' => $this->obraB->id], ['status' => 'finalizada'], []] as $index => $change) {
            $rnc = $this->rnc('awaiting_signature');
            $rnc->update(['disciplina_id' => $discipline->id, ...$change]);
            $rnc->evidencias()->first()->update(['resolution_result' => $index === 7 ? 'no' : 'yes']);
        }
        $filters = ['contract_id' => $this->a->id, 'obra_id' => $this->obraA->id, 'disciplina_id' => $discipline->id,
            'gravidade' => 'Grave', 'status' => 'awaiting_signature', 'resolution_result' => 'yes',
            'date_from' => '2026-10-22', 'date_to' => '2026-10-22'];
        $url = fn ($params = []) => route('tenant.qualidade.rnc.dashboard', [$this->tenant, ...$params]);
        $this->get($url($filters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total', 1)->where('metrics.aguardandoAssinaturas', 1)->where('resolutionCounts.yes', 1)
            ->where('gravidadeCounts.Grave', 1)->where('disciplinaCounts.EST - Estruturas', 1)
            ->where('monthlyCounts', fn ($values) => collect($values)->sum() === 1)
            ->where('monthlyRange', '05/2026 a 10/2026')->where('filters.date_from', '2026-10-22'));
        $this->get($url(['contract_id' => $this->b->id, 'obra_id' => $this->obraA->id]))->assertSessionHasErrors('obra_id');
        $this->get($url(['contract_id' => $this->b->id, 'disciplina_id' => $discipline->id]))->assertSessionHasErrors('disciplina_id');
        $this->get($url(['date_from' => '2026-10-23', 'date_to' => '2026-10-22']))->assertSessionHasErrors('date_to');
        $this->get($url(['resolution_result' => 'invalid']))->assertSessionHasErrors('resolution_result');
        $this->get($url(['status' => 'invalid']))->assertSessionHasErrors('status');
        $this->get($url(['date_from' => 'invalid']))->assertSessionHasErrors('date_from');
        $this->get($url(['date_from' => '2027-01-01', 'date_to' => '2027-01-31']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('metrics.total', 0)->where('resolutionCounts', ['yes' => 0, 'partial' => 0, 'no' => 0]));
    }

    public function test_dashboard_filters_and_metrics_are_scoped_to_permitted_contracts(): void
    {
        $this->rnc('awaiting_signature')->evidencias()->first()->update(['resolution_result' => 'yes']);
        $this->rnc('awaiting_signature', true)->evidencias()->first()->update(['resolution_result' => 'no']);
        $member = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $member->id, 'role' => 'engineer', 'status' => 'active']);
        ContractParticipant::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id, 'user_id' => $member->id,
            'side' => 'manager', 'role' => 'team_member', 'status' => 'active']);
        RelatorioNaoConformidadeResponsavel::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->a->id,
            'user_id' => $member->id, 'created_by_id' => $this->user->id, 'status' => 'active', 'permissions' => [RncPermissions::DASHBOARD]]);
        $url = fn ($params = []) => route('tenant.qualidade.rnc.dashboard', [$this->tenant, ...$params]);
        $this->actingAs($member)->get($url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total', 1)->where('resolutionCounts', ['yes' => 1, 'partial' => 0, 'no' => 0])
            ->has('contracts', 1)->has('obras', 1)->where('contracts.0.id', $this->a->id));
        $this->get($url(['contract_id' => $this->b->id]))->assertSessionHasErrors('contract_id');
        $this->get($url(['obra_id' => $this->obraB->id]))->assertSessionHasErrors('obra_id');
        $otherTenant = Tenant::create(['name' => 'Outro tenant', 'slug' => 'outro-dashboard', 'plan' => 'starter', 'status' => 'active']);
        $this->get(route('tenant.qualidade.rnc.dashboard', $otherTenant))->assertForbidden();
    }

    private function rnc(string $status, bool $secondContract = false): RelatorioNaoConformidade
    {
        $rnc = RelatorioNaoConformidade::create(['tenant_id' => $this->tenant->id, 'contract_id' => $secondContract ? $this->b->id : $this->a->id,
            'obra_id' => $secondContract ? $this->obraB->id : $this->obraA->id, 'contratante_empresa_id' => $this->empresa->id,
            'contratada_empresa_id' => $this->empresa->id, 'created_by_id' => $this->user->id, 'sequence_number' => ++$this->sequence,
            'sequence_year' => 2026, 'opened_at' => '2026-10-22', 'natureza' => 'Qualidade', 'gravidade' => 'Grave',
            'descricao_problema' => 'Concreto fora do padrão', 'acoes_corretivas_recomendadas' => 'Corrigir acabamento',
            'prazo_resposta_acao_corretiva' => '2026-11-22', 'status' => $status === 'completed' ? 'finalizada' : 'aberta',
            'notified_at' => $status === 'draft' ? null : now()]);
        $proposalStatus = ['returned' => 'rejected', 'awaiting_review' => 'pending', 'awaiting_evidence' => 'approved', 'awaiting_signature' => 'approved'][$status] ?? null;
        if ($proposalStatus) $this->proposal($rnc, $proposalStatus, now());
        if ($status === 'awaiting_signature') $rnc->evidencias()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'relatorio_nao_conformidade_acao_corretiva_id' => $rnc->acoesCorretivas()->first()->id, 'attachment_path' => 'evidence.zip', 'attachment_original_name' => 'evidence.zip', 'submitted_at' => now()]);
        return $rnc;
    }

    private function proposal(RelatorioNaoConformidade $rnc, string $status, $submittedAt): void
    {
        $rnc->acoesCorretivas()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'status' => $status,
            'descricao_proposta' => 'Correção proposta', 'prazo_execucao_proposto' => '2026-11-25',
            'attachment_path' => 'proposta.pdf', 'attachment_original_name' => 'proposta.pdf', 'submitted_at' => $submittedAt]);
    }

    private function url(array $filters = []): string
    {
        return route('tenant.qualidade.rnc.index', [$this->tenant, ...$filters]);
    }
}
