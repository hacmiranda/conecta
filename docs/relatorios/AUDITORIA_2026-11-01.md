# Auditoria do sistema: Conecta CRPRS (01/11/2026)

Auditoria de segurança, bugs, inconsistências, desempenho, frontend e manutenção, com plano de correção. Feita sobre o `main` em `1ede200`. **Só leitura**: nenhum código, configuração ou dado foi alterado (o único arquivo criado no projeto é este relatório).

---

## 1. Resumo executivo

| Severidade | Quantidade |
|---|---|
| Crítica | 0 |
| Alta | 3 |
| Média | 17 |
| Baixa | 45 |
| **Total** | **65** |

Nenhuma falha permite acesso sem login nem leitura de dados de outro setor: as regras do `AcessoService`, o CSRF e a sessão em cookie HttpOnly estão bem aplicadas em todos os controllers revisados. Os 181 testes passam. Os problemas mais graves estão em **confiabilidade operacional** (cron de e-mail) e em **arquitetura de segurança** (onde a aplicação está hospedada).

**Os mais urgentes:**

1. **BUG-08 (Alta).** Basta um erro do tipo `Error` (por exemplo `TypeError` ou falta de memória) ao ler **uma** mensagem para derrubar a cadeia inteira do cron: leitura das caixas, lembretes, fila de envio e pesquisas. O motivo é que só `Exception` é capturada. A mensagem problemática fica na janela de 3 dias e derruba todas as execuções seguintes.
2. **DES-01 (Alta).** A leitura das caixas refaz cerca de 3 comandos IMAP para cada mensagem **já processada**, em toda execução: cerca de 1,15 s por mensagem (o log local mostra 30 mensagens em 35 s). Com o volume real de um setor, cada execução passa dos 2 minutos do intervalo do cron.
3. **SEG-01 (Alta).** O Conecta divide a mesma origem (`https://crp07.org.br`) com o METRICA360 e com o site. Um XSS em qualquer um deles pode agir como o usuário no Conecta e ler as respostas da API e o token CSRF do `localStorage`. O cookie HttpOnly só impede que o token seja roubado.
4. **BUG-01 (Média, confirmado com teste).** `HtmlSanitizer::texto()` usa `strip_tags` e **apaga em silêncio** o texto depois de um `<`: o título "valor <100 reais" vira "valor " e "5<10 e 3>2" vira "52". Isso afeta assuntos de e-mail, títulos, nomes e lembretes.
5. **SEG-03 / BUG-02 / BUG-04 (Média).**
   - O link de redefinição de senha fica em texto puro em `email_fila.corpo` por 30 dias.
   - O tempo de resolução sai maior que o real quando a demanda é resolvida direto de um status que pausa o SLA.
   - O registro de auditoria do cancelamento e da exclusão definitiva some quando a descrição tem imagem: passa de 64 KB e o erro do banco é engolido.

**Antes de replicar o banco local na produção (INC-01):** o setor 2 (Cadastro) está com os 15 e-mails desligados, a tabela `feriados` está vazia em todos os setores e há 24 demandas de teste.

---

## 2. Metodologia e escopo

**Lido e analisado:**
- Backend:
  - `api/middleware/auth.php` e `api/config/*`.
  - Todos os 41 controllers de `api/controllers/` (inclusive `integracoes/abrir_chamado.php`), com leitura integral dos críticos: `tickets`, `comentarios`, `demanda-acoes`, `merge`, `usuarios`, `auth`, `auth-sso`, `recuperar/redefinir/alterar-senha`, `anexos`, `avatar`, `macros`, `lembretes`, `notificacoes`, `dashboard`, `relatorios`, `setor-emails`, `meu-painel`, `personalizacao`. Nos demais, conferência das verificações de acesso e CSRF.
  - Services: `acesso`, `demanda`, `sla`, `email-service`, `email-sender`, `email-fila`, `email-mime`, `upload`, `crypto`, `rate-limit`, `rede`, `log`, `html-sanitizer`, `timeline`, `protocolo`, `lembrete`, `catalogo`, `auditoria` e `metrica360-client`. Leitura dirigida em `atividades-acompanhamento` e `prazo-atividade`.
  - Os 6 crons e o `.htaccess` de todas as pastas.
- Frontend: `index.html`, `login.html`, `script.js`, `api.js`, `app-config.js`, `sso-bridge.js`, `detalhe-demanda.js`, `demandas.js`, `ativacao-setor.js`, `meu-painel.js`, `notificacoes.js`, `status-sla.js`, `relatorios.js` e `auditoria.js`.
  - Varredura automática das 842 interpolações `${...}` sem escape aparente; revisão manual das telas de demanda.
  - Busca de funções e variáveis globais duplicadas e de handlers `onclick` sem função correspondente (nenhum encontrado).
- Banco local (`db_conecta`, MySQL 8.4.10), só leitura: esquema completo (`mysqldump --no-data`), tabela `migracoes` (9 a 38 aplicadas), `sql_mode`, contagens, catálogos e `EXPLAIN` das consultas de lista, busca, notificações e logs.
- `backend/logs/email-cron.log`: 32 MB e 392 mil linhas, usadas para medir o cron.

**Executado:**
- `php vendor/bin/phpunit`: **OK, 181 testes, 444 asserções** (os testes de integração desfazem a transação e apagam os anexos que gravam).
- `php -l` em todo o PHP fora do `vendor` e `node --check` em todo o JS do frontend: sem erros de sintaxe.
- `composer outdated --direct`: PHPMailer 6.10.0, com a 7.1.1 disponível. `composer audit`: nenhum alerta.
- Teste do comportamento de `strip_tags` (BUG-01) no PHP 8.2.12 local.

**Fora do escopo ou não executado:**
- O Apache local estava parado, então não houve conferência por HTTP (cabeçalhos reais, bloqueios do `.htaccess`) nem execução de `api/tests/matriz-de-acesso.php`, que precisa da API no ar. Também não houve teste em navegador.
- O banco local tem poucos dados (24 demandas), então os `EXPLAIN` indicam o plano, não o tempo real em escala.
- Ficaram de fora: configuração do servidor de produção (`php.ini`, `memory_limit`, Apache e PHP-FPM), repositório do METRICA360, teste de carga e auditoria visual tela a tela.

**Convenções:**
- **Certeza:** confirmado (por código, consulta ou teste), provável ou hipótese.
- **Esforço:** P (até meio dia), M (1 a 3 dias), G (mais de 3 dias ou depende de infraestrutura).
- Todo local está no formato `arquivo:linha`.

---

## 3. Achados

### 3.1 Segurança

**SEG-01: mesma origem dos outros sistemas do CRPRS**
- Severidade **Alta**. Certeza: confirmado (por projeto). Esforço G (infraestrutura).
- Local:
  - `api/config/environment.php:138` (`base_url` `https://crp07.org.br/conecta`)
  - `api/config/.env.example:96` (METRICA360 em `https://crp07.org.br/metrica360`)
  - `api/middleware/auth.php:124-143` (cookie com `Path=/conecta`)
  - `frontend/script.js:38` (`cd_csrf_token` no `localStorage`)
- Descrição: a política de mesma origem do navegador vale por esquema, host e porta, não por caminho. Um script injetado em `crp07.org.br/metrica360` ou no site institucional é da mesma origem do Conecta. Ele pode:
  - chamar `fetch('/conecta/api/...')`, com o cookie de sessão enviado sozinho (mesma origem e mesmo site);
  - ler as respostas da API;
  - ler `localStorage.cd_csrf_token`, já que o `localStorage` é um só por origem.

  O `CLAUDE.md` (seção *Authentication*) afirma que um XSS em outro sistema "não consegue roubar" a sessão. Isso vale para o token, mas não impede **agir como o usuário**.
- Impacto: a segurança do Conecta (dados pessoais de demandas, e-mails, anexos) passa a depender da segurança de todos os sistemas do mesmo domínio, inclusive de um WordPress ou de plugins do site.
- Correção: publicar em subdomínio próprio (ex.: `conecta.crp07.org.br`), com cookie `__Host-conecta_sessao` (sem `Path` e sem `Domain`). Isso isola o `localStorage` e o cookie. `base_url`, CORS, `rootBase`/`apiBase` em `app-config.js` e o roteiro de implantação mudam junto.

