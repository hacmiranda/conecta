# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Conecta CRPRS** - Sistema de gestão de demandas por setor do Conselho Regional de Psicologia do Rio Grande do Sul (CRPRS). PHP/MySQL backend with vanilla JS frontend, no build step required. Originou-se do CRPDESK (helpdesk): cada e-mail recebido na caixa de um setor abre uma demanda naquele setor.

Vocabulário: a entidade de banco continua `tickets` (e `ticket_*`), mas na interface e nos textos é **demanda**. Papéis: `admin` (administrador), `gestor`, `operador`, `cliente`.

## Commands

### Backend
```bash
# Install PHP dependencies
cd api && composer install

# Verify environment configuration
php api/test/test-environment.php

# Run cron jobs manually (CLI needs no key; HTTP needs header X-Cron-Key = CRON_KEY_* from .env)
php api/cron/verificar-emails.php          # also fires due reminders at the end
php api/cron/verificar-lembretes.php      # also sends the e-mail queue at the end
php api/cron/enviar-fila.php             # sends email_fila (retries failures, up to 5 times)
php api/cron/verificar-emails-perdidos.php 30   # reprocess the last N days (max 60)
```

### Database
```bash
# Banco local: db_conecta (credenciais em api/config/.env). MySQL 8.4:
# "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p db_conecta
# Esquema base: database/squema_crpdesk.sql (sem as tabelas de setores)
# Depois aplicar as migrações pendentes (9..N) com o runner — controla o que já rodou na tabela `migracoes`:
php database/migrar.php            # aplica as pendentes; --status lista; --marcar-ate=20 marca sem executar
# Primeiro administrador (senha pedida no terminal):
php database/seed_admin.php "email@crprs.org.br" "Nome Completo"
```

### Tests (api/tests/, P4 of the 2026-10-15 audit)
```bash
cd api && composer test              # PHPUnit unit tests (AcessoService, SenhaService, SLAService)
php api/tests/matriz-de-acesso.php   # access matrix against the real local API; creates/deletes its own zz_ data; refuses APP_ENV=production
```

There is no lint command or frontend build process.

## Architecture

### Request Flow
All API requests go through `api/middleware/auth.php` for JWT verification and role-based access control. Controllers live in `api/controllers/*.php`. The frontend is a single-page application in `frontend/index.html` driven by `frontend/script.js`.

### Authentication
- JWT tokens stored in `localStorage` (`cd_token`, `cd_usuario`, `cd_csrf_token`)
- No expiration: tokens carry no `exp` claim. `AuthMiddleware::verificarAuth()` rereads `tipo`, `setor_id`, `ativo`, `token_versao` and `trocar_senha` from the database on every request, so role/sector changes and deactivation apply immediately
- Session revocation: "Sair" stores the token `jti` in `sessoes_revogadas` (only that session ends); password change/reset and deactivation bump `usuarios.token_versao` (claim `tv`), ending all sessions of the user
- `usuarios.trocar_senha = 1` (new users, passwords reset by admin/gestor): every API except `alterar-senha.php`/`logout.php` answers 403 `trocar_senha` until the user changes it; the frontend shows only "Alterar senha"
- Four roles: `cliente`, `operador`, `gestor`, `admin`. Gestor/operador belong to one sector (`usuarios.setor_id`); admin has none and picks a sector in the header. `cliente` = external requester created from incoming e-mail: never logs in (login, SSO and password recovery refuse it). Only users registered by admin/gestor log in (SSO does not auto-create users)
- Passwords: only hashes are accepted (`SenhaService`: min. 10 chars, upper, lower, digit, special; Argon2id). Login is rate-limited per IP and per account
- Deactivating a user or moving him to another sector returns his open demands to the sector (`AcessoService::devolverDemandasDoResponsavel`)
- Turnstile CAPTCHA on login (`api/config/turnstile.php`, only in production)
- Every API call requires `Authorization: Bearer <token>` and `X-CSRF-Token` headers

