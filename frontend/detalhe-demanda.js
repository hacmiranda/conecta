// Detalhe da Demanda: cabeçalho, ações de fluxo, anexos, comentários.
// Extraído de script.js na Onda 11 (Fase 12 da divisão de script.js em arquivos por domínio).

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
async function repassarParaColega(ticketId) {
    let equipe;
    try {
        equipe = await Api.get('agentes.php');
    } catch (e) {
        mostrarAlert('Erro', 'Erro ao carregar colegas do setor');
        return;
    }

    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    const colegas = equipe.filter(u => u.id != usuario.id);

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
}

// Transferir a demanda para outro setor
async function transferirParaSetor(ticketId, setorAtualId) {
    let setores;
    try {
        setores = await Api.get('setores.php');
    } catch (e) {
        mostrarAlert('Erro', 'Erro ao carregar setores');
        return;
    }

    const setoresDisponiveis = setores
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
}

// Variável para controlar de onde veio o ticket
let secaoOrigem = 'tickets';

// Ver detalhes do ticket
async function verDetalhes(ticketId) {
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

    try {
        const ticket = await Api.get(`tickets.php?id=${ticketId}`);
        ocultarLoader();
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
            // Imagens no corpo do comentário: botão, colar (Ctrl+V) e arrastar; a imagem vai no e-mail
            habilitarImagensNoEditor(window.quillComentario);

            carregarLembretes(ticketId);
            if (typeof carregarSeletorMacros === 'function') carregarSeletorMacros();
        }, 100);
    } catch (e) {
        ocultarLoader();
        console.error('Erro ao carregar detalhes:', e.status, e.dados);
        mostrarAlert('Erro', 'Erro ao carregar detalhes da demanda');
    }
}

