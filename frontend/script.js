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

// Download autenticado de anexo via fetch + Blob
async function baixarAnexo(id, nomeOriginal) {
    try {
        const resp = await fetch(`../api/controllers/anexos.php?id=${id}&action=download`, {
            headers: getAuthHeaders()
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            alert('Erro ao baixar anexo: ' + (err.error || resp.statusText));
            return;
        }
        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = nomeOriginal || 'anexo';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch (e) {
        alert('Erro ao baixar anexo.');
        console.error(e);
    }
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
    // Só monitora se for funcionário interno (operador/gestor/admin)
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (!['operador', 'gestor', 'admin'].includes(usuario.tipo)) return;

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
let anexosSelecionados = [];
let anexosComentarioSelecionados = [];
const MAX_ARQUIVOS = 5;
const MAX_TAMANHO = 20 * 1024 * 1024; // 20MB
const TIPOS_PERMITIDOS = ['.pdf', '.doc', '.docx', '.txt', '.csv', '.xls', '.xlsx', '.png', '.jpg', '.jpeg'];

document.getElementById('anexos').addEventListener('change', function (e) {
    adicionarArquivos(Array.from(e.target.files));
    e.target.value = ''; // Limpar input para permitir nova seleção
});

function adicionarMaisAnexos() {
    if (anexosSelecionados.length >= MAX_ARQUIVOS) {
        mostrarAlert('Limite Atingido', 'Máximo de 5 arquivos já selecionados.');
        return;
    }
    document.getElementById('anexos').click();
}

function adicionarArquivos(novosArquivos) {
    const infoDiv = document.getElementById('anexos-info');
    const erroDiv = document.getElementById('anexos-erro');

    // Limpar mensagens de erro
    erroDiv.innerHTML = '';

    // Validar tipos de arquivo
    const arquivosInvalidos = novosArquivos.filter(arquivo => {
        const extensao = '.' + arquivo.name.split('.').pop().toLowerCase();
        return !TIPOS_PERMITIDOS.includes(extensao);
    });

    if (arquivosInvalidos.length > 0) {
        mostrarAlert('Tipo Inválido', `Tipos não permitidos: ${arquivosInvalidos.map(a => a.name).join(', ')}`);
        return;
    }

    // Verificar limite de quantidade
    const totalArquivos = anexosSelecionados.length + novosArquivos.length;
    if (totalArquivos > MAX_ARQUIVOS) {
        const permitidos = MAX_ARQUIVOS - anexosSelecionados.length;
        mostrarAlert('Limite de Arquivos', `Máximo ${MAX_ARQUIVOS} arquivos. Você pode adicionar apenas ${permitidos} arquivo(s).`);
        return;
    }

    // Verificar limite de tamanho
    const tamanhoAtual = anexosSelecionados.reduce((total, arquivo) => total + arquivo.size, 0);
    const tamanhoNovos = novosArquivos.reduce((total, arquivo) => total + arquivo.size, 0);
    const tamanhoTotal = tamanhoAtual + tamanhoNovos;

    if (tamanhoTotal > MAX_TAMANHO) {
        const disponivelMB = ((MAX_TAMANHO - tamanhoAtual) / 1024 / 1024).toFixed(2);
        mostrarAlert('Limite de Tamanho', `Tamanho total excede 20MB. Espaço disponível: ${disponivelMB}MB`);
        return;
    }

    // Adicionar arquivos válidos
    anexosSelecionados = [...anexosSelecionados, ...novosArquivos];
    atualizarListaAnexos();
}

function atualizarListaAnexos() {
    const infoDiv = document.getElementById('anexos-info');

    if (anexosSelecionados.length === 0) {
        infoDiv.innerHTML = '';
        return;
    }

    let infoHtml = '';
    anexosSelecionados.forEach((arquivo, index) => {
        const tamanhoMB = (arquivo.size / 1024 / 1024).toFixed(2);
        infoHtml += `
            <div class="anexo-item">
                <span class="anexo-nome">${arquivo.name}</span>
                <span class="anexo-tamanho">${tamanhoMB}MB</span>
                <button type="button" class="anexo-remover" onclick="removerAnexo(${index})">×</button>
            </div>
        `;
    });

    const tamanhoTotal = anexosSelecionados.reduce((total, arquivo) => total + arquivo.size, 0);
    const totalMB = (tamanhoTotal / 1024 / 1024).toFixed(2);

    infoDiv.innerHTML = `
        <div style="margin-bottom: 0.5rem; font-weight: bold;">Arquivos selecionados (${anexosSelecionados.length}/${MAX_ARQUIVOS} - ${totalMB}MB/20MB):</div>
        ${infoHtml}
    `;
}

function removerAnexo(index) {
    anexosSelecionados.splice(index, 1);
    atualizarListaAnexos();
}

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

// Formulário de novo ticket - VERSÃO COM XMLHttpRequest
document.getElementById('form-ticket').addEventListener('submit', function (e) {
    e.preventDefault();
    // O Alpine.js agora espelha a propriedade content do quillInstance automaticamente para o input.value invisível "descricao"

    const titulo = document.getElementById('titulo').value;
    const descricao = document.getElementById('descricao').value;
    const prioridade = document.getElementById('prioridade').value;
    const categoria_id = document.getElementById('categoria-id').value;
    const email_solicitante = document.getElementById('email-solicitante').value;

    if (!titulo || !descricao) {
        mostrarAlert('Erro', 'Título e descrição são obrigatórios');
        return;
    }

    if (!categoria_id) {
        mostrarAlert('Erro', 'Selecione uma categoria');
        return;
    }

    // Mostrar loader
    mostrarLoader();

    // Criar FormData para incluir arquivos
    const formData = new FormData();
    formData.append('titulo', titulo);
    formData.append('descricao', descricao);
    formData.append('prioridade', prioridade || 'normal');
    // Admin: abre a demanda no setor escolhido no cabeçalho (os demais usam o próprio setor)
    const setorContexto = window.CabecalhoSetor ? window.CabecalhoSetor.obterSetorContexto() : null;
    const usuarioCriador = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (usuarioCriador.tipo === 'admin' && setorContexto) {
        formData.append('setor_id', setorContexto);
    }
    if (categoria_id) {
        formData.append('categoria_id', categoria_id);
    }
    if (email_solicitante) {
        formData.append('email_solicitante', email_solicitante);
    }

    // Adicionar anexos se houver
    if (anexosSelecionados.length > 0) {
        anexosSelecionados.forEach((arquivo, index) => {
            formData.append('anexos[]', arquivo);
        });
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'tickets.php', true);
    xhr.timeout = 30000; // 30 segundos

    // Configurar headers de segurança
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                limparFormularioTicket();
                mostrarToast('Sucesso', 'Demanda criada com sucesso!', 'success');
            } catch (e) {
                console.error('Erro ao parsear resposta:', e);
                mostrarAlert('Erro', 'Erro na resposta do servidor');
            }
        } else {
            try {
                const errorResponse = JSON.parse(xhr.responseText);
                mostrarAlert('Erro', errorResponse.error || 'Erro ao criar demanda');
            } catch (e) {
                mostrarAlert('Erro', 'Erro ao criar demanda');
            }
        }
    };

    xhr.onerror = function () {
        ocultarLoader();
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.ontimeout = function () {
        ocultarLoader();
        mostrarAlert('Erro', 'Timeout na requisição');
    };

    xhr.send(formData);
});

// Carregar tickets do banco de dados (apenas não fechados e não cancelados)
// Acrescenta o setor escolhido pelo admin no cabeçalho (cabecalho-setor.js) à URL da API
function urlComSetor(url) {
    return window.CabecalhoSetor ? window.CabecalhoSetor.anexarSetor(url) : url;
}

// Estado do filtro do Arquivo: 'arquivo' | 'transferidas' | 'repassadas'
let escopoArquivoAtual = 'arquivo';

// A coluna "Setor" só agrega para quem enxerga mais de um setor (admin) ou nas visões
// em que a demanda está em outro setor/com outra pessoa.
function exibirColunaSetor(emArquivo) {
    const u = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (u.tipo === 'admin') return true;
    return !!emArquivo && escopoArquivoAtual !== 'arquivo';
}

function carregarTickets() {
    mostrarSkeletonTickets();
    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'tickets.php?escopo=abertas'), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const todosTickets = JSON.parse(xhr.responseText);
            const ticketsAbertos = todosTickets.filter(ticket => ticket.status !== 'encerrado' && ticket.status !== 'cancelado');
            ticketsAtuais = ticketsAbertos;
            ticketsFiltradosPorStatus = ticketsAbertos;
            paginaAtual = 1;
            aplicarFiltroMeusTickets();
        } else {
            console.error('Erro ao carregar tickets:', xhr.status, xhr.responseText);
            document.getElementById('lista-tickets').innerHTML = '<p>Erro ao carregar demandas.</p>';
        }
    };

    xhr.onerror = function () {
        console.error('Erro de conexão ao carregar tickets');
        document.getElementById('lista-tickets').innerHTML = '<p>Erro de conexão.</p>';
    };

    xhr.send();
}

// Estado de paginação e filtros da tabela
let paginaAtual = 1;
let porPagina = parseInt(localStorage.getItem('cd_ticketsPorPagina') || '50');
let filtroStatusAtivo = 'todos';
let ticketsFiltradosPorStatus = [];
let modoVisualizacaoTickets = localStorage.getItem('cd_ticketsModoView') || 'lista';

function aplicarFiltroMeusTickets() {
    const filtroAtivo = document.getElementById('filtro-meus-tickets').checked;
    let ticketsFiltrados = ticketsAtuais;

    if (filtroAtivo) {
        const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario'));
        ticketsFiltrados = ticketsAtuais.filter(ticket => ticket.agente_id == usuarioLogado.id);
    }

    // Re-aplicar ordenação persistida antes de renderizar
    if (ordemAtual.campo) {
        const campo = ordemAtual.campo;
        ticketsFiltrados = [...ticketsFiltrados].sort((a, b) => {
            let valorA = a[campo] || '';
            let valorB = b[campo] || '';

            if (campo === 'criado_em') {
                valorA = new Date(valorA);
                valorB = new Date(valorB);
            } else if (campo === 'id') {
                valorA = parseInt(valorA);
                valorB = parseInt(valorB);
            }

            if (valorA < valorB) return ordemAtual.direcao === 'asc' ? -1 : 1;
            if (valorA > valorB) return ordemAtual.direcao === 'asc' ? 1 : -1;
            return 0;
        });

        // Atualizar ticketsAtuais com a ordem (quando sem filtro de "meus tickets")
        if (!filtroAtivo) ticketsAtuais = ticketsFiltrados;
    }

    filtrarEMostrarTickets(ticketsFiltrados);
}


function aplicarFiltroMeusTicketsFechados() {
    const filtroAtivo = document.getElementById('filtro-meus-tickets-fechados').checked;
    let ticketsFiltrados = ticketsFechadosAtuais;

    if (filtroAtivo) {
        const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario'));
        ticketsFiltrados = ticketsFechadosAtuais.filter(ticket => ticket.agente_id == usuarioLogado.id);
    }

    mostrarTicketsFechadosNaTela(ticketsFiltrados);
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

// Calcular texto do SLA restante
function calcularTextoSLA(ticket) {
    // O SLA vem do backend via status-sla.js
    // Aqui apenas exibimos o badge com base nos dados disponíveis
    if (!ticket.prazo_sla && !ticket.vencimento_sla) return null;

    if (ticket.vencimento_sla) {
        // Já vencido
        const prazo = new Date(ticket.prazo_sla);
        if (!isNaN(prazo)) {
            const diffMin = Math.floor((Date.now() - prazo.getTime()) / 60000);
            if (diffMin < 60) return { texto: `Vencido há ${diffMin}min`, classe: 'sla-vencido' };
            const diffH = Math.floor(diffMin / 60);
            if (diffH < 24) return { texto: `Vencido há ${diffH}h`, classe: 'sla-vencido' };
            return { texto: `Vencido há ${Math.floor(diffH / 24)}d`, classe: 'sla-vencido' };
        }
        return { texto: 'SLA vencido', classe: 'sla-vencido' };
    }

    if (ticket.prazo_sla) {
        const prazo = new Date(ticket.prazo_sla);
        if (isNaN(prazo)) return null;
        const diffMin = Math.ceil((prazo.getTime() - Date.now()) / 60000);
        if (diffMin <= 0) return { texto: 'SLA vencido', classe: 'sla-vencido' };
        if (diffMin <= 30) return { texto: `${diffMin}min restantes`, classe: 'sla-critico' };
        if (diffMin <= 120) return { texto: `${Math.ceil(diffMin / 60)}h restantes`, classe: 'sla-alerta' };
        const diffH = Math.ceil(diffMin / 60);
        if (diffH < 24) return { texto: `${diffH}h restantes`, classe: 'sla-ok' };
        return { texto: `${Math.ceil(diffH / 24)}d restantes`, classe: 'sla-ok' };
    }
    return null;
}

// Atualizar contadores dos pills de filtro
function atualizarContadoresPill(tickets) {
    const contagens = { todos: tickets.length };
    const statusAgrupados = {
        'novo': ['novo'],
        'em_atendimento': ['em_atendimento'],
        'aguardando': ['aguardando', 'aguardando_resposta', 'aguardando_terceiros', 'aguardando_aprovacao', 'aguardando_data'],
        'nova_resposta': ['nova_resposta'],
        'urgente': [] // filtro por prioridade
    };

    tickets.forEach(t => {
        Object.entries(statusAgrupados).forEach(([pill, statuses]) => {
            if (pill === 'urgente') {
                if (t.prioridade === 'urgente') contagens[pill] = (contagens[pill] || 0) + 1;
            } else if (statuses.includes(t.status)) {
                contagens[pill] = (contagens[pill] || 0) + 1;
            }
        });
    });

    Object.entries(contagens).forEach(([key, val]) => {
        const el = document.getElementById(`pill-count-${key}`);
        if (el) el.textContent = val > 0 ? val : '';
    });
}

// Aplicar filtro rápido por status
function aplicarFiltroPill(btn) {
    filtroStatusAtivo = btn.dataset.status;
    paginaAtual = 1;
    document.querySelectorAll('.filtro-pill').forEach(p => p.classList.remove('ativo'));
    btn.classList.add('ativo');

    const base = (() => {
        const filtroMeus = document.getElementById('filtro-meus-tickets');
        if (filtroMeus && filtroMeus.checked) {
            const u = JSON.parse(localStorage.getItem('cd_usuario'));
            return ticketsAtuais.filter(t => t.agente_id == u.id);
        }
        return ticketsAtuais;
    })();

    filtrarEMostrarTickets(base);
}

function filtrarEMostrarTickets(tickets) {
    const statusAgrupados = {
        'novo': ['novo'],
        'em_atendimento': ['em_atendimento'],
        'aguardando': ['aguardando', 'aguardando_resposta', 'aguardando_terceiros', 'aguardando_aprovacao', 'aguardando_data'],
        'nova_resposta': ['nova_resposta'],
        'urgente': [] // por prioridade
    };

    let filtrados = tickets;
    if (filtroStatusAtivo !== 'todos') {
        if (filtroStatusAtivo === 'urgente') {
            filtrados = tickets.filter(t => t.prioridade === 'urgente');
        } else {
            const grupo = statusAgrupados[filtroStatusAtivo] || [filtroStatusAtivo];
            filtrados = tickets.filter(t => grupo.includes(t.status));
        }
    }

    // Aplicar busca textual se ativa
    const termoBusca = document.getElementById('busca-tickets')?.value.toLowerCase().trim();
    if (termoBusca) {
        filtrados = filtrados.filter(t =>
            (t.titulo || '').toLowerCase().includes(termoBusca) ||
            (t.descricao || '').toLowerCase().includes(termoBusca) ||
            (t.usuario_nome || '').toLowerCase().includes(termoBusca)
        );
    }

    ticketsFiltradosPorStatus = filtrados;
    mostrarTicketsNaTela(filtrados);
}

// Renderizar números de páginas
function renderizarPaginacaoNumeros(totalPaginas) {
    const delta = 2;
    const paginas = [];
    for (let i = Math.max(1, paginaAtual - delta); i <= Math.min(totalPaginas, paginaAtual + delta); i++) {
        paginas.push(i);
    }
    return paginas.map(p => `
        <button class="btn-paginacao ${p === paginaAtual ? 'ativo' : ''}" onclick="irParaPagina(${p})" aria-label="Página ${p}" ${p === paginaAtual ? 'aria-current="page"' : ''}>${p}</button>
    `).join('');
}

function irParaPagina(p) {
    paginaAtual = p;
    mostrarTicketsNaTela(ticketsFiltradosPorStatus.length > 0 ? ticketsFiltradosPorStatus : ticketsAtuais);
    document.getElementById('lista-tickets')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Converter dados de SLA em badge visual para a tabela
// Depende de calcularTempoRestanteSLA() e formatarTempo() do status-sla.js
function calcularTextoSLA(ticket) {
    // Só mostra SLA para tickets abertos e se o sistema de horários já carregou
    if (!ticket || !ticket.criado_em) return null;
    if (['encerrado', 'cancelado', 'fechado'].includes(ticket.status)) return null;
    if (typeof calcularTempoRestanteSLA !== 'function') return null;

    const slaInfo = calcularTempoRestanteSLA(ticket);
    if (!slaInfo) return null;

    const tipo = slaInfo.tipo === 'resposta' ? 'Resposta' : 'Resolução';

    // SLA pausado: badge neutro, sem animação, apenas informa
    if (slaInfo.pausado) {
        return { texto: `⏸ ${tipo}: pausado`, classe: 'sla-pausado' };
    }

    const pct = slaInfo.percentual;

    // Vencido
    if (slaInfo.vencido || pct >= 100) {
        const atrasadoSeg = Math.abs(slaInfo.tempoRestante);
        const atrasadoTexto = formatarTempo(atrasadoSeg).replace('Vencido', '').trim();
        const sufixo = atrasadoTexto ? ` há ${atrasadoTexto}` : '';
        return { texto: `${tipo}: vencida${sufixo}`, classe: 'sla-vencido' };
    }

    // Crítico: ≥ 80%
    if (pct >= 80) {
        return { texto: `⚠ ${tipo}: ${formatarTempo(slaInfo.tempoRestante)}`, classe: 'sla-critico' };
    }

    // Alerta: ≥ 50%
    if (pct >= 50) {
        return { texto: `${tipo}: ${formatarTempo(slaInfo.tempoRestante)}`, classe: 'sla-alerta' };
    }

    // OK: < 50% — não exibe badge para não poluir a tabela
    return null;
}


// === MODO KANBAN ===
function toggleVisaoTickets() {
    modoVisualizacaoTickets = modoVisualizacaoTickets === 'lista' ? 'kanban' : 'lista';
    localStorage.setItem('cd_ticketsModoView', modoVisualizacaoTickets);
    const btn = document.getElementById('btn-toggle-view');
    if (btn) {
        btn.innerHTML = modoVisualizacaoTickets === 'lista' ? '<span class="material-symbols-outlined" style="font-size: 1.2rem; margin-right: 0.3rem;">view_kanban</span> Kanban' : '<span class="material-symbols-outlined" style="font-size: 1.2rem; margin-right: 0.3rem;">list_alt</span> Tabela';
    }
    mostrarTicketsNaTela(ticketsFiltradosPorStatus.length > 0 ? ticketsFiltradosPorStatus : ticketsAtuais);
}

function renderizarKanbanTela(tickets) {
    const listaTickets = document.getElementById('lista-tickets');

    const colunas = {
        'novo': { nome: 'Novo', icon: '🆕', tickets: [] },
        'em_atendimento': { nome: 'Em Atendimento', icon: '🔧', tickets: [] },
        'aguardando': { nome: 'Aguardando', icon: '⏳', tickets: [] },
        'nova_resposta': { nome: 'Resp. Usuário', icon: '💬', tickets: [] }
    };

    // Status não detalhados vão para "Outros" temporariamente 
    tickets.forEach(t => {
        if (colunas[t.status]) {
            colunas[t.status].tickets.push(t);
        } else if (t.status !== 'encerrado' && t.status !== 'cancelado') {
            if (!colunas['outros']) colunas['outros'] = { nome: 'Outros', icon: '📌', tickets: [] };
            colunas['outros'].tickets.push(t);
        }
    });

    let kanbanHTML = `<div class="kanban-board">`;
    Object.keys(colunas).forEach(key => {
        const col = colunas[key];
        kanbanHTML += `
            <div class="kanban-coluna" data-status="${key}" ondrop="dropKanban(event)" ondragover="allowDropKanban(event)">
                <div class="kanban-coluna-header">
                    <span>${col.icon} ${col.nome}</span>
                    <span class="kanban-count">${col.tickets.length}</span>
                </div>
                <div class="kanban-cards">
        `;

        col.tickets.forEach(ticket => {
            const isNovaResposta = ticket.status === 'nova_resposta';
            const tituloSeguro = sanitizeHTML(ticket.titulo || '');
            const slaInfo = calcularTextoSLA(ticket);
            const slaBadge = slaInfo ? `<span class="sla-badge ${slaInfo.classe}" style="display:inline-block; margin: 5px 0 0 0; font-size: 0.7rem;">⏱ ${slaInfo.texto}</span>` : '';

            kanbanHTML += `
                <div class="kanban-card ${isNovaResposta ? 'kanban-nova-resposta' : ''}" draggable="true" ondragstart="dragKanban(event)" data-id="${ticket.id}" onclick="verDetalhes(${ticket.id})">
                    <div class="kanban-card-header">
                        <span class="kanban-ticket-id">#${String(ticket.id).padStart(5, '0')}</span>
                        <span class="kanban-ticket-prioridade p-${ticket.prioridade || 'normal'}">${(ticket.prioridade || 'normal').toUpperCase()}</span>
                    </div>
                    <div class="kanban-card-title" title="${tituloSeguro}">${sanitizeHTML(ticket.titulo)}</div>
                    <div class="kanban-card-info">
                        👤 ${sanitizeHTML(ticket.usuario_nome)}
                    </div>
                    ${slaBadge}
                    <div class="kanban-card-footer">
                        <span class="kanban-agente">${sanitizeHTML(ticket.agente_nome || 'Livre')}</span>
                        <span class="kanban-data">${new Date(ticket.criado_em).toLocaleDateString('pt-BR')}</span>
                    </div>
                </div>
            `;
        });

        kanbanHTML += `</div></div>`;
    });
    kanbanHTML += `</div>`;

    // Adicionar HTML na tela e ocultar paginação se houver placeholder
    listaTickets.innerHTML = kanbanHTML;
}

// DRAG AND DROP API KANBAN
function allowDropKanban(ev) {
    ev.preventDefault();
}

function dragKanban(ev) {
    ev.dataTransfer.setData("ticketId", ev.currentTarget.dataset.id);
}

function dropKanban(ev) {
    ev.preventDefault();
    const ticketId = ev.dataTransfer.getData("ticketId");
    let colTarget = ev.target.closest('.kanban-coluna');

    if (colTarget && ticketId) {
        const novoStatus = colTarget.dataset.status;
        if (novoStatus && novoStatus !== 'outros') {
            executarAlteracaoStatus(ticketId, novoStatus);
        }
    }
}

// Mostrar tickets na tela
function mostrarTicketsNaTela(tickets) {
    const listaTickets = document.getElementById('lista-tickets');

    // Atualizar estado visual do botão
    const btnToggle = document.getElementById('btn-toggle-view');
    if (btnToggle) {
        btnToggle.innerHTML = modoVisualizacaoTickets === 'lista' ? '<span class="material-symbols-outlined" style="font-size: 1.2rem; margin-right: 0.3rem;">view_kanban</span> Kanban' : '<span class="material-symbols-outlined" style="font-size: 1.2rem; margin-right: 0.3rem;">list_alt</span> Tabela';
    }

    // Atualizar contadores
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario'));
    const totalAbertos = ticketsAtuais.length;
    const meusTickets = ticketsAtuais.filter(t => t.agente_id == usuarioLogado.id).length;
    document.getElementById('total-tickets-abertos').textContent = `(${totalAbertos})`;
    document.getElementById('total-meus-tickets').textContent = meusTickets > 0 ? `(${meusTickets})` : '';

    // Atualizar contadores dos pills
    atualizarContadoresPill(ticketsAtuais);

    // Salvar referência para paginação
    ticketsFiltradosPorStatus = tickets;

    if (tickets.length === 0) {
        listaTickets.innerHTML = `
            <div style="text-align:center; padding: 3rem 1rem; color: #64748b;">
                <span class="material-symbols-outlined" style="font-size:3rem; opacity:0.4;">inbox</span>
                <p style="margin-top:0.5rem; font-size:0.95rem;">Nenhuma demanda encontrada para este filtro.</p>
            </div>`;
        return;
    }

    // Processamento do Kanban
    if (modoVisualizacaoTickets === 'kanban') {
        renderizarKanbanTela(tickets);
        return;
    }

    // Salvar tickets globalmente para SLA
    window.ticketsAtuais = tickets;

    // Paginação
    const totalPaginas = Math.ceil(tickets.length / porPagina);
    if (paginaAtual > totalPaginas) paginaAtual = 1;
    const inicio = (paginaAtual - 1) * porPagina;
    const fim = Math.min(inicio + porPagina, tickets.length);
    const ticketsPagina = tickets.slice(inicio, fim);

    let tabelaHTML = `
        <table class="tickets-tabela" role="grid" aria-label="Lista de demandas abertas">
            <thead>
                <tr>
                    <th onclick="ordenarTabela('id')" class="sortable" data-campo="id" scope="col">Nº Demanda <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('criado_em')" class="sortable" data-campo="criado_em" scope="col">Abertura <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('titulo')" class="sortable" data-campo="titulo" scope="col">Assunto <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('usuario_nome')" class="sortable" data-campo="usuario_nome" scope="col">Solicitante <span class="sort-arrows">↕</span></th>
                    ${exibirColunaSetor(false) ? '<th onclick="ordenarTabela(\'setor_nome\')" class="sortable" data-campo="setor_nome" scope="col">Setor <span class="sort-arrows">↕</span></th>' : ''}
                    <th onclick="ordenarTabela('agente_nome')" class="sortable" data-campo="agente_nome" scope="col">Responsável <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('categoria_nome')" class="sortable" data-campo="categoria_nome" scope="col">Subcategoria <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('status')" class="sortable" data-campo="status" scope="col">Status <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('prioridade')" class="sortable" data-campo="prioridade" scope="col">Prioridade <span class="sort-arrows">↕</span></th>
                </tr>
            </thead>
            <tbody>
    `;

    // Atualizar indicador de ordenação ativa
    if (ordemAtual.campo) {
        // Será aplicado depois da renderização
    }

    ticketsPagina.forEach(ticket => {
        const isNovaResposta = ticket.status === 'nova_resposta';

        // Ícones Material Symbols (substituindo PNGs)
        const iconeAnexo = ticket.tem_anexos > 0
            ? `<span class="material-symbols-outlined icone-tabela icone-anexo-ms" data-vg-tooltip="Esta demanda possui arquivos anexados." data-vg-title="ANEXOS" aria-label="Tem anexos">attach_file</span>` : '';
        const iconeComentario = ticket.tem_comentario_nao_lido
            ? `<span class="material-symbols-outlined icone-tabela icone-comentario-ms" data-vg-tooltip="Há um ou mais comentários novos ainda não lidos nesta demanda." data-vg-title="NOVA MENSAGEM" aria-label="Novo comentário">chat_bubble</span>` : '';
        const iconomeLembrete = ticket.proximo_lembrete
            ? `<span class="material-symbols-outlined icone-tabela icone-lembrete-ms" data-vg-tooltip="Lembrete agendado para ${new Date(ticket.proximo_lembrete).toLocaleString('pt-BR')}." data-vg-title="LEMBRETE ATIVO" aria-label="Tem lembrete">event</span>` : '';
        const iconeMesclado = ticket.eh_mesclado > 0
            ? `<span class="material-symbols-outlined icone-tabela" data-vg-tooltip="Esta demanda foi mesclada com outra demanda." data-vg-title="DEMANDA MESCLADA" aria-label="Mesclado" style="color:#6366f1">call_merge</span>` : '';

        // SLA badge inline
        const slaInfo = calcularTextoSLA(ticket);
        const slaBadge = slaInfo
            ? `<span class="sla-badge ${slaInfo.classe}" role="status" aria-label="SLA: ${slaInfo.texto}">⏱ ${slaInfo.texto}</span>` : '';

        // Data formatada sem cor hardcoded
        const dataObj = new Date(ticket.criado_em);
        const data = dataObj.toLocaleDateString('pt-BR');
        const hora = dataObj.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

        // Solicitante
        const solicitante = sanitizeHTML(ticket.email_solicitante || ticket.usuario_nome || '—');
        const emailExibir = (ticket.email_solicitante && ticket.email_solicitante !== ticket.usuario_email)
            ? '' : (ticket.usuario_email ? `<small class="ticket-data-hora">${sanitizeHTML(ticket.usuario_email)}</small>` : '');

        // Título truncado
        const tituloSeguro = sanitizeHTML(ticket.titulo || '');

        tabelaHTML += `
            <tr data-ticket-id="${ticket.id}" class="${isNovaResposta ? 'ticket-nova-resposta' : ''}" ${modoMergeAtivo ? 'style="cursor:pointer;"' : ''}>
                <td data-label="Nº Demanda">
                    <a href="#" onclick="${modoMergeAtivo ? 'event.stopPropagation();' : ''} verDetalhes(${ticket.id})" class="ticket-link">#${String(ticket.id).padStart(5, '0')}</a>
                    ${iconeMesclado}${iconeAnexo}${iconeComentario}${iconomeLembrete}
                </td>
                <td data-label="Abertura">
                    ${data}<span class="ticket-data-hora">${hora}</span>
                    ${slaBadge}
                </td>
                <td class="ticket-titulo-cell" data-label="Assunto">
                    <span class="ticket-titulo-texto" data-vg-tooltip="${tituloSeguro}" data-vg-title="ASSUNTO">${sanitizeHTML(ticket.titulo)}</span>
                </td>
                <td data-label="Solicitante">${solicitante}${emailExibir}</td>
                ${exibirColunaSetor(false) ? `<td data-label="Setor">${sanitizeHTML(ticket.setor_nome || '-')}</td>` : ''}
                <td data-label="Responsável"><span class="status ${ticket.agente_nome ? 'atribuido' : 'nao-atribuido'} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); atribuirTicket(${ticket.id}, ${ticket.setor_id ? Number(ticket.setor_id) : 'null'})" title="Clique para atribuir"`}>${sanitizeHTML(ticket.agente_nome || 'Não atribuído')}</span>${(ticket.agente_nome && ticket.atribuido_em) ? `<small class="ticket-data-hora">desde ${new Date(ticket.atribuido_em).toLocaleDateString('pt-BR')}</small>` : ''}</td>
                <td data-label="Subcategoria"><span class="status categoria ${ticket.categoria_nome ? '' : 'sem-categoria'} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); alterarCategoria(${ticket.id})" title="Clique para alterar"`}>${sanitizeHTML(ticket.categoria_nome || 'Sem subcategoria')}</span></td>
                <td data-label="Status"><span class="status ${ticket.status} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); alterarStatusNovo(${ticket.id}, ${ticket.setor_id || 'null'})"`} title="Clique para alterar">${window.STATUS_CONFIG && window.STATUS_CONFIG[ticket.status] ? window.STATUS_CONFIG[ticket.status].nome : (typeof formatarNomeStatus === 'function' ? formatarNomeStatus(ticket.status) : ticket.status)}</span></td>
                <td data-label="Prioridade"><span class="status prioridade ${ticket.prioridade || 'normal'} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); alterarPrioridade(${ticket.id})" title="Clique para alterar"`}>${typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(ticket.prioridade) : (ticket.prioridade ? ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1).toLowerCase() : 'Normal')}</span></td>
            </tr>
        `;
    });

    // Footer: resumo + paginação
    const inicioExib = inicio + 1;
    const fimExib = fim;
    const totalExib = tickets.length;

    tabelaHTML += `
            </tbody>
        </table>
        <div class="tabela-footer" role="navigation" aria-label="Paginação de demandas">
            <div class="tabela-resumo">
                Exibindo <strong>${inicioExib}–${fimExib}</strong> de <strong>${totalExib}</strong> demanda${totalExib !== 1 ? "s" : ""}
            </div>
            <div class="paginacao">
                <button class="btn-paginacao" onclick="irParaPagina(${paginaAtual - 1})" ${paginaAtual <= 1 ? 'disabled' : ''} aria-label="Página anterior">‹</button>
                ${renderizarPaginacaoNumeros(totalPaginas)}
                <button class="btn-paginacao" onclick="irParaPagina(${paginaAtual + 1})" ${paginaAtual >= totalPaginas ? 'disabled' : ''} aria-label="Próxima página">›</button>
                <span class="paginacao-info">de ${totalPaginas}</span>
            </div>
            <div class="paginacao-por-pagina">
                <label for="tickets-por-pagina">Por página:</label>
                <select id="tickets-por-pagina" onchange="alterarPorPagina(this.value)" aria-label="Itens por página">
                    <option value="25" ${porPagina === 25 ? 'selected' : ''}>25</option>
                    <option value="50" ${porPagina === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${porPagina === 100 ? 'selected' : ''}>100</option>
                </select>
            </div>
        </div>
    `;

    listaTickets.innerHTML = tabelaHTML;

    // Aplicar indicador de ordenação ativa ao th correto
    if (ordemAtual.campo) {
        const ths = listaTickets.querySelectorAll('th[data-campo]');
        ths.forEach(th => {
            const arrow = th.querySelector('.sort-arrows');
            if (th.dataset.campo === ordemAtual.campo) {
                th.classList.add('sort-ativo');
                if (arrow) arrow.textContent = ordemAtual.direcao === 'asc' ? '↑' : '↓';
            } else {
                th.classList.remove('sort-ativo');
                if (arrow) arrow.textContent = '↕';
            }
        });
    }

    // Adicionar event listeners para modo merge
    if (modoMergeAtivo) {
        document.querySelectorAll('tr[data-ticket-id]').forEach(row => {
            row.addEventListener('click', function () {
                const ticketId = parseInt(this.getAttribute('data-ticket-id'));
                selecionarTicketParaMerge(ticketId, this);
            });
        });
    }

    // Atualizar indicadores de SLA
    setTimeout(() => {
        if (typeof atualizarIndicadoresSLA === 'function') atualizarIndicadoresSLA();
    }, 100);
}

