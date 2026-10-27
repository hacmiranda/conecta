/**
 * Auditoria (abas Geral/Demandas/Login/Estatísticas/Log de E-mails, filtros, exportação
 * CSV/PDF). Extraído de script.js na Fase 12 (onda 9 da divisão por domínio).
 *
 * carregarAuditoria(), filtrarAuditoria() e mostrarListaAuditoria() são uma versão antiga da
 * tela (formulário de filtro diferente), substituída pelas abas atuais. Nenhum onclick do
 * HTML gerado hoje as chama — mantidas aqui, sem remover, pela regra do projeto de nunca
 * remover função existente sem perguntar antes.
 */

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
async function carregarAuditoria() {
    try {
        const registros = await Api.get('auditoria.php');
        mostrarListaAuditoria(registros);
    } catch (e) {
        document.getElementById('config-content').innerHTML = '<p>Erro ao carregar auditoria.</p>';
    }
}

// ============================================================
// 📧 LOG DE ENVIO DE E-MAILS
// ============================================================
async function carregarLogEmails(pagina = 1) {
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

    try {
        const dados = await Api.get(`email-logs.php?${params}`);
        mostrarLogEmails(dados, pagina);
    } catch (e) {
        content.innerHTML = '<p>❌ Erro ao carregar log de e-mails.</p>';
    }
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
    const corTextoNum  = escuro ? '#f89327' : '#5e130e';
    const corBordaSep  = escuro ? '#444'    : '#eee';
    const corLinhaImpar = escuro ? '#2d2d2d' : '#fff';
    const corLinhaPar   = escuro ? '#353535' : '#fafafa';
    const corSelectBg  = escuro ? '#3d3d3d' : '#fff';
    const corSelectBorda = escuro ? '#555'  : '#ddd';
    const corSelectTxt = escuro ? '#e0e0e0' : 'inherit';
    const corFiltrosBg = escuro ? '#1e1e1e' : '#f5f5f5';
    const corTicket    = escuro ? '#f89327' : '#5e130e';
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
                <caption class="sr-only">Log de e-mails enviados</caption>
                <thead>
                    <tr style="background:#5e130e; color:#fff;">
                        <th scope="col" style="padding:10px 12px; text-align:left;">Data/Hora</th>
                        <th scope="col" style="padding:10px 12px; text-align:left;">Destinatário</th>
                        <th scope="col" style="padding:10px 12px; text-align:left;">Assunto</th>
                        <th scope="col" style="padding:10px 12px; text-align:center;">Tipo</th>
                        <th scope="col" style="padding:10px 12px; text-align:center;">Demanda</th>
                        <th scope="col" style="padding:10px 12px; text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${logs.map((log, i) => {
            const statusBadge = log.status === 'sucesso'
                ? `<span style="background:${badgeSucessoBg}; color:#27ae60; padding:3px 8px; border-radius:12px; font-weight:600;">✅ Sucesso</span>`
                : `<span style="background:${badgeFalhaBg}; color:#e74c3c; padding:3px 8px; border-radius:12px; font-weight:600;" title="${sanitizeHTML(log.erro || '')}">❌ Falha</span>`;

            const ticketLink = log.ticket_id
                ? `<span style="font-family:monospace; font-weight:600; color:${corTicket};">${numeroDemanda(log)}</span>`
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
                ? 'background:#5e130e; color:#fff;'
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
        return acoes[acao] || sanitizeHTML(acao);
    }

    function gerarCardAuditoria(registro) {

        const dados = registro.dados_anteriores ? JSON.parse(registro.dados_anteriores) :
            registro.dados_novos ? JSON.parse(registro.dados_novos) : {};

        let detalhes = '';
        if (registro.acao.includes('ticket')) {
            detalhes = `Demanda ${numeroDemanda(registro)} - ${sanitizeHTML(dados.titulo || 'N/A')}`;
            // Se for exclusão de ticket, adicionar dados do solicitante
            if (registro.acao === 'excluir_ticket') {
                const solicitanteNome = dados.solicitante_nome || dados.usuario_nome || 'N/A';
                const solicitanteEmail = dados.solicitante_email || dados.usuario_email || 'N/A';
                detalhes += ` | Solicitante: ${sanitizeHTML(solicitanteNome)} (${sanitizeHTML(solicitanteEmail)})`;
            }
        } else if (registro.acao.includes('usuario')) {
            detalhes = registro.usuario_afetado ? sanitizeHTML(registro.usuario_afetado) : `${sanitizeHTML(dados.nome || 'N/A')} (${sanitizeHTML(dados.email || 'N/A')}) - ${sanitizeHTML(dados.tipo || 'N/A')}`;
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
                    <div><strong>IP:</strong> ${sanitizeHTML(registro.ip_address || 'N/A')}</div>
                    <div><strong>Host:</strong> ${sanitizeHTML(registro.hostname || 'N/A')}</div>
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
async function filtrarAuditoria() {
    const acao = document.getElementById('filtro-acao').value;
    const dataInicio = document.getElementById('filtro-data-inicio').value;
    const dataFim = document.getElementById('filtro-data-fim').value;

    const params = new URLSearchParams();
    if (acao) params.append('acao', acao);
    if (dataInicio) params.append('data_inicio', dataInicio);
    if (dataFim) params.append('data_fim', dataFim);

    try {
        const registros = await Api.get(`auditoria.php?${params}`);
        mostrarListaAuditoria(registros);
    } catch (e) {
        // comportamento original: silencioso em erro
    }
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

async function carregarUsuariosFiltroAudit() {
    try {
        const usuarios = await Api.get('usuarios.php');
        // Filtrar apenas funcionários internos (ativos e inativos)
        todosUsuariosAudit = usuarios.filter(u => ['operador', 'gestor', 'admin'].includes(u.tipo));
        configurarBuscaUsuarioAudit();
    } catch (e) {
        // comportamento original: silencioso em erro
    }
}

// Configurar busca de usuário
function configurarBuscaUsuarioAudit() {
    const input = document.getElementById('filtro-usuario-audit');
    const lista = document.getElementById('lista-usuarios-audit');
    const inputId = document.getElementById('filtro-usuario-id');

    if (!input || !lista) return;

    // Cada configuração cancela os ouvintes da anterior (antes se acumulavam a cada abertura da tela:
    // as setas pulavam vários itens e o clique fora disparava N vezes)
    if (input._ouvintes) input._ouvintes.abort();
    input._ouvintes = new AbortController();
    const ouvintes = { signal: input._ouvintes.signal };

    // Mostrar lista ao focar
    input.addEventListener('focus', function () {
        if (todosUsuariosAudit.length > 0) {
            renderizarUsuariosAudit(todosUsuariosAudit);
            lista.style.display = 'block';
        }
    }, ouvintes);

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
    }, ouvintes);

    // Fechar ao clicar fora
    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !lista.contains(e.target)) {
            lista.style.display = 'none';
        }
    }, ouvintes);
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
async function carregarAuditoriaGeral(pagina = 1) {
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

    try {
        const dados = await Api.get(`auditoria-avancada.php?${params}`);
        mostrarRegistrosAuditoria(dados.registros);
        mostrarPaginacaoAudit(dados.pagina, dados.total_paginas, 'carregarAuditoriaGeral');
    } catch (e) {
        // comportamento original: silencioso em erro
    }
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
                    ${reg.usuario_afetado ? `<div><strong>Usuário Afetado:</strong> ${sanitizeHTML(reg.usuario_afetado)}</div>` : ''}
                    <div><strong>IP:</strong> ${sanitizeHTML(reg.ip_address || 'N/A')} | <strong>Host:</strong> ${sanitizeHTML(reg.hostname || 'N/A')}</div>
                    <div><strong>Navegador:</strong> ${formatarUserAgent(reg.user_agent)}</div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    content.innerHTML = html;
}

