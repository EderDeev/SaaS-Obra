# Inventário de tabelas e declarações

**Levantamento estático, sem conexão com banco.** Inclui `Schema::create` e `Schema::table` com nomes literais no trecho anterior a `down`. Colunas removidas/renomeadas, SQL bruto, loops e alterações condicionais podem exigir leitura manual. Os tipos abaixo são métodos do Schema Builder; não são introspecção dos tipos finais do PostgreSQL.

Foram identificados **123 nomes de tabela** nas migrations. `change` indica alteração declarada; ocorrências repetidas preservam o histórico e não significam colunas duplicadas no banco.

## activities

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_112010_create_activities_table.php](../../database/migrations/2026_05_16_112010_create_activities_table.php) (linha 14).
- `table` — [database/migrations/2026_05_20_112000_add_soft_deletes_to_empresas_obras_and_activities.php](../../database/migrations/2026_05_20_112000_add_soft_deletes_to_empresas_obras_and_activities.php) (linha 22).
- `table` — [database/migrations/2026_06_01_090000_add_category_to_activities_table.php](../../database/migrations/2026_06_01_090000_add_category_to_activities_table.php) (linha 14).
- `table` — [database/migrations/2026_07_24_120000_add_visibility_to_activities_table.php](../../database/migrations/2026_07_24_120000_add_visibility_to_activities_table.php) (linha 11).
- `table` — [database/migrations/2026_08_07_090000_add_checklists_to_activities.php](../../database/migrations/2026_08_07_090000_add_checklists_to_activities.php) (linha 11).
- `table` — [database/migrations/2026_10_21_190000_link_activities_to_os_dnit_orders.php](../../database/migrations/2026_10_21_190000_link_activities_to_os_dnit_orders.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `activity_type` | string | — |
| `assigned_to_id` | foreignId | constrained, nullOnDelete, nullable |
| `category` | string | — |
| `completed_at` | timestamp | nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `description` | text | nullable |
| `due_date` | date | nullable |
| `id` | id | — |
| `os_dnit_order_id` | foreignId | constrained, nullOnDelete, nullable |
| `position` | unsignedInteger | — |
| `priority` | string | — |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | — |
| `updated_at` | timestamps | — |
| `visibility` | string | — |

## activity_checklist_items

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_07_090000_add_checklists_to_activities.php](../../database/migrations/2026_08_07_090000_add_checklists_to_activities.php) (linha 16).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `activity_id` | foreignId | cascadeOnDelete, constrained |
| `completed_at` | timestamp | nullable |
| `completed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `id` | id | — |
| `is_completed` | boolean | — |
| `label` | string | — |
| `position` | unsignedInteger | — |
| `updated_at` | timestamps | — |

## activity_comments

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_112030_create_activity_comments_table.php](../../database/migrations/2026_05_16_112030_create_activity_comments_table.php) (linha 14).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `activity_id` | foreignId | cascadeOnDelete, constrained |
| `body` | text | — |
| `created_at` | timestamps | — |
| `id` | id | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## activity_files

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_112040_create_activity_files_table.php](../../database/migrations/2026_05_16_112040_create_activity_files_table.php) (linha 14).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `activity_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `id` | id | — |
| `mime_type` | string | nullable |
| `name` | string | — |
| `path` | string | — |
| `size` | unsignedBigInteger | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## activity_user

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_112020_create_activity_user_table.php](../../database/migrations/2026_05_16_112020_create_activity_user_table.php) (linha 14).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `activity_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `id` | id | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## ai_conversations

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_04_090000_create_ai_assistant_conversations.php](../../database/migrations/2026_08_04_090000_create_ai_assistant_conversations.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `last_message_at` | timestamp | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | nullable |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## ai_messages

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_04_090000_create_ai_assistant_conversations.php](../../database/migrations/2026_08_04_090000_create_ai_assistant_conversations.php) (linha 22).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `content` | text | — |
| `conversation_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `id` | id | — |
| `model` | string | nullable |
| `role` | string | — |
| `sources` | json | nullable |
| `updated_at` | timestamps | — |
| `usage` | json | nullable |

## ai_monthly_usages

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_05_120000_add_ai_quotas_and_monthly_usage.php](../../database/migrations/2026_08_05_120000_add_ai_quotas_and_monthly_usage.php) (linha 19).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `cached_input_tokens` | unsignedBigInteger | — |
| `created_at` | timestamps | — |
| `id` | id | — |
| `input_tokens` | unsignedBigInteger | — |
| `output_tokens` | unsignedBigInteger | — |
| `period` | date | — |
| `requests_count` | unsignedInteger | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `total_tokens` | unsignedBigInteger | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## boletins_medicao

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_18_160000_create_boletins_medicao_table.php](../../database/migrations/2026_06_18_160000_create_boletins_medicao_table.php) (linha 11).
- `table` — [database/migrations/2026_07_28_180000_scope_boletim_sequence_to_contract.php](../../database/migrations/2026_07_28_180000_scope_boletim_sequence_to_contract.php) (linha 12).
- `table` — [database/migrations/2026_07_28_180000_scope_boletim_sequence_to_contract.php](../../database/migrations/2026_07_28_180000_scope_boletim_sequence_to_contract.php) (linha 37).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `codigo` | string | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `periodo` | date | — |
| `sequencial` | unsignedInteger | — |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo` | string | — |
| `updated_at` | timestamps | — |

## cache

**Migrations relacionadas:**

- `create` — [database/migrations/0001_01_01_000001_create_cache_table.php](../../database/migrations/0001_01_01_000001_create_cache_table.php) (linha 14).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `expiration` | bigInteger | index |
| `key` | string | — |
| `value` | mediumText | — |

## cache_locks

**Migrations relacionadas:**

- `create` — [database/migrations/0001_01_01_000001_create_cache_table.php](../../database/migrations/0001_01_01_000001_create_cache_table.php) (linha 20).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `expiration` | bigInteger | index |
| `key` | string | — |
| `owner` | string | — |

## contract_additives

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_10_120000_create_contract_additives_table.php](../../database/migrations/2026_07_10_120000_create_contract_additives_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `amount` | decimal | nullable |
| `attachment_mime_type` | string | nullable |
| `attachment_original_name` | string | — |
| `attachment_path` | string | — |
| `attachment_size` | unsignedBigInteger | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `deadline_days` | unsignedInteger | nullable |
| `id` | id | — |
| `motivation` | text | — |
| `new_ends_at` | date | nullable |
| `new_total_value` | decimal | nullable |
| `previous_ends_at` | date | nullable |
| `previous_total_value` | decimal | nullable |
| `sequence_number` | unsignedInteger | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | — |
| `type` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## contract_participants

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_15_151030_create_contract_participants_table.php](../../database/migrations/2026_05_15_151030_create_contract_participants_table.php) (linha 14).
- `table` — [database/migrations/2026_05_20_111000_add_activity_permissions_to_contract_participants_table.php](../../database/migrations/2026_05_20_111000_add_activity_permissions_to_contract_participants_table.php) (linha 13).
- `table` — [database/migrations/2026_05_21_090000_add_soft_deletes_to_contract_participants_table.php](../../database/migrations/2026_05_21_090000_add_soft_deletes_to_contract_participants_table.php) (linha 11).
- `table` — [database/migrations/2026_05_22_091000_add_project_permissions_to_users_and_participants.php](../../database/migrations/2026_05_22_091000_add_project_permissions_to_users_and_participants.php) (linha 17).
- `table` — [database/migrations/2026_08_05_140000_add_documentation_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_140000_add_documentation_permissions_to_users_and_participants.php) (linha 17).
- `table` — [database/migrations/2026_08_05_150000_add_diario_obra_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_150000_add_diario_obra_permissions_to_users_and_participants.php) (linha 17).
- `table` — [database/migrations/2026_08_05_160000_add_ordem_servico_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_160000_add_ordem_servico_permissions_to_users_and_participants.php) (linha 17).
- `table` — [database/migrations/2026_08_05_170000_add_medicao_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_170000_add_medicao_permissions_to_users_and_participants.php) (linha 16).
- `table` — [database/migrations/2026_08_05_180000_add_contract_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_180000_add_contract_permissions_to_users_and_participants.php) (linha 16).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `activity_permissions` | json | nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `contract_permissions` | json | nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `diario_obra_permissions` | json | nullable |
| `documentation_permissions` | json | nullable |
| `id` | id | — |
| `invited_at` | timestamp | nullable |
| `joined_at` | timestamp | nullable |
| `medicao_permissions` | json | nullable |
| `ordem_servico_permissions` | json | nullable |
| `project_permissions` | json | nullable |
| `role` | string | — |
| `side` | string | — |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## contracts

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_15_151020_create_contracts_table.php](../../database/migrations/2026_05_15_151020_create_contracts_table.php) (linha 14).
- `table` — [database/migrations/2026_05_16_103000_add_parametrizacao_links_to_contracts.php](../../database/migrations/2026_05_16_103000_add_parametrizacao_links_to_contracts.php) (linha 14).
- `table` — [database/migrations/2026_05_16_104000_add_currency_to_contracts.php](../../database/migrations/2026_05_16_104000_add_currency_to_contracts.php) (linha 14).
- `table` — [database/migrations/2026_05_16_105000_add_cliente_tipo_and_fiscalizadora_to_contracts.php](../../database/migrations/2026_05_16_105000_add_cliente_tipo_and_fiscalizadora_to_contracts.php) (linha 23).
- `table` — [database/migrations/2026_05_16_110000_add_location_to_contracts.php](../../database/migrations/2026_05_16_110000_add_location_to_contracts.php) (linha 14).
- `table` — [database/migrations/2026_07_10_130000_add_base_document_to_contracts_table.php](../../database/migrations/2026_07_10_130000_add_base_document_to_contracts_table.php) (linha 11).
- `table` — [database/migrations/2026_07_28_120000_add_measurement_mode_to_contracts_and_folhas_rosto.php](../../database/migrations/2026_07_28_120000_add_measurement_mode_to_contracts_and_folhas_rosto.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `base_document_mime_type` | string | nullable |
| `base_document_original_name` | string | nullable |
| `base_document_path` | string | nullable |
| `base_document_size` | unsignedBigInteger | — |
| `city` | string | nullable |
| `client_company_name` | string | nullable |
| `cliente_empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `code` | string | — |
| `construtora_empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `contractor_company_name` | string | nullable |
| `created_at` | timestamps | — |
| `currency` | string | — |
| `description` | text | nullable |
| `ends_at` | date | nullable |
| `fiscalizadora_empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `measurement_mode` | string | nullable |
| `name` | string | — |
| `obra_id` | foreignId | constrained, nullOnDelete, nullable |
| `starts_at` | date | nullable |
| `state` | string | nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `total_value` | decimal | nullable |
| `updated_at` | timestamps | — |