function alterarPorPagina(valor) {
    porPagina = parseInt(valor);
    localStorage.setItem('cd_ticketsPorPagina', porPagina);
    paginaAtual = 1;
    mostrarTicketsNaTela(ticketsFiltradosPorStatus.length > 0 ? ticketsFiltradosPorStatus : ticketsAtuais);
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

// Devolver a demanda ao gestor do setor (quem está com ela atribuída)
function devolverAoGestor(ticketId) {
    pedirComentarioEExecutar(
        ticketId, 'devolver', {},
        'Devolver ao Gestor',
        'Explique o motivo da devolução:',
        'Demanda devolvida ao gestor!'
    );
}

// Repassar a demanda para um colega do mesmo setor
function repassarParaColega(ticketId) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'agentes.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status !== 200) {
            mostrarAlert('Erro', 'Erro ao carregar colegas do setor');
            return;
        }
        const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
        const colegas = JSON.parse(xhr.responseText).filter(u => u.id != usuario.id);

        if (colegas.length === 0) {
            mostrarAlert('Sem colegas', 'Não há outros colegas no seu setor para repassar esta demanda.');
            return;
        }

        mostrarSelect('Repassar Demanda', 'Selecione o colega que vai assumir:', colegas, function (colegaEscolhido) {
            if (!colegaEscolhido) return;
            pedirComentarioEExecutar(
                ticketId, 'reatribuir_colega', { usuario_id: colegaEscolhido.id },
                'Repassar Demanda',
                `Explique o motivo de repassar para ${colegaEscolhido.nome}:`,
                `Demanda repassada para ${sanitizeHTML(colegaEscolhido.nome)}!`
            );
        });
    };

    xhr.send();
}

// Transferir a demanda para outro setor
function transferirParaSetor(ticketId, setorAtualId) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'setores.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status !== 200) {
            mostrarAlert('Erro', 'Erro ao carregar setores');
            return;
        }
        const setoresDisponiveis = JSON.parse(xhr.responseText)
            .filter(s => s.ativo == 1 && s.id != setorAtualId)
            .map(s => ({ id: s.id, nome: s.nome }));

        if (setoresDisponiveis.length === 0) {
            mostrarAlert('Sem setores', 'Não há outro setor ativo disponível para transferência.');
            return;
        }

        mostrarSelect('Transferir para Outro Setor', 'Selecione o setor de destino:', setoresDisponiveis, function (setorEscolhido) {
            if (!setorEscolhido) return;
            pedirComentarioEExecutar(
                ticketId, 'transferir_setor', { setor_id: setorEscolhido.id },
                'Transferir para Outro Setor',
                `Explique o motivo da transferência para ${setorEscolhido.nome}:`,
                `Demanda transferida para ${sanitizeHTML(setorEscolhido.nome)}!`
            );
        });
    };

    xhr.send();
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

// Variável para controlar de onde veio o ticket
let secaoOrigem = 'tickets';

// Ver detalhes do ticket
function verDetalhes(ticketId) {
    // Marcar comentários como lidos
    marcarComentariosLidos(ticketId);

    // Detectar seção atual apenas se não estiver já nos detalhes
    const secaoAtiva = document.querySelector('.secao.active');
    if (secaoAtiva && secaoAtiva.id !== 'detalhes') {
        secaoOrigem = secaoAtiva.id;
        localStorage.setItem('cd_secaoOrigem', secaoOrigem);
    }

    // Salvar ticket ativo
    localStorage.setItem('cd_ticketAtivo', ticketId);
    localStorage.setItem('cd_secaoAtiva', 'detalhes');

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}tickets.php?id=${ticketId}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            const ticket = JSON.parse(xhr.responseText);
            // Salvar dados do ticket para saber se é fechado
            localStorage.setItem('cd_ticketAtual', JSON.stringify(ticket));
            mostrarSecao('detalhes');

            setTimeout(() => {
                mostrarDetalhesTicket(ticket);

                // Inicializando o Quill APÓS o DOM do ticket ser montado
                window.quillComentario = new Quill('#editor-comentario', {
                    theme: 'snow',
                    placeholder: 'Escreva sua resposta ou nota interna aqui.',
                    modules: {
                        toolbar: [
                            ['bold', 'italic', 'underline', 'strike'],
                            ['blockquote', 'code-block'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            [{ 'size': ['small', false, 'large', 'huge'] }],
                            [{ 'color': [] }, { 'background': [] }],
                            ['link'],
                            ['clean']
                        ]
                    }
                });

                carregarLembretes(ticketId);
                if (typeof carregarSeletorMacros === 'function') carregarSeletorMacros();
            }, 100);
        } else {
            console.error('Erro ao carregar detalhes:', xhr.status, xhr.responseText);
            mostrarAlert('Erro', 'Erro ao carregar detalhes da demanda');
        }
    };

    xhr.onerror = function () {
        ocultarLoader();
        console.error('Erro de conexão ao carregar detalhes');
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send();
}

function marcarComentariosLidos(ticketId) {
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}comentarios.php?marcar_lido=1&ticket_id=${ticketId}&usuario_id=${usuario.id}`);
    setSecurityHeaders(xhr);
    xhr.send();
}

// Formatar ações do histórico
function formatarAcaoHistorico(acao, valorAnterior, valorNovo) {
    // Valores do histórico vêm do banco (nomes, setores, e-mails): sempre como texto
    acao = sanitizeHTML(acao);
    valorAnterior = valorAnterior == null ? valorAnterior : sanitizeHTML(valorAnterior);
    valorNovo = valorNovo == null ? valorNovo : sanitizeHTML(valorNovo);
    const formatarStatus = (status) => {
        if (!status) return 'N/A';
        return typeof formatarNomeStatus === 'function' ? formatarNomeStatus(status) :
            (STATUS_CONFIG[status]?.nome || status.charAt(0).toUpperCase() + status.slice(1).toLowerCase());
    };

    const formatarPrioridade = (prioridade) => {
        if (!prioridade) return 'N/A';
        return typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(prioridade) :
            (prioridade.charAt(0).toUpperCase() + prioridade.slice(1).toLowerCase());
    };

    switch (acao) {
        case 'abertura':
            return `abriu a demanda <strong>${valorNovo || ''}</strong>`;
        case 'status':
            const statusAnt = formatarStatus(valorAnterior);
            const statusNovo = formatarStatus(valorNovo);
            return `alterou o status de <strong>${statusAnt}</strong> para <strong>${statusNovo}</strong>`;
        case 'prioridade':
            const priorAnt = formatarPrioridade(valorAnterior);
            const priorNova = formatarPrioridade(valorNovo);
            return `alterou a prioridade de <strong>${priorAnt}</strong> para <strong>${priorNova}</strong>`;
        case 'categoria':
            const catAnt = valorAnterior || 'Sem subcategoria';
            const catNova = valorNovo || 'Sem subcategoria';
            return `alterou a categoria de <strong>${catAnt}</strong> para <strong>${catNova}</strong>`;
        case 'atribuicao':
            const agenteAnt = valorAnterior && valorAnterior !== 'Não atribuído' ? valorAnterior : null;
            const agenteNovo = valorNovo || 'Não atribuído';
            if (agenteAnt) {
                return `reatribuiu a demanda de <strong>${agenteAnt}</strong> para <strong>${agenteNovo}</strong>`;
            } else {
                return `atribuiu a demanda para <strong>${agenteNovo}</strong>`;
            }
        case 'merge':
            return `mesclou as demandas <strong>#${valorNovo}</strong> nesta demanda`;
        case 'atribuicao_supervisor':
            return `encaminhou a demanda para <strong>${valorNovo}</strong>`;
        case 'devolucao':
            return `devolveu a demanda ao gestor (estava com <strong>${valorAnterior}</strong>)`;
        case 'reatribuicao_colega':
            return `repassou a demanda de <strong>${valorAnterior}</strong> para <strong>${valorNovo}</strong>`;
        case 'exclusao':
            return 'enviou a demanda para a <strong>lixeira</strong>';
        case 'restauracao':
            return 'restaurou a demanda da <strong>lixeira</strong>';
        case 'transferencia_setor':
            return `transferiu a demanda do setor <strong>${valorAnterior}</strong> para <strong>${valorNovo}</strong>`;
        default:
            return `realizou a ação: <strong>${acao}</strong>`;
    }
}

// Monta os botões de ação de fluxo multi-setor (encaminhar/devolver/repassar/transferir)
// disponíveis conforme o papel do usuário logado e o estado atual da demanda.
function gerarAcoesDemandaHTML(ticket) {
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    if (!usuario) return '';

    const demandaEncerrada = ['encerrado', 'fechado', 'cancelado'].includes(ticket.status);
    if (demandaEncerrada) return '';

    const ehResponsavelAtual = ticket.agente_id && ticket.agente_id == usuario.id;
    const setorDaDemanda = ticket.setor_id ? Number(ticket.setor_id) : 'null';
    const botoes = [];

    if (usuario.tipo === 'gestor' || usuario.tipo === 'admin') {
        botoes.push(`<button class="btn-primary btn-acao-encaminhar" onclick="atribuirTicket(${ticket.id}, ${setorDaDemanda})">👤 Encaminhar a Operador</button>`);
    }

    if (usuario.tipo === 'operador' && ehResponsavelAtual) {
        botoes.push(`<button class="btn-primary btn-acao-devolver" onclick="devolverAoGestor(${ticket.id})">↩️ Devolver ao Gestor</button>`);
        botoes.push(`<button class="btn-primary btn-acao-repassar" onclick="repassarParaColega(${ticket.id})">🔁 Repassar a Colega</button>`);
    }

    if (usuario.tipo === 'gestor' || usuario.tipo === 'admin' || (usuario.tipo === 'operador' && ehResponsavelAtual)) {
        botoes.push(`<button class="btn-primary btn-acao-transferir" onclick="transferirParaSetor(${ticket.id}, ${setorDaDemanda})">🏢 Transferir para Outro Setor</button>`);
    }

    if (botoes.length === 0) return '';

    return `
        <div class="acoes-demanda-section">
            <h3 class="acoes-demanda-titulo">Ações da Demanda</h3>
            <div class="acoes-demanda-botoes">
                ${botoes.join('')}
            </div>
        </div>
    `;
}

// Mostrar detalhes do ticket
function mostrarDetalhesTicket(ticket) {
    const detalhesDiv = document.getElementById('ticket-detalhes');

    if (!detalhesDiv) {
        return;
    }

    // Garantir que ticket tenha propriedades necessárias
    ticket.comentarios = ticket.comentarios || [];
    ticket.anexos = ticket.anexos || [];
    ticket.historico = ticket.historico || [];

    let comentariosHtml = '';
    if (ticket.comentarios && ticket.comentarios.length > 0) {
        ticket.comentarios.forEach(item => {
            if (item.tipo === 'comentario') {
                comentariosHtml += `
                    <div class="comentario-wrapper">
                        <div class="comentario-info">
                            <strong>💬 ${sanitizeHTML(item.autor_nome)}</strong> - ${new Date(item.criado_em).toLocaleString('pt-BR')}<br>
                            <strong style="color: #666; font-size: 0.9em;">Comentário:</strong>
                        </div>
                        <div class="comentario">
                            ${htmlSeguro(item.comentario)}
                        </div>
                `;

                if (item.anexos && item.anexos.length > 0) {
                    comentariosHtml += `
                        <div style="margin-top: 0.75rem;">
                            <strong style="color: #666; font-size: 0.9em; margin-bottom: 0.5rem; display: block;"><img src="images/clipe-de-anexo.png" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;">Anexos (${item.anexos.length}):</strong>
                    `;
                    item.anexos.forEach(anexo => {
                        const tipo = anexo.tipo || '';
                        const isPDF = tipo === 'application/pdf';
                        const isImage = tipo.startsWith('image/');
                        const nomeExibir = anexo.nome_original || anexo.nome_arquivo;
                        const tamanhoMB = (anexo.tamanho / 1024 / 1024).toFixed(2);
                        comentariosHtml += `
                            <div style="background: #f8f9fa; padding: 0.75rem; margin: 0.5rem 0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>${sanitizeHTML(nomeExibir)}</strong><br>
                                    <small style="color: #666;">${tamanhoMB}MB</small>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    ${isPDF || isImage ? `<button class="btn-visualizar" onclick="visualizarAnexo(${anexo.id}, '${tipo}')">👁️ Visualizar</button>` : ''}
                                    <button class="btn-small" onclick="baixarAnexo(${anexo.id}, decodeURIComponent('${argJs(anexo.nome_original || anexo.nome_arquivo)}'))">⬇ Download</button>
                                </div>
                            </div>
                        `;
                    });
                    comentariosHtml += `</div>`;
                }

                comentariosHtml += `</div>`;
            } else if (item.tipo === 'historico') {
                let acao;
                if (item.acao === 'email_enviado' || item.acao === 'email_copia') {
                    acao = '📧 ' + sanitizeHTML(item.valor_novo);
                } else if (item.valores_formatados) {
                    // Usar valores já formatados do backend
                    const partes = item.valores_formatados.split(' → ').map(p => sanitizeHTML(p));
                    if (item.acao === 'categoria') {
                        acao = `alterou a categoria de <strong>${partes[0]}</strong> para <strong>${partes[1]}</strong>`;
                    } else if (item.acao === 'atribuicao') {
                        if (partes[0] === 'Não atribuído') {
                            acao = `atribuiu a demanda para <strong>${partes[1]}</strong>`;
                        } else {
                            acao = `reatribuiu a demanda de <strong>${partes[0]}</strong> para <strong>${partes[1]}</strong>`;
                        }
                    } else {
                        acao = formatarAcaoHistorico(item.acao, item.valor_anterior, item.valor_novo);
                    }
                } else {
                    acao = formatarAcaoHistorico(item.acao, item.valor_anterior, item.valor_novo);
                }

                comentariosHtml += `
                    <div class="historico-wrapper">
                        <div class="historico-info">
                            <strong>📅 ${sanitizeHTML(item.autor_nome || 'Sistema')}</strong> - ${new Date(item.criado_em).toLocaleString('pt-BR')}
                        </div>
                        <div class="historico-acao">
                            ${acao}
                        </div>
                    </div>
                `;
            }
        });
    }

    if (!comentariosHtml) {
        comentariosHtml = '<p style="color: #999; font-style: italic;">Nenhum comentário ou histórico ainda.</p>';
    }

    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    const isAdmin = usuario && usuario.tipo === 'admin';

    // Botões do topo e faixa de aviso conforme o tipo de acesso
    let botoesTopo = '';
    let faixaAviso = '';
    if (ticket.na_lixeira) {
        faixaAviso = `<div class="faixa-aviso-demanda faixa-lixeira" role="status">🗑️ Esta demanda está na <strong>lixeira</strong> desde ${ticket.excluido_em ? new Date(ticket.excluido_em.replace(' ', 'T')).toLocaleString('pt-BR') : ''}${ticket.excluido_por_nome ? ' (excluída por ' + sanitizeHTML(ticket.excluido_por_nome) + ')' : ''}. Restaure-a para voltar a trabalhar nela.</div>`;
        botoesTopo = `<button class="btn-reabrir" onclick="restaurarDemanda(${Number(ticket.id)})" style="margin-left: 1rem; background-color: #28a745;">♻️ Restaurar</button>
            <button class="btn-excluir" onclick="excluirDefinitivo(${Number(ticket.id)})" style="margin-left: 1rem; background-color: var(--vermelho);">🗑️ Excluir definitivamente</button>`;
    } else if (ticket.acesso_historico) {
        faixaAviso = `<div class="faixa-aviso-demanda" role="status">👁️ Você está vendo o <strong>histórico</strong> desta demanda, que passou pelo seu atendimento. Ela está hoje no setor <strong>${sanitizeHTML(ticket.setor_nome || '—')}</strong>${ticket.agente_nome ? ' com <strong>' + sanitizeHTML(ticket.agente_nome) + '</strong>' : ' sem responsável'}. Somente leitura.</div>`;
    } else if (ticket.somente_leitura) {
        faixaAviso = `<div class="faixa-aviso-demanda" role="status">👁️ Você pode acompanhar esta demanda e comentar, mas só o responsável ou a gestão do setor altera status, prioridade e responsável.</div>`;
    } else {
        botoesTopo = `${ticket.status === 'encerrado' || ticket.status === 'fechado' ?
            `<button class="btn-reabrir" onclick="reabrirTicket(${ticket.id})" style="margin-left: 1rem; background-color: #28a745;">🔄 Reabrir</button>` : ''}
            <button class="btn-excluir" onclick="excluirTicket(${ticket.id})" style="margin-left: 1rem; background-color: var(--vermelho);">🗑️ Excluir</button>`;
    }
    const classeAcesso = (ticket.na_lixeira || ticket.acesso_historico) ? 'detalhe-somente-leitura detalhe-sem-comentar'
        : (ticket.somente_leitura ? 'detalhe-somente-leitura' : '');

    detalhesDiv.innerHTML = `
        <div style="margin-top: 100px; margin-bottom: 1rem; padding: 1rem 0; position: relative; z-index: 999;">
            <button class="btn-voltar" onclick="voltarParaOrigem()">← Voltar</button>
            ${botoesTopo}
        </div>
        ${faixaAviso}

        <div class="ticket-detalhes ${classeAcesso}">
            <div class="ticket-header">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <span class="ticket-id" style="font-size: 1.5rem;">#${String(ticket.id).padStart(5, '0')}</span>
                    ${ticket.eh_mesclado > 0 ? '<span title="Demanda mesclada" style="font-size: 1.5rem;">🔗</span>' : ''}
                    ${ticket.status === 'fechado' ? '<span class="carimbo-fechado">FECHADO</span>' : ''}
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <span class="status ${ticket.agente_nome ? 'atribuido' : 'nao-atribuido'} ${ticket.status === 'fechado' ? 'disabled' : 'clickable'}" ${ticket.status === 'fechado' ? '' : `onclick="atribuirTicket(${ticket.id}, ${ticket.setor_id ? Number(ticket.setor_id) : 'null'})" title="Clique para atribuir"`}>
                        ${sanitizeHTML(ticket.agente_nome || 'Não atribuído')}
                    </span>
                    <span class="status categoria ${ticket.categoria_nome ? '' : 'sem-categoria'} ${ticket.status === 'fechado' ? 'disabled' : 'clickable'}" ${ticket.status === 'fechado' ? '' : `onclick="alterarCategoria(${ticket.id})" title="Clique para alterar"`}>
                        ${sanitizeHTML(ticket.categoria_nome || 'Sem subcategoria')}
                    </span>
                    <span class="status ${ticket.status || 'aberto'} ${ticket.status === 'encerrado' ? 'disabled' : 'clickable'}" ${ticket.status === 'encerrado' ? '' : `onclick="alterarStatus(${ticket.id})" title="Clique para alterar"`}>
                        ${typeof formatarNomeStatus === 'function' ? formatarNomeStatus(ticket.status || 'aberto') : (window.STATUS_CONFIG && window.STATUS_CONFIG[ticket.status || 'aberto'] ? window.STATUS_CONFIG[ticket.status || 'aberto'].nome : (ticket.status || 'aberto').charAt(0).toUpperCase() + (ticket.status || 'aberto').slice(1).toLowerCase())}
                    </span>
                    <span class="status prioridade ${ticket.prioridade || 'normal'} ${ticket.status === 'encerrado' ? 'disabled' : 'clickable'}" ${ticket.status === 'encerrado' ? '' : `onclick="alterarPrioridade(${ticket.id})" title="Clique para alterar"`}>
                        ${typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(ticket.prioridade) : (ticket.prioridade ? ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1).toLowerCase() : 'Normal')}
                    </span>
                </div>
            </div>
            <h2>${sanitizeHTML(ticket.titulo)}</h2>
            <div><strong>Descrição:</strong></div>
            <div class="descricao-ticket">
                ${htmlSeguro(ticket.descricao)}
            </div>
            <p><strong>Criado por:</strong> ${(() => {
            const email = ticket.email_solicitante || ticket.usuario_email || '';
            const nome = ticket.usuario_nome || '';
            if (email && nome) return `${sanitizeHTML(email)} <span style="color:#888; font-size:0.9em;">(${sanitizeHTML(nome)})</span>`;
            if (email) return sanitizeHTML(email);
            return sanitizeHTML(nome) || '—';
        })()}</p>
            <p><strong>Prioridade:</strong> ${ticket.prioridade}</p>
            <p><strong>Data:</strong> ${new Date(ticket.criado_em).toLocaleString('pt-BR')}</p>
            ${ticket.status === 'encerrado' && ticket.fechado_por_nome ?
            `<p><strong>Encerrado por:</strong> ${sanitizeHTML(ticket.fechado_por_nome)} em ${new Date(ticket.fechado_em).toLocaleString('pt-BR')}</p>` :
            ''
        }
            ${ticket.agente_nome && ticket.atribuido_em ?
            `<p><strong>Atribuída a:</strong> ${sanitizeHTML(ticket.agente_nome)} em ${new Date(ticket.atribuido_em).toLocaleString('pt-BR')}</p>` :
            ''
        }

            ${gerarAcoesDemandaHTML(ticket)}

            ${ticket.anexos.length > 0 ? `
                <div class="anexos-section" style="margin-top: 1.5rem; border-top: 1px solid #eee; padding-top: 1rem;">
                    <h3><img src="images/clipe-de-anexo.png" style="width: 20px; height: 20px; vertical-align: middle; margin-right: 0.5rem;" alt="Anexos">Anexos (${ticket.anexos.length})</h3>
                    <div class="anexos-lista">
                        ${ticket.anexos.map(anexo => `
                            <div class="anexo-item" style="background: #f8f9fa; padding: 0.75rem; margin: 0.5rem 0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>${sanitizeHTML(anexo.nome_original)}</strong><br>
                                    <small style="color: #666;">${(anexo.tamanho / 1024 / 1024).toFixed(2)}MB - ${new Date(anexo.criado_em).toLocaleDateString('pt-BR')}</small>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    ${(anexo.tipo || anexo.tipo_mime) === 'application/pdf' || (anexo.tipo || anexo.tipo_mime).startsWith('image/') ?
                `<button class="btn-visualizar" onclick="visualizarAnexo(${anexo.id}, '${anexo.tipo || anexo.tipo_mime}')">👁️ Visualizar</button>` :
                ''
            }
                                    <button class="btn-small" onclick="baixarAnexo(${anexo.id}, decodeURIComponent('${argJs(anexo.nome_original)}'))">📥 Download</button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            ` : ''}
            
            <div class="lembretes-section" style="margin-top: 2rem; border-top: 1px solid #eee; padding-top: 1rem;">
                <h3 style="font-size: 1.5rem; font-weight: bold; color: var(--verde); margin-bottom: 1rem;">🔔 Lembretes</h3>
                <button class="btn-primary" onclick="mostrarModalLembrete(${ticket.id})" style="margin-bottom: 1rem;">➕ Adicionar Lembrete</button>
                <div id="lista-lembretes"></div>
            </div>
            
            <div class="comentarios-section">
                <h3 style="font-size: 1.5rem; font-weight: bold; color: var(--laranja); margin-bottom: 1.5rem;">Interações (${(ticket.comentarios || []).length})</h3>
                
                <div id="lista-comentarios">
                    ${comentariosHtml}
                </div>
                
                <form id="form-comentario" onsubmit="adicionarComentario(event, ${ticket.id})">
                    <div class="form-group" style="display: flex; justify-content: space-between; align-items: flex-end;">
                        <label for="novo-comentario" style="margin-bottom: 0;">Adicionar comentário:</label>
                        <select id="seletor-macros" onchange="aplicarMacroTicket(this.value)" style="padding: 0.4rem; border-radius: 4px; border: 1px solid #ccc; font-size: 0.9rem; max-width: 300px; cursor: pointer;">
                            <option value="">⚡ Usar Resposta Rápida (Macro)...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <div id="editor-comentario" style="height: 150px; background: #fff;"></div>
                        <input type="hidden" id="novo-comentario" required>
                    </div>
                    <div class="form-group">
                        <label class="switch-label">
                            <label class="switch">
                                <input type="checkbox" id="enviar-email" onchange="toggleCampoComCopia(); verificarAnexosEmail();">
                                <span class="slider"></span>
                            </label>
                            <span>Enviar comentário por e-mail para o solicitante</span>
                        </label>
                    </div>
                    <div class="form-group" id="campo-com-copia" style="display: none;">
                        <label class="switch-label">
                            <label class="switch">
                                <input type="checkbox" id="toggle-com-copia" onchange="toggleCampoEmailCopia()">
                                <span class="slider"></span>
                            </label>
                            <span>Enviar cópia para outro e-mail</span>
                        </label>
                    </div>
                    <div class="form-group" id="campo-email-copia" style="display: none;">
                        <label for="com-copia">E-mail para cópia:</label>
                        <input type="email" id="com-copia" placeholder="email@exemplo.com">
                    </div>
                    <div class="form-group">
                        <label for="anexos-comentario">Anexos (máx. 5 arquivos, 20MB total):</label>
                        <input type="file" id="anexos-comentario" multiple accept=".pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,.png,.jpg,.jpeg" onchange="validarAnexosComentario()">
                        <div id="anexos-comentario-info" style="margin-top: 0.5rem; font-size: 0.9rem; color: #666;"></div>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn-primary" id="btn-adicionar-comentario">Adicionar Comentário</button>
                        <button type="button" class="btn-primary" onclick="enviarEFecharTicket(${ticket.id})" style="background-color: #28a745;">✅ Enviar e Fechar</button>
                    </div>
                </form>
            </div>
            
            <div id="visualizador-anexo" class="modal-anexo" style="display: none;">
                <div class="modal-anexo-content">
                    <div class="modal-anexo-header">
                        <h3>Visualizar Anexo</h3>
                        <button class="btn-close-anexo" onclick="fecharVisualizador()">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-anexo-body">
                        <div id="conteudo-anexo"></div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// Visualizar anexo inline — usa fetch autenticado para evitar erro de token
async function visualizarAnexo(anexoId, tipoMime) {
    const modal = document.getElementById('visualizador-anexo');
    const conteudo = document.getElementById('conteudo-anexo');

    if (!modal || !conteudo) {
        console.error('❌ Modal ou conteúdo não encontrado');
        return;
    }

    // Libera blob URL anterior, se houver
    if (modal._blobUrl) {
        URL.revokeObjectURL(modal._blobUrl);
        modal._blobUrl = null;
    }

    conteudo.innerHTML = '<p style="text-align:center;padding:2rem;">Carregando...</p>';
    modal.style.display = 'block';

    try {
        const resp = await fetch(`../api/controllers/anexos.php?id=${anexoId}&action=view`, {
            headers: getAuthHeaders()
        });

        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            conteudo.innerHTML = `<p style="color:red;text-align:center;">Erro ao carregar anexo: ${err.error || resp.statusText}</p>`;
            return;
        }

        const blob = await resp.blob();
        const blobUrl = URL.createObjectURL(blob);
        modal._blobUrl = blobUrl;

        if (tipoMime === 'application/pdf') {
            conteudo.innerHTML = `
                <iframe src="${blobUrl}" width="100%" height="600px" style="border: none;"></iframe>
                <p style="margin-top: 15px; text-align: center; padding: 0 20px;">
                    <button class="btn-small" onclick="baixarAnexo(${anexoId})" style="white-space: nowrap;">📄 Baixar PDF</button>
                </p>
            `;
        } else if (tipoMime.startsWith('image/')) {
            conteudo.innerHTML = `<img src="${blobUrl}" style="max-width: 100%; height: auto; display: block; margin: 0 auto;" alt="Visualização do anexo">`;
        } else {
            conteudo.innerHTML = `<p>Tipo de arquivo não suportado para visualização: ${tipoMime}</p>`;
        }
    } catch (e) {
        console.error('Erro ao visualizar anexo:', e);
        conteudo.innerHTML = '<p style="color:red;text-align:center;">Erro ao carregar o arquivo.</p>';
    }
}

// Fechar visualizador
function fecharVisualizador() {
    const modal = document.getElementById('visualizador-anexo');
    if (modal._blobUrl) {
        URL.revokeObjectURL(modal._blobUrl);
        modal._blobUrl = null;
    }
    modal.style.display = 'none';
}

// Mostrar/ocultar campo "com cópia"
function toggleCampoComCopia() {
    const enviarEmail = document.getElementById('enviar-email').checked;
    const campoComCopia = document.getElementById('campo-com-copia');
    const campoEmailCopia = document.getElementById('campo-email-copia');
    const btnAdicionar = document.getElementById('btn-adicionar-comentario');

    if (enviarEmail) {
        campoComCopia.style.display = 'block';
        btnAdicionar.textContent = 'Adicionar Comentário e Enviar E-mail';
    } else {
        campoComCopia.style.display = 'none';
        campoEmailCopia.style.display = 'none';
        document.getElementById('toggle-com-copia').checked = false;
        document.getElementById('com-copia').value = '';
        document.getElementById('com-copia').removeAttribute('required');
        btnAdicionar.textContent = 'Adicionar Comentário';
    }
}

function toggleCampoEmailCopia() {
    const toggleCopia = document.getElementById('toggle-com-copia').checked;
    const campoEmailCopia = document.getElementById('campo-email-copia');
    const inputEmail = document.getElementById('com-copia');

    if (toggleCopia) {
        campoEmailCopia.style.display = 'block';
        inputEmail.setAttribute('required', 'required');
    } else {
        campoEmailCopia.style.display = 'none';
        inputEmail.removeAttribute('required');
        inputEmail.value = '';
    }
}

// Adicionar comentário
function validarAnexosComentario() {
    const input = document.getElementById('anexos-comentario');
    const novosArquivos = Array.from(input.files);

    if (novosArquivos.length === 0) return;

    // Validar tipos
    const arquivosInvalidos = novosArquivos.filter(arquivo => {
        const extensao = '.' + arquivo.name.split('.').pop().toLowerCase();
        return !TIPOS_PERMITIDOS.includes(extensao);
    });

    if (arquivosInvalidos.length > 0) {
        mostrarAlert('Tipo Inválido', `Tipos não permitidos: ${arquivosInvalidos.map(a => a.name).join(', ')}`);
        input.value = '';
        return;
    }

    // Validar quantidade
    const totalArquivos = anexosComentarioSelecionados.length + novosArquivos.length;
    if (totalArquivos > MAX_ARQUIVOS) {
        const permitidos = MAX_ARQUIVOS - anexosComentarioSelecionados.length;
        mostrarAlert('Limite de Arquivos', `Máximo ${MAX_ARQUIVOS} arquivos. Você pode adicionar apenas ${permitidos} arquivo(s).`);
        input.value = '';
        return;
    }

    // Validar tamanho
    const tamanhoAtual = anexosComentarioSelecionados.reduce((total, arquivo) => total + arquivo.size, 0);
    const tamanhoNovos = novosArquivos.reduce((total, arquivo) => total + arquivo.size, 0);
    const tamanhoTotal = tamanhoAtual + tamanhoNovos;

    if (tamanhoTotal > MAX_TAMANHO) {
        const disponivelMB = ((MAX_TAMANHO - tamanhoAtual) / 1024 / 1024).toFixed(2);
        mostrarAlert('Limite de Tamanho', `Tamanho total excede 20MB. Espaço disponível: ${disponivelMB}MB`);
        input.value = '';
        return;
    }

    // Adicionar arquivos válidos
    anexosComentarioSelecionados = [...anexosComentarioSelecionados, ...novosArquivos];
    input.value = '';
    atualizarListaAnexosComentario();
    verificarAnexosEmail();
}

function atualizarListaAnexosComentario() {
    const info = document.getElementById('anexos-comentario-info');

    if (anexosComentarioSelecionados.length === 0) {
        info.innerHTML = '';
        return;
    }

    let infoHtml = '';
    anexosComentarioSelecionados.forEach((arquivo, index) => {
        const tamanhoMB = (arquivo.size / 1024 / 1024).toFixed(2);
        infoHtml += `
            <div class="anexo-item">
                <span class="anexo-nome">${arquivo.name}</span>
                <span class="anexo-tamanho">${tamanhoMB}MB</span>
                <button type="button" class="anexo-remover" onclick="removerAnexoComentario(${index})">×</button>
            </div>
        `;
    });

    const tamanhoTotal = anexosComentarioSelecionados.reduce((total, arquivo) => total + arquivo.size, 0);
    const totalMB = (tamanhoTotal / 1024 / 1024).toFixed(2);

    info.innerHTML = `
        <div style="margin-bottom: 0.5rem; font-weight: bold;">Arquivos selecionados (${anexosComentarioSelecionados.length}/${MAX_ARQUIVOS} - ${totalMB}MB/20MB):</div>
        ${infoHtml}
    `;
}

function removerAnexoComentario(index) {
    anexosComentarioSelecionados.splice(index, 1);
    atualizarListaAnexosComentario();
}

function verificarAnexosEmail() {
    const enviarEmail = document.getElementById('enviar-email');
    const anexosInput = document.getElementById('anexos-comentario');

    if (!enviarEmail || !anexosInput) return;

    if (enviarEmail.checked && anexosInput.files.length > 0) {
        mostrarToast('Informação', 'Os anexos adicionados serão enviados por e-mail para o solicitante e para o endereço em cópia.', 'info');
    }
}

function adicionarComentario(event, ticketId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    // Atualiza o value invisível para capturar o Rich Text
    if (window.quillComentario) {
        document.getElementById('novo-comentario').value = window.quillComentario.root.innerHTML;
        // Impedindo disparo de form se vazio (lidar com <p><br></p>)
        if (window.quillComentario.getText().trim().length === 0) {
            document.getElementById('novo-comentario').value = '';
        }
    }

    // Validar antes de adicionar comentário
    if (!validarAntesComentario(ticketId)) {
        return;
    }

    const comentario = document.getElementById('novo-comentario').value;
    const enviarEmail = document.getElementById('enviar-email').checked;
    const comCopia = document.getElementById('com-copia').value;
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    const token = localStorage.getItem('cd_token');

    // Usar FormData para suportar anexos
    const formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('comentario', comentario);
    formData.append('usuario_id', usuario.id);
    formData.append('enviar_email', enviarEmail ? '1' : '0');
    formData.append('com_copia', comCopia);

    // Adicionar anexos acumulados se houver
    if (anexosComentarioSelecionados.length > 0) {
        anexosComentarioSelecionados.forEach(arquivo => {
            formData.append('anexos[]', arquivo);
        });
    }

    console.log('Enviando comentário com', anexosComentarioSelecionados.length, 'anexos');

    mostrarLoader();

    let csrfToken = localStorage.getItem('cd_csrf_token') || '';
    if (!csrfToken) {
        try {
            const t = localStorage.getItem('cd_token');
            const payload = JSON.parse(atob(t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
            csrfToken = payload.csrf || '';
        } catch (e) {}
    }
    fetch(window.APP_CONFIG.apiBase + 'comentarios.php', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'X-CSRF-Token': csrfToken
        },
        body: formData
    })
        .then(response => response.json().then(corpo => ({ ok: response.ok, corpo })))
        .then(({ ok, corpo: data }) => {
            ocultarLoader();
            if (!ok) {
                mostrarAlert('Erro', data.error || 'Não foi possível adicionar o comentário.');
                return;
            }
            const mensagem = enviarEmail ?
                (comCopia ? 'Comentário adicionado e e-mails enviados!' : 'Comentário adicionado e e-mail enviado!') :
                'Comentário adicionado com sucesso!';

            if (enviarEmail && data.email_enviado === false) {
                mostrarAlert('Atenção', 'O comentário foi salvo, mas o e-mail para o solicitante NÃO foi enviado. Verifique o endereço do solicitante e a configuração de e-mail do setor.');
            } else {
                mostrarToast('Sucesso', mensagem, 'success');
            }

            // Limpar formulário
            document.getElementById('novo-comentario').value = '';
            if (window.quillComentario) window.quillComentario.root.innerHTML = '';
            document.getElementById('enviar-email').checked = false;
            document.getElementById('toggle-com-copia').checked = false;
            document.getElementById('com-copia').value = '';
            document.getElementById('com-copia').removeAttribute('required');
            document.getElementById('campo-com-copia').style.display = 'none';
            document.getElementById('campo-email-copia').style.display = 'none';
            document.getElementById('btn-adicionar-comentario').textContent = 'Adicionar Comentário';
            document.getElementById('anexos-comentario').value = '';
            anexosComentarioSelecionados = [];
            atualizarListaAnexosComentario();

            // Adicionar comentário na lista usando DOM API para evitar XSS
            if (data.comentario) {
                const listaComentarios = document.getElementById('lista-comentarios');
                if (listaComentarios) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'comentario-wrapper';

                    // Informações do autor (texto puro)
                    const infoDiv = document.createElement('div');
                    infoDiv.className = 'comentario-info';
                    const autorStrong = document.createElement('strong');
                    autorStrong.textContent = '💬 ' + (data.comentario.autor_nome || '');
                    infoDiv.appendChild(autorStrong);
                    infoDiv.appendChild(document.createTextNode(' - ' + new Date(data.comentario.criado_em).toLocaleString('pt-BR')));
                    infoDiv.appendChild(document.createElement('br'));
                    const comentarioLabel = document.createElement('strong');
                    comentarioLabel.style.cssText = 'color: #666; font-size: 0.9em;';
                    comentarioLabel.textContent = 'Comentário:';
                    infoDiv.appendChild(comentarioLabel);
                    wrapper.appendChild(infoDiv);

                    // Conteúdo do comentário — renderizado como HTML (gerado pelo editor Quill)
                    const comentarioDiv = document.createElement('div');
                    comentarioDiv.className = 'comentario';
                    comentarioDiv.innerHTML = htmlSeguro(data.comentario.comentario || '');
                    wrapper.appendChild(comentarioDiv);

                    // Anexos
                    if (data.anexos && data.anexos.length > 0) {
                        const anexosDiv = document.createElement('div');
                        anexosDiv.style.cssText = 'margin-top: 0.5rem; padding-left: 1rem;';
                        const anexosLabel = document.createElement('strong');
                        anexosLabel.style.cssText = 'color: #666; font-size: 0.9em;';
                        anexosLabel.textContent = 'Anexos:';
                        anexosDiv.appendChild(anexosLabel);
                        anexosDiv.appendChild(document.createElement('br'));

                        data.anexos.forEach(anexo => {
                            const tipo = anexo.tipo || anexo.tipo_mime || '';
                            const isPDF = tipo === 'application/pdf';
                            const isImage = tipo.startsWith('image/');
                            const nomeExibir = anexo.nome_original || anexo.nome_arquivo || '';
                            const anexoIdSafe = parseInt(anexo.id, 10);

                            const itemDiv = document.createElement('div');
                            itemDiv.style.marginTop = '0.3rem';

                            const link = document.createElement('button');
                            link.style.cssText = 'color: #007bff; text-decoration: none; background: none; border: none; cursor: pointer; padding: 0; font-size: inherit;';
                            const imgClipe = document.createElement('img');
                            imgClipe.src = 'images/clipe-de-anexo.png';
                            imgClipe.style.cssText = 'width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;';
                            imgClipe.alt = '';
                            link.appendChild(imgClipe);
                            link.appendChild(document.createTextNode(nomeExibir));
                            link.addEventListener('click', () => baixarAnexo(anexoIdSafe, nomeExibir));
                            itemDiv.appendChild(link);

                            if (isPDF || isImage) {
                                const btnVer = document.createElement('button');
                                btnVer.textContent = '👁️ Ver';
                                btnVer.style.cssText = 'margin-left: 0.5rem; padding: 0.2rem 0.5rem; font-size: 0.8rem; cursor: pointer; border: 1px solid #007bff; background: white; color: #007bff; border-radius: 3px;';
                                btnVer.addEventListener('click', () => visualizarAnexo(anexoIdSafe, tipo));
                                itemDiv.appendChild(btnVer);
                            }
                            anexosDiv.appendChild(itemDiv);
                        });
                        wrapper.appendChild(anexosDiv);
                    }

                    listaComentarios.appendChild(wrapper);

                    // Registros de email enviado
                    if (enviarEmail) {
                        const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
                        const agora = new Date().toLocaleString('pt-BR');
                        const ticketAtual = JSON.parse(localStorage.getItem('cd_ticketAtual') || '{}');

                        if (ticketAtual && ticketAtual.usuario_email) {
                            const histDiv = document.createElement('div');
                            histDiv.className = 'historico-wrapper';
                            const histInfo = document.createElement('div');
                            histInfo.className = 'historico-info';
                            const nomeS = document.createElement('strong');
                            nomeS.textContent = '🕒 ' + (usuario.nome || '');
                            histInfo.appendChild(nomeS);
                            histInfo.appendChild(document.createTextNode(' - ' + agora));
                            const histAcao = document.createElement('div');
                            histAcao.className = 'historico-acao';
                            histAcao.textContent = '📧 O comentário acima foi enviado por e-mail para: ' + ticketAtual.usuario_email;
                            histDiv.appendChild(histInfo);
                            histDiv.appendChild(histAcao);
                            listaComentarios.appendChild(histDiv);
                        }

                        if (comCopia) {
                            const histDivC = document.createElement('div');
                            histDivC.className = 'historico-wrapper';
                            const histInfoC = document.createElement('div');
                            histInfoC.className = 'historico-info';
                            const nomeSC = document.createElement('strong');
                            nomeSC.textContent = '🕒 ' + (usuario.nome || '');
                            histInfoC.appendChild(nomeSC);
                            histInfoC.appendChild(document.createTextNode(' - ' + agora));
                            const histAcaoC = document.createElement('div');
                            histAcaoC.className = 'historico-acao';
                            histAcaoC.textContent = '📧 Cópia do comentário acima enviada por e-mail para: ' + comCopia;
                            histDivC.appendChild(histInfoC);
                            histDivC.appendChild(histAcaoC);
                            listaComentarios.appendChild(histDivC);
                        }
                    }
                }
            }
        })
        .catch(error => {
            ocultarLoader();
            console.error('Erro:', error);
            mostrarAlert('Erro', 'Erro ao adicionar comentário');
        });
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

// === GERENCIAMENTO DE SETORES (admin) ===

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

function carregarSetores() {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'setores.php' + (ehAdmin ? '?incluir_inativos=1' : ''), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            let setores = JSON.parse(xhr.responseText);
            // Gestor só gerencia (edita o nome de) o próprio setor
            if (!ehAdmin) {
                setores = setores.filter(s => s.id == usuarioLogado.setor_id);
            }
            mostrarListaSetores(setores);
        } else {
            document.getElementById('config-content').innerHTML = '<p>Erro ao carregar setores.</p>';
        }
    };
    xhr.send();
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

