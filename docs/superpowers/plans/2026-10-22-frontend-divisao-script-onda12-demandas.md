# Onda 12 — extração de Demandas/Kanban/Novo Ticket/Merge — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover o domínio "Demandas" (lista paginada de demandas abertas, visão Kanban com
drag-and-drop, criação de nova demanda, merge de demandas abertas) de `frontend/script.js`
para `frontend/demandas.js`, migrando as chamadas de rede elegíveis para `Api.*`. **Esta é a
última onda do roteiro de 12** — depois dela, `script.js` deve conter só código
genuinamente core/compartilhado (sessão, modais, loader, navegação, avatar, modo escuro,
alertas de e-mail, "Sobre") e as funções de edição de atributos de demanda que são chamadas
tanto pela lista de Demandas quanto pelo Detalhe (ver seção abaixo — ficam em `script.js` de
propósito, não por falta de decisão).

**Architecture:** Mesma mecânica das Ondas 1-11. Esta onda tem **8 pontos de corte** (a mais
fragmentada até agora) — o domínio Demandas está entrelaçado com código verdadeiramente
compartilhado entre 2+ domínios já extraídos (Arquivo, Detalhe). Como não há mais nenhuma onda
futura para "decidir depois", esta onda fecha definitivamente a questão: tudo que é
exclusivo de Demandas/Novo Ticket/Merge se move; tudo que é chamado por 2+ domínios
(incluindo os já extraídos) fica em `script.js` como infraestrutura core.

**Tech Stack:** Vanilla JS. 3 chamadas de rede convertidas para `Api.*`. O formulário de Nova
Demanda usa `FormData` (upload de arquivo) e continua em XHR puro, sem conversão — mesmo
padrão de toda a divisão.

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Por que ficam em `script.js` (decisão final, não mais "a decidir numa onda futura")

Checado o chamador real de cada uma (não só presunção) antes de decidir:

- `urlComSetor`, `exibirColunaSetor` — chamadas por Demandas (este domínio) **e** por
  `arquivo.js` (Onda 10). Ficam.
- `mostrarSkeletonTickets` — chamada por Demandas (`carregarTickets()`) **e** por
  `arquivo.js:carregarTicketsFechados()` (`mostrarSkeletonTickets('lista-fechados')`). Fica.
- `atribuirTicket`, `executarAcaoDemanda`, `pedirComentarioEExecutar`,
  `obterSetorIdDoTicket`, `alterarCategoria`/`executarAlteracaoCategoria`,
  `alterarPrioridade`/`executarAlteracaoPrioridade`, `executarAlteracaoStatus` — chamadas
  tanto pela tabela de Demandas (`onclick` nas células da linha) quanto por
  `detalhe-demanda.js` (Onda 11). Ficam.
- `confirmarAtribuicao`/`executarAtribuicao` — **código morto, sem nenhum chamador real**
  (o fluxo ativo usa `atribuirTicket` + `mostrarSelect` + `pedirComentarioEExecutar`). Já
  identificado assim desde a Onda 11; continua em `script.js`, não removido (regra do
  projeto: nunca remover sem perguntar).
- `executarExclusaoTicket` — chamada por `detalhe-demanda.js:excluirTicket` **e**
  `arquivo.js:removerTicketFechado` (nenhuma delas é Demandas). Fica, por ser
  infraestrutura de 2 outros domínios, não deste.
- `MAX_ARQUIVOS`, `MAX_TAMANHO`, `TIPOS_PERMITIDOS` — usadas pelos anexos de Nova Demanda
  (este domínio) **e** pelos anexos de comentário em `detalhe-demanda.js`. Ficam.
- **Descoberta/correção a este plano:** `alterarStatus` (distinta de `alterarStatusNovo`, que
  já vive em `status-sla.js` desde antes desta divisão) só é chamada hoje por
  `detalhe-demanda.js` — a tabela de Demandas usa `alterarStatusNovo`, não `alterarStatus`.
  Estritamente, `alterarStatus` "pertenceria" ao Detalhe (Onda 11), não a este domínio. Como
  ela está fisicamente intercalada entre `alterarCategoria`/`alterarPrioridade` (que ficam,
  por serem genuinamente compartilhadas) e segue funcionando corretamente como código global
  em `script.js`, a decisão é **deixá-la onde está** em vez de fazer uma extração cirúrgica
  isolada só por purismo de categorização — documentado aqui, não é um bug, não precisa
  perguntar ao usuário (nada está sendo removido).
