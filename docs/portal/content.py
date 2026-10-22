"""Texto do manual. Não colocar valores de .env ou credenciais aqui."""
from html import escape

def p(text): return '<p>'+text+'</p>'
def ul(*items): return '<ul>'+''.join('<li>'+x+'</li>' for x in items)+'</ul>'
def steps(*items): return '<ol class="steps">'+''.join('<li>'+x+'</li>' for x in items)+'</ol>'
def table(headers, rows):
    return '<div class="table-wrap"><table><thead><tr>'+''.join('<th>'+escape(x)+'</th>' for x in headers)+'</tr></thead><tbody>'+''.join('<tr>'+''.join('<td>'+str(x)+'</td>' for x in row)+'</tr>' for row in rows)+'</tbody></table></div>'
def code(text): return '<pre><code>'+escape(text)+'</code></pre>'
def note(text): return '<aside class="note">'+text+'</aside>'
def fig(name, caption): return f'<figure><button class="zoom-image" data-image="assets/{name}" aria-label="Ampliar imagem: {escape(caption,quote=True)}"><img src="assets/{name}" alt="{escape(caption,quote=True)}" loading="lazy"></button><figcaption>{caption}</figcaption></figure>'
def article(id, title, summary, body, sources, tech=''):
    return dict(id=id,title=title,summary=summary,body=body,sources=sources,technical=tech)
def chapter(id, group, title, intro, articles):
    return dict(id=id,group=group,title=title,intro=intro,articles=articles)

