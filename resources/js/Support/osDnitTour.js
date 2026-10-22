export const tourSections = [
    ['responsibles', 'Responsáveis'], ['products', 'Produtos'], ['create', 'Criar OS'],
    ['submission', 'Enviar'], ['concremat', 'Análise Concremat'], ['inspector', 'Fiscal do contrato'], ['dashboard', 'Dashboard'],
];

const step = (section, view, target, title, content) => ({ section, view, target: `[data-tour="dnit-${target}"]`, title, content });

export const osDnitTourSteps = [
    step('responsibles', 'responsibles', 'contract', 'Escolha o contrato', 'Em Responsáveis, selecione o contrato. Os cadastros e as OSs ficam separados por contrato; a equipe precisa de vínculo ativo e das permissões correspondentes.'),
    step('responsibles', 'responsibles', 'roles', 'Defina quem solicita, analisa e decide', 'Marque o Analista DNIT para solicitar, o Fiscal Concremat para analisar e o Fiscal do contrato para decidir. Clique em Salvar responsáveis para registrar a equipe que receberá os avisos do fluxo.'),
    step('products', 'products', 'catalog', 'Confira o catálogo de produtos', 'Pesquise por código ou descrição e filtre por contrato ou categoria. Para incluir um item, clique em Novo produto; ele ficará disponível somente no contrato cadastrado.'),
    step('products', 'product-form', 'product-fields', 'Cadastre as características do produto', 'Informe contrato, código, descrição, categoria, unidade e frequência Mensal ou Por demanda. Salve para disponibilizar o produto na criação da OS.'),
    step('create', 'create', 'request-fields', 'Crie e descreva a solicitação', 'Em Ordem de Serviço, clique em Criar OS. Preencha contrato, lote, rodovia, trecho, assunto, prazo, necessidade e justificativa. Inclua anexos se necessário. A rodovia vem da Parametrização e o número é automático.'),
    step('create', 'create', 'requested-products', 'Selecione os produtos e salve o rascunho', 'Pesquise e marque os produtos do contrato, informe as quantidades e clique em Salvar rascunho. Revise antes de enviar: somente o autor autorizado pode editar uma OS ainda em rascunho e nunca enviada.'),
    step('submission', 'submit-confirm', 'confirmation', 'Confirme o envio para análise', 'Na listagem ou no detalhe, clique em Enviar para análise e confirme no popup. A OS segue ao Fiscal Concremat. Após o envio, o pedido original fica bloqueado para edição, inclusive se for devolvido.'),
    step('concremat', 'concremat', 'review', 'Registre a Análise Concremat', 'Abra Analisar OS na listagem e consulte o pedido original. Ajuste produtos, quantidades e prazo estimado e preencha o comentário. Salve para continuar depois ou encaminhe ao fiscal, confirmando o envio.'),
    step('inspector', 'inspector', 'comparison', 'Compare e aprove o serviço', 'O fiscal compara o pedido com a Análise Concremat; as diferenças ficam destacadas. Para aprovar, registre o comentário da decisão e confirme. O PDF pode reunir a solicitação, as análises e o fluxo completo.'),
    step('inspector', 'inspector-return', 'return', 'Se houver problema, devolva com o motivo', 'Informe o motivo e o destino: Fiscal Concremat para ajustes de produto, quantidade ou prazo da análise; Analista DNIT para problemas no pedido. Confirme a devolução para registrar a decisão e notificar o responsável.'),
    step('dashboard', 'dashboard', 'metrics', 'Acompanhe o Dashboard', 'No Dashboard, filtre contrato, rodovia e período. Os cartões mostram total, rascunhos, análises, pedidos com o fiscal, aprovadas e devolvidas. A aprovação encerra o fluxo, mas não comprova a execução do serviço.'),
    step('dashboard', 'dashboard', 'charts', 'Priorize as pendências', 'Use os gráficos e a lista de pendências para acompanhar a evolução e as esperas. Acessar abre a OS; Analisar OS aparece para quem pode atuar na etapa. Você concluiu o tour!'),
];
