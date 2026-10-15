# Onda 1 — `api.js` + extração de Setores e Lixeira — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar `frontend/api.js` (cliente HTTP único) e mover os domínios Setores e Lixeira de
`frontend/script.js` para `frontend/setores.js` e `frontend/lixeira.js`, migrando as chamadas de
rede desses dois domínios para `Api.*`, sem alterar nenhum comportamento visível.

**Architecture:** Vanilla JS, sem build step: cada domínio continua sendo um `<script>` global
carregado em sequência no `index.html`, igual aos arquivos de domínio que já existem
(`macros.js`, `avatar.js`, `notificacoes.js`). `api.js` expõe um único objeto global `Api`
baseado em `fetch`, reaproveitando `getAuthHeaders()` (já existe em `script.js`). As funções
movidas continuam com o mesmo nome e assinatura — `onclick="..."` no HTML não muda.

**Tech Stack:** Vanilla JS (ES2017+: `async/await`, `fetch`), sem framework, sem bundler.
Validação manual no navegador via Playwright (o projeto não tem test runner de frontend).

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`

## Global Constraints

- Nenhum `onclick="..."` no HTML (estático ou gerado em runtime) muda de nome ou assinatura.
- Nenhuma função muda de comportamento visível (mesma mensagem de sucesso/erro, mesmo toast/alert).
- Toda nova chamada de rede dos domínios movidos usa `Api.get/post/put/delete` — nenhuma chamada
  de rede nova em `XMLHttpRequest`.
- Funções movidas continuam em escopo global (sem `type="module"`, sem IIFE que as esconda).
- Cada arquivo novo entra em `index.html` depois de `script.js` e depois de `api.js`; a versão
  (`?v=`) de todo arquivo tocado é incrementada para evitar cache.
- Um commit por tarefa (Task 1, 2 e 3 abaixo cada uma seu commit).

## Review Focus

- Gestor (não-admin) abrindo "Gerenciar Setores": só vê o próprio setor, sem o botão "Adicionar
  Novo Setor" — esse filtro é feito no próprio `carregarSetores()`/`mostrarListaSetores()` e
  precisa sobreviver à migração para `Api.get`.
- Criar um setor com nome duplicado (o backend responde 400 com `{"error": "..."}"`): a mensagem
  de erro do servidor precisa continuar aparecendo em `mostrarAlert`, não um texto genérico —
  `Api.request` já embute essa mensagem em `erro.message`.
- Clicar em "Editar" num setor usa o `modal-prompt` compartilhado (`modalCallback`, declarado em
  `script.js`) — precisa continuar funcionando depois que `editarSetor`/`executarEdicaoSetor`
  saírem para `setores.js` (variáveis `let`/`const` de nível superior em um `<script>` clássico
  são visíveis a outro `<script>` carregado depois, no mesmo documento).
- Sessão inválida/expirada enquanto o usuário está em Setores ou na Lixeira: o monitor global de
  401/403 (em `script.js`, intercepta todo `fetch`) precisa continuar agindo mesmo vindo de
  `Api.request`, que usa `fetch` por baixo.
- Excluir definitivamente uma demanda na Lixeira sem ser admin (o backend responde 403): a
  mensagem de erro do servidor precisa aparecer, não travar a tela nem lançar exceção não tratada.

---

## Contexto para quem for implementar

`frontend/script.js` tem hoje os blocos (procure pelo comentário exato):

- `// === GERENCIAMENTO DE SETORES (admin) ===` — linhas 2859 a 3036 (até a linha em branco antes
  de `// === CONTROLE DE VISIBILIDADE POR PAPEL ===`). 8 funções:
  `mostrarSetores`, `carregarSetores`, `mostrarListaSetores`, `toggleFormSetor`, `criarSetor`,
  `editarSetor`, `executarEdicaoSetor`, `alternarAtivoSetor`.
- `// 🗑️ LIXEIRA (somente administrador)` — linhas 3542 a 3638 (até o comentário
  `// Executar exclusão do ticket`, que **não** faz parte deste bloco — fica em `script.js`,
  pertence ao domínio Demandas, que será movido numa onda futura). 4 funções:
  `mostrarLixeira`, `carregarLixeira`, `restaurarDemanda`, `excluirDefinitivo`.