### Access rules (sectors)
- `api/services/acesso-service.php` (`AcessoService`) is the single place that decides who can see/operate a demand: admin = all, gestor = demands of own sector, operador = demands where he is the responsible (or opened). Always use it; never filter by role ad hoc
- Three levels: `podeVerTicket` (current participation), `podeOperarTicket` (change it) and `podeLerTicket` (adds history: a gestor whose sector had the demand, an operador who handled it). History readers get `acesso_historico`/`somente_leitura` flags and the UI is read-only
- Trash: any profile that can operate a demand sends it to the trash (`DELETE tickets.php?id=`, `tickets.excluido_em`); only admin sees it (`?escopo=lixeira`), restores (`PUT ?acao=restaurar`) or deletes for good (`DELETE ?definitivo=1`, removes files). Every listing/report/dashboard filters `excluido_em IS NULL`
- Status and priority are validated server-side against the catalog **and** the sector's activation (`CatalogoService`); `tickets.status` is a free VARCHAR
- `AcessoService::setorEscopo($user)`: admin may pass `?setor_id=` (empty = all sectors); everyone else is forced to own sector regardless of the parameter
- Demand flow actions (encaminhar, devolver, repassar, transferir de setor) go through `api/controllers/demanda-acoes.php` and require a comment
- Timeline of a demand is built by `api/services/timeline-service.php` (comments + `ticket_historico`); sector/responsible passages live in `ticket_setor_log` / `ticket_agente_log`

### Backend Structure
- `api/config/` — Environment detection, DB connection (PDO), email config
- `api/controllers/` — One PHP file per resource/feature (~30 endpoints)
- `api/middleware/auth.php` — JWT validation, role checks, CSRF
- `api/services/` — Business logic: access rules, timeline, SLA calculation, email sending, audit logging, crypto of mailbox passwords
- `api/cron/` — Scheduled jobs for email polling and reminders

Time zone: PHP `America/Sao_Paulo` and MySQL session `-03:00` (set in `Database::getConnection()`), so `NOW()` and `date()` agree.

Environment comes from `APP_ENV` in `api/config/.env` (`local` | `production`; missing = production) — never from the Host header. Credentials and keys come only from `api/config/.env` (`*_LOCAL` / `*_PROD`); see `.env.example` (DB, IMAP/SMTP, JWT/CSRF, CORS, SSO, `SETOR_EMAIL_ENCRYPTION_KEY_*`, `INTEGRATION_API_KEY_*`).

### Frontend Structure
Single HTML shell (`frontend/index.html`) with no framework router and no build step. Classic `<script>` tags (no ES modules): every function/variable is global, and `onclick="..."` attributes call them by name. **Load order in `index.html` matters** when two files declare the same function (the last one wins — e.g. `ajuda.js` is loaded before `tutorial.js` on purpose). Every file touched gets its `?v=` bumped.

`script.js` was split by domain in 12 waves (Fase 12/P1, finished 22/10/2026; plans in `docs/superpowers/plans/2026-10-2*-frontend-divisao-script-onda*.md`, spec in `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`). It went from ~10,000 to ~2,200 lines.