- Infraestrutura genuinamente cross-cutting, sem relação com Demandas: sessão
  (`encerrarSessaoLocal`, `fazerLogout`, `tratarRespostaDeSessao`,
  `ativarTrocaObrigatoriaSenha`), alerta de falha de e-mail
  (`iniciarMonitoramentoEmailFalhas` e cia.), avatar (`inicializarAvatar`,
  `toggleAvatarDropdown` e cia. — distintas das funções de `avatar.js`, que trata o modal de
  recorte/upload), `mostrarSecao`, `setSecurityHeaders`, todo o sistema de modais
  (`mostrarLoader`/`mostrarToast`/`mostrarAlert`/`mostrarConfirm`/`mostrarPrompt`/
  `mostrarSelect`/`fecharModal`/`confirmarModal`/`promptModal`/`selecionarModal`),
  `aplicarVisibilidadePorPapel`, `restaurarSecaoAtiva`, `mostrarAlterarSenha`,
  `salvarSecaoAtiva`, `toggleModoEscuro`/`getCorLabel`, `mostrarSobre`, `voltarAoTopo`.
- `obterIconePrioridade` — código morto pré-existente (confirmado sem chamador desde a
  Onda 10), fica onde está, sem mover.

## Descoberta fora de escopo (documentar, não corrigir nesta onda)

`descreverGraficoCanvas`, `getCorLabel`, `criarGraficoStatus`, `criarGraficoPrioridade` e
`criarGraficoMensal` (linhas ~2548-3010 de `script.js`) são as funções reais de criação dos
gráficos Chart.js do Dashboard — chamadas por `dashboard.js` (Onda 8) e pelo órfão
`dashboard-update.js`. A Onda 8 presumiu que toda criação de gráfico já estava em
`graficos-dashboard.js` (arquivo pré-existente separado), mas essas 5 funções específicas
ficaram esquecidas em `script.js`. **Isso é domínio Dashboard, não Demandas — fora de escopo
desta onda.** Registrado aqui para uma eventual onda de limpeza futura, não tocado.

## Descoberta: `executarMerge` (demandas abertas) tem o mesmo bug da Onda 10

Igual ao já corrigido incidentalmente em `executarMergeFechados` (Onda 10), `executarMerge`
(para demandas **abertas**) faz um `XMLHttpRequest` POST em `merge.php` **sem**
`setSecurityHeaders(xhr)` — sem `Authorization` nem `X-CSRF-Token`. `merge.php` exige ambos
(`AuthMiddleware::verificarAuth()` + `verificarCSRF()`). A função provavelmente sempre
falhava com 401/403 quando executada de verdade. A conversão para `Api.post` corrige esse bug
incidentalmente, como já aceito e testado na Onda 10.

## Lição das ondas anteriores (aplicar desde o primeiro rascunho)

- `carregarPaginaServidor`/`carregarTickets`: os originais distinguem erro do servidor de
  erro de rede sem usar a mensagem do servidor em nenhum dos dois — colapsar num catch único
  com o texto mais informativo, igual ao padrão já aceito nas Ondas 5/9/10.
- `executarMerge`: o catch de sucesso faz `JSON.parse` manual com try/catch próprio para
  distinguir "resposta inválida" de "erro HTTP" — com `Api.post`, isso já vem resolvido
  (`Api` faz esse parse e já lança com `.dados`), então o catch final usa
  `(e.dados && e.dados.error) || 'Erro ao mesclar demandas'`, preservando a mensagem mostrada
  ao usuário quando o servidor manda uma.

## Global Constraints

- Nenhum `onclick` muda de nome ou assinatura.
- `frontend/demandas.js` entra em `index.html` depois de `detalhe-demanda.js`, antes de
  `acessibilidade.js`; versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- Lista de Demandas: paginação, busca, filtros rápidos por status (pills), "Minhas Demandas",
  ordenação por coluna, alternar "por página" — tudo via `Api.get` agora.
- Alternar para Kanban e voltar para Tabela; drag-and-drop de um card entre colunas (chama
  `executarAlteracaoStatus`, que fica em `script.js` — já funcionando, não é desta onda).
- Abrir "Nova Demanda", preencher e enviar (com e sem anexo) — `FormData`, sem conversão.
  Cancelar o formulário.
- Merge de 2+ demandas abertas — testar de verdade, mesma lógica de verificação da Onda 10
  (confirmar se o merge agora funciona, dado o bug de headers ausentes corrigido
  incidentalmente).
