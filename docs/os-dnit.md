# OS DNIT

## Tour guiado

O botão **Iniciar tour** está disponível em Ordem de Serviço, Responsáveis,
Produtos e Dashboard. O percurso tem 12 passos: responsáveis, produtos,
criação/rascunho/envio, análise Concremat, comparação e decisão do fiscal,
e dashboard.

As telas usam exemplos locais, sem IDs ou operações de gravação reais.
O tour não altera cadastros, não movimenta OSs e não envia e-mails.
Exige visualizar OS e vínculo ativo no contrato, como a listagem.
Voltar funciona entre etapas; Concluir ou Fechar retorna à listagem.

- Rota: `tenant.os-dnit.tour-preview` (GET `/t/{tenant}/os-dnit/tour-preview`).
- Textos e sequência: `resources/js/Support/osDnitTour.js`.
- Telas: `resources/js/Pages/Tenant/OsDnit/TourPreview.jsx`.
- Controles: `resources/js/Components/OsDnitTour.jsx`, com a API `onEvent`/`options` da versão instalada do React Joyride.

## Cadastro inicial de solicitações

- Lista com busca por número/assunto, filtro por contrato e 10 itens por página, com botão Acessar em cada OS. O autor autorizado também pode enviar para análise diretamente pelo card, com as mesmas regras de envio da visualização.
- Número automático ao salvar, no formato `001/2026`, sequencial por tenant, contrato e ano. A sequência reinicia em cada ano no fuso da aplicação (`America/Sao_Paulo` por padrão). O contrato é bloqueado durante a transação para serializar criações simultâneas; números existentes são preservados e números enviados pelo cliente são ignorados.
- O formulário mostra uma prévia do próximo número para o contrato selecionado, sem reservar ou consumir a sequência. Ao salvar, o servidor recalcula o número para considerar outras OSs criadas nesse intervalo.
- Contrato selecionado entre os contratos acessíveis ao usuário: todos os usuários precisam de participação ativa explícita no contrato, inclusive
  proprietários, administradores do tenant e administradores da plataforma.
- Rodovia ativa da Parametrização vinculada ao mesmo contrato, quilômetros com até três casas decimais,
  prazo pretendido como data, textos da demanda, referência SEI opcional.
- Pelo menos um produto do mesmo contrato, sem duplicidade, quantidade positiva
  com até três casas decimais. Nome, código, unidade e frequência são copiados
  para o registro da solicitação para preservar o histórico.
- Cadastro cria um rascunho. As ações do fluxo dependem dos responsáveis cadastrados no contrato.

## PDF da Ordem de Serviço

Na listagem e na visualização da OS, **Baixar PDF** abre um menu com quatro
opções cumulativas no modelo **Autorização institucional**:

- Somente solicitação do analista (`scope=analyst`).
- Solicitação + Análise Concremat (`scope=concremat`).
- Solicitação + análises Concremat e fiscal (`scope=inspector`).
- Documento completo com o fluxo (`scope=complete`, padrão para links antigos).

A rota `tenant.os-dnit.orders.pdf` valida o escopo e exige autenticação, acesso
ao tenant e participação ativa no contrato, inclusive para administradores. O
arquivo é enviado como download privado, sem cache. Exportar não altera o fluxo,
não cria eventos e não envia e-mails. Cada escopo recebe um sufixo no nome do PDF.

A ordem do documento é:

1. Cabeçalho com as informações da OS, descrição da necessidade técnica,
   justificativa, produtos originalmente solicitados e relação de anexos.
2. Novo cabeçalho com as informações da OS, Análise Concremat, prazo estimado,
   comentário e comparação de produtos/quantidades com o pedido do analista.
3. Novo cabeçalho com as informações da OS, última decisão do fiscal, comentário
   de aprovação ou motivo da reprovação e produtos/prazo registrados nessa decisão.
4. Fluxo na seção final, com movimentações, datas, responsáveis e registros de
   análises anteriores/devoluções. Comentários já exibidos não são duplicados.

