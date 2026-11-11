import { informationalSteps } from './tutorialExamples';
import { sigWorksSteps } from './SigWorksOverview';
import { Activity, Bell, BookOpen, Bot, Building2, Calculator, ClipboardCheck, ClipboardList, FileText, Flag, FolderOpen, GitBranch, HardHat, Image, LayoutDashboard, ListChecks, LockKeyhole, ScanText, Send, Settings2, ShieldCheck, Signature, SlidersHorizontal, Users } from 'lucide-react';

export const tutorialIcons = { Activity, Bell, BookOpen, Bot, Building2, Calculator, ClipboardCheck, ClipboardList, FileText, FolderOpen, HardHat, LayoutDashboard, ShieldCheck, SlidersHorizontal, Users };
const labels = {
    'primeiros-passos': ['Diretoria', 'Contrato', 'Cadastros', 'Medição', 'Equipe', 'Operação'],
    'administracao-plataforma': ['Visão da plataforma', 'Diretorias', 'Separação de acesso', 'Uso APS', 'Revisão de acesso'],
    'visao-geral': ['Contrato', 'Atividades', 'Módulos', 'Atalhos', 'Tour'],
    'contratos': ['Cadastro', 'Estrutura', 'Vínculos', 'Medição', 'Equipe', 'Aditivos', 'Acompanhamento'],
    'parametrizacao': ['Empresas', 'Obras', 'Trechos', 'Disciplinas', 'Vínculos do contrato', 'Cadastros utilizados'],
    'usuarios-permissoes': ['Usuário', 'Módulos', 'Contratos', 'Permissões', 'Revisão de acesso'],
    'atividades': ['Atividade ou checklist', 'Dados da tarefa', 'Etapas', 'Visibilidade', 'Acompanhamento', 'Notificações', 'Métricas'],
    'orcamentos': ['Insumos', 'Composições', 'Bases oficiais', 'Criar e montar orçamento', 'Etapas e quantidades', 'Acessos', 'Exportação'],
    'ordem-servico': ['Responsáveis', 'Nova liberação', 'Rascunho', 'Análise e aprovação', 'Acompanhamento', 'Execução', 'Custos e métricas'],
    'medicao': ['Itens do contrato', 'Índices', 'Boletim', 'Folha de rosto', 'Análises', 'Histórico', 'Relatórios'],
    'qualidade-rnc': ['Abertura', 'Comunicação', 'Proposta', 'Análise', 'Evidências', 'Assinaturas', 'Conclusão'],
    'qualidade-ic': ['Instrução', 'Comunicação', 'Evidências', 'Finalização', 'Referência em RNC'],
    'documentacao': ['Documento', 'OCR', 'Anexos', 'Acesso', 'E-mail', 'Triagem', 'Consulta e download', 'Lixeira'],
    'projetos': ['Responsáveis', 'Submissão', 'Lote', 'Análise e aprovação', 'Visualização', 'Marcações', 'Disponibilidade', 'Acervo'],
    'notificacoes-perfil': ['Sino', 'Leitura', 'Contador', 'Registro relacionado', 'Perfil'],
    'assistente-deming': ['Assistente', 'Pergunta', 'Tutorial', 'Documentos', 'Rascunho', 'Cota'],
};

const roleLabels = {
    'primeiros-passos': 'Administrador', 'administracao-plataforma': 'Administração da plataforma',
    'contratos': 'Equipe autorizada', 'parametrizacao': 'Administrador', 'usuarios-permissoes': 'Administrador',
    'atividades': 'Equipe da atividade', 'orcamentos': 'Orçamentista', 'medicao': 'Equipe de medição',
    'documentacao': 'Equipe documental', 'projetos': 'Equipe de projetos',
    'qualidade-rnc': ['Responsável operacional', 'Responsável operacional', 'Construtora', 'Acompanhamento', 'Responsável pelas evidências', 'Partes selecionadas', 'Equipe autorizada'],
    'qualidade-ic': ['Responsável autorizado', 'Responsável autorizado', 'Supervisão', 'Supervisão', 'Responsável pela RNC'],
};

