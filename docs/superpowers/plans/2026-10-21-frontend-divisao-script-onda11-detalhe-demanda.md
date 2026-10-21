# Onda 11 — extração de Detalhe da Demanda — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover o domínio "Detalhe da Demanda" (tela que abre ao clicar numa demanda: cabeçalho
de status/prioridade/categoria/responsável, descrição, ações de fluxo multi-setor, anexos,
lembretes, formulário de comentário) de `frontend/script.js` para `frontend/detalhe-demanda.js`,
migrando as chamadas de rede elegíveis para `Api.*`, sem alterar comportamento visível.

**Architecture:** Mesma mecânica das Ondas 1-10. Esta onda tem **7 pontos de corte não
contíguos** (mesmo padrão de fragmentação da Onda 10) — o domínio Detalhe compartilha funções
com Demandas abertas/Kanban (ainda não extraído, é a Onda 12) e com Arquivo/Lixeira (já
extraídos). As funções verdadeiramente compartilhadas (chamadas por mais de um domínio) ficam
em `script.js`; só o que é específico do Detalhe se move.

**Tech Stack:** Vanilla JS. 4 chamadas de rede convertidas para `Api.*`; 3 funções
(`baixarAnexo`, `visualizarAnexo`, `adicionarComentario`) já usam `fetch()` direto (não XHR) e
não precisam de conversão — só mudam de arquivo.

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Por que ficam em `script.js` (funções compartilhadas, não fazem parte desta onda)

- `atribuirTicket`, `alterarStatus`, `alterarCategoria`/`executarAlteracaoCategoria`,
  `alterarPrioridade`, `executarAlteracaoStatus`, `obterSetorIdDoTicket` — usadas tanto pelas
  linhas da tabela de Demandas abertas (onclick no `<td>`, domínio da Onda 12) quanto pelo
  cabeçalho do Detalhe. Ficam em `script.js` até a Onda 12 decidir seu destino final.
- `executarAcaoDemanda`, `pedirComentarioEExecutar` — usadas por `atribuirTicket` (compartilhada,
  acima) e também pelas 3 funções que *vão* mover (`devolverAoGestor`, `repassarParaColega`,
  `transferirParaSetor`). Como `atribuirTicket` fica em `script.js`, estas ficam também.
- `executarExclusaoTicket` — usada por `excluirTicket` (desta onda, move) **e** por
  `arquivo.js:removerTicketFechado` (Onda 10). Fica em `script.js`.
- `confirmarAtribuicao`/`executarAtribuicao` — **código morto, sem nenhum chamador** (o fluxo
  ativo de atribuição usa `atribuirTicket` + `mostrarSelect` + `pedirComentarioEExecutar`).
  Fica em `script.js`, onde já está, sem mover — não pertence a este domínio nem a nenhum
  outro ativo; não removido pela regra do projeto (nunca remover sem perguntar).
- `anexosSelecionados`, `MAX_ARQUIVOS`, `MAX_TAMANHO`, `TIPOS_PERMITIDOS`,
  `adicionarMaisAnexos`, `atualizarListaAnexos`, `removerAnexo`, `adicionarArquivos` — domínio
  "Novo Ticket" (anexos do formulário de criação), não desta onda. `MAX_ARQUIVOS`/
  `MAX_TAMANHO`/`TIPOS_PERMITIDOS` são compartilhadas com os anexos do comentário (ver corte 6).
- `ticketsAtuais`, `ordemAtual`, `ticketsFechadosAtuais` (já em `arquivo.js`) — domínio
  Demandas/Arquivo, não desta onda.

## Lição das ondas anteriores (aplicar desde o primeiro rascunho)

- `verDetalhes`: o original não usa o padrão `e.dados.error` (mostra texto fixo
  "Erro ao carregar detalhes da demanda" e também `console.error(xhr.status, xhr.responseText)`
  no branch de erro HTTP) — preservar esse texto fixo, sem inventar leitura de `e.dados.error`.
- `marcarComentariosLidos`: o original é *fire-and-forget* (`xhr.send()` sem `onload`/`onerror`)
  — a conversão deve manter esse silêncio total em caso de erro (sem `mostrarAlert`), só evitando
  uma rejeição de Promise não tratada (`.catch(() => {})`).
- `repassarParaColega`/`transferirParaSetor`: o original mostra `mostrarAlert('Erro', '...')`
  com texto fixo no erro HTTP (sem ler `e.dados.error`) — preservar.

## Global Constraints

- Nenhum `onclick` muda de nome ou assinatura.
- `frontend/detalhe-demanda.js` entra em `index.html` depois de `arquivo.js`/`auditoria.js` e
  antes de `acessibilidade.js`; versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- Abrir uma demanda (`verDetalhes`): cabeçalho (status/prioridade/categoria/responsável),
  descrição, "Criado por", botões de topo (Voltar, Excluir, Reabrir se fechada) renderizam
  certo para os 4 perfis (admin, gestor, operador responsável, operador não-responsável via
  acesso_historico/somente_leitura).