function criarSetor(event) {
    event.preventDefault();
    const nome = document.getElementById('nome-setor').value;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'setores.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Setor criado com sucesso!', 'success');
            document.getElementById('form-setor').reset();
            carregarSetores();
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao criar setor');
        }
    };
    xhr.send(JSON.stringify({ nome }));
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

function executarEdicaoSetor(setorId, nome) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}setores.php/${setorId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Setor atualizado com sucesso!', 'success');
            carregarSetores();
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao atualizar setor');
        }
    };
    xhr.send(JSON.stringify({ nome }));
}

function alternarAtivoSetor(setorId, ativo) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}setores.php/${setorId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', ativo ? 'Setor ativado!' : 'Setor desativado!', 'success');
            carregarSetores();
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao atualizar setor');
        }
    };
    xhr.send(JSON.stringify({ ativo }));
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

// === GERENCIAMENTO DE USUÁRIOS ===

// Mostrar gerenciamento de usuários
function mostrarUsuarios() {
    // Ativar botão
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_usuarios = document.getElementById('btn-usuarios'); if (_bbtn_usuarios) _bbtn_usuarios.classList.add('active');

    // Salvar subseção
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
        // Se o elemento ainda não existe, aguardar mais tempo
        setTimeout(() => mostrarUsuarios(), 200);
    }
}

// Carregar lista de usuários
function carregarUsuarios() {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'usuarios.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const usuarios = JSON.parse(xhr.responseText);

            // Admin precisa da lista de setores para poder escolher em qual setor
            // cadastrar/mover um usuário. Gestor não precisa (fica travado no próprio setor).
            if (ehAdmin) {
                const xhrSetores = new XMLHttpRequest();
                xhrSetores.open('GET', window.APP_CONFIG.apiBase + 'setores.php', true);
                setSecurityHeaders(xhrSetores);
                xhrSetores.onload = function () {
                    const setores = xhrSetores.status === 200 ? JSON.parse(xhrSetores.responseText) : [];
                    mostrarListaUsuarios(usuarios, setores);
                };
                xhrSetores.send();
            } else {
                mostrarListaUsuarios(usuarios, []);
            }
        } else {
            document.getElementById('config-content').innerHTML = '<p>Erro ao carregar usuários.</p>';
        }
    };

    xhr.send();
}

// Mostrar lista de usuários
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

// Criar novo usuário
function criarUsuario(event) {
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

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'usuarios.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Usuário criado com sucesso!', 'success');
            document.getElementById('form-usuario').reset();
            carregarUsuarios();
        } else {
            let errorMsg = 'Erro ao criar usuário';
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.error) errorMsg = res.error;
            } catch (e) { }
            mostrarAlert('Erro', errorMsg);
        }
    };

    xhr.send(JSON.stringify({ nome, username, email, cargo, tipo, setor_id, senha }));
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

// Editar usuário
function editarUsuario(usuarioId) {
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuarioLogado.tipo === 'admin';

    // Buscar dados atuais do usuário
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'usuarios.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const usuarios = JSON.parse(xhr.responseText);
            const usuario = usuarios.find(u => u.id === usuarioId);
            if (!usuario) return;

            if (ehAdmin) {
                const xhrSetores = new XMLHttpRequest();
                xhrSetores.open('GET', window.APP_CONFIG.apiBase + 'setores.php', true);
                setSecurityHeaders(xhrSetores);
                xhrSetores.onload = function () {
                    const setores = xhrSetores.status === 200 ? JSON.parse(xhrSetores.responseText) : [];
                    mostrarFormularioEdicaoUsuario(usuario, setores);
                };
                xhrSetores.send();
            } else {
                mostrarFormularioEdicaoUsuario(usuario, []);
            }
        }
    };

    xhr.send();
}

// Mostrar formulário de edição de usuário
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

// Executar edição de usuário
function executarEdicaoUsuario(usuarioId, nome, email, tipo, username, cargo, setor_id) {
    console.log('🔄 Editando Usuário:', { usuarioId, nome, email, tipo, username, cargo, setor_id });

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}usuarios.php/${usuarioId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const resposta = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            carregarUsuarios();
            const devolvidas = resposta.demandas_devolvidas || 0;
            mostrarToast('Sucesso', devolvidas > 0
                ? `Usuário atualizado. ${devolvidas} demanda(s) dele voltaram para o setor anterior.`
                : 'Usuário atualizado com sucesso!', 'success');
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao atualizar usuário');
        }
    };

    xhr.send(JSON.stringify({ nome, email, tipo, username, cargo, setor_id }));
}

// Excluir usuário
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

// Executar exclusão de usuário
function executarExclusaoUsuario(usuarioId) {
    const xhr = new XMLHttpRequest();
    xhr.open('DELETE', `${window.APP_CONFIG.apiBase}usuarios.php?id=${usuarioId}`, true);

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Usuário excluído com sucesso!', 'success');
            carregarUsuarios();
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error);
        }
    };

    xhr.send();
}

// Excluir ticket
function excluirTicket(ticketId) {
    mostrarConfirm(
        'Excluir Demanda',
        'A demanda será enviada para a lixeira e deixará de aparecer nas listas. Somente o administrador poderá restaurá-la ou excluí-la definitivamente. Deseja continuar?',
        function (confirmado) {
            if (!confirmado) return;
            executarExclusaoTicket(ticketId);
        },
        true // isPerigoso = true
    );
}

// ======================================================
// 🗑️ LIXEIRA (somente administrador)
// ======================================================
function mostrarLixeira() {
    mostrarSecao('lixeira');
    carregarLixeira();
}

function carregarLixeira() {
    const lista = document.getElementById('lista-lixeira');
    if (!lista) return;
    lista.innerHTML = '<p>Carregando...</p>';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'tickets.php?escopo=lixeira'), true);
    setSecurityHeaders(xhr);
    xhr.onload = function () {
        if (xhr.status !== 200) {
            lista.innerHTML = '<p>Erro ao carregar a lixeira.</p>';
            return;
        }
        const demandas = JSON.parse(xhr.responseText);
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
    };
    xhr.send();
}

function restaurarDemanda(ticketId) {
    mostrarConfirm('Restaurar Demanda', 'A demanda volta para as listas do setor, com o histórico preservado. Deseja restaurá-la?', function (confirmado) {
        if (!confirmado) return;
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', `${window.APP_CONFIG.apiBase}tickets.php?id=${Number(ticketId)}&acao=restaurar`, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        setSecurityHeaders(xhr);
        xhr.onload = function () {
            if (xhr.status === 200) {
                mostrarToast('Sucesso', 'Demanda restaurada.', 'success');
                localStorage.removeItem('cd_ticketAtual');
                mostrarLixeira();
            } else {
                let erro = 'Erro ao restaurar a demanda';
                try { erro = JSON.parse(xhr.responseText).error || erro; } catch (e) { /* sem JSON */ }
                mostrarAlert('Erro', erro);
            }
        };
        xhr.send('{}');
    });
}

function excluirDefinitivo(ticketId) {
    mostrarConfirm('Excluir definitivamente',
        'ATENÇÃO: a demanda, os comentários, os anexos e o histórico serão apagados para sempre e não poderão ser recuperados. Confirma?',
        function (confirmado) {
            if (!confirmado) return;
            const xhr = new XMLHttpRequest();
            xhr.open('DELETE', `${window.APP_CONFIG.apiBase}tickets.php?id=${Number(ticketId)}&definitivo=1`, true);
            setSecurityHeaders(xhr);
            xhr.onload = function () {
                if (xhr.status === 200) {
                    mostrarToast('Sucesso', 'Demanda excluída definitivamente.', 'success');
                    localStorage.removeItem('cd_ticketAtual');
                    mostrarLixeira();
                } else {
                    let erro = 'Erro ao excluir a demanda';
                    try { erro = JSON.parse(xhr.responseText).error || erro; } catch (e) { /* sem JSON */ }
                    mostrarAlert('Erro', erro);
                }
            };
            xhr.send();
        }, true);
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

// === GERENCIAMENTO DE CATEGORIAS ===

// Carregar categorias para pesquisa
let todasCategorias = [];

// Admin trocou o setor no cabeçalho: refaz a lista de subcategorias usada nos filtros
document.addEventListener('setor-contexto-alterado', function () {
    if (typeof carregarCategorias === 'function') carregarCategorias();
});

function carregarCategorias() {
    console.log('📁 Carregando categorias...');
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    // Setor do contexto: o do usuário ou, para o admin, o escolhido no cabeçalho
    const setorContextoCat = window.CabecalhoSetor ? window.CabecalhoSetor.obterSetorContexto() : (usuario.setor_id || null);
    const filtroSetor = setorContextoCat ? `?setor_id=${setorContextoCat}` : '';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'categorias.php' + filtroSetor, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            todasCategorias = JSON.parse(xhr.responseText);
            console.log('📁 Categorias carregadas:', todasCategorias.length);
            // NÃO configurar pesquisa automaticamente para não interferir na navegação
        } else {
            console.error('❌ Erro ao carregar categorias:', xhr.status);
        }
    };

    xhr.onerror = function () {
        console.error('❌ Erro de conexão ao carregar categorias');
    };

    xhr.send();
}

// Popula o select de prioridade do formulário "Nova Demanda" com as prioridades
// ativas no setor de quem está criando (cliente/admin sem setor veem o catálogo inteiro).
function popularPrioridadesFormNovo() {
    const select = document.getElementById('prioridade');
    if (!select || typeof carregarPrioridadesDisponiveis !== 'function') return;

    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');

    carregarPrioridadesDisponiveis(usuario.setor_id || null).then(prioridadesList => {
        if (!prioridadesList || prioridadesList.length === 0) return;

        const valorAtual = select.value || 'normal';
        select.innerHTML = prioridadesList.map(p => {
            const nome = typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(p.nome) : p.nome;
            return `<option value="${p.nome}">${nome}</option>`;
        }).join('');

        // Mantém a seleção atual se ainda existir na lista; senão cai em "normal" (ou o primeiro item)
        if (select.querySelector(`option[value="${valorAtual}"]`)) {
            select.value = valorAtual;
        } else if (select.querySelector('option[value="normal"]')) {
            select.value = 'normal';
        }
    }).catch(err => {
        console.warn('Não foi possível carregar prioridades do setor, mantendo lista padrão', err);
    });
}

// Configurar sistema de pesquisa de categoria
function configurarPesquisaCategoria() {
    const input = document.getElementById('categoria-busca');
    const lista = document.getElementById('categoria-lista-form');

    if (!input || !lista) {
        console.log('⚠️ Elementos de categoria não encontrados');
        return;
    }

    console.log('🔧 Configurando pesquisa de categoria...');

    // Limpar eventos anteriores
    input.removeEventListener('click', mostrarListaCategorias);
    input.removeEventListener('focus', mostrarListaCategorias);
    input.removeEventListener('input', filtrarCategorias);

    // Renderizar todas as categorias inicialmente (ocultas)
    renderizarCategoriasForm(todasCategorias);

    // Função para mostrar lista
    function mostrarListaCategorias() {
        if (todasCategorias.length > 0) {
            renderizarCategoriasForm(todasCategorias);
            lista.classList.add('show');
        }
    }

    // Função para filtrar categorias
    function filtrarCategorias() {
        const termo = input.value.toLowerCase().trim();
        let categoriasFiltradas;

        if (termo === '') {
            categoriasFiltradas = todasCategorias;
        } else {
            categoriasFiltradas = todasCategorias.filter(cat =>
                cat.nome.toLowerCase().includes(termo)
            );
        }

        renderizarCategoriasForm(categoriasFiltradas);
        lista.classList.add('show');
    }

    // Mostrar lista ao clicar no campo
    input.addEventListener('click', mostrarListaCategorias);

    // Mostrar lista ao focar no campo
    input.addEventListener('focus', mostrarListaCategorias);

    // Filtrar ao digitar
    input.addEventListener('input', filtrarCategorias);

    // Navegação por teclado
    input.addEventListener('keydown', function (e) {
        const itens = lista.querySelectorAll('.categoria-item-form');
        let itemAtivo = lista.querySelector('.categoria-item-form.selected');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!itemAtivo) {
                if (itens.length > 0) {
                    itens[0].classList.add('selected');
                }
            } else {
                itemAtivo.classList.remove('selected');
                const proximo = itemAtivo.nextElementSibling;
                if (proximo) {
                    proximo.classList.add('selected');
                    proximo.scrollIntoView({ block: 'nearest' });
                } else {
                    itens[0].classList.add('selected');
                    itens[0].scrollIntoView({ block: 'nearest' });
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (!itemAtivo) {
                if (itens.length > 0) {
                    itens[itens.length - 1].classList.add('selected');
                }
            } else {
                itemAtivo.classList.remove('selected');
                const anterior = itemAtivo.previousElementSibling;
                if (anterior) {
                    anterior.classList.add('selected');
                    anterior.scrollIntoView({ block: 'nearest' });
                } else {
                    itens[itens.length - 1].classList.add('selected');
                    itens[itens.length - 1].scrollIntoView({ block: 'nearest' });
                }
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (itemAtivo) {
                itemAtivo.click();
            }
        } else if (e.key === 'Escape') {
            lista.classList.remove('show');
            input.blur();
        }
    });

    // Ocultar lista ao clicar fora
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.categoria-container')) {
            lista.classList.remove('show');
        }
    });

    console.log('✅ Pesquisa de categoria configurada com sucesso!');
}

// Renderizar categorias no formulário
function renderizarCategoriasForm(categorias) {
    const lista = document.getElementById('categoria-lista-form');
    if (!lista) return;

    lista.innerHTML = '';

    if (categorias.length === 0) {
        const item = document.createElement('div');
        item.className = 'categoria-item-form';
        item.textContent = 'Nenhuma subcategoria encontrada';
        item.style.color = '#999';
        item.style.fontStyle = 'italic';
        item.style.cursor = 'default';
        lista.appendChild(item);
        return;
    }

    categorias.forEach((categoria, index) => {
        const item = document.createElement('div');
        item.className = 'categoria-item-form';
        item.textContent = categoria.nome;
        item.setAttribute('data-categoria-id', categoria.id);

        item.onclick = function () {
            // Remover seleção anterior
            document.querySelectorAll('.categoria-item-form').forEach(i => i.classList.remove('selected'));
            // Selecionar atual
            item.classList.add('selected');
            // Definir valores
            document.getElementById('categoria-busca').value = categoria.nome;
            document.getElementById('categoria-id').value = categoria.id;
            // Ocultar lista
            document.getElementById('categoria-lista-form').classList.remove('show');

            console.log('📁 Categoria selecionada:', categoria.nome, 'ID:', categoria.id);
        };

        // Adicionar hover para navegação por teclado
        item.addEventListener('mouseenter', function () {
            document.querySelectorAll('.categoria-item-form').forEach(i => i.classList.remove('selected'));
            item.classList.add('selected');
        });

        lista.appendChild(item);
    });
}

// Mostrar categorias no dropdown (manter para compatibilidade)
function mostrarCategoriasDropdown(categorias) {
    const dropdown = document.getElementById('categoria-lista');
    if (!dropdown) return;

    dropdown.innerHTML = '';

    if (categorias.length === 0) {
        dropdown.innerHTML = '<div class="categoria-item">Nenhuma subcategoria encontrada</div>';
        dropdown.classList.add('show');
        return;
    }

    categorias.forEach(categoria => {
        const item = document.createElement('div');
        item.className = 'categoria-item';
        item.textContent = categoria.nome;

        item.onclick = function () {
            document.getElementById('categoria').value = categoria.nome;
            document.getElementById('categoria-id').value = categoria.id;
            dropdown.classList.remove('show');
        };

        dropdown.appendChild(item);
    });

    dropdown.classList.add('show');
}

// Mostrar gerenciamento de categorias e subcategorias
// (nomes internos de função/variável seguem usando "categoria"/"macrocategoria" —
// mesma nomenclatura das tabelas do banco — só o texto exibido ao usuário mudou)
function mostrarCategorias() {
    // Ativar botão
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_categorias = document.getElementById('btn-categorias'); if (_bbtn_categorias) _bbtn_categorias.classList.add('active');

    // Salvar subseção
    localStorage.setItem('cd_subSecaoAtiva', 'categorias');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F4C1) + ' Categorias e Subcategorias';

    // Admin precisa escolher o setor no cabeçalho para criar/editar (a lista mostra todos)
    const usuarioCategorias = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (usuarioCategorias.tipo === 'admin' && window.CabecalhoSetor && window.CabecalhoSetor.obterSetorContexto() === null) {
        mostrarToast('Escolha um setor', 'Selecione um setor no cabeçalho para criar ou editar categorias.', 'info');
    }

    const content = document.getElementById('config-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        carregarListaCategorias();
    }, 10);
}

// Carregar macrocategorias (exibidas como "Categoria") para o select de subcategoria
function carregarMacrocategorias() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'macrocategorias.php'), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const macrocategorias = JSON.parse(xhr.responseText);
            const select = document.getElementById('macrocategoria-categoria');

            if (select) {
                select.innerHTML = '<option value="">Selecione uma categoria</option>';
                macrocategorias.forEach(macro => {
                    const option = document.createElement('option');
                    option.value = macro.id;
                    option.textContent = macro.nome;
                    select.appendChild(option);
                });
            }
        } else {
            console.error('❌ Erro ao carregar categorias:', xhr.status, xhr.responseText);
        }
    };

    xhr.onerror = function () {
        console.error('❌ Erro de conexão ao carregar categorias');
    };

    xhr.send();
}

// Carregar lista de subcategorias (tabela "categorias")
function carregarListaCategorias() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'categorias.php'), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const categorias = JSON.parse(xhr.responseText);

            // Carregar macrocategorias (Categoria) para exibição agrupada
            const xhr2 = new XMLHttpRequest();
            xhr2.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'macrocategorias.php'), true);
            setSecurityHeaders(xhr2);

            xhr2.onload = function () {
                if (xhr2.status === 200) {
                    const macrocategorias = JSON.parse(xhr2.responseText);
                    mostrarListaCategorias(categorias, macrocategorias);
                }
            };

            xhr2.send();
        } else {
            document.getElementById('config-content').innerHTML = '<p>Erro ao carregar subcategorias.</p>';
        }
    };

    xhr.send();
}

// Mostrar lista de categorias (grupos) e subcategorias
function mostrarListaCategorias(categorias, macrocategorias) {
    const content = document.getElementById('config-content');

    // Agrupar subcategorias por categoria
    const categoriasPorMacro = {};
    const macroMap = {};

    macrocategorias.forEach(macro => {
        macroMap[macro.id] = macro.nome;
        categoriasPorMacro[macro.id] = { nome: macro.nome, itens: [] };
    });

    const semCategoria = { nome: 'Sem Categoria', itens: [] };

    categorias.forEach(categoria => {
        const grupo = categoriasPorMacro[categoria.macrocategoria_id] || semCategoria;
        grupo.itens.push(categoria);
    });

    let categoriasHtml = '';

    // Grupos de subcategorias (uma seção por Categoria, com botões de editar/excluir na própria Categoria)
    macrocategorias.forEach(macro => {
        const grupo = categoriasPorMacro[macro.id];
        categoriasHtml += `
            <div class="usuario-card" style="margin-bottom: 0.75rem;">
                <div class="usuario-info">
                    <h4>📁 ${sanitizeHTML(macro.nome)} <span style="font-weight: normal; color: #666;">(${grupo.itens.length} subcategoria${grupo.itens.length !== 1 ? 's' : ''})</span></h4>
                </div>
                <div class="usuario-acoes">
                    <button class="btn-editar" onclick="editarMacrocategoria(${macro.id}, decodeURIComponent('${argJs(macro.nome)}'))">Editar</button>
                    <button class="btn-excluir" onclick="excluirMacrocategoria(${macro.id})">Excluir</button>
                </div>
            </div>
        `;
        if (grupo.itens.length > 0) {
            categoriasHtml += '<div class="usuarios-grid" style="margin-bottom: 1.5rem;">';
            grupo.itens.forEach(categoria => {
                categoriasHtml += `
                    <div class="usuario-card">
                        <div class="usuario-info">
                            <h4>${sanitizeHTML(categoria.nome)}</h4>
                            <p><strong>Criado:</strong> ${new Date(categoria.criado_em).toLocaleDateString('pt-BR')}</p>
                        </div>
                        <div class="usuario-acoes">
                            <button class="btn-editar" onclick="editarCategoria(${categoria.id})">Editar</button>
                            <button class="btn-excluir" onclick="excluirCategoria(${categoria.id})">Excluir</button>
                        </div>
                    </div>
                `;
            });
            categoriasHtml += '</div>';
        }
    });

    if (semCategoria.itens.length > 0) {
        categoriasHtml += `<h3>📁 Sem Categoria (${semCategoria.itens.length})</h3><div class="usuarios-grid" style="margin-bottom: 1.5rem;">`;
        semCategoria.itens.forEach(categoria => {
            categoriasHtml += `
                <div class="usuario-card">
                    <div class="usuario-info">
                        <h4>${sanitizeHTML(categoria.nome)}</h4>
                        <p><strong>Criado:</strong> ${new Date(categoria.criado_em).toLocaleDateString('pt-BR')}</p>
                    </div>
                    <div class="usuario-acoes">
                        <button class="btn-editar" onclick="editarCategoria(${categoria.id})">Editar</button>
                        <button class="btn-excluir" onclick="excluirCategoria(${categoria.id})">Excluir</button>
                    </div>
                </div>
            `;
        });
        categoriasHtml += '</div>';
    }

    content.innerHTML = `
        <div style="display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap;">
            <button onclick="toggleFormMacrocategoria()" class="btn-primary">➕ Adicionar Nova Categoria</button>
            <button onclick="toggleFormCategoria()" class="btn-primary">➕ Adicionar Nova Subcategoria</button>
        </div>

        <div class="form-usuario" id="form-nova-macrocategoria" style="display: none; overflow: hidden; max-height: 0; transition: max-height 0.3s ease-out;">
            <h3>Adicionar Nova Categoria</h3>
            <form id="form-macrocategoria" onsubmit="criarMacrocategoria(event)">
                <div class="form-group">
                    <label for="nome-macrocategoria">Nome:</label>
                    <input type="text" id="nome-macrocategoria" required>
                </div>
                <button type="submit" class="btn-primary">Adicionar Categoria</button>
            </form>
        </div>

        <div class="form-usuario" id="form-nova-categoria" style="display: none; overflow: hidden; max-height: 0; transition: max-height 0.3s ease-out;">
            <h3>Adicionar Nova Subcategoria</h3>
            <form id="form-categoria" onsubmit="criarCategoria(event)">
                <div class="form-group">
                    <label for="nome-categoria">Nome:</label>
                    <input type="text" id="nome-categoria" required placeholder="Ex: Sistema *RFID*">
                    <small style="color: #666; font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                        📝 <strong>Dica:</strong> Use *asteriscos* para preservar a formatação de palavras específicas (ex: Sistema *RFID* → Sistema RFID)
                    </small>
                </div>
                <div class="form-group">
                    <label for="macrocategoria-categoria">Categoria:</label>
                    <select id="macrocategoria-categoria" required>
                        <option value="">Selecione uma categoria</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary">Adicionar Subcategoria</button>
            </form>
        </div>

        ${categoriasHtml}
    `;

    // Carregar categorias após renderizar o HTML
    setTimeout(() => carregarMacrocategorias(), 100);
}

// Alternar exibição do formulário de nova categoria (macrocategoria)
function toggleFormMacrocategoria() {
    const form = document.getElementById('form-nova-macrocategoria');
    const isVisible = form.style.display === 'block';

    if (isVisible) {
        form.style.maxHeight = '0';
        setTimeout(() => form.style.display = 'none', 300);
    } else {
        form.style.display = 'block';
        setTimeout(() => form.style.maxHeight = form.scrollHeight + 'px', 10);
    }
}

// Criar nova categoria (macrocategoria)
function criarMacrocategoria(event) {
    event.preventDefault();

    const nome = document.getElementById('nome-macrocategoria').value.trim();
    if (!nome) return;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', urlComSetor(window.APP_CONFIG.apiBase + 'macrocategorias.php'), true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            document.getElementById('form-macrocategoria').reset();
            carregarListaCategorias();
            mostrarToast('Sucesso', 'Categoria criada com sucesso!', 'success');
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao criar categoria');
        }
    };

    xhr.send(JSON.stringify({ nome }));
}

// Editar categoria (macrocategoria)
function editarMacrocategoria(macroId, nomeAtual) {
    document.getElementById('modal-prompt-message').innerHTML = `
        <div class="form-group">
            <label for="edit-nome-macro">Nome:</label>
            <input type="text" id="edit-nome-macro" value="${nomeAtual}" required>
        </div>
    `;
    document.getElementById('modal-prompt-title').textContent = 'Editar Categoria';
    document.getElementById('modal-prompt-input').style.display = 'none';
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        if (confirmado) {
            const nome = document.getElementById('edit-nome-macro').value.trim();
            if (nome) executarEdicaoMacrocategoria(macroId, nome);
        }
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
    };
}

// Executar edição de categoria (macrocategoria)
function executarEdicaoMacrocategoria(macroId, nome) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', urlComSetor(`${window.APP_CONFIG.apiBase}macrocategorias.php/${macroId}`), true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            carregarListaCategorias();
            mostrarToast('Sucesso', 'Categoria atualizada com sucesso!', 'success');
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao atualizar categoria');
        }
    };

    xhr.send(JSON.stringify({ nome }));
}

// Excluir categoria (macrocategoria)
function excluirMacrocategoria(macroId) {
    mostrarConfirm(
        'Excluir Categoria',
        'Tem certeza que deseja excluir esta categoria? Ela precisa estar sem subcategorias vinculadas.',
        function (confirmado) {
            if (!confirmado) return;
            executarExclusaoMacrocategoria(macroId);
        }
    );
}

// Executar exclusão de categoria (macrocategoria)
function executarExclusaoMacrocategoria(macroId) {
    const xhr = new XMLHttpRequest();
    xhr.open('DELETE', urlComSetor(`${window.APP_CONFIG.apiBase}macrocategorias.php/${macroId}`), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Categoria excluída com sucesso!', 'success');
            carregarListaCategorias();
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao excluir categoria');
        }
    };

    xhr.send();
}

// Criar nova subcategoria (categoria)
function criarCategoria(event) {
    event.preventDefault();

    const nomeOriginal = document.getElementById('nome-categoria').value.trim();
    const nome = formatarNomeCategoria(nomeOriginal);
    const macrocategoria_id = document.getElementById('macrocategoria-categoria').value;

    if (!macrocategoria_id) {
        mostrarAlert('Erro', 'Selecione uma categoria!');
        return;
    }

    // Verificar se subcategoria já existe
    const xhr1 = new XMLHttpRequest();
    xhr1.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'categorias.php'), true);
    setSecurityHeaders(xhr1);

    xhr1.onload = function () {
        if (xhr1.status === 200) {
            const categorias = JSON.parse(xhr1.responseText);
            const categoriaExiste = categorias.some(cat => cat.nome.toLowerCase() === nome.toLowerCase());

            if (categoriaExiste) {
                mostrarAlert('Erro', 'Esta subcategoria já existe!');
                return;
            }

            // Criar subcategoria
            const xhr2 = new XMLHttpRequest();
            xhr2.open('POST', urlComSetor(window.APP_CONFIG.apiBase + 'categorias.php'), true);
            xhr2.setRequestHeader('Content-Type', 'application/json');

            // Adicionar token de autenticação
            setSecurityHeaders(xhr2);

            xhr2.onload = function () {
                if (xhr2.status === 200) {
                    document.getElementById('form-categoria').reset();
                    carregarListaCategorias();
                    mostrarToast('Sucesso', 'Subcategoria criada com sucesso!', 'success');
                } else {
                    mostrarAlert('Erro', 'Erro ao criar subcategoria');
                }
            };

            xhr2.send(JSON.stringify({ nome, macrocategoria_id }));
        }
    };

    xhr1.send();
}

