# Data Model — SQLite (`data/sonar.db`)

Todas as tabelas são criadas por `db_migrate()` em `includes/database.php`. `PRAGMA foreign_keys = ON` está ativo, mas as tabelas **não** declaram foreign keys explícitas — as relações são implícitas por naming.

## Tabelas

### `tasks`

Cache local das tarefas do ClickUp.

| Coluna              | Tipo     | Notas                                                  |
|---------------------|----------|--------------------------------------------------------|
| `id`                | TEXT PK  | ClickUp task ID                                        |
| `custom_id`         | TEXT     |                                                        |
| `name`              | TEXT NN  |                                                        |
| `description`       | TEXT     | Preferência: `text_content`, fallback `description`    |
| `status_name`       | TEXT     | Ex.: `design`, `approval design`, `published`, …        |
| `status_color`      | TEXT     |                                                        |
| `priority_id`       | INTEGER  | 1=Urgent, 2=High, 3=Normal, 4=Low, NULL=não definido    |
| `priority_label`    | TEXT     |                                                        |
| `due_date`          | INTEGER  | **Milissegundos** epoch (formato ClickUp)              |
| `start_date`        | INTEGER  | ms epoch                                                |
| `parent_id`         | TEXT     | Implícito → `tasks.id` (sem FK)                         |
| `list_id`           | TEXT     |                                                        |
| `list_name`         | TEXT     |                                                        |
| `folder_id`         | TEXT     |                                                        |
| `folder_name`       | TEXT     |                                                        |
| `workspace_id`      | TEXT     | Team ID                                                |
| `task_type`         | TEXT     | Field nativo ou custom field "Task Type"               |
| `assignees`         | TEXT     | JSON (lista de users como o ClickUp retorna)           |
| `tags`              | TEXT     | JSON                                                   |
| `url`               | TEXT     |                                                        |
| `date_created`      | TEXT     | String do ClickUp                                      |
| `date_updated`      | TEXT     | String do ClickUp                                      |
| `synced_at`         | INTEGER NN DEFAULT now | Segundos epoch (strftime '%s')        |
| `approval_rejected` | INTEGER NN DEFAULT 0   | 1 se detetada transição `approval design → design` |

**Índices**

- `idx_tasks_parent` on `parent_id`
- `idx_tasks_list` on `list_id`
- `idx_tasks_workspace` on `workspace_id`
- `idx_tasks_priority_due` on `(priority_id, due_date)`

---

### `task_assignees`

Lookup normalizado para filtrar tarefas por utilizador.

| Coluna     | Tipo        | Notas                                        |
|------------|-------------|----------------------------------------------|
| `task_id`  | TEXT NN     | Implícito → `tasks.id` (sem FK, sem CASCADE) |
| `user_id`  | TEXT NN     | ClickUp user ID como string                  |

**PK composta** `(task_id, user_id)`. Índice `idx_task_assignees_user` em `user_id`.

Mantido por `db_upsert_task()` — apaga tudo de `task_id` e re-insere de `task['assignees']`.

---

### `sync_log`

Histórico de syncs (um por operação).

| Coluna          | Tipo     | Notas                                                          |
|-----------------|----------|----------------------------------------------------------------|
| `id`            | INTEGER PK AUTOINCREMENT |                                                   |
| `workspace_id`  | TEXT NN  |                                                                |
| `user_id`       | TEXT     | Quem pediu o sync                                              |
| `list_id`       | TEXT     |                                                                |
| `started_at`    | INTEGER NN | epoch                                                        |
| `completed_at`  | INTEGER  | epoch, NULL enquanto corre                                     |
| `task_count`    | INTEGER DEFAULT 0 |                                                       |
| `status`        | TEXT DEFAULT 'running' | `running`, `success`, `error`, `cancelled`        |
| `error_message` | TEXT     |                                                                |
| `progress`      | TEXT     | Mensagem humana ("A importar tarefas... (42)"). Adicionada por ALTER TABLE. |

Sem índices explícitos.

---

### `config_lists`

Listas do ClickUp que o dashboard sincroniza.

| Coluna         | Tipo     | Notas                                        |
|----------------|----------|----------------------------------------------|
| `id`           | TEXT PK  | ClickUp list ID                              |
| `name`         | TEXT     |                                              |
| `workspace_id` | TEXT NN  |                                              |
| `enabled`      | INTEGER DEFAULT 1 |                                     |

Hoje só é gravada a lista do sync corrente (uma por workspace). Tabela **subutilizada** face ao que o nome sugere — ver `known-issues.md`.

---

### `watched_tasks`

Tarefas que o utilizador marcou para seguir; mudanças geram `task_notifications`.

| Coluna         | Tipo     | Notas                                        |
|----------------|----------|----------------------------------------------|
| `task_id`      | TEXT NN  | Implícito → `tasks.id`                       |
| `workspace_id` | TEXT NN  |                                              |
| `created_at`   | INTEGER NN DEFAULT now |                              |

**PK composta** `(task_id, workspace_id)`.

---

### `task_notifications`

Notificações geradas durante o sync quando uma watched task muda.

| Coluna         | Tipo     | Notas                                                         |
|----------------|----------|---------------------------------------------------------------|
| `id`           | INTEGER PK AUTOINCREMENT |                                                  |
| `task_id`      | TEXT NN  | A task que mudou (pode ser subtask de um watched post)         |
| `task_name`    | TEXT     | Nome formatado, ex.: `"Post → Copy"`                           |
| `workspace_id` | TEXT NN  |                                                               |
| `change_type`  | TEXT NN  | `status`, `priority`, `due_date`, `assignee`, `name`          |
| `old_value`    | TEXT     |                                                               |
| `new_value`    | TEXT     |                                                               |
| `seen`         | INTEGER NN DEFAULT 0 |                                                   |
| `created_at`   | INTEGER NN DEFAULT now |                                                 |

**Índice** `idx_notifications_unseen` em `(workspace_id, seen, created_at DESC)`.

Limpeza em `db_cleanup_notifications()`: apaga `seen=1` > 30 dias, tudo > 90 dias, mantém no máximo 500.

## Relações (implícitas)

```
tasks.parent_id            → tasks.id        (self, árvore)
task_assignees.task_id     → tasks.id
watched_tasks.task_id      → tasks.id
task_notifications.task_id → tasks.id
config_lists.id            → tasks.list_id   (conceitual; não FK)
sync_log.workspace_id      → (não há tabela workspaces)
```

## Unidades e convenções

- Datas do ClickUp em `tasks` (`due_date`, `start_date`) são **milissegundos epoch**.
- Datas nossas (`synced_at`, `started_at`, `completed_at`, `created_at`) são **segundos epoch** (`strftime('%s','now')` ou `time()`).
- `user_id` é armazenado como TEXT em `task_assignees` mas o ClickUp dá-o como inteiro — há `(string)` casts nos queries.

## Tabelas / colunas legadas ou em risco

- `config_lists` — existe infra para múltiplas listas mas só é usada uma.
- `sync_worker.php` usa o schema igual ao `sync.php`, mas a chamada CLI aparenta não ser usada hoje (ver `known-issues.md`).
