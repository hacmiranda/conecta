// Escapa texto para inserir em HTML (conteúdo e atributos). Use em todo dado vindo da API.
function sanitizeHTML(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * Protocolo único da demanda, sempre com "#" (ex.: "#CRPRS2026-0001"). Aceita o objeto da demanda
 * (campo `protocolo` ou `ticket_protocolo`, conforme o endpoint) ou só o id/número — nesse caso cai
 * no número interno (#00080), só para a tela não ficar vazia em dados antigos sem protocolo.
 */
function numeroDemanda(obj) {
    if (obj && typeof obj === 'object') {
        const protocolo = obj.protocolo || obj.ticket_protocolo;
        if (protocolo) return '#' + protocolo;
        const id = obj.id ?? obj.ticket_id ?? obj.registro_id;
        if (id != null) return '#' + String(id).padStart(5, '0');
    }
    return '#' + String(obj).padStart(5, '0');
}

// HTML rico (descrição, comentários, macros): mantém só a formatação segura (DOMPurify)
function htmlSeguro(html) {
    if (window.DOMPurify) {
        return DOMPurify.sanitize(String(html ?? ''), { ADD_ATTR: ['target'], FORBID_TAGS: ['style', 'form', 'input', 'button'] });
    }
    return sanitizeHTML(html);
}

// Texto como argumento de função em onclick="fn(decodeURIComponent('...'))"
function argJs(valor) {
    return encodeURIComponent(String(valor ?? '')).replace(/'/g, '%27');
}

// Cabeçalhos de segurança para fetch(): só o X-CSRF-Token. A sessão (JWT) vai sozinha no cookie
// HttpOnly "conecta_sessao" (mesma origem), que o JavaScript não lê.
function getAuthHeaders() {
    const headers = {};
    const csrfToken = localStorage.getItem('cd_csrf_token');
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
    return headers;
}

// O mesmo para XMLHttpRequest (o cookie de sessão também vai sozinho)
window.setSecurityHeaders = function (xhr) {
    const csrfToken = localStorage.getItem('cd_csrf_token');
    if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    }
};

// Limpa os dados da sessão no navegador e volta ao login (motivo opcional exibido lá)
function encerrarSessaoLocal(motivo) {
    limparDadosDaSessao();
    window.location.href = './login.html' + (motivo ? '?sessao=' + encodeURIComponent(motivo) : '');
}

// Dados da sessão guardados no navegador (apagados ao sair). Preferências (modo escuro, tutorial,
// sidebar recolhida) ficam. cd_token/cd_token_exp: restos de versões antigas, quando o token ficava aqui.
function limparDadosDaSessao() {
    ['cd_usuario', 'cd_csrf_token', 'cd_token', 'cd_token_exp',
        'cd_ticketAtual', 'cd_ticketAtivo', 'cd_secaoAtiva', 'cd_secaoOrigem', 'cd_subSecaoAtiva']
        .forEach(chave => localStorage.removeItem(chave));
    try { sessionStorage.clear(); } catch (e) { /* sem sessionStorage */ }
}

// Função de logout: encerra a sessão no servidor (o token deixa de valer e o cookie é apagado) e no
// navegador. todas = true: encerra também as sessões dos outros dispositivos.
function fazerLogout(todas = false) {
    if (!localStorage.getItem('cd_usuario')) {
        encerrarSessaoLocal();
        return;
    }
    const controle = new AbortController();
    setTimeout(() => controle.abort(), 3000);
    fetch(window.APP_CONFIG.apiBase + 'logout.php', {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/json' }, getAuthHeaders()),
        body: JSON.stringify({ todas: !!todas }),
        signal: controle.signal,
        keepalive: true
    })
        .catch(() => { })
        .finally(() => encerrarSessaoLocal());
}

// Avatar → "Sair de todos os dispositivos"
function sairDeTodosOsDispositivos() {
    mostrarConfirm('Sair de todos os dispositivos',
        'Encerra sua sessão neste e em todos os outros navegadores e computadores. Você precisará entrar de novo em cada um.',
        () => fazerLogout(true), true);
}

// Respostas da API que mudam o estado da sessão, vindas de qualquer chamada (XHR ou fetch)
function tratarRespostaDeSessao(status, textoResposta, url) {
    if (!url || url.indexOf(window.APP_CONFIG.apiBase) === -1 || /\/(logout|auth)\.php/.test(url)) return;
    if (status === 401) {
        encerrarSessaoLocal('encerrada');
        return;
    }
    if (status === 403 && textoResposta && textoResposta.indexOf('trocar_senha') !== -1) {
        ativarTrocaObrigatoriaSenha();
    }
}

(function monitorarSessao() {
    const abrirOriginal = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (metodo, url) {
        this._urlConecta = String(url);
        this.addEventListener('load', () => {
            let texto = '';
            try { texto = this.responseType === '' || this.responseType === 'text' ? this.responseText : ''; } catch (e) { /* sem texto */ }
            tratarRespostaDeSessao(this.status, texto, this._urlConecta);
        });
        return abrirOriginal.apply(this, arguments);
    };
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = function (recurso, opcoes) {
        return fetchOriginal(recurso, opcoes).then(resposta => {
            if (resposta.status === 401 || resposta.status === 403) {
                resposta.clone().text().then(texto => tratarRespostaDeSessao(resposta.status, texto, resposta.url)).catch(() => { });
            }
            return resposta;
        });
    };
})();

// Troca de senha obrigatória (senha inicial ou redefinida por admin/gestor):
// só a tela "Alterar senha" fica disponível até a troca.
function ativarTrocaObrigatoriaSenha() {
    if (document.body.classList.contains('troca-senha-obrigatoria')) return;
    document.body.classList.add('troca-senha-obrigatoria');
    const aviso = document.getElementById('aviso-troca-obrigatoria');
    if (aviso) aviso.hidden = false;
    if (typeof mostrarAlterarSenha === 'function') mostrarAlterarSenha();
    setTimeout(() => document.getElementById('senha-atual')?.focus(), 150);
}


// ======================================================
// 📧 ALERTA DE FALHAS DE E-MAIL
// ======================================================
const EMAIL_ALERT_INTERVAL = 5 * 60 * 1000; // Checar a cada 5 minutos
const EMAIL_ALERT_DISMISSED = 'emailAlertDismissedAt';

function iniciarMonitoramentoEmailFalhas() {
    // Só o administrador configura e-mail (Setores/Configurar e-mail): só ele pode agir
    // sobre a falha, então só ele é avisado (gestor/operador não têm o que fazer aqui).
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (usuario.tipo !== 'admin') return;

    verificarFalhasEmail(); // verificação imediata
    setInterval(verificarFalhasEmail, EMAIL_ALERT_INTERVAL);
}

function verificarFalhasEmail() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'email-alert.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status !== 200) return;
        try {
            const dados = JSON.parse(xhr.responseText);
            if (dados.falhas_24h > 0) {
                exibirAlertaEmailFalha(dados.falhas_24h, dados.ultima_falha);
            } else {
                ocultarAlertaEmailFalha();
            }
        } catch (e) { /* silencioso */ }
    };

    xhr.send();
}

function exibirAlertaEmailFalha(quantidade, ultimaFalha) {
    // Badge no botão Configurações
    const badge = document.getElementById('email-falha-badge');
    if (badge) {
        badge.textContent = quantidade > 99 ? '99+' : quantidade;
        badge.style.display = 'inline-flex';
        badge.title = `${quantidade} falha(s) de e-mail nas últimas 24h`;
    }

    // Banner: respeitar dismiss (não mostrar se o agente já fechou nas últimas 4h)
    const dismissedAt = localStorage.getItem(EMAIL_ALERT_DISMISSED);
    const agoraDismiss = Date.now();
    if (dismissedAt && (agoraDismiss - parseInt(dismissedAt)) < 4 * 60 * 60 * 1000) {
        return; // ainda dentro da janela de silêncio
    }

    const banner = document.getElementById('email-falha-banner');
    const msg = document.getElementById('email-falha-banner-msg');
    if (!banner || !msg) return;

    let texto = `⚠️ ${quantidade} falha(s) no envio de e-mail detectada(s) nas últimas 24h.`;
    if (ultimaFalha) {
        const dt = new Date(ultimaFalha).toLocaleString('pt-BR');
        texto += ` Última ocorrência: ${dt}.`;
    }
    msg.textContent = texto;
    banner.style.display = 'flex';
}