// Formatar nome da subcategoria
function formatarNomeCategoria(nome) {
    const abreviaturas = ['SEI', 'BRC', 'VPN', 'TI', 'RH', 'CEO', 'CTO', 'CFO', 'CPF', 'CNPJ', 'PDF', 'XML', 'HTML', 'CSS', 'JS', 'SQL', 'API', 'URL', 'HTTP', 'HTTPS', 'FTP', 'SSH', 'DNS', 'IP', 'TCP', 'UDP', 'USB', 'HD', 'SSD', 'RAM', 'CPU', 'GPU', 'RFID', 'NFC', 'QR'];

    // Preservar palavras entre asteriscos
    const palavrasPreservadas = [];
    let nomeProcessado = nome.replace(/\*([^*]+)\*/g, (match, palavra) => {
        const placeholder = `__PRESERVAR_${palavrasPreservadas.length}__`;
        palavrasPreservadas.push(palavra);
        return placeholder;
    });

    // Formatar o resto normalmente
    nomeProcessado = nomeProcessado.split(' ').map(palavra => {
        // Se é um placeholder, pular formatação
        if (palavra.includes('__PRESERVAR_')) {
            return palavra;
        }

        const palavraUpper = palavra.toUpperCase();
        if (abreviaturas.includes(palavraUpper)) {
            return palavraUpper;
        }
        return palavra.charAt(0).toUpperCase() + palavra.slice(1).toLowerCase();
    }).join(' ');

    // Restaurar palavras preservadas
    palavrasPreservadas.forEach((palavra, index) => {
        nomeProcessado = nomeProcessado.replace(`__PRESERVAR_${index}__`, palavra);
    });

    return nomeProcessado;
}

// Editar subcategoria (categoria)
function editarCategoria(categoriaId) {
    // Buscar dados atuais da subcategoria
    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'categorias.php'), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const categorias = JSON.parse(xhr.responseText);
            const categoria = categorias.find(c => c.id === categoriaId);

            if (categoria) {
                mostrarFormularioEdicaoCategoria(categoria);
            } else {
                console.error('❌ Subcategoria não encontrada');
            }
        }
    };

    xhr.send();
}

// Mostrar formulário de edição de subcategoria
function mostrarFormularioEdicaoCategoria(categoria) {
    // Carregar categorias (macrocategorias)
    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'macrocategorias.php'), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const macrocategorias = JSON.parse(xhr.responseText);

            let opcoesHtml = '<option value="">Selecione uma categoria</option>';
            macrocategorias.forEach(macro => {
                const selected = macro.id === categoria.macrocategoria_id ? 'selected' : '';
                opcoesHtml += `<option value="${macro.id}" ${selected}>${sanitizeHTML(macro.nome)}</option>`;
            });

            const content = `
                <div class="form-group">
                    <label for="edit-nome-cat">Nome:</label>
                    <input type="text" id="edit-nome-cat" value="${sanitizeHTML(categoria.nome)}" required>
                    <small style="color: #666; font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                        📝 Use *asteriscos* para preservar formatação (ex: Sistema *RFID*)
                    </small>
                </div>
                <div class="form-group">
                    <label for="edit-macro-cat">Categoria:</label>
                    <select id="edit-macro-cat" required>
                        ${opcoesHtml}
                    </select>
                </div>
            `;

            document.getElementById('modal-prompt-message').innerHTML = content;
            document.getElementById('modal-prompt-title').textContent = 'Editar Subcategoria';
            document.getElementById('modal-prompt-input').style.display = 'none';

            document.getElementById('modal-overlay').classList.add('show');
            document.getElementById('modal-prompt').classList.add('show');

            modalCallback = function (confirmado) {
                if (confirmado === true) {
                    const nome = document.getElementById('edit-nome-cat').value;
                    const macrocategoriaId = document.getElementById('edit-macro-cat').value;

                    if (nome && macrocategoriaId) {
                        executarEdicaoCategoria(categoria.id, nome.trim(), macrocategoriaId);
                    } else {
                        mostrarAlert('Erro', 'Preencha todos os campos obrigatórios');
                    }
                }

                // Restaurar modal
                document.getElementById('modal-prompt-input').style.display = 'block';
                document.getElementById('modal-prompt-message').innerHTML = '';
            };
        }
    };

    xhr.send();
}

// Executar edição de subcategoria
function executarEdicaoCategoria(categoriaId, nomeOriginal, macrocategoriaId) {
    const nome = formatarNomeCategoria(nomeOriginal);

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', urlComSetor(`${window.APP_CONFIG.apiBase}categorias.php/${categoriaId}`), true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            carregarListaCategorias();
            carregarCategorias();
            mostrarToast('Sucesso', 'Subcategoria atualizada com sucesso!', 'success');
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao atualizar subcategoria');
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send(JSON.stringify({ nome, macrocategoria_id: macrocategoriaId }));
}

// Excluir subcategoria (categoria)
function excluirCategoria(categoriaId) {
    mostrarConfirm(
        'Excluir Subcategoria',
        'Tem certeza que deseja excluir esta subcategoria?',
        function (confirmado) {
            if (!confirmado) return;

            executarExclusaoCategoria(categoriaId);
        }
    );
}

// Executar exclusão de subcategoria
function executarExclusaoCategoria(categoriaId) {
    const xhr = new XMLHttpRequest();
    xhr.open('DELETE', urlComSetor(`${window.APP_CONFIG.apiBase}categorias.php/${categoriaId}`), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Subcategoria excluída com sucesso!', 'success');
            carregarListaCategorias();
            // Atualizar lista global de categorias
            carregarCategorias();
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error);
        }
    };

    xhr.send();
}

// ==== Fase 3: Ativação por Setor (categorias, status, prioridades) ====

let setorConfigAtual = null;
let abaConfigSetorAtiva = 'status';

function mostrarConfigSetor() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active'));

    localStorage.setItem('cd_subSecaoAtiva', 'setor-config');
    const configTitle = document.getElementById('config-title');
    if (configTitle) configTitle.textContent = '🧭 Ativação por Setor';

    const content = document.getElementById('config-content');
    if (!content) {
        setTimeout(() => mostrarConfigSetor(), 200);
        return;
    }

    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');

    if (usuario.tipo === 'admin') {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', window.APP_CONFIG.apiBase + 'setores.php', true);
        setSecurityHeaders(xhr);
        xhr.onload = function () {
            if (xhr.status === 200) {
                const setores = JSON.parse(xhr.responseText);
                renderizarPainelConfigSetor(setores, setores[0] ? setores[0].id : null);
            } else {
                content.innerHTML = '<p>Erro ao carregar setores.</p>';
            }
        };
        xhr.send();
    } else {
        renderizarPainelConfigSetor([], usuario.setor_id);
    }
}

function renderizarPainelConfigSetor(setores, setorInicialId) {
    const content = document.getElementById('config-content');
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuario.tipo === 'admin';

    setorConfigAtual = setorInicialId;
    abaConfigSetorAtiva = 'status';

    const seletorHtml = ehAdmin ? `
        <div class="form-group" style="max-width: 320px;">
            <label for="setor-config-select">Setor:</label>
            <select id="setor-config-select" onchange="setorConfigAtual = parseInt(this.value); carregarAbaConfigSetor('status')">
                ${setores.map(s => `<option value="${s.id}" ${s.id == setorInicialId ? 'selected' : ''}>${sanitizeHTML(s.nome)}</option>`).join('')}
            </select>
        </div>
    ` : '';

    content.innerHTML = `
        <p style="color:#666;margin-bottom:1rem;">Ative/desative e reordene quais status e prioridades aparecem para este setor. O catálogo continua compartilhado com os outros setores — nada é apagado aqui, só escondido. As categorias são próprias de cada setor e ficam em Configurações → Categorias.</p>
        ${seletorHtml}
        <div class="tabs-container" style="margin-bottom:1rem;">
            <button type="button" class="tab-btn active" id="tab-config-status" onclick="carregarAbaConfigSetor('status')">🚦 Status</button>
            <button type="button" class="tab-btn" id="tab-config-prioridades" onclick="carregarAbaConfigSetor('prioridades')">🔥 Prioridades</button>
        </div>
        <div id="config-setor-lista"></div>
    `;

    if (!setorConfigAtual) {
        document.getElementById('config-setor-lista').innerHTML = '<p style="color:#999;font-style:italic;">Nenhum setor disponível.</p>';
        return;
    }

    carregarAbaConfigSetor('status');
}

function carregarAbaConfigSetor(tipo) {
    abaConfigSetorAtiva = tipo;
    document.querySelectorAll('.tabs-container .tab-btn').forEach(b => b.classList.remove('active'));
    const btnAtivo = document.getElementById('tab-config-' + tipo);
    if (btnAtivo) btnAtivo.classList.add('active');

    if (tipo === 'emails') {
        carregarEmailsSetor();
        return;
    }

    const lista = document.getElementById('config-setor-lista');
    if (!lista || !setorConfigAtual) return;
    lista.innerHTML = '<p>Carregando...</p>';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}setor-config.php?tipo=${tipo}&setor_id=${setorConfigAtual}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            renderizarListaConfigSetor(JSON.parse(xhr.responseText), tipo);
        } else {
            lista.innerHTML = '<p>Erro ao carregar configuração.</p>';
        }
    };

    xhr.onerror = function () {
        lista.innerHTML = '<p>Erro de conexão.</p>';
    };

    xhr.send();
}

// ==== Fase 4: E-mails por Setor ====

const LIMITE_EMAILS_POR_SETOR = 1;

function carregarEmailsSetor() {
    const lista = document.getElementById('config-setor-lista');
    if (!lista || !setorConfigAtual) return;
    lista.innerHTML = '<p>Carregando...</p>';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}setor-emails.php?setor_id=${setorConfigAtual}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            renderizarEmailsSetor(JSON.parse(xhr.responseText));
        } else {
            lista.innerHTML = '<p>Erro ao carregar e-mails do setor.</p>';
        }
    };

    xhr.onerror = function () {
        lista.innerHTML = '<p>Erro de conexão.</p>';
    };

    xhr.send();
}

function renderizarEmailsSetor(caixas) {
    const lista = document.getElementById('config-setor-lista');

    const cardsHtml = caixas.map(caixa => `
        <div class="config-setor-item ${caixa.ativo ? '' : 'config-setor-item-inativo'}" style="flex-direction:column;align-items:flex-start;gap:0.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;width:100%;">
                <div>
                    <strong>${sanitizeHTML(caixa.email)}</strong>
                    <div style="font-size:0.85rem;color:#666;">Recebimento (IMAP): ${caixa.imap_host}:${caixa.imap_port} · usuário: ${caixa.imap_user}</div>
                    <div style="font-size:0.85rem;color:#666;">Envio (SMTP): ${caixa.smtp_host ? caixa.smtp_host + ':' + caixa.smtp_port + ' (' + (caixa.smtp_seguranca === 'tls' ? 'STARTTLS' : 'SSL') + ')' : 'servidor global do sistema'} · remetente: ${caixa.nome_remetente || 'Conecta CRPRS - (nome do setor)'}</div>
                </div>
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" ${caixa.ativo ? 'checked' : ''} onchange="alternarAtivoEmailSetor(${caixa.id}, this.checked)">
                        <span class="slider"></span>
                    </label>
                </label>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="button" class="btn-small" onclick="excluirEmailSetor(${caixa.id})">🗑️ Excluir</button>
            </div>
        </div>
    `).join('');

    const podeAdicionar = caixas.length < LIMITE_EMAILS_POR_SETOR;

    lista.innerHTML = `
        <p style="color:#666;margin-bottom:1rem;">Cada setor tem um endereço de e-mail. Os e-mails recebidos nele viram demandas automaticamente neste setor e as respostas às demandas do setor são enviadas por esse mesmo endereço.</p>
        <div class="config-setor-itens">${cardsHtml}</div>
        ${podeAdicionar ? `
            <button type="button" class="btn-primary" style="margin-top:1rem;" onclick="mostrarFormularioEmailSetor()">➕ Cadastrar E-mail do Setor</button>
            <div id="form-email-setor-container"></div>
        ` : `<p style="margin-top:1rem;color:#999;font-style:italic;">Este setor já possui um endereço de e-mail. Para trocá-lo, exclua o atual e cadastre outro.</p>`}

        <div style="margin-top:2rem;border-top:1px solid #eee;padding-top:1rem;">
            <h4 style="margin-bottom:0.75rem;">📨 Log de E-mails Recebidos</h4>
            <div id="log-emails-processados-setor"><p>Carregando...</p></div>
        </div>
    `;

    carregarLogEmailsProcessadosSetor();
}

function carregarLogEmailsProcessadosSetor() {
    const container = document.getElementById('log-emails-processados-setor');
    if (!container || !setorConfigAtual) return;

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}emails-processados.php?setor_id=${setorConfigAtual}&limite=20`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status !== 200) {
            container.innerHTML = '<p>Erro ao carregar log de e-mails.</p>';
            return;
        }
        const dados = JSON.parse(xhr.responseText);
        if (!dados.registros || dados.registros.length === 0) {
            container.innerHTML = '<p style="color:#999;font-style:italic;">Nenhum e-mail processado ainda para este setor.</p>';
            return;
        }

        const rotuloTipo = { novo_ticket: '🆕 Nova demanda', comentario: '💬 Comentário', erro: '❌ Erro' };

        container.innerHTML = `
            <div class="config-setor-itens">
                ${dados.registros.map(r => `
                    <div class="config-setor-item" style="flex-direction:column;align-items:flex-start;gap:0.25rem;">
                        <div style="display:flex;justify-content:space-between;width:100%;">
                            <strong>${r.assunto || '(sem assunto)'}</strong>
                            <span style="font-size:0.85rem;color:#666;">${new Date(r.processado_em).toLocaleString('pt-BR')}</span>
                        </div>
                        <div style="font-size:0.85rem;color:#666;">
                            De: ${r.email_remetente} · Caixa: ${r.setor_email_endereco || '—'} · ${rotuloTipo[r.tipo] || r.tipo}
                            ${r.ticket_id ? ` · Demanda #${String(r.ticket_id).padStart(5, '0')}` : ''}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    };

    xhr.onerror = function () {
        container.innerHTML = '<p>Erro de conexão.</p>';
    };

    xhr.send();
}

function mostrarFormularioEmailSetor() {
    const container = document.getElementById('form-email-setor-container');
    if (!container) return;

    container.innerHTML = `
        <form id="form-nova-caixa-email" style="margin-top:1rem;border-top:1px solid #eee;padding-top:1rem;" onsubmit="salvarEmailSetor(event)">
            <div class="form-row">
                <div class="form-group">
                    <label for="novo-email-endereco">E-mail:</label>
                    <input type="email" id="novo-email-endereco" required placeholder="setor@crprs.org.br">
                </div>
                <div class="form-group">
                    <label for="novo-email-usuario">Usuário IMAP:</label>
                    <input type="text" id="novo-email-usuario" required placeholder="geralmente igual ao e-mail">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="novo-email-host">Host IMAP:</label>
                    <input type="text" id="novo-email-host" required placeholder="email-ssl.com.br">
                </div>
                <div class="form-group">
                    <label for="novo-email-porta">Porta IMAP:</label>
                    <input type="number" id="novo-email-porta" required value="993">
                </div>
            </div>
            <div class="form-group">
                <label for="novo-email-senha">Senha IMAP:</label>
                <input type="password" id="novo-email-senha" required autocomplete="new-password">
            </div>

            <h4 style="margin:1rem 0 0.25rem 0;">📤 Envio (SMTP)</h4>
            <p style="color:#666;font-size:0.85rem;margin:0 0 0.75rem 0;">Em branco = usa o servidor SMTP do sistema e as mesmas credenciais do IMAP.</p>
            <div class="form-row">
                <div class="form-group">
                    <label for="novo-smtp-host">Host SMTP:</label>
                    <input type="text" id="novo-smtp-host" placeholder="mesmo servidor do sistema">
                </div>
                <div class="form-group">
                    <label for="novo-smtp-porta">Porta SMTP:</label>
                    <input type="number" id="novo-smtp-porta" value="465">
                </div>
                <div class="form-group">
                    <label for="novo-smtp-seguranca">Segurança:</label>
                    <select id="novo-smtp-seguranca">
                        <option value="ssl">SSL/TLS (porta 465)</option>
                        <option value="tls">STARTTLS (porta 587)</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="novo-smtp-usuario">Usuário SMTP (opcional):</label>
                    <input type="text" id="novo-smtp-usuario" placeholder="igual ao usuário IMAP">
                </div>
                <div class="form-group">
                    <label for="novo-smtp-senha">Senha SMTP (opcional):</label>
                    <input type="password" id="novo-smtp-senha" autocomplete="new-password" placeholder="igual à senha IMAP">
                </div>
            </div>
            <div class="form-group">
                <label for="novo-nome-remetente">Nome do remetente (opcional):</label>
                <input type="text" id="novo-nome-remetente" maxlength="100" placeholder="Conecta CRPRS - Cadastro">
            </div>
            <div id="resultado-teste-email" style="margin-bottom:0.75rem;"></div>
            <div style="display:flex;gap:0.5rem;">
                <button type="button" class="btn-small" onclick="testarEmailSetor()">🔌 Testar Conexão</button>
                <button type="submit" class="btn-primary">Salvar</button>
                <button type="button" class="btn-small" onclick="document.getElementById('form-email-setor-container').innerHTML=''">Cancelar</button>
            </div>
        </form>
    `;
}

function coletarDadosFormEmailSetor() {
    return {
        email: document.getElementById('novo-email-endereco').value.trim(),
        imap_user: document.getElementById('novo-email-usuario').value.trim(),
        imap_host: document.getElementById('novo-email-host').value.trim(),
        imap_port: parseInt(document.getElementById('novo-email-porta').value, 10) || 993,
        imap_pass: document.getElementById('novo-email-senha').value,
        smtp_host: document.getElementById('novo-smtp-host').value.trim(),
        smtp_port: parseInt(document.getElementById('novo-smtp-porta').value, 10) || 465,
        smtp_seguranca: document.getElementById('novo-smtp-seguranca').value,
        smtp_user: document.getElementById('novo-smtp-usuario').value.trim(),
        smtp_pass: document.getElementById('novo-smtp-senha').value,
        nome_remetente: document.getElementById('novo-nome-remetente').value.trim()
    };
}

function testarEmailSetor() {
    const dados = coletarDadosFormEmailSetor();
    const resultadoDiv = document.getElementById('resultado-teste-email');

    if (!dados.email || !dados.imap_user || !dados.imap_host || !dados.imap_pass) {
        resultadoDiv.innerHTML = '<p style="color:#dc3545;">Preencha todos os campos antes de testar.</p>';
        return;
    }

    resultadoDiv.innerHTML = '<p>Testando conexão...</p>';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'setor-emails.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        try {
            const resp = JSON.parse(xhr.responseText);
            const cor = resp.sucesso ? '#28a745' : '#dc3545';
            resultadoDiv.innerHTML = `<p style="color:${cor};">${resp.sucesso ? '✅' : '❌'} ${sanitizeHTML(resp.mensagem)}</p>`;
        } catch (e) {
            resultadoDiv.innerHTML = '<p style="color:#dc3545;">Erro ao testar conexão.</p>';
        }
    };

    xhr.onerror = function () {
        resultadoDiv.innerHTML = '<p style="color:#dc3545;">Erro de conexão.</p>';
    };

    xhr.send(JSON.stringify(Object.assign({ acao: 'testar' }, dados)));
}

function salvarEmailSetor(event) {
    event.preventDefault();
    const dados = coletarDadosFormEmailSetor();

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'setor-emails.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Caixa de e-mail cadastrada!', 'success');
            carregarEmailsSetor();
        } else {
            let mensagemErro = 'Erro ao cadastrar caixa de e-mail';
            try { mensagemErro = JSON.parse(xhr.responseText).error || mensagemErro; } catch (e) { }
            mostrarAlert('Erro', mensagemErro);
        }
    };

    xhr.onerror = function () {
        ocultarLoader();
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send(JSON.stringify(Object.assign({ setor_id: setorConfigAtual }, dados)));
}

function alternarAtivoEmailSetor(id, ativo) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}setor-emails.php/${id}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Caixa de e-mail atualizada!', 'success');
        } else {
            mostrarAlert('Erro', 'Erro ao atualizar caixa de e-mail');
            carregarEmailsSetor();
        }
    };

    xhr.send(JSON.stringify({ ativo: ativo }));
}

function excluirEmailSetor(id) {
    mostrarConfirm('Excluir Caixa de E-mail', 'Tem certeza que deseja excluir esta caixa de e-mail? Novos e-mails recebidos nela deixarão de virar demandas.', function (confirmado) {
        if (!confirmado) return;

        const xhr = new XMLHttpRequest();
        xhr.open('DELETE', `${window.APP_CONFIG.apiBase}setor-emails.php/${id}`, true);
        setSecurityHeaders(xhr);

        xhr.onload = function () {
            if (xhr.status === 200) {
                mostrarToast('Sucesso', 'Caixa de e-mail excluída!', 'success');
                carregarEmailsSetor();
            } else {
                mostrarAlert('Erro', 'Erro ao excluir caixa de e-mail');
            }
        };

        xhr.send();
    });
}

function formatarNomeItemConfigSetor(tipo, nome) {
    if (tipo === 'status') return typeof formatarNomeStatus === 'function' ? formatarNomeStatus(nome) : nome;
    if (tipo === 'prioridades') return typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(nome) : nome;
    return nome;
}

function renderizarListaConfigSetor(itens, tipo) {
    const lista = document.getElementById('config-setor-lista');

    if (itens.length === 0) {
        lista.innerHTML = '<p style="color:#999;font-style:italic;">Nenhum item cadastrado no catálogo global ainda.</p>';
        return;
    }

    lista.innerHTML = `
        <div class="config-setor-itens">
            ${itens.map((item, index) => `
                <div class="config-setor-item ${item.ativo ? '' : 'config-setor-item-inativo'}">
                    <label class="switch-label" style="flex:1;">
                        <label class="switch">
                            <input type="checkbox" ${item.ativo ? 'checked' : ''} onchange="alternarAtivoConfigSetor(${item.id}, '${tipo}', this.checked)">
                            <span class="slider"></span>
                        </label>
                        <span>${formatarNomeItemConfigSetor(tipo, item.nome)}</span>
                    </label>
                    <div class="config-setor-item-ordem">
                        <button type="button" class="btn-small" ${index === 0 ? 'disabled' : ''} onclick="moverItemConfigSetor(${item.id}, '${tipo}', 'up')" title="Mover para cima">▲</button>
                        <button type="button" class="btn-small" ${index === itens.length - 1 ? 'disabled' : ''} onclick="moverItemConfigSetor(${item.id}, '${tipo}', 'down')" title="Mover para baixo">▼</button>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

function alternarAtivoConfigSetor(itemId, tipo, ativo) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', window.APP_CONFIG.apiBase + 'setor-config.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Configuração atualizada!', 'success');
        } else {
            mostrarAlert('Erro', 'Erro ao atualizar configuração');
            carregarAbaConfigSetor(tipo);
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão');
        carregarAbaConfigSetor(tipo);
    };

    xhr.send(JSON.stringify({ tipo: tipo, setor_id: setorConfigAtual, item_id: itemId, ativo: ativo }));
}

function moverItemConfigSetor(itemId, tipo, direcao) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'setor-config.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            carregarAbaConfigSetor(tipo);
        } else {
            mostrarAlert('Erro', 'Erro ao reordenar');
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send(JSON.stringify({ tipo: tipo, setor_id: setorConfigAtual, item_id: itemId, direcao: direcao }));
}

// Função para limpar formulário de ticket completamente
function limparFormularioTicket() {
    console.log('🧹 Limpando formulário de ticket...');

    // Reset geral do formulário primeiro
    document.getElementById('form-ticket').reset();

    // Limpar campos específicos
    document.getElementById('titulo').value = '';
    document.getElementById('descricao').value = '';
    // Limpar editor isolado via Dispatch no Alpine (se for o formulário da main SPA)
    const alpineNode = document.getElementById('editor-descricao');
    if (alpineNode && alpineNode.__x) {
        // Apenas limpamos o state no frontend form
        // Alpine.js ou dispatch faria isso mas neste caso recarregar limpamos o innerHTML forçado
        alpineNode.innerHTML = '<p><br></p>';
    }

    // Limpar campo de categoria
    const categoriaBusca = document.getElementById('categoria-busca');
    if (categoriaBusca) {
        categoriaBusca.value = '';
    }

    const categoriaId = document.getElementById('categoria-id');
    if (categoriaId) {
        categoriaId.value = '';
    }

    // Ocultar lista de categorias
    const categoriaLista = document.getElementById('categoria-lista-form');
    if (categoriaLista) {
        categoriaLista.classList.remove('show');
    }

    // Resetar select de prioridade para Normal
    const prioridadeSelect = document.getElementById('prioridade');
    if (prioridadeSelect) {
        prioridadeSelect.value = 'normal';
    }

    // Limpar anexos completamente
    const anexosInput = document.getElementById('anexos');
    if (anexosInput) {
        anexosInput.value = '';
    }
    anexosSelecionados = [];

    const anexosInfo = document.getElementById('anexos-info');
    if (anexosInfo) {
        anexosInfo.innerHTML = '';
    }

    const anexosErro = document.getElementById('anexos-erro');
    if (anexosErro) {
        anexosErro.innerHTML = '';
    }

    // Remover seleção de categoria
    document.querySelectorAll('.categoria-item-form').forEach(i => i.classList.remove('selected'));

    // Recarregar todas as categorias
    if (todasCategorias.length > 0) {
        renderizarCategoriasForm(todasCategorias);
    }

    console.log('✅ Formulário limpo com sucesso!');
}

// Carregar tickets fechados (encerrados e cancelados)
function carregarTicketsFechados() {
    mostrarSkeletonTickets('lista-fechados');
    prepararFiltroArquivo();

    // Escopo vem do filtro do Arquivo (select); o servidor aplica as regras de cada papel
    const seletorEscopo = document.getElementById('filtro-arquivo-escopo');
    if (seletorEscopo && seletorEscopo.value) {
        escopoArquivoAtual = seletorEscopo.value;
    }

    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'tickets.php?escopo=' + encodeURIComponent(escopoArquivoAtual)), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const ticketsFechados = JSON.parse(xhr.responseText);

            // Re-aplicar ordenação persistida antes de renderizar
            if (ordemFechados.campo) {
                const campo = ordemFechados.campo;
                ticketsFechadosAtuais = [...ticketsFechados].sort((a, b) => {
                    let valorA = a[campo] || '';
                    let valorB = b[campo] || '';

                    if (campo === 'criado_em' || campo === 'fechado_em') {
                        valorA = new Date(valorA);
                        valorB = new Date(valorB);
                    } else if (campo === 'id') {
                        valorA = parseInt(valorA);
                        valorB = parseInt(valorB);
                    }

                    if (valorA < valorB) return ordemFechados.direcao === 'asc' ? -1 : 1;
                    if (valorA > valorB) return ordemFechados.direcao === 'asc' ? 1 : -1;
                    return 0;
                });
            } else {
                ticketsFechadosAtuais = ticketsFechados;
            }

            aplicarFiltroMeusTicketsFechados();
        } else {
            document.getElementById('lista-fechados').innerHTML = '<p>Erro ao carregar tickets fechados.</p>';
        }
    };

    xhr.send();
}

let timeoutBuscaFechados;
function aplicarBuscaFechadosComDebounce() {
    clearTimeout(timeoutBuscaFechados);
    timeoutBuscaFechados = setTimeout(() => {
        aplicarFiltroMeusTicketsFechados();
    }, 300);
}

function limparBuscaFechados() {
    const input = document.getElementById('busca-fechados');
    if (input) {
        input.value = '';
        aplicarFiltroMeusTicketsFechados();
    }
}

// Filtro combinado de fechados
function filtrarEMostrarTicketsFechados(ticketsBase) {
    let filtrados = ticketsBase;

    // Aplicar busca textual se ativa
    const termoBusca = document.getElementById('busca-fechados')?.value.toLowerCase().trim();
    if (termoBusca) {
        filtrados = filtrados.filter(t =>
            (t.titulo || '').toLowerCase().includes(termoBusca) ||
            (t.descricao || '').toLowerCase().includes(termoBusca) ||
            (t.usuario_nome || '').toLowerCase().includes(termoBusca)
        );
    }

    ticketsFechadosFiltradosPorBusca = filtrados;
    mostrarTicketsFechadosNaTela(filtrados);
}

// A função original aplicarFiltroMeusTicketsFechados agora delega para filtrarEMostrarTicketsFechados
function aplicarFiltroMeusTicketsFechados() {
    const filtroAtivo = document.getElementById('filtro-meus-tickets-fechados');
    let base = ticketsFechadosAtuais;

    if (filtroAtivo && filtroAtivo.checked) {
        const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario'));
        base = ticketsFechadosAtuais.filter(ticket => ticket.agente_id == usuarioLogado.id);
    }

    filtrarEMostrarTicketsFechados(base);
}

// Renderizar sub-botões de paginação
function renderizarPaginacaoNumerosFechados(totalPaginas) {
    const delta = 2;
    const paginas = [];
    for (let i = Math.max(1, paginaAtualFechados - delta); i <= Math.min(totalPaginas, paginaAtualFechados + delta); i++) {
        paginas.push(i);
    }
    return paginas.map(p => `
        <button class="btn-paginacao ${p === paginaAtualFechados ? 'ativo' : ''}" onclick="irParaPaginaFechados(${p})" aria-label="Página ${p}" ${p === paginaAtualFechados ? 'aria-current="page"' : ''}>${p}</button>
    `).join('');
}

