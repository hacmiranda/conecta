# Integração total do METRICA360 ao Conecta CRPRS: análise e plano (01/11/2026)

Como levar o METRICA360 para dentro do Conecta: o mesmo banco de dados, a hospedagem dentro do diretório do Conecta e um login só. O documento termina com um plano em fases.

> **Decisão de 03/11/2026 (leia antes do resto).** Como nenhum dos dois sistemas está em produção e os convites/avaliações do METRICA360 local são dados de teste, o usuário escolheu ir **direto para a Fase I9**, sem as etapas intermediárias (I4 a I8): o METRICA360 deixa de existir como sistema e suas telas viram o módulo "Satisfação" do Conecta, no `db_conecta`, com tabelas `pesquisa_*` (não `m360_*`), login único (o do Conecta) e papéis novos (`coordenador`, `supervisor`, `coordenador_geral`, `operador` no lugar de `atendente`). O endereço continua sendo a subpasta `/conecta` (SEG-01: sem subdomínio). Vêm só pessoas, setores e configurações; nenhum convite, avaliação ou auditoria. O plano executado está nas fases 1 a 8 do plano de trabalho de 03/11/2026 e é resumido no `CLAUDE.md`. Este relatório continua válido como análise de riscos.

**Só leitura.** Nenhum código, configuração, banco ou pasta foi alterado. O único arquivo criado no projeto é este documento. No MySQL só rodaram `SELECT`, `SHOW` e `EXPLAIN`, numa sessão aberta como somente leitura.

**Base examinada:**
- Conecta:
  - `main` em `1ede200` e o `CLAUDE.md`;
  - a auditoria de 01/11/2026 (`docs/relatorios/AUDITORIA_2026-11-01.md`) e o roteiro `docs/implantacao-producao.md`;
  - o plano de origem da Área do Funcionário (`C:\Users\hugo.miranda\.claude\plans\planejar-a-implementa-o-transfer-ncia-bubbly-hanrahan.md`).
- METRICA360 (`C:\xampp\htdocs\metrica360`):
  - ramo `melhorias-auditoria-2026-10` (`c8b157b`, com a API do Meu Painel);
  - ramo `remover-area-funcionario` (`b95a128`), que é o **estado de partida** desta integração.
- Bancos locais `db_conecta` e `db_metrica360`, no mesmo MySQL 8.4.10.
- Cabeçalhos HTTP reais dos dois sistemas, no Apache local.

**O que foi executado:**
- `vendor/bin/phpunit --testsuite unit` no Conecta: **OK, 155 testes**.
- A suíte de integração do Conecta **não** rodou. Ela desfaz a transação, mas avança os contadores AUTO_INCREMENT do banco que vai para a produção.
- Os testes do METRICA360 **não** rodaram: gravam e apagam no banco real sem transação (ver 3.1).

**Convenções** (as mesmas da auditoria de 01/11/2026):
- **Certeza:** confirmado (por código, consulta ou teste), provável ou hipótese.
- **Esforço:** P (até meio dia), M (1 a 3 dias), G (mais de 3 dias ou depende de infraestrutura).
- **Aprov.:** o passo precisa de autorização do usuário. Vale para remoção de código, esquema em produção, CSP ou cookie, mudança visível e infraestrutura.
- **Locais** no formato `arquivo:linha`. Os caminhos do METRICA360 são relativos a `C:\xampp\htdocs\metrica360`, e as linhas citadas são as da cópia de trabalho (ramo `melhorias-auditoria-2026-10`). No ramo `remover-area-funcionario`, algumas linhas de `src/models/user_model.php` andam de 1 a 3 posições.

---

## 1. Resumo executivo

**Recomendação.** Integrar em degraus, cada um reversível sozinho:
1. **Um banco só, o `db_conecta`.**
   - As tabelas do METRICA360 entram com o prefixo `m360_`, em `utf8mb4_0900_ai_ci`.
   - Elas se ligam por chave a `usuarios` e `setores` do Conecta.
   - As tabelas de atividades do METRICA360 não vêm, como já decidido ("sem cópia do histórico").
   - O `db_metrica360` antigo fica arquivado, no lugar do script destrutivo previsto.
2. **METRICA360 como subaplicação em `/conecta/metrica360/`**, com roteador, sessão e `.env` próprios.
3. **Login único pelo Conecta.**
   - O Conecta grava no banco um ticket de entrada de uso único, válido por 60 s, e o METRICA360 o consome.
   - A cada requisição, o METRICA360 confere no banco se a sessão do Conecta continua valendo.
   - Nenhum segredo é dividido entre os dois `.env`.
4. **Fim das chamadas HTTP em duas etapas.**
   - Primeiro, as leituras (acesso ao Meu Painel, painel e cruzamento) viram consultas diretas.
   - Depois, as escritas (pedido de convite) passam por uma fila no banco, processada pelo cron do METRICA360.
5. **Módulo do Conecta** como destino final opcional, de esforço G: as telas do METRICA360 reescritas dentro do Conecta.

**O que muda para quem usa:**
- Um login só.
- O METRICA360 passa a abrir em `.../conecta/metrica360/`, e os endereços antigos redirecionam.
- O cidadão recebe o mesmo convite.

**Principais riscos** (confirmados no código ou no banco, salvo onde está marcado):
- **Sete tabelas homônimas** com estruturas diferentes: `setores`, `categorias`, `feriados`, `tentativas_acesso`, `atividades_tipos`, `registros_atividades` e `registros_atividades_itens`.
- **IDs de setor trocados.** "Cadastro" é o setor 2 no Conecta e o 3 no METRICA360; "Cobrança" é o 3 e o 2. Um cruzamento por id corromperia dados sem dar erro.
- **Colação diferente.** A junção por e-mail entre `usuarios` e `users` falha com o erro 1267 (testado).
- **Identidade:**
  - dos 43 usuários ativos do METRICA360 local, só 5 têm conta no Conecta;
  - 26 servidores com convites (156 convites) não têm conta no Conecta;
  - um coordenador do METRICA360 coordena até 5 setores, e o gestor do Conecta tem um setor só.
- **Produção.** Os bancos ficam em hosts DBaaS com nomes e usuários diferentes. Os dois nomes resolvem para o mesmo IP, então é provável que estejam na mesma instância. Se uma conta pode ler os dois depende do provedor (hipótese).
- **Segredo exposto (fora do escopo, mas urgente).** O repositório do METRICA360 no GitHub (`origin/master`) tem um dump com a senha SMTP em texto puro e dados de servidores. A troca da senha não depende desta integração.

**Decisões que dependem do usuário** (lista completa na seção 7):
1. Banco único com prefixo, e arquivamento do banco antigo.
2. Contas e papéis dos servidores.
3. Login único e retirada do login próprio do METRICA360.
4. Convite manual sem HTTP (assíncrono) ou não.
5. Endereço (subpasta ou subdomínio) e destino final (subaplicação ou módulo).

---

## 2. Situação atual

### 2.1 Conecta CRPRS

- **Estado.** PHP 8.2 com MySQL 8.4.10 no local; JS sem build. **Ainda não está em produção** (`CLAUDE.md`, "Pendências"). O banco local será replicado na produção.
- **Banco `db_conecta`:**
  - 46 tabelas, todas `utf8mb4_0900_ai_ci`;
  - 30 migrações aplicadas (9 a 38);
  - `usuarios.id INT` e `usuarios.email VARCHAR(100)` único;
  - 13 usuários (2 admin, 2 gestor, 2 operador, 7 cliente) e 3 setores (Geral, Cadastro, Cobrança).
- **Conexão.** Uma por requisição, com `SET time_zone = '-03:00'` (`api/config/database.php:22-28,52`).
- **Sessão:**
  - JWT em cookie HttpOnly `conecta_sessao`, com `Path` igual ao caminho de `base_url` (`/conecta`) e `SameSite=Strict` (`api/middleware/auth.php:15,124-143`);
  - o estado do usuário é relido do banco a cada requisição (`:204-252`);
  - CSRF pelo claim `csrf` do JWT e o cabeçalho `X-CSRF-Token` (`:319`); o navegador guarda o token em `localStorage.cd_csrf_token` (`frontend/script.js:38`).
- **Migrações.** Executor `database/migrar.php`: arquivos `database/<n>_*.sql` com n ≥ 9, controlados pela tabela `migracoes` (`:30-36`).
- **Cron:**
  - cadeia `verificar-emails` → `verificar-lembretes` → `enviar-fila` → `enviar-pesquisas` (`api/cron/enviar-fila.php:39`);
  - trava `GET_LOCK('conecta_cron_<nome>')` (`api/cron/acesso-cron.php:38-42`).
- **Dependências.** `api/composer.json`: php-jwt 7.2.1, PHPMailer 6.10.0 e HTML Purifier 4.19.1. O `api/vendor` fica fora do Git (`.gitignore:4`, `**/vendor/`).

### 2.2 METRICA360

- **Roteamento:**
  - PHP sem framework e sem autoloader; `index.php` tem um `switch` de ~50 rotas, com `?route=` reescrito pelo `.htaccess` (`RewriteBase /metrica360/`, `.htaccess:2`);
  - `BASE_URL` é fixo em `src/config/security.php:20`, e o JS recebe o valor por `window.CRPRS_BASE_URL`.
- **Efeitos na inclusão.** `src/config/security.php` é incluído em toda requisição e:
  - abre a sessão (`session_start()`, `:17`);
  - manda `X-Frame-Options: DENY` (`:32`), HSTS com `includeSubDomains` (`:36`) e um CSP com nonce (`:41-42`).
- **Sessão.** Confirmado por HTTP local: `Set-Cookie: PHPSESSID=…; path=/; HttpOnly; SameSite=Strict`. O cookie vai para o domínio inteiro, inclusive para o Conecta.
- **Configuração:**
  - `.env` e `.env.local` sem sufixo de ambiente; o `.env.local` tem prioridade (`src/config/env.php:15-26`);
  - `src/config/database.php` fica fora do Git e abre um `PDO` único, **sem `time_zone`**.
- **Banco `db_metrica360`:**
  - 20 tabelas, quase todas `utf8mb4_unicode_ci` (2 em `0900_ai_ci`);
  - `users.id INT UNSIGNED` e `users.email VARCHAR(150)`;
  - 46 usuários (6 admin, 36 atendente, 3 coordenador, 1 supervisora), 20 setores, 214 convites, 221 avaliações e 254 registros de auditoria.
- **Papéis e escopo:**
  - papéis `admin`, `atendente`, `coordenador`, `coordenadora_geral` e `supervisora`; todos entram no painel, menos `atendente` (`src/config/acesso.php:4`);
  - admin, supervisora e coordenadora geral veem tudo; o coordenador vê os setores em que `setores.coordenador_id` é ele, e podem ser vários (`src/utils/escopo_setor.php:8-18`).
