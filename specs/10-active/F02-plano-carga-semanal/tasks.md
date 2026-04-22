# F02 — Tasks

Decomposição executável da spec F02. Uma tarefa = um commit (`F02: …`).
Marcar `[x]` à medida que cada uma termina.

**Regra importante:** a Tarefa 1 (spike) corre com DB real. Se os números contradisserem
alguma decisão em `spec.md § Decisões`, **pausar** e pedir ao utilizador antes de
avançar para a Tarefa 2.

---

## Fase A — Viabilidade

### [ ] 1. Spike: medir qualidade dos dados

**Saída:** `tests/spike_workload.php` — script PHP standalone que abre o SQLite e
imprime:

- Total de tasks na cache (workspace corrente).
- % com `time_estimate` > 0 (hoje não persistimos → provavelmente 0 %; é preciso
  confirmar o payload ClickUp antes — ver nota abaixo).
- % com `start_date` não-null.
- % com `due_date` não-null.
- Parent/subtask: contar tasks que têm ambos parent com estimate E subtasks com
  estimate (para validar D5).
- Status terminais no workspace: listar os distintos `status_name` + count, para
  o utilizador confirmar quais deve excluir a agregação preditiva.

**Nota:** como `time_estimate` ainda não está persistido, o spike tem de fazer
**uma** chamada (read-only) a `/team/{id}/task?page=0&…` para uma amostra e medir
a % de tasks com `time_estimate > 0` no payload. Alternativa, mais barata: inspecionar
o JSON já guardado em logs de sync anteriores, se existir.

**Convenções:**
- Read-only. Nunca escreve na DB.
- Lê token OAuth de `$_SESSION` via helper existente **ou** de argv — o que for mais
  simples.
- Imprime um bloco **"Validação de D1/D2/D5/D4"** com veredicto ("OK" / "reavaliar").

**Commit:** `F02: spike qualidade dos dados de estimativa`

---

### [ ] 2. Checkpoint pós-spike

Revisão das decisões D1/D2/D5/D4 com base nos números do spike. Acrescentar uma
secção **"Decisões pós-spike"** no `spec.md` (como foi feito em F01) com:
- Os números concretos medidos.
- "Mantém" ou "altera para" por cada decisão afectada.

Se altera algo, o utilizador confirma antes de avançar.

*Sem commit próprio — edita `spec.md` e vai junto com a Tarefa 3.*

---

## Fase B — Persistência

### [ ] 3. Schema: `tasks.time_estimate_ms`

- `includes/database.php`:
  - Em `db_migrate()`, dentro do bloco try/catch existente para colunas novas
    (padrão KI-09), adicionar `ALTER TABLE tasks ADD COLUMN time_estimate_ms INTEGER`.
  - No `db_upsert_tasks()`, extrair `$task['time_estimate']` (pode ser int ou string)
    e persistir como int ou null. Campo é tolerante a ausência no payload.
- `tests/test_tasks_time_estimate.php` — smoke:
  - Migrate em DB temp.
  - Upsert de 3 tasks: uma com `time_estimate: 3600000`, uma com `"0"`, uma sem o
    campo → ler de volta e confirmar `3600000 / 0 / null` respectivamente.
  - Confirmar idempotência (`db_migrate()` duas vezes não parte).

**Commit:** `F02: persiste time_estimate_ms na tabela tasks`

---

## Fase C — Lógica preditiva

### [ ] 4. Pure helpers: distribuição + agregação

`includes/workload.php` — helpers puros, testáveis sem DB/HTTP:

- `const WORKLOAD_UNDER_RATIO`, `WORKLOAD_OVER_RATIO` (reutilizar os 0.80 / 1.10 do F01).
- `workload_current_week(DateTimeZone $tz, ?DateTimeImmutable $now = null): array`
  → devolve `[Mon, Tue, Wed, Thu, Fri]` como `DateTimeImmutable[]` (sem sáb/dom,
  decisão D3).
- `workload_distribute(array $task, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, DateTimeZone $tz): array`
  → aplica modelo C (D2):
    - Status terminal → `[]`.
    - Sem `time_estimate_ms` → `[]` (D1 trata visualização; aqui não entra em horas).
    - `start_ms` e `due_ms` definem o intervalo; se `start_ms` null, usa `due_ms - 1 dia útil`.
    - Se o intervalo intersecta o passado E a task está overdue, consolida tudo em `$now` (D4).
    - Se ambos null, devolve `[]`.
    - Filtra dias úteis no intervalo intersectado com [windowStart, windowEnd].
    - Devolve `['YYYY-MM-DD' => hours, ...]`, soma = estimativa total distribuída.