| File | Responsibility |
|---|---|
| `script.js` | **Core only**: `sanitizeHTML`/`htmlSeguro`/`argJs`, `getAuthHeaders`/`setSecurityHeaders`, session (`fazerLogout`, `tratarRespostaDeSessao`, forced password change), e-mail failure banner, header avatar dropdown, `mostrarSecao`/`restaurarSecaoAtiva`/`salvarSecaoAtiva`, modal system (`mostrarLoader`/`Toast`/`Alert`/`Confirm`/`Prompt`/`Select`), `aplicarVisibilidadePorPapel`, dark mode, "Sobre", the `DOMContentLoaded` bootstrap, and functions **shared by 2+ domains** (see below) |
| `api.js` | HTTP client `Api.get/post/put/delete(caminho, corpo)`: prefixes `apiBase`, adds auth/CSRF headers, serializes JSON, throws `Error` with `.status` and `.dados` (parsed body) on non-2xx |
| `demandas.js` | Open demands list (server pagination), status pills, search, sorting, Kanban + drag-and-drop, "Nova Demanda" form (FormData upload), merge of open demands |
| `detalhe-demanda.js` | Demand detail screen: header, timeline (comments + history), flow actions (devolver/repassar/transferir), attachments view/download, comment form, "Enviar e Fechar", excluir, voltar |
| `arquivo.js` | Archive (encerradas/canceladas): server pagination, scope filter per role, sorting, reabrir, merge of closed demands |
| `lixeira.js` | Trash (admin): list, restore, delete for good |
| `setores.js`, `usuarios.js`, `categorias.js` | Settings screens (sectors, users, categories/macrocategories) |
| `ativacao-setor.js` | "Ativação por Setor" (status/priorities per sector) + "Configurar Email" (sector mailbox IMAP/SMTP) |
| `configuracoes.js` | Change password + Personalização (logo uploads stay in XHR/FormData) |
| `relatorios.js`, `dashboard.js`, `auditoria.js`, `ajuda.js` | Reports, dashboard screen, audit tabs (+ CSV/PDF export), help guides |
| `graficos-dashboard.js` | Chart.js charts of the dashboard (pre-existing) |
| `cabecalho-setor.js` | Header: sector name (gestor/operador) or sector selector (admin); `urlComSetor()` appends `setor_id` |
| `notificacoes.js` | Notifications, polling `notificacoes.php` every 30 s |
| `macros.js` | Quick response templates (per sector; `setor_id` NULL = all sectors) |
| `alpine-components.js` | Alpine.js + Quill.js WYSIWYG editor components |
| `status-sla.js` | SLA badge rendering (sector hours via `horarios-sla.php?mapa=1`); also `alterarStatusNovo`/`alterarPrioridadeNova` used by the demands table |
| `lembretes.js` | Reminder management |
| `avatar.js` | Avatar crop/upload modal (the header dropdown lives in `script.js`) |
| `tutorial.js` | Onboarding tutorial (overrides `mostrarTutorialAjuda` from `ajuda.js`) |
| `acessibilidade.js` | Cross-cutting a11y: focus-trap/ESC/return-focus for every `#modal-overlay` dialog, keyboard (Tab/Enter/Space) for `onclick` `<div>/<span>/<tr>/<li>` (skips `aria-hidden` ones), `aria-live` on toasts |

**Shared functions that stay in `script.js` on purpose** (called by more than one domain file): `urlComSetor`, `exibirColunaSetor`, `mostrarSkeletonTickets`, `atribuirTicket`, `executarAcaoDemanda`, `pedirComentarioEExecutar`, `obterSetorIdDoTicket`, `alterarStatus`, `alterarCategoria`/`executarAlteracaoCategoria`, `alterarPrioridade`/`executarAlteracaoPrioridade`, `executarAlteracaoStatus`, `executarExclusaoTicket`, constants `MAX_ARQUIVOS`/`MAX_TAMANHO`/`TIPOS_PERMITIDOS`.

**Frontend conventions (new or changed code):**
- Network calls use `Api.*`, never raw `XMLHttpRequest`. Exception: `FormData` uploads (Api only sends JSON) keep `fetch`/XHR with `getAuthHeaders()`/`setSecurityHeaders()`.
- Error message pattern: `mostrarAlert('Erro', (e.dados && e.dados.error) || 'texto padrão')` — only where the server message should reach the user; never `e.message` alone (always filled).
- Old pre-existing dead code is documented, not removed, unless the user approves (`confirmarAtribuicao`/`executarAtribuicao`, `obterIconePrioridade`, `irParaTickets`, `validarAntesFechamento`, `uploadLogoLogin`, `mostrarModoEscuro`, `frontend/dashboard-update.js` is never loaded).

CSS follows a per-feature file convention (`tickets-melhorias.css`, `status-sla.css`, etc.). The main theme color is `#1e3a8a` (blue gradient sidebar). Dark/light mode is CSS-variable-driven in `style.css`.

