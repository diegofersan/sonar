# F02 — Plano de carga semanal (preditivo, baseado em estimates)

**Status:** approved
**Tipo:** feature
**Criada em:** 2026-04-22
**Aprovada em:** 2026-04-22 (utilizador delegou decisões pendentes ao Claude)

> **Relação com F01:** F01 (Colaboradores) é *descritivo* — mostra horas **realizadas**
> por dia a partir de `time_entries`. F02 é *preditivo* — mostra horas **planeadas** por
> dia a partir de `tasks.time_estimate` + datas, para detectar dias que vão estourar a
> capacidade **antes** de acontecerem.

## Problema

Hoje o chefe de departamento só sabe se um colaborador estourou a carga **depois** de o
tempo ter sido lançado (via time_entries). Não há sinal de alerta *antes*: se houver três
tasks pesadas com entrega na mesma terça-feira, o chefe só vê o pico quando a semana já
passou.

A informação existe no ClickUp — as tasks têm `time_estimate` e datas (`start_date`,
`due_date`) — mas o Sonar **não persiste `time_estimate`** hoje (a coluna não existe em
`tasks`, apesar de vir no payload da API e ser simplesmente descartada no upsert).

## Proposta

Acrescentar uma leitura preditiva da carga semanal: para cada colaborador do grupo
"design", mostrar **por dia** a soma das estimativas das tasks atribuídas, comparada com a
capacidade diária derivada de `weekly_hours`. Dias com total > capacidade ficam marcados
visualmente como "a estourar".

## User Story

Como chefe de departamento, quero ver antecipadamente que dias da semana de cada
colaborador vão ficar sobrecarregados com base nas estimativas das tasks atribuídas,
para redistribuir trabalho antes do pico acontecer.

## Perguntas abertas (a fechar no spike antes de finalizar a spec)

### Q1 — Que % de tasks têm `time_estimate` preenchido?

Se for baixo (< 30 %), a feature tem valor limitado sem primeiro empurrar para a equipa
preencher. Determina se a política para `null` é "silencioso (conta 0h)" ou "warning
visual" (incentiva a preencher).

### Q2 — Que % têm `start_date`?

Decide entre os modelos de distribuição (ver Q3).

### Q3 — Modelo de distribuição da estimativa por dia

- **A. Bucket pelo `due_date`.** Task de 8h devida sexta → 8h em sexta. Simples, mas
  concentra tudo nos dias de entrega.
- **B. Distribuir entre `start_date` e `due_date`.** Divide igualmente pelos dias úteis
  do intervalo. Mais justo, mas degenera em A quando `start_date` é null.
- **C. Como B, com fallback "duração mínima N dias úteis antes do due_date"** quando
  `start_date` está ausente (N configurável, default 1).

Escolher com base em Q2.

### Q4 — Capacidade diária

`weekly_hours / 5` (seg–sex) ou `/ 7` (espalhar por semana)? O F01 já mostra os 7 dias
mas é retroativo — no preditivo, `/ 5` é provavelmente mais realista. **Decisão
pendente.**

### Q5 — Overdue e canceladas

- Task overdue (due_date no passado, status não terminal): conta **hoje**? Conta no
  due_date original (mostra um pico retroativo que já passou)? Não conta?
- Task cancelada/fechada: ignora (decisão provável, mas confirmar).

### Q6 — Parent vs subtask

ClickUp permite estimate em ambos. Somar os dois duplica se o parent agregar subtasks.
Spike tem de inspecionar dados reais para ver qual é a convenção da equipa QRA.

### Q7 — Lista a considerar

O F01 restringe ao grupo "design". F02 faz o mesmo? Ou aplica a todos os workspaces que
o chefe de departamento vê? **Decisão provável: mesmo scope (grupo design)**, mas
confirmar.

## Decisões (aprovadas 2026-04-22)

Fechadas pelo Claude com delegação do utilizador. Cada uma pode ser revista se o spike
da Tarefa 1 trouxer números que as contradigam.

### D1 · Política para `time_estimate` em falta

**Decisão:** contar **0 h silencioso**, mas mostrar por dia um contador "N tasks sem
estimativa" a cinzento. Mantém a leitura principal limpa (barras em horas) e dá
visibilidade à incerteza sem bloquear a feature.