- **Senhas.** bcrypt (`src/models/user_model.php:88,94,131`), com mínimo de 12 caracteres (`src/utils/validator.php:27-39`).
- **Segredos no banco em texto puro:** `configuracoes_smtp.password` (`src/models/configuracao_smtp_model.php:18-40`) e `configuracoes.google_places_api_key`.
- **E-mail.** PHPMailer 7.0.2, com o `vendor/` versionado de propósito (inclui o PHPUnit). O envio é síncrono, dentro da requisição (`src/services/convite_envio_service.php:79-87`).
- **Ramos:**
  - `origin/master` (`37632cc`): hipótese de ser o que está publicado;
  - `melhorias-auditoria-2026-10` (`c8b157b`): 36 commits à frente do `origin/master`. Os dois últimos, com a integração, ainda não foram enviados ao GitHub: `4d35483` (convite automático e cron real) e `c8b157b` (API do Meu Painel);
  - `remover-area-funcionario` (`b95a128`, só no local): tira 16 mil linhas.
- **Depois da remoção:**
  - restam ~5 mil linhas de backend, ~6 mil de views, ~4 mil de JS e ~8,5 mil de CSS;
  - o código usa 14 tabelas, com ~158 referências SQL: `users` 34, `convites_avaliacao` 32, `feedbacks` 21, `setores` 18, `categorias` 12, `frases_motivacionais` 9, `configuracoes` 6, `tentativas_acesso` 5, `feriados` 5, `feedback_categorias` 5, `otp_tokens` 4, `system_changelog` 3, `configuracoes_smtp` 2 e `audit_logs` 2;
  - `feriados` fica só com a tela de cadastro, sem nenhum consumidor.

### 2.3 Integração hoje

| Fluxo | Quem dispara | Rota no METRICA360 | Lado Conecta | Lado METRICA360 |
|---|---|---|---|---|
| Pesquisa ao encerrar a demanda | cron do Conecta (fila `pesquisa_satisfacao`) | `POST /api/integracao/convite` | `api/services/pesquisa-satisfacao-service.php:83-161,266-269` | `src/controllers/integracao_controller.php:34-88` |
| Item "Meu Painel" no menu | **cada carga** da aplicação | `GET /api/integracao/funcionario?email=` | `api/controllers/meu-painel.php:66-77`; `frontend/script.js:993` | `:93-108` |
| Tela Meu Painel | servidor | `GET /api/integracao/painel` | `meu-painel.php:80-88` | `:111-165` (dispara o processador de convites, `:119,284-296`) |
| Convite manual | servidor | `POST /api/integracao/convite-manual` | `meu-painel.php:90-122` | `:168-201` |
| Cruzamento atividades × satisfação | admin e gestor | `GET /api/integracao/satisfacao` | `api/controllers/atividades-acompanhamento.php:155` | `:204-222` |
| Expirações e reenvios | cron-job.org, a cada 15 min | `/cron-processar-convites` (`X-Cron-Key`) | — | `:322-342` |

- **Autenticação.** As cinco primeiras rotas usam `X-API-KEY`, conferida com `hash_equals` e limite por IP (`:305-319`).
- **Cliente.** `Metrica360Client` (`api/services/metrica360-client.php:27-57`): curl com 10 s de limite, certificado validado e sem seguir redirecionamento.
- **Identificação do servidor.** O e-mail do usuário do Conecta, lido no banco (`meu-painel.php:52-55`), casado com `users.email` (`src/models/user_model.php:28-32`).

### 2.4 Os dois bancos lado a lado (MySQL local 8.4.10)

| Nome | No Conecta | No METRICA360 | Depois da remoção da Área do Funcionário |
|---|---|---|---|
| `setores` | `id INT`; nome, ativo | `id INT UNSIGNED`; `ativo_painel`, `coordenador_id`, colunas de prazo | colide |
| `categorias` | subcategoria de demanda (setor, macrocategoria) | etiqueta da avaliação (emoji, ordem) | colide |
| `feriados` | por setor; único (setor_id, data) | global; único (data) | colide |
| `tentativas_acesso` | `chave CHAR(40)`, `acao` | `chave VARCHAR(40)`, `ip` | colide |
| `atividades_tipos`, `registros_atividades`, `registros_atividades_itens` | do Conecta (migração 38) | do METRICA360 | somem do METRICA360 e não vêm |

**Equivalentes com nomes diferentes:**
- `users` ↔ `usuarios`;
- `audit_logs` ↔ `auditoria`;
- `otp_tokens` ↔ `tokens_recuperacao`;
- `configuracoes_smtp` ↔ `setor_emails` + `.env`;
- `abonos_atividades` ↔ `atividades_abonos`;
- `funcionario_favoritos` ↔ `atividades_favoritas`;
- `funcionario_dias_remotos` ↔ `atividades_dias_remotos`.

**Outras diferenças (confirmadas):**
- **Sinal do id.** `usuarios.id` é `INT` e `users.id` é `INT UNSIGNED`. O MySQL não aceita chave estrangeira entre colunas de sinal diferente.
- **Tamanho do e-mail.** 100 no Conecta e 150 no METRICA360. O maior e-mail no banco local tem 32 caracteres.
- **Colação:**
  - `SELECT COUNT(*) FROM db_conecta.usuarios u JOIN db_metrica360.users m ON m.email = u.email` → `ERROR 1267 Illegal mix of collations`;
  - com `COLLATE` explícito, 5 e-mails coincidem;
  - converter para `utf8mb4_0900_ai_ci` não cria duplicatas em `users.email`, `users.username`, `setores.nome` nem `categorias.nome` (conferido).
- **Charset padrão.** Os dois esquemas têm `utf8mb3` como padrão; as tabelas é que declaram `utf8mb4`.
- **Setores.** 20 no METRICA360 e 3 no Conecta. Só "Cadastro" e "Cobrança" têm o mesmo nome, com IDs trocados.
- **Coordenação.** No banco local, um coordenador coordena 5 setores, outro 4 e outro 2.
- **Contas do MySQL.** No local só existe `root`, usado pelos dois sistemas.

### 2.5 Produção: o que se sabe

- **Conecta:**
  - endereço `https://crp07.org.br/conecta`;
  - banco `db_conecta` em `crpdesk.vpshost0269.mysql.dbaas.com.br`, conta `crpdesk` (herdados do CRPDESK, a confirmar; `api/config/environment.php:124-126,138`).
- **METRICA360:**
  - endereço `https://crp07.org.br/metrica360`;
  - banco `db_metrica360` em `db_metrica360.vpshost0269.mysql.dbaas.com.br`, com conta própria;
  - o `.env` local do METRICA360 guarda esses valores de produção, e o `.env.local` os sobrepõe.
- **Instância.** Os dois hosts resolvem para o mesmo IP (179.188.52.51): **provável** mesma instância MySQL. Se uma conta pode acessar os dois bancos, ou se dá para criar contas com privilégio por tabela: **hipótese**.
- **Versão.** As migrações do Conecta usam `utf8mb4_0900_ai_ci`, colação do MySQL 8. Que o servidor de produção a aceita: **hipótese**, a confirmar já para o primeiro deploy do Conecta.
- **Origem.** Site, Conecta e METRICA360 dividem a mesma origem `https://crp07.org.br` (SEG-01 da auditoria).
- **PHP.** O `.user.ini` do METRICA360 aponta para `/home/crprssite-oficia1/...`. `.user.ini` só vale em PHP por CGI ou FPM: **provável** que seja esse o modo.

---

## 3. Análise por eixo

### 3.1 Banco compartilhado

**Nomes e prefixo:**
- Dos sete nomes que colidem, três somem do METRICA360 com a remoção da Área do Funcionário. Restam quatro colisões reais: `setores`, `categorias`, `feriados` e `tentativas_acesso`.
- Proposta: prefixo `m360_` nas 14 tabelas que o METRICA360 ainda usa, e não só nas quatro. Assim fica claro a quem cada tabela pertence e não há colisão futura. O custo é o mesmo, porque as ~158 referências SQL passam por um mapa de nomes (Fase I2).
- O MySQL não aceita curinga em nome de tabela no `GRANT`. O prefixo organiza, mas a permissão por tabela continua sendo uma lista.

**Tipos e chaves:**
- As `m360_*` mantêm os tipos de hoje entre si.
- Duas colunas novas fazem o vínculo, em `INT` para casar com o Conecta:
  - `m360_users.usuario_conecta_id`, única, → `usuarios(id)` `ON DELETE SET NULL`;
  - `m360_setores.setor_conecta_id` → `setores(id)` `ON DELETE SET NULL`. Não é única: dois setores do METRICA360 podem corresponder a um só do Conecta.
- E-mail com mais de 100 caracteres não cabe em `usuarios.email`. O importador lista esses casos e não os vincula.

**Colação, charset e fuso:**
- **Colação.** Todas as `m360_*` nascem em `utf8mb4_0900_ai_ci`. Com isso, as junções por e-mail deixam de dar erro e passam a usar índice.
- **Charset.** Os dois DSN já usam `utf8mb4`. Como o padrão dos esquemas é `utf8mb3`, toda tabela nova declara o charset, como as migrações do Conecta já fazem.
- **Fuso:**
  - o Conecta faz `SET time_zone = '-03:00'` (`api/config/database.php:52`), e o METRICA360 não;
  - a maior parte das datas do METRICA360 é `TIMESTAMP`, guardada em UTC e convertida pela sessão, então não há dado a converter;
  - o que muda é o `NOW()` das consultas (quarentena, expiração, limite de tentativas), que passa a seguir o fuso do PHP;
  - antes, conferir o `@@time_zone` da produção. Se estiver em UTC (hipótese), essas contas estão hoje deslocadas em 3 h.

**Identidade:**
- O vínculo usa o e-mail, como já decidido, mas fica gravado como chave (`usuario_conecta_id`). Depois disso, uma troca de e-mail num dos lados não quebra o vínculo.
- Dos 43 servidores ativos do METRICA360 local, 38 não têm conta no Conecta.
- Isso já afeta o plano de 27/10: o Registro de Atividades do Conecta só aparece para gestor e operador com setor (`frontend/script.js:971-974`). Ver as Fases I1 e I3.

**Setores e coordenação:**
- Os setores são ligados por nome, com revisão humana, e **nunca por id**, porque os IDs estão trocados.
- O METRICA360 tem papéis sem equivalente no Conecta, onde o gestor tem um setor só (`AcessoService::setorEscopo`):
  - o coordenador, com vários setores;
  - a supervisora e a coordenadora geral, que veem tudo sem ser admin.