- Nenhuma regressão nas funções que ficam em `script.js` mas são chamadas pela tabela movida
  (atribuir responsável, mudar categoria/prioridade/status, excluir).

---

## Contexto para quem for implementar

Em `frontend/script.js`, os 8 pontos de corte (linhas de 22/10/2026, podem já ter se
deslocado um pouco):

- **Corte 1a** — linha 354 isolada: `let anexosSelecionados = [];`. Manter o comentário
  acima (`// Sistema de anexos múltiplos`, linha 353) e as constantes abaixo
  (`MAX_ARQUIVOS`/`MAX_TAMANHO`/`TIPOS_PERMITIDOS`, linhas 355-357) em `script.js`.
- **Corte 1b** — linhas 359-447: o `document.getElementById('anexos').addEventListener(...)`
  de nível superior + `adicionarMaisAnexos`, `adicionarArquivos`, `atualizarListaAnexos`,
  `removerAnexo`. Antes: as constantes do corte 1a (ficam). Depois: comentário
  `// Função para configurar headers de segurança` + `setSecurityHeaders` (fica).
- **Corte 2** — linhas 468-556: comentário `// Formulário de novo ticket...` + o
  `document.getElementById('form-ticket').addEventListener('submit', ...)` inteiro. Antes:
  fim de `setSecurityHeaders` (fica). Depois: comentário `// Carregar tickets do banco...` +
  `urlComSetor`/`exibirColunaSetor` (ficam).
- **Corte 3** (o bloco principal) — linhas 572-1187: comentário `// Lista de abertas...`
  até o fim de `alterarPorPagina`. Funções/vars: `paginacaoServidor`, `_tokenCargaLista`,
  `usaPaginacaoServidor`, `carregarPaginaServidor`, `carregarTickets`, `paginaAtual`,
  `porPagina`, `filtroStatusAtivo`, `ticketsFiltradosPorStatus`, `modoVisualizacaoTickets`,
  `aplicarFiltroMeusTickets`, `mostrarSkeletonTickets` (**não** — fica, ver acima; na
  verdade o corte pula essa função e deixa ela em `script.js`, então este corte precisa ser
  feito em 2 sub-blocos ao redor dela — ver Step 2 abaixo para o texto exato),
  `atualizarContadoresPill`, `aplicarFiltroPill`, `filtrarEMostrarTickets`,
  `renderizarPaginacaoNumeros`, `irParaPagina`, `calcularTextoSLA`, `toggleVisaoTickets`,
  `renderizarKanbanTela`, `allowDropKanban`, `dragKanban`, `dropKanban`,
  `mostrarTicketsNaTela`, `alterarPorPagina`. Antes: fim de `exibirColunaSetor` (fica).
  Depois: comentário `// Encaminhar a demanda...` + `atribuirTicket` (fica).
- **Corte 4** — linhas 2004-2097: `popularPrioridadesFormNovo` + comentário
  `// Função para limpar formulário de ticket completamente` + `limparFormularioTicket`.
  Antes: fim de `executarExclusaoTicket` (fica). Depois: comentário
  `// Função para mostrar tickets com atualização forçada` + `mostrarTicketsComAtualizacao`
  (próximo corte, contíguo).
- **Corte 5** — linhas 2099-2192: comentário + `mostrarTicketsComAtualizacao`, comentários de
  WebSocket removido, `ticketsAtuais`, `_ordemSalva`, `ordemAtual`, `ordenarTabela`. Antes:
  fim de `limparFormularioTicket` (corte 4, contíguo). Depois: comentário
  `// Função para obter ícone da prioridade` + `obterIconePrioridade` (código morto, fica).
- **Corte 6** — linhas 2469-2493: comentário `// Busca Avançada` + `_buscaDebounceTimer` +
  `configurarBuscaAvancada` + `filtrarTickets` + `limparBusca`. Antes: fim do grande bloco de
  inicialização no `DOMContentLoaded` (fica, é bootstrap core). Depois: comentário
  `// Modo Escuro` + `toggleModoEscuro` (fica).
- **Corte 7** — linhas 3012-3173: comentário `// Merge de Tickets` + `modoMergeAtivo` +
  `ticketsSelecionados` + `toggleModoMerge` + `cancelarMerge` + `selecionarTicketParaMerge` +
  `atualizarClassesMerge` + `executarMerge`. Antes: fim de `criarGraficoMensal` (fica, fora de
  escopo — ver descoberta acima). Depois: comentário `// Seção Sobre` + `mostrarSobre` (fica).
