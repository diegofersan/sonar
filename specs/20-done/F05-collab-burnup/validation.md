# F05 — Validação

**Concluída em:** 2026-04-22

## Sanity check do helper

Spike em Node com dois cenários (cumulativo agregado + corte no futuro):

```
PAST (team weekly=80, 5 weeks, meta=400):
  meta_total: 400 ✓
  real_total: 345 ✓
  W9  meta=80  real=50
  W10 meta=160 real=135
  W11 meta=240 real=210
  W12 meta=320 real=282
  W13 meta=400 real=345

CURRENT (today 2026-04-22, week 18 starts 2026-04-27):
  meta_total: 200 ✓
  real_total: 125 ✓  (soma até W17, inclusive)
  W14 meta=40  real=35
  W15 meta=80  real=75
  W16 meta=120 real=113
  W17 meta=160 real=125
  W18 meta=200 real=null  ← semana futura, polyline corta aqui
```

Invariantes a verificar manualmente no browser:

- [ ] Chart aparece entre a toolbar e os cartões.
- [ ] Altura proporcional (viewBox 720×200; escala via CSS).
- [ ] Grid horizontal a cada 25% da altura; labels em múltiplos
      sensatos de h (auto-escalado para múltiplo de 40).
- [ ] Linha Meta tracejada cinza; linha Real sólida no primary.
- [ ] Círculo em cada intersecção; hover mostra tooltip com
      `Wxx · Meta · Real · Δ`.
- [ ] Legenda textual com mês, meta total, trabalhado total, delta.
- [ ] Delta negativo a vermelho, positivo a verde.
- [ ] Mês passado: ambas as linhas inteiras.
- [ ] Mês corrente: linha Real pára na semana ISO actual.
- [ ] Mês sem dados (sem last_sync): chart escondido.

## Checks

```
node --check assets/js/app.js → OK
```

Sem novos testes automatizados: o helper é JS puro e pequeno, e o
teste manual acima cobre os dois caminhos não-triviais
(`isFuture` e cumulativo). Se um dia a lógica crescer, extrai-se
para um módulo testável.

## Commits

```
573e716  F05: spec e tasks para burn-up na primeira dobra de Colaboradores
30229e0  F05: helper computeCollabBurnup + render SVG do burn-up
9bc91e0  F05: estilo do burn-up chart
```

## Impacto em produção

- **Schema / API / ClickUp:** nada.
- **Pipeline de render:** `renderCollaborators` ganha uma chamada
  extra `renderCollabBurnup(data)` antes dos cartões. Em meses sem
  dados ou sem membros, o chart esconde-se — zero impacto visual
  no empty-state existente.
- **Performance:** para a ordem de grandeza actual (≤ 10 colabs, ≤ 6
  semanas) o cálculo é trivial (O(n·w)) e o SVG tem < 30 nós.
