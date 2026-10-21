# Onda 4 — extração de Ativação por Setor + E-mail por Setor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover os domínios "Ativação por Setor" (status/prioridades ativos por setor) e
"E-mail por Setor" (caixa IMAP/SMTP do setor + tela "Configurar Email") de
`frontend/script.js` para `frontend/ativacao-setor.js`, migrando para `Api.*`, sem alterar
nenhum comportamento visível.

**Architecture:** Mesma mecânica das Ondas 1-3 (já aprovada e em produção). Os dois domínios
vão para **um único arquivo**, não dois — eles compartilham as variáveis de módulo
`setorConfigAtual`/`abaConfigSetorAtiva` e se chamam diretamente (`carregarAbaConfigSetor('emails')`
chama `carregarEmailsSetor()`; `mostrarConfigEmail()` também chama `carregarEmailsSetor()`
direto). Separar em dois arquivos obrigaria declarar essas variáveis em escopo global de
qualquer forma, sem ganho real.

**Tech Stack:** Vanilla JS (`async/await`, `fetch` via `Api`). Validação manual no navegador
via Playwright.

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Lição das ondas anteriores (aplicar desde o primeiro rascunho)

Todo `catch` que mostra erro ao usuário usa `(e.dados && e.dados.error) || 'texto padrão'` —
nunca `e.message` sozinho.

## Global Constraints

- Nenhum `onclick="..."` muda de nome ou assinatura.
- Nenhuma função muda de comportamento visível, **exceto** uma simplificação já aceita nas
  ondas anteriores: os dois ramos de erro do XHR (`onload` com status != 200, e `onerror` de
  rede) colapsam num único `catch`, às vezes com uma mensagem um pouco menos específica do
  que a dupla original tinha (ex.: "Erro ao carregar configuração." em vez de duas mensagens
  distintas) — isso é aceitável, veja o Review Focus.
- `setor-emails.php?acao=testar` e `testar-email.php` sempre respondem 200, mesmo quando o
  teste de conexão falhou (o front lê `resultado.sucesso`/`resultado.imap.sucesso` do corpo,
  não do status HTTP) — então `Api.post` não entra no `catch` nesse caso, só em erro de rede
  ou 400 de validação de campos. Conferido nos controllers (`api/controllers/setor-emails.php`,
  `api/controllers/testar-email.php`) antes de escrever o código abaixo.
- `setorConfigAtual`, `abaConfigSetorAtiva` (variáveis `let` de módulo) e
  `LIMITE_EMAILS_POR_SETOR` (constante) continuam globais, no novo arquivo.
- `frontend/ativacao-setor.js` entra em `index.html` depois de `script.js`, `api.js` e dos
  demais arquivos de onda já carregados; versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- `carregarAbaConfigSetor('emails')` ainda existe no código mas não há mais botão de aba
  "E-mails" na tela "Ativação por Setor" (confirmado lendo `renderizarPainelConfigSetor` —
  só tem abas Status/Prioridades); a tela "Configurar Email" (`mostrarConfigEmail`) é quem
  chama `carregarEmailsSetor()` direto. Não "arrumar" isso nesta onda — mover como está.
- Admin sem setor escolhido no cabeçalho, na tela "Configurar Email": mostra só a seção do
  remetente global, com o aviso para escolher um setor.
