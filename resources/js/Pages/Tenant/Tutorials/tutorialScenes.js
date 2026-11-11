import { getWalkthroughExample } from './tutorialExamples';

const screen = (label, title, fields, extra = {}) => ({ label, title, fields, ...extra });
const contract = ['Contrato', '001-DNIT — Supervisão das obras da BR-316'];
const work = ['Obra', '101 · Drenagem e dispositivos de concreto'];
const subject = ['Assunto', 'Obstrução na saída de drenagem do km 13,20'];

const budgetCreation = [
    screen('Dados gerais', 'Parametrize o orçamento', [['Código', 'ORC-001'], ['Descrição', 'Recuperação dos dispositivos de drenagem — BR-316'], ['Cliente', 'DNIT'], ['Categoria', 'Infraestrutura'], ['Prazo de entrega do orçamento', '20/11/2026'], ['Permitir insumos com preço zerado', 'Não']], { action: 'Próximo: cálculo' }),
    screen('Cálculo', 'Defina as regras de cálculo', [['Arredondamento', 'Truncar todos os valores em 2 casas decimais'], ['Encargos sociais', 'Desonerado'], ['Incidência do BDI', 'Preço unitário'], ['Percentual de BDI (%)', '22,00%']], { action: 'Próximo: bases' }),
    screen('Bases', 'Selecione as bases de referência', [['Banco', 'SINAPI'], ['Estado', 'PA'], ['Data de referência', '10/2026']], { action: 'Criar orçamento', description: 'Depois de criar o orçamento, a estrutura é montada na tela do orçamento.' }),
    screen('Etapa', 'Adicione uma etapa ao orçamento', [['Item', '1'], ['Descrição', 'Serviços de drenagem']], { action: 'Salvar etapa' }),
    screen('Insumo', 'Inclua um insumo na etapa', [['Etapa', '1 · Serviços de drenagem'], ['Buscar insumo', 'Cimento Portland'], ['Quantidade', '50,00']], { picker: 'insumo', action: 'Adicionar insumo' }),
    screen('Composição', 'Inclua uma composição na etapa', [['Etapa', '1 · Serviços de drenagem'], ['Buscar composição', 'Execução de sarjeta'], ['Quantidade', '120,00']], { picker: 'composicao', action: 'Adicionar composição' }),
    screen('Acessos', 'Defina quem pode acessar o orçamento', [['Usuário', 'Mariana Costa'], ['Acesso', 'Visualizar e editar']], { action: 'Salvar acessos', description: 'Em Acessos, conceda visualização ou edição. O criador e os administradores já têm acesso.' }),
];

function budgetScreens(index) {
    switch (index) {
        case 0: return [screen('Insumos', 'Pesquise e selecione os insumos', [['Buscar por descrição ou código', 'Cimento Portland'], ['Banco', 'SINAPI'], ['Estado', 'PA'], ['Data de referência', '10/2026']], { picker: 'insumo' })];
        case 1: return [screen('Composição', 'Monte uma composição própria', [['Código', 'CP-001'], ['Descrição', 'Execução de sarjeta de concreto'], ['Modelo', 'SINAPI'], ['Método de cálculo', 'Coeficientes'], ['Unidade', 'm'], ['Insumo', 'Concreto usinado'], ['Coeficiente', '0,20 m³ por m de serviço']], { action: 'Salvar composição' })];
        case 2: return [screen('Base oficial', 'Confira uma composição oficial', [['Banco', 'SINAPI'], ['Estado', 'PA'], ['Data de referência', '10/2026'], ['Composição', 'Execução de sarjeta de concreto'], ['Unidade', 'm'], ['Itens da composição', 'Concreto · Pedreiro · Servente']], { picker: 'composicao' })];
        case 3: return budgetCreation;
        case 4: return [budgetCreation[3], budgetCreation[4], budgetCreation[5]];
        case 5: return [budgetCreation[6]];
        case 6: return [screen('Exportação', 'Confira e exporte o orçamento', [['Orçamento', 'ORC-001 · Recuperação dos dispositivos de drenagem'], ['Etapas', '1 · Serviços de drenagem'], ['Verificação de preços zerados', 'Nenhum insumo com preço zerado'], ['Relatório', 'Planilha do orçamento']], { action: 'Gerar relatório' })];
        default: return [];
    }
}

