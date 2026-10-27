// === SISTEMA DE MACROS (RESPOSTAS RÁPIDAS) ===

let todasMacros = [];

function abrirModalMacros() {
    fecharAvatarDropdown();
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-macros').classList.add('show');
    carregarMacros();
}

function fecharModalMacros() {
    document.getElementById('modal-overlay').classList.remove('show');
    document.getElementById('modal-macros').classList.remove('show');
    fecharFormularioMacro();
}


function carregarMacros() {
    const lista = document.getElementById('lista-macros-container');
    lista.innerHTML = '<p>Carregando...</p>';

    // Administrador: macros do setor escolhido no cabeçalho (mais as de todos os setores)
    Api.get(typeof urlComSetor === 'function' ? urlComSetor('macros.php') : 'macros.php').then(macros => {
        todasMacros = macros || [];
        renderizarListaMacros();
    }).catch(e => {
        lista.innerHTML = `<p role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar as respostas rápidas.'))}</p>`;
    });
}

function renderizarListaMacros() {
    const lista = document.getElementById('lista-macros-container');
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario'));

    if (todasMacros.length === 0) {
        lista.innerHTML = '<p>Nenhuma macro encontrada. Crie sua primeira resposta rápida!</p>';
        return;
    }

    let html = '';
    todasMacros.forEach(macro => {
        // Edita/exclui: o autor, o gestor do setor da macro ou o administrador
        const podeEditar = usuarioLogado.tipo === 'admin' || macro.usuario_id == usuarioLogado.id
            || (usuarioLogado.tipo === 'gestor' && macro.setor_id && macro.setor_id == usuarioLogado.setor_id);

        html += `
            <div style="background: #fff; padding: 1rem; border-radius: 8px; margin-bottom: 0.5rem; border: 1px solid #ddd; display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1; padding-right: 1rem;">
                    <h4 style="margin: 0 0 0.5rem 0; color: var(--azul-primario);">${sanitizeHTML(macro.titulo)} <small style="font-weight: normal; color: #666;">· ${macro.setor_nome ? sanitizeHTML(macro.setor_nome) : 'Todos os setores'}</small></h4>
                    <div style="font-size: 0.9rem; color: #555; max-height: 40px; overflow: hidden; text-overflow: ellipsis;">
                        ${sanitizeHTML(macro.texto.replace(/<[^>]*>?/gm, '').substring(0, 100))}...
                    </div>
                </div>
                ${podeEditar ? `
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn-small" style="background:#f39c12; color:#fff;" onclick="editarMacro(${macro.id})">✏️ Editar</button>
                    <button class="btn-small" style="background:#e74c3c; color:#fff;" onclick="excluirMacro(${macro.id})">🗑️ Excluir</button>
                </div>
                ` : ''}
            </div>
        `;
    });
    lista.innerHTML = html;
}

