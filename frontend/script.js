// Função para sanitizar HTML
function sanitizeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Função de logout
function fazerLogout() {
    localStorage.removeItem('usuario');
    localStorage.removeItem('token');
    localStorage.removeItem('token_exp');
    window.location.href = './login.html';
}

// Monitoramento de Sessão e Inatividade
let tempoInatividade;
const TEMPO_LIMITE_INATIVIDADE = 30 * 60 * 1000; // 30 minutos

function iniciarMonitoramentoSessao() {
    // 1. Monitorar expiração do Token (8h)
    verificarExpiracaoToken();
    setInterval(verificarExpiracaoToken, 60000); // Checar a cada minuto

    // 2. Monitorar Inatividade do Usuário (30min)
    window.onload = resetarTimerInatividade;
    document.onmousemove = resetarTimerInatividade;
    document.onkeydown = resetarTimerInatividade;
    document.onclick = resetarTimerInatividade;
    document.onscroll = resetarTimerInatividade;

    // Iniciar timer
    resetarTimerInatividade();
}

function resetarTimerInatividade() {
    clearTimeout(tempoInatividade);
    tempoInatividade = setTimeout(() => {
        mostrarAlert('Sessão Expirada', 'Você ficou 30 minutos inativo. Por segurança, sua sessão foi encerrada.', () => {
            fazerLogout();
        });
    }, TEMPO_LIMITE_INATIVIDADE);
}

function verificarExpiracaoToken() {
    const token = localStorage.getItem('token');
    if (!token) return;

    try {
        const payload = JSON.parse(atob(token.split('.')[1]));
        const agora = Math.floor(Date.now() / 1000);

        if (payload.exp < agora) {
            mostrarAlert('Sessão Expirada', 'Seu token de acesso expirou. Por favor, faça login novamente.', () => {
                fazerLogout();
            });
        }
    } catch (e) {
        // Token inválido
        fazerLogout();
    }
}

// ======================================================
// 📧 ALERTA DE FALHAS DE E-MAIL
// ======================================================
const EMAIL_ALERT_INTERVAL = 5 * 60 * 1000; // Checar a cada 5 minutos
const EMAIL_ALERT_DISMISSED = 'emailAlertDismissedAt';

function iniciarMonitoramentoEmailFalhas() {
    // Só monitora se for agente ou admin
    const usuario = JSON.parse(localStorage.getItem('usuario') || '{}');
    if (!['agente', 'admin'].includes(usuario.tipo)) return;

    verificarFalhasEmail(); // verificação imediata
    setInterval(verificarFalhasEmail, EMAIL_ALERT_INTERVAL);
}

function verificarFalhasEmail() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/email-alert.php', true);
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
    const usuario = JSON.parse(localStorage.getItem('usuario') || '{}');
    if (!usuario.nome) return;

    const nome = usuario.nome || '';
    const email = usuario.email || '';
    const tipo = usuario.tipo || '';

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
    setEl('header-avatar-role', tipo === 'admin' ? 'Administrador' : tipo === 'agente' ? 'Agente' : 'Usuário');
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
    document.querySelectorAll('.secao').forEach(secao => {
        secao.classList.remove('active');
    });

    document.querySelectorAll('.btn-nav').forEach(btn => {
        btn.classList.remove('active');
    });

    document.getElementById(secaoId).classList.add('active');

    // Salvar seção ativa no localStorage
    localStorage.setItem('secaoAtiva', secaoId);

    // Ativar botão correspondente
    const botoes = document.querySelectorAll('.btn-nav');
    if (secaoId === 'tickets') {
        botoes[0].classList.add('active');
    } else if (secaoId === 'fechados') {
        // Botão fechados não está mais no header
    } else if (secaoId === 'novo') {
        // Botão novo não está mais no header
        // Reconfigurar pesquisa de categoria após mostrar seção
        setTimeout(() => {
            if (todasCategorias.length > 0) {
                configurarPesquisaCategoria();
            }
        }, 200);
    } else if (secaoId === 'dashboard') {
        botoes[1].classList.add('active');
    } else if (secaoId === 'relatorios') {
        botoes[2].classList.add('active');
    } else if (secaoId === 'ajuda') {
        botoes[3].classList.add('active');
    } else if (secaoId === 'config') {
        // Verificar se é admin
        const usuario = JSON.parse(localStorage.getItem('usuario'));
        if (usuario.tipo !== 'admin') {
            mostrarAlert('Acesso Negado', 'Apenas administradores podem acessar as configurações.', function () {
                mostrarSecao('tickets');
            });
            return;
        }
        botoes[4].classList.add('active');

        // Carregar subseção padrão se não houver uma salva
        const subSecaoSalva = localStorage.getItem('subSecaoAtiva');
        if (!subSecaoSalva) {
            setTimeout(() => mostrarUsuarios(), 100);
        }
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
    const token = localStorage.getItem('token');
    console.log('🔐 setSecurityHeaders - Token:', token ? 'EXISTS' : 'NOT FOUND');
    if (token) {
        xhr.setRequestHeader('Authorization', `Bearer ${token}`);
        console.log('✅ Authorization header set');
    } else {
        console.error('❌ Token not found in localStorage');
    }
    xhr.setRequestHeader('X-CSRF-Token', 'helpdesk-csrf-token');
}

// Formulário de novo ticket - VERSÃO COM XMLHttpRequest
document.getElementById('form-ticket').addEventListener('submit', function (e) {
    e.preventDefault();

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
    xhr.open('POST', '/crpdesk/api/controllers/tickets.php', true);
    xhr.timeout = 30000; // 30 segundos

    // Configurar headers de segurança
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                limparFormularioTicket();
                mostrarToast('Sucesso', 'Ticket criado com sucesso!', 'success');
            } catch (e) {
                console.error('Erro ao parsear resposta:', e);
                mostrarAlert('Erro', 'Erro na resposta do servidor');
            }
        } else {
            try {
                const errorResponse = JSON.parse(xhr.responseText);
                mostrarAlert('Erro', errorResponse.error || 'Erro ao criar ticket');
            } catch (e) {
                mostrarAlert('Erro', 'Erro ao criar ticket');
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
function carregarTickets() {
    mostrarSkeletonTickets();
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/tickets.php', true);

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
            document.getElementById('lista-tickets').innerHTML = '<p>Erro ao carregar tickets.</p>';
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
let porPagina = parseInt(localStorage.getItem('ticketsPorPagina') || '50');
let filtroStatusAtivo = 'todos';
let ticketsFiltradosPorStatus = [];

function aplicarFiltroMeusTickets() {
    const filtroAtivo = document.getElementById('filtro-meus-tickets').checked;
    let ticketsFiltrados = ticketsAtuais;

    if (filtroAtivo) {
        const usuarioLogado = JSON.parse(localStorage.getItem('usuario'));
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
        const usuarioLogado = JSON.parse(localStorage.getItem('usuario'));
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
        <table class="skeleton-tabela" aria-hidden="true" aria-label="Carregando tickets...">
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
            const u = JSON.parse(localStorage.getItem('usuario'));
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


// Mostrar tickets na tela
function mostrarTicketsNaTela(tickets) {
    const listaTickets = document.getElementById('lista-tickets');

    // Atualizar contadores
    const usuarioLogado = JSON.parse(localStorage.getItem('usuario'));
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
                <p style="margin-top:0.5rem; font-size:0.95rem;">Nenhum ticket encontrado para este filtro.</p>
            </div>`;
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
        <table class="tickets-tabela" role="grid" aria-label="Lista de tickets abertos">
            <thead>
                <tr>
                    <th onclick="ordenarTabela('id')" class="sortable" data-campo="id" scope="col">Nº Ticket <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('criado_em')" class="sortable" data-campo="criado_em" scope="col">Abertura <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('titulo')" class="sortable" data-campo="titulo" scope="col">Assunto <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('usuario_nome')" class="sortable" data-campo="usuario_nome" scope="col">Solicitante <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('agente_nome')" class="sortable" data-campo="agente_nome" scope="col">Responsável <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabela('categoria_nome')" class="sortable" data-campo="categoria_nome" scope="col">Categoria <span class="sort-arrows">↕</span></th>
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
            ? `<span class="material-symbols-outlined icone-tabela icone-anexo-ms" title="Ticket com anexos" aria-label="Tem anexos">attach_file</span>` : '';
        const iconeComentario = ticket.tem_comentario_nao_lido
            ? `<span class="material-symbols-outlined icone-tabela icone-comentario-ms" title="Novo comentário no ticket" aria-label="Novo comentário">chat_bubble</span>` : '';
        const iconomeLembrete = ticket.proximo_lembrete
            ? `<span class="material-symbols-outlined icone-tabela icone-lembrete-ms" title="Lembrete: ${new Date(ticket.proximo_lembrete).toLocaleString('pt-BR')}" aria-label="Tem lembrete">event</span>` : '';
        const iconeMesclado = ticket.eh_mesclado > 0
            ? `<span class="material-symbols-outlined icone-tabela" title="Ticket mesclado" aria-label="Mesclado" style="color:#6366f1">call_merge</span>` : '';

        // SLA badge inline
        const slaInfo = calcularTextoSLA(ticket);
        const slaBadge = slaInfo
            ? `<span class="sla-badge ${slaInfo.classe}" role="status" aria-label="SLA: ${slaInfo.texto}">⏱ ${slaInfo.texto}</span>` : '';

        // Data formatada sem cor hardcoded
        const dataObj = new Date(ticket.criado_em);
        const data = dataObj.toLocaleDateString('pt-BR');
        const hora = dataObj.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

        // Solicitante
        const solicitante = ticket.email_solicitante || ticket.usuario_nome || '—';
        const emailExibir = (ticket.email_solicitante && ticket.email_solicitante !== ticket.usuario_email)
            ? '' : (ticket.usuario_email ? `<small class="ticket-data-hora">${ticket.usuario_email}</small>` : '');

        // Título truncado
        const tituloSeguro = (ticket.titulo || '').replace(/"/g, '&quot;').replace(/</g, '&lt;');

        tabelaHTML += `
            <tr data-ticket-id="${ticket.id}" class="${isNovaResposta ? 'ticket-nova-resposta' : ''}" ${modoMergeAtivo ? 'style="cursor:pointer;"' : ''}>
                <td>
                    <a href="#" onclick="${modoMergeAtivo ? 'event.stopPropagation();' : ''} verDetalhes(${ticket.id})" class="ticket-link">#${String(ticket.id).padStart(5, '0')}</a>
                    ${iconeMesclado}${iconeAnexo}${iconeComentario}${iconomeLembrete}
                </td>
                <td>
                    ${data}<span class="ticket-data-hora">${hora}</span>
                    ${slaBadge}
                </td>
                <td class="ticket-titulo-cell">
                    <span class="ticket-titulo-texto" title="${tituloSeguro}">${ticket.titulo}</span>
                </td>
                <td>${solicitante}${emailExibir}</td>
                <td><span class="status ${ticket.agente_nome ? 'atribuido' : 'nao-atribuido'} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); atribuirTicket(${ticket.id})" title="Clique para atribuir"`}>${ticket.agente_nome || 'Não atribuído'}</span></td>
                <td><span class="status categoria ${ticket.categoria_nome ? '' : 'sem-categoria'} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); alterarCategoria(${ticket.id})" title="Clique para alterar"`}>${ticket.categoria_nome || 'Sem categoria'}</span></td>
                <td><span class="status ${ticket.status} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); alterarStatusNovo(${ticket.id})"`} title="Clique para alterar">${window.STATUS_CONFIG && window.STATUS_CONFIG[ticket.status] ? window.STATUS_CONFIG[ticket.status].nome : (typeof formatarNomeStatus === 'function' ? formatarNomeStatus(ticket.status) : ticket.status)}</span></td>
                <td><span class="status prioridade ${ticket.prioridade || 'normal'} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); alterarPrioridade(${ticket.id})" title="Clique para alterar"`}>${typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(ticket.prioridade) : (ticket.prioridade ? ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1).toLowerCase() : 'Normal')}</span></td>
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
        <div class="tabela-footer" role="navigation" aria-label="Paginação de tickets">
            <div class="tabela-resumo">
                Exibindo <strong>${inicioExib}–${fimExib}</strong> de <strong>${totalExib}</strong> ticket${totalExib !== 1 ? 's' : ''}
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
    localStorage.setItem('ticketsPorPagina', porPagina);
    paginaAtual = 1;
    mostrarTicketsNaTela(ticketsFiltradosPorStatus.length > 0 ? ticketsFiltradosPorStatus : ticketsAtuais);
}

// Atribuir ticket a agente
function atribuirTicket(ticketId) {
    // Carregar lista de agentes
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/agentes.php', true);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const agentes = JSON.parse(xhr.responseText);

            mostrarSelect(
                'Atribuir Ticket',
                'Selecione o agente responsável:',
                agentes,
                function (agenteEscolhido) {
                    if (agenteEscolhido) {
                        confirmarAtribuicao(ticketId, agenteEscolhido.id, agenteEscolhido.nome);
                    }
                }
            );
        } else {
            mostrarAlert('Erro', 'Erro ao carregar agentes');
        }
    };

    xhr.send();
}

// Confirmar atribuição do ticket
function confirmarAtribuicao(ticketId, agenteId, agenteNome) {
    mostrarConfirm(
        'Confirmar Atribuição',
        `Atribuir ticket para ${agenteNome}?`,
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
    const usuario = JSON.parse(localStorage.getItem('usuario'));
    const dados = {
        agente_id: agenteId,
        atribuido_por: usuario ? usuario.id : 1
    };

    // NÃO enviar status - o backend gerencia automaticamente
    // Se ticket está "novo", backend muda para "em_atendimento"

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/crpdesk/api/controllers/tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            // Recarregar lista de tickets
            carregarTickets();

            // Se estiver na página de detalhes, recarregar detalhes
            const ticketAtivo = localStorage.getItem('ticketAtivo');
            if (ticketAtivo && parseInt(ticketAtivo) === ticketId) {
                setTimeout(() => verDetalhes(ticketId), 300);
            }

            const mensagem = dados.status ?
                `Ticket atribuído para ${agenteNome} e status alterado para "Em atendimento"!` :
                `Ticket atribuído para ${agenteNome} com sucesso!`;

            mostrarToast('Sucesso', mensagem, 'success');
        } else {
            ocultarLoader();
            mostrarAlert('Erro', 'Erro ao atribuir ticket');
        }
    };

    xhr.send(JSON.stringify(dados));
}

// Alterar status do ticket
function alterarStatus(ticketId) {
    const statusOpcoes = [
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

    mostrarSelect(
        'Alterar Status',
        'Selecione o novo status do ticket:',
        statusOpcoes,
        function (statusEscolhido) {
            if (statusEscolhido) {
                executarAlteracaoStatus(ticketId, statusEscolhido.id);
            }
        }
    );
}

// Alterar categoria do ticket
function alterarCategoria(ticketId) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/categorias.php', true);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const categorias = JSON.parse(xhr.responseText);

            mostrarSelect(
                'Alterar Categoria',
                'Selecione a nova categoria do ticket:',
                categorias,
                function (categoriaEscolhida) {
                    if (categoriaEscolhida) {
                        executarAlteracaoCategoria(ticketId, categoriaEscolhida.id);
                    }
                }
            );
        } else {
            mostrarAlert('Erro', 'Erro ao carregar categorias');
        }
    };

    xhr.send();
}

