# Execução da auditoria de 01/11/2026: Conecta CRPRS

Execução de `docs/relatorios/AUDITORIA_2026-11-01.md`, feita entre 01 e 03/11/2026 sobre o `main` em `1ede200`. Seguiu o plano em fases do relatório. Entrou só o que **não exige aprovação**; o resto está listado no fim, com o motivo. **Nada foi commitado**: todas as alterações estão no working tree, para revisão.

## 1. Resultado

| Situação | Achados |
|---|---|
| Corrigido | 31 |
| Parcialmente corrigido | 8 |
| Aguarda aprovação do usuário | 24 |
| Não feito (não exigia aprovação; ficou para depois) | 2 |
| **Total** | **65** |

- **Testes:** `composer test` (PHPUnit) **205 testes, 524 asserções, OK**. Antes eram 181 testes; entraram 24 novos.
- **Matriz de acesso** (`php api/tests/matriz-de-acesso.php`, Apache local no ar): **107 OK, 0 falha**. Os dados de teste foram removidos.
- `php -l` em todo PHP alterado ou novo e `node --check` em todo JS alterado: sem erros.
- **Migrações:** nenhuma criada. Os itens que pedem esquema (DES-04/09, INC-04, INC-07 e BUG-04 com `MEDIUMTEXT`) aguardam aprovação.
- O cron de e-mail roda a cada 2 min no computador de desenvolvimento (tarefa agendada do Windows, que não foi mexida), e passou a usar o código novo a partir de 01/11, 18:47. Nos dois dias seguintes:
  - **2,6 a 5,7 s por execução típica**, contra 17 a 35 s antes. Picos de até 17 s vêm da latência do Gmail ao abrir a conexão.
  - **3 linhas de log por execução**, contra cerca de 40 antes: 180 KB por dia, contra 3,5 MB.
  - Uma queda de conexão IMAP em 02/11, às 09:48, que não afetou as execuções seguintes.
  - Nesse período **não chegou nenhum e-mail novo** à caixa local. O caminho "mensagem nova" está coberto pelos testes, mas não foi visto em produção.

## 2. O que foi alterado, arquivo por arquivo

### Cron e leitura de e-mail (Fase 1: BUG-08, DES-01, DES-02, BUG-16)

| Arquivo | Alteração |
|---|---|
| `api/services/email-service.php` | <ul><li>`catch (Throwable)` por mensagem e por caixa.</li><li>A tentativa é contada em `emails_erro` **antes** de processar (`iniciarTentativa`/`anotarFalha`/`concluirTentativa`, com o texto `MARCA_INTERROMPIDA`). Assim, erro fatal também conta e, depois de 5, o e-mail é deixado de lado.</li><li>Estrutura (`imap_fetchstructure`) e cabeçalho lidos uma vez só; estrutura `false` vira falha registrada, não `TypeError`.</li><li>Só as mensagens novas são lidas: memória de UIDs por caixa em `backend/cache/email-uids/caixa-<id>.json` (vale enquanto a UIDVALIDITY não mudar), mais uma `imap_fetch_overview` e **uma** consulta ao banco para as desconhecidas.</li><li>Não remarca como lidas as já processadas.</li><li>Anexo proibido ou acima de 20 MB é recusado sem baixar.</li><li>Uma linha de resumo por caixa no log.</li><li>AVISO só para falha real de envio (BUG-16).</li><li>Funções puras novas: `filtrarPendentes`, `uidsAConferir`, `novaMemoriaUids`, `motivoRecusaAntesDeBaixar`.</li></ul> |
| `api/services/email-mime.php` | <ul><li>`anexos(..., ?callable $recusarAntes)` e `tamanhoEstimado()`, pelo `bytes` da estrutura: o conteúdo não é baixado para ser recusado depois.</li><li>A imagem que ainda pode ser logotipo de assinatura não passa pelo pré-filtro.</li></ul> |
| `api/services/log-service.php` | <ul><li>Um arquivo por dia (`<canal>-AAAA-MM-DD.log`); apaga os do canal com mais de `LOG_RETENCAO_DIAS` dias (padrão 30).</li><li>`detalhe()` só grava com `LOG_DETALHADO=1`.</li><li>`usarDiretorio()` para os testes (MAN-05).</li><li>O antigo `email-cron.log` (32 MB) não é tocado.</li></ul> |
| `api/services/email-sender.php` | <ul><li>`ultimoEmailFoiDesligado()` (BUG-16).</li><li>`COLUNAS_DEMANDA`: 9 consultas sem `t.*`, ou seja, sem a descrição em base64 (DES-05). `notificarAgentesNovoTicket`, que mostra a descrição, mantém o `t.*`.</li></ul> |
| `api/cron/verificar-emails.php` | <ul><li>`catch (Throwable)`.</li><li>`register_shutdown_function`: depois de erro fatal, ainda roda lembretes, fila e pesquisas, com folga de memória e tempo.</li></ul> |
| `api/cron/verificar-lembretes.php`, `verificar-emails-perdidos.php` | <ul><li>`catch (Throwable)`.</li><li>A recuperação de perdidos ignora a memória de UIDs.</li></ul> |
| `api/cron/acesso-cron.php` | `cronFolgaParaFinalizar()`: mais 128 MB e 120 s para a finalização. |

