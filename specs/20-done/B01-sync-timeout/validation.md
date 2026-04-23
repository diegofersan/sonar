# B01 — Validação

**Concluída em:** 2026-04-22

## Fix aplicado

- `api/sync_time_entries.php`: `max_execution_time` de 120s → 600s.

## Desbloqueio do log zombie

```sql
UPDATE sync_log
SET status='error',
    error_message='Killed by PHP max_execution_time (pre-B01)',
    completed_at=strftime('%s','now')
WHERE id=23;
```

Resultado:

```
23|error|1043s|Killed by PHP max_execution_time (pre-B01)
```

E:

```
SELECT COUNT(*) FROM sync_log WHERE scope='time_entries' AND status='running';
-> 0
```

Não há syncs pendentes a bloquear novas tentativas.

## Validação manual (a fazer pelo utilizador)

1. Navegar em Colaboradores para um mês passado (ex.: 2026-03).
2. Clicar **Sync**.
3. Esperar — pode demorar 2–4 min em meses com mais volume.
4. Verificar que o cartão aparece com dados (not "Ainda sem dados").

Se voltar a dar "demorou muito" com logs a marcar `error: Timeout`,
o limite pode ter de subir além dos 600s — mas aí o problema é
outro (rate limit do ClickUp mais apertado do que estimado).

## Commits

```
c47fa92  B01: spec para timeout do sync em meses com mais volume
2550ebf  B01: max_execution_time do sync 120s → 600s
```

O desbloqueio do log #23 foi um one-off direct SQL (não é um commit).
