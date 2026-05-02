# F06 — Dia do mês nos quadrados da grelha de colaboradores

**Status:** done
**Tipo:** feature
**Criada em:** 2026-05-02

## Problema
Na vista de Colaboradores, a grelha semanal mostra horas trabalhadas por dia da semana (SEG..DOM), mas não dá pista visual do **dia do mês** correspondente. Ao olhar para a tabela é difícil saber, por exemplo, em que dia caiu a SEG da W18 — obriga a abrir o popup do dia (que já tem essa info) ou a contar offsets a partir de `week_start`.

## Proposta
Em cada célula de dia da semana (`.collab-day`), mostrar o número do dia do mês (1..31) num canto, em tipo muito pequeno (≈9px), discreto, no canto inferior direito. Aplica-se a todas as células com data, mesmo as que não têm horas (`—`), para que a leitura de calendário seja contínua.

## User Story
Como utilizador a olhar para a grelha mensal de um colaborador, quero ver o dia do mês em cada célula de dia da semana, para localizar rapidamente uma data sem ter de abrir o popup ou cruzar mentalmente com `W18 = semana de X`.

## Critérios de Aceitação
- [ ] Em cada célula `.collab-day` (linhas de dados, não cabeçalho) aparece o número do dia do mês no canto inferior direito.
- [ ] O tamanho é ≈9px e a cor mais discreta que o conteúdo principal (texto secundário/muted), para não competir com as horas.
- [ ] Funciona tanto em células sem horas (`<div>`) como em células clicáveis (`<button>`).
- [ ] O número é derivado a partir de `w.week_start` + offset do `DAY_KEYS` (mon=0..sun=6), sem chamadas extra à API.
- [ ] As células do cabeçalho (SEG, TER, ...) não recebem o número.
- [ ] Não quebra layout em mobile (`@media` existente em `.collab-week-row`).

## Impacto
- **Schema:** sem alterações.
- **API ClickUp:** sem alterações.
- **Rotas/Endpoints:** sem alterações.
- **Dependências:** nenhuma.
- **Ficheiros tocados:** `assets/js/app.js` (render da célula em ~linha 1443-1452) e `assets/css/style.css` (regras de `.collab-day` em ~linha 1633).

## Não-objetivos
- Não mostrar o mês ou ano nas células — só o dia.
- Não destacar visualmente o dia de hoje (pode ser uma F futura).
- Não alterar o popup do dia (já tem `dd/mm`).

## Referências
- F01 (estrutura inicial da vista de colaboradores), F04 (navegação por mês), F05 (burn-up).
- Render atual: `assets/js/app.js:1443-1452`.