### Key Data Models
- `tickets` — Core entity (a "demanda"); `descricao` is LONGTEXT; links to `usuarios`, `categorias`, `setores` (`setor_id`) and responsible (`agente_id`)
- `setores` — Sectors; `setor_emails` (one mailbox per sector: IMAP receive + SMTP send, passwords encrypted), `setor_status` / `setor_prioridades` (per-sector activation of the global catalog)
- `categorias` / `macrocategorias` — Owned by a sector (`setor_id`, name unique per sector)
- `horarios_uteis` + `feriados` — Business hours and holidays **per sector** (SLA of a demand uses its sector)
- `comentarios` — Comments; `comentario` is LONGTEXT (supports base64 images from Quill)
- `ticket_historico` — Timeline events (status, prioridade, categoria, atribuicao, transferencia_setor, abertura...); merges are recorded here too (`acao = merge`); the legacy `historico` table was dropped in migration 21
- `ticket_setor_log` / `ticket_agente_log` — Sector/responsible passage windows; closed on encerrar/cancelar
- `auditoria` — General action log with IP, user agent, session
- `notificacoes` — Internal alert queue polled/streamed to frontend
- `macros` — WYSIWYG quick-reply templates, per sector (`setor_id` NULL = visible to all sectors; author, sector gestor or admin manage)
- `email_logs` — Delivery tracking for all outbound emails (records the sender; one row per real SMTP attempt)
- `email_fila` — Outbound e-mail queue: requests only insert; `api/cron/enviar-fila.php` (also run at the end of `verificar-lembretes.php`/`verificar-emails.php`) sends via `EmailFilaService`, with up to 5 attempts and growing wait; on giving up the demand timeline gets an `email_falha` event. Password recovery sends immediately (`enviarEmail(..., true)`)

### Untrusted content (XSS)
- Backend: rich HTML (description, comments, macros, integration) goes through `HtmlSanitizer::rico()` (HTML Purifier, `api/services/html-sanitizer.php`); plain fields (titles, names, reminder text) through `HtmlSanitizer::texto()`. Incoming e-mail bodies are stored escaped (`htmlspecialchars` + `nl2br`)
- Frontend: every API value inserted with `innerHTML` uses `sanitizeHTML()` (text) or `htmlSeguro()` (rich HTML via local `frontend/lib/purify.min.js`); strings inside `onclick` use `argJs()` + `decodeURIComponent`

### E-mail certificates
IMAP and SMTP validate the server certificate by default. It is turned off only explicitly: per mailbox (`setor_emails.validar_certificado`, screen Configurar e-mail) or for the global sender with `IMAP_VALIDAR_CERTIFICADO_*`/`SMTP_VALIDAR_CERTIFICADO_*` = 0 in `.env`.

### Demand list (server pagination)
`GET tickets.php?escopo=abertas|arquivo|...&pagina=N&por_pagina=10|25|50|100&busca=&grupo=novo|em_atendimento|aguardando|nova_resposta|urgente&meus=1&ordem=&direcao=` returns `{itens, total, pagina, por_pagina, total_paginas, contadores}`; without `pagina` it still returns the whole array (Kanban loads everything). Sorting is limited to a column whitelist.

### Incoming e-mail rules
A reply with `#NNNNN` in the subject becomes a comment only if the sender is the requester, someone who got a copy, or the sector team, and the mailbox's sector is one the demand passed through; otherwise a new demand is opened. Mail from the system's own mailboxes and auto-replies is ignored (`emails_processados.tipo = 'ignorado'`). Failures are retried (`emails_erro`) up to 5 times instead of being lost.

### Real-time Notifications
The frontend polls `api/controllers/notificacoes.php` every 30 s (`notificacoes.js`). Rows are inserted directly by `tickets.php`, `comentarios.php`, `demanda-acoes.php` and the reminder service.

### Reminders
`api/services/lembrete-service.php`, fired only by the cron (never by the browser). Reminders of closed/cancelled demands and directed reminders of transferred demands are cancelled (`lembretes.cancelado_em`/`cancelado_motivo`), not sent.

### File Uploads
Attachments stored in `backend/anexos/`. Max 5 files, 20MB each. Allowed: pdf, doc, docx, txt, csv, xls, xlsx, png, jpg, jpeg.

### SLA
`api/services/sla-service.php` computes business seconds using the hours (`horarios_uteis`) and holidays (`feriados`) of the demand's sector (sector without hours falls back to "Geral"). Some statuses (`congela_sla`) pause the SLA timer.

### Database Migrations
`database/9_*.sql` … `22_*.sql` build on the base schema, in order: 9 sectors/roles, 10 sector log, 11 per-sector status/priorities, 12 sector mailboxes, 13 responsible log, 14 roles gestor/operador, 15 initial seed, 16 categories per sector, 17 hours/holidays per sector, 18 SMTP per sector, 19 reminder cancellation + ignored e-mails, 20 sessions and passwords (token_versao, trocar_senha, sessoes_revogadas), 21 integrity (foreign keys, utf8mb4, free `tickets.status`, trash columns, macros per sector, legacy tables dropped), 22 e-mail queue (`email_fila`) + `setor_emails.validar_certificado`. Each runs once (MySQL 8.4 has no `ADD COLUMN IF NOT EXISTS`).