**SEG-02: script de terceiro (Elfsight) roda dentro da aplicação autenticada**
- Severidade **Média**. Certeza: confirmado. Esforço M.
- Local: `frontend/meu-painel.js:7,242-243` (cria `<script src="https://static.elfsight.com/platform/platform.js">` no documento principal) e `.htaccess:51,53` (`script-src https://*.elfsight.com` para a aplicação inteira).
- Descrição: o widget do Google Reviews é carregado no mesmo documento da aplicação logada. Não há SRI possível, porque o script é dinâmico. Depois que alguém abre "Meu Painel", o script fica ativo em todas as telas e tem acesso ao DOM, ao `localStorage` (token CSRF) e à API com o cookie.
- Impacto: se o fornecedor for comprometido, as sessões de quem abriu o Meu Painel podem ser sequestradas.
- Correção: carregar o widget numa página estática de outra origem dentro de `<iframe sandbox="allow-scripts allow-popups">`, ou trocar por um link externo. Depois, retirar `elfsight` do `script-src` da aplicação. **Exige aprovação** (muda a CSP e a aparência).

**SEG-03: link de redefinição de senha em texto puro na fila de e-mails**
- Severidade **Média**. Certeza: confirmado. Esforço P.
- Local: `api/controllers/recuperar-senha.php:72-92` → `api/services/email-sender.php:529-534` (`enviarEmail`, que enfileira por padrão) → `email-sender.php:456-474` (`INSERT INTO email_fila ... corpo`). Limpeza só depois de 30 dias em `api/services/email-fila-service.php:83`.
- Descrição: o token de redefinição é guardado como hash SHA-256 em `tokens_recuperacao`, mas o e-mail com `#token=<token em claro>` fica gravado em `email_fila.corpo`.
- Impacto: quem tiver leitura do banco ou de um backup (DBA do provedor, dump esquecido) pode redefinir a senha de qualquer servidor, inclusive de um admin, durante a hora de validade do link. Isso anula o hash.
- Correção: depois do envio bem-sucedido, apagar o corpo da linha de tipo `recuperacao_senha` (`UPDATE email_fila SET corpo='' ...`). Alternativa: não guardar o link, só uma referência que o processador da fila recompõe.

**SEG-04: injeção de fórmula nas exportações CSV**
- Severidade **Média**. Certeza: confirmado. Esforço P.
- Local: `frontend/relatorios.js:213-245` (título, solicitante, responsável e subcategoria) e `frontend/auditoria.js:1130-1170` e `1174-1197` (assunto, destinatário, user agent).
- Descrição: os valores entram no CSV sem neutralizar `= + - @`. O título vem do assunto de e-mail externo: um cidadão pode mandar `=HYPERLINK("http://...","clique")` ou fórmulas DDE. A tela de atividades já faz isso certo (`AtividadesAcompanhamentoService::celulaCsv`, `api/services/atividades-acompanhamento-service.php:359`). Falta também o BOM no CSV da auditoria (acentos quebram no Excel).
- Impacto: código ou links maliciosos executados no Excel de quem exporta (gestor ou admin).
- Correção: um helper `celulaCsv()` no frontend (`script.js`), usado nas três exportações, e BOM em todas.

**SEG-05: sem limite de tamanho no servidor para descrição, comentário e macro**
- Severidade **Média**. Certeza: provável. Esforço P.
- Local:
  - `api/controllers/tickets.php:455-463`
  - `api/controllers/comentarios.php:75-119`
  - `api/controllers/macros.php:80-83`
  - `api/services/html-sanitizer.php:59-79`
- Descrição: nenhum desses campos tem limite de tamanho no servidor. O PHP local aceita POST de até 200 MB (`post_max_size=200M`). O HTML Purifier processa o texto todo em memória, e a coluna é `LONGTEXT`. O editor reduz as imagens, mas uma chamada direta à API não passa pelo editor.
- Impacto: um usuário autenticado pode esgotar a memória e os processos do PHP e inchar o banco (lista, e-mail e auditoria leem esse campo).
- Correção: recusar com 413 corpos acima de N MB (ex.: 8 MB de HTML, já com as imagens) antes de sanitizar, e limitar a quantidade de `<img>`.

**SEG-06: login certo zera o contador do IP**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/auth.php:108`.
- Descrição: quem tem uma conta válida pode intercalar logins certos para zerar o limite de 20 tentativas por IP e seguir testando senhas de outras contas (o atraso por conta continua valendo).
- Correção: zerar só a chave da conta, não a do IP.

**SEG-07: primeiro item do X-Forwarded-For atrás de proxy confiável**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/services/rede-service.php:24-27`.
- Descrição: sem `CF-Connecting-IP`, vale o primeiro IP do `X-Forwarded-For`, que o cliente pode inventar. O certo é o último IP antes do proxy confiável, lendo da direita para a esquerda.

**SEG-08: sessão sem validade absoluta**
- Severidade **Média**. Certeza: confirmado. Esforço P.
- Local: `api/middleware/auth.php:178-188`; polling a cada 30 s em `frontend/notificacoes.js:18`.
- Descrição: a renovação a cada uso mantém a sessão viva para sempre enquanto houver atividade. Uma aba visível parada já basta, por causa do sino. Num computador compartilhado do Conselho, a sessão nunca expira.
- Correção: claim `auth_time` no JWT e limite absoluto (ex.: 7 dias, ou 12 h de inatividade real sem contar o polling). **Exige aprovação** (muda comportamento visível).

**SEG-09: `.docx`/`.xlsx` aceitam qualquer ZIP**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/services/upload-service.php:37-41`.
- Descrição: o MIME `application/zip` é aceito para `docx` e `xlsx`. Um ZIP qualquer renomeado entra, contrariando a regra "sem zip".
- Correção: abrir com `ZipArchive` e exigir `[Content_Types].xml` e `word/` ou `xl/`.

**SEG-10: cabeçalhos de segurança divergentes ou obsoletos**
- Severidade Baixa. Certeza: hipótese (não conferido por HTTP). Esforço P.
- Local: `.htaccess:38-39`; `api/controllers/auth.php:4`, `auth-sso.php:18`.
- Descrição: o `.htaccess` manda `X-Frame-Options SAMEORIGIN` com `Header always set`, enquanto controllers mandam `DENY` (e a regra do projeto exige `DENY`). Os dois podem sair juntos. Além disso, `X-XSS-Protection: 1; mode=block` está obsoleto; o recomendado hoje é `0` ou omitir.
- Correção: decidir um valor único (`frame-ancestors` já governa) e conferir com `curl -I` em produção.

**SEG-11: a integração usa a conta de qualquer e-mail informado, inclusive interna**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/integracoes/abrir_chamado.php:222-235`.
- Descrição: um satélite que informe `email_solicitante` de um servidor ou admin abre a demanda em nome dele.
- Correção: usar a conta existente só se for `cliente`; senão, gravar só `email_solicitante`.

**SEG-12: origens de SSO de desenvolvimento valem em produção**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `frontend/app-config.js:13-15`.
- Descrição: `trustedParentOrigins` aceita `http://localhost:*` e `http://127.0.0.1:*` também em produção (o `sso-bridge.js` aceita `postMessage` dessas origens). O `frame-ancestors 'self'` mitiga, mas a configuração deveria ser por ambiente.

**SEG-13: personalização grava na pasta pública de código e sem auditoria**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/personalizacao.php:55-77`.
- Descrição:
  - Caminho relativo (`'../../frontend/images/...'`), que depende do diretório de trabalho do PHP.
  - Cria `*.backup` públicos.
  - Não chama o `AuditoriaService` (regra do projeto).
  - O ramo `tipo=login` grava `logo_login.png`, que nenhuma tela usa.
  - O processo web precisa de permissão de escrita em `frontend/images`.

**SEG-14: exportação da auditoria avançada sem limite e paginação sem validação**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/auditoria-avancada.php:25-28,321-368`.
- Descrição: `limite=0` causa `DivisionByZeroError` (500). `pagina<1` gera `OFFSET` negativo. A exportação devolve a tabela inteira. Afeta só o admin.

**SEG-15: retenção e minimização de dados pessoais (LGPD)**
- Severidade **Média**. Certeza: confirmado. Esforço M.
- Local:
  - `api/services/email-fila-service.php:83-85`: corpo completo dos e-mails (dados de cidadãos) por 30 dias.
  - `email_logs`, `emails_processados`, `auditoria`, `auditoria_login`, `auditoria_tickets`: sem nenhuma limpeza.
  - `api/controllers/tickets.php:643,710-714` e `api/services/demanda-service.php:69-72,97`: a **descrição inteira** da demanda é copiada para `auditoria.dados_anteriores` ao cancelar e ao excluir definitivamente.
- Correção: política de retenção aprovada (prazos por tabela, cron de limpeza ou anonimização) e auditoria sem a descrição (só título, status, setor e um hash). **Exige aprovação** (apaga dados).