**Contas e permissões do MySQL:**
- Proposta para o banco único, se o provedor permitir:

  | Conta | Permissões |
  |---|---|
  | `conecta_app` | DML nas tabelas do Conecta; `SELECT` nas `m360_*` e nas visões; `INSERT` e `UPDATE` em `m360_pedidos_convite` e `m360_tickets_entrada` |
  | `m360_app` | DML nas `m360_*`; `SELECT` em `usuarios` (só `id`, `nome`, `email`, `ativo`, `token_versao`), `sessoes_revogadas` e `setores` |
  | `migrador` | DDL, só pela linha de comando (`php database/migrar.php`) |

- Se o provedor só der uma conta por banco, os dois sistemas usarão a mesma, e isso precisa ficar registrado:
  - um SQL injection no METRICA360 passaria a ler os dados das demandas;
  - o link de redefinição de senha guardado em texto puro em `email_fila.corpo` (SEG-03 da auditoria) permitiria tomar contas do Conecta, inclusive de admin.
- Por isso a correção da SEG-03 entra como pré-requisito da Fase I4.

**Segredos guardados no banco:**
- **METRICA360.** A senha SMTP (`configuracoes_smtp.password`, 20 caracteres no banco local, sem cifra) e a chave do Google Places (`configuracoes.google_places_api_key`) ficam em texto puro.
- **Conecta.** As senhas das caixas são cifradas (`setor_emails.imap_pass_cifrada` e `smtp_pass_cifrada`, prefixo `v2:`, AES-256-GCM).
- **Proposta.** Num banco só, quem lê o banco lê tudo. Cifrar os dois segredos do METRICA360, com a chave no `.env` dele (Fase I2).

**Migrações:**
- O Conecta tem executor com controle (`migracoes`). O METRICA360 tem scripts avulsos em `database/` e `migrations/`, sem registro do que já rodou.
- **Proposta:**
  - no banco único, toda DDL passa pelo executor do Conecta, inclusive a do METRICA360;
  - usar o próximo número livre, porque a auditoria já propôs a 39 (índices) e a 40 (integridade);
  - os scripts do METRICA360 ficam só para o banco antigo.
- **Consequência.** Uma mudança de esquema do METRICA360 passa a exigir um arquivo no repositório do Conecta. É um argumento para um repositório só no fim (opção 4.7).

**Transações:**
- **Hoje** não há transação cruzada:
  - o Conecta grava `pesquisa_satisfacao` depois do commit da troca de status (`api/controllers/tickets.php:754,799-800`) e entrega por HTTP;
  - o METRICA360 cria o convite e manda o e-mail na mesma chamada, e apaga o convite se o e-mail falhar (`src/services/convite_envio_service.php:68-90`).
- **Com o banco único**, uma transação só cobre as duas pontas quando o mesmo processo grava nas duas. Exemplo: o Conecta gravando o pedido em `m360_pedidos_convite`.
- O envio de e-mail continua fora de qualquer transação, nos dois sistemas.

**Testes do METRICA360:**
- Gravam e apagam no banco real, sem transação, e apagam chaves reais de `configuracoes`, como `expiracao_token_dias` (`tests/services/ConviteEnvioServiceTest.php:23-44`, `tests/models/ConviteExpiracaoTest.php:17-33`).
- Rodados contra o banco único, mexeriam nas regras de convite de produção. Corrigir antes da Fase I4.

**Banco local que vira produção:**
- Dados reais do METRICA360 **nunca** entram no `db_conecta` local durante o desenvolvimento.
- O esquema vazio (`m360_*`) entra por migração. Os dados entram só no corte, a partir do dump de produção.
- O ensaio usa um banco descartável.

**Arquivo no lugar do script destrutivo:**
- `migrations/2026_10_27_remover_area_funcionario.sql` (ramo `remover-area-funcionario`) copia e apaga tabelas e colunas no `db_metrica360`. Com o banco único, ele deixa de ser necessário.
- O `db_metrica360` inteiro fica intacto e só leitura, como arquivo e como caminho de volta.

**Volume:**
- No banco local: 214 convites, 221 avaliações, 59 vínculos entre avaliação e categoria e 254 registros de auditoria.
- A importação leva segundos. A janela de manutenção é ditada pelas conferências, não pelos dados.

### 3.2 METRICA360 dentro do diretório do Conecta

**Endereços:**
- **Caminho.** Mudar para `/conecta/metrica360` é pouco trabalho: ler `BASE_URL` do `.env` e trocar o `RewriteBase`.
- **Links em e-mail.** Vêm de `urlAbsoluta()`, que junta `APP_URL` (o domínio, que não muda) e `BASE_URL` (`src/utils/url.php:4-9`).
- **Links já enviados quebrariam:**
  - `/metrica360/avaliar?t=…`, válido por 7 dias e reenviado depois de 48 h;
  - `/metrica360/primeiro-acesso?t=…`, válido por 48 h.

  Proposta: `RedirectMatch 301` de `/metrica360/(.*)` para `/conecta/metrica360/$1`, mantido por pelo menos 90 dias.
- **API.** O POST da API não pode depender do redirecionamento, porque o `Metrica360Client` não segue redirecionamento (`api/services/metrica360-client.php:41`). O `METRICA360_URL_PROD` muda na mesma hora.
- **Textos com o caminho fixo:** `src/views/admin/ajuda.php:194-197` e `src/views/admin/faq.php:75`.

**O `.htaccess` do Conecta passa a valer na subpasta:**
- **Bloqueios.** `FilesMatch` (`.htaccess:8-10`) e `RedirectMatch 404` (`:19`) ajudam: barram `.env`, `.md`, `.sql`, `docs/` e `database/`. Não conflitam com as rotas atuais do METRICA360 (conferido contra o padrão, por exemplo `api/testar-smtp` e `api/salvar-configuracoes-gerais`). Uma rota futura chamada `docs` ou `database` daria 404.
- **Reescrita.** O `RewriteEngine` do METRICA360 substitui as regras do pai, que é o padrão do Apache sem `RewriteOptions Inherit`.
- **Cabeçalhos.** `Header always set` (`.htaccess:34-61`) **soma** aos cabeçalhos do PHP, não os substitui.
  - Confirmado no Apache local: `api/controllers/auth.php` sai com dois `X-Frame-Options`, `SAMEORIGIN` (do `.htaccess`) e `DENY` (do PHP, `:4`).
  - Na subpasta, cada página do METRICA360 sairia com dois CSP e dois `X-Frame-Options`.

**CSP:**
- O navegador aplica os dois CSP ao mesmo tempo, e vale o mais restrito de cada diretiva. Para as páginas do METRICA360 isso funciona:

  | Diretiva | Conecta (`.htaccess:51`) | METRICA360 (`security.php:41`) | Efeito na subpasta |
  |---|---|---|---|
  | `script-src` | `'self' 'unsafe-inline'`, CDNs, Turnstile, Elfsight | `'self'`, nonce, Turnstile, Elfsight | passa o que os dois aceitam: o próprio site, o Turnstile, o Elfsight e o script embutido com nonce |
  | `style-src` | `'unsafe-inline'`, Google Fonts, CDNs, Elfsight | `'unsafe-inline'`, Elfsight | funciona |
  | `frame-src` | sem `'self'` | `'self'`, Turnstile, Elfsight | iframe do próprio site barrado (o METRICA360 não usa) |
  | `frame-ancestors` | `'self'` | `'none'` | `'none'` |

- Proposta: o Conecta deixa de mandar os cabeçalhos globais para `/conecta/metrica360/`, e o METRICA360 manda só os dele. A condição `expr=` no próprio `Header` resolve; o `.htaccess` já usa esse recurso no HSTS (`.htaccess:57`). **Aprov.** (CSP)
- **HSTS.** O METRICA360 manda `includeSubDomains` (`security.php:36`), o que já vale hoje para todo `*.crp07.org.br`. Só pesa se houver subdomínio sem HTTPS.

**Cookies:**
- **`PHPSESSID`.** O cookie do METRICA360 usa `path=/` (confirmado por HTTP) e vai para todo o domínio: site, Conecta e qualquer outro PHP que use o nome padrão.
  - Proposta: nome próprio (`m360_sessao`) e `cookie_path` igual a `BASE_URL`.
  - **Aprov.** (cookie): quem estiver logado entra de novo uma vez.
- **`conecta_sessao`.** O cookie do Conecta (`Path=/conecta`) passa a chegar ao METRICA360.
  - A favor: dá um sinal extra para o login único (3.3).
  - Cuidado: o JWT leva o claim `csrf`. O METRICA360 nunca pode registrar `$_COOKIE` nem cabeçalhos em log ou em página de erro.

**`.env` e ambiente:**
- **Separados.** Os dois `.env` continuam separados:
  - `api/config/.env`: chaves `*_LOCAL`/`*_PROD`, escolhidas por `APP_ENV=local|production`;
  - `metrica360/.env` + `.env.local`: sem sufixo, com `APP_ENV=development|production`.

  Os vocabulários diferem (`local` × `development`). Não unificar antes do módulo.
- **`database.php`.** `src/config/database.php` não é versionado (`.gitignore` do METRICA360), e o deploy precisa recriá-lo.
- **Segredos da cópia.** O `.env` local do METRICA360 guarda valores de produção. Copiar a pasta para dentro do Conecta leva esses segredos junto.
- **`.gitignore` do Conecta.** Ignora `.env` e `api/config/.env` (`.gitignore:8-9`), mas **não** `.env.local`. Antes de mover a pasta, ignorar `/metrica360/` e `.env.*`.

**Repositório, Composer e nomes de classe:**
- **Repositório embutido.** A pasta do METRICA360 dentro do Conecta é um repositório dentro de outro, e o Git do Conecta a veria como repositório embutido.
- **`vendor/`.** O `.gitignore` do Conecta ignora `**/vendor/` (`.gitignore:4`). O METRICA360 versiona o `vendor/` de propósito: o deploy dele é manual, sem Composer.
- **Duas versões do PHPMailer:** 6.10.0 no Conecta e 7.0.2 no METRICA360.
- **Classes com o mesmo nome:** `Database` e `EmailService` existem nos dois. Em processos separados (subaplicação) não há conflito; no mesmo processo (biblioteca ou módulo) dá erro fatal.
- **Histórico sujo.** O histórico do METRICA360 tem dumps com dados de pessoas e a senha SMTP: `database/backup_24072026_db_metrica360.sql:292-293`, e também `backup_22062026…`, `db_reputacao.sql` e `crprs_reputacao.sql`, todos no GitHub. Esse histórico não pode entrar no do Conecta, que foi limpo em 27/10.

**`.user.ini`:**
- O PHP lê os `.user.ini` do diretório do script para cima, até o DocumentRoot.
- O do METRICA360 tem caminhos da hospedagem e regras de sessão.
- O Conecta não tem `.user.ini`. Se ganhar um na raiz, ele também valerá para a subpasta.

