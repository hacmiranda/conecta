# Onda 10 — extração de Arquivo/Fechadas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover o domínio Arquivo/Fechadas (lista paginada de demandas encerradas/canceladas,
filtros, ordenação, merge, reabertura/remoção) de `frontend/script.js` para
`frontend/arquivo.js`, migrando as 2 chamadas de rede para `Api.*`, sem alterar
comportamento visível (exceto uma correção incidental documentada abaixo).

**Architecture:** Mesma mecânica das Ondas 1-9. Esta onda tem **7 pontos de corte não
contíguos** — o domínio Arquivo está fisicamente entrelaçado com o domínio Demandas abertas
em vários pontos: variáveis de estado declaradas na mesma lista (`let ticketsAtuais = []; let
ticketsFechadosAtuais = []; ...`), funções de ordenação vizinhas (`ordenarTabela` /
`ordenarTabelaFechados`), e flags de merge vizinhas (`modoMergeAtivo` / `ticketsSelecionados`
vs. `modoMergeFechadosAtivo` / `ticketsFechadosSelecionados`). Em cada caso, só a declaração
específica de Arquivo é movida — a de Demandas abertas fica em `script.js`.

**Tech Stack:** Vanilla JS. Só 2 chamadas de rede nesta onda (a maioria das ~24 funções só
manipula estado local ou HTML).

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Descoberta: `executarMergeFechados` provavelmente está quebrada hoje (bug pré-existente)

O XHR original de `executarMergeFechados` **não chama `setSecurityHeaders(xhr)`** — não
envia `Authorization` nem `X-CSRF-Token`. Confirmado em `api/controllers/merge.php:19-21`
que o endpoint exige `AuthMiddleware::verificarAuth()` + `verificarGestor()` +
`verificarCSRF()`. Como o sistema usa JWT em `localStorage` (não cookies de sessão), uma
requisição sem esses cabeçalhos deveria sempre falhar com 401. `Api.post` (como toda chamada
`Api.*`) sempre inclui esses cabeçalhos via `getAuthHeaders()` — a conversão corrige esse bug
incidentalmente, do mesmo jeito que ondas anteriores já corrigiram bugs parecidos ao
converter para `Api.*`. **Testar de verdade no navegador** (Review Focus abaixo) para
confirmar se o merge de demandas fechadas de fato não funcionava antes e passa a funcionar
depois.

## Lição das ondas anteriores (aplicar desde o primeiro rascunho)

- `carregarPaginaFechadosServidor`: o original distinguia erro do servidor
  ("Erro ao carregar demandas do arquivo.") de erro de rede ("Erro de conexão."), sem usar a
  mensagem do servidor em nenhum dos dois casos — colapsar num catch único com o texto mais
  informativo ("Erro ao carregar demandas do arquivo."), igual ao padrão já aceito em ondas
  anteriores.
- `executarMergeFechados`: usar `(e.dados && e.dados.error) || 'Erro ao mesclar demandas
  fechadas'` no catch — é a única chamada desta onda que mostra uma mensagem de erro ao
  usuário via `mostrarAlert`, então a regra do padrão se aplica normalmente aqui.

## Global Constraints

- Nenhum `onclick` muda de nome ou assinatura.
- `exibirColunaSetor` (compartilhada entre Demandas abertas e Arquivo) e
  `obterIconePrioridade` (código morto, sem nenhum chamador — não relacionado a esta onda)
  ficam em `script.js`, inalteradas.
- As variáveis de estado compartilhadas (`ticketsAtuais`/`ordemAtual`/`_ordemSalva`/
  `modoMergeAtivo`/`ticketsSelecionados`, do domínio Demandas abertas) ficam em `script.js`;
  só as de Fechados (`ticketsFechadosAtuais`/`ordemFechados`/`_ordemFechadosSalva`/
  `modoMergeFechadosAtivo`/`ticketsFechadosSelecionados`/`paginaAtualFechados`/
  `porPaginaFechados`/`ticketsFechadosFiltradosPorBusca`) vão para `arquivo.js`.
