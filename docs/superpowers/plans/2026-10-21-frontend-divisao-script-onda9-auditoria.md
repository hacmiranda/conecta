# Onda 9 — extração de Auditoria — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. Steps usam checkbox (`- [ ]`) para acompanhamento.

**Goal:** Mover o domínio Auditoria (telas Geral/Demandas/Login/Estatísticas/Log de E-mails,
exportação CSV/PDF) de `frontend/script.js` para `frontend/auditoria.js`, migrando as
chamadas de rede para `Api.*`, sem alterar nenhum comportamento visível.

**Architecture:** Mesma mecânica das Ondas 1-8. Esta onda é a maior até agora (27 funções),
mas **todo o domínio é um único bloco contíguo** em `script.js` — sem recortes
não-adjacentes, diferente de várias ondas anteriores.

**Tech Stack:** Vanilla JS. 10 chamadas de rede a converter.

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`.

## Descoberta: 3 funções mortas pré-existentes (versão antiga da tela)

A tela de Auditoria tem hoje 5 abas (Geral/Demandas/Login/Estatísticas/E-mails, implementadas
por `carregarAuditoriaGeral`/`carregarAuditoriaTickets`/`carregarAuditoriaLogin`/
`carregarEstatisticasAudit`/`carregarLogEmails`). Três funções mais antigas —
`carregarAuditoria`, `filtrarAuditoria` e `mostrarListaAuditoria` (um formulário de filtro
diferente, com `filtro-acao`/`filtro-data-inicio` em vez dos filtros atuais) — não são
chamadas por nenhum `onclick` do HTML gerado pela tela atual. `carregarAuditoria` não tem
nenhum chamador. `filtrarAuditoria` só é chamada por si mesma (nenhum lugar). Só
`mostrarListaAuditoria` é referenciada (por `carregarAuditoria` e `filtrarAuditoria`, ambas
mortas). **Mover como estão, sem remover** — regra do projeto de nunca remover função
existente sem perguntar antes.

## Lição das ondas anteriores (aplicar desde o primeiro rascunho)

Nenhuma das 10 chamadas de rede desta onda mostrava a mensagem do servidor ao usuário no
código original (a maioria é silenciosa em erro, ou usa um texto fixo). Então a regra
`(e.dados && e.dados.error) || fallback` **não se aplica** a nenhuma delas — manter os
textos fixos/comportamento silencioso exatamente como estavam.

## Global Constraints

- Nenhum `onclick` muda de nome ou assinatura.
- `todosUsuariosAudit` (variável `let` de módulo) continua global, no novo arquivo.
- `irParaLogEmails()` (banner de alerta de falha de e-mail, perto do topo de `script.js`,
  linha ~230) **não** faz parte desta onda — chama `mostrarAuditoria`/`carregarLogEmails` via
  `typeof` check, então funciona independente de em qual arquivo elas morarem. Fica em
  `script.js`, junto do resto do sistema de banner de alerta.
- `baixarCSV(registros, filename)` só é usada por `exportarAuditoria` — move junto para
  `auditoria.js`, não é uma função genérica compartilhada com outro domínio.
- `frontend/auditoria.js` entra em `index.html` depois de `script.js`/`api.js` e dos demais
  arquivos de onda já carregados; versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- As 5 abas (Geral, Demandas, Login, Estatísticas, Log de E-mails) carregam cada uma sem
  erro, com paginação funcionando nas que têm (`mostrarPaginacaoAudit`).
- Filtros superiores (Data Início/Fim, Usuário com busca por nome/e-mail): aplicar filtro
  recarrega a aba ativa com os parâmetros certos; "Limpar" reseta e recarrega.
- Exportar CSV (aba ativa) e PDF (abre nova janela com conteúdo formatado para impressão) —
  o teste de PDF no navegador real pode travar a automação (mesma limitação da Onda 5,
  `exportarPDF` de Relatórios, que usa `window.print()`); se acontecer, validar por leitura
  de código em vez de clicar de verdade.
- Aba "Log de E-mails": filtros próprios (Período/Status/Tipo), exportar CSV específico
  (`exportarLogEmailsCSV`), paginação.
- As 3 funções mortas (`carregarAuditoria`, `filtrarAuditoria`, `mostrarListaAuditoria`) só
  precisam compilar sem erro — não têm UI que as exercite, então não são testáveis via
  interação normal.

---

## Contexto para quem for implementar

Em `frontend/script.js`, procure pelo texto exato dos comentários (os números de linha abaixo
são de 21/10/2026 e podem já ter se deslocado um pouco):

- `// Função para mostrar auditoria` (linha 4903) até o fim de `baixarCSV` (linha 6160, antes
  de duas linhas em branco e do comentário `// Função para enviar comentário e fechar
  ticket`, que **não** faz parte deste bloco — é do domínio Demandas). Bloco único e
  contíguo. 27 funções + a variável `todosUsuariosAudit`: `mostrarAuditoria`,
  `carregarAuditoria`, `carregarLogEmails`, `mostrarLogEmails`, `mostrarListaAuditoria`,
  `filtrarAuditoria`, `limparFiltrosAuditoria`, `carregarUsuariosFiltroAudit`,
  `configurarBuscaUsuarioAudit`, `renderizarUsuariosAudit`, `aplicarFiltrosAuditoria`,
  `carregarAuditoriaGeral`, `mostrarRegistrosAuditoria`, `carregarAuditoriaTickets`,
  `mostrarRegistrosTicketsAudit`, `carregarAuditoriaLogin`, `mostrarRegistrosLoginAudit`,
  `carregarEstatisticasAudit`, `mostrarEstatisticasAudit`, `formatarAcaoAuditoria`,
  `formatarAcaoLogin`, `formatarUserAgent`, `mostrarPaginacaoAudit`, `exportarAuditoria`,
  `exportarAuditoriaPDF`, `exportarLogEmailsCSV`, `baixarCSV`.