### 3.2 Bugs e falhas lógicas

**BUG-08: um erro de PHP ao ler uma mensagem derruba toda a cadeia do cron**
- Severidade **Alta**. Certeza: provável. Esforço M.
- Local:
  - `api/services/email-service.php:137-142`: o laço por mensagem só captura `catch (Exception $e)`.
  - `api/cron/verificar-emails.php:29-36`: idem, com os lembretes incluídos só no fim, na linha 41.
  - `api/cron/verificar-lembretes.php:31`: idem (a fila é incluída no fim).
  - `api/services/email-mime.php:161,225-266` e `email-service.php:604-627`: os anexos são baixados e decodificados por completo na memória **antes** de validar tipo e tamanho, sem olhar `$parte->bytes`.
- Descrição: `TypeError`, `ValueError` e falta de memória são `Error`, não `Exception`. Exemplo: `imap_fetchstructure()` devolve `false` e `EmailMime::corpoTexto(object ...)` lança `TypeError`. Em qualquer desses casos o script morre ali. Não roda o resto das caixas, nem os lembretes, nem a fila de envio, nem as pesquisas. A mensagem não é registrada em `emails_erro`, porque `registrarFalha` não é alcançado, e continua na janela `SINCE 3 dias`. Assim, **toda execução morre no mesmo ponto** por até 3 dias. Mensagens do mesmo dia que vêm depois dela podem sair da janela sem nunca ser lidas, o que só se recupera com `verificar-emails-perdidos.php`.
- Impacto: em produção a cadeia inteira depende de um único cron (`verificar-emails.php`, a cada 2 minutos). Por isso, um e-mail grande ou malformado pode parar **a entrada de demandas e todo o envio de e-mails e lembretes** do sistema, sem nenhum alerta.
- Correção:
  1. `catch (Throwable $e)` por mensagem e por caixa, chamando `registrarFalha`.
  2. Registrar a tentativa **antes** de processar, para contar falhas fatais.
  3. Pular sem baixar os anexos com extensão proibida ou `bytes` acima do limite.
  4. Rodar lembretes e fila num cron próprio, ou proteger com `register_shutdown_function`.
  5. Teste com `.eml` grande e com estrutura inválida.

**BUG-01: `HtmlSanitizer::texto()` apaga o texto depois de "<"**
- Severidade **Média**. Certeza: confirmado (teste local). Esforço P.
- Local: `api/services/html-sanitizer.php:92-93`.
- Uso: títulos (`email-service.php:192`, `tickets.php:462`, `abrir_chamado.php:82`), nomes (`usuarios.php:214,409`), categorias, macros e lembretes (`lembretes.php:92`).
- Evidência (PHP 8.2.12):

  | Entrada | Saída de `strip_tags` |
  |---|---|
  | `"valor <100 reais"` | `"valor "` |
  | `"5<10 e 3>2"` | `"52"` |
  | `"x <= y"` | `"x "` |
  | `"Assunto <urgente> teste"` | `"Assunto  teste"` |

- Impacto: perda silenciosa de parte do assunto de e-mails de cidadãos e de textos digitados.
- Correção: remover só tags reais (`/<\/?[a-z][^>]*>/i`), ou gravar o texto como veio e confiar no escape na saída, que o frontend e os e-mails já fazem. Acrescentar casos ao `HtmlSanitizerTest`.

**BUG-02: tempo de resolução maior que o real ao resolver a partir de um status que pausa o SLA**
- Severidade **Média**. Certeza: confirmado (leitura de código). Esforço P.
- Local: `api/services/demanda-service.php:168-196`.
- Descrição: catálogo local: `aguardando*` e `resolvido` têm `congela_sla=1`. Em `aguardando_resposta → resolvido`, os dois status congelam, então nem pausa nem retomada acontece e a pausa em andamento continua aberta. Daí:
  - a resolução é calculada como `total - tempo_pausado_segundos`, sem o trecho pausado atual, que entra como tempo trabalhado;
  - na passagem `resolvido → encerrado` a pausa é somada, mas `resolvido_em` já está gravado e não se recalcula.
- Impacto: as médias de resolução do dashboard e dos relatórios ficam maiores que a realidade. O fluxo "aguardando resposta, depois resolvido" é comum.
- Correção: ao concluir com a pausa aberta, descontar também `calcularSegundosUteis(pausado_em, agora)` (como já faz `SLAService::situacao`, `sla-service.php:35-37`). Teste em `FluxoDemandaTest`.

**BUG-03: feriados não caem no setor Geral e não há feriado cadastrado**
- Severidade **Média**. Certeza: confirmado. Esforço P (mais configuração).
- Local: `api/services/sla-service.php:240-250` (feriados, só do próprio setor) e `210-222` (horários, que caem no Geral).
- Evidência: `SELECT COUNT(*) FROM feriados` dá 0 no banco local.
- Impacto: todo o SLA conta feriados como dia útil. Um setor novo sem feriados próprios conta até os feriados nacionais.
- Correção: setor sem feriados próprios usa os do Geral, ou soma os do Geral aos próprios. Cadastrar os feriados antes da produção.

**BUG-04: auditoria perdida quando o dado passa de 64 KB**
- Severidade **Média**. Certeza: confirmado (código e esquema). Esforço P.
- Local:
  - `api/controllers/tickets.php:643,710-714`
  - `api/services/demanda-service.php:69-72,97`
  - `api/services/auditoria-service.php:45-48` (engole a exceção)
  - esquema `auditoria.dados_anteriores TEXT`, com `sql_mode` `STRICT_TRANS_TABLES`
- Descrição: com uma imagem colada (base64 de 100 a 300 KB), o JSON passa de 64 KB e o `INSERT` falha. O erro só vai para o `error_log`.
- Impacto: a **exclusão definitiva**, a ação mais sensível, fica sem rastro, e o cancelamento também.
- Correção: auditar sem `descricao` (ver SEG-15). Opcionalmente, `MEDIUMTEXT` (migração; exige aprovação).

**BUG-05: CSV do log de e-mails traz só 100 linhas**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `frontend/auditoria.js:1136` (`limite: 99999`) e `api/controllers/email-logs.php:21` (`min(100, …)`).
- Descrição: a exportação parece completa, mas sai truncada, sem aviso. Precisa de um modo de exportação no servidor, com teto alto, ou paginar no cliente.

**BUG-06: histórico da mesclagem mostra "##" e inverte o sentido**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `frontend/detalhe-demanda.js:207`; `api/controllers/merge.php:108-114,157-158`.
- Descrição: o valor gravado já começa com "#" e a tela acrescenta outro. Na demanda secundária aparece "mesclou as demandas #<principal> nesta demanda".

**BUG-07: comentário automático da mesclagem com Markdown literal**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/merge.php:117-123,161-162`.
- Descrição: o texto é gravado com `**` e `\n` e exibido como HTML, então sai numa linha só, com asteriscos. Usa "TICKET MESCLADO" e "ticket" em vez de "demanda". `strip_tags` da descrição da secundária pode trazer até 10 mil caracteres.

**BUG-09: lembrete muda o status fora da regra única**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/services/lembrete-service.php:150-173`.
- Descrição: `retomarSeAguardandoData` faz `UPDATE` direto (sem trava, sem conferir o catálogo do setor) em vez de `DemandaService::mudarStatus`, contrariando a "regra única" documentada.

**BUG-10: comentário pela tela sem transação**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/comentarios.php:164-346`.
- Descrição: comentário, anexos, status e notificações em passos separados, sem `UploadService::iniciarLote()`. Uma falha no meio deixa comentário sem anexo ou sem status. Inconsistente com `tickets.php` POST e com o comentário por e-mail.

**BUG-11: quem só pode ver a demanda consegue comentar e mudar o status**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/comentarios.php:128,182-188,242-256` vs `api/services/acesso-service.php:52-71`.
- Descrição: o comentário exige só `podeVerTicket`. Um operador que abriu a demanda sem ser o responsável comenta, envia e-mail ao solicitante, marca a primeira resposta e passa o status para `nova_resposta`. O docblock do `podeOperarTicket` diz "comentar como responsável". Também dá para responder por e-mail numa demanda encerrada ou cancelada sem reabri-la. **Decidir a regra** (exige aprovação).

