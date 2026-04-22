# F03 — Contexto de post nas time entries (popup Colaboradores)

**Status:** done
**Tipo:** feature
**Criada em:** 2026-04-22

## Problema

O popup "tasks trabalhadas no dia" (UX iteration em cima do F01) mostra
"Task {id}" como label porque o `LEFT JOIN time_entries → tasks` devolve
`task_name = NULL` em 170 de 171 entries.

Causa: a tabela `tasks` só contém as tasks do próprio utilizador autenticado
(scope do sync F01), mas os time entries do grupo Design referem tasks de
qualquer pessoa do workspace — a maioria nunca entrou no cache.

Além disso, a Linha Editorial tem lógica dedicada: quando a task se chama
"Design" ou "Copy", mostra o **título do post pai**, não o da subtask
(`api/tasks.php:109-149`). O popup tem de espelhar esta regra: se a task
trabalhada é uma subtask Design/Copy, o utilizador quer ver o nome do
post (e clicar abre o post no ClickUp).

## Proposta

Enriquecer `time_entries` no sync para guardar o nome/URL da task e,
quando aplicável, o nome/URL do post pai. O popup passa a mostrar o
**título do post** (seguindo a regra Design/Copy → parent) + link ClickUp.

## User Story

Como department head, quero clicar num dia de um colaborador na página
Colaboradores e ver a lista de **posts** (não subtasks Design/Copy) em
que trabalhou, com horas por post e link para o ClickUp, para auditar
rapidamente onde o tempo foi investido.

## Critérios de Aceitação

- [ ] Após sync de time entries, cada linha em `time_entries` tem
      `task_name` e `task_url` populados (não NULL), exceto para entries
      cuja task foi apagada no ClickUp.
- [ ] Para entries em que a task é subtask Design/Copy, `post_name` e
      `post_url` apontam para o post pai; caso contrário apontam para a
      própria task.
- [ ] O popup do dia no Colaboradores mostra, por linha, o `post_name`
      (link `post_url`, target=_blank) e as horas trabalhadas. Múltiplas
      subtasks do mesmo post agrupam-se numa só linha (soma das horas).
- [ ] Sync de time entries não ultrapassa o rate limit da ClickUp (≤ 100
      req/min) mesmo com 200+ entries.
- [ ] Entries sem task (caso impossível hoje — sync já filtra) mantêm o
      fallback `(entry sem task)`.

## Impacto

- **Schema:** 4 colunas novas em `time_entries` (nullable, backfill via
  próximo sync):
  - `task_name TEXT`
  - `task_url TEXT`
  - `parent_task_id TEXT`
  - `parent_name TEXT`
  (URL do parent é derivável do id — `https://app.clickup.com/t/{id}` —
  logo não gravamos `parent_url`).
  Migration: `ALTER TABLE time_entries ADD COLUMN …` dentro do mesmo
  bloco try/catch idempotente que já existe em `init_database()`.

- **API ClickUp:**
  - `GET /team/{id}/time_entries` — já consumido, devolve `task.id` e
    `task.name`. Passamos a capturar também `task.name`. URL da task é
    construída a partir do id.
  - `GET /task/{id}` — **novo uso dentro do sync** de time entries.
    Chamado só para tasks únicas que: (a) aparecem no batch e (b) têm
    nome a conter "design" ou "copy" (case-insensitive) — potenciais
    subtasks. A resposta devolve `parent` (string id) e `top_level_parent`;
    precisamos do nome do parent, obtido com segunda chamada `GET
    /task/{parent_id}` por parent único. Cache em memória durante o
    sync para não repetir.
  - Pior caso estimado (mês típico): 50 tasks únicas × 2 calls = 100
    chamadas, dentro de 1 minuto no rate limit. Se uma mesma task
    aparecer em meses subsequentes, o sync pode saltar a chamada quando
    os campos já estão preenchidos e a data de sync é recente (ver
    Estratégia).

- **Rotas/Endpoints:** nenhum endpoint novo. `api/sync_time_entries.php`
  passa a preencher os campos novos. `api/collaborators.php` passa a
  expor `post_name` e `post_url` no agregado (em vez de `task_name` /
  `task_url` crus). O frontend do popup é trocado.

- **Dependências:** nenhuma.

## Estratégia de resolução do parent (Design/Copy)

1. Durante o sync, agrupar entries por `task.id` único.
2. Para cada task única, guardar `task.name` e `task.url = https://app.clickup.com/t/{id}`.
3. Se `task.name` contém "design" ou "copy" (mb_strtolower), chamar
   `GET /task/{id}` para obter `parent`. Se existir, fazer segunda
   chamada `GET /task/{parent_id}` para obter o nome.
4. `parent_task_id` e `parent_name` são NULL para tasks-que-são-posts.
5. Skip-optimization: se a entry já tem `task_name` gravado e foi
   `synced_at` nos últimos 7 dias, não re-chamar a API.

No endpoint `api/collaborators.php`:
- `post_name = parent_name ?? task_name`
- `post_id   = parent_task_id ?? task_id`
- `post_url  = parent_task_id ? 'https://app.clickup.com/t/' + parent_task_id : task_url`

## Não-objetivos

- Não estender o cache `tasks` com tasks externas. `time_entries` fica
  auto-suficiente para o popup — menos joins, menos dependências.
- Não re-sincronizar time_entries antigos automaticamente. O backfill
  acontece no próximo sync normal do mês.
- Não resolver posts de mais que um nível (avô) — a hierarquia editorial
  é Post → Design/Copy, não há 3 níveis.
- Não mexer no Plano da semana (F02) — esse já resolve parent via DB
  porque as tasks em causa são as do próprio utilizador (no cache).

## Referências

- Regra Design/Copy → parent: `api/tasks.php:109-149`
- Sync atual: `api/sync_time_entries.php:190`, `includes/database.php:894-940`
- Popup existente (a adaptar): `assets/js/app.js` `openCollabDayModal` e
  `renderCollabDayRow`
- Aggregator: `includes/collaborators.php` `collab_aggregate_weeks` — o
  agrupamento passa a ser por `post_id` em vez de `task_id`.
