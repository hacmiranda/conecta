// Escapa texto para inserir em HTML (conteúdo e atributos). Use em todo dado vindo da API.
function sanitizeHTML(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
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

// Retorna headers de autenticação para uso com fetch()
function getAuthHeaders() {
    const token = localStorage.getItem('cd_token');
    const headers = {};
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
        let csrfToken = localStorage.getItem('cd_csrf_token');
        if (!csrfToken) {
            try {
                const partes = token.split('.');
                const payload = JSON.parse(atob(partes[1].replace(/-/g, '+').replace(/_/g, '/')));
                csrfToken = payload.csrf || null;
            } catch (e) {}
        }
        if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
    }
    return headers;
}

// Global Injector de Header (JWT Auth + CSRF) para XMLHttpRequests
window.setSecurityHeaders = function (xhr) {
    const token = localStorage.getItem('cd_token');
    if (token) {
        xhr.setRequestHeader('Authorization', `Bearer ${token}`);
    }
    // CSRF: usa valor salvo no login ou extrai diretamente do JWT
    let csrfToken = localStorage.getItem('cd_csrf_token');
    if (!csrfToken && token) {
        try {
            const partes = token.split('.');
            const payload = JSON.parse(atob(partes[1].replace(/-/g, '+').replace(/_/g, '/')));
            csrfToken = payload.csrf || null;
        } catch (e) {}
    }
    if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    }
};

// Limpa os dados da sessão no navegador e volta ao login (motivo opcional exibido lá)
function encerrarSessaoLocal(motivo) {
    ['cd_usuario', 'cd_token', 'cd_token_exp', 'cd_csrf_token'].forEach(chave => localStorage.removeItem(chave));
    window.location.href = './login.html' + (motivo ? '?sessao=' + encodeURIComponent(motivo) : '');
}

// Função de logout: encerra a sessão no servidor (o token deixa de valer) e no navegador
function fazerLogout() {
    const token = localStorage.getItem('cd_token');
    if (!token) {
        encerrarSessaoLocal();
        return;
    }
    const controle = new AbortController();
    setTimeout(() => controle.abort(), 3000);
    fetch(window.APP_CONFIG.apiBase + 'logout.php', { method: 'POST', headers: getAuthHeaders(), signal: controle.signal, keepalive: true })
        .catch(() => { })
        .finally(() => encerrarSessaoLocal());
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

// Função para configurar headers de segurança
function setSecurityHeaders(xhr) {
    const token = localStorage.getItem('cd_token');
    if (token) {
        xhr.setRequestHeader('Authorization', `Bearer ${token}`);
    }
    // CSRF: usa valor salvo no login ou extrai diretamente do JWT
    let csrfToken = localStorage.getItem('cd_csrf_token');
    if (!csrfToken && token) {
        try {
            const partes = token.split('.');
            const payload = JSON.parse(atob(partes[1].replace(/-/g, '+').replace(/_/g, '/')));
            csrfToken = payload.csrf || null;
        } catch (e) {}
    }
    if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    }
}

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
        const ticketAtual = JSON.parse(localStorage.getItem('cd_ticketAtual') || 'null');
        if (ticketAtual && ticketAtual.id === ticketId) return ticketAtual.setor_id || null;
    } catch (e) { }

    return null;
}