function ocultarAlertaEmailFalha() {
    const badge = document.getElementById('email-falha-badge');
    if (badge) badge.style.display = 'none';

    const banner = document.getElementById('email-falha-banner');
    if (banner) banner.style.display = 'none';
}

function fecharBannerEmailFalha() {
    const banner = document.getElementById('email-falha-banner');
    if (banner) banner.style.display = 'none';
    // Guardar timestamp do dismiss — não mostra novamente por 4 horas
    localStorage.setItem(EMAIL_ALERT_DISMISSED, Date.now().toString());
}

function irParaLogEmails() {
    fecharBannerEmailFalha();
    mostrarSecao('config');
    // Esperar seção de config renderizar, depois abrir Auditoria > log emails
    setTimeout(() => {
        if (typeof mostrarAuditoria === 'function') {
            mostrarAuditoria();
            setTimeout(() => {
                if (typeof carregarLogEmails === 'function') {
                    carregarLogEmails();
                }
            }, 300);
        }
    }, 150);
}

// ======================================================
// 👤 HEADER AVATAR
// ======================================================
function inicializarAvatar() {
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (!usuario.nome) return;

    const nome = usuario.nome || '';
    const email = usuario.email || '';
    const tipo = usuario.tipo || '';
    const cargo = usuario.cargo || '';

    // Gerar iniciais (até 2)
    const iniciais = nome
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map(p => p[0].toUpperCase())
        .join('');

    // Preencher elementos do header
    const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

    setEl('header-avatar-iniciais', iniciais);
    setEl('header-avatar-iniciais-sm', iniciais);
    setEl('header-avatar-nome', nome.split(' ')[0]); // primeiro nome no botão
    setEl('header-avatar-role', 'Perfil: ' + (tipo === 'admin' ? 'Administrador' : tipo === 'gestor' ? 'Gestor' : 'Operador'));
    const elCargo = document.getElementById('header-avatar-cargo');
    if (elCargo) {
        elCargo.textContent = cargo;
        elCargo.style.display = cargo ? '' : 'none';
    }
    setEl('had-nome', nome);
    setEl('had-email', email);
}

function toggleAvatarDropdown() {
    const dropdown = document.getElementById('header-avatar-dropdown');
    const btn = document.getElementById('header-avatar-btn');
    if (!dropdown || !btn) return;

    const isOpen = dropdown.classList.contains('open');

    if (isOpen) {
        fecharAvatarDropdown();
    } else {
        dropdown.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
        dropdown.setAttribute('aria-hidden', 'false');

        // Fechar ao clicar fora
        setTimeout(() => {
            document.addEventListener('click', fecharAvatarDropdownFora, { once: true });
        }, 0);
    }
}

function fecharAvatarDropdownFora(e) {
    const wrapper = document.getElementById('header-avatar-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        fecharAvatarDropdown();
    }
}

function fecharAvatarDropdown() {
    const dropdown = document.getElementById('header-avatar-dropdown');
    const btn = document.getElementById('header-avatar-btn');
    if (dropdown) { dropdown.classList.remove('open'); dropdown.setAttribute('aria-hidden', 'true'); }
    if (btn) { btn.setAttribute('aria-expanded', 'false'); }
}

// Fechar também com ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') fecharAvatarDropdown();
});

// Navegação entre seções
function mostrarSecao(secaoId) {
    // Troca de senha pendente: nenhuma outra tela abre
    if (document.body.classList.contains('troca-senha-obrigatoria') && secaoId !== 'alterar-senha-secao') {
        secaoId = 'alterar-senha-secao';
    }
    document.querySelectorAll('.secao').forEach(secao => {
        secao.classList.remove('active');
    });

    document.querySelectorAll('.btn-nav').forEach(btn => {
        btn.classList.remove('active');
    });

    document.getElementById(secaoId).classList.add('active');

    // Salvar seção ativa no localStorage
    localStorage.setItem('cd_secaoAtiva', secaoId);

    // Ativar botão correspondente por ID (robusto, sem depender de índice de posição)
    const mapaBotoes = {
        'tickets': 'btn-nav-tickets',
        'fechados': 'btn-nav-arquivo',
        'lixeira': 'btn-nav-lixeira',
        'dashboard': 'btn-nav-dashboard',
        'relatorios': 'btn-nav-relatorios',
        'ajuda': 'btn-nav-ajuda',
        'config': 'btn-nav-config'
    };

    const btnId = mapaBotoes[secaoId];
    if (btnId) {
        const btn = document.getElementById(btnId);
        if (btn) btn.classList.add('active');
    }

    if (secaoId === 'novo') {
        // Reconfigurar pesquisa de categoria após mostrar seção
        setTimeout(() => {
            if (todasCategorias.length > 0) {
                configurarPesquisaCategoria();
            }
        }, 200);
    } else if (secaoId === 'config') {
        // Verificar se é admin ou gestor
        const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
        if (usuario.tipo !== 'admin' && usuario.tipo !== 'gestor') {
            mostrarAlert('Acesso Negado', 'Apenas administradores e gestores podem acessar as configurações.', function () {
                mostrarSecao('tickets');
            });
            return;
        }
        // Subseção padrão não é carregada aqui — cada chamador é responsável
        // por invocar a subseção desejada logo após mostrarSecao('config')
    }
}

// Sistema de anexos múltiplos
const MAX_ARQUIVOS = 5;
const MAX_TAMANHO = 20 * 1024 * 1024; // 20MB
const TIPOS_PERMITIDOS = ['.pdf', '.doc', '.docx', '.txt', '.csv', '.xls', '.xlsx', '.png', '.jpg', '.jpeg'];

// setSecurityHeaders(xhr): definido no topo do arquivo (window.setSecurityHeaders), só o X-CSRF-Token

// Carregar tickets do banco de dados (apenas não fechados e não cancelados)
// Acrescenta o setor escolhido pelo admin no cabeçalho (cabecalho-setor.js) à URL da API
function urlComSetor(url) {
    return window.CabecalhoSetor ? window.CabecalhoSetor.anexarSetor(url) : url;
}

// A coluna "Setor" só agrega para quem enxerga mais de um setor (admin) ou nas visões
// em que a demanda está em outro setor/com outra pessoa.
function exibirColunaSetor(emArquivo) {
    const u = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (u.tipo === 'admin') return true;
    return !!emArquivo && escopoArquivoAtual !== 'arquivo';
}




// Mostrar skeleton loading enquanto carrega
function mostrarSkeletonTickets(idContainer = 'lista-tickets') {
    const lista = document.getElementById(idContainer);
    if (!lista) return;
    const linhas = Array.from({ length: 8 }, () => `
        <tr>
            <td><div class="skeleton-block sm"></div></td>
            <td><div class="skeleton-block md"></div></td>
            <td><div class="skeleton-block lg"></div></td>
            <td><div class="skeleton-block md"></div></td>
            <td><div class="skeleton-block sm"></div></td>
            <td><div class="skeleton-block sm"></div></td>
            <td><div class="skeleton-block sm"></div></td>
            <td><div class="skeleton-block sm"></div></td>
        </tr>`).join('');
    lista.innerHTML = `
        <table class="skeleton-tabela" aria-hidden="true" aria-label="Carregando demandas...">
            <thead><tr>${Array.from({ length: 8 }, () => '<th></th>').join('')}</tr></thead>
            <tbody>${linhas}</tbody>
        </table>`;
}


