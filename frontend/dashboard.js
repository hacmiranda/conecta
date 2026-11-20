/**
 * Dashboard (reescrito em 19/11/2026): filtro de período único no topo (vale para indicadores, gráficos e
 * a seção E-mails Enviados), indicadores do período com a variação em relação ao período anterior e
 * minigráfico, a situação de agora (demandas ativas) e os gráficos em Chart.js (graficos-dashboard.js).
 * API: dashboard.php?inicio=&fim= (+ setor_id do seletor da barra lateral, por urlComSetor).
 * Ações por data-acao com ouvintes delegados; nada de onclick nem style embutido.
 */

const Dashboard = (function () {
    'use strict';

    const CHAVE_PERIODO = 'cd_dashPeriodo';
    const ATALHOS = [
        ['7d', '7 dias'], ['30d', '30 dias'], ['90d', '90 dias'],
        ['mes', 'Mês atual'], ['ano', 'Ano atual'], ['12m', '12 meses'], ['personalizado', 'Personalizado'],
    ];

    // Textos do botão ⓘ de cada indicador e gráfico: [título, o que é, como analisar]
    const INFOS = {
        criadas: ['Demandas criadas', 'Demandas abertas no período (canceladas, mescladas e as da lixeira não contam). O minigráfico mostra o volume dia a dia (ou mês a mês, em períodos longos).', 'Mais demandas não é bom nem ruim: indica o volume que chega. Compare com as concluídas para ver se a equipe está dando conta.'],
        concluidas: ['Demandas concluídas', 'Demandas que chegaram a "Resolvido" ou "Encerrado" pela primeira vez dentro do período, qualquer que seja a data de abertura. Se uma demanda for reaberta, deixa de contar.', 'Concluir mais do que se cria reduz a fila; concluir menos faz a fila crescer.'],
        taxa: ['Taxa de conclusão', 'Das demandas criadas no período, quantas já estão resolvidas ou encerradas.', 'Em períodos muito recentes a taxa é naturalmente menor: as demandas ainda estão sendo atendidas.'],
        primeira: ['Tempo médio até a 1ª resposta', 'Média, em horas úteis do expediente do setor, entre a abertura e a primeira resposta da equipe, das demandas criadas no período. O tempo em pausa (status que congela o SLA e prioridade "Baixíssima") não conta.', 'É a métrica que o solicitante mais sente. Quanto menor, melhor.'],
        resolucao: ['Tempo médio de resolução', 'Média, em horas úteis, entre a abertura e a conclusão, das demandas criadas no período que já foram concluídas. O tempo em pausa não conta.', 'Valores altos podem indicar gargalos ou demandas que dependem de terceiros.'],
        fcr: ['Resolvidas no primeiro contato (FCR)', 'Das demandas concluídas no período, quantas foram resolvidas sem que o solicitante precisasse escrever de novo.', 'Quanto maior, mais assertiva foi a primeira resposta.'],
        ativas: ['Demandas ativas', 'Demandas em andamento agora (todas, menos encerradas e canceladas). Não depende do período escolhido.', 'É a carga de trabalho atual da equipe.'],
        sem_responsavel: ['Sem responsável', 'Demandas ativas que ainda não foram atribuídas a ninguém (estão na fila do setor).', 'O ideal é ficar perto de zero: são demandas esperando alguém assumir.'],
        sla: ['SLA vencido', 'Demandas ativas cujo prazo de SLA (resposta ou resolução) já passou. Não depende do período escolhido.', 'Priorize estas demandas e avalie se os prazos estão adequados ao volume.'],
        evolucao: ['Criadas × concluídas', 'Demandas criadas e concluídas em cada dia do período (ou em cada mês, em períodos acima de 92 dias).', 'Quando a linha das concluídas fica abaixo da das criadas por muito tempo, a fila está crescendo.'],
        status: ['Ativas por status', 'Quantas demandas ativas estão em cada status agora.', 'Muitas em "Novo" indicam falta de atribuição; muitas em "Aguardando" indicam dependência de terceiros ou do solicitante.'],
        prioridade: ['Ativas por prioridade', 'Quantas demandas ativas há em cada prioridade, da mais urgente para a menos urgente.', 'Excesso de urgentes pode indicar problema recorrente ou prioridade usada sem critério.'],
        responsavel: ['Ativas por responsável', 'Demandas ativas de cada responsável (os 9 com mais demandas; o resto em "Outros"). Quem não tem nenhuma não aparece.', 'Mostra se a carga está equilibrada entre as pessoas.'],
        statusResponsavel: ['Status por responsável', 'Demandas ativas de cada responsável, divididas por status (sem as resolvidas). Cada status tem sempre a mesma cor.', 'Ajuda a ver quem está com muitas demandas paradas esperando resposta.'],
        diaSemana: ['Criadas por dia da semana', 'Demandas criadas no período em cada dia da semana.', 'Dias com mais demanda podem precisar de mais pessoas disponíveis.'],
        categorias: ['Criadas por categoria', 'Demandas criadas no período em cada categoria (as 9 maiores; o resto em "Outros").', 'Mostra quais assuntos ocupam mais a equipe.'],
        subcategorias: ['Subcategorias mais frequentes', 'As 5 subcategorias com mais demandas criadas no período.', 'Assuntos recorrentes podem virar resposta rápida ou orientação no site.'],
        solicitantes: ['Solicitantes mais frequentes', 'As 5 pessoas que mais abriram demandas no período.', 'Solicitantes muito frequentes podem indicar um caso que precisa de atenção especial.'],
    };

    let ligado = false;
    let pedidoAtual = 0;

    // ---------------------------------------------------------------- período

    function iso(d) {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    function dataBr(texto) {
        const [a, m, d] = String(texto || '').split('-');
        return d ? `${d}/${m}/${a}` : '';
    }

    function lerPeriodo() {
        try {
            const salvo = JSON.parse(localStorage.getItem(CHAVE_PERIODO) || 'null');
            if (salvo && ATALHOS.some(([a]) => a === salvo.atalho)) return salvo;
        } catch (e) { /* sem armazenamento: usa o padrão */ }
        return { atalho: '30d' };
    }

    function gravarPeriodo(p) {
        try { localStorage.setItem(CHAVE_PERIODO, JSON.stringify(p)); } catch (e) { /* só conveniência */ }
    }

    /** Datas de um atalho (calculadas no navegador, no fuso local) */
    function datasDoAtalho(p) {
        const hoje = new Date();
        hoje.setHours(0, 0, 0, 0);
        const menos = (dias) => { const d = new Date(hoje); d.setDate(d.getDate() - dias); return d; };
        switch (p.atalho) {
            case '7d': return [iso(menos(6)), iso(hoje)];
            case '90d': return [iso(menos(89)), iso(hoje)];
            case 'mes': return [iso(new Date(hoje.getFullYear(), hoje.getMonth(), 1)), iso(hoje)];
            case 'ano': return [iso(new Date(hoje.getFullYear(), 0, 1)), iso(hoje)];
            case '12m': return [iso(menos(365)), iso(hoje)];
            case 'personalizado': return [p.inicio || iso(menos(29)), p.fim || iso(hoje)];
            default: return [iso(menos(29)), iso(hoje)];
        }
    }

    // ---------------------------------------------------------------- formatação

    const fmt = (n) => Number(n || 0).toLocaleString('pt-BR');

    function formatarTempo(seg) {
        if (!seg) return '—';
        const h = Math.floor(seg / 3600);
        const m = Math.round((seg % 3600) / 60);
        if (h === 0) return `${m} min`;
        if (h >= 10) return `${fmt(Math.round(seg / 3600))}h`; // tempo longo: só as horas (cabe no cartão)
        return m ? `${fmt(h)}h ${m}min` : `${fmt(h)}h`;
    }

    const formatarPct = (v) => (v === null || v === undefined) ? '—' : `${String(v).replace('.', ',')}%`;

    /**
     * Variação em relação ao período anterior.
     * sentido: 'mais' (subir é bom), 'menos' (descer é bom) ou 'neutro'; tipo: 'contagem' | 'pct' | 'tempo'
     */
    function variacao(ind, sentido, tipo) {
        const { atual, anterior } = ind;
        if (atual === null || anterior === null || anterior === undefined || (tipo !== 'pct' && !anterior)) {
            return '<p class="dg-kpi-delta dg-delta--neutro">Sem dados no período anterior</p>';
        }
        const diff = tipo === 'pct' ? Math.round((atual - anterior) * 10) / 10 : Math.round((atual - anterior) / anterior * 100);
        if (diff === 0) {
            return '<p class="dg-kpi-delta dg-delta--neutro"><span aria-hidden="true">=</span> Igual ao período anterior</p>';
        }
        const subiu = diff > 0;
        const bom = sentido === 'neutro' ? null : (sentido === 'mais') === subiu;
        const classe = bom === null ? 'neutro' : (bom ? 'bom' : 'ruim');
        const valor = tipo === 'pct' ? `${String(Math.abs(diff)).replace('.', ',')} p.p.` : `${Math.abs(diff)}%`;
        const ref = tipo === 'tempo' ? formatarTempo(anterior) : (tipo === 'pct' ? formatarPct(anterior) : fmt(anterior));
        return `<p class="dg-kpi-delta dg-delta--${classe}">
            <span aria-hidden="true">${subiu ? '▲' : '▼'}</span><span class="sr-only">${subiu ? 'Aumento de' : 'Queda de'}</span>
            ${valor} <span class="dg-kpi-ref">vs. período anterior (${ref})</span></p>`;
    }

    /** Minigráfico de tendência (SVG decorativo: os números estão no gráfico Criadas × concluídas) */
    function minigrafico(valores) {
        if (!valores.length || !valores.some(Boolean)) return '';
        const max = Math.max(...valores) || 1;
        const passo = valores.length > 1 ? 100 / (valores.length - 1) : 100;
        const pontos = valores.map((v, i) => `${(i * passo).toFixed(2)},${(28 - (v / max) * 26).toFixed(2)}`).join(' ');
        return `<svg class="dg-kpi-mini" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <polyline points="${pontos}" fill="none" vector-effect="non-scaling-stroke"></polyline></svg>`;
    }

    // ---------------------------------------------------------------- HTML

    function botaoInfo(chave, titulo) {
        return `<button type="button" class="dg-botao-icone" data-acao="dg-info" data-info="${chave}" aria-label="Sobre: ${sanitizeHTML(titulo)}">
            <span class="material-symbols-outlined" aria-hidden="true">info</span></button>`;
    }

    function kpi({ chave, icone, titulo, valor, delta = '', mini = '', sub = '', alerta = false }) {
        return `<article class="dashboard-card dg-kpi${alerta ? ' dg-kpi--alerta' : ''}" aria-labelledby="dg-kpi-${chave}">
            <div class="dg-kpi-cab">
                <span class="material-symbols-outlined dg-kpi-icone" aria-hidden="true">${icone}</span>
                <h4 class="dg-kpi-titulo" id="dg-kpi-${chave}">${titulo}</h4>
                ${botaoInfo(chave, titulo)}
            </div>
            <p class="dg-kpi-valor">${valor}</p>
            ${delta}${mini}
            ${sub ? `<p class="dg-kpi-sub">${sub}</p>` : ''}
        </article>`;
    }

    function cartaoGrafico(id, icone, titulo, sub, def) {
        return `<article class="dashboard-card dg-card" aria-labelledby="dg-titulo-${id}">
            <div class="dg-card-cab">
                <h4 class="dg-card-titulo" id="dg-titulo-${id}">
                    <span class="material-symbols-outlined" aria-hidden="true">${icone}</span>
                    <span>${titulo}${sub ? ` <span class="dg-card-sub">${sub}</span>` : ''}</span>
                </h4>
                <div class="dg-card-acoes">
                    ${botaoInfo(id, titulo)}
                    <button type="button" class="dg-botao-tabela" data-acao="dg-tabela" aria-expanded="false" aria-controls="dg-tab-${id}">
                        <span class="material-symbols-outlined" aria-hidden="true">table_rows</span> Tabela</button>
                </div>
            </div>
            ${def.vazio
                ? '<p class="dg-vazio">Sem dados para mostrar.</p>'
                : `<div class="dg-grafico" data-grafico="${id}"><canvas id="dg-${id}" role="img" aria-label="${sanitizeHTML(GraficosDashboard.descricao(titulo, def))}"></canvas></div>`}
            <div class="dg-tabela" id="dg-tab-${id}" hidden>${GraficosDashboard.tabela(titulo, def)}</div>
        </article>`;
    }

    function montarFiltro() {
        const caixa = document.getElementById('dashboard-filtro');
        if (!caixa || caixa.dataset.montado) return;
        caixa.dataset.montado = '1';
        caixa.innerHTML = `
            <div class="dg-filtro" role="group" aria-labelledby="dg-filtro-rotulo">
                <span class="dg-filtro-rotulo" id="dg-filtro-rotulo">
                    <span class="material-symbols-outlined" aria-hidden="true">date_range</span> Período</span>
                <div class="dg-atalhos">
                    ${ATALHOS.map(([a, r]) => `<button type="button" class="dg-atalho" data-acao="dg-atalho" data-atalho="${a}" aria-pressed="false">${r}</button>`).join('')}
                </div>
                <form class="dg-personalizado" data-acao-form="dg-periodo" autocomplete="off" hidden>
                    <label for="dg-inicio">De</label><input type="date" id="dg-inicio" required>
                    <label for="dg-fim">até</label><input type="date" id="dg-fim" required>
                    <button type="submit" class="dg-aplicar">Aplicar</button>
                </form>
                <p class="dg-comparacao" id="dg-comparacao" aria-live="polite"></p>
                <p class="dg-erro" id="dg-erro" role="alert"></p>
            </div>`;
    }

    function atualizarFiltro(p, resposta) {
        document.querySelectorAll('#dashboard-filtro .dg-atalho').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.atalho === p.atalho)));
        const form = document.querySelector('#dashboard-filtro .dg-personalizado');
        if (form) form.hidden = p.atalho !== 'personalizado';
        const hoje = iso(new Date());
        ['dg-inicio', 'dg-fim'].forEach(id => { const el = document.getElementById(id); if (el) el.max = hoje; });
        if (p.atalho === 'personalizado' && document.getElementById('dg-inicio')) {
            const [ini, fim] = datasDoAtalho(p);
            document.getElementById('dg-inicio').value = ini;
            document.getElementById('dg-fim').value = fim;
        }
        const comp = document.getElementById('dg-comparacao');
        if (comp && resposta) {
            const per = resposta.periodo;
            comp.textContent = `${dataBr(per.inicio)} a ${dataBr(per.fim)} (${per.dias} ${per.dias === 1 ? 'dia' : 'dias'}) · comparado com ${dataBr(per.anterior.inicio)} a ${dataBr(per.anterior.fim)}`;
        }
    }

    function montarTela(d) {
        const i = d.indicadores;
        const a = d.agora;
        const defs = GraficosDashboard.definicoes(d);
        const porMes = d.serie.granularidade === 'mes';

        const html = `
            <section class="dg-secao" aria-labelledby="dg-sec-periodo">
                <h3 class="dg-secao-titulo" id="dg-sec-periodo">No período</h3>
                <div class="dg-kpis dg-kpis--6">
                    ${kpi({ chave: 'criadas', icone: 'add_circle', titulo: 'Criadas', valor: fmt(i.criadas.atual), delta: variacao(i.criadas, 'neutro', 'contagem'),
                            mini: minigrafico(d.serie.pontos.map(p => p.criadas)), sub: `${fmt(i.externas.atual)} por canal externo` })}
                    ${kpi({ chave: 'concluidas', icone: 'task_alt', titulo: 'Concluídas', valor: fmt(i.concluidas.atual), delta: variacao(i.concluidas, 'mais', 'contagem'),
                            mini: minigrafico(d.serie.pontos.map(p => p.concluidas)) })}
                    ${kpi({ chave: 'taxa', icone: 'check_circle', titulo: 'Taxa de conclusão', valor: formatarPct(i.taxa_conclusao.atual), delta: variacao(i.taxa_conclusao, 'mais', 'pct'),
                            sub: 'das demandas criadas no período' })}
                    ${kpi({ chave: 'primeira', icone: 'bolt', titulo: '1ª resposta', valor: formatarTempo(i.tempo_primeira_resposta.atual), delta: variacao(i.tempo_primeira_resposta, 'menos', 'tempo'),
                            sub: 'média, em horas úteis' })}
                    ${kpi({ chave: 'resolucao', icone: 'timer', titulo: 'Resolução', valor: formatarTempo(i.tempo_resolucao.atual), delta: variacao(i.tempo_resolucao, 'menos', 'tempo'),
                            sub: 'média, em horas úteis' })}
                    ${kpi({ chave: 'fcr', icone: 'rocket_launch', titulo: '1º contato', valor: formatarPct(i.fcr.atual), delta: variacao(i.fcr, 'mais', 'pct'),
                            sub: 'resolvidas sem nova mensagem do solicitante' })}
                </div>
                <div class="dg-graficos dg-graficos--1">
                    ${cartaoGrafico('evolucao', 'show_chart', 'Criadas × concluídas', porMes ? 'por mês' : 'por dia', defs.evolucao)}
                </div>
            </section>

            <section class="dg-secao" aria-labelledby="dg-sec-agora">
                <h3 class="dg-secao-titulo" id="dg-sec-agora">Situação agora <span class="dg-secao-sub">demandas ativas, não depende do período</span></h3>
                <div class="dg-kpis dg-kpis--3">
                    ${kpi({ chave: 'ativas', icone: 'pending_actions', titulo: 'Ativas', valor: fmt(a.ativas) })}
                    ${kpi({ chave: 'sem_responsavel', icone: 'person_off', titulo: 'Sem responsável', valor: fmt(a.sem_responsavel), alerta: a.sem_responsavel > 0,
                            sub: a.sem_responsavel > 0 ? 'esperando alguém assumir' : 'todas atribuídas' })}
                    ${kpi({ chave: 'sla', icone: 'warning', titulo: 'SLA vencido', valor: fmt(a.sla_vencido), alerta: a.sla_vencido > 0,
                            sub: a.sla_vencido > 0 ? 'precisam de prioridade' : 'nenhuma fora do prazo' })}
                </div>
                <div class="dg-graficos dg-graficos--2">
                    ${cartaoGrafico('status', 'bar_chart', 'Ativas por status', '', defs.status)}
                    ${cartaoGrafico('prioridade', 'local_fire_department', 'Ativas por prioridade', '', defs.prioridade)}
                    ${cartaoGrafico('responsavel', 'assignment', 'Ativas por responsável', '', defs.responsavel)}
                    ${cartaoGrafico('statusResponsavel', 'stacked_bar_chart', 'Status por responsável', '', defs.statusResponsavel)}
                </div>
            </section>

            <section class="dg-secao" aria-labelledby="dg-sec-distribuicao">
                <h3 class="dg-secao-titulo" id="dg-sec-distribuicao">Demandas criadas no período</h3>
                <div class="dg-graficos dg-graficos--2">
                    ${cartaoGrafico('diaSemana', 'calendar_month', 'Por dia da semana', '', defs.diaSemana)}
                    ${cartaoGrafico('categorias', 'folder_open', 'Por categoria', '', defs.categorias)}
                    ${cartaoGrafico('subcategorias', 'category', 'Subcategorias mais frequentes', '', defs.subcategorias)}
                    ${cartaoGrafico('solicitantes', 'group', 'Solicitantes mais frequentes', '', defs.solicitantes)}
                </div>
            </section>`;

        const content = document.getElementById('dashboard-content');
        GraficosDashboard.destruirTodos();
        content.innerHTML = html;

        // Altura de cada gráfico (barras horizontais crescem com os itens): propriedade CSS calculada
        Object.entries(defs).forEach(([id, def]) => {
            const caixa = content.querySelector(`.dg-grafico[data-grafico="${id}"]`);
            if (!caixa) return;
            caixa.style.setProperty('--dg-altura', GraficosDashboard.altura(def, id === 'evolucao' ? 300 : 240) + 'px');
            GraficosDashboard.desenhar(id, def);
        });
    }

    // ---------------------------------------------------------------- carga e ações

    async function carregar() {
        montarFiltro();
        ligar();
        const p = lerPeriodo();
        const [inicio, fim] = datasDoAtalho(p);
        atualizarFiltro(p, null);

        const content = document.getElementById('dashboard-content');
        const erro = document.getElementById('dg-erro');
        if (erro) erro.textContent = '';
        content.classList.add('dg-carregando'); // mantém o desenho anterior esmaecido, sem piscar
        content.setAttribute('aria-busy', 'true');
        const pedido = ++pedidoAtual;
        try {
            const dados = await Api.get(urlComSetor(`dashboard.php?inicio=${encodeURIComponent(inicio)}&fim=${encodeURIComponent(fim)}`));
            if (pedido !== pedidoAtual) return; // chegou depois de um pedido mais novo
            montarTela(dados);
            atualizarFiltro(p, dados);
            if (typeof carregarEmailsEnviados === 'function') carregarEmailsEnviados(dados.periodo.inicio, dados.periodo.fim);
        } catch (e) {
            if (pedido !== pedidoAtual) return;
            const msg = mensagemDeErro(e, 'Erro ao carregar o Dashboard.');
            if (e && e.status === 400 && erro) {
                erro.textContent = msg;
            } else {
                content.innerHTML = `<p class="dg-vazio" role="alert">${sanitizeHTML(msg)}</p>`;
            }
        } finally {
            if (pedido === pedidoAtual) {
                content.classList.remove('dg-carregando');
                content.removeAttribute('aria-busy');
            }
        }
    }

    function mostrarInfo(chave) {
        const info = INFOS[chave];
        if (!info) return;
        mostrarAlert(info[0], `<p><strong>O que é:</strong> ${info[1]}</p><p><strong>Como analisar:</strong> ${info[2]}</p>`);
    }

    function ligar() {
        if (ligado) return;
        ligado = true;
        const secao = document.getElementById('dashboard');
        secao.addEventListener('click', (ev) => {
            const alvo = ev.target.closest('[data-acao]');
            if (!alvo || !secao.contains(alvo)) return;
            const acao = alvo.dataset.acao;
            if (acao === 'dg-info') {
                mostrarInfo(alvo.dataset.info);
            } else if (acao === 'dg-tabela') {
                const tabela = document.getElementById(alvo.getAttribute('aria-controls'));
                const abrir = alvo.getAttribute('aria-expanded') !== 'true';
                alvo.setAttribute('aria-expanded', String(abrir));
                if (tabela) tabela.hidden = !abrir;
            } else if (acao === 'dg-atalho') {
                if (alvo.dataset.atalho === 'personalizado') {
                    // Abre as datas (com o período atual); só recarrega ao aplicar
                    const [ini, fim] = datasDoAtalho(lerPeriodo());
                    atualizarFiltro({ atalho: 'personalizado', inicio: ini, fim: fim }, null);
                    document.getElementById('dg-inicio').focus();
                    return;
                }
                gravarPeriodo({ atalho: alvo.dataset.atalho });
                carregar();
            }
        });
        secao.addEventListener('submit', (ev) => {
            if (!ev.target.closest('[data-acao-form="dg-periodo"]')) return;
            ev.preventDefault();
            const inicio = document.getElementById('dg-inicio').value;
            const fim = document.getElementById('dg-fim').value;
            const erro = document.getElementById('dg-erro');
            if (!inicio || !fim) { erro.textContent = 'Informe as duas datas do período.'; return; }
            if (inicio > fim) { erro.textContent = 'A data inicial deve ser anterior ou igual à final.'; return; }
            gravarPeriodo({ atalho: 'personalizado', inicio, fim });
            carregar();
        });
    }

    return { carregar };
})();

// Abrir pelo menu
function mostrarDashboard() {
    mostrarSecao('dashboard');
    carregarDashboard();
}

// Chamada pelo menu, pela troca de setor (cabecalho-setor.js) e pela troca de tema (script.js)
function carregarDashboard() {
    return Dashboard.carregar();
}