Todas essas funções só dependem de coisas que continuam em `script.js`: `Api`, `sanitizeHTML`,
`mostrarAlert`, `mostrarToast`, `window.APP_CONFIG`. Para rodar localmente:
`http://localhost/conecta/frontend/index.html`, XAMPP em `C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair o domínio Auditoria para `frontend/auditoria.js`

**Files:**
- Create: `frontend/auditoria.js`
- Modify: `frontend/script.js` (remover o bloco único listado no contexto acima)
- Modify: `frontend/index.html` (registrar `auditoria.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api`, `sanitizeHTML`, `mostrarAlert`, `mostrarToast` — todos inalterados.
- Produces: as mesmas 27 funções globais de antes, mesmos nomes e assinaturas, e a mesma
  variável global `todosUsuariosAudit`.

- [ ] **Step 1: Criar `frontend/auditoria.js`**

- [ ] **Step 2: Remover o bloco de `script.js`**

Confira com `grep -n "Função para mostrar auditoria\|^function mostrarAuditoria\|^function baixarCSV\|^function enviarEFecharTicket" frontend/script.js`
— depois da remoção, nenhuma das 27 funções movidas deve sobrar.

- [ ] **Step 3: Registrar `auditoria.js` em `index.html` e subir a versão de `script.js`**

- [ ] **Step 4: Testar no navegador (Playwright)**

Login como admin:

1. Configurações → Auditoria: aba "Geral" carrega registros reais (admin, gestor, operador
   já geraram eventos de auditoria nas ondas anteriores de teste, ou a lista vem vazia — os
   dois cenários são válidos).
2. Trocar para "Demandas", "Login", "Estatísticas": cada aba carrega sem erro no console.
3. Aplicar um filtro de data (Data Início = hoje) e clicar "Filtrar": a aba ativa recarrega
   com o filtro.
4. "Limpar": reseta os filtros e recarrega.
5. Aba "Log de E-mails": filtros próprios carregam; trocar o período e filtrar.
6. Exportar CSV (de qualquer aba): download real do arquivo.
7. Exportar PDF: se travar a automação (como na Onda 5), documentar como "validado por
   leitura de código" em vez de testar ao vivo.

- [ ] **Step 5: Commit**

```bash
git add frontend/auditoria.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Auditoria de script.js para auditoria.js, usando api.js (Fase 12, onda 9)"
```

---

### Task 2: Regressão rápida da onda

- [ ] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 167 após a Onda 8; esperar 167 - 27 = 140
node --check script.js
node --check auditoria.js
```

- [ ] **Step 2: Login do zero, console limpo**

- [ ] **Step 3: Rodar a suíte de backend**

```bash
cd ../api
composer test
php tests/matriz-de-acesso.php
```

Esperado: `OK (31 tests, ...)` e `Resultado: 19 OK, 0 falha(s)` — idêntico a antes desta onda.
