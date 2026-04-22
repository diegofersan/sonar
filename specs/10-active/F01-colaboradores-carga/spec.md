# F01 — Página "Colaboradores" para chefe de departamento

**Status:** approved
**Tipo:** feature
**Criada em:** 2026-04-22
**Aprovada em:** 2026-04-22

## Problema

Hoje o dashboard só serve a perspetiva individual ("as minhas tarefas") e um relatório
pontual de posts em atraso. O chefe de departamento (Diego Ferreira, ClickUp user
ID `473908`) não tem forma de ver, numa vista agregada, **quanto tempo cada
colaborador do departamento de design trabalhou** e se esse tempo está dentro da
carga esperada — por mês e por dia da semana.

Sem isto, decisões de alocação e identificação de sobrecarga / subocupação são
feitas fora do Sonar, à base de estimativa.

## Proposta

Introduzir uma **navbar** no dashboard com duas entradas de topo:

- **Linha Editorial** (renomeação do atual heading "Minhas Tarefas"; conteúdo
  inalterado — toda a UI de tabs Fila/Aprovação/Futuro/Canceladas/Calendário/Relatório).
- **Colaboradores** (visível apenas para o chefe de departamento) — nova página
  que mostra, para cada colaborador do departamento de design, o tempo trabalhado
  agregado por **mês** e por **dia da semana**, comparado com a carga esperada.

## User Story

Como chefe do departamento de design, quero ver num só sítio o tempo trabalhado
por cada colaborador agregado por mês e por dia da semana, para perceber quem
está sobrecarregado, quem está subocupado, e se o trabalho está a ser distribuído
uniformemente ao longo da semana.

## Critérios de Aceitação

- [ ] Existe uma navbar no topo com "Linha Editorial" e (para o chefe) "Colaboradores".
- [ ] Utilizadores não-chefe não veem a entrada "Colaboradores" (verificação server-side, não apenas CSS/JS).
- [ ] O heading "Minhas Tarefas" em `dashboard.php` foi renomeado para "Linha Editorial".
- [ ] A página Colaboradores mostra o **mês corrente**, com uma secção por colaborador do grupo "design" no ClickUp.
- [ ] Para cada colaborador, exibe **cada semana do mês** com totais por dia (seg-dom) em horas, e o total da semana.
- [ ] Cada total semanal é comparado com a **Weekly Hours** do colaborador (configurada no ClickUp) e marcado visualmente como "dentro da carga", "acima" ou "abaixo".
- [ ] Entradas de tempo sem tarefa associada são ignoradas (assumimos que ClickUp não as permite; se permitir, aparecem nos logs mas não somam).
- [ ] A página Colaboradores tem o seu **próprio botão Sync**, independente do botão Sync da Linha Editorial. O botão atual em "Linha Editorial" **não muda** — continua a sincronizar apenas tarefas.
- [ ] Os dois syncs são registados em `sync_log` distinguíveis pela coluna `scope` (`tasks` | `time_entries`). Não há sync que misture os dois tipos.
- [ ] Um sync em curso de um tipo **não bloqueia** o botão do outro tipo (um utilizador pode sincronizar Linha Editorial enquanto Colaboradores está a sincronizar).
- [ ] O endpoint que serve a página valida que o utilizador autenticado é o chefe de departamento (`user.id === '473908'`); caso contrário devolve 403.
- [ ] A página carrega em < 3s num mês típico (critério a validar na viabilidade).
- [ ] Nenhum sync existente fica mais lento nem faz mais chamadas à API ClickUp (a nova vista usa o seu próprio endpoint / sync, não carrega junto com as tarefas do utilizador).

## Viabilidade da API ClickUp — A INVESTIGAR antes de implementar

Esta feature depende de dados que **não temos hoje na cache SQLite**. Antes de avançar,
a primeira tarefa do `tasks.md` será um **spike** para validar:

### Perguntas que o spike tem de responder

1. **Membros do "time de design"**
   - `GET /team/{team_id}/group` lista os grupos do workspace? Os membros vêm no payload ou é preciso `GET /group/{group_id}`?
   - O token OAuth que já temos serve, ou requer scope adicional?
   - Como identificamos unambiguamente o grupo "design"? (nome exato, ID fixo a configurar, substring?)

2. **Weekly Hours por utilizador**
   - O endpoint `/user` devolve `weekly_hours` (ou equivalente)? E `/team/{id}` na secção `members`? E `/group/{id}/member`?
   - Se nenhum endpoint público expõe isto: há fallback? Custom fields em utilizador? Guest API? (Se não for acessível, esta feature fica bloqueada — é o ponto de decisão mais crítico do spike.)

3. **Time entries por utilizador**
   - `GET /team/{team_id}/time_entries?start_date=…&end_date=…&assignee={user_id}` — confirmar formato (ms epoch? segundos?), paginação (se existe), limites de resultados.
   - O filtro `assignee` aceita múltiplos IDs separados por vírgula? Poupa chamadas.
   - Entradas sem task (caso existam) vêm com `task` null / ausente? Confirmar para podermos ignorar.