// Carregar auditoria de tickets
async function carregarAuditoriaTickets(pagina = 1) {
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

    try {
        const dados = await Api.get(`auditoria-avancada.php?${params}`);
        mostrarRegistrosTicketsAudit(dados.registros);
        mostrarPaginacaoAudit(dados.pagina, dados.total_paginas, 'carregarAuditoriaTickets');
    } catch (e) {
        // comportamento original: silencioso em erro
    }
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
                    <span class="auditoria-acao">Demanda ${numeroDemanda(reg)} - ${sanitizeHTML(reg.campo)}</span>
                    <span class="auditoria-data">${new Date(reg.criado_em).toLocaleString('pt-BR')}</span>
                </div>
                <div class="auditoria-body">
                    <div><strong>Usuário:</strong> ${sanitizeHTML(reg.usuario_nome)}</div>
                    <div><strong>Demanda:</strong> ${sanitizeHTML(reg.ticket_titulo || 'N/A')}</div>
                    <div class="auditoria-diff">
                        <div><strong>De:</strong> ${sanitizeHTML(reg.valor_anterior || 'N/A')}</div>
                        <div><strong>Para:</strong> ${sanitizeHTML(reg.valor_novo || 'N/A')}</div>
                    </div>
                    <div><strong>IP:</strong> ${sanitizeHTML(reg.ip_address || 'N/A')} | <strong>Host:</strong> ${sanitizeHTML(reg.hostname || 'N/A')}</div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    content.innerHTML = html;
}

