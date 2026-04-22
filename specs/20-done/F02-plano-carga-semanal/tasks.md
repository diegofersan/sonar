# F02 — Tasks

Decomposição executável da spec F02. Uma tarefa = um commit (`F02: …`).
Marcar `[x]` à medida que cada uma termina.

**Nota:** após o spike da Tarefa 1, a spec foi pivotada de "horas planeadas" para
"contagem de tasks activas por dia" (só 3 % das tasks têm `time_estimate`). Ver
`spec.md § Decisões pós-spike`.

---

## Fase A — Viabilidade

### [x] 1. Spike: medir qualidade dos dados

`tests/spike_workload.php` corrido em 2026-04-22. Resultados e veredicto na spec.
Descoberta central: `time_estimate` só em 3 % das tasks → obriga a repensar D1.

### [x] 2. Checkpoint pós-spike

Concluído em 2026-04-22. Pivot para forecast por contagem aprovado. Decisões revistas
e critérios de aceitação actualizados na `spec.md § Decisões pós-spike`. Esta tarefa
vai com commit próprio por incluir revisão da spec + do próprio `tasks.md`.

---

## Fase B — Config

### [x] 3. Config: alvo diário de tasks por colaborador (D9)

- `config.php`:
  - `DAILY_TASKS_PER_USER` — mapa `user_id => int` (default `[]`), override em
    `config.local.php` (chave `daily_tasks_per_user`) e env var, mesmo padrão de
    `WEEKLY_HOURS_PER_USER`.
  - `DEFAULT_DAILY_TASKS` — default `5`, override via `default_daily_tasks` ou env var.
- `config.local.php.example`: acrescenta as duas chaves comentadas.
- `includes/session.php`: helper `get_daily_tasks_target(string $user_id): int` com
  lookup no mapa + fallback ao default. Mesma forma do `get_weekly_hours()`.
- `tests/test_daily_tasks_target.php`: 4–5 asserts — mapped user, unmapped fallback,
  `''` → fallback, user com target 0 devolve 0 (não default).

**Commit:** `F02: adiciona config de alvo diário de tasks`

---

## Fase C — Lógica preditiva

### [x] 4. Pure helpers: distribuição + agregação por contagem

`includes/workload.php` — helpers puros, testáveis sem DB/HTTP:

- `const FORECAST_UNDER_RATIO = 0.80; FORECAST_OVER_RATIO = 1.10;` (iguais F01).
- `const FORECAST_TERMINAL_STATUSES = ['published', 'mês social terminado',
  'post cancelado', 'linha editorial cancelada', 'scheduled', 'ready to post'];`
  Case-insensitive na comparação. Flipar é 1 linha.
- `forecast_current_week(DateTimeZone $tz, ?DateTimeImmutable $now = null): array`
  → devolve 5 `DateTimeImmutable` (seg..sex, 00:00 no tz).
- `forecast_is_terminal(?string $status_name): bool` — lowercase trim + `in_array`.
- `forecast_task_days(array $task, DateTimeImmutable $now, DateTimeZone $tz): array`
  → aplica modelo C em modo contagem:
    - Terminal → `[]`.
    - Overdue (due < now, status não terminal) → devolve `[$now->format('Y-m-d')]`.
    - `start_ms` + `due_ms`: enumera dias úteis entre os dois (inclusive).
    - Só `due_ms`: fallback 1 dia útil imediatamente antes do due.
    - Só `start_ms`: usa o start como único dia (sem due não sabemos quanto mais).
    - Ambos null: `[]`.
  Devolve array de strings `YYYY-MM-DD`, sempre dias úteis, Europe/Lisbon.
- `forecast_aggregate(array $tasks_by_user, int $daily_target, array $weekdays,
  DateTimeImmutable $now, DateTimeZone $tz): array`
  → para cada dia útil, devolve
  `{date, weekday, active_count, target, status, overdue_count, undated_count}`.
  Regra D5: se uma task tem `id` que aparece como `parent_id` noutra task do mesmo
  input, só os filhos contam — a parent é skipada.
- `forecast_status(int $count, int $target): string`.

