# 04 · Banco de dados

## Configuração e fonte do esquema

[config/database.php](../../config/database.php) define conexões e resolve o driver a partir de `DB_CONNECTION`, URLs e ambiente. A aplicação operacional usa PostgreSQL; testes automatizados devem resolver SQLite `:memory:`. O nome histórico de uma base não deve ser usado para deduzir sua finalidade ou autorização de acesso.

As migrations em [database/migrations](../../database/migrations) são o histórico versionado. Uma migration posterior pode alterar colunas, índices, dados e relações criadas antes. O [inventário do banco](inventario-banco.md) lista declarações encontradas nesse histórico, com links para as fontes; não afirma que todas as alterações foram aplicadas em uma instalação específica.

## Núcleo relacional

| Entidade | Relação e finalidade |
| --- | --- |
| `tenants` | Diretorias: contexto de isolamento, acesso e configurações de plataforma |
| `users` | Identidade global, autenticação e indicadores administrativos |
| `tenant_users` | Associação do usuário à diretoria, papel, estado e permissões pertinentes |
| `contracts` | Contrato vinculado à diretoria, identificação, datas, valor e referências de empresas |
| `contract_participants` | Participação e permissões do usuário em determinado contrato |
| `obras` | Obras/frentes associadas ao contrato, com hierarquia e identificadores próprios |
| `empresas` / `tipo_empresas` | Empresas participantes dos contratos e sua classificação; entidade distinta da diretoria (`tenants`) |
| `disciplinas` / `trechos` | Classificações e referências utilizadas nos módulos |

As relações devem ser lidas em ambas as direções. Por exemplo, `Contract::obras()` descreve as obras do contrato; o RDO consolidado usa adicionalmente a seleção de obras da sua configuração. Não é correto tratar cada obra como um RDO independente quando o fluxo foi definido para o conjunto.

## Principais famílias de tabelas

| Domínio | Famílias e modelos |
| --- | --- |
| Atividades | `Activity`, comentários, arquivos e checklist |
| Projetos | `ProjectDocument`, versões, status, lotes de submissão, revisões, marcações, fases e responsáveis |
| Orçamento | `Orcamento`, etapas, itens, insumos, composições, analíticos e acessos |
| Medição | `BoletimMedicao`, itens, versões, aditivos e índices/competências de reajuste |
| Liberação de Serviço | `OrdemServico`, itens, análises, comentários, documentos e responsáveis |
| Ordem de Serviço | `OsDnitOrder`, itens, anexos, eventos, produtos, rodovias, lotes e responsáveis |
| RDO | Configurações, diários, seções, análises, registros de fiscalização, divergências e versões |
| Cadastros RDO | Mão de obra, equipamentos e subcontratadas associados ao contrato |
| RNC | Relatórios, fotos, proposta de ação, evidências, responsáveis e configuração de PDF/assinatura |
| IC | Instruções, fotos, anexos, eventos e responsáveis |
| GED | Documentos, versões, anexos, notas, eventos, tags e regras/contas de e-mail |
| Assinaturas | Solicitações e signatários; o subsistema usa os modelos `RdoSignatureRequest`/`RdoSignatureSigner` também para RNC |
| Mobile | Tokens e recibos `rdo_mobile_syncs`; RNC usa `mobile_local_uuid` no relatório |
| Assistente | Conversas, mensagens e uso mensal de IA |
| Infraestrutura | Jobs, falhas, batches, cache, notificações e sessões conforme configuração |

O nome do model pode divergir do nome físico. Por exemplo, `RelatorioNaoConformidade` usa `relatorio_nao_conformidades`, e `RdoConfiguracao` usa `rdo_configuracoes`. Para outras classes, confira `$table`, as migrations e o inventário em vez de pluralizar manualmente nomes em português.

## RDO: documento, obras e partes

`RdoConfiguracao` define o contrato, as obras participantes, a geração, o fluxo e as opções. `rdo_configuracao_obras` associa várias obras à configuração. `RdoDiario` representa o documento de uma data. `RdoSecaoRegistro` armazena seções por diário, obra e parte de preenchimento.

As seções são `clima`, `mao_obra`, `equipamentos`, `atividades`, `comentarios` e `fotos`. O conteúdo fica em `dados`, com estruturas próprias para cada seção. A camada da gerenciadora que analisa a construtora usa `RdoFiscalizacaoRegistro`, com atividades, clima, comentário e evidências por obra. Isso não é a mesma coisa que a gerenciadora ser a parte responsável pelo preenchimento principal.

Snapshots e registros de histórico permitem representar o estado entregue em uma versão e distinguir eventos de fluxo. Retirar “V01” da interface/PDF não significa que as estruturas de histórico foram apagadas do banco.

## JSON e snapshots

| Estrutura | Por que existe |
| --- | --- |
| Permissões em arrays | Guardar capacidades por participante/módulo |
| `RdoSecaoRegistro.dados` | Conteúdo variável de cada seção |
| `workflow_snapshot` no RDO | Preservar o fluxo recebido pelo documento na criação |
| `pdf_configuration_snapshot` no RDO | Preservar modelo e apresentação do PDF |
| `pdf_template_snapshot` na RNC | Preservar layout e definição dos campos |
| `pdf_logo_snapshot` na RNC | Preservar logos, inclusive antes do envio à assinatura |
| `model_field_values` na RNC | Guardar valores dos campos definidos no modelo |

JSON não elimina validação. Campos devem ser validados por tipo, opções, obrigatoriedade e vínculo ao modelo. Não aceite chaves arbitrárias que poderiam ser exibidas posteriormente no PDF. Ao mudar um modelo, diferencie a configuração para documentos novos dos snapshots já associados aos existentes.

## Consistência e concorrência

Operações de numeração e avanço de fluxo utilizam transações e `lockForUpdate` em pontos específicos. A sincronização mobile do RDO usa um hash de revisão do conteúdo de obra/parte; quando `is_holiday` é informado, ele participa da revisão. Um recibo de sincronização registra UUID, hash da requisição e resposta, permitindo retry sem duplicar a operação.

No RNC, o UUID mobile permite reconhecer um envio já criado, e a revisão do modelo enviada pelo app detecta mudanças de campos/layout antes da sincronização. São mecanismos distintos: não atribua à RNC a mesma implementação de recibos e hash de conteúdo usada pelo RDO.

## Arquivos e dados relacionais

Fotos e PDFs não são, como regra geral, colunas binárias do documento. O banco registra caminho, disco e metadados, e os arquivos vivem em storage ou serviço externo. A preservação precisa considerar os dois lados: restaurar só o PostgreSQL não recupera arquivos ausentes do volume ou blob storage.

Não confunda o caminho de uma evidência com uma URL pública autorizada. Cada módulo decide como servir o arquivo; anexos privados devem passar por controller e autorização. O [capítulo de integrações](11-integracoes.md) detalha os discos e a materialização de arquivos.

## Alterar o banco com segurança

Antes de uma escrita, confira ambiente, driver, host e nome do banco sem imprimir credenciais. As regras de [AGENTS.md](../../AGENTS.md) proíbem testes PHPUnit/RefreshDatabase em PostgreSQL e comandos destrutivos. Produção exige pedido explícito para a operação e backup recuperável verificado. Use migrations novas, preferencialmente apenas para frente; não edite uma migration já implantada para simular uma mudança nova.

Este portal não apresenta um comando de reset como instalação padrão. A documentação antiga continha um exemplo desse tipo; ele foi substituído no README principal pelo procedimento seguro deste manual.
