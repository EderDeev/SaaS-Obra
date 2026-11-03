# SigWorks — documentação técnica

Referência para desenvolvimento, manutenção e operação da aplicação web e do aplicativo mobile. Revisão do código local em **03/11/2026**. Inclui alterações ainda não publicadas: a existência de um arquivo aqui não comprova sua implantação em produção.

**Conceito de domínio:** cada `Tenant` representa uma **diretoria**. Os contratos são vinculados à diretoria; as empresas participantes dos contratos são entidades distintas, cadastradas em `empresas`. O identificador técnico `tenant` é mantido nos nomes de classes, tabelas, rotas e tokens.

## Consultar

Abra o [portal navegável](../index.html), com menu, busca local e impressão, ou leia os capítulos abaixo. O portal funciona offline, sem autenticação, CDN ou conexão com o banco. Distribua a pasta `dist` inteira ou o pacote `sigworks-tecnica.zip`.

| Capítulo | Conteúdo |
| --- | --- |
| [01 · Arquitetura](01-arquitetura.md) | Componentes, tecnologias e caminho de uma requisição |
| [02 · Pastas e arquivos](02-estrutura.md) | Onde encontrar e modificar cada parte do sistema |
| [03 · Tenants e segurança](03-tenants-seguranca.md) | Identidade, contratos, permissões e limites de acesso |
| [04 · Banco de dados](04-banco.md) | Relações, grupos de tabelas, JSON, snapshots e transações |
| [05 · Backend](05-backend.md) | Controllers, serviços, regras, jobs e notificações |
| [06 · Frontend](06-frontend.md) | React, Inertia, formulários, componentes e estilos |
| [07 · APIs](07-api.md) | Autenticação, endpoints mobile, webhook e exemplos |
| [08 · Mobile](08-mobile.md) | Expo, dados offline, modelos e sincronização |
| [09 · RDO, RNC e IC](09-qualidade-diario.md) | Fluxos, parametrizações, evidências, PDFs e assinaturas |
| [10 · Demais módulos](10-modulos.md) | OS, contratos, projetos, GED, orçamento e medição |
| [11 · Integrações](11-integracoes.md) | OpenSign, Autodesk, OCR, armazenamento, mapas e IA |
| [12 · Ambiente e operação](12-operacao.md) | Instalação segura, processos, filas, deploy e diagnóstico |
| [13 · Testes e evolução](13-testes-manutencao.md) | Verificação, mudanças de esquema e manutenção documental |
| [Inventário de rotas](inventario-rotas.md) | Métodos, URIs, ações e middleware registrados no Laravel |
| [Inventário de tabelas](inventario-banco.md) | Declarações de tabelas/colunas identificadas nas migrations |
| [Inventário de arquivos](inventario-arquivos.md) | Controllers, modelos, serviços, páginas, comandos e testes |

## Como usar

Para começar no projeto: arquitetura → estrutura → ambiente → tenants → testes. Para alterar uma funcionalidade: capítulo do módulo → arquivos citados → inventários de rotas/tabelas → testes relacionados. Para investigar um incidente: operação → integração envolvida → controller/serviço correspondente.

As referências em Markdown apontam para os arquivos reais do workspace. No portal HTML, esses links funcionam quando a documentação permanece dentro do repositório. Em um ZIP distribuído sem código-fonte, os capítulos e diagramas continuam disponíveis, mas links para o código precisam do checkout correspondente.

## Escopo e limites

- A documentação explica o código, não certifica o estado de serviços externos ou da produção.
- O dicionário de banco é um levantamento estático das migrations, não um dump, uma engenharia reversa do banco em execução ou uma lista garantida de colunas finais.
- O inventário de rotas é obtido do registro do Laravel; não executa seus endpoints.
- Nomes históricos como `os_dnit`, `ordem_servico`, `Rda` e `deming` ainda aparecem em identificadores. O significado atual está explicado nos capítulos.
- Não foram incluídos valores de `.env`, tokens, senhas, registros de clientes ou logs operacionais.
- O manual de uso anterior continua em [docs/portal](../portal/README.md); esta documentação técnica tem revisão e geração próprias.

## Atualizar e gerar

Na raiz de `obras-saas`, com PHP e dependências disponíveis, atualize a captura de rotas:

```powershell
php artisan route:list --json > docs/tecnica/fontes/rotas.json
```

O comando registra rotas; não chama controllers nem valida permissões de um usuário real. Depois:

```powershell
python docs/tecnica/build.py
```

O gerador usa Python 3 e `markdown-it-py` para converter os capítulos, criar inventários, validar referências, montar o HTML e o ZIP. Caso a biblioteca não exista no seu Python, instale a versão listada em `requirements.txt` em um ambiente virtual. O gerador não lê `.env`, não acessa banco, não faz deploy e não executa migrations.

Com Node e as dependências de desenvolvimento do projeto instaladas, `python docs/tecnica/build.py --validate-ui` também executa as verificações de busca, navegação, impressão e referências HTML em JSDOM. Essa verificação não substitui inspeção visual em navegador.

`dist/auditoria.json` registra hashes das fontes, commit de base e estado de alterações dos repositórios. `dist/validacao.json` informa resultados das verificações estruturais. Esses arquivos demonstram rastreabilidade documental, não aprovação funcional da aplicação.
