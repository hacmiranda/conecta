# Onda 2 — extração de Usuários — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mover o domínio Usuários de `frontend/script.js` para `frontend/usuarios.js`, migrando
suas chamadas de rede para `Api.*`, sem alterar nenhum comportamento visível.

**Architecture:** Mesma mecânica da Onda 1 (já aprovada e em produção): um novo `<script>`
global carregado depois de `script.js` e de `api.js`, funções com o mesmo nome/assinatura de
antes (nenhum `onclick="..."` muda). Decisão confirmada em 21/10/2026: a limpeza de
`onclick`/estilo inline de uma skill genérica de padrões não se aplica a esta divisão —
continua fora de escopo, documentado como item de médio prazo (S16/A8).

**Tech Stack:** Vanilla JS (`async/await`, `fetch` via `Api`), sem framework, sem bundler.
Validação manual no navegador via Playwright (o projeto não tem test runner de frontend).

**Spec:** `docs/superpowers/specs/2026-10-15-frontend-api-e-divisao-script-design.md`
(mecânica do corte, válida para toda onda) + lição da revisão da Onda 1, registrada abaixo.

## Lição da Onda 1 (aplicar desde o primeiro rascunho, não só depois da revisão)

A revisão da Onda 1 encontrou (e já foi corrigido lá) que `mostrarAlert('Erro', e.message ||
'texto padrão')` nunca cai no texto padrão, porque `Api.request` sempre preenche `e.message`
(com o erro do servidor ou com `` `Erro ${status}` ``). Nesta onda, todo `catch` já nasce com
`mostrarAlert('Erro', (e.dados && e.dados.error) || 'texto padrão')` — não é uma correção
posterior, é como os blocos abaixo já estão escritos.

## Global Constraints

- Nenhum `onclick="..."` no HTML (estático ou gerado em runtime) muda de nome ou assinatura.
- Nenhuma função muda de comportamento visível (mesma mensagem de sucesso/erro, mesmo toast/alert).
- Toda nova chamada de rede do domínio movido usa `Api.get/post/put/delete` — nenhuma chamada
  de rede nova em `XMLHttpRequest`.
- Erros mostrados ao usuário usam `(e.dados && e.dados.error) || 'texto padrão em português'`
  — nunca `e.message` sozinho (ver lição acima).
- Funções movidas continuam em escopo global.
- `frontend/usuarios.js` entra em `index.html` depois de `script.js`, `api.js`, `setores.js` e
  `lixeira.js` (ordem entre os três últimos não importa — nenhum depende dos outros — mas todos
  precisam vir depois de `api.js`); a versão (`?v=`) de todo arquivo tocado sobe.
- Um commit por tarefa.

## Review Focus

- Gestor só vê/edita usuários do próprio setor e só pode cadastrar/editar com papel "operador"
  (`usuarios.php:78,218,239,342` já fazem essa regra no servidor — a tela precisa continuar
  escondendo o que o servidor recusaria, não só confiar no 403).
- Criar usuário com e-mail ou nome de usuário já existente (400 `{"error":"Email já existe"}` /
  `"Nome de usuário já existe"`): a mensagem do servidor precisa aparecer, não um texto genérico.
- Desativar um usuário que é responsável por demandas abertas: o aviso de confirmação mostra a
  contagem certa (via `usuarios.php?demandas_abertas=`) e, depois de confirmar, o toast mostra
  quantas demandas voltaram para o setor (`resposta.demandas_devolvidas`).
- "Redefinir senha" usa um `window.submeterRedefinicaoSenha` criado e apagado dinamicamente —
  confirmar que isso continua funcionando depois de `redefinirSenhaUsuario` sair de `script.js`
  (a função é anexada em `window`, então em qual arquivo o código mora não deveria importar,
  mas é exatamente esse tipo de suposição que a Onda 1 mostrou valer a pena testar).
- Sessão inválida/expirada numa ação de Usuários (criar, editar, desativar, redefinir senha):
  o monitor global de 401/403 precisa continuar agindo, vindo de `Api.request`.

---

## Contexto para quem for implementar

Em `frontend/script.js`, procure pelo texto exato dos comentários (os números de linha abaixo
são de 21/10/2026 e podem já ter se deslocado um pouco):

