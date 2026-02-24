// === SISTEMA DE NOTIFICAÇÕES (SSE / PUSH HTTP) ===

let sseSource = null;

function inciarNotificacoes() {
    const token = localStorage.getItem('token');
    if (!token) return;

    // Fecha conexão anterior se existir
    if (sseSource) {
        sseSource.close();
    }

    // Inicia a escuta contínua via Server-Sent Events (SSE)
    sseSource = new EventSource(`/crpdesk/api/controllers/notificacoes-stream.php?token=${token}`);

    // Quando o PHP mandar as atualizações (event: update)
    sseSource.addEventListener('update', function (event) {
        try {
            const data = JSON.parse(event.data);
            atualizarBadgeNotificacoes(data.nao_lidas);
            renderizarNotificacoes(data.notificacoes);
        } catch (e) {
            console.error("Erro parseando payload do SSE", e);
        }
    });

    // Tratamento de erros de conexão (O navegador tentará religar automaticamente)
    sseSource.onerror = function (err) {
        console.warn("Conexão SSE caiu ou token inválido. Re-tentando em background...", err);
        // Opcional: Se err.eventPhase == EventSource.CLOSED poderia disparar recarregamento do token
    };
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
    });

    lista.innerHTML = html;
}

function marcarNotificacaoLida(id) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', '/crpdesk/api/controllers/notificacoes.php?id=' + id, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            // carregarNotificacoes() foi removido.
            // O EventSource assumirá e re-renderizará a lista na próxima verificação background.
        }
    };
    xhr.send();
}

function marcarTodasComoLidas() {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', '/crpdesk/api/controllers/notificacoes.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            // carregarNotificacoes() foi removido.
            // O EventSource varrerá em fundo e limpará a lista.
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
    xhr.open('DELETE', '/crpdesk/api/controllers/notificacoes.php', true);
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
        if (localStorage.getItem('token')) {
            inciarNotificacoes();
        }
    }, 2000);
});
