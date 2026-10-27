// === SISTEMA DE NOTIFICAÇÕES (Polling — compatível com Cloudflare) ===
// SSE substituído por polling: o Cloudflare encerra conexões long-lived com erro 524.

let _notificacoesInterval = null;

function inciarNotificacoes() {
    if (!localStorage.getItem('cd_usuario')) return;

    // Cancela polling anterior se existir
    if (_notificacoesInterval) {
        clearInterval(_notificacoesInterval);
    }

    // Busca imediata ao iniciar
    _buscarNotificacoes();

    // Polling a cada 30 segundos (só com a aba visível; ao voltar para a aba, busca na hora)
    _notificacoesInterval = setInterval(_buscarNotificacoes, 30000);
    if (!window._notificacoesVisibilidade) {
        window._notificacoesVisibilidade = true;
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && _notificacoesInterval) _buscarNotificacoes();
        });
    }
}

function _buscarNotificacoes() {
    if (!localStorage.getItem('cd_usuario')) return;
    if (document.hidden) return; // aba escondida: não consulta o servidor à toa

    Api.get('notificacoes.php').then(data => {
        // Administrador: o alerta de falha de e-mail vem na mesma resposta (alerta_email)
        if (data.alerta_email) {
            _mesclarAlertaEmail(data.notificacoes, data.nao_lidas, data.alerta_email);
        } else {
            atualizarBadgeNotificacoes(data.nao_lidas);
            renderizarNotificacoes(data.notificacoes);
        }
    }).catch(e => {
        if (e.status === 401) {
            clearInterval(_notificacoesInterval); // sessão encerrada: para o polling
        } else if (!e.status) {
            console.warn('Erro de rede ao buscar notificações. Próxima tentativa em 30s.');
        }
    });
}

// Lê o estado de dispensa do alerta de falha de e-mail salvo no localStorage
function _getEmailFalhaDispensa() {
    try {
        return JSON.parse(localStorage.getItem('cd_email_falha_dispensada') || '{}');
    } catch (e) {
        return {};
    }
}