function iconFor(title) {
    const patterns = [[/diretoria|empresa|contrato/i, Building2], [/assin/i, Signature], [/notific|sino|comunica/i, Bell], [/usuário|equipe|responsáve/i, Users], [/permiss|acesso|visibilidade/i, LockKeyhole], [/análise|aprova|decisão/i, GitBranch], [/evidência|foto|anexo/i, Image], [/parametr|configura|índice/i, Settings2], [/conclus|finaliza/i, Flag], [/revis|ocr|consulta/i, ScanText], [/envio|submiss|encaminha/i, Send], [/checklist|etapa|item/i, ListChecks], [/relatório|documento|pdf/i, FileText]];
    return patterns.find(([regex]) => regex.test(title))?.[1] || ClipboardList;
}

const fieldsByGuide = {
    'primeiros-passos': [['Diretoria', 'Acesso'], ['Identificação', 'Vigência'], ['Empresas', 'Obras', 'Disciplinas']],
    'administracao-plataforma': [['Diretorias', 'Usuários'], ['Nome da diretoria', 'Owner', 'Situação'], ['Diretoria', 'Contratos', 'Vínculos'], ['Armazenamento', 'Consumo APS'], ['Owner', 'Plano', 'Situação']],
    'visao-geral': [['Contrato'], ['Em aberto', 'Vencidas'], ['Projetos', 'RNC', 'RDO', 'Ordem de Serviço']],
    'contratos': [['Código', 'Nome', 'Vigência', 'Valor (opcional)'], ['Empresas', 'Obras', 'Disciplinas'], ['Cliente', 'Construtora', 'Gerenciadora'], ['Modalidade de medição'], ['Usuários', 'Permissões'], ['Tipo de aditivo', 'Prazo / custo', 'Documento'], ['Vigência', 'Módulos', 'Equipe']],
    'parametrizacao': [['Razão social', 'Tipo', 'CNPJ'], ['Contrato', 'Código', 'Obra / subobra'], ['Obra', 'Código do trecho'], ['Contrato', 'Sigla', 'Nome'], ['Contrato', 'Cadastros vinculados']],
    'usuarios-permissoes': [['Nome', 'E-mail', 'Perfil'], ['Módulo', 'Ações permitidas'], ['Contratos autorizados'], ['Usuário', 'Contrato', 'Permissões'], ['Usuário', 'Vínculos ativos']],
    'atividades': [['Atividade / checklist'], ['Título', 'Contrato', 'Prazo', 'Responsáveis'], ['Etapas do checklist'], ['Pública / restrita'], ['Situação', 'Comentários', 'Arquivos'], ['Evento', 'Participantes'], ['Prazo', 'Responsável', 'Categoria']],
    'orcamentos': [['Banco', 'UF', 'Competência'], ['Composição', 'Insumos', 'Coeficientes'], ['Base oficial', 'Custos'], ['Nome', 'BDI', 'Encargos'], ['Etapas', 'Itens', 'Quantidades'], ['Usuário', 'Visualizar / editar'], ['Totais', 'Exportação']],
    'ordem-servico': [['Contrato', 'Responsáveis', 'Prazos'], ['Escopo', 'Itens', 'Período'], ['Escopo', 'Itens'], ['Comentário', 'Decisão'], ['Resumo', 'Itens', 'Documentos'], ['Serviços', 'Conclusão'], ['Previsto', 'Real medido']],
    'medicao': [['Orçamento', 'Itens', 'Quantidades'], ['Índice', 'Competência'], ['Contrato', 'Boletim'], ['Itens pleiteados', 'Quantidades'], ['Etapa', 'Responsável', 'Parecer'], ['Submissão', 'Análises'], ['Boletim', 'Relatório']],
    'qualidade-rnc': [['Contrato', 'Obra', 'Assunto', 'IC (opcional)'], ['Responsáveis', 'Comunicação'], ['Proposta', 'Prazo proposto', 'Anexo ZIP'], ['Parecer', 'Decisão'], ['Resultado', 'Motivo', 'Evidências'], ['Partes', 'Signatários'], ['Resultado', 'PDF por etapa']],
    'qualidade-ic': [['Contrato', 'Obra', 'Assunto', 'Instrução'], ['Envolvidos', 'Comunicação'], ['Resultado', 'Evidências'], ['Resultado', 'Comentário final'], ['Obra', 'IC comunicada']],
    'documentacao': [['Contrato', 'PDF'], ['Situação do OCR', 'Conteúdo'], ['Arquivos', 'Documento principal'], ['Usuários', 'Grupos'], ['Conta', 'Regras'], ['PDF principal', 'Anexos'], ['Contrato', 'Pesquisa'], ['Documento', 'Restaurar / excluir']],
    'projetos': [['Contrato', 'Disciplina', 'Analista / aprovador'], ['Obra', 'Trecho', 'Disciplina', 'Arquivo'], ['Arquivos', 'Documentos'], ['Checklist', 'Parecer'], ['Modelo', 'Revisão'], ['Objeto', 'Comentário', 'Responsável'], ['Situação', 'Motivo'], ['Filtros', 'Lista mestra']],
    'notificacoes-perfil': [['Eventos recentes'], ['Notificação', 'Situação de leitura'], ['Não lidas'], ['Evento', 'Destino'], ['Nome', 'E-mail', 'Foto']],
    'assistente-deming': [['Pergunta'], ['Módulo', 'Contrato', 'Pergunta'], ['Módulo', 'Dúvida'], ['Documento autorizado'], ['Dados propostos'], ['Uso mensal']],
};