## disciplinas

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_22_090000_create_disciplinas_table.php](../../database/migrations/2026_05_22_090000_create_disciplinas_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `cor` | string | — |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `descricao` | text | nullable |
| `id` | id | — |
| `nome` | string | — |
| `sigla` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## empresas

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_100010_create_empresas_table.php](../../database/migrations/2026_05_16_100010_create_empresas_table.php) (linha 14).
- `table` — [database/migrations/2026_05_16_102000_add_contract_id_to_empresas_and_obras.php](../../database/migrations/2026_05_16_102000_add_contract_id_to_empresas_and_obras.php) (linha 14).
- `table` — [database/migrations/2026_05_19_102000_add_logo_path_to_empresas_table.php](../../database/migrations/2026_05_19_102000_add_logo_path_to_empresas_table.php) (linha 11).
- `table` — [database/migrations/2026_05_20_112000_add_soft_deletes_to_empresas_obras_and_activities.php](../../database/migrations/2026_05_20_112000_add_soft_deletes_to_empresas_obras_and_activities.php) (linha 14).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `cnpj` | string | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained, nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `logo_path` | string | nullable |
| `nome` | string | — |
| `sigla` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo_empresa_id` | foreignId | constrained |
| `updated_at` | timestamps | — |

## failed_jobs

**Migrations relacionadas:**

- `create` — [database/migrations/0001_01_01_000002_create_jobs_table.php](../../database/migrations/0001_01_01_000002_create_jobs_table.php) (linha 37).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `connection` | string | — |
| `exception` | longText | — |
| `failed_at` | timestamp | — |
| `id` | id | — |
| `payload` | longText | — |
| `queue` | string | — |
| `uuid` | string | unique |

## folha_rosto_analise_responsaveis

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_18_180000_create_folha_rosto_analise_responsaveis_table.php](../../database/migrations/2026_06_18_180000_create_folha_rosto_analise_responsaveis_table.php) (linha 11).
- `table` — [database/migrations/2026_06_18_181000_simplify_folha_rosto_analise_responsaveis_scope.php](../../database/migrations/2026_06_18_181000_simplify_folha_rosto_analise_responsaveis_scope.php) (linha 11).
- `table` — [database/migrations/2026_06_18_181000_simplify_folha_rosto_analise_responsaveis_scope.php](../../database/migrations/2026_06_18_181000_simplify_folha_rosto_analise_responsaveis_scope.php) (linha 16).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, change, constrained, nullable |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `etapa` | string | — |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, change, constrained, nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## folha_rosto_analises

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_19_090000_create_folha_rosto_analises_tables.php](../../database/migrations/2026_06_19_090000_create_folha_rosto_analises_tables.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `comentario_geral` | text | nullable |
| `created_at` | timestamps | — |
| `folha_rosto_id` | foreignId | cascadeOnDelete, constrained |
| `id` | id | — |
| `setor` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## folha_rosto_fluxo_historicos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_19_110000_create_folha_rosto_fluxo_historicos_table.php](../../database/migrations/2026_06_19_110000_create_folha_rosto_fluxo_historicos_table.php) (linha 11).
- `table` — [database/migrations/2026_08_07_110000_add_responsaveis_snapshot_to_folha_rosto_fluxo_historicos.php](../../database/migrations/2026_08_07_110000_add_responsaveis_snapshot_to_folha_rosto_fluxo_historicos.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `acao` | string | — |
| `created_at` | timestamps | — |
| `folha_rosto_id` | foreignId | cascadeOnDelete, constrained |
| `id` | id | — |
| `motivo` | text | nullable |
| `responsaveis_snapshot` | json | nullable |
| `status_destino` | string | — |
| `status_origem` | string | nullable |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## folha_rosto_item_analises

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_19_090000_create_folha_rosto_analises_tables.php](../../database/migrations/2026_06_19_090000_create_folha_rosto_analises_tables.php) (linha 22).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `comentario` | text | nullable |
| `created_at` | timestamps | — |
| `folha_rosto_analise_id` | foreignId | cascadeOnDelete, constrained |
| `folha_rosto_item_id` | foreignId | cascadeOnDelete, constrained |
| `id` | id | — |
| `quantidade_aprovada` | decimal | nullable |
| `setor` | string | — |
| `updated_at` | timestamps | — |

## folha_rosto_itens

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_18_140000_create_folhas_rosto_tables.php](../../database/migrations/2026_06_18_140000_create_folhas_rosto_tables.php) (linha 30).
- `table` — [database/migrations/2026_06_19_122000_add_analysis_flags_to_folha_rosto_itens_table.php](../../database/migrations/2026_06_19_122000_add_analysis_flags_to_folha_rosto_itens_table.php) (linha 11).
- `table` — [database/migrations/2026_07_28_120000_add_measurement_mode_to_contracts_and_folhas_rosto.php](../../database/migrations/2026_07_28_120000_add_measurement_mode_to_contracts_and_folhas_rosto.php) (linha 20).
- `table` — [database/migrations/2026_07_28_120000_add_measurement_mode_to_contracts_and_folhas_rosto.php](../../database/migrations/2026_07_28_120000_add_measurement_mode_to_contracts_and_folhas_rosto.php) (linha 39).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `folha_rosto_id` | foreignId | cascadeOnDelete, constrained |
| `id` | id | — |
| `medicao_item_id` | foreignId | constrained, nullable |
| `ordem_servico_item_id` | foreignId | cascadeOnDelete, change, constrained, nullable |
| `precisa_analise_qualidade` | boolean | — |
| `precisa_analise_topografica` | boolean | — |
| `quantidade_pleiteada` | decimal | — |
| `updated_at` | timestamps | — |
| `valor_pleiteado` | decimal | — |

## folhas_rosto

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_18_140000_create_folhas_rosto_tables.php](../../database/migrations/2026_06_18_140000_create_folhas_rosto_tables.php) (linha 11).
- `table` — [database/migrations/2026_06_18_150000_add_memoria_calculo_to_folhas_rosto_table.php](../../database/migrations/2026_06_18_150000_add_memoria_calculo_to_folhas_rosto_table.php) (linha 11).
- `table` — [database/migrations/2026_06_18_160000_create_boletins_medicao_table.php](../../database/migrations/2026_06_18_160000_create_boletins_medicao_table.php) (linha 29).
- `table` — [database/migrations/2026_06_18_170000_add_construtora_to_folhas_rosto_table.php](../../database/migrations/2026_06_18_170000_add_construtora_to_folhas_rosto_table.php) (linha 11).
- `table` — [database/migrations/2026_06_19_120000_add_submitted_for_analysis_at_to_folhas_rosto_table.php](../../database/migrations/2026_06_19_120000_add_submitted_for_analysis_at_to_folhas_rosto_table.php) (linha 12).
- `table` — [database/migrations/2026_07_28_120000_add_measurement_mode_to_contracts_and_folhas_rosto.php](../../database/migrations/2026_07_28_120000_add_measurement_mode_to_contracts_and_folhas_rosto.php) (linha 16).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `boletim_medicao_id` | foreignId | constrained, nullOnDelete, nullable |
| `codigo` | string | — |
| `comentario` | text | — |
| `construtora_empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `memoria_calculo_mime_type` | string | nullable |
| `memoria_calculo_nome_original` | string | nullable |
| `memoria_calculo_path` | string | nullable |
| `memoria_calculo_size` | unsignedBigInteger | nullable |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `ordem_servico_id` | foreignId | cascadeOnDelete, change, constrained, nullable |
| `sequencial` | unsignedInteger | — |
| `status` | string | — |
| `submitted_for_analysis_at` | timestamp | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## ged_correspondents

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_02_140000_create_ged_tables.php](../../database/migrations/2026_07_02_140000_create_ged_tables.php) (linha 37).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `document` | string | nullable |
| `email` | string | nullable |
| `empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `matching_rules` | json | nullable |
| `name` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## ged_document_attachments

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_13_160000_create_ged_document_attachments_table.php](../../database/migrations/2026_07_13_160000_create_ged_document_attachments_table.php) (linha 11).
- `table` — [database/migrations/2026_07_13_170000_add_ocr_fields_to_ged_document_attachments.php](../../database/migrations/2026_07_13_170000_add_ocr_fields_to_ged_document_attachments.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `archive_path` | string | nullable |
| `checksum` | string | index |
| `created_at` | timestamps | — |
| `document_id` | foreignId | cascadeOnDelete, constrained |
| `extension` | string | nullable |
| `extracted_text` | longText | nullable |
| `id` | id | — |
| `mime_type` | string | nullable |
| `notes` | text | nullable |
| `ocr_metadata` | json | nullable |
| `ocr_status` | string | nullable |
| `original_filename` | string | — |
| `page_count` | unsignedInteger | nullable |
| `path` | string | — |
| `processed_at` | timestamp | nullable |
| `size_bytes` | unsignedBigInteger | — |
| `storage_disk` | string | — |
| `title` | string | nullable |
| `updated_at` | timestamps | — |
| `uploaded_by_id` | foreignId | constrained, nullOnDelete, nullable |

## ged_document_events

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_03_101000_create_ged_document_events_table.php](../../database/migrations/2026_07_03_101000_create_ged_document_events_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `actor_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `description` | text | nullable |
| `document_id` | foreignId | cascadeOnDelete, constrained |
| `event_type` | string | index |
| `id` | id | — |
| `properties` | json | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | — |
| `updated_at` | timestamps | — |

## ged_document_notes

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_03_102000_create_ged_document_notes_table.php](../../database/migrations/2026_07_03_102000_create_ged_document_notes_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `body` | text | — |
| `created_at` | timestamps | — |
| `document_id` | foreignId | cascadeOnDelete, constrained |
| `id` | id | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## ged_document_tag

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_02_140000_create_ged_tables.php](../../database/migrations/2026_07_02_140000_create_ged_tables.php) (linha 86).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `document_id` | foreignId | cascadeOnDelete, constrained |
| `id` | id | — |
| `tag_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## ged_document_types

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_02_140000_create_ged_tables.php](../../database/migrations/2026_07_02_140000_create_ged_tables.php) (linha 11).
- `table` — [database/migrations/2026_07_03_090000_add_contract_to_ged_document_types.php](../../database/migrations/2026_07_03_090000_add_contract_to_ged_document_types.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `description` | string | nullable |
| `id` | id | — |
| `matching_rules` | json | nullable |
| `name` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## ged_document_versions

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_02_140000_create_ged_tables.php](../../database/migrations/2026_07_02_140000_create_ged_tables.php) (linha 95).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `checksum` | string | — |
| `created_at` | timestamps | — |
| `document_id` | foreignId | cascadeOnDelete, constrained |
| `id` | id | — |
| `mime_type` | string | nullable |
| `notes` | text | nullable |
| `original_filename` | string | — |
| `path` | string | — |
| `size_bytes` | unsignedBigInteger | — |
| `storage_disk` | string | — |
| `updated_at` | timestamps | — |
| `uploaded_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `version_number` | unsignedInteger | — |

## ged_documents

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_02_140000_create_ged_tables.php](../../database/migrations/2026_07_02_140000_create_ged_tables.php) (linha 52).
- `table` — [database/migrations/2026_07_03_100000_add_sequence_to_ged_documents.php](../../database/migrations/2026_07_03_100000_add_sequence_to_ged_documents.php) (linha 12).
- `table` — [database/migrations/2026_07_03_100000_add_sequence_to_ged_documents.php](../../database/migrations/2026_07_03_100000_add_sequence_to_ged_documents.php) (linha 47).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `archive_path` | string | nullable |
| `checksum` | string | index |
| `contract_id` | foreignId | constrained, nullOnDelete, nullable |
| `correspondent_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `description` | text | nullable |
| `document_date` | date | nullable |
| `document_number` | string | nullable |
| `document_type_id` | foreignId | constrained, nullOnDelete, nullable |
| `extension` | string | nullable |
| `extracted_text` | longText | nullable |
| `id` | id | — |
| `metadata` | json | nullable |
| `mime_type` | string | nullable |
| `obra_id` | foreignId | constrained, nullOnDelete, nullable |
| `original_filename` | string | — |
| `original_path` | string | — |
| `page_count` | unsignedInteger | nullable |
| `processed_at` | timestamp | nullable |
| `sequence_number` | unsignedInteger | nullable |
| `sequence_year` | unsignedSmallInteger | nullable |
| `size_bytes` | unsignedBigInteger | — |
| `status` | string | index |
| `storage_disk` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `thumbnail_path` | string | nullable |
| `title` | string | — |
| `updated_at` | timestamps | — |
| `uploaded_by_id` | foreignId | constrained, nullOnDelete, nullable |

## ged_email_accounts

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_08_210000_create_ged_email_tables.php](../../database/migrations/2026_07_08_210000_create_ged_email_tables.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `email` | string | — |
| `encryption` | string | — |
| `host` | string | — |
| `id` | id | — |
| `is_active` | boolean | — |
| `last_checked_at` | timestamp | nullable |
| `last_error` | text | nullable |
| `mailbox` | string | — |
| `move_to` | string | nullable |
| `name` | string | — |
| `password` | text | nullable |
| `port` | unsignedInteger | — |
| `post_action` | string | — |
| `settings` | json | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `username` | string | — |

## ged_email_processed_messages

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_09_100000_expand_ged_email_rules_and_logs.php](../../database/migrations/2026_07_09_100000_expand_ged_email_rules_and_logs.php) (linha 57).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `account_id` | foreignId | cascadeOnDelete, constrained |
| `attachments_count` | unsignedInteger | — |
| `created_at` | timestamps | — |
| `document_id` | foreignId | constrained, nullOnDelete, nullable |
| `duplicate_count` | unsignedInteger | — |
| `error` | text | nullable |
| `from` | string | nullable |
| `id` | id | — |
| `imported_count` | unsignedInteger | — |
| `message_id` | string | nullable |
| `message_uid` | string | nullable |
| `metadata` | json | nullable |
| `processed_at` | timestamp | nullable |
| `received_at` | timestamp | nullable |
| `rule_id` | foreignId | constrained, nullOnDelete, nullable |
| `status` | string | — |
| `subject` | string | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## ged_email_rules

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_08_210000_create_ged_email_tables.php](../../database/migrations/2026_07_08_210000_create_ged_email_tables.php) (linha 36).
- `table` — [database/migrations/2026_07_09_100000_expand_ged_email_rules_and_logs.php](../../database/migrations/2026_07_09_100000_expand_ged_email_rules_and_logs.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `account_id` | foreignId | cascadeOnDelete, constrained |
| `assign_owner_from_rule` | boolean | — |
| `attachment_name_contains` | string | nullable |
| `attachment_type` | string | — |
| `body_contains` | string | nullable |
| `consume_attachments` | boolean | — |
| `consume_scope` | string | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `correspondent_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `document_type_id` | foreignId | constrained, nullOnDelete, nullable |
| `exclude_attachment_patterns` | string | nullable |
| `from_contains` | string | nullable |
| `id` | id | — |
| `include_attachment_patterns` | string | nullable |
| `is_active` | boolean | — |
| `mailbox` | string | — |
| `max_age_days` | unsignedInteger | nullable |
| `name` | string | — |
| `pdf_layout` | string | — |
| `post_action` | string | — |
| `priority` | unsignedInteger | — |
| `subject_contains` | string | nullable |
| `tag_ids` | json | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title_source` | string | — |
| `to_contains` | string | nullable |
| `updated_at` | timestamps | — |

## ged_tags

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_02_140000_create_ged_tables.php](../../database/migrations/2026_07_02_140000_create_ged_tables.php) (linha 23).
- `table` — [database/migrations/2026_07_03_091000_add_contract_to_ged_tags.php](../../database/migrations/2026_07_03_091000_add_contract_to_ged_tags.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `color` | string | — |
| `contract_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `is_inbox` | boolean | — |
| `matching_rules` | json | nullable |
| `name` | string | — |
| `parent_id` | foreignId | constrained, nullOnDelete, nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## instrucao_campo_attachments

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_23_150000_add_quality_subjects_and_ic_attachments.php](../../database/migrations/2026_10_23_150000_add_quality_subjects_and_ic_attachments.php) (linha 13).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `event_id` | foreignId | constrained |
| `id` | id | — |
| `instrucao_campo_id` | foreignId | constrained |
| `mime_type` | string | — |
| `original_name` | string | — |
| `path` | string | — |
| `size_bytes` | unsignedBigInteger | — |
| `tenant_id` | foreignId | constrained |
| `updated_at` | timestamps | — |