**Envios de arquivo:**
- Os avatares ficam em dois lugares: `public/uploads/avatars/` no METRICA360 (o `.htaccess` da pasta barra PHP) e `backend/uploads/avatars/` no Conecta.
- Com o login único, vale o avatar do Conecta, e o envio de avatar do METRICA360 fica redundante (**Aprov.** para retirar).

**CORS.** Não é necessário: os dois sistemas são da mesma origem, e as rotas de integração são chamadas de servidor para servidor.

### 3.3 Autenticação e sessão

**Hoje:**
- **Conecta:**
  - JWT em cookie com renovação, Argon2id, Turnstile e limite de tentativas;
  - a cada requisição, confere `ativo`, papel, setor, `token_versao` e `sessoes_revogadas`.
- **METRICA360, área admin:**
  - sessão PHP com 60 min de inatividade e id regenerado a cada 15 min (`security.php:60-81`);
  - bcrypt, Turnstile e limite por IP e por conta (`src/controllers/auth_controller.php:20-83`);
  - primeiro acesso por código OTP (`src/controllers/primeiro_acesso_controller.php`).
- **Área do Funcionário:** sai com o ramo `remover-area-funcionario`.

**Proposta: ticket de entrada no banco (opção S1, seção 4.3)**
1. No Conecta, o item "Satisfação (METRICA360)" aparece para quem tem papel de painel no METRICA360: `m360_users` ligado à conta, papel em `ROLES_PAINEL` e sem `deleted_at`.
2. O clique faz `POST metrica360-entrar.php`, com sessão e CSRF. O servidor:
   - grava em `m360_tickets_entrada` só o hash de um token aleatório de 32 bytes, junto com o `usuario_id`, o `jti` e o `token_versao` da sessão do Conecta, e validade de 60 s;
   - devolve a URL `/conecta/metrica360/entrar?t=<token>`.
3. O METRICA360 consome o ticket:
   - numa operação só: `UPDATE … SET usado_em = NOW() WHERE token_hash = ? AND usado_em IS NULL AND expira_em > NOW()`;
   - regenera o id da sessão e carrega papel e escopo de `m360_users`;
   - redireciona para o painel, tirando o token da URL.
4. A cada requisição, `exigirAdmin()` confere numa consulta:
   - `usuarios.ativo`;
   - `token_versao` igual ao do ticket;
   - `jti` fora de `sessoes_revogadas`;
   - papel ainda válido.

   Com isso, o "Sair", o "Sair de todos os dispositivos", a troca de senha e a desativação no Conecta encerram também o METRICA360.
5. Sinal extra, só possível com o METRICA360 sob `/conecta`:
   - o `jti` lido do cookie `conecta_sessao` precisa ser o mesmo do ticket. O cookie é só decodificado, nunca usado como prova;
   - se o cookie sumiu ou é de outra pessoa (troca de usuário num computador compartilhado), a sessão do METRICA360 termina.

**Por que assim.** O banco único é a âncora de confiança. O METRICA360 não precisa do `JWT_SECRET` do Conecta, com o qual daria para forjar sessão de qualquer usuário, inclusive de admin.

**Papéis:**
- Proposta: o METRICA360 mantém os papéis dele (`m360_users.role`) e o escopo por `m360_setores.coordenador_id`, ligados à conta do Conecta.
- Ser admin do METRICA360 não dá poder no Conecta, e vice-versa.
- Traduzir para os papéis do Conecta perderia a coordenação de vários setores e a visão total sem ser admin.

**Contas:**
- Quem não tem conta no Conecta precisa de uma, o que já é necessário para o Registro de Atividades.
- Para a senha, há duas opções:
  - importar o hash bcrypt do METRICA360: o login do Conecta aceita e regrava em Argon2id (`api/controllers/auth.php:103-105`), e a regra de senha do METRICA360 é mais exigente (12 caracteres, contra 10);
  - criar a conta sem senha e mandar a redefinição por e-mail: `api/controllers/recuperar-senha.php:53-54` aceita operador, gestor e admin ativos.
- No banco local, 35 dos 43 ativos do METRICA360 estão sem senha (nunca concluíram o primeiro acesso).

**O que sai do METRICA360 depois do login único** (**Aprov.**, remoção):
- o login por senha, ou manter só para o admin, como acesso de emergência, atrás de `LOGIN_LOCAL=1` no `.env`;
- o primeiro acesso por OTP (`otp_tokens`);
- a importação de usuários por CSV;
- a troca de senha;
- o avatar próprio.

O cadastro de usuários do METRICA360 vira cadastro de papéis, ligado pelo e-mail a uma conta que já existe no Conecta.

**`api/controllers/auth-sso.php`:**
- **Não muda.** Ele faz o caminho inverso: recebe o token de um painel agregador, por `postMessage` de `sso-bridge.js` (origens em `frontend/app-config.js:13-16`), e abre uma sessão no Conecta. Não serve para entrar no METRICA360.
- Sem painel agregador previsto, `auth-sso.php`, `sso-bridge.js` e `trustedParentOrigins` são candidatos a remoção (**Aprov.**). A remoção também resolve a SEG-12, e a auditoria já aponta código sem uso nele (INC-04).

### 3.4 API e integração interna

| Chamada de hoje | Substituta no banco único | Fase | Observação |
|---|---|---|---|
| `GET /api/integracao/funcionario`, a cada carga da aplicação | `SELECT atendimento_externo FROM m360_users WHERE usuario_conecta_id = ? AND deleted_at IS NULL` | I5 | resolve a DES-07 da auditoria |
| `GET /api/integracao/painel` | leitura nas visões `m360_vw_convites`, em `m360_configuracoes` e em `m360_frases_motivacionais` | I5 | ver os efeitos colaterais logo abaixo |
| `GET /api/integracao/satisfacao` | visão `m360_vw_satisfacao_diaria` | I5 | uma consulta, em vez de uma chamada por servidor |
| `POST /api/integracao/convite` (fila da pesquisa) | pedido em `m360_pedidos_convite`, processado pelo cron do METRICA360 | I8 | já é assíncrono hoje |
| `POST /api/integracao/convite-manual` | continuar pela API, virar pedido ou chamada em processo | I8 ou I9 | hoje a tela mostra na hora "enviado" ou "quarentena" |
| `/cron-processar-convites` | continua, no endereço novo; passa a girar a frase do dia (I5) e a processar os pedidos (I8) | I5, I6 e I8 | — |

**Efeitos colaterais da leitura do painel.** Hoje, ler o painel grava:
- a frase do dia gira gravando em `configuracoes` (`src/models/frase_model.php:74-119`);
- o processador de convites roda, no máximo, a cada 5 min (`integracao_controller.php:119,284-296`) e pode reenviar e-mails dentro da requisição do servidor.

Os dois passam para o cron do METRICA360.

**Dono da regra:**
- Pela decisão de 27/10, convites e notas são do METRICA360, e `ConviteEnvioService::enviar()` segue como regra única (quarentena, token, gravação e e-mail).
- O Conecta só lê, pelas visões, e só pede, pela fila. Nunca reimplementa a quarentena.
- As visões são o contrato: uma definição só, mantida junto do METRICA360, que os dois lados leem.
- A decisão de 27/10 também dizia que o Meu Painel "lê e envia por API". O pedido novo (fim do HTTP) troca o meio, não o dono. **Confirmar essa leitura** (pergunta 5).

**Fila de pedidos (`m360_pedidos_convite`):**
- **Colunas:**
  - `chave_integracao` única (`conecta:<ticket_id>`, como hoje) e origem (`encerramento` ou `manual`);
  - `usuario_conecta_id`, protocolo, destinatário e setor;
  - `status` (pendente, processando, enviado, quarentena, sem_funcionario, falha);
  - `convite_id`, `proximo_envio`, tentativas e erro.
- **Fluxo:**
  - o Conecta grava o pedido;
  - o METRICA360 reserva cada pedido (`SELECT … FOR UPDATE SKIP LOCKED` no MySQL 8; conferir a versão de produção) e chama `ConviteEnvioService`;
  - o e-mail sai fora da transação, e o resultado é gravado no pedido;
  - numa passada seguinte, o Conecta lê o resultado e finaliza `pesquisa_satisfacao` e a linha do tempo, como `processarFila()` já faz com a resposta HTTP (`api/services/pesquisa-satisfacao-service.php:131-160`).

**Chamada em processo (biblioteca):**
- Exige tirar os efeitos colaterais da inclusão do METRICA360: hoje `security.php` abre sessão e manda cabeçalhos, e `database.php` dá `die()` se não conectar.
- Exige também namespaces para `Database` e `EmailService`, PDO injetado e um PHPMailer só.
- Cabe na Fase I9 (módulo).

### 3.5 Cron e filas

**Hoje:**
- Conecta: o cron-job.org chama `verificar-emails.php` a cada 2 min, com `X-Cron-Key` = `CRON_KEY_*`, e a cadeia vai até `enviar-pesquisas.php`.
- METRICA360: `/cron-processar-convites` a cada 15 min, com `X-Cron-Key` = `CRON_KEY` do `.env` dele. Esse agendamento ainda consta como pendente no `CLAUDE.md` do Conecta.

**Proposta:**
- Manter os dois agendamentos e as duas chaves.
- Não encadear o processamento de convites na cadeia do Conecta antes de corrigir a BUG-08 da auditoria. Hoje um `Error` numa mensagem derruba a cadeia inteira, e levaria os convites junto.
- Passar o METRICA360 de 15 para 5 min quando ele começar a processar os pedidos (Fase I8). Sem isso, um convite pode esperar até 15 min.
- O agendamento de `/cron-processar-convites` vira pré-requisito da Fase I5, porque o painel deixa de disparar o processador.

**Travas:**
- O nome de `GET_LOCK('conecta_cron_<nome>')` vale para a instância MySQL inteira, não por banco. Uma homologação no mesmo servidor travaria a produção. Proposta: incluir o nome do banco no nome da trava.
- O METRICA360 trava por uma data gravada em `configuracoes` (`convite_processador_api_ultimo`).

**Achado local:**
- Uma tarefa agendada do Windows, "Conecta CRPRS - Verificar e-mails", roda `api/cron/verificar-emails.php` a cada 2 min desde 23/10/2026, contra a caixa real configurada no `.env`. Ela estava ativa durante esta análise e gravou em `backend/logs/email-cron.log`.
- O `CLAUDE.md` diz que nada agenda o cron no ambiente local.
- Isso importa aqui: o que for posto na cadeia roda sozinho na máquina local e grava no `db_conecta` que vai para a produção.

### 3.6 Segurança entre sistemas