export const rdoModels = [
    { id: 'construtora_gerenciadora_cliente', label: 'Construtora + Gerenciadora + Cliente' },
    { id: 'gerenciadora_construtora', label: 'Construtora + Gerenciadora' },
    { id: 'gerenciadora_cliente', label: 'Gerenciadora + Cliente' },
    { id: 'gerenciadora', label: 'Somente Gerenciadora' },
];

export const osExamples = {
    analista: { preview: 'os-demand', role: 'Analista DNIT', fields: ['Contrato', 'Lote', 'Rodovia / trecho', 'Assunto', 'Necessidade', 'Prazo desejado'], action: 'Enviar para análise da supervisão', note: 'Os produtos são escolhidos pela supervisão.' },
    supervisao: { preview: 'os-stage', role: 'Supervisão', fields: ['Produtos do contrato', 'Quantidades', 'Análise técnica', 'Prazo estimado'], action: 'Encaminhar ao fiscal', type: 'products' },
    fiscal: { preview: 'os-stage', role: 'Fiscal do contrato', fields: ['Demanda', 'Produtos', 'Comentário da decisão'], action: 'Registrar a decisão', type: 'decision' },
    'ajuste-analista': { preview: 'os-demand', role: 'Analista DNIT', fields: ['Motivo recebido', 'Dados da demanda'], action: 'Reenviar para a supervisão', note: 'O fiscal informa o motivo e escolhe o responsável pelo retorno.' },
    'ajuste-supervisao': { preview: 'os-stage', role: 'Supervisão', fields: ['Motivo recebido', 'Produtos', 'Quantidades', 'Análise'], action: 'Reencaminhar ao fiscal', type: 'products' },
    decisao: { preview: 'os-stage', role: 'Fiscal do contrato', fields: ['Decisão', 'Motivo', 'Destino da devolução'], action: 'Confirmar decisão', type: 'decision' },
    aprovada: { preview: 'os-stage', role: 'Equipe responsável', fields: ['Situação da OS', 'Execução do serviço'], action: 'Acompanhar a execução', note: 'Aprovada não significa finalizada.' },
    finalizada: { preview: 'os-stage', role: 'Fiscal do contrato', fields: ['Serviço concluído', 'Conclusão registrada'], action: 'Finalizar OS', type: 'document' },
};