export function getTutorialScenes(tutorial, step) {
    const index = Number(step.id.split('-').at(-1));
    if (tutorial.id === 'atividades') {
        const activityFields = [
            ['Título', 'Vistoria dos dispositivos de drenagem'],
            ['Descrição', 'Verificar o escoamento e as condições das saídas de drenagem do trecho TR-01. Registrar os pontos que precisam de limpeza.'],
            contract, ['Categoria', 'Qualidade'], ['Prioridade', 'Alta'], ['Prazo', '20/11/2026'], ['Visibilidade', 'Pública'],
        ];
        if (step.id === 'atividades-create') return [
            screen('Atividade normal', 'Preencha os dados da atividade', activityFields, { action: 'Criar atividade' }),
            screen('Checklist', 'Preencha os dados e os itens do checklist', [
                ...activityFields,
                ['Itens do checklist', 'Conferir as entradas e saídas de drenagem.\nVerificar obstruções e acúmulo de material.\nRegistrar fotos dos pontos inspecionados.'],
            ], { action: 'Criar checklist' }),
        ];
        if (step.id === 'atividades-visibility') return [screen('Visibilidade', 'Defina quem pode visualizar', [
            ['Atividade', 'Vistoria dos dispositivos de drenagem'], ['Pública', 'Visível aos usuários autorizados do contrato'], ['Restrita', 'Visível ao criador e aos usuários vinculados'],
        ], { action: 'Salvar visibilidade' })];
        if (step.id === 'atividades-flow') return [
            screen('A fazer', 'Atividade aguardando início', [['Título', 'Vistoria dos dispositivos de drenagem'], ['Situação', 'A fazer'], ['Descrição', 'Vistoria programada para verificar os dispositivos do trecho TR-01.']], { action: 'Mover para Em andamento' }),
            screen('Em andamento', 'Registre a execução', [['Título', 'Vistoria dos dispositivos de drenagem'], ['Situação', 'Em andamento'], ['Comentário', 'Inspeção iniciada. Identificados dois pontos com acúmulo de material.'], ['Arquivo', 'Vistoria de drenagem — trecho TR-01.jpg']], { action: 'Mover para Em revisão' }),
            screen('Em revisão', 'Confira o que foi executado', [['Título', 'Vistoria dos dispositivos de drenagem'], ['Situação', 'Em revisão'], ['Comentário', 'Vistoria concluída em campo. Fotos e registros disponíveis para conferência.']], { action: 'Mover para Concluída' }),
            screen('Concluída', 'Atividade concluída', [['Título', 'Vistoria dos dispositivos de drenagem'], ['Situação', 'Concluída'], ['Comentário', 'Registros conferidos e itens do checklist concluídos.']], { action: 'Consultar atividade' }),
        ];
    }
    if (tutorial.id === 'orcamentos') return budgetScreens(index);
    if (tutorial.id === 'diario-obra') {
        if (step.id === 'rdo-settings') return [
            screen('Fluxo', 'Escolha o modelo e as partes', [contract, ['Modelo', step.modelLabel], ['Empresa no relatório', 'Concremat Engenharia']]),
            screen('Geração', 'Parametrize a geração do RDO', [work, ['Período', '01/11/2026 a 31/10/2027'], ['Dias de geração', 'Segunda a sexta'], ['Horário de geração', '07:00'], ['Copiar dados', 'Conforme as opções habilitadas no contrato']]),
            screen('Documento', 'Configure o relatório do contrato', [['Modelo do PDF', 'Padrão do sistema'], ['Logos', 'Cliente · Gerenciadora'], ['Registro fotográfico no PDF', 'Habilitado']], { action: 'Salvar parametrização' }),
        ];
        if (step.type === 'resources') return [
            screen('Tempo', 'Informe as condições do tempo', [work, ['Manhã', 'Bom'], ['Tarde', 'Chuvoso'], ['Noite', 'Estável'], ['Pluviosidade', '8,50 mm'], ['Feriado', 'Não']]),
            screen('Mão de obra', 'Registre a mão de obra desta obra', [['Mão de obra direta', '4 serventes · 1 pedreiro'], ['Mão de obra indireta', '1 engenheiro'], ['Subcontratada', 'Pavimentação Nordeste Serviços Ltda. · 3 pessoas']]),
            screen('Equipamentos', 'Registre os equipamentos utilizados', [['Equipamento', 'Retroescavadeira'], ['Quantidade', '1'], ['Situação', 'Operando']]),
            screen('Atividades', 'Descreva as atividades do dia', [['Título', 'Limpeza e recuperação da drenagem'], ['Descrição', 'Limpeza de 120 m de sarjetas e recuperação de duas saídas de drenagem no trecho TR-01.']]),
            screen('Comentário', 'Registre o comentário geral', [['Comentário', 'Serviços executados conforme a programação. A chuva interrompeu a atividade no período da tarde.']]),
            screen('Fotos', 'Inclua fotos quando houver', [['Arquivo', 'Drenagem — trecho TR-01.jpg'], ['Legenda', 'Saída de drenagem após a limpeza'], ['Obrigatoriedade', 'Foto opcional']], { action: step.action, description: 'O preenchimento é por obra. O encaminhamento movimenta todas as obras juntas; no modelo somente Gerenciadora, o RDO é salvo sem aprovação.' }),
        ];
        if (step.type === 'decision') {
            const managerReview = step.role === 'Gerenciadora' && step.modelLabel?.includes('Construtora');
            const hasConstructor = step.modelLabel?.includes('Construtora');
            const scenes = [screen(managerReview ? 'Construtora' : 'Comparação', managerReview ? 'Confira o preenchimento da construtora' : 'Compare os registros por obra', [work, ['Atividades registradas', 'Limpeza de sarjetas e recuperação de duas saídas de drenagem.'], ['Clima registrado', 'Manhã: bom · Tarde: chuvoso'], ['Evidências', 'Drenagem — trecho TR-01.jpg'], ...(!managerReview ? [['Registros disponíveis', hasConstructor ? 'Construtora e gerenciadora, lado a lado por obra' : 'Gerenciadora, por obra']] : [])])];
            if (managerReview) scenes.push(screen('Gerenciadora', 'Registre as considerações da gerenciadora', [work, ['Atividade', 'Vistoria da saída de drenagem recuperada'], ['Descrição', 'Execução conferida em campo; constatado o restabelecimento do escoamento no trecho.'], ['Clima', 'Manhã: bom · Tarde: chuvoso'], ['Comentário', 'A gerenciadora acompanhou a limpeza e verificou as condições das saídas de drenagem.'], ['Evidências', 'Vistoria da gerenciadora.jpg']]));
            scenes.push(screen('Parecer', 'Registre sua manifestação', [['Comentário', 'Registros conferidos conforme a vistoria do trecho TR-01.'], ['Ressalvas da obra', 'Não há ressalvas']], { mode: 'decision', action: step.action }));
            return scenes;
        }
        if (/assinatura/i.test(step.title)) return [screen('Assinaturas', 'Envie o documento aos responsáveis selecionados', [['Modelo', step.modelLabel], ['Partes do fluxo', step.modelLabel], ['Documento', 'RDO 001 — consolidado para assinatura.pdf']], { action: 'Enviar para assinatura' })];
    }
    if (index === 0 && ['qualidade-rnc', 'qualidade-ic'].includes(tutorial.id)) {
        const isRnc = tutorial.id === 'qualidade-rnc';
        const identification = screen('Identificação', isRnc ? 'Identifique a RNC' : 'Identifique a IC', [contract, work, subject, ['Disciplina', 'Drenagem'], ['Gravidade', 'Grave'], ...(isRnc ? [['IC (opcional)', 'IC 001/2026 · Obstrução na saída de drenagem']] : [])]);
        const instruction = screen(isRnc ? 'Ocorrência' : 'Instrução', isRnc ? 'Descreva a não conformidade' : 'Registre a instrução de campo', [[isRnc ? 'Descrição do problema' : 'Instrução', 'Foi identificada obstrução na saída de drenagem do km 13,20. Remover o material acumulado e restabelecer o escoamento.'], ...(isRnc ? [['Ações corretivas recomendadas', 'Limpeza da saída de drenagem e recomposição da proteção do talude.'], ['Prazo para resposta de ação corretiva', '20/11/2026']] : [])]);
        const photos = screen('Registro fotográfico', 'Inclua o registro fotográfico', [['Arquivo', 'Saída de drenagem — km 13,20.jpg'], ['Descrição', 'Material acumulado junto à saída de drenagem']], { action: isRnc ? 'Registrar RNC' : 'Registrar IC' });
        return [identification, instruction, photos];
    }
    const { fields } = getWalkthroughExample(tutorial, step);
    const evidence = ['qualidade-rnc', 'qualidade-ic'].includes(tutorial.id) && /evidência|finaliza/i.test(step.title);
    const mode = evidence ? 'evidence' : step.type === 'decision' ? 'decision' : undefined;
    // A single action is one scene. Context and confirmation do not create artificial steps.
    return [screen(step.title, step.title, fields, { description: step.description, mode, action: step.action })];
}