## instrucao_campo_events

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_22_180000_create_instrucao_campo_tables.php](../../database/migrations/2026_10_22_180000_create_instrucao_campo_tables.php) (linha 50).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `comment` | text | nullable |
| `created_at` | timestamps | — |
| `id` | id | — |
| `instrucao_campo_id` | foreignId | constrained |
| `tenant_id` | foreignId | constrained |
| `type` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained |

## instrucao_campo_photos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_22_180000_create_instrucao_campo_tables.php](../../database/migrations/2026_10_22_180000_create_instrucao_campo_tables.php) (linha 59).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `comment` | text | nullable |
| `created_at` | timestamps | — |
| `event_id` | foreignId | constrained, nullable |
| `id` | id | — |
| `instrucao_campo_id` | foreignId | constrained |
| `mime_type` | string | — |
| `original_name` | string | — |
| `path` | string | — |
| `position` | unsignedInteger | — |
| `tenant_id` | foreignId | constrained |
| `updated_at` | timestamps | — |

## instrucao_campo_responsaveis

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_22_180000_create_instrucao_campo_tables.php](../../database/migrations/2026_10_22_180000_create_instrucao_campo_tables.php) (linha 37).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullable |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `permissions` | json | nullable |
| `responsibility_type` | string | — |
| `status` | string | — |
| `tenant_id` | foreignId | constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained |

## instrucoes_campo

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_22_180000_create_instrucao_campo_tables.php](../../database/migrations/2026_10_22_180000_create_instrucao_campo_tables.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `closed_at` | timestamp | nullable |
| `closed_by_id` | foreignId | constrained, nullable |
| `closing_comment` | text | nullable |
| `contract_id` | foreignId | constrained |
| `contratada_empresa_id` | foreignId | constrained |
| `contratante_empresa_id` | foreignId | constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained |
| `deleted_at` | softDeletes | — |
| `disciplina_id` | foreignId | constrained |
| `gravidade` | string | — |
| `id` | id | — |
| `instrucao` | text | — |
| `latitude` | decimal | nullable |
| `longitude` | decimal | nullable |
| `notified_at` | timestamp | nullable |
| `obra_id` | foreignId | constrained |
| `opened_at` | date | — |
| `sequence_number` | unsignedInteger | — |
| `sequence_year` | unsignedSmallInteger | — |
| `status` | string | — |
| `tenant_id` | foreignId | constrained |
| `updated_at` | timestamps | — |

## job_batches

**Migrations relacionadas:**

- `create` — [database/migrations/0001_01_01_000002_create_jobs_table.php](../../database/migrations/0001_01_01_000002_create_jobs_table.php) (linha 24).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `cancelled_at` | integer | nullable |
| `created_at` | integer | — |
| `failed_job_ids` | longText | — |
| `failed_jobs` | integer | — |
| `finished_at` | integer | nullable |
| `id` | string | — |
| `name` | string | — |
| `options` | mediumText | nullable |
| `pending_jobs` | integer | — |
| `total_jobs` | integer | — |

## jobs

**Migrations relacionadas:**

- `create` — [database/migrations/0001_01_01_000002_create_jobs_table.php](../../database/migrations/0001_01_01_000002_create_jobs_table.php) (linha 14).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `attempts` | unsignedSmallInteger | — |
| `available_at` | unsignedInteger | — |
| `created_at` | unsignedInteger | — |
| `id` | id | — |
| `payload` | longText | — |
| `queue` | string | index |
| `reserved_at` | unsignedInteger | nullable |

## medicao_indice_reajuste_competencias

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_113000_create_medicao_indice_reajuste_competencias_table.php](../../database/migrations/2026_06_17_113000_create_medicao_indice_reajuste_competencias_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `competencia` | date | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `data_publicacao` | date | nullable |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `medicao_indice_reajuste_id` | foreignId | cascadeOnDelete, constrained |
| `observacao` | text | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `valor_indice` | decimal | — |

## medicao_indices_reajuste

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_110000_create_medicao_indices_reajuste_table.php](../../database/migrations/2026_06_17_110000_create_medicao_indices_reajuste_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `codigo` | string | nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `data_atual` | date | — |
| `data_base` | date | — |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `indice_atual` | decimal | — |
| `indice_base` | decimal | — |
| `nome` | string | — |
| `observacao` | text | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## medicao_item_additive_items

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_090000_create_medicao_item_additives_tables.php](../../database/migrations/2026_06_17_090000_create_medicao_item_additives_tables.php) (linha 54).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `additive_id` | foreignId | cascadeOnDelete, constrained |
| `banco` | string | nullable |
| `codigo` | string | nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `descricao` | text | nullable |
| `id` | id | — |
| `item` | string | nullable |
| `medicao_item_id` | foreignId | constrained, nullOnDelete, nullable |
| `medicao_item_version_id` | foreignId | constrained, nullOnDelete, nullable |
| `meta` | json | nullable |
| `quantidade_anterior` | decimal | nullable |
| `quantidade_nova` | decimal | nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `unidade` | string | nullable |
| `updated_at` | timestamps | — |
| `valor_com_bdi_anterior` | decimal | nullable |
| `valor_com_bdi_novo` | decimal | nullable |
| `valor_total_anterior` | decimal | nullable |
| `valor_total_novo` | decimal | nullable |
| `valor_unitario_anterior` | decimal | nullable |
| `valor_unitario_novo` | decimal | nullable |

## medicao_item_additives

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_090000_create_medicao_item_additives_tables.php](../../database/migrations/2026_06_17_090000_create_medicao_item_additives_tables.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `applied_at` | timestamp | nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `effective_at` | timestamp | nullable |
| `id` | id | — |
| `meta` | json | nullable |
| `number` | unsignedInteger | — |
| `reason` | text | nullable |
| `source_orcamento_id` | foreignId | constrained, nullOnDelete, nullable |
| `source_type` | string | — |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | — |
| `updated_at` | timestamps | — |

## medicao_item_reajuste_indices

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_120000_create_medicao_item_reajuste_indices_table.php](../../database/migrations/2026_06_17_120000_create_medicao_item_reajuste_indices_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `indice_codigo` | string | nullable |
| `item_codigo` | string | nullable |
| `medicao_indice_reajuste_id` | foreignId | cascadeOnDelete, constrained |
| `medicao_item_id` | foreignId | cascadeOnDelete, constrained |
| `source_type` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## medicao_item_versions

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_090000_create_medicao_item_additives_tables.php](../../database/migrations/2026_06_17_090000_create_medicao_item_additives_tables.php) (linha 32).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `additive_id` | foreignId | constrained, nullOnDelete, nullable |
| `change_type` | string | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `medicao_item_id` | foreignId | cascadeOnDelete, constrained |
| `quantidade_prevista` | decimal | — |
| `snapshot` | json | nullable |
| `starts_at` | timestamp | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `valor_com_bdi` | decimal | — |
| `valor_total` | decimal | — |
| `valor_unitario` | decimal | — |
| `version_label` | string | — |
| `version_number` | unsignedInteger | — |

## medicao_itens

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_15_090000_create_medicao_itens_table.php](../../database/migrations/2026_06_15_090000_create_medicao_itens_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `banco` | string | nullable |
| `codigo` | string | nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `descricao` | text | — |
| `id` | id | — |
| `item` | string | nullable |
| `item_type` | string | — |
| `meta` | json | nullable |
| `nivel` | unsignedSmallInteger | — |
| `quantidade_prevista` | decimal | — |
| `source_orcamento_etapa_id` | foreignId | constrained, nullOnDelete, nullable |
| `source_orcamento_id` | foreignId | constrained, nullOnDelete, nullable |
| `source_orcamento_item_id` | foreignId | constrained, nullOnDelete, nullable |
| `source_type` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `unidade` | string | nullable |
| `updated_at` | timestamps | — |
| `valor_com_bdi` | decimal | — |
| `valor_total` | decimal | — |
| `valor_unitario` | decimal | — |

## mobile_api_tokens

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_22_150000_create_mobile_api_tokens_table.php](../../database/migrations/2026_06_22_150000_create_mobile_api_tokens_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `expires_at` | timestamp | nullable |
| `id` | id | — |
| `last_used_at` | timestamp | nullable |
| `name` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `token_hash` | string | unique |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## notifications

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_112000_create_notifications_table.php](../../database/migrations/2026_05_16_112000_create_notifications_table.php) (linha 14).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `data` | json | — |
| `id` | uuid | — |
| `read_at` | timestamp | nullable |
| `type` | string | — |
| `updated_at` | timestamps | — |

