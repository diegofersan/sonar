# CLAUDE.md

Contexto operacional para sessões do Claude Code neste projeto.

## O que este projeto é

Sonar é um dashboard editorial em PHP puro que consome a API do ClickUp via OAuth e usa um SQLite local como cache. Mostra ao utilizador as suas tarefas (Copy/Design) ordenadas por urgência, com breadcrumbs até à Linha Editorial, e um relatório admin de posts em atraso. Já está em produção (deploy por FTP via GitHub Actions).

## Stack

- **PHP puro** (sem Composer), PDO + SQLite (WAL, `foreign_keys=ON`), `curl` com fallback stream.
- **Frontend** vanilla: um `assets/js/app.js` (IIFE) e um `assets/css/style.css`.
- **Servidor local**: `php -S localhost:8080` a partir da raiz.
- **Storage**: `data/sonar.db` (gitignored).
- **Deploy**: `.github/workflows/deploy.yml` — FTP na push para `main`.

## Como trabalhamos aqui: Spec-Driven Development

Toda melhoria (feature, refactor, bug) começa por uma **spec** em `specs/10-active/`.
Nunca implementar código sem spec aprovada pelo utilizador.

### Fluxo por item

1. Utilizador pede melhoria → criar spec em `specs/10-active/[TIPO][NN]-[slug]/spec.md` a partir do template correspondente em `specs/_templates/`
2. Aguardar aprovação do utilizador na spec (status passa de `draft` → `approved`)
3. Criar `tasks.md` ao lado da spec com decomposição executável
4. Implementar tarefa a tarefa, marcando cada uma como concluída
5. Criar `validation.md` com resultado dos testes/checks
6. Ao terminar, mover pasta inteira para `specs/20-done/`

### Numeração

- Features: F01, F02, F03...
- Refactors: R01, R02...
- Bugs: B01, B02...

Sequencial por tipo, nunca reutilizar números.

## Regras para este projeto

- **Produção existe.** Nunca alterar schema do SQLite sem migration explícita na spec.
- **ClickUp API tem rate limits.** Qualquer mudança que aumente chamadas precisa ser avaliada na spec (secção Impacto).
- **Não inventar contexto.** Se algo não está claro nas specs ou no código, perguntar antes de assumir.
- **Testes antes de refactor.** Nenhum refactor começa sem a rede de segurança da spec estar no lugar.
- **Testes = scripts PHP puros** em `tests/test_*.php`, sem Composer/PHPUnit (ver `specs/00-context/decisions.md` D7).
- **Commits por tarefa.** Um commit por tarefa do `tasks.md`, mensagem `{TIPO}{NN}: descrição` (ex: `F03: adiciona endpoint de sync`). Sem co-author automático.

## Contexto obrigatório a ler antes de trabalhar

- `specs/00-context/system-overview.md`
- `specs/00-context/data-model.md`
- `specs/00-context/clickup-integration.md` (se a tarefa tocar no ClickUp)
- `specs/00-context/known-issues.md` (para qualquer refactor)
- `specs/00-context/decisions.md` (decisões de projeto já tomadas)

## O que NÃO fazer

- Não criar specs em `20-done/` ou `90-archive/` — essas pastas são só para specs concluídas ou arquivadas.
- Não editar ficheiros em `00-context/` sem pedir — são a memória partilhada do projeto.
- Não começar implementação sem spec aprovada.
- Não misturar mais de um tipo (feature + refactor) na mesma spec — separar.