function abrirFormularioMacro() {
    document.getElementById('form-macro-container').style.display = 'block';
    document.getElementById('macro-id').value = '';
    document.getElementById('macro-titulo').value = '';

    if (!window.quillMacro) {
        window.quillMacro = new Quill('#editor-macro', {
            theme: 'snow',
            placeholder: 'Escreva a resposta padrão aqui...',
            modules: {
                // Sem seletor de tamanho: toda resposta usa a mesma fonte e tamanho (ver --fonte-resposta)
                toolbar: [
                    ['bold', 'italic', 'underline', 'strike'],
                    ['blockquote', 'code-block'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    [{ 'color': [] }, { 'background': [] }],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        });
        habilitarImagensNoEditor(window.quillMacro);
    } else {
        window.quillMacro.root.innerHTML = '';
    }
}

function fecharFormularioMacro() {
    document.getElementById('form-macro-container').style.display = 'none';
    if (window.quillMacro) window.quillMacro.root.innerHTML = '';
    document.getElementById('macro-titulo').value = '';
    document.getElementById('macro-id').value = '';
}

function editarMacro(id) {
    const macro = todasMacros.find(m => m.id == id);
    if (!macro) return;

    abrirFormularioMacro();
    document.getElementById('macro-id').value = macro.id;
    document.getElementById('macro-titulo').value = macro.titulo;
    setTimeout(() => {
        if (window.quillMacro) window.quillMacro.root.innerHTML = htmlSeguro(macro.texto);
    }, 100);
}

function salvarMacro() {
    const id = document.getElementById('macro-id').value;
    const titulo = document.getElementById('macro-titulo').value.trim();
    const texto = window.quillMacro ? window.quillMacro.root.innerHTML : '';

    if (!titulo || !texto || texto === '<p><br></p>') {
        mostrarToast('Erro', 'Título e texto são obrigatórios!', 'error');
        return;
    }

    const payload = { titulo, texto };
    // Administrador: a macro nasce no setor escolhido no cabeçalho (sem setor = vale para todos)
    const ctx = window.CabecalhoSetor ? window.CabecalhoSetor.obterSetorContexto() : null;
    if (!id && ctx) payload.setor_id = ctx;
    const envio = id ? Api.put(`macros.php?id=${encodeURIComponent(id)}`, payload) : Api.post('macros.php', payload);
    envio.then(() => {
        mostrarToast('Sucesso', 'Resposta rápida salva!', 'success');
        fecharFormularioMacro();
        carregarMacros();
        // Também recarregar o seletor no ticket
        carregarSeletorMacros();
    }).catch(e => mostrarToast('Erro', mensagemDeErro(e, 'Erro ao salvar a resposta rápida'), 'error'));
}

function excluirMacro(id) {
    // Modal do sistema (o confirm() nativo não segue o tema nem o padrão de acessibilidade dos modais)
    mostrarConfirm('Excluir resposta rápida', 'Tem certeza que deseja excluir esta resposta rápida?', function (confirmado) {
        if (confirmado) executarExclusaoMacro(id);
    }, true);
}

function executarExclusaoMacro(id) {
    Api.delete(`macros.php?id=${encodeURIComponent(id)}`).then(() => {
        mostrarToast('Sucesso', 'Resposta rápida excluída!', 'success');
        carregarMacros();
        carregarSeletorMacros();
    }).catch(e => mostrarToast('Erro', mensagemDeErro(e, 'Erro ao excluir a resposta rápida'), 'error'));
}

// === INTEGRAÇÃO COM TICKET DETAILS ===

function carregarSeletorMacros() {
    const seletor = document.getElementById('seletor-macros');
    if (!seletor) return;

    seletor.innerHTML = `
        <option value="">⚡ Usar Resposta Rápida (Macro)...</option>
        <option value="__gerenciar__">⚙️ Gerenciar Respostas Rápidas...</option>
    `;

    // No atendimento, as macros são as do setor da demanda aberta
    const setorDemanda = typeof setorDaDemandaAtual === 'function' ? setorDaDemandaAtual() : null;
    Api.get('macros.php' + (setorDemanda ? `?setor_id=${encodeURIComponent(setorDemanda)}` : '')).then(macros => {
        todasMacros = macros || []; // Atualiza a global também
        todasMacros.forEach(m => {
            const opcao = document.createElement('option');
            opcao.value = m.id;
            opcao.textContent = m.titulo;
            seletor.appendChild(opcao);
        });
    }).catch(() => { /* sem respostas rápidas: o seletor fica só com as opções fixas */ });
}

function aplicarMacroTicket(macroId) {
    if (!macroId) return;

    if (macroId === '__gerenciar__') {
        document.getElementById('seletor-macros').value = '';
        abrirModalMacros();
        return;
    }

    const macro = todasMacros.find(m => m.id == macroId);
    if (!macro || !window.quillComentario) return;

    const conteudoAtual = window.quillComentario.root.innerHTML;
    const conteudoLimpo = conteudoAtual === '<p><br></p>' ? '' : conteudoAtual;
    const novoConteudo = conteudoLimpo + htmlSeguro(macro.texto);

    // Usar API do Quill para garantir sincronização do modelo interno
    window.quillComentario.clipboard.dangerouslyPasteHTML(novoConteudo);

    // Resetar o seletor
    document.getElementById('seletor-macros').value = '';
}
