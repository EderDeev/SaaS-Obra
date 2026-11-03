const contract = '001-DNIT — Supervisão das obras da BR-316';
const work = '101 · Drenagem e dispositivos de concreto';
const person = 'Mariana Costa';

const examples = {
    'Equipe': 'Mariana Costa · Gerenciadora; Rafael Almeida · Construtora', 'Prazos': '09/11/2026 a 20/11/2026', 'Documentos': 'Relatório de vistoria.pdf · Fotos do trecho.zip',
    'Situação do OCR': 'Concluído', 'Conteúdo': 'Relatório de vistoria dos dispositivos de drenagem da BR-316, entre os km 12,00 e 14,50.', 'Não lidas': '3 notificações',
    'Diretoria': 'CMAT N & NE', 'Nome da diretoria': 'CMAT N & NE', 'Diretorias': 'CMAT N & NE · CMAT Troncal',
    'Contrato': contract, 'Contratos': contract, 'Contratos autorizados': contract, 'Identificação': contract,
    'Vigência': '01/11/2026 a 31/10/2027', 'Valor (opcional)': 'Não informado', 'Acesso': 'Diretoria e contrato autorizados',
    'Nome': person, 'Usuário': person, 'Usuários': 'Mariana Costa · Rafael Almeida', 'E-mail': 'mariana.costa@example.com', 'Perfil': 'Colaborador', 'Owner': 'Rafael Almeida', 'Plano': 'Plano contratado',
    'Situação': 'Ativo', 'Vínculos': 'Contrato 001-DNIT · ativo', 'Vínculos ativos': 'Contrato 001-DNIT', 'Módulo': 'Diário de obra', 'Módulos': 'Projetos · Qualidade · Diário de obra',
    'Permissões': 'Visualizar e cadastrar no contrato selecionado', 'Ações permitidas': 'Visualizar · Criar · Editar', 'Visualizar / editar': 'Visualização e edição autorizadas',
    'Código': 'BR316-101', 'Razão social': 'Construtora Horizonte Ltda.', 'Tipo': 'Construtora', 'CNPJ': 'A informar no cadastro da empresa',
    'Empresas': 'Construtora Horizonte · Concremat · DNIT', 'Cliente': 'DNIT', 'Construtora': 'Construtora Horizonte Ltda.', 'Gerenciadora': 'Concremat Engenharia',
    'Obra': work, 'Obras': work, 'Obra / frente': work, 'Obra / subobra': work, 'Código do trecho': 'TR-01 · km 12,00 ao km 14,50',
    'Trecho': 'TR-01 · km 12,00 ao km 14,50', 'Disciplinas': 'Drenagem · Pavimentação', 'Disciplina': 'Drenagem', 'Sigla': 'DRE', 'Cadastros vinculados': 'Obra 101 · Trecho TR-01 · Drenagem',
    'Responsável': person, 'Responsáveis': 'Mariana Costa · Rafael Almeida', 'Participantes': 'Mariana Costa · Rafael Almeida', 'Responsáveis configurados': 'Mariana Costa · Gerenciadora',
    'Prazo': '20/11/2026', 'Prazo proposto': '20/11/2026', 'Prazo / custo': 'Prorrogação de 30 dias', 'Tipo de aditivo': 'Prazo', 'Documento': 'Termo aditivo de prazo.pdf',
    'Documento autorizado': 'Relatório de vistoria — BR-316.pdf', 'PDF': 'Relatório de vistoria — BR-316.pdf', 'Arquivo': 'BR316-DRE-101-R01.pdf', 'Arquivos': 'Relatório de vistoria.pdf · Fotos do trecho.zip',
    'Documento principal': 'Relatório de vistoria — BR-316.pdf', 'PDF principal': 'Relatório de vistoria — BR-316.pdf', 'Anexos': 'Fotos da vistoria.zip',
    'Modelo': 'Gerenciadora + Cliente', 'Revisão': 'R01', 'Modelo de PDF': 'Padrão do sistema', 'Logos e PDF': 'Logos do cliente e da gerenciadora · PDF padrão',
    'Período e dias de geração': '01/11/2026 a 31/10/2027 · segunda a sexta', 'Cópia de dados': 'Copiar os registros anteriores conforme a parametrização do contrato',
    'Título': 'Vistoria dos dispositivos de drenagem', 'Assunto': 'Obstrução na saída de drenagem do km 13,20', 'Atividade / checklist': 'Checklist de inspeção de drenagem',
    'Atividade ou checklist': 'Checklist de inspeção de drenagem', 'Etapas do checklist': 'Inspecionar · Registrar evidências · Revisar relatório', 'Etapas': 'Mobilização · Drenagem',
    'Pública / restrita': 'Restrita aos responsáveis e participantes', 'Visibilidade': 'Restrita à equipe autorizada', 'Categoria': 'Inspeção de campo',
    'Comentários': 'Vistoria realizada. Limpeza das saídas de drenagem pendente.', 'Comentário': 'Execução conferida em campo. Prosseguir com a limpeza das saídas de drenagem.',
    'Comentário final': 'Limpeza concluída e escoamento restabelecido no trecho inspecionado.', 'Evento': 'Encaminhamento para análise', 'Eventos recentes': 'RDO encaminhado para análise da gerenciadora',
    'Pergunta': 'Quais RNCs estão pendentes neste contrato?', 'Dúvida': 'Como registrar evidências de uma RNC?', 'Dados propostos': 'Assunto e descrição sugeridos para conferência',
    'Uso mensal': 'Consulte o consumo disponível no seu plano', 'Cota': 'Consulte o limite contratado',
    'Banco': 'SINAPI', 'UF': 'PA', 'Competência': '10/2026', 'Base oficial': 'SINAPI · PA · 10/2026', 'Custos': 'Valores da base selecionada',
    'Composição': 'Execução de sarjeta de concreto', 'Insumos': 'Concreto · Pedreiro · Servente', 'Coeficientes': '0,20 m³ de concreto por unidade do serviço',
    'BDI': '22,00%', 'Encargos': 'Conforme a base e o regime selecionados', 'Itens': 'Execução de sarjeta de concreto', 'Quantidades': '120,00 m',
    'Orçamento': 'Recuperação da drenagem — BR-316', 'Totais': 'Subtotal, BDI e total consolidados do orçamento', 'Exportação': 'Planilha do orçamento.xlsx',
    'Modalidade de medição': 'Medição Controlada', 'Índice': 'Índice de reajuste do contrato', 'Boletim': 'BM 001 · novembro/2026',
    'Itens pleiteados': 'Execução de sarjeta de concreto · 120,00 m', 'Etapa': 'Análise da medição', 'Parecer': 'Serviço conferido conforme os registros apresentados.',
    'Submissão': 'BM 001 · enviado para análise', 'Análises': 'Parecer técnico e decisão registrados', 'Relatório': 'Relatório do boletim de medição.pdf',
    'Escopo': 'Recuperação dos dispositivos de drenagem do trecho TR-01', 'Período': '09/11/2026 a 20/11/2026', 'Resumo': 'Liberação para execução da recuperação de drenagem',
    'Serviços': 'Limpeza e recuperação de sarjetas', 'Conclusão': 'Execução concluída e conferida', 'Previsto': '120,00 m', 'Real medido': '115,00 m',
    'Decisão': 'Aprovar', 'Resultado': 'Sim — problema resolvido', 'Motivo': 'Se parcialmente resolvido ou não resolvido, descreva o que permanece pendente.',
    'IC (opcional)': 'IC 001/2026 · Obstrução na saída de drenagem', 'IC comunicada': 'IC 001/2026', 'Instrução': 'Desobstruir a saída de drenagem do km 13,20 e remover o material acumulado para restabelecer o escoamento.',
    'Envolvidos': 'Supervisão · Construtora · Cliente', 'Comunicação': 'Encaminhar aos responsáveis cadastrados no contrato',
    'Proposta': 'Executar a limpeza da saída de drenagem e recompor a proteção do talude após a retirada do material acumulado.',
    'Anexo ZIP': 'Proposta de correção — RNC 001.zip', 'Evidências': 'Drenagem após a limpeza.jpg · Relatório de execução.pdf',
    'Partes': 'Gerenciadora · Construtora', 'Signatários': 'Mariana Costa · Gerenciadora; Rafael Almeida · Construtora', 'PDF por etapa': 'Abertura · Proposta e análise · Evidências · Completo assinado',
    'Clima': 'Manhã: bom · Tarde: chuvoso · Pluviosidade: 8,50 mm', 'Atividades': 'Limpeza de sarjetas e recuperação de duas saídas de drenagem.',
    'Recursos': '4 serventes · 1 pedreiro · 1 retroescavadeira', 'Calendário / lista': 'RDO 001 · 09/11/2026', 'Etapas do PDF': 'Conforme as partes do modelo selecionado', 'Obras selecionadas': work,
    'Documento final': 'RDO 001 — consolidado para assinatura.pdf', 'Comparação dos registros': 'Registros da construtora e da gerenciadora por obra', 'Ressalvas por obra': 'Obra 101 · Sem ressalvas',
    'Checklist': 'Identificação · Disciplina · Revisão · Compatibilidade', 'Analista / aprovador': 'Mariana Costa · análise; Rafael Almeida · aprovação',
    'Objeto': 'Saída de drenagem do trecho TR-01', 'Filtros': 'Contrato 001-DNIT · Obra 101 · Drenagem', 'Lista mestra': 'BR316-DRE-101 · Revisão R01',
    'Conta': 'Conta de e-mail documental do contrato', 'Regras': 'Contrato e classificação definidos na regra de importação', 'Pesquisa': 'Drenagem · km 13,20',
    'Grupos': 'Equipe técnica do contrato', 'Restaurar / excluir': 'Restaurar o documento selecionado', 'Situação de leitura': 'Não lida', 'Notificação': 'RDO 001 aguarda sua análise', 'Destino': 'Registro relacionado à notificação', 'Foto': 'Foto do perfil atualizada',
    'Armazenamento': 'Consumo por diretoria', 'Consumo APS': 'Uso de tradução e visualização dos projetos', 'Owner, Plano, Situação': 'Diretoria e assinatura selecionadas',
    'Em aberto': '11 atividades', 'Vencidas': '3 atividades', 'Projetos': '8 ativos · 2 em revisão', 'RNC': '3 pendentes', 'RDO': '2 aguardando análise', 'Ordem de Serviço': '4 pendentes',
};