- **Mesma origem (SEG-01):**
  - site, Conecta e METRICA360 já dividem `https://crp07.org.br`, e pôr o METRICA360 sob `/conecta/` não muda isso;
  - um subdomínio próprio (`conecta.crp07.org.br`, com o METRICA360 dentro) separa os dois do site, mas não um do outro;
  - no desenho integrado isso é de propósito: um XSS no METRICA360 continua agindo no Conecta. A favor, o CSP do METRICA360 usa nonce e é mais restrito que o do Conecta.
- **Formulário público:**
  - `/avaliar` é a única página aberta a cidadãos sem login. Na mesma origem dos servidores, um XSS ali alcançaria a sessão de um servidor que abrisse o link;
  - o risco é baixo, porque a página só mostra o nome do servidor e recebe nota e comentário. Separar a origem do formulário é a opção O3 (seção 4.6).
- **Elfsight (SEG-02).** O script do Google Reviews está liberado nos dois CSP, em todas as páginas. A recomendação da auditoria (iframe com `sandbox` em outra origem) vale para os dois e convém antes da Fase I6.
- **Cabeçalhos duplicados (SEG-10).** Confirmado por HTTP local (ver 3.2).
- **Conta do banco e segredos.** Cifrar os segredos do METRICA360 e corrigir a SEG-03 antes de dividir a conta do banco (ver 3.1).
- **Ticket de entrada:**
  - uso único, 60 s de validade e só o hash gravado no banco;
  - ligado ao `jti` da sessão de origem, emitido por POST com CSRF e retirado da URL logo depois;
  - `Referrer-Policy: strict-origin-when-cross-origin` nos dois sistemas, então o caminho com o token não vai para terceiros;
  - limite de emissão por usuário (ex.: 10 por minuto) pelo `RateLimitService` do Conecta.
- **Fim da sessão.** Sem a conferência do item 4 da seção 3.3, um "Sair" no Conecta deixaria o METRICA360 aberto por até 60 min num computador compartilhado. A SEG-08 (sessão sem validade absoluta) vale para os dois.
- **IP real:**
  - o METRICA360 usa só `REMOTE_ADDR` (`src/utils/rate_limiter.php:65-67`), e o Conecta usa `RedeService` com `PROXIES_CONFIAVEIS`;
  - atrás da Cloudflare, o limite por IP do METRICA360 trata todos os visitantes como um só, a menos que o Apache tenha `mod_remoteip`.
- **Auditoria:**
  - as duas trilhas são incompatíveis: `auditoria.usuario_id` é `NOT NULL` no Conecta, e as ações de sistema do METRICA360 gravam `user_id` nulo;
  - manter `m360_audit_logs` separada e, a partir do login único, gravar nela também o `usuario_conecta_id` de quem agiu.
- **LGPD.** O e-mail do cidadão em `m360_convites_avaliacao.destinatario_email` e os comentários das avaliações são dados pessoais. A política de retenção da SEG-15 precisa incluir as `m360_*`.

### 3.7 Desempenho

- **Carga da aplicação:**
  - hoje, cada carga chama o METRICA360 (curl de até 10 s) só para decidir se mostra o Meu Painel;
  - lá, essa chamada abre sessão PHP, monta cabeçalhos, conecta ao banco e consulta o limite de tentativas;
  - no banco único, vira um `SELECT` por chave.
- **Meu Painel.** O processador de convites pode reenviar e-mails por SMTP dentro da requisição, e acima de 10 s o Conecta mostra "não respondeu". Isso sai na Fase I5.
- **Cruzamento.** Hoje é uma chamada HTTP por servidor consultado; vira uma consulta.
- **Sessões descartáveis.** Cada chamada de integração abre uma sessão PHP nova no METRICA360, porque o `session_start()` roda em toda rota. Somem com o fim do HTTP.
- **Índices.** Com a mesma colação, as junções por e-mail usam índice; com `COLLATE` na coluna, não usam. Melhor ainda é juntar pela chave (`usuario_conecta_id`).
- **Carga no banco.** Nenhuma mudança relevante, porque os bancos provavelmente já estão na mesma instância.

### 3.8 Implantação e rollback

**Ordem com os outros planos:**
1. Fases 0 e 1 da auditoria (INC-01 e BUG-08).
2. Roteiro `docs/implantacao-producao.md`, seção 7, passos 1 a 5.
3. Esta integração.

A SEG-01 (subdomínio) precisa ser decidida antes da Fase I6, para o METRICA360 mudar de endereço uma vez só.

**Janelas.** As Fases I4 (banco) e I6 (endereço) podem ser na mesma janela. Separadas, cada volta atrás desfaz uma coisa só.

**Cópias e chaves de volta:**
- `mysqldump` dos dois bancos antes de cada janela, guardado fora do servidor.
- O `db_metrica360` fica intacto até a Fase I10.
- Três chaves no `.env` deixam voltar sem novo deploy:
  - `DB_PREFIXO` (METRICA360): o mesmo código serve ao banco antigo e ao novo;
  - `METRICA360_MODO=http|banco` (Conecta);
  - `LOGIN_LOCAL` (METRICA360).

**Dados novos depois do corte.** Convites e avaliações criados no banco novo voltam ao antigo por um script reverso, que copia as linhas com id acima do último importado. O script é preparado e ensaiado na Fase I3.

**Comunicação.** Avisar os servidores do endereço novo e do login único. Os cidadãos não precisam de aviso, porque os links redirecionam.

---

## 4. Opções avaliadas

### 4.1 Banco

| Opção | Prós | Contras | Custo | Veredito |
|---|---|---|---|---|
| **A. Banco único `db_conecta`, METRICA360 com prefixo `m360_`** | um backup e um executor de migrações; chave estrangeira real para `usuarios`/`setores`; fim do HTTP nas leituras; não depende de permissão cruzada no provedor | ~158 referências a renomear; janela de corte; colação a converter | M | **Recomendada** |
| B. Banco único sem prefixo, fundindo as tabelas homônimas | menos tabelas | as quatro homônimas têm significados diferentes; reescreve os dois sistemas | G | Não |
| C. Dois bancos na mesma instância, com leitura cruzada (`db_metrica360.tabela`) | sem importação nem renomeação | não é o "mesmo banco"; depende de uma conta ler os dois bancos (hipótese); a colação continua diferente; dois executores de migração | P–M | Plano B, se o corte (A) precisar esperar |
| D. Dois bancos com sincronização (cron ou API) | nada muda nos sistemas | consistência eventual; mais código; o HTTP continua | M–G | Não |

### 4.2 Hospedagem

| Opção | Prós | Contras | Custo | Veredito |
|---|---|---|---|---|
| **A. Subaplicação em `/conecta/metrica360/`** | pouca mudança no METRICA360; sessões e CSP separados; atende ao pedido | dois códigos, dois `vendor/`, dois crons | P–M | **Recomendada agora** |
| B. Módulo do Conecta (telas reescritas) | um sistema, uma sessão, um CSP, um cron; o visual do Conecta | portar ~5 mil linhas de backend, ~6 mil de views, ~4 mil de JS e ~8,5 mil de CSS; refatorar o domínio em biblioteca | G | Destino final opcional (Fase I9) |
| C. Ficar em `/metrica360/`, só com banco único e login único | o mínimo de mudança | não atende ao pedido; o cookie do Conecta não chega ao METRICA360, e perde-se o sinal extra da 3.3 | P | Não |

### 4.3 Login

| Opção | Prós | Contras | Veredito |
|---|---|---|---|
| **S1. Ticket de uso único no banco** | nenhum segredo dividido; o fim da sessão se propaga pelo banco | um clique para entrar; uma tabela nova | **Recomendada** |
| S2. METRICA360 valida o JWT do Conecta com o `JWT_SECRET` | entrada transparente | com o segredo, o METRICA360 pode forjar sessão de qualquer usuário do Conecta, inclusive admin | Não |
| S3. METRICA360 inclui o `AuthMiddleware` do Conecta | reaproveita a regra | colisão de `Database`; o middleware encerra com JSON e o METRICA360 é HTML; depende de refatoração | Não agora |
| S4. Módulo, sem sessão própria | o mais simples no fim | só existe com a opção 4.2 B | Junto com a Fase I9 |
| S5. A mesma senha nos dois (hashes sincronizados) | — | dois lugares de senha e dois limites de tentativa | Não |

### 4.4 Identidade dos servidores

| Opção | Prós | Contras | Veredito |
|---|---|---|---|
| **I1. `m360_users` ligada a `usuarios` por `usuario_conecta_id`** | guarda o histórico, inclusive de quem saiu; mantém os papéis e a coordenação do METRICA360; mudança mínima | duas tabelas de pessoas | **Recomendada** |
| I2. Fundir em `usuarios` | uma tabela só | contas inativas para todo o histórico; papéis de vários setores sem lugar no Conecta; tipos e tamanhos de e-mail diferentes | Não agora |

### 4.5 Escritas sem HTTP

| Opção | Prós | Contras | Veredito |
|---|---|---|---|
| W1. Manter a API só para as escritas | nada muda para quem usa | o HTTP e a chave continuam | Para o convite manual, até a Fase I9 |
| **W2. Fila no banco** | sem HTTP e sem chave | assíncrono: o convite manual deixa de responder na hora | **Para a pesquisa de satisfação**, que já é assíncrona |
| W3. Chamada em processo (biblioteca) | resposta na hora, sem HTTP | refatoração: efeitos na inclusão, namespaces, um PHPMailer só | Com o módulo (Fase I9) |

### 4.6 Origem

| Opção | Prós | Contras | Veredito |
|---|---|---|---|
| O1. Subpasta no domínio atual (`crp07.org.br/conecta/metrica360`) | nenhuma infraestrutura nova | a SEG-01 continua | Aceitável se o subdomínio demorar |
| **O2. Subdomínio (`conecta.crp07.org.br/metrica360`)** | isola do site (SEG-01); permite o cookie `__Host-` | DNS, TLS e vhost; mudam URLs (satélites, e-mails, cron) | **Recomendada** (M-01 da auditoria) |
| O3. Formulário público em origem separada | a página do cidadão fica longe da sessão dos servidores | separar o formulário do resto, o que é mais natural no módulo | Opcional, com a Fase I9 |

### 4.7 Repositório

| Opção | Prós | Contras | Veredito |
|---|---|---|---|
| **R1. Repositórios separados; a pasta `metrica360/` fora do Git do Conecta** | os históricos não mudam | publicar em dois passos; o esquema `m360_*` fica num repositório e o código que o usa, em outro | **Recomendada até a Fase I8** |
| R2. Submódulo | versão amarrada | complexo para um mantenedor só; o histórico sujo continua acessível | Não |
| R3. Um repositório, com o histórico do METRICA360 | histórico completo | traz para o Conecta os dumps com dados e a senha SMTP | Só depois de limpar o histórico (`git filter-repo`) |
| R4. Um repositório, sem o histórico (cópia dos arquivos; repositório antigo arquivado) | simples e limpo | perde o histórico de autoria | Para a Fase I9 |