- `workload_aggregate(array $tasks, int $weekly_hours, array $weekdays, DateTimeZone $tz, ?DateTimeImmutable $now): array`
  → recebe tasks de um colaborador + metadados e devolve, por dia útil:
  `{date, planned_hours, capacity_hours, status, overdue_count, unestimated_count}`.
- `workload_status(float $hours, float $capacity): string` → `under/ok/over` com os
  mesmos thresholds do F01.

`tests/test_workload_distribute.php` + `tests/test_workload_aggregate.php`
(podem ser um só ficheiro, estilo F01):
- start=null + due=quinta → 8h toda em quarta (fallback 1 dia útil).
- start=segunda + due=sexta, 40h → 8h/dia seg..sex.
- task span atravessa fim-de-semana: estimate só cai em dias úteis.
- task overdue: consolidada em `$now`.
- task cancelada: ignorada.
- task sem estimate: ignorada pelo distribute, conta no counter do aggregate.
- weekly_hours=0: qualquer planeado → `over`.

**Commit:** `F02: adiciona helpers puros de distribuição e agregação`

---

## Fase D — Endpoint

### [ ] 5. `api/workload_forecast.php`

- GET only. `is_authenticated()` → 401. `is_department_head()` → 403.
- Fluxo:
  - `clickup_find_design_group()` + `clickup_group_member_ids()` (reuso F01).
  - Query SQLite: tasks do workspace com `task_assignees` IN membros + status não
    terminal + (due_date IS NOT NULL OR start_date IS NOT NULL) + janela da semana
    intersecta `[start_date, due_date]`.
  - Para cada membro: `workload_aggregate(...)`.
- Resposta:
  ```json
  {
    "week_start": "2026-04-20",
    "week_end":   "2026-04-24",
    "collaborators": [
      {
        "user": { "id", "username", "profilePicture", ... },
        "weekly_hours": 40,
        "daily_capacity": 8,
        "days": [
          { "date": "2026-04-20", "weekday": "mon", "planned_hours": 6,
            "overdue_count": 1, "unestimated_count": 2, "status": "under" },
          ...
        ]
      }
    ]
  }
  ```

**Commit:** `F02: adiciona endpoint workload_forecast`

---

## Fase E — Frontend

### [ ] 6. Vista "Plano da semana"

- `dashboard.php`:
  - Entrada nova na navbar: `<a data-nav="forecast">Plano da semana</a>` entre
    "Linha Editorial" e "Colaboradores", condicional a `$isDepartmentHead`.
  - Nova `<section class="view" data-view="forecast" style="display:none;">` com
    toolbar própria (título + semana + último sync-tasks) e container `#forecast-list`.
- `assets/js/app.js`:
  - `fetchForecast()` → GET `/api/workload_forecast.php` e render.
  - `renderForecastCard(c)` — por colaborador, grelha 5 colunas (seg–sex):
      - Barra "planned" com cor do status (under/ok/over).
      - Texto: `Xh / Yh` (planeado / capacidade).
      - Se `overdue_count > 0`: badge de overdue.
      - Se `unestimated_count > 0`: contador cinzento "+N sem estimativa".
  - Lazy-load no primeiro `showView('forecast')`, como a Colaboradores.
  - **Sem** botão Sync próprio — a feature depende do sync de **tasks** já existente
    (Linha Editorial). Mostra o timestamp desse sync no topo para dar contexto.
- `assets/css/style.css`: grelha 5 colunas, cores reutilizando `.collab-badge.under/ok/over`.
  Nova classe `.forecast-overdue` e `.forecast-unestimated`.

**Commit:** `F02: adiciona vista Plano da semana`

---

## Fase F — Validação

### [ ] 7. Validação manual + writeup

- `specs/10-active/F02-plano-carga-semanal/validation.md`:
  - Checklist manual (local + produção) dos critérios de aceitação.
  - Resultado de `php tests/test_*.php` (todos passam).
  - Número concreto de tasks envolvidas num forecast típico (para contexto de KI-05 —
    aqui deve ser 0 novas chamadas, mas queremos documentar a shape da query).
  - Capturar a decisão final sobre D5 (parent vs subtask) com base no spike.
- Mover pasta para `specs/20-done/F02-plano-carga-semanal/`.

**Commit:** `F02: conclui feature Plano da semana (validation + done)`
