# F03 — Tasks

Decomposição executável da spec. Um commit por tarefa, mensagem
`F03: descrição`. Cada tarefa deixa o sistema num estado funcional.

## 1. Rede de segurança: testes antes de mexer

- [ ] Adicionar casos a `tests/test_collaborators_aggregate.php` que
      cobrem o novo agrupamento por `post_id`:
  - entries com `parent_task_id` definido → linha agrega com `post_id =
    parent_task_id`, `post_name = parent_name`
  - entries sem parent → linha usa `task_id` e `task_name` como post
  - dois entries, um Design + um Copy do mesmo post → 1 linha só (soma
    das horas), com `post_name` do parent
- [ ] Todos os asserts existentes continuam a passar.

## 2. Schema: colunas novas em `time_entries`

- [ ] Em `includes/database.php`, adicionar bloco idempotente após o
      `CREATE TABLE time_entries` (a seguir ao ALTER idempotente que já
      existe para `sync_log.progress`):
      ```
      try { $pdo->exec('ALTER TABLE time_entries ADD COLUMN task_name TEXT'); } catch (...) {}
      try { $pdo->exec('ALTER TABLE time_entries ADD COLUMN task_url TEXT'); } catch (...) {}
      try { $pdo->exec('ALTER TABLE time_entries ADD COLUMN parent_task_id TEXT'); } catch (...) {}
      try { $pdo->exec('ALTER TABLE time_entries ADD COLUMN parent_name TEXT'); } catch (...) {}
      ```
- [ ] Verificar com `sqlite3 data/sonar.db ".schema time_entries"` que
      as colunas foram criadas.

## 3. Sync: capturar task name/url no upsert

- [ ] `db_upsert_time_entries` passa a ler `$e['task']['name']` e a
      construir `task_url = 'https://app.clickup.com/t/' + task_id`.
      Gravar nas colunas novas. `parent_task_id` e `parent_name` ficam
      NULL nesta tarefa.
- [ ] O INSERT OR REPLACE estende-se para cobrir os novos campos,
      mantendo retrocompatibilidade (se task_name não vier, escreve
      NULL).
- [ ] Correr um sync manual (botão Sync) e confirmar via SQL que
      `task_name` e `task_url` estão preenchidos para todas as novas
      linhas (excepto entries sem `task.name`, caso raro).

## 4. Sync: resolver parent para subtasks Design/Copy

- [ ] Novo helper em `includes/clickup.php`:
      `clickup_get_task(string $token, string $task_id): array` —
      chamada simples a `GET /task/{id}` via `clickup_api_get`.
- [ ] Novo helper pure em `includes/workload.php` ou novo
      `includes/time_entries.php`:
      `time_entry_is_subtask_candidate(string $name): bool` — devolve
      true se mb_strtolower contém "design" ou "copy".
- [ ] Em `api/sync_time_entries.php`, após capturar os entries brutos e
      antes de chamar `db_upsert_time_entries`:
  1. Construir set de task_ids únicos com nome Design/Copy.
  2. Para cada task única (excepto as que já têm `parent_name` gravado
     com `synced_at >= now - 7 days`), chamar `clickup_get_task` para
     obter `parent`.
  3. Se parent existir, chamar `clickup_get_task(parent)` para obter
     `parent.name`. Cache in-memory `[parent_id => parent_name]` para
     não repetir dentro do mesmo sync.
  4. Passar o mapa `[task_id => {parent_task_id, parent_name}]` ao
     `db_upsert_time_entries`.
- [ ] `db_upsert_time_entries` aceita um 3º parâmetro opcional
      `$parentMap` e preenche `parent_task_id` e `parent_name` quando
      existem no mapa.
- [ ] Progress UI: mostrar "A resolver posts... (X/Y)" durante esta
      fase, para o user saber que é este passo que demora.

## 5. Endpoint: expor post_name/post_url no agregado

- [ ] `collab_aggregate_weeks` passa a agrupar por `post_id`:
  - `post_id = parent_task_id || task_id`
  - `post_name = parent_name || task_name || null`
  - `post_url = parent_task_id ? 'https://app.clickup.com/t/' + parent_task_id : task_url`
- [ ] Cada linha em `days_tasks[dow]` passa a ter
      `{post_id, post_name, post_url, duration_ms, hours}` (substitui
      `task_id`/`task_name`/`task_url`).
- [ ] Rodar `tests/test_collaborators_aggregate.php` — todos os asserts
      novos e velhos passam.

## 6. Frontend: popup usa post_name/post_url

- [ ] `renderCollabDayRow` em `assets/js/app.js`:
  - label = `t.post_name || 'Task ' + t.post_id` (fallback só se name
    vazio)
  - href = `t.post_url || 'https://app.clickup.com/t/' + t.post_id`
- [ ] Remover o ramo "entry sem task" — não é atingível com sync
      actual.

## 7. Validação

- [ ] Criar `validation.md` ao lado da spec com:
  - Lista de asserts da rede de segurança e resultado
  - Sampling de 5 entries após sync real: verificar que `post_name`
    corresponde visualmente ao post esperado na ClickUp
  - Contagem pré/pós sync de entries com `task_name IS NOT NULL`
    (deve saltar de 1/171 para ~171/171)
  - Verificar rate limit: tempo total do sync + número de calls
    adicionais (log)

## 8. Fecho

- [ ] `git mv specs/10-active/F03-time-entries-post-context specs/20-done/`
- [ ] Nenhum TODO pendente no código.
