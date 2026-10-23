# Ordem de Serviço

O módulo anteriormente chamado OS DNIT agora aparece como **Ordem de Serviço**, com a listagem em **Ordens de Serviço**. Ele é separado de **Liberação de Serviço**. As rotas e os identificadores internos `os-dnit` continuam compatíveis com os links existentes.

## Fluxo

1. **Demanda:** o Analista DNIT descreve o serviço, seleciona contrato, lote, rodovia e trecho, informa prazo pretendido, necessidade técnica, justificativa, referência SEI e anexos. O analista não inclui produtos nem quantidades.
2. **Análise Supervisão:** a Supervisão consulta a demanda, define os produtos e as quantidades do contrato, informa prazo estimado e comentário. Pode salvar a análise ou encaminhar ao fiscal do contrato.
3. **Fiscal do contrato:** consulta a demanda e a Análise Supervisão. Aprova a demanda ou a reprova com motivo e destino da devolução. O comentário da decisão é obrigatório.
4. **Aprovada:** a execução está autorizada e aguarda a conclusão do serviço. Aprovar não finaliza a OS.
5. **Finalizada:** um fiscal do contrato cadastrado como responsável clica em **Finalizar OS** e confirma a conclusão. O sistema registra data, responsável e evento no fluxo, e comunica os envolvidos.

A devolução para Supervisão serve para revisar produtos, quantidades ou prazo. A devolução ao Analista DNIT registra problemas na demanda e permite o reenvio; o pedido original já enviado continua bloqueado para edição, conforme a regra existente. As versões anteriores permanecem no histórico.

Não existe comparação entre produtos do analista e da Supervisão. Os produtos são definidos exclusivamente pela Supervisão, inclusive nas requisições diretas ao servidor.

## Parametrização: lotes e referências SEI

Há duas abas: **Rodovias** e **Lotes de Supervisão**. Cada cadastro pertence a um tenant e contrato. Criar, editar, excluir e visualizar usam as permissões de parametrização do módulo.

Ao cadastrar um lote, informe o contrato, o nome e, opcionalmente, uma Referência SEI. Na criação da demanda, selecionar o lote preenche automaticamente a referência vinculada. O servidor também resolve a referência pelo cadastro do lote. Quando não há referência vinculada, o usuário pode informar uma manualmente.

O documento guarda uma cópia do nome do lote e da referência SEI; alterações posteriores no cadastro não modificam documentos existentes. Ao editar e salvar uma demanda ainda não enviada, os valores atuais do lote selecionado são registrados em uma nova versão. Lotes inativos não são oferecidos em novas demandas. Um lote usado por uma OS, inclusive uma OS excluída logicamente, não pode ser excluído: use a inativação.

A migração `2026_10_22_190000_add_supervision_lots_and_order_completion.php` cria o cadastro de lotes e liga os documentos antigos, preservando seus textos e referências. Se o mesmo lote de um contrato tiver referências históricas conflitantes, o cadastro fica sem referência padrão para revisão pelo administrador. OSs antigas aprovadas continuam aprovadas e precisam da ação explícita de finalização.

## Número, edição e exclusão

O número no formato `001/2026` é automático e sequencial por tenant, contrato e ano. A prévia não reserva um número; o servidor confirma a sequência ao salvar. A seleção de contrato é limitada aos vínculos ativos e às permissões do usuário, inclusive para administradores.

Salvar a abertura cria uma **Demanda**. Apenas o autor autorizado pode editar antes do primeiro envio. Depois de enviada, a demanda não pode mais ser editada, inclusive após devolução. A exclusão exige permissão e só ocorre antes do envio; é lógica e preserva número, eventos e arquivos.

## Listagem e dashboard

A listagem tem filtros automáticos e paginação de 10 itens. As abas são Todas as OSs, Demanda, Análise Supervisão, Fiscal do Contrato, Aprovadas e Finalizadas. O botão **Finalizar OS** aparece na listagem e na visualização para o fiscal autorizado quando a OS está aprovada.

O Dashboard diferencia aprovadas de finalizadas. Os filtros de contrato, período e rodovia respeitam os acessos. Aprovadas permanecem pendentes de conclusão; Finalizadas indicam que o fiscal registrou a conclusão do serviço. A evolução mensal usa eventos de envio e aprovação; a data de aprovação sozinha não comprova execução.

## PDF

O menu **Baixar PDF** oferece escopos cumulativos: solicitação do analista, solicitação + Análise Supervisão, solicitação + análises e documento completo com fluxo.

Cada etapa começa em uma página própria, com cabeçalho e identificação repetidos. A primeira contém a demanda, necessidade técnica, justificativa e anexos. Os produtos aparecem na página da Análise Supervisão. A decisão do fiscal tem página própria; quando finalizada, a OS inclui o registro de conclusão. O fluxo fica ao final do documento completo. Conteúdo longo pode continuar em páginas adicionais.

O documento usa a marca Concremat e permanece A4. O download exige autenticação e acesso ao contrato, não altera o fluxo nem cria eventos, e usa resposta privada sem cache. Não há comparação de produtos no PDF.

## Responsáveis, permissões e avisos

Os papéis são **Analista DNIT**, **Supervisão** e **Fiscal do contrato**, sempre por contrato e adicionais às permissões de acesso. A finalização exige o papel de fiscal do contrato e acesso ativo ao contrato. Não há dispensa de participação contratual para administradores.

Envio, encaminhamento, devolução, aprovação e finalização registram eventos, criam avisos no sino do sistema e enfileiram e-mails para responsáveis autorizados. Cada aviso mostra a OS, o contrato e um link para abrir a solicitação. O sino consulta o endpoint de notificações a cada 30 segundos com a aba visível; abas ocultas pausam a consulta e atualizam ao voltar. O usuário pode testar e silenciar o som pelo sino. O aviso interno é gravado na mesma transação da mudança de etapa; o envio do e-mail pode ser repetido sem duplicá-lo. Salvar a demanda ou uma análise sem encaminhar não gera aviso. O aviso usa o registro imutável do evento. Enfileirar ou enviar não comprova entrega. Alterações concorrentes são bloqueadas pelo controle de versão; transições inválidas não criam eventos duplicados.

O tour e o assistente usam os novos termos. Os rascunhos preparados pelo assistente não contêm produtos: o usuário revisa os dados da demanda na tela antes de salvar. O assistente não aprova, devolve, envia ou finaliza documentos por chat.
