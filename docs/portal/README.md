# Portal de documentação SigWorks

Manual separado da aplicação, em português, para pessoas usuárias, administradoras e técnicas. O conteúdo descreve o snapshot local revisado em **21/10/2026**. Não confirma a versão publicada em nuvem.

## Abrir

Abra **[dist/index.html](dist/index.html)** no navegador. Funciona sem servidor e sem internet. A busca, os tópicos, a ampliação das imagens e a impressão funcionam localmente. Links para referências externas precisam de internet.

O HTML inclui os estilos, o JavaScript, o menu e os textos. Mesmo sem executar JavaScript, os capítulos continuam disponíveis pelo menu e pela busca nativa do navegador (Ctrl+F). Mantenha a pasta `assets` junto ao HTML para exibir as imagens.

O pacote **sigworks-documentacao.zip** contém o mesmo portal para compartilhar. Extraia a pasta inteira antes de abrir `index.html`.

- `dist/manual.md`: conteúdo em texto para leitura e edição.
- `dist/auditoria.json`: inventário das fontes, hashes, commit de base e data da geração; não contém valores de credenciais.
- `validacao.json`: resultados das verificações estruturais e da revisão visual, quando registrada.

## Atualizar o conteúdo

Na raiz do repositório, com Python 3:

```cmd
python docs/portal/build_portal.py
```

Não instala dependências, não acessa o banco e não lê `.env`. `content.py` contém o texto; `diagrams.py` gera os diagramas. As capturas são copiadas do acervo de tutoriais do projeto. O layout fica em `shell.html`, `styles.css` e `app.js`.

Se as rotas mudarem, atualize o inventário antes de gerar:

```cmd
php artisan route:list --json > docs/portal/routes-snapshot.json
python docs/portal/build_portal.py
```

Revise o texto, as referências, os nomes de configuração, as imagens e os fluxos após cada mudança relevante. O gerador verifica arquivos ausentes e imagens quebradas; ele não valida sozinho a veracidade das regras narradas.

## Prévia HTTP opcional

```cmd
python -m http.server 8765 --bind 127.0.0.1 --directory docs/portal/dist
```

Abra `http://127.0.0.1:8765`. Encerre com Ctrl+C. O servidor serve apenas esta documentação, sem autenticação e sem conexão com o sistema.

## Escopo e cuidado editorial

As explicações foram conferidas no código, configurações, rotas, workflows e documentos locais. As capturas de tutoriais são ilustrativas e podem mostrar nomenclaturas antigas. Os diagramas representam relações lógicas, não uma auditoria da infraestrutura em execução.

Chaves são descritas por **nome, finalidade e local de configuração**. Valores reais, senhas, `.env`, registros de clientes e logs de produção não entram no pacote. Não use este portal para guardar segredos.

O portal não publica código, não executa comandos operacionais e não altera permissões, contratos ou dados da aplicação.