### Pendências conhecidas (atualizado em 22/10/2026)

**Antes do primeiro deploy em produção** (roteiro completo em `docs/implantacao-producao.md`):
- Definir no `.env` do servidor todas as chaves `*_PROD` (`SETOR_EMAIL_ENCRYPTION_KEY_PROD`, `INTEGRATION_API_KEY_PROD`, `CRON_KEY_PROD`, JWT/CSRF etc. — lista e comandos de geração em `.env.example`).
- Confirmar `db_host`/`db_user` de produção (hoje herdados do CRPDESK). Banco `db_conecta`, `base_url` `https://crp07.org.br/conecta` já definidos.
- Aplicar as migrações 9–22 com backup (`php database/migrar.php`), ou confirmar que a réplica do banco local já vem com elas.
- Checklist pós-deploy do roteiro: login do primeiro admin, `migrar.php --status`, matriz de acesso, teste real de e-mail por setor, cron de e-mail (externo, cron-job.org, via HTTP com chave), HTTPS + headers de segurança, backup automático.
- Avisar os mantenedores dos sistemas satélites da nova URL (`docs/especificacoes/PROMPT_INTEGRACAO_SISTEMA_SATELITE.md`; `docs/CONFIGURACAO_AMBIENTE.md` também cita a URL antiga).

**Melhorias deixadas de fora de propósito** (só fazer se o usuário pedir):
- Gráficos do Dashboard esquecidos em `script.js` (`descreverGraficoCanvas`, `getCorLabel`, `criarGraficoStatus/Prioridade/Mensal`) — deveriam ir para `dashboard.js`/`graficos-dashboard.js`.
- Tabela de Demandas usa `alterarStatusNovo` (status-sla.js) mas `alterarPrioridade` antiga (script.js) — inconsistência herdada.
- XSS herdado: `usuario_afetado` sem `sanitizeHTML()` na Auditoria; sanitização no servidor de `macrocategorias.php` (`HtmlSanitizer::texto()`).
- Estilos inline → variáveis CSS; remoção de `onclick` inline (CSP sem `unsafe-inline`).
- Aba "Solicitantes" separada de "Equipe"; estado "enviando" no comentário; resposta × nota interna explícita.
- Logger único (cron de e-mail ainda usa `echo`); padronizar formato de resposta da API (P8); SLA só no backend (P10).
- Upgrade do `firebase/php-jwt` (alerta baixo, CVE-2025-45769).

### Manutenção deste arquivo
Após qualquer alteração relevante (nova tela/arquivo JS, migração, endpoint, regra de acesso, decisão de arquitetura, item de pendência resolvido ou criado), atualizar este `CLAUDE.md` no mesmo trabalho e mencionar no commit.

---

## Regras do Projeto (.agent/rules)

### Idioma (IDIOMA_PTBR.md)
- **Todo o projeto usa Português do Brasil (pt-BR)** — respostas, comentários de código, commits e documentação.
- Comentários PHP/JS **obrigatoriamente em português**. Ex: `// Verifica se o usuário existe`.
- Nomes de variáveis/funções: seguir o padrão já existente no arquivo. Código novo independente prefere português.
- Mensagens de commit em português. Ex: `fix: correção no redirecionamento de login`.
- Termos técnicos consagrados (bug, deploy, loop, fetch) podem ser mantidos em inglês quando a tradução prejudica a clareza.

### Backend PHP (BACKEND_PHP.md)
- Todas as respostas da API devem ser JSON com `header('Content-Type: application/json')`.
- Usar códigos HTTP corretos: 200, 201, 400, 401, 403, 404, 500.
- Toda lógica principal deve estar dentro de `try-catch`. Logar com `error_log("[CONTEXTO] Erro: " . $e->getMessage())`.
- Conexão DB sempre via `new Database()` + `$db->getConnection()`. Services recebem a conexão no construtor.
- **Auditoria obrigatória** em toda operação de escrita (INSERT/UPDATE/DELETE) em tabelas críticas via `AuditoriaService->registrar()`.
- Tentativas de login (sucesso/falha) registradas via `AuditoriaService->registrarLogin()`.
- Nunca usar `var_dump` ou `print_r` em produção; usar `error_log()` com prefixos claros (ex: `[TICKETS POST]`).
- Configurações sensíveis via `Environment::get('key')`.

