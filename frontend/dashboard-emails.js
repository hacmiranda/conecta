/**
 * Dashboard → E-mails Enviados (19/11/2026): só quantitativos das respostas da equipe enviadas ao
 * solicitante por e-mail, por setor e por funcionário (gestor e operador): ontem, últimos 7 dias,
 * últimos 30 dias e o período escolhido no filtro único do Dashboard (dashboard.js).
 * API: dashboard-emails.php (segue o seletor de setor da barra lateral via urlComSetor).
 * Chamado pelo Dashboard.carregar() com as datas do período.
 */
(function () {
    'use strict';


    function dataBr(iso) {
        const [a, m, d] = String(iso || '').split('-');
        return d ? `${d}/${m}/${a}` : '';
    }

    function celulas(l) {
        return ['ontem', 'semana', 'mes', 'periodo'].map(k => `<td class="dem-num">${Number(l[k]) || 0}</td>`).join('');
    }

    function cabecalhoNumeros(dados) {
        const p = dados.periodo;
        return `<th scope="col" class="dem-num">Ontem</th>
                <th scope="col" class="dem-num">Últimos 7 dias</th>
                <th scope="col" class="dem-num">Últimos 30 dias</th>
                <th scope="col" class="dem-num">Período<span class="dem-periodo-rotulo">${sanitizeHTML(dataBr(p.inicio))} a ${sanitizeHTML(dataBr(p.fim))}</span></th>`;
    }

    function totais(lista) {
        return ['ontem', 'semana', 'mes', 'periodo'].reduce((t, k) => {
            t[k] = lista.reduce((s, l) => s + (Number(l[k]) || 0), 0);
            return t;
        }, {});
    }

    function tabelaSetores(dados) {
        if (!dados.mostra_setores) return '';
        const lista = dados.setores || [];
        const linhas = lista.length
            ? lista.map(s => `<tr><th scope="row">${sanitizeHTML(s.nome)}</th>${celulas(s)}</tr>`).join('')
            : '<tr><td colspan="5" class="dem-vazio">Nenhum setor no escopo.</td></tr>';
        const rodape = lista.length > 1 ? `<tfoot><tr><th scope="row">Total</th>${celulas(totais(lista))}</tr></tfoot>` : '';
        return `
            <div class="dem-bloco">
                <h4 class="dem-subtitulo">Por setor</h4>
                <div class="dem-tabela-wrap"><table class="dem-tabela">
                    <caption class="sr-only">E-mails enviados por setor</caption>
                    <thead><tr><th scope="col">Setor</th>${cabecalhoNumeros(dados)}</tr></thead>
                    <tbody>${linhas}</tbody>${rodape}
                </table></div>
            </div>`;
    }

    function tabelaFuncionarios(dados) {
        const lista = dados.funcionarios || [];
        const linhas = lista.length
            ? lista.map(f => `<tr>
                    <th scope="row">${sanitizeHTML(f.nome)}<span class="dem-detalhe">${sanitizeHTML(Papeis.rotulo(f.tipo))}${f.setor_nome ? ' · ' + sanitizeHTML(f.setor_nome) : ''}</span></th>
                    ${celulas(f)}</tr>`).join('')
            : '<tr><td colspan="5" class="dem-vazio">Nenhum gestor ou operador no escopo.</td></tr>';
        return `
            <div class="dem-bloco">
                <h4 class="dem-subtitulo">${dados.mostra_setores ? 'Por funcionário (gestor e operador)' : 'Os meus e-mails enviados'}</h4>
                <div class="dem-tabela-wrap"><table class="dem-tabela">
                    <caption class="sr-only">E-mails enviados por funcionário</caption>
                    <thead><tr><th scope="col">Funcionário</th>${cabecalhoNumeros(dados)}</tr></thead>
                    <tbody>${linhas}</tbody>
                </table></div>
            </div>`;
    }

    function montar(dados) {
        const caixa = document.getElementById('dashboard-emails');
        if (!caixa) return;
        caixa.innerHTML = `
            <div class="dashboard-card dem-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">send</span> E-mails Enviados
                    </div>
                </h3>
                <p class="dem-ajuda">Respostas da equipe enviadas por e-mail ao solicitante (inclusive "Enviar e Fechar"),
                    no setor em que a demanda estava na hora da resposta. Avisos automáticos não entram.
                    "Últimos 7 dias" e "Últimos 30 dias" contam hoje; "Período" segue o filtro do topo do Dashboard.</p>
                ${tabelaSetores(dados)}
                ${tabelaFuncionarios(dados)}
            </div>`;
    }

    async function carregar(inicio, fim) {
        const caixa = document.getElementById('dashboard-emails');
        if (!caixa) return;
        const params = inicio && fim ? `?inicio=${encodeURIComponent(inicio)}&fim=${encodeURIComponent(fim)}` : '';
        try {
            const dados = await Api.get(urlComSetor('dashboard-emails.php' + params));
            montar(dados);
        } catch (e) {
            caixa.innerHTML = `<div class="dashboard-card dem-card"><p class="dem-erro" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar os e-mails enviados.'))}</p></div>`;
        }
    }

    window.carregarEmailsEnviados = carregar;
})();
