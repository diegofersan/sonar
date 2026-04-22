# F04 — Navegação mensal e totais de carga em Colaboradores

**Status:** approved
**Tipo:** feature
**Criada em:** 2026-04-22

## Problema

A vista **Colaboradores** mostra apenas o mês corrente. A department head
(Diego) não consegue:

1. Rever carga de meses anteriores (ex.: quem bateu capacidade em março,
   quem ficou sub-ocupado em fevereiro) sem ir ao ClickUp directamente.
2. Ver, num relance, o **total de horas expectáveis** vs. **total de
   horas efectivamente trabalhadas** no mês — tem de somar de cabeça
   olhando para a coluna "Total" semana a semana e comparando mentalmente
   com `weekly_hours`.

## Proposta

Adicionar setas `← / →` no cabeçalho da vista Colaboradores para saltar
entre meses, e um resumo mensal em cada cartão com "Expectável: Xh" e
"Trabalhado: Yh" + badge de status (under/ok/over) usando a mesma lógica
já aplicada às semanas.

## User Story

Como department head, quero navegar entre meses na vista Colaboradores e
ver, em cada cartão, quantas horas cada pessoa devia ter trabalhado vs.
quantas trabalhou, para identificar rapidamente desvios de carga sem
recalcular manualmente.

## Critérios de Aceitação

### Navegação

- [ ] Na toolbar dos Colaboradores, ao lado da label do mês, aparecem
      dois botões `←` e `→`.
- [ ] `←` volta um mês. `→` avança um mês. Ambos actualizam a label e
      refazem o fetch.
- [ ] `→` fica desactivado quando o mês corrente está a ser mostrado
      (não há dados no futuro).
- [ ] `←` não tem limite inferior (pode-se navegar até 2024 se houver
      entries sincronizados).
- [ ] O mês seleccionado persiste no hash da URL (ex.: `#collaborators/2026-03`)
      para que refresh/deep-link funcione. Sem hash = mês actual.
- [ ] O botão **Sync** sincroniza o **mês actualmente seleccionado** (não
      o mês de calendário) — útil quando se navega para um mês passado
      ainda não sincronizado.

### Totais mensais

- [ ] Cada cartão mostra, acima da tabela de semanas, uma linha com:
      `Expectável: Xh · Trabalhado: Yh · <badge under/ok/over>`.
- [ ] **Expectável** = `weekly_hours` × número de semanas ISO no window
      do mês (mesmo window que o aggregator já usa — inclui semanas que
      cruzam o limite do mês).
- [ ] **Trabalhado** = soma de `total_hours` em todas as semanas do
      cartão.
- [ ] Badge do mês usa a mesma classificação das semanas
      (`COLLAB_UNDER_RATIO` 0.80, `COLLAB_OVER_RATIO` 1.10).
- [ ] Meses sem dados (cache vazia para o window) mostram o cartão na
      mesma com Trabalhado: 0h e o estado de empty handling actual
      ("Ainda sem dados — clica Sync").

## Impacto

- **Schema:** sem alterações. Time entries já estão armazenadas com
  `start_ms` absoluto — o window apenas filtra.
- **API ClickUp:** nenhuma chamada nova na vista (os dados vêm do cache).
  Sync continua a usar `clickup_get_time_entries` — única diferença é
  o window ser parametrizável.
- **Rotas/Endpoints:**
  - `GET /api/collaborators.php?month=YYYY-MM` — parâmetro novo,
    opcional. Sem param ou param inválido = mês actual. `YYYY-MM` tem
    de ser passado ou actual; futuros rejeitados com 400.
  - `POST /api/sync_time_entries.php?month=YYYY-MM` — aceita o mesmo
    parâmetro para sincronizar um mês específico. Default = mês actual.
    `last_sync` continua a ser gravada no `sync_log` com `scope='time_entries'`
    (sem dimensão mês por enquanto — a UI pode mostrar "Última sync" geral).
- **Dependências:** nenhuma.
- **Rate limits:** se o utilizador saltar rapidamente entre meses, o fetch
  local ao SQLite é trivial. Só o botão Sync bate no ClickUp — mesmo
  custo de uma sync actual, sem multiplicador.

## Invariantes

- F01 (aggregator + popup do dia) continua a funcionar igual para o mês
  actual. O contrato de `collab_aggregate_weeks` **não muda** — só o
  window que é passado.
- F03 (post-context em time entries) inalterado.
- `collab_month_window($tz, $now)` já aceita um `$now` arbitrário, por
  isso passar um `DateTimeImmutable` construído a partir de `YYYY-MM-01`
  é o suficiente — sem refactor do helper.
- `test_collaborators_aggregate.php` (43 asserts) continua a passar sem
  alterações.

## Estratégia (a detalhar em tasks.md)

1. **Backend: window parametrizável.** `api/collaborators.php` e
   `api/sync_time_entries.php` passam a aceitar `?month=YYYY-MM`.
   Novo helper `collab_parse_month_param(string $raw, DateTimeZone $tz): ?DateTimeImmutable`
   em `includes/collaborators.php` — `null` = mês actual; inválido ou
   futuro → lança. Teste unitário do parser.

2. **Backend: totais mensais.** Adicionar ao response de collaborators:
   ```
   "month_totals": {
     "expected_hours": number,
     "worked_hours":   number,
     "status":         "under|ok|over"
   }
   ```
   por cada colaborador. Calculado a partir da lista de `weeks` que já é
   devolvida — sem double-counting.

3. **Frontend: navegação.** Estado `_collabMonth` (string `YYYY-MM` ou
   `null` = actual). Handlers `cal-prev`/`cal-next`. Actualizam hash e
   chamam `fetchCollaborators(_collabMonth)`. Sync passa o mês como
   query param.

4. **Frontend: render do total.** `renderCollabCard` mostra a linha
   "Expectável: Xh · Trabalhado: Yh · badge" logo abaixo do header,
   antes da tabela.

5. **Validação + docs.**

## Não-objetivos

- Intervalo arbitrário (ex.: últimos 30 dias, trimestre). Só navegação
  mês-a-mês.
- Export CSV / relatório downloadable. Fora de âmbito.
- Comparar dois meses lado-a-lado.
- Histórico por pessoa (gráfico temporal). Pode surgir numa F05.
- Monthly summary agregado no toolbar (soma de todos os colaboradores).
  Só per-card nesta spec — se for útil, adicionamos numa iteração.
- Auto-sync ao navegar para um mês vazio. O utilizador carrega Sync
  explicitamente (respeita rate limits, evita surpresas).
- Alterar `last_sync` para ter dimensão mês. Continua global.

## Referências

- R01 (remoção do Plano da semana) deixou clara a preferência por UI
  accionável e directa.
- F01 definiu o aggregator semanal que esta feature reaproveita.
- `includes/collaborators.php` — `collab_month_window` já aceita `$now`
  arbitrário, por isso a parametrização é quase de graça.
