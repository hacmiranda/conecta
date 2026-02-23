// === SISTEMA DE LEMBRETES ===

function carregarLembretes(ticketId) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `/crpdesk/api/controllers/lembretes.php?ticket_id=${ticketId}`, true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const lembretes = JSON.parse(xhr.responseText);
            mostrarLembretes(lembretes);
        }
    };

    xhr.send();
}

function mostrarLembretes(lembretes) {
    const lista = document.getElementById('lista-lembretes');

    if (lembretes.length === 0) {
        lista.innerHTML = '<p style="color: #999; font-style: italic;">Nenhum lembrete agendado.</p>';
        return;
    }

    let html = '';
    lembretes.forEach(lembrete => {
        const dataHora = new Date(lembrete.data_lembrete + ' ' + lembrete.hora_lembrete);
        const dataFormatada = dataHora.toLocaleDateString('pt-BR');
        const horaFormatada = dataHora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        const notificado = lembrete.notificado == 1;

        const dataCriacao = new Date(lembrete.criado_em);
        const dataCriacaoFormatada = dataCriacao.toLocaleString('pt-BR');

        html += `
            <div class="lembrete-card ${notificado ? 'lembrete-notificado' : ''}">
                <div class="lembrete-info">
                    <strong>📅 ${dataFormatada} às ${horaFormatada}</strong>
                    <p>${lembrete.descricao}</p>
                    <small>Responsável: ${lembrete.agente_nome || 'Não atribuído'} | Criado por: ${lembrete.criado_por_nome} em ${dataCriacaoFormatada}</small>
                    ${notificado ? '<span class="badge-notificado">✓ Notificado</span>' : ''}
                </div>
                ${!notificado ? `<button class="btn-excluir" onclick="excluirLembrete(${lembrete.id})">🗑️</button>` : ''}
            </div>
        `;
    });

    lista.innerHTML = html;
}

function mostrarModalLembrete(ticketId) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/agentes.php', true);

    xhr.onload = function () {
        if (xhr.status === 200) {
            const agentes = JSON.parse(xhr.responseText);

            let opcoesAgentes = '<option value="">Todos os agentes</option>';
            agentes.forEach(agente => {
                opcoesAgentes += `<option value="${agente.id}">${agente.nome}</option>`;
            });

            const content = `
                <form id="form-lembrete-temp" onsubmit="event.preventDefault(); window.submeterLembreteTemp();">
                    <div style="max-height: 55vh; overflow-y: auto; padding-right: 15px; padding-bottom: 5px;">
                        <div class="form-group">
                            <label for="lembrete-data">Data: <span style="color:var(--vermelho)">*</span></label>
                            <input type="date" id="lembrete-data" required>
                        </div>
                        <div class="form-group">
                            <label for="lembrete-hora">Hora: <span style="color:var(--vermelho)">*</span></label>
                            <input type="time" id="lembrete-hora" required>
                        </div>
                        <div class="form-group">
                            <label for="lembrete-descricao">Descrição: <span style="color:var(--vermelho)">*</span></label>
                            <textarea id="lembrete-descricao" rows="3" required placeholder="Ex: Retirar encaminhamento de email"></textarea>
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

            modalCallback = function (confirmado) {
                if (confirmado) {
                    const data = document.getElementById('lembrete-data').value;
                    const hora = document.getElementById('lembrete-hora').value;
                    const descricao = document.getElementById('lembrete-descricao').value;
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
        }
    };

    xhr.send();
}

function criarLembrete(ticketId, data, hora, descricao, agenteId) {
    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/crpdesk/api/controllers/lembretes.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Lembrete criado com sucesso!', 'success');
            carregarLembretes(ticketId);
        } else {
            mostrarAlert('Erro', 'Erro ao criar lembrete');
        }
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
            xhr.open('DELETE', `/crpdesk/api/controllers/lembretes.php?id=${lembreteId}`, true);
            setSecurityHeaders(xhr);

            xhr.onload = function () {
                if (xhr.status === 200) {
                    mostrarToast('Sucesso', 'Lembrete excluído!', 'success');
                    const ticketId = JSON.parse(localStorage.getItem('ticketAtual')).id;
                    carregarLembretes(ticketId);
                }
            };

            xhr.send();
        }
    );
}
