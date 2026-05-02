# F06 — Tasks

## T1 — JS: helper para dia do mês a partir de week_start + dayKey
- [x] Em `assets/js/app.js`, junto ao bloco que renderiza a linha da semana (~linha 1439), introduzir uma pequena função `collabDayOfMonth(weekStart, dayKey)` que devolve o número do dia (1..31) ou `null` se não conseguir parsear.
- Reutiliza a mesma lógica já presente em `openCollabDayModal` (`new Date(weekStart + 'T00:00:00')` + `setDate(getDate() + offset)`).

## T2 — JS: render do número em cada `.collab-day` com data
- [x] No loop `DAY_KEYS.forEach` (~linha 1443-1452), envolver o conteúdo de cada célula com `<span class="collab-day-hours">…</span>` e adicionar `<span class="collab-day-num">{dia}</span>`.
- [x] Aplicar tanto ao ramo `<button class="collab-day has-val">` como ao `<div class="collab-day">`.
- [x] Cabeçalho (`headRow`, ~linha 1431-1436) **não** muda.

## T3 — CSS: posicionar o número no canto inferior direito, ≈9px, muted
- [x] Em `assets/css/style.css`, adicionar `position: relative` a `.collab-day` (~linha 1633) para servir de âncora.
- [x] Criar `.collab-day-num` com `position: absolute; bottom: 2px; right: 4px; font-size: 9px; line-height: 1; color: var(--color-text-muted, var(--color-text-secondary)); opacity: 0.6; pointer-events: none;`.
- [x] Garantir que `.collab-day-hours` mantém o alinhamento central existente (o flex no `.collab-day` continua a tratar disso).

## T4 — Verificação manual
- [x] `php -S localhost:8080` e abrir a vista de Colaboradores.
- [x] Confirmar números 1..31 nas células certas (cruzar com o popup do dia que mostra `dd/mm`).
- [x] Confirmar que cabeçalhos SEG/TER/... não têm número.
- [x] Confirmar que mobile (largura ≤ ~720px) não quebra.

## T5 — validation.md + commit + mover para 20-done
- [x] Escrever `validation.md` com o que foi testado.
- [x] Commits por tarefa, prefixo `F06:`.
- [x] Atualizar status da spec para `done` e mover pasta para `specs/20-done/F06-collab-day-number/`.
