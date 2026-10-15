# Auditoria do Conecta CRPRS e plano de correção

Data: 15/10/2026 · Base: commit `aee1077` · Escopo: `api/`, `frontend/`, `backend/`, `database/`, banco local `db_conecta`.

Método: leitura do código de todos os controllers, services, crons e dos pontos críticos do frontend, mais consultas ao banco em execução. Os achados marcados **(confirmado)** foram verificados no banco ou por execução. Os demais foram confirmados pela leitura do código, com o caminho exato indicado.

Severidade: **Crítica** (exploração externa ou perda de dados), **Alta** (quebra regra de negócio ou isolamento entre setores), **Média** (erro funcional ou risco limitado), **Baixa** (qualidade, manutenção).

---

## 1. Resumo

| Área | Crítica | Alta | Média | Baixa |
|---|---|---|---|---|
| Segurança | 5 | 8 | 7 | 2 |
| Regras de negócio e bugs | 1 | 5 | 9 | 3 |
| Banco de dados | – | 1 | 3 | 1 |
| Boas práticas | – | – | 4 | 6 |
| Experiência do usuário | – | 2 | 6 | 3 |
| Acessibilidade | – | 3 | 4 | 2 |

Os cinco problemas mais urgentes:

1. **XSS persistente a partir de qualquer e-mail externo.** O assunto do e-mail vira o título da demanda sem filtro, e o frontend insere títulos com `innerHTML`. Como o token fica no `localStorage` e não expira, basta um gestor ou admin abrir a lista para alguém de fora tomar a conta dele.
2. **Ambiente decidido pelo cabeçalho `Host`.** Em produção, uma requisição com `Host: localhost` passa a usar as credenciais e o `JWT_SECRET` locais, com debug ligado.
3. **Comentário em qualquer demanda por e-mail.** Qualquer remetente que escreva `#00012` no assunto, para a caixa de qualquer setor, comenta na demanda 12, reabre a demanda se estiver encerrada e dispara e-mails.
4. **Anexos e crons acessíveis por URL.** `backend/anexos/` é servida diretamente e ignora o controle de acesso. Os três scripts de `api/cron/` rodam via HTTP, um deles com uma chave fixa publicada no código e na documentação.
5. **Demandas listadas que não abrem.** O gestor vê demandas em "Transferidas para outros setores" e o operador vê demandas em "Repassadas por mim" e no Arquivo, mas recebe 403 ao abrir.

---

## 2. Achados

### 2.1 Segurança

