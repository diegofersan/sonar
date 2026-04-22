# R01 — Remover vista "Plano da semana" (F02)

**Status:** approved
**Tipo:** refactor
**Criada em:** 2026-04-22

## Motivação

O F02 entrou em produção mas não entregou o valor esperado ao utilizador:
a vista semanal de tasks/dia não deu contexto accionável suficiente para
tomar decisões de alocação. O sinal ("over" / "under") parecia ruidoso
porque tasks sem data empilhavam em "hoje" e não reflectiam carga real
planeada.

Remover em vez de re-desenhar: o utilizador prefere simplificar a UI a
voltar a iterar nesta vista agora. Pode voltar no futuro como nova
feature se a dor ressurgir.

## Escopo

Remover tudo o que é exclusivo do F02. Ficheiros afectados:

- `dashboard.php` — nav link `data-nav="forecast"` e `<section data-view="forecast">`
- `assets/js/app.js` — bloco `/* Plano da semana (F02) */`, router case `'forecast'`, caches `_forecastLoaded`/`_forecastData`
- `assets/css/style.css` — `.forecast-grid`, `.forecast-day*`, `.forecast-undated-badge`, `.forecast-overdue`
- `api/workload_forecast.php` — endpoint inteiro
- `includes/workload.php` — ficheiro inteiro
- `includes/session.php` — helper `get_daily_tasks_target()`
- `config.php` — bloco F02 (`DAILY_TASKS_PER_USER`, `DEFAULT_DAILY_TASKS`)
- `tests/test_forecast.php` — suite inteira
- `tests/spike_workload.php` — ficheiro inteiro (se existir)

## Invariantes

- F01 (Colaboradores) continua a funcionar exactamente igual, incluindo
  o popup de tasks do dia adicionado em F03. Os testes do
  `test_collaborators_aggregate.php` e `test_time_entries_resolver.php`
  continuam a passar.
- OAuth, sync de tasks, sync de time entries, relatório, notificações —
  nenhum destes toca no código do F02.
- Schema da BD não muda (F02 nunca adicionou colunas).
- CSS do popup "day modal" continua a funcionar. Como os estilos estão
  namespaced como `.forecast-modal-*` mas são agora usados só pelo
  popup do Colaboradores, vamos renomear para `.day-modal-*` no mesmo
  commit (nome histórico enganador).

## Estratégia

Cinco commits, cada um deixa o repo funcional:

1. **Spec + tasks** — este commit.
2. **Remover frontend (view + nav + CSS específico)** — `dashboard.php` e
   o bloco JS do Plano da semana + classes CSS exclusivas do F02.
   A app fica sem o separador mas continua a compilar.
3. **Renomear `.forecast-modal-*` → `.day-modal-*`** — CSS + JS do popup
   Colaboradores. Separado do passo 2 para manter o diff limpo.
4. **Remover backend (endpoint + helpers + config + testes)** — sem
   referências pendentes, o delete é seguro.
5. **Arquivar F02 + fechar R01** — move `specs/20-done/F02-*` para
   `specs/90-archive/` e `specs/10-active/R01-*` para `specs/20-done/`.

## Rede de segurança

- `tests/test_collaborators_aggregate.php` (43 asserts) — garante que o
  aggregator do F01 não é tocado.
- `tests/test_time_entries_resolver.php` (22 asserts) — garante que o F03
  não é afectado.
- `php -l` em cada ficheiro modificado.
- `node --check assets/js/app.js`.
- Grep após cada commit a garantir zero referências a `forecast` fora do
  código renomeado e zero referências a funções/constantes removidas
  (`workload_forecast`, `forecast_aggregate`, `get_daily_tasks_target`,
  `DAILY_TASKS_PER_USER`).

## Critérios de conclusão

- [ ] Nav "Plano da semana" desaparece; rota `#forecast` redireciona
      silenciosamente para `#editorial` (já é o fallback do router).
- [ ] `/api/workload_forecast.php` responde 404.
- [ ] Grep por `forecast` só mostra hits no histórico git / nas specs
      arquivadas (código vivo = 0 matches em `api/`, `includes/`,
      `dashboard.php`, `assets/`).
- [ ] Todos os testes restantes passam.
- [ ] F02 arquivado em `specs/90-archive/`.
