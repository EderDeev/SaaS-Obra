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
                'Prepare a diretoria, os contratos e os acessos para iniciar a operação.',
                'Owner, administradores e gestores',
                ['Diretoria criada e owner ativo.', 'Dados básicos do primeiro contrato disponíveis.'],
                [
                    ['Confirme a diretoria', 'Confira a diretoria selecionada e seu acesso antes de configurar contratos ou convidar a equipe. Tenant representa uma diretoria; as empresas participantes são cadastradas separadamente nos contratos.'],
                    ['Crie o contrato', 'Cadastre identificação, documento, moeda, vigência e localização. O valor é opcional e pode ser informado ou editado depois.'],
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
                'Visão da Plataforma, Diretorias e Uso APS', 'platform_admin', 'platform.dashboard',
                'Acompanhe a operação global, administre tenants e consulte armazenamento e consumo técnico.',
                'Administradores da plataforma',
                ['Conta marcada como administradora da plataforma.'],
                [
                    ['Abra a Visão da Plataforma', 'Consulte totais globais de usuários, armazenamento e distribuição dos tenants por situação e plano.'],
                    ['Administre diretorias', 'Cadastre as diretorias na área Tenants e defina owner, plano e situação de cada ambiente.'],
                    ['Entenda a separação', 'A plataforma resume toda a operação. Cada tenant é uma diretoria com seus contratos e vínculos; empresas são participantes dos contratos.'],
                    ['Consulte Uso APS', 'Acompanhe consumo por tenant e módulo em gigabytes para identificar concentração de armazenamento.'],
                    ['Revise antes de alterar', 'Suspensão, plano e owner afetam o acesso à diretoria e devem ser modificados somente após conferência.'],
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
                    ['Filtre o contrato', 'Selecione um contrato ou consulte os contratos autorizados na diretoria atual. Os cards respeitam suas permissões.'],
                    ['Acompanhe as atividades', 'Consulte o ritmo de atividades, as vencidas e as próximas do prazo.'],
                    ['Consulte os módulos', 'Veja os cards de projetos, RNC, IC, RDO, Ordem de Serviço, medição e demais módulos disponíveis no seu acesso.'],
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
                    ['Crie o contrato', 'Em Novo contrato, informe identificação, moeda, vigência e localização. O valor é opcional e pode ser editado depois; confira os demais campos solicitados no formulário.'],
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
                    ['Novo orçamento', 'Informe os dados gerais; depois configure arredondamento, encargos e BDI; por fim selecione as bases por banco, UF e referência. Com o orçamento criado, adicione etapas, insumos e composições e configure os acessos.'],
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
                'ordem-servico', 'Acompanhamento', 'Liberação de Serviço', 'ClipboardCheck',
                'Liberação de Serviço', 'service_orders', 'tenant.ordem-servico.os.index',
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
                'diario-obra', 'Campo', 'Diário de Obra: RDO', 'HardHat',
                'Diário de Obra', 'field', 'tenant.diario-obra.rdo.calendar',
                'Preencha o RDO na web ou no aplicativo, revise e assine o documento.',
                'Equipe de campo, construtora, gerenciadora e cliente',
                ['Contrato, obra e frente configurados.', 'Responsáveis e catálogos cadastrados.'],
                [
                    ['Escolha o modelo', 'Parametrize o contrato com Construtora + Gerenciadora + Cliente, Construtora + Gerenciadora, Gerenciadora + Cliente ou somente Gerenciadora. O documento preserva o modelo recebido na criação.'],
                    ['Prepare o contrato', 'Vincule as obras, os responsáveis e a empresa do relatório. Os cadastros de mão de obra, equipamentos e subcontratadas pertencem ao contrato. Configure período, dias, cópia de dados, logos e PDF.'],
                    ['Preencha as obras', 'A construtora preenche quando participa do modelo; nos modelos sem construtora, a gerenciadora preenche. Registre clima, atividades, recursos, comentários e fotos opcionais. O feriado é informado manualmente.'],
                    ['Encaminhe o RDO', 'Preencha todas as obras e confirme o encaminhamento conjunto. No aplicativo, sincronize os registros antes de enviar. No modelo somente Gerenciadora, salve sem aprovação.'],
                    ['Analise e decida', 'Quando analisa a construtora, a gerenciadora registra atividades, clima, comentário e evidências por obra. A decisão final cabe ao cliente quando ele participa, ou à gerenciadora no modelo de duas partes. Devoluções retornam o RDO completo; ressalvas são indicadas por obra.'],
                    ['Assine e exporte', 'Conforme o fluxo, envie o documento aos signatários configurados. Em Baixar PDF, escolha etapa e obras; o completo assinado preserva o arquivo final.'],
                    ['Acompanhe os registros', 'Alterne calendário ou lista. Laranja identifica construtora, azul gerenciadora, verde cliente e roxo assinatura. Confira também as ressalvas e os prazos.'],
                ],
                ['O cliente comenta e decide; não preenche clima, recursos e atividades.', 'Fotos são opcionais e sua ausência pode ser tratada por ressalva.', 'Todas as obras avançam ou retornam juntas.', 'O RDA foi descontinuado; novos registros são feitos no RDO.'],
                'O diário terá registros de campo completos, aprovações e assinatura rastreáveis.',
                ['contratos', 'usuarios-permissoes'],
                ['diário de obra', 'rdo', 'offline', 'assinatura digital', 'mão de obra', 'equipamento']
            ),
            self::guide(
                'qualidade-rnc', 'Campo', 'Qualidade e RNC', 'ShieldCheck',
                'Qualidade > RNC', 'quality', 'tenant.qualidade.rnc.index',
                'Da abertura à assinatura: registre, comunique, corrija e acompanhe a não conformidade.',
                'Qualidade, responsável operacional, construtora e acompanhamento',
                ['Contrato e obra vinculados.', 'Responsáveis da RNC configurados.'],
                [
                    ['Abra a RNC', 'Selecione contrato e obra, informe assunto, disciplina, gravidade e descrição. Preencha os campos do modelo parametrizado no contrato e vincule projetos e uma IC comunicada da mesma obra quando necessário.'],
                    ['Comunique os envolvidos', 'O responsável autorizado notifica as partes. Consulte a instrução, as fotos e as informações de abertura antes de propor a correção.'],
                    ['Proponha a correção', 'A construtora descreve a proposta, informa o prazo de execução e envia o anexo ZIP solicitado no formulário.'],
                    ['Analise a proposta', 'O responsável de acompanhamento aprova ou devolve a proposta com os comentários necessários. A construtora corrige e reenvia quando houver devolução.'],
                    ['Evidencie a correção', 'Depois da aprovação da proposta, registre o resultado: resolvido, parcialmente ou não resolvido. Justifique os dois últimos resultados e envie imagens ou arquivo conforme o formulário. Não é obrigatório enviar ZIP e imagem juntos.'],
                    ['Solicite as assinaturas', 'Selecione os responsáveis pelas partes configuradas e envie o documento final para assinatura. Evidenciar não finaliza automaticamente a RNC.'],
                    ['Consulte o documento final', 'A conclusão depende das assinaturas e do PDF final disponível. Baixe PDFs por etapa e acompanhe os resultados no dashboard, com filtros por contrato e demais campos disponíveis.'],
                ],
                ['Modelos e logos são parametrizados por contrato e preservados nas RNCs já criadas.', 'O mapa de consulta pode ser ampliado ou minimizado e não entra no PDF.', 'Campos de seleção do modelo podem aparecer nos filtros do dashboard do contrato.'],
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
                'assistente-deming', 'Ajuda', 'Assistente SigWorks e tutoriais', 'Bot',
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
            self::guide(
                'os-dnit', 'Acompanhamento', 'Ordem de Serviço', 'ClipboardList',
                'Ordem de Serviço', 'os_dnit', 'tenant.os-dnit.orders.index',
                'Da demanda ao serviço concluído: conheça o fluxo e a função de cada responsável.',
                'Analista DNIT, supervisão e fiscal do contrato',
                ['Diretoria e contrato selecionados.', 'Acesso ao módulo e responsáveis cadastrados no contrato.'],
                [
                    ['Demanda', 'O analista descreve a necessidade, identifica o trecho e informa o prazo desejado.'],
                    ['Análise Supervisão', 'A supervisão define produtos, quantidades, análise e prazo estimado.'],
                    ['Decisão do fiscal', 'O fiscal aprova ou devolve com justificativa à supervisão ou ao analista.'],
                    ['Aprovada', 'A OS aguarda a conclusão da execução do serviço.'],
                    ['Finalizada', 'Após a conclusão do serviço, o fiscal finaliza a OS.'],
                ],
                ['Os produtos são definidos pela supervisão.', 'Aprovação e finalização são etapas distintas.'],
                'A equipe identifica o responsável de cada etapa e o caminho correto das devoluções.',
                ['contratos', 'usuarios-permissoes'],
                ['OS DNIT', 'ordem de serviço', 'demanda', 'supervisão', 'fiscal'],
                assistant: false,
            ),
            self::guide(
                'qualidade-ic', 'Campo', 'Instrução de Campo — IC', 'ClipboardCheck',
                'Qualidade > IC', 'quality', 'tenant.qualidade.ic.index',
                'Instrua, comunique e registre o resultado da correção.',
                'Supervisão, construtora e cliente',
                ['Contrato e obra cadastrados.', 'Responsáveis da IC configurados.'],
                [
                    ['Registre a instrução', 'Informe contrato, obra, assunto, disciplina, gravidade e a instrução. Inclua o registro fotográfico com comentários. Não há campo de projeto, latitude ou longitude na criação da IC.'],
                    ['Comunique os envolvidos', 'Envie a instrução aos responsáveis configurados para que a equipe conheça o problema e providencie a correção.'],
                    ['Registre as evidências', 'A supervisão registra as evidências e responde se o problema foi resolvido: Sim, Parcialmente ou Não. Imagens recebem prévia e comentários; documentos são apresentados como anexos.'],
                    ['Finalize a IC', 'A supervisão finaliza a instrução. O resultado aparece ao lado do número na listagem e na distribuição do dashboard.'],
                    ['Referencie em uma RNC', 'Se for necessário abrir uma RNC, selecione a IC já comunicada da mesma obra. Isso preserva o registro da comunicação anterior.'],
                ],
                ['A IC não tem proposta de ação corretiva nem análise dessa proposta.', 'A supervisão registra as evidências e finaliza.', 'O dashboard permite filtrar obra, disciplina, gravidade e período.'],
                'A comunicação e o resultado da correção ficam registrados e podem fundamentar uma RNC.',
                ['qualidade-rnc', 'contratos'],
                ['IC', 'instrução de campo', 'instrução', 'resolução'],
                assistant: false,
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
            'service_orders' => 'Liberação de Serviço',
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
                'workflow' => $guide['capability'] === 'service_orders' ? str_replace('Ordem de Serviço', 'Liberação de Serviço', self::assistantText($guide)) : self::assistantText($guide),
                'tutorial' => $guide['capability'] === 'service_orders' ? str_replace('Ordem de Serviço', 'Liberação de Serviço', self::assistantText($guide)) : self::assistantText($guide),
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
                self::shot('assistente-atual', 'Assistente SigWorks', 'O chat usa somente dados autorizados e pode preparar rascunhos para revisão.'),
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