### Perda de dados e segurança de baixo risco (Fase 2)

| Arquivo | Achado | Alteração |
|---|---|---|
| `api/services/html-sanitizer.php` | BUG-01 | `texto()` remove só tags de verdade, de forma repetida, e põe um espaço depois do `<` que abre uma tag sem fechamento. "valor <100 reais" e "5<10 e 3>2" ficam iguais. |
| `api/services/demanda-service.php` | BUG-02, BUG-04 | <ul><li>A resolução desconta a pausa ainda aberta.</li><li>`resumoParaAuditoria()` grava título, status, setor, `descricao_sha256` e `descricao_tamanho`, sem a descrição, também na exclusão definitiva.</li></ul> |
| `api/controllers/tickets.php` | BUG-04, BUG-12 | <ul><li>A auditoria do cancelamento usa o resumo; o SELECT do PUT não lê mais a descrição.</li><li>`categoria_id` gravado como inteiro.</li></ul> |
| `api/services/email-fila-service.php` | SEG-03 | `marcarEnviado()`: o corpo de `recuperacao_senha` é apagado depois do envio ou da desistência. |
| `frontend/script.js`, `relatorios.js`, `auditoria.js` | SEG-04, BUG-05, SEG-14 | <ul><li>`celulaCsv()` nas 3 exportações e BOM em todas.</li><li>O CSV do log de e-mails usa `exportar=1` e avisa quando passa do teto.</li><li>A exportação da auditoria avisa quando chega ao limite.</li></ul> |
| `api/controllers/email-logs.php` | BUG-05 | `?exportar=1`: uma página de até 10 mil linhas. |
| `api/controllers/macros.php` | BUG-18 | PUT/DELETE sem `id` respondem 400 em JSON. |
| `api/controllers/usuarios.php` | BUG-19 | `registros_atividades` entra na lista de vínculos, com a mensagem de vínculo em vez de 500. |

### Segurança de servidor sem efeito em CSP/cabeçalhos (Fase 3, parte)

| Arquivo | Achado | Alteração |
|---|---|---|
| `api/controllers/auth.php` | SEG-06 | Login certo zera só a contagem da conta, não a do IP. |
| `api/services/rede-service.php` | SEG-07 | `ipDoEncaminhamento()`: X-Forwarded-For lido da direita para a esquerda. |

> Na Fase 3, a linha "SEG-10, SEG-12, SEG-06, SEG-07" está marcada "Sim (CSP e cabeçalhos)". SEG-06 e SEG-07 não mexem em CSP nem em cabeçalho (são o limite do login e a leitura do IP), por isso foram feitos. SEG-10 e SEG-12 ficaram aguardando. Se preferir, os dois feitos se revertem isoladamente: veja o diff desses dois arquivos.

### Desempenho (Fase 4)

