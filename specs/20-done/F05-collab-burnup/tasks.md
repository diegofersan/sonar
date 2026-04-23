# F05 — Tasks

Um commit por tarefa, `F05: …`.

## 1. Helper puro para computar a série do burn-up

- [ ] `assets/js/app.js`: função `computeCollabBurnup(data)` que recebe
      o payload do `/api/collaborators.php` e devolve
      `{weeks: [{label, meta_h, real_h | null}], meta_total, real_total}`.
  - Meta semanal = `Σ weekly_hours` (todos os colabs).
  - `meta_h[k]` = `meta_semanal × (k+1)`.
  - `real_h[k]` = `Σ_user Σ_week<=k total_hours`.
  - Mês corrente: para semanas com Monday > hoje, `real_h = null` (polyline
    corta-se ali).
  - Label = `Wxx` (`weeks_meta[k].week_number`).
- [ ] Expor no `window.Sonar` (em modo non-production se preciso) só
      para permitir testes ad-hoc no browser; não publicar um test
      file novo (o helper é trivial e JS puro).

## 2. Render do SVG burn-up

- [ ] `dashboard.php`: inserir `<div id="collab-burnup"></div>` entre a
      toolbar de Colaboradores e `#collab-list`.
- [ ] `assets/js/app.js`:
  - [ ] `renderCollabBurnup(data)`: constrói SVG inline com
    - Eixos simples (linhas cinza + labels Y em múltiplos de 40 ou
      auto-escala), 
    - Polyline "Meta" tracejada (cor secondary),
    - Polyline "Trabalhado" sólida (cor primary),
    - Círculos de 3px nas intersecções semanais,
    - `<title>` por ponto: `Wxx · Meta: Xh · Real: Yh · Δ ±Zh`.
  - [ ] Legenda textual acima do SVG:
        `Abril 2026 · Meta: 160h · Trabalhado: 128h · -32h`.
  - [ ] `renderCollaborators(data)` chama `renderCollabBurnup(data)`
        antes de renderizar os cartões.
  - [ ] Estado sem dados (`!data.last_sync`): esconde o chart, deixa o
        empty-state existente tomar conta.
  - [ ] `node --check assets/js/app.js` passa.

## 3. Estilo

- [ ] `assets/css/style.css`: `.collab-burnup`, `.collab-burnup-legend`,
      `.collab-burnup svg`, `.collab-burnup .axis`, `.meta-line`,
      `.real-line`, `.meta-dot`, `.real-dot`. Paleta alinhada com
      `.collab-badge.*` já existente (primary/secondary/text-secondary).
- [ ] Responsive: se a viewport for < 720px, reduzir padding; gráfico
      ocupa 100% da largura via `viewBox` (não fixar px).

## 4. Validação + fechar

- [ ] Testar visualmente:
  - Mês passado com dados: duas linhas completas, Δ coerente com a
    soma manual.
  - Mês corrente: "Trabalhado" pára na semana ISO actual.
  - Mês sem dados: chart escondido, só empty state.
- [ ] Criar `validation.md`.
- [ ] `git mv specs/10-active/F05-collab-burnup specs/20-done/`.