- Testar e-mail do setor com campos incompletos: bloqueado no frontend ("Preencha todos os
  campos antes de testar.") sem chamar o servidor — igual a antes.
- Cadastrar a 2ª caixa de e-mail no mesmo setor: o botão "Cadastrar" desaparece depois da 1ª
  (`LIMITE_EMAILS_POR_SETOR = 1`), mas se o servidor recusar por outro motivo a mensagem dele
  precisa aparecer, não um texto genérico.
- Alternar "Validar certificado" e o toggle "Ativo" de uma caixa: toast correto em cada caso
  (incluindo o toast de aviso quando desativa a validação do certificado).
- Reordenar (▲/▼) um status/prioridade ativo no setor: a lista recarrega na posição nova.
- Gestor (sem ser admin): a tela "Ativação por Setor" não mostra o seletor de setor (fica
  travado no próprio) e a tela "Configurar Email" segue o setor do cabeçalho do próprio
  gestor (não deveria ter seletor de setor no cabeçalho para um gestor — confirmar que
  `obterSetorContexto()` já trata isso em `cabecalho-setor.js`, sem mudança nesta onda).

---

## Contexto para quem for implementar

Em `frontend/script.js`, procure pelo texto exato dos comentários (os números de linha
abaixo são de 21/10/2026 e podem já ter se deslocado um pouco):

- `// ==== Fase 3: Ativação por Setor (categorias, status, prioridades) ====` (linha 3001)
  até o fim de `moverItemConfigSetor` (linha 3526, imediatamente antes do comentário
  `// Função para limpar formulário de ticket`, que **não** faz parte deste bloco — é do
  domínio Demandas). 17 funções + 3 variáveis/constante de módulo: `setorConfigAtual`,
  `abaConfigSetorAtiva`, `mostrarConfigSetor`, `renderizarPainelConfigSetor`,
  `carregarAbaConfigSetor`, `LIMITE_EMAILS_POR_SETOR`, `carregarEmailsSetor`,
  `renderizarEmailsSetor`, `carregarLogEmailsProcessadosSetor`, `mostrarFormularioEmailSetor`,
  `coletarDadosFormEmailSetor`, `testarEmailSetor`, `salvarEmailSetor`,
  `alternarAtivoEmailSetor`, `alternarCertificadoEmailSetor`, `excluirEmailSetor`,
  `formatarNomeItemConfigSetor`, `renderizarListaConfigSetor`, `alternarAtivoConfigSetor`,
  `moverItemConfigSetor`.
- `// === CONFIGURAÇÃO DE EMAIL ===` (linha 8173) até o fim de `testarConexaoEmail`
  (linha 8288, antes de várias linhas em branco e do comentário
  `// Função para enviar comentário e fechar ticket`, que **não** faz parte deste bloco).
  3 funções: `mostrarConfigEmail`, `carregarConfigEmail`, `testarConexaoEmail`.

Todas essas funções só dependem de coisas que continuam em `script.js`: `Api`, `sanitizeHTML`,
`mostrarToast`, `mostrarAlert`, `mostrarConfirm`, `mostrarLoader`, `ocultarLoader`,
`formatarNomeStatus`, `formatarNomePrioridade`, `window.APP_CONFIG`, `window.CabecalhoSetor`.
Para rodar localmente: `http://localhost/conecta/frontend/index.html`, XAMPP em
`C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair os dois domínios para `frontend/ativacao-setor.js`

**Files:**
- Create: `frontend/ativacao-setor.js`
- Modify: `frontend/script.js` (remover os 2 trechos listados no contexto acima)
- Modify: `frontend/index.html` (registrar `ativacao-setor.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api`, `sanitizeHTML`, `mostrarToast`, `mostrarAlert`, `mostrarConfirm`,
  `mostrarLoader`, `ocultarLoader`, `formatarNomeStatus`, `formatarNomePrioridade`,
  `window.CabecalhoSetor` — todos inalterados.
- Produces: as mesmas 20 funções globais de antes, mesmos nomes e assinaturas, e as mesmas
  variáveis/constante globais `setorConfigAtual`, `abaConfigSetorAtiva`,
  `LIMITE_EMAILS_POR_SETOR`.

- [x] **Step 1: Criar `frontend/ativacao-setor.js`** (código convertido para `Api.*`, com o
  padrão de erro `(e.dados && e.dados.error) || 'texto padrão'` em todo catch que mostra
  alerta ao usuário)

- [x] **Step 2: Remover os 2 trechos de `script.js`**

Remova:
1. Do comentário `// ==== Fase 3: Ativação por Setor` até o fim de `moverItemConfigSetor`
   (pare antes do comentário `// Função para limpar formulário de ticket`).
2. Do comentário `// === CONFIGURAÇÃO DE EMAIL ===` até o fim de `testarConexaoEmail`
   (pare antes do comentário `// Função para enviar comentário e fechar ticket`).

Confira com `grep -n "Fase 3: Ativação por Setor\|CONFIGURAÇÃO DE EMAIL\|^function mostrarConfigSetor\|^function mostrarConfigEmail\|^let setorConfigAtual" frontend/script.js`
— depois da remoção, nenhuma linha deve sobrar.

- [x] **Step 3: Registrar `ativacao-setor.js` em `index.html` e subir a versão de `script.js`**

- [x] **Step 4: Testar no navegador (Playwright)**

Login como **admin**, setor Cadastro escolhido no cabeçalho:

1. "Ativação por Setor": abas Status e Prioridades carregam sem erro; desativar e reativar um
   status/prioridade (toast de sucesso); reordenar um item com ▲/▼ (lista recarrega na nova
   ordem).
2. "Configurar Email": seção do remetente global aparece (dados do `.env`, só leitura) e a
   seção do e-mail do setor Cadastro carrega (se já tiver uma caixa cadastrada) ou mostra o
   botão de cadastrar.
3. Sem setor escolhido ("Todos os setores"): a seção de e-mail do setor mostra o aviso para
   escolher um setor; a seção do remetente global continua aparecendo.
4. Testar o botão "Testar Conexão" do remetente global (pode falhar de verdade, sem SMTP
   configurado localmente — o importante é que a mensagem de sucesso/falha apareça, não que
   dê 500).

Login como **gestor**: "Ativação por Setor" não mostra seletor de setor (fica no próprio);
"Configurar Email" mostra a seção do setor do gestor automaticamente.

Não é necessário cadastrar uma caixa de e-mail de teste de verdade (exigiria credenciais IMAP
reais) — validar a UI (abrir formulário, cancelar) é suficiente; qualquer dado de teste
inserido no banco (`setor_emails`, `setor_status`, `setor_prioridades`) deve ser restaurado
ao estado anterior ao final.

- [x] **Step 5: Commit**

```bash
git add frontend/ativacao-setor.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Ativacao por Setor e E-mail por Setor de script.js para ativacao-setor.js, usando api.js (Fase 12, onda 4)"
```

---

### Task 2: Regressão rápida da onda

- [x] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 218 após a Onda 3; esperar 218 - 20 = 198
node --check script.js
node --check ativacao-setor.js
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

Executado de verdade no navegador (Playwright), setor Cadastro escolhido no cabeçalho:

- **admin**: "Ativação por Setor" → aba Status carrega sem erro; desativar o status
  "Reaberto" (toast implícito, checkbox desmarca) e reativar (checkbox volta a marcar);
  mover "Em atendimento" para o topo com ▲ (lista recarrega na nova ordem) e devolver com ▼
  (estado restaurado); aba Prioridades carrega sem erro.
- **admin**: "Configurar Email" → seção do e-mail do setor Cadastro (vazia, com botão de
  cadastrar) e seção do remetente global (dados reais do `.env`, somente leitura) aparecem
  juntas; abrir o formulário de nova caixa; "Testar Conexão" dentro do formulário com campos
  vazios bloqueia no frontend ("Preencha todos os campos antes de testar.") sem chamar o
  servidor; cancelar o formulário sem salvar; "Testar Conexão" do remetente global chama o
  servidor de verdade (`Api.post`) e mostra o resultado real (falha de autenticação esperada,
  sem SMTP acessível localmente) com o loader aparecendo e desaparecendo corretamente.
- **admin**, sem setor escolhido ("Todos os setores"): "Configurar Email" mostra só o aviso
  para escolher um setor na seção do e-mail do setor; a seção do remetente global continua
  aparecendo.
- **gestor** (conta `zz Gestor Onda4` temporária, sem `trocar_senha` pendente): "Ativação por
  Setor" não mostra seletor de setor (travado no próprio, Cadastro); menu de Configurações do
  gestor não mostra "Configurar Email" (restrito a admin, regra pré-existente, não desta
  onda) — confirmado pelo próprio menu renderizado, não testado via tentativa de acesso
  direto.
- Erros de console vistos durante os testes (`email-alert.php` → 403 para o gestor) são
  pré-existentes (monitoramento de falhas de e-mail restrito a admin) e não têm relação com
  esta onda.
- Conta de teste `zz Gestor Onda4` removida do banco ao final (não foi possível fazer logout
  pela UI por causa de z-index do menu — removida direto via SQL); confirmado
  `SELECT COUNT(*) FROM usuarios WHERE nome LIKE 'zz%'` = 0 e nenhuma caixa de e-mail de teste
  em `setor_emails`.
- `grep -c "^function " script.js` → 198 (218 - 20, como esperado); `node --check` limpo em
  `script.js` e `ativacao-setor.js`; `composer test` → OK (31 testes, 41 assertions);
  `php tests/matriz-de-acesso.php` → 19 OK, 0 falhas.
