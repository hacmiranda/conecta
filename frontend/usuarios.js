/**
 * Gerenciamento de Usuários (equipe interna: admin, gestor, operador — nunca "cliente",
 * que não tem acesso ao sistema). Extraído de script.js na Fase 12 (onda 2 da divisão por
 * domínio).
 */

function mostrarUsuarios() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active'));
    const _bbtn_usuarios = document.getElementById('btn-usuarios'); if (_bbtn_usuarios) _bbtn_usuarios.classList.add('active');

    localStorage.setItem('cd_subSecaoAtiva', 'usuarios');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F465) + ' Gerenciar Usuários';

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