// Encaminhar a demanda a um operador do setor (gestor/admin).
// Usa demanda-acoes.php (comentário obrigatório + histórico) em vez do PUT direto.
function atribuirTicket(ticketId, setorId) {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (usuarioLogado.tipo !== 'gestor' && usuarioLogado.tipo !== 'admin') {
        return; // operadores não distribuem demandas
    }

    // O setor vem da própria demanda (o admin não pertence a um setor)
    if (setorId === undefined || setorId === null) {
        const ticketLista = (typeof ticketsAtuais !== 'undefined' ? ticketsAtuais : []).find(t => t.id == ticketId);
        setorId = ticketLista ? ticketLista.setor_id : null;
    }
    const filtroSetor = setorId ? `?setor_id=${encodeURIComponent(setorId)}` : '';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'agentes.php' + filtroSetor, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const equipe = JSON.parse(xhr.responseText);

            if (equipe.length === 0) {
                mostrarAlert('Sem operadores', 'Não há operadores ativos neste setor para receber a demanda.');
                return;
            }

            mostrarSelect(
                'Encaminhar a Operador',
                'Selecione quem vai atender a demanda:',
                equipe,
                function (escolhido) {
                    if (!escolhido) return;
                    pedirComentarioEExecutar(
                        ticketId, 'atribuir', { usuario_id: escolhido.id },
                        'Encaminhar a Operador',
                        `Registre o encaminhamento para ${escolhido.nome}:`,
                        `Demanda encaminhada para ${sanitizeHTML(escolhido.nome)}!`
                    );
                }
            );
        } else {
            mostrarAlert('Erro', 'Erro ao carregar a equipe do setor');
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send();
}

// Confirmar atribuição do ticket
function confirmarAtribuicao(ticketId, agenteId, agenteNome) {
    mostrarConfirm(
        'Confirmar Atribuição',
        `Atribuir demanda para ${agenteNome}?`,
        function (confirmado) {
            if (!confirmado) return;

            executarAtribuicao(ticketId, agenteId, agenteNome);
        }
    );
}

// Executar atribuição do ticket
function executarAtribuicao(ticketId, agenteId, agenteNome) {
    // Buscar status atual do ticket
    const ticketAtual = ticketsAtuais.find(t => t.id === ticketId);
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    const dados = {
        agente_id: agenteId,
        atribuido_por: usuario ? usuario.id : 1
    };

    // NÃO enviar status - o backend gerencia automaticamente
    // Se ticket está "novo", backend muda para "em_atendimento"

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            // Recarregar lista de tickets
            carregarTickets();

            // Se estiver na página de detalhes, recarregar detalhes
            const ticketAtivo = localStorage.getItem('cd_ticketAtivo');
            if (ticketAtivo && parseInt(ticketAtivo) === ticketId) {
                setTimeout(() => verDetalhes(ticketId), 300);
            }

            const mensagem = dados.status ?
                `Demanda atribuída para ${agenteNome} e status alterado para "Em atendimento"!` :
                `Demanda atribuída para ${agenteNome} com sucesso!`;

            mostrarToast('Sucesso', mensagem, 'success');
        } else {
            ocultarLoader();
            mostrarAlert('Erro', 'Erro ao atribuir demanda');
        }
    };

    xhr.send(JSON.stringify(dados));
}

// ==== Fase 2: Atribuição, Devolução, Repasse e Transferência de Setor ====

