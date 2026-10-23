// Arquivo (demandas encerradas/canceladas): paginação, filtros, ordenação, merge e ações.
// Extraído de script.js na Onda 10 (Fase 12 da divisão de script.js em arquivos por domínio).

// Estado do filtro do Arquivo: 'arquivo' | 'transferidas' | 'repassadas'
let escopoArquivoAtual = 'arquivo';

// Carregar tickets fechados (encerrados e cancelados)
// Arquivo: mesma paginação no servidor da lista de abertas
let paginacaoFechadosServidor = null;   // { total, totalPaginas }
let _tokenCargaFechados = 0;

let ticketsFechadosAtuais = [];
let paginaAtualFechados = 1;
let porPaginaFechados = parseInt(localStorage.getItem('cd_ticketsFechadosPorPagina') || '50');
let ticketsFechadosFiltradosPorBusca = [];

const _ordemFechadosSalva = JSON.parse(localStorage.getItem('cd_ticketsFechadosOrdem') || 'null');
let ordemFechados = _ordemFechadosSalva || { campo: 'id', direcao: 'desc' };

let modoMergeFechadosAtivo = false;
let ticketsFechadosSelecionados = [];

let timeoutBuscaFechados;

async function carregarPaginaFechadosServidor() {
    const token = ++_tokenCargaFechados;
    const parametros = new URLSearchParams({
        escopo: escopoArquivoAtual,
        pagina: String(paginaAtualFechados),
        por_pagina: String(porPaginaFechados)
    });
    const meus = document.getElementById('filtro-meus-tickets-fechados');
    if (meus && meus.checked) parametros.set('meus', '1');
    const busca = document.getElementById('busca-fechados');
    if (busca && busca.value.trim()) parametros.set('busca', busca.value.trim());
    if (ordemFechados.campo) {
        parametros.set('ordem', ordemFechados.campo);
        parametros.set('direcao', ordemFechados.direcao);
    }

    try {
        const dados = await Api.get(urlComSetor('tickets.php?' + parametros.toString()));
        if (token !== _tokenCargaFechados) return;
        paginacaoFechadosServidor = { total: dados.total, totalPaginas: dados.total_paginas };
        paginaAtualFechados = dados.pagina;
        ticketsFechadosAtuais = dados.itens;
        mostrarTicketsFechadosNaTela(dados.itens);
    } catch (e) {
        if (token !== _tokenCargaFechados) return;
        document.getElementById('lista-fechados').innerHTML = '<p>Erro ao carregar demandas do arquivo.</p>';
    }
}

function carregarTicketsFechados() {
    mostrarSkeletonTickets('lista-fechados');
    prepararFiltroArquivo();

    // Escopo vem do filtro do Arquivo (select); o servidor aplica as regras de cada papel
    const seletorEscopo = document.getElementById('filtro-arquivo-escopo');
    if (seletorEscopo && seletorEscopo.value) {
        escopoArquivoAtual = seletorEscopo.value;
    }

    paginaAtualFechados = 1;
    carregarPaginaFechadosServidor();
}

function aplicarBuscaFechadosComDebounce() {
    clearTimeout(timeoutBuscaFechados);
    timeoutBuscaFechados = setTimeout(() => {
        aplicarFiltroMeusTicketsFechados();
    }, 300);
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
    paginaAtualFechados = 1;
    carregarPaginaFechadosServidor();
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
    carregarPaginaFechadosServidor();
    document.getElementById('lista-fechados')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;

}