const contexts = {
    'primeiros-passos': [['Diretoria', 'CMAT N & NE'], ['Contrato', contract]],
    'administracao-plataforma': [['Diretoria', 'CMAT N & NE'], ['Owner', 'Rafael Almeida']],
    'visao-geral': [['Contrato', contract]],
    'contratos': [['Código', '001-DNIT'], ['Nome', 'Supervisão das obras da BR-316']],
    'parametrizacao': [['Contrato', contract]],
    'usuarios-permissoes': [['Usuário', person], ['Contrato', contract]],
    'atividades': [['Título', 'Vistoria dos dispositivos de drenagem'], ['Contrato', contract]],
    'orcamentos': [['Orçamento', examples.Orçamento], ['Contrato', contract]],
    'medicao': [['Boletim', examples.Boletim], ['Contrato', contract]],
    'ordem-servico': [['Contrato', contract], ['Resumo', examples.Resumo]],
    'qualidade-rnc': [['RNC', '001/2026'], ['Assunto', examples.Assunto]],
    'qualidade-ic': [['IC', '001/2026'], ['Assunto', examples.Assunto]],
    'diario-obra': [['RDO', '001 · 09/11/2026'], ['Obra / frente', work]],
    'documentacao': [['Contrato', contract], ['Documento principal', examples.PDF]],
    'projetos': [['Contrato', contract], ['Arquivo', examples.Arquivo]],
    'notificacoes-perfil': [['Usuário', person], ['Evento', examples['Eventos recentes']]],
    'assistente-deming': [['Contrato', contract], ['Pergunta', examples.Pergunta]],
};

export function getWalkthroughExample(tutorial, step) {
    const context = contexts[tutorial.id] || [['Contrato', contract]];
    const specific = tutorial.id === 'contratos' ? { Nome: 'Supervisão das obras da BR-316', Código: '001-DNIT' }
        : tutorial.id === 'parametrizacao' ? { Nome: 'Drenagem' }
        : tutorial.id === 'orcamentos' ? { Nome: examples.Orçamento }
        : {};
    if (step.modelLabel) specific.Modelo = step.modelLabel;
    const fields = (step.fields?.length ? step.fields : context.map(([label]) => label)).map(label => [label, specific[label] || examples[label] || context.find(([key]) => key === label)?.[1] || step.title]);
    return { context, fields };
}

// These nodes describe system behavior or a resulting state, rather than a user action.
export const informationalSteps = {
    'orcamentos': [4, 5], 'administracao-plataforma': [2], 'atividades': [5], 'documentacao': [1], 'notificacoes-perfil': [2], 'assistente-deming': [5],
    'qualidade-rnc': [6], 'qualidade-ic': [4],
};