## obras

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_101000_create_obras_table.php](../../database/migrations/2026_05_16_101000_create_obras_table.php) (linha 14).
- `table` — [database/migrations/2026_05_16_102000_add_contract_id_to_empresas_and_obras.php](../../database/migrations/2026_05_16_102000_add_contract_id_to_empresas_and_obras.php) (linha 21).
- `table` — [database/migrations/2026_05_20_112000_add_soft_deletes_to_empresas_obras_and_activities.php](../../database/migrations/2026_05_20_112000_add_soft_deletes_to_empresas_obras_and_activities.php) (linha 18).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `codigo` | string | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained, nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `nome` | string | — |
| `obra_pai_id` | foreignId | constrained, nullOnDelete, nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo` | string | — |
| `updated_at` | timestamps | — |

## orcamento_acessos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_30_090000_add_budget_permissions_and_orcamento_accesses.php](../../database/migrations/2026_07_30_090000_add_budget_permissions_and_orcamento_accesses.php) (linha 28).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `access_level` | string | — |
| `created_at` | timestamps | — |
| `granted_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `orcamento_id` | foreignId | cascadeOnDelete, constrained |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## orcamento_composicao_analitico_items

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_03_140000_create_orcamento_composicao_analitico_items_table.php](../../database/migrations/2026_06_03_140000_create_orcamento_composicao_analitico_items_table.php) (linha 11).
- `table` — [database/migrations/2026_06_11_150000_add_sicro3_fields_to_orcamento_composicao_analitico_items_table.php](../../database/migrations/2026_06_11_150000_add_sicro3_fields_to_orcamento_composicao_analitico_items_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `codigo_composicao` | string | — |
| `codigo_item` | string | — |
| `codigo_item_referenciado` | string | nullable |
| `coeficiente` | decimal | — |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `data_referencia` | date | nullable |
| `deleted_at` | softDeletes | — |
| `descricao_composicao` | text | nullable |
| `descricao_item` | text | nullable |
| `fator_influencia_chuvas` | decimal | nullable |
| `grupo` | string | nullable |
| `id` | id | — |
| `is_global` | boolean | — |
| `modelo` | string | — |
| `producao_equipe` | decimal | nullable |
| `raw_payload` | json | nullable |
| `secao` | string | nullable |
| `tenant_id` | foreignId | constrained, nullOnDelete, nullable |
| `tipo_item` | string | — |
| `tipo_transporte` | string | nullable |
| `uf` | string | nullable |
| `unidade` | string | nullable |
| `unidade_composicao` | string | nullable |
| `updated_at` | timestamps | — |
| `utilizacao_improdutiva` | decimal | nullable |
| `utilizacao_operativa` | decimal | nullable |

## orcamento_composicao_items

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_03_120000_create_orcamento_composicao_items_table.php](../../database/migrations/2026_06_03_120000_create_orcamento_composicao_items_table.php) (linha 11).
- `table` — [database/migrations/2026_06_05_101000_optimize_orcamento_composicao_queries.php](../../database/migrations/2026_06_05_101000_optimize_orcamento_composicao_queries.php) (linha 35).
- `table` — [database/migrations/2026_06_09_100000_add_sicro3_section_to_orcamento_composicao_items_table.php](../../database/migrations/2026_06_09_100000_add_sicro3_section_to_orcamento_composicao_items_table.php) (linha 12).
- `table` — [database/migrations/2026_06_10_090000_add_sicro3_calculation_fields_to_orcamento_composicao_items_table.php](../../database/migrations/2026_06_10_090000_add_sicro3_calculation_fields_to_orcamento_composicao_items_table.php) (linha 11).
- `table` — [database/migrations/2026_06_10_130000_add_sicro3_reference_fields_to_orcamento_composicao_items_table.php](../../database/migrations/2026_06_10_130000_add_sicro3_reference_fields_to_orcamento_composicao_items_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `base` | string | nullable |
| `child_composicao_id` | foreignId | constrained, nullOnDelete, nullable |
| `codigo` | string | — |
| `coeficiente` | decimal | — |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `custo_improdutivo_desonerado` | decimal | nullable |
| `custo_improdutivo_onerado` | decimal | nullable |
| `deleted_at` | softDeletes | — |
| `descricao` | text | — |
| `id` | id | — |
| `item_type` | string | — |
| `observacao` | text | nullable |
| `orcamento_composicao_id` | foreignId | cascadeOnDelete, constrained |
| `orcamento_insumo_id` | foreignId | constrained, nullOnDelete, nullable |
| `preco_desonerado` | decimal | — |
| `preco_onerado` | decimal | — |
| `preco_unitario_desonerado` | decimal | — |
| `preco_unitario_onerado` | decimal | — |
| `sicro3_referenced_item_code` | string | nullable |
| `sicro3_referenced_item_description` | text | nullable |
| `sicro3_referenced_item_id` | foreignId | constrained, nullOnDelete, nullable |
| `sicro3_section` | string | nullable |
| `sicro3_transport_fe_code` | string | nullable |
| `sicro3_transport_ln_code` | string | nullable |
| `sicro3_transport_p_code` | string | nullable |
| `sicro3_transport_rp_code` | string | nullable |
| `sicro3_utilizacao_improdutiva` | decimal | nullable |
| `sicro3_utilizacao_operativa` | decimal | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo` | string | nullable |
| `unidade` | string | — |
| `updated_at` | timestamps | — |

## orcamento_composicoes

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_03_110000_create_orcamento_composicoes_table.php](../../database/migrations/2026_06_03_110000_create_orcamento_composicoes_table.php) (linha 11).
- `table` — [database/migrations/2026_06_03_130000_add_is_global_to_orcamento_composicoes_table.php](../../database/migrations/2026_06_03_130000_add_is_global_to_orcamento_composicoes_table.php) (linha 11).
- `table` — [database/migrations/2026_06_05_101000_optimize_orcamento_composicao_queries.php](../../database/migrations/2026_06_05_101000_optimize_orcamento_composicao_queries.php) (linha 30).
- `table` — [database/migrations/2026_06_09_090000_add_sicro3_fields_to_orcamento_composicoes_table.php](../../database/migrations/2026_06_09_090000_add_sicro3_fields_to_orcamento_composicoes_table.php) (linha 11).
- `table` — [database/migrations/2026_06_10_120000_make_orcamento_composicoes_uf_nullable.php](../../database/migrations/2026_06_10_120000_make_orcamento_composicoes_uf_nullable.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `adicional_mao_obra` | decimal | nullable |
| `base_references` | json | nullable |
| `codigo` | string | — |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `descricao` | text | — |
| `fator_influencia_chuvas` | decimal | nullable |
| `id` | id | — |
| `is_global` | boolean | — |
| `metodo_calculo` | string | — |
| `modelo` | string | — |
| `observacao` | text | nullable |
| `preco_desonerado` | decimal | — |
| `preco_onerado` | decimal | — |
| `producao_equipe` | decimal | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo_composicao` | string | — |
| `uf` | string | change, nullable |
| `unidade` | string | — |
| `updated_at` | timestamps | — |

## orcamento_etapas

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_12_090000_create_orcamento_etapas_table.php](../../database/migrations/2026_06_12_090000_create_orcamento_etapas_table.php) (linha 11).
- `table` — [database/migrations/2026_06_23_140000_add_social_charge_rates_to_orcamentos_table.php](../../database/migrations/2026_06_23_140000_add_social_charge_rates_to_orcamentos_table.php) (linha 13).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `descricao` | string | — |
| `id` | id | — |
| `meta` | json | nullable |
| `orcamento_id` | foreignId | cascadeOnDelete, constrained |
| `ordem` | unsignedInteger, string | change |
| `quantidade` | decimal | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `valor_desonerado` | decimal | — |
| `valor_nao_desonerado` | decimal | — |

## orcamento_insumo_grupos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_01_090000_create_orcamento_insumo_grupos_table.php](../../database/migrations/2026_06_01_090000_create_orcamento_insumo_grupos_table.php) (linha 15).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `descricao` | text | nullable |
| `id` | id | — |
| `nome` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## orcamento_insumos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_02_090000_create_orcamento_insumos_table.php](../../database/migrations/2026_06_02_090000_create_orcamento_insumos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_03_090000_add_classificacao_to_orcamento_insumos_table.php](../../database/migrations/2026_06_03_090000_add_classificacao_to_orcamento_insumos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_08_090000_add_improdutivo_costs_to_orcamento_insumos_table.php](../../database/migrations/2026_06_08_090000_add_improdutivo_costs_to_orcamento_insumos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_08_101000_add_grupo_id_to_orcamento_insumos_table.php](../../database/migrations/2026_06_08_101000_add_grupo_id_to_orcamento_insumos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_08_102000_add_observacao_to_orcamento_insumos_table.php](../../database/migrations/2026_06_08_102000_add_observacao_to_orcamento_insumos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_08_103000_make_orcamento_insumos_uf_nullable.php](../../database/migrations/2026_06_08_103000_make_orcamento_insumos_uf_nullable.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `banco` | string | — |
| `classificacao` | string | nullable |
| `codigo_insumo` | string | — |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `custo_improdutivo_desonerado` | decimal | nullable |
| `custo_improdutivo_nao_desonerado` | decimal | nullable |
| `data_referencia` | date | — |
| `deleted_at` | softDeletes | — |
| `descricao` | text | — |
| `grupo_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `observacao` | text | nullable |
| `origem_preco` | string | nullable |
| `preco_desonerado` | decimal | nullable |
| `preco_nao_desonerado` | decimal | nullable |
| `tenant_id` | foreignId | constrained, nullOnDelete, nullable |
| `tipo` | string | nullable |
| `uf` | string | change, nullable |
| `unidade` | string | — |
| `updated_at` | timestamps | — |

## orcamento_itens

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_12_100000_create_orcamento_itens_table.php](../../database/migrations/2026_06_12_100000_create_orcamento_itens_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `aplicar_bdi` | boolean | — |
| `banco` | string | nullable |
| `codigo` | string | — |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `descricao` | text | — |
| `id` | id | — |
| `item_type` | string | — |
| `meta` | json | nullable |
| `orcamento_composicao_id` | foreignId | constrained, nullOnDelete, nullable |
| `orcamento_etapa_id` | foreignId | cascadeOnDelete, constrained |
| `orcamento_id` | foreignId | cascadeOnDelete, constrained |
| `orcamento_insumo_id` | foreignId | constrained, nullOnDelete, nullable |
| `ordem` | unsignedInteger | — |
| `quantidade` | decimal | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `unidade` | string | nullable |
| `updated_at` | timestamps | — |
| `valor_com_bdi_desonerado` | decimal | — |
| `valor_com_bdi_nao_desonerado` | decimal | — |
| `valor_total_desonerado` | decimal | — |
| `valor_total_nao_desonerado` | decimal | — |
| `valor_unitario_desonerado` | decimal | — |
| `valor_unitario_nao_desonerado` | decimal | — |

## orcamentos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_11_090000_create_orcamentos_table.php](../../database/migrations/2026_06_11_090000_create_orcamentos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_16_150000_add_closed_fields_to_orcamentos_table.php](../../database/migrations/2026_06_16_150000_add_closed_fields_to_orcamentos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_23_140000_add_social_charge_rates_to_orcamentos_table.php](../../database/migrations/2026_06_23_140000_add_social_charge_rates_to_orcamentos_table.php) (linha 18).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `arredondamento` | string | — |
| `base_references` | json | nullable |
| `bdi_percentual` | decimal | — |
| `bdi_tipo` | string | — |
| `categoria` | string | — |
| `cliente_empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `closed_at` | timestamp | nullable |
| `closed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `codigo` | string | — |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `descricao` | string | — |
| `encargos_horista` | decimal | nullable |
| `encargos_mensalista` | decimal | nullable |
| `encargos_sociais` | string | — |
| `id` | id | — |
| `is_licitacao` | boolean | — |
| `licitacao_abertura_at` | timestamp | nullable |
| `licitacao_processo` | string | nullable |
| `licitacao_tipo` | string | nullable |
| `permitir_insumos_preco_zerado` | boolean | — |
| `prazo_entrega_at` | timestamp | nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `valor_desonerado` | decimal | — |
| `valor_nao_desonerado` | decimal | — |

## ordem_servico_analises

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_18_122000_create_ordem_servico_analises_table.php](../../database/migrations/2026_06_18_122000_create_ordem_servico_analises_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `decisao` | string | — |
| `id` | id | — |
| `observacao` | text | nullable |
| `ordem_servico_id` | foreignId | cascadeOnDelete, constrained |
| `tipo` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## ordem_servico_comentario_mencoes

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php](../../database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php) (linha 53).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `comentario_id` | foreignId | cascadeOnDelete, constrained |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## ordem_servico_comentarios

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php](../../database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php) (linha 37).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `body` | text | — |
| `created_at` | timestamps | — |
| `id` | id | — |
| `ordem_servico_id` | foreignId | cascadeOnDelete, constrained |
| `parent_id` | foreignId | cascadeOnDelete, constrained, nullable |
| `resolved_at` | timestamp | nullable |
| `resolved_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## ordem_servico_contract_settings

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php](../../database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `require_deadline` | boolean | — |
| `require_document` | boolean | — |
| `require_execution_responsible` | boolean | — |
| `require_project` | boolean | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `updated_by_id` | foreignId | constrained, nullOnDelete, nullable |

## ordem_servico_documentos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_130000_create_ordem_servicos_tables.php](../../database/migrations/2026_06_17_130000_create_ordem_servicos_tables.php) (linha 53).
- `table` — [database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php](../../database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php) (linha 59).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `categoria` | string | — |
| `comentario_id` | foreignId | cascadeOnDelete, constrained, nullable |
| `created_at` | timestamps | — |
| `id` | id | — |
| `mime_type` | string | nullable |
| `nome_original` | string | — |
| `ordem_servico_id` | foreignId | cascadeOnDelete, constrained |
| `path` | string | — |
| `size` | unsignedBigInteger | — |
| `updated_at` | timestamps | — |
| `uploaded_by_id` | foreignId | constrained, nullOnDelete, nullable |

## ordem_servico_itens

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_130000_create_ordem_servicos_tables.php](../../database/migrations/2026_06_17_130000_create_ordem_servicos_tables.php) (linha 32).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `medicao_item_id` | foreignId | cascadeOnDelete, constrained |
| `ordem_servico_id` | foreignId | cascadeOnDelete, constrained |
| `quantidade_solicitada` | decimal | nullable |
| `updated_at` | timestamps | — |
| `valor_previsto` | decimal | — |