CHAPTERS = [
chapter('inicio','Introdução','Visão geral','O que é o SigWorks e como este manual está organizado.',[
article('o-que-e','O que é o SigWorks','Sistema web de gestão de contratos de obra.',
p('O SigWorks é o sistema web que usamos para gerir contratos de obra. Cada empresa tem o seu ambiente, e dentro dele a equipe trabalha nos contratos em que está cadastrada. Tarefas, orçamentos, medição, diário de obra, qualidade, documentos e projetos ficam no mesmo lugar.')+
fig('visao-geral-atual.png','Tela inicial. A captura é de uma versão anterior; hoje o menu também tem OS DNIT.')+
table(['Para…','Use o módulo'],[
['Ver os dados do contrato e a equipe','Contratos'],['Distribuir e acompanhar tarefas','Atividades'],['Montar custos e quantitativos','Orçamentos e Medição'],['Liberar itens para execução e medição','Liberação de Serviço'],['Pedir serviços e produtos no fluxo do DNIT','OS DNIT'],['Registrar o dia de obra','Diário de Obra (RDA e RDO)'],['Tratar uma não conformidade','Qualidade (RNC)'],['Guardar arquivos e controlar revisões','Documentação e Projetos']]),
['README.md','routes/web.php','app/Support/TutorialCatalog.php']),
article('como-ler','Como usar este manual','Sumário, busca, imagens e impressão.',
ul('O sumário fica à esquerda. No celular, toque em <strong>Sumário</strong> no topo da tela.','A busca procura em todo o texto, inclusive nas partes técnicas. Atalho: Ctrl+K.','Clique numa imagem para ampliar. Algumas capturas são de versões anteriores e mostram nomes antigos de menu.','<strong>Imprimir</strong> gera o manual completo (dá para salvar em PDF). O arquivo <code>manual.md</code> tem o mesmo conteúdo em texto.')+
p('Esta revisão é de 21/10/2026. Se algo na tela estiver diferente do que está escrito aqui, vale o sistema, e o manual precisa ser atualizado (ver <a href="#operacao/manter-manual">Atualizar este manual</a>).'),
['docs/portal/README.md']),
article('jornadas','Por onde começar','Sugestão de leitura por perfil.',
table(['Perfil','Leia primeiro','Depois'],[['Usuário','Empresas e contratos, depois o capítulo do seu módulo','Notificações; Assistente'],['Administrador da empresa','Usuários e permissões; Cadastros básicos','Credenciais; Backup e suporte'],['Desenvolvimento e manutenção','Arquitetura; Instalação local','GitHub e CI/CD; Ambientes; Serviços externos']])+
p('Um exemplo para fixar a ideia: a empresa tem os contratos CT-001 e CT-002. Quem está vinculado só ao CT-001 não vê nada do CT-002, nem trocando um filtro nem digitando o endereço na mão.'),
['app/Http/Middleware/ResolveTenant.php','app/Support/OsDnitContractAccess.php'])]),

chapter('tecnologias','Introdução','Tecnologias','O que roda por trás do sistema e onde fica cada parte do código.',[
article('stack','Ferramentas e bibliotecas','Laravel, React, PostgreSQL e bibliotecas de apoio.',
'<div class="tech-grid">'+''.join(f'<div class="tech-card"><img src="assets/logos/{slug}.svg" alt="{name}"><strong>{name}</strong><span>{role}</span></div>' for slug,name,role in [
('laravel','Laravel 13','Backend'),('php','PHP 8.4+','Linguagem do servidor'),('react','React 19','Telas'),('tailwindcss','Tailwind CSS','Estilos'),('vite','Vite','Build do frontend'),('postgresql','PostgreSQL','Banco de dados'),('docker','Docker','Imagem dos deploys'),('github','GitHub','Repositório e CI')])+'</div>'+
p('O Laravel cuida de rotas, validação, permissões e gravação dos dados. As telas são em React, e o Inertia 2 faz a ponte entre os dois sem precisar de uma API à parte. Além disso, o projeto usa estas bibliotecas:')+
table(['Biblioteca','Para que serve'],[
['Dompdf, FPDF, FPDI','Geração e montagem de PDFs.'],
['PhpSpreadsheet','Importação e exportação de planilhas.'],
['ECharts, FullCalendar, Mapbox GL','Gráficos, calendário e mapas.'],
['OCRmyPDF, Tesseract, Poppler','OCR e tratamento de PDF no servidor.']]),
['composer.json','package.json','Dockerfile'],
p('As versões da tabela são as mínimas declaradas em <code>composer.json</code> e <code>package.json</code>. As versões instaladas de fato estão em <code>composer.lock</code> e <code>package-lock.json</code>.')),
article('pastas','Estrutura do repositório','Onde procurar regras, telas e configurações.',
table(['Pasta / arquivo','O que tem lá'],[['app/Http/Controllers','Ações das telas, validações e respostas.'],['app/Models','Models e relacionamentos.'],['app/Support','Regras compartilhadas, como as de permissão.'],['app/Services','Integrações, assistente, PDF e processamento.'],['app/Jobs, app/Notifications','Jobs em fila e notificações.'],['resources/js','Telas e componentes React.'],['resources/views','Templates de PDF e e-mail.'],['routes','Rotas web, API e agendamentos.'],['config','Configurações e nomes das variáveis de ambiente.'],['database/migrations','Migrations.'],['tests','Testes automatizados.'],['.github, railway, azure, infra','CI/CD, scripts de inicialização e infraestrutura.'],['docs/portal','Fonte deste manual.']]),
['composer.json','routes/web.php','routes/api.php','bootstrap/app.php'])]),

chapter('contratos','Administração','Empresas e contratos','Como o sistema separa os dados de cada empresa e de cada contrato.',[
article('tenant','O que é um tenant','O ambiente de uma empresa dentro do SigWorks.',
p('Tenant é o ambiente de uma empresa dentro do SigWorks. A conta do usuário é uma só, mas ela só enxerga as empresas e os contratos aos quais está vinculada.')+
fig('tenant.svg','Conta, empresa e contratos.')+
p('Os endereços das telas seguem o formato <code>/t/{tenant}/...</code>. O trecho depois de <code>/t/</code> é o identificador da empresa. Em cada requisição o sistema identifica o tenant, confere se o usuário tem acesso a ele e aplica as regras da tela. Se o tenant estiver suspenso, ninguém da empresa consegue entrar.'),
['app/Http/Middleware/ResolveTenant.php','app/Http/Middleware/EnsureTenantAccess.php','app/Models/User.php'],
p('Todas as empresas usam o mesmo banco. A separação é feita pelas colunas <code>tenant_id</code> e <code>contract_id</code> nas consultas e nas checagens de permissão. O middleware ResolveTenant define <code>app.current_tenant</code> na sessão do PostgreSQL, mas as migrations não criam políticas RLS, então essa variável sozinha não isola nada. Quem garante a separação é o código da aplicação.')),
article('contrato-cadastro','Cadastrar um contrato','Vigência, empresas, estrutura e participantes.',
steps('Em Contratos, clique em <strong>Novo contrato</strong> e preencha identificação, valor, vigência e documento base.','Vincule empresas, obras, trechos e disciplinas ao contrato e salve.','Escolha a modalidade de medição (Simples ou Controlada) antes de começar a operar.','Adicione os usuários e configure as permissões de cada módulo.','Mudanças de valor ou prazo entram como aditivo, para manter o histórico.')+
fig('contratos-atual.png','Lista de contratos (dados de teste).')+
p('Obra, contrato e tenant são coisas diferentes: a obra é a estrutura física, o contrato é o vínculo comercial e o tenant é a empresa no sistema.'),
['app/Http/Controllers/Tenant/ContractController.php','app/Http/Controllers/Tenant/ContractAdditiveController.php','app/Models/Contract.php']),
article('contrato-vinculos','Vínculo e permissão','Estar no contrato não basta para fazer tudo nele.',
p('Estar vinculado a um contrato não basta para fazer tudo nele. Cada ação passa por várias checagens:')+
table(['Checagem','Exemplo'],[['Empresa','A conta tem vínculo ativo com o tenant.'],['Contrato','O usuário participa do CT-001.'],['Permissão do módulo','Pode ver as OS DNIT, mas não pode excluir.'],['Papel no fluxo','É Fiscal Concremat nesse contrato.'],['Situação do registro','A OS está na etapa em que a análise é permitida.'],['Regra específica','Só o autor edita a solicitação DNIT, e só enquanto ela é um rascunho nunca enviado.']])+
p('As exceções para administradores mudam de módulo para módulo. No OS DNIT, por exemplo, até o administrador precisa estar vinculado ao contrato. Na dúvida, veja a regra no capítulo do módulo.'),
['app/Models/ContractParticipant.php','app/Support/OsDnitPermissions.php','app/Support/OsDnitWorkflowAccess.php','app/Support/ActivityPermissions.php'])]),

chapter('acessos','Administração','Usuários e permissões','Cadastro de usuários e configuração do que cada um pode fazer.',[
article('contas','Cadastrar usuários','Conta, vínculo com a empresa e com os contratos.',
steps('Um administrador da empresa cadastra a conta ou associa uma conta já existente ao tenant.','Preencha os dados do vínculo, a situação e o perfil.','Vincule o usuário aos contratos em que ele vai trabalhar.','Em <strong>Permissões</strong>, escolha o usuário e o contrato e marque os módulos.','Se ele for responsável em algum fluxo (fiscal, aprovador etc.), cadastre isso também.')+
p('Os perfis disponíveis são proprietário, administrador, gestão, coordenação, engenharia e apoio. O perfil é só um ponto de partida; o que vale é a permissão configurada. No primeiro acesso o usuário precisa trocar a senha pelo navegador antes de usar o app.')+
fig('usuarios-atual.png','Cadastro de usuários.'),
['app/Http/Controllers/Tenant/UserController.php','app/Support/TenantRoles.php','app/Http/Middleware/EnsurePasswordWasChanged.php']),
article('permissoes','Configurar permissões','Visualizar, editar e decidir são permissões separadas.',
steps('Em Permissões, escolha o usuário.','Escolha o contrato, quando o grupo de permissões for por contrato.','Abra o módulo e marque as ações.','Salve e confira com o próprio usuário se ficou como esperado.')+
p('Há permissões da empresa, do contrato e, em alguns casos, de um registro específico. Orçamentos têm controle de acesso próprio, atividades podem ser públicas ou restritas e documentos podem ter acesso individual. A tela esconde os botões que o usuário não pode usar, e o servidor repete a checagem caso a ação chegue por outro caminho.')+
fig('permissoes-atual.png','Tela de permissões. Na versão atual há também o grupo OS DNIT, com 13 opções.'),
['app/Http/Controllers/Tenant/PermissionController.php','app/Support/BudgetPermissions.php','app/Support/DocumentationPermissions.php']),
article('osdnit-permissoes','Permissões do OS DNIT','As 13 opções do grupo e como elas se combinam.',
table(['Grupo','Permissões'],[['Ordens','Visualizar, criar, editar e excluir OS.'],['Catálogo','Visualizar, criar, editar e excluir produtos.'],['Indicadores','Visualizar dashboard.'],['Parametrização','Criar, editar e excluir.'],['Responsáveis','Gerenciar responsáveis.']])+
p('As permissões ficam gravadas no vínculo do usuário com o contrato. Marcar qualquer ação do catálogo já inclui a visualização do catálogo, e as demais incluem Visualizar OS. Ao desmarcar Visualizar OS, o grupo inteiro é limpo. O menu OS DNIT só aparece para quem tem Visualizar OS. O proprietário tem acesso total, mas só nos contratos de que participa.')+
note('<strong>Atenção:</strong> se o usuário nunca teve as permissões do OS DNIT configuradas (valor <code>null</code>), vale o padrão do perfil, para não quebrar quem já usava o sistema. Para tirar o acesso, salve o grupo vazio na tela de permissões (valor <code>[]</code>).'),
['app/Support/OsDnitPermissions.php','app/Http/Middleware/EnsureOsDnitPermission.php','database/migrations/2026_10_21_180000_add_os_dnit_permissions_and_soft_deletes.php'])]),

chapter('parametrizacao','Administração','Cadastros básicos e plataforma','O que precisa estar cadastrado antes de começar a operar.',[
article('cadastros','Empresas, obras, trechos e disciplinas','Cadastros usados por vários módulos.',
steps('Cadastre as empresas com o tipo: Gerenciadora, Construtora ou Cliente.','Cadastre as obras. Se houver obra principal e subobras, faça a ligação entre elas.','Cadastre trechos e disciplinas no formato pedido pela tela.','Vincule esses cadastros ao contrato e salve.','Complete os cadastros de cada módulo, como rodovias no OS DNIT e equipamentos no RDO.')+
fig('parametrizacao-atual.png','Parametrização. Os cadastros só aparecem nos módulos depois de vinculados ao contrato.')+
p('Obras usam código de três dígitos e disciplinas usam sigla de três letras. Em projetos sem divisão por trecho, use o trecho GER (Geral).'),
['app/Http/Controllers/Tenant/Parametrizacao/ObraController.php','app/Http/Controllers/Tenant/Parametrizacao/DisciplinaController.php','app/Http/Controllers/Tenant/ContractController.php']),
article('plataforma','Administração da plataforma','Área global, acima das empresas.',
p('O administrador da plataforma enxerga todas as empresas. Nessa área ele cadastra e suspende tenants, acompanha indicadores gerais e o consumo do Autodesk APS e define os limites de uso do assistente. Isso não dá acesso livre aos contratos: as regras de cada módulo continuam valendo.')+
fig('admin-plataforma-atual.png','Administração da plataforma.')+
p('Suspender um tenant bloqueia o acesso da empresa inteira. Antes de mudar plano, proprietário ou limites, confira se está no tenant certo.'),
['app/Http/Controllers/Platform/TenantController.php','app/Http/Controllers/Platform/ApsUsageController.php','app/Http/Middleware/EnsurePlatformAdmin.php'])]),

chapter('atividades','Módulos','Atividades e checklists','Distribuição e acompanhamento das tarefas da equipe.',[
article('atividades-uso','Criar uma atividade','Quadro com A fazer, Em andamento, Em revisão e Concluídas.',
p('O quadro tem quatro colunas: A fazer, Em andamento, Em revisão e Concluídas.')+
steps('Clique em <strong>Nova atividade</strong> e escolha Atividade ou Checklist.','Preencha título, contrato, categoria, prioridade, prazo e descrição.','Escolha os responsáveis e a visibilidade.','No checklist, adicione as etapas.','Salve. Pelo card dá para comentar, anexar arquivos, marcar etapas e mudar a coluna.')+
fig('atividades-atual.png','Quadro de atividades.')+
p('Atividade pública aparece para todos que têm acesso ao contrato; restrita, só para quem criou e para os responsáveis. A aba Métricas tem permissão própria. Quem criou a atividade pode editá-la, mas isso não dá permissão para mexer nas atividades dos outros.'),
['app/Http/Controllers/Tenant/ActivityController.php','app/Models/Activity.php','app/Support/ActivityPermissions.php']),
article('atividade-os','Vincular uma atividade a uma OS DNIT','Categoria Ordem de Serviço DNIT.',
steps('Escolha a categoria <strong>Ordem de Serviço DNIT</strong>.','No campo que aparece, busque a OS pelo número ou pelo assunto.','Selecione uma OS do mesmo contrato.','Salve. O detalhe da atividade passa a ter um link para a OS.')+
p('Nessa categoria o vínculo é obrigatório. Se você trocar o contrato, a OS selecionada é limpa; se trocar a categoria, o vínculo é removido ao salvar. A busca mostra até 20 resultados, então refine o termo se a OS não aparecer. A categoria Liberação de Serviço é outra e não usa esse campo.')+
p('A atividade só aponta para a OS. Ela não cria, não encaminha e não aprova nada no OS DNIT.'),
['resources/js/Components/ActivityOsDnitSelect.jsx','app/Http/Controllers/Tenant/ActivityController.php','database/migrations/2026_10_21_190000_link_activities_to_os_dnit_orders.php'])]),

chapter('orcamentos','Módulos','Orçamentos','Montagem de orçamentos a partir de insumos e composições.',[
article('orcamento-fluxo','Montar um orçamento','Bases, parâmetros, etapas e finalização.',
steps('Consulte ou importe os insumos e composições (SINAPI, SICRO ou base própria).','Confira UF, competência, coeficientes, produção e regras de custo.','Crie o orçamento e defina bases, encargos, arredondamento e BDI.','Monte as etapas e adicione composições ou insumos com os quantitativos.','Revise os totais, corrija os preços zerados e gere os relatórios.','Finalize. Só orçamento finalizado pode ser usado em Itens de Contrato.')+
fig('orcamentos-atual.png','Tela de orçamento.')+
p('As bases SINAPI e SICRO são importadas para o sistema; não há consulta em tempo real às bases oficiais. Confira a competência e a UF da base antes de usar.'),
['app/Http/Controllers/Tenant/OrcamentoController.php','app/Support/BudgetPermissions.php','composer.json']),
article('orcamento-acesso','Acesso e alterações','Controle de acesso por orçamento e importação de planilhas.',
p('Cada orçamento tem controle de acesso próprio. Quem criou e os administradores podem abrir <strong>Acessos</strong> e liberar visualização ou edição para outras pessoas, independentemente da permissão geral do módulo. Se for alterar um orçamento já usado como referência, faça uma cópia antes.')+
p('A planilha importada precisa seguir o modelo do importador. Mesmo quando a importação funciona, revise valores, casas decimais, unidades, base e competência. Finalizar é o que libera o orçamento para Itens de Contrato, então só finalize depois da revisão.'),
['app/Support/BudgetPermissions.php','app/Http/Controllers/Tenant/OrcamentoController.php'])]),

chapter('liberacao','Módulos','Liberação de Serviço','O antigo módulo de Ordem de Serviço, ligado aos itens do contrato e à medição.',[
article('liberacao-fluxo','Criar e liberar uma OS','Do rascunho à aprovação.',
steps('Prepare os itens de contrato, os responsáveis e os prazos.','Em Liberação de Serviço, crie a OS com escopo, obra, prazos e itens autorizados.','Revise o rascunho e envie para análise.','Os fiscais analisam e os aprovadores concluem, conforme a parametrização do contrato.','Registre a execução e acompanhe documentos, histórico e métricas.')+
fig('ordem-servico-atual.png','Liberação de Serviço (captura de quando o módulo ainda se chamava Ordem de Serviço).')+
p('Na medição controlada, os itens liberados por OS aprovada entram no pleito. O módulo também mostra custo previsto, custo medido e percentual executado.'),
['app/Http/Controllers/Tenant/OrdemServicoController.php','app/Http/Controllers/Tenant/OrdemServicoMetricsController.php','app/Support/OrdemServicoPermissions.php']),
article('diferenca-os','Liberação de Serviço e OS DNIT','São dois módulos diferentes.',
p('São módulos separados, cada um com seus cadastros, fluxo e permissões.')+
table(['','Liberação de Serviço','OS DNIT'],[['Para quê','Itens do contrato, execução e medição controlada','Pedido e análise de serviços e produtos do DNIT'],['Endereço','/ordem-servico/...','/os-dnit/...'],['Papéis','Fiscais e aprovadores do contrato','Analista DNIT, Fiscal Concremat e Fiscal do contrato'],['Devolução','Volta para rascunho','O pedido original continua travado'],['Código','OrdemServico, OrdemServicoPermissions','OsDnitOrder, OsDnitPermissions']])+
p('As regras de edição e de permissão de um módulo não valem para o outro.'),
['app/Http/Controllers/Tenant/OrdemServicoController.php','app/Support/OsDnitWorkflowAccess.php','routes/web.php'])]),

chapter('os-dnit','Módulos','OS DNIT','Pedido de serviços e produtos ao DNIT, com análise da Concremat e decisão do fiscal do contrato.',[
article('dnit-preparar','Criar uma OS','Cadastros necessários, rascunho e envio.',
p('Para conhecer o fluxo na prática, clique em <strong>Iniciar tour</strong> nas telas Ordem de Serviço, Responsáveis, Produtos ou Dashboard. São 12 passos, na ordem: responsáveis, produtos, criação e envio, análise da Concremat, decisão do fiscal e dashboard. O tour usa dados fictícios, então nada é cadastrado e nenhum e-mail é enviado.')+
steps('No contrato, cadastre os produtos e as rodovias e defina os responsáveis.','Em Ordem de Serviço, clique em <strong>Criar OS</strong>.','Preencha lote, rodovia, km inicial e final, assunto, prazo pretendido, necessidade técnica, justificativa e número SEI/processo. Anexe os documentos.','Busque os produtos do contrato e informe as quantidades.','Salve o rascunho. O número (sequencial/ano, por contrato) é gerado automaticamente.','Revise e clique em <strong>Enviar para análise</strong>. A OS vai para o Fiscal Concremat.')+
fig('os-dnit.svg','Fluxo da OS DNIT.')+
p('O autor só pode editar a OS enquanto ela for um rascunho que nunca foi enviado. Depois do primeiro envio, mesmo que volte devolvida, a solicitação original fica travada. Excluir segue a mesma regra, e a exclusão é lógica: o registro, os anexos e o número continuam guardados.'),
['app/Http/Controllers/Tenant/OsDnitOrderController.php','app/Support/OsDnitWorkflowAccess.php','app/Services/OsDnitOrderNumber.php']),
article('dnit-analisar','Analisar, aprovar ou devolver','Análise da Concremat e decisão do fiscal do contrato.',
steps('Na lista, o responsável clica em <strong>Analisar OS</strong>. A análise abre numa janela sobre a própria lista.','O Fiscal Concremat preenche o comentário (obrigatório), o prazo estimado e os produtos e quantidades. Ele pode só salvar ou já encaminhar ao Fiscal do contrato.','O Fiscal do contrato vê o pedido do analista ao lado da análise da Concremat, com as diferenças de produtos e quantidades destacadas.','Para aprovar ou devolver, escreva o comentário e confirme. Na devolução, informe também o motivo e para quem volta.','Devolva à Concremat quando o problema for produto, quantidade ou prazo da análise, e ao Analista DNIT quando for no pedido.')+
p('Toda mudança de etapa fica no histórico e gera aviso para os responsáveis. O e-mail entrar na fila não quer dizer que foi entregue ou lido. A aprovação encerra o fluxo da OS; ela não confirma que o serviço foi executado.'),
['app/Http/Controllers/Tenant/OsDnitWorkflowController.php','app/Services/OsDnitOrderHistory.php','app/Services/OsDnitFlowRecipients.php','app/Services/OsDnitFlowNotifier.php']),
article('dnit-cadastros','Produtos, rodovias e responsáveis','Cadastros do OS DNIT, separados por contrato.',
table(['Cadastro','Como funciona'],[['Produtos','Código, nome, categoria, unidade e frequência (Mensal ou Por demanda). Lista com filtros, 15 por página.'],['Categorias','Gestão e controle; Supervisão de obras; Controle tecnológico; Controle executivo; Levantamentos e estudos; Sondagens; Projetos e inspeções; Ambiental.'],['Rodovias','Texto livre, por exemplo BR-364/AC. Podem ser editadas e desativadas.'],['Responsáveis','Analista DNIT, Fiscal Concremat e Fiscal do contrato, por contrato.']])+
p('Produtos e rodovias que já foram usados em alguma OS não podem ser excluídos. Alterar o catálogo não muda as OS já gravadas. As permissões de catálogo e de parametrização não têm relação com os papéis da análise.'),
['app/Http/Controllers/Tenant/OsDnitProductController.php','app/Http/Controllers/Tenant/OsDnitSettingsController.php','app/Http/Controllers/Tenant/OsDnitResponsibleController.php']),
article('dnit-consultar','Lista, PDF e dashboard','Filtros, opções de PDF e indicadores.',
p('A lista tem filtros e mostra 10 OS por página, separadas em Todas, Rascunho/Analista DNIT, Análise Concremat, Fiscal do contrato e Finalizadas. <strong>Acessar</strong> abre o detalhe com o quadro do fluxo.')+
table(['PDF','Conteúdo'],[['Solicitação do analista','Cabeçalho, identificação, descrição, justificativa e produtos pedidos.'],['Solicitação + Concremat','O anterior mais a análise da Concremat, em nova seção com cabeçalho.'],['Solicitação + análises','O anterior mais a decisão do fiscal.'],['Completo','Tudo isso e o histórico do fluxo no final.']])+
p('O PDF sai em A4. O dashboard fica no menu logo depois de Produtos e mostra total, rascunhos, em análise, com o fiscal, aprovadas e devolvidas. Os cartões contam a situação atual de cada OS; o gráfico mensal conta envios e aprovações. Salvar a análise sem encaminhar não zera o tempo de espera da etapa.'),
['app/Http/Controllers/Tenant/OsDnitDashboardController.php','app/Services/OsDnitPdfRenderer.php','resources/js/Pages/Tenant/OsDnit/Orders.jsx','docs/os-dnit.md'])]),

chapter('medicao','Módulos','Medição e Folhas de Rosto','Do item de contrato até o boletim de medição.',[
article('medicao-modalidades','Medição simples e controlada','A modalidade define de onde sai o pleito.',
table(['Modalidade','Como funciona'],[['Simples','O pleito é montado direto dos itens do contrato, sem OS de liberação.'],['Controlada','O pleito usa só itens liberados por OS aprovada no módulo Liberação de Serviço.']])+
steps('Importe os Itens de Contrato de um orçamento finalizado e confira quantitativos, valores e unidades.','Cadastre os índices de reajuste por competência, se houver.','Abra o boletim do contrato e crie as Folhas de Rosto.','Envie as FRs e acompanhe as etapas de fiscal, qualidade, medição e finalização.','Use o fluxo do BM e os relatórios para conferir autores, decisões, datas e acumulados.')+
fig('medicao-atual.png','Medição. A modalidade e os responsáveis do contrato definem o fluxo.'),
['app/Http/Controllers/Tenant/MedicaoController.php','app/Http/Controllers/Tenant/FolhaRostoController.php','app/Http/Controllers/Tenant/BoletimMedicaoController.php']),
article('medicao-limites','Conferência e relatórios','O que revisar antes de fechar a medição.',
p('Em algumas operações o sistema bloqueia a redução de quantidade abaixo do que já foi medido. Mesmo assim, confira período, preço, arredondamento e acumulado. Custo medido e quantidade autorizada são números diferentes e não devem ser comparados entre si.')+
p('Boletins, Relatórios de Medição e B.I. servem para conferência. Opções marcadas como "em breve" na tela ainda não funcionam. A aprovação da medição é independente das aprovações do OS DNIT e do RDO.'),
['app/Http/Controllers/Tenant/MedicaoRelatorioController.php','app/Http/Controllers/Tenant/MedicaoBiController.php','app/Support/MedicaoPermissions.php'])]),

chapter('diario','Módulos','Diário de obra (RDA e RDO)','O apontamento de campo e o relatório diário oficial.',[
article('rda-rdo','Preencher o diário','Configuração, apontamentos e geração do RDO.',
table(['Documento','O que é'],[['RDA','Apontamento de campo. Pode ser feito offline no app e sincronizado depois.'],['RDO','Relatório diário oficial, por data e por frente de obra.']])+
steps('Configure contrato, obras/frentes, responsáveis, datas e regras de geração.','Cadastre mão de obra, equipamentos e subcontratadas.','Preencha os apontamentos e publique os RDAs.','No calendário do RDO, gere ou copie o registro do dia.','Importe os RDAs publicados e confira clima, serviços, recursos, ocorrências e fotos.','Envie as frentes e acompanhe as etapas seguintes.')+
fig('diario-obra-atual.png','Calendário do diário de obra.'),
['app/Http/Controllers/Tenant/RdaController.php','app/Http/Controllers/Tenant/RdoController.php','app/Services/RdoDailyGenerator.php','routes/console.php']),
article('rdo-fluxo','Análise, ressalvas e comprovação','Construtora, gerenciadora e cliente.',
p('A construtora envia as frentes, a gerenciadora analisa e o cliente dá a decisão final. O sistema mostra quais frentes ainda estão pendentes em cada etapa. A gerenciadora também pode registrar fiscalização e divergências.')+
steps('A construtora envia as frentes para a gerenciadora.','A gerenciadora registra as decisões e, com todas as frentes analisadas, encaminha ao cliente.','Se houver devolução, o RDO volta para a construtora ajustar.','Se o cliente aceitar com ressalvas, pode ficar uma pendência de comprovação. A construtora resolve e envia a comprovação.','Sem ressalvas pendentes, o cliente conclui e o RDO é arquivado, com versões e histórico.')+
p('A geração do PDF e a assinatura dependem da situação e da configuração do RDO. Para reabertura, versões e prazos, siga o que a tela mostra.'),
['app/Http/Controllers/Tenant/RdoController.php','app/Models/RdoVersao.php','app/Models/RdoDivergencia.php','app/Services/RdoSignatureService.php'])]),

chapter('qualidade','Módulos','Qualidade (RNC)','Registro e tratamento de não conformidades.',[
article('rnc-fluxo','Abrir e encerrar uma RNC','Responsáveis, ação corretiva e evidências.',
steps('Defina o Responsável Operacional, o Responsável da Construtora e o Responsável de Acompanhamento.','Crie a RNC com contrato, obra, disciplina, gravidade, localização e descrição.','Vincule os projetos relacionados.','Clique em <strong>Notificar</strong> para formalizar a comunicação.','A construtora propõe a ação corretiva e o prazo; os responsáveis analisam e podem pedir ajustes.','Registre as evidências da execução, encerre a RNC e, se precisar, gere o PDF.')+
fig('qualidade-rnc-atual.png','Tela de RNC.')+
p('O mapa serve para marcar a localização (latitude e longitude). O PDF da RNC traz texto, fotos e histórico. A área IC ainda não está em uso.'),
['app/Http/Controllers/Tenant/Qualidade/RelatorioNaoConformidadeController.php','app/Http/Controllers/Tenant/Qualidade/RncAcaoCorretivaController.php','app/Http/Controllers/Tenant/Qualidade/RncEvidenciaController.php','app/Support/RncPermissions.php'])]),

chapter('documentacao','Módulos','Documentação (GED)','Envio, busca e controle de acesso de documentos.',[
article('ged-uso','Enviar e encontrar documentos','Metadados, busca, filtros e lixeira.',
steps('Em Documentação, clique em <strong>Enviar documento</strong> e preencha os dados, incluindo o contrato.','Classifique com tipo, etiquetas e correspondentes.','Anexe o arquivo e aguarde o processamento.','Use a busca e os filtros para encontrar o documento. Dali dá para ver a prévia, baixar, anotar e consultar histórico e acessos.','Documento excluído vai para a Lixeira e pode ser recuperado por quem tem permissão.')+
fig('documentacao-atual.png','GED: busca, filtros, triagem, parametrização e lixeira.')+
p('Ter acesso à empresa não dá acesso a todos os documentos: contam a permissão do módulo, o contrato e a visibilidade de cada documento. O OCR serve para pesquisar o conteúdo do arquivo e não valida o documento.'),
['app/Http/Controllers/Tenant/GedController.php','app/Support/DocumentationPermissions.php','app/Models/GedDocument.php']),
article('ged-email-ocr','Importação por e-mail e OCR','Leitura de caixas IMAP, triagem e fila de OCR.',
p('O GED pode ler caixas de e-mail por IMAP e importar os anexos. O envio de e-mails do sistema é outra coisa e passa pelo Brevo. Quando não dá para saber qual é o PDF principal da mensagem, ela vai para a triagem.')+
steps('Cadastre a conta IMAP e teste a conexão.','Defina as regras e o contrato de destino.','Resolva a triagem quando o sistema não souber qual é o documento principal.','Acompanhe a fila de OCR.','Antes de usar o texto extraído, confira com o arquivo original.')+
p('Não existe integração com SharePoint.'),
['app/Http/Controllers/Tenant/GedController.php','app/Models/GedEmailAccount.php','app/Services/GedOcrService.php','config/ged.php'],
p('A senha IMAP é gravada criptografada (cast <code>encrypted</code>) e não aparece na serialização do model; para ser lida, depende da APP_KEY. O OCR roda em fila, usando OCRmyPDF, Tesseract e Poppler, com limite de páginas e de tempo por arquivo.'))]),

chapter('projetos','Módulos','Projetos e Autodesk APS','Acervo de projetos e visualização de modelos 3D.',[
article('projetos-uso','Cadastrar e revisar projetos','Envio, revisões, aprovação e visualizador.',
steps('Confira se o contrato tem obras, trechos, disciplinas e responsáveis vinculados.','Envie o arquivo com os dados e a identificação no padrão da EAP.','Acompanhe versões, revisões e, quando for o caso, pacotes de submissão.','Os responsáveis analisam e aprovam ou reprovam.','Consulte Projetos Revisados, CAP, comparação e Lista Mestra.','Abra o visualizador quando a conversão do arquivo terminar.')+
fig('projetos-atual.png','Acervo de projetos.')+
p('O SigWorks guarda e organiza os arquivos de projeto, mas não edita modelos. A integração com o Autodesk APS envia o arquivo, pede a conversão e mostra o resultado no visualizador. Não há edição de geometria nem integração com ACC ou Forma.'),
['app/Http/Controllers/Tenant/ProjectController.php','app/Http/Controllers/Tenant/ProjectReviewController.php','app/Http/Controllers/Tenant/ProjectSubmissionBatchController.php','app/Services/AutodeskApsService.php']),
article('aps-processamento','Por que o modelo demora a abrir','Envio, conversão e visualizador são etapas separadas.',
fig('aps.svg','Do arquivo enviado até o visualizador.')+
p('O arquivo aparecer no cadastro não quer dizer que a conversão terminou. A fila APS envia o arquivo, a Autodesk converte e devolve um manifesto, e só então o visualizador consegue abrir. O status mostra em que ponto está: na fila, processando, concluído ou com falha.')+
p('Antes de reenviar, veja se o worker APS está rodando, se há jobs com falha e se a Autodesk ainda está processando. Reenviar sem necessidade duplica o trabalho e consome cota.'),
['app/Services/AutodeskApsService.php','app/Http/Controllers/Tenant/ProjectViewerController.php','railway/start-app.sh'])]),

chapter('assistente','Técnico','Assistente SigWorks','Como o assistente de IA busca as informações antes de responder.',[
article('rag-simples','Como o assistente responde','Busca nos dados permitidos e depois gera a resposta.',
p('O assistente usa RAG (geração com recuperação): antes de responder, ele busca no sistema as informações que o usuário tem permissão para ver e manda só esse trecho para o modelo de IA, junto com a pergunta.')+
fig('rag.svg','Etapas de uma resposta do assistente.')+
steps('O usuário pergunta no chat. O sistema identifica o tenant, o perfil e a tela em que ele está.','O sistema confere a cota e define quais contratos e módulos ele pode consultar.','A busca procura registros e guias de uso dentro desse escopo e ordena os resultados.','O backend monta o contexto, com as últimas mensagens da conversa, e chama a Responses API.','A resposta é gravada. Sugestões de navegação ou de formulário passam por uma nova validação.','Se a resposta trouxer um formulário preenchido, o usuário abre, revisa e salva na tela normal.'),
['app/Http/Controllers/Tenant/AssistantController.php','app/Services/Assistant/AssistantRetriever.php','app/Services/Assistant/AssistantContextBuilder.php','app/Services/Assistant/OpenAiAssistant.php']),
article('rag-real','Implementação atual','Busca por palavras-chave, sem índice vetorial.',
p('A busca combina consultas SQL, guias de uso e uma pontuação por palavras-chave, prioridade e tela atual. Não há embeddings nem índice vetorial. O modelo recebe só um recorte dos dados, nunca o banco inteiro ou todos os anexos.')+
table(['Limite','Padrão'],[['Fontes por pergunta','8'],['Texto por fonte','1.600 caracteres'],['Total de contexto','12.000 caracteres'],['Histórico','4 mensagens, com limite de tamanho por mensagem'],['Cota por usuário','60.000 tokens/mês'],['Cota por tenant','1.000.000 tokens/mês']])+
p('Todos esses limites podem ser alterados por variável de ambiente. O modelo usado vem de <code>OPENAI_CHAT_MODEL</code>.')+
note('Os dados encontrados na busca são enviados ao provedor de IA. Por isso a busca precisa sempre filtrar pelo acesso do usuário. A resposta pode vir errada ou incompleta; decisões formais continuam sendo tomadas nas telas do sistema.'),
['app/Services/Assistant/AssistantAccessScope.php','app/Services/Assistant/AssistantRetriever.php','app/Services/Assistant/AssistantQuotaService.php','config/services.php']),
article('rag-dnit','Assistente e OS DNIT','O que o assistente consegue fazer no OS DNIT.',
ul('Explica rascunho, análise da Concremat, decisão, devolução, PDF, produtos, rodovias, responsáveis e dashboard.','Sabe a diferença entre OS DNIT e Liberação de Serviço.','Conta as OS que o usuário pode ver e traz exemplos. No detalhe, mostra até três OS recentes ou a OS com o número informado.','Só consulta catálogo e responsáveis se o usuário tiver as permissões correspondentes.','Preenche parte do formulário de nova OS para quem é Analista DNIT e pode criar OS. Produtos e rodovias são buscados no contrato.','Preenche uma atividade na categoria OS DNIT, já com a OS vinculada.','Não salva, não envia, não aprova, não devolve, não exclui e não dispara e-mail.')+
p('Exemplos: "Como analisar uma OS DNIT?", "Como baixar só a solicitação do analista?", "Mostre a OS DNIT 001/2026 do contrato CT-001". Para preencher um formulário, informe o contrato e os dados, porque o mesmo número de OS pode existir em contratos diferentes.'),
['app/Services/Assistant/OsDnitAssistantContext.php','app/Services/Assistant/AssistantActionResolver.php','resources/js/Utils/assistantDraft.js','docs/os-dnit.md'])]),

chapter('integracoes','Técnico','Serviços externos','Integrações com outros serviços e as variáveis de cada uma.',[
article('integracoes-mapa','Visão geral','Quais serviços o sistema usa e para quê.',
fig('integracoes.svg','Serviços externos usados pelo backend. O Mapbox e o visualizador da Autodesk também rodam no navegador.')+
table(['Serviço','Uso','Variáveis'],[['Autodesk APS','Armazenamento, conversão e visualização de modelos','AUTODESK_APS_*'],['OpenAI','Respostas do assistente','OPENAI_API_KEY, OPENAI_CHAT_MODEL'],['Brevo','Envio de e-mails','MAIL_MAILER, BREVO_API_KEY'],['Mapbox','Mapas, como a localização da RNC','VITE_MAPBOX_ACCESS_TOKEN'],['OpenSign','Assinatura de PDFs, com retorno por webhook','SIGNATURE_DRIVER, OPENSIGN_*'],['IMAP','Leitura de caixas de e-mail no GED','Cadastrado na tela do GED'],['Azure Blob','Arquivos privados e temporários, quando esse disco é escolhido','AZURE_STORAGE_*']])+
p('Postmark, Resend, SES e Slack aparecem nos arquivos de configuração padrão do Laravel, mas não são usados. Cada integração só funciona no ambiente em que as variáveis estiverem preenchidas.'),
['config/services.php','config/mail.php','config/filesystems.php','config/signatures.php','app/Services/AutodeskApsService.php']),
article('api-autodesk','Autodesk APS','OAuth, armazenamento e Model Derivative.',
p('O servidor se autentica por OAuth com client ID e client secret, guarda os arquivos em buckets, pede a conversão ao Model Derivative e consulta o manifesto. Para o visualizador, gera um token só de leitura, que expira. O client secret nunca vai para o navegador.')+
p('Os arquivos enviados ficam armazenados na Autodesk. Se for ligar o processamento em outro ambiente, use bucket e região próprios.')+
p('Documentação: <a href="https://aps.autodesk.com/en/docs/oauth/v2">OAuth do APS</a>.'),
['app/Services/AutodeskApsService.php','config/services.php']),
article('api-email-mapas','Brevo e Mapbox','Envio de e-mail e mapas.',
p('O Brevo é chamado por HTTPS com uma chave de API (não é senha SMTP). Se a conta exigir lista de IPs autorizados, cadastre o IP de saída do servidor, não o do seu computador. Lembre que o e-mail passa por etapas diferentes: entra na fila, é aceito pelo Brevo, é entregue e, por fim, lido.')+
p('O token do Mapbox fica no frontend e é público, então deve ter só os escopos necessários. Nunca coloque segredos em variáveis <code>VITE_*</code>. Mudou uma variável <code>VITE_*</code>? É preciso refazer o build do frontend.')+
p('Documentação: <a href="https://developers.brevo.com/docs/api-key-authentication">Brevo</a>, <a href="https://docs.mapbox.com/help/getting-started/access-tokens/">Mapbox</a>.'),
['config/mail.php','package.json','resources/js/Pages/Tenant/Qualidade/RelatorioNaoConformidade/Index.jsx','config/services.php']),
article('api-assinaturas','Assinaturas (OpenSign)','Driver OpenSign e driver local.',
p('Com <code>SIGNATURE_DRIVER=opensign</code>, o servidor envia o PDF e os signatários para o OpenSign, guarda os identificadores e links de assinatura e recebe as atualizações por webhook. O driver <code>local</code> só simula o fluxo, para desenvolvimento; ele não gera assinatura de verdade.')+
note('<strong>Atenção:</strong> o webhook <code>POST /api/webhooks/opensign</code> só confere o segredo se <code>OPENSIGN_WEBHOOK_SECRET</code> estiver preenchida. Com a variável vazia, qualquer chamada é aceita. Em produção, configure o segredo antes de ligar o fluxo.')+
p('A validade jurídica da assinatura é uma questão da empresa; aqui só tratamos da parte técnica. Documentação: <a href="https://docs.opensignlabs.com/">OpenSign</a>.'),
['app/Services/Signatures/OpenSignSignatureProvider.php','app/Services/Signatures/LocalSignatureProvider.php','app/Http/Controllers/Api/OpenSignWebhookController.php','config/signatures.php']),
article('api-openai','OpenAI','Chamada à Responses API pelo servidor.',
p('O backend chama <code>/responses</code> por HTTPS, com a chave no cabeçalho Authorization e o modelo e os limites vindos da configuração. A <code>OPENAI_API_KEY</code> fica só no servidor. O modelo recebe só o contexto de cada pergunta; o SigWorks não treina nenhum modelo com os dados do sistema.')+
p('Preço, limites e retenção de dados são definidos pela OpenAI; as cotas do SigWorks são só internas. Documentação: <a href="https://developers.openai.com/api/reference/overview">OpenAI API</a>.'),
['app/Services/Assistant/OpenAiAssistant.php','app/Services/Assistant/AssistantQuotaService.php','config/services.php'])]),

chapter('arquitetura','Técnico','Arquitetura','Como uma ação percorre o sistema, do clique ao banco.',[
article('arquitetura-pedido','O que acontece ao clicar em Salvar','O caminho de uma requisição.',
fig('arquitetura.svg','Arquitetura: navegador, servidor, banco, arquivos, filas e serviços externos.')+
steps('A tela React envia os campos preenchidos, com a sessão do usuário.','A rota e os middlewares tratam autenticação, tenant e regras gerais.','O controller valida os dados, o contrato, a permissão e a situação do registro.','Models e services leem e gravam no banco. Operações críticas usam transação e lock.','Arquivos vão para o disco configurado, e o trabalho pesado vai para a fila.','A resposta atualiza a tela ou mostra os erros de validação.')+
p('Quem decide se a ação é permitida é o servidor. Esconder um botão na tela é só conveniência; saber a URL ou o ID de um registro não dá acesso a ele.'),
['bootstrap/app.php','routes/web.php','app/Http/Middleware/EnsureOsDnitPermission.php','app/Http/Controllers/Tenant/OsDnitWorkflowController.php']),
article('arquitetura-entidades','Principais entidades','Models centrais e como se relacionam.',
table(['Model','O que guarda'],[['User','A conta da pessoa (global).'],['TenantUser','Vínculo da conta com a empresa: situação e permissões.'],['ContractParticipant','Participação no contrato: situação e grupos de permissão.'],['Contract','Dados do contrato e vínculos operacionais.'],['OsDnitOrder','A OS DNIT: tenant, contrato, autor, situação e versão do fluxo.'],['OsDnitOrderItem, OsDnitOrderEvent','Produtos da solicitação e eventos do histórico, com snapshot.'],['Activity','Atividade ou checklist, com a OS DNIT quando houver.'],['GedDocument, ProjectDocument','Metadados do documento ou projeto. O arquivo fica no disco.']])+
p('O banco guarda os metadados e o arquivo fica no disco. Por isso o backup tem que cobrir os dois: só o banco não traz os arquivos de volta, e só os arquivos não trazem vínculos, versões e decisões.'),
['app/Models/User.php','app/Models/ContractParticipant.php','app/Models/OsDnitOrder.php','app/Models/Activity.php','config/filesystems.php']),
article('arquitetura-limites','Limitações conhecidas','Pontos que costumam gerar dúvida.',
ul('As empresas compartilham o mesmo banco; não há um banco por tenant.','O assistente não usa busca vetorial.','Ter o driver S3/Azure configurado não significa que todos os módulos gravam lá.','O <code>/up</code> responder 200 só mostra que o servidor web está de pé. Filas, OCR, e-mail, assinatura e visualizador precisam ser verificados à parte.','Planejamento e outras áreas marcadas como futuras ainda não estão prontas.'),
['app/Services/Assistant/AssistantRetriever.php','config/filesystems.php','railway.toml','.github/workflows/post-deploy-smoke.yml','resources/js/Layouts/AuthenticatedLayout.jsx'])]),

chapter('mobile-api','Técnico','API e app mobile','Rotas usadas pelo aplicativo, com autenticação própria.',[
article('api-mobile','Login e sincronização','Token do app e envio de registros feitos offline.',
table(['Rota','Função'],[['POST /api/mobile/login','Confere usuário e senha e devolve o token do app.'],['GET /api/mobile/bootstrap','Dados de apoio que o app precisa (contratos, cadastros etc.).'],['POST /api/mobile/rnc/offline','Recebe uma RNC feita offline.'],['POST /api/mobile/rda/offline','Recebe um RDA feito offline.']])+
p('O app manda o token como Bearer em cada chamada. O servidor guarda só o hash SHA-256 do token e confere validade, expiração e acesso ao tenant. Se o usuário ainda precisa trocar a senha, ele tem que fazer isso no navegador. A escolha do tenant no login é feita pelo servidor.')+
p('Este repositório tem só o backend. O aplicativo em si fica em outro projeto.'),
['routes/api.php','app/Http/Controllers/Api/Mobile/MobileAuthController.php','app/Http/Middleware/EnsureMobileApiToken.php','app/Models/MobileApiToken.php']),
article('api-catalogo','Lista de rotas','Saída do route:list, agrupada.',
p('Gerada com <code>php artisan route:list --json</code>. Inclui as rotas das telas, então nem tudo aqui é API para integração externa.')+
'<div id="route-catalog"></div>',
['routes/web.php','routes/api.php','bootstrap/app.php'])]),

chapter('github','Operação','GitHub e CI/CD','Do commit até o deploy.',[
article('git-fluxo','Branches e pull requests','Fluxo de trabalho no repositório.',
fig('cicd.svg','Caminho de uma alteração até produção.')+
p('O repositório é o <a href="https://github.com/EderDeev/SaaS-Obra">EderDeev/SaaS-Obra</a>. O desenvolvimento acontece na branch <code>staging</code>, e o que vai para produção passa para a <code>main</code>.')+
steps('Crie uma branch a partir de <code>staging</code>.','Faça a alteração e teste localmente.','Abra o PR para <code>staging</code> e espere a revisão e o CI.','Teste na homologação com dados de teste.','Abra o PR de <code>staging</code> para <code>main</code> e publique depois da aprovação.')+
p('Proteção de branch, aprovação obrigatória e o "Wait for CI" são configurados no GitHub e no Railway, fora do repositório.'),
['docs/ci-cd.md','.github/workflows/ci.yml','.github/workflows/azure-development.yml']),
article('ci-validacao','O que o CI verifica','Jobs do GitHub Actions.',
table(['Job','O que faz'],[['Application tests and build','Instala dependências, valida o Composer, faz o build do frontend e roda os testes em SQLite <code>:memory:</code>.'],['PostgreSQL migration check','Sobe um PostgreSQL descartável e roda as migrations. Não roda testes contra ele.'],['Public healthcheck','Depois de um deploy (ou manualmente), chama <code>/up</code> por HTTPS.'],['Azure development deploy','Manual, só para staging. Faz o build da imagem, autentica por OIDC, publica, testa o <code>/up</code> e desliga as revisões no fim.']])+
p('O CI não faz deploy. No Railway, o deploy automático e a espera pelo CI dependem da configuração do serviço. O workflow da Azure também não roda sozinho depois do CI.')+
p('Com OIDC o workflow pega credenciais temporárias na Azure, sem guardar senha. Client ID, tenant ID e subscription ID ficam em Variables; as senhas de banco, storage e a APP_KEY continuam em Secrets. Ver <a href="https://docs.github.com/en/actions/concepts/security/openid-connect">OIDC no GitHub</a>.'),
['.github/workflows/ci.yml','.github/workflows/post-deploy-smoke.yml','.github/workflows/azure-development.yml']),
article('release','Deploy, migrations e rollback','Checklist de publicação.',
steps('Anote o commit e o ambiente de destino.','Confira variáveis, volumes, backup e migrations pendentes.','Rode só as migrations previstas, pelo processo do ambiente.','Teste o <code>/up</code>, o login e um fluxo real, além de filas e integrações.','Se der problema, pare os deploys e volte o código para a versão anterior.','Não reverta o banco: corrija com uma nova migration compatível com os dados que já existem.')+
p('As migrations do OS DNIT (permissões e vínculo com atividades) precisam ir junto com o código. Sem elas o build passa, mas as telas quebram.'),
['AGENTS.md','railway/init-app.sh','azure/start-app.sh','database/migrations/2026_10_21_180000_add_os_dnit_permissions_and_soft_deletes.php','database/migrations/2026_10_21_190000_link_activities_to_os_dnit_orders.php'])]),

chapter('ambientes','Operação','Ambientes','Local, Railway (produção) e Azure (homologação).',[
article('ambientes-mapa','Onde o sistema roda','Os ambientes e o papel de cada um.',
table(['Ambiente','Uso'],[['Local','Desenvolvimento.'],['Railway','Produção. Configurado em <code>railway.toml</code> e nos scripts da pasta <code>railway/</code>.'],['Azure','Homologação, ligada só quando necessário. Ver <code>docs/azure-operacao.md</code>.'],['GitHub Actions','Máquinas e bancos temporários do CI. Nunca acessa o banco de produção.']])+
p('Cada ambiente tem seu próprio banco, bucket, credenciais e lista de destinatários. Não reaproveite os de produção na homologação.'),
['docs/azure-operacao.md','docs/railway.md','docs/ci-cd.md','railway.toml']),
article('railway-operacao','Inicialização no Railway','Processos que sobem junto com o servidor.',
p('O <code>railway.toml</code> define build, pre-deploy, comando de início e o healthcheck em <code>/up</code>. O script de início sobe, além do servidor web, o agendador, o worker geral, o worker de e-mail do OS DNIT, os workers do APS e o worker do GED/OCR. A documentação antiga, que fala só em fila <code>sync</code>, está desatualizada.')+
p('O disco do container é apagado a cada deploy. Arquivos públicos, documentos privados e temporários precisam estar em volume. Um volume montado só em <code>storage/app/public</code> não cobre <code>storage/app/private</code>.')+
p('Confira os volumes no painel antes de publicar. Ver <a href="https://docs.railway.com/volumes">volumes no Railway</a>.'),
['railway.toml','railway/start-app.sh','railway/init-app.sh','config/filesystems.php','docs/railway.md']),
article('azure-sessao','Ligar e desligar a homologação Azure','Script de sessão e limite de uso.',
code('cd /d "C:\\caminho\\obras-saas"\npython scripts\\azure-dev-session.py start --minutes 60')+
p('Rode no CMD, com Python e o Azure CLI já logado, e deixe a janela aberta. Quando a sessão subir, o script mostra a linha <code>URL: https://…</code>. Para ver o status ou encerrar:')+
code('python scripts\\azure-dev-session.py status\npython scripts\\azure-dev-session.py stop')+
p('Cada sessão dura de 1 a 60 minutos. O script reserva o tempo pedido mais 15 minutos de margem, dentro de um limite de 2.100 minutos por mês (UTC). Sessões curtas ou que falharam gastam a reserva do mesmo jeito. Esse limite é nosso, não da Azure, e não impede cobrança na assinatura.')+
p('Ao terminar, o script desativa as revisões, e uma regra cron garante a escala a zero. Banco e arquivos não são apagados, e alguns custos continuam. Não ative revisões por fora do script. Ver <a href="https://learn.microsoft.com/en-us/azure/container-apps/scale-app">escala no Container Apps</a>.'),
['scripts/azure-dev-session.py','docs/azure-operacao.md','infra/azure/modules/core.bicep'])]),

chapter('arquivos-filas','Operação','Arquivos, filas e agendamentos','Armazenamento de arquivos e processamento em segundo plano.',[
article('storage','Discos de armazenamento','Onde cada módulo grava os arquivos.',
table(['Variável','Padrão','Uso'],[['FILESYSTEM_DISK','local','Disco geral.'],['GED_DOCUMENT_DISK','public','Arquivos do GED.'],['PROJECT_DOCUMENT_DISK','local','Arquivos de projetos.'],['OS_DNIT_ATTACHMENT_DISK','local','Anexos da OS DNIT.'],['azure-private, azure-temporary','não usados por padrão','Disponíveis; precisam ser escolhidos e configurados.']])+
p('O disco <code>local</code> aponta para <code>storage/app/private</code> e o <code>public</code> para <code>storage/app/public</code> (exposto em <code>public/storage</code>). Esses são os padrões; confira o que está configurado em cada ambiente antes de definir volumes e backup.')+
note('Arquivos no disco <code>public</code> podem ser abertos direto pela URL, sem passar pelo login. Um link difícil de adivinhar não é controle de acesso.'),
['config/filesystems.php','config/projects.php','config/ged.php','config/os_dnit.php','app/Services/Storage/StoredFileMaterializer.php']),
article('queues','Filas e agendamentos','Workers, filas e o agendador.',
table(['Fila','Para quê'],[['imports, default, maintenance','Importações, tarefas gerais e manutenção.'],['aps (ou AUTODESK_APS_QUEUE)','Envio e conversão de modelos na Autodesk.'],['ged (ou GED_OCR_QUEUE)','OCR e processamento de documentos.'],['os-dnit-mail, na conexão os_dnit_mail','E-mails do fluxo DNIT, com retentativas próprias.'],['schedule:work','Agendador. Entre outras coisas, gera o RDO diário.']])+
p('Job é a tarefa, a fila é onde ela espera e o worker é o processo que executa. Se um job não anda, a tela continua funcionando normalmente; veja primeiro se existe worker rodando para aquela conexão e fila. Quando OCR, conversão APS ou e-mails não acontecem, quase sempre é fila parada.')+
p('Cuidado ao reprocessar jobs com falha: alguns, como envio de e-mail, podem duplicar o efeito.'),
['config/queue.php','railway/start-app.sh','railway/start-worker.sh','routes/console.php','app/Services/OsDnitFlowNotifier.php']),
article('notificacoes','Notificações e perfil','Sino, e-mails e dados da conta.',
p('O sino mostra as notificações da conta e marca o que já foi lido. Os e-mails são enviados conforme o evento e os responsáveis. Para saber quem decidiu o quê e quando, a referência é o histórico do módulo, não o e-mail.')+
p('No perfil o usuário altera os dados da própria conta. Isso não muda vínculos nem permissões. Ao abrir um chamado sobre e-mail, informe ambiente, contrato, documento e horário, sem colar chaves ou senhas.'),
['app/Http/Controllers/NotificationController.php','app/Http/Controllers/ProfileController.php','app/Notifications/OsDnitFlowChangedNotification.php'])]),

chapter('segredos','Operação','Credenciais e variáveis de ambiente','Onde ficam as chaves e como trocá-las.',[
article('segredos-regra','Onde guardar as credenciais','Cada ambiente tem o seu lugar.',
table(['Onde','O que fica lá'],[['.env local','Configuração da máquina de desenvolvimento. Nunca vai para o Git.'],['Variables do Railway','Configuração de produção.'],['Secrets e Variables do GitHub','Valores usados pelos workflows.'],['Container App na Azure','Configuração da homologação.'],['Banco de dados','Senhas cadastradas pela tela, como a do IMAP do GED, gravadas criptografadas.']])+
p('Variáveis <code>VITE_*</code> entram no JavaScript que vai para o navegador, então só podem ter valores públicos, como o token do Mapbox. <code>OPENAI_API_KEY</code>, <code>BREVO_API_KEY</code>, client secret do APS, senha do banco e connection string do storage ficam só no servidor.')+
p('Para cada credencial, registre quem é o responsável, em qual ambiente ela é usada, para que serve, os escopos, a validade e quando foi testada pela última vez. Nunca coloque valores em prints, exemplos, documentação ou chamados.'),
['.gitignore','config/services.php','config/mail.php','config/filesystems.php','.github/workflows/azure-development.yml']),
article('segredos-catalogo','Lista de variáveis','Nomes e finalidade das principais variáveis.',
'<div id="credential-catalog"></div>'+p('Existem ainda outras variáveis de timeout e limites. A lista completa está abaixo.')+'<div id="env-catalog"></div>',
['config/app.php','config/database.php','config/services.php','config/mail.php','config/filesystems.php','config/signatures.php','config/ged.php']),
article('segredos-rotacao','Trocar uma chave','Passo a passo da rotação.',
steps('Identifique o provedor, o ambiente, o que usa a chave e quem vai fazer a troca.','Gere a nova chave no provedor, com os escopos necessários.','Atualize a variável no ambiente e reinicie o que for preciso. Se for <code>VITE_*</code>, refaça o build.','Teste uma operação real e veja os logs e os workers.','Revogue a chave antiga e registre a troca.')+
note('<strong>APP_KEY:</strong> não gere uma nova a cada deploy. Ela criptografa dados já gravados, como as senhas IMAP. Para trocar, coloque a chave antiga em <code>APP_PREVIOUS_KEYS</code> e planeje a recriptografia dos dados.')+
p('Se uma chave vazar, avise o responsável e faça a troca no provedor. Apagar o valor num commit novo não resolve: ele continua no histórico do Git.'),
['config/app.php','app/Models/GedEmailAccount.php','.gitignore'])]),

chapter('instalacao','Operação','Instalação local','Como montar o ambiente de desenvolvimento sem apagar o banco.',[
article('instalar','Preparar o ambiente','Dependências, configuração e servidor local.',
steps('Instale PHP (com as extensões do projeto), Composer, Node.js e npm. O Dockerfile mostra o ambiente Linux completo, com as ferramentas de OCR.','Clone o repositório e rode <code>composer install</code> e <code>npm ci</code>.','Crie o <code>.env</code> a partir do <code>.env.example</code>, com credenciais de desenvolvimento. Não copie o banco de produção.','Gere a <code>APP_KEY</code> só em instalação nova, antes de existir qualquer dado criptografado.','Antes de rodar migrations, confira driver, host, banco e <code>APP_ENV</code>. Em banco com dados, nunca use <code>migrate:fresh</code>, <code>refresh</code> ou <code>reset</code>.','Suba o backend, o frontend e os workers que precisar. O sistema abre em 127.0.0.1:8000.')+
code('composer install\nnpm ci\nnpm run serve:php')+
p('Em outro terminal, o Vite:')+code('npm run dev -- --host 127.0.0.1 --port 5173 --strictPort')+
p('Se existir o arquivo <code>public/hot</code>, o endereço dentro dele precisa ser o do Vite que está rodando. Para usar o frontend compilado, rode o build e apague o <code>public/hot</code>.'),
['composer.json','package.json','Dockerfile','server.php','AGENTS.md']),
article('workers-locais','Workers e HTTPS','Comandos dos workers e erro de certificado.',
code('php artisan queue:work database --queue=imports,default,maintenance --sleep=3 --tries=1 --timeout=3600\nphp artisan queue:work database --queue=aps --sleep=1 --tries=1 --timeout=900\nphp artisan queue:work database --queue=ged --sleep=3 --tries=1 --timeout=3600\nphp artisan queue:work os_dnit_mail --queue=os-dnit-mail --sleep=2 --tries=5 --timeout=60\nphp artisan schedule:work')+
p('Cada linha é um processo, em um terminal separado. Suba só os que o recurso que você vai testar precisa. O <code>composer run dev</code> também sobe alguns processos, mas confira se ele cobre as filas de que você precisa.')+
p('Se aparecer erro de certificado HTTPS, veja qual <code>php.ini</code> está sendo carregado e se o <code>curl.cainfo</code> aponta para um bundle de CAs válido. Não desligue a verificação SSL. Ver <a href="https://www.php.net/manual/en/curl.configuration.php">curl.cainfo</a>.'),
['composer.json','railway/start-app.sh','config/queue.php','config/services.php']),
article('testes-seguros','Rodar os testes','Somente em SQLite em memória.',
p('Os testes (PHPUnit/Pest) só podem rodar em SQLite em memória. Antes de rodar, confirme que a conexão resolvida é <code>sqlite</code>, o banco é <code>:memory:</code> e o ambiente é <code>testing</code>. O bootstrap dos testes já remove as URLs de banco e bloqueia a execução se detectar sinais de produção.')+
steps('Use o <code>phpunit.xml</code> e os arquivos de teste do projeto, sem alterar a conexão.','Confirme a conexão resolvida.','Rode a suíte ou só o arquivo que interessa.','Nos testes, simule as chamadas a serviços externos.','Rode também o build do frontend quando mexer em telas.')+
note('<strong>Nunca</strong> rode os testes contra PostgreSQL, seja local, homologação ou produção. A etapa de migrations com PostgreSQL do CI usa um banco descartável, criado só para isso.'),
['AGENTS.md','tests/bootstrap.php','phpunit.xml','.github/workflows/ci.yml'])]),

chapter('operacao','Operação','Backup e suporte','Backup, problemas comuns e manutenção deste manual.',[
article('backup','Backup','O que precisa entrar no backup.',
ul('Banco: vínculos, registros, versões, histórico e metadados.','Arquivos: todas as pastas e containers usados pelos módulos.','Configuração: lista das variáveis de cada ambiente, guardada em local seguro.','Código: o commit em produção e as dependências.','Serviços externos: os modelos na Autodesk e os documentos no OpenSign.')+
steps('Defina frequência, retenção e responsável.','Faça um backup antes de qualquer mudança grande.','Teste a restauração num ambiente separado.','Depois de restaurar, confira registros, arquivos, permissões e dados criptografados.','Anote quanto tempo levou e o que não voltou.')+
p('Ter backup ligado no provedor não garante que a restauração funciona. Só o teste confirma.'),
['AGENTS.md','config/filesystems.php','docs/azure-operacao.md','docs/ci-cd.md']),
article('diagnostico','Problemas comuns','Por onde começar a investigar.',
table(['Problema','O que verificar primeiro'],[['Menu ou dados não aparecem','Tenant, vínculo ativo, contrato e permissão. Depois, a visibilidade do registro e o papel no fluxo.'],['Rascunho DNIT não edita','Se é o autor, se tem permissão de editar, se o status é <code>draft</code> e se <code>submitted_at</code> está vazio. OS devolvida não volta a ser editável.'],['Análise não encaminha','Etapa atual, versão do registro, campos obrigatórios e se o responsável de destino está ativo.'],['E-mail não chegou','Se o evento foi gerado, os destinatários, o worker da fila, os jobs com falha e o retorno do Brevo.'],['OCR parado','Worker <code>ged</code>, ferramentas instaladas, acesso ao arquivo e limites de tempo e páginas.'],['Modelo não abre','Worker APS, URN, manifesto e resposta da conversão.'],['Assistente não responde','Se está habilitado, a cota, a chave, o modelo, a base URL, o certificado HTTPS e o log do backend.'],['Arquivos somem depois do deploy','O disco usado pelo módulo e se ele está num volume.'],['Tela sem CSS ou JS','Build, <code>public/hot</code>, endereço do Vite e arquivos estáticos.'],['Homologação Azure não abre','Se a sessão está ativa, a URL mostrada pelo script e se a revisão está ativa.']])+
p('No chamado, informe horário, ambiente, módulo, contrato, número do documento e em que passo deu erro, com a mensagem e um print sem dados sensíveis. Para erro de validação, comece pelo campo indicado. Nunca limpe o banco para resolver um problema.'),
['docs/os-dnit.md','docs/azure-operacao.md','railway/start-app.sh','app/Http/Controllers/Tenant/AssistantController.php']),
article('manter-manual','Atualizar este manual','Onde editar e como gerar de novo.',
steps('Edite o texto em <code>docs/portal/content.py</code>.','Se a tela ou o fluxo mudou, atualize os diagramas (<code>diagrams.py</code>) e as capturas.','Confira os nomes das variáveis direto em <code>config/</code> e nos workflows. Não copie nada do <code>.env</code>.','Rode <code>python docs/portal/build_portal.py</code>.','Abra o <code>dist/index.html</code> e confira busca, imagens, navegação, impressão e a versão de celular.')+
p('O <code>auditoria.json</code>, gerado junto, lista os arquivos usados e o commit de base de cada versão.'),
['docs/portal/content.py','docs/portal/build_portal.py','docs/portal/README.md'])]),

chapter('glossario','Anexos','Glossário e referências','Termos usados no sistema e links para documentação externa.',[
article('glossario-termos','Glossário','Siglas e termos técnicos.',
table(['Termo','Significado'],[['SaaS','Software usado como serviço, pela internet.'],['Tenant','O ambiente de uma empresa no sistema.'],['Contrato','O contrato de obra, que organiza pessoas, registros e operação.'],['Frontend / backend','A tela no navegador / o servidor que valida e executa as regras.'],['API','Interface para um sistema conversar com outro.'],['Endpoint','O endereço de uma operação da API.'],['Token / escopo','Credencial de acesso / o que ela permite fazer.'],['Webhook','Chamada que um serviço externo faz ao SigWorks quando algo acontece.'],['Job / worker / fila','A tarefa / o processo que executa / onde a tarefa espera.'],['Migration','Alteração versionada na estrutura do banco.'],['Commit / branch / PR','Versão registrada / linha de trabalho / pedido para juntar alterações.'],['CI/CD','Testes automáticos / processo de publicação.'],['Deploy','Publicar uma versão num ambiente.'],['RAG','Buscar informações antes de gerar a resposta com IA.'],['OCR','Leitura automática de texto em imagem ou PDF.'],['Exclusão lógica','O registro some das listas, mas continua guardado.'],['BDI','Benefícios e Despesas Indiretas, aplicado sobre o custo.'],['EAP','Estrutura Analítica do Projeto.'],['RDA / RDO','Apontamento diário / Relatório Diário de Obra.'],['RNC','Relatório de Não Conformidade.'],['BM / FR','Boletim de Medição / Folha de Rosto.']]),
['app/Support/TutorialCatalog.php','app/Services/Assistant/AssistantRetriever.php','docs/os-dnit.md']),
article('fontes','Referências','Documentação dos serviços externos.',
ul('<a href="https://developers.openai.com/api/reference/overview">OpenAI: API e autenticação</a>','<a href="https://aps.autodesk.com/en/docs/oauth/v2">Autodesk APS: OAuth</a>','<a href="https://developers.brevo.com/docs/api-key-authentication">Brevo: chave de API</a>','<a href="https://docs.mapbox.com/help/getting-started/access-tokens/">Mapbox: tokens</a>','<a href="https://docs.opensignlabs.com/">OpenSign</a>','<a href="https://docs.github.com/en/actions/concepts/security/openid-connect">GitHub: OpenID Connect</a>','<a href="https://learn.microsoft.com/en-us/azure/container-apps/scale-app">Azure: escala no Container Apps</a>','<a href="https://docs.railway.com/volumes">Railway: volumes</a>','<a href="https://www.php.net/manual/en/curl.configuration.php">PHP: configuração do cURL</a>')+
p('As capturas de tela são do próprio sistema. Os diagramas foram feitos para este manual.'),
['docs/portal/README.md'])])
]

