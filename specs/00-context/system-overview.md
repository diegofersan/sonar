# System Overview — Sonar

## Propósito

Sonar é um dashboard para operação editorial/conteúdo que vive sobre a API do ClickUp. Os colaboradores fazem login com OAuth do ClickUp, escolhem o workspace e veem as suas tarefas (Copy/Design) ordenadas por urgência, com breadcrumbs até à Linha Editorial e estado dos subtasks irmãos. Um utilizador admin (Diego Ferreira) vê ainda um relatório de posts em atraso ao nível do workspace inteiro.

O ClickUp é o sistema de registo — o SQLite local é apenas uma **cache** para evitar chamadas repetidas à API e para computar enriquecimentos (urgency score, breadcrumbs, deteção de rejeição de aprovação).

## Stack real

- **PHP puro** sem Composer. Usa PDO + SQLite, `curl` (com fallback para `file_get_contents + stream_context_create`) e `strict_types=1` em `includes/database.php`.
- **Frontend**: HTML renderizado em PHP + um único `assets/js/app.js` vanilla (IIFE) + um único `assets/css/style.css`. Sem bundler, sem dependências JS.
- **Servidor built-in**: `php -S localhost:8080` a partir da raiz do projeto.
- **Storage**: `data/sonar.db` (SQLite, WAL, `PRAGMA foreign_keys = ON`). Diretório `data/` e `config.local.php` estão no `.gitignore`.
- **Deploy**: GitHub Actions (`.github/workflows/deploy.yml`) via FTP na push para `main`. Secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_PATH`. Exclui `.git*`, `.github/**`, `config.local.php`, `test_copy.php`, `data/sonar.db`.

## Arquitetura em alto nível

```
Browser
  │
  ├── /              → index.php (router mínimo)
  ├── /login.php     → botão "Login with ClickUp"
  ├── /workspace.php → seletor (quando há >1 workspace)
  ├── /dashboard.php → UI principal (tabs Fila/Aprovação/Futuro/Canceladas/Calendário [+Relatório p/ admin])
  │
  ├── /oauth/authorize.php  → redirect p/ ClickUp (state CSRF)
  ├── /oauth/callback.php   → troca code→token, fetch user+teams
  │
  └── /api/*.php            → JSON endpoints (CSRF via X-CSRF-Token)
        ├── workspaces.php       GET   lista teams
        ├── select-workspace.php POST  guarda workspace na sessão
        ├── sync.php             POST inicia sync; GET polling de progresso
        ├── sync_worker.php      CLI (legacy, provavelmente inerte — sync.php faz inline)
        ├── tasks.php            GET tarefas do utilizador enriquecidas
        ├── report.php           GET relatório admin (overdue cross-user)
        ├── watch.php            POST watch/unwatch
        ├── notifications.php    GET lista/count; POST mark_read/mark_all_read
        └── logout.php           POST destroi sessão
              │
              ▼
        includes/
          ├── session.php   PHP sessions + CSRF token + timeouts
          ├── security.php  X-Frame, CSP, X-Content-Type
          ├── clickup.php   wrapper HTTP (curl/stream) + helpers API
          └── database.php  PDO singleton, migrations, queries

ClickUp API  ←────  includes/clickup.php (Bearer <access_token>)
SQLite       ←────  includes/database.php (data/sonar.db)
```

## Pontos de entrada

**HTTP (PHP built-in ou Apache/nginx)**
- Páginas: `/`, `/login.php`, `/workspace.php`, `/dashboard.php`
- OAuth: `/oauth/authorize.php`, `/oauth/callback.php`
- API: `/api/{workspaces,select-workspace,sync,tasks,report,watch,notifications,logout}.php`

**CLI**
- `api/sync_worker.php` — script CLI que replica a lógica de `api/sync.php` (ver `known-issues.md`).

**Cron / webhooks**: nenhum configurado hoje. A sincronização é **manual** (utilizador clica "Sync").

## Correr localmente

```sh
# 1. Criar config.local.php a partir do exemplo e preencher credenciais OAuth
cp config.local.php.example config.local.php
# editar client_id, client_secret, redirect_uri

# 2. Arrancar PHP built-in server a partir da raiz
php -S localhost:8080

# 3. Abrir http://localhost:8080
```

A redirect URI registada no app OAuth do ClickUp tem de coincidir com `CLICKUP_REDIRECT_URI` (default `http://localhost:8080/oauth/callback.php`).

O ficheiro SQLite é criado no primeiro acesso (`data/sonar.db`, modo 0700).

## Produção e deploy

- Deploy por FTP via GitHub Actions no push para `main`.
- Host FTP, credenciais e path vivem em secrets do GitHub (`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_PATH`) — não estão no repo.
- Rollback: não há mecanismo automatizado; reverter requer push de um commit anterior (novo deploy FTP).
- Não há ambiente de staging visível no repo.