// Carregar auditoria de login
async function carregarAuditoriaLogin(pagina = 1) {
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

    try {
        const dados = await Api.get(`auditoria-avancada.php?${params}`);
        mostrarRegistrosLoginAudit(dados.registros);
        mostrarPaginacaoAudit(dados.pagina, dados.total_paginas, 'carregarAuditoriaLogin');
    } catch (e) {
        // comportamento original: silencioso em erro
    }
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
                    <div><strong>E-mail:</strong> ${sanitizeHTML(reg.email)}</div>
                    <div><strong>IP:</strong> ${sanitizeHTML(reg.ip_address || 'N/A')} | <strong>Host:</strong> ${sanitizeHTML(reg.hostname || 'N/A')}</div>
                    <div><strong>Navegador:</strong> ${formatarUserAgent(reg.user_agent)}</div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    content.innerHTML = html;
}

// Carregar estatísticas
async function carregarEstatisticasAudit() {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-btn')[3].classList.add('active');

    // Restaura o filtro superior ao voltar para esta aba
    const filtroSuperior = document.querySelector('.auditoria-filtros');
    if (filtroSuperior) filtroSuperior.style.display = '';

    try {
        const dados = await Api.get('auditoria-avancada.php?tipo=estatisticas');
        mostrarEstatisticasAudit(dados);
    } catch (e) {
        // comportamento original: silencioso em erro
    }
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
                            <span>${sanitizeHTML(item.campo)}</span>
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
    return acoes[acao] || sanitizeHTML(acao);
}

function formatarAcaoLogin(acao) {
    const acoes = {
        'login_sucesso': 'Login Bem-sucedido',
        'login_falha': 'Tentativa de Login Falhou',
        'logout': 'Saída do sistema'
    };
    return acoes[acao] || sanitizeHTML(acao);
}

function formatarUserAgent(ua) {
    if (!ua) return 'N/A';
    if (ua.includes('Chrome')) return '🌐 Chrome';
    if (ua.includes('Firefox')) return '🦊 Firefox';
    if (ua.includes('Safari')) return '🧭 Safari';
    if (ua.includes('Edge')) return '🌊 Edge';
    return sanitizeHTML(ua.substring(0, 50)) + '...';
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
async function exportarAuditoria() {
    const tabAtiva = document.querySelector('.tab-btn.active');
    const tipo = tabAtiva.textContent.includes('Geral') ? 'geral' :
        tabAtiva.textContent.includes('Demandas') ? 'tickets' : 'login';

    try {
        const dados = await Api.post(`auditoria-avancada.php?exportar=1&tipo=${tipo}`, {});
        baixarCSV(dados.registros, `auditoria_${tipo}_${new Date().toISOString().split('T')[0]}.csv`);
    } catch (e) {
        // comportamento original: silencioso em erro
    }
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
            border-bottom: 3px solid #a7241c;
            margin-bottom: 14px;
        }
        .cabecalho-pdf .logo-bloco { display: flex; flex-direction: column; gap: 2px; }
        .cabecalho-pdf .titulo-principal {
            font-size: 17px; font-weight: 800;
            color: #a7241c; letter-spacing: -0.3px;
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
        thead tr { background: #a7241c !important; color: #fff !important; }
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
        tbody tr:nth-child(even) td { background: #fbeceb !important; }

        /* ── Cards de estatísticas ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 14px;
            width: 100%;
        }
        .stat-card {
            border: 1px solid #f5d3d0;
            border-radius: 6px;
            padding: 10px 8px;
            text-align: center;
            background: #fbeceb !important;
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
            border-left: 3px solid #a7241c;
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

async function exportarLogEmailsCSV() {
    // Lê os filtros atuais do painel de e-mails
    const periodo = document.getElementById('email-log-periodo')?.value || 30;
    const status  = document.getElementById('email-log-status')?.value  || '';
    const tipo    = document.getElementById('email-log-tipo')?.value    || '';

    const params = new URLSearchParams({ pagina: 1, limite: 99999, periodo });
    if (status) params.append('status', status);
    if (tipo)   params.append('tipo', tipo);

    try {
        const dados = await Api.get(`email-logs.php?${params}`);
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
        mostrarToast('Erro', 'Falha ao buscar dados para exportação.', 'error');
    }
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