| ID | Sev. | Onde | Problema | Correção |
|---|---|---|---|---|
| S1 | Crítica | `api/services/email-service.php:195,333`; `frontend/script.js:874,1030,1922,5032,5964,6108` | O assunto do e-mail é gravado em `tickets.titulo` sem filtro e o frontend o insere com `innerHTML`. Um e-mail com assunto `<img src=x onerror=…>` executa script no navegador de quem lista as demandas. | Escapar todo texto simples no frontend (`sanitizeHTML`) e gravar o título como texto puro (`strip_tags` e limite de tamanho) nas três origens: portal, e-mail e integração. |
| S2 | Crítica | `api/controllers/comentarios.php:83-85`; `integracoes/abrir_chamado.php:92-93`; `tickets.php:236-241` | O filtro de HTML é feito com regex. `<img>` é permitido e `on\w+=` só é removido entre aspas, então `<img src=x onerror=alert(1)>` passa. Também é possível contornar o filtro de `javascript:` com entidades. Os comentários são exibidos com `innerHTML` (`script.js:1815,2342`). | Criar `HtmlSanitizer` com HTML Purifier (via composer), com lista de tags e atributos permitidos, e usá-lo em descrição, comentário, macro e integração. No frontend, passar o HTML rico por DOMPurify antes de inseri-lo. |
| S3 | Crítica | `api/config/environment.php:15-21` | `isProduction()` decide pelo cabeçalho `HTTP_HOST`, que o cliente controla. Em produção, `Host: localhost` carrega `*_LOCAL` (banco, `JWT_SECRET`, debug). Pela linha de comando (cron), `HTTP_HOST` não existe e o cron de produção também roda como local. | Variável `APP_ENV=production\|local` no `.env`, com padrão **production** quando ausente. Remover a detecção por host. |
| S4 | Crítica | `api/services/email-service.php:221,672-683,685` | `extrairTicketId()` aceita `#NNNNN` de qualquer remetente, recebido em qualquer caixa. O e-mail vira comentário na demanda de outro setor, reabre demandas encerradas (`verificarEReabrirTicket`) e dispara e-mails ao solicitante. | Só aceitar o comentário se o remetente for o solicitante ou alguém já envolvido no fio (cópias anteriores) **e** se a caixa for do setor atual da demanda. Caso contrário, abrir uma demanda nova e registrar o motivo. |
| S5 | Crítica | `backend/anexos/` (sem `.htaccess`); `email-service.php:609` | Os anexos podem ser baixados por URL direta, sem passar pelo `anexos.php`. Os nomes dos anexos de e-mail são previsíveis (`time()_email_<nome original>`), o que permite baixar documentos de qualquer setor. | Colocar `Require all denied` em `backend/` (exceto `uploads/avatars`), gerar o nome com `bin2hex(random_bytes(16))` e aplicar `basename()` ao nome original. |
| S6 | Alta | `api/cron/verificar-emails.php:5`; `verificar-emails-perdidos.php`; `verificar-lembretes.php`; `frontend/script.js:89-91` | Os crons rodam via HTTP. A chave fixa (removida na Fase 8) estava no código e em `docs/relatorios/RELATORIO_EMAIL_DUPLA_VERIFICACAO.md:164`, junto com a URL de produção. Os outros dois não têm proteção. A saída mostra remetentes e assuntos. O navegador de cada usuário chama `verificar-lembretes.php` a cada minuto. | Linha de comando livre; HTTP só com `CRON_KEY_*` do `.env` (o cron-job.org continua chamando por URL). Remover o "ping" de lembretes do navegador (**D4**) e trocar a chave no cron-job.org. |
| S7 | Alta | `api/controllers/lembretes.php:19-108` | Sem regra de acesso. Qualquer usuário logado lista os lembretes de qualquer demanda, lista **todos** os lembretes pendentes de todos os setores (com títulos), cria lembrete em qualquer demanda e apaga qualquer lembrete pelo id. Também não valida CSRF nem os dados de entrada, e devolve a mensagem da exceção. | Usar `AcessoService::exigirVerTicket` na listagem por demanda e `exigirOperarTicket` na criação. Na exclusão, permitir só ao autor, ao gestor do setor ou ao admin. A lista geral deve trazer só os lembretes do usuário. Validar data, hora e responsável (do setor da demanda). |
| S8 | Alta | `api/controllers/avatar.php:59-60` | A extensão do arquivo salvo vem do nome enviado. O MIME é validado, mas uma imagem válida pode se chamar `.phtml` ou `.phar`, e o `.htaccess` da pasta só bloqueia `.php`. | Derivar a extensão do MIME (`jpg`/`png`/`gif`) e bloquear qualquer script na pasta. |
| S9 | Alta | `api/controllers/config-email.php:29-70` | O PUT **reescreve o `.env` inteiro** a partir do navegador (perde comentários, grava senhas em texto puro e permite injetar linhas). Além disso, grava chaves em minúsculas (`imap_host`), que `Environment::get` nunca lê: **o salvamento não tem efeito** (confirmado pela leitura de `environment.php:55-64`). | Ver **decisão D5**. Recomendação: a tela global fica somente leitura e a configuração do remetente do sistema continua no `.env`. |
| S10 | Alta | `api/controllers/logout.php`; `middleware/auth.php:299-309` | O JWT não expira e o logout não o invalida. Trocar ou redefinir a senha também não derruba sessões antigas. Um token vazado (por XSS ou log) vale para sempre. | Coluna `usuarios.token_versao`, gravada no JWT e conferida em `sincronizarComBanco()`. Incrementar no logout ("sair de todos os dispositivos"), na troca e na redefinição de senha. Mantém a sessão sem expiração, que foi uma decisão sua. |
| S11 | Alta | `api/controllers/recuperar-senha.php:35-39`; `redefinir-senha.php:42` | A resposta 404 confirma se o e-mail existe. Não há limite de tentativas (dá para encher a caixa de alguém). A senha nova não segue a regra (`SenhaService`) e usa `PASSWORD_DEFAULT`. O token fica gravado em texto puro. | Mesma resposta para e-mail existente ou não, `RateLimitService` por IP e e-mail, `SenhaService::validar/hash`, guardar `hash('sha256', $token)` e invalidar sessões (S10). |
| S12 | Alta | `email-service.php:646-670`; `auth.php` | Cada remetente externo vira usuário `cliente`. Pelo "Esqueci a senha", qualquer pessoa que já mandou e-mail define uma senha e **entra no sistema**. | Ver **decisão D1**. Recomendação: bloquear o login de `cliente`. |
| S13 | Alta | `api/controllers/auth.php:142-143`; `tickets.php:220,414,835,892`; `comentarios.php:19,380`; `usuarios.php:245,397`; `lembretes.php:111`; `redefinir-senha.php:59`; `macros.php:26` | A mensagem da exceção vai para o cliente. No login há um "[DEBUG TEMP]" que expõe erros de SQL e de conexão. | Helper `Resposta::erro($codigo, $mensagemPublica, $e)`: registra no log o detalhe e devolve ao cliente só a mensagem pública. |
| S14 | Média | `email-sender.php:52-58`; `email-service.php:80` | SMTP com `verify_peer=false` e IMAP tentando primeiro `novalidate-cert`, o que permite interceptar as credenciais das caixas. | Validar o certificado por padrão. Desligar a validação só com uma configuração explícita por caixa (`setor_emails.validar_certificado`). |
| S15 | Média | `api/composer.phar`, `api/composer-setup.php` versionados e servidos; `.htaccess` | Instalador do Composer acessível pela web. `.git/`, `composer.json` e `composer.lock` não estão bloqueados (contrariando a SEGURANCA_SPEC). | Tirar os dois arquivos do repositório e bloquear `\.(git\|json\|lock\|phar\|md)` e `vendor/`. |
| S16 | Média | `frontend/index.html:41,930` | `alpinejs@3.x.x` sem versão fixa e sem SRI (*Subresource Integrity*, o hash que confere o arquivo baixado da CDN). Quill 1.3.6 tem histórico de XSS. A CSP exige `unsafe-inline` e `unsafe-eval` por causa de 188 `onclick` inline. | Fixar as versões com SRI, avaliar o Quill 2.x e, no médio prazo, trocar `onclick` por `addEventListener` para retirar `unsafe-inline`. |
| S17 | Média | `api/controllers/auth.php:74-78,93` | O usuário inativo recebe 403 **antes** da conferência da senha, o que revela que a conta existe. Ainda há comparação de senha em texto puro (legado). | Conferir a senha primeiro e responder com a mesma mensagem. Remover a comparação em texto puro (**decisão D3**). |
| S18 | Média | `email-sender.php:173-175`, `183`; `cron/verificar-lembretes.php:33-35` | Título, nomes de anexo e descrição do lembrete são inseridos sem escape no HTML dos e-mails (injeção de HTML ou phishing no e-mail do solicitante). | `htmlspecialchars()` em toda interpolação nos templates. |
| S19 | Média | `tickets.php:230,244`; `comentarios.php:73,79` | `email_solicitante` e `com_copia` não são validados: dá para usar o SMTP do setor para mandar e-mail a qualquer endereço. | `filter_var(FILTER_VALIDATE_EMAIL)`, limite de destinatários e registro em auditoria. |
| S20 | Média | `api/controllers/notificacoes-stream.php` | Endpoint SSE sem uso (o frontend usa polling em `notificacoes.js:19`) que recebe o token na URL (fica no log do servidor) e mantém um processo PHP preso em laço infinito. | Remover (**decisão D2**). |
| S21 | Baixa | `middleware/auth.php:63-69` | Para origens não permitidas devolve `Access-Control-Allow-Origin: null` com `Allow-Credentials: true`, o que libera iframes com sandbox (origem `null`). | Não enviar o cabeçalho quando a origem não estiver na lista. |
| S22 | Baixa | `auth.php`, `avatar.php:108-111`, `logout.php` | `session_start()` sobrando numa aplicação sem estado (sessão nunca lida). | Remover. |