// Executar alteração de categoria
function executarAlteracaoCategoria(ticketId, categoriaId) {
    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/crpdesk/api/controllers/tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            // Recarregar lista de tickets
            carregarTickets();

            // Se estiver na página de detalhes, recarregar detalhes
            const ticketAtivo = localStorage.getItem('ticketAtivo');
            if (ticketAtivo && parseInt(ticketAtivo) === ticketId) {
                setTimeout(() => verDetalhes(ticketId), 300);
            }

            mostrarToast('Sucesso', 'Categoria alterada com sucesso!', 'success');
        } else {
            ocultarLoader();
            mostrarAlert('Erro', 'Erro ao alterar categoria: ' + xhr.responseText);
        }
    };

    xhr.onerror = function () {
        mostrarAlert('Erro', 'Erro de conexão ao alterar categoria');
    };

    xhr.send(JSON.stringify({ categoria_id: categoriaId }));
}

// Alterar prioridade do ticket
function alterarPrioridade(ticketId) {
    console.log('🔄 alterarPrioridade chamada para ticket:', ticketId);

    const prioridadeOpcoes = [
        { id: 'baixa', nome: 'Baixa' },
        { id: 'normal', nome: 'Normal' },
        { id: 'alta', nome: 'Alta' },
        { id: 'urgente', nome: 'Urgente' }
    ];

    console.log('📋 Opções de prioridade:', prioridadeOpcoes);

    mostrarSelect(
        'Alterar Prioridade',
        'Selecione a nova prioridade do ticket:',
        prioridadeOpcoes,
        function (prioridadeEscolhida) {
            console.log('🎯 Prioridade escolhida:', prioridadeEscolhida);
            if (prioridadeEscolhida) {
                executarAlteracaoPrioridade(ticketId, prioridadeEscolhida.id);
            }
        }
    );
}

// Executar alteração de prioridade
function executarAlteracaoPrioridade(ticketId, novaPrioridade) {
    console.log('🔄 Alterando prioridade:', { ticketId, novaPrioridade });
    console.log('📍 Chamando mostrarLoader...');
    mostrarLoader();
    console.log('✅ mostrarLoader chamado');

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/crpdesk/api/controllers/tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        console.log('📡 Resposta do servidor:', xhr.status, xhr.responseText);
        if (xhr.status === 200) {
            // Recarregar lista de tickets
            carregarTickets();

            // Se estiver na página de detalhes, recarregar detalhes
            const ticketAtivo = localStorage.getItem('ticketAtivo');
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

    const usuario = JSON.parse(localStorage.getItem('usuario'));

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/crpdesk/api/controllers/tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    const token = localStorage.getItem('token');
    if (token) {
        xhr.setRequestHeader('Authorization', `Bearer ${token}`);
    }

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            const mensagem = (novoStatus === 'aberto' || novoStatus === 'atendimento') ? 'Ticket reaberto com sucesso!' : 'Status alterado com sucesso!';

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
            const ticketAtivo = localStorage.getItem('ticketAtivo');
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
                mostrarAlert('Atenção', erro.error || 'Erro ao atualizar ticket');
            } catch (e) {
                mostrarAlert('Erro', 'Erro ao atualizar ticket');
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
        localStorage.setItem('secaoOrigem', secaoOrigem);
    }

    // Salvar ticket ativo
    localStorage.setItem('ticketAtivo', ticketId);
    localStorage.setItem('secaoAtiva', 'detalhes');

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `/crpdesk/api/controllers/tickets.php?id=${ticketId}`, true);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const ticket = JSON.parse(xhr.responseText);
            // Salvar dados do ticket para saber se é fechado
            localStorage.setItem('ticketAtual', JSON.stringify(ticket));
            mostrarSecao('detalhes');
            setTimeout(() => {
                mostrarDetalhesTicket(ticket);
                carregarLembretes(ticketId);
            }, 100);
        } else {
            console.error('Erro ao carregar detalhes:', xhr.status, xhr.responseText);
            mostrarAlert('Erro', 'Erro ao carregar detalhes do ticket');
        }
    };

    xhr.onerror = function () {
        console.error('Erro de conexão ao carregar detalhes');
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send();
}

