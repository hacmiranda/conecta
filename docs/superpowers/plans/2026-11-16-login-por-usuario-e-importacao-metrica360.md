# Login por e-mail ou usuário + importação do METRICA360 — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** login aceita e-mail ou nome de usuário; 35 pessoas do METRICA360 (e seus setores) entram no `db_conecta`.

**Architecture:** regras puras em `UsuarioService` (validação do nome de usuário e decisão e-mail/usuário), usadas por `auth.php` e `usuarios.php`; `ImportacaoMetrica360::planejar()` ganha a lista de e-mails ignorados; o script de importação ganha `--ignorar=`.

**Tech Stack:** PHP 8 + PDO/MySQL 8.4, PHPUnit (`cd api && composer test`), JS puro.

**Spec:** `docs/superpowers/specs/2026-11-16-login-por-usuario-e-importacao-metrica360-design.md`

## Global Constraints
- Nome de usuário: `^[a-z0-9._-]{3,50}$` (minúsculas; sem `@`).
- Login JSON: aceita `login` (novo) e `email` (antigo).
- Resposta de falha continua `401 {"error":"Credenciais inválidas"}` (não revela se a conta existe).
- Ignorar na importação: `admin@crprs.org.br`, `administrador@crrs.org.br`.
- Código e textos em pt-BR; CLAUDE.md atualizado no mesmo trabalho.

## Review Focus
- Login com `Hugo.Miranda` (maiúsculas) → entra (username comparado em minúsculas).
- Login com espaço antes/depois → entra (trim).
- Solicitante (`cliente`) tentando pelo nome de usuário → 401, como por e-mail.
- Editar usuário sem mexer no nome de usuário (campo vazio) → mantém o atual, não vira `nome2`.
- Rodar a importação duas vezes → a segunda não duplica nada (já garantido por `executar()`).

---

### Task 1: Regras do nome de usuário (`UsuarioService`)

**Files:** Modify `api/services/usuario-service.php`; Test `api/tests/Unit/UsuarioServiceTest.php` (novo)

**Interfaces — Produces:**
- `UsuarioService::PADRAO_USERNAME = '/^[a-z0-9._-]{3,50}$/'`
- `UsuarioService::usernameValido(string $username): bool`
- `UsuarioService::campoDoLogin(string $identificador): string` → `'email'` se contém `@`, senão `'username'`
- `UsuarioService::normalizarLogin(string $identificador): string` → `strtolower(trim())`

- [ ] Testes: `usernameValido('hugo.miranda')` true; `('ab')` false; `('Hugo')` false; `('a@b.com')` false; `('nome sobrenome')` false; 51 caracteres false; `campoDoLogin('x@y.br') === 'email'`; `campoDoLogin('hugo.miranda') === 'username'`; `normalizarLogin('  Hugo.Miranda ') === 'hugo.miranda'`.
- [ ] Rodar (falha), implementar, rodar (passa).

### Task 2: Login por e-mail ou usuário (`auth.php` + `login.html`)

**Files:** Modify `api/controllers/auth.php`, `frontend/login.html`

- [ ] `auth.php`: `$login = UsuarioService::normalizarLogin($input['login'] ?? $input['email'] ?? '')`; `WHERE u.email = ?` ou `WHERE u.username = ?` conforme `campoDoLogin`; chave do limite por conta = `'conta_' . ($usuario ? 'id' . $usuario['id'] : sha1($login))`; auditoria: e-mail da conta quando existe, senão `LogService::mascararLogin($login)` (novo: e-mail → `mascararEmail`; usuário → 1ª letra + `***`). Mensagem 400: "Informe o e-mail ou usuário e a senha".
- [ ] `login.html`: rótulo "E-mail ou usuário", `type="text"`, `id="login"`, `autocomplete="username"`, `autocapitalize="none"`, `spellcheck="false"`, ícone `person` (adicionar a `icon_names`, ordem alfabética); envia `{login, senha, …}`; campos vazios avisam antes de enviar; erro 401 diz "E-mail/usuário ou senha incorretos".
- [ ] Teste `LogServiceTest`: `mascararLogin('hugo.miranda') === 'h***'`, `mascararLogin('a@b.br') === 'a***@b.br'`.
- [ ] Verificar por HTTP: login por e-mail, por usuário, usuário em maiúsculas → 200; senha errada → 401; matriz de acesso sem falhas.

### Task 3: Validação do nome de usuário no cadastro (`usuarios.php` + `usuarios.js`)

**Files:** Modify `api/controllers/usuarios.php` (POST ~l.300/378, PUT ~l.541/614), `frontend/usuarios.js` (campos `#username-usuario`, `#edit-username`)

- [ ] Servidor: `username = strtolower(trim(...))`; não vazio e `!usernameValido` → 400 "Nome de usuário inválido: use de 3 a 50 letras minúsculas, números, ponto, hífen ou sublinhado (sem @)". PUT com campo vazio mantém `$alvo['username']` (gera só se o atual também for vazio).
- [ ] Tela: `pattern`, `maxlength="50"`, dica abaixo do campo (`aria-describedby`) e conferência antes de enviar (`mostrarAlert`). `sugerirUsername()` já gera no formato.
- [ ] Dar `hugo.miranda` ao usuário id 1 (UPDATE com `WHERE id = 1`, conferindo antes que está livre).
- [ ] Verificar por HTTP: POST com `a@b` → 400; PUT sem username mantém o atual.

### Task 4: Importação com e-mails ignorados

**Files:** Modify `api/services/importacao-metrica360.php` (`planejar`, `relatorio`), `database/importar-metrica360.php`; Test `api/tests/Unit/ImportacaoMetrica360Test.php`

**Interfaces:** `planejar(array $setoresOrigem, array $usuariosOrigem, array $setoresDestino, array $usuariosDestino, array $emailsIgnorados = []): array` — plano ganha `'ignorados' => string[]` (e-mails pulados); ignorados não contam para os setores que entram nem para coordenação.

- [ ] Teste: usuário ignorado não aparece em `usuarios`, aparece em `ignorados`; setor só dele não entra.
- [ ] Relatório: seção "IGNORADOS A PEDIDO (n)" com os e-mails. Script: `--ignorar=a@x,b@y`.
- [ ] Ensaio com `--ignorar=admin@crprs.org.br,administrador@crrs.org.br` → 35 contas a criar.
- [ ] Backup (`mysqldump` do `db_conecta` em `C:\Users\hugo.miranda\conecta-backup-banco-2026-11-16\`), `--executar` com o mesmo `--ignorar`, conferir contagens e um login por usuário de uma conta importada é recusado só pela senha (401), não por não existir.

### Task 5: Testes completos, CLAUDE.md e commit

- [ ] `cd api && composer test` e `php api/tests/matriz-de-acesso.php` sem falhas.
- [ ] CLAUDE.md: login por e-mail ou usuário (Authentication), regra do nome de usuário, importação executada em 16/11/2026 (35 contas, 2 ignoradas), pendência "Unificação" atualizada.
- [ ] Commit(s) em pt-BR.