Cada etapa começa em uma nova página; textos e tabelas extensos continuam em
páginas adicionais sem cortes. Etapas ainda não registradas aparecem como pendentes,
sem autorização fictícia. Uma decisão de devolução anterior é identificada como tal
quando a OS já teve novas movimentações. Os anexos são relacionados sem incorporar
seus conteúdos ao documento.

Produtos e prazo autorizados usam o snapshot do evento de aprovação. Alterações
posteriores no catálogo não mudam os textos e quantidades registrados. A coleta
dos dados ocorre sob bloqueio compartilhado da OS, antes de renderizar o PDF,
para não misturar versões durante uma transição concorrente. O documento mostra
versão e data de emissão e não representa assinatura digital. HTML do usuário é
escapado e o renderizador não executa scripts nem carrega recursos remotos.

## Análise e autorização

A análise fica em **OS DNIT → Ordem de Serviço**, sem submenu separado.
O card exibe **Acessar** e, quando a etapa exige uma ação do usuário,
**Analisar OS**. A lista possui cinco filtros com contadores: **Todas as OSs**,
**Rascunho (Analista DNIT)**, **Análise Concremat**, **Fiscal do Contrato** e **Finalizadas**.
As devoluções entram na etapa do responsável pela próxima ação. Finalizadas
reúne as OSs aprovadas pelo fiscal do contrato após o fluxo de análise;
elas também continuam em Todas as OSs. Contagens e listagem respeitam o tenant, os contratos
com participação ativa e os filtros de contrato/busca. Trocar de etapa limpa a
situação e reinicia a paginação, mantendo busca e contrato. A lista tem 10 itens
por página e filtro adicional de situação. Busca automática após 350 ms de pausa;
contrato, situação e etapa aplicados imediatamente, sempre voltando à primeira
página. Requisições de filtro anteriores são canceladas para evitar resultados
antigos. A paginação fica visível mesmo com uma única página, com intervalo de
registros, 10 itens por página, seletor e botões de navegação. Com várias páginas,
os controles também aparecem no fim da listagem. Links preservam os filtros.

O contador de pendências no submenu considera análise/revisão Concremat nos
contratos onde o usuário é fiscal Concremat, e decisão onde é fiscal do contrato.
Rascunhos, aprovações e devoluções ao analista não entram nesse contador.
Um usuário com ambos os papéis conta cada OS uma única vez. O contador é
atualizado em navegações e ações, inclusive atualizações parciais do Inertia.

**Analisar OS** abre um modal maior, de até 1152 px e 94% da altura disponível,
com fundo escurecido e borrado. Pedido original, produtos e anexos ficam em uma
seção recolhível; a recomendação Concremat é a área principal, com quantidades
editáveis, inclusão/retirada de produtos sob o título **Produtos solicitados**, prazo
e comentário obrigatório tanto ao salvar quanto ao encaminhar. Comentários ausentes,
vazios ou contendo somente espaços são recusados pelo servidor. O modal não exibe
os blocos introdutórios da Concremat e do fiscal do contrato, a consulta de histórico
nem o aviso de e-mails. O comentário é identificado como **Análise Concremat** no
modal e na visualização da OS.
O fiscal do contrato
vê a comparação em dois painéis: **Solicitação do analista** e **Análise Concremat**.
Cada lado reúne seu prazo e seus produtos/quantidades; inclusões, retiradas e
alterações ficam destacadas na análise, junto do comentário da Concremat. Em telas
pequenas, os painéis ficam empilhados. O fiscal informa um comentário para aprovar;
para reprovar e devolver, informa o motivo e o destino. O comentário é exigido no
cliente e no servidor para ambas as decisões, sem indicação de obrigatoriedade no
rótulo. Textos vazios ou somente com espaços são recusados. As ações ficam no rodapé enquanto o conteúdo rola.

**Salvar para depois** mostra a confirmação de rascunho salvo no próprio modal e
recarrega a versão atualizada. Encaminhar, aprovar ou devolver fecha o modal e
atualiza a lista e o contador. Esc e Fechar/X permitem sair; alterações não salvas
exigem escolher continuar ou descartar. O foco retorna ao botão que abriu o modal,
quando ainda existe na lista. Erros de validação são trazidos para a área visível.

