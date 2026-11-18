/**
 * Gerenciamento de Usuários, em duas abas: "Equipe" (admin, coordenador geral, supervisor,
 * coordenador, gestor e operador — acessam o sistema) e "Solicitantes" (tipo "cliente", só visível para admin;
 * nunca têm acesso ao sistema, aqui é só consulta/edição de cadastro). Extraído
 * de script.js na Fase 12 (onda 2 da divisão por domínio); abas separadas na
 * Fase 5 das melhorias adiadas (antes misturadas numa lista só).
 */

let abaUsuariosAtiva = localStorage.getItem('cd_abaUsuariosAtiva') || 'equipe';
let usuariosCacheAtual = []; // última lista carregada (da aba ativa), usada por editarUsuario/filtrarSolicitantes

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
    // Gestor nunca vê solicitantes (cliente não tem setor); se a aba ficou marcada
    // de uma sessão de admin anterior no mesmo navegador, volta para "equipe".
    if (!ehAdmin && abaUsuariosAtiva === 'solicitantes') {
        abaUsuariosAtiva = 'equipe';
    }

    try {
        const usuarios = await Api.get(`usuarios.php?tipo=${abaUsuariosAtiva}`);
        usuariosCacheAtual = usuarios;
        // Admin precisa da lista de setores para poder escolher em qual setor
        // cadastrar/mover um usuário da equipe. Gestor não precisa (fica travado no
        // próprio setor); a aba Solicitantes também não usa setores.
        const setores = (ehAdmin && abaUsuariosAtiva === 'equipe') ? await Api.get('setores.php').catch(() => []) : [];
        mostrarListaUsuarios(usuarios, setores);
    } catch (e) {
        document.getElementById('config-content').innerHTML = '<p>Erro ao carregar usuários.</p>';
    }
}

// Troca a aba ativa (chamado pelo clique no botão da aba). O conteúdo é recriado via
// innerHTML (mostrarListaUsuarios), o que destrói o botão da aba que tinha o foco —
// por isso devolvemos o foco ao botão da aba recém-ativada depois do recarregamento.
async function mudarAbaUsuarios(aba) {
    if (aba === abaUsuariosAtiva) return;
    abaUsuariosAtiva = aba;
    localStorage.setItem('cd_abaUsuariosAtiva', aba);
    await carregarUsuarios();
    document.getElementById(`aba-usuarios-${aba}`)?.focus();
}

// Setas do teclado movem o foco entre as abas e já ativam a aba (padrão WAI-ARIA de tabs)
function navegarAbasUsuarios(event, abaAtualId) {
    const abas = ['equipe', 'solicitantes'];
    const indiceAtual = abas.indexOf(abaAtualId);
    let destino = null;
    if (event.key === 'ArrowRight') destino = abas[(indiceAtual + 1) % abas.length];
    else if (event.key === 'ArrowLeft') destino = abas[(indiceAtual - 1 + abas.length) % abas.length];
    else return;

    event.preventDefault();
    mudarAbaUsuarios(destino);
}

function rotuloTipoUsuario(tipo) {
    if (tipo === 'cliente') return 'Solicitante externo (sem acesso ao sistema)';
    return Papeis.rotulo(tipo || 'operador');
}

// Admin redefine a senha de qualquer membro da equipe; gestor, só dos operadores do setor
function podeRedefinirSenhaUsuario(usuario, usuarioLogado, ehAdmin) {
    if (Number(usuario.id) === Number(usuarioLogado.id) || usuario.tipo === 'cliente') return false;
    return ehAdmin || usuario.tipo === 'operador';
}