---

## 5. Plano de implementação

### 5.1 Arquitetura de destino (até a Fase I8)

```
crp07.org.br/conecta/  (ou conecta.crp07.org.br/, se aprovado o subdomínio)
 ├─ frontend/ + api/  ── Conecta: sessão JWT no cookie conecta_sessao (Path=/conecta)
 │   ├─ metrica360-entrar.php ──► grava o ticket em m360_tickets_entrada ──┐
 │   ├─ Metrica360Leitura ──────► SELECT nas visões m360_vw_*              │
 │   └─ PesquisaSatisfacao ─────► INSERT em m360_pedidos_convite           │
 └─ metrica360/  ── subaplicação: sessão m360_sessao (Path=/conecta/metrica360)
     ├─ entrar?t= ◄────────────── consome o ticket ────────────────────────┘
     ├─ admin-* (papéis de m360_users, ligados a usuarios.id)
     ├─ avaliar?t= (público, para o cidadão)
     └─ cron-processar-convites (expirações, reenvios, pedidos, frase do dia)
                       │
                db_conecta (uma instância)
                 ├─ tabelas do Conecta (usuarios, setores, sessoes_revogadas, …)
                 └─ m360_* (users → usuario_conecta_id, setores → setor_conecta_id, convites, avaliações, …)
```

### 5.2 Visão geral das fases

| Fase | Objetivo | Depende de | Esforço | Aprov. |
|---|---|---|---|---|
| I0 | Decisões, inventário de produção e troca dos segredos expostos | — | P | Sim |
| I1 | Concluir o que já está planejado (roteiro, seção 7, passos 1 a 5) | I0 | já planejado | Sim |
| I2 | Preparar o METRICA360 para o banco único | I0 | M | Parcial |
| I3 | Esquema `m360_*` no Conecta, importador ensaiado e provisionamento | I1, I2 | M | Sim |
| I4 | Corte do banco em produção | I3 | P (com ensaio) | Sim |
| I5 | Leituras sem HTTP | I4 | M | Não |
| I6 | METRICA360 em `/conecta/metrica360/` (ou no subdomínio) | I4 | P–M | Sim |
| I7 | Login único pelo Conecta | I4, I6 | M | Sim |
| I8 | Escritas sem HTTP (fila de pedidos) | I5 | M | Sim |
| I9 | (Opcional) METRICA360 como módulo do Conecta | I8 | G | Sim |
| I10 | Arquivo do banco antigo, limpeza aprovada e documentação | I8 (ou I9) | P | Sim |

Cada fase tem commit próprio, em português, com o `CLAUDE.md` do sistema afetado atualizado na mesma entrega (regra do projeto). Se a integração não for aprovada, o roteiro da seção 7 de `docs/implantacao-producao.md` continua valendo como está.

### 5.3 Fases

#### Fase I0: decisões, inventário e segredos (sem código)

- **Objetivo.** Responder às perguntas da seção 7, transformar as hipóteses de produção em fatos e tirar de circulação os segredos expostos.
- **Ações:**
  1. Perguntar ao provedor, sobre o DBaaS `vpshost0269`:
     - se os dois bancos estão na mesma instância;
     - se uma conta pode acessar os dois, e se dá para criar contas com privilégio por tabela e coluna;
     - se `CREATE VIEW` é permitido;
     - qual é a versão (MySQL ou MariaDB), o `@@time_zone` e o `max_connections`.
  2. Inventário do METRICA360 de produção, só com `SELECT` e `SHOW` (Apêndice B):
     - commit publicado e migrações aplicadas;
     - contagens;
     - e-mails dos servidores, para comparar fora do servidor com as contas do Conecta;
     - setores e coordenadores.
  3. **Trocar a senha SMTP do METRICA360 e a chave do Google Places**, que estão num dump versionado no GitHub (`database/backup_24072026_db_metrica360.sql:292-293`, em `origin/master`). Decidir a limpeza do histórico com `git filter-repo`, como o Conecta fez em 27/10.
  4. Servidor web:
     - versão do PHP e modo (FPM ou CGI);
     - se `mod_headers` e `expr=` são aceitos no `.htaccess`;
     - onde fica o DocumentRoot de `crp07.org.br`;
     - se dá para criar `conecta.crp07.org.br`.
- **Arquivos e tabelas.** Nenhum.
- **Aceite.** Respostas registradas na seção 7 deste documento e inventário guardado fora do repositório.
- **Rollback.** Não se aplica.
- **Aprov.** Sim (infraestrutura, segredos e histórico do Git).

#### Fase I1: concluir o que já está planejado (pré-requisito)

- **Objetivo.** Chegar ao estado de partida:
  - o Conecta em produção, com Registro de Atividades e Meu Painel funcionando pela API;
  - o METRICA360 sem a Área do Funcionário.
- **Ações:**
  - Seguir `docs/implantacao-producao.md`, seção 7, passos 1 a 5, e publicar o código do ramo `remover-area-funcionario`.
  - **Não** rodar `migrations/2026_10_27_remover_area_funcionario.sql`. O arquivamento da Fase I10 o substitui, e isso muda o passo 6 do roteiro (**Aprov.**).
  - Provisionar setores e contas dos servidores. É uma lacuna do roteiro atual, que só pede para "conferir os e-mails": o Registro de Atividades depende disso. Pode ser feito à mão aqui, ou com o importador na Fase I3.
- **Aceite.** O checklist da seção 7 do roteiro.
- **Rollback.** O do próprio roteiro.
- **Aprov.** Sim.

#### Fase I2: METRICA360 preparado para o banco único (repositório do METRICA360)

- **Objetivo.** O mesmo código funcionar no `db_metrica360` (sem prefixo) e no `db_conecta` (prefixo `m360_`), com fuso, sessão e segredos certos. O banco ainda não muda.
- **Arquivos:**
  - `src/config/database.php`: passa a ser versionado, sem segredos, lendo o `.env`;
  - `src/config/env.php` e `src/config/security.php`;
  - os models, services e utils com SQL (as ~158 referências);
  - `tests/*`.
- **Mudanças:**
  1. Nome de tabela por mapa fechado, com `DB_PREFIXO` no `.env` (vazio por padrão). Exemplo: `Tabela::nome('users')` devolve `users` ou `m360_users`. O nome nunca vem da requisição.
  2. `SET time_zone = '-03:00'` na conexão, igual ao Conecta, depois de conferir o fuso da produção (I0).
  3. Sessão com nome próprio (`m360_sessao`) e `cookie_path` igual a `BASE_URL`.
  4. `BASE_URL` lido do `.env`, com padrão `/metrica360`.
  5. Senha SMTP e chave do Google Places cifradas com AES-256-GCM e chave no `.env`, no mesmo padrão do `CryptoService` do Conecta. A leitura aceita o texto puro antigo e regrava cifrado.
  6. Testes em transação desfeita ou num banco de teste; nunca apagar `configuracoes` reais.
- **Migrações.** Nenhuma.
- **Aceite:**
  - a suíte do METRICA360 passa nos dois modos: sem prefixo, contra uma cópia do `db_metrica360`, e com prefixo, contra um banco de ensaio;
  - nenhuma mudança visível, além de entrar de novo uma vez.
- **Teste.** PHPUnit e navegação do painel admin; um `/avaliar` com convite de teste.
- **Rollback.** Reverter o commit; os dados não mudam.
- **Aprov.** Parcial (cookie de sessão).

#### Fase I3: esquema `m360_*` no Conecta, importador e provisionamento

- **Objetivo.** O `db_conecta` ganha as tabelas do METRICA360, vazias, e um importador ensaiado.
- **Migração** `database/NN_metrica360_tabelas.sql` (próximo número livre):
  - 14 tabelas `m360_*` (Apêndice A) em `utf8mb4_0900_ai_ci`, com as colunas e tipos de hoje, sem as colunas de prazo e entrega e sem `forms_por_semana`/`limite_atraso_semanas` (nenhum uso no ramo de remoção);
  - chaves estrangeiras internas, como as de `m360_feedback_categorias`;
  - os dois vínculos, `usuario_conecta_id` e `setor_conecta_id`;
  - uma segunda migração para o script reverso, se ele precisar de tabela de controle.
- **Importador** `database/importar-metrica360.php` (só linha de comando, como o `migrar.php`):
  - `--origem`: conexão só leitura ao `db_metrica360`;
  - `--ensaio` (padrão): só relatório; `--executar`: grava;
  - recusa rodar se as `m360_*` não estiverem vazias;
  - mantém os ids, para tokens e links seguirem valendo e o AUTO_INCREMENT continuar de onde estava;
  - liga `usuario_conecta_id` pelo e-mail (minúsculas, sem espaços) e `setor_conecta_id` pelo nome exato, com um arquivo de mapeamento para o resto;
  - relatório: servidores sem conta no Conecta, setores sem par, e-mails com mais de 100 caracteres e contagem por tabela.
- **Script reverso** `database/devolver-metrica360.php`: copia de volta ao banco antigo as linhas criadas depois do corte.
- **Provisionamento** (**Aprov.**, mudança visível e dados):
  - setores que faltam, criados pela regra do Conecta (`api/controllers/setores.php:60-77`, que copia horários, feriados do Geral, status e prioridades), nunca por `INSERT` cru;
  - contas dos servidores (operador no setor; coordenadores conforme a pergunta 3), com redefinição de senha por e-mail ou com o hash importado.
- **Regra do banco local.** Nunca importar dados reais no `db_conecta` local. O ensaio usa um banco descartável (cópia do `db_conecta` + dump do METRICA360), apagado no fim.
- **Aceite:**
  - `php database/migrar.php --status` mostra a migração nova aplicada no local;
  - o ensaio tem contagens iguais às da origem, 0 erros e relatório revisado;
  - a junção `m360_users` × `usuarios` por e-mail funciona sem `COLLATE`, e o `EXPLAIN` usa índice.
- **Teste.** PHPUnit do Conecta, com a suíte de integração; suíte do METRICA360 com prefixo, contra o banco de ensaio.
- **Rollback.** As tabelas novas estão vazias: `DROP TABLE m360_*` e apagar a linha em `migracoes`, com backup antes.
- **Aprov.** Sim (esquema, contas e setores).

#### Fase I4: corte do banco em produção (janela de manutenção)