Os dados são carregados sob demanda por uma resposta JSON autenticada e privada,
com as mesmas verificações de tenant, contrato e papel. Links antigos de análise
redirecionam para Ordem de Serviço; links de uma OS abrem seu modal pela query
`analysis_order_id`. A visualização de uma OS também possui o atalho **Analisar OS**
quando há uma ação pendente. O fluxo mantém bloqueio de versão, histórico e e-mails.

O envio do modal usa chamadas separadas a `form.transform()` e `form.post()`;
o hook instalado retorna `void` na transformação. Ao salvar, usa `setDefaults()`.
O teste `node tests/Frontend/osDnitAnalysisForm.test.mjs` usa o hook real instalado
para verificar salvar, encaminhar, aprovar e devolver, sem rede ou banco.

Em **OS DNIT → Responsáveis**, um administrador com vínculo ativo no contrato
seleciona os usuários para Analista DNIT, Fiscal Concremat e Fiscal do contrato.
É possível cadastrar vários usuários em cada papel. Ninguém recebe um papel
automaticamente; ser administrador não autoriza analisar ou aprovar uma OS.
O vínculo ativo no contrato continua obrigatório em todas as etapas.

1. O Analista DNIT salva a OS como **Rascunho**, pode editar sua solicitação e
   clica em **Enviar para Fiscal Concremat**. Apenas o autor com papel ativo de
   analista pode editar sua solicitação, somente enquanto for rascunho e ainda
   não tiver sido enviada. Após o envio, a edição fica bloqueada no servidor e na interface.
2. Em **Em análise Concremat**, o fiscal informa comentário, prazo estimado e
   produtos/quantidades recomendados. Pode acrescentar ou retirar produtos do
   mesmo contrato, salvar a análise e encaminhar ao fiscal do contrato.
3. Em **Aguardando fiscal do contrato**, o fiscal vê o pedido e a análise lado
   a lado. Produtos incluídos/retirados, quantidades e prazo alterados aparecem
   destacados. Aprovação exige comentário; reprovação e devolução exigem motivo e destino.
4. Problemas de prazo/produtos/quantidades podem retornar à Concremat. Problemas
   no pedido retornam ao analista autor. A devolução ao analista não reabre a
   edição: a solicitação permanece preservada. O autor pode reencaminhá-la à
   Concremat para nova análise. Os ajustes da Concremat continuam separados do pedido original.
5. Uma OS **Aprovada** fica somente para consulta neste fluxo.

O envio exige um responsável com vínculo ativo na etapa de destino. Revogar um
papel ou vínculo impede imediatamente novas ações. Cada alteração usa bloqueio
da OS e uma versão para impedir que um formulário antigo sobrescreva decisões
ou que o mesmo envio seja registrado duas vezes.

O pedido e a análise são armazenados separadamente. O histórico conserva autor,
data, etapa, comentários e uma cópia da solicitação/análise em cada ação, inclusive
as versões anteriores às alterações do rascunho e da análise. Anexos existentes são preservados e novos
anexos podem ser acrescentados somente na edição do rascunho, antes do envio, respeitando o limite total.
A migração 190000 converte registros antigos `registered` em rascunhos, preservando
seus dados; o histórico passa a ser registrado na primeira ação do novo fluxo.
As mudanças de etapa também geram e-mails aos responsáveis.

## E-mails do fluxo

| Ação | Destinatários |
| --- | --- |
| Envio ou reenvio à Concremat | Autor e fiscais Concremat do contrato |
| Encaminhamento ao fiscal do contrato | Autor, fiscais Concremat e fiscais do contrato |
| Reprovação e devolução à Concremat | Autor, fiscais Concremat e fiscais do contrato |
| Reprovação e devolução ao analista | Autor, fiscais Concremat e fiscais do contrato |
| Aprovação | Autor, fiscais Concremat e fiscais do contrato |

Somente responsáveis ativos com participação ativa no mesmo tenant e contrato
recebem os avisos. Outros analistas que não sejam autores da OS não recebem a
solicitação. Um usuário com vários papéis recebe um aviso por evento. A autorização
é conferida novamente pelo worker, para evitar envio após revogação do acesso.
Salvar rascunho ou salvar uma análise sem encaminhar não muda a etapa e não
dispara e-mail.