### 2.2 Regras de negócio e bugs

| ID | Sev. | Onde | Problema | Correção |
|---|---|---|---|---|
| N1 | Crítica | `email-service.php:233,279-407` | `criarTicketPorEmail()` **não retorna o id**, então `emails_processados.ticket_id` fica sempre nulo. A função engole as próprias exceções, e o e-mail é marcado como lido e processado mesmo quando a demanda **não foi criada**: o e-mail se perde em silêncio. | Retornar o id, relançar as exceções, marcar como lido e processado só depois de criar, e registrar em `emails_erro` para nova tentativa. |
| N2 | Alta | `acesso-service.php:17-31` × `tickets.php:123-179` | As listas "Transferidas" (gestor), "Repassadas por mim" e o Arquivo do operador mostram demandas que `podeVerTicket()` recusa. O usuário clica e recebe erro. | Criar `podeVerHistorico()`: quem passou pela demanda (`ticket_setor_log` / `ticket_agente_log`) pode **ler** a demanda. A tela abre em modo somente leitura, com o aviso "Esta demanda está hoje no setor X". |
| N3 | Alta | `email-service.php:200` | A lista de remetentes ignorados só tem `suporte@` e `suporte2@`. As caixas dos setores e o remetente global não entram, e respostas automáticas ("ausente do escritório") não são detectadas. Um setor que escreve para outro gera **laço de e-mails** e demandas em cascata. | Ignorar todo endereço de `setor_emails` e `FROM_EMAIL`, além dos cabeçalhos `Auto-Submitted`, `X-Autoreply` e `Precedence: bulk/auto_reply`. |
| N4 | Alta | `tickets.php:260,475-492` | A ativação de status e prioridades por setor (`setor_status`/`setor_prioridades`) não vale no servidor. O PUT valida só contra o catálogo global, e o POST usa uma lista fixa de prioridades (`baixa,normal,alta,urgente`). | Validar contra a ativação do setor da demanda, nos dois métodos. |
| N5 | Alta | Banco `tickets.status` (ENUM) × `ticket_status` | O status é um ENUM fixo com 15 valores e `ticket_status` é um catálogo editável. Um status novo cadastrado no catálogo **falha ao ser gravado** na demanda (confirmado no banco). | Nova migração: `tickets.status VARCHAR(50)` com índice. A validação passa a ser pelo catálogo, como já acontece com prioridade. |
| N6 | Alta | `usuarios.php:193-308` | Mudar o setor ou desativar um operador não trata as demandas dele. Ele continua responsável, e um operador movido continua **operando demandas do setor antigo**. | Ao desativar ou mudar de setor, devolver as demandas abertas ao gestor do setor (com registro no histórico) ou bloquear até que sejam redistribuídas. |
| N7 | Média | `tickets.php:815` | A notificação de reabertura só dispara para `reaberto/aberto/atendimento` vindo de `encerrado/fechado`. `em_atendimento` não está na lista e `cancelado` nunca é considerado. | Usar `AcessoService::STATUS_ARQUIVO` e qualquer status que não seja de arquivo. |
| N8 | Média | `comentarios.php:326-347,360-362` | Se o e-mail ao solicitante falha, o comentário responde `success: true` e o usuário acha que o e-mail saiu. O histórico registra o e-mail do **usuário** dono da demanda, não o `email_solicitante`. | Devolver `email_enviado: false` com aviso na tela e registrar o destinatário real. |
| N9 | Média | `ticket-status.php:48-144` | Segundo caminho para trocar o status, sem histórico, sem janelas de setor, sem pausa de SLA, sem notificação e com SLA por relógio comum. O frontend não usa. | Remover o PUT (**decisão D2**). O GET continua. |
| N10 | Média | `tickets.php:578-824` | O PUT faz UPDATE, SLA, histórico, janelas e auditoria sem transação, e erros no histórico são engolidos (`catch` na linha 799). Falhas parciais deixam a demanda alterada sem registro. | Tudo numa transação. Notificações e e-mails depois do `commit`. |
| N11 | Média | `tickets.php:883-886`; banco sem FKs | Excluir uma demanda deixa órfãos em comentários, anexos (linhas e arquivos), histórico, SLA, lembretes e não lidos: não há chaves estrangeiras nessas tabelas (confirmado no banco). | Migração com FKs `ON DELETE CASCADE` e exclusão física dos arquivos. Ver também a **decisão D7**. |
| N12 | Média | `usuarios.php:97,252,229` | `tipo` não é validado (vazio ou inválido gera 500 ou trava a conta) e o e-mail não é validado. O admin pode rebaixar ou desativar a si mesmo ou o último admin. | Lista fechada de papéis, `FILTER_VALIDATE_EMAIL` e proteção do último admin ativo. |
| N13 | Média | `cron/verificar-emails-perdidos.php` | Varre só a caixa global do `.env`, que ficou obsoleta com as caixas por setor. Usa caminhos relativos (`'../config'`), que quebram no cron. | Reescrever sobre `EmailService` por caixa, ou remover (**decisão D2**). |
| N14 | Média | `cron/verificar-lembretes.php` | Sem trava: duas execuções simultâneas (vários navegadores chamando) enviam o mesmo lembrete duas vezes. | `UPDATE … SET notificado = 1 WHERE id = ? AND notificado = 0` antes de enviar (reserva atômica) e execução só por cron. |
| N15 | Média | Fuso horário: `email-sender.php:2`, `email-service.php:3` vs. demais | Só dois arquivos fixam `America/Sao_Paulo`. O resto usa o fuso do `php.ini` e o MySQL usa `SYSTEM`. O SLA mistura `NOW()` (MySQL) com `date()` (PHP). No banco de produção (provavelmente em UTC) os tempos ficariam 3 h errados. | Fixar o fuso em `environment.php` e executar `SET time_zone = '-03:00'` na conexão (`database.php`). |
| N16 | Baixa | `comentarios.php:83`; `script.js:1665` | O editor oferece "bloco de código" (`<pre>`), mas o filtro do servidor remove a tag e a formatação some ao salvar. | Incluir `<pre>`/`<code>` no sanitizador (S2) ou tirar o botão. |
| N17 | Baixa | `email-service.php:190,695` | Truncamento com `substr` corta caracteres UTF-8 ao meio. | `mb_substr`. |
| N18 | Baixa | `email-service.php:842-877` | `enviarConfirmacao()` é código morto que usa `mail()` e constantes nulas. | Remover (**decisão D2**). |