*Porquê:* políticas "null = warning visual" poluem a vista quando % de null é alto
(que é provável dado o estado actual). Zero + contador é honesto e accionável: o chefe
vê "Ter: 6h planeadas, 4 sem estimativa" e decide se investiga.

### D2 · Modelo de distribuição por dia

**Decisão:** modelo **C — distribuir entre `start_date` e `due_date` em dias úteis,
com fallback "duração mínima 1 dia útil antes do `due_date`"** quando `start_date` é
null. Se ambos são null, a task **não entra** no cálculo (sem âncora temporal).

*Porquê:* é um superset de A e B. Degrada gracefully. O fallback de 1 dia mantém a
estimativa visível em vez de a deitar fora. Fim de semana (sáb/dom) é saltado —
distribuição só em seg–sex.

### D3 · Capacidade diária

**Decisão:** `weekly_hours / 5` (seg–sex). Fim de semana **não aparece** na vista
preditiva.

*Porquê:* predição é sobre dias úteis. Mostrar sáb/dom vazios só adiciona ruído. A
vista Colaboradores (descritiva) continua a mostrar 7 dias porque time entries em
fim-de-semana são dados reais; esta inconsistência visual é intencional e sinaliza a
diferença cognitiva entre as duas vistas.

### D4 · Overdue e estados terminais

**Decisão:**
- **Overdue** (due_date no passado, status não terminal): conta em **hoje** até fechar.
  Flag visual (ícone / cor) a dizer "overdue — conta aqui porque ainda não fechou".
- **Cancelada / fechada / complete:** ignorar. Qualquer status terminal do workspace
  não entra.

*Porquê:* esconder overdue de hoje dava falsa sensação de capacidade livre. O trabalho
ainda precisa de ser feito. Visibilidade > estética.

### D5 · Parent vs subtask

**Decisão default (a confirmar no spike):** se uma task tem subtasks com estimate
próprio, **só as subtasks contam**; o estimate do parent é ignorado (assume-se
roll-up). Se o parent tem estimate e as subtasks não, usa-se o parent. A Tarefa 1 do
`tasks.md` amostra dados reais para confirmar a convenção da equipa; se divergir,
a regra fica como constante num único sítio e muda numa linha.

### D6 · Scope

**Decisão:** mesmo scope do F01 — **membros do grupo "design"** no workspace. Reusa
`clickup_find_design_group()` + `clickup_group_member_ids()`.

### D7 · Janela temporal da vista

**Decisão:** **semana corrente** (seg–sex), sem navegação entre semanas na v1.
Pode-se acrescentar botão "semana seguinte" em F03+ se pedirem.

*Porquê:* v1 minimalista. A pergunta original era "que dias da semana da pessoa vão
estourar" — uma semana de cada vez responde à pergunta.

### D8 · Caminho UI

**Decisão:** **nova vista "Plano da semana"** na navbar (entrada nova, entre
"Linha Editorial" e "Colaboradores"), department-head only como o F01.

*Porquê:* descritivo vs preditivo têm cognições diferentes; misturar barras
"planeado + realizado" na grelha do F01 enche-a. Separar mantém cada card legível.
Reavaliar fusão mais tarde se o uso real mostrar que os dois são consultados sempre
em conjunto.

## Critérios de Aceitação

- [ ] Existe coluna `time_estimate_ms` (INTEGER nullable) em `tasks`, populada a cada
      sync de tarefas (o payload já traz o campo — é só parar de o descartar).
- [ ] Existe entrada "Plano da semana" na navbar do `dashboard.php`, só visível para o
      chefe de departamento (server-side, mesmo padrão do "Colaboradores").
- [ ] A nova vista mostra, para cada colaborador do grupo design:
      - Grelha seg–sex da semana corrente (Europe/Lisbon).
      - Por dia: horas planeadas + capacidade diária (`weekly_hours / 5`) + estado
        `under` / `ok` / `over` (mesmos thresholds do F01: 80 % / 110 %).
      - Contador "N sem estimativa" por dia, a cinzento, quando aplicável.
- [ ] Dias `over` ficam visualmente distintos (cor + badge), iguais em linguagem aos
      do F01 para consistência.
