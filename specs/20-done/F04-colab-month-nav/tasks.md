# F04 — Tasks

Um commit por tarefa, mensagem `F04: descrição`. Cada commit deixa o
repo funcional.

## 1. Helper `collab_parse_month_param` + teste

- [ ] `includes/collaborators.php`: adicionar
      `collab_parse_month_param(?string $raw, DateTimeZone $tz, ?DateTimeImmutable $now = null): ?DateTimeImmutable`
      — `null`/`''` → `null` (= mês actual). Formato válido `YYYY-MM`
      que representa mês ≤ actual → `DateTimeImmutable` para o primeiro
      dia do mês, 00:00 em `$tz`. Formato inválido ou mês futuro →
      lança `InvalidArgumentException`.
- [ ] `tests/test_collaborators_aggregate.php`: adicionar bloco para
      `collab_parse_month_param` (válido, null, inválido, futuro, mês
      actual com o 1º dia no fuso correcto). Actualizar contador.
- [ ] `php tests/test_collaborators_aggregate.php` passa (count antigo +
      novos asserts, 0 failed).

## 2. Endpoints aceitam `?month=YYYY-MM`

- [ ] `api/collaborators.php`: lê `$_GET['month']`, passa pelo parser, e
      usa o resultado como `$now` em `collab_month_window`. Inválido/
      futuro → 400 JSON `{error: 'Invalid month'}`.
- [ ] `api/sync_time_entries.php`: mesma leitura + validação.
      `collab_month_window($tz, $parsed ?? null)` passa a determinar o
      window sincronizado.
- [ ] `php -l` em ambos os ficheiros.
- [ ] Teste manual: `curl "localhost:8080/api/collaborators.php?month=2026-03"`
      devolve payload; `?month=2099-01` devolve 400; `?month=foo` devolve
      400.

## 3. Totais mensais no response de collaborators

- [ ] `api/collaborators.php`: por cada colaborador, computa
      `expected_hours = weekly_hours * count(weeks_meta)` e
      `worked_hours = sum(weeks[*].total_hours)`; usa `collab_status`
      (pura, não precisa adaptar) para o badge com os parâmetros
      `worked_hours` e `expected_hours`.
- [ ] Adiciona `month_totals` ao objecto do colaborador:
      `{expected_hours, worked_hours, status}`.
- [ ] Opcional: extrair helper `collab_month_totals(array $weeks, int $weekly_hours, int $num_weeks): array`
      em `includes/collaborators.php` se der para partilhar com um teste.
      Se não, inline no endpoint.
- [ ] `test_collaborators_aggregate.php`: se o helper for extraído,
      cobrir com 2-3 asserts (under/ok/over).
- [ ] Suite passa.

## 4. Frontend — navegação mensal

- [ ] `dashboard.php`: na toolbar dos Colaboradores, adicionar botões
      `collab-prev` e `collab-next` a ladear `#collab-month-label`.
      Mesma aparência dos botões de calendário (`btn-secondary btn-sm`).
- [ ] `assets/js/app.js`:
  - [ ] Estado `_collabMonth = null` (null = mês actual).
  - [ ] `parseCollabHash()` lê `#collaborators/YYYY-MM`. Formato inválido
        → null.
  - [ ] `navCollabMonth(delta)` calcula novo `YYYY-MM` (ou null se voltar
        ao actual) e actualiza hash + re-fetch.
  - [ ] `fetchCollaborators(month)` aceita param; se passado, chama
        `/api/collaborators.php?month=…`.
  - [ ] `startTimeEntriesSync` passa o mês actual seleccionado
        (`?month=…`) quando aplicável.
  - [ ] `renderCollaborators(data)` actualiza a label do mês a partir de
        `data.month_start` em vez de `now` (remove o hack existente em
        `formatMonthLabel`).
  - [ ] `→` disabled quando `_collabMonth === null` (mês actual).
  - [ ] Router `showView('collaborators')` lê o hash e actualiza
        `_collabMonth` antes do fetch.
- [ ] `node --check assets/js/app.js` passa.
- [ ] Teste manual: clicar `←` várias vezes volta no tempo; `→` volta à
      frente; refresh em `#collaborators/2026-03` mantém o mês; URL
      sem sub-hash volta ao actual.

## 5. Frontend — render do total mensal

- [ ] `renderCollabCard`: inserir, entre `<header>` e `<div class="collab-weeks">`,
      uma `<div class="collab-month-summary">` com:
      `Expectável: Xh · Trabalhado: Yh · <span class="collab-badge <status>">Label</span>`.
- [ ] `assets/css/style.css`: estilo `.collab-month-summary` — flex row,
      gap pequeno, tipografia secundária. Reutilizar `.collab-badge`.
- [ ] Teste visual: valores coincidem com a soma manual das semanas.

## 6. Validação + fechar

- [ ] Executar `test_collaborators_aggregate.php` e
      `test_time_entries_resolver.php` — 0 failed.
- [ ] `php -l` em todos os PHP modificados.
- [ ] `node --check assets/js/app.js`.
- [ ] Grep: `grep -n "formatMonthLabel" assets/js/app.js` — função
      removida ou actualizada para usar `data.month_start`.
- [ ] Criar `validation.md` ao lado da spec.
- [ ] `git mv specs/10-active/F04-colab-month-nav specs/20-done/`