Os e-mails usam o provedor de e-mail já configurado pela aplicação (Brevo no
ambiente local atual), em HTML e texto. Incluem número, assunto, contrato, etapa,
autor da ação, data, prazo estimado quando disponível, comentário/justificativa e
link autenticado para a OS. O conteúdo vem da cópia preservada no evento,
mesmo quando a OS avança antes de o e-mail ser processado. Os anexos não são
enviados por e-mail.

A fila `os-dnit-mail`, na conexão `os_dnit_mail`, grava os jobs na mesma conexão
de banco da aplicação e na mesma transação da mudança de etapa. Uma transação
cancelada não deixa avisos pendentes. O provedor é chamado pelo worker após a
confirmação da transação, com até cinco tentativas e intervalos de 60, 300, 900 e
1800 segundos. Uma falha de entrega não desfaz a etapa da OS. Falhas que esgotam
as tentativas ficam na tabela padrão `failed_jobs` para diagnóstico e reenvio.

```sh
php artisan queue:work os_dnit_mail --queue=os-dnit-mail --sleep=2 --tries=5 --timeout=60
```

`composer dev` inicia esse worker. Os scripts `railway/start-app.sh` e
`railway/start-worker.sh` também o iniciam na próxima publicação. Nenhuma
publicação ou alteração de variáveis de produção foi realizada nesta mudança.

## Anexos e publicação

Até dez anexos, 10 MB cada: PDF, Word, Excel, JPG/PNG ou ZIP. São servidos por
download autenticado, com conferência da empresa, contrato e vínculo à OS.
Os nomes internos de armazenamento não são enviados na resposta da página.

`OS_DNIT_ATTACHMENT_DISK` seleciona o disco. O padrão `local` usa
`storage/app/private`, adequado ao desenvolvimento local. **Antes de publicar no
Railway, configure um disco privado persistente** (por exemplo, `azure-private`
com suas credenciais já configuradas, ou um volume privado persistente).
O volume atual em `storage/app/public` não persiste `storage/app/private`.
Não use o disco `public` para estes anexos. Nenhuma alteração de volume ou
variáveis de produção foi realizada nesta implementação.

## Isolamento por tenant e contrato

Produtos e rodovias têm `contract_id` obrigatório. Código de produto e nome de
rodovia são únicos dentro do contrato; contratos diferentes podem repetir esses
valores. Listagens, filtros, criação, edição e downloads verificam o tenant e a
participação ativa do usuário no contrato. Participações inativadas ou removidas
não autorizam acesso. O papel de administrador não dispensa esse vínculo.

A criação de OS oferece apenas catálogos de contratos acessíveis. Ao trocar o
contrato, a interface limpa a rodovia e os produtos selecionados. O servidor
revalida que todos pertencem ao contrato escolhido, mesmo se o usuário participar
de vários contratos. O contrato de uma rodovia já cadastrada não pode ser trocado.

A migração 180000 preserva os registros existentes e resolve o contrato pelo uso
em OSs existentes ou, na ausência de uso, pelo único contrato do tenant. Caso haja
ambiguidade, ela interrompe a aplicação e exige definição explícita do vínculo.
Não escolhe um contrato arbitrário, não concede participações a usuários e não
remove dados. O PostgreSQL executa essa migração em transação.

A seleção de produtos usa uma lista pesquisável com marcação direta, sem botão separado de adicionar. A busca considera código, nome, categoria, unidade e frequência, sem diferenciar acentos ou maiúsculas. Trocar o contrato limpa a seleção e a busca.

## Dashboard

O submenu **OS DNIT > Dashboard** usa a rota `tenant.os-dnit.dashboard`
(`/t/{tenant}/os-dnit/dashboard`). É somente leitura e exige participação ativa
nos contratos, inclusive para administradores. Não cria registros nem dispara
notificações.

Os filtros automáticos são contrato, rodovia e período. O padrão cobre os
últimos 12 meses até hoje, e o intervalo máximo é de 24 meses. Trocar o contrato
limpa a rodovia; rodovias inativas continuam disponíveis para consultar o
histórico, respeitando o vínculo contratual.

