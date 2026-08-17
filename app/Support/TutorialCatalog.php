<?php

namespace App\Support;

class TutorialCatalog
{
    public static function all(): array
    {
        return [
            self::guide(
                'primeiros-passos', 'Comece por aqui', 'Ordem recomendada de configuração', 'BookOpen',
                'Visão geral', 'dashboard', 'tenant.dashboard',
                'Prepare tenant, contrato, cadastros e acessos antes de iniciar a operação.',
                'Owner, administradores e gestores',
                ['Tenant criado e owner ativo.', 'Dados básicos do primeiro contrato disponíveis.'],
                [
                    ['Confirme o ambiente', 'Valide tenant, owner, plano e dados da empresa antes de convidar a equipe.'],
                    ['Crie o contrato', 'Cadastre identificação, documento, valor, moeda, vigência e localização.'],
                    ['Monte a estrutura', 'Cadastre empresas, obras, trechos e disciplinas e salve os vínculos do contrato.'],
                    ['Escolha a medição', 'Defina Medição Simples ou Controlada antes de iniciar o fluxo financeiro.'],
                    ['Vincule usuários', 'Crie as contas, vincule os contratos e distribua permissões por módulo.'],
                    ['Inicie a operação', 'Use atividades, projetos, documentação, qualidade, diário, OS e medição conforme o contrato.'],
                ],
                ['Comece com um contrato piloto.', 'Use códigos padronizados, pois eles reaparecem em EAPs, filtros e relatórios.'],
                'O ambiente ficará pronto para operar sem depender de cadastros improvisados.',
                ['contratos', 'parametrizacao', 'usuarios-permissoes'],
                ['implantação', 'primeiros passos', 'começar', 'configuração inicial'],
                [
                    self::shot('contratos', 'Contratos como ponto de partida', 'Cada contrato organiza acessos, cadastros e operação.'),
                    self::shot('parametrizacao-empresas', 'Estrutura operacional', 'Parametrize os dados reutilizados pelos módulos.'),
                ]
            ),
            self::guide(
                'administracao-plataforma', 'Plataforma', 'Administração da plataforma', 'Building2',
                'Visão da Plataforma, Tenants e Uso APS', 'platform_admin', 'platform.dashboard',
                'Acompanhe a operação global, administre tenants e consulte armazenamento e consumo técnico.',
                'Administradores da plataforma',
                ['Conta marcada como administradora da plataforma.'],
                [
                    ['Abra a Visão da Plataforma', 'Consulte totais globais de usuários, armazenamento e distribuição dos tenants por situação e plano.'],
                    ['Administre tenants', 'Crie o ambiente da empresa, defina owner, plano e situação e mantenha os dados contratuais da conta atualizados.'],
                    ['Entenda a separação', 'Visão da Plataforma resume toda a operação; Tenants concentra cadastro e manutenção individual de cada cliente.'],
                    ['Consulte Uso APS', 'Acompanhe consumo por tenant e módulo em gigabytes para identificar concentração de armazenamento.'],
                    ['Revise antes de alterar', 'Suspensão, plano e owner afetam o acesso da empresa e devem ser modificados somente após conferência.'],
                ],
                ['O identificador do tenant compõe a URL e deve ser curto e único.', 'A área administrativa não substitui as permissões internas de cada tenant.'],
                'A plataforma terá acompanhamento centralizado sem misturar a operação interna dos clientes.',
                ['primeiros-passos', 'usuarios-permissoes'],
                ['super admin', 'visão da plataforma', 'tenant', 'plano', 'uso aps', 'armazenamento', 'administrador da plataforma']
            ),
            self::guide(
                'visao-geral', 'Gestão', 'Visão geral e pendências', 'LayoutDashboard',
                'Visão geral', 'dashboard', 'tenant.dashboard',
                'Acompanhe pontos de atenção, suas atividades, eventos e resumos autorizados dos módulos.',
                'Todos os usuários do tenant',
                ['Usuário vinculado a pelo menos um contrato.'],
                [
                    ['Leia os pontos de atenção', 'Priorize registros vencidos, aguardando análise ou com ação necessária.'],
                    ['Acompanhe suas atividades', 'O resumo usa a mesma lógica do quadro de atividades para destacar prazo e situação.'],
                    ['Consulte os módulos', 'Os resumos de RDO, Medição, OS e Aditivos respeitam vínculo contratual e permissões macro.'],
                    ['Use os atalhos', 'Abra o registro relacionado diretamente pelo card quando houver uma ação disponível.'],
                    ['Inicie o tour', 'O tour da visão geral apresenta rapidamente os grupos e módulos do menu lateral.'],
                ],
                ['A tela não concede acesso adicional: ela resume somente dados já autorizados.', 'Use o sino para eventos novos e a visão geral para prioridades operacionais.'],
                'O usuário terá uma leitura rápida do que exige atenção sem percorrer todos os módulos.',
                ['notificacoes-perfil', 'atividades'],
                ['visão geral', 'dashboard', 'pendências', 'pontos de atenção', 'resumo']
            ),
            self::guide(
                'contratos', 'Gestão', 'Contratos, vínculos e aditivos', 'ClipboardList',
                'Contratos', 'contracts', 'tenant.contracts.index',
                'Crie o ambiente contratual, parametrize participantes e preserve o histórico de aditivos.',
                'Administradores e usuários autorizados no contrato',
                ['Dados do contrato e documento base.', 'Empresas e estrutura operacional identificadas.'],
                [
                    ['Crie o contrato', 'Em Novo contrato, informe código, valor, moeda, vigência, localização e documento base.'],
                    ['Parametrize a estrutura', 'Cadastre empresas, obras, trechos e disciplinas nas abas correspondentes.'],
                    ['Salve os vínculos', 'Escolha obra principal, cliente, construtora e gerenciadora e confirme os vínculos.'],
                    ['Defina a medição', 'Na aba Medição, escolha Simples ou Controlada. Depois de utilizada, a modalidade fica protegida contra troca.'],
                    ['Vincule a equipe', 'Associe usuários ao contrato para delimitar dados, módulos e responsabilidades.'],
                    ['Registre aditivos', 'Selecione custo, prazo ou ambos, preencha os campos obrigatórios e anexe o documento.'],
                    ['Acompanhe o contrato', 'Ao abrir, consulte prazo restante, equipe e os resumos de atividades, projetos e RNCs.'],
                ],
                ['A obra principal deve ser uma obra pai.', 'O histórico separa contrato base e cada aditivo.', 'Aditivos de prazo começam após a última vigência válida.'],
                'O contrato concentrará participantes, vigência, valor, operação e histórico formal.',
                ['parametrizacao', 'usuarios-permissoes', 'medicao'],
                ['contrato', 'aditivo', 'vigência', 'vínculo', 'equipe do contrato', 'medição simples', 'medição controlada'],
                [self::shot('contratos', 'Portfólio de contratos', 'Filtros, prazo restante e ações ficam reunidos na listagem.')]
            ),
            self::guide(
                'parametrizacao', 'Administração', 'Parametrização do sistema e do contrato', 'SlidersHorizontal',
                'Parametrização', 'settings', 'tenant.dashboard',
                'Padronize empresas, obras, trechos, disciplinas e demais cadastros reutilizados.',
                'Owner e administradores com permissão',
                ['Contrato criado para os vínculos específicos.'],
                [
                    ['Cadastre empresas', 'Use apenas Gerenciadora, Construtora ou Cliente; informe CNPJ formatado, sigla e logo quando disponível.'],
                    ['Cadastre obras', 'O código deve possuir exatamente três dígitos. Defina obra pai e subobra quando necessário.'],
                    ['Cadastre trechos', 'Vincule cada trecho a uma obra. Para obras sem divisão física, utilize GER - Geral.'],
                    ['Cadastre disciplinas', 'A sigla deve possuir exatamente três letras; a descrição separada não é necessária.'],
                    ['Parametrize o contrato', 'No modal do contrato, crie ou selecione os registros e confirme cada operação pelo aviso verde.'],
                    ['Proteja cadastros usados', 'Tipos documentais e etiquetas com documentos, além de cadastros de RDO usados em registros, não podem ser excluídos.'],
                ],
                ['Mantenha siglas e códigos estáveis após iniciar projetos.', 'Trecho GER permite uma EAP uniforme mesmo sem segmentação rodoviária.'],
                'Os módulos compartilharão cadastros consistentes e relações válidas.',
                ['contratos', 'projetos', 'documentacao'],
                ['parametrização', 'empresa', 'obra', 'trecho', 'disciplina', 'cadastro'],
                [self::shot('parametrizacao-empresas', 'Cadastro de empresas', 'Crie e consulte registros no mesmo fluxo.')]
            ),
            self::guide(
                'usuarios-permissoes', 'Administração', 'Usuários, contratos e permissões', 'Users',
                'Usuários e Permissões', 'users', 'tenant.users.index',
                'Crie usuários, vincule contratos e distribua permissões macro por módulo.',
                'Owner e administradores',
                ['Empresas e contratos cadastrados.', 'Responsabilidades de cada pessoa definidas.'],
                [
                    ['Crie o usuário', 'Em Usuários, informe dados, empresa e perfil. As permissões disponíveis são as mesmas da tela Permissões.'],
                    ['Marque por módulo', 'Use Marcar todas ou Desmarcar todas dentro de cada módulo para configurar rapidamente a nova conta.'],
                    ['Vincule contratos', 'Na listagem de usuários, use Vincular e selecione um ou mais contratos acessíveis.'],
                    ['Ajuste permissões', 'Em Permissões, escolha usuário e contrato e libere somente visualização e ações necessárias.'],
                    ['Revise acessos', 'Desative contas ou remova vínculos quando houver mudança de equipe.'],
                ],
                ['Vínculo contratual e permissão macro trabalham juntos.', 'Permissões internas de documentos podem restringir ainda mais o acesso por usuário ou grupo.'],
                'Cada pessoa verá apenas tenants, contratos, módulos e ações compatíveis com sua função.',
                ['contratos', 'documentacao', 'projetos'],
                ['usuário', 'usuarios', 'permissão', 'acesso', 'vincular contrato', 'marcar todas'],
                [
                    self::shot('usuarios', 'Gestão de usuários', 'Crie, edite, vincule e desative contas.'),
                    self::shot('permissoes', 'Permissões por módulo', 'A autorização é configurada dentro do contrato.'),
                ]
            ),
            self::guide(
                'atividades', 'Gestão', 'Atividades e checklists', 'Activity',
                'Atividades', 'activities', 'tenant.activities.index',
                'Organize tarefas comuns ou checklists em um quadro com responsáveis, comunicação e métricas.',
                'Equipes operacionais e gestores',
                ['Usuários vinculados ao contrato.', 'Permissão de visualizar atividades.'],
                [
                    ['Abra o modal', 'Clique em Nova atividade e escolha Atividade ou Checklist.'],
                    ['Preencha os dados', 'Informe título, descrição, contrato, categoria, prioridade, prazo, visibilidade e responsáveis.'],
                    ['Monte o checklist', 'No tipo Checklist, adicione as etapas. Na edição, inclua novas etapas e reposicione a ordem.'],
                    ['Escolha a visibilidade', 'Pública aparece aos usuários autorizados do contrato; restrita somente ao criador e vinculados.'],
                    ['Acompanhe o card', 'Movimente no quadro, comente, anexe arquivos e marque etapas concluídas.'],
                    ['Atualize os envolvidos', 'Novas etapas e movimentações relevantes disparam notificações e e-mails aos participantes.'],
                    ['Consulte Métricas', 'Acompanhe produtividade, categorias, responsáveis, prazo e resolução quando possuir permissão.'],
                ],
                ['A busca de responsáveis sugere os seis usuários mais atribuídos e continua encontrando os demais.', 'O criador pode editar e excluir a própria atividade mesmo sem permissão global para registros alheios.'],
                'A equipe terá tarefas rastreáveis e checklists operacionais no mesmo fluxo.',
                ['notificacoes-perfil', 'visao-geral'],
                ['atividade', 'tarefa', 'checklist', 'kanban', 'etapa', 'métrica de atividade'],
                [self::shot('atividades', 'Quadro de atividades', 'Cards comuns e checklists compartilham o mesmo acompanhamento.')]
            ),
            self::guide(
                'orcamentos', 'Programação', 'Orçamentos, insumos e composições', 'Calculator',
                'Orçamentos', 'budgets', 'tenant.orcamentos.index',
                'Monte bases de preço, composições e estruturas orçamentárias com regras de cálculo e acesso.',
                'Orçamentistas, engenheiros de custos e gestores',
                ['Permissão para visualizar Orçamentos.', 'Competência e UF das bases definidas.'],
                [
                    ['Consulte insumos', 'Filtre SINAPI, SICRO ou base própria por banco, estado, data, tipo, código ou descrição. Cadastre ou importe quando autorizado.'],
                    ['Monte composições', 'Crie no modelo SINAPI ou SICRO, escolha o método de cálculo e vincule insumos e composições auxiliares.'],
                    ['Revise composições oficiais', 'Abra os detalhes SINAPI por UF ou as categorias SICRO para conferir coeficientes, produção, FIC e custos.'],
                    ['Novo orçamento', 'No passo 1, informe identificação e parâmetros gerais. No passo 2, escolha arredondamento, encargos, BDI e bases.'],
                    ['Estruture as etapas', 'Adicione etapas, composições e insumos, informe quantitativos e confira os totais.'],
                    ['Controle os acessos', 'O criador e administradores veem por padrão; em Acessos, conceda somente visualização ou também edição.'],
                    ['Finalize e exporte', 'Gere relatórios, confira preços zerados e finalize para permitir a importação aos Itens de Contrato.'],
                ],
                ['O BDI aceita percentual de 0,00 a 100,00 e insere a vírgula automaticamente.', 'Ao permitir preço zerado, o usuário deverá preencher o valor antes da conclusão.', 'Copie o orçamento inicial para preparar alterações futuras sem perder a referência.'],
                'O orçamento ficará auditável, calculado conforme as regras escolhidas e pronto para alimentar o contrato.',
                ['medicao', 'ordem-servico'],
                ['orçamento', 'insumo', 'composição', 'sinapi', 'sicro', 'bdi', 'arredondamento', 'base própria', 'preço zerado']
            ),
            self::guide(
                'ordem-servico', 'Acompanhamento', 'Ordem de Serviço', 'ClipboardCheck',
                'Ordem de Serviço', 'service_orders', 'tenant.ordem-servico.os.index',
                'Formalize escopo, itens, prazos, responsáveis, execução, análise e aprovação de uma OS.',
                'Equipe contratual, fiscais, aprovadores e gestores',
                ['Contrato parametrizado.', 'Itens de Contrato disponíveis.', 'Responsáveis e prazos configurados.'],
                [
                    ['Parametrize o fluxo', 'Em Parametrização, defina responsáveis e prazos das etapas de análise e aprovação.'],
                    ['Crie a OS', 'Abra o modal Nova OS, informe dados, prazo inicial e final e selecione os itens com busca e paginação.'],
                    ['Edite o rascunho', 'Enquanto estiver em Rascunho, ajuste escopo e itens. Depois do envio para análise, a edição fica bloqueada.'],
                    ['Envie para análise', 'Confirme no modal. O fiscal analisa e os aprovadores concluem a decisão; reprovação exige motivo e devolve ao rascunho.'],
                    ['Acompanhe por abas', 'Resumo, Itens, Execução, Documentos e Histórico reduzem a densidade e preservam a trilha de decisões.'],
                    ['Registre a execução', 'Atualize execução e conclusão conforme a permissão e os limites parametrizados.'],
                    ['Consulte custos e métricas', 'Compare previsto, real medido e percentual, e use Métricas para uma leitura consolidada.'],
                ],
                ['Na Medição Controlada, somente itens de OS aprovada podem ser pleiteados.', 'O custo real usa quantidade efetivamente medida e valor aplicável.', 'E-mails seguem o padrão visual do sistema em submissão, análise, aprovação e reprovação.'],
                'A OS terá escopo controlado, responsáveis, prazos e rastreabilidade do rascunho à conclusão.',
                ['orcamentos', 'medicao'],
                ['ordem de serviço', 'os', 'fiscal', 'aprovação os', 'execução os', 'custo previsto', 'custo real']
            ),
            self::guide(
                'medicao', 'Acompanhamento', 'Medição e Folhas de Rosto', 'Ruler',
                'Medição', 'measurement', 'tenant.medicao.boletim-medicao.index',
                'Gerencie itens contratuais, pleitos, boletins, reajustes, análise e relatórios financeiros.',
                'Medição, fiscalização, qualidade e gestores',
                ['Modalidade de medição definida no contrato.', 'Orçamento finalizado para importar os itens.'],
                [
                    ['Importe Itens de Contrato', 'Use um orçamento finalizado. Em atualizações futuras, o sistema impede reduzir quantitativo abaixo do que já foi medido.'],
                    ['Cadastre índices', 'Registre índices de reajuste por competência e valide a virada entre anos antes de aplicar aos itens.'],
                    ['Abra o boletim', 'Cada contrato possui sua própria sequência iniciando em BM-0001. Reequilíbrio e Contingência permanecem sinalizados como em breve.'],
                    ['Crie a Folha de Rosto', 'Na modalidade Simples, pleiteie itens sem OS; na Controlada, use somente itens vinculados a uma OS aprovada.'],
                    ['Percorra as análises', 'Submeta e acompanhe responsáveis e datas nas etapas fiscal, qualidade, medição e finalização.'],
                    ['Consulte o fluxo', 'O relatório de fluxo por BM registra quem submeteu, analisou e concluiu cada FR.'],
                    ['Gere relatórios', 'Use Boletim de Medição, Relatórios de Medição e B.I. para conferência e acompanhamento.'],
                ],
                ['Nunca reduza saldo ou quantitativo já medido.', 'Valide arredondamento, reajuste e acumulados antes de finalizar um BM.', 'A modalidade contratual não deve mudar após iniciar a operação.'],
                'O histórico financeiro ficará reproduzível por contrato, BM, FR, item, índice e responsável.',
                ['orcamentos', 'ordem-servico', 'contratos'],
                ['medição', 'boletim', 'bm', 'folha de rosto', 'fr', 'reajuste', 'índice', 'item de contrato', 'pleito']
            ),
            self::guide(
                'diario-obra', 'Campo', 'Diário de Obra: RDA e RDO', 'HardHat',
                'Diário de Obra', 'field', 'tenant.diario-obra.rdo.calendar',
                'Registre apontamentos de campo no RDA e consolide, revise e assine o RDO.',
                'Equipe de campo, construtora, gerenciadora e cliente',
                ['Contrato, obra e frente configurados.', 'Responsáveis e catálogos cadastrados.'],
                [
                    ['Prepare os cadastros', 'Configure mão de obra, equipamentos e subcontratadas; registros usados em RDO não podem ser excluídos.'],
                    ['Defina responsáveis', 'Associe preenchimento da construtora e aprovação da gerenciadora e do cliente por obra ou frente.'],
                    ['Preencha o RDA', 'No aplicativo mobile, registre o apoio diário inclusive offline e sincronize quando houver conexão.'],
                    ['Consolide o RDO', 'Reúna clima, serviços, recursos, ocorrências, fotos e apontamentos do dia.'],
                    ['Revise e aprove', 'Encaminhe pelas etapas definidas, preservando comentários, responsáveis, datas e histórico.'],
                    ['Assine digitalmente', 'Utilize o fluxo de assinatura digital para formalizar o registro final.'],
                    ['Gere e acompanhe', 'Baixe o PDF e consulte calendário e dashboard para acompanhar preenchimento e pendências.'],
                ],
                ['O RDA é apoio ao RDO e pode ser preenchido offline no aplicativo.', 'Estado Ativo é administrado na edição dos cadastros, não na criação.'],
                'O diário terá registros de campo completos, aprovações e assinatura rastreáveis.',
                ['contratos', 'usuarios-permissoes'],
                ['diário de obra', 'rdo', 'rda', 'offline', 'assinatura digital', 'mão de obra', 'equipamento']
            ),
            self::guide(
                'qualidade-rnc', 'Campo', 'Qualidade e RNC', 'ShieldCheck',
                'Qualidade > RNC', 'quality', 'tenant.qualidade.rnc.index',
                'Conduza a não conformidade da alocação de responsáveis ao encerramento com evidências.',
                'Qualidade, responsável operacional, construtora e acompanhamento',
                ['Contrato e obra vinculados.', 'Responsáveis da RNC configurados.'],
                [
                    ['Alocar responsáveis', 'Cadastre Responsável Operacional, Responsável da Construtora e Responsável de Acompanhamento conforme suas atribuições.'],
                    ['Nova RNC', 'Selecione primeiro o contrato e depois a obra; informe disciplina, gravidade, localização, descrição, observação e recomendação.'],
                    ['Vincule projetos', 'Selecione um ou mais projetos autorizados; a EAP exibe trecho e revisão no final.'],
                    ['Notificar', 'O responsável operacional formaliza a notificação e dispara alertas e e-mails.'],
                    ['Receba a ação corretiva', 'A construtora envia proposta, prazo e documentos para análise.'],
                    ['Analise e evidencie', 'Aprove ou devolva a proposta e, depois da execução, registre evidências comentadas.'],
                    ['Finalize e acompanhe', 'Gere o PDF final e consulte o dashboard de prazos, gravidade, responsáveis e situação.'],
                ],
                ['O mapa atualiza o ponto ao alterar latitude e longitude.', 'O PDF preserva parágrafos, quebras de linha, imagens e fluxo.', 'O submenu IC está reservado, ainda sem operação.'],
                'A RNC preservará comunicação, decisão, ação corretiva e comprovação do encerramento.',
                ['projetos', 'notificacoes-perfil'],
                ['qualidade', 'rnc', 'não conformidade', 'ação corretiva', 'evidência', 'responsável operacional', 'construtora']
            ),
            self::guide(
                'documentacao', 'Controle', 'Documentação e GED', 'FileText',
                'Documentação', 'documents', 'tenant.ged.index',
                'Centralize PDFs, OCR, anexos, permissões documentais, e-mails, triagem e descarte seguro.',
                'Equipes documentais e usuários autorizados',
                ['Permissão macro de visualizar Documentação.', 'Contrato acessível.'],
                [
                    ['Envie o documento', 'O contrato é o único campo obrigatório. O documento principal aceita PDF e entra na fila de OCR.'],
                    ['Acompanhe o OCR', 'Abra Detalhes, Conteúdo, Anexos, Metadados, Notas, Histórico e Permissões. Durante a fila aparece Processando OCR.'],
                    ['Anexos', 'Vincule vários arquivos ao documento principal. PDFs anexos também recebem OCR e podem ser visualizados.'],
                    ['Restrinja o acesso', 'Além da permissão macro e do vínculo contratual, defina proprietário, usuários e grupos com acesso de Ver ou Editar.'],
                    ['Automatize e-mails', 'Configure conta e regras. Um único PDF vira principal e os demais arquivos são anexos; mais de um PDF pode exigir Triagem, conforme o escopo.'],
                    ['Faça a triagem', 'Escolha o PDF principal quando a regra gerar ambiguidade e mantenha os demais como anexos vinculados.'],
                    ['Use filtros e downloads', 'Filtros fecham ao trocar ou clicar fora. Baixe arquivo arquivado, original e, quando marcado, use o nome formatado.'],
                    ['Use a lixeira', 'A exclusão comum move para a Lixeira, onde é possível restaurar ou excluir definitivamente mediante confirmação.'],
                ],
                ['Documentos já processados por e-mail não devem ser importados novamente.', 'Tipos e etiquetas em uso não podem ser excluídos.', 'O acesso por grupo pode separar construtora, cliente e gerenciadora.'],
                'O acervo ficará pesquisável, protegido e rastreável da entrada ao descarte.',
                ['usuarios-permissoes', 'contratos'],
                ['documentação', 'ged', 'ocr', 'anexo', 'email', 'e-mail', 'triagem', 'lixeira', 'permissão de documento', 'grupo']
            ),
            self::guide(
                'projetos', 'Controle', 'Projetos e revisões', 'FolderOpen',
                'Projetos', 'projects', 'tenant.projects.visualizar.index',
                'Controle responsáveis, EAP, submissão individual ou em lote, APS, análise, árvore e revisões.',
                'Projetistas, analistas, aprovadores e coordenação',
                ['Contrato com obra, trecho, disciplina e fase.', 'Responsáveis por disciplina cadastrados.'],
                [
                    ['Defina responsáveis', 'Vincule quem analisa e quem aprova cada disciplina do contrato.'],
                    ['Submeter projeto', 'Informe contrato, obra, trecho, disciplina, fase, tipo, título e arquivo. A revisão aparece no final da EAP.'],
                    ['Use o lote', 'Selecione mais de um arquivo, revise disciplina e sequencial de três dígitos e envie o pacote identificado como LOT-001-AAAA.'],
                    ['Revise alertas', 'Se a EAP já existir, o sistema informa que o arquivo entrará como revisão. CAP existe somente para revisões e pode consolidar o pacote.'],
                    ['Aguarde o APS', 'Cada projeto segue processamento próprio; o indicador giratório permanece enquanto a tradução está em fila ou execução.'],
                    ['Analise e aprove', 'Use checklist, Viewer e comentários. O pacote pode ser analisado em conjunto sem perder os botões individuais de baixar, checklist e CAP.'],
                    ['Visualize na árvore', 'Projetos aprovados aparecem por contrato, obra, trecho e disciplina; expanda tudo por contrato quando necessário.'],
                    ['Comente e responda', 'Crie comentários visuais com um ou mais responsáveis, acompanhe respostas e vinculação ao objeto ou marcação.'],
                    ['Controle disponibilidade', 'Ao entrar Em revisão ou receber bloqueio manual com motivo, o projeto sai temporariamente da visualização. Alertas de RNC continuam visíveis.'],
                    ['Consulte o acervo', 'Projetos Revisados reúne CAP e comparação; Lista Mestra filtra, pagina e exporta; Responsáveis mostra disciplinas por usuário.'],
                ],
                ['Sem trecho físico, utilize GER - Geral.', 'O sequencial sugerido considera a EAP escolhida e avança ao mudar seus dados.', 'Aprovação de revisão notifica todos os usuários vinculados ao contrato.'],
                'O acervo técnico ficará controlado por EAP, revisão, pacote, decisão e disponibilidade.',
                ['parametrizacao', 'qualidade-rnc', 'documentacao'],
                ['projeto', 'eap', 'trecho', 'aps', 'autodesk', 'lote', 'pacote', 'cap', 'revisão', 'lista mestra', 'viewer']
            ),
            self::guide(
                'notificacoes-perfil', 'Ajuda', 'Notificações e perfil', 'Bell',
                'Sino e perfil', 'dashboard', 'tenant.dashboard',
                'Identifique eventos novos, marque a leitura e mantenha os dados da conta atualizados.',
                'Todos os usuários',
                ['Conta ativa.'],
                [
                    ['Abra o sino', 'A lista apresenta até 50 notificações recentes; as novas usam cor distinta e mantêm o indicador vermelho.'],
                    ['Marque como lida', 'Ao passar o mouse sobre uma notificação nova, ela é marcada como lida e muda de aparência.'],
                    ['Entenda o contador', 'O sino depende do total não lido no servidor, inclusive para registros fora da lista carregada.'],
                    ['Acesse o registro', 'Abra a notificação quando houver destino relacionado à atividade, projeto, RNC, OS ou outro fluxo.'],
                    ['Atualize o perfil', 'Revise nome, e-mail, foto e senha nas configurações da conta.'],
                ],
                ['Notificações já lidas permanecem no histórico recente.', 'Nunca compartilhe credenciais individuais.'],
                'O usuário distinguirá eventos novos sem perder o histórico recente.',
                ['visao-geral', 'atividades'],
                ['notificação', 'sino', 'não lida', 'perfil', 'senha']
            ),
            self::guide(
                'assistente-deming', 'Ajuda', 'Assistente Deming e tutoriais', 'Bot',
                'Chat flutuante e Tutoriais', 'tutorials', 'tenant.tutorials.index',
                'Use o agente para consultar dados autorizados e aprender os fluxos do sistema.',
                'Todos os usuários',
                ['Permissão e vínculo nos módulos sobre os quais será feita a consulta.'],
                [
                    ['Abra o chat', 'Use o botão flutuante no canto inferior direito para expandir o assistente.'],
                    ['Faça uma pergunta objetiva', 'Informe contrato, BM, projeto, RNC ou módulo quando isso ajudar a delimitar a consulta.'],
                    ['Peça um tutorial', 'Pergunte como executar um fluxo. O agente usa este catálogo e adapta a orientação às permissões do usuário.'],
                    ['Consulte documentos', 'Quando autorizado, o agente pode ler conteúdo OCR e memória de cálculo acessível para resumir ou localizar informações.'],
                    ['Prepare rascunhos', 'Ações assistidas podem preencher um rascunho, como uma nova RNC, para revisão humana antes de salvar.'],
                    ['Acompanhe a cota', 'Cada usuário possui cota mensal de 60.000 tokens, exibida como percentual utilizado.'],
                ],
                ['O agente não atravessa tenant, contrato, módulo ou permissão documental.', 'Links aparecem somente quando ajudam a abrir um registro ou fluxo relacionado.', 'Revise qualquer rascunho antes de confirmar a gravação.'],
                'O usuário poderá tirar dúvidas e chegar ao fluxo correto sem perder os controles de acesso.',
                ['primeiros-passos', 'usuarios-permissoes'],
                ['assistente', 'agente', 'rag', 'chat', 'tutorial', 'cota', 'token', 'memória de cálculo']
            ),
        ];
    }

