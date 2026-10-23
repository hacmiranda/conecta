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
- `api/services/` — Business logic: access rules, timeline, SLA calculation, email sending, audit logging, crypto of mailbox passwords, single file logger (`LogService`, `backend/logs/<canal>.log`)
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
| `graficos-dashboard.js` | Canvas-drawn charts of the dashboard (status, priority, monthly, by-agent, funnel, 30-day evolution, stacked-by-month, heatmap) |
| `cabecalho-setor.js` | Header: sector name (gestor/operador) or sector selector (admin); `urlComSetor()` appends `setor_id` |
| `notificacoes.js` | Notifications, polling `notificacoes.php` every 30 s |
| `macros.js` | Quick response templates (per sector; `setor_id` NULL = all sectors) |
| `alpine-components.js` | Alpine.js + Quill.js WYSIWYG editor components |
| `status-sla.js` | `STATUS_CONFIG`/`PRIORIDADE_CONFIG` (icon/color/name), status/priority catalog fetch for the Fase 4 modals, and `atualizarIndicadoresSLA()` — renders the SLA badge from `ticket.sla` (computed server-side by `SLAService::situacao()`, Fase 8), no business-hours math left here |
| `lembretes.js` | Reminder management |
| `avatar.js` | Avatar crop/upload modal (the header dropdown lives in `script.js`) |
| `tutorial.js` | Onboarding tutorial (overrides `mostrarTutorialAjuda` from `ajuda.js`) |
| `acessibilidade.js` | Cross-cutting a11y: focus-trap/ESC/return-focus for every `#modal-overlay` dialog, keyboard (Tab/Enter/Space) for `onclick` `<div>/<span>/<tr>/<li>` (skips `aria-hidden` ones), `aria-live` on toasts |
| `sidebar-navegacao.js` | Sidebar: highlights the open sub-screen in the Ajuda/Configurações groups (each `.nav-dropdown-item` has `data-subsecao` = the value its screen writes to `cd_subSecaoAtiva`; `.active` + `aria-current="page"`, re-evaluated after every click and after `mostrarSecao`, which it wraps) and the collapse button `#btn-recolher-sidebar` (all roles; class `recolhida` on `#app-sidebar`, saved in `cd_sidebarRecolhida`; icons only, labels kept for screen readers; opening a group or the avatar menu expands it first; desktop only) |
| `senha-visivel.js` | Show/hide button (eye icon, `aria-pressed`) on every `input[type=password]`, including ones created later in modals (MutationObserver); skips fields that already have their own button (`.btn-toggle-pass` on login). Also loaded by `redefinir-senha.html`; CSS in `senha-visivel.css` |
| `inicio-pagina.js` | Login redirect guard, hide "Configurações" for non-admins, initial dark-mode state, avatar dropdown close-on-outside-click, `toggleNavDropdown`/`fecharNavDropdown` (Ajuda/Configurações menu groups) — moved verbatim out of `index.html`'s inline `<script>` (Fase 9) |

**Shared functions that stay in `script.js` on purpose** (called by more than one domain file): `urlComSetor`, `exibirColunaSetor`, `mostrarSkeletonTickets`, `atribuirTicket`, `executarAcaoDemanda`, `pedirComentarioEExecutar`, `obterSetorIdDoTicket`, `alterarStatus`, `alterarCategoria`/`executarAlteracaoCategoria`, `alterarPrioridade`/`executarAlteracaoPrioridade`, `executarAlteracaoStatus`, `executarExclusaoTicket`, constants `MAX_ARQUIVOS`/`MAX_TAMANHO`/`TIPOS_PERMITIDOS`.

**Frontend conventions (new or changed code):**
- Network calls use `Api.*`, never raw `XMLHttpRequest`. Exception: `FormData` uploads (Api only sends JSON) keep `fetch`/XHR with `getAuthHeaders()`/`setSecurityHeaders()`.
- Error message pattern: `mostrarAlert('Erro', (e.dados && e.dados.error) || 'texto padrão')` — only where the server message should reach the user; never `e.message` alone (always filled).
- Old pre-existing dead code is documented, not removed, unless the user approves (`confirmarAtribuicao`/`executarAtribuicao`, `obterIconePrioridade`, `irParaTickets`, `validarAntesFechamento`, `uploadLogoLogin`, `mostrarModoEscuro`, `frontend/dashboard-update.js` is never loaded).

CSS follows a per-feature file convention (`tickets-melhorias.css`, `status-sla.css`, etc.). The main theme color is `#1e3a8a` (blue gradient sidebar). Dark/light mode is CSS-variable-driven in `style.css`.