**BUG-12: `categoria_id` gravado sem conversão**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/tickets.php:680-688,733-736`.
- Descrição: a validação usa `(int)`, mas o `UPDATE` grava o valor cru. `"5abc"` passa na validação e dá erro 500 no modo estrito.

**BUG-13: bloqueio de login na tela diverge do servidor**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `frontend/login.html:1240-1268`.
- Descrição: a tela bloqueia "5 tentativas, 5 minutos" só no `localStorage` e diz "N tentativa(s) restante(s)". O servidor usa 20 por IP em 15 minutos, mais atraso por conta (`auth.php:37,80-85`). A mensagem é falsa e o bloqueio se contorna limpando o navegador.

**BUG-14: datas do banco convertidas sem formato ISO**
- Severidade Baixa. Certeza: hipótese (Safari/iOS antigos). Esforço P.
- Local: cerca de 25 pontos, entre eles `frontend/arquivo.js:193,205`, `detalhe-demanda.js:284,348,427-433`, `notificacoes.js:161`, `relatorios.js:160,219,307`, `lembretes.js:26`.
- Descrição: `new Date("AAAA-MM-DD HH:MM:SS")` sem o `T` vira "Invalid Date" em navegadores mais rígidos; outros pontos já usam `.replace(' ', 'T')`. Criar um helper único `dataDoBanco()`.

**BUG-15: confirmação de comentário para a própria equipe e código duplicado**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/services/email-sender.php:774-834`, `719-731`.
- Descrição: um servidor que responde por e-mail recebe "Seu comentário foi adicionado". `$mensagem` é montada e descartada (795-813), e a consulta do autor está repetida em `notificarNovoComentario`.

**BUG-16: aviso de log enganoso**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/services/email-service.php:577-582`.
- Descrição: "Confirmação … não enfileirada" sai em nível AVISO quando o e-mail está **desligado de propósito** no setor (79 ocorrências no log local), o que esconde falhas reais.

**BUG-17: limites de anexo diferentes por canal**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/services/upload-service.php:15` (20 MB **por arquivo**), tela (20 MB **no total**) e e-mails `email-sender.php:99,808` ("20MB total").

**BUG-18: macros sem `id` respondem 200 vazio**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/macros.php:144,191` (`die()`). Deveria responder 400 com JSON.

**BUG-19: excluir usuário com registro de atividades dá 500**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/usuarios.php:554-559`.
- Descrição: a lista de vínculos não inclui `registros_atividades` (FK RESTRICT), então o resultado é 500 genérico em vez de "Usuário possui vínculos".

**BUG-20: transferência e devolução deixam estados estranhos**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/demanda-acoes.php:182-197,279`.
- Descrição: transferir mantém o status (ex.: "em atendimento") sem responsável no novo setor. "Devolver" sem responsável grava "Não atribuído → Não atribuído". Encaminhar ou repassar para a mesma pessoa abre nova janela em `ticket_agente_log`. **Decidir a regra** (exige aprovação).

### 3.3 Inconsistências

**INC-01: banco local pronto para virar produção ainda tem configuração de teste**
- Severidade **Média**. Certeza: confirmado. Esforço P (operação).
- Evidência: o setor 2 (Cadastro) tem os **15** códigos de e-mail desligados em `setor_notificacoes_email`. Há 23 demandas em `novo` e 1 em `aguardando`, 7 solicitantes externos e `feriados` vazia.
- Impacto: replicado como está, o Cadastro nasce sem nenhum e-mail ao cidadão nem à equipe.
- Correção: checklist de limpeza e revisão antes da replicação (ver Fase 0).

**INC-02: `CLAUDE.md` e README desatualizados**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Pontos:
  - "`script.js` … ~2.200 linhas": hoje são 1.561.
  - "Aplicar as migrações 9–22", nas pendências: hoje são 9–38.
  - `editor-imagens.js` "na descrição de nova demanda (Alpine)" e "`limparFormularioTicket()` … `clearEditor()` do componente Alpine": o Alpine saiu na fase 8.
  - A tabela de arquivos não cita `fluxos-email-func.js`, `horarios-sla.js`, `canvas-tooltip.js`, `vanguard-tooltip.js` nem `app-config.js`.
  - "O log de e-mails do setor (Ativação por Setor)": o log fica em Configurar E-mail (admin); não existe aba de e-mail na Ativação por Setor (`ativacao-setor.js:58-62`).
  - Lista de migrações com a 37 antes da 36.
  - `README.md` é de 01/12/2025.

**INC-03: `.env.example` com comentário quebrado e `.env` desatualizado**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/config/.env.example:72` e `.env` local.
- Descrição: a linha 72 tem `/n` literal no lugar de quebras de linha. O comentário engole `PROXIES_CONFIAVEIS=` e o título "CLOUDFLARE TURNSTILE", e quem copia o modelo fica sem a chave. O `.env` local ainda diz "X-Cron-Key ou ?key=", e `?key=` é recusado desde a fase 1.

**INC-04: código e esquema sem uso (apenas apontados; remoção exige aprovação)**
- Severidade Baixa. Certeza: confirmado.
- Endpoints e código:
  - endpoint `horarios-sla.php?mapa` (`api/controllers/horarios-sla.php:52-91`), sem chamador desde a fase 8;
  - ramos `$ehGestor` em controllers só de admin: `auditoria.php:25-28`, `auditoria-avancada.php:18,34,126,200,265-285`, `emails-processados.php:30,38-41`;
  - `mapearTipo()`, `$nome` e `$setorIdSso` em `auth-sso.php:42-57,99-101`;
  - ramo `carregarAbaConfigSetor('emails')` em `ativacao-setor.js:79-82`, sem aba correspondente;
  - `tipo=login` em `personalizacao.php`;
  - `frontend/branding-preview.html`.
- Imagens: `images/comentario.png`, `visualizar.png` e `logo-conecta.svg`, sem referência, e `images/logos-propostas/` (720 KB).
- Colunas sem leitura nem escrita:
  - `ticket_sla.prioridade`, `sla_resposta_minutos`, `sla_resolucao_horas`, `sla_resposta_vencido`, `sla_resolucao_vencido` e `tempo_total_segundos`;
  - `usuarios.reset_token`, `reset_token_expira` e `anydesk_id`;
  - `hostname` das tabelas de auditoria (sempre NULL).
- Outros:
  - `AcessoService::GRUPO_AGUARDANDO` contém `aguardando_aprovacao`, que não existe no catálogo (`acesso-service.php:23`);
  - `composer.json` com autoload `App\\ → src/`, pasta inexistente;
  - `frontend/package.json` com Quill ^2.0.3, enquanto a aplicação carrega o 1.3.6.

**INC-05: vocabulário e paleta herdados do helpdesk**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/integracoes/abrir_chamado.php:104,155-158,161-164,183,281-286`.
- Descrição: "retorno da TI", "Mesa de TI", "Bloco Hacker", "Interceptação API", "ticket M2M" e o azul `#3b82f6`, que a paleta eliminou. O frontend tem cores em hex embutidas em templates (`detalhe-demanda.js:285,295,304` e outros), contra a regra "só tokens".

**INC-06: papel e setor guardados no navegador não se atualizam**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `frontend/script.js:1243-1257,979-1017`.
- Descrição: o servidor relê papel e setor a cada requisição, mas menus e botões seguem `localStorage.cd_usuario` até um novo login. Um gestor rebaixado continua vendo os menus de gestor (e recebe 403). Falta um endpoint `me`, consultado no carregamento.

**INC-07: chaves estrangeiras ausentes**
- Severidade Baixa. Certeza: confirmado (esquema). Esforço M (migração).
- Colunas: `comentarios.usuario_id`, `ticket_historico.usuario_id`, `lembretes.agente_id`/`criado_por`, `comentarios_nao_lidos.usuario_id`, `email_fila.ticket_id`, `tokens_recuperacao.usuario_id`.
- Descrição: a integridade depende só do código. Linhas de `email_fila` sobram depois da exclusão definitiva. **Exige aprovação** (esquema).