Os seis cartões mostram total, rascunhos, análise Concremat, fiscal do contrato,
aprovadas e devolvidas. Os cinco grupos de situação são exclusivos: devolvidas
não são somadas novamente às filas de análise. Cartões, distribuição e
pendências consideram a **data de abertura** da OS e sua situação atual.
Aprovação representa conclusão do fluxo, não comprovação da execução.

A evolução mensal considera os eventos `submitted` e `approved` ocorridos
no período, mesmo quando a OS foi aberta antes. Cada OS conta uma única vez por
ação e mês, evitando duplicidade em reenvios. Meses sem movimentos mostram zero.
Registros antigos sem eventos não recebem movimentações inventadas.

As pendências incluem OSs ainda não aprovadas, com paginação de 10 itens,
ordenadas pela maior espera na etapa atual. Salvar um rascunho ou uma análise
não reinicia essa espera. Quando não há evento de transição, a referência é a
data de criação. O botão Analisar aparece somente para responsáveis autorizados
na etapa atual; o acesso à visualização continua sujeito ao vínculo contratual.

## Uso em celulares

As telas do módulo usam a classe `os-dnit-page`, e os modais usam
`os-dnit-dialog` / `os-dnit-confirm`. As regras são restritas ao OS DNIT:
campos com fonte de 16 px e controles de pelo menos 44 px em telas estreitas,
quebra de textos longos, ações empilhadas e espaço para o assistente flutuante.
A paginação mantém o seletor de página e oferece Anterior/Próxima com largura
distribuída no celular; no desktop, também mostra os números das páginas.

As opções de PDF se expandem dentro do cartão no celular, evitando sair pela
lateral. No desktop, continuam como menu suspenso. O modal de análise permite
rolagem integral em telas baixas ou em paisagem, mantendo as ações alcançáveis.
Cancelar a confirmação preserva os dados e não encaminha a OS.

Verificação em 21/10/2026: listagem, criação, visualização, produtos,
parametrização e responsáveis em viewports de 320, 375, 390, 768 e 1280 px;
análise e confirmação também em 844 × 390 px. Não houve transbordamento
horizontal nos cenários verificados. Também foi conferido o filtro dinâmico
de produtos e o cadastro em modal a 320 px. Isso é emulação de viewport no
navegador, não um teste em aparelhos físicos iOS/Android.

As quatro opções de PDF foram baixadas pelo menu e renderizadas para revisão.
Os documentos preservam o formato A4, a seleção de texto e os conteúdos de
cada recorte; não se reorganizam como uma página HTML. Em celulares, a leitura
usa o zoom do visualizador de PDF. As tabelas continuam nas páginas seguintes
quando necessário, sem reduzir automaticamente o texto para caber em uma só.

## Permissões por contrato

Em `/permissoes`, o grupo **OS DNIT** contém 13 permissões: visualizar, criar,
editar e excluir OS; visualizar, criar, editar e excluir produtos; visualizar
dashboard; criar, editar e excluir parametrizações; e gerenciar responsáveis.
Selecione primeiro o usuário e o contrato. É obrigatório um vínculo explícito
e ativo em `contract_participants`, inclusive para administradores. Conceder
permissões não cria esse vínculo nem permite acesso a outro tenant ou contrato.

`Visualizar OS` controla o menu e é a base das demais ações. Permissões de
alteração de produtos também incluem a visualização do catálogo. O servidor
verifica todas as rotas, PDFs e anexos; ocultar botões é apenas a apresentação.
Parametrização pode ser consultada com visualizar OS; suas alterações têm
permissões independentes. Os submenus Produtos, Dashboard e Responsáveis
aparecem conforme suas permissões específicas.

O campo JSON `contract_participants.os_dnit_permissions` guarda as concessões.
`[]` revoga todo o módulo naquele contrato. `null` preserva o comportamento
anterior: administradores têm todas as permissões e os demais podem visualizar
OS e catálogo, criar/editar pedidos e consultar o dashboard. O proprietário
mantém acesso total nos contratos em que possui vínculo ativo, seguindo o
padrão da tela de permissões. Os papéis definidos em Responsáveis continuam
necessários para criar pedidos como analista, analisar como Concremat ou decidir
como fiscal. Revogar visualizar OS bloqueia esses papéis e os avisos por e-mail.