## ordem_servico_obra_responsaveis

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_18_120000_create_ordem_servico_obra_responsaveis_table.php](../../database/migrations/2026_06_18_120000_create_ordem_servico_obra_responsaveis_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## ordem_servico_project_documents

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_19_100000_create_ordem_servico_project_documents_table.php](../../database/migrations/2026_06_19_100000_create_ordem_servico_project_documents_table.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `ordem_servico_id` | foreignId | cascadeOnDelete, constrained |
| `project_document_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## ordem_servico_responsaveis

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_130000_create_ordem_servicos_tables.php](../../database/migrations/2026_06_17_130000_create_ordem_servicos_tables.php) (linha 43).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `ordem_servico_id` | foreignId | cascadeOnDelete, constrained |
| `papel` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## ordem_servicos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_17_130000_create_ordem_servicos_tables.php](../../database/migrations/2026_06_17_130000_create_ordem_servicos_tables.php) (linha 11).
- `table` — [database/migrations/2026_06_18_100000_add_empresas_to_ordem_servicos_table.php](../../database/migrations/2026_06_18_100000_add_empresas_to_ordem_servicos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_18_121000_add_analysis_fields_to_ordem_servicos_table.php](../../database/migrations/2026_06_18_121000_add_analysis_fields_to_ordem_servicos_table.php) (linha 11).
- `table` — [database/migrations/2026_06_18_130000_add_eap_sequence_to_ordem_servicos_table.php](../../database/migrations/2026_06_18_130000_add_eap_sequence_to_ordem_servicos_table.php) (linha 12).
- `table` — [database/migrations/2026_06_18_130000_add_eap_sequence_to_ordem_servicos_table.php](../../database/migrations/2026_06_18_130000_add_eap_sequence_to_ordem_servicos_table.php) (linha 42).
- `table` — [database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php](../../database/migrations/2026_08_10_120000_extend_ordem_servico_execution_flow.php) (linha 26).
- `table` — [database/migrations/2026_08_10_140000_add_execution_period_to_ordem_servicos.php](../../database/migrations/2026_08_10_140000_add_execution_period_to_ordem_servicos.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `analysis_observation` | text | nullable |
| `analyzed_at` | timestamp | nullable |
| `analyzed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `approval_decided_at` | timestamp | nullable |
| `approval_decided_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `approval_observation` | text | nullable |
| `cancellation_reason` | text | nullable |
| `cancelled_at` | timestamp | nullable |
| `cancelled_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `codigo` | string | — |
| `completed_at` | timestamp | nullable |
| `completed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `completion_summary` | text | nullable |
| `construtora_empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `custo_observacao` | text | nullable |
| `custo_previsto` | decimal | — |
| `deleted_at` | softDeletes | — |
| `descricao` | text | nullable |
| `execution_started_at` | timestamp | nullable |
| `execution_started_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `gerenciadora_empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `obra_id` | foreignId | constrained, nullOnDelete, nullable |
| `prazo_execucao` | date | nullable |
| `prazo_finalizacao` | date | nullable |
| `prazo_inicio` | date | nullable |
| `project_document_id` | foreignId | constrained, nullOnDelete, nullable |
| `sequencial` | unsignedInteger | nullable |
| `status` | string | — |
| `submitted_for_review_at` | timestamp | nullable |
| `submitted_for_review_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `titulo` | string | — |
| `updated_at` | timestamps | — |

## os_dnit_highways

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_20_160000_create_os_dnit_highways_table.php](../../database/migrations/2026_10_20_160000_create_os_dnit_highways_table.php) (linha 11).
- `table` — [database/migrations/2026_10_20_180000_scope_os_dnit_catalogs_to_contracts.php](../../database/migrations/2026_10_20_180000_scope_os_dnit_catalogs_to_contracts.php) (linha 31).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `active` | boolean | — |
| `contract_id` | unsignedBigInteger | change, nullable |
| `created_at` | timestamps | — |
| `id` | id | — |
| `name` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## os_dnit_order_attachments

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_20_170000_create_os_dnit_orders_tables.php](../../database/migrations/2026_10_20_170000_create_os_dnit_orders_tables.php) (linha 46).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `mime_type` | string | nullable |
| `order_id` | foreignId | cascadeOnDelete, constrained |
| `original_name` | string | — |
| `path` | string | — |
| `size_bytes` | unsignedBigInteger | — |
| `storage_disk` | string | — |
| `updated_at` | timestamps | — |

## os_dnit_order_events

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_20_190000_add_os_dnit_review_workflow.php](../../database/migrations/2026_10_20_190000_add_os_dnit_review_workflow.php) (linha 31).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `action` | string | — |
| `actor_id` | foreignId | constrained |
| `comment` | text | nullable |
| `created_at` | timestamps | — |
| `from_status` | string | nullable |
| `id` | id | — |
| `order_id` | foreignId | constrained |
| `snapshot` | json | — |
| `to_status` | string | — |
| `updated_at` | timestamps | — |

## os_dnit_order_items

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_20_170000_create_os_dnit_orders_tables.php](../../database/migrations/2026_10_20_170000_create_os_dnit_orders_tables.php) (linha 33).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `frequency` | string | — |
| `id` | id | — |
| `order_id` | foreignId | cascadeOnDelete, constrained |
| `product_code` | string | — |
| `product_id` | foreignId | constrained |
| `product_name` | string | — |
| `quantity` | decimal | — |
| `unit` | string | — |
| `updated_at` | timestamps | — |

## os_dnit_orders

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_20_170000_create_os_dnit_orders_tables.php](../../database/migrations/2026_10_20_170000_create_os_dnit_orders_tables.php) (linha 11).
- `table` — [database/migrations/2026_10_20_190000_add_os_dnit_review_workflow.php](../../database/migrations/2026_10_20_190000_add_os_dnit_review_workflow.php) (linha 22).
- `table` — [database/migrations/2026_10_22_190000_add_supervision_lots_and_order_completion.php](../../database/migrations/2026_10_22_190000_add_supervision_lots_and_order_completion.php) (linha 22).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `analyzed_by_id` | foreignId | constrained, nullable |
| `contract_id` | foreignId | constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained |
| `desired_deadline` | date | — |
| `estimated_deadline` | date | nullable |
| `finalized_at` | timestamp | nullable |
| `finalized_by_id` | foreignId | constrained, nullable |
| `highway_id` | foreignId | constrained |
| `highway_name` | string | — |
| `id` | id | — |
| `justification` | text | — |
| `km_end` | decimal | — |
| `km_start` | decimal | — |
| `request_number` | string | — |
| `review_comment` | text | nullable |
| `review_items` | json | nullable |
| `reviewed_at` | timestamp | nullable |
| `sei_reference` | string | nullable |
| `status` | string | — |
| `subject` | string | — |
| `submitted_at` | timestamp | nullable |
| `supervision_lot` | string | — |
| `supervision_lot_id` | foreignId | constrained, nullable |
| `technical_need` | text | — |
| `tenant_id` | foreignId | constrained |
| `updated_at` | timestamps | — |
| `workflow_version` | unsignedInteger | — |

## os_dnit_products

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_20_150000_create_os_dnit_products_table.php](../../database/migrations/2026_10_20_150000_create_os_dnit_products_table.php) (linha 11).
- `table` — [database/migrations/2026_10_20_180000_scope_os_dnit_catalogs_to_contracts.php](../../database/migrations/2026_10_20_180000_scope_os_dnit_catalogs_to_contracts.php) (linha 26).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `category` | string | — |
| `code` | string | — |
| `contract_id` | unsignedBigInteger | change, nullable |
| `created_at` | timestamps | — |
| `frequency` | string | — |
| `id` | id | — |
| `name` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `unit` | string | — |
| `updated_at` | timestamps | — |

## os_dnit_responsibles

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_20_190000_add_os_dnit_review_workflow.php](../../database/migrations/2026_10_20_190000_add_os_dnit_review_workflow.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `active` | boolean | — |
| `contract_id` | foreignId | constrained |
| `created_at` | timestamps | — |
| `id` | id | — |
| `role` | string | — |
| `tenant_id` | foreignId | constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained |

## os_dnit_supervision_lots

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_22_190000_add_supervision_lots_and_order_completion.php](../../database/migrations/2026_10_22_190000_add_supervision_lots_and_order_completion.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `active` | boolean | — |
| `contract_id` | foreignId | constrained |
| `created_at` | timestamps | — |
| `id` | id | — |
| `name` | string | — |
| `sei_reference` | string | nullable |
| `tenant_id` | foreignId | constrained |
| `updated_at` | timestamps | — |

## password_reset_tokens

**Migrations relacionadas:**

- `create` — [database/migrations/0001_01_01_000000_create_users_table.php](../../database/migrations/0001_01_01_000000_create_users_table.php) (linha 27).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamp | nullable |
| `email` | string | — |
| `token` | string | — |

## project_discipline_responsaveis

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_22_094000_create_project_discipline_responsaveis_table.php](../../database/migrations/2026_05_22_094000_create_project_discipline_responsaveis_table.php) (linha 13).
- `table` — [database/migrations/2026_05_22_101000_add_project_approval_flow.php](../../database/migrations/2026_05_22_101000_add_project_approval_flow.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `disciplina_id` | foreignId | cascadeOnDelete, constrained |
| `id` | id | — |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## project_document_status_changes

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_06_120000_create_project_document_status_changes_table.php](../../database/migrations/2026_08_06_120000_create_project_document_status_changes_table.php) (linha 13).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `from_status` | string | — |
| `id` | id | — |
| `project_document_id` | foreignId | cascadeOnDelete, constrained |
| `reason` | text | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `to_status` | string | — |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## project_document_versions

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_22_091010_create_project_documents_tables.php](../../database/migrations/2026_05_22_091010_create_project_documents_tables.php) (linha 30).
- `table` — [database/migrations/2026_05_24_090000_add_revision_flow_to_project_document_versions.php](../../database/migrations/2026_05_24_090000_add_revision_flow_to_project_document_versions.php) (linha 21).
- `table` — [database/migrations/2026_05_25_090000_add_change_summary_to_project_document_versions.php](../../database/migrations/2026_05_25_090000_add_change_summary_to_project_document_versions.php) (linha 11).
- `table` — [database/migrations/2026_05_25_091000_add_stored_name_to_project_document_versions.php](../../database/migrations/2026_05_25_091000_add_stored_name_to_project_document_versions.php) (linha 12).
- `table` — [database/migrations/2026_05_27_090000_add_cap_to_project_document_versions.php](../../database/migrations/2026_05_27_090000_add_cap_to_project_document_versions.php) (linha 11).
- `table` — [database/migrations/2026_08_05_200000_create_project_submission_batches.php](../../database/migrations/2026_08_05_200000_create_project_submission_batches.php) (linha 46).
- `table` — [database/migrations/2026_08_06_131000_expand_project_version_cap_number.php](../../database/migrations/2026_08_06_131000_expand_project_version_cap_number.php) (linha 11).
- `table` — [database/migrations/2026_08_06_140000_add_storage_disk_to_project_document_versions.php](../../database/migrations/2026_08_06_140000_add_storage_disk_to_project_document_versions.php) (linha 13).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `approval_notes` | text | nullable |
| `approved_at` | timestamp | nullable |
| `approved_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `aps_object_id` | string | nullable |
| `aps_urn` | text | nullable |
| `cap_description` | text | nullable |
| `cap_impacts` | json | nullable |
| `cap_number` | string | change, nullable |
| `cap_reason` | text | nullable |
| `cap_requested_at` | timestamp | nullable |
| `cap_requested_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `cap_sequence` | unsignedInteger | nullable |
| `cap_year` | unsignedSmallInteger | nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `derivative_status` | string | — |
| `file_path` | string | — |
| `file_size` | unsignedBigInteger | nullable |
| `id` | id | — |
| `mime_type` | string | nullable |
| `original_name` | string | — |
| `processed_at` | timestamp | nullable |
| `project_document_id` | foreignId | cascadeOnDelete, constrained |
| `project_submission_batch_id` | foreignId | constrained, nullOnDelete, nullable |
| `review_notes` | text | nullable |
| `reviewed_at` | timestamp | nullable |
| `reviewed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `revision` | string | — |
| `revision_change_summary` | text | nullable |
| `status` | string | — |
| `storage_disk` | string | nullable |
| `stored_name` | string | nullable |
| `submitted_to_aps_at` | timestamp | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `uploaded_by_id` | foreignId | constrained, nullOnDelete, nullable |

## project_documents

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_22_091010_create_project_documents_tables.php](../../database/migrations/2026_05_22_091010_create_project_documents_tables.php) (linha 11).
- `table` — [database/migrations/2026_05_22_092000_expand_project_document_code_length.php](../../database/migrations/2026_05_22_092000_expand_project_document_code_length.php) (linha 11).
- `table` — [database/migrations/2026_05_22_093000_add_review_flow_to_project_documents.php](../../database/migrations/2026_05_22_093000_add_review_flow_to_project_documents.php) (linha 13).
- `table` — [database/migrations/2026_05_22_101000_add_project_approval_flow.php](../../database/migrations/2026_05_22_101000_add_project_approval_flow.php) (linha 18).
- `table` — [database/migrations/2026_05_24_090000_add_revision_flow_to_project_document_versions.php](../../database/migrations/2026_05_24_090000_add_revision_flow_to_project_document_versions.php) (linha 12).
- `table` — [database/migrations/2026_05_26_090000_create_project_phases_table.php](../../database/migrations/2026_05_26_090000_create_project_phases_table.php) (linha 50).
- `table` — [database/migrations/2026_05_29_090000_add_inactivation_fields_to_project_documents_table.php](../../database/migrations/2026_05_29_090000_add_inactivation_fields_to_project_documents_table.php) (linha 11).
- `table` — [database/migrations/2026_08_05_190000_create_trechos_and_link_project_documents.php](../../database/migrations/2026_08_05_190000_create_trechos_and_link_project_documents.php) (linha 26).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `approval_notes` | text | nullable |
| `approved_at` | timestamp | nullable |
| `approved_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `code` | string | change, nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `disciplina_id` | foreignId | constrained, nullOnDelete, nullable |
| `document_number` | string | nullable |
| `document_type` | string | — |
| `id` | id | — |
| `inactive_at` | timestamp | nullable |
| `inactive_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `inactive_reason` | text | nullable |
| `obra_id` | foreignId | constrained, nullOnDelete, nullable |
| `project_phase_id` | foreignId | constrained, nullOnDelete, nullable |
| `review_notes` | text | nullable |
| `reviewed_at` | timestamp | nullable |
| `reviewed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | — |
| `trecho_id` | foreignId | constrained, nullOnDelete, nullable |
| `updated_at` | timestamps | — |

## project_phases

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_26_090000_create_project_phases_table.php](../../database/migrations/2026_05_26_090000_create_project_phases_table.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `code` | string | unique |
| `created_at` | timestamps | — |
| `id` | id | — |
| `is_active` | boolean | — |
| `name` | string | — |
| `position` | unsignedSmallInteger | — |
| `updated_at` | timestamps | — |

## project_review_checklist_items

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_28_090000_create_project_review_workspace_tables.php](../../database/migrations/2026_05_28_090000_create_project_review_workspace_tables.php) (linha 50).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `checked` | boolean | — |
| `checked_at` | timestamp | nullable |
| `checked_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `id` | id | — |
| `label` | string | — |
| `notes` | text | nullable |
| `position` | unsignedInteger | — |
| `project_review_checklist_id` | foreignId | cascadeOnDelete, constrained |
| `required` | boolean | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## project_review_checklists

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_28_090000_create_project_review_workspace_tables.php](../../database/migrations/2026_05_28_090000_create_project_review_workspace_tables.php) (linha 36).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `project_document_id` | foreignId | cascadeOnDelete, constrained |
| `project_document_version_id` | foreignId | cascadeOnDelete, constrained |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## project_review_markup_assignees

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_06_111500_create_project_review_markup_assignees_table.php](../../database/migrations/2026_08_06_111500_create_project_review_markup_assignees_table.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `project_review_markup_id` | foreignId | cascadeOnDelete, constrained |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## project_review_markup_replies

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_22_120000_create_project_review_markup_replies_table.php](../../database/migrations/2026_07_22_120000_create_project_review_markup_replies_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `body` | text | — |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `project_review_markup_id` | foreignId | cascadeOnDelete, constrained |
| `resolves_markup` | boolean | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## project_review_markups

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_28_090000_create_project_review_workspace_tables.php](../../database/migrations/2026_05_28_090000_create_project_review_workspace_tables.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `assigned_to_id` | foreignId | constrained, nullOnDelete, nullable |
| `closed_at` | timestamp | nullable |
| `closed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `description` | text | nullable |
| `due_date` | date | nullable |
| `id` | id | — |
| `markup_payload` | json | nullable |
| `markup_type` | string | — |
| `priority` | string | — |
| `project_document_id` | foreignId | cascadeOnDelete, constrained |
| `project_document_version_id` | foreignId | cascadeOnDelete, constrained |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | — |
| `updated_at` | timestamps | — |
| `viewer_state` | json | nullable |

## project_submission_batches

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_05_200000_create_project_submission_batches.php](../../database/migrations/2026_08_05_200000_create_project_submission_batches.php) (linha 11).
- `table` — [database/migrations/2026_08_05_201000_make_project_batch_cap_nullable.php](../../database/migrations/2026_08_05_201000_make_project_batch_cap_nullable.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `approval_notes` | text | nullable |
| `approved_at` | timestamp | nullable |
| `approved_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `cap_description` | text | nullable |
| `cap_impacts` | json | nullable |
| `cap_number` | string | change, nullable |
| `cap_reason` | text | nullable |
| `cap_requested_at` | timestamp | nullable |
| `cap_sequence` | unsignedInteger | change, nullable |
| `cap_year` | unsignedSmallInteger | change, nullable |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `document_type` | string | — |
| `has_revisions` | boolean | — |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `package_number` | string | — |
| `package_sequence` | unsignedInteger | — |
| `project_phase_id` | foreignId | constrained, nullOnDelete, nullable |
| `review_notes` | text | nullable |
| `reviewed_at` | timestamp | nullable |
| `reviewed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `sequence_year` | unsignedSmallInteger | — |
| `status` | string | — |
| `submitted_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | — |
| `trecho_id` | foreignId | constrained, nullOnDelete, nullable |
| `updated_at` | timestamps | — |

## rda_apontamentos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_27_090000_create_rda_apontamentos_table.php](../../database/migrations/2026_06_27_090000_create_rda_apontamentos_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `dados` | json | nullable |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `published_at` | timestamp | nullable |
| `rdo_configuracao_id` | foreignId | cascadeOnDelete, constrained |
| `rdo_diario_id` | foreignId | constrained, nullOnDelete, nullable |
| `reference_date` | date | — |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `updated_by_id` | foreignId | constrained, nullOnDelete, nullable |

## rdo_analises

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_25_120000_create_rdo_analises_table.php](../../database/migrations/2026_06_25_120000_create_rdo_analises_table.php) (linha 11).
- `table` — [database/migrations/2026_06_25_130000_create_rdo_responsaveis_table.php](../../database/migrations/2026_06_25_130000_create_rdo_responsaveis_table.php) (linha 27).
- `table` — [database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php](../../database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php) (linha 16).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `comentario` | text | nullable |
| `created_at` | timestamps | — |
| `decisao` | string | — |
| `detalhes` | json | nullable |
| `empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `etapa` | string | — |
| `id` | id | — |
| `obra_id` | foreignId | constrained, nullOnDelete, nullable |
| `rdo_diario_id` | foreignId | cascadeOnDelete, constrained |
| `status_anterior` | string | — |
| `status_novo` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## rdo_configuracao_obras

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_24_100000_create_rdo_configuracao_obras_table.php](../../database/migrations/2026_06_24_100000_create_rdo_configuracao_obras_table.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `rdo_configuracao_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## rdo_configuracoes

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_23_180000_create_rdo_tables.php](../../database/migrations/2026_06_23_180000_create_rdo_tables.php) (linha 11).
- `table` — [database/migrations/2026_06_24_090000_change_rdo_submission_deadline_to_days.php](../../database/migrations/2026_06_24_090000_change_rdo_submission_deadline_to_days.php) (linha 11).
- `table` — [database/migrations/2026_07_01_120000_add_digital_signature_to_rdo_configuracoes.php](../../database/migrations/2026_07_01_120000_add_digital_signature_to_rdo_configuracoes.php) (linha 11).
- `table` — [database/migrations/2026_08_17_160000_fix_rdo_generation_time_at_0005.php](../../database/migrations/2026_08_17_160000_fix_rdo_generation_time_at_0005.php) (linha 16).
- `table` — [database/migrations/2026_10_28_120000_add_report_company_to_rdo_configurations.php](../../database/migrations/2026_10_28_120000_add_report_company_to_rdo_configurations.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `active` | boolean | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `copy_equipment` | boolean | — |
| `copy_pending_activities` | boolean | — |
| `copy_previous_day` | boolean | — |
| `copy_workforce` | boolean | — |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `digital_signature_enabled` | boolean | — |
| `end_date` | date | nullable |
| `generate_on_holidays` | boolean | — |
| `generation_time` | time | change |
| `generation_weekdays` | json | — |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `report_company_id` | foreignId | constrained, nullOnDelete, nullable |
| `require_photos` | boolean | — |
| `responsible_user_id` | foreignId | constrained, nullOnDelete, nullable |
| `start_date` | date | — |
| `submission_deadline_days` | unsignedSmallInteger | — |
| `submission_deadline_time` | time | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `timezone` | string | — |
| `updated_at` | timestamps | — |

## rdo_diarios

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_23_180000_create_rdo_tables.php](../../database/migrations/2026_06_23_180000_create_rdo_tables.php) (linha 37).
- `table` — [database/migrations/2026_07_02_120000_add_reopen_fields_to_rdo_diarios.php](../../database/migrations/2026_07_02_120000_add_reopen_fields_to_rdo_diarios.php) (linha 11).
- `table` — [database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php](../../database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php) (linha 12).
- `table` — [database/migrations/2026_10_28_110000_add_rdo_pdf_settings.php](../../database/migrations/2026_10_28_110000_add_rdo_pdf_settings.php) (linha 19).
- `table` — [database/migrations/2026_10_30_120000_add_manual_holiday_to_rdo_diaries.php](../../database/migrations/2026_10_30_120000_add_manual_holiday_to_rdo_diaries.php) (linha 10).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `approved_at` | timestamp | nullable |
| `code` | string | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `copied_from_rdo_id` | foreignId | constrained, nullOnDelete, nullable |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `current_version` | unsignedInteger | — |
| `deleted_at` | softDeletes | — |
| `generated_automatically` | boolean | — |
| `id` | id | — |
| `is_holiday` | boolean | nullable |
| `mobile_local_uuid` | uuid | nullable |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `pdf_configuration_snapshot` | json | nullable |
| `rdo_configuracao_id` | foreignId | cascadeOnDelete, constrained |
| `reference_date` | date | — |
| `reopened_at` | timestamp | nullable |
| `reopened_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `reopened_until` | timestamp | nullable |
| `responsible_user_id` | foreignId | constrained, nullOnDelete, nullable |
| `sequence_number` | unsignedInteger | — |
| `status` | string | — |
| `submitted_at` | timestamp | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## rdo_divergencias

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php](../../database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php) (linha 57).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `client_decided_at` | timestamp | nullable |
| `client_decided_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `comentario_cliente` | text | nullable |
| `concluded_at` | timestamp | nullable |
| `concluded_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `conclusao_gerenciadora` | text | nullable |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `decisao_cliente` | string | nullable |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `rdo_diario_id` | foreignId | cascadeOnDelete, constrained |
| `responded_at` | timestamp | nullable |
| `responded_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `resposta_construtora` | text | nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `titulo` | string | — |
| `topico` | string | — |
| `updated_at` | timestamps | — |
| `versao_construtora` | text | — |
| `versao_gerenciadora` | text | — |
| `version_number` | unsignedInteger | — |

