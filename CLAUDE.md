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
php api/cron/verificar-lembretes.php
php api/cron/verificar-emails-perdidos.php 30   # reprocess the last N days (max 60)
```

### Database
```bash
# Banco local: db_conecta (credenciais em api/config/.env). MySQL 8.4:
# "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p db_conecta
# Esquema base: database/squema_crpdesk.sql (sem as tabelas de setores)
# Depois aplicar, em ordem e uma única vez cada: database/9_*.sql até database/19_*.sql
# Primeiro administrador (senha pedida no terminal):
php database/seed_admin.php "email@crprs.org.br" "Nome Completo"
```

There is no lint command, test suite, or frontend build process.

## Architecture

### Request Flow
All API requests go through `api/middleware/auth.php` for JWT verification and role-based access control. Controllers live in `api/controllers/*.php`. The frontend is a single-page application in `frontend/index.html` driven by `frontend/script.js`.

### Authentication
- JWT tokens stored in `localStorage` (`cd_token`, `cd_usuario`, `cd_csrf_token`)
- No expiration: tokens carry no `exp` claim. `AuthMiddleware::verificarAuth()` rereads `tipo`, `setor_id` and `ativo` from the database on every request, so role/sector changes and deactivation apply immediately
- Four roles: `cliente`, `operador`, `gestor`, `admin`. Gestor/operador belong to one sector (`usuarios.setor_id`); admin has none and picks a sector in the header
- Turnstile CAPTCHA on login (`api/config/turnstile.php`, only in production)
- Every API call requires `Authorization: Bearer <token>` and `X-CSRF-Token` headers

### Access rules (sectors)
- `api/services/acesso-service.php` (`AcessoService`) is the single place that decides who can see/operate a demand: admin = all, gestor = demands of own sector, operador = demands where he is the responsible (or opened). Always use it; never filter by role ad hoc
- `AcessoService::setorEscopo($user)`: admin may pass `?setor_id=` (empty = all sectors); everyone else is forced to own sector regardless of the parameter
- Demand flow actions (encaminhar, devolver, repassar, transferir de setor) go through `api/controllers/demanda-acoes.php` and require a comment
- Timeline of a demand is built by `api/services/timeline-service.php` (comments + `ticket_historico`); sector/responsible passages live in `ticket_setor_log` / `ticket_agente_log`

### Backend Structure
- `api/config/` — Environment detection, DB connection (PDO), email config
- `api/controllers/` — One PHP file per resource/feature (~30 endpoints)
- `api/middleware/auth.php` — JWT validation, role checks, CSRF
- `api/services/` — Business logic: access rules, timeline, SLA calculation, email sending, audit logging, crypto of mailbox passwords
- `api/cron/` — Scheduled jobs for email polling and reminders

Environment comes from `APP_ENV` in `api/config/.env` (`local` | `production`; missing = production) — never from the Host header. Credentials and keys come only from `api/config/.env` (`*_LOCAL` / `*_PROD`); see `.env.example` (DB, IMAP/SMTP, JWT/CSRF, CORS, SSO, `SETOR_EMAIL_ENCRYPTION_KEY_*`, `INTEGRATION_API_KEY_*`).

### Frontend Structure
Single HTML shell (`frontend/index.html`) with no framework router. JavaScript is split by feature module:

| File | Responsibility |
|---|---|
| `script.js` | Core app: demands CRUD, pagination, modals, session management, settings screens |
| `cabecalho-setor.js` | Header: sector name (gestor/operador) or sector selector (admin); sector context sent to the API |
| `graficos-dashboard.js` | Chart.js analytics/dashboard |
| `notificacoes.js` | Real-time notifications via SSE (`EventSource`) |
| `macros.js` | Quick response templates |
| `alpine-components.js` | Alpine.js + Quill.js WYSIWYG editor components |
| `status-sla.js` | SLA badge rendering (uses each demand's sector hours via `horarios-sla.php?mapa=1`) |
| `lembretes.js` | Reminder management |
| `avatar.js` | User profile dropdown |

CSS follows a per-feature file convention (`tickets-melhorias.css`, `status-sla.css`, etc.). The main theme color is `#1e3a8a` (blue gradient sidebar). Dark/light mode is CSS-variable-driven in `style.css`.

### Key Data Models
- `tickets` — Core entity (a "demanda"); `descricao` is LONGTEXT; links to `usuarios`, `categorias`, `setores` (`setor_id`) and responsible (`agente_id`)
- `setores` — Sectors; `setor_emails` (one mailbox per sector: IMAP receive + SMTP send, passwords encrypted), `setor_status` / `setor_prioridades` (per-sector activation of the global catalog)
- `categorias` / `macrocategorias` — Owned by a sector (`setor_id`, name unique per sector)
- `horarios_uteis` + `feriados` — Business hours and holidays **per sector** (SLA of a demand uses its sector)
- `comentarios` — Comments; `comentario` is LONGTEXT (supports base64 images from Quill)
- `ticket_historico` — Timeline events (status, prioridade, categoria, atribuicao, transferencia_setor, abertura...); `historico` is legacy (merge only)
- `ticket_setor_log` / `ticket_agente_log` — Sector/responsible passage windows; closed on encerrar/cancelar
- `auditoria` — General action log with IP, user agent, session
- `notificacoes` — Internal alert queue polled/streamed to frontend
- `macros` — WYSIWYG quick-reply templates
- `email_logs` — Delivery tracking for all outbound emails (records the sender)

### Untrusted content (XSS)
- Backend: rich HTML (description, comments, macros, integration) goes through `HtmlSanitizer::rico()` (HTML Purifier, `api/services/html-sanitizer.php`); plain fields (titles, names, reminder text) through `HtmlSanitizer::texto()`. Incoming e-mail bodies are stored escaped (`htmlspecialchars` + `nl2br`)
- Frontend: every API value inserted with `innerHTML` uses `sanitizeHTML()` (text) or `htmlSeguro()` (rich HTML via local `frontend/lib/purify.min.js`); strings inside `onclick` use `argJs()` + `decodeURIComponent`

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
`database/9_*.sql` … `19_*.sql` build on the base schema, in order: 9 sectors/roles, 10 sector log, 11 per-sector status/priorities, 12 sector mailboxes, 13 responsible log, 14 roles gestor/operador, 15 initial seed, 16 categories per sector, 17 hours/holidays per sector, 18 SMTP per sector, 19 reminder cancellation + ignored e-mails. Each runs once (MySQL 8.4 has no `ADD COLUMN IF NOT EXISTS`).

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
