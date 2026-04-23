# F05 — Gráfico burn-up na primeira dobra de Colaboradores

**Status:** done
**Tipo:** feature
**Criada em:** 2026-04-22

## Problema

Hoje o utilizador só percebe se a equipa está dentro do plano quando
rola os cartões e faz a soma mental dos badges (under/ok/over). Em
meio de mês é difícil ver "estamos atrasados vs. o ritmo expectável".

## Proposta

Acima dos cartões, um gráfico burn-up pequeno (≤ 200px de altura) que
mostra, para o mês seleccionado:

- **Meta** — linha recta de `0h` a `total_expected_hours`, a subir
  semana a semana ao ritmo `weekly_hours × semana_acumulada`.
- **Trabalhado** — linha cumulativa real, semana a semana, para toda
  a equipa agregada.
- Eixo X: semanas ISO do window (4 ou 5 pontos).
- Eixo Y: horas.

Leitura instantânea: a linha "Trabalhado" acima da "Meta" = equipa
adiantada; abaixo = atrasada.

## User Story

Como department head, quero ver um gráfico burn-up no topo dos
Colaboradores para perceber de relance se a equipa está no ritmo ou
não, sem ter de percorrer todos os cartões.

## Critérios de Aceitação

- [ ] O gráfico aparece entre a toolbar e o primeiro cartão.
- [ ] Altura ≤ 200px; ocupa a largura total da área de conteúdo.
- [ ] Duas polylines em SVG: Meta (tracejada, cor secundária) e
      Trabalhado (sólida, cor primária).
- [ ] Pontos em cada intersecção semana — circulito + tooltip nativo
      (`<title>`) com `Wxx · Meta: Xh · Real: Yh · Δ ±Zh`.
- [ ] Label "Abril 2026 · Meta 160h · Trabalhado 128h · -32h"
      acima do SVG.
- [ ] Em meses já passados, as duas linhas cobrem a largura toda.
- [ ] No mês corrente, a linha "Trabalhado" pára na semana ISO actual
      (não é prolongada com 0h para o futuro).
- [ ] Em meses sem dados (pré-sync), o gráfico mostra só a linha
      "Meta" e um estado vazio textual para "Trabalhado".
- [ ] Recalculado sempre que o response chega (inclui ao mudar de mês
      via setas).

## Cálculo

Aproveita o que já existe. Sem endpoint novo; sem novas chamadas à API.

Dado o response actual de `/api/collaborators.php`:

- `weeks_meta` → pontos do eixo X.
- `collaborators[*].weekly_hours` → capacidade individual.
- `collaborators[*].weeks[*].total_hours` → trabalhado por semana.

Para cada ponto `k` (0..N-1):

```
meta[k]        = (Σ weekly_hours) × (k + 1)
trabalhado[k]  = Σ_user Σ_week<=k collaborators[u].weeks[w].total_hours
```

Soma de `weekly_hours` de todos os colaboradores = capacidade semanal
da equipa. Multiplicado pelo n.º da semana = meta acumulada até ali.

Mês corrente: `trabalhado[k]` é `null` para semanas cuja Monday ISO
ainda não começou. A polyline simplesmente não passa por esses
pontos.

## Impacto

- **Schema:** nada.
- **API:** nada. Todos os dados já estão no payload de F04.
- **Rotas:** nenhuma.
- **Dependências:** nenhuma. SVG inline e CSS.
- **Rate limits:** sem efeito (cálculo 100% client-side).

## Decisões e alternativas que descartei

- **Granularidade diária** em vez de semanal: 30 pontos na horizontal
  na primeira dobra fica ilegível num ecrã normal; fim-de-semana
  adiciona ruído (capacidade semanal é implicitamente Mon-Fri). A
  semanal alinha-se com o resto da vista e 4-5 pontos chegam para
  perceber o ritmo.
- **Uma linha por colaborador**: os cartões já fazem essa leitura
  per-pessoa. O valor do gráfico é a visão de equipa.
- **Burn-down** (resto a fazer) em vez de burn-up: "expectável é a
  meta" do teu pedido encaixa melhor em burn-up (eixo Y = progresso
  para cima). Podemos inverter se preferires.
- **Library de charts** (Chart.js, etc.): overkill para 2 polylines.
  Mantém o stack vanilla.

## Não-objetivos

- Filtros de colaborador no gráfico (ver só o João vs. a equipa).
- Hover highlight cross-chart-e-cartões.
- Export de imagem.
- Linhas históricas sobrepostas (comparar com mês anterior).

## Referências

- F04 adicionou `month_totals` por colaborador — o sumário textual
  que fica agora no cartão. O gráfico é o equivalente temporal
  agregado.
