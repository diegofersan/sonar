# Decisões de Projeto

Decisões tomadas no bootstrap do spec-driven development (2026-04-22) para as
perguntas que ficaram em aberto no inventário. Todas assumidas como *default*
— qualquer uma pode ser revertida numa spec futura.

---

## D1 · `api/sync_worker.php` é legacy inerte

**Decisão:** tratar como órfão confirmado.

- `grep sync_worker.php` só encontra o próprio ficheiro → nenhum caminho no repo invoca-o.
- `api/sync.php` faz tudo inline via `fastcgi_finish_request()` + `session_write_close()`.
- Se algum cron externo (fora do repo) o chamasse, teríamos evidência em produção — assumimos que não.

**Consequência:** primeira R de housekeeping remove `api/sync_worker.php` junto com outras
pontas soltas (ver D2). Nada a fazer agora — só fica registado como candidato.

---

## D2 · `test_copy.php` no `deploy.yml` é resíduo

**Decisão:** o ficheiro nunca existirá. A linha de exclude em
`.github/workflows/deploy.yml:29` é limpa na mesma R de housekeeping que apaga
o `sync_worker.php`.

---

## D3 · URL de produção — fica vago

**Decisão:** `specs/00-context/system-overview.md` descreve o deploy como
"FTP via GitHub Actions, secrets no GitHub" sem referir host/domínio.

- Não há memória fresca do URL.
- Os secrets (`FTP_SERVER`, `FTP_PATH`) são a fonte de verdade.
- Se precisarmos referenciar o URL numa spec, adicionamos na altura.

---

## D4 · Numeração F/R/B sequencial global por tipo

**Decisão:** conforme `CLAUDE.md` — `F01, F02, ...`, `R01, R02, ...`, `B01, B02, ...`.
Sem sub-namespaces. Números nunca reutilizados, mesmo que a spec seja arquivada.

---

## D5 · Formato de commit: `{TIPO}{NN}: descrição`

**Decisão:**

```
F03: adiciona endpoint de sync incremental
R01: extrai cliente HTTP do ClickUp para módulo próprio
B02: corrige paginação de sync para não truncar em 20 páginas
```

- Um commit por tarefa do `tasks.md`.
- Sem co-author automático (o utilizador é o autor, o assistente só ajuda).
- Sem Conventional Commits (`feat:`, `fix:`) — o prefixo da spec já identifica o tipo.

---

## D6 · `config_lists` fica dormente, não é removida

**Decisão:** não fazer nada à tabela.

- Hoje é escrita mas nunca lida (`db_save_list_config` vs `db_get_enabled_lists` não invocada).
- Remover requer migration destrutiva em SQLite de produção — risco > benefício cosmético.
- Se / quando aparecer uma spec de multi-list, a tabela está pronta.
- Entretanto: `data-model.md` e `known-issues.md` (KI-16) documentam o estado "meia-feature".

---

## D7 · Rede de segurança = scripts PHP puros, sem Composer

**Decisão:** testes automatizados vivem em `tests/` como scripts PHP
executáveis directamente (`php tests/test_foo.php`). Sem PHPUnit, sem Composer.

**Porquê:**

- Todo o projeto é pure PHP sem dependências — introduzir Composer só para testes
  muda a barreira de entrada e o processo de deploy (FTP teria de excluir `vendor/`,
  runtime teria de ter `vendor/autoload.php`, etc.).
- A superfície a testar é pequena (queries SQLite, helpers de enriquecimento,
  parsing de payloads ClickUp). Um `assert()` + exit code chega.

**Convenção:**

- Ficheiros em `tests/test_<area>.php`.
- Cada ficheiro corre standalone: `php tests/test_xxx.php` → exit 0 = ok, exit 1 = fail.
- SQLite de testes num temp file (não tocar em `data/sonar.db`).
- Helper `tests/bootstrap.php` opcional se os asserts começarem a repetir-se.
- Um teste de "smoke" mínimo (connect + migrate + upsert + query) para desbloquear a
  primeira R.

Revisitar esta decisão se / quando o número de testes ou a necessidade de mocks
justificar PHPUnit.