- `// === GERENCIAMENTO DE USUÁRIOS ===` (linha 2893) até o fim de `executarExclusaoUsuario`
  (linha 3349, imediatamente antes do comentário `// Excluir ticket`, que **não** faz parte
  deste bloco — pertence ao domínio Demandas). 11 funções: `mostrarUsuarios`, `carregarUsuarios`,
  `mostrarListaUsuarios`, `sugerirUsername`, `criarUsuario`, `validarSenhaNova`, `editarUsuario`,
  `mostrarFormularioEdicaoUsuario`, `executarEdicaoUsuario`, `excluirUsuario`,
  `executarExclusaoUsuario`.
- Três funções soltas, bem mais abaixo no arquivo, fora do bloco principal: `desativarUsuario`
  (linha 7840), `redefinirSenhaUsuario` (linha 7880), `ativarUsuario` (linha 7952) — logo depois
  de `verificarPrimeiraVisita` e antes do comentário `// Validações antes de adicionar
  comentário`. **Fazem parte do domínio Usuários** apesar de distantes — confirme pelo nome da
  função, não pela proximidade de outras.
- `toggleFormUsuario` (linha 9272, entre `baixarCSV` e `toggleFormCategoria`) — abre/fecha o
  formulário de novo usuário na tela de Usuários. `toggleFormCategoria`, logo depois, **não**
  faz parte desta onda (é do domínio Categorias, de uma onda futura).

Todas essas funções só dependem de coisas que continuam em `script.js`: `getAuthHeaders`/`Api`,
`sanitizeHTML`, `argJs`, `mostrarToast`, `mostrarAlert`, `mostrarConfirm`, `modalCallback`,
`window.APP_CONFIG`. Para rodar localmente: `http://localhost/conecta/frontend/index.html`,
XAMPP em `C:\xampp\htdocs\conecta`.

---

### Task 1: Extrair o domínio Usuários para `frontend/usuarios.js`

**Files:**
- Create: `frontend/usuarios.js`
- Modify: `frontend/script.js` (remover os 3 trechos listados no contexto acima)
- Modify: `frontend/index.html` (registrar `usuarios.js`, subir a versão de `script.js`)

**Interfaces:**
- Consumes: `Api` (de `api.js`); `sanitizeHTML`, `argJs`, `mostrarToast`, `mostrarAlert`,
  `mostrarConfirm`, `modalCallback`, `carregarUsuarios` (chamada entre as próprias funções deste
  arquivo) — todos inalterados.
- Produces: as mesmas 15 funções globais de antes, mesmos nomes e assinaturas: `mostrarUsuarios()`,
  `carregarUsuarios()`, `mostrarListaUsuarios(usuarios, setores)`, `sugerirUsername()`,
  `criarUsuario(event)`, `validarSenhaNova(senha)`, `editarUsuario(usuarioId)`,
  `mostrarFormularioEdicaoUsuario(usuario, setores)`,
  `executarEdicaoUsuario(usuarioId, nome, email, tipo, username, cargo, setor_id)`,
  `excluirUsuario(usuarioId)`, `executarExclusaoUsuario(usuarioId)`, `desativarUsuario(usuarioId)`,
  `redefinirSenhaUsuario(usuarioId, nome)`, `ativarUsuario(usuarioId)`, `toggleFormUsuario()`.

- [ ] **Step 1: Criar `frontend/usuarios.js`**

