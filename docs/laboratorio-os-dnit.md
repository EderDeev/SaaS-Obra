# Laboratório OS DNIT

Tenant local `teste-os-dnit`, nome **Laboratório OS DNIT**. Dados inteiramente fictícios, preservados para navegação manual. Não é teste de carga simultânea ou aferição de capacidade.

## Massa

- 4 contratos: supervisão no Acre, conservação em Rondônia, projetos no Mato Grosso e fiscalização em Goiás.
- 17 contas: um gestor com os três papéis nos quatro contratos e, em cada contrato, analista DNIT, fiscal Concremat, fiscal do contrato e leitor.
- O administrador da plataforma indicado na execução recebe vínculo nos quatro contratos para consultar o laboratório; sua senha e seus outros vínculos não são alterados.
- 80 produtos (20 por contrato), nas oito categorias, com códigos reutilizados em contratos diferentes e descrições/unidades variadas.
- 8 rodovias (duas por contrato).
- 120 OSs: 12 rascunhos, 24 em análise Concremat, 12 com o fiscal, 12 devolvidas à Concremat, 12 devolvidas ao analista e 48 aprovadas.
- 48 atividades, sendo 24 checklists; 32 atividades referenciam OSs do próprio contrato. Há cards em todas as colunas, públicos e restritos, com responsáveis, comentários e etapas concluídas.
- As datas simuladas alimentam os gráficos de vários meses. Não representam serviços reais.

## Execução

```cmd
php artisan os-dnit:seed-sandbox --viewer=ID_DO_ADMINISTRADOR
```

O comando imprime ambiente, driver, host e nome do banco antes de gravar. Só aceita PostgreSQL em loopback no ambiente `local`, ou SQLite `:memory:` no ambiente `testing`. Produção, conexões remotas e marcadores de proteção são bloqueados. Não executa migrations, resets, exclusões nem seeders destrutivos.

Todos os registros são adicionados em uma transação. Se uma validação falhar, a criação é revertida. Se o tenant marcado como laboratório já existir, o comando apenas informa os resultados originais; não duplica dados nem desfaz alterações manuais. Um slug já usado por outro tenant é rejeitado.

Contratos, usuários e vínculos formam a estrutura de demonstração. Produtos, rodovias, responsáveis, criação de OS, transições e atividades passam pelos controllers reais, com suas validações, autorização interna, numeração e eventos. Isso não substitui a suíte HTTP que verifica os middlewares.

## E-mails e acessos

Na carga, os avisos usam **ArrayTransport em memória** e a fila de e-mail é síncrona, somente no processo do comando. Nenhum job dessa carga fica para o worker normal e nenhum e-mail é transmitido a provedores. As configurações normais são restauradas ao terminar.

Operações manuais posteriores voltam a usar a configuração normal do sistema. As contas têm endereços fictícios `.test`. Não representam pessoas reais.

As senhas são aleatórias; não ficam no código nem no Git. O comando informa os caminhos privados:

- `storage/app/private/sandbox-os-dnit/{tenant_id}/acessos.md`: logins e senha do laboratório.
- `storage/app/private/sandbox-os-dnit/{tenant_id}/relatorio.json`: totais, ações e verificações, sem senha.

O gestor permite experimentar o fluxo completo. As contas por papel ajudam a verificar isolamento e responsabilidades; cada equipe acessa somente seu contrato. O leitor consulta, sem criar ou decidir.

## Verificações

A própria carga verifica os estados finais, a preservação dos produtos originais e os bloqueios por contrato, papel, versão e comentário. `OsDnitSandboxTest` executa a mesma geração exclusivamente em SQLite em memória, conferindo também preservação de dados existentes, senha do administrador, reexecução e ausência de jobs pendentes. As suítes de workflow, isolamento, produtos e referência de atividades complementam a validação HTTP.