function irParaPaginaFechados(p) {
    paginaAtualFechados = p;
    mostrarTicketsFechadosNaTela(ticketsFechadosFiltradosPorBusca.length > 0 ? ticketsFechadosFiltradosPorBusca : ticketsFechadosAtuais);
    document.getElementById('lista-fechados')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function alterarPorPaginaFechados(valor) {
    porPaginaFechados = parseInt(valor);
    localStorage.setItem('cd_ticketsFechadosPorPagina', porPaginaFechados);
    paginaAtualFechados = 1;
    mostrarTicketsFechadosNaTela(ticketsFechadosFiltradosPorBusca.length > 0 ? ticketsFechadosFiltradosPorBusca : ticketsFechadosAtuais);
}

// Ofuscar e-mail conforme premissa da LGPD
function ofuscarEmail(email) {
    if (!email || typeof email !== 'string') return '';
    const partes = email.split('@');
    if (partes.length !== 2) return email;
    const [nome, dominio] = partes;
    if (nome.length <= 2) return `${nome.charAt(0)}***@${dominio}`;
    return `${nome.substring(0, 2)}***${nome.slice(-1)}@${dominio}`;
}

// Mostrar tickets fechados na tela
function mostrarTicketsFechadosNaTela(tickets) {
    const listaFechados = document.getElementById('lista-fechados');

    ticketsFechadosFiltradosPorBusca = tickets;

    if (tickets.length === 0) {
        listaFechados.innerHTML = `
            <div style="text-align:center; padding: 3rem 1rem; color: #64748b;">
                <span class="material-symbols-outlined" style="font-size:3rem; opacity:0.4;">inbox</span>
                <p style="margin-top:0.5rem; font-size:0.95rem;">Nenhuma demanda fechada encontrada para este filtro.</p>
            </div>`;
        return;
    }

    // Paginação
    const totalPaginas = Math.ceil(tickets.length / porPaginaFechados);
    if (paginaAtualFechados > totalPaginas && totalPaginas > 0) paginaAtualFechados = totalPaginas;
    const inicio = (paginaAtualFechados - 1) * porPaginaFechados;
    const fim = Math.min(inicio + porPaginaFechados, tickets.length);
    const ticketsPagina = tickets.slice(inicio, fim);

    let tabelaHTML = `
        <table class="tickets-tabela">
            <thead>
                <tr>
                    <th onclick="ordenarTabelaFechados('id')" class="sortable" data-campo-fechado="id">Nº Demanda <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('criado_em')" class="sortable" data-campo-fechado="criado_em">Abertura <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('titulo')" class="sortable" data-campo-fechado="titulo">Assunto <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('usuario_nome')" class="sortable" data-campo-fechado="usuario_nome">Solicitante <span class="sort-arrows">↕</span></th>
                    ${exibirColunaSetor(true) ? '<th onclick="ordenarTabelaFechados(\'setor_nome\')" class="sortable" data-campo-fechado="setor_nome">Setor <span class="sort-arrows">↕</span></th>' : ''}
                    <th onclick="ordenarTabelaFechados('agente_nome')" class="sortable" data-campo-fechado="agente_nome">Responsável <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('categoria_nome')" class="sortable" data-campo-fechado="categoria_nome">Subcategoria <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('status')" class="sortable" data-campo-fechado="status">Status <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('fechado_em')" class="sortable" data-campo-fechado="fechado_em" style="white-space: nowrap;">Encerramento <span class="sort-arrows">↕</span></th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
    `;

    ticketsPagina.forEach(ticket => {
        // Ícones Material Symbols
        const iconeAnexo = ticket.tem_anexos > 0
            ? `<span class="material-symbols-outlined icone-tabela icone-anexo-ms" data-vg-tooltip="Esta demanda possui arquivos anexados." data-vg-title="ANEXOS" aria-label="Tem anexos">attach_file</span>` : '';
        const iconeMesclado = ticket.eh_mesclado > 0
            ? `<span class="material-symbols-outlined icone-tabela" data-vg-tooltip="Esta demanda foi mesclada com outra demanda." data-vg-title="DEMANDA MESCLADA" aria-label="Mesclado" style="color:#6366f1">call_merge</span>` : '';

        // Título truncado
        const tituloSeguro = sanitizeHTML(ticket.titulo || '');

        tabelaHTML += `
            <tr data-ticket-id="${ticket.id}" ${modoMergeFechadosAtivo ? 'style="cursor: pointer;"' : ''}>
                <td data-label="Nº Demanda">
                    <a href="#" onclick="${modoMergeFechadosAtivo ? 'event.stopPropagation();' : ''} verDetalhes(${ticket.id})" class="ticket-link">#${String(ticket.id).padStart(5, '0')}</a>
                    ${iconeMesclado}${iconeAnexo}
                </td>
                <td data-label="Abertura">${new Date(ticket.criado_em).toLocaleDateString('pt-BR')}<span class="ticket-data-hora">${new Date(ticket.criado_em).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</span></td>
                <td class="ticket-titulo-cell" data-label="Assunto">
                    <span class="ticket-titulo-texto" data-vg-tooltip="${tituloSeguro}" data-vg-title="ASSUNTO">${sanitizeHTML(ticket.titulo)}</span>
                </td>
                <td data-label="Solicitante">
                    <strong>${sanitizeHTML(ticket.usuario_nome)}</strong>
                    ${ticket.usuario_email ? `<span class="ticket-data-hora" style="font-size: 0.85em;">${ofuscarEmail(ticket.usuario_email)}</span>` : ''}
                </td>
                ${exibirColunaSetor(true) ? `<td data-label="Setor">${sanitizeHTML(ticket.setor_nome || '-')}</td>` : ''}
                <td data-label="Responsável">${sanitizeHTML(ticket.agente_nome || 'Não atribuído')}</td>
                <td data-label="Subcategoria">${sanitizeHTML(ticket.categoria_nome || 'Sem subcategoria')}</td>
                <td data-label="Status"><span class="status ${ticket.status}">${ticket.status === 'encerrado' ? '🏁 Encerrado' : ticket.status === 'cancelado' ? '❌ Cancelado' : (typeof formatarNomeStatus === 'function' ? formatarNomeStatus(ticket.status) : ticket.status)}</span></td>
                <td data-label="Encerramento" style="font-size: 0.8rem; font-weight: bold;">${ticket.fechado_em ? new Date(ticket.fechado_em).toLocaleDateString('pt-BR') + '<span class="ticket-data-hora">' + new Date(ticket.fechado_em).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) + '</span>' : '-'}</td>
                <td data-label="Ações">
                    <button class="btn-compact" onclick="${modoMergeFechadosAtivo ? 'event.stopPropagation();' : ''} reabrirTicket(${ticket.id})" style="background-color: #28a745; margin-right: 0.5rem; transition: background 0.2s;" onmouseover="this.style.backgroundColor='#218838'" onmouseout="this.style.backgroundColor='#28a745'">Reabrir</button>
                </td>
            </tr>
        `;
    });

    // Footer: resumo + paginação
    const inicioExib = inicio + 1;
    const fimExib = fim;
    const totalExib = tickets.length;

    tabelaHTML += `
            </tbody>
        </table>
        <div class="tabela-footer" role="navigation" aria-label="Paginação de demandas fechadas">
            <div class="tabela-resumo">
                Exibindo <strong>${inicioExib}–${fimExib}</strong> de <strong>${totalExib}</strong> demanda${totalExib !== 1 ? "s" : ""}
            </div>
            <div class="paginacao">
                <button class="btn-paginacao" onclick="irParaPaginaFechados(${paginaAtualFechados - 1})" ${paginaAtualFechados <= 1 ? 'disabled' : ''} aria-label="Página anterior">‹</button>
                ${renderizarPaginacaoNumerosFechados(totalPaginas)}
                <button class="btn-paginacao" onclick="irParaPaginaFechados(${paginaAtualFechados + 1})" ${paginaAtualFechados >= totalPaginas ? 'disabled' : ''} aria-label="Próxima página">›</button>
                <span class="paginacao-info">de ${totalPaginas}</span>
            </div>
            <div class="paginacao-por-pagina">
                <label for="tickets-fechados-por-pagina">Por página:</label>
                <select id="tickets-fechados-por-pagina" onchange="alterarPorPaginaFechados(this.value)" aria-label="Itens por página">
                    <option value="25" ${porPaginaFechados === 25 ? 'selected' : ''}>25</option>
                    <option value="50" ${porPaginaFechados === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${porPaginaFechados === 100 ? 'selected' : ''}>100</option>
                </select>
            </div>
        </div>
    `;

    listaFechados.innerHTML = tabelaHTML;

    // Aplicar indicador de ordenação ativa
    if (ordemFechados.campo) {
        const ths = listaFechados.querySelectorAll('th[data-campo-fechado]');
        ths.forEach(th => {
            const arrow = th.querySelector('.sort-arrows');
            if (th.dataset.campoFechado === ordemFechados.campo) {
                th.classList.add('sort-ativo');
                if (arrow) arrow.textContent = ordemFechados.direcao === 'asc' ? '↑' : '↓';
            } else {
                th.classList.remove('sort-ativo');
                if (arrow) arrow.textContent = '↕';
            }
        });
    }

    // Adicionar event listeners para modo merge
    if (modoMergeFechadosAtivo) {
        document.querySelectorAll('#lista-fechados tr[data-ticket-id]').forEach(row => {
            row.addEventListener('click', function (e) {
                const ticketId = parseInt(this.getAttribute('data-ticket-id'));
                selecionarTicketFechadoParaMerge(ticketId, this);
            });
        });
    }
}


// Mostrar página de tickets fechados
// Monta as opções do filtro do Arquivo conforme o papel do usuário
function prepararFiltroArquivo() {
    const seletor = document.getElementById('filtro-arquivo-escopo');
    if (!seletor) return;

    const u = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const opcoes = [];
    if (u.tipo === 'gestor' || u.tipo === 'admin') {
        opcoes.push({ valor: 'arquivo', texto: 'Encerradas e canceladas' });
        opcoes.push({ valor: 'transferidas', texto: 'Transferidas para outros setores' });
    } else if (u.tipo === 'operador') {
        opcoes.push({ valor: 'arquivo', texto: 'Minhas encerradas e canceladas' });
        opcoes.push({ valor: 'repassadas', texto: 'Repassadas por mim' });
    }

    const wrapper = document.getElementById('filtro-arquivo-wrapper');
    if (opcoes.length === 0) {
        if (wrapper) wrapper.hidden = true;
        return;
    }
    if (wrapper) wrapper.hidden = false;

    seletor.innerHTML = opcoes.map(o => `<option value="${o.valor}">${o.texto}</option>`).join('');
    seletor.value = opcoes.some(o => o.valor === escopoArquivoAtual) ? escopoArquivoAtual : 'arquivo';
    escopoArquivoAtual = seletor.value;
}

function mudarEscopoArquivo() {
    const seletor = document.getElementById('filtro-arquivo-escopo');
    escopoArquivoAtual = seletor ? seletor.value : 'arquivo';
    paginaAtualFechados = 1;
    carregarTicketsFechados();
}

function mostrarTicketsFechados() {
    mostrarSecao('fechados');
    carregarTicketsFechados();
}

// Função para mostrar relatórios
function mostrarRelatorios() {
    mostrarSecao('relatorios');
    salvarSecaoAtiva('relatorios');
    carregarFiltrosRelatorio();
}

// Reabrir ticket fechado
function reabrirTicket(ticketId) {
    mostrarConfirm(
        'Reabrir Demanda',
        'Tem certeza que deseja reabrir esta demanda?',
        function (confirmado) {
            if (!confirmado) return;

            const row = document.querySelector(`tr[data-ticket-id="${ticketId}"]`);
            if (row) {
                row.classList.add('ticket-removendo');
            }

            // Usar status 'reaberto' ao reabrir um ticket encerrado
            setTimeout(() => {
                executarAlteracaoStatus(ticketId, 'reaberto', function () {
                    carregarTicketsFechados(); // Recarregar lista de fechados
                });
            }, 500);
        }
    );
}

// Remover ticket fechado
function removerTicketFechado(ticketId) {
    mostrarConfirm(
        'Remover Demanda',
        'Tem certeza que deseja remover esta demanda fechada? Esta ação não pode ser desfeita.',
        function (confirmado) {
            if (!confirmado) return;

            executarExclusaoTicket(ticketId, function () {
                carregarTicketsFechados(); // Recarregar lista de fechados
            });
        },
        true // isPerigoso = true
    );
}

// Função para mostrar tickets com atualização forçada
function mostrarTicketsComAtualizacao() {
    mostrarSecao('tickets');
    carregarTickets();
}

// Função para voltar à seção de origem
function voltarParaOrigem() {
    // Limpar ticket ativo
    localStorage.removeItem('cd_ticketAtivo');
    localStorage.removeItem('cd_secaoOrigem');

    if (secaoOrigem === 'fechados') {
        mostrarTicketsFechados();
    } else if (secaoOrigem === 'lixeira') {
        mostrarLixeira();
    } else {
        mostrarTicketsComAtualizacao();
    }
}

// Função para mostrar dashboard
function mostrarDashboard() {
    mostrarSecao('dashboard');
    carregarDashboard();
}

// Carregar dados do dashboard
function carregarDashboard() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'dashboard.php'), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const dados = JSON.parse(xhr.responseText);
            mostrarDashboardNaTela(dados);
        } else {
            document.getElementById('dashboard-content').innerHTML = '<p>Erro ao carregar dashboard.</p>';
        }
    };

    xhr.send();
}

