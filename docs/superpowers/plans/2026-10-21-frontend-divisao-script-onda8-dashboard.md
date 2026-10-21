# Onda 8 — extração de Dashboard — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover o domínio Dashboard (painel principal de KPIs/gráficos + modal de informações
de cada gráfico) de `frontend/script.js` para `frontend/dashboard.js`, migrando a única
chamada de rede para `Api.*`, sem alterar nenhum comportamento visível.

**Architecture:** Mesma mecânica das Ondas 1-7. Dois cortes não contíguos num único arquivo
novo. A criação dos gráficos (Chart.js) já é um arquivo separado pré-existente
(`frontend/graficos-dashboard.js`), fora do escopo desta divisão — `mostrarDashboardNaTela`
só chama `criarGraficoX(dados)` depois de montar o HTML, sem saber como cada gráfico é
desenhado.

**Tech Stack:** Vanilla JS. Uma única chamada de rede.

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Descoberta: `dashboard-update.js` é um arquivo órfão (não afeta esta onda)

`frontend/dashboard-update.js` redefine `mostrarDashboardNaTela(dados)`, mas **não é
referenciado em nenhum `<script src="...">` do `index.html`** — nunca é carregado pelo
navegador. A versão de `mostrarDashboardNaTela` em `script.js` (movida nesta onda) é a única
realmente ativa. Não mexer em `dashboard-update.js` — está fora de `script.js`, fora do
escopo desta divisão, e como nunca carrega, não há risco de conflito de ordem.

## Global Constraints

- Nenhum `onclick` muda de nome ou assinatura (os ícones "info" de cada card chamam
  `mostrarInfoGrafico('tipo')`).
- `frontend/dashboard.js` entra em `index.html` depois de `script.js`/`api.js` e dos demais
  arquivos de onda já carregados; versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.
- Erro ao carregar o dashboard: `(e.dados && e.dados.error)` não se aplica aqui — o original
  só mostrava um texto fixo (`'<p>Erro ao carregar dashboard.</p>'`) sem usar a mensagem do
  servidor; manter esse texto fixo (nenhuma tela de erro detalhada existia antes).

## Review Focus

- Dashboard carrega com dados reais: os 9 cards de KPI (Total, Encerrados, Ativos, Taxa de
  Conclusão, Tempo Médio Total, Tempo Médio 1º Atendimento, FCR, SLA Vencido, Via Canal
  Externo) e os 7 gráficos (Status, Prioridade, Agente, Status por Agente, Evolução Mensal,
  Dia da Semana, Evolução Diária 30d) renderizam sem erro.
- Clicar no ícone "info" de qualquer card/gráfico abre o modal com a explicação certa
  (`mostrarInfoGrafico`) — são ~17 tipos diferentes de texto, não precisa testar todos, só
  uma amostra.
- Listas de Top 5 Solicitantes, Top 5 Subcategorias e Demandas por Categoria aparecem com
  `sanitizeHTML()` nos nomes.
- Admin trocando de setor no cabeçalho recarrega o dashboard automaticamente
  (`cabecalho-setor.js` → `RECARREGADORES.dashboard` → `carregarDashboard()`), de um arquivo
  que não muda nesta onda.

---

## Contexto para quem for implementar

Em `frontend/script.js`, procure pelo texto exato dos comentários (os números de linha abaixo
são de 21/10/2026 e podem já ter se deslocado um pouco):

- `// Função para mostrar dashboard` (linha 3432) até o fim de `mostrarDashboardNaTela`
  (linha 3731, antes dos comentários mortos de WebSocket `// WebSocket removido para PHP`,
  que **não** fazem parte deste bloco — ficam em `script.js`). 3 funções:
  `mostrarDashboard`, `carregarDashboard`, `mostrarDashboardNaTela`.
- `// Função para mostrar informações sobre os gráficos` (linha 6463) até o fim de
  `mostrarInfoGrafico` (linha 6592, antes do comentário `// Função para enviar comentário e
  fechar ticket`, que **não** faz parte deste bloco — é do domínio Demandas). 1 função:
  `mostrarInfoGrafico`.

Todas essas funções só dependem de coisas que continuam em `script.js`: `Api`, `urlComSetor`,
`sanitizeHTML`, `mostrarSecao`, `modalCallback`, e as funções `criarGraficoX` de
`graficos-dashboard.js` (arquivo que não muda). Para rodar localmente:
`http://localhost/conecta/frontend/index.html`, XAMPP em `C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair o domínio Dashboard para `frontend/dashboard.js`

**Files:**
- Create: `frontend/dashboard.js`
- Modify: `frontend/script.js` (remover os 2 trechos listados no contexto acima)
- Modify: `frontend/index.html` (registrar `dashboard.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api`, `urlComSetor`, `sanitizeHTML`, `mostrarSecao`, `modalCallback`,
  `criarGraficoStatus`/`criarGraficoPrioridade`/`criarGraficoMensal`/`criarGraficoAgente`/
  `criarGraficoResolucao`/`criarGraficoDiaSemana`/`criarGraficoStatusAgente`/
  `criarGraficoFunil`/`criarGraficoDiarios30d`/`criarGraficoStatusMensal`/
  `criarGraficoHeatmap` (de `graficos-dashboard.js`) — todos inalterados.
- Produces: as mesmas 4 funções globais de antes, mesmos nomes e assinaturas.

- [ ] **Step 1: Criar `frontend/dashboard.js`**

- [ ] **Step 2: Remover os 2 trechos de `script.js`**

Confira com `grep -n "Função para mostrar dashboard\|^function mostrarInfoGrafico\|^function enviarEFecharTicket" frontend/script.js`
— depois da remoção, nenhuma das 4 funções movidas deve sobrar.

- [ ] **Step 3: Registrar `dashboard.js` em `index.html` e subir a versão de `script.js`**

- [ ] **Step 4: Testar no navegador (Playwright)**

1. Abrir "Dashboard": os 9 cards de KPI e os 7 gráficos carregam com dados reais, sem erro no
   console.
2. Clicar no ícone "info" de 2-3 cards diferentes (ex.: "Total de Demandas", "FCR"): modal
   abre com o texto certo para cada um.
3. Trocar o setor no cabeçalho (como admin): dashboard recarrega automaticamente com os dados
   do novo setor.

- [ ] **Step 5: Commit**

```bash
git add frontend/dashboard.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Dashboard de script.js para dashboard.js, usando api.js (Fase 12, onda 8)"
```

---

### Task 2: Regressão rápida da onda

- [ ] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 171 após a Onda 7; esperar 171 - 4 = 167
node --check script.js
node --check dashboard.js
```

- [ ] **Step 2: Login do zero, console limpo**

- [ ] **Step 3: Rodar a suíte de backend**

```bash
cd ../api
composer test
php tests/matriz-de-acesso.php
```

Esperado: `OK (31 tests, ...)` e `Resultado: 19 OK, 0 falha(s)` — idêntico a antes desta onda.