### 2.3 Banco de dados

| ID | Sev. | Problema | Correção |
|---|---|---|---|
| B1 | Alta | Faltam FKs em `tickets` (usuario, agente, categoria, fechado_por), `comentarios`, `anexos`, `ticket_historico`, `ticket_sla`, `lembretes` e `comentarios_nao_lidos` (confirmado). | Nova migração com FKs (CASCADE nas tabelas dependentes da demanda e RESTRICT em usuários). |
| B2 | Média | As migrações 10 a 13 criaram tabelas em `utf8mb3_general_ci` (`setor_*`, `ticket_setor_log`, `ticket_agente_log`), misturadas com `utf8mb4_0900_ai_ci` e `utf8mb4_unicode_ci` (confirmado). Há risco de erro "Illegal mix of collations" em JOINs de texto, e emojis não cabem. | Converter tudo para `utf8mb4_0900_ai_ci`. |
| B3 | Média | Não há registro de quais migrações já rodaram. Como o MySQL 8.4 não tem `IF NOT EXISTS` em colunas, reaplicar quebra. | Tabela `migracoes` e `database/migrar.php`, que aplica em ordem só as pendentes. Essencial para a implantação em produção. |
| B4 | Média | As tabelas legadas `historico`, `ticket_historico_status` e `ticket_prioridade_sla` estão vazias e só aparecem em subconsultas por linha da listagem (`tickets.php:42-43,111-112`). | Migrar o "merge" para `ticket_historico` e remover as subconsultas. As tabelas só saem com sua aprovação (**D2**). |
| B5 | Baixa | Índice duplicado em `comentarios.ticket_id` (`ticket_id` e `idx_comentarios_ticket`). | Remover um. |