function executarAcaoDemanda(ticketId, acao, extra, comentario, mensagemSucesso) {
    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${window.APP_CONFIG.apiBase}demanda-acoes.php`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', mensagemSucesso || 'Ação registrada com sucesso!', 'success');
            carregarTickets();
            const ticketAtivo = localStorage.getItem('cd_ticketAtivo');
            if (ticketAtivo && parseInt(ticketAtivo) === ticketId) {
                setTimeout(() => verDetalhes(ticketId), 300);
            }
        } else {
            let mensagemErro = 'Erro ao executar ação';
            try { mensagemErro = JSON.parse(xhr.responseText).error || mensagemErro; } catch (e) { }
            mostrarAlert('Erro', mensagemErro);
        }
    };

    xhr.onerror = function () {
        ocultarLoader();
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send(JSON.stringify(Object.assign({ ticket_id: ticketId, acao: acao, comentario: comentario }, extra || {})));
}

function pedirComentarioEExecutar(ticketId, acao, extra, tituloPrompt, mensagemPrompt, mensagemSucesso) {
    mostrarPromptObservacoes(tituloPrompt, mensagemPrompt, function (comentario) {
        if (comentario === null || comentario === undefined) return;
        const texto = comentario.trim();
        if (!texto) {
            mostrarAlert('Comentário obrigatório', 'Descreva o motivo desta ação antes de confirmar.');
            return;
        }
        executarAcaoDemanda(ticketId, acao, extra, texto, mensagemSucesso);
    });
}

// Alterar status do ticket
// Descobre o setor_id de um ticket já carregado (lista atual, lista de fechados ou detalhe aberto),
// para filtrar status/prioridade/categoria pelas opções ativas naquele setor.
function obterSetorIdDoTicket(ticketId) {
    const emLista = (typeof ticketsAtuais !== 'undefined' && ticketsAtuais.find(t => t.id === ticketId))
        || (typeof ticketsFechadosAtuais !== 'undefined' && ticketsFechadosAtuais.find(t => t.id === ticketId));
    if (emLista) return emLista.setor_id || null;

    try {
        const ticketAtual = JSON.parse(sessionStorage.getItem('cd_ticketAtual') || 'null');
        if (ticketAtual && ticketAtual.id === ticketId) return ticketAtual.setor_id || null;
    } catch (e) { }

    return null;
}

function alterarStatus(ticketId, setorId) {
    if (setorId === undefined || setorId === null) {
        setorId = obterSetorIdDoTicket(ticketId);
    }
    const statusOpcoesFallback = [
        { id: 'novo', nome: '🆕 Novo' },
        { id: 'em_atendimento', nome: '🔧 Em atendimento' },
        { id: 'aguardando', nome: '⏳ Aguardando' },
        { id: 'aguardando_resposta', nome: '⏳ Aguardando resposta' },
        { id: 'aguardando_terceiros', nome: '🔄 Aguardando terceiros' },
        { id: 'aguardando_aprovacao', nome: '📋 Aguardando aprovação' },
        { id: 'aguardando_data', nome: '📅 Aguardando data' },
        { id: 'resolvido', nome: '✅ Resolvido' },
        { id: 'encerrado', nome: '🏁 Encerrado' },
        { id: 'cancelado', nome: '❌ Cancelado' }
    ];

    const abrirSelect = (opcoes) => {
        mostrarSelectComDescricao(
            'Alterar Status',
            'Selecione o novo status da demanda:',
            opcoes,
            function (statusEscolhido) {
                if (statusEscolhido) {
                    executarAlteracaoStatus(ticketId, statusEscolhido.id);
                }
            }
        );
    };

    if (typeof carregarStatusDisponiveis !== 'function') {
        abrirSelect(statusOpcoesFallback);
        return;
    }

    carregarStatusDisponiveis(setorId).then(statusList => {
        const opcoes = statusList.map(item => ({
            id: item.nome,
            nome: `${window.STATUS_CONFIG?.[item.nome]?.icone || ''} ${typeof formatarNomeStatus === 'function' ? formatarNomeStatus(item.nome) : item.nome}`,
            descricao: item.descricao || window.STATUS_CONFIG?.[item.nome]?.descricao || ''
        }));
        abrirSelect(opcoes.length > 0 ? opcoes : statusOpcoesFallback);
    }).catch(() => abrirSelect(statusOpcoesFallback));
}

// Alterar categoria do ticket
function alterarCategoria(ticketId) {
    const setorId = obterSetorIdDoTicket(ticketId);
    const filtroSetor = setorId ? `?setor_id=${setorId}` : '';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'categorias.php' + filtroSetor, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            // Mostra "Subcategoria - Categoria": o nome sozinho pode se repetir entre categorias
            const categorias = JSON.parse(xhr.responseText)
                .map(cat => Object.assign({}, cat, { nome: rotuloSubcategoria(cat) }));

            mostrarSelect(
                'Alterar Subcategoria',
                'Selecione a nova subcategoria da demanda:',
                categorias,
                function (categoriaEscolhida) {
                    if (categoriaEscolhida) {
                        executarAlteracaoCategoria(ticketId, categoriaEscolhida.id);
                    }
                }
            );
        } else {
            mostrarAlert('Erro', 'Erro ao carregar subcategorias');
        }
    };

    xhr.send();
}

// Executar alteração de categoria
function executarAlteracaoCategoria(ticketId, categoriaId) {
    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            // Recarregar lista de tickets
            carregarTickets();

            // Se estiver na página de detalhes, recarregar detalhes
            const ticketAtivo = localStorage.getItem('cd_ticketAtivo');
            if (ticketAtivo && parseInt(ticketAtivo) === ticketId) {
                setTimeout(() => verDetalhes(ticketId), 300);
            }

            mostrarToast('Sucesso', 'Subcategoria alterada com sucesso!', 'success');
        } else {
            ocultarLoader();
            // Mensagem do servidor, nunca o JSON cru
            let erroServidor = '';
            try { erroServidor = (JSON.parse(xhr.responseText) || {}).error || ''; } catch (e) { /* resposta não é JSON */ }
            mostrarAlert('Erro', sanitizeHTML(erroServidor || 'Erro ao alterar subcategoria.'));
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão ao alterar subcategoria');
    };

    xhr.send(JSON.stringify({ categoria_id: categoriaId }));
}

// Alterar prioridade do ticket
function alterarPrioridade(ticketId, setorId) {
    if (setorId === undefined || setorId === null) {
        setorId = obterSetorIdDoTicket(ticketId);
    }
    const prioridadeOpcoesFallback = [
        { id: 'baixa', nome: 'Baixa' },
        { id: 'normal', nome: 'Normal' },
        { id: 'alta', nome: 'Alta' },
        { id: 'urgente', nome: 'Urgente' }
    ];

    const abrirSelect = (opcoes) => {
        mostrarSelectComDescricao(
            'Alterar Prioridade',
            'Selecione a nova prioridade da demanda:',
            opcoes,
            function (prioridadeEscolhida) {
                if (prioridadeEscolhida) {
                    executarAlteracaoPrioridade(ticketId, prioridadeEscolhida.id);
                }
            }
        );
    };

    if (typeof carregarPrioridadesDisponiveis !== 'function') {
        abrirSelect(prioridadeOpcoesFallback);
        return;
    }

    carregarPrioridadesDisponiveis(setorId).then(prioridadesList => {
        const opcoes = prioridadesList.map(p => ({
            id: p.nome,
            nome: `${window.PRIORIDADE_CONFIG?.[p.nome]?.icone || ''} ${typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(p.nome) : p.nome}`,
            descricao: p.descricao
                ? `${p.descricao} (SLA: ${p.sla_resposta_minutos}min resposta / ${p.sla_resolucao_horas}h resolução)`
                : ''
        }));
        abrirSelect(opcoes.length > 0 ? opcoes : prioridadeOpcoesFallback);
    }).catch(() => abrirSelect(prioridadeOpcoesFallback));
}

// Executar alteração de prioridade
async function executarAlteracaoPrioridade(ticketId, novaPrioridade, callback) {
    mostrarLoader();

    try {
        await Api.put(`tickets.php?id=${ticketId}`, { prioridade: novaPrioridade });
        ocultarLoader();

        // Recarregar lista de tickets
        carregarTickets();

        // Se estiver na página de detalhes, recarregar detalhes
        const ticketAtivo = localStorage.getItem('cd_ticketAtivo');
        if (ticketAtivo && parseInt(ticketAtivo) === ticketId && !callback) {
            setTimeout(() => verDetalhes(ticketId), 300);
        }

        if (callback) {
            callback();
        }

        const prioridadeNome = typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(novaPrioridade) : novaPrioridade;
        mostrarToast('Sucesso', `Prioridade alterada para "${prioridadeNome}" com sucesso!`, 'success');
    } catch (e) {
        ocultarLoader();
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao alterar prioridade');
    }
}

// Executar alteração de status
async function executarAlteracaoStatus(ticketId, novoStatus, callback, observacoes) {
    mostrarLoader();

    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    const dados = { status: novoStatus };
    if (novoStatus === 'encerrado' || novoStatus === 'cancelado') {
        dados.fechado_por = usuario.id;
    }
    if (observacoes) {
        dados.observacoes = observacoes;
    }

    try {
        await Api.put(`tickets.php?id=${ticketId}`, dados);
        ocultarLoader();

        const mensagem = (novoStatus === 'aberto' || novoStatus === 'atendimento') ? 'Demanda reaberta com sucesso!' : 'Status alterado com sucesso!';

        // Se for encerramento, animar remoção da lista
        if (novoStatus === 'encerrado' || novoStatus === 'cancelado') {
            const row = document.querySelector(`tr[data-ticket-id="${ticketId}"]`);
            if (row) {
                row.classList.add('ticket-removendo');
                setTimeout(() => carregarTickets(), 500);
            } else {
                carregarTickets();
            }
        } else {
            carregarTickets();
        }

        // Se estiver na página de detalhes, recarregar detalhes
        const ticketAtivo = localStorage.getItem('cd_ticketAtivo');
        if (ticketAtivo && parseInt(ticketAtivo) === ticketId && !callback) {
            setTimeout(() => verDetalhes(ticketId), 300);
        }

        // Executar callback se houver
        if (callback) {
            callback();
        }

        mostrarToast('Sucesso', mensagem, 'success');
    } catch (e) {
        ocultarLoader();
        mostrarAlert('Atenção', (e.dados && e.dados.error) || 'Erro ao atualizar demanda');
    }
}

// === LOADER ===

let loaderStartTime = null;

function mostrarLoader() {
    console.log('🔵 mostrarLoader: iniciando...');
    loaderStartTime = Date.now();
    const loaderElement = document.getElementById('loader-overlay');
    console.log('🔵 Elemento loader:', loaderElement);
    if (loaderElement) {
        loaderElement.classList.add('show');
        console.log('✅ Loader mostrado');
    } else {
        console.error('❌ Elemento loader-overlay não encontrado!');
    }
}

function ocultarLoader() {
    console.log('🔴 ocultarLoader: iniciando...');
    const tempoDecorrido = Date.now() - loaderStartTime;
    const tempoRestante = Math.max(0, 500 - tempoDecorrido);
    console.log('🔴 Tempo decorrido:', tempoDecorrido, 'ms, tempo restante:', tempoRestante, 'ms');

    setTimeout(() => {
        const loaderElement = document.getElementById('loader-overlay');
        if (loaderElement) {
            loaderElement.classList.remove('show');
            console.log('✅ Loader ocultado');
        }
    }, tempoRestante);
}

// === MODALS CUSTOMIZADOS ===

let modalCallback = null;
let modalSelectCallback = null;

// Toast notification
function mostrarToast(titulo, mensagem, tipo = 'info') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${tipo}`;

    toast.innerHTML = `
        <div class="toast-header">${titulo}</div>
        <div class="toast-body">${mensagem}</div>
    `;

    container.appendChild(toast);

    // Animar entrada
    setTimeout(() => toast.classList.add('show'), 100);

    // Remover após 5 segundos
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => container.removeChild(toast), 300);
    }, 5000);
}

