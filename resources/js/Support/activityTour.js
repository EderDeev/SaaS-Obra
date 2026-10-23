export const activityTourSections = [
    ['create', 'Criar atividade'], ['board', 'Quadro'], ['detail', 'Execução'], ['flow', 'Andamento'], ['metrics', 'Métricas'],
];

const step = (section, target, title, content, status = null) => ({ section, target: `[data-tour="activities-${target}"]`, title, content, status });

export const activityTourSteps = [
    step('create', 'create-type', 'Comece por Nova atividade', 'Escolha Atividade para uma entrega simples ou Checklist para um trabalho com várias etapas. Neste exemplo, vamos acompanhar um checklist de medição.'),
    step('create', 'create-fields', 'Identifique e planeje o trabalho', 'Preencha título, contrato, categoria, prioridade, prazo e descrição. Na categoria Ordem de Serviço, pesquise e selecione a OS do mesmo contrato.'),
    step('create', 'create-checklist', 'Organize as etapas do checklist', 'Adicione as etapas na ordem de execução. Elas poderão ser marcadas individualmente para registrar o progresso. Use a descrição para explicar o resultado esperado.'),
    step('create', 'create-assignees', 'Escolha a equipe e crie o card', 'Defina a visibilidade: Pública para usuários autorizados do contrato ou Restrita ao criador e responsáveis. Selecione a equipe e clique em Criar checklist. O card começa em A fazer e os responsáveis são notificados.'),
    step('board', 'board', 'Encontre a atividade no quadro', 'Pesquise por título ou responsável e filtre contrato e categoria. O card resume prioridade, visibilidade, prazo e progresso. Clique nele para abrir os detalhes.'),
    step('detail', 'detail-header', 'Consulte e atualize a atividade', 'O detalhe reúne a descrição, o prazo, a situação e os responsáveis. As opções de editar e excluir aparecem conforme suas permissões no contrato e na atividade.'),
    step('detail', 'detail-checklist', 'Registre o progresso do checklist', 'Marque cada etapa concluída. O sistema atualiza o progresso e registra quem concluiu. Novas etapas podem ser incluídas pela edição quando necessário.'),
    step('detail', 'detail-updates', 'Centralize comentários e evidências', 'Registre decisões e pendências nos comentários e anexe documentos de apoio. A equipe acompanha as movimentações pelas notificações. Evite deixar informações importantes fora do card.'),
    step('flow', 'column-in_progress', 'Mova o card conforme a execução', 'Arraste de A fazer para Em andamento quando o trabalho começar. Ao terminar a execução, use Em revisão para indicar que o resultado aguarda conferência.', 'in_progress'),
    step('flow', 'column-done', 'Conclua depois da conferência', 'Mova para Concluídas ao finalizar o trabalho. O sistema registra a data de resolução e a compara com o prazo nos indicadores. A movimentação depende das suas permissões.', 'done'),
    step('metrics', 'metrics-summary', 'Acompanhe os resultados em Métricas', 'Abra Métricas e filtre período, contrato, categoria e responsável. Veja total de atividades, conclusão, pontualidade, tempo médio de resolução e pendências atrasadas.'),
    step('metrics', 'metrics-responsibles', 'Identifique prioridades da equipe', 'Compare o desempenho por responsável e consulte os gráficos, as conclusões e os atrasos. Use essas informações para distribuir tarefas e cobrar pendências. Você concluiu o tour de Atividades!'),
];

// Resolve links diretos e transições de tela sem reiniciar o contador global.
export function resolveActivityTourIndex(section, requestedIndex) {
    const index = requestedIndex === null || requestedIndex === '' ? NaN : Number(requestedIndex);
    if (Number.isInteger(index) && activityTourSteps[index]?.section === section) return index;
    return Math.max(0, activityTourSteps.findIndex(item => item.section === section));
}