    public static function assistantGuides(): array
    {
        $labels = [
            'dashboard' => 'Visão geral',
            'platform_admin' => 'Administração da plataforma',
            'contracts' => 'Contratos',
            'settings' => 'Parametrização',
            'users' => 'Usuários e Permissões',
            'activities' => 'Atividades',
            'budgets' => 'Orçamentos',
            'service_orders' => 'Ordem de Serviço',
            'measurement' => 'Medição',
            'field' => 'Diário de Obra',
            'quality' => 'Qualidade / RNC',
            'documents' => 'Documentação',
            'projects' => 'Projetos',
            'tutorials' => 'Assistente e Tutoriais',
        ];

        return collect(self::all())
            ->filter(fn (array $guide): bool => $guide['enabled'] && $guide['assistant'])
            ->mapWithKeys(fn (array $guide): array => [$guide['id'] => [
                'capability' => $guide['capability'],
                'label' => $labels[$guide['capability']] ?? $guide['title'],
                'aliases' => $guide['aliases'],
                'route' => $guide['route'],
                'enabled' => true,
                'summary' => $guide['summary'],
                'workflow' => self::assistantText($guide),
                'tutorial' => self::assistantText($guide),
            ]])
            ->all();
    }

    private static function guide(
        string $id,
        string $group,
        string $title,
        string $icon,
        string $path,
        string $capability,
        string $route,
        string $summary,
        string $audience,
        array $prerequisites,
        array $steps,
        array $tips,
        string $outcome,
        array $related,
        array $aliases,
        array $screenshots = [],
        bool $enabled = true,
        bool $assistant = true,
    ): array {
        $screenshots = self::screenshotsFor($id) ?: $screenshots;
        $videos = self::videosFor($id);

        return compact(
            'id', 'group', 'title', 'icon', 'path', 'capability', 'route', 'summary', 'audience',
            'prerequisites', 'steps', 'tips', 'outcome', 'related', 'aliases', 'screenshots', 'videos', 'enabled', 'assistant'
        );
    }