// Injeta o alerta de falhas de e-mail (alerta_email de notificacoes.php) no topo da lista
function _mesclarAlertaEmail(notificacoes, naoLidas, dados) {
    let todas = notificacoes;
    let total = naoLidas;

    if (dados && dados.falhas_24h > 0) {
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

    atualizarBadgeNotificacoes(total);
    renderizarNotificacoes(todas);
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

// Lista do sino: <ul> de botões (teclado e leitor de tela), cores por classe (notificacoes.css, com
// modo escuro) e um ouvinte delegado em #lista-notificacoes (sem onclick no HTML montado)
function renderizarNotificacoes(notificacoes) {
    const lista = document.getElementById('lista-notificacoes');
    if (!lista) return;
    ligarAcoesNotificacoes();

    if (!notificacoes || notificacoes.length === 0) {
        lista.innerHTML = '<p class="notificacoes-vazio">Nenhuma notificação encontrada.</p>';
        return;
    }

    const itens = notificacoes.map(notif => {
        const naoLida = Number(notif.lida) === 0;
        if (notif._tipo === 'email_falha') {
            const ultima = sanitizeHTML(String(notif._ultima_falha || ''));
            return `
                <li class="notif-item notif-item--email${naoLida ? ' notif-item--nao-lida' : ''}">
                    <button type="button" class="notif-abrir" data-acao="log-emails">
                        <span class="notif-titulo">${sanitizeHTML(notif.titulo)}</span>
                        <span class="notif-mensagem">${sanitizeHTML(notif.mensagem)}</span>
                        <span class="notif-rodape">Ver log de e-mails →</span>
                    </button>
                    <span class="notif-acoes">
                        ${naoLida ? `<button type="button" class="notif-botao" data-acao="email-lida" data-ultima="${ultima}">Marcar como lida</button>` : ''}
                        <button type="button" class="notif-botao notif-botao--perigo" data-acao="email-excluir" data-ultima="${ultima}">Excluir</button>
                    </span>
                </li>`;
        }
        const quando = new Date(notif.criado_em).toLocaleString('pt-BR');
        return `
            <li class="notif-item${naoLida ? ' notif-item--nao-lida' : ''}">
                <button type="button" class="notif-abrir" data-acao="abrir" data-id="${Number(notif.id)}" data-ticket="${notif.ticket_id ? Number(notif.ticket_id) : ''}">
                    <span class="notif-titulo">${naoLida ? '<span class="sr-only">Não lida: </span>' : ''}${sanitizeHTML(notif.titulo)}</span>
                    <span class="notif-mensagem">${sanitizeHTML(notif.mensagem)}</span>
                    <span class="notif-rodape">${sanitizeHTML(quando)}</span>
                </button>
            </li>`;
    });
    lista.innerHTML = `<ul class="notif-lista">${itens.join('')}</ul>`;
}

// Ouvintes do sino: ligados uma vez (cabeçalho e lista)
function ligarAcoesNotificacoes() {
    const dropdown = document.getElementById('nav-notificacoes-dropdown');
    if (!dropdown || dropdown.dataset.ligado) return;
    dropdown.dataset.ligado = '1';
    dropdown.addEventListener('click', (ev) => {
        const alvo = ev.target.closest('[data-acao]');
        if (!alvo || !dropdown.contains(alvo)) return;
        ev.stopPropagation();
        const acao = alvo.dataset.acao;
        if (acao === 'abrir') {
            const id = Number(alvo.dataset.id);
            if (alvo.dataset.ticket) abrirDetalhesNotificacao(id, Number(alvo.dataset.ticket));
            else marcarNotificacaoLida(id);
        } else if (acao === 'log-emails') {
            fecharNavDropdown('notificacoes');
            irParaLogEmails();
        } else if (acao === 'email-lida') {
            _marcarEmailFalhaLida(alvo.dataset.ultima);
        } else if (acao === 'email-excluir') {
            _excluirEmailFalha(alvo.dataset.ultima);
        } else if (acao === 'marcar-todas') {
            marcarTodasComoLidas();
        } else if (acao === 'excluir-todas') {
            excluirTodasNotificacoes();
        }
    });
}
// Marcar uma (id) ou todas como lidas; atualiza o sino logo em seguida
function marcarNotificacaoLida(id) {
    Api.put('notificacoes.php?id=' + encodeURIComponent(id)).then(_buscarNotificacoes).catch(() => { });
}

function marcarTodasComoLidas() {
    Api.put('notificacoes.php').then(_buscarNotificacoes)
        .catch(e => mostrarToast('Erro', mensagemDeErro(e, 'Não foi possível marcar as notificações como lidas.'), 'error'));
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

// Pede confirmação antes (antes apagava todas de uma vez, sem volta)
function excluirTodasNotificacoes() {
    mostrarConfirm('Excluir notificações', 'Excluir todas as suas notificações? Isso não pode ser desfeito.', function (confirmado) {
        if (confirmado) executarExclusaoNotificacoes();
    }, true);
}

function executarExclusaoNotificacoes() {
    Api.delete('notificacoes.php').then(() => {
        const lista = document.getElementById('lista-notificacoes');
        if (lista) {
            lista.innerHTML = '<p class="notificacoes-vazio">Nenhuma notificação encontrada.</p>';
        }
        atualizarBadgeNotificacoes(0);
    }).catch(e => mostrarToast('Erro', mensagemDeErro(e, 'Não foi possível excluir as notificações.'), 'error'));
}

// Interceptar o carregamento do usuário para inicar as notificações
document.addEventListener('DOMContentLoaded', () => {
    // Espera 2 segundos para dar tempo do login carregar o usuário
    setTimeout(() => {
        if (localStorage.getItem('cd_usuario')) {
            inciarNotificacoes();
        }
    }, 2000);
});
