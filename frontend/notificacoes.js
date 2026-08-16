// === SISTEMA DE NOTIFICAÇÕES (Polling — compatível com Cloudflare) ===
// SSE substituído por polling: o Cloudflare encerra conexões long-lived com erro 524.

let _notificacoesInterval = null;

function inciarNotificacoes() {
    const token = localStorage.getItem('cd_token');
    if (!token) return;

    // Cancela polling anterior se existir
    if (_notificacoesInterval) {
        clearInterval(_notificacoesInterval);
    }

    // Busca imediata ao iniciar
    _buscarNotificacoes();

    // Polling a cada 30 segundos
    _notificacoesInterval = setInterval(_buscarNotificacoes, 30000);
}

function _buscarNotificacoes() {
    const token = localStorage.getItem('cd_token');
    if (!token) return;

    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'notificacoes.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);
                const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');

                // Para agentes e admins, incluir alertas de falha de e-mail no sino
                if (['agente', 'admin'].includes(usuario.tipo)) {
                    _mesclarAlertaEmail(data.notificacoes, data.nao_lidas);
                } else {
                    atualizarBadgeNotificacoes(data.nao_lidas);
                    renderizarNotificacoes(data.notificacoes);
                }
            } catch (e) {
                console.error('Erro ao processar notificações', e);
            }
        } else if (xhr.status === 401) {
            // Token expirou — para o polling
            clearInterval(_notificacoesInterval);
        }
    };

    xhr.onerror = function () {
        console.warn('Erro de rede ao buscar notificações. Próxima tentativa em 30s.');
    };

    xhr.send();
}

// Lê o estado de dispensa do alerta de falha de e-mail salvo no localStorage
function _getEmailFalhaDispensa() {
    try {
        return JSON.parse(localStorage.getItem('cd_email_falha_dispensada') || '{}');
    } catch (e) {
        return {};
    }
}

// Consulta email-alert.php e injeta alerta de falhas no topo da lista de notificações
function _mesclarAlertaEmail(notificacoes, naoLidas) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'email-alert.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        let todas = notificacoes;
        let total = naoLidas;

        if (xhr.status === 200) {
            try {
                const dados = JSON.parse(xhr.responseText);
                if (dados.falhas_24h > 0) {
                    const ultimaFalha = dados.ultima_falha || '';
                    const dispensada = _getEmailFalhaDispensa();

                    // Se o usuário excluiu e não há falhas mais recentes, não exibe
                    const foiExcluida = dispensada.excluida && ultimaFalha <= dispensada.excluida;
                    // Se o usuário marcou como lida e não há falhas mais recentes, exibe como lida
                    const foiLida = dispensada.lida && ultimaFalha <= dispensada.lida;

                    if (!foiExcluida) {
                        const dt = ultimaFalha
                            ? new Date(ultimaFalha).toLocaleString('pt-BR')
                            : '';
                        todas = [{
                            id: '__email_falha__',
                            titulo: `⚠️ Falhas no envio de e-mail (${dados.falhas_24h})`,
                            mensagem: `${dados.falhas_24h} falha(s) nas últimas 24h.${dt ? ' Última: ' + dt : ''} Clique para ver o log.`,
                            lida: foiLida ? 1 : 0,
                            ticket_id: null,
                            criado_em: ultimaFalha || new Date().toISOString(),
                            _tipo: 'email_falha',
                            _ultima_falha: ultimaFalha
                        }, ...notificacoes];
                        if (!foiLida) total = naoLidas + 1;
                    }
                }
            } catch (e) { /* silencioso */ }
        }

        atualizarBadgeNotificacoes(total);
        renderizarNotificacoes(todas);
    };

    xhr.onerror = function () {
        // Se falhar a consulta de e-mail, renderiza apenas as notificações normais
        atualizarBadgeNotificacoes(naoLidas);
        renderizarNotificacoes(notificacoes);
    };

    xhr.send();
}

// Marca o alerta de falha de e-mail como lido (persiste no localStorage)
function _marcarEmailFalhaLida(ultimaFalha) {
    const dispensada = _getEmailFalhaDispensa();
    dispensada.lida = ultimaFalha;
    localStorage.setItem('cd_email_falha_dispensada', JSON.stringify(dispensada));
    _buscarNotificacoes();
}

// Exclui/descarta o alerta de falha de e-mail (persiste no localStorage)
function _excluirEmailFalha(ultimaFalha) {
    const dispensada = _getEmailFalhaDispensa();
    dispensada.excluida = ultimaFalha;
    dispensada.lida = ultimaFalha; // também marca como lida
    localStorage.setItem('cd_email_falha_dispensada', JSON.stringify(dispensada));
    _buscarNotificacoes();
}

function atualizarBadgeNotificacoes(quantidade) {
    const badge = document.getElementById('badge-notificacoes');
    const btnNav = document.getElementById('btn-nav-notificacoes');
    if (!badge) return;

    if (quantidade > 0) {
        badge.textContent = quantidade > 99 ? '99+' : quantidade;
        badge.style.display = 'flex';
        badge.classList.add('pulse'); // css de pulso opcional
        if (btnNav) {
            btnNav.style.backgroundColor = 'var(--vermelho, #e53e3e)';
            btnNav.style.color = '#fff';
        }
    } else {
        badge.style.display = 'none';
        badge.textContent = '';
        if (btnNav) {
            btnNav.style.backgroundColor = '';
            btnNav.style.color = '';
        }
    }
}