```js
/**
 * Gerenciamento de Usuários (equipe interna: admin, gestor, operador — nunca "cliente",
 * que não tem acesso ao sistema). Extraído de script.js na Fase 12 (onda 2 da divisão por
 * domínio).
 */

function mostrarUsuarios() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active'));
    const _bbtn_usuarios = document.getElementById('btn-usuarios'); if (_bbtn_usuarios) _bbtn_usuarios.classList.add('active');

    localStorage.setItem('cd_subSecaoAtiva', 'usuarios');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F465) + ' Gerenciar Usu\u00e1rios';

    const content = document.getElementById('config-content');
    if (content) {
        content.style.animation = 'none';
        setTimeout(() => {
            content.style.animation = '';
            carregarUsuarios();
        }, 100);
    } else {
        setTimeout(() => mostrarUsuarios(), 200);
    }
}

async function carregarUsuarios() {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';

    try {
        const usuarios = await Api.get('usuarios.php');
        // Admin precisa da lista de setores para poder escolher em qual setor
        // cadastrar/mover um usuário. Gestor não precisa (fica travado no próprio setor).
        const setores = ehAdmin ? await Api.get('setores.php').catch(() => []) : [];
        mostrarListaUsuarios(usuarios, setores);
    } catch (e) {
        document.getElementById('config-content').innerHTML = '<p>Erro ao carregar usuários.</p>';
    }
}

function mostrarListaUsuarios(usuarios, setores) {
    const content = document.getElementById('config-content');
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';
    setores = setores || [];

    // Separar por papel (tratar vazio como usuário comum, por segurança de exibição)
    const admins = usuarios.filter(u => u.tipo === 'admin');
    const gestores = usuarios.filter(u => u.tipo === 'gestor');
    const usuariosComuns = usuarios.filter(u => u.tipo === 'operador' || u.tipo === '' || !u.tipo);
    const outrosTipos = usuarios.filter(u => u.tipo && !['admin', 'gestor', 'operador', ''].includes(u.tipo));

    function rotuloTipo(tipo) {
        if (tipo === 'admin') return 'Administrador';
        if (tipo === 'gestor') return 'Gestor';
        if (tipo === 'operador' || !tipo) return 'Operador';
        if (tipo === 'cliente') return 'Solicitante externo (sem acesso ao sistema)';
        return tipo;
    }

    // Admin redefine a senha de qualquer membro da equipe; gestor, só dos operadores do setor
    function podeRedefinirSenha(usuario) {
        if (Number(usuario.id) === Number(usuarioLogado.id) || usuario.tipo === 'cliente') return false;
        return ehAdmin || usuario.tipo === 'operador';
    }

    function gerarCardUsuario(usuario) {
        const tipoExibicao = rotuloTipo(usuario.tipo);
        const statusAtivo = usuario.ativo === 1 || usuario.ativo === '1';

        return `
            <div class="usuario-card ${!statusAtivo ? 'usuario-inativo' : ''}">
                <div class="usuario-info">
                    <h4>${sanitizeHTML(usuario.nome)} ${!statusAtivo ? '<span style="color: #dc3545;">(Inativo)</span>' : ''}</h4>
                    <p>${sanitizeHTML(usuario.email)}${usuario.username ? ` · <code>${sanitizeHTML(usuario.username)}</code>` : ''}</p>
                    <p><strong>Papel:</strong> ${tipoExibicao}${usuario.cargo ? ` · <strong>Cargo:</strong> ${sanitizeHTML(usuario.cargo)}` : ''}${usuario.setor_nome ? ` · <strong>Setor:</strong> ${usuario.setor_nome}` : ''}</p>
                    <p><strong>Criado:</strong> ${new Date(usuario.criado_em).toLocaleDateString('pt-BR')}</p>
                </div>
                <div class="usuario-acoes">
                    <button class="btn-${statusAtivo ? 'desativar' : 'ativar'}" onclick="${statusAtivo ? 'desativar' : 'ativar'}Usuario(${usuario.id})">
                        ${statusAtivo ? 'Desativar' : 'Ativar'}
                    </button>
                    <button class="btn-editar" onclick="editarUsuario(${usuario.id})">Editar</button>
                    ${podeRedefinirSenha(usuario) ? `<button class="btn-redefinir-senha" onclick="redefinirSenhaUsuario(${Number(usuario.id)}, decodeURIComponent('${argJs(usuario.nome)}'))">Redefinir senha</button>` : ''}
                    ${ehAdmin ? `<button class="btn-excluir" onclick="excluirUsuario(${usuario.id})">Excluir</button>` : ''}
                </div>
            </div>
        `;
    }

    let secoes = '';

    if (admins.length > 0) {
        secoes += `<h3>👑 Administradores (${admins.length})</h3><div class="usuarios-grid">${admins.map(gerarCardUsuario).join('')}</div>`;
    }
    if (gestores.length > 0) {
        secoes += `<h3>🧭 Gestores (${gestores.length})</h3><div class="usuarios-grid">${gestores.map(gerarCardUsuario).join('')}</div>`;
    }
    if (usuariosComuns.length > 0) {
        secoes += `<h3>👥 Operadores (${usuariosComuns.length})</h3><div class="usuarios-grid">${usuariosComuns.map(gerarCardUsuario).join('')}</div>`;
    }
    if (outrosTipos.length > 0) {
        secoes += `<h3>✉️ Solicitantes externos (${outrosTipos.length})</h3><div class="usuarios-grid">${outrosTipos.map(gerarCardUsuario).join('')}</div>`;
    }

    // Opções de papel: gestor só pode cadastrar "usuario"; admin pode tudo
    const opcoesTipo = ehAdmin
        ? `<option value="operador">Operador</option>
           <option value="gestor">Gestor</option>
           <option value="admin">Administrador</option>`
        : `<option value="operador">Operador</option>`;

    // Setor só é escolhido pelo admin — gestor fica travado no próprio setor no backend
    const campoSetor = ehAdmin ? `
                    <div class="form-group">
                        <label for="setor-usuario">Setor:</label>
                        <select id="setor-usuario">
                            <option value="">Nenhum (obrigatório para Operador/Gestor)</option>
                            ${setores.map(s => `<option value="${s.id}">${sanitizeHTML(s.nome)}</option>`).join('')}
                        </select>
                    </div>` : '';

    content.innerHTML = `
        <button onclick="toggleFormUsuario()" class="btn-primary" style="margin-bottom: 1rem;">
            ➕ Adicionar Novo Usuário
        </button>

        <div class="form-usuario" id="form-novo-usuario" style="display: none; overflow: hidden; max-height: 0; transition: max-height 0.3s ease-out;">
            <h3>Adicionar Novo Usuário</h3>
            <form id="form-usuario" onsubmit="criarUsuario(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="nome-usuario">Nome completo:</label>
                        <input type="text" id="nome-usuario" required oninput="sugerirUsername()">
                    </div>
                    <div class="form-group">
                        <label for="username-usuario">Nome de usuário:</label>
                        <input type="text" id="username-usuario" placeholder="gerado automaticamente (nome.sobrenome)"
                            oninput="this.dataset.editadoManualmente='true'">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="email-usuario">Email:</label>
                        <input type="email" id="email-usuario" required>
                    </div>
                    <div class="form-group">
                        <label for="cargo-usuario">Cargo/Função:</label>
                        <input type="text" id="cargo-usuario">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="tipo-usuario">Perfil:</label>
                        <select id="tipo-usuario">
                            ${opcoesTipo}
                        </select>
                    </div>
                    ${campoSetor}
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="senha-usuario">Senha inicial:</label>
                        <input type="password" id="senha-usuario" required autocomplete="new-password"
                            aria-describedby="senha-usuario-ajuda">
                    </div>
                    <div class="form-group">
                        <label for="senha-confirmacao-usuario">Confirmar senha:</label>
                        <input type="password" id="senha-confirmacao-usuario" required autocomplete="new-password">
                    </div>
                </div>
                <p id="senha-usuario-ajuda" class="ajuda-senha-usuario">
                    A senha deve ter no mínimo 10 caracteres, com letra maiúscula, minúscula, número e caractere especial.
                    Informe a senha ao usuário por um canal seguro; ele pode alterá-la depois em <em>Alterar senha</em>.
                </p>
                <button type="submit" class="btn-primary">Adicionar Usuário</button>
            </form>
        </div>

        ${secoes}
    `;
}

// Sugere um username (nome.sobrenome) a partir do nome completo digitado, sem sobrescrever edição manual
function sugerirUsername() {
    const campoUsername = document.getElementById('username-usuario');
    if (!campoUsername || campoUsername.dataset.editadoManualmente === 'true') return;

    const nome = document.getElementById('nome-usuario').value.trim();
    const partes = nome.normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/[^a-z\s]/g, '').trim().split(/\s+/).filter(Boolean);

    if (partes.length === 0) {
        campoUsername.value = '';
    } else if (partes.length === 1) {
        campoUsername.value = partes[0];
    } else {
        campoUsername.value = `${partes[0]}.${partes[partes.length - 1]}`;
    }
}

async function criarUsuario(event) {
    event.preventDefault();

    const nome = document.getElementById('nome-usuario').value;
    const username = document.getElementById('username-usuario').value.trim();
    const email = document.getElementById('email-usuario').value;
    const cargo = document.getElementById('cargo-usuario').value;
    const tipo = document.getElementById('tipo-usuario').value;
    const campoSetor = document.getElementById('setor-usuario');
    const setor_id = campoSetor ? campoSetor.value : null;
    const senha = document.getElementById('senha-usuario').value;
    const senhaConfirmacao = document.getElementById('senha-confirmacao-usuario').value;

    if ((tipo === 'operador' || tipo === 'gestor') && campoSetor && !setor_id) {
        mostrarAlert('Erro', 'Selecione um setor para os perfis Operador/Gestor.');
        return;
    }

    if (senha !== senhaConfirmacao) {
        mostrarAlert('Erro', 'As senhas não coincidem.');
        return;
    }

    const erroSenha = validarSenhaNova(senha);
    if (erroSenha) {
        mostrarAlert('Senha inválida', erroSenha);
        return;
    }

    try {
        await Api.post('usuarios.php', { nome, username, email, cargo, tipo, setor_id, senha });
        mostrarToast('Sucesso', 'Usuário criado com sucesso!', 'success');
        document.getElementById('form-usuario').reset();
        carregarUsuarios();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao criar usuário');
    }
}

// Regras de senha (as mesmas do servidor e da tela "Alterar senha"); retorna a mensagem ou null
function validarSenhaNova(senha) {
    if (senha.length < 10) return 'A senha deve ter no mínimo 10 caracteres.';
    if (!/[A-Z]/.test(senha)) return 'A senha deve conter ao menos 1 letra maiúscula.';
    if (!/[a-z]/.test(senha)) return 'A senha deve conter ao menos 1 letra minúscula.';
    if (!/[0-9]/.test(senha)) return 'A senha deve conter ao menos 1 número.';
    if (!/[!@#$%^&*(),.?":{}|<>]/.test(senha)) return 'A senha deve conter ao menos 1 caractere especial (! @ # $ % ^ & * ( ) , . ? " : { } | < >).';
    return null;
}

async function editarUsuario(usuarioId) {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';

    try {
        const usuarios = await Api.get('usuarios.php');
        const usuario = usuarios.find(u => u.id === usuarioId);
        if (!usuario) return;

        const setores = ehAdmin ? await Api.get('setores.php').catch(() => []) : [];
        mostrarFormularioEdicaoUsuario(usuario, setores);
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao carregar usuário');
    }
}

function mostrarFormularioEdicaoUsuario(usuario, setores) {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';
    setores = setores || [];

    const opcoesTipo = ehAdmin
        ? `<option value="operador" ${usuario.tipo === 'operador' ? 'selected' : ''}>Operador</option>
           <option value="gestor" ${usuario.tipo === 'gestor' ? 'selected' : ''}>Gestor</option>
           <option value="admin" ${usuario.tipo === 'admin' ? 'selected' : ''}>Administrador</option>`
        : `<option value="operador" selected>Operador</option>`;

    const campoSetor = ehAdmin ? `
        <div class="form-group">
            <label for="edit-setor">Setor:</label>
            <select id="edit-setor">
                <option value="">Nenhum (obrigatório para Operador/Gestor)</option>
                ${setores.map(s => `<option value="${s.id}" ${String(usuario.setor_id) === String(s.id) ? 'selected' : ''}>${sanitizeHTML(s.nome)}</option>`).join('')}
            </select>
        </div>` : '';

    const content = `
        <div class="form-group">
            <label for="edit-nome">Nome:</label>
            <input type="text" id="edit-nome" value="${sanitizeHTML(usuario.nome)}" required>
        </div>
        <div class="form-group">
            <label for="edit-username">Nome de usuário:</label>
            <input type="text" id="edit-username" value="${usuario.username || ''}">
        </div>
        <div class="form-group">
            <label for="edit-cargo">Cargo/Função:</label>
            <input type="text" id="edit-cargo" value="${usuario.cargo || ''}">
        </div>
        <div class="form-group">
            <label for="edit-tipo">Perfil:</label>
            <select id="edit-tipo" ${ehAdmin ? '' : 'disabled'}>
                ${opcoesTipo}
            </select>
        </div>
        ${campoSetor}
    `;

    document.getElementById('modal-prompt-message').innerHTML = content;
    document.getElementById('modal-prompt-title').textContent = 'Editar Usuário';
    document.getElementById('modal-prompt-input').style.display = 'none';

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        if (confirmado) {
            const nome = document.getElementById('edit-nome').value;
            const username = document.getElementById('edit-username').value.trim();
            const cargo = document.getElementById('edit-cargo').value;
            const tipo = document.getElementById('edit-tipo').value;
            const campoSetorEl = document.getElementById('edit-setor');
            const setor_id = campoSetorEl ? campoSetorEl.value : null;

            if ((tipo === 'operador' || tipo === 'gestor') && campoSetorEl && !setor_id) {
                mostrarAlert('Erro', 'Selecione um setor para os perfis Operador/Gestor.');
                return;
            }

            if (nome && tipo) {
                executarEdicaoUsuario(usuario.id, nome, usuario.email, tipo, username, cargo, setor_id);
            }
        }

        // Restaurar modal
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
    };
}

async function executarEdicaoUsuario(usuarioId, nome, email, tipo, username, cargo, setor_id) {
    try {
        const resposta = await Api.put(`usuarios.php/${usuarioId}`, { nome, email, tipo, username, cargo, setor_id });
        carregarUsuarios();
        const devolvidas = (resposta && resposta.demandas_devolvidas) || 0;
        mostrarToast('Sucesso', devolvidas > 0
            ? `Usuário atualizado. ${devolvidas} demanda(s) dele voltaram para o setor anterior.`
            : 'Usuário atualizado com sucesso!', 'success');
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar usuário');
    }
}

function excluirUsuario(usuarioId) {
    mostrarConfirm(
        'Excluir Usuário',
        'Tem certeza que deseja excluir este usuário?',
        function (confirmado) {
            if (!confirmado) return;
            executarExclusaoUsuario(usuarioId);
        }
    );
}

async function executarExclusaoUsuario(usuarioId) {
    try {
        await Api.delete(`usuarios.php?id=${usuarioId}`);
        mostrarToast('Sucesso', 'Usuário excluído com sucesso!', 'success');
        carregarUsuarios();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao excluir usuário');
    }
}

function desativarUsuario(usuarioId) {
    // Primeiro descobre quantas demandas abertas ele tem, para avisar antes de confirmar
    Api.get(`usuarios.php?demandas_abertas=${Number(usuarioId)}`)
        .catch(() => ({ total: 0 }))
        .then((resposta) => {
            const total = (resposta && resposta.total) || 0;
            const aviso = total > 0
                ? `Este usuário é responsável por ${total} demanda(s) aberta(s). Ao desativá-lo, elas voltam para o setor (sem responsável) e os gestores são avisados. Ele também será desconectado. Deseja continuar?`
                : 'Tem certeza que deseja desativar este usuário? Ele será desconectado e não aparecerá mais na lista de atribuição.';

            mostrarConfirm('Desativar Usuário', aviso, async function (confirmado) {
                if (!confirmado) return;
                try {
                    const resp = await Api.put(`usuarios.php/${usuarioId}`, { ativo: false });
                    const devolvidas = (resp && resp.demandas_devolvidas) || 0;
                    mostrarToast('Sucesso', devolvidas > 0
                        ? `Usuário desativado. ${devolvidas} demanda(s) voltaram para o setor.`
                        : 'Usuário desativado com sucesso!', 'success');
                    carregarUsuarios();
                } catch (e) {
                    mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao desativar usuário');
                }
            }, true);
        });
}

// Admin/gestor define uma senha nova; o usuário é obrigado a trocá-la no próximo acesso
function redefinirSenhaUsuario(usuarioId, nome) {
    const content = `
        <form id="form-redefinir-senha" onsubmit="event.preventDefault(); window.submeterRedefinicaoSenha();">
            <p>Defina uma senha provisória para <strong>${sanitizeHTML(nome)}</strong>. Ele será desconectado e precisará trocá-la no próximo acesso.</p>
            <div class="form-group">
                <label for="redefinir-senha-nova">Nova senha:</label>
                <input type="password" id="redefinir-senha-nova" required minlength="10" autocomplete="new-password" aria-describedby="redefinir-senha-ajuda">
            </div>
            <div class="form-group">
                <label for="redefinir-senha-confirma">Confirmar senha:</label>
                <input type="password" id="redefinir-senha-confirma" required minlength="10" autocomplete="new-password">
            </div>
            <p id="redefinir-senha-ajuda" class="ajuda-senha-usuario">Mínimo de 10 caracteres, com letra maiúscula, minúscula, número e caractere especial. Informe a senha ao usuário por um canal seguro.</p>
            <div style="display:flex; justify-content:flex-end; gap: 10px; margin-top: 15px;">
                <button type="button" class="btn-voltar" onclick="promptModal(null)">Cancelar</button>
                <button type="submit" class="btn-primary">Redefinir senha</button>
            </div>
        </form>
    `;

    document.getElementById('modal-prompt-message').innerHTML = content;
    document.getElementById('modal-prompt-title').textContent = '🔑 Redefinir senha';
    document.getElementById('modal-prompt-input').style.display = 'none';
    const modalFooter = document.querySelector('#modal-prompt .modal-footer');
    if (modalFooter) modalFooter.style.display = 'none';
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');
    setTimeout(() => document.getElementById('redefinir-senha-nova')?.focus(), 100);

    const restaurarModal = () => {
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
        if (modalFooter) modalFooter.style.display = 'flex';
        delete window.submeterRedefinicaoSenha;
    };

    window.submeterRedefinicaoSenha = async function () {
        const senha = document.getElementById('redefinir-senha-nova').value;
        const confirma = document.getElementById('redefinir-senha-confirma').value;
        if (senha !== confirma) {
            mostrarToast('Atenção', 'As senhas não coincidem.', 'error');
            return;
        }
        const erro = validarSenhaNova(senha);
        if (erro) {
            mostrarToast('Senha inválida', erro, 'error');
            return;
        }

        try {
            await Api.post('usuarios.php?acao=redefinir_senha', { usuario_id: usuarioId, senha });
            promptModal(null);
            mostrarToast('Sucesso', 'Senha redefinida. O usuário deverá trocá-la no próximo acesso.', 'success');
        } catch (e) {
            mostrarToast('Erro', (e.dados && e.dados.error) || 'Erro ao redefinir a senha', 'error');
        }
    };

    modalCallback = function () {
        restaurarModal();
    };
}

async function ativarUsuario(usuarioId) {
    try {
        await Api.put(`usuarios.php/${usuarioId}`, { ativo: true });
        mostrarToast('Sucesso', 'Usuário ativado com sucesso!', 'success');
        carregarUsuarios();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao ativar usuário');
    }
}

// Toggle formulário de novo usuário
function toggleFormUsuario() {
    const form = document.getElementById('form-novo-usuario');
    const isVisible = form.style.display === 'block';

    if (isVisible) {
        form.style.maxHeight = '0';
        setTimeout(() => form.style.display = 'none', 300);
    } else {
        form.style.display = 'block';
        setTimeout(() => form.style.maxHeight = form.scrollHeight + 'px', 10);
    }
}
```