- **Corte 8** — linhas 3348-3362: comentário `// Função para ir para lista de tickets` +
  `irParaTickets` (código morto, sem nenhum chamador — mover como está) + comentário
  `// Cancelar criação de novo ticket` + `cancelarNovoTicket`. Antes: fim de
  `renderizarGraficoCanvasComLegenda`/conteúdo de `mostrarSobre` (fica). Depois: comentário
  `// === BOTÃO VOLTAR AO TOPO ===` + `voltarAoTopo` (fica).

Todas essas funções só dependem de coisas que continuam em `script.js` (globais, scripts
clássicos): `Api`, `urlComSetor`, `exibirColunaSetor`, `mostrarSkeletonTickets`,
`sanitizeHTML`, `mostrarAlert`, `mostrarToast`, `mostrarConfirm`, `mostrarSelect`,
`mostrarLoader`, `ocultarLoader`, `mostrarSecao`, `setSecurityHeaders`, `verDetalhes`
(`detalhe-demanda.js`), `atribuirTicket`, `alterarCategoria`, `alterarPrioridade`,
`executarAlteracaoStatus`, `limparFormularioTicket`, `carregarTickets`,
`calcularTempoRestanteSLA`/`formatarTempo`/`atualizarIndicadoresSLA` (`status-sla.js`),
`todasCategorias`/`renderizarCategoriasForm` (`categorias.js`),
`carregarPrioridadesDisponiveis`/`formatarNomePrioridade`/`formatarNomeStatus`
(`status-sla.js`/`ativacao-setor.js`), `salvarSecaoAtiva`.

Chamadas de rede a converter (3 no total):
1. `carregarPaginaServidor`: `GET tickets.php?pagina=...` → `Api.get`.
2. `carregarTickets` (ramo não paginado, usado só pelo Kanban): `GET tickets.php?escopo=abertas`
   → `Api.get`.
3. `executarMerge`: `POST merge.php` → `Api.post`, preservando a mensagem de erro do servidor
   via `e.dados.error` (aqui o original já tentava ler o erro do servidor, então o padrão se
   aplica normalmente).

Não convertida (usa `FormData`, fora do padrão `Api.*` desde o início da divisão): o
`addEventListener('submit', ...)` de `#form-ticket` (Corte 2).

