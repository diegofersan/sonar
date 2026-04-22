# F01 — Tasks

Decomposição executável da spec. Uma tarefa = um commit (mensagem `F01: …`).
Marcar `[x]` à medida que cada uma termina.

**Regra importante:** nada de código de produção até a Tarefa 1 (spike) voltar
e a Tarefa 2 (checkpoint com o utilizador) confirmar o desenho.

---

## Fase A — Viabilidade

### [x] 1. Spike: viabilidade da API ClickUp

**Saída**: `tests/spike_collaborators.php` — script PHP puro, standalone, que com um
token OAuth e um `team_id` válidos imprime:

- Resposta bruta de `GET /team/{team_id}/group` (lista de grupos do workspace).
- Tentativa de identificar o grupo "design" e listar membros (payload do próprio
  `/group` ou chamada adicional a `/group/{id}`).
- **Resposta de um endpoint candidato a expor `weekly_hours`** para um membro.
  Candidatos a testar em ordem: `/user`, `/team/{id}` (secção `members`),
  `/group/{id}/member`. Imprimir payload cru e destacar o campo encontrado.
- `GET /team/{team_id}/time_entries?start_date=…&end_date=…&assignee=…` para o
  mês corrente e 1–2 colaboradores. Testar `assignee` com múltiplos IDs
  separados por vírgula; anotar se funcionou.
- Tempo total de execução e número de chamadas feitas (para avaliar rate limit).

**Convenções**:

- Lê token e team_id de argv: `php tests/spike_collaborators.php <token> <team_id>`.
- Usa `includes/clickup.php` (`clickup_api_get`) — não duplicar HTTP.
- Nunca chama endpoints de escrita. Nunca toca em `data/sonar.db`.
- Se algum endpoint retornar 401/403, continuar com os outros e reportar.

**Commit**: `F01: spike viabilidade API time tracking e grupos`

---

### [x] 2. Checkpoint pós-spike

Concluído em 2026-04-22. Ver `spec.md § Decisões pós-spike`.

Resumo:
1. Grupo "design" → `GET /group?team_id={id}`, match por nome, membros embutidos.
2. `weekly_hours` → **não acessível** (requer plano Enterprise). Fallback confirmado: **Opção A** (constante `WEEKLY_HOURS_PER_USER` em `config.php` + `config.local.php`).
3. Time entries → ms epoch, `assignee` aceita CSV de IDs, 0/30 entries sem task no spike.
4. Rate limit → 2 calls por sync, sem preocupações. Cache `time_entries` mantém-se para o GET do endpoint.

---

## Fase B — Infraestrutura (depende da Fase A)

### [x] 3. Schema: `time_entries` + `sync_log.scope`

- `includes/database.php`:
  - Adicionar `CREATE TABLE IF NOT EXISTS time_entries(...)` ao `db_migrate()` com o shape da spec (+ índice `(workspace_id, user_id, start_ms)`).
  - Adicionar `ALTER TABLE sync_log ADD COLUMN scope TEXT NOT NULL DEFAULT 'tasks'` dentro de try/catch (mesmo padrão de `progress` / `approval_rejected`).
  - Helpers novos: `db_upsert_time_entries(array, string $workspace_id)`, `db_get_time_entries(string $workspace_id, array $user_ids, int $start_ms, int $end_ms)`.
- `tests/test_time_entries_db.php` — smoke: migrate, upsert de 3 entries, query por intervalo, contagem por dia da semana.

**Commit**: `F01: adiciona tabela time_entries e coluna sync_log.scope`

---

### [x] 4. Auth: chefe de departamento + config de weekly_hours

- `config.php`:
  - Constante `DEPARTMENT_HEAD_USER_ID` (override em `config.local.php` e env var, como as credenciais OAuth).
  - Constante `WEEKLY_HOURS_PER_USER` (mapa `user_id => horas`, default vazio).
  - Constante `DEFAULT_WEEKLY_HOURS` (default 40, fallback para user_ids não mapeados).
- `config.local.php.example`: acrescentar as três chaves com valores reais comentados.
- `includes/session.php`: helper `is_department_head(): bool` que compara `get_user()['id']` com a constante (cast para string dos dois lados).
- `includes/session.php` ou novo `includes/capacity.php` (o que for mais simples): helper `get_weekly_hours(string $user_id): int` que consulta o mapa com fallback ao default.
- `tests/test_is_department_head.php` — assert true para `473908`, false para outros IDs e para sessão não autenticada.
- `tests/test_weekly_hours.php` — assert lookup no mapa, fallback ao default para user desconhecido.

**Commit**: `F01: adiciona role de chefe de departamento e config de weekly_hours`

---

### [x] 5. Cliente ClickUp: grupos, weekly_hours, time entries