- [ ] **Step 2: Remover os 3 trechos de `script.js`**

Remova, nesta ordem:
1. Do comentário `// === GERENCIAMENTO DE USUÁRIOS ===` até o fim de `executarExclusaoUsuario`
   (pare antes do comentário `// Excluir ticket`).
2. As funções `desativarUsuario`, `redefinirSenhaUsuario` e `ativarUsuario` (do comentário
   `// Desativar usuário` até o fim de `ativarUsuario`, pare antes do comentário em branco
   seguido de `// Validações antes de adicionar comentário`).
3. A função `toggleFormUsuario` (do comentário `// Toggle formulário de novo usuário` até seu
   fechamento, pare antes do comentário `// Toggle formulário de nova categoria`).

Confira com `grep -n "GERENCIAMENTO DE USUÁRIOS\|^function desativarUsuario\|^function toggleFormUsuario\|^function toggleFormCategoria" frontend/script.js` — depois da remoção, só deve
sobrar `toggleFormCategoria`.

- [ ] **Step 3: Registrar `usuarios.js` em `index.html` e subir a versão de `script.js`**

```html
    <script src="script.js?v=4.13"></script>
    <script src="api.js?v=1"></script>
    <script src="setores.js?v=1"></script>
    <script src="lixeira.js?v=1"></script>
    <script src="usuarios.js?v=1"></script>
```