function alterarPorPaginaFechados(valor) {
    porPaginaFechados = parseInt(valor);
    localStorage.setItem('cd_ticketsFechadosPorPagina', porPaginaFechados);
    paginaAtualFechados = 1;
    carregarPaginaFechadosServidor();
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
            <div style="text-align:center; padding: 3rem 1rem; color: #5f5852;">
                <span class="material-symbols-outlined" style="font-size:3rem; opacity:0.4;">inbox</span>
                <p style="margin-top:0.5rem; font-size:0.95rem;">Nenhuma demanda fechada encontrada para este filtro.</p>
            </div>`;
        return;
    }

    // Paginação (feita no servidor: "tickets" já é a página)
    const totalPaginas = paginacaoFechadosServidor ? paginacaoFechadosServidor.totalPaginas : Math.ceil(tickets.length / porPaginaFechados);
    const inicio = (paginaAtualFechados - 1) * porPaginaFechados;
    const fim = paginacaoFechadosServidor ? inicio + tickets.length : Math.min(inicio + porPaginaFechados, tickets.length);
    const ticketsPagina = paginacaoFechadosServidor ? tickets : tickets.slice(inicio, fim);

    let tabelaHTML = `
        <table class="tickets-tabela">
            <caption class="sr-only">Demandas do Arquivo</caption>
            <thead>
                <tr>
                    <th onclick="ordenarTabelaFechados('id')" class="sortable" data-campo-fechado="id" scope="col">Nº Demanda <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('criado_em')" class="sortable" data-campo-fechado="criado_em" scope="col">Abertura <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('titulo')" class="sortable" data-campo-fechado="titulo" scope="col">Assunto <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('usuario_nome')" class="sortable" data-campo-fechado="usuario_nome" scope="col">Solicitante <span class="sort-arrows">↕</span></th>
                    ${exibirColunaSetor(true) ? '<th onclick="ordenarTabelaFechados(\'setor_nome\')" class="sortable" data-campo-fechado="setor_nome" scope="col">Setor <span class="sort-arrows">↕</span></th>' : ''}
                    <th onclick="ordenarTabelaFechados('agente_nome')" class="sortable" data-campo-fechado="agente_nome" scope="col">Responsável <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('categoria_nome')" class="sortable" data-campo-fechado="categoria_nome" scope="col">Subcategoria <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('status')" class="sortable" data-campo-fechado="status" scope="col">Status <span class="sort-arrows">↕</span></th>
                    <th onclick="ordenarTabelaFechados('fechado_em')" class="sortable" data-campo-fechado="fechado_em" style="white-space: nowrap;" scope="col">Encerramento <span class="sort-arrows">↕</span></th>
                    <th scope="col">Ações</th>
                </tr>
            </thead>
            <tbody>
    `;

    ticketsPagina.forEach(ticket => {
        // Ícones Material Symbols
        const iconeAnexo = ticket.tem_anexos > 0
            ? `<span class="material-symbols-outlined icone-tabela icone-anexo-ms" data-vg-tooltip="Esta demanda possui arquivos anexados." data-vg-title="ANEXOS" aria-label="Tem anexos">attach_file</span>` : '';
        const iconeMesclado = ticket.eh_mesclado > 0
            ? `<span class="material-symbols-outlined icone-tabela" data-vg-tooltip="Esta demanda foi mesclada com outra demanda." data-vg-title="DEMANDA MESCLADA" aria-label="Mesclado" style="color:#a7241c">call_merge</span>` : '';

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
    const totalExib = paginacaoFechadosServidor ? paginacaoFechadosServidor.total : tickets.length;

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

    paginaAtualFechados = 1;
    carregarPaginaFechadosServidor();
}

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
        async function (confirmado) {
            if (!confirmado) return;

            mostrarLoader();

            try {
                const response = await Api.post('merge.php', {
                    ticket_principal: ticketPrincipal,
                    tickets_secundarios: ticketsSecundarios
                });
                mostrarToast("Sucesso", `${response.tickets_mesclados} demanda(s) fechada(s) mesclada(s) com sucesso!`, "success");
                cancelarMergeFechados();
                carregarTicketsFechados();
            } catch (e) {
                mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao mesclar demandas fechadas');
            } finally {
                ocultarLoader();
            }
        }
    );
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
    paginaAtualFechados = 1;
    carregarPaginaFechadosServidor();
}

function limparBuscaFechados() {
    document.getElementById('busca-fechados').value = '';
    paginaAtualFechados = 1;
    carregarPaginaFechadosServidor();
}