// Alert customizado (apenas para erros e confirmações)
function mostrarAlert(titulo, mensagem, callback) {
    // Se for sucesso, usar toast
    if (titulo.toLowerCase().includes('sucesso')) {
        mostrarToast(titulo, mensagem, 'success');
        if (callback) callback();
        return;
    }

    document.getElementById('modal-alert-title').textContent = titulo;
    document.getElementById('modal-alert-message').innerHTML = mensagem;

    const header = document.querySelector('#modal-alert .modal-header');
    const btnOk = document.querySelector('#modal-alert .btn-modal');

    header.classList.remove('sucesso', 'erro');
    btnOk.classList.remove('btn-success-modal', 'btn-danger-modal', 'btn-primary-modal');

    if (titulo.toLowerCase().includes('erro')) {
        header.classList.add('erro');
        btnOk.classList.add('btn-danger-modal');
    } else {
        btnOk.classList.add('btn-primary-modal');
    }

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-alert').classList.add('show');
    modalCallback = callback;
}

// Confirm customizado
function mostrarConfirm(titulo, mensagem, callback, isPerigoso = false) {
    document.getElementById('modal-confirm-title').textContent = titulo;
    document.getElementById('modal-confirm-message').textContent = mensagem;

    // Mudar cor do cabeçalho e botão
    const header = document.querySelector('#modal-confirm .modal-header');
    const btnConfirm = document.getElementById('btn-confirm-action');

    header.classList.remove('erro', 'laranja', 'vermelho', 'atribuicao');
    btnConfirm.classList.remove('btn-danger-modal', 'btn-primary-modal', 'btn-laranja-modal', 'btn-vermelho-modal');

    if (titulo.toLowerCase().includes('enviar e fechar')) {
        // Modal "Enviar e Fechar Demanda" usa botão "Sim"
        header.classList.add('atribuicao');
        btnConfirm.classList.add('btn-primary-modal');
        btnConfirm.textContent = 'Sim';
    } else if (titulo.toLowerCase().includes('excluir')) {
        // Modal de exclusão usa laranja CRPRS
        header.classList.add('laranja');
        btnConfirm.classList.add('btn-laranja-modal');
        btnConfirm.textContent = 'Excluir';
    } else if (titulo.toLowerCase().includes('final')) {
        // Confirmação final usa vermelho CRPRS
        header.classList.add('vermelho');
        btnConfirm.classList.add('btn-vermelho-modal');
        btnConfirm.textContent = 'Confirmar';
    } else if (titulo.toLowerCase().includes('atribui')) {
        // Modal de atribuição usa gradiente azul
        header.classList.add('atribuicao');
        btnConfirm.classList.add('btn-primary-modal');
        btnConfirm.textContent = 'Confirmar';
    } else if (isPerigoso) {
        header.classList.add('erro');
        btnConfirm.classList.add('btn-danger-modal');
        btnConfirm.textContent = 'Excluir';
    } else {
        btnConfirm.classList.add('btn-primary-modal');
        btnConfirm.textContent = 'Confirmar';
    }

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-confirm').classList.add('show');
    modalCallback = callback;
}

// Prompt customizado
function mostrarPrompt(titulo, mensagem, callback) {
    document.getElementById('modal-prompt-title').textContent = titulo;
    document.getElementById('modal-prompt-message').textContent = mensagem;
    document.getElementById('modal-prompt-input').value = '';
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');
    modalCallback = callback;
    setTimeout(() => document.getElementById('modal-prompt-input').focus(), 100);
}

// Select customizado
function mostrarSelect(titulo, mensagem, opcoes, callback) {
    document.getElementById('modal-select-title').textContent = titulo;
    document.getElementById('modal-select-message').textContent = mensagem;

    const optionsDiv = document.getElementById('modal-select-options');

    // Adicionar campo de pesquisa se for (sub)categoria
    if (/categoria/i.test(titulo)) {
        optionsDiv.innerHTML = `
            <div style="margin-bottom: 1rem;">
                <input type="text" id="pesquisa-categoria" placeholder="Digite para pesquisar..." 
                       style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div id="opcoes-lista"></div>
        `;

        const pesquisaInput = document.getElementById('pesquisa-categoria');
        const opcoesLista = document.getElementById('opcoes-lista');

        function renderizarOpcoes(opcoesFiltradas) {
            opcoesLista.innerHTML = '';
            opcoesFiltradas.forEach((opcao) => {
                const div = document.createElement('div');
                div.className = 'select-option';
                div.textContent = opcao.nome;
                div.onclick = function () {
                    document.querySelectorAll('.select-option').forEach(opt => opt.classList.remove('selected'));
                    div.classList.add('selected');
                    setTimeout(() => {
                        selecionarModal(opcao);
                    }, 200);
                };
                opcoesLista.appendChild(div);
            });
        }

        // Renderizar todas as opções inicialmente
        renderizarOpcoes(opcoes);

        // Filtrar ao digitar
        pesquisaInput.addEventListener('input', function () {
            const termo = this.value.toLowerCase();
            const opcoesFiltradas = opcoes.filter(opcao =>
                opcao.nome.toLowerCase().includes(termo)
            );
            renderizarOpcoes(opcoesFiltradas);
        });

        // Focar no campo de pesquisa
        setTimeout(() => pesquisaInput.focus(), 100);
    } else {
        // Modal normal sem pesquisa
        optionsDiv.innerHTML = '';
        opcoes.forEach((opcao) => {
            const div = document.createElement('div');
            div.className = 'select-option';
            div.textContent = opcao.nome;
            div.onclick = function () {
                document.querySelectorAll('.select-option').forEach(opt => opt.classList.remove('selected'));
                div.classList.add('selected');
                setTimeout(() => {
                    selecionarModal(opcao);
                }, 200);
            };
            optionsDiv.appendChild(div);
        });
    }

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-select').classList.add('show');
    modalSelectCallback = callback;
}

// Fechar modal
function fecharModal() {
    // Guarda o estado antes de limpar as classes
    const wasAlert = document.getElementById('modal-alert').classList.contains('show');
    
    document.getElementById('modal-overlay').classList.remove('show');
    document.querySelectorAll('.modal').forEach(modal => modal.classList.remove('show'));

    // Executar callback apenas se for alert
    if (modalCallback && wasAlert) {
        const callback = modalCallback;
        modalCallback = null;
        modalSelectCallback = null;
        callback();
    } else {
        modalCallback = null;
        modalSelectCallback = null;
    }
}

// Confirmar modal
function confirmarModal(resultado) {
    console.log('🔘 confirmarModal chamado com:', resultado);
    const callback = modalCallback;
    fecharModal();
    if (callback) callback(resultado);
}

// Prompt modal
function promptModal(valor) {
    console.log('📝 promptModal chamado com:', valor);
    const callback = modalCallback;
    fecharModal();
    if (callback) callback(valor !== null);
}

// Selecionar modal
function selecionarModal(opcao) {
    console.log('selecionarModal chamado com:', opcao);
    console.log('modalSelectCallback:', modalSelectCallback);

    const callback = modalSelectCallback;

    // Fechar modal
    document.getElementById('modal-overlay').classList.remove('show');
    document.querySelectorAll('.modal').forEach(modal => modal.classList.remove('show'));

    // Limpar callback
    modalSelectCallback = null;
    modalCallback = null;

    // Executar callback apenas se houver opção selecionada
    if (opcao && callback) {
        callback(opcao);
    }
}

// === CONTROLE DE VISIBILIDADE POR PAPEL ===