// Mostrar dashboard na tela
function mostrarDashboardNaTela(dados) {
    const content = document.getElementById('dashboard-content');

    function formatarTempo(segundos) {
        if (!segundos) return 'N/A';
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        return `${horas}h ${minutos}m`;
    }

    const taxaConclusao = dados.sla_indicadores?.taxa_conclusao || 0;

    let html = `
        <div class="dashboard-grid kpi-row-5">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">tag</span> Total de Demandas
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('total')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up" data-target="${dados.total_tickets}">0</div>
                <div class="dashboard-subtitle">Histórico geral do painel</div>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">check_circle</span> Encerrados
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('encerrados')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up" data-target="${dados.total_encerrados || 0}">0</div>
                <div class="dashboard-subtitle">Concluídos com sucesso</div>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">pending_actions</span> Ativos
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('ativos')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up" data-target="${dados.total_tickets - (dados.total_encerrados || 0)}">0</div>
                <div class="dashboard-subtitle">Aguardando time ou usuário</div>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">task_alt</span> Taxa de Conclusão
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('taxa-conclusao')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number flip-in">${taxaConclusao}%</div>
                <div class="dashboard-subtitle">Percentual de finalização</div>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">timer</span> Tempo Médio Total
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('tempo-total')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number scale-bounce">${formatarTempo(dados.tempoMedioSegundos)}</div>
                <div class="dashboard-subtitle">Até o encerramento da demanda</div>
            </div>
        </div>

        <div class="dashboard-grid kpi-row-5" style="margin-top: 1.5rem;">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">bolt</span> Tempo Médio 1º Atend.
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('primeiro-atendimento')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number slide-down">${formatarTempo(dados.tempoPrimeiroAtendimentoSegundos)}</div>
                <div class="dashboard-subtitle">Velocidade da primeira resposta</div>
            </div>
            <div class="dashboard-card fcr-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">rocket_launch</span> FCR (1º Contato)
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('fcr')" title="Clique para mais informações" aria-hidden="true" style="color: rgba(255,255,255,0.8);">info</span>
                </h3>
                <div class="dashboard-number flip-in fcr-number">${dados.fcr?.taxa || 0}%</div>
                <div class="fcr-subtitle">
                    ${dados.fcr?.resolvidos_primeiro_contato || 0} de ${dados.fcr?.total_resolvidos || 0} resolvidos na primeira interação
                </div>
            </div>
            <div class="dashboard-card kpi-sla-vencido">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">warning</span> SLA Vencido
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('sla-vencido')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up sla-vencido-number" data-target="${dados.sla_vencido || 0}">0</div>
                <div class="dashboard-subtitle">Demandas ativas com SLA expirado</div>
            </div>
            <div class="dashboard-card kpi-origem-externa">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">api</span> Via Canal Externo
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('origem-externa')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up origem-externa-number" data-target="${dados.total_origem_externa || 0}">0</div>
                <div class="dashboard-subtitle">Demandas recebidas por integração</div>
            </div>
        </div>

        <div class="dashboard-grid graficos-row-4" style="margin-top: 1.5rem;">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">donut_large</span> Demandas por Status <span class="text-caption">(apenas ativos)</span>
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('status')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-status" width="320" height="220"></canvas>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">local_fire_department</span> Demandas por Prioridade <span class="text-caption">(apenas ativos)</span>
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('prioridade')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-prioridade" width="400" height="260"></canvas>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">pie_chart</span> Distribuição por Responsável <span class="text-caption">(apenas ativos)</span>
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('agente')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-agente" width="400" height="260"></canvas>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">stacked_bar_chart</span> Status por Responsável <span class="text-caption">(apenas ativos)</span>
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('status-agente')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-status-agente" width="400" height="260"></canvas>
            </div>
        </div>

        <div class="dashboard-grid" style="margin-top: 1.2rem;">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">show_chart</span> Evolução Mensal
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('evolucao')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-mensal" width="600" height="250"></canvas>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span> Demandas Dia da Semana
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('dia-semana')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-dia-semana" width="400" height="250" style="display:block;margin:0 auto;"></canvas>
            </div>
        </div>

        <div class="dashboard-grid" style="margin-top: 1.2rem;">
            <div class="dashboard-card dashboard-card-fullrow">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">trending_up</span> Evolução Diária — Últimos 30 Dias
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('diarios-30d')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-diarios-30d" width="900" height="200"></canvas>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">group</span> Top 5 Solicitantes
                    </div>
                </h3>
                <div class="dashboard-list">`;

    (dados.top_solicitantes || []).forEach((item, index) => {
        const trofeu = index === 0 ? ' <span class="material-symbols-outlined item-trofeu" aria-hidden="true">emoji_events</span>' : '';
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${sanitizeHTML(item.nome)}${trofeu}</span><span>${item.total}</span></div>`;
    });

    html += `</div></div><div class="dashboard-card"><h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">category</span> Top 5 Subcategorias
                    </div>
                </h3><div class="dashboard-list">`;

    (dados.top_categorias || []).forEach((item, index) => {
        const trofeu = index === 0 ? ' <span class="material-symbols-outlined item-trofeu" aria-hidden="true">emoji_events</span>' : '';
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${sanitizeHTML(item.nome || 'Sem subcategoria')}${trofeu}</span><span>${item.total}</span></div>`;
    });

    html += `</div></div><div class="dashboard-card"><h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">folder_open</span> Demandas por Categoria
                    </div>
                </h3><div class="dashboard-list">`;

    (dados.ticketsPorMacrocategoria || []).forEach((item, index) => {
        const trofeu = index === 0 ? ' <span class="material-symbols-outlined item-trofeu" aria-hidden="true">emoji_events</span>' : '';
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${sanitizeHTML(item.nome || 'Sem categoria')}${trofeu}</span><span>${item.quantidade}</span></div>`;
    });

    html += `</div></div></div>`;

    content.innerHTML = html;

    setTimeout(() => {
        criarGraficoStatus(dados);
        criarGraficoPrioridade(dados);
        criarGraficoMensal(dados);
        criarGraficoAgente(dados);
        criarGraficoResolucao(dados);
        criarGraficoDiaSemana(dados);
        criarGraficoStatusAgente(dados);
        criarGraficoFunil(dados);
        criarGraficoDiarios30d(dados);
        criarGraficoStatusMensal(dados);
        criarGraficoHeatmap(dados);

        // Animar itens do dashboard quando visíveis
        setTimeout(() => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const delay = entry.target.getAttribute('data-delay') || 0;
                        setTimeout(() => {
                            entry.target.style.animation = 'slideInLeft 0.5s ease forwards';
                        }, delay * 1000);
                        observer.unobserve(entry.target);
                    }
                });
            });

            document.querySelectorAll('.dashboard-item-animate').forEach(item => {
                observer.observe(item);
            });

            // Animar contadores
            document.querySelectorAll('.count-up').forEach(counter => {
                const target = parseInt(counter.getAttribute('data-target'));
                const duration = 2000;
                const increment = target / (duration / 16);
                let current = 0;

                const updateCounter = () => {
                    current += increment;
                    if (current < target) {
                        counter.textContent = Math.floor(current);
                        requestAnimationFrame(updateCounter);
                    } else {
                        counter.textContent = target;
                    }
                };
                updateCounter();
            });
        }, 200);
    }, 100);
}

// WebSocket removido para PHP
// const socket = io();

// Socket listeners removidos para PHP
// socket.on('connect', function() { ... });
// socket.on('novoTicket', function(data) { ... });
// socket.on('novoComentario', function(data) { ... });

// Variáveis para ordenação e estado
let ticketsAtuais = [];
let ticketsFechadosAtuais = [];
let paginaAtualFechados = 1;
let porPaginaFechados = parseInt(localStorage.getItem('cd_ticketsFechadosPorPagina') || '50');
let ticketsFechadosFiltradosPorBusca = [];

// Restaurar ordenação salva ou usar default
const _ordemSalva = JSON.parse(localStorage.getItem('cd_ticketsOrdem') || 'null');
const _ordemFechadosSalva = JSON.parse(localStorage.getItem('cd_ticketsFechadosOrdem') || 'null');

let ordemAtual = _ordemSalva || { campo: 'id', direcao: 'desc' };
let ordemFechados = _ordemFechadosSalva || { campo: 'id', direcao: 'desc' };

// Função para ordenar tabela
function ordenarTabela(campo) {
    // Alternar direção se for o mesmo campo
    if (ordemAtual.campo === campo) {
        ordemAtual.direcao = ordemAtual.direcao === 'asc' ? 'desc' : 'asc';
    } else {
        ordemAtual.campo = campo;
        ordemAtual.direcao = 'asc';
    }

    // Persistir no localStorage
    localStorage.setItem('cd_ticketsOrdem', JSON.stringify(ordemAtual));

    // Ordenar tickets
    const ticketsOrdenados = [...ticketsAtuais].sort((a, b) => {
        let valorA = a[campo] || '';
        let valorB = b[campo] || '';

        // Tratamento especial para datas
        if (campo === 'criado_em') {
            valorA = new Date(valorA);
            valorB = new Date(valorB);
        }

        // Tratamento especial para números
        if (campo === 'id') {
            valorA = parseInt(valorA);
            valorB = parseInt(valorB);
        }

        // Comparar valores
        if (valorA < valorB) return ordemAtual.direcao === 'asc' ? -1 : 1;
        if (valorA > valorB) return ordemAtual.direcao === 'asc' ? 1 : -1;
        return 0;
    });

    // Atualizar setas nos cabeçalhos
    document.querySelectorAll('.sort-arrows').forEach(arrow => {
        arrow.textContent = '↕'; // Seta dupla
    });

    // Mostrar seta da coluna ativa
    const colunas = {
        'id': 0,
        'criado_em': 1,
        'titulo': 2,
        'usuario_nome': 3,
        'agente_nome': 4,
        'categoria_nome': 5,
        'status': 6,
        'prioridade': 7
    };

    const indiceColuna = colunas[campo];
    if (indiceColuna !== undefined) {
        const seta = document.querySelectorAll('.sort-arrows')[indiceColuna];
        if (seta) {
            seta.textContent = ordemAtual.direcao === 'asc' ? '↑' : '↓';
        }
    }

    // Armazenar tickets ordenados e mostrar
    ticketsAtuais = ticketsOrdenados;
    mostrarTicketsNaTela(ticketsOrdenados);
}

// Função para ordenar tabela de fechados
function ordenarTabelaFechados(campo) {
    // Alternar direção se for o mesmo campo
    if (ordemFechados.campo === campo) {
        ordemFechados.direcao = ordemFechados.direcao === 'asc' ? 'desc' : 'asc';
    } else {
        ordemFechados.campo = campo;
        ordemFechados.direcao = 'asc';
    }

    // Persistir no localStorage
    localStorage.setItem('cd_ticketsFechadosOrdem', JSON.stringify(ordemFechados));

    // Ordenar tickets fechados
    const ticketsOrdenados = [...ticketsFechadosAtuais].sort((a, b) => {
        let valorA = a[campo] || '';
        let valorB = b[campo] || '';

        // Tratamento especial para datas
        if (campo === 'criado_em' || campo === 'fechado_em') {
            valorA = new Date(valorA);
            valorB = new Date(valorB);
        }

        // Tratamento especial para números
        if (campo === 'id') {
            valorA = parseInt(valorA);
            valorB = parseInt(valorB);
        }

        // Comparar valores
        if (valorA < valorB) return ordemFechados.direcao === 'asc' ? -1 : 1;
        if (valorA > valorB) return ordemFechados.direcao === 'asc' ? 1 : -1;
        return 0;
    });

    // Atualizar base e re-aplicar os filtros e busca
    ticketsFechadosAtuais = ticketsOrdenados;
    aplicarFiltroMeusTicketsFechados();
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

// Função para mostrar relatórios
function mostrarRelatorios() {
    mostrarSecao('relatorios');
    salvarSecaoAtiva('relatorios');
    carregarFiltrosRelatorio();
}

// Carregar opções dos filtros
function carregarFiltrosRelatorio() {
    // Carregar categorias
    const xhr1 = new XMLHttpRequest();
    xhr1.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'categorias.php'), true);
    setSecurityHeaders(xhr1);
    xhr1.onload = function () {
        if (xhr1.status === 200) {
            const categorias = JSON.parse(xhr1.responseText);
            const select = document.getElementById('filtro-categoria');
            categorias.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.id;
                option.textContent = cat.nome;
                select.appendChild(option);
            });
        }
    };
    xhr1.send();

    // Carregar agentes
    const xhr2 = new XMLHttpRequest();
    xhr2.open('GET', window.APP_CONFIG.apiBase + 'agentes.php', true);
    setSecurityHeaders(xhr2);
    xhr2.onload = function () {
        if (xhr2.status === 200) {
            const agentes = JSON.parse(xhr2.responseText);
            const select = document.getElementById('filtro-agente');
            agentes.forEach(agente => {
                const option = document.createElement('option');
                option.value = agente.id;
                option.textContent = agente.nome;
                select.appendChild(option);
            });
        }
    };
    xhr2.send();
}

// Controlar exibição de datas personalizadas
document.addEventListener('DOMContentLoaded', function () {
    const filtroPeriodo = document.getElementById('filtro-periodo');
    const datasPersonalizadas = document.getElementById('datas-personalizadas');

    if (filtroPeriodo) {
        filtroPeriodo.addEventListener('change', function () {
            if (this.value === 'personalizado') {
                datasPersonalizadas.style.display = 'block';
            } else {
                datasPersonalizadas.style.display = 'none';
            }
        });
    }
});

// Gerar relatório
function gerarRelatorio() {
    const periodo = document.getElementById('filtro-periodo').value;
    const status = document.getElementById('filtro-status').value;
    const prioridade = document.getElementById('filtro-prioridade').value;
    const categoria = document.getElementById('filtro-categoria').value;
    const agente = document.getElementById('filtro-agente').value;

    let dataInicio, dataFim;

    // Calcular datas baseado no período
    const hoje = new Date();
    switch (periodo) {
        case 'mes-atual':
            dataInicio = new Date(hoje.getFullYear(), hoje.getMonth(), 1).toISOString().split('T')[0];
            dataFim = hoje.toISOString().split('T')[0];
            break;
        case 'mes-anterior':
            const mesAnterior = new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1);
            dataInicio = mesAnterior.toISOString().split('T')[0];
            dataFim = new Date(hoje.getFullYear(), hoje.getMonth(), 0).toISOString().split('T')[0];
            break;
        case 'trimestre':
            const trimestre = Math.floor(hoje.getMonth() / 3) * 3;
            dataInicio = new Date(hoje.getFullYear(), trimestre, 1).toISOString().split('T')[0];
            dataFim = hoje.toISOString().split('T')[0];
            break;
        case 'ano':
            dataInicio = new Date(hoje.getFullYear(), 0, 1).toISOString().split('T')[0];
            dataFim = hoje.toISOString().split('T')[0];
            break;
        case 'personalizado':
            dataInicio = document.getElementById('data-inicio').value;
            dataFim = document.getElementById('data-fim').value;
            break;
    }

    const params = new URLSearchParams({
        dataInicio,
        dataFim,
        status,
        prioridade,
        categoria,
        agente
    });

    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(`${window.APP_CONFIG.apiBase}relatorios.php?${params}`), true);
    setSecurityHeaders(xhr);
    xhr.onload = function () {
        if (xhr.status === 200) {
            const dados = JSON.parse(xhr.responseText);
            exibirRelatorio(dados);
        } else {
            document.getElementById('relatorio-resultado').innerHTML = '<p>Erro ao gerar relatório.</p>';
        }
    };
    xhr.send();
}

// Exibir relatório na tela
function exibirRelatorio(dados) {
    dadosRelatorioAtual = dados;
    const container = document.getElementById('relatorio-resultado');

    function formatarTempo(segundos) {
        if (!segundos) return 'N/A';
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        return `${horas}h ${minutos}m`;
    }

    let html = `
        <div class="relatorio-resumo">
            <div class="resumo-card">
                <h3>Total de Demandas</h3>
                <div class="resumo-numero">${dados.estatisticas.totalTickets}</div>
            </div>
            <div class="resumo-card">
                <h3>Demandas Encerradas</h3>
                <div class="resumo-numero">${dados.estatisticas.ticketsEncerrados || 0}</div>
            </div>
            <div class="resumo-card">
                <h3>Tempo Médio Total</h3>
                <div class="resumo-numero">${formatarTempo(dados.estatisticas.tempoMedioSegundos)}</div>
            </div>
            <div class="resumo-card">
                <h3>Tempo Médio 1º Atendimento</h3>
                <div class="resumo-numero">${formatarTempo(dados.estatisticas.tempoMedioPrimeiroAtendimentoSegundos)}</div>
            </div>
        </div>
        
        <div class="relatorio-tabela">
            <table class="tickets-tabela">
                <thead>
                    <tr>
                        <th>Nº Demanda</th>
                        <th>Abertura</th>
                        <th>Assunto</th>
                        <th>Solicitante</th>
                        <th>Responsável</th>
                        <th>Subcategoria</th>
                        <th>Status</th>
                        <th>Prioridade</th>
                        <th>Fechamento</th>
                    </tr>
                </thead>
                <tbody>
    `;

    dados.tickets.forEach(ticket => {
        const dataAbertura = new Date(ticket.criado_em);
        const dataAberturaFormatada = dataAbertura.toLocaleDateString('pt-BR');
        const horaAberturaFormatada = dataAbertura.toLocaleTimeString('pt-BR');

        let dataFechamento = '-';
        if (ticket.fechado_em) {
            const dataFech = new Date(ticket.fechado_em);
            dataFechamento = dataFech.toLocaleDateString('pt-BR') + '<br>' + dataFech.toLocaleTimeString('pt-BR');
        }

        html += `
            <tr>
                <td>
                    #${String(ticket.id).padStart(5, '0')}
                    ${ticket.tem_anexos > 0 ? '<img src="images/clipe-de-anexo.png" class="icone-anexo" title="Demanda com anexos" alt="Anexo">' : ''}
                </td>
                <td>${dataAberturaFormatada}<br><small style="color: #666;">${horaAberturaFormatada}</small></td>
                <td>${sanitizeHTML(ticket.titulo)}</td>
                <td>${sanitizeHTML(ticket.usuario_nome || 'N/A')}</td>
                <td>${sanitizeHTML(ticket.agente_nome || 'Não atribuído')}</td>
                <td>${sanitizeHTML(ticket.categoria_nome || 'Sem subcategoria')}</td>
                <td>${ticket.status.charAt(0).toUpperCase() + ticket.status.slice(1)}</td>
                <td>${ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1)}</td>
                <td><small style=""color: #666;"">${dataFechamento}</small></td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    container.innerHTML = html;
}

// Exportar relatório
let dadosRelatorioAtual = null;

function exportarRelatorio(formato) {
    if (!dadosRelatorioAtual) {
        mostrarAlert('Erro', 'Gere um relatório antes de exportar.');
        return;
    }

    if (formato === 'excel') {
        exportarExcel();
    } else if (formato === 'pdf') {
        exportarPDF();
    }
}

function exportarExcel() {
    const dados = dadosRelatorioAtual;
    let csv = '\uFEFF'; // BOM UTF-8
    csv += 'Nº Demanda;Data Abertura;Hora Abertura;Assunto;Solicitante;Responsável;Subcategoria;Status;Prioridade;Data Fechamento;Hora Fechamento\n';

    dados.tickets.forEach(ticket => {
        const dataAbertura = new Date(ticket.criado_em);
        const dataAberturaFormatada = dataAbertura.toLocaleDateString('pt-BR');
        const horaAberturaFormatada = dataAbertura.toLocaleTimeString('pt-BR');

        let dataFechamento = '-';
        let horaFechamento = '-';
        if (ticket.fechado_em) {
            const dataFech = new Date(ticket.fechado_em);
            dataFechamento = dataFech.toLocaleDateString('pt-BR');
            horaFechamento = dataFech.toLocaleTimeString('pt-BR');
        }

        const numero = String(ticket.id).padStart(5, '0');
        const titulo = ticket.titulo.replace(/;/g, ',');
        const solicitante = (ticket.usuario_nome || 'N/A').replace(/;/g, ',');
        const responsavel = (ticket.agente_nome || 'Não atribuído').replace(/;/g, ',');
        const categoria = (ticket.categoria_nome || 'Sem subcategoria').replace(/;/g, ',');
        const status = ticket.status.charAt(0).toUpperCase() + ticket.status.slice(1);
        const prioridade = ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1);

        csv += `#${numero};${dataAberturaFormatada};${horaAberturaFormatada};${titulo};${solicitante};${responsavel};${categoria};${status};${prioridade};${dataFechamento};${horaFechamento}\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `relatorio_tickets_${new Date().toISOString().split('T')[0]}.csv`;
    link.click();

    mostrarToast('Sucesso', 'Relatório exportado para Excel!', 'success');
}

function exportarPDF() {
    const dados = dadosRelatorioAtual;

    const printWindow = window.open('', '', 'width=800,height=600');
    printWindow.document.write(`
        <html>
        <head>
            <title>Relatório de Demandas</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #f79226; text-align: center; }
                .resumo { display: flex; justify-content: space-around; margin: 20px 0; }
                .resumo-card { text-align: center; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
                .resumo-card h3 { margin: 0; font-size: 14px; color: #666; }
                .resumo-numero { font-size: 24px; font-weight: bold; color: #f79226; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
                th { background-color: #f79226; color: white; }
                tr:nth-child(even) { background-color: #f9f9f9; }
                @media print { body { margin: 0; } }
            </style>
        </head>
        <body>
            <h1>Relatório de Demandas - Conecta CRPRS</h1>
            <p style="text-align: center; color: #666;">Gerado em: ${new Date().toLocaleString('pt-BR')}</p>
            
            <div class="resumo">
                <div class="resumo-card">
                    <h3>Total de Demandas</h3>
                    <div class="resumo-numero">${dados.estatisticas.totalTickets}</div>
                </div>
                <div class="resumo-card">
                    <h3>Demandas Encerradas</h3>
                    <div class="resumo-numero">${dados.estatisticas.ticketsEncerrados || 0}</div>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Nº</th>
                        <th>Abertura</th>
                        <th>Assunto</th>
                        <th>Solicitante</th>
                        <th>Responsável</th>
                        <th>Subcategoria</th>
                        <th>Status</th>
                        <th>Prioridade</th>
                        <th>Fechamento</th>
                    </tr>
                </thead>
                <tbody>
    `);

    dados.tickets.forEach(ticket => {
        const dataAbertura = new Date(ticket.criado_em);
        const dataAberturaFormatada = dataAbertura.toLocaleDateString('pt-BR');
        const horaAberturaFormatada = dataAbertura.toLocaleTimeString('pt-BR');

        let dataFechamento = '-';
        if (ticket.fechado_em) {
            const dataFech = new Date(ticket.fechado_em);
            dataFechamento = dataFech.toLocaleDateString('pt-BR') + '<br>' + dataFech.toLocaleTimeString('pt-BR');
        }

        const numero = String(ticket.id).padStart(5, '0');
        printWindow.document.write(`
            <tr>
                <td>#${numero}</td>
                <td>${dataAberturaFormatada}<br><small>${horaAberturaFormatada}</small></td>
                <td>${sanitizeHTML(ticket.titulo)}</td>
                <td>${sanitizeHTML(ticket.usuario_nome || 'N/A')}</td>
                <td>${sanitizeHTML(ticket.agente_nome || 'Não atribuído')}</td>
                <td>${sanitizeHTML(ticket.categoria_nome || 'Sem subcategoria')}</td>
                <td>${ticket.status.charAt(0).toUpperCase() + ticket.status.slice(1)}</td>
                <td>${ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1)}</td>
                <td><small style=""color: #666;"">${dataFechamento}</small></td>
            </tr>
        `);
    });

    printWindow.document.write(`
                </tbody>
            </table>
        </body>
        </html>
    `);

    printWindow.document.close();
    setTimeout(() => {
        printWindow.print();
    }, 250);

    mostrarToast('Sucesso', 'Relatório preparado para impressão/PDF!', 'success');
}

// Verificar complexidade da senha
function verificarComplexidade() {
    const senha = document.getElementById('nova-senha').value;
    const btnAlterar = document.getElementById('btn-alterar');

    if (!senha || !btnAlterar) return;

    const checks = {
        tamanho: senha.length >= 10,
        maiuscula: /[A-Z]/.test(senha),
        minuscula: /[a-z]/.test(senha),
        numero: /[0-9]/.test(senha),
        especial: /[!@#$%^&*(),.?":{}|<>]/.test(senha)
    };

    // Atualizar indicadores visuais
    document.getElementById('check-tamanho').innerHTML = checks.tamanho ? '✅ Mínimo 10 caracteres' : '❌ Mínimo 10 caracteres';
    document.getElementById('check-maiuscula').innerHTML = checks.maiuscula ? '✅ 1 letra maiúscula' : '❌ 1 letra maiúscula';
    document.getElementById('check-minuscula').innerHTML = checks.minuscula ? '✅ 1 letra minúscula' : '❌ 1 letra minúscula';
    document.getElementById('check-numero').innerHTML = checks.numero ? '✅ 1 número' : '❌ 1 número';
    document.getElementById('check-especial').innerHTML = checks.especial ? '✅ 1 caractere especial' : '❌ 1 caractere especial';

    // Habilitar botão se todos os critérios forem atendidos
    const todasValidas = Object.values(checks).every(check => check);
    btnAlterar.disabled = !todasValidas;
}

// Alterar senha
function alterarSenha(event) {
    event.preventDefault();

    const senhaAtual = document.getElementById('senha-atual').value;
    const novaSenha = document.getElementById('nova-senha').value;
    const confirmarSenha = document.getElementById('confirmar-senha').value;

    if (novaSenha !== confirmarSenha) {
        mostrarAlert('Erro', 'As senhas não coincidem.');
        return;
    }

    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'alterar-senha.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const resposta = JSON.parse(xhr.responseText);
            if (resposta.token) {
                localStorage.setItem('cd_token', resposta.token);
                localStorage.setItem('cd_csrf_token', resposta.csrf_token || '');
            }
            if (usuario && usuario.trocar_senha) {
                usuario.trocar_senha = false;
                localStorage.setItem('cd_usuario', JSON.stringify(usuario));
                mostrarAlert('Senha alterada', 'Senha alterada com sucesso! O sistema será carregado.', () => window.location.reload());
                return;
            }
            mostrarToast('Sucesso', 'Senha alterada com sucesso! As outras sessões abertas foram encerradas.', 'success');
            document.getElementById('form-alterar-senha').reset();
            document.querySelectorAll('.check-item').forEach(item => {
                item.innerHTML = item.innerHTML.replace('✅', '❌');
            });
            document.getElementById('btn-alterar').disabled = true;
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao alterar senha');
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send(JSON.stringify({
        senha_atual: senhaAtual,
        nova_senha: novaSenha
    }));
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

// Merge para tickets fechados
function toggleModoMergeFechados() {
    modoMergeFechadosAtivo = !modoMergeFechadosAtivo;

    const btn = document.getElementById('btn-merge-fechados');
    const info = document.getElementById('merge-info-fechados');

    if (modoMergeFechadosAtivo) {
        btn.textContent = '❌ Cancelar Merge';
        btn.classList.add('active');
        info.style.display = 'block';
        mostrarToast('Modo Merge', 'Clique nas demandas fechadas para selecioná-las', 'info');
        mostrarTicketsFechadosNaTela(ticketsFechadosAtuais);
    } else {
        cancelarMergeFechados();
    }
}

function cancelarMergeFechados() {
    modoMergeFechadosAtivo = false;
    ticketsFechadosSelecionados = [];

    const btn = document.getElementById('btn-merge-fechados');
    const info = document.getElementById('merge-info-fechados');
    const btnExecute = document.getElementById('btn-merge-execute-fechados');

    btn.textContent = '🔗 Mesclar Demandas';
    btn.classList.remove('active');
    info.style.display = 'none';
    btnExecute.disabled = true;

    document.querySelectorAll('#lista-fechados .ticket-selecionado, #lista-fechados .ticket-principal').forEach(row => {
        row.classList.remove('ticket-selecionado', 'ticket-principal');
    });
}

function selecionarTicketFechadoParaMerge(ticketId, elemento) {
    if (!modoMergeFechadosAtivo) return;

    const index = ticketsFechadosSelecionados.indexOf(ticketId);

    if (index > -1) {
        ticketsFechadosSelecionados.splice(index, 1);
        elemento.classList.remove('ticket-selecionado', 'ticket-principal');
    } else {
        ticketsFechadosSelecionados.push(ticketId);
        if (ticketsFechadosSelecionados.length === 1) {
            elemento.classList.add('ticket-principal');
        } else {
            elemento.classList.add('ticket-selecionado');
        }
    }

    const btnExecute = document.getElementById('btn-merge-execute-fechados');
    btnExecute.disabled = ticketsFechadosSelecionados.length < 2;

    atualizarClassesMergeFechados();
}

function atualizarClassesMergeFechados() {
    document.querySelectorAll('#lista-fechados .ticket-selecionado, #lista-fechados .ticket-principal').forEach(row => {
        row.classList.remove('ticket-selecionado', 'ticket-principal');
    });

    ticketsFechadosSelecionados.forEach((ticketId, index) => {
        const row = document.querySelector(`#lista-fechados tr[data-ticket-id="${ticketId}"]`);
        if (row) {
            if (index === 0) {
                row.classList.add('ticket-principal');
            } else {
                row.classList.add('ticket-selecionado');
            }
        }
    });
}

function executarMergeFechados() {
    if (ticketsFechadosSelecionados.length < 2) {
        mostrarAlert('Erro', 'Selecione pelo menos 2 demandas para mesclar.');
        return;
    }

    const ticketPrincipal = ticketsFechadosSelecionados[0];
    const ticketsSecundarios = ticketsFechadosSelecionados.slice(1);

    mostrarConfirm(
        'Confirmar Merge',
        `Mesclar ${ticketsSecundarios.length} demanda(s) fechada(s) na demanda principal #${String(ticketPrincipal).padStart(5, '0')}?\n\n⚠️ ATENÇÃO: Esta ação não pode ser desfeita!`,
        function (confirmado) {
            if (!confirmado) return;

            mostrarLoader();

            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.APP_CONFIG.apiBase + 'merge.php', true);
            xhr.setRequestHeader('Content-Type', 'application/json');

            xhr.onload = function () {
                ocultarLoader();
                if (xhr.status === 200) {
                    let response;
                    try {
                        response = JSON.parse(xhr.responseText);
                        mostrarToast("Sucesso", `${response.tickets_mesclados} demanda(s) fechada(s) mesclada(s) com sucesso!`, "success");
                        cancelarMergeFechados();
                        carregarTicketsFechados();
                    } catch (e) {
                        mostrarAlert('Erro', 'Resposta inválida do servidor');
                    }
                } else {
                    mostrarAlert('Erro', 'Erro ao mesclar demandas fechadas');
                }
            };

            xhr.send(JSON.stringify({
                ticket_principal: ticketPrincipal,
                tickets_secundarios: ticketsSecundarios
            }));
        }
    );
}

// Busca Avançada
// Busca com debounce
let _buscaDebounceTimer = null;
function configurarBuscaAvancada() {
    const inputBusca = document.getElementById('busca-tickets');
    if (!inputBusca) return;
    inputBusca.addEventListener('input', function () {
        clearTimeout(_buscaDebounceTimer);
        _buscaDebounceTimer = setTimeout(() => {
            paginaAtual = 1;
            filtrarEMostrarTickets(ticketsAtuais);
        }, 300);
    });
}

function filtrarTickets(termo) {
    paginaAtual = 1;
    filtrarEMostrarTickets(ticketsAtuais);
}

function limparBusca() {
    document.getElementById('busca-tickets').value = '';
    paginaAtual = 1;
    filtrarEMostrarTickets(ticketsAtuais);
}



function configurarBuscaFechados() {
    const inputBusca = document.getElementById('busca-fechados');
    if (!inputBusca) return;

    inputBusca.addEventListener('input', function () {
        const termo = this.value.toLowerCase().trim();
        filtrarTicketsFechados(termo);
    });
}

function filtrarTicketsFechados(termo) {
    if (!termo) {
        mostrarTicketsFechadosNaTela(ticketsFechadosAtuais);
        return;
    }

    const ticketsFiltrados = ticketsFechadosAtuais.filter(ticket =>
        ticket.titulo.toLowerCase().includes(termo) ||
        ticket.descricao.toLowerCase().includes(termo) ||
        ticket.usuario_nome.toLowerCase().includes(termo)
    );

    mostrarTicketsFechadosNaTela(ticketsFiltrados);
}

function limparBuscaFechados() {
    document.getElementById('busca-fechados').value = '';
    mostrarTicketsFechadosNaTela(ticketsFechadosAtuais);
}



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

// Merge de Tickets
let modoMergeAtivo = false;
let ticketsSelecionados = [];
let modoMergeFechadosAtivo = false;
let ticketsFechadosSelecionados = [];

function toggleModoMerge() {
    modoMergeAtivo = !modoMergeAtivo;

    const btn = document.getElementById('btn-merge');
    const info = document.getElementById('merge-info');

    if (modoMergeAtivo) {
        btn.textContent = '❌ Cancelar Merge';
        btn.classList.add('active');
        info.style.display = 'block';
        mostrarToast('Modo Merge', 'Clique nas demandas para selecioná-las', 'info');
        // Recarregar tabela para adicionar listeners
        mostrarTicketsNaTela(ticketsAtuais);
    } else {
        cancelarMerge();
    }
}

function cancelarMerge() {
    modoMergeAtivo = false;
    ticketsSelecionados = [];

    const btn = document.getElementById('btn-merge');
    const info = document.getElementById('merge-info');
    const btnExecute = document.getElementById('btn-merge-execute');

    btn.textContent = '🔗 Mesclar Demandas';
    btn.classList.remove('active');
    info.style.display = 'none';
    btnExecute.disabled = true;

    // Remover seleções visuais
    document.querySelectorAll('.ticket-selecionado, .ticket-principal').forEach(row => {
        row.classList.remove('ticket-selecionado', 'ticket-principal');
    });
}

function selecionarTicketParaMerge(ticketId, elemento) {
    if (!modoMergeAtivo) return;

    const index = ticketsSelecionados.indexOf(ticketId);

    if (index > -1) {
        // Desselecionar
        ticketsSelecionados.splice(index, 1);
        elemento.classList.remove('ticket-selecionado', 'ticket-principal');
    } else {
        // Selecionar
        ticketsSelecionados.push(ticketId);
        if (ticketsSelecionados.length === 1) {
            elemento.classList.add('ticket-principal');
        } else {
            elemento.classList.add('ticket-selecionado');
        }
    }

    // Atualizar botão executar
    const btnExecute = document.getElementById('btn-merge-execute');
    btnExecute.disabled = ticketsSelecionados.length < 2;

    // Atualizar classes visuais
    atualizarClassesMerge();
}

function atualizarClassesMerge() {
    document.querySelectorAll('.ticket-selecionado, .ticket-principal').forEach(row => {
        row.classList.remove('ticket-selecionado', 'ticket-principal');
    });

    ticketsSelecionados.forEach((ticketId, index) => {
        const row = document.querySelector(`tr[data-ticket-id="${ticketId}"]`);
        if (row) {
            if (index === 0) {
                row.classList.add('ticket-principal');
            } else {
                row.classList.add('ticket-selecionado');
            }
        }
    });
}

function executarMerge() {
    if (ticketsSelecionados.length < 2) {
        mostrarAlert('Erro', 'Selecione pelo menos 2 demandas para mesclar.');
        return;
    }

    const ticketPrincipal = ticketsSelecionados[0];
    const ticketsSecundarios = ticketsSelecionados.slice(1);

    mostrarConfirm(
        'Confirmar Merge',
        `Mesclar ${ticketsSecundarios.length} demanda(s) na demanda principal #${String(ticketPrincipal).padStart(5, '0')}?\n\nAs demandas secundárias serão encerradas e seus comentários/anexos serão transferidos.\n\n⚠️ ATENÇÃO: Esta ação não pode ser desfeita!`,
        function (confirmado) {
            if (!confirmado) return;

            // Executar merge real
            mostrarLoader();

            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.APP_CONFIG.apiBase + 'merge.php', true);
            xhr.setRequestHeader('Content-Type', 'application/json');

            xhr.onload = function () {
                ocultarLoader();
                if (xhr.status === 200) {
                    let response;
                    try {
                        response = JSON.parse(xhr.responseText);
                    } catch (e) {
                        console.error('Erro ao parsear JSON:', xhr.responseText);
                        mostrarAlert('Erro', 'Resposta inválida do servidor');
                        return;
                    }
                    mostrarToast('Sucesso', `${response.tickets_mesclados} demanda(s) mesclada(s) com sucesso!`, 'success');

                    // Animar remoção dos tickets fechados
                    ticketsSecundarios.forEach((ticketId, index) => {
                        const row = document.querySelector(`tr[data-ticket-id="${ticketId}"]`);
                        if (row) {
                            setTimeout(() => {
                                row.classList.add('ticket-mesclado');
                                // Remover da lista local após animação
                                setTimeout(() => {
                                    ticketsAtuais = ticketsAtuais.filter(t => t.id !== ticketId);
                                    if (index === ticketsSecundarios.length - 1) {
                                        // Último ticket - atualizar tabela
                                        mostrarTicketsNaTela(ticketsAtuais);
                                    }
                                }, 600);
                            }, index * 150);
                        }
                    });

                    cancelarMerge();
                } else {
                    let error;
                    try {
                        error = JSON.parse(xhr.responseText);
                        mostrarAlert('Erro', error.error || 'Erro ao mesclar demandas');
                    } catch (e) {
                        mostrarAlert('Erro', 'Erro ao mesclar demandas: ' + xhr.responseText);
                    }
                }
            };

            xhr.onerror = function () {
                ocultarLoader();
                mostrarAlert('Erro', 'Erro de conexão ao mesclar demandas');
            };

            xhr.send(JSON.stringify({
                ticket_principal: ticketPrincipal,
                tickets_secundarios: ticketsSecundarios
            }));
        }
    );
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

                    <!-- Tabela de tickets -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>🎫</span> Tabelas de Demandas — Melhorias de UX e Unificação
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li><strong>Unificação da Tabela de Demandas Encerradas</strong>: Paridade de design e performance com "Demandas Abertas" — paginação dedicada, busca com debounce (300ms), skeleton loading, ordenação persistente independente.</li>
                            <li><strong>Módulo Kanban</strong>: Visão Kanban com toggle Tabela/Kanban, drag &amp; drop assíncrono e colunas inteligentes (Novo, Em Atendimento, Aguardando, Resp. Usuário).</li>
                            <li><strong>Responsividade Híbrida</strong>: Tabela se transforma em cards em telas &lt; 768px usando data-labels CSS e pseudo-elementos <code>:before</code>.</li>
                            <li><strong>Editor WYSIWYG (Quill.js)</strong>: Formatação rica nas demandas e comentários — negrito, listas, imagens. Migração para <code>LONGTEXT</code> no banco.</li>
                            <li><strong>Indicador de ordenação ativa</strong>: Coluna ordenada exibe ↑ ou ↓ em laranja; demais exibem ↕ cinza. Persistente via <code>localStorage</code>.</li>
                            <li><strong>Filtros rápidos por status</strong>: Pills com contadores em tempo real — Todos, Novo, Em atendimento, Aguardando, Nova resposta, Urgente.</li>
                            <li><strong>Badge de SLA inline</strong>: Tempo restante (ou vencimento) — 🟢 OK, 🟡 Alerta, 🔴 Crítico, 🩸 Vencido, ⬜ Pausado. Respeita <code>prefers-reduced-motion</code>.</li>
                            <li><strong>Paginação</strong>: 25/50/100 itens por página com numeração ±2. Padrão alterado para 50 itens. Quantidade persistente.</li>
                            <li><strong>Ícones Material Symbols</strong>: Substituem PNGs antigos — escaláveis, compatíveis com dark mode e acessíveis com <code>aria-label</code>.</li>
                        </ul>
                    </div>

                    <!-- Macros -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>⚡</span> Respostas Rápidas (Macros)
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Templates de texto pré-formatados com WYSIWYG (Quill.js) para acelerar o atendimento.</li>
                            <li>Modal dedicado com listagem dinâmica, edição e exclusão. Acessível pelo dropdown do perfil.</li>
                            <li>Integração fluida no campo de comentários — macro injetada sem sobrescrever o texto existente.</li>
                        </ul>
                    </div>

                    <!-- Notificações SSE -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>🔔</span> Notificações em Tempo Real (SSE)
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Notificações atualizadas automaticamente a cada 30 segundos, sem recarregar a página.</li>
                            <li>Badge pulsante com contador de alertas não lidos sobre o ícone do sino.</li>
                            <li>Dropdown de feed com histórico; clicar na notificação abre a demanda correspondente e marca como lida.</li>
                            <li>Triggers em sistema: nova atribuição, nova demanda, novos comentários e lembretes.</li>
                        </ul>
                    </div>

                    <!-- Alpine.js -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>🏗️</span> Arquitetura Frontend — Componentização (Alpine.js)
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Migração incremental de <code>script.js</code> monolítico para componentes Alpine.js encapsulados sem build step.</li>
                            <li>Editor Quill.js extraído para <code>alpine-components.js</code> com inicialização atômica.</li>
                            <li>Micro-animações com física de mola (<code>cubic-bezier(0.175, 0.885, 0.32, 1.275)</code>); respeita <code>prefers-reduced-motion</code>.</li>
                        </ul>
                    </div>

                    <!-- Segurança -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>🛡️</span> Segurança e Compliance (LGPD)
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Mascaramento obrigatório de e-mails (<code>a***@gmail.com</code>) na visualização de demandas encerradas.</li>
                            <li>CSRF obrigatório em todos os Payloads de mutação via macros (<code>AuthMiddleware::verificarCSRF()</code>).</li>
                        </ul>
                    </div>

                    <!-- BI -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>📈</span> Business Intelligence — Dashboard FCR e Otimizações
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Widget de <strong>First Contact Resolution (FCR)</strong>: % de demandas resolvidas no primeiro contato.</li>
                            <li>Cache HTTP dinâmico no <code>dashboard.php</code> (60s) — reduz em ~90% as queries simultâneas de resumo.</li>
                            <li>Migration v8 com indexação agressiva em <code>tickets</code>, <code>comentarios</code>, <code>historico</code> e <code>notificacoes</code>.</li>
                        </ul>
                    </div>

                    <!-- Login e header -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>🔐</span> Login — Redesign Completo e Melhorias de Segurança
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Layout split-screen: painel de identidade CRPRS (esquerdo) + formulário moderno com Inter e Material Symbols.</li>
                            <li>Cloudflare Turnstile CAPTCHA validado no frontend antes do envio; 5 tentativas antes do bloqueio.</li>
                            <li>Tratamento diferenciado por código HTTP (400 captcha, 401/403 credenciais, erro de rede).</li>
                            <li>Suporte completo a <code>prefers-reduced-motion</code> e acessibilidade WCAG 2.2.</li>
                        </ul>
                    </div>

                    <!-- E-mail -->
                    <div style="margin-bottom: 0;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary, #004373); margin-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                            <span>📧</span> Sistema de E-mail e Demandas por E-mail
                        </div>
                        <ul style="margin: 0; padding-left: 1.4rem; font-size: 0.88rem; color: var(--text-secondary, #555); line-height: 1.8;">
                            <li>Estratégia IMAP alterada para <code>SINCE 3 days ago</code> — captura e-mails lidos em outros clientes.</li>
                            <li>Anti-duplicidade de demandas (10 min) e comentários (5 min) via e-mail e interface.</li>
                            <li>Blacklist de remetentes internos (<code>suporte@crprs.org.br</code>) para evitar loops.</li>
                            <li>Log completo de envios na tabela <code>email_logs</code> com filtros, paginação e estatísticas de taxa de sucesso.</li>
                            <li>Alerta visual pulsante 🔴 no botão Configurações quando há falhas de e-mail nas últimas 24h.</li>
                            <li>Notificação entre colegas: comentário em demanda de outro responsável dispara e-mail + muda status para "Nova Resposta".</li>
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

// Seção de Ajuda
function mostrarAjuda() {
    mostrarSecao('ajuda');
    // Cada item do submenu é responsável por chamar sua própria subsecção
}

function mostrarSLA() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_sla = document.getElementById('btn-sla'); if (_bbtn_sla) _bbtn_sla.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'sla');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        carregarHorariosDinamicos();
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>⏰ SLA - Acordo de Nível de Serviço</h3>
            <p>O SLA (Service Level Agreement) define os <strong>tempos máximos</strong> para resposta e resolução de demandas baseado na prioridade. Os prazos são contados em <strong>horas úteis</strong> (Segunda a Sexta, 8h às 17h).</p>
            
            <h4>📊 Como Funciona o SLA</h4>
            <p>O sistema monitora automaticamente dois tipos de SLA para cada demanda:</p>
            <ul>
                <li><strong>SLA de 1ª Resposta:</strong> Tempo máximo até o primeiro atendimento da equipe</li>
                <li><strong>SLA de Resolução:</strong> Tempo máximo até a resolução completa do problema</li>
            </ul>
            
            <h4>🚦 Indicadores Visuais</h4>
            <p>As demandas exibem ícones de alerta conforme o prazo se aproxima:</p>
            <ul>
                <li><strong>🟢 0-50% do prazo:</strong> Sem ícone - Tudo ok</li>
                <li><strong>🟡 50-79% do prazo:</strong> <img src="images/icone_atencao_amarelo.png" style="width: 16px; height: 16px; vertical-align: middle;"> Amarelo - Atenção, prazo se aproximando</li>
                <li><strong>🔴 80-100%+ do prazo:</strong> <img src="images/icone_atencao_vermelho.png" style="width: 16px; height: 16px; vertical-align: middle;"> Vermelho - Urgente ou vencido</li>
            </ul>
            
            <h4>📋 Tabela de Prazos por Prioridade</h4>
            <table class="sla-table">
                <thead>
                    <tr>
                        <th>Prioridade</th>
                        <th>1ª Resposta</th>
                        <th>Resolução</th>
                        <th>Exemplos</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="status prioridade urgente">Urgente</span></td>
                        <td><strong>2 horas úteis</strong></td>
                        <td><strong>4 horas úteis</strong></td>
                        <td>Sistema fora do ar, falha crítica que impede o trabalho</td>
                    </tr>
                    <tr>
                        <td><span class="status prioridade alta">Alta</span></td>
                        <td><strong>4 horas úteis</strong></td>
                        <td><strong>8 horas úteis</strong></td>
                        <td>Funcionalidade importante indisponível que afeta a produtividade</td>
                    </tr>
                    <tr>
                        <td><span class="status prioridade normal">Normal</span></td>
                        <td><strong>12 horas úteis</strong></td>
                        <td><strong>24 horas úteis</strong></td>
                        <td>Dúvidas, solicitações rotineiras</td>
                    </tr>
                    <tr>
                        <td><span class="status prioridade baixa">Baixa</span></td>
                        <td><strong>24 horas úteis</strong></td>
                        <td><strong>40 horas úteis</strong></td>
                        <td>Melhorias, sugestões não urgentes</td>
                    </tr>
                </tbody>
            </table>
            
            <h4>🕰️ Horário de Atendimento</h4>
            <div id="horarios-atendimento-info" style="background: #e3f2fd; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <p style="margin: 0; color: #1976d2;">⚙️ <strong>Configurável:</strong> Os horários de atendimento podem ser personalizados em <strong>Configurações → Horários e Feriados</strong></p>
            </div>
            <div id="horarios-dinamicos">Carregando horários...</div>
            
            <h4>⏸️ Pausa Automática do SLA</h4>
            <p>O SLA é <strong>pausado automaticamente</strong> quando a demanda está nos seguintes status:</p>
            <ul>
                <li>⏳ <strong>Aguardando:</strong> Em espera por motivo não especificado</li>
                <li>⏳ <strong>Aguardando resposta:</strong> Aguardando informações do solicitante</li>
                <li>🔄 <strong>Aguardando terceiros:</strong> Depende de fornecedor ou outro setor</li>
                <li>📋 <strong>Aguardando aprovação:</strong> Precisa de autorização do cliente ou gestor</li>
                <li>📅 <strong>Aguardando data:</strong> Aguardando data agendada para retomar o atendimento</li>
                <li>✅ <strong>Resolvido:</strong> Aguardando confirmação do cliente para encerrar</li>
            </ul>
            <p><em>Quando a demanda retorna ao atendimento, o SLA é retomado automaticamente, descontando o tempo pausado.</em></p>
            
            <h4>📊 Contabilização de Demandas</h4>
            <ul>
                <li>✅ <strong>Demandas Encerradas:</strong> Contabilizadas nas estatísticas e relatórios</li>
                <li>❌ <strong>Demandas Canceladas:</strong> NÃO são contabilizadas nas métricas de desempenho</li>
            </ul>
            <p><em>Demandas canceladas são aquelas abertas por engano ou cuja solicitação foi retirada pelo solicitante.</em></p>
            
            <h4>💡 Dicas para Cumprir o SLA</h4>
            <ul>
                <li>Atribua a prioridade correta ao criar a demanda</li>
                <li>Responda rapidamente às demandas urgentes e de alta prioridade</li>
                <li>Use os status de "aguardando" quando depender de terceiros para pausar o SLA</li>
                <li>Mantenha o solicitante informado sobre o andamento</li>
                <li>Documente as soluções para consultas futuras</li>
                <li>Cancele apenas demandas abertas por engano (não contam nas estatísticas)</li>
            </ul>
        </div>
    `;
    }, 10);
}

function carregarHorariosDinamicos() {
    const xhr1 = new XMLHttpRequest();
    xhr1.open('GET', window.APP_CONFIG.apiBase + 'horarios-sla.php', true);
    setSecurityHeaders(xhr1);

    xhr1.onload = function () {
        if (xhr1.status === 200) {
            const horarios = JSON.parse(xhr1.responseText);

            const xhr2 = new XMLHttpRequest();
            xhr2.open('GET', window.APP_CONFIG.apiBase + 'horarios-sla.php?feriados=1', true);
            setSecurityHeaders(xhr2);

            xhr2.onload = function () {
                if (xhr2.status === 200) {
                    const feriados = JSON.parse(xhr2.responseText);
                    mostrarHorariosDinamicos(horarios, feriados);
                }
            };

            xhr2.send();
        }
    };

    xhr1.send();
}

function mostrarHorariosDinamicos(horarios, feriados) {
    const container = document.getElementById('horarios-dinamicos');
    if (!container) return;

    const diasSemana = {
        1: 'Domingo',
        2: 'Segunda-feira',
        3: 'Terça-feira',
        4: 'Quarta-feira',
        5: 'Quinta-feira',
        6: 'Sexta-feira',
        7: 'Sábado'
    };

    let html = '<ul>';

    for (let dia = 1; dia <= 7; dia++) {
        const horario = horarios.find(h => h.dia_semana == dia);
        if (horario && horario.ativo == 1) {
            const inicio = horario.hora_inicio.substring(0, 5);
            const fim = horario.hora_fim.substring(0, 5);
            html += `<li><strong>${diasSemana[dia]}:</strong> ${inicio} às ${fim}</li>`;
        } else {
            html += `<li><strong>${diasSemana[dia]}:</strong> Sem atendimento</li>`;
        }
    }

    if (feriados.length > 0) {
        html += '<li style="margin-top: 0.5rem;"><strong>Feriados cadastrados:</strong> ' + feriados.length + ' feriado(s)</li>';
    }

    html += '</ul>';
    container.innerHTML = html;
}

function mostrarStatusGuia() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_status = document.getElementById('btn-status'); if (_bbtn_status) _bbtn_status.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'status');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>📊 Status das Demandas</h3>
            <p>Cada demanda passa por diferentes status durante seu ciclo de vida:</p>

            <div class="status-item">
                <span class="status-badge" style="background:#fff3e0;color:#e65100;">🆕 Novo</span>
                <div>
                    <strong>Demanda recém-criada</strong><br>
                    A demanda foi criada (via sistema ou e-mail), mas ainda não foi atribuída nem visualizada pela equipe.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#e3f2fd;color:#0277bd;">🔧 Em atendimento</span>
                <div>
                    <strong>Responsável trabalhando ativamente</strong><br>
                    A demanda está sendo analisada ou trabalhada pelo responsável.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fff8e1;color:#f57f17;">⏳ Aguardando</span>
                <div>
                    <strong>Em espera</strong><br>
                    A demanda está em espera por algum motivo não especificado.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fff8e1;color:#f57f17;">⏳ Aguardando resposta</span>
                <div>
                    <strong>Aguardando informações do solicitante</strong><br>
                    O responsável precisa de mais informações do solicitante para continuar.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#f3e5f5;color:#6a1b9a;">🔄 Aguardando terceiros</span>
                <div>
                    <strong>Depende de terceiros</strong><br>
                    Quando depende de outro setor ou fornecedor externo para resolução.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fff3e0;color:#bf360c;">📋 Aguardando aprovação</span>
                <div>
                    <strong>Precisa de autorização</strong><br>
                    Necessário para situações em que uma solução ou ação precisa de aprovação do cliente ou gestor.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#e0f7fa;color:#00695c;">📅 Aguardando data</span>
                <div>
                    <strong>Agendado para data futura</strong><br>
                    O atendimento está pausado até uma data/hora específica agendada para retomar.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#e3f2fd;color:#1565c0;">💬 Nova Resposta</span>
                <div>
                    <strong>Solicitante respondeu</strong><br>
                    O solicitante enviou uma nova resposta ou comentário. A demanda aguarda retomada do atendimento pelo responsável.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#e8f5e9;color:#2e7d32;">✅ Resolvido</span>
                <div>
                    <strong>Solução aplicada</strong><br>
                    A solução foi aplicada, mas aguarda a confirmação do usuário/cliente para encerrar.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#f5f5f5;color:#424242;">🏁 Encerrado</span>
                <div>
                    <strong>Demanda finalizada</strong><br>
                    O problema foi solucionado e o atendimento foi oficialmente concluído.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#ffebee;color:#c62828;">❌ Cancelado</span>
                <div>
                    <strong>Demanda cancelada</strong><br>
                    A demanda foi aberta por engano ou a solicitação foi retirada pelo solicitante. <strong>Não é contabilizada</strong> nas métricas de desempenho.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fce4ec;color:#880e4f;">🔄 Reaberto</span>
                <div>
                    <strong>Demanda reaberta</strong><br>
                    Quando uma demanda já encerrada ou resolvida volta a apresentar problemas e precisa ser tratada novamente.
                </div>
            </div>

            <h4>🔄 Transições de Status</h4>
            <p><strong>Fluxo normal:</strong> Novo → Em atendimento → Resolvido → Encerrado</p>
            <p><strong>Com interação:</strong> Novo → Em atendimento → Aguardando resposta → Nova Resposta → Em atendimento → Encerrado</p>
            <p><strong>Com terceiros:</strong> Novo → Em atendimento → Aguardando terceiros → Em atendimento → Resolvido → Encerrado</p>
            <p><strong>Com aprovação:</strong> Novo → Em atendimento → Aguardando aprovação → Resolvido → Encerrado</p>
            <p><strong>Com data agendada:</strong> Novo → Em atendimento → Aguardando data → Em atendimento → Resolvido → Encerrado</p>
            <p><strong>Reabertura:</strong> Encerrado → Reaberto → Em atendimento</p>
        </div>
    `;
    }, 10);
}

function mostrarPrioridadeGuia() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_prioridade = document.getElementById('btn-prioridade'); if (_bbtn_prioridade) _bbtn_prioridade.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'prioridade');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>🔥 Prioridades das Demandas</h3>
            <p>A prioridade determina a urgência e o SLA da demanda:</p>
            
            <div class="prioridade-item">
                <span class="status prioridade urgente">Urgente</span>
                <div>
                    <strong>Impacto crítico no negócio</strong><br>
                    Sistema principal fora do ar, falha de segurança, perda de dados, falha crítica que impede o trabalho.
                    <br><em>SLA: 2 horas úteis resposta / 4 horas úteis resolução</em>
                </div>
            </div>
            
            <div class="prioridade-item">
                <span class="status prioridade alta">Alta</span>
                <div>
                    <strong>Impacto significativo</strong><br>
                    Funcionalidade importante indisponível, erro que afeta vários usuários, afeta a produtividade.
                    <br><em>SLA: 4 horas úteis resposta / 8 horas úteis resolução</em>
                </div>
            </div>
            
            <div class="prioridade-item">
                <span class="status prioridade normal">Normal</span>
                <div>
                    <strong>Demanda rotineira</strong><br>
                    Dúvidas, solicitações de suporte, problemas menores, solicitações rotineiras.
                    <br><em>SLA: 12 horas úteis resposta / 24 horas úteis resolução</em>
                </div>
            </div>
            
            <div class="prioridade-item">
                <span class="status prioridade baixa">Baixa</span>
                <div>
                    <strong>Melhorias e sugestões</strong><br>
                    Solicitações de melhoria, sugestões não urgentes, documentação.
                    <br><em>SLA: 24 horas úteis resposta / 40 horas úteis resolução</em>
                </div>
            </div>
            
            <h4>🎯 Como Definir a Prioridade</h4>
            <ul>
                <li><strong>Impacto:</strong> Quantos usuários são afetados?</li>
                <li><strong>Urgência:</strong> Quão rápido precisa ser resolvido?</li>
                <li><strong>Criticidade:</strong> Quão importante é para o negócio?</li>
            </ul>
        </div>
    `;
    }, 10);
}

function mostrarAtalhosGuia() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_atalhos = document.getElementById('btn-atalhos'); if (_bbtn_atalhos) _bbtn_atalhos.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'atalhos');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>⌨️ Atalhos de Teclado</h3>
            <p>Use estes atalhos para navegar mais rapidamente pelo sistema:</p>
            
            <div class="atalho-item">
                <span>Criar nova demanda</span>
                <span class="atalho-tecla">Alt + N</span>
            </div>
            
            <div class="atalho-item">
                <span>Ir para lista de demandas</span>
                <span class="atalho-tecla">Alt + T</span>
            </div>
            
            <div class="atalho-item">
                <span>Abrir dashboard</span>
                <span class="atalho-tecla">Alt + D</span>
            </div>
            
            <div class="atalho-item">
                <span>Ver demandas fechadas</span>
                <span class="atalho-tecla">Alt + F</span>
            </div>
            
            <div class="atalho-item">
                <span>Fechar modals/janelas</span>
                <span class="atalho-tecla">ESC</span>
            </div>
            
            <h4>📝 Dicas de Produtividade</h4>
            <ul>
                <li>Use <strong>Tab</strong> para navegar entre campos nos formulários</li>
                <li>Pressione <strong>Enter</strong> para confirmar ações em modals</li>
                <li>Use as <strong>setas</strong> para navegar nas listas de subcategorias</li>
                <li>Digite <strong>*palavra*</strong> para preservar formatação em subcategorias</li>
            </ul>
        </div>
    `;
    }, 10);
}

// Dica de atalhos
function mostrarDicaAtalhos() {
    const dica = document.createElement('div');
    dica.className = 'atalhos-info';
    dica.innerHTML = 'Alt+N: Novo | Alt+T: Demandas | Alt+D: Dashboard | Alt+F: Fechados';
    document.body.appendChild(dica);

    setTimeout(() => dica.classList.add('show'), 1000);
    setTimeout(() => dica.classList.remove('show'), 5000);
}
// === SISTEMA DE DICAS ===

// Função para ir para lista de tickets
function irParaTickets() {
    localStorage.removeItem('cd_ticketAtivo');
    localStorage.removeItem('cd_secaoAtiva');
    mostrarSecao('tickets');
    salvarSecaoAtiva('tickets');
    carregarTickets();
}

// Cancelar criação de novo ticket
function cancelarNovoTicket() {
    limparFormularioTicket();
    mostrarSecao('tickets');
    carregarTickets();
}

// Mostrar modal de dicas
function mostrarDicas() {
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-dicas').classList.add('show');

    // Verificar se deve mostrar checkbox
    const checkbox = document.getElementById('nao-mostrar-dicas');
    const naoMostrar = localStorage.getItem('cd_naoMostrarDicas') === 'true';
    checkbox.checked = naoMostrar;
}

// Fechar modal de dicas
function fecharModalDicas() {
    document.getElementById('modal-overlay').classList.remove('show');
    document.getElementById('modal-dicas').classList.remove('show');
}

// Toggle para não mostrar dicas na inicialização
function toggleDicasIniciais() {
    const checkbox = document.getElementById('nao-mostrar-dicas');
    localStorage.setItem('cd_naoMostrarDicas', checkbox.checked);
}

// Mostrar dicas automaticamente na primeira visita
function verificarPrimeiraVisita() {
    const naoMostrar = localStorage.getItem('cd_naoMostrarDicas') === 'true';
    const jaVisitou = localStorage.getItem('cd_jaVisitou') === 'true';

    if (!naoMostrar && !jaVisitou) {
        setTimeout(() => {
            mostrarDicas();
            localStorage.setItem('cd_jaVisitou', 'true');
        }, 2000); // Mostrar após 2 segundos
    }
}