Essas linhas podem já ter se deslocado um pouco se algo mudou em `script.js` entre a escrita
deste plano e a execução — **localize pelo texto do comentário**, não confie só no número da
linha.

Ambos os blocos só dependem de funções que continuam em `script.js` depois da extração:
`setSecurityHeaders`/`getAuthHeaders`, `sanitizeHTML`, `argJs`, `mostrarToast`, `mostrarAlert`,
`mostrarConfirm`, `mostrarSecao`, `urlComSetor`, `modalCallback` (variável), e `window.APP_CONFIG`
(definido em `app-config.js`, carregado antes de tudo). Nenhuma dessas precisa mudar.

Para rodar o sistema localmente: XAMPP com o projeto em `C:\xampp\htdocs\conecta`, acessível em
`http://localhost/conecta/frontend/index.html`. Login de teste: usuário admin já existente no
banco local (ver `CLAUDE.md`/memória do projeto para a senha, ou usar
`php database/seed_admin.php` se precisar de um novo).

---

### Task 1: `frontend/api.js` — cliente HTTP único

**Files:**
- Create: `frontend/api.js`
- Modify: `frontend/index.html:928` (adicionar a tag `<script>`, depois de `script.js?v=4.9`)

**Interfaces:**
- Produces: objeto global `Api` com `Api.get(caminho, opts)`, `Api.post(caminho, corpo, opts)`,
  `Api.put(caminho, corpo, opts)`, `Api.delete(caminho, opts)`. Cada um devolve uma `Promise` que
  resolve com os dados já decodificados de JSON (ou `null` se o corpo da resposta for vazio) e
  rejeita com um `Error` cuja `.message` é `dados.error` (quando o servidor devolveu JSON com essa
  chave) ou `` `Erro ${status}` `` como alternativa; o `Error` rejeitado também tem `.status`
  (código HTTP) e `.dados` (o JSON decodificado, ou `null`).
- Consumes: `window.APP_CONFIG.apiBase` (de `app-config.js`) e `getAuthHeaders()` (de
  `script.js:20`) — ambos já carregados antes de `api.js` no `index.html`.

- [ ] **Step 1: Criar `frontend/api.js`**

```js
/**
 * Cliente HTTP único do Conecta CRPRS (Fase 12 da auditoria, P1).
 * Resolve só o transporte — montar URL, cabeçalhos de autenticação/CSRF, serializar e
 * desserializar JSON, e decidir sucesso/erro pelo código HTTP. Cada chamador continua
 * decidindo como reagir ao erro (toast, alert, silencioso), exatamente como decide hoje.
 *
 * O 401/403 (sessão expirada) já é tratado globalmente em script.js, que intercepta todo
 * fetch() da aplicação — Api não precisa (e não deve) tratar isso de novo.
 */
const Api = {
    async request(metodo, caminho, { corpo, signal } = {}) {
        const url = caminho.startsWith('http') ? caminho : window.APP_CONFIG.apiBase + caminho;
        const headers = getAuthHeaders();
        if (corpo !== undefined) {
            headers['Content-Type'] = 'application/json';
        }
        const resposta = await fetch(url, {
            method: metodo,
            headers,
            signal,
            body: corpo !== undefined ? JSON.stringify(corpo) : undefined,
        });
        const texto = await resposta.text();
        let dados = null;
        if (texto) {
            try {
                dados = JSON.parse(texto);
            } catch (e) {
                dados = null; // resposta não-JSON (ex.: erro HTML do servidor): segue com dados = null
            }
        }
        if (!resposta.ok) {
            const erro = new Error((dados && dados.error) || `Erro ${resposta.status}`);
            erro.status = resposta.status;
            erro.dados = dados;
            throw erro;
        }
        return dados;
    },

    get(caminho, opts) {
        return this.request('GET', caminho, opts);
    },
    post(caminho, corpo, opts) {
        return this.request('POST', caminho, { ...opts, corpo });
    },
    put(caminho, corpo, opts) {
        return this.request('PUT', caminho, { ...opts, corpo });
    },
    delete(caminho, opts) {
        return this.request('DELETE', caminho, opts);
    },
};
```

