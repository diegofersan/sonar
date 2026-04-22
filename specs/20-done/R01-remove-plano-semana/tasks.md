# R01 — Tasks

Um commit por tarefa, mensagem `R01: descrição`. Cada commit mantém o
repo funcional.

## 1. Remover frontend do Plano da semana

- [ ] `dashboard.php`: remover nav link `data-nav="forecast"` e a
      `<section class="view" data-view="forecast">` inteira.
- [ ] `assets/js/app.js`: remover bloco `/* Plano da semana (F02) */`
      (fetchForecast, renderForecast, renderForecastCard,
      bindForecastClicks, openForecastModal, renderForecastModalRow,
      `_forecastLoaded`, `_forecastData`, `WEEKDAY_PT`,
      `FORECAST_STATUS_LABEL`, `formatWeekLabel`, case `'forecast'` do
      router). Não mexer no bloco do popup Colaboradores que usa
      `forecast-modal-*` — esse fica para a task 2.
- [ ] `assets/css/style.css`: remover `.forecast-grid`, `.forecast-day`,
      `.forecast-day-*`, `.forecast-undated-badge`, `.forecast-overdue`,
      `.forecast-count`, `.forecast-target`.
- [ ] `node --check assets/js/app.js` passa.
- [ ] `grep -r "data-nav=\"forecast\"" dashboard.php` → 0 matches.

## 2. Renomear `.forecast-modal-*` → `.day-modal-*`

- [ ] `assets/css/style.css`: substituir todas as classes
      `.forecast-modal*` por `.day-modal*` (backdrop, modal, header,
      count, close, body, row, title, meta, status, overdue).
- [ ] `assets/js/app.js`: substituir no HTML gerado por
      `openCollabDayModal` e `renderCollabDayRow`.
- [ ] Verificar visualmente (manual) que o popup abre com o mesmo
      aspecto que antes.

## 3. Remover backend + config do F02

- [ ] `git rm api/workload_forecast.php`
- [ ] `git rm includes/workload.php`
- [ ] `git rm tests/test_forecast.php`
- [ ] `git rm tests/spike_workload.php` (se existir)
- [ ] `includes/session.php`: remover `get_daily_tasks_target()`.
- [ ] `config.php`: remover bloco `DAILY_TASKS_PER_USER` e
      `DEFAULT_DAILY_TASKS` (em ambas as secções — config.local.php
      reading + env-var fallback).
- [ ] `php -l` em cada ficheiro modificado.
- [ ] Correr `php tests/test_collaborators_aggregate.php` e
      `php tests/test_time_entries_resolver.php` — ambos passam.

## 4. Arquivar + fechar

- [ ] `git mv specs/20-done/F02-plano-carga-semanal specs/90-archive/`
- [ ] Criar `validation.md` ao lado da spec.
- [ ] `git mv specs/10-active/R01-remove-plano-semana specs/20-done/`
- [ ] Último check: `grep -rn "forecast" api/ includes/ assets/ dashboard.php`
      retorna 0 hits.
