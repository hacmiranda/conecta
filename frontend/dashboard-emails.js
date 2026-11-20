/**
 * Dashboard → E-mails Enviados (19/11/2026): só quantitativos das respostas da equipe enviadas ao
 * solicitante por e-mail, por setor e por funcionário (gestor e operador): ontem, últimos 7 dias,
 * últimos 30 dias e o período escolhido no filtro (vale para as duas tabelas).
 * API: dashboard-emails.php (segue o seletor de setor da barra lateral via urlComSetor).
 * Carregado pelo carregarDashboard() (dashboard.js). Ações por data-acao com ouvintes delegados.
 */
(function () {
    'use strict';

    // Período escolhido nesta visita (vazio = o padrão do servidor: do dia 1º do mês até hoje)
    let periodo = { inicio: '', fim: '' };
    let ligado = false;

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
                    "Últimos 7 dias" e "Últimos 30 dias" contam hoje.</p>
                <form class="dem-filtro" data-acao-form="dem-periodo" autocomplete="off">
                    <div class="dem-campo">
                        <label for="dem-inicio">Período: de</label>
                        <input type="date" id="dem-inicio" value="${sanitizeHTML(dados.periodo.inicio)}" max="${sanitizeHTML(dados.janelas.hoje)}" required>
                    </div>
                    <div class="dem-campo">
                        <label for="dem-fim">até</label>
                        <input type="date" id="dem-fim" value="${sanitizeHTML(dados.periodo.fim)}" max="${sanitizeHTML(dados.janelas.hoje)}" required>
                    </div>
                    <button type="submit" class="dem-botao">Aplicar</button>
                    <button type="button" class="dem-botao dem-botao--secundario" data-acao="dem-mes-atual">Mês atual</button>
                </form>
                <p class="dem-erro" id="dem-erro" role="alert" aria-live="polite"></p>
                ${tabelaSetores(dados)}
                ${tabelaFuncionarios(dados)}
            </div>`;
    }

    async function carregar() {
        const caixa = document.getElementById('dashboard-emails');
        if (!caixa) return;
        ligar(caixa);
        const params = periodo.inicio && periodo.fim
            ? `?inicio=${encodeURIComponent(periodo.inicio)}&fim=${encodeURIComponent(periodo.fim)}` : '';
        try {
            const dados = await Api.get(urlComSetor('dashboard-emails.php' + params));
            montar(dados);
        } catch (e) {
            if (e && e.status === 400 && document.getElementById('dem-erro')) {
                // Período recusado: mantém a tela e mostra o motivo
                document.getElementById('dem-erro').textContent = mensagemDeErro(e, 'Período inválido.');
                return;
            }
            caixa.innerHTML = `<div class="dashboard-card dem-card"><p class="dem-erro" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar os e-mails enviados.'))}</p></div>`;
        }
    }

    function ligar(caixa) {
        if (ligado) return;
        ligado = true;
        caixa.addEventListener('submit', (ev) => {
            const form = ev.target.closest('[data-acao-form="dem-periodo"]');
            if (!form) return;
            ev.preventDefault();
            const inicio = document.getElementById('dem-inicio').value;
            const fim = document.getElementById('dem-fim').value;
            if (inicio && fim && inicio > fim) {
                document.getElementById('dem-erro').textContent = 'A data inicial deve ser anterior ou igual à final.';
                return;
            }
            periodo = { inicio, fim };
            carregar();
        });
        caixa.addEventListener('click', (ev) => {
            if (!ev.target.closest('[data-acao="dem-mes-atual"]')) return;
            periodo = { inicio: '', fim: '' };
            carregar();
        });
    }

    window.carregarEmailsEnviados = carregar;
})();
