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
- **`base_url` de produção: decidido — `https://crp07.org.br/conecta`** (confirmado em
  15/10/2026; já ajustado em `api/config/environment.php`). Dois documentos antigos da época do
  CRPDESK ainda citam a URL anterior e nem foram renomeados para Conecta CRPRS — não são lidos
  pelo código, só guias para humanos: `docs/CONFIGURACAO_AMBIENTE.md` e
  `docs/especificacoes/PROMPT_INTEGRACAO_SISTEMA_SATELITE.md` (este é usado por sistemas
  satélites que consomem `abrir_chamado.php` — avisar quem mantém esses sistemas sobre a nova
  URL quando o caminho `/conecta` entrar no ar).
- **Subdomínio próprio (auditoria 01/11/2026, SEG-01): decidido em 03/11/2026 — NÃO.** O Conecta
  fica em subpasta (`https://crp07.org.br/conecta`), sem DNS nem certificado novos; `base_url`, cookie
  (`Path=/conecta`), CORS e URL dos crons ficam como estão. O risco de mesma origem com o site
  institucional foi aceito (o METRICA360 deixa de ser um sistema à parte: vira o módulo "Satisfação"
  do Conecta). `https://crp07.org.br/metrica360` passará a redirecionar (301) para `/conecta/`.
  Se um dia for preciso mudar, a lista do que muda junto está no histórico do Git deste arquivo
  (commits anteriores a 03/11/2026).
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
cabeçalho `X-Cron-Key: <valor>` (o parâmetro `?key=` na URL não é mais aceito, para a chave não ficar em logs).

- `verificar-emails.php` — a cada 2 minutos (cron-job.org ou equivalente). Já dispara, na mesma
  execução, os lembretes vencidos e o envio da fila de e-mail — **não precisa agendar os outros
  três separadamente**, a menos que se queira um intervalo diferente para algum deles.
- Teste manual antes de agendar: `curl -H "X-Cron-Key: <valor>" https://<dominio>/api/cron/verificar-emails.php`
- Desde a auditoria de 01/11/2026: um erro de PHP numa mensagem não derruba mais a cadeia (lembretes e fila
  seguem, até depois de erro fatal); cada execução lê só as mensagens novas (os UIDs já tratados ficam em
  `backend/cache/email-uids/`, que pode ser apagada sem perda: a próxima execução confere tudo de novo);
  o log é um arquivo por dia (`backend/logs/email-cron-AAAA-MM-DD.log`, guardado `LOG_RETENCAO_DIAS` dias,
  padrão 30) com uma linha de resumo por caixa. O antigo `backend/logs/email-cron.log` não é mais escrito e
  pode ser arquivado ou apagado.

## 6. Depois do primeiro deploy

- [ ] Login funciona com o primeiro administrador
- [ ] `php database/migrar.php --status` mostra todas as migrações aplicadas (9 a 40 em 03/11/2026)
- [ ] `php api/tests/matriz-de-acesso.php https://<dominio>` roda limpo contra o ambiente (cuidado: cria e apaga dados de teste — só rodar antes de haver dados reais, ou usar um ambiente de homologação)
- [ ] Teste de envio de e-mail pela tela "Configurar e-mail" (teste de uma caixa de setor) chega de verdade
- [ ] Cron de e-mail rodou ao menos uma vez com sucesso (ver `backend/logs/email-cron-AAAA-MM-DD.log`: linha
      "Caixa #N (setor #M): ... (X s)" por caixa)
- [ ] Pastas `backend/logs/` e `backend/cache/` graváveis pelo usuário do PHP (e fora do alcance da web:
      `backend/.htaccess` nega tudo)
- [ ] `curl -I https://<dominio>/frontend/script.js?v=<versão>` traz `Cache-Control: public, max-age=31536000, immutable`
      e, com `mod_deflate` ativo, `Content-Encoding: gzip` (pedir com `-H "Accept-Encoding: gzip"`)
- [ ] HTTPS ativo e `Secure`/`HttpOnly`/`SameSite=Strict` confirmados nos cookies de sessão (se usados) e nos headers de segurança (`X-Frame-Options`, `Content-Security-Policy` etc.)
- [ ] Backup automático do banco agendado (fora do escopo deste sistema, mas necessário)

## 6a. Conferir no servidor antes do deploy (auditoria 01/11/2026, Fase 0)

Registrar aqui os valores encontrados no servidor de produção (não foi possível conferir daqui):