### 2.4 Boas práticas e manutenção

| ID | Sev. | Problema | Correção |
|---|---|---|---|
| P1 | Média | `frontend/script.js` tem 9.816 linhas, 79 `XMLHttpRequest` repetidos, 266 estilos inline e **4 funções definidas duas vezes** (`aplicarFiltroMeusTicketsFechados`, `calcularTextoSLA`, `limparBuscaFechados`, `mostrarRelatorios`): a segunda sobrescreve a primeira em silêncio. | Criar `api.js` (cliente HTTP único: cabeçalhos, 401, erros e *toast*) e dividir por domínio (`demandas.js`, `configuracoes.js`, `usuarios.js`…), sem framework. Remover as duplicatas. |
| P2 | Média | A listagem não tem paginação no servidor: devolve **todas** as demandas com 5 subconsultas por linha. Busca e filtros ficam no navegador. | `?pagina=&por_pagina=&busca=&status=` no `tickets.php` e `COUNT(*)` para o total. |
| P3 | Média | E-mails são enviados dentro da requisição. Criar uma demanda envia ao solicitante e a todos os gestores antes de responder, e o SMTP lento trava a tela. | Tabela `email_fila`, gravada na requisição e enviada pelo cron a cada minuto. |
| P4 | Média | Não há testes automatizados. As matrizes de acesso usadas nas fases 1 a 7 foram manuais. | `tests/` com PHPUnit para `AcessoService`, `SenhaService` e `SLAService`, mais um script de matriz de acesso por papel na API, rodado antes de cada implantação. |
| P5 | Baixa | `echo "<p>…"` dentro dos services (`email-service.php` inteiro), logs `[DEBUG]` em `tickets.php` (o corpo do PUT vai para o log) e `error_reporting(0)` em 3 controllers. | Logger único com nível (`Log::info/erro`) e sem dados pessoais. Os services não imprimem nada. |
| P6 | Baixa | Status finais escritos à mão em 5 lugares (`comentarios.php:270`, `email-service.php:773,796`, `tickets.php:544,645,815`), em vez de `AcessoService::STATUS_ARQUIVO`. | Centralizar em uma classe `StatusDemanda`. |
| P7 | Baixa | A documentação diverge do código: o CLAUDE.md e a tela "Novidades" (`script.js:7272-7281`) dizem que as notificações são SSE, mas são polling. `config-email.php` lê chaves que não existem (`imap_user`). | Atualizar junto com as correções. |
| P8 | Baixa | O padrão de resposta varia (`error`/`message`/`success`, 500 para erro de validação em `ticket-status.php:73`). | Padronizar `{ success, data }` / `{ error }` com o código HTTP correto. |
| P9 | Baixa | `email-alert.php` conta as falhas de e-mail de **todos** os setores para qualquer operador. | Filtrar pelo setor (e-mails das demandas do setor) e mostrar só a gestor e admin. |
| P10 | Baixa | O SLA é calculado em dois lugares: `sla-service.php` e `status-sla.js`. | O backend devolve o SLA pronto na listagem e o frontend só exibe. |

