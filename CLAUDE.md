# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**CRPDESK** - Sistema de Helpdesk do Conselho Regional de Psicologia do Rio Grande do Sul (CRPRS). PHP/MySQL backend with vanilla JS frontend, no build step required.

## Commands

### Backend
```bash
# Install PHP dependencies
cd api && composer install

# Verify environment configuration
php api/test/test-environment.php

# Run cron jobs manually
php api/cron/verificar-emails.php
php api/cron/verificar-lembretes.php
php api/cron/verificar-emails-perdidos.php
```

### Database
```bash
# Apply migrations in order (run via MySQL client or phpMyAdmin)
# Files: database/1_*.sql through database/8_*.sql
```

There is no lint command, test suite, or frontend build process.

## Architecture

### Request Flow
All API requests go through `api/middleware/auth.php` for JWT verification and role-based access control. Controllers live in `api/controllers/*.php`. The frontend is a single-page application in `frontend/index.html` driven by `frontend/script.js`.

### Authentication
- JWT tokens stored in `localStorage` (`token`, `usuario`, `token_exp`)
- 8-hour expiration; client checks every 60s, inactivity logout at 30min
- Three roles: `cliente`, `agente`, `admin`
- Turnstile CAPTCHA on login (`api/config/turnstile.php`)
- Every API call requires `Authorization: Bearer <token>` and `X-CSRF-Token` headers

### Backend Structure
- `api/config/` — Environment detection, DB connection (PDO), email config
- `api/controllers/` — One PHP file per resource/feature (~30 endpoints)
- `api/middleware/auth.php` — JWT validation, role checks, CSRF
- `api/services/` — Business logic: SLA calculation, email sending, audit logging
- `api/cron/` — Scheduled jobs for email polling and reminders

Environment is auto-detected in `api/config/environment.php` (localhost = dev, else prod). Dev DB: `localhost/root` (no password). Prod DB: `crpdesk.vpshost0269.mysql.dbaas.com.br`.

### Frontend Structure
Single HTML shell (`frontend/index.html`) with no framework router. JavaScript is split by feature module:

| File | Responsibility |
|---|---|
| `script.js` | Core app: tickets CRUD, pagination, modals, session management |
| `graficos-dashboard.js` | Chart.js analytics/dashboard |
| `notificacoes.js` | Real-time notifications via SSE (`EventSource`) |
| `macros.js` | Quick response templates |
| `alpine-components.js` | Alpine.js + Quill.js WYSIWYG editor components |
| `status-sla.js` | SLA badge rendering |
| `lembretes.js` | Reminder management |
| `avatar.js` | User profile dropdown |

CSS follows a per-feature file convention (`tickets-melhorias.css`, `status-sla.css`, etc.). The main theme color is `#1e3a8a` (blue gradient header). Dark/light mode is CSS-variable-driven in `style.css`.

### Key Data Models
- `tickets` — Core entity; `descricao` is LONGTEXT; links to `usuarios`, `categorias`, `agentes`
- `comentarios` — Comments on tickets; `comentario` is LONGTEXT (supports base64 images from Quill)
- `historico` — Field-level change log per ticket
- `auditoria` — General action log with IP, user agent, session
- `notificacoes` — Internal alert queue polled/streamed to frontend
- `macros` — WYSIWYG quick-reply templates owned by agents
- `horarios_sla` + `feriados` — Business hours and holidays for SLA calculation
- `email_logs` — Delivery tracking for all outbound emails

### Real-time Notifications
`api/controllers/notificacoes-stream.php` streams via Server-Sent Events (SSE). Triggers are inserted directly in `tickets.php` and `comentarios.php` on assignment and reply events.

### File Uploads
Attachments stored in `backend/anexos/`. Max 5 files, 20MB each. Allowed: pdf, doc, docx, txt, csv, xls, xlsx, png, jpg, jpeg.

### SLA
`api/services/sla-service.php` computes SLA deadlines using business hours from `horarios_sla` and holidays from `feriados`. Ticket `status` can be `pausado_em` to freeze SLA timer.

### Database Migrations
8 sequential SQL files in `database/`. Must be applied in order. Key changes: migrations 6–8 add LONGTEXT columns, `macros`, `notificacoes` tables, and performance indexes.

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
- Executável MySQL: `C:\xampp\mysql\bin\mysql.exe`
- Conexão: host `localhost`, database `crpdesk`, usuário `root`, senha vazia.
- Comando padrão: `C:\xampp\mysql\bin\mysql.exe -u root -e "USE crpdesk; SUA_QUERY_AQUI;"`
- O arquivo `crpdesk.sql` na raiz é um **backup estático e pode estar desatualizado**. Usar o MySQL em execução como fonte da verdade.
- Para dump atualizado: `C:\xampp\mysql\bin\mysqldump.exe -u root crpdesk > crpdesk_atual.sql`
- UPDATE/DELETE com **EXTREMA CAUTELA**: sempre com `WHERE id = ?`. Scripts temporários de manutenção devem ser removidos após uso.
