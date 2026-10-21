# Onda 7 — extração de Ajuda/Guias — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover o domínio Ajuda/Guias (SLA, Status, Prioridades, Atalhos de Teclado, Tutorial,
Dicas do Sistema) de `frontend/script.js` para `frontend/ajuda.js`, migrando a única chamada
de rede para `Api.*`, sem alterar nenhum comportamento visível.

**Architecture:** Mesma mecânica das Ondas 1-6. Quatro cortes não contíguos num único arquivo
novo. "Fluxos de E-mail" (outro item do mesmo submenu Ajuda) **não** faz parte desta onda —
já é um arquivo separado pré-existente (`frontend/fluxos-email-func.js`), fora do escopo da
divisão de `script.js`.

**Tech Stack:** Vanilla JS. A única chamada de rede (`carregarHorariosDinamicos`) já era
sequencial no original (a 2ª requisição só dispara dentro do `onload` da 1ª) — a versão
convertida usa `await` sequencial real, preservando esse comportamento.

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Descoberta: `mostrarTutorialAjuda` é código morto (pré-existente, não desta onda)

`frontend/script.js` define `function mostrarTutorialAjuda() { reiniciarTutorial(); }`, mas
`frontend/tutorial.js` (carregado **depois** de `script.js` no `index.html`) define sua
**própria** `function mostrarTutorialAjuda() { ... }` com corpo completo — como são scripts
clássicos no mesmo escopo global, a segunda declaração sobrescreve a primeira, e a versão de
`script.js` nunca executa. **Mover como está, sem remover** (regra do projeto: nunca remover
função existente sem perguntar). Para preservar exatamente esse comportamento,
`frontend/ajuda.js` precisa carregar **antes** de `frontend/tutorial.js` no `index.html` —
confirmado no Step 3 abaixo.

## Lição das ondas anteriores (aplicar desde o primeiro rascunho)

O único `catch` (`carregarHorariosDinamicos`) fica silencioso em erro — comportamento
idêntico ao original (sem chamada a `mostrarAlert`/toast; o container simplesmente continua
mostrando "Carregando horários..." para sempre nesse caso raro). Não há outro catch com
alerta ao usuário nesta onda, então a regra `(e.dados && e.dados.error) || fallback` não se
aplica a nenhuma função daqui.

## Global Constraints

