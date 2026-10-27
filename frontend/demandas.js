// Demandas: lista paginada, Kanban, criação de nova demanda, merge de demandas abertas.
// Extraído de script.js na Onda 12 (Fase 12 da divisão de script.js em arquivos por domínio).
// Última onda do roteiro: as funções de edição de atributos de demanda compartilhadas com
// o Detalhe (atribuirTicket, alterarCategoria, alterarPrioridade, executarAlteracaoStatus
// etc.) ficaram propositalmente em script.js — ver plano da Onda 12.

// Sistema de anexos múltiplos (Nova Demanda)
let anexosSelecionados = [];

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
        mostrarAlert('Tipo Inválido', `Tipos não permitidos: ${sanitizeHTML(arquivosInvalidos.map(a => a.name).join(', '))}`);
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
                avisarAnexosRecusados(response.anexos_recusados);
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

// Lista de abertas: paginação, busca, filtros e ordenação no servidor (tickets.php?pagina=).
// O Kanban precisa de todas as demandas e segue carregando a lista inteira.
let paginacaoServidor = null;   // { total, totalPaginas, contadores } da última resposta paginada
let _tokenCargaLista = 0;

function usaPaginacaoServidor() {
    return modoVisualizacaoTickets !== 'kanban';
}

async function carregarPaginaServidor() {
    const token = ++_tokenCargaLista;
    const parametros = new URLSearchParams({
        escopo: 'abertas',
        pagina: String(paginaAtual),
        por_pagina: String(porPagina)
    });
    if (filtroStatusAtivo && filtroStatusAtivo !== 'todos') parametros.set('grupo', filtroStatusAtivo);
    const meus = document.getElementById('filtro-meus-tickets');
    if (meus && meus.checked) parametros.set('meus', '1');
    const busca = document.getElementById('busca-tickets');
    if (busca && busca.value.trim()) parametros.set('busca', busca.value.trim());
    if (ordemAtual.campo) {
        parametros.set('ordem', ordemAtual.campo);
        parametros.set('direcao', ordemAtual.direcao);
    }

    try {
        const dados = await Api.get(urlComSetor('tickets.php?' + parametros.toString()));
        if (token !== _tokenCargaLista) return;   // resposta de uma carga mais antiga
        paginacaoServidor = { total: dados.total, totalPaginas: dados.total_paginas, contadores: dados.contadores };
        paginaAtual = dados.pagina;
        ticketsAtuais = dados.itens;
        ticketsFiltradosPorStatus = dados.itens;
        mostrarTicketsNaTela(dados.itens);
    } catch (e) {
        if (token !== _tokenCargaLista) return;
        console.error('Erro ao carregar tickets:', e);
        document.getElementById('lista-tickets').innerHTML = '<p>Erro ao carregar demandas.</p>';
    }
}

async function carregarTickets() {
    mostrarSkeletonTickets();
    if (usaPaginacaoServidor()) {
        carregarPaginaServidor();
        return;
    }
    paginacaoServidor = null;
    try {
        const todosTickets = await Api.get(urlComSetor('tickets.php?escopo=abertas'));
        const ticketsAbertos = todosTickets.filter(ticket => ticket.status !== 'encerrado' && ticket.status !== 'cancelado');
        ticketsAtuais = ticketsAbertos;
        ticketsFiltradosPorStatus = ticketsAbertos;
        paginaAtual = 1;
        aplicarFiltroMeusTickets();
    } catch (e) {
        console.error('Erro ao carregar tickets:', e);
        document.getElementById('lista-tickets').innerHTML = '<p>Erro ao carregar demandas.</p>';
    }
}

// Estado de paginação e filtros da tabela
let paginaAtual = 1;
let porPagina = parseInt(localStorage.getItem('cd_ticketsPorPagina') || '50');
let filtroStatusAtivo = 'todos';
let ticketsFiltradosPorStatus = [];
let modoVisualizacaoTickets = localStorage.getItem('cd_ticketsModoView') || 'lista';