- [ ] Overdue tasks contam em **hoje** e têm marcação visual específica (ex.: ícone ou
      borda da célula).
- [ ] Tasks canceladas / em status terminal são ignoradas na soma.
- [ ] Endpoint `api/workload_forecast.php` devolve 403 a não-chefes, 401 a não-autenticados.
- [ ] **Zero chamadas novas à API ClickUp** — tudo sai do SQLite local. Sync de tasks
      existente passa a persistir o campo; nenhum novo endpoint ClickUp é consumido.
- [ ] A vista carrega em < 500 ms a partir do SQLite populado (query com índice
      existente `idx_tasks_workspace` + filtro por assignees).
- [ ] Nenhum sync existente fica mais lento. O único custo extra é persistir um int
      por task no upsert.
- [ ] `tests/test_workload_*.php` cobre os casos: distribuição C com start_date,
      fallback 1-dia quando start null, skip de fim de semana, skip de status
      terminal, contador de sem-estimativa, weekly_hours=0.

## Impacto

- **Schema:**
  - `ALTER TABLE tasks ADD COLUMN time_estimate_ms INTEGER` (try/catch, padrão KI-09).
  - Sem tabelas novas. Agregação faz-se em query + PHP por request.
- **API ClickUp:** **zero chamadas novas.** `time_estimate` já vem na resposta de
  `/team/{id}/task`; basta persistir.
- **Rotas/Endpoints:**
  - Novo `api/workload_forecast.php` (GET, department-head only).
  - Eventual nova entrada na navbar "Plano da semana" OU integração dentro da vista
    Colaboradores (decisão UI aberta — ver secção seguinte).
- **Dependências:** nenhuma.

## Decisões UI (a alinhar)

Dois caminhos, a escolher antes das tarefas:

1. **Sobrepor na vista Colaboradores existente.** A grelha seg–dom passa a mostrar
   "planeado (barra leve) + realizado (barra sólida)" por dia. Denso mas unificado.
2. **Nova vista "Plano da semana".** Nova entrada na navbar, card por colaborador com
   foco exclusivo no preditivo. Mais limpo, mas duplica navegação.

**Proposta default**: começar por (2) — separação clara preditivo / descritivo — e
avaliar fusão mais tarde se fizer sentido.

## Decisões pós-spike (2026-04-22)

Resultados do `tests/spike_workload.php` contra workspace `2590506`:

| Métrica                          | Valor | Fonte   |
| -------------------------------- | ----: | ------- |
| Total tasks no cache             |   368 | SQLite  |
| Com `start_date`                 |  7.9 %| SQLite  |
| Com `due_date`                   | 52.2 %| SQLite  |
| Com `parent_id` (são subtasks)   | 96.5 %| SQLite  |
| **Com `time_estimate > 0`**      |   **3 %** | API (sample 100) |
| Status terminal                  | 36 %  | API sample |
| Double-count parent+child estimate | 0 | API sample |

### Descoberta central

Só **3 %** das tasks têm `time_estimate` preenchido. A feature na forma original
("horas planeadas vs capacidade") ia mostrar barras vazias e contadores enormes de
"sem estimativa" — sem valor imediato.

### Pivot aprovado

**Mudar de horas planeadas para contagem de tasks activas por dia.** Cada task
atribuída a um colaborador conta como **1 unidade/dia** nos dias em que está activa
(modelo C de distribuição continua válido, só muda a unidade). Capacidade diária passa
a ser um **alvo de nº de tasks por dia** (configurável).

Evolução futura natural: quando a equipa começar a preencher `time_estimate` com
regularidade (> 30 %), ponderar voltar ao modelo de horas — o schema para isso está
desenhado mas não implementado em v1.

### Decisões revistas

- **D1 (SAI)** — política para estimate null deixa de existir. V1 não usa estimates.
- **D9 (ENTRA)** — **alvo diário de tasks por colaborador**, configurável.
  `DAILY_TASKS_PER_USER` (mapa `user_id => int`) + `DEFAULT_DAILY_TASKS` (default **5**)
  em `config.php`, override em `config.local.php` e env, mesmo padrão de `weekly_hours`.
  Thresholds mantêm-se 80 % / 110 %: um dia com ≤ 4 tasks (80 % de 5) fica `under`,
  entre 4 e 5.5 fica `ok`, > 5.5 fica `over`.