(se a versão atual de `script.js` em `index.html` não for `4.12` neste ponto, use a versão
atual + 1.)

- [ ] **Step 4: Testar no navegador (Playwright)**

Login como **admin**:

1. Abrir "Gerenciar Usuários": a lista carrega sem erro no console, separada por papel
   (Administradores/Gestores/Operadores/Solicitantes externos).
2. Criar um usuário de teste (ex. operador, setor existente, senha válida como
   `Zz@Teste12345`): toast de sucesso, aparece na lista.
3. Tentar criar outro usuário com o **mesmo e-mail**: `mostrarAlert` com "Email já existe" (a
   mensagem do servidor, não um texto genérico).
4. Editar o usuário de teste (nome, cargo): toast de sucesso, dados atualizados na lista.
5. Desativar o usuário de teste: o aviso de confirmação aparece (sem demandas abertas, já que é
   novo) e, ao confirmar, toast de sucesso e card marcado "(Inativo)".
6. Ativar de volta: toast de sucesso, card volta ao normal.
7. "Redefinir senha" no usuário de teste: preencher e confirmar uma senha nova válida — toast de
   sucesso. Preencher senhas diferentes uma da outra — aviso "As senhas não coincidem" sem
   fechar o modal.
8. Excluir o usuário de teste: toast de sucesso, sai da lista.

