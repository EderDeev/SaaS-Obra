# 13 · Testes, evolução e manutenção documental

## Segurança dos testes

[tests/bootstrap.php](../../tests/bootstrap.php) bloqueia marcadores de produção e fixa ambiente testing, SQLite em memória, filas síncronas, sessão/cache em array e mail de teste. [phpunit.xml](../../phpunit.xml) reforça a configuração. Mesmo assim, as regras de [AGENTS.md](../../AGENTS.md) exigem conferir a conexão resolvida antes de executar testes que usam banco.

Não rode PHPUnit, Pest, `RefreshDatabase` ou `php artisan test` contra PostgreSQL. A verificação de migrations PostgreSQL no CI é uma etapa distinta em banco efêmero: executa migrations, não a suíte funcional sobre uma base operacional.

Para conferir o ambiente resolvido sem depender de um script temporário da estação, use um arquivo PHP local que carregue o bootstrap de testes, inicialize a aplicação e imprima apenas os campos permitidos:

```php
<?php
require __DIR__.'/tests/bootstrap.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection()->getConfig();
echo json_encode([
    'environment' => app()->environment(),
    'driver' => $connection['driver'],
    'host' => $connection['host'] ?? null,
    'database' => $connection['database'],
]);
```

Esse exemplo pressupõe arquivo na raiz do projeto. Prossiga somente se a saída confirmar `testing`, `sqlite`, sem host operacional e `:memory:`. Não imprima o array completo da conexão.

## Verificações por camada

| Camada | Comando após preflight / objetivo |
| --- | --- |
| Backend | `php vendor/phpunit/phpunit/phpunit tests/Feature/TenantRdoWorkflowTest.php` — regras e respostas do RDO |
| RNC | `php vendor/phpunit/phpunit/phpunit tests/Feature/TenantRncModelFieldsTest.php` — campos e preservação documental |
| Tenants mobile | `php vendor/phpunit/phpunit/phpunit tests/Feature/MobileTenantSelectionTest.php` — escopo de tokens e troca |
| Interface RDO | `npm run test:rdo-ui` — componentes e efeitos em JSDOM |
| Tutoriais | `npm run test:tutorial-ui` — diagramas, modais de etapa, menu e modelos de RDO; lê o catálogo PHP sem inicializar o banco |
| Build web | `npm run build` — resolução/compilação de assets |
| Mobile | `npx tsc --noEmit` e `npm run test:sync`, dentro de `mobile-rnc` |
| Empacotamento mobile | `npx expo export --platform android --platform ios --output-dir dist/verificacao` |
| Documentação | `python docs/tecnica/build.py` — referências, inventários e montagem do portal |

Selecione testes conforme o risco da mudança. Uma alteração de fluxo pede cenários de devolução, responsabilidade, concorrência e obras incompletas. Uma alteração visual simples pode exigir só build e inspeção. Evite criar testes que apenas repitam a implementação sem verificar uma consequência observável.

## Casos importantes de regressão

- Usuário em dois tenants, contrato sem permissão, vínculo revogado e recurso de outro ambiente.
- Quatro fluxos RDO, todas as obras preenchidas, devolução conjunta, ressalva por obra e conclusão sem aprovação.
- Fotos opcionais, anexos não-imagem, caminhos estrangeiros e retry sem duplicação.
- RNC com modelo padrão/inspeção, campos obrigatórios/seleção e mudança posterior de configuração.
- PDF por etapa sem conteúdo indevido, frentes selecionadas, imagens e assinatura na posição correta.
- Snapshots antigos preservados após trocar layout, campos ou logos.
- API mobile com revisão antiga, UUID reutilizado com conteúdo diferente, etapa travada e tenant errado.

## Mudanças de modelos e banco

Antes de acrescentar um layout, defina se a mudança é apenas visual ou exige campos/fluxo. Layout novo não deve alterar implicitamente todos os documentos existentes. Implemente catálogo, validação, preview, renderer, snapshot e, se necessário, tipos/campos do mobile e filtros do dashboard.

Uma migration deve ter estratégia para registros anteriores. Não transforme um campo opcional em obrigatório sem tratar os documentos que já existem. Uma nova FK precisa de vínculo correto e semântica de exclusão decidida; tenant/contrato não podem ser preenchidos com qualquer valor apenas para passar numa constraint.

## Como manter este portal

1. Atualize o capítulo afetado no mesmo conjunto de mudanças da funcionalidade.
2. Atualize `fontes/rotas.json` quando o registro de rotas mudar.
3. Execute `build.py` para atualizar inventários, hashes e pacote HTML.
4. Revise os avisos: arquivos ausentes, links, rotas e tabelas não resolvidas pela leitura estática.
5. Confira a leitura dos capítulos principais e a navegação/busca em tela larga e estreita quando houver acesso a navegador.

O gerador confere estrutura e referências locais, não prova que uma explicação está correta. Mudanças de regra exigem revisão do código e dos testes. Os inventários não devem listar valores de `.env`, credenciais ou dados do banco.

## Rastreabilidade

`auditoria.json` registra data, commit base, alterações locais e hashes dos arquivos consultados. Em um checkout com mudanças pendentes, o commit sozinho não descreve o conteúdo: conserve os hashes e a identificação da entrega. `validacao.json` diferencia verificações executadas de inspeção visual não realizada.

A documentação técnica foi criada a partir do workspace local. Antes de usá-la como referência de produção, compare a versão entregue com o código documentado e revise as diferenças.