function renderizarNotificacoes(notificacoes) {
    const lista = document.getElementById('lista-notificacoes');
    if (!lista) return;

    if (!notificacoes || notificacoes.length === 0) {
        lista.innerHTML = '<div style="padding: 1rem; text-align: center; color: #666;">Nenhuma notificação encontrada.</div>';
        return;
    }

    let html = '';
    notificacoes.forEach(notif => {
        if (notif._tipo === 'email_falha') {
            const foiLida = notif.lida == 1;
            const bgCor    = foiLida ? '#ffffff' : '#fffbeb';
            const bordaCor = foiLida ? '#d1d5db' : '#d97706';
            const tituloCor = foiLida ? '#555' : '#92400e';
            const msgCor    = foiLida ? '#777' : '#b45309';
            const ultimaFalhaEsc = (notif._ultima_falha || '').replace(/'/g, "\\'");

            html += `
                <div style="background: ${bgCor}; border-left: 4px solid ${bordaCor}; padding: 0.8rem; margin-bottom: 0.5rem; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <div onclick="fecharNavDropdown('notificacoes'); irParaLogEmails();" style="cursor: pointer;">
                        <div style="font-weight: 600; font-size: 0.9rem; color: ${tituloCor}; margin-bottom: 0.3rem;">${notif.titulo}</div>
                        <div style="font-size: 0.8rem; color: ${msgCor}; line-height: 1.3;">${notif.mensagem}</div>
                        <div style="font-size: 0.7rem; color: #aaa; margin-top: 0.4rem; text-align: right;">Ver log de e-mails →</div>
                    </div>
                    <div style="display: flex; gap: 0.4rem; margin-top: 0.5rem; justify-content: flex-end;">
                        ${!foiLida ? `<button onclick="event.stopPropagation(); _marcarEmailFalhaLida('${ultimaFalhaEsc}')" style="font-size: 0.7rem; padding: 2px 8px; border: 1px solid #d97706; background: transparent; color: #d97706; border-radius: 4px; cursor: pointer;">Marcar como lida</button>` : ''}
                        <button onclick="event.stopPropagation(); _excluirEmailFalha('${ultimaFalhaEsc}')" style="font-size: 0.7rem; padding: 2px 8px; border: 1px solid #dc2626; background: transparent; color: #dc2626; border-radius: 4px; cursor: pointer;">Excluir</button>
                    </div>
                </div>
            `;
        } else {
            const bgCor = notif.lida == 0 ? '#fff5f5' : '#ffffff';
            const barraCor = notif.lida == 0 ? 'var(--vermelho, #e53e3e)' : 'transparent';
            const txtCor = notif.lida == 0 ? '#c53030' : '#777';
            const tituloCor = notif.lida == 0 ? '#9b2c2c' : '#555';

            // Se a notificação tem ticket, clica nela e abre
            const onclickAction = notif.ticket_id ? `abrirDetalhesNotificacao(${notif.id}, ${notif.ticket_id})` : `marcarNotificacaoLida(${notif.id})`;

            html += `
                <div onclick="${onclickAction}" style="background: ${bgCor}; border-left: 4px solid ${barraCor}; padding: 0.8rem; margin-bottom: 0.5rem; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); cursor: pointer; transition: background 0.2s;">
                    <div style="font-weight: 600; font-size: 0.9rem; color: ${tituloCor}; margin-bottom: 0.3rem;">${notif.titulo}</div>
                    <div style="font-size: 0.8rem; color: ${txtCor}; line-height: 1.3;">${notif.mensagem}</div>
                    <div style="font-size: 0.7rem; color: #aaa; margin-top: 0.4rem; text-align: right;">${new Date(notif.criado_em).toLocaleString('pt-BR')}</div>
                </div>
            `;
        }
    });

    lista.innerHTML = html;
}

function marcarNotificacaoLida(id) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', window.APP_CONFIG.apiBase + 'notificacoes.php?id=' + id, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            _buscarNotificacoes(); // Atualiza badge e lista imediatamente
        }
    };
    xhr.send();
}

function marcarTodasComoLidas() {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', window.APP_CONFIG.apiBase + 'notificacoes.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            _buscarNotificacoes(); // Atualiza badge e lista imediatamente
        }
    };
    xhr.send();
}

function abrirDetalhesNotificacao(notifId, ticketId) {
    marcarNotificacaoLida(notifId);

    // Fechar dropdown de notificacoes
    fecharNavDropdown('notificacoes');

    // Abrir detalhe do ticket via função principal do script.js
    if (typeof verDetalhes === 'function') {
        verDetalhes(ticketId);
    } else {
        console.warn("Função verDetalhes não encontrada.");
    }
}

function excluirTodasNotificacoes() {
    const xhr = new XMLHttpRequest();
    xhr.open('DELETE', window.APP_CONFIG.apiBase + 'notificacoes.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            // Limpar lista imediatamente; SSE atualizará o badge
            const lista = document.getElementById('lista-notificacoes');
            if (lista) {
                lista.innerHTML = '<div style="padding: 1rem; text-align: center; color: #666;">Nenhuma notificação encontrada.</div>';
            }
            atualizarBadgeNotificacoes(0);
        }
    };
    xhr.send();
}

// Interceptar o carregamento do usuário para inicar as notificações
document.addEventListener('DOMContentLoaded', () => {
    // Espera 2 segundos para dar tempo do login carregar o token
    setTimeout(() => {
        if (localStorage.getItem('cd_token')) {
            inciarNotificacoes();
        }
    }, 2000);
});
