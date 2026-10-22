# Identidade SigWorks

O sistema passou a usar **SigWorks**, com o símbolo azul e a tipografia aprovados. A identidade está no login e nas telas de autenticação, no menu, no ícone da aba, no assistente, nos tutoriais, nos e-mails, nos PDFs e nas planilhas geradas.

Os PDFs de OS DNIT usam especificamente a logo Concremat (`public/brand/concremat-logo.png`) no cabeçalho. O rodapé com OS, contrato, versão, data de emissão, fuso horário e aviso sobre assinatura foi removido; a numeração de páginas permanece.

## Arquivos da marca

- `public/brand/sigworks-logo.png`: PNG original aprovado e enviado pelo usuário (`logo-sigworks.png`), preservado sem compressão, redesenho ou alteração de pixels.
- `public/brand/sigworks-mark.svg`: símbolo vetorial, usado também como ícone da aba.
- O SVG da marca completa foi preservado no acervo, mas o menu e as telas de autenticação usam o PNG aprovado.
- `resources/js/Components/SigLogo.jsx`: componente compartilhado pelas telas.

No menu e no login, o componente usa o PNG original de 2078 × 757 pixels e mantém sua proporção. Uma moldura CSS deixa apenas as margens transparentes externas fora da área visível, sem editar o arquivo nem cortar o desenho. O símbolo isolado continua disponível em SVG para o ícone da aba.

O portal independente também usa SigWorks. Para atualizá-lo, execute `python docs/portal/build_portal.py`. O pacote novo é `docs/portal/sigworks-documentacao.zip`. Capturas históricas do manual podem conservar a aparência anterior.

## Configuração por ambiente

Os valores de apresentação são:

```dotenv
APP_NAME="SigWorks"
MAIL_FROM_NAME="SigWorks"
VITE_APP_NAME="${APP_NAME}"
```

No ambiente local esses valores já foram atualizados. As configurações de exemplo, o nome do build no workflow Azure e o parâmetro APP_NAME do Container App também foram preparados para a próxima publicação. Nenhum deploy foi executado nesta alteração. Ambientes já publicados precisam receber os novos valores de apresentação e um novo build na publicação normal.

Domínios, URLs, endereços de e-mail, credenciais, banco, containers e nomes dos recursos Azure continuam os mesmos. Não altere esses identificadores para fazer uma mudança visual. Chaves internas de armazenamento no navegador e o identificador de tutoriais também foram mantidos para preservar dados e links existentes.

Como o Laravel pode derivar nomes de sessão e prefixos do APP_NAME, a configuração local preserva explicitamente os valores resolvidos anteriores em SESSION_COOKIE, CACHE_PREFIX e REDIS_PREFIX. Em outro ambiente já existente, confira e preserve os seus próprios valores antes de trocar o APP_NAME; não copie prefixos de um ambiente diferente.

## Validação

- Build do Vite concluído.
- Login conferido no navegador com a marca azul, em prévia isolada.
- 50 testes de autenticação, PDFs DNIT, assistente e notificações aprovados, com 311 verificações, usando SQLite em memória e limite PHP de 512 MB.
- Portal gerado com 25 capítulos e 65 tópicos, sem fontes ou imagens ausentes.

O PostgreSQL local estava indisponível durante a conferência visual; a prévia usou sessão em memória e não gravou no banco da aplicação.
