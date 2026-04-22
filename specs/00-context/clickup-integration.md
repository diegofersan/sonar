# ClickUp Integration

## Autenticação

- **Fluxo**: OAuth 2.0 Authorization Code (server-side).
- **Client credentials**: `CLICKUP_CLIENT_ID`, `CLICKUP_CLIENT_SECRET`, `CLICKUP_REDIRECT_URI` definidos em `config.local.php` (gitignored) com fallback para env vars. Defaults em `config.php`.
- **Authorize URL**: `https://app.clickup.com/api` (constante `CLICKUP_AUTH_URL`). Parâmetros: `client_id`, `redirect_uri`, `state` (token CSRF de 32 bytes hex em `$_SESSION['oauth_state']`).
- **Token exchange**: `POST https://api.clickup.com/api/v2/oauth/token` com `{client_id, client_secret, code}` (JSON). Resposta: `access_token`.
- **Storage**: access token guardado em `$_SESSION['clickup_token']` (PHP session cookie, `httponly`, `secure`, `samesite=Lax`). Nunca persistido em DB.
- **Sem refresh token**. Quando o token expira, o utilizador volta a fazer login.

### Timeouts de sessão (`includes/session.php`)
- Absoluto: 8 horas desde `created_at`.
- Inatividade: 2 horas desde `last_activity`.

## Endpoints consumidos

| Método | Path                                                | Onde                                   | Para quê                                                 |
|--------|-----------------------------------------------------|----------------------------------------|----------------------------------------------------------|
| POST   | `/oauth/token`                                      | `includes/clickup.php` (`clickup_exchange_token`) | Troca de `code` por `access_token`                        |
| GET    | `/user`                                             | `oauth/callback.php` → `clickup_get_user`         | Perfil do utilizador autenticado                          |
| GET    | `/team`                                             | `oauth/callback.php`, `api/workspaces.php` → `clickup_get_workspaces` | Lista workspaces (teams) do utilizador                    |
| GET    | `/team/{workspace_id}/task?assignees[]=&list_ids[]=&subtasks=true&include_closed=false&page=N` | `api/sync.php`, `api/sync_worker.php` | Tarefas do utilizador numa lista (paginação até 20 páginas × 100) |
| GET    | `/list/{list_id}/task?assignees[]=&subtasks=true&include_closed=false&page=N` | `api/sync.php`, `api/sync_worker.php` (fallback) | Fallback para utilizadores convidados (o team endpoint falha) |
| GET    | `/task/{task_id}`                                   | sync — walk up parent chain            | Preencher breadcrumbs (pais que não estão assigned)       |
| GET    | `/task/{task_id}?include_subtasks=true`             | sync — siblings                         | Ir buscar Copy/Design do mesmo post                        |
| GET    | `/task/{task_id}` (para Copy)                       | sync — descrição do Copy                | O payload `subtasks` não traz `description`, tem de ser refetched |

**Base URL**: `CLICKUP_API_BASE = https://api.clickup.com/api/v2`.

## Tratamento de erros

- `clickup_http()` devolve `{ok, status, body, error}`. Considera sucesso `200–299`.
- Em `api/sync.php` o team endpoint é tentado primeiro; se falhar, cai para o list endpoint (padrão útil para guest users). Se ambos falharem, `db_log_sync_end('error', ..., $result['error'])`.
- `callback.php` redireciona para `/login.php?error=...` em qualquer falha de estado/code/exchange.
- 401 nos endpoints `/api/*` = `Not authenticated`. 403 = CSRF inválido ou admin-only (`report.php`).

## Rate limits

**Não há tratamento.** O ClickUp aplica ~100 req/min por token. Durante um sync grande, o código faz:

- N páginas de tarefas (até 20)
- 1 `GET /task/{id}` por cada parente em falta (até profundidade 10)
- 1 `GET /task/{id}?include_subtasks=true` por cada post único
- 1 `GET /task/{id}` adicional por cada Copy sibling

Isto pode estourar o rate limit em workspaces grandes. Não há backoff, retry, nem leitura de cabeçalhos `X-RateLimit-*`. Qualquer spec que aumente o volume de chamadas tem de ser avaliada aqui.

## Retries

Nenhum. Qualquer falha de rede é terminal no sync corrente.

## Dados sincronizados para o SQLite

Para cada tarefa (`db_upsert_task`):

- Identidade: `id`, `custom_id`, `url`
- Conteúdo: `name`, `description` (de `text_content` ou `description`)
- Estado: `status_name`, `status_color`
- Prioridade: `priority_id` (1=Urgent→4=Low), `priority_label`
- Datas: `due_date`, `start_date` (ms epoch do ClickUp), `date_created`, `date_updated`, `synced_at`
- Hierarquia: `parent_id`, `list_id`, `list_name`, `folder_id`, `folder_name`, `workspace_id`
- Tipo: `task_type` (campo nativo ou custom field "Task Type")
- Collections: `assignees` (JSON), `tags` (JSON)
- Enriquecimento: `approval_rejected` (heurística `approval design → design`)

E ainda:

- `task_assignees(task_id, user_id)` — tabela de lookup derivada de `assignees[*].id`
- `task_notifications` — linha por cada mudança de campo observada em tarefas `watched`
- `sync_log` — uma linha por cada operação de sync (status, progress, task_count)
- `config_lists` — lista sincronizada (apenas uma é guardada hoje)
- `watched_tasks` — tarefas "seguidas" pelo utilizador, para gerar notifications

## Lista por omissão

`api/sync.php:105` tem **hardcoded** `list_id = '46726233'` quando o POST não o fornece. Qualquer mudança no modelo "content/editorial" depende deste ID.
