// === SISTEMA DE LEMBRETES ===
// O envio é feito pelo agendador do servidor (api/cron/verificar-lembretes.php).

function carregarLembretes(ticketId) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${window.APP_CONFIG.apiBase}lembretes.php?ticket_id=${encodeURIComponent(ticketId)}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            mostrarLembretes(JSON.parse(xhr.responseText));
        }
    };

    xhr.send();
}

function mostrarLembretes(lembretes) {
    const lista = document.getElementById('lista-lembretes');
    if (!lista) return;

    if (lembretes.length === 0) {
        lista.innerHTML = '<p style="color: #999; font-style: italic;">Nenhum lembrete agendado.</p>';
        return;
    }

    let html = '';
    lembretes.forEach(lembrete => {
        const dataHora = new Date(lembrete.data_lembrete + 'T' + lembrete.hora_lembrete);
        const dataFormatada = dataHora.toLocaleDateString('pt-BR');
        const horaFormatada = dataHora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        const notificado = lembrete.notificado == 1;
        const cancelado = !!lembrete.cancelado_em;
        const dataCriacaoFormatada = new Date(lembrete.criado_em).toLocaleString('pt-BR');

        let selo = '';
        if (cancelado) {
            selo = '<span class="badge-cancelado">Cancelado</span>';
        } else if (notificado) {
            selo = '<span class="badge-notificado">✓ Notificado</span>';
        }

        html += `
            <div class="lembrete-card ${notificado ? 'lembrete-notificado' : ''} ${cancelado ? 'lembrete-cancelado' : ''}">
                <div class="lembrete-info">
                    <strong>📅 ${dataFormatada} às ${horaFormatada}</strong>${selo}
                    <p>${sanitizeHTML(lembrete.descricao)}</p>
                    <small>Notificar: ${sanitizeHTML(lembrete.agente_nome || 'responsável da demanda (ou gestores do setor)')} | Criado por: ${sanitizeHTML(lembrete.criado_por_nome)} em ${dataCriacaoFormatada}</small>
                    ${cancelado && lembrete.cancelado_motivo ? `<small class="lembrete-motivo">Motivo: ${sanitizeHTML(lembrete.cancelado_motivo)}</small>` : ''}
                </div>
                ${!notificado && !cancelado ? `<button class="btn-excluir" onclick="excluirLembrete(${Number(lembrete.id)})" aria-label="Excluir lembrete de ${dataFormatada} às ${horaFormatada}">🗑️</button>` : ''}
            </div>
        `;
    });

    lista.innerHTML = html;
}

// Setor da demanda aberta (para listar só a equipe daquele setor)
function setorDaDemandaAtual() {
    try {
        const ticket = JSON.parse(sessionStorage.getItem('cd_ticketAtual') || '{}');
        return ticket.setor_id ? Number(ticket.setor_id) : null;
    } catch (e) {
        return null;
    }
}