- **D2 (MANTÉM)** — modelo C, mas a unidade distribuída é `1` (e depois somada) em vez
  de horas. Dias úteis seg–sex apenas.
- **D3 (ADAPTA)** — "capacidade diária" passa a ser alvo de nº de tasks (D9). Sáb/dom
  continuam sem aparecer.
- **D4 (FECHA LISTA)** — terminais confirmados (não entram no forecast):
  `published`, `mês social terminado`, `post cancelado`, `linha editorial cancelada`,
  `scheduled`, `ready to post`. Os dois últimos foram ambíguos; decisão: uma vez
  scheduled/ready, não há mais trabalho de design activo. **Flipable** em 1 linha se
  a equipa discordar. Lista fica como constante `FORECAST_TERMINAL_STATUSES` no topo
  do helper.
- **D5 (NOVA SEMÂNTICA)** — sem estimates, a pergunta "parent vs filho" muda: se uma
  task tem children na mesma resposta, **só os filhos contam** (as leaves são o
  trabalho real). Parents LE/mês não inflam contagens. Spike confirmou que parents
  no workspace QRA são mesmo estruturais (96 % de tasks são subtasks).
- **D6 (MANTÉM)** — grupo design.
- **D7 (MANTÉM)** — semana corrente seg–sex.
- **D8 (MANTÉM)** — nova vista "Plano da semana" na navbar.

### Critérios de aceitação revistos

Substituem os anteriores:

- [ ] Existe entrada "Plano da semana" na navbar do `dashboard.php`, só visível para o
      chefe de departamento (server-side, mesmo padrão do "Colaboradores").
- [ ] A vista mostra, para cada colaborador do grupo design:
      - Grelha seg–sex da semana corrente (Europe/Lisbon).
      - Por dia: **nº de tasks activas** + alvo diário + estado `under/ok/over`
        (thresholds 80 %/110 % do alvo configurado em `DEFAULT_DAILY_TASKS` /
        `DAILY_TASKS_PER_USER`).
      - Contador "N sem data" por dia, a cinzento, para tasks que existem para o
        colaborador mas não podem ser colocadas (sem `start_date` nem `due_date`).
- [ ] Dias `over` visualmente distintos, mesma linguagem do F01.
- [ ] Overdue tasks contam em **hoje** com marcação visual.
- [ ] Tasks em status terminal (lista em D4 revisto) são ignoradas.
- [ ] Endpoint `api/workload_forecast.php` devolve 403/401 nas condições certas.
- [ ] **Zero chamadas novas à API ClickUp.** Tudo do SQLite local. Nenhuma migration
      de schema em v1 (as colunas `start_date`, `due_date`, `parent_id`, `status_name`
      e a tabela `task_assignees` já contêm tudo o que é preciso).
- [ ] Vista carrega em < 500 ms.
- [ ] Nenhum sync existente é alterado.
- [ ] Testes puros cobrem: distribuição C com start/due, fallback 1-dia, skip
      fim-de-semana, skip status terminal, skip parent quando há filhos na resposta,
      overdue empurrada para hoje, contador de "sem data", thresholds 80/110.

## Não-objetivos

- **Não** altera o fluxo de time tracking nem a vista Colaboradores descritiva (F01
  continua a mostrar o que mostra hoje).
- **Não** introduz um editor de estimates dentro do Sonar — continuam a ser geridos no
  ClickUp.
- **Não** faz previsão para além da semana corrente (sem rolling forecast, sem grávida
  de custos, sem "o que aconteceria se movesse esta task"). Sprint preditivo é *só*
  mostrar o que já está atribuído na semana corrente.
- **Não** cobre tasks recorrentes (complicadas de distribuir). Se forem raras, seguem a
  mesma regra de tasks normais; se forem frequentes, fica para F03+.

## Referências

- F01 — `specs/20-done/F01-colaboradores-carga/` (descritivo; esta spec estende o mesmo
  conceito para o lado preditivo).
- KI-05 (`specs/00-context/known-issues.md`) — rate limit. F02 não degrada, por não
  adicionar calls.
- KI-09 — convenção de `ALTER TABLE` try/catch para migrations.