### 2.5 Experiência do usuário

| ID | Sev. | Problema | Melhoria |
|---|---|---|---|
| U1 | Alta | N2 visto pelo usuário: abas que listam demandas que não abrem. | Detalhe em modo somente leitura, com faixa "Transferida para Cobrança em 12/10 por Fulano". |
| U2 | Alta | Não há tratamento global de 401. Um usuário desativado ou com token inválido fica com telas quebradas em vez de voltar ao login (só `notificacoes.js:46` trata). | O cliente HTTP único (P1) redireciona ao login com a mensagem "Sua sessão foi encerrada". |
| U3 | Média | Remetentes externos aparecem na lista de usuários como "cliente", com o nome tirado do e-mail (`fulano.silva`), ignorando o nome de exibição do remetente (`$header->from[0]->personal`). | Gravar o nome de exibição e separar a aba "Solicitantes" da aba "Equipe". |
| U4 | Média | Falta redefinição de senha pelo admin ou gestor e a troca obrigatória no primeiro acesso. Hoje a senha inicial fica para sempre. | Botão "Redefinir senha" na lista de usuários e `usuarios.trocar_senha = 1` obrigando a troca no primeiro login. |
| U5 | Média | Envio de comentário e e-mail sem estado de "enviando" e sem confirmação real do e-mail (N8). | Botão desabilitado com indicador e mensagem "Comentário salvo, mas o e-mail não foi enviado" quando falhar. |
| U6 | Média | Desativar um operador não avisa quantas demandas abertas ele tem (N6). | Confirmação: "Fulano tem 7 demandas abertas: devolvê-las ao gestor?". |
| U7 | Média | Os textos das prioridades ainda descrevem situações de TI (pendência já conhecida). As notificações internas ainda dizem "Ticket Atribuído" e "O ticket…" (`tickets.php:790,793`), e o Arquivo diz "Erro ao carregar tickets fechados" (`script.js:4884`). | Revisão final de vocabulário. |
| U8 | Média | Lista lenta com muitas demandas (P2) e busca só na página carregada. | Paginação e busca no servidor, com contadores por aba. |
| U9 | Baixa | O formulário de comentário não diz claramente quando a resposta vai por e-mail ao solicitante e quando é nota interna. | Seleção explícita "Responder ao solicitante" ou "Nota interna", com cor diferente na linha do tempo. |
| U10 | Baixa | O alerta de falha de e-mail (`email-alert`) aparece para o operador, que não pode agir. | Mostrar só a quem configura (P9). |
| U11 | Baixa | A tela "Novidades" mostra detalhes técnicos internos (nomes de arquivos PHP) aos usuários. | Reescrever para o usuário final ou mostrar só ao admin. |

