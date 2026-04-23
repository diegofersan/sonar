# B01 — Sync de time entries morre aos 120s em meses com mais volume

**Status:** done
**Tipo:** bug
**Severidade:** alta
**Criada em:** 2026-04-22

## Reprodução

1. Em Colaboradores, navegar para um mês passado com bastante actividade
   (ex.: 139+ subtasks Design/Copy a resolver parent).
2. Clicar em **Sync**.
3. Esperar.

## Comportamento Observado

- UI faz poll durante 4 min e mostra `Sync demorou demasiado. Verifica mais tarde.`
- `sync_log` fica com uma linha em `status='running'` indefinidamente
  (até 300s depois da próxima tentativa, altura em que o cleanup stale
  a marca como `error: Timeout`).
- Nada é gravado no cache — dados do mês continuam em falta.
- Entre o kill e o stale-sweep (~3 min), qualquer novo sync devolve
  429 `A time-entries sync is already running`.

Evidência no DB (sync #23 do utilizador):

```
status=running, started_at=1776901802, completed_at=NULL,
progress='A resolver posts... (183/139)'
```

613s depois do start e ainda "running" — o processo já tinha morrido há
muito.

## Comportamento Esperado

O sync do mês completa-se (pode demorar minutos) e o cartão aparece
com os dados.

## Causa Raiz

`api/sync_time_entries.php:15` define
`ini_set('max_execution_time', '120')`. O PHP mata o script aos 120s.

Para 139 subtasks Design/Copy, o resolver F03 faz até ~278 chamadas ao
ClickUp (uma por subtask + uma por parent único). Ao rate limit típico
do ClickUp (~100 req/min para user tokens), só as chamadas demoram
~2–3 min, antes de qualquer processamento local.

Conclusão: o timeout de 120s foi dimensionado para sync de "mês actual
quente" (parents já resolvidos em corridas anteriores) e não cobre
meses novos/maiores.

## Fix Proposto

1. **`api/sync_time_entries.php:15`**:
   `ini_set('max_execution_time', '120')` → `'600'` (10 min).
   Margem confortável sem remover o limite por completo (previne loops
   infinitos).
2. **Limpar manualmente** a linha `sync_log` #23 que ficou zombie, para
   desbloquear o próximo sync sem esperar o stale-sweep.

Deliberadamente **não** vou:
- Chunkar o sync por semanas (complicação sem valor para este volume).
- Reescrever o contador de progresso (cosmético; fica para outra spec
  se houver apetite).
- Mudar a mensagem "demorou demasiado" (a spec F05 do gráfico pode
  tocar nesta UX).

## Teste de Regressão

Não há teste automatizado — o bug é infra (timeout config). Validação
manual: sync do mês `2026-03` (o que falhou) completa com sucesso e
popula o cartão com dados.

Contramedida adicional: adicionar uma nota em
`specs/00-context/known-issues.md` para referenciar o limite escolhido
e em que cenário seria revisto.

## Impacto

- **Schema:** nada.
- **API ClickUp:** sem chamadas novas.
- **Produção:** o valor `max_execution_time` é aplicado por
  `ini_set` no runtime do script, não requer alteração a `php.ini` no
  servidor. Deploy via FTP cobre.
- **Riscos:** um sync que bloqueie indefinidamente fica vivo até 600s
  em vez de 120s. O stale cleanup continua em 300s, por isso meia
  janela com 'running' é possível — mas o utilizador verá o sync a
  correr na mesma nos próximos polls.