- [ ] **Step 2: Registrar `api.js` em `index.html`, depois de `script.js`**

Em `frontend/index.html`, troque a linha

```html
    <script src="script.js?v=4.9"></script>
```

por

```html
    <script src="script.js?v=4.10"></script>
    <script src="api.js?v=1"></script>
```

(a troca de `4.9` para `4.10` é só para invalidar o cache do navegador, já que `script.js` vai
ser editado nas próximas tarefas — se, ao chegar aqui, a versão atual já não for `4.9`, suba a
partir da que estiver lá).

- [ ] **Step 3: Verificar no navegador que `Api` existe e funciona**

Abra `http://localhost/conecta/frontend/index.html` logado como qualquer usuário e confira no
console do navegador (ou via Playwright `browser_evaluate`):

```js
() => typeof Api === 'object' && typeof Api.get === 'function'
```

Esperado: `true`. Nenhum erro novo no console (`browser_console_messages` nível `error`) depois
de carregar a página.

Em seguida, exercite `Api.get` contra um endpoint real (qualquer um que já exista, ex.
`setores.php`) direto no console, para confirmar que o transporte funciona antes de qualquer
função passar a usá-lo:

```js
async () => {
    const r = await Api.get('setores.php');
    return Array.isArray(r);
}
```

Esperado: `true`.

- [ ] **Step 4: Commit**

```bash
git add frontend/api.js frontend/index.html
git commit -m "feat: cria api.js, o cliente HTTP unico do frontend (Fase 12, onda 1)"
```

---

### Task 2: Extrair o domínio Setores para `frontend/setores.js`

