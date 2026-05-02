# F06 — Validação

## Sintaxe
- `node -e "new Function(require('fs').readFileSync('assets/js/app.js','utf8'))"` → OK (parse-check do `app.js`).
- CSS: alterações simples (uma propriedade adicional em `.collab-day` e uma nova regra `.collab-day-num`). Sem regressões esperadas.

## Verificação visual (manual)
Servidor local com `php -S localhost:8080`, vista de Colaboradores:
- [x] Cada célula da grelha semanal (linhas de dados, SEG..DOM) mostra o dia do mês no canto inferior direito.
- [x] Tamanho ≈9px, cor secundária com `opacity: 0.55` — discreto, não compete com as horas no centro.
- [x] Cabeçalho (linha `Sem | SEG | TER | ... | Total | Carga`) **não** tem números — confirmado, JS só emite o `<span>` no loop das linhas de dados.
- [x] Células sem horas (`—`) mostram à mesma o dia do mês — útil para leitura calendárica contínua.
- [x] Células com horas (clicáveis) mostram o dia e mantêm o clique → popup do dia.
- [x] Cruzamento com o popup do dia (que mostra `dd/mm`) confirma que os números estão alinhados com o calendário real.
- [x] Mobile (`@media (max-width: 720px)` em `.collab-week-row`): número permanece no canto, sem quebrar o layout.

## Critérios de aceitação
- [x] Número do dia em cada `.collab-day` de dados.
- [x] ≈9px e cor mais discreta que o conteúdo principal.
- [x] Funciona em `<button>` (com horas) e `<div>` (sem horas).
- [x] Derivado de `w.week_start + DAY_KEYS.indexOf(dayKey)`, sem chamadas extra.
- [x] Cabeçalhos não recebem o número.
- [x] Mobile não quebra.

## Impacto
- API ClickUp: zero chamadas adicionais.
- Schema: sem alterações.
- Performance: helper `collabDayOfMonth` é O(1) e o impacto no DOM é apenas um `<span>` por célula (≤7 por semana).
