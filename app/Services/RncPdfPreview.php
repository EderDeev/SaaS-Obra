<?php

namespace App\Services;

use App\Models\{Contract, Disciplina, Empresa, Obra, RelatorioNaoConformidade, RelatorioNaoConformidadeAcaoCorretiva, RelatorioNaoConformidadeEvidencia, Tenant, User};
use Illuminate\Database\Eloquent\Collection;

class RncPdfPreview
{
    public function make(Tenant $tenant, Contract $contract, ?User $actor = null): RelatorioNaoConformidade
    {
        $author = $actor ?? new User(['name' => 'Engenheiro responsável']);
        $company = fn (string $name) => new Empresa(['tenant_id' => $tenant->id, 'nome' => $name]);
        $sameTenant = fn ($model) => $model && (int) $model->tenant_id === (int) $tenant->id ? $model : null;
        $client = $sameTenant($contract->clienteEmpresa) ?? $company($contract->client_company_name ?: 'Contratante');
        $contractor = $sameTenant($contract->construtoraEmpresa) ?? $company($contract->contractor_company_name ?: 'Construtora');
        $work = $sameTenant($contract->obra) ?? new Obra(['tenant_id' => $tenant->id, 'codigo' => 'OB-01', 'nome' => $contract->name ?: 'Obra vinculada ao contrato']);
        $rnc = new RelatorioNaoConformidade([
            'tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'sequence_number' => 1, 'sequence_year' => now()->year,
            'opened_at' => today(), 'notified_at' => now(), 'status' => 'em_assinatura', 'natureza' => 'Qualidade', 'gravidade' => 'Grave',
            'assunto' => 'Irregularidade no acabamento do concreto',
            'descricao_problema' => 'Durante a inspeção foram identificadas irregularidades no acabamento do concreto na região da junta, em desacordo com as especificações do projeto.',
            'observacao' => 'O trecho deve permanecer identificado até a conclusão das correções e a verificação pela supervisão.',
            'acoes_corretivas_recomendadas' => 'Revisar o trecho identificado e executar os ajustes conforme as especificações técnicas aplicáveis.',
            'prazo_resposta_acao_corretiva' => today()->addDays(7),
        ]);
        $action = new RelatorioNaoConformidadeAcaoCorretiva([
            'descricao_proposta' => 'Refazer o serviço no trecho identificado e apresentar os registros de controle.',
            'prazo_execucao_proposto' => today()->addDays(10), 'status' => 'approved', 'submitted_at' => now(), 'reviewed_at' => now(),
            'review_observation' => 'Proposta aprovada para execução, com acompanhamento da supervisão.',
        ]);
        $action->setRelations(['user' => $author, 'reviewer' => $author]);
        $evidence = new RelatorioNaoConformidadeEvidencia(['resolution_result' => 'partial',
            'resolution_reason' => 'O trecho principal foi corrigido. Um segmento ainda aguarda o resultado dos ensaios.', 'submitted_at' => now()]);
        $evidence->setRelations(['user' => $author, 'photos' => new Collection]);
        $photos = new Collection(collect(range(1, 6))->map(fn ($position) => new \App\Models\RelatorioNaoConformidadePhoto([
            'position' => $position, 'path' => '__rnc_preview__/registro.jpg', 'mime_type' => 'image/jpeg',
            'original_name' => 'registro-fotografico-'.$position.'.jpg', 'comment' => 'Condição do trecho vistoriado',
        ]))->all());
        $rnc->setRelations([
            'tenant' => $tenant, 'contract' => $contract,
            'obra' => $work,
            'disciplina' => new Disciplina(['nome' => 'Qualidade', 'sigla' => 'QUA']),
            'contratante' => $client, 'contratada' => $contractor,
            'creator' => $author, 'finalizedBy' => null, 'instrucaoCampo' => null, 'projectDocument' => null,
            'projectDocuments' => new Collection, 'photos' => $photos,
            'acoesCorretivas' => new Collection([$action]), 'evidencias' => new Collection([$evidence]), 'signatureRequests' => new Collection,
        ]);

        return $rnc;
    }
}
