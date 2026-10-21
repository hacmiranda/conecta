# Onda 6 — extração de Configurações (Alterar Senha + Personalização) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover os domínios "Alterar Senha" (menu do usuário) e "Personalização" (menu
Configurações, inclui o código morto "Modo Escuro (Configurações)") de `frontend/script.js`
para `frontend/configuracoes.js`, migrando a chamada JSON para `Api.*`, sem alterar nenhum
comportamento visível.

**Architecture:** Mesma mecânica das Ondas 1-5. Três cortes não contíguos num único arquivo
novo — os dois domínios são pequenos e ambos são telas de "configuração" (uma da conta do
usuário, outra do sistema), por isso vão para o mesmo arquivo.

**Tech Stack:** Vanilla JS. **Excepcional nesta onda:** `uploadLogoHeader`/`uploadLogoLogin`
fazem upload de arquivo (`multipart/form-data` via `FormData`) e **continuam em
`XMLHttpRequest` puro** — `Api.js` (`frontend/api.js`) só serializa JSON
(`JSON.stringify(corpo)` + `Content-Type: application/json`), não suporta `FormData`. Isso
não é uma lacuna desta onda: o mesmo padrão já existe hoje em `avatar.js` (arquivo pré-existente,
fora de `script.js`) para o upload de avatar. Expandir `Api.js` para suportar upload está fora
de escopo — não é pedido pela spec e nenhum outro arquivo já dividido precisou disso.

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Lição das ondas anteriores (aplicar desde o primeiro rascunho)

O único `catch` com chamada JSON (`alterarSenha`) usa
`(e.dados && e.dados.error) || 'texto padrão'`.

## Global Constraints

- Nenhum `onclick`/`oninput` muda de nome ou assinatura (`verificarComplexidade()` é chamado
  via `oninput` num campo estático do `index.html`, não gerado dinamicamente — confirmar que
  continua funcionando vindo de um arquivo carregado depois).
- `uploadLogoHeader`/`uploadLogoLogin` continuam em `XMLHttpRequest` puro (ver Tech Stack).
- `mostrarModoEscuro()` é código morto (nenhum botão no `index.html` atual a chama; o modo
  escuro real usa `toggleModoEscuro()` direto, de outro ponto do `script.js`, fora desta
  onda) — **mover como está, sem remover**, por não ser isso que a tarefa pede e pela regra
  do projeto de nunca remover função existente sem perguntar antes.
- `uploadLogoLogin()` também parece não ter um botão correspondente na tela de Personalização
  renderizada hoje (só tem o upload do logo do header) — mover como está, mesma razão acima.
- `frontend/configuracoes.js` entra em `index.html` depois de `script.js`, `api.js` e dos
  demais arquivos de onda já carregados; versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- `verificarComplexidade()`/`alterarSenha()` precisam continuar funcionando no modal/formulário
  estático de "Alterar Senha" do menu do usuário (não são regeneradas via `innerHTML`).
- Trocar a senha quando `usuario.trocar_senha` está `true` (primeiro acesso ou senha redefinida
  por admin/gestor): o fluxo de recarregar a página inteira (`window.location.reload()`) via
  callback do `mostrarAlert` precisa continuar.
- Trocar a senha em uso normal (sem `trocar_senha` pendente): toast de sucesso, formulário
  limpo, indicadores de força voltam a `❌`, botão desabilitado de novo.
- Senhas diferentes: `mostrarAlert` "As senhas não coincidem." sem chamar o servidor.
- Personalização como admin: upload do logo do header funciona (ou ao menos chega ao
  servidor sem erro de sintaxe — não é obrigatório ter um PNG de teste à mão, mas confirmar
  que clicar "Upload Logo" sem arquivo mostra o alerta correto "Selecione um arquivo PNG").
- Personalização como gestor/operador: tela somente leitura (campo e botão desabilitados,
  aviso correto).