function marcarComentariosLidos(ticketId) {
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    Api.put(`comentarios.php?marcar_lido=1&ticket_id=${ticketId}&usuario_id=${usuario.id}`).catch(() => {});
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
                const ehNotaInterna = Number(item.interno) === 1;
                comentariosHtml += `
                    <div class="comentario-wrapper${ehNotaInterna ? ' comentario-interno' : ''}">
                        <div class="comentario-info">
                            <strong>💬 ${sanitizeHTML(item.autor_nome)}</strong> - ${new Date(item.criado_em).toLocaleString('pt-BR')}${ehNotaInterna ? ' <span class="selo-nota-interna">🔒 Nota interna</span>' : ''}<br>
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
                            <div style="background: #f7f4f0; padding: 0.75rem; margin: 0.5rem 0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
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
                } else if (item.acao === 'pesquisa_satisfacao') {
                    // Pedido entregue ao METRICA360 (pesquisa-satisfacao-service.php)
                    acao = '⭐ ' + sanitizeHTML(item.valor_novo);
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
                    <span class="ticket-id" style="font-size: 1.5rem;">${numeroDemanda(ticket)}</span>
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
                            <div class="anexo-item" style="background: #f7f4f0; padding: 0.75rem; margin: 0.5rem 0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
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
                        <small class="ajuda-editor">Arraste a borda inferior para aumentar a caixa. Para incluir imagem, use o botão da barra, cole (Ctrl+V) ou arraste o arquivo.</small>
                        <input type="hidden" id="novo-comentario" required>
                    </div>
                    <div class="form-group">
                        <label class="switch-label">
                            <label class="switch">
                                <input type="checkbox" id="comentario-interno" onchange="toggleComentarioInterno()">
                                <span class="slider"></span>
                            </label>
                            <span>🔒 Nota interna (não vai para o solicitante)</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="switch-label">
                            <label class="switch">
                                <input type="checkbox" id="enviar-email" onchange="toggleCampoComCopia(); verificarAnexosEmail();" ${ticket.email_resposta_ativo === false ? 'disabled' : ''}>
                                <span class="slider"></span>
                            </label>
                            <span>Enviar comentário por e-mail para o solicitante</span>
                        </label>
                        ${ticket.email_resposta_ativo === false ? '<small class="ajuda-editor">Envio de resposta por e-mail desativado neste setor.</small>' : ''}
                    </div>
                    <div class="form-group" id="campo-com-copia" style="display: none;">
                        <label class="switch-label">
                            <label class="switch">
                                <input type="checkbox" id="toggle-com-copia" onchange="toggleCampoEmailCopia()" ${ticket.email_copia_ativo === false ? 'disabled' : ''}>
                                <span class="slider"></span>
                            </label>
                            <span>Enviar cópia para outro e-mail</span>
                        </label>
                        ${ticket.email_copia_ativo === false ? '<small class="ajuda-editor">Envio de cópia por e-mail desativado neste setor.</small>' : ''}
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
                        <button type="button" class="btn-primary" id="btn-enviar-fechar" onclick="enviarEFecharTicket(${ticket.id})" style="background-color: #28a745;">✅ Enviar e Fechar</button>
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
    if (!modal) return; // só existe quando algum anexo já foi aberto nesta tela
    if (modal._blobUrl) {
        URL.revokeObjectURL(modal._blobUrl);
        modal._blobUrl = null;
    }
    modal.style.display = 'none';
}

// Nota interna nunca vai por e-mail: ao marcar, desliga e trava "Enviar por e-mail"
function toggleComentarioInterno() {
    const interno = document.getElementById('comentario-interno').checked;
    const enviarEmail = document.getElementById('enviar-email');

    enviarEmail.disabled = interno;
    if (interno && enviarEmail.checked) {
        enviarEmail.checked = false;
        toggleCampoComCopia();
    }
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

    // Evita duplo envio enquanto a requisição está em andamento
    const btnAdicionarForm = document.getElementById('btn-adicionar-comentario');
    const btnEnviarFechar = document.getElementById('btn-enviar-fechar');
    if (btnAdicionarForm?.disabled) return;
    if (btnAdicionarForm) btnAdicionarForm.disabled = true;
    if (btnEnviarFechar) btnEnviarFechar.disabled = true;
    const formComentario = document.getElementById('form-comentario');
    if (formComentario) formComentario.setAttribute('aria-busy', 'true');

    const comentario = document.getElementById('novo-comentario').value;
    const interno = document.getElementById('comentario-interno').checked;
    const enviarEmail = !interno && document.getElementById('enviar-email').checked;
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
    formData.append('interno', interno ? '1' : '0');

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
                (comCopia ? 'Comentário adicionado e e-mails na fila de envio!' : 'Comentário adicionado e e-mail na fila de envio!') :
                'Comentário adicionado com sucesso!';

            if (enviarEmail && data.email_enviado === false) {
                mostrarAlert('Atenção', 'O comentário foi salvo, mas o e-mail para o solicitante NÃO foi enviado. Verifique o endereço do solicitante e a configuração de e-mail do setor.');
            } else {
                mostrarToast('Sucesso', mensagem, 'success');
            }

            // Limpar formulário
            document.getElementById('novo-comentario').value = '';
            if (window.quillComentario) window.quillComentario.root.innerHTML = '';
            document.getElementById('comentario-interno').checked = false;
            document.getElementById('enviar-email').disabled = false;
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
                    const ehNotaInterna = Number(data.comentario.interno) === 1;
                    const wrapper = document.createElement('div');
                    wrapper.className = 'comentario-wrapper' + (ehNotaInterna ? ' comentario-interno' : '');

                    // Informações do autor (texto puro)
                    const infoDiv = document.createElement('div');
                    infoDiv.className = 'comentario-info';
                    const autorStrong = document.createElement('strong');
                    autorStrong.textContent = '💬 ' + (data.comentario.autor_nome || '');
                    infoDiv.appendChild(autorStrong);
                    infoDiv.appendChild(document.createTextNode(' - ' + new Date(data.comentario.criado_em).toLocaleString('pt-BR')));
                    if (ehNotaInterna) {
                        const seloInterno = document.createElement('span');
                        seloInterno.className = 'selo-nota-interna';
                        seloInterno.textContent = '🔒 Nota interna';
                        infoDiv.appendChild(document.createTextNode(' '));
                        infoDiv.appendChild(seloInterno);
                    }
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
                            link.style.cssText = 'color: #a7241c; text-decoration: none; background: none; border: none; cursor: pointer; padding: 0; font-size: inherit;';
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
                                btnVer.style.cssText = 'margin-left: 0.5rem; padding: 0.2rem 0.5rem; font-size: 0.8rem; cursor: pointer; border: 1px solid #a7241c; background: white; color: #a7241c; border-radius: 3px;';
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
        })
        .finally(() => {
            if (btnAdicionarForm) btnAdicionarForm.disabled = false;
            if (btnEnviarFechar) btnEnviarFechar.disabled = false;
            if (formComentario) formComentario.removeAttribute('aria-busy');
        });
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

let anexosComentarioSelecionados = [];

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

        // Evita duplo envio enquanto a requisição está em andamento
        const btnAdicionarForm = document.getElementById('btn-adicionar-comentario');
        const btnEnviarFechar = document.getElementById('btn-enviar-fechar');
        if (btnEnviarFechar?.disabled) return;
        if (btnAdicionarForm) btnAdicionarForm.disabled = true;
        if (btnEnviarFechar) btnEnviarFechar.disabled = true;

        mostrarLoader();
        const formData = new FormData();
        formData.append('comentario', comentario);
        formData.append('ticket_id', ticketId);
        const interno = document.getElementById('comentario-interno').checked;
        const enviarEmail = !interno && document.getElementById('enviar-email').checked;
        formData.append('enviar_email', enviarEmail ? '1' : '0');
        formData.append('interno', interno ? '1' : '0');
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
                if (btnAdicionarForm) btnAdicionarForm.disabled = false;
                if (btnEnviarFechar) btnEnviarFechar.disabled = false;
                mostrarAlert('Erro', 'Erro ao adicionar comentário');
            }
        };
        xhr.onerror = function () {
            ocultarLoader();
            if (btnAdicionarForm) btnAdicionarForm.disabled = false;
            if (btnEnviarFechar) btnEnviarFechar.disabled = false;
            mostrarAlert('Erro', 'Erro de conexão ao adicionar comentário');
        };
        xhr.send(formData);
    }, true);
}

// Caixas de comentário/descrição: têm altura máxima de 200 px, mas podem ser aumentadas arrastando o
// canto inferior direito. O navegador não deixa passar do max-height, então, ao segurar o canto,
// o limite é solto e a altura atual vira altura fixa (a partir daí o arraste cresce livremente).
document.addEventListener('pointerdown', function (e) {
    const caixa = e.target.closest && e.target.closest('.comentario, .ticket-detalhes .descricao-ticket');
    if (!caixa) return;
    const r = caixa.getBoundingClientRect();
    if (e.clientX >= r.right - 24 && e.clientY >= r.bottom - 24) {
        caixa.style.maxHeight = 'none';
        caixa.style.height = r.height + 'px';
    }
}, true);