**INC-08: definições de métricas do dashboard**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/dashboard.php:202-220` (o "FCR" conta demandas **sem comentário do solicitante**, o que não é resolução no primeiro contato) e `55-58` vs `189-200` (`total_tickets` inclui demandas mescladas, `sla_indicadores` não).
- Descrição: contagens saem como texto no JSON. Revisar as definições com a gestão. **Exige aprovação**.

**INC-09: prazo de SLA retroativo**
- Severidade Baixa. Certeza: confirmado. Esforço M.
- Local: `api/services/sla-service.php:117-127`.
- Descrição: o prazo vem da prioridade **atual** e do catálogo global. Trocar a prioridade, ou o catálogo, muda o prazo de todas as demandas abertas, inclusive para trás. As colunas de prazo em `ticket_sla` existem, mas não são usadas.

### 3.4 Desempenho

**DES-01: o cron relê toda a caixa a cada execução**
- Severidade **Alta**. Certeza: confirmado (log). Esforço M.
- Local: `api/services/email-service.php:119-143` (`SINCE 3 dias`, laço por UID), `165-166` (`imap_headerinfo`, `imap_fetchstructure`), `175-187` (`imap_setflag_full` também nas já processadas) e `608` (`imap_fetchstructure` repetido).
- Evidência: `email-cron.log`, execução de 26/10 09:23:26 a 09:24:01, 30 mensagens **já processadas** em 35 s. São 5.781 execuções em 9 dias.
- Impacto: o custo cresce com o volume da caixa e se soma entre as caixas, que são lidas em série. Com cerca de 100 e-mails por dia num setor, cada execução passa dos 2 minutos. O resultado:
  - as demandas novas demoram;
  - as execuções se sobrepõem e a leitura é pulada, porque a trava está ocupada;
  - aumenta o risco de limite do provedor IMAP.
- Correção:
  1. `imap_fetch_overview($inbox, "uid1,uid2,...", FT_UID)` numa chamada só, para obter os `message_id`.
  2. Conferir no banco em lote (`IN (...)`).
  3. Buscar estrutura e corpo só das novas.
  4. Guardar o último UID por caixa (com `UIDVALIDITY`).
  5. Não remarcar como lidas as já processadas.

**DES-02: log de e-mail sem rotação**
- Severidade **Média**. Certeza: confirmado. Esforço P.
- Local: `api/services/log-service.php:37-48`.
- Evidência: `backend/logs/email-cron.log` tem 32 MB em 9 dias (cerca de 3,5 MB por dia; 98% são linhas INFO "já processado"). Em produção, passa de 1 GB por ano.
- Correção: rotação diária ou por tamanho no `LogService` (ex.: `email-cron-AAAA-MM-DD.log`, mantendo N dias), nível INFO reduzido e um resumo por execução.

**DES-03: estáticos sem compressão nem cache longo**
- Severidade **Média**. Certeza: confirmado. Esforço P.
- Local: `.htaccess` (não há `mod_deflate`, `Cache-Control` nem `Expires`) e `frontend/index.html:13-36,901-958`.
- Descrição: são 37 JS (cerca de 820 KB sem minificar) e 24 CSS (cerca de 293 KB). Sem regra de cache, cada carga revalida cerca de 60 arquivos.
- Correção: compressão (`mod_deflate`) e `Cache-Control: public, max-age=31536000, immutable` para `*.js`/`*.css` com `?v=`, que já existe. Opcional: concatenar e minificar no empacotamento (MAN-04).

**DES-04: lista e busca sem índice composto nem full-text**
- Severidade **Média**. Certeza: provável (em escala). Esforço M (migração).
- Evidência: `EXPLAIN` da lista do gestor mostra `key: idx_tickets_setor_id`, `Extra: Using where; Using filesort`. A busca `LIKE '%termo%'` em `descricao_texto` (MEDIUMTEXT) varre o setor inteiro. Cada página roda 4 consultas: contadores, contagem, ids e dados (`tickets.php:81-161`).
- Correção: índice `(setor_id, excluido_em, criado_em)` e, quando o volume justificar, `FULLTEXT(titulo, descricao_texto)`. **Exige aprovação** (esquema).

**DES-05: `SELECT t.*` em todos os e-mails**
- Severidade **Média**. Certeza: confirmado. Esforço P.
- Local: `api/services/email-sender.php:240,300,600,701,780,839,920,958,1007,1047`.
- Descrição: cada e-mail preparado traz `descricao` (LONGTEXT, com base64 de vários MB) sem precisar dela, salvo onde ela é usada de fato (`notificarAgentesNovoTicket`). Usar colunas explícitas.

**DES-06: relatório sem paginação**
- Severidade **Média**. Certeza: confirmado. Esforço M.
- Local: `api/controllers/relatorios.php:36-113`.
- Descrição: o relatório devolve todas as demandas do filtro (um admin sem datas recebe a tabela inteira em JSON, e o navegador desenha tudo). `DATE(t.criado_em) >= ?` impede o uso de índice.
- Correção: paginação, agregados no SQL e intervalo `criado_em >= ? AND criado_em < ? + 1 dia`.

**DES-07: acesso ao Meu Painel consultado no METRICA360 a cada carga**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `frontend/script.js:993` → `api/controllers/meu-painel.php:62-73` → `metrica360-client.php:34-43` (até 10 s).
- Descrição: com o METRICA360 lento, cada carga da aplicação prende um processo PHP. Guardar a resposta no servidor por usuário, por cerca de 15 minutos.

**DES-08: assinatura da lista varre todas as demandas da visão**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/tickets.php:401-413`.
- Descrição: `BIT_XOR(CRC32(...))` sobre todas as demandas da visão, a cada 60 s por usuário. No Arquivo do admin, que só cresce, é uma varredura completa por minuto.
- Correção: `COUNT(*)` mais `MAX(atualizado_em)` com índice, ou só o intervalo da página.

**DES-09: notificações ordenadas sem índice**
- Severidade Baixa. Certeza: confirmado (EXPLAIN). Esforço P (migração).
- Descrição: `ORDER BY criado_em` usa o índice `(usuario_id, lida)` com filesort. Trocar ou acrescentar `(usuario_id, criado_em)`.

**DES-10: "SLA vencido" do dashboard calculado demanda a demanda**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/controllers/dashboard.php:251-266`; `sla-service.php:144-205`.
- Descrição: o custo cresce com a quantidade de demandas abertas vezes os dias de cada uma. Guardar o resultado no servidor por cerca de 60 s, que hoje só fica no navegador.

**DES-11: consultas em série (N+1)**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/services/timeline-service.php:26-30` (anexos por comentário) e `atividades-acompanhamento-service.php:225-300` (pendências por servidor e consultas dentro de laços).

**DES-12: fila abre uma conexão SMTP por mensagem**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `api/services/email-fila-service.php:42-58` e `email-sender.php:151-189`.
- Descrição: a fila abre nova conexão SMTP e decifra de novo a senha da caixa a cada mensagem. Agrupar por setor e usar `SMTPKeepAlive`.

**DES-13: tabelas que só crescem**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Descrição: `email_logs`, `emails_processados`, as tabelas de auditoria e as notificações **não lidas** nunca são limpas. `sessoes_revogadas` só é limpa no logout (`auth.php:263`). Junto com SEG-15.

**DES-14: relógios das telas continuam rodando**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `frontend/registro-atividades.js:73-74` e `meu-painel.js:52-53`.
- Descrição: os `setInterval` de 30 s dos relógios seguem depois de sair da tela.

### 3.5 Frontend e acessibilidade

**FE-01: `console.log` de depuração em produção**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `frontend/script.js:933,941,949-950` (modais logam o callback) e outros 19 em `categorias.js`, `demandas.js`, `detalhe-demanda.js` e `tutorial.js`.

**FE-02: elementos sem texto acessível**
- Severidade Baixa. Certeza: confirmado. Esforço P.
- Local: `detalhe-demanda.js:295` (ícone de clipe sem `alt=""`), `ajuda.js:44-45` (ícones de atenção sem `alt`) e `index.html:947` (`#btn-voltar-topo` só com `title`, sem `aria-label`).

O restante da base de acessibilidade (lang, skip-link, foco, diálogos, `aria-sort`, legendas de tabela) está coerente com a fase 7. Continua pendente a passada com NVDA.

### 3.6 Manutenção

**MAN-01: lacunas de testes em áreas críticas**
- Severidade **Média**. Certeza: confirmado. Esforço M.
- Sem testes:
  - listagem, paginação, busca e assinatura de `tickets.php`;
  - `comentarios.php`, `demanda-acoes.php` e `merge.php`;
  - `EmailFilaService`, `LembreteService` e `CatalogoService`;
  - `AuthMiddleware` (renovação, CSRF), `CryptoService` e `RateLimitService`;
  - o frontend inteiro.
- A matriz de acesso depende do Apache ligado e não foi executada nesta auditoria.
- Os bugs BUG-01, 02, 03, 04 e 08 deveriam ganhar teste de regressão.

**MAN-02: arquivos grandes e repetitivos**
- Severidade Baixa. Esforço M.
- `email-sender.php` (1.209 linhas; cerca de 15 métodos que repetem consulta, modelo, substituições e envio), `tickets.php` (861), `usuarios.php` (604), `auditoria.js` (1.198), `demandas.js` (1.167) e `detalhe-demanda.js` (1.126).
- Extrair um "montador de e-mail" e o serviço de listagem.