function gerarCardUsuario(usuario, usuarioLogado, ehAdmin) {
    const tipoExibicao = rotuloTipoUsuario(usuario.tipo);
    const statusAtivo = usuario.ativo === 1 || usuario.ativo === '1';

    return `
        <div class="usuario-card ${!statusAtivo ? 'usuario-inativo' : ''}">
            <div class="usuario-info">
                <h4>${sanitizeHTML(usuario.nome)} ${!statusAtivo ? '<span style="color: #dc3545;">(Inativo)</span>' : ''}</h4>
                <p>${sanitizeHTML(usuario.email)}${usuario.username ? ` · <code>${sanitizeHTML(usuario.username)}</code>` : ''}</p>
                <p><strong>Papel:</strong> ${tipoExibicao}${usuario.cargo ? ` · <strong>Cargo:</strong> ${sanitizeHTML(usuario.cargo)}` : ''}${usuario.setor_nome ? ` · <strong>Setor:</strong> ${sanitizeHTML(usuario.setor_nome)}` : ''}${(usuario.setores_adicionais || []).length ? ` · <strong>Setores adicionais:</strong> ${usuario.setores_adicionais.map(s => sanitizeHTML(s.nome)).join(', ')}` : ''}${usuario.atendimento_externo ? ' · <strong>Atendimento externo</strong>' : ''}</p>
                <p><strong>Criado:</strong> ${dataDoBanco(usuario.criado_em).toLocaleDateString('pt-BR')}</p>
            </div>
            <div class="usuario-acoes">
                <button class="btn-${statusAtivo ? 'desativar' : 'ativar'}" onclick="${statusAtivo ? 'desativar' : 'ativar'}Usuario(${usuario.id})">
                    ${statusAtivo ? 'Desativar' : 'Ativar'}
                </button>
                <button class="btn-editar" onclick="editarUsuario(${usuario.id})">Editar</button>
                ${ehAdmin && usuario.tipo === 'cliente' ? `<button type="button" class="btn-promover" data-acao-usuario="promover" data-id="${Number(usuario.id)}">Promover a usuário interno</button>` : ''}
                ${podeRedefinirSenhaUsuario(usuario, usuarioLogado, ehAdmin) ? `<button class="btn-redefinir-senha" onclick="redefinirSenhaUsuario(${Number(usuario.id)}, decodeURIComponent('${argJs(usuario.nome)}'))">Redefinir senha</button>` : ''}
                ${ehAdmin ? `<button class="btn-excluir" onclick="excluirUsuario(${usuario.id})">Excluir</button>` : ''}
            </div>
        </div>
    `;
}

function mostrarListaUsuarios(usuarios, setores) {
    const content = document.getElementById('config-content');
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';
    setores = setores || [];

    // Gestor nunca vê a aba Solicitantes (clientes não têm setor, então nunca apareceriam
    // na lista dele de qualquer forma) — nem a barra de abas, só a lista de equipe direto.
    const abasHtml = ehAdmin ? `
        <div class="abas-usuarios" role="tablist" aria-label="Listas de usuários">
            <button type="button" id="aba-usuarios-equipe" class="aba-usuarios-btn" role="tab"
                aria-selected="${abaUsuariosAtiva === 'equipe'}" aria-controls="painel-usuarios"
                tabindex="${abaUsuariosAtiva === 'equipe' ? '0' : '-1'}"
                onclick="mudarAbaUsuarios('equipe')" onkeydown="navegarAbasUsuarios(event, 'equipe')">
                👥 Equipe
            </button>
            <button type="button" id="aba-usuarios-solicitantes" class="aba-usuarios-btn" role="tab"
                aria-selected="${abaUsuariosAtiva === 'solicitantes'}" aria-controls="painel-usuarios"
                tabindex="${abaUsuariosAtiva === 'solicitantes' ? '0' : '-1'}"
                onclick="mudarAbaUsuarios('solicitantes')" onkeydown="navegarAbasUsuarios(event, 'solicitantes')">
                ✉️ Solicitantes
            </button>
        </div>` : '';

    const painelHtml = (ehAdmin && abaUsuariosAtiva === 'solicitantes')
        ? gerarPainelSolicitantes(usuarios, usuarioLogado)
        : gerarPainelEquipe(usuarios, setores, usuarioLogado, ehAdmin);

    content.innerHTML = `
        ${abasHtml}
        <div id="painel-usuarios" role="tabpanel" aria-labelledby="aba-usuarios-${ehAdmin ? abaUsuariosAtiva : 'equipe'}">
            ${painelHtml}
        </div>
    `;
}

