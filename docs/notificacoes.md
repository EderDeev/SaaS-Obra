# Notificações do sistema

O sino mostra os avisos vinculados ao usuário autenticado. Cada aviso pode trazer o contrato e um link para o documento correspondente. A listagem é limitada aos 50 avisos mais recentes, mantendo a contagem total de não lidos.

Com a aba visível, o navegador consulta `GET /notificacoes` a cada 30 segundos. Essa consulta retorna somente o feed do usuário, sem executar novamente o controlador da página aberta. Abas ocultas pausam as consultas e atualizam o sino ao voltar. Requisições lentas não se sobrepõem; falhas temporárias preservam os avisos exibidos.

## Som

O arquivo escolhido é **New Notification 012 (363675)**, identificado como Universfield no nome do arquivo fornecido pelo usuário. O MP3 original é servido em `/audio/notification-new-012.mp3`, sem alteração, com reprodução a 50% do volume do player. Os créditos ficam em `/audio/CREDITS.txt`.

O som funciona em segundo plano, sem controles de reprodução no sino. Uma interação com a página prepara o áudio para os navegadores que exigem uma ação do usuário antes de permitir a reprodução.

O som toca uma vez por lote de novos avisos não lidos, sem tocar ao abrir a página, repetir a mesma notificação ou reproduzir avisos recebidos enquanto estava silenciado. As abas compartilham o histórico de avisos já ouvidos para evitar repetição.

## Ordem de Serviço

Envio, encaminhamento, devolução, reenvio, aprovação e finalização criam avisos para os responsáveis autorizados no contrato. O aviso interno é gravado na mesma transação do fluxo, enquanto o e-mail segue em uma fila independente. Salvar demanda ou análise sem encaminhar não cria aviso.