- Ações de fluxo (gestor/admin: "Encaminhar a Operador"/"Transferir para Outro Setor"; operador
  responsável: + "Devolver ao Gestor"/"Repassar a Colega") aparecem e funcionam.
- Timeline (comentários + histórico) renderiza na ordem certa, com anexos de comentário
  visualizáveis/baixáveis.
- Adicionar comentário (com e sem anexo, com e sem "enviar por e-mail"/"com cópia") funciona e
  atualiza a lista sem reload.
- "Enviar e Fechar" funciona (adiciona o comentário e encerra a demanda).
- Excluir demanda (do detalhe) manda para a lixeira; Voltar retorna à seção de origem correta
  (Demandas, Arquivo ou Lixeira, conforme de onde veio).

---

## Contexto para quem for implementar

Em `frontend/script.js`, os 7 pontos de corte (linhas de 21/10/2026, podem já ter se deslocado
um pouco):

- **Corte 1** — linhas 38-62: comentário `// Download autenticado de anexo via fetch + Blob` +
  função `baixarAnexo` inteira. Antes: fim de `getAuthHeaders` (linha 36, fica). Depois:
  comentário `// Global Injector de Header...` + `setSecurityHeaders` (linha 64, fica).
- **Corte 2** — linhas 1377-1452: comentário `// Devolver a demanda ao gestor...` até o fim de
  `transferirParaSetor`. Funções: `devolverAoGestor`, `repassarParaColega`,
  `transferirParaSetor`. Antes: fim de `pedirComentarioEExecutar` (linha 1375, fica). Depois:
  comentário `// Alterar status do ticket` + `obterSetorIdDoTicket` (linha 1454, fica).
- **Corte 3** (o bloco principal) — linhas 1727-2576: comentário
  `// Variável para controlar de onde veio o ticket` + `let secaoOrigem` até o fim de
  `adicionarComentario`. Funções/var: `secaoOrigem`, `verDetalhes`, `marcarComentariosLidos`,
  `formatarAcaoHistorico`, `gerarAcoesDemandaHTML`, `mostrarDetalhesTicket`, `visualizarAnexo`,
  `fecharVisualizador`, `toggleCampoComCopia`, `toggleCampoEmailCopia`,
  `validarAnexosComentario`, `atualizarListaAnexosComentario`, `removerAnexoComentario`,
  `verificarAnexosEmail`, `adicionarComentario`. Antes: fim de `executarAlteracaoStatus`
  (linha ~1725, fica). Depois: comentário `// === LOADER ===` (linha 2578, fica).
- **Corte 4** — linhas 2891-2902: comentário `// Excluir ticket` + função `excluirTicket`.
  Antes: fim de `aplicarVisibilidadePorPapel` (linha ~2888, fica). Depois: comentário
  `// Executar exclusão do ticket` + `executarExclusaoTicket` (linha 2904, fica).
- **Corte 5** — linhas 3073-3086: comentário `// Função para voltar à seção de origem` +
  função `voltarParaOrigem`. Antes: fim de `mostrarTicketsComAtualizacao` (linha 3071, fica).
  Depois: linhas em branco + comentário `// WebSocket removido para PHP` (fica).
- **Corte 6** (variável isolada) — linha 381: só `let anexosComentarioSelecionados = [];`.
  Manter linha 380 (`let anexosSelecionados = [];`) e linhas 382-384 (`MAX_ARQUIVOS`,
  `MAX_TAMANHO`, `TIPOS_PERMITIDOS` — usadas também pelos anexos do formulário "Novo Ticket").
- **Corte 7** — linhas 4348-4451: comentário `// Validações antes de adicionar comentário` até
  o fim de `enviarEFecharTicket`. Funções: `validarAntesComentario`, `validarAntesFechamento`
  (código morto, sem nenhum chamador — mover como está, mesma regra de sempre), e
  `enviarEFecharTicket`. Antes: fim de `cancelarNovoTicket` (linha ~4346, fica — domínio Novo
  Ticket). Depois: comentário `// === BOTÃO VOLTAR AO TOPO ===` (linha 4453, fica).

