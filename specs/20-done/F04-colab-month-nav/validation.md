# F04 — Validação

**Concluída em:** 2026-04-22

## Checks executados

### Testes unitários

```
php tests/test_collaborators_aggregate.php
  Passed: 66
  Failed: 0

php tests/test_time_entries_resolver.php
  Passed: 22
  Failed: 0
```

Cobertura nova (+23 asserts em relação ao baseline pré-F04):

- **Secção 6 — `collab_parse_month_param`** (13 asserts): null, vazio,
  mês passado, mês corrente, mês no futuro (rejeitado), formatos
  inválidos, número de mês inválido, ano-boundary.
- **Secção 7 — `collab_month_totals`** (10 asserts): ok/under/over,
  `weekly_hours = 0` degenerado, weeks vazias, keys em falta.

### Lint

```
php -l includes/collaborators.php    → OK
php -l api/collaborators.php         → OK
php -l api/sync_time_entries.php     → OK
node --check assets/js/app.js        → OK
```

### Critérios de aceitação

**Navegação:**
- [x] Botões `←` `→` na toolbar, a ladear a label do mês.
- [x] `←` volta um mês, `→` avança, ambos re-fetcham.
- [x] `→` disabled no mês corrente.
- [x] `←` sem limite inferior.
- [x] Hash `#collaborators/YYYY-MM` persiste em refresh.
- [x] Sync usa o mês seleccionado (query param + JSON body field).

**Totais mensais:**
- [x] Cartão mostra `Expectável: Xh · Trabalhado: Yh · <badge>` entre
      o header e a tabela de semanas.
- [x] Expectável = `weekly_hours × len(weeks_meta)`.
- [x] Trabalhado = soma de `total_hours`.
- [x] Badge reutiliza `.collab-badge.<status>`.
- [x] Meses vazios mostram `empty_state` existente (sem regressão).

## Commits

```
e66063f  F04: spec para navegação mensal e totais em Colaboradores
2578b06  F04: parser collab_parse_month_param + testes
4a87368  F04: endpoints aceitam ?month=YYYY-MM
441d540  F04: helper collab_month_totals + campo month_totals no response
7ee62e0  F04: navegação mensal na vista Colaboradores
f3e570e  F04: renderiza resumo mensal no cartão de colaborador
```

## Impacto em produção

- **Schema:** inalterado.
- **API ClickUp:** sem chamadas novas. Sync de mês passado tem o mesmo
  custo de um sync normal (um time-entries window do mês alvo).
- **Rate limits:** sem aumento. Navegação é 100% cache local.
- **Rotas:**
  - `GET /api/collaborators.php` aceita `?month=YYYY-MM` (opcional).
  - `POST /api/sync_time_entries.php` aceita `?month=YYYY-MM` ou
    `{"month": "YYYY-MM"}` no body (opcional).
  - Ambos devolvem 400 JSON se o mês for inválido ou no futuro.
- **Compatibility:** sem param → comportamento actual. Front-end sem
  o novo estado funciona na mesma.

## Notas

- `last_sync` mantém-se global (scope=`time_entries`). A spec deixou
  explícito que não queríamos dimensão mês no sync log para já.
- `formatMonthLabel` foi corrigida: antes derivava do `now()` do
  browser (hardcoded ao mês corrente), agora usa `data.month_start`
  da API para suportar meses passados correctamente.
- Hash com sub-segmento: `#collaborators/2026-03`. `#collaborators`
  sem sub-segmento = mês actual.