**MAN-03: dependências e versões**
- Severidade Baixa. Esforço M.
- PHPMailer 6.10.0, com a 7.x disponível (major; avaliar).
- PHP local 8.2.12, de out/2023. O suporte de segurança do 8.2 termina em 31/12/2026: planejar 8.3 ou 8.4 em produção.
- Quill 1.3.6 (pendência conhecida). `composer audit` sem alertas.

**MAN-04: deploy sem lista do que não enviar**
- Severidade Baixa. Esforço P.
- `docs/implantacao-producao.md` não lista as exclusões: `mockups/` (é servido, só os `.php` são bloqueados), `.playwright-mcp/` (7,7 MB), `.superpowers/`, `docs/`, `api/tests/`, `frontend/node_modules/`, `images/logos-propostas/`, `branding-preview.html` e `composer.phar`.
- Criar um script de empacotamento com essas exclusões.

**MAN-05: testes gravam no log real**
- Severidade Baixa. Esforço P.
- Os testes de integração escrevem em `backend/logs/email-cron.log`, porque o `LogService` não tem canal de teste.

---

## 4. Melhorias sugeridas

| # | Melhoria | Prioridade |
|---|---|---|
| M-01 | Subdomínio próprio para o Conecta (resolve SEG-01 e simplifica cookie, CORS e SSO) | Alta |
| M-02 | Painel de saúde para o admin: último cron de e-mail por caixa, fila pendente ou em falha, `emails_erro`, pesquisas pendentes, com alerta no sino quando o cron passar de 10 minutos sem rodar (heartbeat em tabela) | Alta |
| M-03 | Proteção contra laço com respostas automáticas: limitar confirmações automáticas ("demanda criada" ou "comentário recebido") por remetente e por hora (`email-sender.php:774`) | Média |
| M-04 | Nome do solicitante pelo nome de exibição do e-mail (`$header->from[0]->personal`) em vez da parte local do endereço (`email-service.php:654`) | Média |
| M-05 | Política de retenção LGPD (anonimizar solicitantes e limpar logs depois de N meses), documentada e com cron | Média |
| M-06 | Limite de tamanho visível no editor (contador e aviso) junto com SEG-05 | Média |
| M-07 | Endpoint `me` e atualização do papel sem novo login (INC-06) | Média |
| M-08 | Empacotamento de deploy: exclusões, minificação opcional e conferência de `.env` e permissões | Média |
| M-09 | Tempos exibidos em "dias e horas úteis" em vez de "27h 0m" (`status-sla.js:250`, `dashboard.js:29`, `relatorios.js:113`) | Baixa |
| M-10 | Prazo de SLA congelado por demanda na abertura e na troca de prioridade (INC-09) | Baixa |
| M-11 | Transições de status permitidas por setor (hoje qualquer status vai para qualquer outro, ex.: cancelado → novo) | Baixa |
| M-12 | Integração contínua simples: `php -l`, `phpunit`, `node --check` e a matriz de acesso com o PHP embutido (`php -S`) num container | Baixa |
| M-13 | Testes E2E (Playwright) dos fluxos: login, nova demanda, encaminhar, comentar com e-mail, encerrar e reabrir | Baixa |
| M-14 | Busca full-text e filtros salvos na lista (junto com DES-04) | Baixa |
| M-15 | Indicar no detalhe da demanda quais e-mails estão desligados no setor (hoje só o switch de resposta e cópia) | Baixa |

---

## 5. Pendências já conhecidas (situação em 01/11/2026)

| Pendência do `CLAUDE.md` | Situação |
|---|---|
| CSP com `'unsafe-inline'` | **Confirmada.** 213 `onclick=` e 400 `style="` em JS e `index.html`; `login.html:1107`, `redefinir-senha.html:167` e `politica-privacidade.html:202` com `<script>` embutido. Agravante novo: `elfsight` no `script-src` (SEG-02). |
| Trocar senhas e chaves expostas no histórico do Git | **Não verificável aqui.** Continua pendente até haver registro de troca. |
| `TURNSTILE_SECRET_KEY_PROD` e chaves `*_PROD` | **Pendente.** O `.env` local não tem nenhuma chave de produção, o que é esperado. |
| `db_host`/`db_user` de produção herdados do CRPDESK | **Confirmada.** `environment.php:124-126` ainda usa `crpdesk.vpshost0269...` e `crpdesk`. |
| Aplicar migrações "9–22" | **Superada no texto:** hoje são 9 a 38 (INC-02). O banco local tem todas. |
| Consolidar CSS repetido e as três bases de modo escuro | **Confirmada** (não reavaliada em detalhe). |
| Quill 1.3.6 sem manutenção | **Confirmada.** `frontend/package.json` cita Quill 2 só como ferramenta local (INC-04). |
| Passada com NVDA e teclado nas Configurações | **Pendente** (não executada). |
| Não enviar `frontend/node_modules` | **Confirmada.** O `.htaccess` bloqueia; somar à lista de MAN-04. |
| Avisar satélites da nova URL | **Pendente.** Junto com SEG-01, a URL pode mudar de novo; decidir antes de avisar. |
| Pesquisa e Meu Painel: implantar primeiro a API do METRICA360 | **Pendente** (fora do escopo). |

---

## 6. Plano de correção e implementação

Ordem por risco e dependência. Cada fase pode virar um commit ou PR próprio, com o `CLAUDE.md` atualizado na mesma entrega. A coluna **Aprov.** marca o que precisa de autorização do usuário: remoção de código, esquema, CSP, comportamento visível ou infraestrutura.

### Fase 0: antes de replicar o banco ou de qualquer deploy (operação, sem código)

| Item | O que fazer | Aceite | Aprov. |
|---|---|---|---|
| INC-01 | Revisar `setor_notificacoes_email` (religar os e-mails do setor 2 ou registrar a decisão), cadastrar os feriados (BUG-03), retirar demandas e solicitantes de teste | `SELECT * FROM setor_notificacoes_email` só com o que foi decidido; `feriados` com o calendário de 2026 e 2027 | Sim (dados) |
| Produção | Conferir no servidor `memory_limit`, `post_max_size`, `upload_max_filesize`, rotação de log do sistema e o PHP ≥ 8.2 | Valores registrados em `docs/implantacao-producao.md` | Não |

### Fase 1: confiabilidade do cron de e-mail (Alta)

| Item | Arquivos | Aceite | Teste | Aprov. |
|---|---|---|---|---|
| BUG-08 | `api/services/email-service.php:137-142,604-627`; `api/services/email-mime.php:225-266`; `api/cron/verificar-emails.php:29-41`; `api/cron/verificar-lembretes.php:31` | Um `TypeError` ou uma mensagem enorme numa caixa não impede as demais caixas, os lembretes nem a fila; a mensagem conta em `emails_erro` e para depois de 5 tentativas | Unitário com estrutura `false` e com `.eml` de 40 MB (fixture sintética); cron por CLI com caixa de teste | Não |
| DES-01 | `email-service.php:119-187` | Execução com 300 mensagens já processadas em menos de 10 s; nenhuma mensagem nova perdida | Medir no log (`Início` → `concluída`) com a caixa real em modo só leitura, como na fase 5 | Não |
| DES-02 | `api/services/log-service.php` | Um arquivo por dia, apagado depois de N dias; INFO por mensagem só em modo detalhado | Unitário do nome e da limpeza | Não |
| BUG-16 | `email-service.php:577-582` | "E-mail desligado no setor" em INFO; AVISO só em falha real | Leitura do log | Não |

### Fase 2: perda de dados e segurança de baixo risco (Média)