// Mostra/oculta o menu Configurações e seus itens conforme o papel do usuário logado.
// admin: vê tudo. gestor: vê só "Gerenciar Usuários" (escopado ao próprio setor).
// usuario/cliente: menu Configurações fica oculto por completo.
function aplicarVisibilidadePorPapel(tipo) {
    // Lixeira: somente administrador
    const itemLixeira = document.getElementById('nav-lixeira-item');
    if (itemLixeira) itemLixeira.hidden = tipo !== 'admin';

    const menuConfigWrapper = document.getElementById('nav-config-wrapper');
    if (!menuConfigWrapper) return;

    if (tipo === 'admin') {
        menuConfigWrapper.style.display = '';
        return;
    }

    if (tipo === 'gestor') {
        menuConfigWrapper.style.display = '';
        // Gestor: Usuários, Categorias, Ativação por Setor, Horários e Feriados e Personalização
        // (somente leitura). Setores, Configurar e-mail e Auditoria são exclusivos do admin.
        const itensAdminApenas = ['btn-config-setores', 'btn-config-email', 'btn-config-auditoria'];
        itensAdminApenas.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        return;
    }

    // usuario/cliente: sem acesso a Configurações
    menuConfigWrapper.style.display = 'none';
}


// Executar exclusão do ticket
function executarExclusaoTicket(ticketId, callback) {
    // Verificar se ticket está fechado antes de excluir
    const ticketAtual = JSON.parse(sessionStorage.getItem('cd_ticketAtual') || '{}');
    const isTicketFechado = ticketAtual.status === 'encerrado' || ticketAtual.status === 'cancelado';

    // Validar se ticket tem responsável (REMOVIDO: permitir exclusão sem responsável)
    /*
    if (!ticketAtual.agente_id) {
        mostrarAlert('Atenção', 'Esta demanda não possui responsável. Atribua a demanda antes de excluí-la.');
        return;
    }
    */

    // Validar se ticket tem categoria (REMOVIDO: permitir exclusão sem categoria)
    /*
    if (!ticketAtual.categoria_id) {
        mostrarAlert('Atenção', 'Esta demanda não possui subcategoria. Defina uma subcategoria antes de excluí-la.');
        return;
    }
    */

    const xhr = new XMLHttpRequest();
    xhr.open('DELETE', `${window.APP_CONFIG.apiBase}tickets.php?id=${ticketId}`, true);

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const row = document.querySelector(`tr[data-ticket-id="${ticketId}"]`);
            if (row) {
                row.classList.add('ticket-removendo');
            }

            mostrarToast('Sucesso', 'Demanda enviada para a lixeira.', 'success');

            // Aguardar um pouco e redirecionar
            setTimeout(() => {
                if (callback) {
                    callback();
                } else {
                    // Limpar dados e ir para lista apropriada
                    localStorage.removeItem('cd_ticketAtivo');
                    sessionStorage.removeItem('cd_ticketAtual');

                    if (isTicketFechado) {
                        mostrarTicketsFechados();
                    } else {
                        mostrarTicketsComAtualizacao();
                    }
                }
            }, 500);
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao remover demanda');
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send();
}

// Função para obter ícone da prioridade
function obterIconePrioridade(prioridade) {
    const icones = {
        'baixa': '⬇️',
        'normal': '➡️',
        'alta': '⬆️',
        'urgente': '🔥'
    };
    return icones[prioridade] || '➡️';
}

// Restaurar seção ativa após reload
function restaurarSecaoAtiva() {
    const secaoSalva = localStorage.getItem('cd_secaoAtiva');
    const subSecaoSalva = localStorage.getItem('cd_subSecaoAtiva');
    const secaoOrigemSalva = localStorage.getItem('cd_secaoOrigem');
    const ticketAtivo = localStorage.getItem('cd_ticketAtivo');

    // Restaurar seção de origem se houver
    if (secaoOrigemSalva) {
        secaoOrigem = secaoOrigemSalva;
    }

    if (secaoSalva === 'detalhes' && ticketAtivo) {
        // Restaurar detalhes do ticket
        verDetalhes(parseInt(ticketAtivo));
    } else if (secaoSalva === 'novo') {
        mostrarSecao('novo');
        setTimeout(() => {
            if (todasCategorias.length > 0) {
                configurarPesquisaCategoria();
            } else {
                carregarCategorias();
            }
        }, 200);
    } else if (secaoSalva === 'dashboard') {
        mostrarDashboard();
    } else if (secaoSalva === 'fechados') {
        mostrarTicketsFechados();
    } else if (secaoSalva === 'lixeira' && JSON.parse(localStorage.getItem('cd_usuario') || '{}').tipo === 'admin') {
        mostrarLixeira();
    } else if (secaoSalva === 'relatorios') {
        mostrarRelatorios();
    } else if (secaoSalva === 'sobre') {
        mostrarSobre();
    } else if (secaoSalva === 'ajuda') {
        mostrarSecao('ajuda');
        if (subSecaoSalva === 'sla') {
            mostrarSLA();
        } else if (subSecaoSalva === 'status') {
            mostrarStatusGuia();
        } else if (subSecaoSalva === 'prioridade') {
            mostrarPrioridadeGuia();
        } else if (subSecaoSalva === 'fluxos-email') {
            mostrarFluxosEmail();
        } else if (subSecaoSalva === 'atalhos') {
            mostrarAtalhosGuia();
        } else {
            mostrarSLA();
        }
    } else if (secaoSalva === 'config') {
        const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
        if (usuario.tipo !== 'admin' && usuario.tipo !== 'gestor') {
            mostrarSecao('tickets');
            carregarTickets();
        } else if (usuario.tipo === 'gestor') {
            // Gestor: Usuários, Categorias, Ativação por Setor, Horários e Feriados e Personalização (leitura)
            mostrarSecao('config');
            if (subSecaoSalva === 'categorias') {
                mostrarCategorias();
            } else if (subSecaoSalva === 'setor-config') {
                mostrarConfigSetor();
            } else if (subSecaoSalva === 'horarios-sla') {
                mostrarHorariosSLA();
            } else if (subSecaoSalva === 'personalizacao') {
                mostrarPersonalizacao();
            } else {
                mostrarUsuarios();
            }
        } else {
            mostrarSecao('config');
            // Restaurar subseção de configuração
            if (subSecaoSalva === 'setores') {
                mostrarSetores();
            } else if (subSecaoSalva === 'usuarios') {
                mostrarUsuarios();
            } else if (subSecaoSalva === 'categorias') {
                mostrarCategorias();
            } else if (subSecaoSalva === 'setor-config') {
                mostrarConfigSetor();
            } else if (subSecaoSalva === 'auditoria') {
                mostrarAuditoria();
            } else if (subSecaoSalva === 'horarios-sla') {
                mostrarHorariosSLA();
            } else if (subSecaoSalva === 'personalizacao') {
                mostrarPersonalizacao();
            } else if (subSecaoSalva === 'config-email') {
                mostrarConfigEmail();
            } else {
                mostrarUsuarios();
            }
        }
    } else {
        // Página inicial padrão - não fazer nada, já está em tickets
    }
}

// Mostrar alterar senha
function mostrarAlterarSenha() {
    document.querySelectorAll('.secao').forEach(secao => {
        secao.classList.remove('active');
    });

    document.querySelectorAll('.btn-nav').forEach(btn => {
        btn.classList.remove('active');
    });

    document.getElementById('alterar-senha-secao').classList.add('active');

    setTimeout(() => {
        verificarComplexidade();
    }, 100);
}

// Salvar seção ativa
function salvarSecaoAtiva(secaoId) {
    localStorage.setItem('cd_secaoAtiva', secaoId);
    // Limpar subseção se não for config
    if (secaoId !== 'config') {
        localStorage.removeItem('cd_subSecaoAtiva');
    }
}

// Fechar modal ao clicar fora
window.onclick = function (event) {
    const modal = document.getElementById('visualizador-anexo');
    if (event.target === modal) {
        fecharVisualizador();
    }
}