// Desativar usuário
function desativarUsuario(usuarioId) {
    // Primeiro descobre quantas demandas abertas ele tem, para avisar antes de confirmar
    const consulta = new XMLHttpRequest();
    consulta.open('GET', `${window.APP_CONFIG.apiBase}usuarios.php?demandas_abertas=${Number(usuarioId)}`, true);
    setSecurityHeaders(consulta);
    consulta.onload = function () {
        const total = consulta.status === 200 ? (JSON.parse(consulta.responseText).total || 0) : 0;
        const aviso = total > 0
            ? `Este usuário é responsável por ${total} demanda(s) aberta(s). Ao desativá-lo, elas voltam para o setor (sem responsável) e os gestores são avisados. Ele também será desconectado. Deseja continuar?`
            : 'Tem certeza que deseja desativar este usuário? Ele será desconectado e não aparecerá mais na lista de atribuição.';

        mostrarConfirm('Desativar Usuário', aviso, function (confirmado) {
            if (!confirmado) return;

            const xhr = new XMLHttpRequest();
            xhr.open('PUT', `${window.APP_CONFIG.apiBase}usuarios.php/${usuarioId}`, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            setSecurityHeaders(xhr);

            xhr.onload = function () {
                let resposta = {};
                try { resposta = JSON.parse(xhr.responseText); } catch (e) { /* sem JSON */ }
                if (xhr.status === 200) {
                    const devolvidas = resposta.demandas_devolvidas || 0;
                    mostrarToast('Sucesso', devolvidas > 0
                        ? `Usuário desativado. ${devolvidas} demanda(s) voltaram para o setor.`
                        : 'Usuário desativado com sucesso!', 'success');
                    carregarUsuarios();
                } else {
                    mostrarAlert('Erro', resposta.error || 'Erro ao desativar usuário');
                }
            };

            xhr.send(JSON.stringify({ ativo: false }));
        }, true);
    };
    consulta.send();
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

    window.submeterRedefinicaoSenha = function () {
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

        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_CONFIG.apiBase + 'usuarios.php?acao=redefinir_senha', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        setSecurityHeaders(xhr);
        xhr.onload = function () {
            let resposta = {};
            try { resposta = JSON.parse(xhr.responseText); } catch (e) { /* sem JSON */ }
            if (xhr.status === 200) {
                promptModal(null);
                mostrarToast('Sucesso', 'Senha redefinida. O usuário deverá trocá-la no próximo acesso.', 'success');
            } else {
                mostrarToast('Erro', resposta.error || 'Erro ao redefinir a senha', 'error');
            }
        };
        xhr.send(JSON.stringify({ usuario_id: usuarioId, senha }));
    };

    modalCallback = function () {
        restaurarModal();
    };
}

// Ativar usuário
function ativarUsuario(usuarioId) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `${window.APP_CONFIG.apiBase}usuarios.php/${usuarioId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Usuário ativado com sucesso!', 'success');
            carregarUsuarios();
        } else {
            mostrarAlert('Erro', 'Erro ao ativar usuário');
        }
    };

    xhr.send(JSON.stringify({ ativo: true }));
}


// Validações antes de adicionar comentário
function validarAntesComentario(ticketId) {
    const ticketAtual = JSON.parse(localStorage.getItem('cd_ticketAtual') || '{}');
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));

    // Se for funcionário interno (operador/gestor/admin)
    if (['operador', 'gestor', 'admin'].includes(usuario.tipo)) {
        // Verificar se ticket tem responsável
        if (!ticketAtual.agente_id) {
            mostrarAlert('Atenção', 'Esta demanda não possui responsável. Atribua a demanda antes de adicionar comentários.');
            return false;
        }

        // Verificar se ticket tem categoria
        if (!ticketAtual.categoria_id) {
            mostrarAlert('Atenção', 'Esta demanda não possui subcategoria. Defina uma subcategoria antes de adicionar comentários.');
            return false;
        }
    }

    return true;
}

// Validações antes de fechar ticket
function validarAntesFechamento(ticketId) {
    const ticketAtual = JSON.parse(localStorage.getItem('cd_ticketAtual') || '{}');

    // Verificar se ticket tem responsável
    if (!ticketAtual.agente_id) {
        mostrarAlert('Atenção', 'Esta demanda não possui responsável. Atribua a demanda antes de fechá-la.');
        return false;
    }

    // Verificar se ticket tem categoria
    if (!ticketAtual.categoria_id) {
        mostrarAlert('Atenção', 'Esta demanda não possui subcategoria. Defina uma subcategoria antes de fechá-la.');
        return false;
    }

    return true;
}
// Função para mostrar auditoria
function mostrarAuditoria() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_auditoria = document.getElementById('btn-auditoria'); if (_bbtn_auditoria) _bbtn_auditoria.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'auditoria');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F4CB) + ' Auditoria';

    const content = document.getElementById('config-content');
    content.innerHTML = `
        <div class="auditoria-tabs">
            <button class="tab-btn active" onclick="carregarAuditoriaGeral()">📋 Geral</button>
            <button class="tab-btn" onclick="carregarAuditoriaTickets()">🎫 Demandas</button>
            <button class="tab-btn" onclick="carregarAuditoriaLogin()">🔐 Login</button>
            <button class="tab-btn" onclick="carregarEstatisticasAudit()">📊 Estatísticas</button>
            <button class="tab-btn" onclick="carregarLogEmails()">📧 Envio de E-mails</button>
        </div>
        
        <div class="auditoria-filtros">
            <div class="filtro-grupo">
                <label for="data-inicio-audit">Data Início:</label>
                <input type="date" id="data-inicio-audit">
            </div>
            <div class="filtro-grupo">
                <label for="data-fim-audit">Data Fim:</label>
                <input type="date" id="data-fim-audit">
            </div>
            <div class="filtro-grupo filtro-grupo--expand">
                <label for="filtro-usuario-audit">Usuário:</label>
                <div class="categoria-container" style="position: relative;">
                    <input type="text" id="filtro-usuario-audit" placeholder="👤 Digite o nome do usuário..." autocomplete="off">
                    <input type="hidden" id="filtro-usuario-id">
                    <div id="lista-usuarios-audit" class="categoria-lista-form" style="display: none;"></div>
                </div>
            </div>
            <div class="filtro-acoes">
                <button class="btn-primary" onclick="aplicarFiltrosAuditoria()">🔍 Filtrar</button>
                <button class="btn-secondary" onclick="limparFiltrosAuditoria()">Limpar</button>
                <span class="filtro-separador"></span>
                <button class="btn-exportar-csv" onclick="exportarAuditoria()">📥 CSV</button>
                <button class="btn-exportar-pdf" onclick="exportarAuditoriaPDF()">🖨️ PDF</button>
            </div>
        </div>
        
        <div id="auditoria-content"></div>
        <div id="auditoria-paginacao"></div>
    `;

    carregarUsuariosFiltroAudit();
    carregarAuditoriaGeral();
}

// Carregar registros de auditoria
function carregarAuditoria() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'auditoria.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const registros = JSON.parse(xhr.responseText);
            mostrarListaAuditoria(registros);
        } else {
            document.getElementById('config-content').innerHTML = '<p>Erro ao carregar auditoria.</p>';
        }
    };

    xhr.send();
}

// ============================================================
// 📧 LOG DE ENVIO DE E-MAILS
// ============================================================
function carregarLogEmails(pagina = 1) {
    // Ativar tab
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    const tabs = document.querySelectorAll('.tab-btn');
    tabs.forEach(t => { if (t.textContent.includes('E-mails')) t.classList.add('active'); });

    // Oculta o filtro superior — esta aba tem seus próprios filtros
    const filtroSuperior = document.querySelector('.auditoria-filtros');
    if (filtroSuperior) filtroSuperior.style.display = 'none';

    // Coletar filtros do painel de auditoria
    const params = new URLSearchParams({ pagina: pagina, limite: 50 });

    const dataInicio = document.getElementById('data-inicio-audit')?.value;
    if (dataInicio) params.append('periodo', ''); // data livre não suportada diretamente, usar busca

    const busca = document.getElementById('filtro-usuario-audit')?.value;
    if (busca) params.append('busca', busca);

    // Filtros próprios do log de e-mails (dentro do painel)
    const statusFiltro = document.getElementById('email-log-status')?.value;
    if (statusFiltro) params.append('status', statusFiltro);

    const tipoFiltro = document.getElementById('email-log-tipo')?.value;
    if (tipoFiltro) params.append('tipo', tipoFiltro);

    const periodoFiltro = document.getElementById('email-log-periodo')?.value || 30;
    params.set('periodo', periodoFiltro);

    const content = document.getElementById('auditoria-content');
    content.innerHTML = '<p>⏳ Carregando log de e-mails...</p>';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}email-logs.php?${params}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            try {
                const dados = JSON.parse(xhr.responseText);
                mostrarLogEmails(dados, pagina);
            } catch (e) {
                content.innerHTML = '<p>❌ Erro ao processar resposta.</p>';
            }
        } else {
            content.innerHTML = `<p>❌ Erro ${xhr.status} ao carregar log de e-mails.</p>`;
        }
    };

    xhr.onerror = function () {
        content.innerHTML = '<p>❌ Erro de conexão ao carregar log de e-mails.</p>';
    };

    xhr.send();
}

function mostrarLogEmails(dados, paginaAtual = 1) {
    const content = document.getElementById('auditoria-content');
    const paginacao = document.getElementById('auditoria-paginacao');

    const { stats, por_tipo, logs, total, paginas } = dados;

    // Detecta modo escuro para adaptar cores inline
    const escuro = document.body.classList.contains('modo-escuro');
    const corCard      = escuro ? '#2d2d2d' : '#fff';
    const corBorda     = escuro ? '#444'    : '#e0e0e0';
    const corTextoSub  = escuro ? '#aaa'    : '#666';
    const corTextoNum  = escuro ? '#93c5fd' : '#1e3a5f';
    const corBordaSep  = escuro ? '#444'    : '#eee';
    const corLinhaImpar = escuro ? '#2d2d2d' : '#fff';
    const corLinhaPar   = escuro ? '#353535' : '#fafafa';
    const corSelectBg  = escuro ? '#3d3d3d' : '#fff';
    const corSelectBorda = escuro ? '#555'  : '#ddd';
    const corSelectTxt = escuro ? '#e0e0e0' : 'inherit';
    const corFiltrosBg = escuro ? '#1e1e1e' : '#f5f5f5';
    const corTicket    = escuro ? '#93c5fd' : '#1e3a5f';
    const corPagInativa = escuro ? '#2d2d2d' : '#fff';
    const corPagTxtInativa = escuro ? '#e0e0e0' : '#333';

    // Estilos comuns dos selects
    const selectStyle = `padding:6px 10px; border-radius:6px; border:1px solid ${corSelectBorda}; background:${corSelectBg}; color:${corSelectTxt};`;

    // --- Painel de filtros específicos ---
    const filtrosHtml = `
        <div class="email-log-filtros" style="display:flex; flex-direction:column; gap:10px; margin-bottom:16px; padding:12px; background:${corFiltrosBg}; border-radius:8px; border:1px solid ${corBorda};">

            <!-- Linha 1: filtros -->
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label for="email-log-periodo" style="font-size:13px; font-weight:600; white-space:nowrap;">Período:</label>
                <select id="email-log-periodo" style="${selectStyle}">
                    <option value="7">Últimos 7 dias</option>
                    <option value="30" selected>Últimos 30 dias</option>
                    <option value="90">Últimos 90 dias</option>
                    <option value="365">Último ano</option>
                </select>

                <label for="email-log-status" style="font-size:13px; font-weight:600; white-space:nowrap;">Status:</label>
                <select id="email-log-status" style="${selectStyle}">
                    <option value="">Todos</option>
                    <option value="sucesso">✅ Sucesso</option>
                    <option value="falha">❌ Falha</option>
                </select>

                <label for="email-log-tipo" style="font-size:13px; font-weight:600; white-space:nowrap;">Tipo:</label>
                <select id="email-log-tipo" style="${selectStyle}">
                    <option value="">Todos</option>
                    <option value="novo_ticket">📩 Nova Demanda (solicitante)</option>
                    <option value="notif_agente">👥 Aviso à Equipe (nova demanda/comentário)</option>
                    <option value="primeira_resposta">📧 Primeira Resposta (automática)</option>
                    <option value="resposta">✉️ Resposta do Suporte</option>
                    <option value="comentario">💬 Comentário (solicitante)</option>
                    <option value="atribuicao">👤 Atribuição</option>
                    <option value="fechamento">🔒 Encerramento</option>
                    <option value="reabertura">🔓 Reabertura</option>
                    <option value="encaminhamento">↪️ Encaminhamento</option>
                    <option value="lembrete">🔔 Lembrete</option>
                    <option value="recuperacao_senha">🔑 Recuperação de Senha</option>
                    <option value="outros">📌 Outros</option>
                </select>
            </div>

            <!-- Linha 2: botões alinhados à direita -->
            <div style="display:flex; gap:6px; justify-content:flex-end; align-items:center;">
                <button class="btn-primary" onclick="carregarLogEmails()" style="padding:6px 14px;">🔍 Filtrar</button>
                <span style="width:1px; height:22px; background:${corBorda}; display:inline-block; margin:0 10px;"></span>
                <button class="btn-exportar-csv" onclick="exportarLogEmailsCSV()">📥 CSV</button>
                <button class="btn-exportar-pdf" onclick="exportarAuditoriaPDF()">🖨️ PDF</button>
            </div>
        </div>
    `;

    // --- Cards de estatísticas ---
    const taxaSucesso = parseFloat(stats?.taxa_sucesso || 0);
    const corTaxa = taxaSucesso >= 95 ? '#27ae60' : taxaSucesso >= 80 ? '#e67e22' : '#e74c3c';

    const statsHtml = `
        <div class="stats-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:20px;">
            <div class="stat-card" style="background:${corCard}; border:1px solid ${corBorda}; border-radius:8px; padding:14px; text-align:center;">
                <div class="stat-num" style="font-size:24px; font-weight:700; color:${corTextoNum};">${stats?.total || 0}</div>
                <div class="stat-label" style="font-size:12px; color:${corTextoSub}; margin-top:4px;">Total Enviados</div>
            </div>
            <div class="stat-card" style="background:${corCard}; border:1px solid ${corBorda}; border-radius:8px; padding:14px; text-align:center;">
                <div class="stat-num" style="font-size:24px; font-weight:700; color:#27ae60;">${stats?.sucessos || 0}</div>
                <div class="stat-label" style="font-size:12px; color:${corTextoSub}; margin-top:4px;">✅ Sucessos</div>
            </div>
            <div class="stat-card" style="background:${corCard}; border:1px solid ${corBorda}; border-radius:8px; padding:14px; text-align:center;">
                <div class="stat-num" style="font-size:24px; font-weight:700; color:#e74c3c;">${stats?.falhas || 0}</div>
                <div class="stat-label" style="font-size:12px; color:${corTextoSub}; margin-top:4px;">❌ Falhas</div>
            </div>
            <div class="stat-card" style="background:${corCard}; border:1px solid ${corBorda}; border-radius:8px; padding:14px; text-align:center;">
                <div class="stat-num" style="font-size:24px; font-weight:700; color:${corTaxa};">${taxaSucesso}%</div>
                <div class="stat-label" style="font-size:12px; color:${corTextoSub}; margin-top:4px;">Taxa de Sucesso</div>
            </div>
        </div>
    `;

    // --- Tabela de logs ---
    let tabelaHtml = '';
    if (!logs || logs.length === 0) {
        tabelaHtml = `<p style="color:${corTextoSub}; text-align:center; padding:30px;">Nenhum e-mail registrado no período.</p>`;
    } else {
        const tipoLabels = {
            novo_ticket:       '📩 Nova Demanda',
            notif_agente:      '👥 Aviso à Equipe',
            primeira_resposta: '📧 Primeira Resposta',
            resposta:          '✉️ Resposta do Suporte',
            comentario:        '💬 Comentário',
            atribuicao:        '👤 Atribuição',
            fechamento:        '🔒 Encerramento',
            reabertura:        '🔓 Reabertura',
            encaminhamento:    '↪️ Encaminhamento',
            lembrete:          '🔔 Lembrete',
            recuperacao_senha: '🔑 Recuperação de Senha',
            outros:            '📌 Outros',
        };

        // Badges de status adaptados ao modo escuro
        const badgeSucessoBg = escuro ? '#1a3a2a' : '#e8f5e9';
        const badgeFalhaBg   = escuro ? '#3a1a1a' : '#fdecea';

        tabelaHtml = `
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#1e3a5f; color:#fff;">
                        <th style="padding:10px 12px; text-align:left;">Data/Hora</th>
                        <th style="padding:10px 12px; text-align:left;">Destinatário</th>
                        <th style="padding:10px 12px; text-align:left;">Assunto</th>
                        <th style="padding:10px 12px; text-align:center;">Tipo</th>
                        <th style="padding:10px 12px; text-align:center;">Demanda</th>
                        <th style="padding:10px 12px; text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${logs.map((log, i) => {
            const statusBadge = log.status === 'sucesso'
                ? `<span style="background:${badgeSucessoBg}; color:#27ae60; padding:3px 8px; border-radius:12px; font-weight:600;">✅ Sucesso</span>`
                : `<span style="background:${badgeFalhaBg}; color:#e74c3c; padding:3px 8px; border-radius:12px; font-weight:600;" title="${sanitizeHTML(log.erro || '')}">❌ Falha</span>`;

            const ticketLink = log.ticket_id
                ? `<span style="font-family:monospace; font-weight:600; color:${corTicket};">#${String(log.ticket_id).padStart(5, '0')}</span>`
                : `<span style="color:${corTextoSub};">—</span>`;

            const tipoTexto = tipoLabels[log.tipo] || log.tipo;
            const bg = i % 2 === 0 ? corLinhaImpar : corLinhaPar;
            const dataFormatada = log.enviado_em
                ? new Date(log.enviado_em).toLocaleString('pt-BR')
                : '—';

            return `
                            <tr style="background:${bg}; border-bottom:1px solid ${corBordaSep};">
                                <td style="padding:9px 12px; white-space:nowrap;">${dataFormatada}</td>
                                <td style="padding:9px 12px;">${sanitizeHTML(log.destinatario)}</td>
                                <td style="padding:9px 12px; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${sanitizeHTML(log.assunto)}">${sanitizeHTML(log.assunto)}</td>
                                <td style="padding:9px 12px; text-align:center; white-space:nowrap;">${tipoTexto}</td>
                                <td style="padding:9px 12px; text-align:center;">${ticketLink}</td>
                                <td style="padding:9px 12px; text-align:center;">${statusBadge}</td>
                            </tr>
                        `;
        }).join('')}
                </tbody>
            </table>
            </div>`;
    }

    content.innerHTML = filtrosHtml + statsHtml + tabelaHtml;

    // Re-aplicar valores dos selects após renderizar
    const periodoEl = document.getElementById('email-log-periodo');
    const statusEl = document.getElementById('email-log-status');
    const tipoEl = document.getElementById('email-log-tipo');
    // (valores já são os default; numa segunda chamada serão re-lidos antes de recarregar)

    // --- Paginação ---
    if (paginas > 1) {
        let pagHtml = `<div style="text-align:center; margin-top:16px; display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">`;
        for (let p = 1; p <= paginas; p++) {
            const ativo = p === paginaAtual
                ? 'background:#1e3a5f; color:#fff;'
                : `background:${corPagInativa}; color:${corPagTxtInativa};`;
            pagHtml += `<button onclick="carregarLogEmails(${p})" style="${ativo} border:1px solid ${corSelectBorda}; padding:6px 12px; border-radius:6px; cursor:pointer;">${p}</button>`;
        }
        pagHtml += '</div>';
        paginacao.innerHTML = pagHtml;
    } else {
        paginacao.innerHTML = '';
    }
}

// Mostrar lista de auditoria
function mostrarListaAuditoria(registros) {
    const content = document.getElementById('config-content');

    // Salvar valores dos filtros
    const acaoAtual = document.getElementById('filtro-acao')?.value || '';
    const dataInicioAtual = document.getElementById('filtro-data-inicio')?.value || '';
    const dataFimAtual = document.getElementById('filtro-data-fim')?.value || '';

    const acoesPorTipo = {
        'excluir_ticket': registros.filter(r => r.acao === 'excluir_ticket'),
        'criar_usuario': registros.filter(r => r.acao === 'criar_usuario'),
        'atualizar_usuario': registros.filter(r => r.acao === 'atualizar_usuario'),
        'excluir_usuario': registros.filter(r => r.acao === 'excluir_usuario'),
        'criar_categoria': registros.filter(r => r.acao === 'criar_categoria'),
        'atualizar_categoria': registros.filter(r => r.acao === 'atualizar_categoria'),
        'excluir_categoria': registros.filter(r => r.acao === 'excluir_categoria')
    };

    function formatarAcao(acao) {
        const acoes = {
            'excluir_ticket': '🗑️ Exclusão de Demanda',
            'criar_usuario': '➕ Criação de Usuário',
            'atualizar_usuario': '✏️ Atualização de Usuário',
            'excluir_usuario': '🗑️ Exclusão de Usuário',
            'criar_categoria': '➕ Criação de Subcategoria',
            'atualizar_categoria': '✏️ Atualização de Subcategoria',
            'excluir_categoria': '🗑️ Exclusão de Subcategoria',
            'criar_macrocategoria': '➕ Criação de Categoria',
            'atualizar_macrocategoria': '✏️ Atualização de Categoria',
            'excluir_macrocategoria': '🗑️ Exclusão de Categoria'
        };
        return acoes[acao] || acao;
    }

    function gerarCardAuditoria(registro) {

        const dados = registro.dados_anteriores ? JSON.parse(registro.dados_anteriores) :
            registro.dados_novos ? JSON.parse(registro.dados_novos) : {};

        let detalhes = '';
        if (registro.acao.includes('ticket')) {
            detalhes = `Demanda #${String(registro.registro_id).padStart(5, '0')} - ${sanitizeHTML(dados.titulo || 'N/A')}`;
            // Se for exclusão de ticket, adicionar dados do solicitante
            if (registro.acao === 'excluir_ticket') {
                const solicitanteNome = dados.solicitante_nome || dados.usuario_nome || 'N/A';
                const solicitanteEmail = dados.solicitante_email || dados.usuario_email || 'N/A';
                detalhes += ` | Solicitante: ${solicitanteNome} (${solicitanteEmail})`;
            }
        } else if (registro.acao.includes('usuario')) {
            detalhes = registro.usuario_afetado || `${sanitizeHTML(dados.nome || 'N/A')} (${sanitizeHTML(dados.email || 'N/A')}) - ${sanitizeHTML(dados.tipo || 'N/A')}`;
        } else if (registro.acao.includes('categoria')) {
            detalhes = `${sanitizeHTML(dados.nome || 'N/A')}`;
        }

        return `
            <div class="usuario-card">
                <div style="display: grid; grid-template-columns: 250px 400px 180px 250px 120px 180px; gap: 1rem; align-items: center; font-size: 14px;">
                    <div><strong>Ação:</strong> ${formatarAcao(registro.acao)}</div>
                    <div><strong>Detalhes:</strong> ${detalhes}</div>
                    <div><strong>Usuário:</strong> ${sanitizeHTML(registro.usuario_nome)}</div>
                    <div><strong>Data/Hora:</strong> ${new Date(registro.criado_em).toLocaleString('pt-BR')}</div>
                    <div><strong>IP:</strong> ${registro.ip_address || 'N/A'}</div>
                    <div><strong>Host:</strong> ${registro.hostname || 'N/A'}</div>
                </div>
            </div>
        `;
    }

    let html = `
        <div class="form-usuario">
            <h3>🔍 Filtros de Auditoria</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label>Data Início:</label>
                    <input type="date" id="filtro-data-inicio" value="${dataInicioAtual}">
                </div>
                <div class="form-group">
                    <label>Data Fim:</label>
                    <input type="date" id="filtro-data-fim" value="${dataFimAtual}">
                </div>
                <div class="form-group">
                    <label>Ação:</label>
                    <select id="filtro-acao">
                        <option value="">Todas</option>
                        <option value="excluir_ticket" ${acaoAtual === 'excluir_ticket' ? 'selected' : ''}>Exclusão de Demandas</option>
                        <option value="criar_usuario" ${acaoAtual === 'criar_usuario' ? 'selected' : ''}>Criação de Usuários</option>
                        <option value="atualizar_usuario" ${acaoAtual === 'atualizar_usuario' ? 'selected' : ''}>Atualização de Usuários</option>
                        <option value="excluir_usuario" ${acaoAtual === 'excluir_usuario' ? 'selected' : ''}>Exclusão de Usuários</option>
                        <option value="criar_categoria" ${acaoAtual === 'criar_categoria' ? 'selected' : ''}>Criação de Subcategorias</option>
                        <option value="atualizar_categoria" ${acaoAtual === 'atualizar_categoria' ? 'selected' : ''}>Atualização de Subcategorias</option>
                        <option value="excluir_categoria" ${acaoAtual === 'excluir_categoria' ? 'selected' : ''}>Exclusão de Subcategorias</option>
                        <option value="criar_macrocategoria" ${acaoAtual === 'criar_macrocategoria' ? 'selected' : ''}>Criação de Categorias</option>
                        <option value="atualizar_macrocategoria" ${acaoAtual === 'atualizar_macrocategoria' ? 'selected' : ''}>Atualização de Categorias</option>
                        <option value="excluir_macrocategoria" ${acaoAtual === 'excluir_macrocategoria' ? 'selected' : ''}>Exclusão de Categorias</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button class="btn-primary" onclick="filtrarAuditoria()">🔍 Pesquisar</button>
                <button class="btn-secondary" onclick="limparFiltrosAuditoria()">🗑️ Limpar</button>
            </div>
        </div>
    `;

    if (registros.length === 0) {
        html += '<p>Nenhum registro de auditoria encontrado.</p>';
    } else {
        html += `<h3>📋 Registros de Auditoria (${registros.length})</h3>`;
        html += '<div class="usuarios-grid">';
        registros.forEach(registro => {
            html += gerarCardAuditoria(registro);
        });
        html += '</div>';
    }

    content.innerHTML = html;
}

// Filtrar auditoria
function filtrarAuditoria() {
    const acao = document.getElementById('filtro-acao').value;
    const dataInicio = document.getElementById('filtro-data-inicio').value;
    const dataFim = document.getElementById('filtro-data-fim').value;

    const params = new URLSearchParams();
    if (acao) params.append('acao', acao);
    if (dataInicio) params.append('data_inicio', dataInicio);
    if (dataFim) params.append('data_fim', dataFim);

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}auditoria.php?${params}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const registros = JSON.parse(xhr.responseText);
            mostrarListaAuditoria(registros);
        }
    };

    xhr.send();
}

// Limpar filtros de auditoria
function limparFiltrosAuditoria() {
    const dataInicio = document.getElementById('data-inicio-audit');
    const dataFim = document.getElementById('data-fim-audit');
    const usuario = document.getElementById('filtro-usuario-audit');
    const usuarioId = document.getElementById('filtro-usuario-id');

    if (dataInicio) dataInicio.value = '';
    if (dataFim) dataFim.value = '';
    if (usuario) usuario.value = '';
    if (usuarioId) usuarioId.value = '';

    const tabAtiva = document.querySelector('.tab-btn.active');
    if (tabAtiva.textContent.includes('Geral')) carregarAuditoriaGeral();
    else if (tabAtiva.textContent.includes('Demandas')) carregarAuditoriaTickets();
    else if (tabAtiva.textContent.includes('Login')) carregarAuditoriaLogin();
}

// Carregar usuários para filtro (equipe e administradores)
let todosUsuariosAudit = [];

function carregarUsuariosFiltroAudit() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'usuarios.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const usuarios = JSON.parse(xhr.responseText);
            // Filtrar apenas funcionários internos (ativos e inativos)
            todosUsuariosAudit = usuarios.filter(u => ['operador', 'gestor', 'admin'].includes(u.tipo));
            configurarBuscaUsuarioAudit();
        }
    };
    xhr.send();
}

// Configurar busca de usuário
function configurarBuscaUsuarioAudit() {
    const input = document.getElementById('filtro-usuario-audit');
    const lista = document.getElementById('lista-usuarios-audit');
    const inputId = document.getElementById('filtro-usuario-id');

    if (!input || !lista) return;

    // Mostrar lista ao focar
    input.addEventListener('focus', function () {
        if (todosUsuariosAudit.length > 0) {
            renderizarUsuariosAudit(todosUsuariosAudit);
            lista.style.display = 'block';
        }
    });

    // Filtrar ao digitar
    input.addEventListener('input', function () {
        const termo = this.value.toLowerCase();
        if (termo === '') {
            inputId.value = '';
            renderizarUsuariosAudit(todosUsuariosAudit);
        } else {
            const filtrados = todosUsuariosAudit.filter(u =>
                u.nome.toLowerCase().includes(termo) ||
                u.email.toLowerCase().includes(termo)
            );
            renderizarUsuariosAudit(filtrados);
        }
        lista.style.display = 'block';
    });

    // Fechar ao clicar fora
    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !lista.contains(e.target)) {
            lista.style.display = 'none';
        }
    });
}

// Renderizar lista de usuários
function renderizarUsuariosAudit(usuarios) {
    const lista = document.getElementById('lista-usuarios-audit');
    if (!lista) return;

    lista.innerHTML = '';

    if (usuarios.length === 0) {
        lista.innerHTML = '<div class="categoria-item-form" style="padding: 0.75rem; color: #999;">Nenhum usuário encontrado</div>';
        return;
    }

    usuarios.forEach(usuario => {
        const div = document.createElement('div');
        div.className = 'categoria-item-form';
        div.textContent = `${usuario.nome} (${usuario.email})`;
        div.style.cursor = 'pointer';

        div.onclick = function () {
            document.getElementById('filtro-usuario-audit').value = usuario.nome;
            document.getElementById('filtro-usuario-id').value = usuario.id;
            lista.style.display = 'none';
        };

        lista.appendChild(div);
    });
}

// Aplicar filtros
function aplicarFiltrosAuditoria() {
    const tabAtiva = document.querySelector('.tab-btn.active');
    if (tabAtiva.textContent.includes('Geral')) carregarAuditoriaGeral();
    else if (tabAtiva.textContent.includes('Demandas')) carregarAuditoriaTickets();
    else if (tabAtiva.textContent.includes('Login')) carregarAuditoriaLogin();
    else if (tabAtiva.textContent.includes('E-mails')) carregarLogEmails();
}

// Carregar auditoria geral
function carregarAuditoriaGeral(pagina = 1) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-btn')[0].classList.add('active');

    // Restaura o filtro superior ao voltar para esta aba
    const filtroSuperior = document.querySelector('.auditoria-filtros');
    if (filtroSuperior) filtroSuperior.style.display = '';

    const params = new URLSearchParams({ tipo: 'geral', pagina: pagina, limite: 50 });

    const dataInicio = document.getElementById('data-inicio-audit')?.value;
    if (dataInicio) params.append('data_inicio', dataInicio);

    const dataFim = document.getElementById('data-fim-audit')?.value;
    if (dataFim) params.append('data_fim', dataFim);

    const usuarioId = document.getElementById('filtro-usuario-id')?.value;
    if (usuarioId) params.append('usuario_id', usuarioId);

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}auditoria-avancada.php?${params}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const dados = JSON.parse(xhr.responseText);
            mostrarRegistrosAuditoria(dados.registros);
            mostrarPaginacaoAudit(dados.pagina, dados.total_paginas, 'carregarAuditoriaGeral');
        }
    };
    xhr.send();
}

// Mostrar registros de auditoria
function mostrarRegistrosAuditoria(registros) {
    const content = document.getElementById('auditoria-content');

    if (registros.length === 0) {
        content.innerHTML = '<p>Nenhum registro encontrado.</p>';
        return;
    }

    let html = '<div class="auditoria-lista">';

    registros.forEach(reg => {
        html += `
            <div class="auditoria-card">
                <div class="auditoria-header">
                    <span class="auditoria-acao">${formatarAcaoAuditoria(reg.acao)}</span>
                    <span class="auditoria-data">${new Date(reg.criado_em).toLocaleString('pt-BR')}</span>
                </div>
                <div class="auditoria-body">
                    <div><strong>Usuário:</strong> ${sanitizeHTML(reg.usuario_nome)} (${sanitizeHTML(reg.usuario_email)})</div>
                    ${reg.usuario_afetado ? `<div><strong>Usuário Afetado:</strong> ${reg.usuario_afetado}</div>` : ''}
                    <div><strong>IP:</strong> ${reg.ip_address || 'N/A'} | <strong>Host:</strong> ${reg.hostname || 'N/A'}</div>
                    <div><strong>Navegador:</strong> ${formatarUserAgent(reg.user_agent)}</div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    content.innerHTML = html;
}

// Carregar auditoria de tickets
function carregarAuditoriaTickets(pagina = 1) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-btn')[1].classList.add('active');

    // Restaura o filtro superior ao voltar para esta aba
    const filtroSuperior = document.querySelector('.auditoria-filtros');
    if (filtroSuperior) filtroSuperior.style.display = '';

    const params = new URLSearchParams({ tipo: 'tickets', pagina: pagina, limite: 50 });

    const dataInicio = document.getElementById('data-inicio-audit')?.value;
    if (dataInicio) params.append('data_inicio', dataInicio);

    const dataFim = document.getElementById('data-fim-audit')?.value;
    if (dataFim) params.append('data_fim', dataFim);

    const usuarioId = document.getElementById('filtro-usuario-id')?.value;
    if (usuarioId) params.append('usuario_id', usuarioId);

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}auditoria-avancada.php?${params}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const dados = JSON.parse(xhr.responseText);
            mostrarRegistrosTicketsAudit(dados.registros);
            mostrarPaginacaoAudit(dados.pagina, dados.total_paginas, 'carregarAuditoriaTickets');
        }
    };
    xhr.send();
}

// Mostrar registros de tickets
function mostrarRegistrosTicketsAudit(registros) {
    const content = document.getElementById('auditoria-content');

    if (registros.length === 0) {
        content.innerHTML = '<p>Nenhuma alteração de demanda encontrada.</p>';
        return;
    }

    let html = '<div class="auditoria-lista">';

    registros.forEach(reg => {
        html += `
            <div class="auditoria-card">
                <div class="auditoria-header">
                    <span class="auditoria-acao">Demanda #${String(reg.ticket_id).padStart(5, '0')} - ${reg.campo}</span>
                    <span class="auditoria-data">${new Date(reg.criado_em).toLocaleString('pt-BR')}</span>
                </div>
                <div class="auditoria-body">
                    <div><strong>Usuário:</strong> ${sanitizeHTML(reg.usuario_nome)}</div>
                    <div><strong>Demanda:</strong> ${sanitizeHTML(reg.ticket_titulo || 'N/A')}</div>
                    <div class="auditoria-diff">
                        <div><strong>De:</strong> ${sanitizeHTML(reg.valor_anterior || 'N/A')}</div>
                        <div><strong>Para:</strong> ${sanitizeHTML(reg.valor_novo || 'N/A')}</div>
                    </div>
                    <div><strong>IP:</strong> ${reg.ip_address || 'N/A'} | <strong>Host:</strong> ${reg.hostname || 'N/A'}</div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    content.innerHTML = html;
}

// Carregar auditoria de login
function carregarAuditoriaLogin(pagina = 1) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-btn')[2].classList.add('active');

    // Restaura o filtro superior ao voltar para esta aba
    const filtroSuperior = document.querySelector('.auditoria-filtros');
    if (filtroSuperior) filtroSuperior.style.display = '';

    const params = new URLSearchParams({ tipo: 'login', pagina: pagina, limite: 50 });

    const dataInicio = document.getElementById('data-inicio-audit')?.value;
    if (dataInicio) params.append('data_inicio', dataInicio);

    const dataFim = document.getElementById('data-fim-audit')?.value;
    if (dataFim) params.append('data_fim', dataFim);

    const usuarioId = document.getElementById('filtro-usuario-id')?.value;
    if (usuarioId) params.append('usuario_id', usuarioId);

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}auditoria-avancada.php?${params}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const dados = JSON.parse(xhr.responseText);
            mostrarRegistrosLoginAudit(dados.registros);
            mostrarPaginacaoAudit(dados.pagina, dados.total_paginas, 'carregarAuditoriaLogin');
        }
    };
    xhr.send();
}

