# F03 — Validação

**Data:** 2026-04-22
**Branch:** main (commits `40ee323`, `fce7d2c`, `f67486f`, `99f4775`)

## Testes automatizados

| Suite                                        | Asserts | Resultado |
|----------------------------------------------|---------|-----------|
| `tests/test_collaborators_aggregate.php`     | 43      | 43/43 ✅  |
| `tests/test_time_entries_resolver.php` (novo)| 22      | 22/22 ✅  |
| `tests/test_forecast.php`                    | 45      | 45/45 ✅  |

Executados em sequência sem falhas. Comando: `php tests/test_*.php`.

## Lint

```
php -l includes/database.php        → OK
php -l includes/collaborators.php   → OK
php -l includes/clickup.php         → OK
php -l includes/time_entries.php    → OK
php -l api/sync_time_entries.php    → OK
node --check assets/js/app.js       → OK
```

## Schema (pós-migration)

```
sqlite3 data/sonar.db ".schema time_entries"
CREATE TABLE time_entries (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    task_id TEXT,
    workspace_id TEXT NOT NULL,
    start_ms INTEGER NOT NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    synced_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    task_name TEXT, task_url TEXT, parent_task_id TEXT, parent_name TEXT
);
```

As 4 colunas novas existem e estão a NULL nas 171 linhas existentes — o
backfill acontece ao correr um sync novo a seguir ao deploy.

## Estado pré-sync (evidência da dor)

```
SELECT COUNT(*) FROM time_entries;                               → 171
SELECT COUNT(*) FROM time_entries WHERE task_name IS NOT NULL;   →   0
SELECT COUNT(*) FROM time_entries WHERE parent_task_id IS NOT NULL;→ 0
```

## Verificação pós-sync (a fazer pelo utilizador)

Após mergar e correr um sync manual do botão "Sync" na página
Colaboradores, os seguintes queries devem devolver números próximos de
171 (excepto eventuais entries cujo task.name venha vazio na API, raro):

```sql
-- task_name deve estar preenchido em ~100% das linhas
SELECT
  (SELECT COUNT(*) FROM time_entries WHERE task_name IS NOT NULL) AS with_name,
  (SELECT COUNT(*) FROM time_entries) AS total;

-- parent_task_id deve estar preenchido em linhas cuja task se chama
-- Design ou Copy (espectável: maioria dos entries do Diego/Karllos/Ruben/Carolina)
SELECT COUNT(*) FROM time_entries WHERE parent_task_id IS NOT NULL;

-- Sampling: 5 linhas random para verificar que o post_name corresponde
-- visualmente ao post esperado
SELECT task_id, task_name, parent_task_id, parent_name
  FROM time_entries WHERE task_name IS NOT NULL
  ORDER BY RANDOM() LIMIT 5;
```

No popup (UI):
- Cada linha deve mostrar o **título do post** (não "Design" ou "Copy")
- O link deve abrir o post (não a subtask) no ClickUp
- Duas subtasks do mesmo post no mesmo dia devem aparecer como **uma só
  linha** com soma das horas

## Critérios de aceitação

- [x] Migration idempotente aplicada (4 colunas novas, NULL por defeito)
- [x] Sync captura `task_name` e `task_url` — código
      `db_upsert_time_entries` escreve nas 4 colunas
- [x] Sync resolve parent para subtasks Design/Copy — código
      `time_entries_resolve_parents` + integração no endpoint
- [x] Aggregator agrupa por `post_id` e devolve `days_posts`
- [x] Popup frontend mostra `post_name` linkado a `post_url`
- [x] Testes automatizados passam (110 asserts no total)
- [ ] Sync manual executado em produção local — **depende do utilizador**
- [ ] Verificação visual de 5 posts no popup — **depende do utilizador**

## Impacto no rate limit

Pior caso estimado (1 mês, 4 colaboradores, ~50 tasks únicas Design/Copy):
- 50 calls para GET /task/{sub_id}
- ~20 calls para GET /task/{post_id} (parents cached, muitos partilhados)
- Total ~70 calls adicionais por sync, dentro dos 100 RPM da ClickUp

Optimização para próximos syncs (não implementada nesta versão, ver
não-objetivos da spec): saltar tasks já resolvidas com synced_at recente.
Isto é deferido porque o sync não é automático — só quando o user clica.

## Pendências operacionais

Nenhuma. Próximo passo: mergar em main e correr um sync para backfillar
as 171 entries existentes.