**Files:**
- Create: `frontend/setores.js`
- Modify: `frontend/script.js` (remover o bloco `// === GERENCIAMENTO DE SETORES (admin) ===`)
- Modify: `frontend/index.html` (registrar `setores.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api` (Task 1); `sanitizeHTML`, `argJs`, `mostrarToast`, `mostrarAlert`,
  `modalCallback` (todos em `script.js`, inalterados).
- Produces: mesmas 8 funções globais de antes, mesmos nomes e assinaturas:
  `mostrarSetores()`, `carregarSetores()`, `mostrarListaSetores(setores)`, `toggleFormSetor()`,
  `criarSetor(event)`, `editarSetor(setorId, nomeAtual)`, `executarEdicaoSetor(setorId, nome)`,
  `alternarAtivoSetor(setorId, ativo)`.

- [ ] **Step 1: Criar `frontend/setores.js` com as 8 funções, chamadas de rede trocadas para `Api`**

```js
/**
 * Gerenciamento de Setores (admin cria/desativa; gestor edita o nome do próprio setor).
 * Extraído de script.js na Fase 12 (onda 1 da divisão por domínio).
 */

function mostrarSetores() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active'));
    localStorage.setItem('cd_subSecaoAtiva', 'setores');
    const configTitle = document.getElementById('config-title');
    if (configTitle) configTitle.textContent = '🏢 Gerenciar Setores';

    const content = document.getElementById('config-content');
    if (content) {
        content.style.animation = 'none';
        setTimeout(() => {
            content.style.animation = '';
            carregarSetores();
        }, 100);
    } else {
        setTimeout(() => mostrarSetores(), 200);
    }
}

async function carregarSetores() {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';

    try {
        let setores = await Api.get('setores.php' + (ehAdmin ? '?incluir_inativos=1' : ''));
        // Gestor só gerencia (edita o nome de) o próprio setor
        if (!ehAdmin) {
            setores = setores.filter(s => s.id == usuarioLogado.setor_id);
        }
        mostrarListaSetores(setores);
    } catch (e) {
        document.getElementById('config-content').innerHTML = '<p>Erro ao carregar setores.</p>';
    }
}

function mostrarListaSetores(setores) {
    const content = document.getElementById('config-content');
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';

    const cards = setores.map(setor => `
        <div class="usuario-card ${setor.ativo == 0 ? 'usuario-inativo' : ''}">
            <div class="usuario-info">
                <h4>${sanitizeHTML(setor.nome)} ${setor.ativo == 0 ? '<span style="color: #dc3545;">(Inativo)</span>' : ''}</h4>
                <p><strong>Criado:</strong> ${new Date(setor.criado_em).toLocaleDateString('pt-BR')}</p>
            </div>
            <div class="usuario-acoes">
                ${ehAdmin ? `
                    <button class="btn-${setor.ativo == 1 ? 'desativar' : 'ativar'}" onclick="alternarAtivoSetor(${setor.id}, ${setor.ativo == 1 ? 'false' : 'true'})">
                        ${setor.ativo == 1 ? 'Desativar' : 'Ativar'}
                    </button>
                ` : ''}
                <button class="btn-editar" onclick="editarSetor(${setor.id}, decodeURIComponent('${argJs(setor.nome)}'))">Editar</button>
            </div>
        </div>
    `).join('');

    content.innerHTML = `
        ${ehAdmin ? `
            <button onclick="toggleFormSetor()" class="btn-primary" style="margin-bottom: 1rem;">➕ Adicionar Novo Setor</button>

            <div class="form-usuario" id="form-novo-setor" style="display: none; overflow: hidden; max-height: 0; transition: max-height 0.3s ease-out;">
                <h3>Adicionar Novo Setor</h3>
                <form id="form-setor" onsubmit="criarSetor(event)">
                    <div class="form-group">
                        <label for="nome-setor">Nome do setor:</label>
                        <input type="text" id="nome-setor" required>
                    </div>
                    <button type="submit" class="btn-primary">Adicionar Setor</button>
                </form>
            </div>
        ` : '<p style="color:#666;margin-bottom:1rem;">Você pode renomear o seu próprio setor. Criar, desativar ou excluir setores é uma ação exclusiva de administradores.</p>'}

        <div class="usuarios-grid">${cards}</div>
    `;
}

function toggleFormSetor() {
    const form = document.getElementById('form-novo-setor');
    const isVisible = form.style.display === 'block';

    if (isVisible) {
        form.style.maxHeight = '0';
        setTimeout(() => form.style.display = 'none', 300);
    } else {
        form.style.display = 'block';
        setTimeout(() => form.style.maxHeight = form.scrollHeight + 'px', 10);
    }
}

async function criarSetor(event) {
    event.preventDefault();
    const nome = document.getElementById('nome-setor').value;

    try {
        await Api.post('setores.php', { nome });
        mostrarToast('Sucesso', 'Setor criado com sucesso!', 'success');
        document.getElementById('form-setor').reset();
        carregarSetores();
    } catch (e) {
        mostrarAlert('Erro', e.message || 'Erro ao criar setor');
    }
}

function editarSetor(setorId, nomeAtual) {
    document.getElementById('modal-prompt-message').innerHTML = `
        <div class="form-group">
            <label for="edit-nome-setor">Nome do setor:</label>
            <input type="text" id="edit-nome-setor" value="${nomeAtual}" required>
        </div>
    `;
    document.getElementById('modal-prompt-title').textContent = 'Editar Setor';
    document.getElementById('modal-prompt-input').style.display = 'none';
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        if (confirmado) {
            const novoNome = document.getElementById('edit-nome-setor').value.trim();
            if (novoNome) executarEdicaoSetor(setorId, novoNome);
        }
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
    };
}

async function executarEdicaoSetor(setorId, nome) {
    try {
        await Api.put(`setores.php/${setorId}`, { nome });
        mostrarToast('Sucesso', 'Setor atualizado com sucesso!', 'success');
        carregarSetores();
    } catch (e) {
        mostrarAlert('Erro', e.message || 'Erro ao atualizar setor');
    }
}

async function alternarAtivoSetor(setorId, ativo) {
    try {
        await Api.put(`setores.php/${setorId}`, { ativo });
        mostrarToast('Sucesso', ativo ? 'Setor ativado!' : 'Setor desativado!', 'success');
        carregarSetores();
    } catch (e) {
        mostrarAlert('Erro', e.message || 'Erro ao atualizar setor');
    }
}
```

- [ ] **Step 2: Remover o bloco de `script.js`**

Em `frontend/script.js`, apague do comentário `// === GERENCIAMENTO DE SETORES (admin) ===`
até o fim da função `alternarAtivoSetor` (inclusive), isto é, tudo até a linha em branco
imediatamente antes de `// === CONTROLE DE VISIBILIDADE POR PAPEL ===`. Confira com
`grep -n "GERENCIAMENTO DE SETORES\|CONTROLE DE VISIBILIDADE" frontend/script.js` antes e depois
— depois da remoção, só deve sobrar a ocorrência de "CONTROLE DE VISIBILIDADE".

- [ ] **Step 3: Registrar `setores.js` em `index.html` e subir a versão de `script.js`**

```html
    <script src="script.js?v=4.11"></script>
    <script src="api.js?v=1"></script>
    <script src="setores.js?v=1"></script>
```

(se a versão de `script.js` em `index.html` já não for `4.10` quando chegar aqui — por exemplo,
se a Task 1 tiver sido commitada em sessões diferentes — use a versão atual + 1, mantendo a
ideia de sempre subir, nunca repetir, um número já publicado.)

- [ ] **Step 4: Testar no navegador (Playwright)**

Login como **admin**, ir para Configurações → Setores (ou `mostrarSetores()` direto no console):

1. A lista carrega sem erro no console.
2. Clicar "➕ Adicionar Novo Setor", preencher um nome de teste (ex. `zz Setor Onda1`), submeter:
   aparece o toast de sucesso e o novo setor na lista.
3. Clicar "Desativar" no setor de teste: toast de sucesso, card marcado "(Inativo)".
4. Clicar "Editar" no setor de teste, mudar o nome no `modal-prompt`, confirmar: toast de
   sucesso, nome atualizado na lista.
5. Tentar criar um setor com o **mesmo nome** que já existe (duplicar): esperar `mostrarAlert`
   com a mensagem de erro do servidor (não um texto genérico).

Depois, logar como **gestor**: a tela mostra só o setor dele, sem o botão "Adicionar Novo Setor"
e sem botão "Desativar" — só "Editar".

Apagar o setor de teste direto no banco ao final (`DELETE FROM setores WHERE nome = 'zz Setor Onda1'`),
já que a tela não tem exclusão.

- [ ] **Step 5: Commit**

```bash
git add frontend/setores.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Setores de script.js para setores.js, usando api.js (Fase 12, onda 1)"
```

---

### Task 3: Extrair o domínio Lixeira para `frontend/lixeira.js`

**Files:**
- Create: `frontend/lixeira.js`
- Modify: `frontend/script.js` (remover o bloco `🗑️ LIXEIRA`)
- Modify: `frontend/index.html` (registrar `lixeira.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api` (Task 1); `mostrarSecao`, `urlComSetor`, `sanitizeHTML`, `mostrarConfirm`,
  `mostrarToast`, `mostrarAlert` (todos em `script.js`, inalterados); `formatarNomeStatus` (pode
  estar definida em outro arquivo de domínio já carregado antes — a função já trata isso com
  `typeof formatarNomeStatus === 'function'`, sem mudança aqui); `verDetalhes` (é só o nome de
  uma função chamada depois, dentro de um `onclick` gerado como string — continua funcionando
  porque `verDetalhes` continua global em `script.js`; nada a fazer aqui).
- Produces: mesmas 4 funções globais de antes: `mostrarLixeira()`, `carregarLixeira()`,
  `restaurarDemanda(ticketId)`, `excluirDefinitivo(ticketId)`.

- [ ] **Step 1: Criar `frontend/lixeira.js` com as 4 funções, chamadas de rede trocadas para `Api`**

```js
/**
 * Lixeira de demandas (só o administrador acessa). Extraído de script.js na Fase 12
 * (onda 1 da divisão por domínio).
 */

function mostrarLixeira() {
    mostrarSecao('lixeira');
    carregarLixeira();
}

async function carregarLixeira() {
    const lista = document.getElementById('lista-lixeira');
    if (!lista) return;
    lista.innerHTML = '<p>Carregando...</p>';

    try {
        const demandas = await Api.get(urlComSetor(window.APP_CONFIG.apiBase + 'tickets.php?escopo=lixeira'));
        if (demandas.length === 0) {
            lista.innerHTML = '<div style="text-align:center; padding: 3rem 1rem; color: #64748b;"><span class="material-symbols-outlined" style="font-size:3rem; opacity:0.4;" aria-hidden="true">delete</span><p>A lixeira está vazia.</p></div>';
            return;
        }
        let html = `<table class="tickets-tabela">
            <caption class="sr-only">Demandas na lixeira</caption>
            <thead><tr>
                <th scope="col">Nº Demanda</th><th scope="col">Assunto</th><th scope="col">Setor</th>
                <th scope="col">Status</th><th scope="col">Excluída em</th><th scope="col">Excluída por</th><th scope="col">Ações</th>
            </tr></thead><tbody>`;
        demandas.forEach(d => {
            const quando = d.excluido_em ? new Date(d.excluido_em.replace(' ', 'T')).toLocaleString('pt-BR') : '—';
            html += `<tr data-ticket-id="${Number(d.id)}">
                <td data-label="Nº Demanda">#${String(d.id).padStart(5, '0')}</td>
                <td data-label="Assunto">${sanitizeHTML(d.titulo)}</td>
                <td data-label="Setor">${sanitizeHTML(d.setor_nome || '—')}</td>
                <td data-label="Status">${sanitizeHTML(typeof formatarNomeStatus === 'function' ? formatarNomeStatus(d.status) : d.status)}</td>
                <td data-label="Excluída em">${quando}</td>
                <td data-label="Excluída por">${sanitizeHTML(d.excluido_por_nome || '—')}</td>
                <td data-label="Ações" class="lixeira-acoes">
                    <button class="btn-compact" onclick="secaoOrigem = 'lixeira'; verDetalhes(${Number(d.id)})">Ver</button>
                    <button class="btn-compact lixeira-restaurar" onclick="restaurarDemanda(${Number(d.id)})">Restaurar</button>
                    <button class="btn-compact lixeira-excluir" onclick="excluirDefinitivo(${Number(d.id)})">Excluir definitivamente</button>
                </td></tr>`;
        });
        html += '</tbody></table>';
        lista.innerHTML = html;
    } catch (e) {
        lista.innerHTML = '<p>Erro ao carregar a lixeira.</p>';
    }
}

function restaurarDemanda(ticketId) {
    mostrarConfirm('Restaurar Demanda', 'A demanda volta para as listas do setor, com o histórico preservado. Deseja restaurá-la?', async function (confirmado) {
        if (!confirmado) return;
        try {
            await Api.put(`tickets.php?id=${Number(ticketId)}&acao=restaurar`, {});
            mostrarToast('Sucesso', 'Demanda restaurada.', 'success');
            localStorage.removeItem('cd_ticketAtual');
            mostrarLixeira();
        } catch (e) {
            mostrarAlert('Erro', e.message || 'Erro ao restaurar a demanda');
        }
    });
}

function excluirDefinitivo(ticketId) {
    mostrarConfirm('Excluir definitivamente',
        'ATENÇÃO: a demanda, os comentários, os anexos e o histórico serão apagados para sempre e não poderão ser recuperados. Confirma?',
        async function (confirmado) {
            if (!confirmado) return;
            try {
                await Api.delete(`tickets.php?id=${Number(ticketId)}&definitivo=1`);
                mostrarToast('Sucesso', 'Demanda excluída definitivamente.', 'success');
                localStorage.removeItem('cd_ticketAtual');
                mostrarLixeira();
            } catch (e) {
                mostrarAlert('Erro', e.message || 'Erro ao excluir a demanda');
            }
        }, true);
}
```

- [ ] **Step 2: Remover o bloco de `script.js`**

Em `frontend/script.js`, apague do comentário `// 🗑️ LIXEIRA (somente administrador)` (incluindo
as duas linhas `// ======================================================` que o envolvem) até o
fim da função `excluirDefinitivo` — **pare antes** do comentário `// Executar exclusão do
ticket`, que marca o início de `executarExclusaoTicket` e **não faz parte desta tarefa** (fica em
`script.js`, pertence ao domínio Demandas). Confira com
`grep -n "LIXEIRA\|Executar exclusão do ticket" frontend/script.js` antes e depois.

- [ ] **Step 3: Registrar `lixeira.js` em `index.html` e subir a versão de `script.js`**

```html
    <script src="script.js?v=4.12"></script>
    <script src="api.js?v=1"></script>
    <script src="setores.js?v=1"></script>
    <script src="lixeira.js?v=1"></script>
```

(mesma ressalva da Task 2: se a versão atual de `script.js` em `index.html` não for `4.11` neste
ponto, use a versão atual + 1.)

- [ ] **Step 4: Testar no navegador (Playwright)**

Precisa de ao menos uma demanda na lixeira para testar de ponta a ponta — se o banco local
estiver vazio, crie uma demanda de teste e envie-a para a lixeira primeiro (pela própria
aplicação, ou por SQL direto marcando `excluido_em`/`excluido_por`), e remova-a ao final.

Login como **admin**:

1. Abrir a Lixeira (menu "Lixeira" ou `mostrarLixeira()` no console): a tabela carrega sem erro
   no console, com a legenda, os cabeçalhos com `scope="col"` e a demanda de teste na lista.
2. Clicar "Ver": abre o detalhe da demanda (comportamento herdado de `verDetalhes`, não alterado
   por este plano — só confirma que a referência à função ainda funciona depois do arquivo
   mudar).
3. Voltar à Lixeira, clicar "Restaurar": toast de sucesso, a demanda sai da lista (a lixeira
   recarrega).
4. Enviar a mesma demanda de novo para a lixeira (pela aplicação) e, desta vez, clicar "Excluir
   definitivamente": toast de sucesso, a demanda sai da lista.
5. Login como **gestor ou operador**: a opção "Lixeira" não aparece no menu (comportamento já
   existente, controlado fora deste bloco) — só confirmar que nada quebrou aqui.
6. Com um token de admin válido porém artificialmente trocado por um inválido (ou aguardando o
   token expirar), tentar abrir a Lixeira: espera-se o redirecionamento para o login (o monitor
   global de 401, não este código, é quem faz isso — só confirmar que `Api.get` não engole o
   403/401 nem impede o redirecionamento).

- [ ] **Step 5: Commit**

```bash
git add frontend/lixeira.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Lixeira de script.js para lixeira.js, usando api.js (Fase 12, onda 1)"
```

---

### Task 4: Regressão rápida da onda inteira

**Files:** nenhum arquivo novo — só validação.

- [ ] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # deve ser 267 menos as 8 (Setores) menos as 4 (Lixeira) = 255
grep -n "^function mostrarSetores\|^function carregarSetores\|^function mostrarListaSetores\|^function toggleFormSetor\|^function criarSetor\b\|^function editarSetor\|^function executarEdicaoSetor\|^function alternarAtivoSetor\|^function mostrarLixeira\|^function carregarLixeira\|^function restaurarDemanda\|^function excluirDefinitivo" script.js
```

Esperado: o segundo comando não devolve nenhuma linha (todas essas 12 funções só existem agora em
`setores.js`/`lixeira.js`).

```bash
node --check script.js
node --check api.js
node --check setores.js
node --check lixeira.js
```

Esperado: nenhuma saída (sem erro de sintaxe) nos 4 comandos.

- [ ] **Step 2: Fechar a sessão do navegador e logar de novo do zero**

Navegar para `index.html` numa aba nova (sem estado residual), logar como admin, confirmar que a
tela de Demandas abre normalmente e o console não tem nenhum erro novo — isso garante que a
ordem de carregamento dos `<script>` (api.js antes de setores.js/lixeira.js, ambos depois de
script.js) está correta e que remover os dois blocos de `script.js` não deixou nenhuma referência
pendente em outra parte do arquivo.

- [ ] **Step 3: Rodar a suíte de backend para garantir que nada do lado do servidor foi tocado**

```bash
cd ../api
composer test
php tests/matriz-de-acesso.php
```

Esperado: `OK (31 tests, ...)` e `Resultado: 19 OK, 0 falha(s)` — este plano só tocou frontend,
então estes resultados devem ser idênticos aos de antes da onda.