| Arquivo | Achado | Alteração |
|---|---|---|
| `.htaccess` | DES-03 | `mod_deflate` (se ativo) e `Cache-Control: public, max-age=31536000, immutable` para `*.js`/`*.css` pedidos com `?v=`. **A CSP não mudou.** Conferido por `curl` (com `?v=`: cabeçalho presente; sem `?v=`: ausente). O gzip não pôde ser conferido: o Apache local não carrega o `mod_deflate`. |
| `api/services/cache-arquivo-service.php` (novo) | DES-07, DES-10 | Cache curto em arquivo, em `backend/cache/<grupo>/`, com a chave em sha1. |
| `api/controllers/meu-painel.php` | DES-07 | `?acao=acesso` guardado por 15 min (2 min quando o METRICA360 não respondeu). |
| `api/controllers/dashboard.php` | DES-10 | "SLA vencido" guardado por 60 s, por escopo. |
| `api/services/timeline-service.php` | DES-11 | Anexos dos comentários numa consulta só. |
| `frontend/registro-atividades.js`, `meu-painel.js` | DES-14 | O relógio para quando a tela sai de vista. |

### Consistência funcional (Fase 5)

| Arquivo | Achado | Alteração |
|---|---|---|
| `api/controllers/merge.php`, `frontend/detalhe-demanda.js` | BUG-06, BUG-07 | <ul><li>Histórico da secundária com `valor_anterior = 'secundaria'`; a tela mostra "mesclou esta demanda na demanda #…", também nas antigas (pelo `mesclada_em`).</li><li>Sem o "##".</li><li>Comentários automáticos em HTML, com "demanda" e a descrição resumida em até 1000 caracteres.</li></ul> |
| `api/services/lembrete-service.php` | BUG-09 | `retomarSeAguardandoData` usa `DemandaService::mudarStatus`, com trava, conferência do status sob trava e do catálogo do setor. |
| `api/controllers/comentarios.php` | BUG-10 | <ul><li>Comentário, primeira resposta, anexos e troca para `nova_resposta` numa transação, com `iniciarLote`/`desfazerLote`.</li><li>Avisos e e-mails depois do commit.</li></ul> |
| `api/controllers/me.php` (novo), `frontend/script.js` | INC-06 | <ul><li>Dados atuais do usuário.</li><li>Ao carregar, a tela compara e, se o papel ou o setor mudou, guarda e recarrega uma vez.</li></ul> |
| `api/services/upload-service.php`, `integracoes/abrir_chamado.php`, `email-service.php` | SEG-09 | `validarArquivo`/`validarConteudo`: DOCX/XLSX vistos como `application/zip` precisam ter `[Content_Types].xml` e `word/` ou `xl/`. |
| `api/controllers/personalizacao.php` | SEG-13 (parte) | <ul><li>Caminhos absolutos (`dirname(__DIR__, 2)`).</li><li>Auditoria `alterar_logo`.</li></ul> |
| `api/controllers/auditoria-avancada.php` | SEG-14 | `pagina ≥ 1`, `1 ≤ limite ≤ 200`; exportação com teto de 50 mil (`limitado`). |
| `frontend/script.js` + 11 arquivos JS | BUG-14 | `dataDoBanco()` em 39 conversões. Também corrige a data sozinha (`DATE(...)`), que era lida como UTC e aparecia um dia antes (gráfico de logins da auditoria). |

### Manutenção, documentação e textos (Fase 6)

