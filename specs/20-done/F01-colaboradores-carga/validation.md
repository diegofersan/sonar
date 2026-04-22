# F01 — Validação

Data: 2026-04-22.
Status: pronto para aceitação do utilizador. Tudo o que é testável sem sessão ClickUp em
produção está validado; os pontos **[prod]** dependem de uma sessão real do chefe de
departamento e ficam marcados como "a confirmar no deploy".

---

## 1. Testes automatizados

Todos os scripts em `tests/test_*.php` criados para F01 passam sem falhas:

| Ficheiro                                      | Passed | Failed |
| --------------------------------------------- | -----: | -----: |
| `tests/test_collaborators_aggregate.php`      |     31 |      0 |
| `tests/test_is_department_head.php`           |      5 |      0 |
| `tests/test_weekly_hours.php`                 |      5 |      0 |
| `tests/test_sync_log_scope.php`               |      6 |      0 |
| `tests/test_clickup_design_group.php`         |     11 |      0 |
| `tests/test_time_entries_db.php`              |     15 |      0 |
| **Total**                                     | **73** |  **0** |

Reexecutar com:

```sh
for t in tests/test_collaborators_aggregate.php \
         tests/test_is_department_head.php \
         tests/test_weekly_hours.php \
         tests/test_sync_log_scope.php \
         tests/test_clickup_design_group.php \
         tests/test_time_entries_db.php; do
  php "$t" || echo "FAIL: $t";
done
```

---

## 2. Lints / sanity checks

- `php -l dashboard.php` → sem erros.
- `php -l api/collaborators.php` → sem erros.
- `php -l api/sync_time_entries.php` → sem erros.
- `php -l includes/collaborators.php` → sem erros.
- `node --check assets/js/app.js` → sem erros.
- Smoke via `php -S`:
  - `GET /dashboard.php` sem sessão → 302 para `/login.php`.
  - `GET /api/collaborators.php` sem sessão → 401 `{"error":"Not authenticated"}`.
  - `GET /api/sync_time_entries.php` sem sessão → 401.

Nenhum fatal no `error_log`.

---

## 3. Critérios de aceitação (da spec)

### Local — validados nesta sessão

- [x] **Navbar no topo com "Linha Editorial" + "Colaboradores".**
  `dashboard.php`: `<nav class="app-navbar">` com dois `<a data-nav>`. O segundo só é
  emitido dentro de `<?php if ($isDepartmentHead): ?>`.
- [x] **Utilizadores não-chefe não veem a entrada "Colaboradores" (server-side).**
  `dashboard.php` usa `is_department_head()` para condicionar a emissão HTML; `api/collaborators.php`
  e `api/sync_time_entries.php` fazem o mesmo gate e devolvem 403 a não-chefes
  (teste manual da UI + inspecção do código).
- [x] **Heading "Minhas Tarefas" renomeado para "Linha Editorial"** em `dashboard.php`.
- [x] **Cada semana exibe mon–sun + total** no markup do card (`DAY_KEYS` × 7 + `Total`
  em `renderCollabCard()`), cobertura unitária em `test_collaborators_aggregate.php §4`.
- [x] **Badges de status (under / ok / over)** com thresholds 80 % / 110 %
  (`COLLAB_UNDER_RATIO` / `COLLAB_OVER_RATIO` em `includes/collaborators.php`). Cobertura
  em `test_collaborators_aggregate.php §1` (9 asserts, incluindo `weekly_hours=0`).
- [x] **Entries sem task são ignoradas** no upsert.
  `db_upsert_time_entries()` salta `$e['task']['id']` em falta; teste em
  `test_time_entries_db.php §2` ("upsert returns count ignoring task-less entries (4 of 5)").
- [x] **Página Colaboradores tem o seu próprio botão Sync** (`btn-sync-time-entries`),
  independente do botão da Linha Editorial (`btn-sync`). O handler `startTimeEntriesSync`
  chama `/api/sync_time_entries.php`; o da Linha Editorial continua a chamar `/api/sync.php`
  sem alteração funcional (só filtro de `scope='tasks'` na query).