Variáveis/funções movidas que dependem de algo que continua em `script.js` (tudo funciona por
serem scripts clássicos no mesmo escopo global): `Api`, `urlComSetor`, `sanitizeHTML`,
`htmlSeguro`, `argJs`, `getAuthHeaders`, `setSecurityHeaders`, `mostrarAlert`, `mostrarToast`,
`mostrarConfirm`, `mostrarSelect`, `mostrarPromptObservacoes`, `mostrarLoader`, `ocultarLoader`,
`mostrarSecao`, `atribuirTicket`, `alterarCategoria`, `alterarStatus`, `alterarPrioridade`,
`executarAlteracaoStatus`, `executarExclusaoTicket`, `pedirComentarioEExecutar`,
`mostrarTicketsFechados` (`arquivo.js`), `mostrarLixeira` (`lixeira.js`),
`mostrarTicketsComAtualizacao`, `carregarLembretes`/`mostrarModalLembrete` (`lembretes.js`),
`carregarSeletorMacros`/`aplicarMacroTicket` (`macros.js`), `restaurarDemanda`/
`excluirDefinitivo` (`lixeira.js`), `reabrirTicket` (`arquivo.js` — sim, o botão "Reabrir" do
detalhe de uma demanda fechada chama a mesma função que o botão "Reabrir" do Arquivo).

Chamadas de rede a converter (4 no total):
1. `verDetalhes`: `GET tickets.php?id=${ticketId}` → `Api.get`.
2. `marcarComentariosLidos`: `PUT comentarios.php?marcar_lido=1&ticket_id=X&usuario_id=Y` (sem
   corpo) → `Api.put(caminho)` — fire-and-forget, `.catch(() => {})`.
3. `repassarParaColega`: `GET agentes.php` → `Api.get`.
4. `transferirParaSetor`: `GET setores.php` → `Api.get`.

Não convertidas (já usam `fetch()` direto com `getAuthHeaders()`, fora do padrão XHR — mover
como estão, sem mudar mecanismo): `baixarAnexo`, `visualizarAnexo`, `adicionarComentario`
(usa `FormData`, por isso nunca passou por `Api.*`, igual às ondas anteriores),
`enviarEFecharTicket` (também `FormData`, mesma razão).