| Arquivo | Achado | Alteração |
|---|---|---|
| `frontend/script.js`, `categorias.js`, `demandas.js`, `detalhe-demanda.js`, `tutorial.js` | FE-01 | 23 `console.log` de depuração retirados. Os `console.error` dos `catch` ficaram. |
| `frontend/detalhe-demanda.js`, `ajuda.js`, `index.html` | FE-02 | `alt=""` no clipe decorativo, `alt` nos ícones de atenção e `aria-label` no botão "Voltar ao topo". |
| `api/controllers/integracoes/abrir_chamado.php` | INC-05 (parte) | <ul><li>"retorno da TI", "Mesa de TI", "Bloco Hacker", "Interceptação API" e "ticket M2M" trocados por textos com "demanda".</li><li>Azul `#3b82f6` trocado pela paleta CRPRS no HTML gravado na descrição.</li><li>**Mudou o texto de `message` da resposta da integração: avisar os mantenedores dos satélites.** Os campos (`ticket_id`, `protocolo`…) não mudaram.</li></ul> |
| `api/config/.env.example` | INC-03 (parte) | <ul><li>Linha 72 com `/n` literal desfeita.</li><li>`PROXIES_CONFIAVEIS=` e o título "CLOUDFLARE TURNSTILE" voltaram.</li><li>`LOG_RETENCAO_DIAS` e `LOG_DETALHADO` documentados.</li></ul> |
| `CLAUDE.md` | INC-02 e registro | <ul><li>Linhas do `script.js`; migrações 9–38; Alpine → `editor-descricao.js`; 5 JS na tabela; log de e-mails em "Configurar E-mail"; ordem 36/37.</li><li>Nova seção "Auditoria de 01/11/2026" e ajustes em autenticação, upload, XSS, fila e e-mail recebido.</li></ul> |
| `docs/implantacao-producao.md` | Fase 0, MAN-04, SEG-01 | <ul><li>Tabela do que conferir no servidor.</li><li>Lista do que **não** enviar ao servidor.</li><li>Logs e cache novos, e o checklist pós-deploy.</li><li>SEG-01 documentado como decisão pendente, com tudo que muda junto.</li></ul> |
| `api/tests/bootstrap.php` | MAN-05 | Os testes gravam o log na pasta temporária e não ecoam no terminal. |
| `frontend/index.html` | (regra do projeto) | `?v=` incrementado (`.a1101`) nos 16 JS alterados. |

### Testes novos ou ampliados (MAN-01, parte)

- `api/tests/Unit/EmailCronRobustezTest.php` (novo):
  - anexo de 40 MB e extensão proibida recusados **sem baixar**;
  - logotipo de assinatura preservado;
  - estimativa de tamanho;
  - filtro das já processadas e memória de UIDs;
  - ZIP renomeado para `.docx` recusado.
- `api/tests/Unit/LogServiceTest.php` (novo): nome por dia e limpeza dos vencidos do mesmo canal.
- `api/tests/Unit/CacheArquivoServiceTest.php` (novo).
- `api/tests/Unit/HtmlSanitizerTest.php`: casos do BUG-01 e de tag que não pode se formar.
- `api/tests/Unit/RedeServiceTest.php`: X-Forwarded-For da direita para a esquerda.
- `api/tests/Integracao/EmailRecebidoTest.php`: tentativa contada antes de processar; sexta tentativa passa do limite.
- `api/tests/Integracao/FluxoDemandaTest.php`:
  - pausa em andamento descontada (BUG-02);
  - auditoria com descrição de cerca de 200 KB (BUG-04);
  - lembrete pela regra única (BUG-09).
- `api/tests/Integracao/EmailFilaTest.php` (novo): corpo da recuperação de senha apagado após o envio.

## 3. Testes executados

| Teste | Resultado |
|---|---|
| `cd api && composer test` (antes) | 181 testes OK |
| `cd api && composer test` (final) | **205 testes, 524 asserções, OK** |
| `php api/tests/matriz-de-acesso.php` | **107 OK, 0 falha**; dados de teste removidos |
| `php -l` em todo PHP alterado ou novo; `node --check` em todo JS alterado | sem erros |
| Medição IMAP só leitura (`OP_READONLY`) na caixa real do setor 2 | ~0,4 s por ida e volta ao Gmail; a visão geral custa uma ida por mensagem, por isso a memória de UIDs |
| HTTP real, comentário (BUG-10), como `zz_nav_gestor` numa demanda `zz` criada e apagada no teste | comentário, anexo PDF e `nova_resposta` (com histórico) gravados juntos; comentário vazio → 400; limpeza confirmada (0 restante) |
| HTTP real, como `zz_nav_admin` | `me.php` 200; `email-logs.php?exportar=1` com limite 10000; sem exportar, 100; `auditoria-avancada.php?limite=0&pagina=-3` 200 (antes 500); `macros.php` PUT/DELETE sem id 400 |
| `curl -I` nos estáticos | `Cache-Control immutable` só com `?v=`; CSP igual |
| Log do cron, 01–03/11 | ver o item 1 |

**Não executado:**