    private static function shot(string $file, string $title, string $caption): array
    {
        return [
            'src' => "/images/tutorials/{$file}.png",
            'title' => $title,
            'caption' => $caption,
        ];
    }

    private static function video(string $file, string $poster, string $title, string $caption): array
    {
        return [
            'src' => "/videos/tutorials/{$file}.webm",
            'poster' => "/images/tutorials/{$poster}.png",
            'title' => $title,
            'caption' => $caption,
        ];
    }

    private static function videosFor(string $id): array
    {
        return match ($id) {
            'qualidade-rnc' => [
                self::video(
                    'qualidade-rnc',
                    'qualidade-rnc-atual',
                    'Percurso pelo módulo de Qualidade e RNC',
                    'Veja a listagem, o painel de acompanhamento, a definição dos responsáveis e o início de uma nova RNC.'
                ),
            ],
            default => [],
        };
    }

    private static function screenshotsFor(string $id): array
    {
        return match ($id) {
            'primeiros-passos' => [
                self::shot('contratos-atual', 'Contratos como ponto de partida', 'O contrato organiza a equipe, os cadastros e os fluxos de trabalho.'),
                self::shot('parametrizacao-atual', 'Estrutura operacional', 'Empresas, obras, trechos e disciplinas preparam os demais módulos.'),
            ],
            'administracao-plataforma' => [
                self::shot('admin-plataforma-atual', 'Visão da Plataforma', 'A administração global resume tenants, usuários, planos e armazenamento.'),
            ],
            'visao-geral' => [
                self::shot('visao-geral-atual', 'Visão geral do workspace', 'Pendências e resumos autorizados ficam organizados por prioridade e módulo.'),
            ],
            'contratos' => [
                self::shot('contratos-atual', 'Portfólio de contratos', 'Filtros, prazos e ações contratuais ficam reunidos na listagem.'),
            ],
            'parametrizacao' => [
                self::shot('parametrizacao-atual', 'Parametrização', 'Cadastros reutilizáveis mantêm códigos e vínculos consistentes.'),
            ],
            'usuarios-permissoes' => [
                self::shot('usuarios-atual', 'Gestão de usuários', 'Crie usuários, vincule contratos e mantenha as contas atualizadas.'),
                self::shot('permissoes-atual', 'Permissões por módulo', 'As ações disponíveis são definidas por usuário e contrato.'),
            ],
            'atividades' => [
                self::shot('atividades-atual', 'Quadro de atividades', 'Atividades comuns e checklists compartilham acompanhamento e responsáveis.'),
            ],
            'orcamentos' => [
                self::shot('orcamentos-atual', 'Orçamentos', 'A tela atual reúne criação, importação, situação e acesso aos orçamentos.'),
            ],
            'ordem-servico' => [
                self::shot('ordem-servico-atual', 'Ordens de Serviço', 'A listagem apresenta situação, custos, medição e ações disponíveis.'),
            ],
            'medicao' => [
                self::shot('medicao-atual', 'Boletins de Medição', 'Os boletins são organizados por contrato, competência e situação.'),
            ],
            'diario-obra' => [
                self::shot('diario-obra-atual', 'Diário de Obra', 'O calendário concentra os registros de RDO e o acompanhamento diário.'),
            ],
            'qualidade-rnc' => [
                self::shot('qualidade-rnc-atual', 'Qualidade e RNC', 'A operação de não conformidades começa na listagem e segue o fluxo definido.'),
            ],
            'documentacao' => [
                self::shot('documentacao-atual', 'Documentação', 'Busca, filtros, visualizações e ações do GED aparecem no mesmo espaço.'),
            ],
            'projetos' => [
                self::shot('projetos-atual', 'Árvore de projetos', 'A visualização organiza contratos, EAPs, revisões e disponibilidade dos arquivos.'),
            ],
            'notificacoes-perfil' => [
                self::shot('notificacoes-atual', 'Notificações', 'O sino destaca eventos novos e permite acompanhar as mudanças recentes.'),
            ],
            'assistente-deming' => [
                self::shot('assistente-atual', 'Assistente Deming', 'O chat usa somente dados autorizados e pode preparar rascunhos para revisão.'),
            ],
            default => [],
        };
    }

    private static function assistantText(array $guide): string
    {
        $lines = [
            $guide['summary'],
            'Pré-requisitos: '.implode(' ', $guide['prerequisites']),
        ];

        foreach ($guide['steps'] as $index => [$title, $description]) {
            $lines[] = ($index + 1).". {$title}: {$description}";
        }

        if ($guide['tips'] !== []) {
            $lines[] = 'Atenção: '.implode(' ', $guide['tips']);
        }

        $lines[] = 'Resultado: '.$guide['outcome'];

        return implode("\n", $lines);
    }
}