### Key Data Models
- `tickets` — Core entity (a "demanda"); `descricao` is LONGTEXT; links to `usuarios`, `categorias`, `setores` (`setor_id`) and responsible (`agente_id`)
- `setores` — Sectors; `setor_emails` (one mailbox per sector: IMAP receive + SMTP send, passwords encrypted), `setor_status` / `setor_prioridades` (per-sector activation of the global catalog)
- `categorias` / `macrocategorias` — Owned by a sector (`setor_id`). UI: `macrocategorias` = "Categoria", `categorias` = "Subcategoria". Category names are unique per sector; subcategory names are unique **per category** (migration 24: the same "Inscrição" can exist under "Pessoa Física" and "Pessoa Jurídica"). Wherever a subcategory is shown alone (lists, selects, reports, dashboard, history) it is labelled `"Subcategoria - Categoria"`: SQL `CONCAT_WS(' - ', c.nome, mc.nome)` (`tickets.php` `categoria_nome`, `relatorios.php`, `dashboard.php`, history in `tickets.php`/`demanda-acoes.php`), `categorias.php` GET returns it as `rotulo`, frontend helper `rotuloSubcategoria(cat)` in `categorias.js`
- `horarios_uteis` + `feriados` — Business hours and holidays **per sector** (SLA of a demand uses its sector)
- `comentarios` — Comments; `comentario` is LONGTEXT (supports base64 images from Quill); `interno` (migration 23) flags an internal note that never e-mails the requester
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