| Item | Arquivos | Aceite | Teste | Aprov. |
|---|---|---|---|---|
| BUG-01 | `api/services/html-sanitizer.php:92-100` | "valor <100 reais" e "5<10 e 3>2" saem iguais; `<script>x</script>` continua sem tag | Casos novos em `HtmlSanitizerTest` | Não |
| BUG-02 | `api/services/demanda-service.php:168-196` | Resolver a partir de `aguardando_*` desconta a pausa em andamento | Caso novo em `FluxoDemandaTest` | Não |
| BUG-03 | `api/services/sla-service.php:240-250` | Setor sem feriados próprios usa os do Geral | Caso novo em `SLAServiceTest` | Sim (muda o SLA exibido) |
| BUG-04 + parte de SEG-15 | `api/controllers/tickets.php:643,710-714`; `api/services/demanda-service.php:69-72,97` | Auditoria de cancelar e excluir definitivamente gravada mesmo com imagem de 1 MB na descrição, sem a descrição | Integração: cancelar uma demanda com descrição de 200 KB e conferir a linha em `auditoria` | Não |
| SEG-03 | `api/services/email-fila-service.php:62-66`; `email-sender.php:456-474` | Depois do envio, `email_fila.corpo` da recuperação de senha fica vazio | Integração com `EmailFilaService` simulado | Não |
| SEG-04 | `frontend/relatorios.js:213-245`; `frontend/auditoria.js:1130-1197`; helper em `frontend/script.js` | Célula que começa com `= + - @` sai com `'` na frente; BOM em todos os CSV | Manual: título `=1+1` exportado e aberto no Excel | Não |
| SEG-05 | `tickets.php:455-463`; `comentarios.php:75-119`; `macros.php:80-83` | Corpo acima do limite volta 413 com mensagem; tela avisa antes de enviar | Chamada direta à API com 20 MB | Sim (novo limite visível) |
| BUG-05, BUG-12, BUG-18, BUG-19 | `email-logs.php:21` + `auditoria.js:1136`; `tickets.php:733-736`; `macros.php:144,191`; `usuarios.php:554-559` | CSV completo; `categoria_id` como inteiro; 400 em JSON; mensagem de vínculo | Matriz de acesso e chamadas diretas | Não |

### Fase 3: arquitetura de segurança (exige decisão do CRPRS)

| Item | Arquivos | Aceite | Teste | Aprov. |
|---|---|---|---|---|
| SEG-01 | Infraestrutura (DNS, TLS, vhost); `api/config/environment.php:138,144`; `api/middleware/auth.php:124-154` (cookie `__Host-`); `frontend/app-config.js`; `docs/implantacao-producao.md` | O Conecta responde em subdomínio próprio; o cookie não tem `Path` nem `Domain`; o `localStorage` não é compartilhado com o METRICA360 nem com o site | Matriz de acesso no novo endereço; SSO de teste | **Sim (infra)** |
| SEG-02 | `frontend/meu-painel.js:7,126,242`; `.htaccess:51,53` | O widget roda em `<iframe sandbox>` de outra origem; `elfsight` sai do `script-src` | Navegador: Meu Painel funcionando, sem violação de CSP | **Sim (CSP e visual)** |
| SEG-08 | `api/middleware/auth.php:160-190,403-417` | A sessão termina depois do limite absoluto mesmo com a aba aberta | Matriz de acesso, seção [8], com um caso novo | **Sim (comportamento)** |
| SEG-10, SEG-12, SEG-06, SEG-07 | `.htaccess:38-39`; `frontend/app-config.js:13-15`; `api/controllers/auth.php:108`; `api/services/rede-service.php:24-27` | Um único `X-Frame-Options`; origens de SSO por ambiente; login certo não zera o contador do IP; IP real lido da direita | `curl -I` em produção; `RedeServiceTest` | Sim (CSP e cabeçalhos) |

### Fase 4: desempenho web e banco

| Item | Arquivos | Aceite | Teste | Aprov. |
|---|---|---|---|---|
| DES-03 | `.htaccess` | JS e CSS com `Content-Encoding: gzip` e `Cache-Control immutable`; recarga sem revalidar os estáticos | DevTools > Network | Não |
| DES-05 | `api/services/email-sender.php` (10 consultas) | Nenhum `SELECT t.*` no envio; e-mails idênticos aos de antes | `EmailHistoricoTest` e mockups (`php mockups/gerar-mockups.php`) | Não |
| DES-06 | `api/controllers/relatorios.php`; `frontend/relatorios.js` | Relatório paginado; totais pelo SQL; exportação completa à parte | Comparar os totais antes e depois | Sim (tela muda) |
| DES-04, DES-09 | Migração nova `database/39_indices_listas.sql` | `EXPLAIN` da lista sem filesort; `notificacoes` usando `(usuario_id, criado_em)` | `EXPLAIN` antes e depois | **Sim (esquema)** |
| DES-07, DES-08, DES-10, DES-11, DES-12, DES-14 | `meu-painel.php`; `tickets.php:401-413`; `dashboard.php:251-266`; `timeline-service.php:26-30`; `atividades-acompanhamento-service.php`; `email-fila-service.php`; `registro-atividades.js:73`; `meu-painel.js:52` | Menos consultas por requisição (medir com o log de consultas do MySQL); relógios parados ao sair da tela | Integração e medição | Não |

### Fase 5: consistência funcional e UX

| Item | Arquivos | Aceite | Aprov. |
|---|---|---|---|
| BUG-11 (regra de quem comenta) | `api/controllers/comentarios.php:128,182-256` | Regra decidida e documentada no `AcessoService` | **Sim (regra)** |
| BUG-20 (status na transferência e devolução) | `api/controllers/demanda-acoes.php:182-197,279` | Ex.: transferir volta para `novo`; devolver sem responsável dá 400 | **Sim (regra)** |
| BUG-06, BUG-07 | `frontend/detalhe-demanda.js:207`; `api/controllers/merge.php:117-162` | Histórico com um "#" só e sentido correto; comentário de mesclagem em HTML e com "demanda" | Não |
| BUG-09, BUG-10 | `lembrete-service.php:150-173`; `comentarios.php:164-346` | Lembrete passa por `DemandaService::mudarStatus`; comentário pela tela em transação, com `iniciarLote`/`desfazerLote` | Não |
| BUG-13 | `frontend/login.html:1240-1268` | A mensagem da tela usa o retorno do servidor (429 e minutos) | Sim (texto visível) |
| BUG-14, BUG-15, BUG-17 | Helper `dataDoBanco()` em `script.js` e substituição nos cerca de 25 pontos; `email-sender.php:719-834`; textos de limite | Datas certas no Safari; sem confirmação para a equipe; um texto único para o limite de anexo | Sim (textos) |
| INC-06 | Endpoint `api/controllers/me.php` (novo) e `script.js:1238-1257` | Mudança de papel reflete no menu ao recarregar | Não |
| INC-08, INC-09 | `dashboard.php:189-220`; `sla-service.php:117-127` | Definições de métricas aprovadas; prazo congelado (opcional) | **Sim** |
| SEG-09, SEG-11, SEG-13, SEG-14 | `upload-service.php:37-41`; `abrir_chamado.php:222-235`; `personalizacao.php`; `auditoria-avancada.php:25-28,321-368` | ZIP disfarçado recusado; integração não usa conta interna; personalização auditada e com `__DIR__`; paginação validada | Sim (SEG-11 muda a integração) |

### Fase 6: manutenção, documentação e limpeza

| Item | Arquivos | Aceite | Aprov. |
|---|---|---|---|
| INC-02, INC-03 | `CLAUDE.md`; `README.md`; `api/config/.env.example:72` | Documentação igual ao código; `.env.example` copiável | Não |
| INC-04 (código morto) | Ver lista em 3.3 | Remover só o que for aprovado, item a item; registrar no `CLAUDE.md` | **Sim (remoção)** |
| INC-04 (colunas sem uso) e INC-07 (FKs) | Migração nova `database/40_integridade.sql` | FKs criadas com dados conferidos antes (sem órfãos); colunas mortas removidas | **Sim (esquema)** |
| INC-05, FE-01, FE-02 | `abrir_chamado.php`; templates JS; `script.js:933-950`; `detalhe-demanda.js:295`; `ajuda.js:44-45`; `index.html:947` | Vocabulário "demanda" e tokens de cor; sem `console.log`; imagens e botões com rótulo | Não (textos da integração mudam para satélites: avisar) |
| SEG-15, DES-13 | Cron `api/cron/limpeza.php` (novo), chamado por `enviar-fila.php` | Prazos de retenção aplicados e documentados | **Sim (apaga dados)** |
| MAN-01, MAN-05 | `api/tests/` | Testes de regressão dos bugs das fases 1 e 2; canal de log de teste | Não |
| MAN-02, MAN-03, MAN-04 | `email-sender.php`, `tickets.php`; `composer.json`; script de empacotamento e `docs/implantacao-producao.md` | Montador de e-mail extraído sem mudar a saída (mockups iguais); plano de PHP 8.3 ou 8.4 e PHPMailer 7; pacote de deploy sem `mockups`, `.playwright-mcp`, `docs` e testes | Sim (troca de versões) |

### Ordem recomendada e dependências