| Item | Recomendado | Encontrado |
|---|---|---|
| Versão do PHP | 8.2 ou mais nova (o suporte de segurança do 8.2 termina em 31/12/2026: planejar 8.3/8.4) | |
| Extensões | `imap`, `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `zip` (sem `zip`, DOCX/XLSX são aceitos sem conferir o pacote) | |
| `memory_limit` | 256M ou mais (o cron lê e-mails com anexos de até 20 MB) | |
| `post_max_size` / `upload_max_filesize` | coerentes com 5 anexos de 20 MB (ex.: 110M / 20M) | |
| `max_execution_time` do cron por HTTP | o cron chama `set_time_limit(300)` | |
| Módulos do Apache | `mod_headers`, `mod_rewrite` e, para a compressão, `mod_deflate` | |
| Rotação dos logs do servidor (Apache/PHP) | ativa | |

## 6b. O que NÃO enviar ao servidor (auditoria 01/11/2026, MAN-04)

Ao empacotar o deploy, deixar de fora:

- `docs/`, `mockups/` (o `.htaccess` só bloqueia os `.php` de lá), `.playwright-mcp/`, `.superpowers/`,
  `.claude/`, `.agent/`, `.git/`
- `api/tests/` (testes e matriz de acesso) e `api/phpunit.xml`
- `frontend/node_modules/` e `frontend/package*.json` (ferramentas locais)
- `frontend/images/logos-propostas/` e `frontend/branding-preview.html` (só histórico)
- `api/composer.phar`, dumps `.sql` fora de `database/`, `backend/logs/*`, `backend/cache/*` e `backend/anexos/*`
  do ambiente local (dados de desenvolvimento)
- `api/config/.env` local (o servidor tem o seu, com as chaves `*_PROD`)

## 7. Módulo Satisfação, papéis de coordenação e Registro de Atividades (unificação com o METRICA360, 03/11/2026)

O METRICA360 deixou de ser um sistema à parte: pesquisa de satisfação, Meu Painel e Registro de Atividades
são do próprio Conecta, no mesmo banco, com o mesmo login. **Nada do METRICA360 precisa ser publicado**: não há
mais API entre os dois, nem `INTEGRACAO_CONECTA_API_KEY`, `METRICA360_URL_*` ou `METRICA360_API_KEY_*`.

1. **Banco:** `php database/migrar.php` (aplica a 39, papéis de coordenação, e a 40, módulo Satisfação), ou
   confirmar com `--status` que a réplica do banco local já vem com elas.
2. **Pessoas e setores:** o banco local recebe as contas e os setores dos servidores com
   `php database/importar-metrica360.php` (ensaio primeiro, depois `--executar`; só roda no banco local, nunca
   no de produção). As contas novas nascem com senha desconhecida e troca obrigatória: cada pessoa usa
   **"Esqueci a senha"** no primeiro acesso (o e-mail precisa estar certo e a caixa de envio configurada).
   Conferir depois, em Configurações → Usuários, o setor de quem ficou em "Geral" por não ter setor no
   METRICA360 e os coordenadores com mais de um setor.
3. **Configurar a pesquisa:** Configurações → Pesquisa de satisfação (admin): regras de convite (quarentena,
   validade do link, reenvio), meta de nota, e-mail do alerta de nota baixa, link do Google Avaliações,
   categorias e frases. O importador já traz as do METRICA360.
4. **Configurar cada setor** em Configurações → Atividades (admin, coordenador ou gestor): atividades (dá para
   colar várias, uma por linha), regras de prazo do setor e de cada servidor. O Conecta começa sem os tipos de
   atividade do METRICA360: copiar a lista de cada setor de lá antes de avisar os servidores. Sem "Cobrar a
   partir de", a cobrança começa no dia em que as regras são salvas.
5. **Marcar quem faz atendimento externo** (Configurações → Usuários, admin): só essas pessoas veem o
   "Meu Painel" e enviam convites. O importador já traz a marcação.
6. **Cron:** o de e-mail (`verificar-emails.php`, a cada 2 minutos) já fecha a cadeia, e o fim dela cria os
   convites, expira os vencidos e faz o reenvio. Não há cron novo.
7. **`/metrica360` no endereço antigo:** quando o METRICA360 não for mais usado, fazer o endereço
   `https://crp07.org.br/metrica360` redirecionar (301) para `https://crp07.org.br/conecta/` no servidor
   (no `.htaccess` da pasta antiga: `RedirectMatch 301 ^/metrica360/(.*)$ /conecta/`) e arquivar o repositório do METRICA360
   no GitHub. O `db_metrica360` fica só como cópia de segurança até o usuário decidir apagar. Os convites
   que o METRICA360 de teste enviou não migram: seus links deixam de funcionar.
8. **Avisar os servidores:** registro de atividades, envio de pesquisas e (para a coordenação) a Satisfação
   agora são feitos dentro do Conecta.

Conferência depois da implantação:
- [ ] Gestor/operador/coordenador/supervisor/coordenador geral veem "Registro de Atividades"; registrar, salvar rascunho e enviar um dia de teste
- [ ] Admin, gestor, coordenador, supervisor e coordenador geral veem o grupo "Atividades" (só gestão abona) e o CSV abre no Excel com acentos
- [ ] Admin, coordenador geral, supervisor e coordenador veem o grupo "Satisfação"; gestor e operador não
- [ ] Supervisor e coordenador geral não conseguem alterar nada, mas conseguem fazer a triagem de uma avaliação
- [ ] Um servidor com atendimento externo vê "Meu Painel", com indicadores e histórico; o convite enviado chega por e-mail e o link abre a página de avaliação
- [ ] Encerrar uma demanda de um solicitante externo gera o convite (log `pesquisa-satisfacao` e a fila de e-mail)
- [ ] Widget do Google Reviews carrega sem erro de CSP no console (Meu Painel)
- [ ] `php api/tests/matriz-de-acesso.php` (seções 7c a 7f, 9 e 10) limpo, se rodado em homologação

## Pendências técnicas conhecidas (não bloqueiam a implantação)

- **`firebase/php-jwt`**: alerta de segurança baixo (`CVE-2025-45769`, versões < 7.0.0; o projeto
  usa `^6.0`). Avaliar a atualização para a 7.x numa janela própria, testando a geração/validação
  de token depois.
- **P8 da auditoria** (padronizar o formato de resposta da API) e **P10** (SLA calculado só no
  backend, sem duplicar a conta no frontend) ficaram de fora desta rodada: são mudanças
  transversais (dezenas de controllers e o `status-sla.js` do frontend) que merecem uma fase própria,
  com tempo de teste dedicado, em vez de entrarem de carona numa fase de qualidade/implantação.
