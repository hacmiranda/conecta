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

- [ ] **Step 1: Criar `frontend/detalhe-demanda.js`**

- [ ] **Step 2: Remover os 7 trechos de `script.js`**

Confira com
`grep -n "^async function baixarAnexo\|^function devolverAoGestor\|^let secaoOrigem\|^function excluirTicket\|^function voltarParaOrigem\|^let anexosComentarioSelecionados\|^function validarAntesComentario" frontend/script.js`
— depois da remoção, nenhuma linha deve sobrar. Confirme também que `atribuirTicket`,
`executarAcaoDemanda`, `pedirComentarioEExecutar`, `obterSetorIdDoTicket`,
`executarAlteracaoStatus`, `executarExclusaoTicket`, `confirmarAtribuicao`/`executarAtribuicao`
(dead code), `anexosSelecionados`, `MAX_ARQUIVOS`/`MAX_TAMANHO`/`TIPOS_PERMITIDOS` continuam em
`script.js`.

- [ ] **Step 3: Registrar `detalhe-demanda.js` em `index.html` e subir a versão de `script.js`**

- [ ] **Step 4: Testar no navegador (Playwright)**

Login como admin (ou gestor/operador), abrir uma demanda real:

1. Cabeçalho mostra status/prioridade/categoria/responsável corretos; clicar neles abre o
   seletor (funções compartilhadas, já devem funcionar sem mudança).
2. Botões de ação de fluxo aparecem conforme o papel (gestor/admin: Encaminhar/Transferir;
   operador responsável: + Devolver/Repassar) e cada um funciona de ponta a ponta.
3. Adicionar um comentário simples: aparece na lista sem precisar recarregar a página.
4. Adicionar um comentário com anexo: valida tipo/tamanho, aparece na lista com os botões
   Ver/Baixar funcionando (`visualizarAnexo`/`baixarAnexo`).
5. "Enviar e Fechar": adiciona o comentário e encerra a demanda, navega para a lista certa.
6. "Excluir": manda para a lixeira (soft delete).
7. "Voltar": retorna à seção de origem certa (testar vindo de Demandas e de Arquivo).
8. Reabrir uma demanda encerrada pelo botão do próprio detalhe (chama a função já migrada em
   `arquivo.js` na Onda 10 — confirma que a integração entre arquivos continua funcionando).

- [ ] **Step 5: Commit**

```bash
git add frontend/detalhe-demanda.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Detalhe da Demanda de script.js para detalhe-demanda.js, usando api.js (Fase 12, onda 11)"
```

---

### Task 2: Regressão rápida da onda

- [ ] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function \|^async function " script.js   # era 116 após a Onda 10; esperar ~116 - 21 = 95
node --check script.js
node --check detalhe-demanda.js
```

- [ ] **Step 2: Login do zero, console limpo**

- [ ] **Step 3: Rodar a suíte de backend**

```bash
cd ../api
composer test
php tests/matriz-de-acesso.php
```

Esperado: `OK (31 tests, ...)` e `Resultado: 19 OK, 0 falha(s)` — idêntico a antes desta onda.