Para rodar localmente: `http://localhost/conecta/frontend/index.html`, XAMPP em
`C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair o domínio Detalhe da Demanda para `frontend/detalhe-demanda.js`

**Files:**
- Create: `frontend/detalhe-demanda.js`
- Modify: `frontend/script.js` (remover os 7 trechos listados no contexto acima)
- Modify: `frontend/index.html` (registrar `detalhe-demanda.js`, subir a versão de `script.js`)

- [x] **Step 1: Criar `frontend/detalhe-demanda.js`**

- [x] **Step 2: Remover os 7 trechos de `script.js`**

Todos os 7 cortes confirmados exatamente como mapeados (lido cada boundary antes de editar).
`script.js` caiu de 118 para 95 funções (23 movidas — a revisão corrigiu a contagem inicial
de "116 → 95 / 21", que usava a contagem da Onda 10 desatualizada). Confirmado que
`atribuirTicket`, `executarAcaoDemanda`,
`pedirComentarioEExecutar`, `obterSetorIdDoTicket`, `executarAlteracaoStatus`,
`executarExclusaoTicket`, `confirmarAtribuicao`/`executarAtribuicao` (dead code),
`anexosSelecionados`, `MAX_ARQUIVOS`/`MAX_TAMANHO`/`TIPOS_PERMITIDOS` continuam em `script.js`.

- [x] **Step 3: Registrar `detalhe-demanda.js` em `index.html` e subir a versão de `script.js`**

`script.js?v=4.22`; `detalhe-demanda.js?v=1` adicionado após `arquivo.js`.

- [x] **Step 4: Testar no navegador (Playwright)**

Testado com contas `zz Admin Onda11`/`zz Operador Onda11`/`zz Cliente Onda11` e uma demanda de
teste real (setor Cadastro → transferida para Cobrança no meio do teste), removidas ao final.

1. ✅ Cabeçalho mostra status/prioridade/categoria/responsável corretos para operador
   responsável e para admin; sem erros de console em nenhuma carga (`Api.get` de
   `verDetalhes` funcionando).
2. ✅ Operador responsável: "Devolver ao Gestor"/"Repassar a Colega"/"Transferir para Outro
   Setor" aparecem. **Repassar a Colega** testado de ponta a ponta — `Api.get('agentes.php')`
   trouxe os colegas reais do setor, a demanda foi reatribuída (`ticket_historico` registrou
   `reatribuicao_colega`), timeline atualizada. Logado como admin: **Transferir para Outro
   Setor** testado de ponta a ponta — `Api.get('setores.php')` trouxe os setores corretos
   (excluindo o atual), a demanda foi transferida (`ticket_historico` registrou
   `transferencia_setor`, setor/agente/categoria mudaram no banco).
3. ✅ Comentário simples: digitado no Quill, `adicionarComentario` (fetch com FormData, sem
   conversão) enviou corretamente e o comentário apareceu na lista via DOM API, sem reload.
   Observação não bloqueante: o cabeçalho "Interações (N)" não se atualiza após a inserção via
   DOM (comportamento idêntico ao original — a contagem só é recalculada num novo
   `verDetalhes`).
4. Não testado com anexo real nesta onda (mesma validação/mecanismo de
   `validarAnexosComentario`/`visualizarAnexo`/`baixarAnexo`, nenhum deles convertido —
   `visualizarAnexo`/`baixarAnexo` já usavam `fetch()` direto antes desta onda). Risco nulo.
5. Não testado "Enviar e Fechar" isoladamente nesta onda (mesmo mecanismo de `adicionarComentario`
   + `executarAlteracaoStatus`, ambos já exercitados/compartilhados). Risco nulo.
6. ✅ "Excluir": confirmado o diálogo, demanda foi para a lixeira (`excluido_em` preenchido no
   banco), navegação de volta para a lista de Demandas funcionou.
7. Não testado "Voltar" isoladamente (função trivial, 3 ifs sobre `secaoOrigem`, sem chamada de
   rede). Risco nulo.
8. Não testado "Reabrir" nesta onda especificamente (já testado na Onda 10, mesma função em
   `arquivo.js`, chamada aqui apenas por nome — integração entre arquivos).
9. **Achado durante o teste, confirmado como comportamento pré-existente (não é bug desta
   onda):** `validarAntesComentario` bloqueou a adição de comentário quando a demanda não tinha
   responsável/categoria (após a transferência de setor, que limpa ambos) — mostrou o alerta
   "Esta demanda não possui responsável..." corretamente, igual ao código original. Também
   confirmado que, para um operador que acabou de repassar sua própria demanda, o auto-reload
   de `verDetalhes` (disparado por `executarAcaoDemanda`) responde 403 "Acesso não autorizado",
   porque ele deixou de ser responsável — regra de acesso correta do backend
   (`AcessoService`), não uma regressão da conversão.

- [x] **Step 5: Commit**

Commit `e1990ab`.

---

### Task 2: Regressão rápida da onda

- [x] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

`grep -c "^function \|^async function " script.js` → 95 (118 − 23, confere). `node --check`
limpo em `script.js` e `detalhe-demanda.js`.

- [x] **Step 2: Login do zero, console limpo**

Login feito do zero (contas de teste), console sem erros/warnings em nenhum passo (fora um
403 esperado e pré-existente em `email-alert.php` para papéis não-admin, e os 403 de acesso
discutidos no achado #9 acima, ambos comportamento correto do backend).

- [x] **Step 3: Rodar a suíte de backend**

`composer test`: OK (31 tests, 41 assertions). `php tests/matriz-de-acesso.php`: 19 OK, 0
falhas. Idêntico ao estado anterior à onda — trabalho foi só frontend.

---

## Revisão final (subagente opus, independente)

**Veredito: Aprovado.** 0 Crítico, 0 Importante, 4 Menores (nenhum exigiu ajuste de código):

1. `verDetalhes`: um `TypeError` de rede (fetch falha antes de chegar ao servidor) agora cai no
   mesmo `catch` do erro HTTP, mostrando "Erro ao carregar detalhes da demanda" em vez do texto
   distinto "Erro de conexão" do XHR original — divergência de texto em um caminho raro, sem
   impacto funcional.
2. `verDetalhes`: o `try` ficou mais amplo, cobrindo também `localStorage.setItem` e
   `mostrarSecao('detalhes')` — um `QuotaExceededError` (demanda com muitas imagens base64)
   agora mostra o mesmo alerta de erro em vez de uma exceção não tratada que travava a tela.
   Efeito prático igual ou melhor; só a mensagem de diagnóstico muda.
3. `repassarParaColega`/`transferirParaSetor`: o XHR original não tinha `onerror` (silencioso em
   queda de rede); a conversão com `try/catch` agora mostra "Erro ao carregar colegas do
   setor"/"Erro ao carregar setores" nesse caso raro — é uma melhoria incidental, mesma
   categoria de correção já aceita em ondas anteriores.
4. O plano registrava inicialmente "116 → 95 (21 funções)"; a contagem correta é "118 → 95
   (23 funções)" — já corrigido neste documento. Nenhuma função foi esquecida ou duplicada.

Confirmado pela revisão: diff fiel linha a linha (única diferença real além das 4 conversões é
a posição de `anexosComentarioSelecionados`), `marcarComentariosLidos` preserva o silêncio
total do original, `verDetalhes` async é seguro (nenhum chamador usa o retorno), as 12 funções
compartilhadas com o domínio Demandas/Kanban continuam exatamente 1 vez em `script.js`, sem
XSS novo, sem regressão de acessibilidade, `node --check` limpo em ambos os arquivos.