### 2.6 Acessibilidade (WCAG 2.2, conforme `.agent/rules/ACESSIBILIDADE_SPEC.md`)

| ID | Sev. | Problema (medido em `index.html` + `script.js`) | Correção |
|---|---|---|---|
| A1 | Alta | Só 4 dos ~30 modais têm `role="dialog"`/`aria-modal`/`aria-labelledby`. Não há foco preso dentro do modal nem retorno do foco ao fechar. | Função única `abrirModal(el)`/`fecharModal()` que aplica os atributos, prende o foco, fecha com ESC e devolve o foco a quem abriu. |
| A2 | Alta | 52 `div`/`span`/`tr`/`li` com `onclick`: não recebem foco pelo teclado e não são anunciados como botões. | Trocar por `<button>`, ou incluir `role="button"`, `tabindex="0"` e Enter/Espaço. |
| A3 | Alta | Não existe nenhuma região `aria-live`: *toasts*, "demanda salva" e erros não são anunciados por leitores de tela. | Container de *toasts* com `role="status" aria-live="polite"` e erros com `role="alert"`. |
| A4 | Média | Fonte base Arial e Segoe UI (`style.css:17,721`), contra a regra que exige **Inter**. | Carregar Inter (Google Fonts) e aplicar em `body`. |
| A5 | Média | Não há `<header>` semântico nem link "Pular para o conteúdo". | Envolver a marca e o setor em `<header>` e incluir *skip link* para `<main>`. |
| A6 | Média | 7 tabelas sem `<caption>`; `scope="col"` em apenas 9 colunas. | `<caption class="sr-only">` e `<th scope="col">` em todas. |
| A7 | Média | 7 gráficos `<canvas>` sem alternativa textual. | `role="img"` e `aria-label` com o resumo, e tabela de dados opcional. |
| A8 | Baixa | 266 estilos inline com cores fixas (ex.: `#555`, `#888`) ignoram o modo escuro e podem falhar no contraste de 4,5:1. | Migrar para as variáveis de `modern-enhancements.css` (acompanha P1). |
| A9 | Baixa | 7 ocorrências de `outline: none/0` no CSS. | Conferir se cada uma tem substituto visível com `:focus-visible`. |

---

## 3. Decisões que dependem de você

Pela regra "não remover função sem perguntar", estes pontos precisam da sua resposta antes da execução:

| # | Pergunta | Recomendação |
|---|---|---|
| D1 | Solicitantes externos (papel `cliente`, criados automaticamente por e-mail) devem conseguir entrar no sistema? | **Não.** Bloquear o login e o "Esqueci a senha" para `cliente`. O solicitante acompanha só por e-mail. |
| D2 | Posso remover o código morto ou paralelo: `ticket-status.php` (só o PUT), `ticket-detalhes.php` (sem uso), `notificacoes-stream.php` (SSE sem uso), `enviarConfirmacao()`, `verificar-emails-perdidos.php` (ou reescrevê-lo por setor), e as tabelas legadas vazias `historico`, `ticket_historico_status` e `ticket_prioridade_sla`? | Remover, exceto o script de e-mails perdidos, que vale reescrever por setor. |
| D3 | Remover o login com senha legada em texto puro (`auth.php:93`)? O banco foi recriado e não há senhas nesse formato. | Remover. |
| D4 | Os lembretes passam a ser disparados só pelo cron do servidor (fim da chamada a cada minuto pelo navegador)? | Sim. Exige agendar o cron em produção. |
| D5 | A tela "Configurar e-mail → Remetente do sistema (global)" hoje não salva de verdade e reescreve o `.env`. Ela deve ficar somente leitura, ou o remetente global deve ir para o banco cifrado, como as caixas dos setores? | Somente leitura, com a configuração no `.env`. |
| D6 | As macros (respostas prontas) são globais. Devem passar a ser por setor? | Por setor, com opção "visível a todos os setores" para o admin. |
| D7 | A exclusão de demanda (admin) deve continuar apagando de vez, ou passar a ser só cancelamento com motivo? | Manter a exclusão física, com FKs em cascata e remoção dos arquivos (N11). |

---

## 4. Plano de execução

Cada fase termina com o sistema funcionando, um commit próprio, testes de API por papel e um teste rápido no navegador (admin, gestor e operador), como nas fases 1 a 7.