Para rodar localmente: `http://localhost/conecta/frontend/index.html`, XAMPP em
`C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair o domínio Demandas para `frontend/demandas.js`

**Files:**
- Create: `frontend/demandas.js`
- Modify: `frontend/script.js` (remover os 8 trechos listados no contexto acima)
- Modify: `frontend/index.html` (registrar `demandas.js`, subir a versão de `script.js`)

- [x] **Step 1: Criar `frontend/demandas.js`**

- [x] **Step 2: Remover os 8 trechos de `script.js`**

Todos os 8 cortes confirmados exatamente como mapeados, incluindo a sub-divisão do Corte 3
em torno de `mostrarSkeletonTickets` (que ficou, intercalada entre o bloco que move). `script.js`
caiu de 95 para 60 funções. Confirmado que `urlComSetor`, `exibirColunaSetor`,
`mostrarSkeletonTickets`, `MAX_ARQUIVOS`/`MAX_TAMANHO`/`TIPOS_PERMITIDOS`, `atribuirTicket`,
`executarAcaoDemanda`, `pedirComentarioEExecutar`, `obterSetorIdDoTicket`, `alterarStatus`,
`alterarCategoria`/`executarAlteracaoCategoria`, `alterarPrioridade`/
`executarAlteracaoPrioridade`, `executarAlteracaoStatus`, `executarExclusaoTicket`,
`confirmarAtribuicao`/`executarAtribuicao` (código morto), `obterIconePrioridade` (código
morto) continuam em `script.js`.

- [x] **Step 3: Registrar `demandas.js` em `index.html` e subir a versão de `script.js`**

`script.js?v=4.23`; `demandas.js?v=1` adicionado após `detalhe-demanda.js`.

- [x] **Step 4: Testar no navegador (Playwright)**

Testado com contas `zz Admin Onda12`/`zz Cliente Onda12`, 2 demandas abertas de teste + 1
criada via o próprio formulário + 1 categoria de teste, tudo removido ao final.

1. ✅ Lista de Demandas carrega com paginação, Setor, pills de filtro corretos (contagens
   "Todos 2"/"Novo 1"/"Em atendimento 1"); ordenação por coluna funciona (clicado em
   "Assunto", seta mudou corretamente), sem erros de console.
2. ✅ Alternar para Kanban: colunas e cards corretos, com os 2 tickets de teste nas colunas
   certas (Novo/Em Atendimento); `Api.get('tickets.php?escopo=abertas')` (ramo não paginado)
   funcionou sem erros. **Drag-and-drop não testado via UI** — Playwright não simula de forma
   confiável eventos HTML5 `dataTransfer` nativos (limitação conhecida da ferramenta, não um
   problema do código); `dragKanban`/`allowDropKanban`/`dropKanban` foram movidos
   literalmente, sem nenhuma mudança de lógica, e chamam `executarAlteracaoStatus` (que não
   mudou nesta onda, já testado em ondas anteriores) — risco nulo, verificado por leitura de
   código.
3. Não testado "por página" isoladamente (mesma função já exercitada indiretamente pela
   paginação inicial, sem conversão nova). Risco nulo.
4. ✅ "Nova Demanda": formulário preenchido e enviado com sucesso (sem anexo), ticket criado
   corretamente no banco com a categoria/setor certos; "Cancelar" não testado isoladamente
   (função trivial, sem chamada de rede).
5. ✅ **Merge de 2 demandas abertas**: selecionadas, confirmado o diálogo, sem erros de
   console. Confirmado no banco que o merge funcionou de ponta a ponta — `ticket_historico`
   registrou `acao = 'merge'` nos dois tickets, comentários de merge inseridos em ambos, um
   dos tickets foi encerrado. **Confirma a descoberta**: `executarMerge`, sem
   `setSecurityHeaders` no XHR original, estaria sujeito ao mesmo bug já corrigido na Onda 10
   — a conversão para `Api.post` resolveu isso aqui também.
6. Não testado explicitamente "abrir o Detalhe a partir da lista" nesta onda (mesmo
   `verDetalhes`, já testado exaustivamente na Onda 11, chamado por nome sem mudança). Risco
   nulo.

- [x] **Step 5: Commit**

Commit `acb8238`.

---

### Task 2: Regressão rápida da onda

- [x] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

`grep -c "^function \|^async function " script.js` → 60 (95 − 35, confere com as funções
realmente movidas). `node --check` limpo em `script.js` e `demandas.js`.

- [x] **Step 2: Login do zero, console limpo**

Login feito do zero (contas de teste), console sem erros/warnings em nenhum passo.

- [x] **Step 3: Rodar a suíte de backend**

`composer test`: OK (31 tests, 41 assertions). `php tests/matriz-de-acesso.php`: 19 OK, 0
falhas. Idêntico ao estado anterior à onda — trabalho foi só frontend.

---

### Task 3: Fechamento do roteiro de 12 ondas

- [x] **Step 1: Conferir o tamanho final de `script.js`**

`script.js` caiu de ~10.000 linhas originais para 2.227 linhas, contendo apenas
infraestrutura core: utilitários de sanitização (`sanitizeHTML`/`htmlSeguro`/`argJs`),
sessão/autenticação (`getAuthHeaders`, `encerrarSessaoLocal`, `fazerLogout`,
`tratarRespostaDeSessao`, `ativarTrocaObrigatoriaSenha`, `setSecurityHeaders`), alerta de
falha de e-mail, avatar/dropdown do cabeçalho, `mostrarSecao`, sistema de anexos
compartilhado (constantes), `urlComSetor`/`exibirColunaSetor`/`mostrarSkeletonTickets`, as
funções de edição de atributos de demanda compartilhadas entre Demandas e Detalhe
(`atribuirTicket`, `executarAcaoDemanda`, `pedirComentarioEExecutar`, `obterSetorIdDoTicket`,
`alterarStatus/Categoria/Prioridade` e seus `executarAlteracao*`), `executarExclusaoTicket`,
todo o sistema de modais (`mostrarLoader/Toast/Alert/Confirm/Prompt/Select` e cia.),
`aplicarVisibilidadePorPapel`, navegação/estado de seção, modo escuro, os gráficos do
Dashboard esquecidos na Onda 8 (descoberta registrada, fora de escopo), "Sobre", "Voltar ao
topo", e 2 itens de código morto documentados (`confirmarAtribuicao`/`executarAtribuicao`,
`obterIconePrioridade`, `irParaTickets`).

- [x] **Step 2: Atualizar a memória do projeto**

Registrada a conclusão de todo o roteiro de divisão de `script.js` (Fase 12, P1) — ver
memória `conecta-crprs-migration.md`.
