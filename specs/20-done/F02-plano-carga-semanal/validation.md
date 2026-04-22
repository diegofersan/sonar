# F02 — Validação

Data: 2026-04-22.
Status: pronto para aceitação do utilizador. Os pontos **[prod]** dependem de uma
sessão real do chefe de departamento no servidor de produção e ficam marcados como
"a confirmar no deploy".

---

## 1. Testes automatizados

| Ficheiro                              | Passed | Failed |
| ------------------------------------- | -----: | -----: |
| `tests/test_daily_tasks_target.php`   |      5 |      0 |
| `tests/test_forecast.php`             |     40 |      0 |
| **Total F02**                         | **45** |  **0** |

Regressão F01 continua verde (`tests/test_weekly_hours.php` 5/0, restantes
inalterados — nenhuma dependência cruzada foi introduzida).

Reexecutar com:

```sh
php tests/test_daily_tasks_target.php
php tests/test_forecast.php
php tests/test_weekly_hours.php
```

Cobertura (resumida):

- **`forecast_is_terminal`** — case-insensitive, trim, unicode (`mb_strtolower`)
  sobre toda a lista `FORECAST_TERMINAL_STATUSES` + não-terminais.
- **`forecast_task_days`** — modelo C em todas as permutações: start+due spread;
  span Fri→Mon skip de fim-de-semana; só due → fallback 1 dia útil antes; só start →
  start (se weekday); ambos null → skip; overdue → hoje; terminal → skip; start em
  sáb sem due → skip.
- **`forecast_current_week`** — início da semana a partir de Mon / Wed / Sun.
- **`forecast_status`** — thresholds 80 % / 110 %; `target=0` degenerado.
- **`forecast_aggregate`** — D5 (parent skipped quando tem filhos no input);
  `undated_count` bump em hoje; `overdue_count` bump; estados under/ok/over;
  `daily_target=0` degenerado; terminal nunca contado; labels `mon..fri`.

---

## 2. Lints / sanity checks

- `php -l dashboard.php` → sem erros.
- `php -l api/workload_forecast.php` → sem erros.
- `php -l includes/workload.php` → sem erros.
- `php -l includes/session.php` → sem erros (helper `get_daily_tasks_target`).
- `php -l config.php` → sem erros (constantes `DAILY_TASKS_PER_USER` /
  `DEFAULT_DAILY_TASKS` adicionadas).
- Smoke via `php -S localhost:8089`:
  - `GET /dashboard.php` sem sessão → 302 → `/login.php`.
  - `GET /api/workload_forecast.php` sem sessão → 401 `{"error":"Not authenticated"}`.
  - `POST /api/workload_forecast.php` sem sessão → 401 (auth gate corre antes do
    method gate, comportamento idêntico a `api/collaborators.php`).

Nenhum fatal no `error_log`.

---

## 3. Critérios de aceitação (da spec revista pós-spike)

### Local — validados nesta sessão

- [x] **Entrada "Plano da semana" na navbar, só visível ao chefe de departamento.**
  `dashboard.php` condiciona a emissão do `<a data-nav="forecast">` com
  `<?php if ($isDepartmentHead): ?>`. Mesmo padrão do "Colaboradores".
- [x] **Grelha seg–sex da semana corrente (Europe/Lisbon).**
  `forecast_current_week()` devolve 5 `DateTimeImmutable` alinhados ao monday ISO;
  frontend renderiza `.forecast-grid` com `grid-template-columns: repeat(5, …)`.
- [x] **Por dia: nº de tasks activas + alvo diário + estado `under/ok/over`.**
  `forecast_aggregate()` produz `{active_count, target, status}`; render aplica
  classe `.status-{under|ok|over}` + badge `collab-badge.{under|ok|over}` (linguagem
  visual idêntica ao F01).
- [x] **Thresholds 80 % / 110 % de `DEFAULT_DAILY_TASKS` / `DAILY_TASKS_PER_USER`.**
  Constantes `FORECAST_UNDER_RATIO` / `FORECAST_OVER_RATIO` em `includes/workload.php`.
  Cobertura em `test_forecast.php § forecast_status` + `forecast_aggregate` thresholds.
- [x] **Contador "N sem data" por dia a cinzento.**
  Undated tasks incrementam `undated_count` na coluna de hoje; render mostra
  `+N sem data` com `.forecast-undated`.
- [x] **Dias `over` visualmente distintos.**
  `.forecast-day.status-over` com borda e tint vermelhos + badge "Sobre".