// Atalhos de teclado
document.addEventListener('keydown', function (e) {
    // Ignorar atalhos se estiver digitando em input/textarea
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
        // Permitir apenas ESC em campos de texto
        if (e.key === 'Escape') {
            fecharModal();
            fecharVisualizador();
        }
        return;
    }

    // Alt+N = Novo ticket
    if (e.altKey && e.key === 'n') {
        e.preventDefault();
        mostrarSecao('novo');
        setTimeout(() => {
            if (todasCategorias.length > 0) {
                configurarPesquisaCategoria();
            }
        }, 200);
    }
    // Alt+D = Dashboard
    else if (e.altKey && e.key === 'd') {
        e.preventDefault();
        mostrarDashboard();
    }
    // Alt+T = Tickets
    else if (e.altKey && e.key === 't') {
        e.preventDefault();
        mostrarTicketsComAtualizacao();
    }
    // Alt+F = Fechados
    else if (e.altKey && e.key === 'f') {
        e.preventDefault();
        mostrarTicketsFechados();
    }
    // ESC = Fechar modals
    else if (e.key === 'Escape') {
        fecharModal();
        fecharVisualizador();
    }
});

// Inicializar aplicação
document.addEventListener('DOMContentLoaded', function () {
    // Verificar se há usuário logado (o token fica no cookie HttpOnly; se tiver expirado, a primeira
    // chamada à API responde 401 e tratarRespostaDeSessao leva ao login)
    const usuario = localStorage.getItem('cd_usuario');
    if (!usuario) {
        limparDadosDaSessao();
        window.location.href = './login.html';
        return;
    }

    // Configurar informações do usuário
    const dadosUsuario = JSON.parse(usuario);
    const nomeUsuarioEl = document.getElementById('nome-usuario');
    if (nomeUsuarioEl) {
        nomeUsuarioEl.textContent = dadosUsuario.nome;
    }

    // Mostrar/ocultar menu Configurações e seus itens conforme o papel do usuário
    aplicarVisibilidadePorPapel(dadosUsuario.tipo);

    // Primeiro acesso / senha redefinida: troca obrigatória antes de qualquer outra tela
    if (dadosUsuario.trocar_senha) {
        ativarTrocaObrigatoriaSenha();
        return;
    }

    // Inicializar sistema de SLA
    if (typeof inicializarSistemaSLA === 'function') {
        inicializarSistemaSLA();
    }

    // Verificar se é primeiro acesso após login
    const primeiroAcesso = sessionStorage.getItem('primeiroAcesso');

    if (!primeiroAcesso) {
        // Primeiro acesso - forçar lista de tickets
        sessionStorage.setItem('primeiroAcesso', 'false');
        localStorage.removeItem('cd_secaoAtiva');
        localStorage.removeItem('cd_subSecaoAtiva');
        localStorage.removeItem('cd_ticketAtivo');
        localStorage.removeItem('cd_secaoOrigem');
        carregarTickets();
    } else {
        // Acesso normal - restaurar seção salva
        const secaoSalva = localStorage.getItem('cd_secaoAtiva');

        if (secaoSalva && secaoSalva !== 'tickets') {
            restaurarSecaoAtiva();
        } else {
            carregarTickets();
        }
    }

    // Carregar categorias por último
    carregarCategorias();
    popularPrioridadesFormNovo();

    // Atualizar lista de tickets a cada 1 minuto
    setInterval(() => {
        const secaoAtiva = document.querySelector('.secao.active');
        if (secaoAtiva && (secaoAtiva.id === 'tickets' || secaoAtiva.id === 'fechados')) {
            if (secaoAtiva.id === 'tickets') {
                carregarTickets();
            } else {
                carregarTicketsFechados();
            }
        }
    }, 60000); // 60 segundos

    // Configurar busca avançada
    configurarBuscaAvancada();
    configurarBuscaFechados();



    // Mostrar dica de atalhos
    mostrarDicaAtalhos();

    // Mostrar dicas apenas no segundo login (após completar tutorial)
    const naoMostrar = localStorage.getItem('cd_naoMostrarDicas') === 'true';
    const jaViuTutorial = localStorage.getItem('cd_tutorialCompleto') === 'true';

    if (!naoMostrar && jaViuTutorial && dadosUsuario && ['operador', 'gestor', 'admin'].includes(dadosUsuario.tipo)) {
        setTimeout(() => {
            mostrarDicas();
        }, 2000);
    }
    // NÃO ir para tickets automaticamente
});

// Modo Escuro
function toggleModoEscuro() {
    document.body.classList.toggle('modo-escuro');
    const isDark = document.body.classList.contains('modo-escuro');

    localStorage.setItem('cd_modoEscuro', isDark);

    const btn = document.getElementById('btn-modo-escuro');
    if (btn) {
        btn.textContent = isDark ? '☀️' : '🌙';
        btn.title = isDark ? 'Modo claro' : 'Modo escuro';
    }

    const label = document.getElementById('label-modo-escuro');
    if (label) {
        label.textContent = isDark ? 'Desativar Modo Escuro' : 'Ativar Modo Escuro';
    }

    const icone = document.getElementById('icone-modo-escuro');
    const texto = document.getElementById('texto-modo-escuro');
    if (icone && texto) {
        icone.textContent = isDark ? '☀️' : '🌙';
        texto.textContent = isDark ? 'Modo Claro' : 'Modo Escuro';
    }
}

// Restaurar modo escuro
if (localStorage.getItem('cd_modoEscuro') === 'true') {
    document.body.classList.add('modo-escuro');
    const btn = document.getElementById('btn-modo-escuro');
    if (btn) {
        btn.textContent = '☀️';
        btn.title = 'Modo claro';
    }
}