- **Objetivo.** O METRICA360 de produção passa a usar o `db_conecta`.
- **Pré-requisitos.** SEG-03 corrigida e segredos do METRICA360 cifrados (I2).
- **Passos:**
  1. Backup dos dois bancos, guardado fora do servidor.
  2. METRICA360 em manutenção, com uma página simples ("volte em alguns minutos", também no `/avaliar`). Pausar o cron de convites do METRICA360. A fila de pesquisas do Conecta pode seguir: com o METRICA360 fora, cada pedido recebe erro e é tentado de novo depois de 5, 15, 45 e 135 min. Só desiste depois de 5 tentativas (cerca de 3 h e 20 min), então a janela precisa ser mais curta que isso.
  3. Rodar o importador com `--executar`, contra o dump final.
  4. No `.env` do METRICA360: host, banco e conta do `db_conecta`, e `DB_PREFIXO=m360_`.
  5. Conferir: Apêndice B, contagens, login admin, um convite de teste e uma avaliação de teste.
  6. Reabrir.
  7. O `db_metrica360` fica intacto e sem uso, como caminho de volta.
- **Conta do MySQL.** O ideal é a `m360_app` da seção 3.1. Se o provedor só der uma conta por banco, usar a do Conecta e registrar o risco.
- **Conecta.** Nada muda: continua chamando a API em `/metrica360`.
- **Aceite:**
  - o METRICA360 funciona e as contagens batem;
  - o convite de uma demanda encerrada chega;
  - 48 h sem erro nos logs.
- **Rollback.** Voltar o `.env` para o banco antigo, sem prefixo; o mesmo código serve aos dois. O que foi gravado depois do corte volta pelo script reverso.
- **Aprov.** Sim (produção e janela).

#### Fase I5: leituras sem HTTP

- **Objetivo.** O acesso e o painel do Meu Painel e o Cruzamento passam a ler direto do banco.
- **Conecta:**
  - `api/services/metrica360-leitura-service.php` (`Metrica360Leitura`), usado por `meu-painel.php` (GET) e por `atividades-acompanhamento.php` (cruzamento);
  - tudo atrás de `METRICA360_MODO=banco|http` no `.env`, para voltar sem deploy.
- **Migração:**
  - visão `m360_vw_convites`: convite, nota, comentário, situação e `usuario_conecta_id`;
  - visão `m360_vw_satisfacao_diaria`: servidor, dia, convites, avaliados e nota;
  - são o contrato entre os dois lados (hipótese: o provedor permite `CREATE VIEW`; se não, as consultas ficam no serviço).
- **METRICA360.** A rotação da frase do dia e o processador de convites saem da leitura e passam para o cron. A regra continua a mesma.
- **Pré-requisito.** `/cron-processar-convites` agendado (I0).
- **Aceite:**
  - para uma amostra de servidores, o JSON do modo banco é igual ao do modo HTTP (teste automatizado);
  - a carga da aplicação não chama mais o METRICA360;
  - a seção [7e] da matriz de acesso passa.
- **Teste.** Integração no Conecta com `m360_*` de teste em transação desfeita; matriz de acesso.
- **Rollback.** `METRICA360_MODO=http`.
- **Aprov.** Não: nada muda para quem usa.

#### Fase I6: METRICA360 em `/conecta/metrica360/` (ou no subdomínio)

- **Objetivo.** Hospedar o METRICA360 dentro do diretório do Conecta.
- **METRICA360:**
  - pasta `conecta/metrica360/`, com repositório próprio (opção R1);
  - `BASE_URL=/conecta/metrica360` no `.env` e `RewriteBase /conecta/metrica360/` no `.htaccess`;
  - textos de ajuda e FAQ com o caminho novo.
- **Conecta:**
  - `.gitignore` ignorando `/metrica360/` e `.env.*` (exceto `.env.example`);
  - `.htaccess` da raiz: os cabeçalhos globais com `expr=%{REQUEST_URI} !~ m#^/conecta/metrica360/#`;
  - `METRICA360_URL_*`, enquanto houver HTTP.
- **Servidor:**
  - `/metrica360/.htaccess` com `RedirectMatch 301 ^/metrica360/(.*)$ /conecta/metrica360/$1`, por pelo menos 90 dias;
  - endereço novo de `/cron-processar-convites` no cron-job.org.
- **Se o subdomínio for aprovado,** fazer aqui:
  - DNS, TLS e vhost;
  - `base_url`, `rootBase`/`apiBase` em `frontend/app-config.js` e CORS;
  - cookie `__Host-conecta_sessao`;
  - aviso aos sistemas satélites.
- **Aceite:**
  - `curl -I` mostra um só CSP e um só `X-Frame-Options` por página: o do METRICA360 nas páginas dele e o do Conecta nas outras;
  - `Set-Cookie: m360_sessao…; Path=/conecta/metrica360; Secure; HttpOnly; SameSite=Strict`;
  - links antigos de convite e de primeiro acesso abrem pelo redirecionamento;
  - a matriz de acesso do Conecta e a suíte do METRICA360 passam.
- **Rollback.** Tirar o redirecionamento e voltar a pasta e o `METRICA360_URL`; o banco não muda.
- **Aprov.** Sim (infraestrutura, CSP e endereço visível).

#### Fase I7: login único pelo Conecta

- **Objetivo.** Entrar no METRICA360 com a sessão do Conecta, sem senha própria.
- **Migração.** `m360_tickets_entrada`: `token_hash CHAR(64)` como chave primária, `usuario_id` com chave estrangeira, `jti`, `token_versao`, `criado_em`, `expira_em` e `usado_em`.
- **Conecta:**
  - `api/controllers/metrica360-entrar.php`: `GET ?acao=acesso` responde se a pessoa pode entrar; `POST`, com CSRF, cria o ticket de 60 s e devolve a URL;
  - item "Satisfação (METRICA360)" no menu (`frontend/index.html`), com o ícone novo na lista `icon_names` e a visibilidade em `aplicarVisibilidadePorPapel`;
  - seção nova da matriz de acesso.
- **METRICA360:**
  - rota `entrar?t=`, que consome o ticket como descrito na seção 3.3;
  - `exigirAdmin()` com a conferência por requisição e o sinal do cookie;
  - a tela de login vira "Entrar pelo Conecta".
- **`auth-sso.php`.** Não muda (ver 3.3).
- **Retiradas no METRICA360**, depois de duas semanas estável (**Aprov.**):
  - o login por senha, ou manter só para o admin, atrás de `LOGIN_LOCAL=1`;
  - o primeiro acesso por OTP, a importação CSV, a troca de senha e o avatar próprio.
- **Aceite:**
  - o login único funciona para admin, coordenador e supervisora;
  - ticket reutilizado ou vencido é recusado, e usuário sem papel no METRICA360 recebe 403;
  - "Sair" no Conecta leva a próxima página do METRICA360 para o login do Conecta;
  - trocar de usuário no mesmo navegador encerra a sessão anterior do METRICA360.
- **Teste.** Matriz de acesso; testes do METRICA360 para o consumo do ticket; Playwright com os três perfis.
- **Rollback.** `LOGIN_LOCAL=1` religa o login antigo.
- **Aprov.** Sim (comportamento visível e remoções).

#### Fase I8: escritas sem HTTP (fila de pedidos)

- **Objetivo.** A pesquisa de satisfação sem a API; o convite manual também, se aprovado.
- **Migração.** `m360_pedidos_convite`, com as colunas da seção 3.4.
- **Conecta:**
  - `PesquisaSatisfacaoService::processarFila()` grava o pedido no lugar do POST (`pesquisa-satisfacao-service.php:123-130,266-269`);
  - numa passada seguinte, lê o resultado e finaliza `pesquisa_satisfacao` e a linha do tempo.
- **Convite manual**, duas saídas:
  - (a) segue pela API até a Fase I9;
  - (b) vira pedido, e a tela passa a dizer "registrado; sai em até 5 min" (**Aprov.**: muda o que a tela diz).
- **METRICA360:**
  - o cron processa os pedidos com `ConviteEnvioService::enviar()`, a regra única de sempre;
  - transação própria, reserva por pedido e e-mail fora da transação;
  - frequência de 5 min.
- **Depois** (**Aprov.**, remoção): sai o que ficar sem uso, ou seja, `Metrica360Client`, `METRICA360_URL_*`, `METRICA360_API_KEY_*`, `INTEGRACAO_CONECTA_API_KEY` e as rotas `/api/integracao/*`. Na saída (a), a rota `convite-manual`, o cliente e a chave ficam até a Fase I9.
- **Aceite:**
  - encerrar uma demanda de teste gera o convite pelo caminho novo, com o protocolo e a chave `conecta:<id>`;
  - repetir não duplica;
  - com o METRICA360 fora do ar, o pedido espera;
  - nenhuma requisição HTTP entre os sistemas nos logs.
- **Rollback.** A chave de modo volta para HTTP; as rotas ficam até a limpeza.
- **Aprov.** Sim (remoção de código e texto da tela).

#### Fase I9: METRICA360 como módulo do Conecta (opcional)

- **Objetivo.** Um sistema só: as telas do METRICA360 dentro do Conecta, com uma sessão, um CSP e um cron.
- **Escopo:**
  - painel de avaliações e triagem, painel de setores, convites, configurações (categorias, frases, regras, SMTP, Google) e "Sobre";
  - o formulário público `/avaliar` continua como página isolada, com CSP com nonce e, de preferência, em origem separada (opção O3).
- **Pré-requisitos técnicos:**
  - o domínio do METRICA360 vira biblioteca com namespace (`Metrica360\…`), com PDO injetado e sem efeito na inclusão;
  - `Database` e `EmailService` renomeados, e um PHPMailer só (MAN-03 da auditoria);
  - autorização pelos papéis do módulo, no padrão do `AcessoService`;
  - o cron no encadeamento do Conecta só depois da BUG-08.
- **Tamanho.** ~5 mil linhas de backend, ~6 mil de views, ~4 mil de JS e ~8,5 mil de CSS (estado depois da remoção).
- **Aceite.** Lista de funções igual à do METRICA360, conferida tela a tela; testes; Playwright.
- **Rollback.** A subaplicação segue publicada até o aceite.
- **Aprov.** Sim (remoção do admin do METRICA360 e mudança visível).

#### Fase I10: arquivo, limpeza e documentação

- **Banco antigo.**
  - O `db_metrica360` fica só leitura, ou só o backup fora do servidor, por N meses.
  - Depois é apagado (**Aprov.**).
  - O script destrutivo do METRICA360 sai do roteiro.
- **Código.** Saem as remoções aprovadas nas Fases I7 e I8.
- **Documentação.**
  - `CLAUDE.md` dos dois sistemas, inclusive corrigindo a frase sobre o cron local (3.5).
  - `docs/implantacao-producao.md`, com uma seção nova.
  - `.env.example` dos dois.
- **Aceite.** `grep` sem referências ao que saiu e documentação igual ao código.
- **Aprov.** Sim (apaga dados e código).

---

## 6. Riscos e mitigações