- [x] **Overdue tasks contam em hoje com marcação visual.**
  `forecast_task_days` empurra para hoje; `forecast_aggregate` soma em
  `overdue_count`; render mostra `⚠ N overdue` com `.forecast-overdue`.
- [x] **Tasks em status terminal são ignoradas.**
  `FORECAST_TERMINAL_STATUSES` (6 nomes) filtra em `forecast_is_terminal`, aplicado
  tanto em `forecast_task_days` como na agregação (defense in depth).
- [x] **Endpoint devolve 403/401 nas condições certas.**
  Smoke confirma 401. Gate de `is_department_head()` idêntico a
  `api/collaborators.php` (revisto visualmente).
- [x] **Zero chamadas novas à API ClickUp.**
  `api/workload_forecast.php` faz 1 query SQLite + 1 chamada `/group?team_id={id}`
  que já era usada pelo F01 (roster do grupo). Não consome nenhum endpoint novo.
- [x] **Nenhuma migration de schema em v1.**
  Nenhum `ALTER TABLE` adicionado. Só usa colunas já existentes: `tasks.id`,
  `status_name`, `start_date`, `due_date`, `parent_id`, `workspace_id` + tabela
  `task_assignees`.
- [x] **Nenhum sync existente é alterado.**
  `api/sync.php` intocado.

### [prod] — a confirmar pelo utilizador após deploy

- [ ] **Vista carrega em < 500 ms.** Não-mensurável localmente de forma fiável
  (cache não aquecida, sem latência real). A query única usa
  `idx_tasks_workspace` + filtro por assignees indexado (`idx_task_assignees_user`).
  Medição típica local contra 196 rows (tasks × assignees dos 4 membros): bem
  abaixo do limite.
- [ ] **Look-and-feel em produção** (contraste, wrap no mobile). Breakpoint a 720px
  cai para 2 colunas; confirmar num telefone real.

---

## 4. Shape típica do forecast (contexto para KI-05)

Medição contra `data/sonar.db` local em 2026-04-22 (workspace QRA, 4 membros do
grupo design), semana 2026-04-20 → 2026-04-24:

| user_id  | nome     | tasks (input) | active (soma seg-sex) | overdue | undated (hoje) |
| -------- | -------- | ------------: | --------------------: | ------: | -------------: |
| 473908   | Diego    |            40 |                    24 |       4 |              3 |
| 54230261 | Karllos  |            12 |                     1 |       1 |              4 |
| 4727509  | Ruben    |             0 |                     0 |       0 |              0 |
| 87600945 | Carolina |           144 |                    44 |      44 |             26 |

Observações:

- **Ruben (0 tasks)** — ou o roster do grupo design não corresponde ao que o
  sync recente trouxe, ou ainda não tem atribuições. Não é bug da feature; é uma
  pergunta para o chefe confirmar no deploy.
- **Carolina (44 overdue)** — carga real, não artefacto do cálculo. `forecast_task_days`
  empurra-as para hoje conforme D4; o ícone ⚠ dá visibilidade imediata.
- **Impacto em KI-05:** **0 chamadas novas** por cada GET do endpoint. O payload
  total deste workspace é ~196 linhas da join tasks↔task_assignees; seriailza em
  JSON < 20 KB. Não cria pressão adicional no rate limit.

---

## 5. Nota sobre `FORECAST_TERMINAL_STATUSES`

Lista actual (6 nomes, case-insensitive, em `includes/workload.php`):

```
published, mês social terminado, post cancelado,
linha editorial cancelada, scheduled, ready to post
```

As duas últimas (`scheduled`, `ready to post`) foram marcadas como terminais
com base na assumption de que, uma vez nesses estados, não há mais trabalho de
design activo. **Se a equipa reportar** que ainda fazem edições pós-schedule,
remover da lista é uma mudança de 1 linha — a constante é a única fonte de verdade.

---

## 6. Pendências operacionais (fora da feature)

1. **Preencher `config.local.php` em produção** com `DAILY_TASKS_PER_USER`
   (mapa `user_id => alvo_diário`). Sem este mapa, todos usam
   `DEFAULT_DAILY_TASKS = 5`.
2. **Validar com o chefe de departamento o alvo real por pessoa** (part-time?
   target menor?). Os 4 membros locais estão actualmente a 5 tasks/dia como
   placeholder.
3. **Rotação da API key** `pk_473908_…` — pendência herdada de F01,
   re-listada aqui.

---

## 7. Próximo passo

Mover esta pasta para `specs/20-done/` no mesmo commit de conclusão.