function aplicarFiltroMeusTickets() {
    if (usaPaginacaoServidor()) {
        paginaAtual = 1;
        carregarPaginaServidor();
        return;
    }
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

// Alerta "nova resposta" ao lado do título da lista de demandas
function atualizarAlertaNovaResposta(quantidade) {
    const botao = document.getElementById('alerta-nova-resposta');
    if (!botao) return;
    if (quantidade > 0) {
        botao.textContent = '💬 ' + quantidade + (quantidade === 1 ? ' nova resposta' : ' novas respostas') + ' do solicitante';
        botao.setAttribute('aria-label', quantidade + (quantidade === 1 ? ' demanda com nova resposta' : ' demandas com nova resposta') + '. Ativar para listar.');
        botao.hidden = false;
    } else {
        botao.hidden = true;
        botao.textContent = '';
    }
}

// Clique no alerta: mostra só as demandas com nova resposta (mesmo filtro do botão "Nova resposta")
function filtrarNovaResposta() {
    const pill = document.querySelector('.filtro-pill[data-status="nova_resposta"]');
    if (pill) aplicarFiltroPill(pill);
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

    if (usaPaginacaoServidor()) {
        carregarPaginaServidor();
        return;
    }

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
    if (usaPaginacaoServidor()) {
        carregarPaginaServidor();
        return;
    }
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
    if (usaPaginacaoServidor()) {
        carregarPaginaServidor();
        document.getElementById('lista-tickets')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }
    mostrarTicketsNaTela(ticketsFiltradosPorStatus.length > 0 ? ticketsFiltradosPorStatus : ticketsAtuais);
    document.getElementById('lista-tickets')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Converter o SLA em badge visual para a tabela. O cálculo em si (tipo/segundos_restantes/
// percentual/vencido/pausado) já vem pronto do servidor em ticket.sla (SLAService::situacao,
// Fase 8 das melhorias adiadas); depende só de formatarTempo() do status-sla.js para o texto.
function calcularTextoSLA(ticket) {
    if (!ticket || !ticket.sla) return null;

    const slaInfo = ticket.sla;
    const tipo = slaInfo.tipo === 'resposta' ? 'Resposta' : 'Resolução';

    // SLA pausado: badge neutro, sem animação, apenas informa
    if (slaInfo.pausado) {
        return { texto: `⏸ ${tipo}: pausado`, classe: 'sla-pausado' };
    }

    const pct = slaInfo.percentual;

    // Vencido
    if (slaInfo.vencido || pct >= 100) {
        const atrasadoSeg = Math.abs(slaInfo.segundos_restantes);
        const atrasadoTexto = formatarTempo(atrasadoSeg).replace('Vencido', '').trim();
        const sufixo = atrasadoTexto ? ` há ${atrasadoTexto}` : '';
        return { texto: `${tipo}: vencida${sufixo}`, classe: 'sla-vencido' };
    }

    // Crítico: ≥ 80%
    if (pct >= 80) {
        return { texto: `⚠ ${tipo}: ${formatarTempo(slaInfo.segundos_restantes)}`, classe: 'sla-critico' };
    }

    // Alerta: ≥ 50%
    if (pct >= 50) {
        return { texto: `${tipo}: ${formatarTempo(slaInfo.segundos_restantes)}`, classe: 'sla-alerta' };
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
    // A lista vem paginada do servidor; o Kanban precisa de todas: recarrega no formato do novo modo
    paginaAtual = 1;
    carregarTickets();
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
                        <span class="kanban-ticket-id">${numeroDemanda(ticket)}</span>
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
    const doServidor = paginacaoServidor && usaPaginacaoServidor();
    const totalAbertos = doServidor ? paginacaoServidor.contadores.todos : ticketsAtuais.length;
    const meusTickets = doServidor ? paginacaoServidor.contadores.meus : ticketsAtuais.filter(t => t.agente_id == usuarioLogado.id).length;
    document.getElementById('total-tickets-abertos').textContent = `(${totalAbertos})`;
    document.getElementById('total-meus-tickets').textContent = meusTickets > 0 ? `(${meusTickets})` : '';

    // Alerta visível de nova resposta (a linha vermelha da tabela pode estar fora da tela)
    atualizarAlertaNovaResposta(doServidor
        ? (paginacaoServidor.contadores.nova_resposta || 0)
        : ticketsAtuais.filter(t => t.status === 'nova_resposta').length);

    // Atualizar contadores dos pills
    if (doServidor) {
        ['todos', 'novo', 'em_atendimento', 'aguardando', 'nova_resposta', 'urgente'].forEach(chave => {
            const el = document.getElementById(`pill-count-${chave}`);
            const valor = paginacaoServidor.contadores[chave] || 0;
            if (el) el.textContent = valor > 0 ? valor : '';
        });
    } else {
        atualizarContadoresPill(ticketsAtuais);
    }

    // Salvar referência para paginação
    ticketsFiltradosPorStatus = tickets;

    if (tickets.length === 0) {
        listaTickets.innerHTML = `
            <div style="text-align:center; padding: 3rem 1rem; color: #5f5852;">
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
    const totalPaginas = doServidor ? paginacaoServidor.totalPaginas : Math.ceil(tickets.length / porPagina);
    if (!doServidor && paginaAtual > totalPaginas) paginaAtual = 1;
    const inicio = (paginaAtual - 1) * porPagina;
    const fim = doServidor ? inicio + tickets.length : Math.min(inicio + porPagina, tickets.length);
    const ticketsPagina = doServidor ? tickets : tickets.slice(inicio, fim);

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
            ? `<span class="material-symbols-outlined icone-tabela" data-vg-tooltip="Esta demanda foi mesclada com outra demanda." data-vg-title="DEMANDA MESCLADA" aria-label="Mesclado" style="color:#a7241c">call_merge</span>` : '';

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
                    <a href="#" onclick="${modoMergeAtivo ? 'event.stopPropagation();' : ''} verDetalhes(${ticket.id})" class="ticket-link">${numeroDemanda(ticket)}</a>
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
                <td data-label="Status"><span class="status ${ticket.status} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); alterarStatus(${ticket.id}, ${ticket.setor_id || 'null'})"`} title="Clique para alterar">${window.STATUS_CONFIG && window.STATUS_CONFIG[ticket.status] ? window.STATUS_CONFIG[ticket.status].nome : (typeof formatarNomeStatus === 'function' ? formatarNomeStatus(ticket.status) : ticket.status)}</span></td>
                <td data-label="Prioridade"><span class="status prioridade ${ticket.prioridade || 'normal'} ${modoMergeAtivo ? '' : 'clickable'}" ${modoMergeAtivo ? '' : `onclick="event.stopPropagation(); alterarPrioridade(${ticket.id})" title="Clique para alterar"`}>${typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(ticket.prioridade) : (ticket.prioridade ? ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1).toLowerCase() : 'Normal')}</span></td>
            </tr>
        `;
    });

    // Footer: resumo + paginação
    const inicioExib = inicio + 1;
    const fimExib = fim;
    const totalExib = doServidor ? paginacaoServidor.total : tickets.length;

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
    if (usaPaginacaoServidor()) {
        carregarPaginaServidor();
        return;
    }
    mostrarTicketsNaTela(ticketsFiltradosPorStatus.length > 0 ? ticketsFiltradosPorStatus : ticketsAtuais);
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
            return `<option value="${sanitizeHTML(p.nome)}">${sanitizeHTML(nome)}</option>`;
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

// Função para limpar formulário de ticket completamente
function limparFormularioTicket() {
    console.log('🧹 Limpando formulário de ticket...');

    // Reset geral do formulário primeiro
    document.getElementById('form-ticket').reset();

    // Limpar campos específicos
    document.getElementById('titulo').value = '';
    document.getElementById('descricao').value = '';
    // Limpar editor isolado via Dispatch no Alpine (se for o formulário da main SPA)
    // O editor é um componente Alpine (Quill): usa o clearEditor() dele, que esvazia o texto, o estado
    // e o campo oculto. (Antes procurava a propriedade __x, que só existe no Alpine 2 — o Alpine 3 em
    // uso nunca limpava.) Sem Alpine, limpa o Quill direto.
    const nodoEditor = document.getElementById('editor-descricao');
    const componente = nodoEditor ? nodoEditor.closest('[x-data]') : null;
    if (componente && window.Alpine && typeof window.Alpine.$data === 'function') {
        window.Alpine.$data(componente).clearEditor();
    } else if (nodoEditor) {
        const area = nodoEditor.querySelector('.ql-editor');
        if (area) area.innerHTML = '';
    }
    const campoDescricao = document.getElementById('descricao');
    if (campoDescricao) campoDescricao.value = '';

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

// Função para mostrar tickets com atualização forçada
function mostrarTicketsComAtualizacao() {
    mostrarSecao('tickets');
    carregarTickets();
}

// WebSocket removido para PHP
// const socket = io();

// Socket listeners removidos para PHP
// socket.on('connect', function() { ... });
// socket.on('novoTicket', function(data) { ... });
// socket.on('novoComentario', function(data) { ... });

// Variáveis para ordenação e estado
let ticketsAtuais = [];

// Restaurar ordenação salva ou usar default
const _ordemSalva = JSON.parse(localStorage.getItem('cd_ticketsOrdem') || 'null');

let ordemAtual = _ordemSalva || { campo: 'id', direcao: 'desc' };

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

    if (usaPaginacaoServidor()) {
        paginaAtual = 1;
        carregarPaginaServidor();
        return;
    }

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

// Merge de Tickets
let modoMergeAtivo = false;
let ticketsSelecionados = [];

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
    const rotuloPrincipal = numeroDemanda((window.ticketsAtuais || []).find(t => t.id == ticketPrincipal) || ticketPrincipal);

    mostrarConfirm(
        'Confirmar Merge',
        `Mesclar ${ticketsSecundarios.length} demanda(s) na demanda principal ${rotuloPrincipal}?\n\nAs demandas secundárias serão encerradas e seus comentários/anexos serão transferidos.\n\n⚠️ ATENÇÃO: Esta ação não pode ser desfeita!`,
        async function (confirmado) {
            if (!confirmado) return;

            // Executar merge real
            mostrarLoader();

            try {
                const response = await Api.post('merge.php', {
                    ticket_principal: ticketPrincipal,
                    tickets_secundarios: ticketsSecundarios
                });
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
            } catch (e) {
                mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao mesclar demandas');
            } finally {
                ocultarLoader();
            }
        }
    );
}

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