### Fase 8: segurança crítica (antes de qualquer implantação)
1. **S3**: `APP_ENV` no `.env` e no `.env.example`, com padrão production.
2. **S1 e S2**: HTML Purifier (composer) em `api/services/html-sanitizer.php`, aplicado em `tickets.php`, `comentarios.php`, `macros.php`, `integracoes/abrir_chamado.php` e `email-service.php`. Títulos como texto puro. No frontend, `sanitizeHTML` nos títulos e nomes e DOMPurify (versão fixa e SRI) em descrição e comentários.
3. **S5**: `backend/.htaccess` (deny, exceto avatares), nomes aleatórios e `basename()` nos anexos de e-mail.
4. **S6**: `api/cron/.htaccess` deny, guarda de CLI, remoção da chave fixa e da menção na documentação.
5. **S7**: `lembretes.php` com `AcessoService`, CSRF e validação.
6. **S8**: extensão do avatar derivada do MIME.
7. **S13**: helper de erro e remoção do "[DEBUG TEMP]" e de todas as mensagens de exceção enviadas ao cliente.
8. **S4 e N3**: regras de vínculo do comentário por e-mail e a lista de remetentes ignorados (mais respostas automáticas).
9. **S15**: tirar `composer.phar`/`composer-setup.php` e bloquear arquivos sensíveis no `.htaccess`.

**Teste:** e-mail simulado com assunto e corpo maliciosos, inseridos direto pelo `EmailService`, exibidos sem executar; download direto de `backend/anexos/x` com 403; cron via HTTP com 403; matriz de lembretes por papel.

### Fase 9: sessão, senha e contas
1. **S10**: `usuarios.token_versao` (migração 19), conferida no middleware e incrementada no logout, na troca e na redefinição de senha.
2. **S11, S12, S17**: recuperação de senha sem enumeração, com limite de tentativas e `SenhaService`; bloqueio de `cliente` (D1); login sem a senha legada (D3).
3. **N12, U4**: validação de papel e e-mail, proteção do último admin, "Redefinir senha" pelo admin e gestor, troca obrigatória no primeiro acesso.
4. **N6, U6**: ao desativar ou mover um operador, devolver as demandas ao gestor.

### Fase 10: regras de negócio e integridade de dados
1. **N1**: `criarTicketPorEmail` com retorno e exceções reais; e-mail marcado só depois de criar a demanda.
2. **N2, U1**: `podeVerHistorico()` e detalhe somente leitura.
3. **N4, N5**: validação por setor; `tickets.status` como VARCHAR (migração).
4. **N7, N8, N10**: reabertura, retorno real do e-mail e transação no PUT.
5. **N11, B1, B2, B3**: FKs, conversão para `utf8mb4` e `database/migrar.php` com a tabela `migracoes`.
6. **N13, N14, N15**: crons por setor com reserva atômica e fuso único.
7. **N9, N18, S20, B4**: remoções conforme D2.

### Fase 11: e-mail confiável e desempenho
1. **P3**: fila de e-mails (`email_fila`) com envio pelo cron.
2. **S14**: validação de certificado configurável por caixa.
3. **S18, S19**: escape nos templates e validação de destinatários.
4. **P2, U8**: paginação, busca e contadores no servidor.

### Fase 12: frontend (estrutura, UX e acessibilidade)
1. **P1, U2**: `api.js` (cliente HTTP único com 401 global), divisão do `script.js` e remoção das funções duplicadas.
2. **A1-A3**: modais acessíveis, elementos clicáveis com teclado e regiões `aria-live`.
3. **A4-A9**: Inter, `<header>` e *skip link*, tabelas, gráficos, contraste e foco.
4. **U3, U5, U7, U9-U11**: aba Solicitantes, estados de envio, vocabulário e prioridades, resposta × nota interna.
5. **S16**: versões fixas com SRI e início da retirada de `onclick` inline.

### Fase 13: qualidade contínua
1. **P4**: PHPUnit e script de matriz de acesso.
2. **P5-P10**: logger, constantes de status, padrão de resposta e documentação (CLAUDE.md e a tela Novidades).
3. Roteiro de implantação em produção: `migrar.php` com backup, `APP_ENV`, chaves (`SETOR_EMAIL_ENCRYPTION_KEY_PROD`, `INTEGRATION_API_KEY_PROD`), `base_url` e host do banco de produção (ainda `crpdesk` em `environment.php:117-131`) e agendamento dos crons.

**Ordem recomendada:** 8 → 9 → 10 são pré-requisito para a produção. 11 a 13 podem ser intercaladas conforme a prioridade de uso.