- Teste no navegador: as telas mudaram pouco (CSV, datas, histórico da mesclagem, relógios, `me.php`). Vale abrir lista, detalhe de uma demanda mesclada, Arquivo, Auditoria → exportar CSV, Registro de Atividades e Meu Painel e conferir o console.
- Execução do cron contra um e-mail novo de verdade (não chegou nenhum no período).
- Conferência do gzip, porque o `mod_deflate` está desligado no Apache local.

## 4. O que não foi executado e por quê

### Aguardam aprovação (a própria auditoria marca como decisão do usuário)

| Achado | Por que precisa de aprovação | O que fica pronto ou sugerido |
|---|---|---|
| SEG-01 (alto) | Infraestrutura: DNS, certificado, URL nova | Documentado em `docs/implantacao-producao.md` §1, com a lista do que muda junto |
| SEG-02 | CSP e aparência do Meu Painel | `<iframe sandbox>` de outra origem, ou link externo, e tirar `elfsight` do `script-src` |
| SEG-05 | Novo limite visível ao usuário | Sugestão: 413 acima de 8 MB de HTML e contador no editor (M-06) |
| SEG-08 | Muda a duração da sessão | Sugestão: claim `auth_time` e limite absoluto (ex.: 7 dias) |
| SEG-10 | Cabeçalhos de segurança | Decidir entre `X-Frame-Options` DENY e SAMEORIGIN (o `.htaccess` manda SAMEORIGIN e os controllers mandam DENY), e `X-XSS-Protection: 0` |
| SEG-11 | Muda o comportamento da integração | Usar a conta só se for `cliente` |
| SEG-12 | Configuração de SSO (marcado com SEG-10) | `trustedParentOrigins` por ambiente |
| SEG-15 / DES-13 | Apaga dados (retenção LGPD) | Feita só a parte que não apaga nada: auditoria sem a descrição (BUG-04). Falta definir os prazos por tabela |
| BUG-03 | Muda o SLA exibido | Feriados do Geral para setores sem feriados próprios, e cadastrar o calendário |
| BUG-11 | Regra de quem comenta | Decidir se `podeVerTicket` basta ou se exige `podeOperarTicket` |
| BUG-13 | Texto visível do login | Usar o 429 e os minutos do servidor |
| BUG-15 | Quem recebe a confirmação | Não confirmar para a equipe; retirar a `$mensagem` sem uso |
| BUG-17 | Textos de limite de anexo | Um texto único |
| BUG-20 | Regra do fluxo | Transferir volta para `novo`; devolver sem responsável dá 400 |
| INC-01 | Dados do banco que vai para produção | Ver as decisões 1 e 2 abaixo |
| INC-04 | Remoção de código e colunas | Lista no relatório; nada removido |
| INC-07 | Esquema (chaves estrangeiras) | Migração 40, depois de conferir os órfãos |
| INC-08, INC-09 | Definição de métricas e prazo de SLA | Com a gestão |
| DES-04, DES-09 | Esquema (índices) | Migração 39 |
| DES-06 | Muda a tela do relatório | Paginação e agregados no SQL |
| MAN-02, MAN-03 | Refatoração grande e troca de versões | PHPMailer 7 e PHP 8.3/8.4 |

### Parcialmente corrigidos

| Achado | O que falta e por quê |
|---|---|
| SEG-13 | Ficaram a criação de `*.backup` públicos e o ramo `tipo=login` (sem uso), porque remover exige aprovação. A gravação em `frontend/images` continua: mudar o local muda onde a tela lê o logotipo |
| SEG-15 | Ver acima |
| INC-02 | `README.md` (de 01/12/2025) não foi reescrito: é um documento inteiro a refazer, fora do escopo de "corrigir o que está errado" |
| INC-03 | O comentário do `.env` local ("X-Cron-Key ou ?key=") não foi mexido, porque é o arquivo de segredos do ambiente. Basta trocar a linha 45 à mão |
| INC-05 | As cores em hex dentro dos templates JS (`detalhe-demanda.js` e outros) ficaram: entram no projeto de CSP sem `'unsafe-inline'`, tela por tela |
| DES-11 | As consultas em laço de `atividades-acompanhamento-service.php` ficaram: o cálculo de pendências por servidor precisa de outra estrutura, com medição |
| MAN-01 | Faltam testes de `comentarios.php`, `demanda-acoes.php`, `merge.php`, `AuthMiddleware`, `CryptoService` e `RateLimitService`. BUG-03 aguarda decisão. O BUG-10 foi conferido por HTTP, mas não virou teste automático |
| MAN-04 | Lista de exclusões no roteiro de implantação; o script de empacotamento não foi feito |