// Papéis na ordem em que aparecem na lista, com o título de cada grupo
const GRUPOS_EQUIPE_USUARIOS = [
    ['admin', '👑 Administradores'],
    ['coordenador_geral', '🧩 Coordenação Geral'],
    ['supervisor', '🔎 Supervisão'],
    ['coordenador', '🗂️ Coordenadores'],
    ['gestor', '🧭 Gestores'],
    ['operador', '👥 Operadores']
];

// Quem já coordena cada setor (principal ou adicional), para o admin não dar o mesmo setor a dois coordenadores.
// `equipe` = lista de usuários a consultar; sem ela, a da aba aberta (na aba Solicitantes ela não tem coordenadores:
// a promoção passa a equipe buscada à parte)
function coordenadorDeCadaSetor(equipe) {
    const mapa = {};
    (equipe || usuariosCacheAtual).filter(u => u.tipo === 'coordenador' && (u.ativo === 1 || u.ativo === '1')).forEach(u => {
        if (u.setor_id) mapa[String(u.setor_id)] = { id: Number(u.id), nome: u.nome };
        (u.setores_adicionais || []).forEach(s => { mapa[String(s.id)] = { id: Number(u.id), nome: u.nome }; });
    });
    return mapa;
}

// Caixas de seleção dos setores adicionais de um coordenador (só o admin vê)
function caixasSetoresAdicionais(setores, selecionados, usuarioId, equipe) {
    const marcados = new Set((selecionados || []).map(String));
    const ocupados = coordenadorDeCadaSetor(equipe);
    return setores.map(s => {
        const dono = ocupados[String(s.id)];
        const ocupadoPorOutro = dono && Number(dono.id) !== Number(usuarioId || 0);
        return `<label class="caixa-setor-adicional${ocupadoPorOutro ? ' caixa-setor-ocupado' : ''}">
            <input type="checkbox" name="setor-adicional" value="${Number(s.id)}" ${marcados.has(String(s.id)) ? 'checked' : ''} ${ocupadoPorOutro ? 'disabled' : ''}>
            <span>${sanitizeHTML(s.nome)}${ocupadoPorOutro ? ` <small>(coordenador(a): ${sanitizeHTML(dono.nome)})</small>` : ''}</span>
        </label>`;
    }).join('');
}

function contagemSetoresAdicionais(n) {
    return n === 0 ? '(nenhum marcado)' : `(${n} marcado${n > 1 ? 's' : ''})`;
}

// Atualiza a contagem no título da lista ao marcar/desmarcar (ouvinte delegado, vale para o cadastro e o modal)
document.addEventListener('change', (ev) => {
    if (!ev.target.matches('input[name="setor-adicional"]')) return;
    const grupo = ev.target.closest('.fieldset-setores-adicionais');
    const rotulo = grupo && grupo.querySelector('[data-contagem-setores]');
    if (rotulo) rotulo.textContent = contagemSetoresAdicionais(grupo.querySelectorAll('input[name="setor-adicional"]:checked').length);
});

// Equipe usada pelo formulário de promoção (aberto na aba Solicitantes, cuja lista não tem coordenadores)
let equipePromocao = null;

// Mostra os setores adicionais só quando o perfil escolhido é Coordenador e, no setor de lotação, esmaece e
// bloqueia os setores que já têm outro coordenador (o servidor também recusa: 409)
function atualizarCamposPerfilUsuario(idSelect, idGrupo) {
    const seletor = document.getElementById(idSelect);
    const grupo = document.getElementById(idGrupo);
    if (grupo && seletor) grupo.hidden = seletor.value !== 'coordenador';
    const prefixo = idSelect.replace(/tipo(-usuario)?$/, '');
    const idSetor = { '': 'setor-usuario', 'edit-': 'edit-setor', 'promo-': 'promo-setor' }[prefixo];
    if (seletor && idSetor) bloquearSetoresComCoordenador(document.getElementById(idSetor), seletor.value === 'coordenador',
        prefixo === 'promo-' ? equipePromocao : null);
}

