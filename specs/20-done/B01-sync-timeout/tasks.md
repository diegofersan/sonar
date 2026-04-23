# B01 — Tasks

## 1. Aumentar max_execution_time do sync

- [ ] `api/sync_time_entries.php:15`: `'120'` → `'600'`.
- [ ] `php -l api/sync_time_entries.php`.

## 2. Desbloquear o log zombie #23

- [ ] `UPDATE sync_log SET status='error', error_message='Killed by PHP max_execution_time (pre-B01)', completed_at=strftime('%s','now') WHERE id=23;`
- [ ] Confirmar que `SELECT * FROM sync_log WHERE scope='time_entries' AND status='running'` está vazio.

## 3. Fechar

- [ ] `validation.md`: nota com o `sqlite3` do sync de validação.
- [ ] `git mv specs/10-active/B01-sync-timeout specs/20-done/`.