function marcarComentariosLidos(ticketId) {
    const usuario = JSON.parse(localStorage.getItem('usuario'));
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/crpdesk/api/controllers/comentarios.php?marcar_lido=1&ticket_id=${ticketId}&usuario_id=${usuario.id}`);
    xhr.send();
}

// Formatar ações do histórico
function formatarAcaoHistorico(acao, valorAnterior, valorNovo) {
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
        case 'status':
            const statusAnt = formatarStatus(valorAnterior);
            const statusNovo = formatarStatus(valorNovo);
            return `alterou o status de <strong>${statusAnt}</strong> para <strong>${statusNovo}</strong>`;
        case 'prioridade':
            const priorAnt = formatarPrioridade(valorAnterior);
            const priorNova = formatarPrioridade(valorNovo);
            return `alterou a prioridade de <strong>${priorAnt}</strong> para <strong>${priorNova}</strong>`;
        case 'categoria':
            const catAnt = valorAnterior || 'Sem categoria';
            const catNova = valorNovo || 'Sem categoria';
            return `alterou a categoria de <strong>${catAnt}</strong> para <strong>${catNova}</strong>`;
        case 'atribuicao':
            const agenteAnt = valorAnterior && valorAnterior !== 'Não atribuído' ? valorAnterior : null;
            const agenteNovo = valorNovo || 'Não atribuído';
            if (agenteAnt) {
                return `reatribuiu o ticket de <strong>${agenteAnt}</strong> para <strong>${agenteNovo}</strong>`;
            } else {
                return `atribuiu o ticket para <strong>${agenteNovo}</strong>`;
            }
        case 'merge':
            return `mesclou os tickets <strong>#${valorNovo}</strong> neste ticket`;
        default:
            return `realizou a ação: <strong>${acao}</strong>`;
    }
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
                            <strong>💬 ${item.autor_nome}</strong> - ${new Date(item.criado_em).toLocaleString('pt-BR')}<br>
                            <strong style="color: #666; font-size: 0.9em;">Comentário:</strong>
                        </div>
                        <div class="comentario">
                            ${item.comentario}
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
                                    <strong>${nomeExibir}</strong><br>
                                    <small style="color: #666;">${tamanhoMB}MB</small>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    ${isPDF || isImage ? `<button class="btn-visualizar" onclick="visualizarAnexo(${anexo.id}, '${tipo}')">👁️ Visualizar</button>` : ''}
                                    <a href="../api/controllers/anexos.php?id=${anexo.id}&action=download" class="btn-small" style="text-decoration: none;">⬇ Download</a>
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
                    acao = '📧 ' + item.valor_novo;
                } else if (item.valores_formatados) {
                    // Usar valores já formatados do backend
                    const partes = item.valores_formatados.split(' → ');
                    if (item.acao === 'categoria') {
                        acao = `alterou a categoria de <strong>${partes[0]}</strong> para <strong>${partes[1]}</strong>`;
                    } else if (item.acao === 'atribuicao') {
                        if (partes[0] === 'Não atribuído') {
                            acao = `atribuiu o ticket para <strong>${partes[1]}</strong>`;
                        } else {
                            acao = `reatribuiu o ticket de <strong>${partes[0]}</strong> para <strong>${partes[1]}</strong>`;
                        }
                    }
                } else {
                    acao = formatarAcaoHistorico(item.acao, item.valor_anterior, item.valor_novo);
                }

                comentariosHtml += `
                    <div class="historico-wrapper">
                        <div class="historico-info">
                            <strong>📅 ${item.autor_nome || 'Sistema'}</strong> - ${new Date(item.criado_em).toLocaleString('pt-BR')}
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

    const usuario = JSON.parse(localStorage.getItem('usuario'));
    const isAdmin = usuario && usuario.tipo === 'admin';

    detalhesDiv.innerHTML = `
        <div style="margin-top: 100px; margin-bottom: 2rem; padding: 1rem 0; position: relative; z-index: 999;">
            <button class="btn-voltar" onclick="voltarParaOrigem()">← Voltar</button>
            ${ticket.status === 'encerrado' || ticket.status === 'fechado' ?
            `<button class="btn-reabrir" onclick="reabrirTicket(${ticket.id})" style="margin-left: 1rem; background-color: #28a745;">🔄 Reabrir</button>` :
            ''
        }
            <button class="btn-excluir" onclick="excluirTicket(${ticket.id})" style="margin-left: 1rem; background-color: var(--vermelho);">🗑️ Excluir</button>
        </div>
        
        <div class="ticket-detalhes">
            <div class="ticket-header">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <span class="ticket-id" style="font-size: 1.5rem;">#${String(ticket.id).padStart(5, '0')}</span>
                    ${ticket.eh_mesclado > 0 ? '<span title="Ticket mesclado" style="font-size: 1.5rem;">🔗</span>' : ''}
                    ${ticket.status === 'fechado' ? '<span class="carimbo-fechado">FECHADO</span>' : ''}
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <span class="status ${ticket.agente_nome ? 'atribuido' : 'nao-atribuido'} ${ticket.status === 'fechado' ? 'disabled' : 'clickable'}" ${ticket.status === 'fechado' ? '' : `onclick="atribuirTicket(${ticket.id})" title="Clique para atribuir"`}>
                        ${ticket.agente_nome || 'Não atribuído'}
                    </span>
                    <span class="status categoria ${ticket.categoria_nome ? '' : 'sem-categoria'} ${ticket.status === 'fechado' ? 'disabled' : 'clickable'}" ${ticket.status === 'fechado' ? '' : `onclick="alterarCategoria(${ticket.id})" title="Clique para alterar"`}>
                        ${ticket.categoria_nome || 'Sem categoria'}
                    </span>
                    <span class="status ${ticket.status || 'aberto'} ${ticket.status === 'encerrado' ? 'disabled' : 'clickable'}" ${ticket.status === 'encerrado' ? '' : `onclick="alterarStatus(${ticket.id})" title="Clique para alterar"`}>
                        ${typeof formatarNomeStatus === 'function' ? formatarNomeStatus(ticket.status || 'aberto') : (window.STATUS_CONFIG && window.STATUS_CONFIG[ticket.status || 'aberto'] ? window.STATUS_CONFIG[ticket.status || 'aberto'].nome : (ticket.status || 'aberto').charAt(0).toUpperCase() + (ticket.status || 'aberto').slice(1).toLowerCase())}
                    </span>
                    <span class="status prioridade ${ticket.prioridade || 'normal'} ${ticket.status === 'encerrado' ? 'disabled' : 'clickable'}" ${ticket.status === 'encerrado' ? '' : `onclick="alterarPrioridade(${ticket.id})" title="Clique para alterar"`}>
                        ${typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(ticket.prioridade) : (ticket.prioridade ? ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1).toLowerCase() : 'Normal')}
                    </span>
                </div>
            </div>
            <h2>${ticket.titulo}</h2>
            <div><strong>Descrição:</strong></div>
            <div class="descricao-ticket">
                ${ticket.descricao}
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
            `<p><strong>Encerrado por:</strong> ${ticket.fechado_por_nome} em ${new Date(ticket.fechado_em).toLocaleString('pt-BR')}</p>` :
            ''
        }
            
            ${ticket.anexos.length > 0 ? `
                <div class="anexos-section" style="margin-top: 1.5rem; border-top: 1px solid #eee; padding-top: 1rem;">
                    <h3><img src="images/clipe-de-anexo.png" style="width: 20px; height: 20px; vertical-align: middle; margin-right: 0.5rem;" alt="Anexos">Anexos (${ticket.anexos.length})</h3>
                    <div class="anexos-lista">
                        ${ticket.anexos.map(anexo => `
                            <div class="anexo-item" style="background: #f8f9fa; padding: 0.75rem; margin: 0.5rem 0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>${anexo.nome_original}</strong><br>
                                    <small style="color: #666;">${(anexo.tamanho / 1024 / 1024).toFixed(2)}MB - ${new Date(anexo.criado_em).toLocaleDateString('pt-BR')}</small>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    ${(anexo.tipo || anexo.tipo_mime) === 'application/pdf' || (anexo.tipo || anexo.tipo_mime).startsWith('image/') ?
                `<button class="btn-visualizar" onclick="visualizarAnexo(${anexo.id}, '${anexo.tipo || anexo.tipo_mime}')">👁️ Visualizar</button>` :
                ''
            }
                                    <a href="../api/controllers/anexos.php?id=${anexo.id}&action=download" class="btn-small" style="text-decoration: none;">📥 Download</a>
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
                    <div class="form-group">
                        <label for="novo-comentario">Adicionar comentário:</label>
                        <textarea id="novo-comentario" rows="3" required></textarea>
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

// Visualizar anexo inline
function visualizarAnexo(anexoId, tipoMime) {
    console.log('🔍 Visualizando anexo:', anexoId, tipoMime);

    const modal = document.getElementById('visualizador-anexo');
    const conteudo = document.getElementById('conteudo-anexo');

    if (!modal || !conteudo) {
        console.error('❌ Modal ou conteúdo não encontrado');
        return;
    }

    const baseUrl = '../api/controllers/anexos.php';

    if (tipoMime === 'application/pdf') {
        conteudo.innerHTML = `
            <iframe src="${baseUrl}?id=${anexoId}&action=view" width="100%" height="600px" style="border: none;" 
                    onload="console.log('PDF carregado')" 
                    onerror="console.error('Erro ao carregar PDF')"></iframe>
            <p style="margin-top: 15px; text-align: center; padding: 0 20px;">
                <a href="${baseUrl}?id=${anexoId}&action=download" target="_blank" class="btn-small" style="color: #000; white-space: nowrap; display: inline-block;">📄 Abrir em nova aba</a>
            </p>
        `;
    } else if (tipoMime.startsWith('image/')) {
        conteudo.innerHTML = `<img src="${baseUrl}?id=${anexoId}&action=view" style="max-width: 100%; height: auto; display: block; margin: 0 auto;" onload="console.log('Imagem carregada')" onerror="console.error('Erro ao carregar imagem')">`;
    } else {
        conteudo.innerHTML = `<p>Tipo de arquivo não suportado para visualização: ${tipoMime}</p>`;
    }

    console.log('📄 Conteúdo definido:', conteudo.innerHTML);
    modal.style.display = 'block';
}

// Fechar visualizador
function fecharVisualizador() {
    document.getElementById('visualizador-anexo').style.display = 'none';
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

    // Validar antes de adicionar comentário
    if (!validarAntesComentario(ticketId)) {
        return;
    }

    const comentario = document.getElementById('novo-comentario').value;
    const enviarEmail = document.getElementById('enviar-email').checked;
    const comCopia = document.getElementById('com-copia').value;
    const usuario = JSON.parse(localStorage.getItem('usuario'));
    const token = localStorage.getItem('token');

    // Usar FormData para suportar anexos
    const formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('comentario', comentario);
    formData.append('usuario_id', usuario.id);
    formData.append('enviar_email', enviarEmail);
    formData.append('com_copia', comCopia);

    // Adicionar anexos acumulados se houver
    if (anexosComentarioSelecionados.length > 0) {
        anexosComentarioSelecionados.forEach(arquivo => {
            formData.append('anexos[]', arquivo);
        });
    }

    console.log('Enviando comentário com', anexosComentarioSelecionados.length, 'anexos');

    mostrarLoader();

    fetch('/crpdesk/api/controllers/comentarios.php', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`
        },
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            ocultarLoader();
            const mensagem = enviarEmail ?
                (comCopia ? 'Comentário adicionado e e-mails enviados!' : 'Comentário adicionado e e-mail enviado!') :
                'Comentário adicionado com sucesso!';

            mostrarToast('Sucesso', mensagem, 'success');

            // Limpar formulário
            document.getElementById('novo-comentario').value = '';
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

            // Adicionar comentário na lista
            if (data.comentario) {
                const listaComentarios = document.getElementById('lista-comentarios');
                if (listaComentarios) {
                    let novoComentarioHtml = `
                    <div class="comentario-wrapper">
                        <div class="comentario-info">
                            <strong>💬 ${data.comentario.autor_nome}</strong> - ${new Date(data.comentario.criado_em).toLocaleString('pt-BR')}<br>
                            <strong style="color: #666; font-size: 0.9em;">Comentário:</strong>
                        </div>
                        <div class="comentario">
                            ${data.comentario.comentario}
                        </div>
                `;

                    if (data.anexos && data.anexos.length > 0) {
                        novoComentarioHtml += `
                        <div style="margin-top: 0.5rem; padding-left: 1rem;">
                            <strong style="color: #666; font-size: 0.9em;">Anexos:</strong><br>
                    `;
                        data.anexos.forEach(anexo => {
                            const tipo = anexo.tipo || anexo.tipo_mime || '';
                            const isPDF = tipo === 'application/pdf';
                            const isImage = tipo.startsWith('image/');
                            const nomeExibir = anexo.nome_original || anexo.nome_arquivo;
                            novoComentarioHtml += `
                            <div style="margin-top: 0.3rem;">
                                <a href="/crpdesk/api/controllers/anexos.php?id=${anexo.id}" download="${nomeExibir}" style="color: #007bff; text-decoration: none;"><img src="images/clipe-de-anexo.png" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"> ${nomeExibir}</a>
                                ${isPDF || isImage ? `<button onclick="visualizarAnexo(${anexo.id}, '${tipo}')" style="margin-left: 0.5rem; padding: 0.2rem 0.5rem; font-size: 0.8rem; cursor: pointer; border: 1px solid #007bff; background: white; color: #007bff; border-radius: 3px;">👁️ Ver</button>` : ''}
                            </div>
                        `;
                        });
                        novoComentarioHtml += `</div>`;
                    }

                    novoComentarioHtml += `</div>`;

                    // Adicionar registros de email se enviado
                    if (enviarEmail) {
                        const usuario = JSON.parse(localStorage.getItem('usuario'));
                        const agora = new Date().toLocaleString('pt-BR');

                        // Buscar email do solicitante do ticketAtual
                        const ticketAtual = JSON.parse(localStorage.getItem('ticketAtual'));
                        if (ticketAtual && ticketAtual.usuario_email) {
                            novoComentarioHtml += `
                            <div class="historico-wrapper">
                                <div class="historico-info">
                                    <strong>🕒 ${usuario.nome}</strong> - ${agora}
                                </div>
                                <div class="historico-acao">
                                    📧 O comentário acima foi enviado por e-mail para: ${ticketAtual.usuario_email}
                                </div>
                            </div>
                        `;
                        }

                        if (comCopia) {
                            novoComentarioHtml += `
                            <div class="historico-wrapper">
                                <div class="historico-info">
                                    <strong>🕒 ${usuario.nome}</strong> - ${agora}
                                </div>
                                <div class="historico-acao">
                                    📧 Cópia do comentário acima enviada por e-mail para: ${comCopia}
                                </div>
                            </div>
                        `;
                        }
                    }

                    listaComentarios.insertAdjacentHTML('beforeend', novoComentarioHtml);
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
    const tempoRestante = Math.max(0, 2000 - tempoDecorrido);
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
        // Modal "Enviar e Fechar Ticket" usa botão "Sim"
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

    // Adicionar campo de pesquisa se for categoria
    if (titulo.includes('Categoria')) {
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
    document.getElementById('modal-overlay').classList.remove('show');
    document.querySelectorAll('.modal').forEach(modal => modal.classList.remove('show'));

    // Executar callback apenas se for alert
    if (modalCallback && document.getElementById('modal-alert').classList.contains('show')) {
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

// === GERENCIAMENTO DE USUÁRIOS ===

// Mostrar gerenciamento de usuários
function mostrarUsuarios() {
    // Ativar botão
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_usuarios = document.getElementById('btn-usuarios'); if (_bbtn_usuarios) _bbtn_usuarios.classList.add('active');

    // Salvar subseção
    localStorage.setItem('subSecaoAtiva', 'usuarios');

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
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/usuarios.php', true);

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const usuarios = JSON.parse(xhr.responseText);
            mostrarListaUsuarios(usuarios);
        } else {
            document.getElementById('config-content').innerHTML = '<p>Erro ao carregar usuários.</p>';
        }
    };

    xhr.send();
}

// Mostrar lista de usuários
function mostrarListaUsuarios(usuarios) {
    const content = document.getElementById('config-content');

    // Debug: verificar tipos existentes
    console.log('Usuários recebidos:', usuarios.map(u => ({ nome: u.nome, tipo: u.tipo })));

    // Separar usuários por tipo (tratar vazios como usuários comuns)
    const admins = usuarios.filter(u => u.tipo === 'admin');
    const agentes = usuarios.filter(u => u.tipo === 'agente');
    const usuariosComuns = usuarios.filter(u => u.tipo === 'usuario' || u.tipo === '' || !u.tipo);
    const outrosTipos = usuarios.filter(u => u.tipo && !['admin', 'agente', 'usuario', ''].includes(u.tipo));

    console.log('Separação:', { admins: admins.length, agentes: agentes.length, usuariosComuns: usuariosComuns.length, outros: outrosTipos.length });
    console.log('Outros tipos encontrados:', outrosTipos.map(u => u.tipo));

    function gerarCardUsuario(usuario) {
        const tipoExibicao = (usuario.tipo === 'usuario' || usuario.tipo === '' || !usuario.tipo) ? 'Usuário' :
            usuario.tipo === 'agente' ? 'Agente' :
                usuario.tipo === 'admin' ? 'Administrador' : usuario.tipo;

        const isAgenteOuAdmin = usuario.tipo === 'agente' || usuario.tipo === 'admin';
        const statusAtivo = usuario.ativo === 1 || usuario.ativo === '1';

        return `
            <div class="usuario-card ${!statusAtivo ? 'usuario-inativo' : ''}">
                <div class="usuario-info">
                    <h4>${usuario.nome} ${!statusAtivo ? '<span style="color: #dc3545;">(Inativo)</span>' : ''}</h4>
                    <p>${usuario.email}</p>
                    <p><strong>Tipo:</strong> ${tipoExibicao} | <strong>Criado:</strong> ${new Date(usuario.criado_em).toLocaleDateString('pt-BR')}</p>
                </div>
                <div class="usuario-acoes">
                    ${isAgenteOuAdmin ? `
                        <button class="btn-${statusAtivo ? 'desativar' : 'ativar'}" onclick="${statusAtivo ? 'desativar' : 'ativar'}Usuario(${usuario.id})">
                            ${statusAtivo ? 'Desativar' : 'Ativar'}
                        </button>
                    ` : ''}
                    <button class="btn-editar" onclick="editarUsuario(${usuario.id})">Editar</button>
                    <button class="btn-excluir" onclick="excluirUsuario(${usuario.id})">Excluir</button>
                </div>
            </div>
        `;
    }

    let adminsHtml = '';
    admins.forEach(admin => {
        adminsHtml += gerarCardUsuario(admin);
    });

    let agentesHtml = '';
    agentes.forEach(agente => {
        agentesHtml += gerarCardUsuario(agente);
    });

    let usuariosComunsHtml = '';
    usuariosComuns.forEach(usuario => {
        usuariosComunsHtml += gerarCardUsuario(usuario);
    });

    let secoes = '';

    if (admins.length > 0) {
        secoes += `
            <h3>👑 Administradores (${admins.length})</h3>
            <div class="usuarios-grid">
                ${adminsHtml}
            </div>
        `;
    }

    if (agentes.length > 0) {
        secoes += `
            <h3>🛠️ Agentes (${agentes.length})</h3>
            <div class="usuarios-grid">
                ${agentesHtml}
            </div>
        `;
    }

    if (usuariosComuns.length > 0) {
        secoes += `
            <h3>👥 Usuários (${usuariosComuns.length})</h3>
            <div class="usuarios-grid">
                ${usuariosComunsHtml}
            </div>
        `;
    }

    // Mostrar outros tipos se existirem
    if (outrosTipos.length > 0) {
        let outrosHtml = '';
        outrosTipos.forEach(usuario => {
            outrosHtml += gerarCardUsuario(usuario);
        });

        secoes += `
            <h3>🔄 Outros Tipos (${outrosTipos.length})</h3>
            <div class="usuarios-grid">
                ${outrosHtml}
            </div>
        `;
    }

    content.innerHTML = `
        <button onclick="toggleFormUsuario()" class="btn-primary" style="margin-bottom: 1rem;">
            ➕ Adicionar Novo Usuário
        </button>
        
        <div class="form-usuario" id="form-novo-usuario" style="display: none; overflow: hidden; max-height: 0; transition: max-height 0.3s ease-out;">
            <h3>Adicionar Novo Usuário</h3>
            <form id="form-usuario" onsubmit="criarUsuario(event)">
                <div class="form-group">
                    <label for="nome-usuario">Nome:</label>
                    <input type="text" id="nome-usuario" required>
                </div>
                <div class="form-group">
                    <label for="email-usuario">Email:</label>
                    <input type="email" id="email-usuario" required>
                </div>
                <div class="form-group">
                    <label for="tipo-usuario">Tipo:</label>
                    <select id="tipo-usuario">
                        <option value="agente">Agente</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary">Adicionar Usuário</button>
            </form>
        </div>
        
        ${secoes}
    `;
}

// Criar novo usuário
function criarUsuario(event) {
    event.preventDefault();

    const nome = document.getElementById('nome-usuario').value;
    const email = document.getElementById('email-usuario').value;
    const tipo = document.getElementById('tipo-usuario').value;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/crpdesk/api/controllers/usuarios.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Usuário criado com sucesso!', 'success');
            document.getElementById('form-usuario').reset();
            carregarUsuarios();
        } else {
            mostrarAlert('Erro', 'Erro ao criar usuário');
        }
    };

    xhr.send(JSON.stringify({ nome, email, tipo }));
}

// Editar usuário
function editarUsuario(usuarioId) {
    // Buscar dados atuais do usuário
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/usuarios.php', true);

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const usuarios = JSON.parse(xhr.responseText);
            const usuario = usuarios.find(u => u.id === usuarioId);

            if (usuario) {
                mostrarFormularioEdicaoUsuario(usuario);
            }
        }
    };

    xhr.send();
}

// Mostrar formulário de edição de usuário
function mostrarFormularioEdicaoUsuario(usuario) {
    const content = `
        <div class="form-group">
            <label for="edit-nome">Nome:</label>
            <input type="text" id="edit-nome" value="${usuario.nome}" required>
        </div>
        <div class="form-group">
            <label for="edit-tipo">Função:</label>
            <select id="edit-tipo">
                <option value="usuario" ${usuario.tipo === 'usuario' ? 'selected' : ''}>Usuário</option>
                <option value="agente" ${usuario.tipo === 'agente' ? 'selected' : ''}>Agente</option>
                <option value="admin" ${usuario.tipo === 'admin' ? 'selected' : ''}>Administrador</option>
            </select>
        </div>
    `;

    document.getElementById('modal-prompt-message').innerHTML = content;
    document.getElementById('modal-prompt-title').textContent = 'Editar Usuário';
    document.getElementById('modal-prompt-input').style.display = 'none';

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        if (confirmado) {
            const nome = document.getElementById('edit-nome').value;
            const tipo = document.getElementById('edit-tipo').value;

            if (nome && tipo) {
                executarEdicaoUsuario(usuario.id, nome, usuario.email, tipo);
            }
        }

        // Restaurar modal
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
    };
}

// Executar edição de usuário
function executarEdicaoUsuario(usuarioId, nome, email, tipo) {
    console.log('🔄 Editando Usuário:', { usuarioId, nome, email, tipo });

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/crpdesk/api/controllers/usuarios.php/${usuarioId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        console.log('📞 Resposta edição Usuário:', xhr.status, xhr.responseText);
        if (xhr.status === 200) {
            carregarUsuarios();
            mostrarToast('Sucesso', 'Usuário atualizado com sucesso!', 'success');
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao atualizar usuário');
        }
    };

    xhr.send(JSON.stringify({ nome, email, tipo }));
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
    xhr.open('DELETE', `/crpdesk/api/controllers/usuarios.php?id=${usuarioId}`, true);

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
        'Excluir Ticket',
        'Tem certeza que deseja excluir este ticket? Esta ação não pode ser desfeita.',
        function (confirmado) {
            if (!confirmado) return;

            // Segunda confirmação com cabeçalho vermelho
            mostrarConfirm(
                'Confirmação Final',
                'ATENÇÃO: O ticket e todos os seus comentários serão excluídos permanentemente. Confirma?',
                function (confirmadoFinal) {
                    if (!confirmadoFinal) return;

                    executarExclusaoTicket(ticketId);
                },
                true // isPerigoso = true
            );
        }
    );
}

// Executar exclusão do ticket
function executarExclusaoTicket(ticketId, callback) {
    // Verificar se ticket está fechado antes de excluir
    const ticketAtual = JSON.parse(localStorage.getItem('ticketAtual') || '{}');
    const isTicketFechado = ticketAtual.status === 'encerrado' || ticketAtual.status === 'cancelado';

    // Validar se ticket tem responsável (REMOVIDO: permitir exclusão sem responsável)
    /*
    if (!ticketAtual.agente_id) {
        mostrarAlert('Atenção', 'Este ticket não possui responsável. Atribua o ticket antes de excluí-lo.');
        return;
    }
    */

    // Validar se ticket tem categoria (REMOVIDO: permitir exclusão sem categoria)
    /*
    if (!ticketAtual.categoria_id) {
        mostrarAlert('Atenção', 'Este ticket não possui categoria. Defina uma categoria antes de excluí-lo.');
        return;
    }
    */

    const xhr = new XMLHttpRequest();
    xhr.open('DELETE', `/crpdesk/api/controllers/tickets.php?id=${ticketId}`, true);

    // Adicionar token de autenticação
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const row = document.querySelector(`tr[data-ticket-id="${ticketId}"]`);
            if (row) {
                row.classList.add('ticket-removendo');
            }

            mostrarToast('Sucesso', 'Ticket removido com sucesso!', 'success');

            // Aguardar um pouco e redirecionar
            setTimeout(() => {
                if (callback) {
                    callback();
                } else {
                    // Limpar dados e ir para lista apropriada
                    localStorage.removeItem('ticketAtivo');
                    localStorage.removeItem('ticketAtual');

                    if (isTicketFechado) {
                        mostrarTicketsFechados();
                    } else {
                        mostrarTicketsComAtualizacao();
                    }
                }
            }, 500);
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao remover ticket');
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

function carregarCategorias() {
    console.log('📁 Carregando categorias...');
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/categorias.php', true);

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
        item.textContent = 'Nenhuma categoria encontrada';
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
        dropdown.innerHTML = '<div class="categoria-item">Nenhuma categoria encontrada</div>';
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

// Mostrar gerenciamento de categorias
function mostrarCategorias() {
    // Ativar botão
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_categorias = document.getElementById('btn-categorias'); if (_bbtn_categorias) _bbtn_categorias.classList.add('active');

    // Salvar subseção
    localStorage.setItem('subSecaoAtiva', 'categorias');

    const content = document.getElementById('config-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        carregarListaCategorias();
    }, 10);
}

// Carregar macrocategorias para o select
function carregarMacrocategorias() {
    console.log('🔌 Carregando macrocategorias...');
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/macrocategorias.php', true);

    xhr.onload = function () {
        console.log('📡 Status:', xhr.status);
        console.log('📡 Resposta:', xhr.responseText);

        if (xhr.status === 200) {
            const macrocategorias = JSON.parse(xhr.responseText);
            console.log('📁 Macrocategorias:', macrocategorias);

            const select = document.getElementById('macrocategoria-categoria');
            console.log('🔍 Select encontrado:', !!select);

            if (select) {
                // Limpar opções existentes (exceto a primeira)
                select.innerHTML = '<option value="">Selecione uma macrocategoria</option>';

                macrocategorias.forEach(macro => {
                    const option = document.createElement('option');
                    option.value = macro.id;
                    option.textContent = macro.nome;
                    select.appendChild(option);
                });

                console.log('✅ Macrocategorias carregadas no select');
            }
        } else {
            console.error('❌ Erro ao carregar macrocategorias:', xhr.status, xhr.responseText);
        }
    };

    xhr.onerror = function () {
        console.error('❌ Erro de conexão ao carregar macrocategorias');
    };

    xhr.send();
}

// Carregar lista de categorias
function carregarListaCategorias() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/categorias.php', true);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const categorias = JSON.parse(xhr.responseText);

            // Carregar macrocategorias para exibição
            const xhr2 = new XMLHttpRequest();
            xhr2.open('GET', '/crpdesk/api/controllers/macrocategorias.php', true);

            xhr2.onload = function () {
                if (xhr2.status === 200) {
                    const macrocategorias = JSON.parse(xhr2.responseText);
                    mostrarListaCategorias(categorias, macrocategorias);
                }
            };

            xhr2.send();
        } else {
            document.getElementById('config-content').innerHTML = '<p>Erro ao carregar categorias.</p>';
        }
    };

    xhr.send();
}

// Mostrar lista de categorias
function mostrarListaCategorias(categorias, macrocategorias) {
    const content = document.getElementById('config-content');

    // Agrupar categorias por macrocategoria
    const categoriasPorMacro = {};
    const macroMap = {};

    macrocategorias.forEach(macro => {
        macroMap[macro.id] = macro.nome;
        categoriasPorMacro[macro.nome] = [];
    });

    categorias.forEach(categoria => {
        const macroNome = macroMap[categoria.macrocategoria_id] || 'Sem Macrocategoria';
        if (!categoriasPorMacro[macroNome]) {
            categoriasPorMacro[macroNome] = [];
        }
        categoriasPorMacro[macroNome].push(categoria);
    });

    let categoriasHtml = '';

    Object.keys(categoriasPorMacro).forEach(macroNome => {
        const cats = categoriasPorMacro[macroNome];
        if (cats.length > 0) {
            categoriasHtml += `<h3>📁 ${macroNome} (${cats.length})</h3><div class="usuarios-grid">`;

            cats.forEach(categoria => {
                categoriasHtml += `
                    <div class="usuario-card">
                        <div class="usuario-info">
                            <h4>${categoria.nome}</h4>
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

    content.innerHTML = `
        <button onclick="toggleFormCategoria()" class="btn-primary" style="margin-bottom: 1rem;">
            ➕ Adicionar Nova Categoria
        </button>
        
        <div class="form-usuario" id="form-nova-categoria" style="display: none; overflow: hidden; max-height: 0; transition: max-height 0.3s ease-out;">
            <h3>Adicionar Nova Categoria</h3>
            <form id="form-categoria" onsubmit="criarCategoria(event)">
                <div class="form-group">
                    <label for="nome-categoria">Nome:</label>
                    <input type="text" id="nome-categoria" required placeholder="Ex: Sistema *RFID*">
                    <small style="color: #666; font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                        📝 <strong>Dica:</strong> Use *asteriscos* para preservar a formatação de palavras específicas (ex: Sistema *RFID* → Sistema RFID)
                    </small>
                </div>
                <div class="form-group">
                    <label for="macrocategoria-categoria">Macrocategoria:</label>
                    <select id="macrocategoria-categoria" required>
                        <option value="">Selecione uma macrocategoria</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary">Adicionar Categoria</button>
            </form>
        </div>
        
        ${categoriasHtml}
    `;

    // Carregar macrocategorias após renderizar o HTML
    setTimeout(() => carregarMacrocategorias(), 100);
}

// Criar nova categoria
function criarCategoria(event) {
    event.preventDefault();

    const nomeOriginal = document.getElementById('nome-categoria').value.trim();
    const nome = formatarNomeCategoria(nomeOriginal);
    const macrocategoria_id = document.getElementById('macrocategoria-categoria').value;

    if (!macrocategoria_id) {
        mostrarAlert('Erro', 'Selecione uma macrocategoria!');
        return;
    }

    // Verificar se categoria já existe
    const xhr1 = new XMLHttpRequest();
    xhr1.open('GET', '/crpdesk/api/controllers/categorias.php', true);

    xhr1.onload = function () {
        if (xhr1.status === 200) {
            const categorias = JSON.parse(xhr1.responseText);
            const categoriaExiste = categorias.some(cat => cat.nome.toLowerCase() === nome.toLowerCase());

            if (categoriaExiste) {
                mostrarAlert('Erro', 'Esta categoria já existe!');
                return;
            }

            // Criar categoria
            const xhr2 = new XMLHttpRequest();
            xhr2.open('POST', '/crpdesk/api/controllers/categorias.php', true);
            xhr2.setRequestHeader('Content-Type', 'application/json');

            // Adicionar token de autenticação
            setSecurityHeaders(xhr2);

            xhr2.onload = function () {
                if (xhr2.status === 200) {
                    document.getElementById('form-categoria').reset();
                    carregarListaCategorias();
                    mostrarToast('Sucesso', 'Categoria criada com sucesso!', 'success');
                } else {
                    mostrarAlert('Erro', 'Erro ao criar categoria');
                }
            };

            xhr2.send(JSON.stringify({ nome, macrocategoria_id }));
        }
    };

    xhr1.send();
}

// Formatar nome da categoria
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

// Editar categoria
function editarCategoria(categoriaId) {
    console.log('✏️ Editando categoria ID:', categoriaId);

    // Buscar dados atuais da categoria
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/categorias.php', true);

    xhr.onload = function () {
        console.log('📡 Categorias carregadas para edição:', xhr.status);
        if (xhr.status === 200) {
            const categorias = JSON.parse(xhr.responseText);
            const categoria = categorias.find(c => c.id === categoriaId);

            console.log('🔍 Categoria encontrada:', categoria);

            if (categoria) {
                mostrarFormularioEdicaoCategoria(categoria);
            } else {
                console.error('❌ Categoria não encontrada');
            }
        }
    };

    xhr.send();
}

// Mostrar formulário de edição de categoria
function mostrarFormularioEdicaoCategoria(categoria) {
    console.log('📝 Criando modal para categoria:', categoria);

    // Carregar macrocategorias
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/macrocategorias.php', true);

    xhr.onload = function () {
        console.log('📡 Macrocategorias para modal:', xhr.status);
        if (xhr.status === 200) {
            const macrocategorias = JSON.parse(xhr.responseText);
            console.log('📁 Macrocategorias recebidas:', macrocategorias.length);

            let opcoesHtml = '<option value="">Selecione uma macrocategoria</option>';
            macrocategorias.forEach(macro => {
                const selected = macro.id === categoria.macrocategoria_id ? 'selected' : '';
                opcoesHtml += `<option value="${macro.id}" ${selected}>${macro.nome}</option>`;
                console.log(`🔘 Opção: ${macro.nome} (ID: ${macro.id}) - Selected: ${selected}`);
            });

            const content = `
                <div class="form-group">
                    <label for="edit-nome-cat">Nome:</label>
                    <input type="text" id="edit-nome-cat" value="${categoria.nome}" required>
                    <small style="color: #666; font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                        📝 Use *asteriscos* para preservar formatação (ex: Sistema *RFID*)
                    </small>
                </div>
                <div class="form-group">
                    <label for="edit-macro-cat">Macrocategoria:</label>
                    <select id="edit-macro-cat" required>
                        ${opcoesHtml}
                    </select>
                </div>
            `;

            document.getElementById('modal-prompt-message').innerHTML = content;
            document.getElementById('modal-prompt-title').textContent = 'Editar Categoria';
            document.getElementById('modal-prompt-input').style.display = 'none';

            document.getElementById('modal-overlay').classList.add('show');
            document.getElementById('modal-prompt').classList.add('show');

            console.log('🔓 Modal aberto, aguardando confirmação...');

            modalCallback = function (confirmado) {
                console.log('🔘 Modal callback:', confirmado);

                if (confirmado === true) {
                    const nome = document.getElementById('edit-nome-cat').value;
                    const macrocategoriaId = document.getElementById('edit-macro-cat').value;

                    console.log('📝 Valores do modal:', { nome, macrocategoriaId });

                    if (nome && macrocategoriaId) {
                        executarEdicaoCategoria(categoria.id, nome.trim(), macrocategoriaId);
                    } else {
                        console.error('❌ Campos obrigatórios não preenchidos');
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

// Executar edição de categoria
function executarEdicaoCategoria(categoriaId, nomeOriginal, macrocategoriaId) {
    const nome = formatarNomeCategoria(nomeOriginal);

    console.log('🔄 Enviando edição:', { categoriaId, nome, macrocategoriaId });

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/crpdesk/api/controllers/categorias.php/${categoriaId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    xhr.onload = function () {
        console.log('📞 Resposta do servidor:', xhr.status, xhr.responseText);
        if (xhr.status === 200) {
            console.log('🔄 Atualizando listas...');
            carregarListaCategorias();
            carregarCategorias();
            mostrarToast('Sucesso', 'Categoria atualizada com sucesso!', 'success');
        } else {
            const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            mostrarAlert('Erro', response.error || 'Erro ao atualizar categoria');
        }
    };

    xhr.onerror = function () {
        console.error('❌ Erro de conexão');
        mostrarAlert('Erro', 'Erro de conexão');
    };

    xhr.send(JSON.stringify({ nome, macrocategoria_id: macrocategoriaId }));
}

// Excluir categoria
function excluirCategoria(categoriaId) {
    mostrarConfirm(
        'Excluir Categoria',
        'Tem certeza que deseja excluir esta categoria?',
        function (confirmado) {
            if (!confirmado) return;

            executarExclusaoCategoria(categoriaId);
        }
    );
}

// Executar exclusão de categoria
function executarExclusaoCategoria(categoriaId) {
    const xhr = new XMLHttpRequest();
    xhr.open('DELETE', `/crpdesk/api/controllers/categorias.php/${categoriaId}`, true);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Categoria excluída com sucesso!', 'success');
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

// Função para limpar formulário de ticket completamente
function limparFormularioTicket() {
    console.log('🧹 Limpando formulário de ticket...');

    // Reset geral do formulário primeiro
    document.getElementById('form-ticket').reset();

    // Limpar campos específicos
    document.getElementById('titulo').value = '';
    document.getElementById('descricao').value = '';

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
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/tickets.php', true);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const todosTickets = JSON.parse(xhr.responseText);
            const ticketsFechados = todosTickets.filter(ticket => ticket.status === 'encerrado' || ticket.status === 'cancelado');

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
        const usuarioLogado = JSON.parse(localStorage.getItem('usuario'));
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
    localStorage.setItem('ticketsFechadosPorPagina', porPaginaFechados);
    paginaAtualFechados = 1;
    mostrarTicketsFechadosNaTela(ticketsFechadosFiltradosPorBusca.length > 0 ? ticketsFechadosFiltradosPorBusca : ticketsFechadosAtuais);
}

// Mostrar tickets fechados na tela
function mostrarTicketsFechadosNaTela(tickets) {
    const listaFechados = document.getElementById('lista-fechados');

    ticketsFechadosFiltradosPorBusca = tickets;

    if (tickets.length === 0) {
        listaFechados.innerHTML = `
            <div style="text-align:center; padding: 3rem 1rem; color: #64748b;">
                <span class="material-symbols-outlined" style="font-size:3rem; opacity:0.4;">inbox</span>
                <p style="margin-top:0.5rem; font-size:0.95rem;">Nenhum ticket fechado encontrado para este filtro.</p>
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
                    <th onclick="ordenarTabelaFechados('id')" class="sortable" data-campo-fechado="id">Nº Ticket <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('criado_em')" class="sortable" data-campo-fechado="criado_em">Abertura <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('titulo')" class="sortable" data-campo-fechado="titulo">Assunto <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('usuario_nome')" class="sortable" data-campo-fechado="usuario_nome">Solicitante <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('agente_nome')" class="sortable" data-campo-fechado="agente_nome">Responsável <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('categoria_nome')" class="sortable" data-campo-fechado="categoria_nome">Categoria <span class="sort-arrows">↕</span></th>
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
            ? `<span class="material-symbols-outlined icone-tabela icone-anexo-ms" title="Ticket com anexos" aria-label="Tem anexos">attach_file</span>` : '';
        const iconeMesclado = ticket.eh_mesclado > 0
            ? `<span class="material-symbols-outlined icone-tabela" title="Ticket mesclado" aria-label="Mesclado" style="color:#6366f1">call_merge</span>` : '';

        // Título truncado
        const tituloSeguro = (ticket.titulo || '').replace(/"/g, '&quot;').replace(/</g, '&lt;');

        tabelaHTML += `
            <tr data-ticket-id="${ticket.id}" ${modoMergeFechadosAtivo ? 'style="cursor: pointer;"' : ''}>
                <td>
                    <a href="#" onclick="${modoMergeFechadosAtivo ? 'event.stopPropagation();' : ''} verDetalhes(${ticket.id})" class="ticket-link">#${String(ticket.id).padStart(5, '0')}</a>
                    ${iconeMesclado}${iconeAnexo}
                </td>
                <td>${new Date(ticket.criado_em).toLocaleDateString('pt-BR')}<span class="ticket-data-hora">${new Date(ticket.criado_em).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</span></td>
                <td class="ticket-titulo-cell" title="${tituloSeguro}">
                    <span class="ticket-titulo-texto">${ticket.titulo}</span>
                </td>
                <td>
                    <strong>${ticket.usuario_nome}</strong>
                    ${ticket.usuario_email ? `<span class="ticket-data-hora" style="font-size: 0.85em;">${ticket.usuario_email}</span>` : ''}
                </td>
                <td>${ticket.agente_nome || 'Não atribuído'}</td>
                <td>${ticket.categoria_nome || 'Sem categoria'}</td>
                <td><span class="status ${ticket.status}">${ticket.status === 'encerrado' ? '🏁 Encerrado' : '❌ Cancelado'}</span></td>
                <td style="font-size: 0.8rem; font-weight: bold;">${ticket.fechado_em ? new Date(ticket.fechado_em).toLocaleDateString('pt-BR') + '<span class="ticket-data-hora">' + new Date(ticket.fechado_em).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) + '</span>' : '-'}</td>
                <td>
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
        <div class="tabela-footer" role="navigation" aria-label="Paginação de tickets fechados">
            <div class="tabela-resumo">
                Exibindo <strong>${inicioExib}–${fimExib}</strong> de <strong>${totalExib}</strong> ticket${totalExib !== 1 ? 's' : ''}
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
        'Reabrir Ticket',
        'Tem certeza que deseja reabrir este ticket?',
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
        'Remover Ticket',
        'Tem certeza que deseja remover este ticket fechado? Esta ação não pode ser desfeita.',
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
    localStorage.removeItem('ticketAtivo');
    localStorage.removeItem('secaoOrigem');

    if (secaoOrigem === 'fechados') {
        mostrarTicketsFechados();
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
    // Primeiro inicializar tabelas
    const xhrInit = new XMLHttpRequest();
    xhrInit.open('POST', '/crpdesk/api/controllers/init-tempo.php', true);
    xhrInit.setRequestHeader('Content-Type', 'application/json');

    xhrInit.onload = function () {
        // Inicializar histórico
        const xhrHist = new XMLHttpRequest();
        xhrHist.open('POST', '/crpdesk/api/controllers/init-historico.php', true);
        xhrHist.setRequestHeader('Content-Type', 'application/json');

        xhrHist.onload = function () {
            // Depois carregar dashboard
            const xhr = new XMLHttpRequest();
            xhr.open('GET', '/crpdesk/api/controllers/dashboard.php', true);

            xhr.onload = function () {
                if (xhr.status === 200) {
                    const dados = JSON.parse(xhr.responseText);
                    mostrarDashboardNaTela(dados);
                } else {
                    document.getElementById('dashboard-content').innerHTML = '<p>Erro ao carregar dashboard.</p>';
                }
            };

            xhr.send();
        };

        xhrHist.send('{}');
    };

    xhrInit.send('{}');
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
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>🎫 Total de Tickets <span class="info-icon" onclick="mostrarInfoGrafico('total')" title="Clique para mais informações">ℹ️</span></h3>
                <div class="dashboard-number count-up" data-target="${dados.total_tickets}">0</div>
            </div>
            <div class="dashboard-card">
                <h3>✅ Tickets Encerrados <span class="info-icon" onclick="mostrarInfoGrafico('encerrados')" title="Clique para mais informações">ℹ️</span></h3>
                <div class="dashboard-number count-up" data-target="${dados.total_encerrados || 0}">0</div>
            </div>
            <div class="dashboard-card">
                <h3>🔄 Tickets Ativos <span class="info-icon" onclick="mostrarInfoGrafico('ativos')" title="Clique para mais informações">ℹ️</span></h3>
                <div class="dashboard-number count-up" data-target="${dados.total_tickets - (dados.total_encerrados || 0)}">0</div>
            </div>
            <div class="dashboard-card">
                <h3>⏱️ Tempo Médio Total <span class="info-icon" onclick="mostrarInfoGrafico('tempo-total')" title="Clique para mais informações">ℹ️</span></h3>
                <div class="dashboard-number scale-bounce">${formatarTempo(dados.tempoMedioSegundos)}</div>
            </div>
            <div class="dashboard-card">
                <h3>⚡ Tempo Médio 1º Atendimento <span class="info-icon" onclick="mostrarInfoGrafico('primeiro-atendimento')" title="Clique para mais informações">ℹ️</span></h3>
                <div class="dashboard-number slide-down">${formatarTempo(dados.tempoPrimeiroAtendimentoSegundos)}</div>
            </div>
            <div class="dashboard-card">
                <h3>✅ Taxa de Conclusão <span class="info-icon" onclick="mostrarInfoGrafico('taxa-conclusao')" title="Clique para mais informações">ℹ️</span></h3>
                <div class="dashboard-number flip-in">${taxaConclusao}%</div>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>📊 Tickets por Status <small style="color: #666; font-size: 0.8em;">(apenas ativos)</small> <span class="info-icon" onclick="mostrarInfoGrafico('status')" title="Clique para mais informações">ℹ️</span></h3>
                <canvas id="grafico-status" width="400" height="300"></canvas>
            </div>
            <div class="dashboard-card">
                <h3>🔥 Tickets por Prioridade <small style="color: #666; font-size: 0.8em;">(apenas ativos)</small> <span class="info-icon" onclick="mostrarInfoGrafico('prioridade')" title="Clique para mais informações">ℹ️</span></h3>
                <canvas id="grafico-prioridade" width="400" height="250"></canvas>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>📈 Evolução Mensal <span class="info-icon" onclick="mostrarInfoGrafico('evolucao')" title="Clique para mais informações">ℹ️</span></h3>
                <canvas id="grafico-mensal" width="600" height="250"></canvas>
            </div>
            <div class="dashboard-card">
                <h3>🥧 Distribuição por Agente <small style="color: #666; font-size: 0.8em;">(apenas ativos)</small> <span class="info-icon" onclick="mostrarInfoGrafico('agente')" title="Clique para mais informações">ℹ️</span></h3>
                <canvas id="grafico-agente" width="400" height="250"></canvas>
            </div>
        </div>
        
        <div class="dashboard-grid">

        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>📊 Status por Agente <small style="color: #666; font-size: 0.8em;">(apenas ativos)</small> <span class="info-icon" onclick="mostrarInfoGrafico('status-agente')" title="Clique para mais informações">ℹ️</span></h3>
                <canvas id="grafico-status-agente" width="600" height="300"></canvas>
            </div>
            <div class="dashboard-card">
                <h3>📅 Tickets por Dia da Semana <span class="info-icon" onclick="mostrarInfoGrafico('dia-semana')" title="Clique para mais informações">ℹ️</span></h3>
                <canvas id="grafico-dia-semana" width="400" height="200"></canvas>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>👥 Top 5 Solicitantes</h3>
                <div class="dashboard-list">`;

    (dados.top_solicitantes || []).forEach((item, index) => {
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${item.nome}</span><span>${item.total}</span></div>`;
    });

    html += `</div></div><div class="dashboard-card"><h3>🏆 Top 5 Categorias</h3><div class="dashboard-list">`;

    (dados.top_categorias || []).forEach((item, index) => {
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${item.nome || 'Sem categoria'}</span><span>${item.total}</span></div>`;
    });

    html += `</div></div><div class="dashboard-card"><h3>📁 Tickets por Macrocategoria</h3><div class="dashboard-list">`;

    (dados.ticketsPorMacrocategoria || []).forEach((item, index) => {
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${item.nome || 'Sem macrocategoria'}</span><span>${item.quantidade}</span></div>`;
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
let porPaginaFechados = parseInt(localStorage.getItem('ticketsFechadosPorPagina') || '50');
let ticketsFechadosFiltradosPorBusca = [];

// Restaurar ordenação salva ou usar default
const _ordemSalva = JSON.parse(localStorage.getItem('ticketsOrdem') || 'null');
const _ordemFechadosSalva = JSON.parse(localStorage.getItem('ticketsFechadosOrdem') || 'null');

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
    localStorage.setItem('ticketsOrdem', JSON.stringify(ordemAtual));

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
    localStorage.setItem('ticketsFechadosOrdem', JSON.stringify(ordemFechados));

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
    const secaoSalva = localStorage.getItem('secaoAtiva');
    const subSecaoSalva = localStorage.getItem('subSecaoAtiva');
    const secaoOrigemSalva = localStorage.getItem('secaoOrigem');
    const ticketAtivo = localStorage.getItem('ticketAtivo');

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
    } else if (secaoSalva === 'relatorios') {
        mostrarRelatorios();
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
        const usuario = JSON.parse(localStorage.getItem('usuario'));
        if (usuario.tipo !== 'admin') {
            mostrarSecao('tickets');
            carregarTickets();
        } else {
            mostrarSecao('config');
            // Restaurar subseção de configuração
            if (subSecaoSalva === 'usuarios') {
                mostrarUsuarios();
            } else if (subSecaoSalva === 'categorias') {
                mostrarCategorias();
            } else if (subSecaoSalva === 'auditoria') {
                mostrarAuditoria();
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
    localStorage.setItem('secaoAtiva', secaoId);
    // Limpar subseção se não for config
    if (secaoId !== 'config') {
        localStorage.removeItem('subSecaoAtiva');
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
    xhr1.open('GET', '/crpdesk/api/controllers/categorias.php', true);
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
    xhr2.open('GET', '/crpdesk/api/controllers/agentes.php', true);
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
    xhr.open('GET', `/crpdesk/api/controllers/relatorios.php?${params}`, true);
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
                <h3>Total de Tickets</h3>
                <div class="resumo-numero">${dados.estatisticas.totalTickets}</div>
            </div>
            <div class="resumo-card">
                <h3>Tickets Encerrados</h3>
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
                        <th>Nº Ticket</th>
                        <th>Abertura</th>
                        <th>Assunto</th>
                        <th>Solicitante</th>
                        <th>Responsável</th>
                        <th>Categoria</th>
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
                    ${ticket.tem_anexos > 0 ? '<img src="images/clipe-de-anexo.png" class="icone-anexo" title="Ticket com anexos" alt="Anexo">' : ''}
                </td>
                <td>${dataAberturaFormatada}<br><small style="color: #666;">${horaAberturaFormatada}</small></td>
                <td>${ticket.titulo}</td>
                <td>${ticket.usuario_nome || 'N/A'}</td>
                <td>${ticket.agente_nome || 'Não atribuído'}</td>
                <td>${ticket.categoria_nome || 'Sem categoria'}</td>
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
    csv += 'Nº Ticket;Data Abertura;Hora Abertura;Assunto;Solicitante;Responsável;Categoria;Status;Prioridade;Data Fechamento;Hora Fechamento\n';

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
        const categoria = (ticket.categoria_nome || 'Sem categoria').replace(/;/g, ',');
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
            <title>Relatório de Tickets</title>
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
            <h1>Relatório de Tickets - CRPRS Helpdesk</h1>
            <p style="text-align: center; color: #666;">Gerado em: ${new Date().toLocaleString('pt-BR')}</p>
            
            <div class="resumo">
                <div class="resumo-card">
                    <h3>Total de Tickets</h3>
                    <div class="resumo-numero">${dados.estatisticas.totalTickets}</div>
                </div>
                <div class="resumo-card">
                    <h3>Tickets Encerrados</h3>
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
                        <th>Categoria</th>
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
                <td>${ticket.titulo}</td>
                <td>${ticket.usuario_nome || 'N/A'}</td>
                <td>${ticket.agente_nome || 'Não atribuído'}</td>
                <td>${ticket.categoria_nome || 'Sem categoria'}</td>
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

    const usuario = JSON.parse(localStorage.getItem('usuario'));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/crpdesk/api/controllers/alterar-senha.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Senha alterada com sucesso!', 'success');
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
        usuario_id: usuario.id,
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
    const usuario = localStorage.getItem('usuario');
    const token = localStorage.getItem('token');
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

    // Mostrar/ocultar seções baseado no tipo de usuário
    if (dadosUsuario.tipo === 'admin') {
        const menuConfig = document.getElementById('menu-config');
        if (menuConfig) {
            menuConfig.style.display = 'block';
        }
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
        localStorage.removeItem('secaoAtiva');
        localStorage.removeItem('subSecaoAtiva');
        localStorage.removeItem('ticketAtivo');
        localStorage.removeItem('secaoOrigem');
        carregarTickets();
    } else {
        // Acesso normal - restaurar seção salva
        const secaoSalva = localStorage.getItem('secaoAtiva');

        if (secaoSalva && secaoSalva !== 'tickets') {
            restaurarSecaoAtiva();
        } else {
            carregarTickets();
        }
    }

    // Carregar categorias por último
    carregarCategorias();

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
    const naoMostrar = localStorage.getItem('naoMostrarDicas') === 'true';
    const jaViuTutorial = localStorage.getItem('tutorialCompleto') === 'true';

    if (!naoMostrar && jaViuTutorial && dadosUsuario && (dadosUsuario.tipo === 'agente' || dadosUsuario.tipo === 'admin')) {
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
        mostrarToast('Modo Merge', 'Clique nos tickets fechados para selecioná-los', 'info');
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

    btn.textContent = '🔗 Mesclar Tickets';
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
        mostrarAlert('Erro', 'Selecione pelo menos 2 tickets para mesclar.');
        return;
    }

    const ticketPrincipal = ticketsFechadosSelecionados[0];
    const ticketsSecundarios = ticketsFechadosSelecionados.slice(1);

    mostrarConfirm(
        'Confirmar Merge',
        `Mesclar ${ticketsSecundarios.length} ticket(s) fechado(s) no ticket principal #${String(ticketPrincipal).padStart(5, '0')}?\n\n⚠️ ATENÇÃO: Esta ação não pode ser desfeita!`,
        function (confirmado) {
            if (!confirmado) return;

            mostrarLoader();

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/crpdesk/api/controllers/merge.php', true);
            xhr.setRequestHeader('Content-Type', 'application/json');

            xhr.onload = function () {
                ocultarLoader();
                if (xhr.status === 200) {
                    let response;
                    try {
                        response = JSON.parse(xhr.responseText);
                        mostrarToast('Sucesso', `${response.tickets_mesclados} ticket(s) fechado(s) mesclado(s) com sucesso!`, 'success');
                        cancelarMergeFechados();
                        carregarTicketsFechados();
                    } catch (e) {
                        mostrarAlert('Erro', 'Resposta inválida do servidor');
                    }
                } else {
                    mostrarAlert('Erro', 'Erro ao mesclar tickets fechados');
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

    localStorage.setItem('modoEscuro', isDark);

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
if (localStorage.getItem('modoEscuro') === 'true') {
    document.body.classList.add('modo-escuro');
    const btn = document.getElementById('btn-modo-escuro');
    if (btn) {
        btn.textContent = '☀️';
        btn.title = 'Modo claro';
    }
}

// Gráficos simples com Canvas
function getCorLabel() {
    return document.body.classList.contains('modo-escuro') ? '#fff' : '#333';
}

function criarGraficoStatus(dados) {
    const canvas = document.getElementById('grafico-status');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const coresStatus = {
        'novo': '#f79226',
        'aberto': '#f79226',
        'atendimento': '#17a2b8',
        'em_atendimento': '#17a2b8',
        'aguardando_resposta': '#ffc107',
        'aguardando_terceiros': '#6f42c1',
        'aguardando_aprovacao': '#fd7e14',
        'aguardando_data': '#20c997',
        'resolvido': '#28a745',
        'encerrado': '#6c757d',
        'cancelado': '#dc3545',
        'reaberto': '#e83e8c'
    };

    const labelsStatus = {
        'novo': 'Novo',
        'aberto': 'Aberto',
        'atendimento': 'Em atendimento',
        'em_atendimento': 'Em atendimento',
        'aguardando_resposta': 'Aguardando resposta',
        'aguardando_terceiros': 'Aguardando terceiros',
        'aguardando_aprovacao': 'Aguardando aprovação',
        'aguardando_data': 'Aguardando data',
        'resolvido': 'Resolvido',
        'encerrado': 'Encerrado',
        'cancelado': 'Cancelado',
        'reaberto': 'Reaberto'
    };

    const statusOrdenados = Object.keys(dados.por_status || {});
    const valores = statusOrdenados.map(s => dados.por_status[s]);
    const labels = statusOrdenados.map(s => labelsStatus[s] || s);
    const cores = statusOrdenados.map(s => coresStatus[s] || '#6c757d');

    const maxValor = Math.max(...valores) || 1;
    const alturaBarra = 30;
    const espacamento = 10;
    const margemEsquerda = 180;
    const alturaTotal = valores.length * (alturaBarra + espacamento) - espacamento;
    const offsetY = (canvas.height - alturaTotal) / 2;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    valores.forEach((valor, i) => {
        const larguraFinal = (valor / maxValor) * (canvas.width - margemEsquerda - 60);
        const y = offsetY + i * (alturaBarra + espacamento);

        // Label à esquerda
        ctx.fillStyle = getCorLabel();
        ctx.font = '13px Arial';
        ctx.textAlign = 'right';
        ctx.fillText(labels[i], margemEsquerda - 10, y + alturaBarra / 2 + 5);

        // Animar barra
        let larguraAtual = 0;
        const animacao = setInterval(() => {
            ctx.clearRect(margemEsquerda, y, canvas.width - margemEsquerda, alturaBarra);
            larguraAtual += larguraFinal / 50;
            if (larguraAtual >= larguraFinal) {
                larguraAtual = larguraFinal;
                clearInterval(animacao);
            }
            ctx.fillStyle = cores[i];
            ctx.fillRect(margemEsquerda, y, larguraAtual, alturaBarra);

            // Valor à direita da barra
            ctx.fillStyle = getCorLabel();
            ctx.font = 'bold 13px Arial';
            ctx.textAlign = 'left';
            ctx.fillText(valor, margemEsquerda + larguraAtual + 5, y + alturaBarra / 2 + 5);
        }, 20);
    });
}

function criarGraficoPrioridade(dados) {
    const canvas = document.getElementById('grafico-prioridade');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const cores = {
        'baixa': '#6c757d',
        'normal': '#71aa29',
        'alta': '#f79226',
        'urgente': '#a8231e'
    };

    const prioridades = ['urgente', 'alta', 'normal', 'baixa'];
    const valores = prioridades.map(p => dados.por_prioridade?.[p] || 0);
    const labels = ['Urgente', 'Alta', 'Normal', 'Baixa'];

    const maxValor = Math.max(...valores) || 1;
    const alturaBarra = 30;
    const espacamento = 15;
    const margemEsquerda = 100;
    const alturaTotal = valores.length * (alturaBarra + espacamento) - espacamento;
    const offsetY = (canvas.height - alturaTotal) / 2;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    valores.forEach((valor, i) => {
        const larguraFinal = (valor / maxValor) * (canvas.width - margemEsquerda - 60);
        const y = offsetY + i * (alturaBarra + espacamento);

        // Label à esquerda
        ctx.fillStyle = getCorLabel();
        ctx.font = '14px Arial';
        ctx.textAlign = 'right';
        ctx.fillText(labels[i], margemEsquerda - 10, y + alturaBarra / 2 + 5);

        // Animar barra
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

            // Valor à direita da barra
            ctx.fillStyle = getCorLabel();
            ctx.font = 'bold 14px Arial';
            ctx.textAlign = 'left';
            ctx.fillText(valor, margemEsquerda + larguraAtual + 5, y + alturaBarra / 2 + 5);
        }, 20);
    });
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

                const maxValor = Math.max(...valores) || 1;
                const margemEsquerda = 30;
                const margemDireita = 30;
                const larguraUtil = canvas.width - margemEsquerda - margemDireita;
                const stepX = larguraUtil / (valores.length - 1);

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Labels dos meses
                valores.forEach((valor, i) => {
                    const x = margemEsquerda + i * stepX;
                    ctx.fillStyle = getCorLabel();
                    ctx.font = '13px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText(meses[i], x, canvas.height - 5);
                });

                // Animar linha
                let progress = 0;
                const animacao = setInterval(() => {
                    progress += 0.02;
                    if (progress >= 1) {
                        progress = 1;
                        clearInterval(animacao);
                    }

                    ctx.clearRect(0, 0, canvas.width, canvas.height - 30);

                    const pontosVisiveis = Math.floor(valores.length * progress);

                    // Desenhar linha
                    ctx.strokeStyle = '#4B79A1';
                    ctx.lineWidth = 3;
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

                    // Desenhar pontos
                    valores.forEach((valor, i) => {
                        if (i <= pontosVisiveis) {
                            const x = margemEsquerda + i * stepX;
                            const y = canvas.height - 40 - (valor / maxValor) * (canvas.height - 60);

                            ctx.fillStyle = '#4B79A1';
                            ctx.beginPath();
                            ctx.arc(x, y, 4, 0, Math.PI * 2);
                            ctx.fill();
                        }
                    });

                    // Redesenhar labels
                    valores.forEach((valor, i) => {
                        const x = margemEsquerda + i * stepX;
                        ctx.fillStyle = getCorLabel();
                        ctx.font = '13px Arial';
                        ctx.textAlign = 'center';
                        ctx.fillText(meses[i], x, canvas.height - 5);
                    });
                }, 20);

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
        mostrarToast('Modo Merge', 'Clique nos tickets para selecioná-los', 'info');
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

    btn.textContent = '🔗 Mesclar Tickets';
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
        mostrarAlert('Erro', 'Selecione pelo menos 2 tickets para mesclar.');
        return;
    }

    const ticketPrincipal = ticketsSelecionados[0];
    const ticketsSecundarios = ticketsSelecionados.slice(1);

    mostrarConfirm(
        'Confirmar Merge',
        `Mesclar ${ticketsSecundarios.length} ticket(s) no ticket principal #${String(ticketPrincipal).padStart(5, '0')}?\n\nOs tickets secundários serão encerrados e seus comentários/anexos serão transferidos.\n\n⚠️ ATENÇÃO: Esta ação não pode ser desfeita!`,
        function (confirmado) {
            if (!confirmado) return;

            // Executar merge real
            mostrarLoader();

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/crpdesk/api/controllers/merge.php', true);
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
                    mostrarToast('Sucesso', `${response.tickets_mesclados} ticket(s) mesclado(s) com sucesso!`, 'success');

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
                        mostrarAlert('Erro', error.error || 'Erro ao mesclar tickets');
                    } catch (e) {
                        mostrarAlert('Erro', 'Erro ao mesclar tickets: ' + xhr.responseText);
                    }
                }
            };

            xhr.onerror = function () {
                ocultarLoader();
                mostrarAlert('Erro', 'Erro de conexão ao mesclar tickets');
            };

            xhr.send(JSON.stringify({
                ticket_principal: ticketPrincipal,
                tickets_secundarios: ticketsSecundarios
            }));
        }
    );
}

// Seção de Ajuda
function mostrarAjuda() {
    mostrarSecao('ajuda');
    mostrarSLA(); // Mostrar SLA por padrão
}

function mostrarSLA() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_sla = document.getElementById('btn-sla'); if (_bbtn_sla) _bbtn_sla.classList.add('active');
    localStorage.setItem('subSecaoAtiva', 'sla');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        carregarHorariosDinamicos();
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>⏰ SLA - Acordo de Nível de Serviço</h3>
            <p>O SLA (Service Level Agreement) define os <strong>tempos máximos</strong> para resposta e resolução de tickets baseado na prioridade. Os prazos são contados em <strong>horas úteis</strong> (Segunda a Sexta, 8h às 17h).</p>
            
            <h4>📊 Como Funciona o SLA</h4>
            <p>O sistema monitora automaticamente dois tipos de SLA para cada ticket:</p>
            <ul>
                <li><strong>SLA de 1ª Resposta:</strong> Tempo máximo até o primeiro atendimento do agente</li>
                <li><strong>SLA de Resolução:</strong> Tempo máximo até a resolução completa do problema</li>
            </ul>
            
            <h4>🚦 Indicadores Visuais</h4>
            <p>Os tickets exibem ícones de alerta conforme o prazo se aproxima:</p>
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
            <p>O SLA é <strong>pausado automaticamente</strong> quando o ticket está nos seguintes status:</p>
            <ul>
                <li>⏳ <strong>Aguardando resposta:</strong> Aguardando informações do solicitante</li>
                <li>🔄 <strong>Aguardando terceiros:</strong> Depende de fornecedor ou outro setor</li>
                <li>📋 <strong>Aguardando aprovação:</strong> Precisa de autorização do cliente ou gestor</li>
                <li>📅 <strong>Aguardando data:</strong> Aguardando data agendada para retomar o atendimento</li>
                <li>✅ <strong>Resolvido:</strong> Aguardando confirmação do cliente para encerrar</li>
            </ul>
            <p><em>Quando o ticket retorna ao atendimento, o SLA é retomado automaticamente, descontando o tempo pausado.</em></p>
            
            <h4>📊 Contabilização de Tickets</h4>
            <ul>
                <li>✅ <strong>Tickets Encerrados:</strong> Contabilizados nas estatísticas e relatórios</li>
                <li>❌ <strong>Tickets Cancelados:</strong> NÃO são contabilizados nas métricas de desempenho</li>
            </ul>
            <p><em>Tickets cancelados são aqueles abertos por engano ou cuja solicitação foi retirada pelo solicitante.</em></p>
            
            <h4>💡 Dicas para Cumprir o SLA</h4>
            <ul>
                <li>Atribua a prioridade correta ao criar o ticket</li>
                <li>Responda rapidamente aos tickets urgentes e de alta prioridade</li>
                <li>Use os status de "aguardando" quando depender de terceiros para pausar o SLA</li>
                <li>Mantenha o solicitante informado sobre o andamento</li>
                <li>Documente as soluções para consultas futuras</li>
                <li>Cancele apenas tickets abertos por engano (não contam nas estatísticas)</li>
            </ul>
        </div>
    `;
    }, 10);
}

function carregarHorariosDinamicos() {
    const xhr1 = new XMLHttpRequest();
    xhr1.open('GET', '/crpdesk/api/controllers/horarios-sla.php', true);
    setSecurityHeaders(xhr1);

    xhr1.onload = function () {
        if (xhr1.status === 200) {
            const horarios = JSON.parse(xhr1.responseText);

            const xhr2 = new XMLHttpRequest();
            xhr2.open('GET', '/crpdesk/api/controllers/horarios-sla.php?feriados=1', true);
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
    localStorage.setItem('subSecaoAtiva', 'status');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>📊 Status dos Tickets</h3>
            <p>Cada ticket passa por diferentes status durante seu ciclo de vida:</p>
            
            <div class="status-item">
                <span class="status-badge aberto">🆕 Aberto</span>
                <div>
                    <strong>Ticket recém-criado</strong><br>
                    O ticket foi criado, mas ainda não começou a ser tratado.
                </div>
            </div>
            
            <div class="status-item">
                <span class="status-badge em_atendimento">🔧 Em atendimento</span>
                <div>
                    <strong>Agente trabalhando ativamente</strong><br>
                    O ticket está sendo analisado ou trabalhado por um atendente do helpdesk.
                </div>
            </div>
            
            <div class="status-item">
                <span class="status-badge aguardando_resposta">⏳ Aguardando resposta</span>
                <div>
                    <strong>Aguardando informações do solicitante</strong><br>
                    O atendente precisa de mais informações do usuário/cliente para continuar.
                    <br><em style="color: #ffc107;">⏸️ SLA pausado neste status</em>
                </div>
            </div>
            
            <div class="status-item">
                <span class="status-badge aguardando_terceiros">🔄 Aguardando terceiros</span>
                <div>
                    <strong>Depende de terceiros</strong><br>
                    Quando depende de outro setor ou fornecedor externo para resolução.
                    <br><em style="color: #ffc107;">⏸️ SLA pausado neste status</em>
                </div>
            </div>
            
            <div class="status-item">
                <span class="status-badge aguardando_aprovacao">📋 Aguardando aprovação</span>
                <div>
                    <strong>Precisa de autorização</strong><br>
                    Necessário para situações em que uma solução ou ação precisa de aprovação do cliente ou gestor.
                    <br><em style="color: #ffc107;">⏸️ SLA pausado neste status</em>
                </div>
            </div>
            
            <div class="status-item">
                <span class="status-badge resolvido">✅ Resolvido</span>
                <div>
                    <strong>Solução aplicada</strong><br>
                    A solução foi aplicada, mas aguarda a confirmação do usuário/cliente para encerrar.
                </div>
            </div>
            
            <div class="status-item">
                <span class="status-badge encerrado">🏁 Encerrado</span>
                <div>
                    <strong>Ticket finalizado</strong><br>
                    O problema foi solucionado, e o atendimento foi oficialmente concluído.
                </div>
            </div>
            
            <div class="status-item">
                <span class="status-badge cancelado">❌ Cancelado</span>
                <div>
                    <strong>Ticket cancelado</strong><br>
                    O ticket foi aberto por engano, ou a solicitação foi retirada pelo solicitante.
                </div>
            </div>
            
            <div class="status-item">
                <span class="status-badge reaberto">🔄 Reaberto</span>
                <div>
                    <strong>Ticket reaberto</strong><br>
                    Quando um ticket já encerrado ou resolvido volta a apresentar problemas e precisa ser tratado novamente.
                </div>
            </div>
            
            <h4>🔄 Transições de Status</h4>
            <p><strong>Fluxo normal:</strong> Aberto → Em atendimento → Resolvido → Encerrado</p>
            <p><strong>Com interação:</strong> Aberto → Em atendimento → Aguardando resposta → Em atendimento → Encerrado</p>
            <p><strong>Reabertura:</strong> Encerrado → Reaberto → Em atendimento</p>
            <p><strong>Com aprovação:</strong> Aberto → Em atendimento → Aguardando aprovação → Resolvido → Encerrado</p>
        </div>
    `;
    }, 10);
}

function mostrarPrioridadeGuia() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_prioridade = document.getElementById('btn-prioridade'); if (_bbtn_prioridade) _bbtn_prioridade.classList.add('active');
    localStorage.setItem('subSecaoAtiva', 'prioridade');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>🔥 Prioridades dos Tickets</h3>
            <p>A prioridade determina a urgência e o SLA do ticket:</p>
            
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
    localStorage.setItem('subSecaoAtiva', 'atalhos');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>⌨️ Atalhos de Teclado</h3>
            <p>Use estes atalhos para navegar mais rapidamente pelo sistema:</p>
            
            <div class="atalho-item">
                <span>Criar novo ticket</span>
                <span class="atalho-tecla">Alt + N</span>
            </div>
            
            <div class="atalho-item">
                <span>Ir para lista de tickets</span>
                <span class="atalho-tecla">Alt + T</span>
            </div>
            
            <div class="atalho-item">
                <span>Abrir dashboard</span>
                <span class="atalho-tecla">Alt + D</span>
            </div>
            
            <div class="atalho-item">
                <span>Ver tickets fechados</span>
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
                <li>Use as <strong>setas</strong> para navegar nas listas de categorias</li>
                <li>Digite <strong>*palavra*</strong> para preservar formatação em categorias</li>
            </ul>
        </div>
    `;
    }, 10);
}

// Dica de atalhos
function mostrarDicaAtalhos() {
    const dica = document.createElement('div');
    dica.className = 'atalhos-info';
    dica.innerHTML = 'Alt+N: Novo | Alt+T: Tickets | Alt+D: Dashboard | Alt+F: Fechados';
    document.body.appendChild(dica);

    setTimeout(() => dica.classList.add('show'), 1000);
    setTimeout(() => dica.classList.remove('show'), 5000);
}
// === SISTEMA DE DICAS ===

// Função para ir para lista de tickets
function irParaTickets() {
    localStorage.removeItem('ticketAtivo');
    localStorage.removeItem('secaoAtiva');
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
    const naoMostrar = localStorage.getItem('naoMostrarDicas') === 'true';
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
    localStorage.setItem('naoMostrarDicas', checkbox.checked);
}

// Mostrar dicas automaticamente na primeira visita
function verificarPrimeiraVisita() {
    const naoMostrar = localStorage.getItem('naoMostrarDicas') === 'true';
    const jaVisitou = localStorage.getItem('jaVisitou') === 'true';

    if (!naoMostrar && !jaVisitou) {
        setTimeout(() => {
            mostrarDicas();
            localStorage.setItem('jaVisitou', 'true');
        }, 2000); // Mostrar após 2 segundos
    }
}


// Desativar usuário
function desativarUsuario(usuarioId) {
    mostrarConfirm(
        'Desativar Usuário',
        'Tem certeza que deseja desativar este usuário? Ele não aparecerá mais na lista de atribuição.',
        function (confirmado) {
            if (!confirmado) return;

            const xhr = new XMLHttpRequest();
            xhr.open('PUT', `/crpdesk/api/controllers/usuarios.php/${usuarioId}`, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            setSecurityHeaders(xhr);

            xhr.onload = function () {
                if (xhr.status === 200) {
                    mostrarToast('Sucesso', 'Usuário desativado com sucesso!', 'success');
                    carregarUsuarios();
                } else {
                    mostrarAlert('Erro', 'Erro ao desativar usuário');
                }
            };

            xhr.send(JSON.stringify({ ativo: false }));
        }
    );
}

// Ativar usuário
function ativarUsuario(usuarioId) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/crpdesk/api/controllers/usuarios.php/${usuarioId}`, true);
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
    const ticketAtual = JSON.parse(localStorage.getItem('ticketAtual') || '{}');
    const usuario = JSON.parse(localStorage.getItem('usuario'));

    // Se for agente ou admin
    if (usuario.tipo === 'agente' || usuario.tipo === 'admin') {
        // Verificar se ticket tem responsável
        if (!ticketAtual.agente_id) {
            mostrarAlert('Atenção', 'Este ticket não possui responsável. Atribua o ticket antes de adicionar comentários.');
            return false;
        }

        // Verificar se ticket tem categoria
        if (!ticketAtual.categoria_id) {
            mostrarAlert('Atenção', 'Este ticket não possui categoria. Defina uma categoria antes de adicionar comentários.');
            return false;
        }
    }

    return true;
}

// Validações antes de fechar ticket
function validarAntesFechamento(ticketId) {
    const ticketAtual = JSON.parse(localStorage.getItem('ticketAtual') || '{}');

    // Verificar se ticket tem responsável
    if (!ticketAtual.agente_id) {
        mostrarAlert('Atenção', 'Este ticket não possui responsável. Atribua o ticket antes de fechá-lo.');
        return false;
    }

    // Verificar se ticket tem categoria
    if (!ticketAtual.categoria_id) {
        mostrarAlert('Atenção', 'Este ticket não possui categoria. Defina uma categoria antes de fechá-lo.');
        return false;
    }

    return true;
}
// Função para mostrar auditoria
function mostrarAuditoria() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_auditoria = document.getElementById('btn-auditoria'); if (_bbtn_auditoria) _bbtn_auditoria.classList.add('active');
    localStorage.setItem('subSecaoAtiva', 'auditoria');

    const content = document.getElementById('config-content');
    content.innerHTML = `
        <div class="auditoria-tabs">
            <button class="tab-btn active" onclick="carregarAuditoriaGeral()">📋 Geral</button>
            <button class="tab-btn" onclick="carregarAuditoriaTickets()">🎫 Tickets</button>
            <button class="tab-btn" onclick="carregarAuditoriaLogin()">🔐 Login</button>
            <button class="tab-btn" onclick="carregarEstatisticasAudit()">📊 Estatísticas</button>
            <button class="tab-btn" onclick="carregarLogEmails()">📧 Envio de E-mails</button>
        </div>
        
        <div class="auditoria-filtros">
            <label>Data Início:</label>
            <input type="date" id="data-inicio-audit">
            <label>Data Fim:</label>
            <input type="date" id="data-fim-audit">
            <div class="categoria-container" style="position: relative; display: inline-block;">
                <input type="text" id="filtro-usuario-audit" placeholder="👤 Digite o nome do agente/admin..." autocomplete="off">
                <input type="hidden" id="filtro-usuario-id">
                <div id="lista-usuarios-audit" class="categoria-lista-form" style="display: none;"></div>
            </div>
            <button class="btn-primary" onclick="aplicarFiltrosAuditoria()">Filtrar</button>
            <button class="btn-secondary" onclick="limparFiltrosAuditoria()">Limpar</button>
            <button class="btn-primary" onclick="exportarAuditoria()">📥 Exportar CSV</button>
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
    xhr.open('GET', '/crpdesk/api/controllers/auditoria.php', true);
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
    xhr.open('GET', `/crpdesk/api/controllers/email-logs.php?${params}`, true);
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

    // --- Painel de filtros específicos ---
    const filtrosHtml = `
        <div class="email-log-filtros" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px; padding:12px; background:var(--bg-secondary, #f5f5f5); border-radius:8px;">
            <label for="email-log-periodo" style="font-size:13px; font-weight:600;">Período:</label>
            <select id="email-log-periodo" style="padding:6px 10px; border-radius:6px; border:1px solid #ddd;">
                <option value="7">Últimos 7 dias</option>
                <option value="30" selected>Últimos 30 dias</option>
                <option value="90">Últimos 90 dias</option>
                <option value="365">Último ano</option>
            </select>

            <label for="email-log-status" style="font-size:13px; font-weight:600;">Status:</label>
            <select id="email-log-status" style="padding:6px 10px; border-radius:6px; border:1px solid #ddd;">
                <option value="">Todos</option>
                <option value="sucesso">✅ Sucesso</option>
                <option value="falha">❌ Falha</option>
            </select>

            <label for="email-log-tipo" style="font-size:13px; font-weight:600;">Tipo:</label>
            <select id="email-log-tipo" style="padding:6px 10px; border-radius:6px; border:1px solid #ddd;">
                <option value="">Todos</option>
                <option value="novo_ticket">📩 Novo Ticket</option>
                <option value="comentario">💬 Comentário</option>
                <option value="notif_agente">👥 Notif. Agente</option>
                <option value="fechamento">🔒 Fechamento</option>
                <option value="reabertura">🔓 Reabertura</option>
                <option value="atribuicao">👤 Atribuição</option>
                <option value="encaminhamento">↪️ Encaminhamento</option>
                <option value="outros">📌 Outros</option>
            </select>

            <button class="btn-primary" onclick="carregarLogEmails()" style="padding:6px 14px;">🔍 Filtrar</button>
        </div>
    `;

    // --- Cards de estatísticas ---
    const taxaSucesso = parseFloat(stats?.taxa_sucesso || 0);
    const corTaxa = taxaSucesso >= 95 ? '#27ae60' : taxaSucesso >= 80 ? '#e67e22' : '#e74c3c';

    const statsHtml = `
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:20px;">
            <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:14px; text-align:center;">
                <div style="font-size:24px; font-weight:700; color:#1e3a5f;">${stats?.total || 0}</div>
                <div style="font-size:12px; color:#666; margin-top:4px;">Total Enviados</div>
            </div>
            <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:14px; text-align:center;">
                <div style="font-size:24px; font-weight:700; color:#27ae60;">${stats?.sucessos || 0}</div>
                <div style="font-size:12px; color:#666; margin-top:4px;">✅ Sucessos</div>
            </div>
            <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:14px; text-align:center;">
                <div style="font-size:24px; font-weight:700; color:#e74c3c;">${stats?.falhas || 0}</div>
                <div style="font-size:12px; color:#666; margin-top:4px;">❌ Falhas</div>
            </div>
            <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:14px; text-align:center;">
                <div style="font-size:24px; font-weight:700; color:${corTaxa};">${taxaSucesso}%</div>
                <div style="font-size:12px; color:#666; margin-top:4px;">Taxa de Sucesso</div>
            </div>
        </div>
    `;

    // --- Tabela de logs ---
    let tabelaHtml = '';
    if (!logs || logs.length === 0) {
        tabelaHtml = '<p style="color:#888; text-align:center; padding:30px;">Nenhum e-mail registrado no período.</p>';
    } else {
        const tipoLabels = {
            novo_ticket: '📩 Novo Ticket',
            comentario: '💬 Comentário',
            notif_agente: '👥 Notif. Agente',
            fechamento: '🔒 Fechamento',
            reabertura: '🔓 Reabertura',
            atribuicao: '👤 Atribuição',
            encaminhamento: '↪️ Encaminhamento',
            outros: '📌 Outros',
        };

        tabelaHtml = `
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#1e3a5f; color:#fff;">
                        <th style="padding:10px 12px; text-align:left;">Data/Hora</th>
                        <th style="padding:10px 12px; text-align:left;">Destinatário</th>
                        <th style="padding:10px 12px; text-align:left;">Assunto</th>
                        <th style="padding:10px 12px; text-align:center;">Tipo</th>
                        <th style="padding:10px 12px; text-align:center;">Ticket</th>
                        <th style="padding:10px 12px; text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${logs.map((log, i) => {
            const statusBadge = log.status === 'sucesso'
                ? '<span style="background:#e8f5e9; color:#27ae60; padding:3px 8px; border-radius:12px; font-weight:600;">✅ Sucesso</span>'
                : `<span style="background:#fdecea; color:#e74c3c; padding:3px 8px; border-radius:12px; font-weight:600;" title="${sanitizeHTML(log.erro || '')}">❌ Falha</span>`;

            const ticketLink = log.ticket_id
                ? `<span style="font-family:monospace; font-weight:600; color:#1e3a5f;">#${String(log.ticket_id).padStart(5, '0')}</span>`
                : '<span style="color:#ccc;">—</span>';

            const tipoTexto = tipoLabels[log.tipo] || log.tipo;
            const bg = i % 2 === 0 ? '#fff' : '#fafafa';
            const dataFormatada = log.enviado_em
                ? new Date(log.enviado_em).toLocaleString('pt-BR')
                : '—';

            return `
                            <tr style="background:${bg}; border-bottom:1px solid #eee;">
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
        let pagHtml = '<div style="text-align:center; margin-top:16px; display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">';
        for (let p = 1; p <= paginas; p++) {
            const ativo = p === paginaAtual ? 'background:#1e3a5f; color:#fff;' : 'background:#fff; color:#333;';
            pagHtml += `<button onclick="carregarLogEmails(${p})" style="${ativo} border:1px solid #ddd; padding:6px 12px; border-radius:6px; cursor:pointer;">${p}</button>`;
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
            'excluir_ticket': '🗑️ Exclusão de Ticket',
            'criar_usuario': '➕ Criação de Usuário',
            'atualizar_usuario': '✏️ Atualização de Usuário',
            'excluir_usuario': '🗑️ Exclusão de Usuário',
            'criar_categoria': '➕ Criação de Categoria',
            'atualizar_categoria': '✏️ Atualização de Categoria',
            'excluir_categoria': '🗑️ Exclusão de Categoria'
        };
        return acoes[acao] || acao;
    }

    function gerarCardAuditoria(registro) {

        const dados = registro.dados_anteriores ? JSON.parse(registro.dados_anteriores) :
            registro.dados_novos ? JSON.parse(registro.dados_novos) : {};

        let detalhes = '';
        if (registro.acao.includes('ticket')) {
            detalhes = `Ticket #${String(registro.registro_id).padStart(5, '0')} - ${dados.titulo || 'N/A'}`;
            // Se for exclusão de ticket, adicionar dados do solicitante
            if (registro.acao === 'excluir_ticket') {
                const solicitanteNome = dados.solicitante_nome || dados.usuario_nome || 'N/A';
                const solicitanteEmail = dados.solicitante_email || dados.usuario_email || 'N/A';
                detalhes += ` | Solicitante: ${solicitanteNome} (${solicitanteEmail})`;
            }
        } else if (registro.acao.includes('usuario')) {
            detalhes = registro.usuario_afetado || `${dados.nome || 'N/A'} (${dados.email || 'N/A'}) - ${dados.tipo || 'N/A'}`;
        } else if (registro.acao.includes('categoria')) {
            detalhes = `${dados.nome || 'N/A'}`;
        }

        return `
            <div class="usuario-card">
                <div style="display: grid; grid-template-columns: 250px 400px 180px 250px 120px 180px; gap: 1rem; align-items: center; font-size: 14px;">
                    <div><strong>Ação:</strong> ${formatarAcao(registro.acao)}</div>
                    <div><strong>Detalhes:</strong> ${detalhes}</div>
                    <div><strong>Usuário:</strong> ${registro.usuario_nome}</div>
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
                        <option value="excluir_ticket" ${acaoAtual === 'excluir_ticket' ? 'selected' : ''}>Exclusão de Tickets</option>
                        <option value="criar_usuario" ${acaoAtual === 'criar_usuario' ? 'selected' : ''}>Criação de Usuários</option>
                        <option value="atualizar_usuario" ${acaoAtual === 'atualizar_usuario' ? 'selected' : ''}>Atualização de Usuários</option>
                        <option value="excluir_usuario" ${acaoAtual === 'excluir_usuario' ? 'selected' : ''}>Exclusão de Usuários</option>
                        <option value="criar_categoria" ${acaoAtual === 'criar_categoria' ? 'selected' : ''}>Criação de Categorias</option>
                        <option value="atualizar_categoria" ${acaoAtual === 'atualizar_categoria' ? 'selected' : ''}>Atualização de Categorias</option>
                        <option value="excluir_categoria" ${acaoAtual === 'excluir_categoria' ? 'selected' : ''}>Exclusão de Categorias</option>
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
    xhr.open('GET', `/crpdesk/api/controllers/auditoria.php?${params}`, true);
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
    else if (tabAtiva.textContent.includes('Tickets')) carregarAuditoriaTickets();
    else if (tabAtiva.textContent.includes('Login')) carregarAuditoriaLogin();
}

// Carregar usuários para filtro (apenas agentes e admins)
let todosUsuariosAudit = [];

function carregarUsuariosFiltroAudit() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/usuarios.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const usuarios = JSON.parse(xhr.responseText);
            // Filtrar apenas agentes e admins (ativos e inativos)
            todosUsuariosAudit = usuarios.filter(u => u.tipo === 'agente' || u.tipo === 'admin');
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
    else if (tabAtiva.textContent.includes('Tickets')) carregarAuditoriaTickets();
    else if (tabAtiva.textContent.includes('Login')) carregarAuditoriaLogin();
    else if (tabAtiva.textContent.includes('E-mails')) carregarLogEmails();
}

// Carregar auditoria geral
function carregarAuditoriaGeral(pagina = 1) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-btn')[0].classList.add('active');

    const params = new URLSearchParams({ tipo: 'geral', pagina: pagina, limite: 50 });

    const dataInicio = document.getElementById('data-inicio-audit')?.value;
    if (dataInicio) params.append('data_inicio', dataInicio);

    const dataFim = document.getElementById('data-fim-audit')?.value;
    if (dataFim) params.append('data_fim', dataFim);

    const usuarioId = document.getElementById('filtro-usuario-id')?.value;
    if (usuarioId) params.append('usuario_id', usuarioId);

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `/crpdesk/api/controllers/auditoria-avancada.php?${params}`, true);
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
                    <div><strong>Usuário:</strong> ${reg.usuario_nome} (${reg.usuario_email})</div>
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

    const params = new URLSearchParams({ tipo: 'tickets', pagina: pagina, limite: 50 });

    const dataInicio = document.getElementById('data-inicio-audit')?.value;
    if (dataInicio) params.append('data_inicio', dataInicio);

    const dataFim = document.getElementById('data-fim-audit')?.value;
    if (dataFim) params.append('data_fim', dataFim);

    const usuarioId = document.getElementById('filtro-usuario-id')?.value;
    if (usuarioId) params.append('usuario_id', usuarioId);

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `/crpdesk/api/controllers/auditoria-avancada.php?${params}`, true);
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
        content.innerHTML = '<p>Nenhuma alteração de ticket encontrada.</p>';
        return;
    }

    let html = '<div class="auditoria-lista">';

    registros.forEach(reg => {
        html += `
            <div class="auditoria-card">
                <div class="auditoria-header">
                    <span class="auditoria-acao">Ticket #${String(reg.ticket_id).padStart(5, '0')} - ${reg.campo}</span>
                    <span class="auditoria-data">${new Date(reg.criado_em).toLocaleString('pt-BR')}</span>
                </div>
                <div class="auditoria-body">
                    <div><strong>Usuário:</strong> ${reg.usuario_nome}</div>
                    <div><strong>Ticket:</strong> ${reg.ticket_titulo || 'N/A'}</div>
                    <div class="auditoria-diff">
                        <div><strong>De:</strong> ${reg.valor_anterior || 'N/A'}</div>
                        <div><strong>Para:</strong> ${reg.valor_novo || 'N/A'}</div>
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

    const params = new URLSearchParams({ tipo: 'login', pagina: pagina, limite: 50 });

    const dataInicio = document.getElementById('data-inicio-audit')?.value;
    if (dataInicio) params.append('data_inicio', dataInicio);

    const dataFim = document.getElementById('data-fim-audit')?.value;
    if (dataFim) params.append('data_fim', dataFim);

    const usuarioId = document.getElementById('filtro-usuario-id')?.value;
    if (usuarioId) params.append('usuario_id', usuarioId);

    const xhr = new XMLHttpRequest();
    xhr.open('GET', `/crpdesk/api/controllers/auditoria-avancada.php?${params}`, true);
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
                    <div><strong>Usuário:</strong> ${reg.usuario_nome || reg.email}</div>
                    <div><strong>Email:</strong> ${reg.email}</div>
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

    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/auditoria-avancada.php?tipo=estatisticas', true);
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
                            <span>${item.nome || 'N/A'}</span>
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
                <h3>🎫 Alterações de Tickets por Campo</h3>
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
        'excluir_ticket': '🗑️ Exclusão de Ticket',
        'criar_usuario': '➕ Criação de Usuário',
        'atualizar_usuario': '✏️ Atualização de Usuário',
        'excluir_usuario': '🗑️ Exclusão de Usuário',
        'criar_categoria': '➕ Criação de Categoria',
        'atualizar_categoria': '✏️ Atualização de Categoria',
        'excluir_categoria': '🗑️ Exclusão de Categoria'
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
        tabAtiva.textContent.includes('Tickets') ? 'tickets' : 'login';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', `/crpdesk/api/controllers/auditoria-avancada.php?exportar=1&tipo=${tipo}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const dados = JSON.parse(xhr.responseText);
            baixarCSV(dados.registros, `auditoria_${tipo}_${new Date().toISOString().split('T')[0]}.csv`);
        }
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
        setTimeout(() => form.style.maxHeight = '500px', 10);
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
        setTimeout(() => form.style.maxHeight = '500px', 10);
    }
}


// Função para mostrar informações sobre os gráficos
function mostrarInfoGrafico(tipo) {
    const infos = {
        'total': {
            titulo: '🎫 Total de Tickets',
            descricao: 'Mostra o número total de tickets registrados no sistema.',
            analise: 'Use para acompanhar o volume geral de demandas. Um aumento pode indicar necessidade de mais recursos ou problemas recorrentes.'
        },
        'encerrados': {
            titulo: '✅ Tickets Encerrados',
            descricao: 'Total de tickets com status "encerrado".',
            analise: 'Indica o volume de tickets finalizados. Use para acompanhar a produtividade da equipe ao longo do tempo.'
        },
        'ativos': {
            titulo: '🔄 Tickets Ativos',
            descricao: 'Total de tickets em andamento (todos os status exceto encerrado).',
            analise: 'Mostra a carga de trabalho atual da equipe. Valores altos podem indicar sobrecarga ou necessidade de mais recursos.'
        },
        'tempo-total': {
            titulo: '⏱️ Tempo Médio Total',
            descricao: 'Tempo médio desde a abertura até o fechamento dos tickets.',
            analise: 'Indica a eficiência geral do atendimento. Valores altos podem indicar gargalos no processo ou tickets complexos.'
        },
        'primeiro-atendimento': {
            titulo: '⚡ Tempo Médio 1º Atendimento',
            descricao: 'Tempo médio até a primeira resposta do agente ao ticket.',
            analise: 'Métrica crítica de SLA. Valores altos indicam demora na resposta inicial, afetando a satisfação do usuário.'
        },
        'taxa-conclusao': {
            titulo: '✅ Taxa de Conclusão',
            descricao: 'Percentual de tickets concluídos em relação ao total.',
            analise: 'Indica a efetividade da equipe em resolver demandas. Valores baixos podem indicar tickets abandonados ou mal gerenciados.'
        },
        'status': {
            titulo: '📊 Tickets por Status',
            descricao: 'Distribuição dos tickets pelos diferentes status do sistema.',
            analise: 'Identifique gargalos: muitos tickets "Abertos" indicam falta de atribuição, muitos "Aguardando" podem indicar dependências externas.'
        },
        'prioridade': {
            titulo: '🔥 Tickets por Prioridade',
            descricao: 'Distribuição dos tickets por nível de prioridade (Urgente, Alta, Normal, Baixa).',
            analise: 'Verifique se há excesso de tickets urgentes (pode indicar problemas sistêmicos) ou se as prioridades estão bem distribuídas.'
        },
        'evolucao': {
            titulo: '📈 Evolução Mensal',
            descricao: 'Gráfico de linha mostrando a quantidade de tickets criados por mês nos últimos 12 meses.',
            analise: 'Identifique tendências: crescimento constante, sazonalidade ou picos específicos que requerem atenção.'
        },
        'agente': {
            titulo: '🥧 Distribuição por Agente',
            descricao: 'Gráfico de pizza mostrando a distribuição de tickets entre os agentes.',
            analise: 'Verifique o balanceamento da carga de trabalho. Desbalanceamento pode indicar necessidade de redistribuição ou treinamento.'
        },
        'resolucao': {
            titulo: '📊 Taxa de Resolução',
            descricao: 'Comparação entre tickets abertos e fechados.',
            analise: 'Se há mais abertos que fechados, a equipe pode estar sobrecarregada ou há problemas de eficiência.'
        },
        'dia-semana': {
            titulo: '📅 Tickets por Dia da Semana',
            descricao: 'Distribuição de tickets criados por dia da semana (Domingo a Sábado).',
            analise: 'Identifique padrões: dias com mais demanda podem requerer mais agentes disponíveis ou plantões.'
        },
        'status-agente': {
            titulo: '📊 Status por Agente',
            descricao: 'Gráfico de barras empilhadas mostrando a distribuição de status dos tickets por agente.',
            analise: 'Compare o desempenho dos agentes: quem tem mais tickets resolvidos, quem tem mais pendentes, etc.'
        },
        'funil': {
            titulo: '🔄 Funil de Conversão',
            descricao: 'Visualiza a progressão dos tickets através dos status: Aberto → Em Atendimento → Resolvido → Encerrado.',
            analise: 'Identifique gargalos: onde os tickets ficam acumulados? Muitos "Abertos" indica falta de atribuição, muitos "Em Atendimento" pode indicar complexidade ou falta de recursos.'
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
    localStorage.setItem('subSecaoAtiva', 'personalizacao');

    const content = document.getElementById('config-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
            <div class="form-usuario">
                <h3>🎨 Personalização do Sistema</h3>
                
                <p style="background: #fff3cd; padding: 0.75rem; border-radius: 4px; border-left: 4px solid #ffc107; margin-bottom: 1.5rem;">
                    ⚠️ <strong>Importante:</strong> Se as alterações não aparecerem imediatamente, atualize a página (F5 ou Ctrl+R).
                </p>
                
                <div style="margin-bottom: 2rem;">
                    <h4>📝 Título do Sistema</h4>
                    <div class="form-group">
                        <label for="titulo-header">Título (máx. 20 caracteres):</label>
                        <input type="text" id="titulo-header-input" maxlength="20" placeholder="Helpdesk CRPRS">
                    </div>
                    <button class="btn-primary" onclick="salvarTituloHeader()">💾 Salvar Título</button>
                </div>
                
                <div style="margin-bottom: 2rem;">
                    <h4>🖼️ Logo do Sistema (40x40px)</h4>
                    <p style="color: #666; font-size: 0.9rem; margin-bottom: 0.5rem;">
                        📌 O logo será aplicado em: <strong>Cabeçalho</strong>, <strong>Favicon</strong>, <strong>Loader</strong> e <strong>Modais de Recuperação de Senha</strong>
                    </p>
                    <input type="file" id="logo-header" accept="image/png" style="margin-bottom: 0.5rem;">
                    <button class="btn-primary" onclick="uploadLogoHeader()">📤 Upload Logo</button>
                </div>
            </div>
        `;

        fetch('/crpdesk/frontend/config.json')
            .then(r => r.json())
            .then(config => {
                document.getElementById('titulo-header-input').value = config.titulo || 'Helpdesk CRPRS';
            })
            .catch(() => {
                document.getElementById('titulo-header-input').value = 'Helpdesk CRPRS';
            });
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
    xhr.open('POST', '/crpdesk/api/controllers/personalizacao.php', true);
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
    xhr.open('POST', '/crpdesk/api/controllers/personalizacao.php', true);
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

function salvarTituloHeader() {
    const input = document.getElementById('titulo-header-input');
    if (!input) {
        mostrarAlert('Erro', 'Campo de título não encontrado');
        return;
    }

    const titulo = input.value.trim();
    if (!titulo) {
        mostrarAlert('Erro', 'Digite um título');
        return;
    }

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/crpdesk/api/controllers/personalizacao.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Título atualizado!', 'success');
            document.getElementById('titulo-header').textContent = titulo;
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao salvar título');
        }
    };

    xhr.send(JSON.stringify({ tipo: 'titulo', titulo: titulo }));
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
    localStorage.setItem('subSecaoAtiva', 'modo-escuro');

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
    localStorage.setItem('subSecaoAtiva', 'config-email');

    const content = document.getElementById('config-content');
    content.innerHTML = `
        <div class="config-email-container">
            <h3>📧 Configurações de Email</h3>
            <p style="color: #666; margin-bottom: 2rem;">Configure os parâmetros de conexão IMAP/SMTP para envio e recebimento de emails.</p>
            
            <form id="form-config-email" onsubmit="salvarConfigEmail(event)">
                <div class="config-section">
                    <h4>📥 Servidor IMAP (Recebimento)</h4>
                    <div class="form-group">
                        <label>Servidor IMAP:</label>
                        <input type="text" id="imap_host" required placeholder="email-ssl.com.br">
                    </div>
                    <div class="form-group">
                        <label>Porta IMAP:</label>
                        <input type="number" id="imap_port" required placeholder="993">
                    </div>
                    <div class="form-group">
                        <label>Usuário IMAP:</label>
                        <input type="email" id="imap_username" required placeholder="suporte@exemplo.com">
                    </div>
                    <div class="form-group">
                        <label>Senha IMAP:</label>
                        <input type="password" id="imap_password" placeholder="••••••••">
                        <small style="color: #666;">Deixe em branco para manter a senha atual</small>
                    </div>
                </div>
                
                <div class="config-section">
                    <h4>📤 Servidor SMTP (Envio)</h4>
                    <div class="form-group">
                        <label>Servidor SMTP:</label>
                        <input type="text" id="smtp_host" required placeholder="email-ssl.com.br">
                    </div>
                    <div class="form-group">
                        <label>Porta SMTP:</label>
                        <input type="number" id="smtp_port" required placeholder="465">
                    </div>
                    <div class="form-group">
                        <label>Usuário SMTP:</label>
                        <input type="email" id="smtp_username" required placeholder="suporte@exemplo.com">
                    </div>
                    <div class="form-group">
                        <label>Senha SMTP:</label>
                        <input type="password" id="smtp_password" placeholder="••••••••">
                        <small style="color: #666;">Deixe em branco para manter a senha atual</small>
                    </div>
                </div>
                
                <div class="config-section">
                    <h4>✉️ Remetente</h4>
                    <div class="form-group">
                        <label>Email do Remetente:</label>
                        <input type="email" id="from_email" required placeholder="suporte@exemplo.com">
                    </div>
                    <div class="form-group">
                        <label>Nome do Remetente:</label>
                        <input type="text" id="from_name" required placeholder="Suporte CRPRS">
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" class="btn-primary">💾 Salvar Configurações</button>
                    <button type="button" onclick="testarConexaoEmail()" class="btn-secondary">🔍 Testar Conexão</button>
                </div>
            </form>
        </div>
    `;

    carregarConfigEmail();
}

function carregarConfigEmail() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/config-email.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const config = JSON.parse(xhr.responseText);
            document.getElementById('imap_host').value = config.imap_host || '';
            document.getElementById('imap_port').value = config.imap_port || 993;
            document.getElementById('imap_username').value = config.imap_username || '';
            document.getElementById('smtp_host').value = config.smtp_host || '';
            document.getElementById('smtp_port').value = config.smtp_port || 465;
            document.getElementById('smtp_username').value = config.smtp_username || '';
            document.getElementById('from_email').value = config.from_email || '';
            document.getElementById('from_name').value = config.from_name || '';
        }
    };

    xhr.send();
}

function salvarConfigEmail(event) {
    event.preventDefault();

    const data = {
        imap_host: document.getElementById('imap_host').value,
        imap_port: document.getElementById('imap_port').value,
        imap_username: document.getElementById('imap_username').value,
        imap_password: document.getElementById('imap_password').value,
        smtp_host: document.getElementById('smtp_host').value,
        smtp_port: document.getElementById('smtp_port').value,
        smtp_username: document.getElementById('smtp_username').value,
        smtp_password: document.getElementById('smtp_password').value,
        from_email: document.getElementById('from_email').value,
        from_name: document.getElementById('from_name').value
    };

    const xhr = new XMLHttpRequest();
    xhr.open('PUT', '/crpdesk/api/controllers/config-email.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Configurações de email salvas com sucesso', 'success');
            document.getElementById('imap_password').value = '';
            document.getElementById('smtp_password').value = '';
        } else {
            mostrarToast('Erro', 'Erro ao salvar configurações', 'error');
        }
    };

    xhr.send(JSON.stringify(data));
}

function testarConexaoEmail() {
    const data = {
        imap_host: document.getElementById('imap_host').value,
        imap_port: document.getElementById('imap_port').value,
        imap_username: document.getElementById('imap_username').value,
        imap_password: document.getElementById('imap_password').value,
        smtp_host: document.getElementById('smtp_host').value,
        smtp_port: document.getElementById('smtp_port').value,
        smtp_username: document.getElementById('smtp_username').value,
        smtp_password: document.getElementById('smtp_password').value
    };

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/crpdesk/api/controllers/testar-email.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            const resultado = JSON.parse(xhr.responseText);

            let mensagem = '<div style="text-align: left;">';

            if (resultado.imap.sucesso) {
                mensagem += '<p style="color: #28a745;">✅ <strong>IMAP:</strong> ' + resultado.imap.mensagem + '</p>';
            } else {
                mensagem += '<p style="color: #dc3545;">❌ <strong>IMAP:</strong> ' + resultado.imap.mensagem + '</p>';
            }

            if (resultado.smtp.sucesso) {
                mensagem += '<p style="color: #28a745;">✅ <strong>SMTP:</strong> ' + resultado.smtp.mensagem + '</p>';
            } else {
                mensagem += '<p style="color: #dc3545;">❌ <strong>SMTP:</strong> ' + resultado.smtp.mensagem + '</p>';
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

    xhr.send(JSON.stringify(data));
}










// Função para enviar comentário e fechar ticket
function enviarEFecharTicket(ticketId) {
    const comentario = document.getElementById('novo-comentario').value.trim();
    if (!comentario) {
        mostrarAlert('Atenção', 'Por favor, adicione um comentário antes de fechar o ticket.');
        return;
    }
    mostrarConfirm('Enviar e Fechar Ticket', 'Deseja adicionar o comentário e encerrar este ticket?', function (confirmado) {
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
        const anexosInput = document.getElementById('anexos-comentario');
        if (anexosInput.files.length > 0) {
            for (let i = 0; i < anexosInput.files.length; i++) {
                formData.append('anexos[]', anexosInput.files[i]);
            }
        }
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/crpdesk/api/controllers/comentarios.php', true);
        setSecurityHeaders(xhr);
        xhr.onload = function () {
            if (xhr.status === 200) {
                const usuarioLogado = JSON.parse(localStorage.getItem('usuario'));
                executarAlteracaoStatus(ticketId, 'encerrado', function () {
                    ocultarLoader();
                    mostrarToast('Sucesso', 'Comentário adicionado e ticket encerrado!', 'success');
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
    iniciarMonitoramentoSessao();

    // Inicializar avatar do header
    inicializarAvatar();

    // Iniciar monitoramento de falhas de e-mail (apenas agentes/admins)
    iniciarMonitoramentoEmailFalhas();

    // Atualizar ano no footer se existir
    const anoFooter = document.getElementById('ano-atual');
    if (anoFooter) {
        anoFooter.textContent = new Date().getFullYear();
    }
});