- Confirmar que `mostrarModoEscuro`/`uploadLogoLogin` (código morto) não geram erro algum ao
  carregar o arquivo (não precisam de teste funcional, já que nada os chama hoje).

---

## Contexto para quem for implementar

Em `frontend/script.js`, procure pelo texto exato dos comentários (os números de linha abaixo
são de 21/10/2026 e podem já ter se deslocado um pouco):

- `// Verificar complexidade da senha` (linha 3977) até o fim de `alterarSenha` (linha 4057,
  antes do comentário `// Fechar modal ao clicar fora`, que **não** faz parte deste bloco —
  é do sistema de modais, domínio core). 2 funções: `verificarComplexidade`, `alterarSenha`.
- `// === PERSONALIZAÇÃO DO SISTEMA ===` (linha 7143) até o fim de `uploadLogoLogin` (linha
  7244, antes do comentário `// === TUTORIAL INTERATIVO ===`, que **não** faz parte deste
  bloco — é do domínio Ajuda, de uma onda futura). 3 funções: `mostrarPersonalizacao`,
  `uploadLogoHeader`, `uploadLogoLogin`.
- `// === MODO ESCURO (CONFIGURAÇÕES) ===` (linha 7254) até o fim de `mostrarModoEscuro`
  (linha 7282, antes do comentário `// Função para enviar comentário e fechar ticket`, que
  **não** faz parte deste bloco — é do domínio Demandas). 1 função: `mostrarModoEscuro`.

Todas essas funções só dependem de coisas que continuam em `script.js`: `Api`, `mostrarAlert`,
`mostrarToast`, `mostrarLoader`, `ocultarLoader`, `window.APP_CONFIG`, `getAuthHeaders`/
`setSecurityHeaders` (para os dois uploads, que mantêm XHR). Para rodar localmente:
`http://localhost/conecta/frontend/index.html`, XAMPP em `C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair os domínios Alterar Senha e Personalização para `frontend/configuracoes.js`

**Files:**
- Create: `frontend/configuracoes.js`
- Modify: `frontend/script.js` (remover os 3 trechos listados no contexto acima)
- Modify: `frontend/index.html` (registrar `configuracoes.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api`, `mostrarAlert`, `mostrarToast`, `mostrarLoader`, `ocultarLoader`,
  `setSecurityHeaders`, `window.APP_CONFIG` — todos inalterados.
- Produces: as mesmas 6 funções globais de antes, mesmos nomes e assinaturas:
  `verificarComplexidade()`, `alterarSenha(event)`, `mostrarPersonalizacao()`,
  `uploadLogoHeader()`, `uploadLogoLogin()`, `mostrarModoEscuro()`.

- [x] **Step 1: Criar `frontend/configuracoes.js`**

- [x] **Step 2: Remover os 3 trechos de `script.js`**

Confira com `grep -n "Verificar complexidade da senha\|^function alterarSenha\b\|PERSONALIZAÇÃO DO SISTEMA\|^function mostrarModoEscuro\b" frontend/script.js`
— depois da remoção, nenhuma linha deve sobrar.

- [x] **Step 3: Registrar `configuracoes.js` em `index.html` e subir a versão de `script.js`**

- [x] **Step 4: Testar no navegador (Playwright)**

Login como admin:

1. Menu do usuário → "Alterar Senha": digitar uma senha fraca nos indicadores (❌ em todos);
   completar critérios um a um até todos ficarem ✅ e o botão habilitar.
2. Senha nova ≠ confirmação: `mostrarAlert` "As senhas não coincidem." sem chamar o servidor.
3. Alterar a senha de verdade (senha atual correta + nova válida): toast de sucesso,
   formulário limpo, indicadores voltam a ❌; **depois trocar a senha de volta para a
   original**, para não deixar a conta de teste com senha diferente.
4. Configurações → Personalização: tela carrega com o aviso correto para admin (campo e
   botão habilitados); clicar "Upload Logo" sem selecionar arquivo → `mostrarAlert`
   "Selecione um arquivo PNG" sem chamar o servidor.

Login como gestor: Personalização mostra o aviso de somente leitura, campo e botão
desabilitados.

- [x] **Step 5: Commit**

```bash
git add frontend/configuracoes.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Alterar Senha e Personalizacao de script.js para configuracoes.js, usando api.js (Fase 12, onda 6)"
```

---

### Task 2: Regressão rápida da onda

- [x] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 191 após a Onda 5; esperar 191 - 6 = 185
node --check script.js
node --check configuracoes.js
```