// Mostrar registros de login
function mostrarRegistrosLoginAudit(registros) {
    const content = document.getElementById('auditoria-content');

    if (registros.length === 0) {
        content.innerHTML = '<p>Nenhum registro de login encontrado.</p>';
        return;
    }

    let html = '<div class="auditoria-lista">';

    registros.forEach(reg => {
        const icone = reg.acao === 'login_sucesso' ? '✅' : reg.acao === 'login_falha' ? '❌' : '🚺';
        const classe = reg.acao === 'login_sucesso' ? 'sucesso' : reg.acao === 'login_falha' ? 'falha' : 'logout';

        html += `
            <div class="auditoria-card ${classe}">
                <div class="auditoria-header">
                    <span class="auditoria-acao">${icone} ${formatarAcaoLogin(reg.acao)}</span>
                    <span class="auditoria-data">${new Date(reg.criado_em).toLocaleString('pt-BR')}</span>
                </div>
                <div class="auditoria-body">
                    <div><strong>Usuário:</strong> ${sanitizeHTML(reg.usuario_nome || reg.email)}</div>
                    <div><strong>Email:</strong> ${sanitizeHTML(reg.email)}</div>
                    <div><strong>IP:</strong> ${reg.ip_address || 'N/A'} | <strong>Host:</strong> ${reg.hostname || 'N/A'}</div>
                    <div><strong>Navegador:</strong> ${formatarUserAgent(reg.user_agent)}</div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    content.innerHTML = html;
}

// Carregar estatísticas
function carregarEstatisticasAudit() {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-btn')[3].classList.add('active');

    // Restaura o filtro superior ao voltar para esta aba
    const filtroSuperior = document.querySelector('.auditoria-filtros');
    if (filtroSuperior) filtroSuperior.style.display = '';

    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'auditoria-avancada.php?tipo=estatisticas', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const dados = JSON.parse(xhr.responseText);
            mostrarEstatisticasAudit(dados);
        }
    };
    xhr.send();
}

// Mostrar estatísticas
function mostrarEstatisticasAudit(dados) {
    const content = document.getElementById('auditoria-content');

    let html = `
        <div class="estatisticas-grid">
            <div class="estatistica-card">
                <h3>📊 Ações por Tipo</h3>
                <div class="estatistica-lista">
                    ${dados.acoes_por_tipo.map(item => `
                        <div class="estatistica-item">
                            <span>${formatarAcaoAuditoria(item.acao)}</span>
                            <span class="estatistica-valor">${item.total}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
            
            <div class="estatistica-card">
                <h3>👥 Top 10 Usuários Mais Ativos</h3>
                <div class="estatistica-lista">
                    ${dados.acoes_por_usuario.map(item => `
                        <div class="estatistica-item">
                            <span>${sanitizeHTML(item.nome || 'N/A')}</span>
                            <span class="estatistica-valor">${item.total}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
            
            <div class="estatistica-card">
                <h3>🔐 Logins (Últimos 30 dias)</h3>
                <div class="estatistica-lista">
                    ${dados.logins_por_dia.slice(0, 10).map(item => `
                        <div class="estatistica-item">
                            <span>${new Date(item.data).toLocaleDateString('pt-BR')}</span>
                            <span class="estatistica-valor">✅ ${item.sucessos} | ❌ ${item.falhas}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
            
            <div class="estatistica-card">
                <h3>🎫 Alterações de Demandas por Campo</h3>
                <div class="estatistica-lista">
                    ${dados.alteracoes_por_campo.map(item => `
                        <div class="estatistica-item">
                            <span>${item.campo}</span>
                            <span class="estatistica-valor">${item.total}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>
    `;

    content.innerHTML = html;
    document.getElementById('auditoria-paginacao').innerHTML = '';
}

// Funções auxiliares
function formatarAcaoAuditoria(acao) {
    const acoes = {
        'excluir_ticket': '🗑️ Exclusão de Demanda',
        'criar_usuario': '➕ Criação de Usuário',
        'atualizar_usuario': '✏️ Atualização de Usuário',
        'excluir_usuario': '🗑️ Exclusão de Usuário',
        'criar_categoria': '➕ Criação de Subcategoria',
        'atualizar_categoria': '✏️ Atualização de Subcategoria',
        'excluir_categoria': '🗑️ Exclusão de Subcategoria',
        'criar_macrocategoria': '➕ Criação de Categoria',
        'atualizar_macrocategoria': '✏️ Atualização de Categoria',
        'excluir_macrocategoria': '🗑️ Exclusão de Categoria'
    };
    return acoes[acao] || acao;
}

function formatarAcaoLogin(acao) {
    const acoes = {
        'login_sucesso': 'Login Bem-sucedido',
        'login_falha': 'Tentativa de Login Falhou',
        'logout': 'Logout'
    };
    return acoes[acao] || acao;
}

function formatarUserAgent(ua) {
    if (!ua) return 'N/A';
    if (ua.includes('Chrome')) return '🌐 Chrome';
    if (ua.includes('Firefox')) return '🦊 Firefox';
    if (ua.includes('Safari')) return '🧭 Safari';
    if (ua.includes('Edge')) return '🌊 Edge';
    return ua.substring(0, 50) + '...';
}

function mostrarPaginacaoAudit(paginaAtual, totalPaginas, funcao) {
    const container = document.getElementById('auditoria-paginacao');

    if (totalPaginas <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '<div class="paginacao">';

    if (paginaAtual > 1) {
        html += `<button onclick="${funcao}(${paginaAtual - 1})">← Anterior</button>`;
    }

    html += `<span>Página ${paginaAtual} de ${totalPaginas}</span>`;

    if (paginaAtual < totalPaginas) {
        html += `<button onclick="${funcao}(${paginaAtual + 1})">Próxima →</button>`;
    }

    html += '</div>';
    container.innerHTML = html;
}

// Exportar para CSV
function exportarAuditoria() {
    const tabAtiva = document.querySelector('.tab-btn.active');
    const tipo = tabAtiva.textContent.includes('Geral') ? 'geral' :
        tabAtiva.textContent.includes('Demandas') ? 'tickets' : 'login';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${window.APP_CONFIG.apiBase}auditoria-avancada.php?exportar=1&tipo=${tipo}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const dados = JSON.parse(xhr.responseText);
            baixarCSV(dados.registros, `auditoria_${tipo}_${new Date().toISOString().split('T')[0]}.csv`);
        }
    };
    xhr.send();
}

function exportarAuditoriaPDF() {
    const tabAtiva = document.querySelector('.tab-btn.active');
    const tituloTab = tabAtiva ? tabAtiva.textContent.trim() : 'Auditoria';
    const conteudo = document.getElementById('auditoria-content');

    if (!conteudo || !conteudo.innerHTML.trim()) {
        mostrarAlert('Aviso', 'Nenhum conteúdo para exportar como PDF.');
        return;
    }

    // Clona e remove elementos não desejados no PDF
    const clone = conteudo.cloneNode(true);
    clone.querySelectorAll('.email-log-filtros, button, .no-print').forEach(el => el.remove());

    // Normaliza inline styles incompatíveis com a largura A4
    clone.querySelectorAll('[style]').forEach(el => {
        let s = el.getAttribute('style') || '';
        // Remove overflow-x que impede expansão da tabela
        s = s.replace(/overflow-x\s*:\s*[^;]+;?\s*/gi, '');
        // Substitui grid com px fixo por grid fluido
        s = s.replace(/grid-template-columns\s*:[^;]+;/gi, 'grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));');
        // Remove white-space: nowrap (quebra texto em vez de cortar)
        s = s.replace(/white-space\s*:\s*nowrap\s*;?\s*/gi, '');
        // Remove max-width em px (ex: max-width:260px em células de assunto)
        s = s.replace(/max-width\s*:\s*\d+px\s*;?\s*/gi, '');
        // Remove min-width em px
        s = s.replace(/min-width\s*:\s*\d+px\s*;?\s*/gi, '');
        el.setAttribute('style', s.trim());
    });

    // Remove background inline de tr e td para que o CSS do PDF controle as cores das linhas
    clone.querySelectorAll('tr, td').forEach(el => {
        let s = el.getAttribute('style') || '';
        s = s.replace(/background\s*:\s*[^;]+;?\s*/gi, '');
        s = s.replace(/background-color\s*:\s*[^;]+;?\s*/gi, '');
        el.setAttribute('style', s.trim());
    });

    const dataAtual = new Date().toLocaleDateString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });

    const janela = window.open('', '_blank', 'width=900,height=700');
    janela.document.write(`<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Auditoria Conecta CRPRS — ${tituloTab}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm 12mm 16mm 12mm; }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10.5px;
            color: #1a1a1a;
            background: #fff;
            width: 100%;
        }

        /* ── Cabeçalho ── */
        .cabecalho-pdf {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-bottom: 8px;
            border-bottom: 3px solid #004373;
            margin-bottom: 14px;
        }
        .cabecalho-pdf .logo-bloco { display: flex; flex-direction: column; gap: 2px; }
        .cabecalho-pdf .titulo-principal {
            font-size: 17px; font-weight: 800;
            color: #004373; letter-spacing: -0.3px;
        }
        .cabecalho-pdf .titulo-secao {
            font-size: 11px; font-weight: 600; color: #444; margin-top: 1px;
        }
        .cabecalho-pdf .subtitulo {
            font-size: 9px; color: #888; margin-top: 3px;
        }
        .cabecalho-pdf .data-bloco {
            text-align: right; font-size: 9px; color: #666; line-height: 1.6;
        }

        /* ── Layout geral ── */
        #auditoria-content, .auditoria-lista { width: 100% !important; }

        /* ── Tabelas ── */
        table {
            width: 100% !important;
            border-collapse: collapse;
            font-size: 9.5px;
            margin-bottom: 14px;
            table-layout: fixed;
        }
        thead tr { background: #004373 !important; color: #fff !important; }
        th {
            padding: 6px 7px;
            text-align: left;
            font-weight: 700;
            font-size: 9.5px;
            color: #fff !important;
            word-break: break-word;
        }
        td {
            padding: 5px 7px;
            border-bottom: 1px solid #e8e8e8;
            vertical-align: top;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        tbody tr:nth-child(even) td { background: #f4f6fb !important; }

        /* ── Cards de estatísticas ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 14px;
            width: 100%;
        }
        .stat-card {
            border: 1px solid #d0d7e8;
            border-radius: 6px;
            padding: 10px 8px;
            text-align: center;
            background: #f8faff !important;
        }
        .stat-num { font-size: 20px; font-weight: 800; }
        .stat-label { font-size: 8.5px; color: #666; margin-top: 3px; }

        /* ── Cards de auditoria (abas Geral / Tickets / Login) ── */
        .auditoria-card {
            width: 100% !important;
            background: #fff !important;
            border-radius: 5px;
            padding: 8px 10px;
            margin-bottom: 8px;
            border-left: 3px solid #004373;
            border: 1px solid #e0e0e0;
            page-break-inside: avoid;
        }
        .auditoria-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            padding-bottom: 4px;
            border-bottom: 1px solid #eee;
        }
        .auditoria-acao { font-weight: 700; font-size: 10px; }
        .auditoria-data { font-size: 9px; color: #777; }
        .auditoria-body {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 4px 12px !important;
            font-size: 9px;
            flex-wrap: wrap !important;
        }
        .auditoria-info-item { display: flex; gap: 4px; flex-wrap: wrap; }
        .auditoria-label { font-weight: 600; color: #555; white-space: nowrap; }
        .auditoria-valor { color: #222; word-break: break-all; }

        /* ── Cards de usuário (aba Geral detalhes) ── */
        .usuario-card {
            width: 100% !important;
            background: #fff !important;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 8px 10px;
            margin-bottom: 8px;
            page-break-inside: avoid;
        }
        .usuario-card > div {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 6px !important;
            font-size: 9.5px !important;
        }

        /* ── Badges de status ── */
        span[style*="border-radius"] {
            font-size: 8.5px !important;
            padding: 2px 5px !important;
        }

        /* ── Rodapé fixo ── */
        .rodape-pdf {
            position: fixed;
            bottom: 6mm; left: 12mm; right: 12mm;
            display: flex;
            justify-content: space-between;
            font-size: 8px;
            color: #bbb;
            border-top: 1px solid #e8e8e8;
            padding-top: 3px;
        }

        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .rodape-pdf { position: fixed; }
        }
    </style>
</head>
<body>
    <div class="cabecalho-pdf">
        <div class="logo-bloco">
            <div class="titulo-principal">Auditoria Conecta CRPRS</div>
            <div class="titulo-secao">Relatório: ${tituloTab}</div>
            <div class="subtitulo">Conselho Regional de Psicologia do Rio Grande do Sul</div>
        </div>
        <div class="data-bloco">
            <div><strong>Gerado em:</strong> ${dataAtual}</div>
        </div>
    </div>

    ${clone.innerHTML}

    <div class="rodape-pdf">
        <span>Auditoria Conecta CRPRS</span>
        <span>${dataAtual}</span>
    </div>

    <script>window.onload = function () { window.print(); };<\/script>
</body>
</html>`);
    janela.document.close();
}

function exportarLogEmailsCSV() {
    // Lê os filtros atuais do painel de e-mails
    const periodo = document.getElementById('email-log-periodo')?.value || 30;
    const status  = document.getElementById('email-log-status')?.value  || '';
    const tipo    = document.getElementById('email-log-tipo')?.value    || '';

    const params = new URLSearchParams({ pagina: 1, limite: 99999, periodo });
    if (status) params.append('status', status);
    if (tipo)   params.append('tipo', tipo);

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}email-logs.php?${params}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            try {
                const dados = JSON.parse(xhr.responseText);
                const logs = dados.logs || [];

                if (logs.length === 0) {
                    mostrarToast('Aviso', 'Nenhum registro encontrado para exportar.', 'warning');
                    return;
                }

                // Colunas do CSV
                const colunas = ['id', 'destinatario', 'assunto', 'tipo', 'ticket_id', 'status', 'erro', 'enviado_em'];
                const cabecalho = ['ID', 'Destinatário', 'Assunto', 'Tipo', 'Demanda', 'Status', 'Erro', 'Data/Hora'];

                let csv = cabecalho.join(',') + '\n';
                logs.forEach(log => {
                    const linha = colunas.map(col => {
                        const val = log[col] != null ? String(log[col]) : '';
                        return `"${val.replace(/"/g, '""')}"`;
                    });
                    csv += linha.join(',') + '\n';
                });

                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `email_logs_${new Date().toISOString().split('T')[0]}.csv`;
                link.click();

                mostrarToast('Sucesso', 'Arquivo CSV exportado com sucesso!', 'success');
            } catch (e) {
                mostrarToast('Erro', 'Falha ao processar dados para exportação.', 'error');
            }
        } else {
            mostrarToast('Erro', 'Falha ao buscar dados para exportação.', 'error');
        }
    };

    xhr.onerror = function () {
        mostrarToast('Erro', 'Erro de conexão ao exportar.', 'error');
    };

    xhr.send();
}

function baixarCSV(registros, filename) {
    if (registros.length === 0) {
        mostrarAlert('Aviso', 'Nenhum registro para exportar');
        return;
    }

    const headers = Object.keys(registros[0]);
    let csv = headers.join(',') + '\n';

    registros.forEach(reg => {
        const row = headers.map(h => {
            const val = reg[h] || '';
            return `"${String(val).replace(/"/g, '""')}"`;
        });
        csv += row.join(',') + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();

    mostrarToast('Sucesso', 'Arquivo CSV exportado com sucesso!', 'success');
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
        // Altura dinâmica: usa o tamanho real do conteúdo em vez de um valor fixo,
        // para não cortar botões quando o formulário cresce (ex: novos campos).
        setTimeout(() => form.style.maxHeight = form.scrollHeight + 'px', 10);
    }
}

// Toggle formulário de nova categoria
function toggleFormCategoria() {
    const form = document.getElementById('form-nova-categoria');
    const isVisible = form.style.display === 'block';

    if (isVisible) {
        form.style.maxHeight = '0';
        setTimeout(() => form.style.display = 'none', 300);
    } else {
        form.style.display = 'block';
        setTimeout(() => form.style.maxHeight = form.scrollHeight + 'px', 10);
    }
}


// Função para mostrar informações sobre os gráficos
function mostrarInfoGrafico(tipo) {
    const infos = {
        'total': {
            titulo: '🎫 Total de Demandas',
            descricao: 'Mostra o número total de demandas registradas no sistema.',
            analise: 'Use para acompanhar o volume geral de demandas. Um aumento pode indicar necessidade de mais recursos ou problemas recorrentes.'
        },
        'encerrados': {
            titulo: '✅ Demandas Encerradas',
            descricao: 'Total de demandas com status "encerrado".',
            analise: 'Indica o volume de demandas finalizadas. Use para acompanhar a produtividade da equipe ao longo do tempo.'
        },
        'ativos': {
            titulo: '🔄 Demandas Ativas',
            descricao: 'Total de demandas em andamento (todos os status exceto encerrado).',
            analise: 'Mostra a carga de trabalho atual da equipe. Valores altos podem indicar sobrecarga ou necessidade de mais recursos.'
        },
        'tempo-total': {
            titulo: '⏱️ Tempo Médio Total',
            descricao: 'Tempo médio desde a abertura até o fechamento das demandas.',
            analise: 'Indica a eficiência geral do atendimento. Valores altos podem indicar gargalos no processo ou demandas complexas.'
        },
        'primeiro-atendimento': {
            titulo: '⚡ Tempo Médio 1º Atendimento',
            descricao: 'Tempo médio até a primeira resposta da equipe à demanda.',
            analise: 'Métrica crítica de SLA. Valores altos indicam demora na resposta inicial, afetando a satisfação do usuário.'
        },
        'taxa-conclusao': {
            titulo: '✅ Taxa de Conclusão',
            descricao: 'Percentual de demandas concluídas em relação ao total.',
            analise: 'Indica a efetividade da equipe em resolver demandas. Valores baixos podem indicar demandas abandonadas ou mal gerenciadas.'
        },
        'fcr': {
            titulo: '🚀 FCR (First Contact Resolution)',
            descricao: 'Taxa de demandas resolvidas logo no primeiro contato/resposta, sem precisarem de interações adicionais ou vaivém com o solicitante.',
            analise: 'A principal métrica de eficiência do Suporte. Valores altos significam que a equipe é altamente assertiva e tem forte domínio sobre os problemas dos usuários logo de primeira.'
        },
        'status': {
            titulo: '📊 Demandas por Status',
            descricao: 'Distribuição das demandas pelos diferentes status do sistema.',
            analise: 'Identifique gargalos: muitas demandas "Abertas" indicam falta de atribuição, muitas "Aguardando" podem indicar dependências externas.'
        },
        'prioridade': {
            titulo: '🔥 Demandas por Prioridade',
            descricao: 'Distribuição das demandas por nível de prioridade (Urgente, Alta, Normal, Baixa).',
            analise: 'Verifique se há excesso de demandas urgentes (pode indicar problemas sistêmicos) ou se as prioridades estão bem distribuídas.'
        },
        'evolucao': {
            titulo: '📈 Evolução Mensal',
            descricao: 'Gráfico de linha mostrando a quantidade de demandas criadas por mês nos últimos 12 meses.',
            analise: 'Identifique tendências: crescimento constante, sazonalidade ou picos específicos que requerem atenção.'
        },
        'agente': {
            titulo: '🥧 Distribuição por Responsável',
            descricao: 'Gráfico de pizza mostrando a distribuição de demandas entre os responsáveis.',
            analise: 'Verifique o balanceamento da carga de trabalho. Desbalanceamento pode indicar necessidade de redistribuição ou treinamento.'
        },
        'resolucao': {
            titulo: '📊 Taxa de Resolução',
            descricao: 'Comparação entre demandas abertas e fechadas.',
            analise: 'Se há mais abertos que fechados, a equipe pode estar sobrecarregada ou há problemas de eficiência.'
        },
        'dia-semana': {
            titulo: '📅 Demandas por Dia da Semana',
            descricao: 'Distribuição de demandas criadas por dia da semana (Domingo a Sábado).',
            analise: 'Identifique padrões: dias com mais demanda podem requerer mais pessoas disponíveis ou plantões.'
        },
        'status-agente': {
            titulo: '📊 Status por Responsável',
            descricao: 'Gráfico de barras empilhadas mostrando a distribuição de status das demandas por responsável.',
            analise: 'Compare o desempenho dos responsáveis: quem tem mais demandas resolvidas, quem tem mais pendentes, etc.'
        },
        'sla-vencido': {
            titulo: 'SLA Vencido',
            descricao: 'Quantidade de demandas ativas cujo prazo de SLA j\u00e1 foi ultrapassado.',
            analise: 'Valores altos indicam que a equipe n\u00e3o est\u00e1 conseguindo atender dentro dos prazos acordados. Priorize essas demandas imediatamente e avalie se os prazos de SLA est\u00e3o adequados ao volume de demanda.'
        },
        'origem-externa': {
            titulo: 'Via Canal Externo',
            descricao: 'Demandas criadas automaticamente por integra\u00e7\u00e3o com canais externos, como e-mail recebido na caixa de suporte.',
            analise: 'Indica o volume de demandas que chegam por canais autom\u00e1ticos. Um volume alto pode justificar regras de triagem autom\u00e1tica ou templates de resposta r\u00e1pida.'
        },
        'diarios-30d': {
            titulo: 'Evolu\u00e7\u00e3o Di\u00e1ria \u2014 \u00daltimos 30 Dias',
            descricao: 'Gr\u00e1fico de linha mostrando a quantidade de demandas criadas por dia nos \u00faltimos 30 dias. O ponto verde marca o dia de maior volume.',
            analise: 'Identifique picos de demanda recentes. Picos isolados podem indicar incidentes pontuais; picos recorrentes em dias espec\u00edficos sugerem padr\u00f5es que podem ser antecipados.'
        },
        'status-mensal': {
            titulo: 'Status por M\u00eas',
            descricao: 'Barras empilhadas mostrando a distribui\u00e7\u00e3o de demandas por status em cada m\u00eas do ano atual.',
            analise: 'Acompanhe a evolu\u00e7\u00e3o da equipe ao longo do ano. Um aumento na fatia de demandas encerradas ao longo dos meses indica melhora na efici\u00eancia. Meses com muitas demandas em aberto merecem aten\u00e7\u00e3o.'
        },
        'heatmap': {
            titulo: 'Heatmap: Dia x Hora',
            descricao: 'Mapa de calor cruzando dia da semana com hora do dia, mostrando em quais momentos h\u00e1 maior concentra\u00e7\u00e3o de abertura de demandas.',
            analise: 'Use para dimensionar a equipe: hor\u00e1rios com c\u00e9lulas mais intensas (ciano) s\u00e3o os de maior demanda. Garanta que haja agentes dispon\u00edveis nesses per\u00edodos.'
        },
        'funil': {
            titulo: '🔄 Funil de Conversão',
            descricao: 'Visualiza a progressão das demandas através dos status: Aberto → Em Atendimento → Resolvido → Encerrado.',
            analise: 'Identifique gargalos: onde as demandas ficam acumuladas? Muitas "Abertas" indica falta de atribuição, muitas "Em Atendimento" pode indicar complexidade ou falta de recursos.'
        }
    };

    const info = infos[tipo];
    if (!info) return;

    const conteudo = `
        <div style="text-align: left;">
            <h4 style="margin-top: 0; color: var(--laranja);">${info.titulo}</h4>
            <p><strong>O que é:</strong><br>${info.descricao}</p>
            <p><strong>Como analisar:</strong><br>${info.analise}</p>
        </div>
    `;

    document.getElementById('modal-alert-title').textContent = 'Informações do Gráfico';
    document.getElementById('modal-alert-message').innerHTML = conteudo;

    const header = document.querySelector('#modal-alert .modal-header');
    const btnOk = document.querySelector('#modal-alert .btn-modal');

    header.classList.remove('sucesso', 'erro');
    btnOk.classList.remove('btn-success-modal', 'btn-danger-modal', 'btn-primary-modal');
    btnOk.classList.add('btn-primary-modal');

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-alert').classList.add('show');
    modalCallback = null;
}


// === PERSONALIZAÇÃO DO SISTEMA ===

function mostrarPersonalizacao() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_personalizacao = document.getElementById('btn-personalizacao'); if (_bbtn_personalizacao) _bbtn_personalizacao.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'personalizacao');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F3A8) + ' Personaliza\u00e7\u00e3o';

    // A personalização vale para o sistema inteiro: só o administrador altera
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const somenteLeitura = usuarioLogado.tipo !== 'admin';

    const content = document.getElementById('config-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
            <div class="form-usuario">
                ${somenteLeitura ? `
                <p class="aviso-somente-leitura" role="status">
                    <strong>Somente leitura:</strong> a personaliza&ccedil;&atilde;o vale para todos os setores e s&oacute; pode ser alterada por administradores.
                </p>` : `
                <p class="aviso-personalizacao">
                    <strong>Importante:</strong> Se as altera&ccedil;&otilde;es n&atilde;o aparecerem imediatamente, atualize a p&aacute;gina (F5 ou Ctrl+R).
                </p>`}

                <div class="personalizacao-bloco">
                    <h4>Logo do Sistema <small class="personalizacao-dica">(PNG, 40x40px)</small></h4>
                    <p class="personalizacao-descricao">
                        Aplicado em: <strong>Cabe&ccedil;alho</strong>, <strong>Favicon</strong>, <strong>Loader</strong> e <strong>Modais de Recupera&ccedil;&atilde;o de Senha</strong>.
                    </p>
                    <div class="personalizacao-upload">
                        <label for="logo-header" class="sr-only">Arquivo PNG do logo</label>
                        <input type="file" id="logo-header" accept="image/png" ${somenteLeitura ? 'disabled' : ''}>
                        <button class="btn-primary" onclick="uploadLogoHeader()" ${somenteLeitura ? 'disabled' : ''}>Upload Logo</button>
                    </div>
                </div>
            </div>
        `;
    }, 10);
}

function uploadLogoHeader() {
    const input = document.getElementById('logo-header');
    if (!input.files[0]) {
        mostrarAlert('Erro', 'Selecione um arquivo PNG');
        return;
    }

    const formData = new FormData();
    formData.append('logo', input.files[0]);
    formData.append('tipo', 'header');

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'personalizacao.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Logo do header atualizado!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao fazer upload');
        }
    };

    xhr.send(formData);
}

function uploadLogoLogin() {
    const input = document.getElementById('logo-login');
    if (!input.files[0]) {
        mostrarAlert('Erro', 'Selecione um arquivo PNG');
        return;
    }

    const formData = new FormData();
    formData.append('logo', input.files[0]);
    formData.append('tipo', 'login');

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'personalizacao.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Logo de login atualizado!', 'success');
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao fazer upload');
        }
    };

    xhr.send(formData);
}

// === TUTORIAL INTERATIVO ===
function mostrarTutorialAjuda() { reiniciarTutorial(); }

// Função iniciarTutorial está em tutorial.js

// === DICAS DO SISTEMA ===
function mostrarDicasAjuda() { mostrarDicas(); }

// === MODO ESCURO (CONFIGURAÇÕES) ===
function mostrarModoEscuro() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    document.getElementById('btn-modo-escuro-config').classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'modo-escuro');

    const content = document.getElementById('config-content');
    const isDark = document.body.classList.contains('modo-escuro');

    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="form-usuario">
            <h3>🌙 Modo Escuro</h3>
            <p>Ative o modo escuro para reduzir o cansaço visual em ambientes com pouca luz.</p>
            <div style="margin-top: 1.5rem;">
                <label class="switch-label" style="font-size: 1.1rem;">
                    <label class="switch">
                        <input type="checkbox" id="toggle-modo-escuro" ${isDark ? 'checked' : ''} onchange="toggleModoEscuro()">
                        <span class="slider"></span>
                    </label>
                    <span id="label-modo-escuro">${isDark ? 'Desativar Modo Escuro' : 'Ativar Modo Escuro'}</span>
                </label>
            </div>
        </div>
    `;
    }, 10);
}

// === CONFIGURAÇÃO DE EMAIL ===
function mostrarConfigEmail() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_config_email = document.getElementById('btn-config-email'); if (_bbtn_config_email) _bbtn_config_email.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'config-email');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F4E7) + ' Configurar Email';

    const content = document.getElementById('config-content');

    // Setor em contexto (escolhido pelo admin no cabeçalho)
    const setorEmailId = window.CabecalhoSetor ? window.CabecalhoSetor.obterSetorContexto() : null;
    const seletorCab = document.getElementById('seletor-setor');
    const setorEmailNome = (seletorCab && seletorCab.value) ? seletorCab.options[seletorCab.selectedIndex].textContent : '';

    const secaoSetor = setorEmailId ? `
        <div class="config-email-setor">
            <h3>🏢 E-mail do setor: ${setorEmailNome}</h3>
            <div id="config-setor-lista"><p>Carregando...</p></div>
        </div>` : `
        <div class="config-email-setor">
            <h3>🏢 E-mail do setor</h3>
            <p style="color:#666;">Selecione um setor no cabeçalho para configurar o endereço de e-mail dele.</p>
        </div>`;

    content.innerHTML = `
        ${secaoSetor}
        <div class="config-email-container">
            <h3>📧 Remetente do sistema (global)</h3>
            <p style="color: #666; margin-bottom: 2rem;">Usado nos e-mails do sistema (recuperação de senha, lembretes, avisos internos) e como alternativa para setores sem endereço próprio.</p>
            
            <p class="config-email-aviso">Somente leitura: estes dados ficam no arquivo <code>.env</code> do servidor e são alterados pelo responsável técnico.</p>
            <dl class="config-email-global">
                <dt>Servidor IMAP (recebimento)</dt><dd id="cfg-imap">—</dd>
                <dt>Usuário IMAP</dt><dd id="cfg-imap-usuario">—</dd>
                <dt>Servidor SMTP (envio)</dt><dd id="cfg-smtp">—</dd>
                <dt>Usuário SMTP</dt><dd id="cfg-smtp-usuario">—</dd>
                <dt>Remetente</dt><dd id="cfg-remetente">—</dd>
            </dl>
            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="button" onclick="testarConexaoEmail()" class="btn-secondary">🔍 Testar Conexão</button>
            </div>
        </div>
    `;

    carregarConfigEmail();

    if (setorEmailId) {
        setorConfigAtual = setorEmailId;
        carregarEmailsSetor();
    }
}

function carregarConfigEmail() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'config-email.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status !== 200) return;
        const config = JSON.parse(xhr.responseText);
        const preencher = (id, texto) => { const el = document.getElementById(id); if (el) el.textContent = texto || '—'; };
        const senha = (definida) => definida ? ' (senha definida)' : ' (senha NÃO definida)';
        preencher('cfg-imap', config.imap_host ? `${config.imap_host}:${config.imap_port}` : '');
        preencher('cfg-imap-usuario', config.imap_username ? config.imap_username + senha(config.imap_senha_definida) : '');
        preencher('cfg-smtp', config.smtp_host ? `${config.smtp_host}:${config.smtp_port}` : '');
        preencher('cfg-smtp-usuario', config.smtp_username ? config.smtp_username + senha(config.smtp_senha_definida) : '');
        preencher('cfg-remetente', config.from_email ? `${config.from_name || ''} <${config.from_email}>` : '');
    };

    xhr.send();
}

function testarConexaoEmail() {
    // Testa o remetente global configurado no .env (nenhum dado é enviado pelo navegador)
    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'testar-email.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            const resultado = JSON.parse(xhr.responseText);

            let mensagem = '<div style="text-align: left;">';

            if (resultado.imap.sucesso) {
                mensagem += '<p style="color: #28a745;">✅ <strong>IMAP:</strong> ' + sanitizeHTML(resultado.imap.mensagem) + '</p>';
            } else {
                mensagem += '<p style="color: #dc3545;">❌ <strong>IMAP:</strong> ' + sanitizeHTML(resultado.imap.mensagem) + '</p>';
            }

            if (resultado.smtp.sucesso) {
                mensagem += '<p style="color: #28a745;">✅ <strong>SMTP:</strong> ' + sanitizeHTML(resultado.smtp.mensagem) + '</p>';
            } else {
                mensagem += '<p style="color: #dc3545;">❌ <strong>SMTP:</strong> ' + sanitizeHTML(resultado.smtp.mensagem) + '</p>';
            }

            mensagem += '</div>';

            const titulo = (resultado.imap.sucesso && resultado.smtp.sucesso) ? 'Teste Bem-Sucedido' : 'Teste com Falhas';
            mostrarAlert(titulo, mensagem);
        } else {
            mostrarAlert('Erro', 'Erro ao testar conexão');
        }
    };

    xhr.onerror = function () {
        ocultarLoader();
        mostrarAlert('Erro', 'Erro de conexão ao testar');
    };

    xhr.send("{}");
}










// Função para enviar comentário e fechar ticket
function enviarEFecharTicket(ticketId) {
    // Sincroniza o Quill com o input hidden antes de ler o valor
    if (window.quillComentario) {
        document.getElementById('novo-comentario').value = window.quillComentario.root.innerHTML;
        if (window.quillComentario.getText().trim().length === 0) {
            document.getElementById('novo-comentario').value = '';
        }
    }
    const comentario = document.getElementById('novo-comentario').value.trim();
    if (!comentario || comentario.replace(/<[^>]*>/g, '').trim().length < 3) {
        mostrarAlert('Atenção', 'Por favor, adicione um comentário antes de fechar a demanda.');
        return;
    }
    mostrarConfirm('Enviar e Fechar Demanda', 'Deseja adicionar o comentário e encerrar esta demanda?', function (confirmado) {
        if (!confirmado) return;
        mostrarLoader();
        const formData = new FormData();
        formData.append('comentario', comentario);
        formData.append('ticket_id', ticketId);
        const enviarEmail = document.getElementById('enviar-email').checked;
        formData.append('enviar_email', enviarEmail ? '1' : '0');
        if (enviarEmail) {
            const comCopia = document.getElementById('com-copia').value;
            if (comCopia) formData.append('com_copia', comCopia);
        }
        // Usar o array acumulado (o input é limpo após validação, então .files estaria vazio)
        if (anexosComentarioSelecionados.length > 0) {
            anexosComentarioSelecionados.forEach(arquivo => {
                formData.append('anexos[]', arquivo);
            });
        }
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_CONFIG.apiBase + 'comentarios.php', true);
        setSecurityHeaders(xhr);
        xhr.onload = function () {
            if (xhr.status === 200) {
                const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario'));
                executarAlteracaoStatus(ticketId, 'encerrado', function () {
                    ocultarLoader();
                    mostrarToast('Sucesso', 'Comentário adicionado e demanda encerrada!', 'success');
                    setTimeout(() => {
                        if (secaoOrigem === 'fechados') {
                            mostrarTicketsFechados();
                        } else {
                            mostrarTicketsComAtualizacao();
                        }
                    }, 500);
                });
            } else {
                ocultarLoader();
                mostrarAlert('Erro', 'Erro ao adicionar comentário');
            }
        };
        xhr.onerror = function () {
            ocultarLoader();
            mostrarAlert('Erro', 'Erro de conexão ao adicionar comentário');
        };
        xhr.send(formData);
    }, true);
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