4. **Volume e rate limit**
   - Estimativa: 1 chamada para listar grupo + 1 por colaborador para weekly_hours (se necessário) + N chamadas para time entries (pode ser 1 se `assignee` aceitar vírgulas).
   - Cabe nos ~100 req/min? Precisamos cachear time entries em SQLite com sync incremental?

### Entregável do spike

- Script `tests/spike_collaborators.php` (não-interativo, standalone, lê token OAuth do argv ou env). Imprime:
  - Resposta bruta de `GET /team/{id}/group` e escolha do grupo "design".
  - Resposta de um endpoint candidato a expor `weekly_hours` para um membro.
  - Resposta bruta de `GET /team/{id}/time_entries` para um intervalo do mês corrente, filtrado por um ou mais colaboradores.
- Secção **"Decisões pós-spike"** acrescentada no final desta spec, confirmando (ou revendo) o desenho antes de escrever `tasks.md`. Se `weekly_hours` não estiver acessível, a spec pausa e volta ao utilizador para decidir fallback (ex.: configurar manualmente, ou abandonar a métrica de carga).

## Impacto

- **Schema** (a confirmar no spike — se volume for baixo e rate limit não apertar, podemos viver em memória por request):
  - Nova tabela `time_entries(id TEXT PK, user_id TEXT, task_id TEXT, workspace_id TEXT, start_ms INTEGER, duration_ms INTEGER, synced_at INTEGER)` — cache local com índice em `(workspace_id, user_id, start_ms)`.
  - Nova coluna `sync_log.scope TEXT` (default `'tasks'`), adicionada via `ALTER TABLE` no `db_migrate()` (segue o padrão try/catch existente — ver KI-09). Valores: `'tasks'`, `'time_entries'`.
  - `weekly_hours` e membros do grupo vêm do ClickUp na hora (cache in-memory ou TTL curto); não planeamos tabelas locais para eles.
- **API ClickUp** (novos endpoints a consumir — confirmar sintaxe no spike):
  - `GET /team/{team_id}/group` (+ eventualmente `/group/{id}`) para listar o time "design".
  - Algum endpoint que devolva `weekly_hours` do utilizador — a identificar no spike.
  - `GET /team/{team_id}/time_entries?start_date=…&end_date=…&assignee=…`.
- **Rotas / Endpoints** (novos):
  - `api/collaborators.php` — GET devolve JSON agregado para o mês corrente: `[{user, weekly_hours, weeks: [{week_number, days: {mon, tue, …}, total_hours, status}]}]`.
  - `api/sync_time_entries.php` — POST inicia sync do mês corrente (padrão do `api/sync.php`: resposta imediata + trabalho inline via `fastcgi_finish_request`); GET devolve estado do sync em curso + `last_sync` — polling pela UI. Filtra `sync_log` por `scope='time_entries'` e pelo utilizador autenticado.
  - O `api/sync.php` existente **não muda** — continua a escrever em `sync_log` com `scope='tasks'` (default da nova coluna). O filtro GET de status também passa a fixar `scope='tasks'` para garantir que não vê progresso do outro sync.
- **Frontend**:
  - Nova navbar em `dashboard.php` (link "Linha Editorial" sempre visível; "Colaboradores" só para chefe).
  - Renomear heading atual "Minhas Tarefas" → "Linha Editorial".
  - Nova vista em `app.js` (mantém o IIFE monolítico — desacoplar fica para R futura).
  - Adições mínimas a `style.css` (navbar + layout semanas/dias).
- **Auth**:
  - Nova constante `DEPARTMENT_HEAD_USER_ID = '473908'` em `config.php` (com override em `config.local.php` e env var, como os outros).
  - Novo helper `is_department_head(): bool` em `includes/session.php`.
  - `api/collaborators.php` e `api/sync_time_entries.php` validam isto server-side (403 se falhar).

## Não-objetivos

- **Não** substitui nem toca na lógica `isAdmin` (substring-match) usada por `api/report.php` e a tab "Relatório" — essa dívida está em `known-issues.md#KI-06` e resolve-se numa R futura. Por agora as duas verificações coexistem (o chefe de departamento também é admin, na prática é a mesma pessoa).
- **Não** inclui edição de carga / capacity por utilizador a partir do dashboard (read-only nesta spec).
- **Não** inclui notificações ou alertas automáticos de sobrecarga.
- **Não** inclui comparações entre colaboradores além do indicador "dentro/acima/abaixo da carga".
- **Não** abrange outros departamentos além de design.
- **Não** sincroniza time entries em tempo real — haverá um botão de sync manual (ou trigger no primeiro load do mês).

## Decisões (confirmadas com o utilizador — 2026-04-22)

1. **Carga esperada**: usar a **Weekly Hours** configurada para cada utilizador no ClickUp. Nada para configurar do nosso lado.
2. **Departamento de design**: é o **grupo/time "design"** do ClickUp — usamos a lista de membros que o ClickUp devolve.
3. **Agregação por dia da semana**: **totais por cada semana** do mês (não média). Cada semana é comparada isoladamente com a Weekly Hours.
4. **Horizonte**: **só o mês corrente**. Sem navegação histórica nesta spec.
5. **Time entries sem tarefa**: **ignorar**. Se o ClickUp as expuser, não somam.
6. **Botão Sync**: **dois botões independentes** (um por página), com polling de progresso separado. A coluna `sync_log.scope` distingue os dois tipos de sync e evita colisão entre eles.

