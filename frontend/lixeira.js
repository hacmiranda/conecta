/**
 * Lixeira de demandas (só o administrador acessa). Extraído de script.js na Fase 12
 * (onda 1 da divisão por domínio).
 */

function mostrarLixeira() {
    mostrarSecao('lixeira');
    carregarLixeira();
}

async function carregarLixeira() {
    const lista = document.getElementById('lista-lixeira');
    if (!lista) return;
    lista.innerHTML = '<p>Carregando...</p>';

    try {
        const demandas = await Api.get(urlComSetor('tickets.php?escopo=lixeira'));
        if (demandas.length === 0) {
            lista.innerHTML = '<div style="text-align:center; padding: 3rem 1rem; color: #5f5852;"><span class="material-symbols-outlined" style="font-size:3rem; opacity:0.4;" aria-hidden="true">delete</span><p>A lixeira está vazia.</p></div>';
            return;
        }
        let html = `<table class="tickets-tabela">
            <caption class="sr-only">Demandas na lixeira</caption>
            <thead><tr>
                <th scope="col">Nº Demanda</th><th scope="col">Assunto</th><th scope="col">Setor</th>
                <th scope="col">Status</th><th scope="col">Excluída em</th><th scope="col">Excluída por</th><th scope="col">Ações</th>
            </tr></thead><tbody>`;
        demandas.forEach(d => {
            const quando = d.excluido_em ? new Date(d.excluido_em.replace(' ', 'T')).toLocaleString('pt-BR') : '—';
            html += `<tr data-ticket-id="${Number(d.id)}">
                <td data-label="Nº Demanda">${numeroDemanda(d)}</td>
                <td data-label="Assunto">${sanitizeHTML(d.titulo)}</td>
                <td data-label="Setor">${sanitizeHTML(d.setor_nome || '—')}</td>
                <td data-label="Status">${sanitizeHTML(typeof formatarNomeStatus === 'function' ? formatarNomeStatus(d.status) : d.status)}</td>
                <td data-label="Excluída em">${quando}</td>
                <td data-label="Excluída por">${sanitizeHTML(d.excluido_por_nome || '—')}</td>
                <td data-label="Ações" class="lixeira-acoes">
                    <button class="btn-compact" onclick="secaoOrigem = 'lixeira'; verDetalhes(${Number(d.id)})">Ver</button>
                    <button class="btn-compact lixeira-restaurar" onclick="restaurarDemanda(${Number(d.id)})">Restaurar</button>
                    <button class="btn-compact lixeira-excluir" onclick="excluirDefinitivo(${Number(d.id)})">Excluir definitivamente</button>
                </td></tr>`;
        });
        html += '</tbody></table>';
        lista.innerHTML = html;
    } catch (e) {
        lista.innerHTML = '<p>Erro ao carregar a lixeira.</p>';
    }
}

function restaurarDemanda(ticketId) {
    mostrarConfirm('Restaurar Demanda', 'A demanda volta para as listas do setor, com o histórico preservado. Deseja restaurá-la?', async function (confirmado) {
        if (!confirmado) return;
        try {
            await Api.put(`tickets.php?id=${Number(ticketId)}&acao=restaurar`, {});
            mostrarToast('Sucesso', 'Demanda restaurada.', 'success');
            localStorage.removeItem('cd_ticketAtual');
            mostrarLixeira();
        } catch (e) {
            mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao restaurar a demanda');
        }
    });
}

function excluirDefinitivo(ticketId) {
    mostrarConfirm('Excluir definitivamente',
        'ATENÇÃO: a demanda, os comentários, os anexos e o histórico serão apagados para sempre e não poderão ser recuperados. Confirma?',
        async function (confirmado) {
            if (!confirmado) return;
            try {
                await Api.delete(`tickets.php?id=${Number(ticketId)}&definitivo=1`);
                mostrarToast('Sucesso', 'Demanda excluída definitivamente.', 'success');
                localStorage.removeItem('cd_ticketAtual');
                mostrarLixeira();
            } catch (e) {
                mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao excluir a demanda');
            }
        }, true);
}