- [x] **Syncs distinguíveis por `sync_log.scope`.** Migration em `db_migrate()`
  (ALTER TABLE idempotente). Cobertura em `test_sync_log_scope.php §1–4` (default
  `'tasks'`, insert explícito de `'time_entries'`, queries filtradas por scope).
- [x] **Sync em curso de um tipo não bloqueia o outro.**
  `test_sync_log_scope.php §5–6`: check de running scoped por `'tasks'` não vê o
  running de `'time_entries'` (e vice-versa). Também na UI: `btn-sync` e
  `btn-sync-time-entries` são DOM separados, handlers independentes.
- [x] **Endpoint valida `is_department_head()` ou devolve 403.**
  `api/collaborators.php` e `api/sync_time_entries.php` fazem `is_department_head()` antes de
  qualquer lógica. Teste do helper: `test_is_department_head.php` (5 asserts incluindo
  id=int, id=string, utilizador sem id, user desconhecido).
- [x] **Nenhum sync existente fica mais lento / faz mais chamadas.**
  `api/sync.php` só ganhou filtro `scope='tasks'` (custo O(index lookup)). Não chama
  novos endpoints ClickUp. O sync de Colaboradores é inteiramente separado.

### [prod] — a confirmar pelo utilizador após deploy

- [ ] **Mês corrente mostra uma secção por membro do grupo "design".**
  Requer sessão real: depende de `clickup_find_design_group()` encontrar grupo com
  nome contendo "design" case-insensitive e de `WEEKLY_HOURS_PER_USER` estar preenchido
  em `config.local.php` **em produção**.
- [ ] **Total semanal marcado visualmente como under / ok / over.**
  Lógica validada em testes, mas o look-and-feel final em produção deve ser visto uma
  vez para confirmar contraste.
- [ ] **Página carrega em < 3 s num mês típico.**
  Não-validável localmente (não há cache populada nem latência ClickUp real). Em
  produção, um mês típico faz 1 query ao SQLite (tabela indexada por
  `(workspace_id, user_id, start_ms)`) + 1 chamada `/group?team_id={id}` a ClickUp — deve
  ficar bem abaixo dos 3 s.

---

## 4. Chamadas à API ClickUp por sync de Colaboradores (para KI-05)

Por `POST /api/sync_time_entries.php`, o sync faz exatamente **2 requests** à API
do ClickUp:

1. `GET /group?team_id={workspace_id}` — roster do grupo de design.
2. `GET /team/{workspace_id}/time_entries?start_date=…&end_date=…&assignee=<csv>`
   — todos os membros numa só chamada (CSV de IDs confirmado no spike).

Não há paginação implementada: no spike (2026-04-22) a janela do mês corrente
devolveu < 30 entries, abaixo do limite da API. Se um dia passarmos desse volume
será preciso paginar aqui.

**Impacto em KI-05**: acréscimo marginal (2 req/sync manual), folga confortável nos
~100 req/min. Cada sync gasta a mesma ordem de grandeza que um sync de Linha Editorial
mais pequeno.

---

## 5. Pendências operacionais (fora da feature, responsabilidade do utilizador)

1. **Preencher `config.local.php` em produção** com:
   - `DEPARTMENT_HEAD_USER_ID` (já definido como default `'473908'` em `config.php`, mas
     override/env disponível se mudar).
   - `WEEKLY_HOURS_PER_USER` (mapa `user_id => horas`) — chave para que os badges under/ok/over
     façam sentido. Sem este mapa, todos os colaboradores usam `DEFAULT_WEEKLY_HOURS` (40 h).
2. **Rotação da API key** `pk_473908_YNV…` que foi colada em chat no início deste ciclo.
   Não faz parte do scope de F01 mas fica aqui como lembrete.

---

## 6. Próximo passo

Mover esta pasta para `specs/20-done/` no mesmo commit de conclusão.