function bloquearSetoresComCoordenador(select, ehCoordenador, equipe) {
    if (!select) return;
    const ocupados = coordenadorDeCadaSetor(equipe);
    const usuarioId = Number(select.dataset.usuarioId || 0);
    Array.from(select.options).forEach(op => {
        if (!op.value) return;
        if (op.dataset.nome === undefined) op.dataset.nome = op.textContent;
        const dono = ocupados[String(op.value)];
        const bloqueado = ehCoordenador && dono && Number(dono.id) !== usuarioId;
        op.disabled = !!bloqueado;
        op.classList.toggle('opcao-setor-ocupado', !!bloqueado);
        op.textContent = bloqueado ? `${op.dataset.nome} (coordenador(a): ${dono.nome})` : op.dataset.nome;
    });
    // O setor escolhido ficou bloqueado: volta para "Selecione"
    if (select.selectedOptions[0] && select.selectedOptions[0].disabled) select.value = '';
}

function opcoesPerfilUsuario(ehAdmin, atual) {
    const tipos = ehAdmin ? ['operador', 'gestor', 'coordenador', 'supervisor', 'coordenador_geral', 'admin'] : ['operador'];
    return tipos.map(t => `<option value="${t}" ${t === atual ? 'selected' : ''}>${Papeis.rotulo(t)}</option>`).join('');
}

// Campos que só o admin preenche: setores adicionais do coordenador e atendimento externo
function camposSoAdminUsuario(prefixo, setores, usuario, equipe) {
    const extras = ((usuario && usuario.setores_adicionais) || []).map(s => s.id);
    return `
        <div class="form-group" id="${prefixo}grupo-setores-adicionais" ${usuario && usuario.tipo === 'coordenador' ? '' : 'hidden'}>
            <details class="fieldset-setores-adicionais" open>
                <summary>Setores adicionais do coordenador (além do setor principal)
                    <span class="contagem-setores-adicionais" data-contagem-setores>${contagemSetoresAdicionais(extras.length)}</span></summary>
                <div class="caixas-setores-adicionais" role="group" aria-label="Setores adicionais do coordenador">${caixasSetoresAdicionais(setores, extras, usuario ? usuario.id : null, equipe)}</div>
                <small>Cada setor tem um único coordenador. Clique no título para recolher a lista.</small>
            </details>
        </div>
        <div class="form-group">
            <label class="switch-label switch-label-quebra">
                <span class="switch"><input type="checkbox" id="${prefixo}atendimento-externo" ${usuario && usuario.atendimento_externo ? 'checked' : ''}><span class="slider"></span></span>
                <span>Faz atendimento externo (pode enviar pesquisas de satisfação no Meu Painel)</span>
            </label>
        </div>`;
}

// Lê os campos que só o admin preenche (vazio quando a tela não os mostra)
function lerCamposSoAdminUsuario(prefixo, tipo) {
    const externo = document.getElementById(`${prefixo}atendimento-externo`);
    const dados = {};
    if (externo) {
        dados.atendimento_externo = externo.checked;
        dados.setores_adicionais = tipo === 'coordenador'
            ? Array.from(document.querySelectorAll(`#${prefixo}grupo-setores-adicionais input[name="setor-adicional"]:checked`)).map(c => Number(c.value))
            : [];
    }
    return dados;
}