// Seção Sobre
function mostrarSobre() {
    mostrarSecao('sobre');
    localStorage.removeItem('cd_subSecaoAtiva');

    const content = document.getElementById('sobre-content');
    content.innerHTML = `
        <div class="ajuda-container" style="animation: fadeInUp 0.4s ease-out; max-width: 900px; margin: 0 auto; padding-top: 2.5rem;">

            <!-- Cabeçalho da página -->
            <div style="background: linear-gradient(135deg, #a7241c 0%, #5e130e 100%); color: white; padding: 2.5rem 2rem; border-radius: 14px; margin-bottom: 2rem; box-shadow: 0 4px 20px rgba(167, 36, 28,0.25); display: flex; align-items: center; gap: 2rem; flex-wrap: wrap;">
                <div style="flex-shrink: 0; width: 80px; height: 80px; background: rgba(255,255,255,0.12); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.2);">
                    🖥️
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.75; margin-bottom: 0.3rem;">Sistema de Gestão de Demandas</div>
                    <h2 style="margin: 0 0 0.3rem; font-size: 2rem; font-weight: 700; letter-spacing: -0.5px; color: white; border: none; padding: 0;">Conecta CRPRS</h2>
                    <div style="font-size: 1rem; opacity: 0.9;">Conselho Regional de Psicologia do Rio Grande do Sul</div>
                </div>
                <div style="text-align: center; flex-shrink: 0;">
                    <div style="background: rgba(248, 147, 39,0.85); border-radius: 8px; padding: 0.4rem 1.1rem; display: inline-block; font-size: 0.85rem; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 0.4rem;">VERSÃO</div>
                    <div style="font-size: 2.2rem; font-weight: 800; line-height: 1;">3.0</div>
                </div>
            </div>

            <!-- Cards de informação -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <div style="background: var(--bg-card, #fff); border-radius: 10px; padding: 1.2rem 1.4rem; box-shadow: var(--shadow-sm, 0 1px 4px rgba(0,0,0,0.08)); border-left: 4px solid #a7241c;">
                    <div style="font-size: 1.4rem; margin-bottom: 0.4rem;">🏛️</div>
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary, #666); letter-spacing: 0.8px; margin-bottom: 0.2rem;">Organização</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: var(--text-primary, #a7241c);">CRPRS — Conselho Regional de Psicologia do Rio Grande do Sul</div>
                </div>
                <div style="background: var(--bg-card, #fff); border-radius: 10px; padding: 1.2rem 1.4rem; box-shadow: var(--shadow-sm, 0 1px 4px rgba(0,0,0,0.08)); border-left: 4px solid #f89327;">
                    <div style="font-size: 1.4rem; margin-bottom: 0.4rem;">🛠️</div>
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary, #666); letter-spacing: 0.8px; margin-bottom: 0.2rem;">Stack Tecnológica</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: var(--text-primary, #333);">PHP 8.2 · MySQL · Vanilla JS · Alpine.js · Quill.js</div>
                </div>
                <div style="background: var(--bg-card, #fff); border-radius: 10px; padding: 1.2rem 1.4rem; box-shadow: var(--shadow-sm, 0 1px 4px rgba(0,0,0,0.08)); border-left: 4px solid #28a745;">
                    <div style="font-size: 1.4rem; margin-bottom: 0.4rem;">📅</div>
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary, #666); letter-spacing: 0.8px; margin-bottom: 0.2rem;">Última Atualização</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: var(--text-primary, #333);">Fevereiro de 2026</div>
                </div>
            </div>

            <!-- Changelog 3.0 -->
            <div style="background: var(--bg-card, #fff); border-radius: 12px; box-shadow: var(--shadow-sm, 0 1px 4px rgba(0,0,0,0.08)); overflow: hidden; margin-bottom: 1.5rem;">
                <div style="background: linear-gradient(135deg, #a7241c 0%, #5e130e 100%); padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <span style="font-size: 1.3rem;">📦</span>
                        <span style="color: white; font-size: 1.1rem; font-weight: 700;">Versão 3.0</span>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        
                    </div>
                </div>
                <div style="padding: 1.5rem;">

                    <!-- Tabela de demandas -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #a7241c); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #ddd6cd);">
                            <span>🎫</span> Lista de Demandas
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>O Arquivo (demandas encerradas) ganhou a mesma busca, paginação e velocidade da lista de demandas abertas.</li>
                            <li><strong>Visão Kanban</strong>: alterne entre tabela e quadro, e arraste a demanda entre as colunas (Novo, Em Atendimento, Aguardando, Nova Resposta) para mudar o status.</li>
                            <li>Em telas pequenas (celular), a lista se adapta automaticamente em cartões.</li>
                            <li>Editor de texto com formatação nas demandas e comentários — negrito, listas e imagens.</li>
                            <li>A coluna usada para ordenar a lista fica destacada com uma seta (↑ ou ↓).</li>
                            <li>Filtros rápidos por status, com a quantidade de demandas em cada um.</li>
                            <li>Selo de prazo (SLA) ao lado de cada demanda, com cores indicando o quanto falta ou se já venceu.</li>
                            <li>Escolha quantas demandas mostrar por página (25, 50 ou 100) — o sistema lembra sua preferência.</li>
                            <li>Ícones mais nítidos, compatíveis com o modo escuro.</li>
                        </ul>
                    </div>

                    <!-- Macros -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #a7241c); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #ddd6cd);">
                            <span>⚡</span> Respostas Rápidas (Macros)
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Textos padronizados e já formatados para agilizar o atendimento.</li>
                            <li>Tela própria para criar, editar e excluir — acessível pelo menu do seu perfil.</li>
                            <li>O texto da macro é inserido no comentário sem apagar o que você já tinha escrito.</li>
                        </ul>
                    </div>

                    <!-- Notificações -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #a7241c); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #ddd6cd);">
                            <span>🔔</span> Notificações
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Notificações atualizadas automaticamente, sem precisar recarregar a página.</li>
                            <li>Contador de alertas não lidos sobre o ícone do sino.</li>
                            <li>Veja o histórico de notificações; clicar em uma abre a demanda e marca como lida.</li>
                            <li>Você é avisado de: nova atribuição, nova demanda, novos comentários e lembretes.</li>
                        </ul>
                    </div>

                    <!-- Segurança -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #a7241c); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #ddd6cd);">
                            <span>🛡️</span> Segurança e Privacidade
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Nas demandas encerradas, o e-mail do solicitante aparece parcialmente oculto (ex.: a***@gmail.com), para proteger dados pessoais.</li>
                            <li>Proteção reforçada contra ações indevidas em todas as telas que alteram dados, incluindo as macros.</li>
                        </ul>
                    </div>

                    <!-- BI -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #a7241c); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #ddd6cd);">
                            <span>📈</span> Dashboard
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Indicador de resolução no primeiro contato: percentual de demandas resolvidas já no primeiro atendimento.</li>
                            <li>Dashboard mais rápido para quem usa o sistema ao mesmo tempo.</li>
                        </ul>
                    </div>

                    <!-- Login -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #a7241c); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #ddd6cd);">
                            <span>🔐</span> Tela de Login
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Tela redesenhada, com a identidade visual do CRPRS.</li>
                            <li>Verificação de segurança (captcha) antes do envio; bloqueio temporário após 5 tentativas.</li>
                            <li>Mensagens de erro mais claras para cada situação (captcha, senha incorreta, falha de conexão).</li>
                            <li>Compatível com leitores de tela e com quem prefere menos animações na tela.</li>
                        </ul>
                    </div>

                    <!-- E-mail -->
                    <div style="margin-bottom: 0;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #a7241c); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #ddd6cd);">
                            <span>📧</span> E-mail e Demandas Abertas por E-mail
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>A verificação de e-mails deixou de perder mensagens já lidas em outro aplicativo (celular, Outlook etc.).</li>
                            <li>Proteção contra demandas e comentários duplicados quando o mesmo e-mail chega mais de uma vez.</li>
                            <li>E-mails enviados pelo próprio sistema não geram demandas, evitando loops.</li>
                            <li>Histórico completo dos e-mails enviados, com filtros, paginação e taxa de sucesso.</li>
                            <li>Aviso visual quando há falhas recentes no envio de e-mail (visível a quem configura o e-mail do sistema).</li>
                            <li>Ao comentar em uma demanda de outro responsável, ele é avisado por e-mail e o status muda para "Nova Resposta".</li>
                        </ul>
                    </div>

                </div>
            </div>

            <!-- Rodapé -->
            <div style="text-align: center; padding: 1.5rem; font-size: 0.82rem; color: var(--text-secondary, #888); border-top: 1px solid var(--border-color, #ddd6cd); margin-top: 0.5rem;">
                Conecta CRPRS v3.0 &mdash; Desenvolvido para o Conselho Regional de Psicologia do Rio Grande do Sul (CRPRS)<br>
                <span style="opacity: 0.7;">PHP 8.2 · MySQL · Vanilla JS · Alpine.js · Quill.js · Chart.js</span>
            </div>

        </div>

        <style>
            .modo-escuro #sobre-content code {
                background: #3a3a3a;
                color: #f89327;
                padding: 0.1rem 0.3rem;
                border-radius: 3px;
                font-size: 0.82em;
            }
        </style>
    `;
}

// === BOTÃO VOLTAR AO TOPO ===
function voltarAoTopo() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// Mostrar/ocultar botão baseado no scroll
window.addEventListener('scroll', function () {
    const btn = document.getElementById('btn-voltar-topo');
    if (btn) {
        if (window.pageYOffset > 300) {
            btn.classList.add('show');
        } else {
            btn.classList.remove('show');
        }
    }
});

// Inicialização Geral
document.addEventListener('DOMContentLoaded', function () {
    // Iniciar monitoramento de sessão

    // Inicializar avatar do header
    inicializarAvatar();

    // Atualizar ano no footer se existir
    const anoFooter = document.getElementById('ano-atual');
    if (anoFooter) {
        anoFooter.textContent = new Date().getFullYear();
    }
});