- [x] **Step 2: Login do zero, console limpo**

- [x] **Step 3: Rodar a suíte de backend**

```bash
cd ../api
composer test
php tests/matriz-de-acesso.php
```

Esperado: `OK (31 tests, ...)` e `Resultado: 19 OK, 0 falha(s)` — idêntico a antes desta onda.

## Registro da validação

Executado de verdade no navegador (Playwright):

- **admin**: menu do usuário → "Alterar Senha" — indicadores de força reagem ao digitar
  (todos ❌ → todos ✅ conforme critérios são atendidos, botão habilita); senha nova ≠
  confirmação bloqueia com "As senhas não coincidem." sem chamar o servidor; alterar a senha
  de verdade (via `Api.post`) — sucesso, formulário resetado, indicadores voltam a ❌, botão
  desabilita; senha trocada de volta para a original ao final, mesmo fluxo.
- **admin**: Configurações → Personalização — tela editável carrega (aviso de "Importante",
  campo e botão habilitados); "Upload Logo" sem arquivo selecionado bloqueia no frontend
  ("Selecione um arquivo PNG") sem chamar o servidor.
- **gestor** (conta `zz Gestor Onda6` temporária, sem `trocar_senha` pendente, setor Cadastro):
  Personalização mostra o aviso de somente leitura, campo e botão desabilitados.
- Único erro de console visto (`email-alert.php` → 403 para o gestor) é pré-existente
  (monitoramento de falhas de e-mail restrito a admin), sem relação com esta onda.
- Contas de teste (`zz Admin Onda6`, `zz Gestor Onda6`) removidas do banco ao final;
  confirmado `SELECT COUNT(*) FROM usuarios WHERE nome LIKE 'zz%'` = 0.
- `grep -c "^function " script.js` → 185 (191 - 6, como esperado); `node --check` limpo em
  `script.js` e `configuracoes.js`; login do zero → console sem erro novo; `composer test` →
  OK (31 testes, 41 assertions); `php tests/matriz-de-acesso.php` → 19 OK, 0 falhas.

## Revisão final (subagente opus)

0 Crítico, 0 Importante, 2 Menor — nenhum corrigido, ambos aceitáveis. A conversão de
`alterarSenha` para `Api.post` foi confirmada fiel (corpo, leitura de `token`/`csrf_token`,
os dois fluxos pós-sucesso, cabeçalhos equivalentes via `getAuthHeaders()`); os uploads
continuam em XHR puro como decidido; as três funções sem rede são cópias literais; os três
cortes em `script.js` ficaram limpos; nenhum dado do servidor entra em `innerHTML` sem
escape nesta onda (a tela de Personalização só interpola booleanos).

- **Menor (não corrigido, aceitável):** em erro de rede (sem resposta JSON), a mensagem
  mudou de "Erro de conexão" (XHR `onerror`) para "Erro ao alterar senha" (fallback padrão
  do `catch`) — mudança de texto pequena e consistente com o padrão já adotado nas ondas
  anteriores, não uma regressão funcional.
- **Menor (pré-existente, não corrigido):** `JSON.parse(xhr.responseText)` sem `try` em
  `uploadLogoHeader`/`uploadLogoLogin` seria frágil diante de uma resposta não-JSON — copiado
  de propósito como estava, fora de escopo desta onda (os uploads continuam em XHR).
