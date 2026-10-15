# Roteiro de implantação em produção — Conecta CRPRS

Checklist para a primeira vez que o sistema for publicado (ou para replicar o banco local
na produção, como ficou decidido para o fim deste projeto). Siga na ordem.

## 1. Decisões que só o CRPRS pode tomar

Nada abaixo foi definido neste roteiro — são escolhas de implantação, não técnicas.

- **Nome do banco de produção: decidido — `db_conecta`** (confirmado em 15/10/2026; já ajustado
  em `api/config/environment.php` e `api/config/.env.example`).
- **Host e usuário do banco de produção.** `api/config/environment.php` (função `production()`)
  ainda tem `db_host` = `crpdesk.vpshost0269.mysql.dbaas.com.br` e `db_user` = `crpdesk`, herdados
  do CRPDESK. Confirmar se o novo banco `db_conecta` fica no mesmo servidor/usuário ou muda —
  e ajustar `db_host`/`db_user` em `environment.php` de acordo (ou preencher `DB_HOST_PROD`/
  `DB_USER_PROD` no `.env`, que tem prioridade sobre o valor fixo no arquivo).
- **`base_url` de produção.** Mesma função, hoje `https://crp07.org.br/crpdesk`. Confirme o caminho
  definitivo (ex.: `https://crp07.org.br/conecta`) e ajuste.
- **E-mail de cada setor.** As caixas IMAP/SMTP de setor (`setor_emails`) são cadastradas pela
  tela "Configurar e-mail" depois do primeiro deploy — não há nada a migrar aqui, mas alguém
  precisa cadastrá-las antes dos setores dependerem de e-mail.

## 2. Banco de dados

```bash
# 1. Backup do banco de produção atual (mesmo que vá ser substituído)
mysqldump -u <usuario> -p <banco_prod> > backup_antes_da_migracao.sql

# 2. Se o banco de produção já tiver as tabelas do CRPDESK original (sem as migrações 9-22),
#    aplique o esquema base uma vez: database/squema_crpdesk.sql

# 3. Rode o runner de migrações (cada arquivo roda uma única vez; nunca editar um já aplicado)
php database/migrar.php --status      # lista o que já está aplicado
php database/migrar.php               # aplica 9..22 pendentes
```

Se o banco de produção for a **réplica do banco local** (`db_conecta`) em vez de migrado a partir
do CRPDESK antigo, não rode `migrar.php` — ele já vem com as 22 migrações aplicadas; só confirme
com `--status` que a tabela `migracoes` foi copiada junto.

## 3. Arquivo `.env`

Copie `api/config/.env.example` para `api/config/.env` no servidor e preencha os valores
`*_PROD` (nunca comite o `.env` real). Cada chave tem o comando de geração no próprio `.env.example`.

- `APP_ENV=production` (ou omita a linha — produção é o padrão quando `APP_ENV` não é reconhecido)
- `DB_HOST_PROD`, `DB_NAME_PROD`, `DB_USER_PROD`, `DB_PASS_PROD`
- `IMAP_*_PROD`, `SMTP_*_PROD`, `FROM_EMAIL_PROD` — remetente global/fallback do sistema
- `JWT_SECRET_PROD`, `CSRF_SECRET_PROD` — gere um valor **novo**, não copie do `.env` local
- `SETOR_EMAIL_ENCRYPTION_KEY_PROD` — cifra as senhas das caixas de e-mail de setor; gerar uma vez e nunca trocar depois (perderia o acesso às senhas já cifradas)
- `INTEGRATION_API_KEY_PROD` — só se a integração M2M (`abrir_chamado.php`) for usada em produção
- `CRON_KEY_PROD` — exigido pelos crons quando acionados por HTTP (ver seção 5)
- `CORS_ALLOWED_ORIGINS_PROD`, `SSO_SHARED_SECRET_PROD` — só se o sistema for incorporado por um painel agregador

Confirme permissões do arquivo (`chmod 600`) e que ele não é servido pelo Apache (já bloqueado
pelo `.htaccess` do projeto, mas vale testar `GET /api/config/.env` de fora).

## 4. Primeiro administrador (só se o banco não vier com usuários)

```bash
php database/seed_admin.php "email@crprs.org.br" "Nome Completo"
```

Pedirá a senha no terminal (regras de `SenhaService`: mínimo 10 caracteres, maiúscula,
minúscula, número e caractere especial).

## 5. Crons (`api/cron/`)

Os 4 crons (`verificar-emails.php`, `verificar-lembretes.php`, `enviar-fila.php`,
`verificar-emails-perdidos.php`) são chamados por HTTP e exigem a chave `CRON_KEY_PROD`:
cabeçalho `X-Cron-Key: <valor>` (preferível) ou `?key=<valor>`.

- `verificar-emails.php` — a cada 2 minutos (cron-job.org ou equivalente). Já dispara, na mesma
  execução, os lembretes vencidos e o envio da fila de e-mail — **não precisa agendar os outros
  três separadamente**, a menos que se queira um intervalo diferente para algum deles.
- Teste manual antes de agendar: `curl -H "X-Cron-Key: <valor>" https://<dominio>/api/cron/verificar-emails.php`

## 6. Depois do primeiro deploy

- [ ] Login funciona com o primeiro administrador
- [ ] `php database/migrar.php --status` mostra as 22 migrações aplicadas
- [ ] `php api/tests/matriz-de-acesso.php https://<dominio>` roda limpo contra o ambiente (cuidado: cria e apaga dados de teste — só rodar antes de haver dados reais, ou usar um ambiente de homologação)
- [ ] Teste de envio de e-mail pela tela "Configurar e-mail" (teste de uma caixa de setor) chega de verdade
- [ ] Cron de e-mail rodou ao menos uma vez com sucesso (ver `backend/logs/email-cron.log`)
- [ ] HTTPS ativo e `Secure`/`HttpOnly`/`SameSite=Strict` confirmados nos cookies de sessão (se usados) e nos headers de segurança (`X-Frame-Options`, `Content-Security-Policy` etc.)
- [ ] Backup automático do banco agendado (fora do escopo deste sistema, mas necessário)

## Pendências técnicas conhecidas (não bloqueiam a implantação)

- **`firebase/php-jwt`**: alerta de segurança baixo (`CVE-2025-45769`, versões < 7.0.0; o projeto
  usa `^6.0`). Avaliar a atualização para a 7.x numa janela própria, testando a geração/validação
  de token depois.
- **P8 da auditoria** (padronizar o formato de resposta da API) e **P10** (SLA calculado só no
  backend, sem duplicar a conta no frontend) ficaram de fora desta rodada: são mudanças
  transversais (dezenas de controllers e o `status-sla.js` do frontend) que merecem uma fase própria,
  com tempo de teste dedicado, em vez de entrarem de carona numa fase de qualidade/implantação.