### Frontend Moderno (FRONTEND_MODERNO.md)
- **Tipografia obrigatória: Inter** (Google Fonts).
- Usar variáveis CSS definidas em `modern-enhancements.css`: `--brand-500`, `--text-secondary`, `--bg-card`, `--shadow-sm`, `--shadow-md`, `--radius-sm`, `--radius-md`, `--transition-fast`.
- Compatibilidade obrigatória com **modo escuro**.
- Interatividade: ripple effect, hover states, transições suaves.
- **Proibido**: Arial/Times/Comic Sans; roxo/violeta; elementos desalinhados; animações pesadas.
- Cards: `--shadow-sm`/`--shadow-md`, `--radius-lg`. Modais: backdrop blur + animação scale/fade-in.
- **Vanilla JS puro e modular**. Não adicionar React/Angular/Vue ao core existente.
- Sempre tratar erros de fetch com notificações visuais (Toasts/Alerts).

### Segurança (SEGURANCA_SPEC.md)
- **Sempre** usar Prepared Statements PDO. **Nunca** concatenar strings em queries SQL.
- Validar tipo de arquivo por conteúdo (finfo/MIME), não apenas extensão. Usar `basename()` em caminhos.
- Senhas sempre com `password_hash($senha, PASSWORD_ARGON2ID)` e `password_verify()`.
- Headers de segurança obrigatórios: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `X-XSS-Protection`, `Referrer-Policy`, `Content-Security-Policy`.
- Sessões PHP com `secure`, `httponly`, `samesite: Strict`. Regenerar ID após login.
- Rate limiting em login. CSRF token em formulários (`bin2hex(random_bytes(32))`).
- Permissões de arquivo: PHP `644`, diretórios `755`, config `600`. **Nunca** `777` em produção.
- Nunca expor `.env`, `.git`, `composer.json` publicamente.
- **Nunca logar**: senhas, tokens de sessão, dados pessoais sensíveis.

### Acessibilidade WCAG 2.2 (ACESSIBILIDADE_SPEC.md)
- HTML semântico obrigatório: `<header>`, `<nav>`, `<main>`, `<article>`, `<section>`, `<footer>`. Nunca `<div class="header">`.
- Contraste mínimo: texto normal 4.5:1, texto grande 3:1, componentes UI 3:1.
- Formulários: `<label for>` associado a todo `<input>`. Mensagens de erro com `role="alert"` e `aria-live="polite"`.
- Foco visível obrigatório: `outline: 2px solid` em `button:focus`, `a:focus`, `input:focus`. **Nunca** remover outline sem substituto.
- Imagens com `alt` descritivo. Ícones decorativos: `aria-hidden="true"` + `.sr-only` para leitores de tela.
- Modais com `role="dialog"`, `aria-modal="true"`, `aria-labelledby`. Gerenciar foco ao abrir/fechar. Fechar com ESC.
- Tabelas com `<caption>`, `<thead>`, `<th scope="col">`.
- Mensagens de status: `role="status" aria-live="polite"`. Alertas urgentes: `role="alert" aria-live="assertive"`.
- Animações respeitam `@media (prefers-reduced-motion: reduce)`.
- `<html lang="pt-BR">` obrigatório.

### Acesso ao Banco de Dados Local (DB_ACCESS_LOCAL.md)
- Executável MySQL: `C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe` (o MySQL do XAMPP não é o usado).
- Conexão: host `localhost`, database `db_conecta`; usuário e senha em `api/config/.env` (`DB_*_LOCAL`).
- Comando padrão: `"C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p -e "USE db_conecta; SUA_QUERY_AQUI;"`
- Os dumps em `database/` são **estáticos e podem estar desatualizados**. Usar o MySQL em execução como fonte da verdade.
- Dump atualizado: `"C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe" -u root -p db_conecta > db_conecta_atual.sql` (fora do repositório).
- UPDATE/DELETE com **EXTREMA CAUTELA**: sempre com `WHERE id = ?`. Scripts temporários de manutenção devem ser removidos após uso.