Login como **gestor**: "Gerenciar Usuários" mostra só os usuários do próprio setor; o formulário
de novo usuário só oferece o papel "Operador" e não mostra o campo Setor.

Remova pelo banco (`DELETE FROM usuarios WHERE ...`) qualquer usuário de teste que não tenha
sido excluído pela própria tela ao final.

- [ ] **Step 5: Commit**

```bash
git add frontend/usuarios.js frontend/script.js frontend/index.html
git commit -m "feat: extrai Usuarios de script.js para usuarios.js, usando api.js (Fase 12, onda 2)"
```

---

### Task 2: Regressão rápida da onda

**Files:** nenhum arquivo novo — só validação.

- [ ] **Step 1: Conferir que `script.js` encolheu e nada ficou duplicado**

```bash
cd frontend
grep -c "^function " script.js   # era 255 após a Onda 1; esperar 255 - 15 = 240
grep -n "^function mostrarUsuarios\|^function carregarUsuarios\|^function mostrarListaUsuarios\|^function sugerirUsername\|^function criarUsuario\b\|^function validarSenhaNova\|^function editarUsuario\|^function mostrarFormularioEdicaoUsuario\|^function executarEdicaoUsuario\|^function excluirUsuario\b\|^function executarExclusaoUsuario\|^function desativarUsuario\|^function redefinirSenhaUsuario\|^function ativarUsuario\|^function toggleFormUsuario\b" script.js
```

Esperado: o segundo comando não devolve nenhuma linha.

```bash
node --check script.js
node --check usuarios.js
```

Esperado: nenhuma saída (sem erro de sintaxe).

- [ ] **Step 2: Login do zero, console limpo**

Limpar o `localStorage`, logar de novo (admin), abrir Demandas e Usuários — console sem erro
novo.

- [ ] **Step 3: Rodar a suíte de backend**

```bash
cd ../api
composer test
php tests/matriz-de-acesso.php
```

Esperado: `OK (31 tests, ...)` e `Resultado: 19 OK, 0 falha(s)` — idêntico a antes desta onda
(este plano só tocou frontend).