## rdo_equipamento_cadastros

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_24_110000_create_rdo_cadastros_tables.php](../../database/migrations/2026_06_24_110000_create_rdo_cadastros_tables.php) (linha 25).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `active` | boolean | — |
| `codigo` | string | nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `descricao` | string | — |
| `id` | id | — |
| `propriedade` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `unidade` | string | — |
| `updated_at` | timestamps | — |

## rdo_fiscalizacao_registros

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php](../../database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php) (linha 36).
- `table` — [database/migrations/2026_10_29_150000_add_activities_to_rdo_inspections.php](../../database/migrations/2026_10_29_150000_add_activities_to_rdo_inspections.php) (linha 10).
- `table` — [database/migrations/2026_10_29_160000_add_comment_to_rdo_inspections.php](../../database/migrations/2026_10_29_160000_add_comment_to_rdo_inspections.php) (linha 10).
- `table` — [database/migrations/2026_10_29_170000_add_weather_to_rdo_inspections.php](../../database/migrations/2026_10_29_170000_add_weather_to_rdo_inspections.php) (linha 10).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `atividades` | json | nullable |
| `clima` | json | nullable |
| `comentario` | text | nullable |
| `created_at` | timestamps | — |
| `empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `evidencias` | json | nullable |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `ocorrencias` | text | nullable |
| `orientacoes` | text | nullable |
| `rdo_diario_id` | foreignId | cascadeOnDelete, constrained |
| `resumo_fiscalizacao` | text | — |
| `servicos_atestados` | text | nullable |
| `status` | string | — |
| `submitted_at` | timestamp | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |
| `version_number` | unsignedInteger | — |

## rdo_mao_obra_cadastros

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_24_110000_create_rdo_cadastros_tables.php](../../database/migrations/2026_06_24_110000_create_rdo_cadastros_tables.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `active` | boolean | — |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `descricao` | string | — |
| `id` | id | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `tipo` | string | — |
| `unidade` | string | — |
| `updated_at` | timestamps | — |

## rdo_mobile_syncs

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_28_100000_create_rdo_mobile_syncs_table.php](../../database/migrations/2026_10_28_100000_create_rdo_mobile_syncs_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamp | — |
| `id` | id | — |
| `local_uuid` | string | — |
| `request_hash` | string | — |
| `response` | json | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## rdo_pdf_settings

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_28_110000_add_rdo_pdf_settings.php](../../database/migrations/2026_10_28_110000_add_rdo_pdf_settings.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `configuration` | json | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `id` | id | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## rdo_responsaveis

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_25_130000_create_rdo_responsaveis_table.php](../../database/migrations/2026_06_25_130000_create_rdo_responsaveis_table.php) (linha 11).
- `table` — [database/migrations/2026_06_29_120000_add_modulo_to_rdo_responsaveis_table.php](../../database/migrations/2026_06_29_120000_add_modulo_to_rdo_responsaveis_table.php) (linha 12).
- `table` — [database/migrations/2026_06_29_120000_add_modulo_to_rdo_responsaveis_table.php](../../database/migrations/2026_06_29_120000_add_modulo_to_rdo_responsaveis_table.php) (linha 18).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `etapa` | string | — |
| `id` | id | — |
| `modulo` | string | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## rdo_secao_registros

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_24_120000_create_rdo_secao_registros_table.php](../../database/migrations/2026_06_24_120000_create_rdo_secao_registros_table.php) (linha 11).
- `table` — [database/migrations/2026_06_25_090000_separate_rdo_sections_by_obra.php](../../database/migrations/2026_06_25_090000_separate_rdo_sections_by_obra.php) (linha 12).
- `table` — [database/migrations/2026_06_25_090000_separate_rdo_sections_by_obra.php](../../database/migrations/2026_06_25_090000_separate_rdo_sections_by_obra.php) (linha 25).
- `table` — [database/migrations/2026_10_27_190000_separate_rdo_sections_by_filling_party.php](../../database/migrations/2026_10_27_190000_separate_rdo_sections_by_filling_party.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `dados` | json | — |
| `filling_party` | string | — |
| `id` | id | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained, nullable |
| `rdo_diario_id` | foreignId | cascadeOnDelete, constrained |
| `secao` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `updated_by_id` | foreignId | constrained, nullOnDelete, nullable |

## rdo_signature_requests

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_25_150000_create_rdo_signature_tables.php](../../database/migrations/2026_06_25_150000_create_rdo_signature_tables.php) (linha 11).
- `table` — [database/migrations/2026_10_23_190000_add_rnc_signatures.php](../../database/migrations/2026_10_23_190000_add_rnc_signatures.php) (linha 19).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `audit_trail_path` | string | nullable |
| `cancelled_at` | timestamp | nullable |
| `completed_at` | timestamp | nullable |
| `created_at` | timestamps | — |
| `error_message` | text | nullable |
| `id` | id | — |
| `provider` | string | — |
| `provider_document_id` | string | nullable |
| `provider_payload` | json | nullable |
| `provider_request_id` | string | nullable |
| `rdo_diario_id` | foreignId, unsignedBigInteger | cascadeOnDelete, change, constrained, nullable |
| `relatorio_nao_conformidade_id` | foreignId | constrained, nullable |
| `request_payload` | json | nullable |
| `requested_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `sent_at` | timestamp | nullable |
| `signed_pdf_path` | string | nullable |
| `signing_url` | text | nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `title` | string | — |
| `unsigned_pdf_path` | string | nullable |
| `updated_at` | timestamps | — |
| `webhook_payload` | json | nullable |