function mostrarModalLembrete(ticketId) {
    const setorId = setorDaDemandaAtual();
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.APP_CONFIG.apiBase + 'agentes.php' + (setorId ? `?setor_id=${setorId}` : ''), true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status !== 200) {
            mostrarAlert('Erro', 'Não foi possível carregar a equipe do setor.');
            return;
        }

        const agentes = JSON.parse(xhr.responseText)
            .filter(agente => !setorId || Number(agente.setor_id) === setorId);

        let opcoesAgentes = '<option value="">Responsável da demanda (ou gestores do setor)</option>';
        agentes.forEach(agente => {
            opcoesAgentes += `<option value="${Number(agente.id)}">${sanitizeHTML(agente.nome)}</option>`;
        });

        const hoje = new Date();
        const hojeIso = `${hoje.getFullYear()}-${String(hoje.getMonth() + 1).padStart(2, '0')}-${String(hoje.getDate()).padStart(2, '0')}`;

        const content = `
            <form id="form-lembrete-temp" onsubmit="event.preventDefault(); window.submeterLembreteTemp();">
                <div style="max-height: 55vh; overflow-y: auto; padding-right: 15px; padding-bottom: 5px;">
                    <div class="form-group">
                        <label for="lembrete-data">Data: <span style="color:var(--vermelho)" aria-hidden="true">*</span></label>
                        <input type="date" id="lembrete-data" min="${hojeIso}" required>
                    </div>
                    <div class="form-group">
                        <label for="lembrete-hora">Hora: <span style="color:var(--vermelho)" aria-hidden="true">*</span></label>
                        <input type="time" id="lembrete-hora" required>
                    </div>
                    <div class="form-group">
                        <label for="lembrete-descricao">Descrição: <span style="color:var(--vermelho)" aria-hidden="true">*</span></label>
                        <textarea id="lembrete-descricao" rows="3" maxlength="500" required placeholder="Ex: Retirar encaminhamento de email"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="lembrete-agente">Notificar:</label>
                        <select id="lembrete-agente">
                            ${opcoesAgentes}
                        </select>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap: 10px; margin-top: 15px; padding-top: 10px; border-top: 1px solid #eee;">
                    <button type="button" class="btn-voltar" onclick="promptModal(null)">Cancelar</button>
                    <button type="submit" class="btn-primary">Criar Lembrete</button>
                </div>
            </form>
        `;

        document.getElementById('modal-prompt-message').innerHTML = content;
        document.getElementById('modal-prompt-title').textContent = '🔔 Adicionar Lembrete';
        document.getElementById('modal-prompt-input').style.display = 'none';

        // Oculta o footer genérico do prompt para deixar o botão do form interceptar o required do navegador e dar scroll
        const modalFooter = document.querySelector('#modal-prompt .modal-footer');
        if (modalFooter) modalFooter.style.display = 'none';

        window.submeterLembreteTemp = function () {
            // Só dispara se o form passou na validação nativa de required
            promptModal(true);
        };

        document.getElementById('modal-overlay').classList.add('show');
        document.getElementById('modal-prompt').classList.add('show');
        setTimeout(() => document.getElementById('lembrete-data')?.focus(), 100);

        modalCallback = function (confirmado) {
            if (confirmado) {
                const data = document.getElementById('lembrete-data').value;
                const hora = document.getElementById('lembrete-hora').value;
                const descricao = document.getElementById('lembrete-descricao').value.trim();
                const agenteId = document.getElementById('lembrete-agente').value || null;

                if (data && hora && descricao) {
                    criarLembrete(ticketId, data, hora, descricao, agenteId);
                }
            }

            // Limpeza e Restauração do layout padrão do modal prompt
            document.getElementById('modal-prompt-input').style.display = 'block';
            document.getElementById('modal-prompt-message').innerHTML = '';
            if (modalFooter) modalFooter.style.display = 'flex';
            delete window.submeterLembreteTemp;
        };
    };

    xhr.send();
}

function criarLembrete(ticketId, data, hora, descricao, agenteId) {
    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'lembretes.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Lembrete criado com sucesso!', 'success');
            carregarLembretes(ticketId);
        } else {
            let mensagem = 'Erro ao criar lembrete';
            try { mensagem = JSON.parse(xhr.responseText).error || mensagem; } catch (e) { /* resposta sem JSON */ }
            mostrarAlert('Erro', mensagem);
        }
    };

    xhr.onerror = function () {
        ocultarLoader();
        mostrarAlert('Erro', 'Falha de conexão ao criar o lembrete.');
    };

    xhr.send(JSON.stringify({
        ticket_id: ticketId,
        data_lembrete: data,
        hora_lembrete: hora,
        descricao: descricao,
        agente_id: agenteId
    }));
}

function excluirLembrete(lembreteId) {
    mostrarConfirm(
        'Excluir Lembrete',
        'Tem certeza que deseja excluir este lembrete?',
        function (confirmado) {
            if (!confirmado) return;

            const xhr = new XMLHttpRequest();
            xhr.open('DELETE', `${window.APP_CONFIG.apiBase}lembretes.php?id=${encodeURIComponent(lembreteId)}`, true);
            setSecurityHeaders(xhr);

            xhr.onload = function () {
                if (xhr.status === 200) {
                    mostrarToast('Sucesso', 'Lembrete excluído!', 'success');
                    const ticketId = JSON.parse(sessionStorage.getItem('cd_ticketAtual')).id;
                    carregarLembretes(ticketId);
                } else {
                    let mensagem = 'Erro ao excluir lembrete';
                    try { mensagem = JSON.parse(xhr.responseText).error || mensagem; } catch (e) { /* resposta sem JSON */ }
                    mostrarAlert('Erro', mensagem);
                }
            };

            xhr.send();
        }
    );
}