| # | Risco | Certeza | Impacto | Mitigação | Fase |
|---|---|---|---|---|---|
| R1 | Setor ou pessoa cruzados pelo id (IDs trocados entre os bancos) | confirmado | dados no setor errado, sem erro | vínculo só por nome ou e-mail no importador, com relatório e revisão humana; chave estrangeira; nunca usar id do METRICA360 numa tabela do Conecta | I3 |
| R2 | Colação mista (erro 1267, índice ignorado) | confirmado | telas quebram ou ficam lentas | todas as `m360_*` em `utf8mb4_0900_ai_ci`; teste de junção | I3 |
| R3 | Provedor sem conta por tabela ou sem acesso cruzado | hipótese | raio de dano maior; plano B (4.1 C) indisponível | conta única documentada; segredos cifrados; SEG-03 corrigida antes | I0, I4 |
| R4 | Links de avaliação já enviados quebram | confirmado sem redirecionamento | o cidadão não consegue avaliar | `RedirectMatch 301` por 90 dias; os e-mails novos já saem com o caminho novo | I6 |
| R5 | Testes do METRICA360 apagando configuração real no banco único | confirmado se rodarem | regras de convite perdidas | testes em transação ou em banco de teste antes do corte | I2 |
| R6 | Dados reais importados no `db_conecta` local antes da hora | provável sem regra | a produção recebe dados velhos | importar só no corte; ensaio em banco descartável | I3, I4 |
| R7 | Dois CSP e dois `X-Frame-Options` na mesma resposta | confirmado | política confusa e regressões futuras | condição `expr=` no `.htaccess`; conferência com `curl -I` | I6 |
| R8 | `PHPSESSID` do METRICA360 em todo o domínio | confirmado | colisão com outros PHP do domínio | nome e caminho próprios | I2, I6 |
| R9 | Sessão do METRICA360 segue aberta depois do "Sair" no Conecta | provável sem conferência | acesso indevido em computador compartilhado | conferência por requisição e sinal do cookie | I7 |
| R10 | Ticket de entrada vazado (URL, histórico) | baixa | alguém entra no lugar de outro por até 60 s | uso único, hash, ligado ao `jti`, retirado da URL na hora, `Referrer-Policy` | I7 |
| R11 | Contas faltando no Conecta | confirmado (38 de 43 no local) | Registro de Atividades e Meu Painel inacessíveis | provisionamento aprovado antes do corte | I1, I3 |
| R12 | Coordenadores de vários setores perdem visão | confirmado | gestão incompleta das atividades | decidir a coordenação de vários setores no Conecta (pergunta 3) | I0 |
| R13 | A cadeia de cron do Conecta cai (BUG-08) e leva os convites | provável se encadear | convites param | crons separados até corrigir a BUG-08 | I8 |
| R14 | Fuso diferente em produção (`NOW()` em UTC) | hipótese | quarentena e expiração deslocadas 3 h | conferir `@@time_zone`; `SET time_zone` no METRICA360 | I0, I2 |
| R15 | Classes com o mesmo nome e PHPMailer 6 × 7 no mesmo processo | confirmado se carregar junto | erro fatal | processos separados até o módulo; namespaces no módulo | I9 |
| R16 | Segredo e dados pessoais no histórico do METRICA360 | confirmado | senha SMTP exposta | trocar a senha; limpar o histórico; não importar o histórico | I0 |
| R17 | Rollback com dados novos no banco único | provável | avaliações do período perdidas | script reverso ensaiado; janela curta | I3, I4 |
| R18 | `.env.local` versionado ou `vendor/` ignorado pelo Git do Conecta | provável se unir as pastas | segredo no Git ou deploy quebrado | `.gitignore` ajustado antes de mover; repositórios separados | I6 |
| R19 | Mesma origem com o site (SEG-01) e Elfsight (SEG-02) | confirmado | um XSS num sistema afeta o outro | subdomínio; iframe com `sandbox` | I6 |
| R20 | Processador de convites sem o disparo pelo painel | provável sem cron | reenvios e expirações param | agendar `/cron-processar-convites` antes da Fase I5 | I0, I5 |

---

## 7. Perguntas abertas

1. **Banco.** Aprova o banco único `db_conecta` com prefixo `m360_`, com o `db_metrica360` arquivado no lugar do script destrutivo? Pode perguntar ao provedor sobre instância, contas por tabela, `CREATE VIEW`, versão e fuso?
2. **Contas.** Criar conta no Conecta para os servidores ativos do METRICA360 e os setores que faltam? Para a senha, importar o hash do METRICA360 ou mandar a redefinição por e-mail?
3. **Papéis.** O METRICA360 mantém papéis próprios, ligados à conta do Conecta? O coordenador de vários setores precisa ver as atividades de todos eles no Conecta? Hoje não vê.
4. **Login.** Desligar o login próprio do METRICA360, junto com o primeiro acesso, a importação CSV, a troca de senha e o avatar? Manter um login de emergência para o admin?
5. **Convites.** Confirma que "convites e notas continuam no METRICA360" quer dizer que o METRICA360 é dono da regra e das tabelas, mesmo com o Conecta lendo direto do banco? O convite manual pode virar assíncrono ("sai em até 5 min") ou fica pela API até o módulo?
6. **Endereço e destino.** Subpasta no domínio atual ou já o subdomínio do Conecta (SEG-01)? Parar na subaplicação ou levar as telas para dentro do Conecta (G)?
7. **Repositório e segredos.** Os repositórios ficam separados? Autoriza trocar a senha SMTP e a chave do Google Places e limpar o histórico do METRICA360 no GitHub?
8. **Guarda.** Por quanto tempo guardar o `db_metrica360` arquivado? Sugestão: 6 meses.

---

## Apêndice A: mapa das tabelas (METRICA360 → banco único)

| Tabela atual | No banco único | Linhas (local) | Observação |
|---|---|---|---|
| `users` | `m360_users` | 46 | + `usuario_conecta_id`; sem as colunas de prazo e entrega; `password_hash` sai depois do login único |
| `setores` | `m360_setores` | 20 | + `setor_conecta_id`; mantém `coordenador_id` e `ativo_painel` |
| `convites_avaliacao` | `m360_convites_avaliacao` | 214 | ids mantidos (tokens e links) |
| `feedbacks` | `m360_feedbacks` | 221 | — |
| `feedback_categorias` | `m360_feedback_categorias` | 59 | chaves estrangeiras internas |
| `categorias` | `m360_categorias` | 7 | colidia com `categorias` do Conecta |
| `configuracoes` | `m360_configuracoes` | 16 | sem `data_base_atividades`; `google_places_api_key` cifrada |
| `configuracoes_smtp` | `m360_configuracoes_smtp` | 1 | senha cifrada |
| `frases_motivacionais` | `m360_frases_motivacionais` | 34 | — |
| `audit_logs` | `m360_audit_logs` | 254 | + `usuario_conecta_id` (opcional) |
| `tentativas_acesso` | `m360_tentativas_acesso` | 2 | colidia; sai se o METRICA360 passar a usar o limite do Conecta |
| `otp_tokens` | `m360_otp_tokens` | 0 | sai com o primeiro acesso (Fase I7) |
| `system_changelog` | `m360_system_changelog` | 0 | — |
| `feriados` | `m360_feriados` | 11 | colidia; sem consumidor depois da remoção, é candidato a sair junto com a tela |
| `atividades_tipos`, `registros_atividades`, `registros_atividades_itens`, `funcionario_favoritos`, `funcionario_dias_remotos`, `abonos_atividades` | — | 17, 1, 0, 1, 0, 0 | não vêm ("sem cópia do histórico"); ficam no `db_metrica360` arquivado |

---

## Apêndice B: consultas de conferência (somente leitura)

**Em produção**, no banco do METRICA360, com uma conta só de leitura:

```sql
SELECT VERSION(), @@global.time_zone, @@system_time_zone, @@character_set_server, @@collation_server;
SHOW CREATE TABLE convites_avaliacao;           -- tem origem, referencia_externa e chave_integracao?
SELECT TABLE_NAME, TABLE_COLLATION, TABLE_ROWS
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
SELECT role, deleted_at IS NULL AS ativo, COUNT(*) AS n,
       SUM(password_hash IS NULL) AS sem_senha, SUM(atendimento_externo) AS atend_externo,
       MAX(CHAR_LENGTH(email)) AS maior_email
  FROM users GROUP BY role, deleted_at IS NULL;
SELECT id, nome, coordenador_id, ativo_painel FROM setores ORDER BY nome;
SELECT coordenador_id, COUNT(*) AS setores FROM setores
 WHERE coordenador_id IS NOT NULL GROUP BY coordenador_id;
-- Repetições que apareceriam na colação nova (só MySQL 8; o erro aqui também diz a versão)
SELECT email COLLATE utf8mb4_0900_ai_ci AS k, COUNT(*) FROM users GROUP BY k HAVING COUNT(*) > 1;
SELECT nome  COLLATE utf8mb4_0900_ai_ci AS k, COUNT(*) FROM setores GROUP BY k HAVING COUNT(*) > 1;
-- Volumes e últimos ids (para o script reverso)
SELECT (SELECT COUNT(*) FROM convites_avaliacao) AS convites, (SELECT MAX(id) FROM convites_avaliacao) AS ultimo_convite,
       (SELECT COUNT(*) FROM feedbacks) AS avaliacoes, (SELECT MAX(id) FROM feedbacks) AS ultima_avaliacao;
-- E-mails dos servidores (nenhum dado de cidadão), para comparar fora do servidor com as contas do Conecta
SELECT LOWER(TRIM(email)) AS email, role, setor_id, deleted_at IS NULL AS ativo FROM users ORDER BY email;
```

**No local**, as consultas que embasaram esta análise (MySQL 8.4.10, sessão somente leitura):

```sql
-- Erro 1267: colação mista
SELECT COUNT(*) FROM db_conecta.usuarios u JOIN db_metrica360.users m ON m.email = u.email;
-- E-mails que coincidem (5)
SELECT COUNT(*) FROM db_conecta.usuarios u
  JOIN db_metrica360.users m ON LOWER(TRIM(m.email)) COLLATE utf8mb4_0900_ai_ci = LOWER(TRIM(u.email));
-- Setores com o mesmo nome e IDs trocados
SELECT c.id AS id_conecta, m.id AS id_m360, c.nome
  FROM db_conecta.setores c JOIN db_metrica360.setores m ON m.nome COLLATE utf8mb4_0900_ai_ci = c.nome;
-- Servidores com convites, com e sem conta no Conecta (4/58 e 26/156)
SELECT (u2.id IS NOT NULL) AS tem_conta, COUNT(DISTINCT c.funcionario_id) AS servidores, COUNT(*) AS convites
  FROM db_metrica360.convites_avaliacao c
  JOIN db_metrica360.users m ON m.id = c.funcionario_id
  LEFT JOIN db_conecta.usuarios u2 ON LOWER(u2.email) = LOWER(m.email) COLLATE utf8mb4_0900_ai_ci
 GROUP BY 1;
```