### Não feitos (não exigiam aprovação; ficaram para depois)

- **DES-08** (assinatura da lista): trocar o `BIT_XOR(CRC32(...))` por `COUNT` + `MAX(atualizado_em)` pode deixar de detectar mudanças que hoje a atualização automática pega (status, responsável, não lidos, lembretes). Com 24 demandas não há ganho mensurável. Fazer junto com o índice de DES-04.
- **DES-12** (uma conexão SMTP por mensagem): mexe no envio real da fila, que está em uso. Precisa de teste contra o servidor SMTP de verdade.

### Melhorias sugeridas (M-01 a M-15)

Não entraram: são novas funcionalidades, não correções. O M-07 (endpoint `me`) foi feito por ser o próprio INC-06.

## 5. Migrações criadas

Nenhuma.

## 6. Decisões para o usuário

1. **SEG-01 (alto): subdomínio próprio** (`conecta.crp07.org.br`) antes do primeiro deploy e antes de avisar os satélites. Tudo que muda junto está em `docs/implantacao-producao.md` §1.
2. **INC-01: banco que vai para produção.** O setor 2 (Cadastro) está com os 15 e-mails desligados, a tabela `feriados` está vazia, há 24 demandas e 7 solicitantes de teste. Sobraram também 3 usuários `zz_nav_*` (de 27/10) e 2 linhas `zz` em `email_fila` (21–22/10), que não vieram deste trabalho. Diga o que religar e o que apagar.
3. **BUG-03 + feriados:** setor sem feriados próprios passa a usar os do Geral? E quem cadastra o calendário de 2026–2027?
4. **Regras de fluxo:**
   - BUG-11: só o responsável comenta e muda o status?
   - BUG-20: transferir volta a demanda para `novo`?
   - BUG-15: a equipe deixa de receber "seu comentário foi adicionado"?
5. **Esquema e retenção:**
   - aprovar as migrações 39 (índices, DES-04/09) e 40 (chaves estrangeiras e colunas sem uso, INC-04/07);
   - definir a política de retenção LGPD (SEG-15/DES-13).

Outras decisões menores: SEG-02 (Elfsight), SEG-05 (limite de 8 MB), SEG-08 (sessão absoluta), SEG-10/12 (cabeçalhos e SSO), SEG-11 (integração), BUG-13/BUG-17 (textos) e INC-04 (código morto, item a item).

## 7. Pontos de atenção na revisão

- **Arquivos novos de estado em `backend/cache/`** (pasta fora do Git e negada pelo `backend/.htaccess`):
  - `email-uids/` (memória de UIDs do cron);
  - `meu-painel-acesso/`;
  - `dashboard-sla-vencido/`.
  
  Podem ser apagados a qualquer momento: tudo é recalculado.
- **Log:** o cron grava em `backend/logs/email-cron-AAAA-MM-DD.log`. O `email-cron.log` antigo (32 MB) pode ser arquivado ou apagado.
- **Comentário pela tela (BUG-10):** antes, uma falha ao mudar o status era engolida e o comentário ficava. Agora tudo é desfeito e a tela recebe 500.
- **Achado novo, não corrigido:** em `api/services/email-sender.php`, `use PHPMailer\PHPMailer\Exception;` faz todo `catch (Exception $e)` daquele arquivo capturar **só** a exceção do PHPMailer. Um `PDOException` num método de notificação sobe para quem chamou. Está registrado no `CLAUDE.md`. A correção (`catch (\Throwable $e)`) é simples, mas mexe em 15 métodos de envio: vale fazer em separado.
- A tarefa agendada do Windows que roda o cron no computador de desenvolvimento não foi alterada.