1. **Fase 0**, antes de qualquer replicação do banco.
2. **Fase 1**, que protege a entrada de demandas e todo o envio de e-mails. BUG-08 vem antes de DES-01, porque mexe no mesmo laço.
3. **Fase 2**, correções pontuais com teste e sem decisão de produto (exceto BUG-03 e SEG-05).
4. **Fase 3**: SEG-01 depende do CRPRS (DNS e servidor) e muda a URL. Decidir antes de avisar os satélites e antes de mexer em CORS e SSO.
5. **Fases 4 a 6**, em paralelo conforme a disponibilidade. As migrações 39 e 40 precisam de backup e do `php database/migrar.php` documentado.

---

## 7. Status da execução (atualizado em 03/11/2026)

Execução feita entre 01 e 03/11/2026, sem commit. O detalhe, arquivo por arquivo, os testes e as decisões pendentes estão em `docs/relatorios/AUDITORIA_2026-11-01_EXECUCAO.md`.

**Resultado:** 31 corrigidos, 8 parcialmente corrigidos, 24 aguardando aprovação, 2 não feitos (não exigiam aprovação).

**Testes:** PHPUnit com 205 testes OK (eram 181); matriz de acesso com 107 OK e 0 falha.

**Legenda:**
- **Corrigido**
- **Parcial**: parte feita, o motivo do restante na coluna ao lado.
- **Aguarda aprovação**: decisão do usuário, marcada no plano.
- **Não feito**: não exigia aprovação, ficou para depois.

### Segurança

| Achado | Status | Observação |
|---|---|---|
| SEG-01 | Decidido em 03/11/2026: **fica em subpasta** (`crp07.org.br/conecta`) | O usuário optou por não usar subdomínio. O METRICA360 será absorvido pelo Conecta (ver nota no topo de `INTEGRACAO_METRICA360_CONECTA_2026-11-01.md`), o que elimina um dos dois sistemas que dividiam a origem; resta o site institucional. Risco aceito |
| SEG-02 | Aguarda aprovação | CSP e aparência |
| SEG-03 | Corrigido | `EmailFilaService::marcarEnviado()` apaga o corpo de `recuperacao_senha`; teste `EmailFilaTest` |
| SEG-04 | Corrigido | `celulaCsv()` em `script.js`, nas 3 exportações, e BOM em todas |
| SEG-05 | Aguarda aprovação | Novo limite visível |
| SEG-06 | Corrigido | O login certo zera só a conta |
| SEG-07 | Corrigido | `RedeService::ipDoEncaminhamento()`, da direita para a esquerda; teste |
| SEG-08 | Aguarda aprovação | Duração da sessão |
| SEG-09 | Corrigido | `UploadService::validarArquivo`/`validarConteudo` (ZipArchive); teste |
| SEG-10 | Aguarda aprovação | Cabeçalhos |
| SEG-11 | Aguarda aprovação | Muda a integração |
| SEG-12 | Aguarda aprovação | Configuração de SSO (marcado junto com SEG-10) |
| SEG-13 | Parcial | Feito: `__DIR__` e auditoria `alterar_logo`. Ficou: `*.backup` e `tipo=login`, cuja remoção exige aprovação |
| SEG-14 | Corrigido | Paginação validada; exportação com teto de 50 mil e aviso |
| SEG-15 | Parcial | Feito: auditoria sem a descrição (com BUG-04). Ficou: retenção, que apaga dados e aguarda aprovação |

### Bugs

| Achado | Status | Observação |
|---|---|---|
| BUG-01 | Corrigido | `HtmlSanitizer::texto()` remove só tags reais; testes |
| BUG-02 | Corrigido | Pausa em andamento descontada; teste |
| BUG-03 | Aguarda aprovação | Muda o SLA exibido, e falta cadastrar os feriados |
| BUG-04 | Corrigido | `DemandaService::resumoParaAuditoria()`; teste com descrição de 200 KB |
| BUG-05 | Corrigido | `email-logs.php?exportar=1` (até 10 mil) e aviso na tela |
| BUG-06 | Corrigido | `valor_anterior = 'secundaria'` e um "#" só |
| BUG-07 | Corrigido | Comentários de mesclagem em HTML, com "demanda" |
| BUG-08 | Corrigido | `Throwable` por mensagem e caixa; tentativa contada antes; anexo recusado sem baixar; shutdown nos crons; testes |
| BUG-09 | Corrigido | `DemandaService::mudarStatus`; teste |
| BUG-10 | Corrigido | Transação com `iniciarLote`/`desfazerLote`; conferido por HTTP |
| BUG-11 | Aguarda aprovação | Regra de quem comenta |
| BUG-12 | Corrigido | `(int)` no UPDATE |
| BUG-13 | Aguarda aprovação | Texto visível |
| BUG-14 | Corrigido | `dataDoBanco()` em 39 pontos |
| BUG-15 | Aguarda aprovação | Muda quem recebe e-mail (marcado com os textos) |
| BUG-16 | Corrigido | `EmailSender::ultimoEmailFoiDesligado()` |
| BUG-17 | Aguarda aprovação | Textos |
| BUG-18 | Corrigido | 400 em JSON |
| BUG-19 | Corrigido | `registros_atividades` entra nos vínculos |
| BUG-20 | Aguarda aprovação | Regra do fluxo |

### Inconsistências

| Achado | Status | Observação |
|---|---|---|
| INC-01 | Aguarda aprovação | Dados do banco local |
| INC-02 | Parcial | `CLAUDE.md` corrigido; `README.md` não foi reescrito |
| INC-03 | Parcial | `.env.example` corrigido; o comentário do `.env` local (arquivo de segredos) não foi mexido |
| INC-04 | Aguarda aprovação | Remoção de código e colunas |
| INC-05 | Parcial | Integração corrigida (textos e cores); os hex dos templates JS ficaram para o projeto de CSP |
| INC-06 | Corrigido | `api/controllers/me.php` e `atualizarUsuarioDoServidor()` |
| INC-07 | Aguarda aprovação | Esquema |
| INC-08 | Aguarda aprovação | Definição de métricas |
| INC-09 | Aguarda aprovação | Prazo de SLA |

### Desempenho

| Achado | Status | Observação |
|---|---|---|
| DES-01 | Corrigido | Memória de UIDs por caixa (arquivo em `backend/cache/`, sem esquema), visão geral em lote e conferência no banco de uma vez. Medido de 17–35 s para 3–6 s por execução |
| DES-02 | Corrigido | Arquivo por dia, retenção e `detalhe()`; teste |
| DES-03 | Corrigido | `.htaccess` com `mod_deflate` e `Cache-Control immutable` (gzip não conferível no Apache local) |
| DES-04 | Aguarda aprovação | Esquema |
| DES-05 | Corrigido | `EmailSender::COLUNAS_DEMANDA` |
| DES-06 | Aguarda aprovação | A tela muda |
| DES-07 | Corrigido | Cache de 15 min (`CacheArquivoService`) |
| DES-08 | Não feito | Risco de deixar de detectar mudanças sem ganho no volume atual; fazer com DES-04 |
| DES-09 | Aguarda aprovação | Esquema |
| DES-10 | Corrigido | Cache de 60 s por escopo |
| DES-11 | Parcial | Linha do tempo corrigida; atividades-acompanhamento ficou |
| DES-12 | Não feito | Mexe no envio SMTP em uso; precisa de teste contra o servidor real |
| DES-13 | Aguarda aprovação | Apaga dados (com SEG-15) |
| DES-14 | Corrigido | Relógios param fora da tela |

### Frontend e manutenção

| Achado | Status | Observação |
|---|---|---|
| FE-01 | Corrigido | 23 `console.log` retirados |
| FE-02 | Corrigido | `alt` e `aria-label` |
| MAN-01 | Parcial | Testes de regressão de BUG-01, 02, 04, 08 e 09, SEG-03, SEG-07, SEG-09, DES-01 e DES-02; faltam os controllers e serviços listados |
| MAN-02 | Aguarda aprovação | Refatoração grande (linha do plano marcada) |
| MAN-03 | Aguarda aprovação | Troca de versões |
| MAN-04 | Parcial | Lista de exclusões em `docs/implantacao-producao.md` §6b; sem script de empacotamento |
| MAN-05 | Corrigido | `LogService::usarDiretorio()` no bootstrap dos testes |

**Fase 0, item "Produção":** a tabela de conferência está em `docs/implantacao-producao.md` §6a. Os valores precisam ser preenchidos no servidor, que não pôde ser acessado daqui.

**Achado novo durante a execução (não corrigido):** em `api/services/email-sender.php`, `use PHPMailer\PHPMailer\Exception;` faz todo `catch (Exception $e)` daquele arquivo capturar só a exceção do PHPMailer.