Editar OS continua limitado ao autor, enquanto rascunho nunca enviado. Excluir
OS exige a permissão específica e também um rascunho nunca enviado, com
confirmação e verificação da versão. A exclusão é lógica: preserva histórico,
anexos e a reserva do número sequencial. Produtos e rodovias já usados em OSs
não podem ser excluídos; suas edições preservam os dados salvos nas solicitações.

A migração `2026_10_21_180000_add_os_dnit_permissions_and_soft_deletes` adiciona
somente o campo de permissões e `deleted_at`, sem alterar concessões existentes.
Foi aplicada no ambiente local em 21/10/2026. Outros ambientes precisam dessa
migração antes de executar a versão atualizada do módulo.

## Vínculo com atividades

Em Atividades, a categoria **Ordem de Serviço DNIT** exibe um seletor com busca
por número ou assunto da OS. O vínculo é obrigatório nessa categoria e é salvo
em `activities.os_dnit_order_id`. A criação e a edição validam tenant, contrato,
participação ativa e a permissão de visualizar OS. Trocar o contrato limpa a
seleção; trocar a categoria remove o vínculo ao salvar.

A busca retorna até 20 ordens por consulta e permite refinar o texto. Rascunhos,
OSs em análise e aprovadas podem ser vinculadas; excluídas ficam fora da busca.
O detalhe da atividade mostra um link para a OS somente quando o usuário pode
acessá-la. A migração aditiva `2026_10_21_190000_link_activities_to_os_dnit_orders`
foi aplicada localmente em 21/10/2026 e preserva todas as atividades existentes.

## Assistente Deming

O assistente reconhece OS DNIT como módulo distinto de Liberação de Serviço.
`OsDnitAssistantContext` reúne guias curtos de criação, análise Concremat,
decisão fiscal, PDFs, catálogo, rodovias, dashboard, responsáveis, permissões,
avisos e vínculo com atividades. Os guias são recuperados pelo assunto da
pergunta ou pela tela atual, dentro do orçamento de contexto já configurado.

As consultas usam os vínculos ativos e as permissões reais do OS DNIT, sem
dispensa para administradores. O resumo conta as OSs autorizadas; os detalhes
trazem até três registros recentes ou os correspondentes ao número informado.
O mesmo número pode existir em contratos diferentes. Catálogos e responsáveis
exigem suas permissões específicas e são amostras limitadas. Excluídas não
entram no contexto. O assistente distingue aprovação do fluxo, execução física
e entrega de e-mail; estes últimos não são comprovados pelo status da OS.

Mediante pedido explícito, pode preparar um formulário parcial de OS DNIT para
revisão, exigindo permissão de criar e papel Analista DNIT. Rodovias e produtos
são resolvidos no contrato; referências inexistentes são descartadas. O número
da solicitação continua automático ao salvar. Também pode preparar atividade
na categoria OS DNIT e resolver o número da OS no mesmo contrato. O usuário
abre a ação, revisa, completa e salva na tela real. O chat não salva, envia,
aprova, reprova, exclui nem dispara avisos por conta própria.

A integração foi verificada com chamadas à IA simuladas, testes de isolamento,
permissões, recuperação dos guias e preparação de formulários sem persistência.
Em 21/10/2026, uma pergunta real na interface local também confirmou a orientação
de criação, análise Concremat, decisão, devolução e bloqueio de edição após envio.

O PHP local estava sem caminho de certificados CA e recusava a conexão HTTPS.
Foi reiniciado mantendo verificação TLS, usando o bundle já instalado pelo Git,
e `curl.cainfo` / `openssl.cafile` foram configurados em `C:/php-8.5.2/php.ini`
para futuras inicializações, com cópia de segurança do arquivo anterior. Isso
segue a [configuração oficial de CA do PHP](https://www.php.net/manual/en/curl.configuration.php).
Não há configuração `verify=false` nem alteração de certificados dos ambientes
Railway ou Azure. As mudanças do assistente precisam de deploy nesses ambientes.
