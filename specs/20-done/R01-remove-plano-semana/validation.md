# R01 — Validação

**Concluída em:** 2026-04-22

## Checks executados

### Safety net (testes)

```
php tests/test_collaborators_aggregate.php
  Passed: 43
  Failed: 0

php tests/test_time_entries_resolver.php
  Passed: 22
  Failed: 0
```

F01 (Colaboradores) e F03 (post-context em time entries) não foram
afectados pela remoção do F02.

### Lint

```
php -l config.php              → No syntax errors
php -l includes/session.php    → No syntax errors
php -l includes/time_entries.php → No syntax errors
node --check assets/js/app.js  → OK
```

### Grep — código vivo sem referências a F02

Depois dos 4 commits:

```
grep -rn "workload|forecast|get_daily_tasks_target|DAILY_TASKS_PER_USER|DEFAULT_DAILY_TASKS" \
  api/ includes/ assets/ dashboard.php config.php
```

Só restam:

- `api/collaborators.php:5` — comentário "workload per member" (F01,
  sem relação com F02).

Zero referências a `forecast` em `api/`, `includes/`, `assets/`,
`dashboard.php` e `config.php`.

## Critérios de conclusão

- [x] Nav "Plano da semana" desaparece.
- [x] `#forecast` cai no fallback do router (`#editorial`).
- [x] `/api/workload_forecast.php` já não existe (404).
- [x] Grep por `forecast` em código vivo = 0.
- [x] Todos os testes restantes passam.
- [x] F02 arquivado em `specs/90-archive/`.
- [x] CSS do popup do Colaboradores renomeado para `.day-modal-*`.

## Commits

```
5708a4a  R01: spec para remover vista Plano da semana (F02)
2cd95c9  R01: remove frontend da vista Plano da semana
ef49a02  R01: renomeia .forecast-modal-* para .day-modal-*
84b5788  R01: remove backend + config do Plano da semana
```

Último commit (arquivar + fechar) segue este documento.

## Impacto em produção

- Uma rota pública desaparece: `/api/workload_forecast.php`. Nenhum
  cliente externo conhecido a chamar este endpoint — só era consumido
  pela própria vista que também foi removida.
- Schema SQLite inalterado (o F02 nunca adicionou colunas).
- Utilizadores com `#forecast` marcado em bookmarks aterram em
  `#editorial` sem erro visível.