## Decisões pós-spike (2026-04-22)

Output do `tests/spike_collaborators.php` + probes extra confirmaram:

### 1. Grupo "design" — resolvido

- Endpoint: **`GET /group?team_id={team_id}`** (o `/team/{id}/group` devolve 404 — não é o formato v2).
- Identificação: **por nome** (substring `design`, case-insensitive). O UUID `1b9513be-3061-45ea-bbdb-b66e8c056016` bate com o que vem na URL do ClickUp do utilizador, mas usar o UUID hardcoded seria frágil se o grupo fosse recriado.
- Membros vêm **embutidos** no payload do próprio `/group` (campo `members: [{id, username, email, …}]`) — **não é preciso chamada extra** ao `/group/{id}`.
- Membros atuais do grupo "design" (4): 473908 (Diego), 4727509 (Ruben), 54230261 (Karllos), 87600945 (Carolina).
- Personal API key (`pk_…`) funciona sem scope adicional — passa no header `Authorization` sem prefixo `Bearer`, igual ao OAuth.

### 2. Weekly Hours — **BLOQUEADO**

Nenhum endpoint público do plano atual devolve `weekly_hours`:

| Probe | Resultado |
|---|---|
| `GET /user` | 200, sem campo de horas |
| `GET /team/{id}` (members[]) | 200, sem campo de horas |
| `GET /group?team_id={id}` (members[]) | 200, sem campo de horas |
| `GET /team/{id}/user/{id}` | **403 `TEAM_110`: "Team must be on enterprise plan"** |
| `GET /team/{id}/capacity` | 404 |
| `GET /user/{id}/weekly_hours` | 404 |
| `GET /workload?team_id=…` | 404 |

A feature Workload (onde `weekly_hours` vive na UI do ClickUp) exige plano **Enterprise** para ficar exposta via API. Este workspace ("QRA") não está em Enterprise.

**Decisão (2026-04-22, confirmada pelo utilizador): Opção A — config local.**

- Constante `WEEKLY_HOURS_PER_USER` em `config.php` (mapa `user_id → horas`), editável em `config.local.php` (gitignored) com os valores reais.
- Constante `DEFAULT_WEEKLY_HOURS` como fallback para user_ids não mapeados.
- Racional: single admin, 4 membros no grupo, mapa muda 1-2× por ano → tabela + UI seria overkill. Mesmo padrão das credenciais OAuth e do `DEPARTMENT_HEAD_USER_ID`.
- Implicação: thresholds de status (under/ok/over) dependem apenas deste mapa; se um user do grupo não estiver no mapa, usa `DEFAULT_WEEKLY_HOURS` e marca um aviso discreto na UI.
- Alternativas rejeitadas: (B) tabela + UI de edição — overkill; (C) abandonar métrica de carga — perde o valor principal da vista; (D) valor único global — menos fiel sem ganho face a A.

### 3. Time entries — resolvido

- Endpoint: `GET /team/{team_id}/time_entries?start_date={ms}&end_date={ms}&assignee={csv}` funciona como esperado.
- Formato datas: **milissegundos epoch** (confirmado pelos valores `start`/`end` dos entries devolvidos).
- `assignee` **aceita múltiplos IDs separados por vírgula** (testei 2, obtive entries dos dois user_ids) — poupa chamadas.
- Sem paginação observada no mês corrente (30 entries devolvidos num pedido); documentação do ClickUp sugere limite implícito alto. Se uma janela de mês completo para 4 assignees ultrapassar o limite na produção, trata-se como futuro KI (não é crítico agora).
- Payload por entry tem `task: {id, name, status}`, `user: {id, …}`, `start`, `end`, `duration` (ms, string), `wid`, `task_location`. Campo `task` veio sempre presente — **0/30 entries sem task** no spike. Vamos na mesma defender com `if (empty($e['task']))`.

### 4. Rate limit — resolvido

- Spike fez **5 requests em 3.45s**, nenhum 429.
- Estimativa por sync de Colaboradores: 1 (grupos) + 1 (time_entries com CSV de assignees) = **2 chamadas**. Chefe clica Sync → 2 req. Confortável mesmo no pior cenário.
- **Cache em `time_entries` ainda faz sentido** (conforme spec) para o GET do `api/collaborators.php` não ir à API em cada load — só o botão Sync refresca. Mantém a experiência rápida e desacopla UI de rate limit.

### 5. Paginação e volume

- Nada a fazer agora. Revisitar se observarmos trunca em produção.

### Desbloqueio

Fase A concluída, decisão registada → Fase B pode arrancar.

## Referências

- `specs/00-context/clickup-integration.md` — endpoints já consumidos e padrão de auth.
- `specs/00-context/known-issues.md#KI-05` (rate limit) e `#KI-06` (admin-by-name).
- `specs/00-context/decisions.md#D7` — testes como scripts PHP puros; o spike segue a mesma convenção.