## rdo_signature_signers

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_25_150000_create_rdo_signature_tables.php](../../database/migrations/2026_06_25_150000_create_rdo_signature_tables.php) (linha 39).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `email` | string | — |
| `empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `name` | string | — |
| `provider_payload` | json | nullable |
| `provider_signer_id` | string | nullable |
| `rdo_signature_request_id` | foreignId | cascadeOnDelete, constrained |
| `role` | string | — |
| `signed_at` | timestamp | nullable |
| `signing_url` | text | nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## rdo_subcontratada_cadastros

**Migrations relacionadas:**

- `create` — [database/migrations/2026_06_24_110000_create_rdo_cadastros_tables.php](../../database/migrations/2026_06_24_110000_create_rdo_cadastros_tables.php) (linha 40).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `active` | boolean | — |
| `cnpj` | string | nullable |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `email` | string | nullable |
| `id` | id | — |
| `nome_fantasia` | string | nullable |
| `razao_social` | string | — |
| `responsavel` | string | nullable |
| `telefone` | string | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## rdo_versoes

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php](../../database/migrations/2026_08_24_120000_reformulate_rdo_contractual_flow.php) (linha 20).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `rdo_diario_id` | foreignId | cascadeOnDelete, constrained |
| `sections_snapshot` | json | — |
| `status` | string | — |
| `submitted_at` | timestamp | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `trigger` | string | — |
| `updated_at` | timestamps | — |
| `version_number` | unsignedInteger | — |

## relatorio_nao_conformidade_acoes_corretivas

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_17_123000_create_relatorio_nao_conformidade_acoes_corretivas_table.php](../../database/migrations/2026_05_17_123000_create_relatorio_nao_conformidade_acoes_corretivas_table.php) (linha 11).
- `table` — [database/migrations/2026_05_17_124000_add_review_fields_to_relatorio_nao_conformidade_acoes_corretivas_table.php](../../database/migrations/2026_05_17_124000_add_review_fields_to_relatorio_nao_conformidade_acoes_corretivas_table.php) (linha 11).
- `table` — [database/migrations/2026_05_17_125000_add_execution_deadline_to_relatorio_nao_conformidade_acoes_corretivas_table.php](../../database/migrations/2026_05_17_125000_add_execution_deadline_to_relatorio_nao_conformidade_acoes_corretivas_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `attachment_mime_type` | string | nullable |
| `attachment_original_name` | string | — |
| `attachment_path` | string | — |
| `attachment_size` | unsignedBigInteger | — |
| `created_at` | timestamps | — |
| `descricao_proposta` | text | — |
| `id` | id | — |
| `prazo_execucao_proposto` | date | nullable |
| `relatorio_nao_conformidade_id` | foreignId | cascadeOnDelete, constrained |
| `review_observation` | text | nullable |
| `reviewed_at` | timestamp | nullable |
| `reviewed_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `status` | string | — |
| `submitted_at` | timestamp | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## relatorio_nao_conformidade_evidencia_photos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_18_090000_create_relatorio_nao_conformidade_evidencias_table.php](../../database/migrations/2026_05_18_090000_create_relatorio_nao_conformidade_evidencias_table.php) (linha 40).
- `table` — [database/migrations/2026_05_19_101000_add_comment_to_relatorio_nao_conformidade_evidencia_photos_table.php](../../database/migrations/2026_05_19_101000_add_comment_to_relatorio_nao_conformidade_evidencia_photos_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `comment` | text | nullable |
| `created_at` | timestamps | — |
| `id` | id | — |
| `mime_type` | string | nullable |
| `original_name` | string | nullable |
| `path` | string | — |
| `position` | unsignedInteger | — |
| `relatorio_nao_conformidade_evidencia_id` | foreignId | — |
| `size` | unsignedBigInteger | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## relatorio_nao_conformidade_evidencias

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_18_090000_create_relatorio_nao_conformidade_evidencias_table.php](../../database/migrations/2026_05_18_090000_create_relatorio_nao_conformidade_evidencias_table.php) (linha 16).
- `table` — [database/migrations/2026_10_23_180000_add_rnc_evidence_resolution.php](../../database/migrations/2026_10_23_180000_add_rnc_evidence_resolution.php) (linha 11).
- `table` — [database/migrations/2026_10_23_200000_allow_rnc_evidence_without_zip.php](../../database/migrations/2026_10_23_200000_allow_rnc_evidence_without_zip.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `attachment_mime_type` | string | nullable |
| `attachment_original_name` | string | change, nullable |
| `attachment_path` | string | change, nullable |
| `attachment_size` | unsignedBigInteger | — |
| `created_at` | timestamps | — |
| `id` | id | — |
| `relatorio_nao_conformidade_acao_corretiva_id` | foreignId | — |
| `relatorio_nao_conformidade_id` | foreignId | — |
| `resolution_reason` | text | nullable |
| `resolution_result` | string | nullable |
| `submitted_at` | timestamp | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## relatorio_nao_conformidade_photos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_114010_create_relatorio_nao_conformidade_photos_table.php](../../database/migrations/2026_05_16_114010_create_relatorio_nao_conformidade_photos_table.php) (linha 14).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `comment` | text | nullable |
| `created_at` | timestamps | — |
| `id` | id | — |
| `mime_type` | string | nullable |
| `original_name` | string | — |
| `path` | string | — |
| `position` | unsignedInteger | — |
| `relatorio_nao_conformidade_id` | foreignId | cascadeOnDelete, constrained |
| `size` | unsignedBigInteger | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | constrained, nullOnDelete, nullable |

## relatorio_nao_conformidade_responsaveis

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_17_120000_create_relatorio_nao_conformidade_responsaveis_table.php](../../database/migrations/2026_05_17_120000_create_relatorio_nao_conformidade_responsaveis_table.php) (linha 11).
- `table` — [database/migrations/2026_05_19_130000_add_permissions_to_relatorio_nao_conformidade_responsaveis_table.php](../../database/migrations/2026_05_19_130000_add_permissions_to_relatorio_nao_conformidade_responsaveis_table.php) (linha 13).
- `table` — [database/migrations/2026_05_20_112010_add_soft_deletes_to_relatorio_nao_conformidade_responsaveis.php](../../database/migrations/2026_05_20_112010_add_soft_deletes_to_relatorio_nao_conformidade_responsaveis.php) (linha 11).
- `table` — [database/migrations/2026_07_23_140000_add_responsibility_type_to_rnc_responsaveis_table.php](../../database/migrations/2026_07_23_140000_add_responsibility_type_to_rnc_responsaveis_table.php) (linha 13).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `permissions` | json | nullable |
| `responsibility_type` | string | index, nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |

## relatorio_nao_conformidades

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_114000_create_relatorio_nao_conformidades_table.php](../../database/migrations/2026_05_16_114000_create_relatorio_nao_conformidades_table.php) (linha 14).
- `table` — [database/migrations/2026_05_16_115000_add_sequence_to_relatorio_nao_conformidades_table.php](../../database/migrations/2026_05_16_115000_add_sequence_to_relatorio_nao_conformidades_table.php) (linha 15).
- `table` — [database/migrations/2026_05_16_115000_add_sequence_to_relatorio_nao_conformidades_table.php](../../database/migrations/2026_05_16_115000_add_sequence_to_relatorio_nao_conformidades_table.php) (linha 46).
- `table` — [database/migrations/2026_05_16_115010_rename_rnc_corrective_action_deadline_column.php](../../database/migrations/2026_05_16_115010_rename_rnc_corrective_action_deadline_column.php) (linha 14).
- `table` — [database/migrations/2026_05_17_121000_add_soft_deletes_to_relatorio_nao_conformidades_table.php](../../database/migrations/2026_05_17_121000_add_soft_deletes_to_relatorio_nao_conformidades_table.php) (linha 11).
- `table` — [database/migrations/2026_05_17_122000_add_notified_at_to_relatorio_nao_conformidades_table.php](../../database/migrations/2026_05_17_122000_add_notified_at_to_relatorio_nao_conformidades_table.php) (linha 11).
- `table` — [database/migrations/2026_05_18_090000_create_relatorio_nao_conformidade_evidencias_table.php](../../database/migrations/2026_05_18_090000_create_relatorio_nao_conformidade_evidencias_table.php) (linha 11).
- `table` — [database/migrations/2026_06_01_090000_add_project_document_id_to_relatorio_nao_conformidades_table.php](../../database/migrations/2026_06_01_090000_add_project_document_id_to_relatorio_nao_conformidades_table.php) (linha 11).
- `table` — [database/migrations/2026_06_01_091000_add_disciplina_id_to_relatorio_nao_conformidades_table.php](../../database/migrations/2026_06_01_091000_add_disciplina_id_to_relatorio_nao_conformidades_table.php) (linha 11).
- `table` — [database/migrations/2026_06_22_150100_add_mobile_local_uuid_to_relatorio_nao_conformidades_table.php](../../database/migrations/2026_06_22_150100_add_mobile_local_uuid_to_relatorio_nao_conformidades_table.php) (linha 11).
- `table` — [database/migrations/2026_10_23_170000_add_ic_reference_to_rncs.php](../../database/migrations/2026_10_23_170000_add_ic_reference_to_rncs.php) (linha 11).
- `table` — [database/migrations/2026_10_26_180000_add_pdf_logo_snapshot_to_relatorio_nao_conformidades_table.php](../../database/migrations/2026_10_26_180000_add_pdf_logo_snapshot_to_relatorio_nao_conformidades_table.php) (linha 11).
- `table` — [database/migrations/2026_10_26_190000_add_rnc_model_fields_and_document_snapshot.php](../../database/migrations/2026_10_26_190000_add_rnc_model_fields_and_document_snapshot.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `acoes_corretivas_recomendadas` | text | — |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `contratada_empresa_id` | foreignId | constrained |
| `contratante_empresa_id` | foreignId | constrained |
| `created_at` | timestamps | — |
| `created_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `deleted_at` | softDeletes | — |
| `deleted_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `descricao_problema` | text | — |
| `disciplina_id` | foreignId | nullable |
| `finalized_at` | timestamp | nullable |
| `finalized_by_id` | foreignId | constrained, nullOnDelete, nullable |
| `gravidade` | string | — |
| `id` | id | — |
| `instrucao_campo_id` | foreignId | constrained, nullable |
| `latitude` | decimal | nullable |
| `longitude` | decimal | nullable |
| `mobile_local_uuid` | string | nullable |
| `model_field_values` | json | nullable |
| `natureza` | string | — |
| `notified_at` | timestamp | nullable |
| `obra_id` | foreignId | constrained |
| `observacao` | text | nullable |
| `opened_at` | date | — |
| `pdf_logo_snapshot` | json | nullable |
| `pdf_template_snapshot` | json | nullable |
| `prazo_acao_corretiva` | date | — |
| `project_document_id` | foreignId | nullable |
| `sequence_number` | unsignedInteger | nullable |
| `sequence_year` | unsignedSmallInteger | nullable |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## rnc_pdf_settings

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_24_120000_create_rnc_pdf_settings_table.php](../../database/migrations/2026_10_24_120000_create_rnc_pdf_settings_table.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | cascadeOnDelete, constrained |
| `created_at` | timestamps | — |
| `id` | id | — |
| `layout` | string | — |
| `options` | json | nullable |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `updated_by_id` | foreignId | constrained, nullOnDelete, nullable |

## rnc_project_documents

**Migrations relacionadas:**

- `create` — [database/migrations/2026_07_23_120000_create_rnc_project_documents_table.php](../../database/migrations/2026_07_23_120000_create_rnc_project_documents_table.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `project_document_id` | unsignedBigInteger | — |
| `relatorio_nao_conformidade_id` | unsignedBigInteger | — |
| `updated_at` | timestamps | — |

## rnc_signature_settings

**Migrations relacionadas:**

- `create` — [database/migrations/2026_10_23_190000_add_rnc_signatures.php](../../database/migrations/2026_10_23_190000_add_rnc_signatures.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `contract_id` | foreignId | constrained, unique |
| `created_at` | timestamps | — |
| `id` | id | — |
| `signers` | json | nullable |
| `tenant_id` | foreignId | constrained |
| `updated_at` | timestamps | — |
| `updated_by_id` | foreignId | constrained, nullable |

## sessions

**Migrations relacionadas:**

- `create` — [database/migrations/0001_01_01_000000_create_users_table.php](../../database/migrations/0001_01_01_000000_create_users_table.php) (linha 33).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `id` | string | — |
| `ip_address` | string | nullable |
| `last_activity` | integer | index |
| `payload` | longText | — |
| `user_agent` | text | nullable |
| `user_id` | foreignId | index, nullable |

## tenant_users

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_15_151010_create_tenant_users_table.php](../../database/migrations/2026_05_15_151010_create_tenant_users_table.php) (linha 14).
- `table` — [database/migrations/2026_05_16_111000_add_empresa_id_to_tenant_users.php](../../database/migrations/2026_05_16_111000_add_empresa_id_to_tenant_users.php) (linha 14).
- `table` — [database/migrations/2026_05_20_100000_add_activity_permissions_to_tenant_users_table.php](../../database/migrations/2026_05_20_100000_add_activity_permissions_to_tenant_users_table.php) (linha 16).
- `table` — [database/migrations/2026_05_20_110000_add_user_permissions_to_tenant_users_table.php](../../database/migrations/2026_05_20_110000_add_user_permissions_to_tenant_users_table.php) (linha 13).
- `table` — [database/migrations/2026_05_21_091000_add_parametrizacao_permissions_to_tenant_users_table.php](../../database/migrations/2026_05_21_091000_add_parametrizacao_permissions_to_tenant_users_table.php) (linha 13).
- `table` — [database/migrations/2026_05_22_091000_add_project_permissions_to_users_and_participants.php](../../database/migrations/2026_05_22_091000_add_project_permissions_to_users_and_participants.php) (linha 13).
- `table` — [database/migrations/2026_07_30_090000_add_budget_permissions_and_orcamento_accesses.php](../../database/migrations/2026_07_30_090000_add_budget_permissions_and_orcamento_accesses.php) (linha 13).
- `table` — [database/migrations/2026_08_05_120000_add_ai_quotas_and_monthly_usage.php](../../database/migrations/2026_08_05_120000_add_ai_quotas_and_monthly_usage.php) (linha 15).
- `table` — [database/migrations/2026_08_05_140000_add_documentation_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_140000_add_documentation_permissions_to_users_and_participants.php) (linha 13).
- `table` — [database/migrations/2026_08_05_150000_add_diario_obra_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_150000_add_diario_obra_permissions_to_users_and_participants.php) (linha 13).
- `table` — [database/migrations/2026_08_05_160000_add_ordem_servico_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_160000_add_ordem_servico_permissions_to_users_and_participants.php) (linha 13).
- `table` — [database/migrations/2026_08_05_170000_add_medicao_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_170000_add_medicao_permissions_to_users_and_participants.php) (linha 13).
- `table` — [database/migrations/2026_08_05_180000_add_contract_permissions_to_users_and_participants.php](../../database/migrations/2026_08_05_180000_add_contract_permissions_to_users_and_participants.php) (linha 13).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `activity_permissions` | json | nullable |
| `ai_monthly_token_limit` | unsignedBigInteger | nullable |
| `budget_permissions` | json | nullable |
| `contract_permissions` | json | nullable |
| `created_at` | timestamps | — |
| `diario_obra_permissions` | json | nullable |
| `documentation_permissions` | json | nullable |
| `empresa_id` | foreignId | constrained, nullOnDelete, nullable |
| `id` | id | — |
| `invited_at` | timestamp | nullable |
| `joined_at` | timestamp | nullable |
| `medicao_permissions` | json | nullable |
| `ordem_servico_permissions` | json | nullable |
| `parametrizacao_permissions` | json | nullable |
| `project_permissions` | json | nullable |
| `role` | string | — |
| `status` | string | — |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |
| `user_id` | foreignId | cascadeOnDelete, constrained |
| `user_permissions` | json | nullable |

## tenants

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_15_151000_create_tenants_table.php](../../database/migrations/2026_05_15_151000_create_tenants_table.php) (linha 14).
- `table` — [database/migrations/2026_08_05_120000_add_ai_quotas_and_monthly_usage.php](../../database/migrations/2026_08_05_120000_add_ai_quotas_and_monthly_usage.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `ai_monthly_token_limit` | unsignedBigInteger | nullable |
| `branding` | json | nullable |
| `cnpj` | string | nullable |
| `created_at` | timestamps | — |
| `id` | id | — |
| `name` | string | — |
| `plan` | string | — |
| `settings` | json | nullable |
| `slug` | string | unique |
| `status` | string | — |
| `trial_ends_at` | timestamp | nullable |
| `updated_at` | timestamps | — |

## tipos_empresa

**Migrations relacionadas:**

- `create` — [database/migrations/2026_05_16_100000_create_tipos_empresa_table.php](../../database/migrations/2026_05_16_100000_create_tipos_empresa_table.php) (linha 15).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `created_at` | timestamps | — |
| `id` | id | — |
| `nome` | string | unique |
| `updated_at` | timestamps | — |

## trechos

**Migrations relacionadas:**

- `create` — [database/migrations/2026_08_05_190000_create_trechos_and_link_project_documents.php](../../database/migrations/2026_08_05_190000_create_trechos_and_link_project_documents.php) (linha 12).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `codigo` | string | — |
| `created_at` | timestamps | — |
| `deleted_at` | softDeletes | — |
| `id` | id | — |
| `is_default` | boolean | — |
| `nome` | string | — |
| `obra_id` | foreignId | cascadeOnDelete, constrained |
| `tenant_id` | foreignId | cascadeOnDelete, constrained |
| `updated_at` | timestamps | — |

## users

**Migrations relacionadas:**

- `create` — [database/migrations/0001_01_01_000000_create_users_table.php](../../database/migrations/0001_01_01_000000_create_users_table.php) (linha 14).
- `table` — [database/migrations/2026_05_28_150000_add_must_change_password_to_users_table.php](../../database/migrations/2026_05_28_150000_add_must_change_password_to_users_table.php) (linha 11).
- `table` — [database/migrations/2026_10_23_130000_add_os_dnit_contract_exemption_to_users.php](../../database/migrations/2026_10_23_130000_add_os_dnit_contract_exemption_to_users.php) (linha 11).

| Campo declarado | Tipos/ocorrências | Características identificadas |
| --- | --- | --- |
| `avatar_url` | text | nullable |
| `created_at` | timestamps | — |
| `email` | string | unique |
| `email_verified_at` | timestamp | nullable |
| `id` | id | — |
| `is_platform_admin` | boolean | — |
| `must_change_password` | boolean | — |
| `name` | string | — |
| `os_dnit_contract_exemption` | boolean | — |
| `password` | string | — |
| `phone` | string | nullable |
| `remember_token` | rememberToken | — |
| `temporary_password_created_at` | timestamp | nullable |
| `updated_at` | timestamps | — |