CREDENTIALS = [
['APP_KEY / APP_PREVIOUS_KEYS','Criptografia da aplicação','Servidor. Não trocar em instalação que já tem dados criptografados.'],
['DB_CONNECTION / DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD','Conexão com o banco','Servidor. DB_URL ou DATABASE_URL podem substituir as demais.'],
['DB_PROTECT_DESTRUCTIVE','Bloqueia comandos destrutivos no banco','Servidor. Manter ligado em ambientes com dados.'],
['AUTODESK_APS_CLIENT_ID / AUTODESK_APS_CLIENT_SECRET','Credenciais do APS','Servidor. O secret nunca vai para o navegador.'],
['AUTODESK_APS_BUCKET_KEY / AUTODESK_APS_REGION / AUTODESK_APS_SCOPES','Bucket, região e escopos','Servidor. Usar bucket diferente em cada ambiente.'],
['AUTODESK_APS_CA_BUNDLE / AUTODESK_APS_VERIFY_SSL','Certificados HTTPS do APS','Servidor. Corrigir o bundle em vez de desligar a verificação.'],
['OPENAI_API_KEY / OPENAI_BASE_URL / OPENAI_CHAT_MODEL','Chave, endereço e modelo do assistente','Servidor.'],
['AI_ASSISTANT_ENABLED / AI_*_MONTHLY_TOKEN_LIMIT','Liga o assistente e define as cotas internas','Servidor.'],
['OPENAI_RAG_* / OPENAI_HISTORY_*','Limites de contexto e histórico','Servidor. Mudar só depois de avaliar qualidade e consumo.'],
['MAIL_MAILER / BREVO_API_KEY / MAIL_FROM_ADDRESS','Envio de e-mail e remetente','Servidor.'],
['MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD','SMTP, se for usado no lugar do Brevo','Servidor.'],
['VITE_MAPBOX_ACCESS_TOKEN','Mapas','Público; vai no build do frontend.'],
['SIGNATURE_DRIVER / OPENSIGN_BASE_URL / OPENSIGN_API_KEY','Assinatura de documentos','Servidor.'],
['OPENSIGN_WEBHOOK_SECRET','Segredo do webhook do OpenSign','Servidor. Obrigatório em produção.'],
['AZURE_STORAGE_CONNECTION_STRING / AZURE_STORAGE_CONTAINER','Acesso ao Azure Blob','Servidor. A connection string é secreta.'],
['FILESYSTEM_DISK / GED_DOCUMENT_DISK / PROJECT_DOCUMENT_DISK / OS_DNIT_ATTACHMENT_DISK','Disco usado por cada módulo','Servidor. Conferir se cada disco está em volume.'],
['AZURE_CLIENT_ID / AZURE_TENANT_ID / AZURE_SUBSCRIPTION_ID','Login OIDC do workflow','GitHub Variables. Não são secretos.'],
['AZURE_APP_KEY / AZURE_POSTGRES_ADMIN_PASSWORD / AZURE_STORAGE_CONNECTION_STRING','Segredos da homologação Azure','GitHub Secrets.'],
['GITHUB_TOKEN','Token temporário do workflow','Gerado pelo GitHub a cada execução.'],
['Token do app mobile','Acesso do app à API','O servidor guarda só o hash e controla a expiração.'],
['Conta e senha IMAP do GED','Leitura da caixa de e-mail','Cadastrada na tela; a senha fica criptografada no banco.']
]