### Global SMTP encryption mode (fixed 23/10/2026)
The global sender's host/port fall back to `smtp.gmail.com:587` (STARTTLS) if `SMTP_HOST_*`/`SMTP_PORT_*` aren't set in `.env` — but `email-sender.php` and `testar-email.php` used to hardcode `SMTPSecure = ENCRYPTION_SMTPS` (implicit TLS, port 465) regardless of port, so SMTP always failed against a STARTTLS server (IMAP worked fine since it's unaffected). `EmailConfig::segurancaSmtp()` now picks `'tls'`/`'ssl'` from an optional `SMTP_SEGURANCA_*` env var, or infers it from the port (587 → STARTTLS, anything else → implicit SSL) — same convention already used per-sector (`setor_emails.seguranca`). Both files now call it instead of hardcoding `ENCRYPTION_SMTPS`.

### Demand list (server pagination)
`GET tickets.php?escopo=abertas|arquivo|...&pagina=N&por_pagina=10|25|50|100&busca=&grupo=novo|em_atendimento|aguardando|nova_resposta|urgente&meus=1&ordem=&direcao=` returns `{itens, total, pagina, por_pagina, total_paginas, contadores}`; without `pagina` it still returns the whole array (Kanban loads everything). Sorting is limited to a column whitelist.

### Incoming e-mail rules
Demands from e-mail are created **only when the cron `api/cron/verificar-emails.php` runs** (production: cron-job.org every 2 min via HTTP + `X-Cron-Key`). Locally nothing schedules it — run it by hand (`php api/cron/verificar-emails.php`) or a demand "never opens". Subjects/attachment names are decoded with `iconv_mime_decode` (`EmailService::decodificarTexto`); `mb_decode_mimeheader` left `_` instead of spaces in Q-encoded subjects (fixed 23/10/2026).

A reply with `#NNNNN` in the subject becomes a comment only if the sender is the requester, someone who got a copy, or the sector team, and the mailbox's sector is one the demand passed through; otherwise a new demand is opened. Mail from the system's own mailboxes and auto-replies is ignored (`emails_processados.tipo = 'ignorado'`). Failures are retried (`emails_erro`) up to 5 times instead of being lost.

### Real-time Notifications
The frontend polls `api/controllers/notificacoes.php` every 30 s (`notificacoes.js`). Rows are inserted directly by `tickets.php`, `comentarios.php`, `demanda-acoes.php` and the reminder service.

### Reminders
`api/services/lembrete-service.php`, fired only by the cron (never by the browser). Reminders of closed/cancelled demands and directed reminders of transferred demands are cancelled (`lembretes.cancelado_em`/`cancelado_motivo`), not sent.

### File Uploads
Attachments stored in `backend/anexos/`. Max 5 files, 20MB each. Allowed: pdf, doc, docx, txt, csv, xls, xlsx, png, jpg, jpeg.

### SLA
`api/services/sla-service.php` computes business seconds using the hours (`horarios_uteis`) and holidays (`feriados`) of the demand's sector (sector without hours falls back to "Geral"). Some statuses (`congela_sla`) pause the SLA timer. `SLAService::situacao()` (Fase 8) is the single source of truth for a demand's current SLA status (`{tipo, segundos_restantes, percentual, vencido, pausado}`); `tickets.php` attaches it as `ticket['sla']` on every list/detail response (`anexarSla()`), and the frontend (`status-sla.js`, `demandas.js`) only renders it — no business-hours math left in JS.

### Database Migrations
`database/9_*.sql` … `23_*.sql` build on the base schema, in order: 9 sectors/roles, 10 sector log, 11 per-sector status/priorities, 12 sector mailboxes, 13 responsible log, 14 roles gestor/operador, 15 initial seed, 16 categories per sector, 17 hours/holidays per sector, 18 SMTP per sector, 19 reminder cancellation + ignored e-mails, 20 sessions and passwords (token_versao, trocar_senha, sessoes_revogadas), 21 integrity (foreign keys, utf8mb4, free `tickets.status`, trash columns, macros per sector, legacy tables dropped), 22 e-mail queue (`email_fila`) + `setor_emails.validar_certificado`, 23 internal note (`comentarios.interno`), 24 subcategory name unique per category (`uq_categorias_setor_macro_nome`), 25 `comentarios.visivel_solicitante` (conversation shown to the requester). Each runs once (MySQL 8.4 has no `ADD COLUMN IF NOT EXISTS`).

### Pendências conhecidas (atualizado em 22/10/2026)

**Antes do primeiro deploy em produção** (roteiro completo em `docs/implantacao-producao.md`):
- Definir no `.env` do servidor todas as chaves `*_PROD` (`SETOR_EMAIL_ENCRYPTION_KEY_PROD`, `INTEGRATION_API_KEY_PROD`, `CRON_KEY_PROD`, JWT/CSRF etc. — lista e comandos de geração em `.env.example`).
- Confirmar `db_host`/`db_user` de produção (hoje herdados do CRPDESK). Banco `db_conecta`, `base_url` `https://crp07.org.br/conecta` já definidos.
- Aplicar as migrações 9–22 com backup (`php database/migrar.php`), ou confirmar que a réplica do banco local já vem com elas.
- Checklist pós-deploy do roteiro: login do primeiro admin, `migrar.php --status`, matriz de acesso, teste real de e-mail por setor, cron de e-mail (externo, cron-job.org, via HTTP com chave), HTTPS + headers de segurança, backup automático.
- Avisar os mantenedores dos sistemas satélites da nova URL (`docs/especificacoes/PROMPT_INTEGRACAO_SISTEMA_SATELITE.md`; `docs/CONFIGURACAO_AMBIENTE.md` também cita a URL antiga).

**Projeto "Melhorias adiadas" em andamento** (plano completo nas notas do usuário; 10 fases, cada uma com commit próprio). **Fase 1 concluída em 22/10/2026**: XSS herdado corrigido em `auditoria.js`/`usuarios.js` (todo valor vindo da API passa por `sanitizeHTML()`, incl. `formatarAcao*`/`formatarUserAgent` quando o valor não bate com o catálogo conhecido); `HtmlSanitizer::texto($nome, 100)` adicionado em `categorias.php`/`macrocategorias.php` (POST e PUT); duplo envio de comentário bloqueado em `detalhe-demanda.js` (`adicionarComentario`/`enviarEFecharTicket` desabilitam `#btn-adicionar-comentario`/`#btn-enviar-fechar` durante a requisição, com guard contra clique duplicado síncrono); `.btn-primary:disabled` adicionado em `style.css`.

**Fase 2 concluída em 22/10/2026**: `setor-emails.php` — a falha ao decifrar a senha salva de uma caixa já cadastrada (`CryptoService::decrypt` lançando exceção, rota `acao=testar` + `setor_email_id`) agora responde `http_response_code(400)` + `{error}` em vez de `{sucesso:false, mensagem}` com HTTP 200; `ativacao-setor.js` (`testarEmailSetor`) ajustado para ler `e.dados.error` no catch. Varredura em todos os `api/controllers/*.php`/`api/integracoes/**/*.php` confirmou que é o único caso: todo outro `{error}` já vem com `http_response_code` ≥ 400 nas 3 linhas anteriores, e os demais `sucesso:false`/`success:false` restantes (`testar-email.php`, outros pontos de `setor-emails.php`) são resultado de teste de verdade (IMAP ok, SMTP falhou etc.), não erro de infraestrutura — ficam como HTTP 200 de propósito. Confirmado com HTTP real (`curl` com token/CSRF de teste): resposta 400 + `{error}`.

**Fase 3 concluída em 22/10/2026**: `descreverGraficoCanvas`/`getCorLabel`/`criarGraficoStatus/Prioridade/Mensal` (esquecidos em `script.js` desde a Onda 8) movidos literalmente para `graficos-dashboard.js`, junto dos demais gráficos do Dashboard. Sem conversão de rede (são só desenho em canvas). Testado no navegador: os 3 gráficos renderizam com o `aria-label` de acessibilidade correto a partir do novo arquivo.

**Fase 4 concluída em 22/10/2026**: `alterarStatus`/`alterarPrioridade` (em `script.js`, compartilhadas por `demandas.js`/`detalhe-demanda.js`/`arquivo.js`) unificadas numa só versão cada, sem duplicar as antigas "Nova" de `status-sla.js`. Agora buscam o catálogo do setor (`carregarStatusDisponiveis`/`carregarPrioridadesDisponiveis`), mostram ícone real de `window.STATUS_CONFIG`/`window.PRIORIDADE_CONFIG` (o `icone` que vem do catálogo do banco pode ser só o nome em texto, não um emoji) e a descrição com o SLA (resposta/resolução) via `mostrarSelectComDescricao`; caem para uma lista de reserva fixa se o catálogo falhar. `executarAlteracaoStatus`/`executarAlteracaoPrioridade` usam `Api.put('tickets.php?id=')`, com loader, erro no padrão `(e.dados && e.dados.error) || '…'` e a mesma animação de saída da linha ao encerrar/cancelar. Removidas de `status-sla.js`: `alterarStatusNovo`, `executarAlteracaoStatusNovo`, `alterarPrioridadeNova`, `executarAlteracaoPrioridadeNova`; os `window.STATUS_CONFIG`/`window.PRIORIDADE_CONFIG` **foram mantidos** (ainda usados pelas funções unificadas e por `demandas.js`/`detalhe-demanda.js` — divergência do texto literal do plano, verificada por `grep` antes de remover). `demandas.js:680` passa a chamar `alterarStatus(id, setor_id)`. Testado no navegador nos dois consumidores (tabela de Demandas e Detalhe da demanda): modal com ícone/descrição/SLA corretos, sem duplicação, e `Api.put` atualizando a demanda com sucesso nos dois pontos.

**Fase 5 concluída em 22/10/2026**: tela de usuários dividida em duas abas (`role="tablist"`/`tab`/`tabpanel`, setas ←/→ já ativam e devolvem o foco ao botão da aba — o conteúdo é recriado via `innerHTML` e isso destruía o elemento focado, corrigido focando o botão da aba de novo depois do recarregamento). "Equipe" (admin/gestor/operador, como antes) e "Solicitantes" (`tipo = cliente`, só visível para admin — gestor nunca via clientes mesmo antes, pois não têm `setor_id`), com busca por nome/e-mail (filtro local sobre a lista já carregada, sem nova requisição). `usuarios.php` GET ganhou `?tipo=equipe|solicitantes` (padrão "equipe" preserva o comportamento atual menos os clientes, que antes apareciam misturados em "Solicitantes externos" dentro da mesma lista); sem o parâmetro, format antigo não existe mais — todo consumidor do endpoint já foi migrado. Editar um solicitante mostra o campo Perfil desabilitado (sem seletor de papel/setor), sempre mantendo `tipo = cliente` — evita que o `<select>` sem opção "cliente" promovesse-o a operador ao salvar. Testado no navegador como admin (abas, busca, edição sem mudar o papel) e como gestor (sem a barra de abas, só a própria equipe).

**Fase 6 concluída em 22/10/2026**: nota interna no comentário. Migração `database/23_comentarios_nota_interna.sql` adiciona `comentarios.interno` (`TINYINT(1) DEFAULT 0`); comentários vindos de e-mail, `demanda-acoes.php` e `merge.php` continuam sem informar a coluna, então ficam com o padrão 0 (sem alteração nesses pontos). `comentarios.php` POST lê `interno` do FormData/JSON; quando 1, força `enviar_email = false` e `com_copia = null` antes de qualquer outra validação (nunca sai e-mail para o solicitante nem cópia), grava a coluna; as notificações internas (`comentarios_nao_lidos`, `notificacoes`, e o e-mail agente→agente de `notificarComentarioEntreAgentes`, que já não vai ao solicitante) continuam iguais. `timeline-service.php` não precisou de alteração — o `SELECT c.*` já devolve a coluna nova. `detalhe-demanda.js`: opção "🔒 Nota interna (não vai para o solicitante)" acima do envio por e-mail; ao marcar, desliga e desabilita (`disabled`) o campo de e-mail (`toggleComentarioInterno()`); comentário com `interno` ganha a classe `comentario-interno` + selo "🔒 Nota interna" tanto no histórico inicial quanto no comentário inserido ao vivo — comparação feita com `Number(item.interno) === 1` porque o PDO devolve a coluna como string ("0"/"1"). Vale para `adicionarComentario` e `enviarEFecharTicket`. CSS novo em `tickets-melhorias.css` (fundo/borda âmbar + selo, com modo escuro) e `input:disabled+.slider` em `style.css` para o switch desabilitado. Testado no navegador: nota interna não entra na fila de e-mail (`email_fila` vazia), selo aparece nos dois caminhos de renderização, e um comentário normal em seguida confirma que `interno = 0` e o fluxo de e-mail não regrediram. **Correção (revisão da Fase 8):** a notificação agente→agente (`notificarComentarioEntreAgentes`, quando outro membro da equipe comenta numa demanda de responsável diferente) ainda saía por e-mail mesmo com `interno = 1` — agora só dispara quando `!$interno`.

**Fase 7 concluída em 22/10/2026**: logger único. Novo `api/services/log-service.php` (`LogService::info/aviso/erro($canal, $mensagem)`) grava `[data] NIVEL mensagem` em `backend/logs/<canal>.log`; `erro()` também chama `error_log()`; em CLI (`PHP_SAPI === 'cli'`) toda chamada também ecoa no terminal. `api/cron/acesso-cron.php` `cronLog($arquivo, $mensagem)` mantém a assinatura antiga (nome do arquivo com `.log`, usada por ~10 chamadas nos crons) mas agora só delega para `LogService::info()`. Em `api/services/email-service.php`, os ~59 `echo "<p>…</p>"` e ~18 `file_put_contents($log_file, …)` (todos no canal `email-cron`, exceto a escrita real do anexo em disco, que não é log) viraram chamadas de `LogService::info/aviso/erro`; os `error_log()` de depuração que já existiam (ex.: `[ANEXO SAVE]`) ficaram como estavam, por decisão do plano. Como o `EmailService` não imprime mais HTML na saída, `api/cron/verificar-emails.php` e `verificar-emails-perdidos.php` perderam o `ob_start()`/`strip_tags()` que capturava aquele HTML — ficaria vazio e o `strip_tags` seria inútil, então os dois passaram a confiar só no `LogService` para o detalhe (arquivo de log e terminal em CLI) e no `echo` final só com o resumo de uma linha (igual ao que já ia para o cron HTTP). `verificar-lembretes.php`/`enviar-fila.php` não precisaram de mudança (já usavam `cronLog()` direto, sem HTML). Testado: `LogService` isoladamente (grava e ecoa as 3 mensagens corretamente); `verificar-lembretes.php` por CLI (aciona a fila também, grava `[data] INFO …` no log); `enviar-fila.php` por HTTP com `X-Cron-Key` (resposta de uma linha, sem HTML, headers de segurança OK). O cron de e-mail (IMAP) não foi executado neste teste — tocaria a caixa real configurada no `.env`; a correção foi validada por leitura de código + `php -l`, e pelos dois crons-irmãos que passam pelo mesmo `LogService`. **Correções (revisão da Fase 8):** o canal `email-cron` persiste em disco (fora do controle de acesso por setor do sistema) o que antes, na rota HTTP normal do cron, era descartado num buffer de saída — por isso o e-mail do remetente passou a ser mascarado (`j***@dominio.com`, `EmailService::mascararEmail()`) e o assunto (texto livre, pode ter dado pessoal) saiu do log; e o log de erro ao enviar e-mails de uma demanda nova voltou a incluir o `#ticket_id` (tinha se perdido na conversão para `LogService::erro()`).

**Fase 8 concluída em 22/10/2026**: SLA calculado só no backend. `SLAService::situacao(array $ticket, array $sla, ?int $setorId): ?array` → `{tipo:'resposta'|'resolucao', segundos_restantes, percentual, vencido, pausado}` (ou `null` se não há SLA a mostrar): usa `calcularSegundosUteis()` (já existente), os prazos do catálogo global `ticket_prioridades` e se o status atual pausa o SLA via `ticket_status.congela_sla` — ambos com cache por instância (`congelaSla()`, público, reaproveitado também pela troca de status em `tickets.php` no lugar da consulta que já existia lá). `api/controllers/tickets.php` ganhou `LEFT JOIN ticket_sla` nas três queries de ticket (detalhe, listagem paginada e listagem completa/Kanban) e a função `anexarSla()`, que roda depois de qualquer SELECT de tickets e preenche `$ticket['sla']` (fica `null` para demandas arquivadas — `encerrado`/`cancelado`/`fechado`). `frontend/status-sla.js` perdeu ~210 linhas de cálculo de horário útil em JS (`calcularSegundosUteisOtimizado`, `eHorarioUtil`, `eFeriado`, `carregarHorariosConfigurados`, as variáveis de horários/feriados por setor) e a função `calcularTempoRestanteSLA`; `PRIORIDADE_CONFIG` perdeu os prazos fixos (manteve ícone/cor/nome/descrição, usados em outros lugares). `atualizarIndicadoresSLA()` (a cada 60s) agora só lê `ticket.sla` e decrementa `segundos_restantes` localmente entre uma busca e outra — a lista recarrega o valor exato do servidor normalmente. `demandas.js` `calcularTextoSLA()` passou a ler `ticket.sla` direto (tabela e Kanban). Teste novo `api/tests/Unit/SLAServiceTest.php` (situacao: resposta, resolução, pausado, vencida, fora do horário, sem `criado_em`, já resolvida) — usa um setor fictício "sempre aberto" (todo dia, 00:00–23:59:59) para o cálculo depender do relógio real de forma previsível, sem mockar `date()`.

Testado no navegador e via `curl` com 4 demandas de teste (vencida, em alerta/50%, pausada, arquivada): selo de SLA idêntico ao anterior na tabela, Kanban e resposta da API, inclusive `sla: null` para a arquivada sem erro.

**Revisão independente (subagente, `/code-review high`, pedida pelo próprio plano para a Fase 8)** encontrou e corrigiu, antes do commit: (1) a assimetria entre os ramos resposta/resolução de `situacao()` deixava uma demanda já concluída sem `primeira_resposta_em` (dado legado) cair no cálculo de SLA de resposta — corrigido excluindo `resolvido`/`encerrado`/`cancelado` também nesse ramo; (2) `tempo_pausado_segundos` só é somado quando a pausa termina (`retomarPausa`), então uma pausa em andamento não descontava o trecho corrente — `situacao()` agora soma também `calcularSegundosUteis(pausado_em, agora)` quando o status atual pausa o SLA (`ticket_sla.pausado_em` entrou no SELECT das três queries); (3) o decaimento local de `atualizarIndicadoresSLA()` marcava `vencido = true` sem subir o `percentual`, deixando o ícone (que só aparece com `percentual >= 50`) escondido até a lista recarregar — corrigido subindo o `percentual` para 100 junto. Dois testes novos (`test_situacao_retorna_null_quando_resolvida_sem_primeira_resposta`, `test_situacao_desconta_a_pausa_em_andamento`) cobrem os dois primeiros achados. O achado sobre `anexarSla()` não ter cache entre requisições (N+1 de `calcularSegundosUteis` por listagem) foi avaliado e aceito como o mesmo custo que antes rodava no navegador, por linha visível — não é uma regressão de performance desta fase, só mudou de lugar; reavaliar se algum setor acumular demandas muito antigas sem encerrar.

**Fase 9 concluída em 22/10/2026**: o `<script>` inline de `index.html` (verificação de login, esconder "Configurações" para quem não é admin, estado inicial do modo escuro, fechar o dropdown do avatar ao clicar fora, `toggleNavDropdown`/`fecharNavDropdown` do menu Ajuda/Configurações, e as funções legadas vazias `toggleMenuUsuario`/`logout`) foi movido literalmente para `frontend/inicio-pagina.js`, carregado no mesmo ponto onde o bloco inline estava (antes de `script.js` e dos demais). Nenhuma mudança de comportamento. **CSP não mudou nesta fase** (decisão do plano): `script-src` em `.htaccess:45` continua com `'unsafe-inline' 'unsafe-eval'` — ainda necessários por causa dos `onclick=`/`style=` inline (~650 ocorrências, fora do escopo das melhorias adiadas) e do Alpine.js (usa `eval`); fica registrado aqui para um projeto futuro de endurecimento de CSP. Testado no navegador: login redireciona corretamente sem usuário, dropdown "Ajuda" abre/fecha (`aria-expanded`), e o modo escuro persiste e aplica o ícone/texto certos depois de um reload — sem erros no console.

**Fase 10 concluída em 22/10/2026**: `firebase/php-jwt` 6.11.1 → 7.2.1 (`composer require firebase/php-jwt:^7.0`), resolve o alerta baixo (CVE-2025-45769). A única mudança de comportamento da 7.0.0 é validar o tamanho mínimo da chave HS256 (256 bits/32 bytes, ou lança `DomainException`) — `JWT_SECRET_*`/`SSO_SHARED_SECRET_*` já exigiam `strlen() >= 32` em `auth.php`/`auth-sso.php` antes mesmo desta fase, e o `.env.example` já recomenda gerar com `bin2hex(random_bytes(32))` (64 bytes), então nenhuma chave precisou mudar. `new Key($secret, 'HS256')`/`JWT::encode`/`JWT::decode` (`auth.php`, `auth-sso.php`) não mudaram de assinatura. Nenhuma alteração de código foi necessária. Testado: `composer test` (40/40) e matriz de acesso (19/19, que geram e validam tokens reais) sem falhas; login real, uma chamada autenticada e logout (revogação de sessão) no navegador, todos passando pelo `JWT::decode`/`encode` da nova versão sem erro.

**Plano de melhorias adiadas concluído (fases 1–10, 22/10/2026).**

**23/10/2026**: botão de mostrar senha em todos os campos de senha (`senha-visivel.js`); subcategorias com o mesmo nome em categorias diferentes (migração 24) exibidas como "Subcategoria - Categoria"; destaque do submenu ativo e botão de recolher a sidebar (`sidebar-navegacao.js`). As subtelas ainda têm código antigo que tenta marcar `.active` em IDs inexistentes (`btn-usuarios`, `btn-categorias`…) ou em `btn-config-email` — inofensivo, o `sidebar-navegacao.js` recalcula o destaque logo depois.

### Sistema de cores (paleta oficial CRPRS, igual ao Sycer — 23/10/2026)
Fonte: `C:/xampp/htdocs/sycer/public/assets/css/app.css`. Tokens no `:root` de `frontend/style.css`: marca `--vinho-900/700/600/100/50` (vinho `#A7241C` = cor primária), `--laranja-500/100/50` (`#F89327`, acento: item ativo, destaques), `--verde-500/700/50` (`#70AB2A`) e neutros quentes `--neutro-50…900` (substituíram os cinzas azulados tipo slate/Tailwind). Os nomes antigos `--laranja/--vermelho/--verde/--cinza` continuam, apontando para os tokens. **Todo CSS novo usa `var(--token)`, nunca hex de cor direto.** A sidebar é um degradê vinho com item ativo marcado em laranja; o cabeçalho das tabelas de demandas (`.tickets-tabela th`) é **liso**, no estilo do Sycer (fundo `--neutro-100`, texto escuro, linha de 2 px em vinho; coluna ordenada com fundo `--vinho-50`; modo escuro `--neutro-900`) — nada de degradê por célula; o azul `#004373`/`#1e3a8a` e os roxos/rosas foram eliminados. Exceções deliberadas: (1) cores **funcionais de status/prioridade** (`status-config.js`, `status-sla.js`: ciano, âmbar, verde, vermelho etc.) ficam distintas para o reconhecimento rápido; (2) o **dashboard** é sempre escuro, com paleta própria (`dashboard-redesign.css`, `graficos-dashboard.js`): laranja, verde, vinho claro `#D1453B` e neutros sobre fundo `#171211`; (3) as telas autônomas `login.html`, `redefinir-senha.html` e `politica-privacidade.html` têm CSS embutido e usam hex literal da mesma paleta. A foto de fundo do login ganha a cor pelo véu vinho do `body::before`. E-mails (`backend/email-template-*.html`) usam laranja `#F89327`/verde `#70AB2A`/vinho; o de reabertura usa vinho.

### Cabeçalho padrão dos e-mails ao solicitante (Opção C, 23/10/2026)
Os 7 modelos `backend/email-template*.html` (criada, resposta, cópia, comentário recebido, primeira resposta, encerrada, reaberta) trazem o marcador `{{CABECALHO:Título}}` no lugar do cabeçalho antigo. `EmailCabecalho` (`api/services/email-cabecalho.php`) gera o HTML ("papel timbrado": logotipo à esquerda, instituição em 11 px e título em 20 px à direita, filete vinho embaixo — tabelas e estilos embutidos de propósito, por causa do Outlook/Gmail) e `EmailSender::carregarModelo()` troca o marcador ao ler o modelo, antes de qualquer conteúdo do usuário. **Todo modelo novo ao solicitante usa o marcador** (teste `EmailCabecalhoTest` exige em todos); o acento do corpo (barra lateral e etiquetas) é sempre vinho `#A7241C`. As propostas A/B/C avaliadas estão em `mockups/cabecalhos/` (só histórico).

### Demanda registrada + histórico nos e-mails ao solicitante (23/10/2026)
Os 7 modelos `backend/email-template*.html` terminam com o marcador `{{DEMANDA_E_HISTORICO}}`, trocado por `EmailSender::carregarModelo($caminho, $ticketId, $excluirComentarioId)` pelo bloco de `EmailHistorico` (`api/services/email-historico.php`): **Demanda registrada** (título + descrição originais) e **Histórico da conversa** (mais recente primeiro, até 30 mensagens, com nomes dos anexos). Entra só `comentarios.visivel_solicitante = 1 AND interno = 0`: o que o solicitante (ou cliente copiado) escreveu, ou comentário da equipe **enviado a ele por e-mail** (`comentarios.php` grava 1 só se `enviar_email` e não é nota interna; `email-service.php` grava 1 quando o autor é cliente ou o próprio solicitante). Ficam de fora notas internas, comentários da equipe sem e-mail, e comentários de fluxo interno (devolver/repassar/transferir) e mesclagem (default 0). Comentários antigos da equipe ficam ocultos (migração 25 não tem como saber quais foram por e-mail). "Resposta da equipe" e "Cópia da resposta" não repetem o comentário recém-enviado. Textos passam de novo por `HtmlSanitizer::rico()`, imagens base64 viram aviso e `{{…}}` do usuário é neutralizado. **Todo comentário novo que o solicitante deva ver precisa gravar `visivel_solicitante = 1`.** Testes: `EmailHistoricoTest`.

### Tipografia das respostas da equipe (23/10/2026)
Toda resposta da equipe tem a mesma fonte e tamanho, não importa o que foi digitado ou colado. **E-mails:** `EmailTipografia::padronizar()` (`api/services/email-tipografia.php`) descarta `font-size/font-family/line-height` dos estilos embutidos, remove `<font>`, troca h1–h6 por parágrafo em negrito e envolve tudo em Segoe UI/Verdana/Arial 15 px, entrelinha 1,6 (mantém negrito, itálico, listas, links e cores); é aplicada na "Resposta da equipe"/"Cópia" (`enviarComentarioParaSolicitante`) e em cada mensagem/descrição do histórico (`EmailHistorico::texto`). A caixa de resposta dos modelos usa a mesma fonte (a "primeira resposta" estava em monospace). **Sistema:** tokens `--fonte-resposta` (Inter), `--tamanho-resposta` (15 px) e `--entrelinha-resposta` em `style.css`; `.comentario *` e os editores `#editor-comentario`/`#editor-macro` herdam a fonte com `!important` (título vira negrito no mesmo tamanho; `pre/code` seguem monoespaçados); os editores de resposta e de respostas rápidas **não têm mais o seletor de tamanho** (os demais editores, como o de nova demanda, mantêm). Texto novo que mostre uma resposta da equipe deve usar essa mesma tipografia. Testes: `EmailTipografiaTest`.

### Mockups dos e-mails (`mockups/`, 23/10/2026)
Catálogo visual dos 16 e-mails que o sistema envia (8 ao solicitante, 8 internos), cada um com o momento do envio e o destinatário: `mockups/README.md` (tabela + imagens) e `mockups/index.html` (galeria). `php mockups/gerar-mockups.php` regenera as páginas a partir dos modelos reais `backend/email-template-*.html`; os e-mails montados direto em `email-sender.php`/`lembrete-service.php`/`recuperar-senha.php` e os avisos em texto puro são **cópias** no catálogo do gerador — ao mudar o texto de um e-mail, atualizar o gerador, rodar de novo e refazer os PNGs de `mockups/imagens/`. `mockups/.htaccess` só libera o logotipo externo (CSP `img-src`) para a pré-visualização; o e-mail entra na moldura por `srcdoc` porque o `.htaccess` raiz manda `X-Frame-Options DENY`. A pasta não deve ir para o servidor de produção. Os e-mails ao solicitante assinam **todos** com `EmailSender::getAssinatura()` (equipe do setor que envia); `backend/AssinaturaEmail.html` ficou sem uso. O modelo `email-template-atribuicao.html` foi removido (a atribuição envia só aviso em texto puro).

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
