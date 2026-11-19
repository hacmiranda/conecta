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
                <p><strong>Criado:</strong> ${dataDoBanco(setor.criado_em).toLocaleDateString('pt-BR')}</p>
                ${Number(setor.setor_coordenacao) === 1 ? '<p><span class="selo-setor-coordenacao">Setor de coordenação</span></p>' : ''}
            </div>
            <div class="usuario-acoes">
                ${ehAdmin ? `
                    <button type="button" class="btn-editar" data-acao-setor="coordenacao" data-id="${Number(setor.id)}"
                        data-valor="${Number(setor.setor_coordenacao) === 1 ? '0' : '1'}">
                        ${Number(setor.setor_coordenacao) === 1 ? 'Desmarcar coordenação' : 'Marcar como coordenação'}
                    </button>` : ''}
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

        ${ehAdmin ? `<p class="aviso-setor-coordenacao">
            <strong>Setor de coordenação:</strong> setor em que só o(a) coordenador(a) trabalha, sem relatório de
            atividades nem pesquisa de satisfação. Ele não aparece no seletor de setor do cabeçalho nem no Cruzamento de Dados.
        </p>` : ''}

        <div class="usuarios-grid" id="lista-setores">${cards}</div>
    `;

    // Ação "setor de coordenação" por ouvinte delegado (sem onclick no HTML montado)
    const lista = document.getElementById('lista-setores');
    if (lista) {
        lista.addEventListener('click', (e) => {
            const botao = e.target.closest('[data-acao-setor="coordenacao"]');
            if (botao) alternarSetorCoordenacao(Number(botao.dataset.id), botao.dataset.valor === '1');
        });
    }
}

async function alternarSetorCoordenacao(setorId, marcar) {
    try {
        await Api.put(`setores.php/${setorId}`, { setor_coordenacao: marcar });
        mostrarToast('Sucesso', marcar ? 'Setor marcado como de coordenação.' : 'Setor desmarcado.', 'success');
        carregarSetores();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar setor');
    }
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
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao criar setor');
    }
}

function editarSetor(setorId, nomeAtual) {
    document.getElementById('modal-prompt-message').innerHTML = `
        <div class="form-group">
            <label for="edit-nome-setor">Nome do setor:</label>
            <input type="text" id="edit-nome-setor" value="${sanitizeHTML(nomeAtual)}" required>
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
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar setor');
    }
}

async function alternarAtivoSetor(setorId, ativo) {
    try {
        await Api.put(`setores.php/${setorId}`, { ativo });
        mostrarToast('Sucesso', ativo ? 'Setor ativado!' : 'Setor desativado!', 'success');
        carregarSetores();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar setor');
    }
}