- Nenhum `onclick` muda de nome ou assinatura.
- `frontend/ajuda.js` entra em `index.html` depois de `script.js`/`api.js` e dos demais
  arquivos de onda já carregados, **e antes de `tutorial.js`** (ver seção acima); versão
  (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- Guia de SLA: a tabela de prazos por prioridade é estática (texto fixo); só a lista de
  horários de atendimento é dinâmica (`carregarHorariosDinamicos`/`mostrarHorariosDinamicos`).
  Confirmar que ela carrega normalmente e mostra os dias com/sem atendimento.
- Guias de Status, Prioridades e Atalhos de Teclado são 100% estáticos — confirmar que abrem
  sem erro e mostram o conteúdo completo.
- `mostrarDicaAtalhos()` (o toast flutuante "Alt+N: Novo | ..." que aparece nos cantos da
  tela) continua aparecendo e desaparecendo no tempo certo (1s para aparecer, desaparece aos
  5s) — é chamado em algum ponto da inicialização, fora desta onda.
- Modal de Dicas do Sistema: abrir (botão "💡 Dicas" do menu Ajuda ou automaticamente na
  primeira visita), marcar "Não mostrar dicas na inicialização", fechar, recarregar a página
  — a dica não deve aparecer de novo automaticamente.
- `mostrarTutorialAjuda()`: confirmar que clicar em "🎓 Tutorial" no menu Ajuda roda o
  tutorial real (de `tutorial.js`), não a versão morta que foi movida para `ajuda.js` — é
  exatamente o comportamento de antes desta onda, só confirmando que não regredil.

---

## Contexto para quem for implementar

Em `frontend/script.js`, procure pelo texto exato dos comentários (os números de linha abaixo
são de 21/10/2026 e podem já ter se deslocado um pouco):

- `// Seção de Ajuda` (linha 5146) até o fim de `mostrarDicaAtalhos` (linha 5570, antes do
  comentário `// === SISTEMA DE DICAS ===`, que **não** é movido — ele introduz `irParaTickets`/
  `cancelarNovoTicket`, do domínio Demandas, que ficam em `script.js`). 8 funções:
  `mostrarAjuda`, `mostrarSLA`, `carregarHorariosDinamicos`, `mostrarHorariosDinamicos`,
  `mostrarStatusGuia`, `mostrarPrioridadeGuia`, `mostrarAtalhosGuia`, `mostrarDicaAtalhos`.
- `// Mostrar modal de dicas` (linha 5589) até o fim de `verificarPrimeiraVisita` (linha 5623,
  antes de 3 linhas em branco e do comentário `// Validações antes de adicionar comentário`,
  que **não** faz parte deste bloco — é do domínio Demandas/Comentários). 4 funções:
  `mostrarDicas`, `fecharModalDicas`, `toggleDicasIniciais`, `verificarPrimeiraVisita`.
- `// === TUTORIAL INTERATIVO ===` até `function mostrarDicasAjuda() { mostrarDicas(); }`
  (linhas 7061-7067, contíguo — inclui o comentário `// === DICAS DO SISTEMA ===` no meio,
  antes do comentário `// Função para enviar comentário e fechar ticket`, que **não** faz
  parte deste bloco — é do domínio Demandas). 2 funções: `mostrarTutorialAjuda` (código morto,
  ver seção acima), `mostrarDicasAjuda`.

Todas essas funções só dependem de coisas que continuam em `script.js`: `Api`, `mostrarSecao`,
`window.APP_CONFIG`. Para rodar localmente: `http://localhost/conecta/frontend/index.html`,
XAMPP em `C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair o domínio Ajuda/Guias para `frontend/ajuda.js`

**Files:**
- Create: `frontend/ajuda.js`
- Modify: `frontend/script.js` (remover os 3 trechos listados no contexto acima)
- Modify: `frontend/index.html` (registrar `ajuda.js` antes de `tutorial.js`, subir a versão
  de `script.js`)

**Interfaces:**
- Consumes: `Api`, `mostrarSecao` — inalterados.
- Produces: as mesmas 14 funções globais de antes, mesmos nomes e assinaturas.

- [x] **Step 1: Criar `frontend/ajuda.js`**

- [x] **Step 2: Remover os 3 trechos de `script.js`**

Confira com `grep -n "Seção de Ajuda\|^function mostrarDicas\b\|^function mostrarTutorialAjuda\|^function enviarEFecharTicket" frontend/script.js`
— depois da remoção, nenhuma das funções movidas deve sobrar.

- [x] **Step 3: Registrar `ajuda.js` em `index.html` ANTES de `tutorial.js`, subir a versão de `script.js`**

- [x] **Step 4: Testar no navegador (Playwright)**

1. Menu Ajuda → "⏰ SLA": tabela de prazos aparece; lista de horários de atendimento carrega
   (dias da semana com/sem atendimento).
2. "📊 Status das Demandas", "🔥 Prioridades", "⌨️ Atalhos de Teclado": cada um abre com o
   conteúdo completo, sem erro no console.
3. "🎓 Tutorial": inicia o tutorial real (de `tutorial.js`), não uma versão quebrada.
4. "💡 Dicas": modal abre; marcar "Não mostrar dicas na inicialização"; fechar; recarregar a
   página — modal não aparece automaticamente.
5. Observar o canto da tela por ~5s após o carregamento inicial: o toast "Alt+N: Novo | ..."
   aparece e desaparece.

- [x] **Step 5: Commit**

```bash
git add frontend/ajuda.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Ajuda/Guias de script.js para ajuda.js, usando api.js (Fase 12, onda 7)"
```

---

### Task 2: Regressão rápida da onda

- [x] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 185 após a Onda 6; esperar 185 - 14 = 171
node --check script.js
node --check ajuda.js
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

- Modal de Dicas apareceu automaticamente na primeira visita (`verificarPrimeiraVisita`);
  marcado "Não mostrar dicas na inicialização", fechado, recarregada a página — não
  reapareceu (persistência via `localStorage` confirmada).
- Toast de atalhos ("Alt+N: Novo | ...") visível no rodapé (`mostrarDicaAtalhos`).
- Menu Ajuda → "⏰ SLA": guia completo renderizado, incluindo a lista dinâmica de horários de
  atendimento (Segunda a Sexta 08:00-17:00, Domingo/Sábado "Sem atendimento") — confirma que
  `carregarHorariosDinamicos()` (convertida para `Api.get` sequencial) funcionou ponta a
  ponta contra o servidor real.
- "📊 Status das Demandas", "🔥 Prioridades", "⌨️ Atalhos de Teclado": cada um abriu sem erro
  no console.
- "🎓 Tutorial": confirmado que executa a versão real de `tutorial.js` ("Tutorial - Passo: 0
  Total: 14" no console), não a versão morta movida para `ajuda.js` — comportamento idêntico
  a antes da onda, já que `ajuda.js` continua carregando antes de `tutorial.js`.
- "💡 Dicas" (chamado via `mostrarAjuda(); mostrarDicasAjuda()`, testado com
  `browser_evaluate` por um problema de timing do dropdown na automação, não um bug do
  sistema): abriu o modal de dicas corretamente.
- Conta de teste `zz Admin Onda7` removida do banco ao final; confirmado
  `SELECT COUNT(*) FROM usuarios WHERE nome LIKE 'zz%'` = 0.
- `grep -c "^function " script.js` → 171 (185 - 14, como esperado); `node --check` limpo em
  `script.js` e `ajuda.js`; `composer test` → OK (31 testes, 41 assertions);
  `php tests/matriz-de-acesso.php` → 19 OK, 0 falhas.

## Revisão final (subagente opus)

0 Crítico, 0 Importante, 3 Menor (1 corrigido, 2 opcionais deixados como estão). A cópia foi
confirmada literal caractere por caractere (exceto a conversão de `carregarHorariosDinamicos`);
a ordem de carregamento em `index.html` (ajuda.js antes de tutorial.js) preserva o
comportamento do código morto; XSS reconfirmado sem novidade.

- **Corrigido (commit `9998b33`):** o comentário `// === SISTEMA DE DICAS ===` tinha ficado
  órfão em `script.js`, acima de `irParaTickets`/`cancelarNovoTicket` (domínio Demandas) —
  o Sistema de Dicas de verdade saiu para `ajuda.js`. Só cosmético, removido.
- **Menor (não corrigido, opcional):** o `catch` silencioso de `carregarHorariosDinamicos`
  engole também exceções de dentro de `mostrarHorariosDinamicos` (ex.: resposta não-JSON),
  sem deixar rastro no console — comportamento visível ao usuário é o mesmo de antes
  ("Carregando horários..." para sempre nesse caso raro), só se perde a pista de depuração.