export function getTutorialFlow(tutorial, model = rdoModels[0].id) {
    if (tutorial.id === 'sigworks') return sigWorksSteps;
    if (tutorial.id === 'atividades') return [
        { id: 'atividades-create', title: 'Atividade ou checklist', role: 'Criação da tarefa', icon: ListChecks, description: 'Escolha Atividade normal ou Checklist e preencha os dados da tarefa no mesmo formulário. No checklist, inclua os itens que precisam ser conferidos.' },
        { id: 'atividades-visibility', title: 'Visibilidade', role: 'Acesso à atividade', icon: LockKeyhole, description: 'Defina se a atividade será pública para os usuários autorizados do contrato ou restrita ao criador e aos usuários vinculados.' },
        { id: 'atividades-flow', title: 'Fluxo da atividade', role: 'Quadro de atividades', icon: Activity, statuses: ['A fazer', 'Em andamento', 'Em revisão', 'Concluída'], description: 'Acompanhe a atividade no quadro: A fazer, Em andamento, Em revisão e Concluída. Durante a execução, registre comentários, anexe arquivos e confira os itens do checklist.' },
        { id: 'atividades-metrics', title: 'Métricas', role: 'Indicadores das atividades', icon: LayoutDashboard, informational: true, description: 'Consulte as métricas para acompanhar atividades em aberto, vencidas e concluídas, produtividade, categorias e cumprimento dos prazos, conforme seu acesso.' },
    ];
    if (tutorial.id === 'diario-obra') {
        const fillRole = ['gerenciadora_cliente', 'gerenciadora'].includes(model) ? 'Gerenciadora' : 'Construtora';
        const steps = [{ title: `${fillRole} preenche`, role: fillRole, description: tutorial.steps[2][1], icon: HardHat, fields: ['Obra / frente', 'Clima', 'Atividades', 'Recursos', 'Comentário'], type: 'resources', action: model === 'gerenciadora' ? 'Salvar o RDO completo' : 'Encaminhar o RDO completo' }];
        if (model.startsWith('construtora') || model === 'gerenciadora_construtora') steps.push({ title: 'Gerenciadora analisa', role: 'Gerenciadora', description: tutorial.steps[4][1], icon: ClipboardCheck, fields: ['Obra / frente', 'Atividades', 'Clima', 'Comentário', 'Evidências'], action: model === 'gerenciadora_construtora' ? 'Aprovar ou devolver' : 'Encaminhar ao cliente', type: 'decision' });
        if (model.endsWith('cliente')) steps.push({ title: 'Cliente aprova', role: 'Cliente', description: 'Compare os registros, comente e informe ressalvas por obra quando houver. Confirme a aprovação ou justifique a devolução, escolhendo o destino. A decisão movimenta o RDO completo.', icon: GitBranch, fields: ['Comparação dos registros', 'Comentário', 'Ressalvas por obra'], action: 'Aprovar ou devolver', type: 'decision' });
        if (model !== 'gerenciadora') steps.push({ title: 'Assinaturas', role: 'Partes configuradas', description: tutorial.steps[5][1], icon: Signature, fields: ['Signatários', 'Documento final'], action: 'Enviar para assinatura', type: 'document' });
        steps.push({ title: model === 'gerenciadora' ? 'RDO salvo' : 'Documento final', role: 'Equipe autorizada', description: tutorial.steps[6][1] + (model === 'gerenciadora' ? ' Neste modelo não existe aprovação de outra parte.' : ''), icon: FileText, fields: ['Calendário / lista', 'Etapas do PDF', 'Obras selecionadas'], action: 'Consultar e baixar PDF', type: 'document' });
        return steps.map((step, index) => ({ id: `rdo-${model}-${index}`, modelLabel: rdoModels.find(item => item.id === model)?.label, informational: index === steps.length - 1, ...step }));
    }
    return tutorial.steps.map(([title, description], index) => {
        const label = labels[tutorial.id]?.[index] || title;
        const roles = roleLabels[tutorial.id];
        return { informational: informationalSteps[tutorial.id]?.includes(index) || false, id: `${tutorial.id}-${index}`, title: label, role: Array.isArray(roles) ? roles[index] : roles || 'Usuário autorizado', description, icon: iconFor(label), fields: fieldsByGuide[tutorial.id]?.[index] || [], action: title, optional: tutorial.id === 'qualidade-ic' && index === 4,
            type: /assin|conclus|exporta|relatório/i.test(label) ? 'document' : /análise|aprova/i.test(label) ? 'decision' : /evidência|anexo|submiss|documento/i.test(label) ? 'upload' : /permiss|acesso/i.test(label) ? 'permissions' : 'form' };
    });
}
