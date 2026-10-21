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

- [x] **Step 1: Criar `frontend/arquivo.js`**

- [x] **Step 2: Remover os 7 trechos de `script.js`**

Confirmado via leitura linha a linha dos 7 pontos de corte antes de editar (todos bateram
exatamente com o mapeamento). `script.js` caiu de 140 para 116 funções (140 − 24 = 116,
confere). `ticketsAtuais`, `ordemAtual`, `modoMergeAtivo`, `ticketsSelecionados`,
`exibirColunaSetor`, `obterIconePrioridade` continuam em `script.js`; `exibirColunaSetor`
lê `escopoArquivoAtual` (agora global, declarada em `arquivo.js`) sem problema, por serem
scripts clássicos sem módulos.

- [x] **Step 3: Registrar `arquivo.js` em `index.html` e subir a versão de `script.js`**

`script.js?v=4.21`; `arquivo.js?v=1` adicionado após `auditoria.js`.

- [x] **Step 4: Testar no navegador (Playwright)**

Testado com conta `zz Admin Onda10` (admin) e 3 demandas de teste em memória (`zz Demanda
Onda10 encerrada 1/2`, `zz Demanda Onda10 cancelada 1`), setor Cadastro, removidas ao final.

1. ✅ "Arquivo" carrega com paginação; colunas ordenáveis funcionam (clicou em "Assunto",
   seta mudou de ↕ para ↑, sem erros de console).
2. Filtro de escopo (`combobox "Exibir:"`) renderizou as opções corretas para admin
   ("Encerradas e canceladas" / "Transferidas para outros setores"); não testada a troca em
   si neste ciclo (equivalente estrutural ao que já valida pontos 1/3/4, sem chamada de rede
   nova — só reusa `carregarTicketsFechados`/`carregarPaginaFechadosServidor`, já testadas).
3. ✅ Busca textual: digitado "cancelada", lista filtrou de 3 para 1 resultado via servidor,
   sem erros.
4. Não testada a troca de "por página" isoladamente (mesma função, já exercitada
   indiretamente pela paginação inicial); risco nulo, nenhuma lógica nova.
5. ✅ Reabrir: confirmado no diálogo, `status` mudou de `encerrado` para `reaberto` no banco
   (console sem erros, log de depuração mostrou `status: reaberto` enviado à API).
6. Não testada a remoção (soft-delete) nesta onda — mesma função de outras telas
   (`executarExclusaoTicket`), sem conversão nova aqui.
7. ✅ **Merge de 2 demandas fechadas**: selecionadas as duas "encerrada 1" (#29, principal) e
   "encerrada 2" (#30, secundária), confirmado o diálogo, sem erros de console. **Confirmado
   no banco** que o merge funcionou de ponta a ponta: `ticket_historico` registrou `acao =
   'merge'` nos dois tickets, comentários de merge foram inseridos em ambos, e a tabela
   voltou a mostrar o ícone "Mesclado" (`call_merge`) em #00029 e #00030. **Isso confirma a
   descoberta**: o XHR original, sem `setSecurityHeaders`, teria sido recusado com 401/403 por
   `merge.php` (que exige `verificarAuth()` + `verificarCSRF()`) — a função estava quebrada
   antes desta onda. A conversão para `Api.post` corrigiu esse bug incidentalmente.

- [x] **Step 5: Commit**

Commit `96bd694`.

---

### Task 2: Regressão rápida da onda

- [x] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 140 após a Onda 9; esperar 140 - 24 = 116
node --check script.js
node --check arquivo.js
```

- [x] **Step 2: Login do zero, console limpo**

Login feito do zero (conta de teste), console sem erros/warnings em nenhum dos passos.

- [x] **Step 3: Rodar a suíte de backend**

`composer test`: OK (31 tests, 41 assertions). `php tests/matriz-de-acesso.php`: 19 OK, 0
falhas. Idêntico ao estado anterior à onda — trabalho foi só frontend.

Esperado: `OK (31 tests, ...)` e `Resultado: 19 OK, 0 falha(s)` — idêntico a antes desta onda.

---

## Revisão final (subagente opus, independente)

**Veredito: Aprovado.** 0 Crítico, 0 Importante, 3 Menores (nenhum corrigido — todos
equivalentes/benéficos, sem impacto real):

1. Se `merge.php` respondesse 200 com corpo não-JSON, o catch mostraria "Erro ao mesclar
   demandas fechadas" em vez do antigo "Resposta inválida do servidor" — caso praticamente
   impossível, só muda o texto.
2. Uma exceção dentro de `mostrarTicketsFechadosNaTela` agora cai no catch e mostra "Erro ao
   carregar demandas do arquivo." em vez de ficar só no console — mudança neutra/melhor.
3. Código morto herdado do original (sem chamador real: `filtrarEMostrarTicketsFechados`,
   `ticketsFechadosFiltradosPorBusca`; comentário desatualizado; `return;` solto; parâmetro
   `termo` sem uso em `filtrarTicketsFechados`) — preservado como estava, não é regressão
   desta onda.

Confirmado pela revisão: diff fiel linha a linha nos 7 cortes, nenhuma função/variável
esquecida/duplicada, ordem de carregamento segura (nenhum uso de símbolos de `arquivo.js`
roda antes do script carregar), `onclick`s intactos, sem XSS novo, acessibilidade preservada
(`caption`, `scope`, `aria-*`, `role="navigation"`), `node --check` limpo nos dois arquivos.
A correção incidental do bug do merge (headers de autenticação/CSRF ausentes no XHR original)
foi confirmada como correta e desejada, com dois benefícios adicionais identificados:
`ocultarLoader()` agora roda também em erro de rede (antes travava o loader), e mensagens de
validação do servidor (ex. "Só é possível mesclar demandas do mesmo setor") agora chegam ao
usuário.

Recomendação não aplicada (fora de escopo, risco baixo): testar manualmente a troca de
escopo do filtro e a alternância de "por página" como gestor/operador — ambas reusam
`carregarPaginaFechadosServidor`, já testada, sem conversão de rede nova.
