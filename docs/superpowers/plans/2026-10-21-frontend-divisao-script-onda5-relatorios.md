# Onda 5 — extração de Relatórios — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover o domínio Relatórios de `frontend/script.js` para `frontend/relatorios.js`,
migrando as chamadas de rede para `Api.*`, sem alterar nenhum comportamento visível.

**Architecture:** Mesma mecânica das Ondas 1-4 (já aprovada e em produção). Bloco único e
contíguo — sem recortes não adjacentes nesta onda.

**Tech Stack:** Vanilla JS (`async/await` e `.then/.catch` onde o original disparava duas
requisições em paralelo), validação manual no navegador via Playwright.

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Lição das ondas anteriores (aplicar desde o primeiro rascunho)

Todo `catch` que mostra erro ao usuário usa `(e.dados && e.dados.error) || 'texto padrão'`.

## Global Constraints

- Nenhum `onclick="..."` muda de nome ou assinatura.
- `carregarFiltrosRelatorio()` disparava duas requisições (categorias e agentes) em paralelo,
  sem uma esperar a outra. A versão com `Api` preserva esse paralelismo usando
  `.then/.catch` em vez de `await` sequencial — não transformar em duas chamadas em série.
- `dadosRelatorioAtual` (variável `let` de módulo) continua global, no novo arquivo.
- `frontend/relatorios.js` entra em `index.html` depois de `script.js`, `api.js` e dos demais
  arquivos de onda já carregados; versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- `exportarPDF`/`exportarExcel`/`exibirRelatorio` não fazem chamada de rede — são só
  renderização a partir de `dadosRelatorioAtual`; confirmar que nenhuma delas virou `async`
  sem necessidade.
- `sanitizeHTML()` nos campos de dados do servidor (`ticket.titulo`, `ticket.usuario_nome`,
  `ticket.agente_nome`, `ticket.categoria_nome`) precisa continuar presente em
  `exibirRelatorio` e em `exportarPDF` (ambas renderizam a mesma tabela em lugares
  diferentes).
- Gerar relatório sem resultados (filtros que não batem com nenhuma demanda): a tabela mostra
  cabeçalho vazio, sem erro.
- Exportar sem ter gerado um relatório antes: `mostrarAlert` "Gere um relatório antes de
  exportar." — continua bloqueando no frontend, sem chamar nada.
- O listener de `filtro-periodo` (mostra/oculta `datas-personalizadas`) não depende de rede —
  confirmar que ele continua registrado uma única vez, sem duplicar se a tela recarregar.

---

## Contexto para quem for implementar

Em `frontend/script.js`, procure pelo texto exato dos comentários (os números de linha abaixo
são de 21/10/2026 e podem já ter se deslocado um pouco):

- `// Função para mostrar relatórios` (linha 3977) até o fim de `exportarPDF` (linha 4337,
  imediatamente antes do comentário `// Verificar complexidade da senha`, que **não** faz
  parte deste bloco — é da tela "Alterar senha"). 6 funções nomeadas + 1 listener de
  `DOMContentLoaded` (mostra/oculta `datas-personalizadas` conforme o filtro de período) + a
  variável `dadosRelatorioAtual`: `mostrarRelatorios`, `carregarFiltrosRelatorio`,
  `gerarRelatorio`, `exibirRelatorio`, `exportarRelatorio`, `exportarExcel`, `exportarPDF`.

Todas essas funções só dependem de coisas que continuam em `script.js`: `Api`, `urlComSetor`,
`sanitizeHTML`, `mostrarAlert`, `mostrarToast`, `mostrarSecao`, `salvarSecaoAtiva`,
`window.APP_CONFIG`. Uma chamada a `mostrarRelatorios()` (linha 3886, restauração de seção
salva ao recarregar a página) fica em `script.js` e não muda. Para rodar localmente:
`http://localhost/conecta/frontend/index.html`, XAMPP em `C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair o domínio Relatórios para `frontend/relatorios.js`

**Files:**
- Create: `frontend/relatorios.js`
- Modify: `frontend/script.js` (remover o bloco único listado no contexto acima)
- Modify: `frontend/index.html` (registrar `relatorios.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api`, `urlComSetor`, `sanitizeHTML`, `mostrarAlert`, `mostrarToast`,
  `mostrarSecao`, `salvarSecaoAtiva` — todos inalterados.
- Produces: as mesmas 6 funções globais de antes, mesmos nomes e assinaturas, e a mesma
  variável global `dadosRelatorioAtual`.

- [x] **Step 1: Criar `frontend/relatorios.js`**

- [x] **Step 2: Remover o bloco de `script.js`**

Remova do comentário `// Função para mostrar relatórios` até o fim de `exportarPDF` (pare
antes do comentário `// Verificar complexidade da senha`).

Confira com `grep -n "Função para mostrar relatórios\|^function mostrarRelatorios\|^function exportarPDF\|^function verificarComplexidade" frontend/script.js`
— depois da remoção, só deve sobrar `verificarComplexidade`.

- [x] **Step 3: Registrar `relatorios.js` em `index.html` e subir a versão de `script.js`**

- [x] **Step 4: Testar no navegador (Playwright)**

Login como admin:

1. Abrir "Relatórios": filtros de categoria e agente carregam populados, sem erro no console.
2. Selecionar período "Mês atual" e gerar relatório: cartões de resumo e tabela aparecem
   (mesmo que vazios, se não houver demandas no período).
3. Selecionar período "Personalizado": campos de data aparecem; sem selecionar, voltar para
   outro período esconde os campos de novo.
4. Exportar para Excel (CSV) sem ter gerado relatório antes: `mostrarAlert` "Gere um relatório
   antes de exportar."
5. Gerar um relatório com dados reais (período amplo, ex. "Ano") e exportar para Excel: toast
   de sucesso, arquivo baixado.
6. Exportar para PDF (abre nova janela/aba com o conteúdo formatado para impressão): toast de
   sucesso.

- [x] **Step 5: Commit**

```bash
git add frontend/relatorios.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Relatorios de script.js para relatorios.js, usando api.js (Fase 12, onda 5)"
```

---

### Task 2: Regressão rápida da onda

- [x] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 198 após a Onda 4; esperar 198 - 6 = 192
node --check script.js
node --check relatorios.js
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

- Abrir "Relatórios": filtros de Status/Prioridade carregam com as opções do catálogo;
  "Subcategoria" veio vazio além de "Todas" (não há categorias cadastradas no setor agora —
  nada a ver com esta onda); "Responsável" carregou via `agentes.php` com os 4 usuários reais.
- Período "Personalizado": campos "De:"/"Até:" aparecem; voltar para "Ano Atual": campos
  escondem de novo. Listener de `filtro-periodo` funcionando sem duplicar.
- "Exportar Excel" sem gerar relatório antes: `mostrarAlert` "Gere um relatório antes de
  exportar." sem chamar o servidor.
- "Gerar Relatório" (Ano Atual, sem filtros): `Api.get` em `relatorios.php` retornou com
  sucesso; cartões de resumo (0 demandas, "N/A" nos tempos médios — não há demandas reais no
  setor agora) e tabela vazia renderizados sem erro.
- "Exportar Excel" com relatório gerado: download real do CSV (`relatorio_tickets_AAAA-MM-DD.csv`)
  confirmado pelo navegador.
- "Exportar PDF": **não testável via Playwright** — `exportarPDF()` chama `window.print()`,
  que abre o diálogo de impressão nativo do sistema operacional e bloqueia toda a automação
  (travou a sessão do Playwright, precisou reabrir o navegador). `exportarPDF()` não foi
  alterada nesta onda (não faz nenhuma chamada de rede, é cópia literal do código original) —
  risco de regressão nulo, só o teste automatizado dessa função específica não é viável neste
  ambiente.
- Conta de teste `zz Admin Onda5` removida do banco ao final; confirmado
  `SELECT COUNT(*) FROM usuarios WHERE nome LIKE 'zz%'` = 0.
- `grep -c "^function " script.js` → 191 (198 - 7; o plano original contou errado "6
  funções", são 7: `mostrarRelatorios`, `carregarFiltrosRelatorio`, `gerarRelatorio`,
  `exibirRelatorio`, `exportarRelatorio`, `exportarExcel`, `exportarPDF`); `node --check`
  limpo em `script.js` e `relatorios.js`; login do zero → console sem erro novo;
  `composer test` → OK (31 testes, 41 assertions); `php tests/matriz-de-acesso.php` → 19 OK,
  0 falhas.

## Revisão final (subagente opus)

0 Crítico, 2 Importante (corrigidos), 4 Menor (todos pré-existentes, não corrigidos nesta
onda). A conversão foi confirmada fiel linha a linha ao bloco original: `carregarFiltrosRelatorio`
mantém as duas chamadas em paralelo com `.then/.catch` silencioso (decisão documentada no
plano), `gerarRelatorio` usa `Api.get` corretamente, `dadosRelatorioAtual` é compartilhada sem
redeclaração, `exibirRelatorio`/`exportarExcel`/`exportarPDF` continuam síncronas e idênticas,
`sanitizeHTML()` preservado em todos os campos de texto do servidor, o `.replace(/;/g, ',')`
anti-quebra-de-CSV intacto, e o corte em `script.js` ficou limpo.

- **Corrigidos (commit `bbf5c52`):**
  - `gerarRelatorio()` usava um texto fixo no `catch` em vez do padrão
    `(e.dados && e.dados.error) || 'fallback'` desta onda (efeito prático nulo hoje, mas
    engoliria uma mensagem nova do servidor).
  - O BOM do CSV em `exportarExcel()` (`let csv = '﻿';`) tinha virado o caractere
    U+FEFF literal no arquivo-fonte, em vez do escape `﻿` do código original — mesmo
    efeito em runtime (confirmado isoladamente: `'﻿'.length === 1`,
    `charCodeAt(0) === 0xfeff`), mas um caractere invisível que um editor/formatador
    poderia apagar em silêncio sem aparecer no diff. Restaurado ao escape literal.
- **Menor (não corrigido, adiado, todos pré-existentes desde antes desta divisão):**
  `ticket.status`/`ticket.prioridade` sem `sanitizeHTML()` (valores de catálogo, risco
  baixo); atributo malformado `<small style=""color: #666;"">`; `carregarFiltrosRelatorio`
  não limpa os `<select>` antes de popular (duplica opções a cada visita); nenhum é
  regressão desta onda.