function gerarPainelEquipe(usuarios, setores, usuarioLogado, ehAdmin) {
    // Separar por papel (tratar vazio como operador, por segurança de exibição)
    let secoes = '';
    GRUPOS_EQUIPE_USUARIOS.forEach(([papel, titulo]) => {
        const grupo = usuarios.filter(u => u.tipo === papel || (papel === 'operador' && !u.tipo));
        if (grupo.length > 0) {
            secoes += `<h3>${titulo} (${grupo.length})</h3><div class="usuarios-grid">${grupo.map(u => gerarCardUsuario(u, usuarioLogado, ehAdmin)).join('')}</div>`;
        }
    });
    if (!secoes) {
        secoes = '<p>Nenhum usuário da equipe encontrado.</p>';
    }

    // Opções de papel: gestor e coordenador só cadastram operadores; admin pode tudo
    const opcoesTipo = opcoesPerfilUsuario(ehAdmin, 'operador');

    // Setor: o admin escolhe entre todos; o coordenador, entre os seus; gestor fica travado no próprio setor (backend)
    const setoresEscolha = ehAdmin ? setores : (Papeis.setores(usuarioLogado) || []);
    const campoSetor = (ehAdmin || setoresEscolha.length > 1) ? `
                    <div class="form-group">
                        <label for="setor-usuario">Setor:</label>
                        <select id="setor-usuario">
                            <option value="">${ehAdmin ? 'Nenhum (obrigatório, menos para Administrador)' : 'Escolha o setor'}</option>
                            ${setoresEscolha.map(s => `<option value="${s.id}">${sanitizeHTML(s.nome)}</option>`).join('')}
                        </select>
                    </div>` : '';

    const camposAdmin = ehAdmin ? camposSoAdminUsuario('', setores, null) : '';

    return `
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
                            maxlength="50" autocapitalize="none" spellcheck="false" aria-describedby="username-usuario-ajuda"
                            oninput="this.dataset.editadoManualmente='true'">
                        <p id="username-usuario-ajuda" class="ajuda-campo-usuario">${TEXTO_REGRA_USERNAME}</p>
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
                        <select id="tipo-usuario" onchange="atualizarCamposPerfilUsuario('tipo-usuario', 'grupo-setores-adicionais')">
                            ${opcoesTipo}
                        </select>
                    </div>
                    ${campoSetor}
                </div>
                ${camposAdmin}
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

function gerarPainelSolicitantes(usuarios, usuarioLogado) {
    return `
        <div class="form-group busca-solicitantes">
            <label for="busca-solicitantes-input">Buscar por nome ou e-mail:</label>
            <input type="search" id="busca-solicitantes-input" placeholder="Digite para filtrar..." oninput="filtrarSolicitantes()">
        </div>
        <div id="grid-solicitantes">
            ${renderGridSolicitantes(usuarios, usuarioLogado)}
        </div>
    `;
}

function renderGridSolicitantes(usuarios, usuarioLogado) {
    if (usuarios.length === 0) {
        return '<p>Nenhum solicitante encontrado.</p>';
    }
    return `<div class="usuarios-grid">${usuarios.map(u => gerarCardUsuario(u, usuarioLogado, true)).join('')}</div>`;
}

// Filtra a lista de solicitantes já carregada (sem nova requisição) por nome ou e-mail
function filtrarSolicitantes() {
    const campoBusca = document.getElementById('busca-solicitantes-input');
    const grid = document.getElementById('grid-solicitantes');
    if (!campoBusca || !grid) return;

    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const termo = campoBusca.value.trim().toLowerCase();
    const filtrados = !termo ? usuariosCacheAtual : usuariosCacheAtual.filter(u =>
        (u.nome || '').toLowerCase().includes(termo) || (u.email || '').toLowerCase().includes(termo)
    );
    grid.innerHTML = renderGridSolicitantes(filtrados, usuarioLogado);
}

// Regra do nome de usuário (a mesma de UsuarioService::PADRAO_USERNAME no servidor). O login aceita e-mail
// ou nome de usuário e decide pelo "@", por isso o nome de usuário nunca tem "@".
const PADRAO_USERNAME = /^[a-z0-9._-]{3,50}$/;
const TEXTO_REGRA_USERNAME = 'Usado no login. De 3 a 50 letras minúsculas, números, ponto, hífen ou sublinhado (sem @ e sem acento).';

// Mensagem de erro do nome de usuário digitado, ou null se está certo ('' = gerado pelo sistema)
function erroUsernameDigitado(username) {
    if (username === '' || PADRAO_USERNAME.test(username)) return null;
    return 'Nome de usuário inválido. ' + TEXTO_REGRA_USERNAME;
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
    const username = document.getElementById('username-usuario').value.trim().toLowerCase();
    const email = document.getElementById('email-usuario').value;
    const cargo = document.getElementById('cargo-usuario').value;
    const tipo = document.getElementById('tipo-usuario').value;
    const campoSetor = document.getElementById('setor-usuario');
    const setor_id = campoSetor ? campoSetor.value : null;
    const senha = document.getElementById('senha-usuario').value;
    const senhaConfirmacao = document.getElementById('senha-confirmacao-usuario').value;

    if (tipo !== 'admin' && campoSetor && !setor_id) {
        mostrarAlert('Erro', 'Selecione o setor de lotação (obrigatório para todos os perfis, menos Administrador).');
        return;
    }

    const erroUsername = erroUsernameDigitado(username);
    if (erroUsername) {
        mostrarAlert('Erro', erroUsername);
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
        await Api.post('usuarios.php', Object.assign({ nome, username, email, cargo, tipo, setor_id, senha }, lerCamposSoAdminUsuario('', tipo)));
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
        // Usa a lista já carregada da aba ativa (equipe ou solicitantes); evita buscar
        // de novo e evita que o filtro de ?tipo= da outra aba escondesse o usuário.
        let usuario = usuariosCacheAtual.find(u => Number(u.id) === Number(usuarioId));
        if (!usuario) {
            const usuarios = await Api.get(`usuarios.php?tipo=${abaUsuariosAtiva}`);
            usuario = usuarios.find(u => Number(u.id) === Number(usuarioId));
        }
        if (!usuario) return;

        const setores = (ehAdmin && usuario.tipo !== 'cliente') ? await Api.get('setores.php').catch(() => []) : [];
        mostrarFormularioEdicaoUsuario(usuario, setores);
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao carregar usuário');
    }
}

function mostrarFormularioEdicaoUsuario(usuario, setores) {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';
    setores = setores || [];

    // Solicitante (cliente) não tem papel interno: o seletor de Perfil não se aplica a ele
    // (editar aqui é só corrigir nome/e-mail/cargo; para levá-lo à equipe há o botão "Promover a usuário interno").
    const ehSolicitante = usuario.tipo === 'cliente';
    const opcoesTipo = ehSolicitante
        ? `<option value="cliente" selected>Solicitante externo (sem acesso ao sistema)</option>`
        : ehAdmin
            ? opcoesPerfilUsuario(true, usuario.tipo)
            : `<option value="operador" selected>Operador</option>`;

    const campoSetor = (ehAdmin && !ehSolicitante) ? `
        <div class="form-group">
            <label for="edit-setor">Setor:</label>
            <select id="edit-setor" data-usuario-id="${Number(usuario.id)}">
                <option value="">Nenhum (obrigatório, menos para Administrador)</option>
                ${setores.map(s => `<option value="${s.id}" ${String(usuario.setor_id) === String(s.id) ? 'selected' : ''}>${sanitizeHTML(s.nome)}</option>`).join('')}
            </select>
        </div>` : '';
    const camposAdmin = (ehAdmin && !ehSolicitante) ? camposSoAdminUsuario('edit-', setores, usuario) : '';

    const content = `
        <div class="form-group">
            <label for="edit-nome">Nome:</label>
            <input type="text" id="edit-nome" value="${sanitizeHTML(usuario.nome)}" required>
        </div>
        <div class="form-group">
            <label for="edit-username">Nome de usuário:</label>
            <input type="text" id="edit-username" value="${sanitizeHTML(usuario.username || '')}"
                maxlength="50" autocapitalize="none" spellcheck="false" aria-describedby="edit-username-ajuda">
            <p id="edit-username-ajuda" class="ajuda-campo-usuario">${TEXTO_REGRA_USERNAME} Em branco, mantém o atual.</p>
        </div>
        <div class="form-group">
            <label for="edit-cargo">Cargo/Função:</label>
            <input type="text" id="edit-cargo" value="${sanitizeHTML(usuario.cargo || '')}">
        </div>
        <div class="form-group">
            <label for="edit-tipo">Perfil:</label>
            <select id="edit-tipo" ${(ehAdmin && !ehSolicitante) ? '' : 'disabled'} onchange="atualizarCamposPerfilUsuario('edit-tipo', 'edit-grupo-setores-adicionais')">
                ${opcoesTipo}
            </select>
        </div>
        ${campoSetor}
        ${camposAdmin}
    `;

    document.getElementById('modal-prompt-message').innerHTML = content;
    document.getElementById('modal-prompt-title').textContent = 'Editar Usuário';
    if (ehAdmin && !ehSolicitante) atualizarCamposPerfilUsuario('edit-tipo', 'edit-grupo-setores-adicionais');
    document.getElementById('modal-prompt-input').style.display = 'none';

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        if (confirmado) {
            const nome = document.getElementById('edit-nome').value;
            const username = document.getElementById('edit-username').value.trim().toLowerCase();
            const cargo = document.getElementById('edit-cargo').value;
            const tipo = document.getElementById('edit-tipo').value;
            const campoSetorEl = document.getElementById('edit-setor');
            const setor_id = campoSetorEl ? campoSetorEl.value : null;

            if (tipo !== 'admin' && tipo !== 'cliente' && campoSetorEl && !setor_id) {
                mostrarAlert('Erro', 'Selecione o setor de lotação (obrigatório para todos os perfis, menos Administrador).');
                return;
            }

            const erroUsername = erroUsernameDigitado(username);
            if (erroUsername) {
                mostrarAlert('Erro', erroUsername);
                return;
            }

            if (nome && tipo) {
                executarEdicaoUsuario(usuario.id, nome, usuario.email, tipo, username, cargo, setor_id, lerCamposSoAdminUsuario('edit-', tipo));
            }
        }

        // Restaurar modal
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
    };
}

// ── Promoção de solicitante externo a usuário interno (só admin, 18/11/2026) ──
// O solicitante nasce do e-mail recebido e nunca teve senha: a promoção pede perfil, setor e uma senha inicial,
// que ele troca no primeiro acesso. As demandas que ele abriu continuam com ele como solicitante.
document.addEventListener('click', (ev) => {
    const botao = ev.target.closest('[data-acao-usuario="promover"]');
    if (botao) promoverSolicitante(Number(botao.dataset.id));
});

async function promoverSolicitante(usuarioId) {
    const usuario = usuariosCacheAtual.find(u => Number(u.id) === usuarioId);
    if (!usuario) return;
    let setores = [];
    let equipe = [];
    try {
        // A equipe (não a lista de solicitantes da aba aberta) diz quais setores já têm coordenador
        [setores, equipe] = await Promise.all([Api.get('setores.php'), Api.get('usuarios.php?tipo=equipe')]);
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao carregar os setores e a equipe.'));
        return;
    }
    mostrarFormularioPromocao(usuario, setores.filter(s => s.ativo === undefined || Number(s.ativo) === 1), equipe);
}

// Nome de usuário sugerido (nome.sobrenome, sem acento), como no cadastro
function usernameSugerido(nome) {
    const partes = String(nome || '').normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/[^a-z\s]/g, '').trim().split(/\s+/).filter(Boolean);
    if (partes.length === 0) return '';
    return partes.length === 1 ? partes[0] : `${partes[0]}.${partes[partes.length - 1]}`;
}

function mostrarFormularioPromocao(usuario, setores, equipe) {
    equipePromocao = equipe;
    document.getElementById('modal-prompt-message').innerHTML = `
        <p class="aviso-promocao">${sanitizeHTML(usuario.nome)} (${sanitizeHTML(usuario.email)}) passa a entrar no sistema.
            As demandas que abriu continuam com ele(a) como solicitante.</p>
        <div class="form-group">
            <label for="promo-tipo">Perfil:</label>
            <select id="promo-tipo" onchange="atualizarCamposPerfilUsuario('promo-tipo', 'promo-grupo-setores-adicionais')">${opcoesPerfilUsuario(true, 'operador')}</select>
        </div>
        <div class="form-group">
            <label for="promo-setor">Setor de lotação:</label>
            <select id="promo-setor">
                <option value="">Selecione (obrigatório, menos para Administrador)</option>
                ${setores.map(s => `<option value="${Number(s.id)}">${sanitizeHTML(s.nome)}</option>`).join('')}
            </select>
        </div>
        ${camposSoAdminUsuario('promo-', setores, null, equipe)}
        <div class="form-group">
            <label for="promo-username">Nome de usuário:</label>
            <input type="text" id="promo-username" value="${sanitizeHTML(usuario.username || usernameSugerido(usuario.nome))}"
                maxlength="50" autocapitalize="none" spellcheck="false" aria-describedby="promo-username-ajuda">
            <p id="promo-username-ajuda" class="ajuda-campo-usuario">${TEXTO_REGRA_USERNAME}</p>
        </div>
        <div class="form-group">
            <label for="promo-cargo">Cargo/Função:</label>
            <input type="text" id="promo-cargo" value="${sanitizeHTML(usuario.cargo || '')}">
        </div>
        <div class="form-group">
            <label for="promo-senha">Senha inicial:</label>
            <input type="password" id="promo-senha" autocomplete="new-password" aria-describedby="promo-senha-ajuda">
        </div>
        <div class="form-group">
            <label for="promo-senha-confirmacao">Confirmar senha:</label>
            <input type="password" id="promo-senha-confirmacao" autocomplete="new-password">
            <p id="promo-senha-ajuda" class="ajuda-campo-usuario">Mínimo de 10 caracteres, com letra maiúscula, minúscula, número e caractere especial.
                A pessoa troca a senha no primeiro acesso; informe-a por um canal seguro.</p>
        </div>`;
    document.getElementById('modal-prompt-title').textContent = 'Promover a usuário interno';
    document.getElementById('modal-prompt-input').style.display = 'none';
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        const dados = confirmado ? lerFormularioPromocao() : null;
        // Restaura o modal antes de qualquer aviso (o alerta usa o mesmo fundo)
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
        if (!confirmado) return;
        if (dados.erro) {
            mostrarAlert('Atenção', dados.erro);
            return;
        }
        executarPromocao(usuario, dados);
    };
}

// Lê e confere o formulário de promoção; devolve {erro} ou os dados para o servidor
function lerFormularioPromocao() {
    const tipo = document.getElementById('promo-tipo').value;
    const setor_id = document.getElementById('promo-setor').value;
    const username = document.getElementById('promo-username').value.trim().toLowerCase();
    const senha = document.getElementById('promo-senha').value;
    const confirmacao = document.getElementById('promo-senha-confirmacao').value;
    if (tipo !== 'admin' && !setor_id) return { erro: 'Selecione o setor de lotação (obrigatório para todos os perfis, menos Administrador).' };
    const erroUsername = erroUsernameDigitado(username);
    if (erroUsername) return { erro: erroUsername };
    if (senha !== confirmacao) return { erro: 'As senhas não coincidem.' };
    const erroSenha = validarSenhaNova(senha);
    if (erroSenha) return { erro: erroSenha };
    return Object.assign({ tipo, setor_id, username, senha, cargo: document.getElementById('promo-cargo').value },
        lerCamposSoAdminUsuario('promo-', tipo));
}

async function executarPromocao(usuario, dados) {
    try {
        await Api.put(`usuarios.php/${Number(usuario.id)}`, Object.assign({ nome: usuario.nome, email: usuario.email }, dados));
        mostrarToast('Sucesso', `${sanitizeHTML(usuario.nome)} agora é ${sanitizeHTML(Papeis.rotulo(dados.tipo))} e aparece na aba Equipe.`, 'success');
        carregarUsuarios();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao promover o solicitante');
    }
}

async function executarEdicaoUsuario(usuarioId, nome, email, tipo, username, cargo, setor_id, extras) {
    try {
        const resposta = await Api.put(`usuarios.php/${usuarioId}`, Object.assign({ nome, email, tipo, username, cargo, setor_id }, extras || {}));
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