- `frontend/arquivo.js` entra em `index.html` depois de `script.js`/`api.js` e dos demais
  arquivos de onda já carregados; versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- Lista de Arquivo carrega com paginação, filtros (Data/Usuário — espera, não: o filtro de
  Arquivo é por escopo via `filtro-arquivo-escopo`, "meus tickets" e busca textual) e
  ordenação por coluna funcionando.
- Trocar o escopo do filtro (ex. admin: "Encerradas e canceladas" ↔ "Transferidas para outros
  setores"; operador: "Minhas encerradas e canceladas" ↔ "Repassadas por mim") recarrega a
  lista corretamente.
- Reabrir uma demanda fechada (`reabirTicket` → muda status para "reaberto") e remover uma
  demanda fechada (`removerTicketFechado` → exclusão/soft-delete) continuam funcionando.
- **Merge de demandas fechadas**: testar de verdade com pelo menos 2 demandas fechadas reais
  — confirmar se funciona agora (ver descoberta acima) e documentar o resultado no registro
  de validação, já que pode ser uma correção de bug, não só uma migração neutra.
- Busca textual no Arquivo (`configurarBuscaFechados`) e alternância de "por página"
  continuam funcionando.

---

## Contexto para quem for implementar

Em `frontend/script.js`, procure pelo texto exato dos comentários/linhas (os números de
linha abaixo são de 21/10/2026 e podem já ter se deslocado um pouco):

- **Corte 1** — linha 592 isolada: `let escopoArquivoAtual = 'arquivo';` (a função
  `exibirColunaSetor`, nas linhas vizinhas, fica em `script.js`, lendo essa variável de
  `arquivo.js` — funciona porque é todo escopo global de scripts clássicos).
- **Corte 2** — `// Carregar tickets fechados (encerrados e cancelados)` (linha 3070) até o
  fim de `removerTicketFechado` (linha 3409, antes de `// Função para mostrar tickets com
  atualização forçada`, que fica em `script.js` — domínio Demandas). Inclui as variáveis
  `paginacaoFechadosServidor`/`_tokenCargaFechados` e as funções
  `carregarPaginaFechadosServidor`, `carregarTicketsFechados`,
  `aplicarBuscaFechadosComDebounce` (+ variável `timeoutBuscaFechados`),
  `filtrarEMostrarTicketsFechados`, `aplicarFiltroMeusTicketsFechados`,
  `renderizarPaginacaoNumerosFechados`, `irParaPaginaFechados`, `alterarPorPaginaFechados`,
  `ofuscarEmail`, `mostrarTicketsFechadosNaTela`, `prepararFiltroArquivo`,
  `mudarEscopoArquivo`, `mostrarTicketsFechados`, `reabrirTicket`, `removerTicketFechado`.
- **Corte 3** — dentro do bloco `// Variáveis para ordenação e estado` (linhas 3441-3453):
  mover só `let ticketsFechadosAtuais = [];`, `let paginaAtualFechados = 1;`,
  `let porPaginaFechados = ...;`, `let ticketsFechadosFiltradosPorBusca = [];`,
  `const _ordemFechadosSalva = ...;`, `let ordemFechados = ...;` — deixar `ticketsAtuais`,
  `_ordemSalva`, `ordemAtual` em `script.js`.
- **Corte 4** — `// Função para ordenar tabela de fechados` até o fim de
  `ordenarTabelaFechados` (linhas 3527-3542, antes de `// Função para obter ícone da
  prioridade`, que fica em `script.js` — não tem chamador, não é desta onda).
- **Corte 5** — `// Merge para tickets fechados` até o fim de `executarMergeFechados`
  (linhas 3820-3940, antes de `// Busca Avançada`, que é do domínio Demandas abertas).
  Funções: `toggleModoMergeFechados`, `cancelarMergeFechados`,
  `selecionarTicketFechadoParaMerge`, `atualizarClassesMergeFechados`,
  `executarMergeFechados`.
- **Corte 6** — `function configurarBuscaFechados` até o fim de `limparBuscaFechados`
  (linhas 3970-3989, antes de `// Modo Escuro`, domínio core/perfil).
- **Corte 7** — dentro de `// Merge de Tickets` (linhas 4508-4512): mover só
  `let modoMergeFechadosAtivo = false;` e `let ticketsFechadosSelecionados = [];` — deixar
  `modoMergeAtivo`/`ticketsSelecionados` em `script.js`.

Todas essas funções só dependem de coisas que continuam em `script.js`: `Api`, `urlComSetor`,
`sanitizeHTML`, `mostrarAlert`, `mostrarToast`, `mostrarConfirm`, `mostrarLoader`,
`ocultarLoader`, `mostrarSecao`, `mostrarSkeletonTickets`, `executarAlteracaoStatus`,
`executarExclusaoTicket`, `verDetalhes`, `formatarNomeStatus`, `exibirColunaSetor`. Para
rodar localmente: `http://localhost/conecta/frontend/index.html`, XAMPP em
`C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair o domínio Arquivo/Fechadas para `frontend/arquivo.js`

**Files:**
- Create: `frontend/arquivo.js`
- Modify: `frontend/script.js` (remover os 7 trechos listados no contexto acima)
- Modify: `frontend/index.html` (registrar `arquivo.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api`, `urlComSetor`, `sanitizeHTML`, `mostrarAlert`, `mostrarToast`,
  `mostrarConfirm`, `mostrarLoader`, `ocultarLoader`, `mostrarSecao`,
  `mostrarSkeletonTickets`, `executarAlteracaoStatus`, `executarExclusaoTicket`,
  `verDetalhes`, `exibirColunaSetor` — todos inalterados.
- Produces: as mesmas 24 funções globais de antes, mesmos nomes e assinaturas, e as mesmas
  12 variáveis/constantes globais listadas nos Global Constraints.

- [ ] **Step 1: Criar `frontend/arquivo.js`**

- [ ] **Step 2: Remover os 7 trechos de `script.js`**

Confira com `grep -n "^let escopoArquivoAtual\|^function carregarPaginaFechadosServidor\|^function ordenarTabelaFechados\|^function toggleModoMergeFechados\|^function configurarBuscaFechados\|^let modoMergeFechadosAtivo" frontend/script.js`
— depois da remoção, nenhuma linha deve sobrar. Confirme também que `ticketsAtuais`,
`ordemAtual`, `modoMergeAtivo`, `ticketsSelecionados`, `exibirColunaSetor`,
`obterIconePrioridade` continuam em `script.js`.

- [ ] **Step 3: Registrar `arquivo.js` em `index.html` e subir a versão de `script.js`**

- [ ] **Step 4: Testar no navegador (Playwright)**

Login como admin, com demandas reais já encerradas/canceladas no banco (ou criar/encerrar
algumas de teste):

1. Abrir "Arquivo": lista carrega com paginação; colunas ordenáveis funcionam (clicar num
   cabeçalho, confirmar seta de ordenação e reordenação).
2. Trocar o escopo do filtro (ex. "Transferidas para outros setores"): lista recarrega.
3. Busca textual: digitar um termo, lista filtra via servidor.
4. Alternar "por página" (25/50/100): lista recarrega com o novo tamanho.
5. Reabrir uma demanda de teste: volta para "Demandas Abertas" com status "Reaberto".
6. Remover uma demanda de teste (soft-delete): sai da lista do Arquivo.
7. **Merge de 2+ demandas fechadas de teste**: confirmar se funciona (ver descoberta sobre
   `executarMergeFechados` acima) — documentar o resultado real no registro de validação.

- [ ] **Step 5: Commit**

```bash
git add frontend/arquivo.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Arquivo/Fechadas de script.js para arquivo.js, usando api.js (Fase 12, onda 10)"
```

---

### Task 2: Regressão rápida da onda

- [ ] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 140 após a Onda 9; esperar 140 - 24 = 116
node --check script.js
node --check arquivo.js
```

- [ ] **Step 2: Login do zero, console limpo**

- [ ] **Step 3: Rodar a suíte de backend**

```bash
cd ../api
composer test
php tests/matriz-de-acesso.php
```

Esperado: `OK (31 tests, ...)` e `Resultado: 19 OK, 0 falha(s)` — idêntico a antes desta onda.