Em `includes/clickup.php`, adicionar os helpers necessários conforme o spike:

- `clickup_get_groups(string $token, string $team_id)` (+ variante para membros, se necessário).
- `clickup_get_user_weekly_hours(...)` — assinatura final depende do spike.
- `clickup_get_time_entries(string $token, string $team_id, int $start_ms, int $end_ms, array $assignee_ids)` — já considerar múltiplos assignees se o spike confirmou.
- Sem lógica de agregação aqui — isto é HTTP + JSON só. Agregação vive no endpoint.

**Commit**: `F01: adiciona helpers ClickUp para grupos e time entries`

---

## Fase C — Endpoints

### [x] 6. `api/sync_time_entries.php`

- POST: valida auth + `is_department_head()` (403 se falhar). Segue o padrão de `api/sync.php`:
  - stale timeout, cancel de running com `force`, `session_write_close`, `fastcgi_finish_request`.
  - `db_log_sync_start(workspace, user, null, scope='time_entries')`.
  - Busca membros do grupo design, determina intervalo do mês corrente, chama `clickup_get_time_entries`, `db_upsert_time_entries`.
- GET: devolve `{running, progress, last_sync, last_count}` filtrando `sync_log` por `scope='time_entries'` e `user_id` atual.
- `api/sync.php` — ajuste mínimo: GET filtra `scope='tasks'` (ou `scope IS NULL OR scope='tasks'` para compat com rows antigas); POST passa `scope='tasks'` ao `db_log_sync_start`. Confirmar que `db_log_sync_start` aceita o novo parâmetro sem partir a assinatura (usar parâmetro com default).

**Commit**: `F01: adiciona endpoint sync_time_entries`

---

### [x] 7. `api/collaborators.php`

- GET: valida auth + `is_department_head()`. Para o mês corrente:
  - Lista membros do grupo design (via helpers da Tarefa 5).
  - Lê `weekly_hours` de cada.
  - Lê time entries do mês da cache (`db_get_time_entries`) — se não houver nada, devolver `last_sync: null` e array vazio mas 200 OK (UI mostra "Ainda não sincronizado").
  - Agrega por **semana ISO** e por **dia da semana** (seg–dom). Status de cada semana: `under` (< 80% weekly_hours), `ok` (80–110%), `over` (> 110%). Thresholds como constantes no topo do ficheiro (fácil de afinar depois).
- Formato de resposta: exatamente o da spec (`[{user, weekly_hours, weeks: [...]}]`).

**Commit**: `F01: adiciona endpoint collaborators`

---

## Fase D — Frontend

### [x] 8. Navbar + rename "Linha Editorial"

- `dashboard.php`:
  - Acrescenta navbar de topo (componente HTML, sem framework) com "Linha Editorial" sempre + "Colaboradores" dentro de `<?php if (is_department_head()): ?>`.
  - Renomeia o heading `"Minhas Tarefas"` → `"Linha Editorial"` (na `.toolbar`).
  - A vista atual fica dentro de um wrapper `<section data-view="editorial">`. Novo wrapper `<section data-view="collaborators">` vazio por agora.
- `assets/css/style.css`: estilos mínimos da navbar (respeitar CSP — sem `style` inline novo).
- `assets/js/app.js`: router simples de views (mostrar/esconder por `data-view`), sem alterar o comportamento das tabs da Linha Editorial.

**Commit**: `F01: adiciona navbar e renomeia Linha Editorial`

---

### [x] 9. Vista Colaboradores

- `assets/js/app.js`:
  - No load da view "collaborators", GET `/api/collaborators.php` e render.
  - Botão Sync próprio: POST `/api/sync_time_entries.php`, polling via GET a cada ~2s (reutilizar o padrão do botão Sync existente, sem o copiar à força — factorizar se for curto, não se for muito).
  - Render por colaborador: cabeçalho (nome, weekly_hours), lista de semanas. Cada semana: 7 células (seg–dom em horas), total, badge de status com cor.
  - Mensagem "Ainda sem dados — clica Sync" quando `last_sync` é null.
- `assets/css/style.css`: grelha das semanas, cores dos status.

**Commit**: `F01: adiciona vista Colaboradores`

---

## Fase E — Validação

### [x] 10. Validação manual + writeup

- Criar `specs/10-active/F01-colaboradores-carga/validation.md` com:
  - Checklist manual (local + produção) dos critérios de aceitação da spec.
  - Resultado do `php tests/test_*.php` (passam todos).
  - Nota de quantas chamadas à API ClickUp um sync de Colaboradores fez (para contexto de KI-05).
- Se tudo OK, mover a pasta `F01-colaboradores-carga/` para `specs/20-done/`.

**Commit**: `F01: conclui feature Colaboradores (validation + done)`