function alterarStatus(ticketId) {
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
        mostrarSelect(
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

    const setorId = obterSetorIdDoTicket(ticketId);
    carregarStatusDisponiveis(setorId).then(statusList => {
        const opcoes = statusList.map(item => ({
            id: item.nome,
            nome: `${window.STATUS_CONFIG?.[item.nome]?.icone || ''} ${typeof formatarNomeStatus === 'function' ? formatarNomeStatus(item.nome) : item.nome}`
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
            const categorias = JSON.parse(xhr.responseText);

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
            mostrarAlert('Erro', 'Erro ao alterar subcategoria: ' + xhr.responseText);
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão ao alterar subcategoria');
    };

    xhr.send(JSON.stringify({ categoria_id: categoriaId }));
}

// Alterar prioridade do ticket
function alterarPrioridade(ticketId) {
    const prioridadeOpcoesFallback = [
        { id: 'baixa', nome: 'Baixa' },
        { id: 'normal', nome: 'Normal' },
        { id: 'alta', nome: 'Alta' },
        { id: 'urgente', nome: 'Urgente' }
    ];

    const abrirSelect = (opcoes) => {
        mostrarSelect(
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

    const setorId = obterSetorIdDoTicket(ticketId);
    carregarPrioridadesDisponiveis(setorId).then(prioridadesList => {
        const opcoes = prioridadesList.map(p => ({
            id: p.nome,
            nome: typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(p.nome) : p.nome
        }));
        abrirSelect(opcoes.length > 0 ? opcoes : prioridadeOpcoesFallback);
    }).catch(() => abrirSelect(prioridadeOpcoesFallback));
}

// Executar alteração de prioridade
function executarAlteracaoPrioridade(ticketId, novaPrioridade) {
    console.log('🔄 Alterando prioridade:', { ticketId, novaPrioridade });
    console.log('📍 Chamando mostrarLoader...');
    mostrarLoader();
    console.log('✅ mostrarLoader chamado');

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        console.log('📡 Resposta do servidor:', xhr.status, xhr.responseText);
        if (xhr.status === 200) {
            // Recarregar lista de tickets
            carregarTickets();

            // Se estiver na página de detalhes, recarregar detalhes
            const ticketAtivo = localStorage.getItem('cd_ticketAtivo');
            if (ticketAtivo && parseInt(ticketAtivo) === ticketId) {
                setTimeout(() => verDetalhes(ticketId), 300);
            }

            ocultarLoader();
            mostrarToast('Sucesso', 'Prioridade alterada com sucesso!', 'success');
        } else {
            ocultarLoader();
            mostrarAlert('Erro', 'Erro ao alterar prioridade: ' + xhr.responseText);
        }
    };

    xhr.onerror = function () {
        ocultarLoader();
        mostrarAlert('Erro', 'Erro de conexão');
    };

    const dados = { prioridade: novaPrioridade };
    console.log('📤 Enviando dados:', dados);
    xhr.send(JSON.stringify(dados));
}

// Executar alteração de status
function executarAlteracaoStatus(ticketId, novoStatus, callback) {

    mostrarLoader();

    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
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
        } else {
            try {
                const erro = JSON.parse(xhr.responseText);
                mostrarAlert('Atenção', erro.error || 'Erro ao atualizar demanda');
            } catch (e) {
                mostrarAlert('Erro', 'Erro ao atualizar demanda');
            }
        }
    };

    xhr.onerror = function () {
        ocultarLoader();
        mostrarAlert('Erro', 'Erro de conexão');
    };

    const dados = { status: novoStatus };
    if (novoStatus === 'encerrado' || novoStatus === 'cancelado') {
        dados.fechado_por = usuario.id;
    }

    console.log('📧 Enviando dados para API:', dados);
    xhr.send(JSON.stringify(dados));
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
    const ticketAtual = JSON.parse(localStorage.getItem('cd_ticketAtual') || '{}');
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
                    localStorage.removeItem('cd_ticketAtual');

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
    // Verificar se há usuário logado e token válido
    const usuario = localStorage.getItem('cd_usuario');
    const token = localStorage.getItem('cd_token');
    if (!usuario || !token) {
        localStorage.clear();
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

// Gráficos simples com Canvas
function getCorLabel() {
    /* Dashboard é sempre dark; retorna cor clara para legibilidade */
    const dashboardAtivo = document.getElementById('dashboard')?.classList.contains('active');
    if (dashboardAtivo || document.body.classList.contains('modo-escuro')) {
        return '#94a3b8';
    }
    return '#475569';
}

/**
 * Acessibilidade (A7): os gráficos abaixo são desenhados à mão em <canvas>, sem nenhuma
 * alternativa textual. Esta função dá ao canvas o papel de imagem e um resumo dos valores,
 * para quem usa leitor de tela.
 */
function descreverGraficoCanvas(canvas, titulo, labels, valores) {
    if (!canvas) return;
    const total = valores.reduce((s, v) => s + (Number(v) || 0), 0);
    const partes = labels.map((l, i) => {
        const v = Number(valores[i]) || 0;
        const pct = total > 0 ? Math.round((v / total) * 100) : 0;
        return `${l}: ${v}${total > 0 ? ` (${pct}%)` : ''}`;
    });
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', partes.length ? `${titulo}. ${partes.join(', ')}.` : `${titulo}. Sem dados no período.`);
}

function criarGraficoStatus(dados) {
    const canvas = document.getElementById('grafico-status');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    // Cor única e dedicada para cada status — sem repetições
    const coresStatus = {
        'novo':                  '#e56b46',
        'aberto':                '#f59e0b',
        'atendimento':           '#2797e1',
        'em_atendimento':        '#2797e1',
        'nova_resposta':         '#06b6d4',
        'aguardando_resposta':   '#d7e744',
        'aguardando_terceiros':  '#c52ee7',
        'aguardando_aprovacao':  '#f97316',
        'aguardando_data':       '#8b5cf6',
        'resolvido':             '#39dd52',
        'encerrado':             '#475569',
        'cancelado':             '#e62c45',
        'reaberto':              '#ec4899'
    };

    const labelsStatus = {
        'novo':                  'Novo',
        'aberto':                'Aberto',
        'atendimento':           'Em atendimento',
        'em_atendimento':        'Em atendimento',
        'nova_resposta':         'Nova resposta',
        'aguardando':            'Aguardando',
        'aguardando_resposta':   'Aguardando resp.',
        'aguardando_terceiros':  'Aguardando terc.',
        'aguardando_aprovacao':  'Aguardando aprov.',
        'aguardando_data':       'Aguardando data',
        'resolvido':             'Resolvido',
        'encerrado':             'Encerrado',
        'cancelado':             'Cancelado',
        'reaberto':              'Reaberto'
    };

    // Paleta de fallback para status desconhecidos (cores distintas, sem repetição dos fixos acima)
    const paletaFallback = ['#10b981', '#0ea5e9', '#a78bfa', '#fb7185', '#34d399', '#facc15'];

    const statusOrdenados = Object.keys(dados.por_status || {}).filter(s => dados.por_status[s] > 0);
    const valores = statusOrdenados.map(s => parseInt(dados.por_status[s]));
    const labels = statusOrdenados.map(s => labelsStatus[s] || s);
    descreverGraficoCanvas(canvas, 'Demandas por status', labels, valores);

    // Atribuir cor única: fixa se mapeada, fallback sem repetição
    const coresUsadas = new Set(Object.values(coresStatus));
    let fallbackIdx = 0;
    const cores = statusOrdenados.map(s => {
        if (coresStatus[s]) return coresStatus[s];
        // Avança no fallback enquanto a cor já estiver em uso
        while (fallbackIdx < paletaFallback.length && coresUsadas.has(paletaFallback[fallbackIdx])) {
            fallbackIdx++;
        }
        const cor = paletaFallback[fallbackIdx] || '#6c757d';
        coresUsadas.add(cor);
        fallbackIdx++;
        return cor;
    });

    const total = valores.reduce((s, v) => s + v, 0) || 1;

    // Layout: rosca à esquerda, legenda à direita
    const raio = 70;
    const raioInterno = raio * 0.54;
    const centerX = raio + 10;
    const centerY = canvas.height / 2;
    const legendaX = centerX + raio + 14;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Calcular fatias
    const fatias = [];
    let anguloAcumulado = -Math.PI / 2; // Começa no topo
    statusOrdenados.forEach((s, i) => {
        const angulo = (valores[i] / total) * 2 * Math.PI;
        fatias.push({
            label: labels[i],
            valor: valores[i],
            cor: cores[i],
            anguloInicio: anguloAcumulado,
            anguloFim: anguloAcumulado + angulo
        });
        anguloAcumulado += angulo;
    });

    // Buraco central da rosca
    function desenharBuraco() {
        ctx.fillStyle = '#0d1117';
        ctx.beginPath();
        ctx.arc(centerX, centerY, raioInterno, 0, Math.PI * 2);
        ctx.fill();
    }

    // Total no centro
    function desenharCentro() {
        ctx.fillStyle = '#94a3b8';
        ctx.font = 'bold 15px Inter, Arial';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(total, centerX, centerY - 6);
        ctx.font = '9px Inter, Arial';
        ctx.fillStyle = '#475569';
        ctx.fillText('demandas', centerX, centerY + 9);
        ctx.textBaseline = 'alphabetic';
    }

    // Legenda: quadrado colorido + nome + valor (pct) na mesma linha
    function desenharLegenda() {
        const alturaItem = 19;
        const totalItens = fatias.length;
        const blocoAltura = totalItens * alturaItem;
        let ly = centerY - blocoAltura / 2 + alturaItem / 2;

        fatias.forEach(f => {
            const pct = Math.round((f.valor / total) * 100);

            // Quadrado colorido
            ctx.fillStyle = f.cor;
            ctx.fillRect(legendaX, ly - 6, 10, 10);

            // Nome do status
            ctx.fillStyle = '#94a3b8';
            ctx.font = '12px Inter, Arial';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText(f.label, legendaX + 14, ly);

            // Valor + percentual logo após o nome
            const larguraNome = ctx.measureText(f.label).width;
            ctx.fillStyle = '#cbd5e1';
            ctx.font = 'bold 12px Inter, Arial';
            ctx.fillText(`${f.valor} (${pct}%)`, legendaX + 14 + larguraNome + 5, ly);

            ctx.textBaseline = 'alphabetic';
            ly += alturaItem;
        });
    }

    // Animar fatias sequencialmente
    let fatiaIndex = 0;

    function desenharProximaFatia() {
        if (fatiaIndex >= fatias.length) {
            desenharBuraco();
            desenharCentro();
            desenharLegenda();
            return;
        }

        const fatia = fatias[fatiaIndex];
        let anguloAtual = fatia.anguloInicio;
        const incremento = (fatia.anguloFim - fatia.anguloInicio) / 25;

        const animacao = setInterval(() => {
            anguloAtual += incremento;
            if (anguloAtual >= fatia.anguloFim) {
                anguloAtual = fatia.anguloFim;
                clearInterval(animacao);
                fatiaIndex++;
                setTimeout(desenharProximaFatia, 80);
            }

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            // Fatias já concluídas
            for (let i = 0; i < fatiaIndex; i++) {
                ctx.fillStyle = fatias[i].cor;
                ctx.beginPath();
                ctx.moveTo(centerX, centerY);
                ctx.arc(centerX, centerY, raio, fatias[i].anguloInicio, fatias[i].anguloFim);
                ctx.closePath();
                ctx.fill();
            }

            // Fatia atual em animação
            ctx.fillStyle = fatia.cor;
            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, raio, fatia.anguloInicio, anguloAtual);
            ctx.closePath();
            ctx.fill();

            desenharBuraco();
            desenharCentro();
            desenharLegenda();
        }, 20);
    }

    desenharProximaFatia();

    // Tooltip: hit-detection por ângulo e raio na rosca
    canvas.addEventListener('mousemove', function(e) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width  / rect.width;
        const scaleY = canvas.height / rect.height;
        const mx = (e.clientX - rect.left) * scaleX;
        const my = (e.clientY - rect.top)  * scaleY;
        const dx = mx - centerX;
        const dy = my - centerY;
        const dist = Math.sqrt(dx * dx + dy * dy);

        if (dist < raioInterno || dist > raio) {
            CanvasTooltip.hide();
            return;
        }

        let angulo = Math.atan2(dy, dx);
        if (angulo < -Math.PI / 2) angulo += 2 * Math.PI;

        const fatia = fatias.find(f => angulo >= f.anguloInicio && angulo <= f.anguloFim);
        if (!fatia) { CanvasTooltip.hide(); return; }

        const pct = Math.round((fatia.valor / total) * 100);
        CanvasTooltip.show(e,
            `<strong style="color:${fatia.cor}">${fatia.label}</strong><br>` +
            `Demandas: <strong>${fatia.valor}</strong><br>` +
            `Percentual: <strong>${pct}%</strong>`
        );
    });
    canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());
}

function criarGraficoPrioridade(dados) {
    const canvas = document.getElementById('grafico-prioridade');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const cores = {
        'baixa': '#475569',
        'normal': '#39dd52',
        'alta': '#e56b46',
        'urgente': '#e62c45'
    };

    const prioridades = ['urgente', 'alta', 'normal', 'baixa'];
    const valores = prioridades.map(p => dados.por_prioridade?.[p] || 0);
    const labels = ['Urgente', 'Alta', 'Normal', 'Baixa'];
    descreverGraficoCanvas(canvas, 'Demandas por prioridade', labels, valores);

    const totalPrioridade = valores.reduce((s, v) => s + v, 0) || 1;
    const maxValor = Math.max(...valores) || 1;
    const alturaBarra = 30;
    const espacamento = 15;
    const margemEsquerda = 100;
    const alturaTotal = valores.length * (alturaBarra + espacamento) - espacamento;
    const offsetY = (canvas.height - alturaTotal) / 2;

    // Mapa de barras para hit-detection
    const barrasPrioridade = valores.map((valor, i) => ({
        label: labels[i],
        cor: cores[prioridades[i]],
        valor,
        x: margemEsquerda,
        y: offsetY + i * (alturaBarra + espacamento),
        largura: (valor / maxValor) * (canvas.width - margemEsquerda - 60),
        altura: alturaBarra
    }));

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    valores.forEach((valor, i) => {
        const larguraFinal = barrasPrioridade[i].largura;
        const y = barrasPrioridade[i].y;

        ctx.fillStyle = getCorLabel();
        ctx.font = '14px Arial';
        ctx.textAlign = 'right';
        ctx.fillText(labels[i], margemEsquerda - 10, y + alturaBarra / 2 + 5);

        let larguraAtual = 0;
        const animacao = setInterval(() => {
            ctx.clearRect(margemEsquerda, y, canvas.width - margemEsquerda, alturaBarra);
            larguraAtual += larguraFinal / 50;
            if (larguraAtual >= larguraFinal) {
                larguraAtual = larguraFinal;
                clearInterval(animacao);
            }
            ctx.fillStyle = cores[prioridades[i]];
            ctx.fillRect(margemEsquerda, y, larguraAtual, alturaBarra);

            ctx.fillStyle = getCorLabel();
            ctx.font = 'bold 14px Arial';
            ctx.textAlign = 'left';
            const pct = totalPrioridade > 0 ? Math.round((valor / totalPrioridade) * 100) : 0;
            ctx.fillText(`${valor} (${pct}%)`, margemEsquerda + larguraAtual + 5, y + alturaBarra / 2 + 5);
        }, 20);
    });

    // Tooltip: hit-detection por retângulo
    canvas.addEventListener('mousemove', function(e) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width  / rect.width;
        const scaleY = canvas.height / rect.height;
        const mx = (e.clientX - rect.left) * scaleX;
        const my = (e.clientY - rect.top)  * scaleY;

        const barra = barrasPrioridade.find(b =>
            mx >= b.x && mx <= b.x + b.largura &&
            my >= b.y && my <= b.y + b.altura
        );
        if (!barra || barra.valor === 0) { CanvasTooltip.hide(); return; }

        const pct = Math.round((barra.valor / totalPrioridade) * 100);
        CanvasTooltip.show(e,
            `<strong style="color:${barra.cor}">${barra.label}</strong><br>` +
            `Demandas: <strong>${barra.valor}</strong><br>` +
            `Percentual: <strong>${pct}%</strong>`
        );
    });
    canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());
}

function criarGraficoMensal(dados) {
    const canvas = document.getElementById('grafico-mensal');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const ctx = canvas.getContext('2d');
                const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
                const valores = meses.map((_, i) => dados.por_mes?.[i + 1] || 0);
                const totalMensal = valores.reduce((s, v) => s + v, 0) || 1;
                descreverGraficoCanvas(canvas, 'Demandas por mês', meses, valores);

                const maxValor = Math.max(...valores) || 1;
                const margemEsquerda = 30;
                const margemDireita = 30;
                const larguraUtil = canvas.width - margemEsquerda - margemDireita;
                const stepX = larguraUtil / (valores.length - 1);

                // Pontos para hit-detection
                const pontosMensal = valores.map((valor, i) => ({
                    mes: meses[i],
                    valor,
                    x: margemEsquerda + i * stepX,
                    y: canvas.height - 40 - (valor / maxValor) * (canvas.height - 60)
                }));

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                valores.forEach((valor, i) => {
                    const x = margemEsquerda + i * stepX;
                    ctx.fillStyle = getCorLabel();
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText(meses[i], x, canvas.height - 5);
                });

                let progress = 0;
                const animacao = setInterval(() => {
                    progress += 0.02;
                    if (progress >= 1) {
                        progress = 1;
                        clearInterval(animacao);
                    }

                    ctx.clearRect(0, 0, canvas.width, canvas.height - 30);

                    const pontosVisiveis = Math.floor(valores.length * progress);

                    ctx.beginPath();
                    const baseY = canvas.height - 40;
                    valores.forEach((valor, i) => {
                        if (i <= pontosVisiveis) {
                            const x = margemEsquerda + i * stepX;
                            const y = canvas.height - 40 - (valor / maxValor) * (canvas.height - 60);
                            if (i === 0) { ctx.moveTo(x, baseY); ctx.lineTo(x, y); }
                            else ctx.lineTo(x, y);
                        }
                    });
                    const ultimoI = Math.min(pontosVisiveis, valores.length - 1);
                    ctx.lineTo(margemEsquerda + ultimoI * stepX, baseY);
                    ctx.closePath();
                    ctx.fillStyle = 'rgba(57, 221, 82, 0.07)';
                    ctx.fill();

                    ctx.strokeStyle = '#39dd52';
                    ctx.lineWidth = 2.5;
                    ctx.beginPath();
                    valores.forEach((valor, i) => {
                        if (i <= pontosVisiveis) {
                            const x = margemEsquerda + i * stepX;
                            const y = canvas.height - 40 - (valor / maxValor) * (canvas.height - 60);
                            if (i === 0) ctx.moveTo(x, y);
                            else ctx.lineTo(x, y);
                        }
                    });
                    ctx.stroke();

                    valores.forEach((valor, i) => {
                        if (i <= pontosVisiveis) {
                            const x = margemEsquerda + i * stepX;
                            const y = canvas.height - 40 - (valor / maxValor) * (canvas.height - 60);
                            ctx.fillStyle = '#39dd52';
                            ctx.beginPath();
                            ctx.arc(x, y, 4, 0, Math.PI * 2);
                            ctx.fill();
                            // Valor em laranja acima do ponto quando > 0
                            if (valor > 0) {
                                ctx.fillStyle = '#e56b46';
                                ctx.font = 'bold 11px Inter, Arial';
                                ctx.textAlign = 'center';
                                ctx.fillText(valor, x, y - 8);
                            }
                        }
                    });

                    valores.forEach((valor, i) => {
                        const x = margemEsquerda + i * stepX;
                        ctx.fillStyle = getCorLabel();
                        ctx.font = '14px Arial';
                        ctx.textAlign = 'center';
                        ctx.fillText(meses[i], x, canvas.height - 5);
                    });
                }, 20);

                // Tooltip: ponto mais próximo no eixo X (raio de captura 30px)
                canvas.addEventListener('mousemove', function(e) {
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width  / rect.width;
                    const mx = (e.clientX - rect.left) * scaleX;

                    let closest = null;
                    let menorDist = Infinity;
                    pontosMensal.forEach(p => {
                        const d = Math.abs(mx - p.x);
                        if (d < menorDist) { menorDist = d; closest = p; }
                    });

                    if (!closest || menorDist > stepX / 2 + 4) { CanvasTooltip.hide(); return; }

                    const pct = Math.round((closest.valor / totalMensal) * 100);
                    CanvasTooltip.show(e,
                        `<strong style="color:#39dd52">${closest.mes}</strong><br>` +
                        `Demandas: <strong>${closest.valor}</strong><br>` +
                        `Percentual: <strong>${pct}%</strong>`
                    );
                });
                canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());

                observer.unobserve(canvas);
            }
        });
    });
    observer.observe(canvas);
}