`tests/test_forecast.php` — asserts mínimos:
- `forecast_is_terminal` — case-insensitive, cobre todos da lista + alguns não-terminais.
- `forecast_task_days` — 6 cenários: start+due spread; só due → fallback; só start;
  ambos null → skip; overdue → hoje; terminal → skip; span atravessa fim-de-semana
  (só dias úteis).
- `forecast_aggregate` — parent skipada quando tem filhos no input; undated_count
  incrementa; overdue_count incrementa; `under/ok/over` com thresholds 80/110;
  `daily_target = 0` degenera (qualquer task → over).

**Commit:** `F02: adiciona helpers puros de forecast por contagem`

---

## Fase D — Endpoint

### [x] 5. `api/workload_forecast.php`

- GET only. `is_authenticated()` → 401. `is_department_head()` → 403.
- Fluxo:
  - `clickup_find_design_group()` + `clickup_group_member_ids()` (reuso F01).
  - `forecast_current_week()` → janela seg–sex.
  - Query SQLite única: tasks do workspace com `JOIN task_assignees` IN membros,
    `status_name` fora de `FORECAST_TERMINAL_STATUSES` (comparação case-insensitive
    no PHP, não em SQL, para preservar a constante como única fonte de verdade).
    Trazer `start_date`, `due_date`, `parent_id`, `status_name`, `name`, `id`.
  - Agrupar por user_id, correr `forecast_aggregate` por colaborador.
- Resposta:
  ```json
  {
    "week_start": "2026-04-20",
    "week_end":   "2026-04-24",
    "collaborators": [
      {
        "user": { "id", "username", "profilePicture", ... },
        "daily_target": 5,
        "days": [
          { "date": "2026-04-20", "weekday": "mon",
            "active_count": 6, "target": 5,
            "overdue_count": 1, "undated_count": 2, "status": "over" },
          ...
        ]
      }
    ]
  }
  ```

**Commit:** `F02: adiciona endpoint workload_forecast`

---

## Fase E — Frontend

### [x] 6. Vista "Plano da semana"

- `dashboard.php`:
  - Entrada nova na navbar: `<a data-nav="forecast">Plano da semana</a>` entre
    "Linha Editorial" e "Colaboradores", condicional a `$isDepartmentHead`.
  - Nova `<section class="view" data-view="forecast">` com toolbar (título + label
    da semana) e container `#forecast-list`. **Sem** botão Sync próprio — depende do
    sync de tasks já existente na Linha Editorial.
- `assets/js/app.js`:
  - `fetchForecast()` → GET `/api/workload_forecast.php` e render.
  - `renderForecastCard(c)` — por colaborador, grelha 5 colunas (seg–sex):
      - Número grande: `active_count` / `target`.
      - Badge com status (`under` / `ok` / `over`).
      - Se `overdue_count > 0`: ícone + número pequeno "N overdue".
      - Se `undated_count > 0`: texto cinzento "+N sem data".
  - Router: lazy-load no primeiro `showView('forecast')`, como a Colaboradores.
- `assets/css/style.css`: grelha 5 colunas, reutilizar `.collab-badge.under/ok/over`.
  Nova classe `.forecast-overdue` (pequeno ícone/alerta) e `.forecast-undated`.

**Commit:** `F02: adiciona vista Plano da semana`

---

## Fase F — Validação

### [ ] 7. Validação manual + writeup

- `specs/10-active/F02-plano-carga-semanal/validation.md`:
  - Checklist manual (local + produção) dos critérios de aceitação revistos.
  - Resultado de `php tests/test_*.php` (todos passam).
  - Nota sobre lista de status terminais — se a equipa reportar que `scheduled` ou
    `ready to post` ainda têm trabalho de design, flipar em `FORECAST_TERMINAL_STATUSES`.
  - Contagem típica: quantas tasks entram no forecast de um sync real (para contexto
    de KI-05 — aqui deve ser 0 novas chamadas, mas queremos documentar a shape).
- Mover pasta para `specs/20-done/F02-plano-carga-semanal/`.

**Commit:** `F02: conclui feature Plano da semana (validation + done)`
