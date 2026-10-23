# IC — Instrução de Campo

A IC registra uma orientação para corrigir uma ocorrência de campo antes que ela exija uma RNC. O módulo fica em **Qualidade → IC**, com os submenus **IC**, **Dashboard** e **Responsáveis**.

## Preparar os acessos

Em **Responsáveis IC**, escolha o contrato e cadastre os envolvidos como **Engenheiro Concremat**, **Responsável da Construtora** e **Cliente / acompanhamento**. Os usuários precisam de acesso ativo ao contrato. As permissões específicas da IC também estão em **Usuários** e **Permissões**, separadas das da RNC.

## Abrir e comunicar

Clique em **Criar IC**, selecione contrato, obra, empresas, disciplina e gravidade. Informe a data de abertura, a instrução e, se necessário, as coordenadas e as fotos com legendas. Não há prazo de ação corretiva nem vínculo com projetos.

Ao salvar, o sistema atribui um número sequencial por tenant e ano e mantém o documento em rascunho. Depois clique em **Comunicar IC**. É necessário ter os três tipos de responsáveis cadastrados. Depois da comunicação, a instrução original não pode mais ser editada ou excluída.

## Corrigir e verificar

A construtora usa **Informar correção** para descrever o que fez e anexar as fotos da correção. A Concremat verifica e registra um comentário, escolhendo entre resolver e encerrar, solicitar nova correção ou indicar que o problema requer uma RNC. Os comentários e as evidências anteriores permanecem no fluxo.

**Requer RNC** registra a necessidade de um novo documento. Nesta etapa, não gera uma RNC automaticamente nem altera o fluxo existente de RNC.

## Dashboard

O dashboard mostra o total de ICs, os rascunhos, as instruções aguardando correção ou verificação, as resolvidas e as que requerem RNC. Também mostra as aberturas nos últimos seis meses, os contratos com mais registros e as últimas ICs. O filtro de contrato atualiza a tela automaticamente.

A permissão **Visualizar dashboard IC** controla o menu e a rota. Os indicadores consideram somente os contratos autorizados para o usuário e não incluem rascunhos excluídos. Para acessar um documento a partir do dashboard, é necessária também a permissão **Visualizar IC** naquele contrato.

## Avisos e arquivos

Comunicação, correção, devolução e encerramento geram avisos no sistema e por e-mail para os responsáveis ativos. Os avisos são enfileirados na conexão `database`, fila `default`, junto da transação do fluxo, com tentativas de reenvio. O worker já usado pelo sistema processa essa fila; é possível inspecionar falhas com os comandos usuais de filas do Laravel. O acesso do destinatário é conferido novamente antes de enviar.

As fotos ficam no disco privado `local` e só são servidas por uma rota autenticada, que verifica tenant, contrato e permissão de visualizar IC. A exclusão do rascunho é lógica e preserva o número e o registro da operação.

## Implantação

A migração `2026_10_22_180000_create_instrucao_campo_tables.php` adiciona as quatro tabelas da IC, sem modificar as tabelas ou os registros de RNC. É necessário executá-la no processo normal de implantação antes de atender requisições com esta versão.
