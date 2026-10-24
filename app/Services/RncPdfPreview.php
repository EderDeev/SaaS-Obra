<?php

namespace App\Services;

use App\Models\{Contract, Disciplina, Empresa, Obra, RelatorioNaoConformidade, RelatorioNaoConformidadeAcaoCorretiva, RelatorioNaoConformidadeEvidencia, Tenant, User};
use Illuminate\Database\Eloquent\Collection;

class RncPdfPreview
{
    public function make(Tenant $tenant, Contract $contract): RelatorioNaoConformidade
    {
        $author = new User(['name' => 'Responsável de exemplo']);
        $company = fn (string $name) => new Empresa(['tenant_id' => $tenant->id, 'nome' => $name]);
        $rnc = new RelatorioNaoConformidade([
            'tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'sequence_number' => 1, 'sequence_year' => now()->year,
            'opened_at' => today(), 'notified_at' => now(), 'status' => 'em_assinatura', 'natureza' => 'Qualidade', 'gravidade' => 'Grave',
            'assunto' => 'Exemplo de não conformidade na execução dos serviços',
            'descricao_problema' => 'Durante a inspeção foi identificada uma divergência entre o serviço executado e a especificação do projeto. Este texto demonstra como a descrição da ocorrência será apresentada no modelo.',
            'observacao' => 'Exemplo para conferência da diagramação. Nenhuma RNC será criada por esta prévia.',
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
            'position' => $position, 'path' => '__rnc_preview__/example.jpg', 'mime_type' => 'image/jpeg',
            'original_name' => 'Registro fotográfico de exemplo', 'comment' => 'Legenda da foto de exemplo '.$position,
        ]))->all());
        $rnc->setRelations([
            'tenant' => $tenant, 'contract' => $contract,
            'obra' => new Obra(['codigo' => 'OB-01', 'nome' => 'Obra de exemplo']),
            'disciplina' => new Disciplina(['nome' => 'Qualidade', 'sigla' => 'QUA']),
            'contratante' => $company('Contratante de exemplo'), 'contratada' => $company('Construtora de exemplo'),
            'creator' => $author, 'finalizedBy' => null, 'instrucaoCampo' => null, 'projectDocument' => null,
            'projectDocuments' => new Collection, 'photos' => $photos,
            'acoesCorretivas' => new Collection([$action]), 'evidencias' => new Collection([$evidence]), 'signatureRequests' => new Collection,
        ]);

        return $rnc;
    }
}