// Seção Sobre
function mostrarSobre() {
    mostrarSecao('sobre');
    localStorage.removeItem('cd_subSecaoAtiva');

    const content = document.getElementById('sobre-content');
    content.innerHTML = `
        <div class="ajuda-container" style="animation: fadeInUp 0.4s ease-out; max-width: 900px; margin: 0 auto; padding-top: 2.5rem;">

            <!-- Cabeçalho da página -->
            <div style="background: linear-gradient(135deg, #004373 0%, #002d4d 100%); color: white; padding: 2.5rem 2rem; border-radius: 14px; margin-bottom: 2rem; box-shadow: 0 4px 20px rgba(0, 67, 115,0.25); display: flex; align-items: center; gap: 2rem; flex-wrap: wrap;">
                <div style="flex-shrink: 0; width: 80px; height: 80px; background: rgba(255,255,255,0.12); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.2);">
                    🖥️
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.75; margin-bottom: 0.3rem;">Sistema de Gestão de Demandas</div>
                    <h2 style="margin: 0 0 0.3rem; font-size: 2rem; font-weight: 700; letter-spacing: -0.5px; color: white; border: none; padding: 0;">Conecta CRPRS</h2>
                    <div style="font-size: 1rem; opacity: 0.9;">Conselho Regional de Psicologia do Rio Grande do Sul</div>
                </div>
                <div style="text-align: center; flex-shrink: 0;">
                    <div style="background: rgba(247,146,38,0.85); border-radius: 8px; padding: 0.4rem 1.1rem; display: inline-block; font-size: 0.85rem; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 0.4rem;">VERSÃO</div>
                    <div style="font-size: 2.2rem; font-weight: 800; line-height: 1;">3.0</div>
                </div>
            </div>

            <!-- Cards de informação -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <div style="background: var(--bg-card, #fff); border-radius: 10px; padding: 1.2rem 1.4rem; box-shadow: var(--shadow-sm, 0 1px 4px rgba(0,0,0,0.08)); border-left: 4px solid #004373;">
                    <div style="font-size: 1.4rem; margin-bottom: 0.4rem;">🏛️</div>
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary, #666); letter-spacing: 0.8px; margin-bottom: 0.2rem;">Organização</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: var(--text-primary, #004373);">CRPRS — Conselho Regional de Psicologia do Rio Grande do Sul</div>
                </div>
                <div style="background: var(--bg-card, #fff); border-radius: 10px; padding: 1.2rem 1.4rem; box-shadow: var(--shadow-sm, 0 1px 4px rgba(0,0,0,0.08)); border-left: 4px solid #f79226;">
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
                <div style="background: linear-gradient(135deg, #004373 0%, #002d4d 100%); padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
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
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
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
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
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
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
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
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>🛡️</span> Segurança e Privacidade
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Nas demandas encerradas, o e-mail do solicitante aparece parcialmente oculto (ex.: a***@gmail.com), para proteger dados pessoais.</li>
                            <li>Proteção reforçada contra ações indevidas em todas as telas que alteram dados, incluindo as macros.</li>
                        </ul>
                    </div>

                    <!-- BI -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>📈</span> Dashboard
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Indicador de resolução no primeiro contato: percentual de demandas resolvidas já no primeiro atendimento.</li>
                            <li>Dashboard mais rápido para quem usa o sistema ao mesmo tempo.</li>
                        </ul>
                    </div>

                    <!-- Login -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
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
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
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
            <div style="text-align: center; padding: 1.5rem; font-size: 0.82rem; color: var(--text-secondary, #888); border-top: 1px solid var(--border-color, #e5e7eb); margin-top: 0.5rem;">
                Conecta CRPRS v3.0 &mdash; Desenvolvido para o Conselho Regional de Psicologia do Rio Grande do Sul (CRPRS)<br>
                <span style="opacity: 0.7;">PHP 8.2 · MySQL · Vanilla JS · Alpine.js · Quill.js · Chart.js</span>
            </div>

        </div>

        <style>
            .modo-escuro #sobre-content code {
                background: #3a3a3a;
                color: #93c5fd;
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
