# Known Issues — Dívida Técnica Observada

Diagnóstico apenas. Sem propostas de solução (essas vivem em specs dedicadas).

Severidade: **alta** (quebra / risco de produção), **média** (bloqueia evolução), **baixa** (cosmético / cleanup).

---

## KI-01 · `http_get_last_response_headers()` indefinida — **alta**

- **Onde**: `includes/clickup.php:98` (fallback stream).
- **Problema**: a função é chamada mas não está definida em lado nenhum do projeto (`grep` confirma). Se o servidor não tiver extensão `curl`, `clickup_http_stream()` explode com `Call to undefined function`. O fallback existe em teoria mas nunca foi testado.
- **Evidência**: `grep -R "function http_get_last_response_headers" → No matches`.

## KI-02 · `sync_worker.php` duplica lógica de `api/sync.php` — **média**

- **Onde**: `api/sync_worker.php` (CLI) vs `api/sync.php` (inline post-response).
- **Problema**: o worker CLI replica paginação, fetch de parents e siblings, e escrita em `sync_log`. O `api/sync.php` usa `fastcgi_finish_request()` + `session_write_close()` para correr tudo inline — o worker não é invocado por nenhum ponto do código (`grep sync_worker.php` só encontra o próprio ficheiro). Código órfão e pronto a divergir.

## KI-03 · Paginação com cap silencioso de 20 páginas — **alta**

- **Onde**: `api/sync.php:187`, `api/sync_worker.php:92` (`if ($page >= 20) break;`).
- **Problema**: listas grandes (>2000 tarefas do utilizador) são truncadas sem erro nem aviso no `sync_log`. O `task_count` fica em 2000 e o utilizador assume que o sync acabou com sucesso.

## KI-04 · Detecção de subtasks por substring no nome — **média**

- **Onde**: `api/sync.php` (251, 261), `api/sync_worker.php` (141, 150), `api/tasks.php` (111–114, 142), `api/report.php` (83–86), `includes/database.php:268` (`'approval design'`, `'design'`).
- **Problema**: toda a lógica de "isto é um Copy/Design" assenta em `strpos(strtolower($name), 'copy'|'design')`. Qualquer renomear no ClickUp (ex.: "Copywriting", "Arte") quebra a classificação silenciosamente. O estado "approval design → design" também é string-matched.

## KI-05 · Sem tratamento de rate limit da API ClickUp — **alta**

- **Onde**: transversal (`includes/clickup.php`, `api/sync.php`).
- **Problema**: não há leitura de `X-RateLimit-*`, nem backoff/retry em 429. Um sync num workspace grande pode esgotar a quota (~100 req/min) e cair com erros sucessivos. Não há feedback no UI além de "API error".

## KI-06 · Verificação de admin por substring do nome, duplicada — **alta** (segurança)

- **Onde**: `dashboard.php:25`, `api/report.php:39`.
- **Problema**: `stripos($rawName, 'diego ferreira') !== false || stripos($rawName, 'diego') !== false`. Qualquer utilizador chamado "Diego X" passa. A string está hardcoded em dois sítios. Não há tabela de roles.

## KI-07 · CSRF validation inlined em `api/sync.php` — **baixa**

- **Onde**: `api/sync.php:60–66` faz `hash_equals` manualmente em vez de chamar `require_csrf()` (existente em `includes/session.php`). As outras APIs usam o helper.
- **Problema**: divergência de estilo e risco de desalinhamento se a lógica de CSRF mudar.

## KI-08 · Sem foreign keys explícitas apesar de `PRAGMA foreign_keys = ON` — **média**

- **Onde**: `includes/database.php` (schema).
- **Problema**: `task_assignees.task_id`, `watched_tasks.task_id`, `task_notifications.task_id` relacionam-se com `tasks.id` por convenção mas não declaram FK. `db_clear_list_tasks()` precisa apagar manualmente os assignees (linha 537). Qualquer caminho que apague `tasks` sem essa manutenção deixa órfãos.

## KI-09 · Migrations por `ALTER TABLE` + try/catch, sem versioning — **média**

- **Onde**: `includes/database.php:142–153` (adição de `progress` e `approval_rejected`).
- **Problema**: novas colunas são anexadas com try/catch — funciona mas não há registo do que já foi aplicado, não é possível descer uma migration, e o método não serve para backfills, renomeações ou drops.

## KI-10 · Default list ID hardcoded — **média**

- **Onde**: `api/sync.php:105` (`$listId = $input['list_id'] ?? '46726233';`).
- **Problema**: o ID está no código. `config_lists` existe mas só é *gravada*, nunca *lida* para determinar o que sincronizar. O memory do projeto menciona outro ID (`2f1ta-4981`) — divergência entre documentação e código.

## KI-11 · Access token sem refresh — **média**

- **Onde**: `includes/session.php`, `oauth/callback.php`.
- **Problema**: o token vive só na sessão PHP e não há `refresh_token`. Quando expira, o utilizador é deitado fora sem mensagem específica.

## KI-12 · Ficheiros monolíticos e sem testes — **média**

- **Onde**:
  - `includes/database.php` — 849 linhas, responsabilidades misturadas (connection, migrations, tasks, sync log, config, watched, notifications).
  - `assets/js/app.js` — ficheiro único para toda a UI.
  - `assets/css/style.css` — 27 KB, único.
- **Problema**: nenhum teste automatizado. Não há `composer.json`, nem PHPUnit, nem fixtures. Refactors dependem inteiramente de teste manual.

## KI-13 · `test_copy.php` referenciado no deploy mas ausente — **baixa**

- **Onde**: `.github/workflows/deploy.yml:29` exclui `test_copy.php` de upload FTP, mas o ficheiro não existe no repo. Resíduo de código de debug removido.

## KI-14 · Unidades de tempo inconsistentes — **baixa**

- **Onde**: `tasks.due_date`, `start_date` em **milissegundos**; `synced_at`, `started_at`, `completed_at`, `created_at` em **segundos**. Comparações em `api/report.php:47` (`$nowMs = time() * 1000`) e `api/tasks.php:9` (`$now = time() * 1000`) repetem a conversão sem helper.
- **Problema**: fácil esquecer a conversão num sítio e introduzir bugs difíceis de detetar (datas ~1970 ou ~3000).

## KI-15 · Validação de input frágil em endpoints — **média** (segurança)

- **Onde**: `api/sync.php:105` (`list_id` vindo do body é concatenado em URL sem validação), `api/select-workspace.php:44` (`workspace_id` idem).
- **Problema**: são IDs usados em `"/team/{$workspaceId}/task"` e `"/list/{$listId}/task"`. A superfície é limitada (só utilizadores autenticados via OAuth), mas não há validação de formato. URL injection contra a API do ClickUp é teoricamente possível se o atacante já tiver sessão.

## KI-16 · `config_lists` subutilizada — **baixa**

- **Onde**: `db_save_list_config()` e `db_get_enabled_lists()` existem mas só `save` é chamado. O sync só processa a lista passada no POST (ou o default hardcoded).
- **Problema**: infra a meio caminho — ou completar o multi-list, ou remover a tabela.

## KI-17 · Stream fallback nunca extrai status code no path normal — **baixa**

- **Onde**: `includes/clickup.php:95–101`.
- **Problema**: ligado a KI-01 — mesmo que `http_get_last_response_headers` fosse definida, a lógica assume que os headers estão disponíveis sempre que o request não falha, o que depende da extensão/versão de PHP.
