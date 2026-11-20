/**
 * Gráficos do Dashboard em Chart.js (frontend/lib/chart.umd.min.js, 4.4.1, guardado no projeto).
 * Reescrito em 19/11/2026 no lugar dos gráficos desenhados à mão em canvas.
 *
 * Regras (skill de visualização de dados):
 *  - cores só dos tokens --grafico-* de dashboard-graficos.css (paleta validada para daltonismo nos
 *    dois temas, 7 posições em ordem fixa); série única = posição 1;
 *  - cada status tem sempre a mesma cor (ORDEM_STATUS), qualquer que seja o filtro;
 *  - um eixo só, valores inteiros, grade em linha fina, barras finas com a ponta arredondada,
 *    2 px de vão entre segmentos empilhados;
 *  - todo gráfico tem dica ao passar o mouse, descrição para leitor de tela e a tabela com os números
 *    (GraficosDashboard.tabela), e não anima para quem pediu menos movimento.
 */
const GraficosDashboard = (function () {
    'use strict';

    // Status na ordem das cores (a 8ª posição em diante cai em "Outros", cinza)
    const ORDEM_STATUS = ['novo', 'em_atendimento', 'aguardando', 'aguardando_resposta',
        'aguardando_terceiros', 'aguardando_data', 'nova_resposta'];
    const ORDEM_PRIORIDADE = ['urgente', 'alta', 'normal', 'baixa', 'baixissima'];
    const DIAS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
    const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    const MAX_BARRAS = 10;

    const instancias = {};

    function nomeStatus(s) {
        return (window.STATUS_CONFIG && window.STATUS_CONFIG[s] && window.STATUS_CONFIG[s].nome) || s;
    }

    function nomePrioridade(p) {
        return (window.PRIORIDADE_CONFIG && window.PRIORIDADE_CONFIG[p] && window.PRIORIDADE_CONFIG[p].nome) || p;
    }

    /** Lê os tokens do tema atual (claro ou escuro) */
    function tema() {
        const raiz = document.getElementById('dashboard') || document.body;
        const css = getComputedStyle(raiz);
        const v = (nome) => css.getPropertyValue(nome).trim();
        return {
            serie: [1, 2, 3, 4, 5, 6, 7].map(n => v('--grafico-' + n)),
            outros: v('--grafico-outros'),
            texto: v('--grafico-texto'),
            textoFraco: v('--grafico-texto-fraco'),
            grade: v('--grafico-grade'),
            superficie: v('--grafico-superficie'),
        };
    }

    function semMovimento() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function corDoStatus(status, t) {
        const i = ORDEM_STATUS.indexOf(status);
        return i >= 0 ? t.serie[i] : t.outros;
    }

    /** Opções comuns a todos os gráficos */
    function opcoesBase(t, extra) {
        const base = {
            responsive: true,
            maintainAspectRatio: false,
            animation: semMovimento() ? false : { duration: 500 },
            plugins: {
                legend: { display: false, labels: { color: t.texto, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, boxHeight: 10, font: { family: 'Inter', size: 12 } } },
                rotulosValores: { cor: t.texto },
                tooltip: {
                    backgroundColor: t.superficie, titleColor: t.texto, bodyColor: t.texto, borderColor: t.grade, borderWidth: 1,
                    padding: 10, cornerRadius: 8, boxPadding: 4, usePointStyle: true,
                    titleFont: { family: 'Inter', weight: '700' }, bodyFont: { family: 'Inter' },
                    callbacks: { label: (ctx) => ` ${ctx.dataset.label ? ctx.dataset.label + ': ' : ''}${formatarNumero(ctx.parsed[ctx.chart.options.indexAxis === 'y' ? 'x' : 'y'])}` },
                },
            },
        };
        return Object.assign(base, extra || {});
    }

    function eixoValores(t, extra) {
        return Object.assign({
            beginAtZero: true,
            border: { display: false },
            grid: { color: t.grade, drawTicks: false },
            ticks: { color: t.textoFraco, precision: 0, padding: 6, font: { family: 'Inter', size: 11 } },
        }, extra || {});
    }

    function eixoCategorias(t, extra) {
        return Object.assign({
            border: { color: t.grade },
            grid: { display: false },
            ticks: { color: t.texto, font: { family: 'Inter', size: 12 }, autoSkip: false },
        }, extra || {});
    }

    function formatarNumero(n) {
        return Number(n || 0).toLocaleString('pt-BR');
    }

    /** Número na ponta de cada barra (gráficos de uma série só): leitura sem depender da dica */
    const rotulosValores = {
        id: 'rotulosValores',
        afterDatasetsDraw(chart, args, opcoes) {
            const conjunto = chart.data.datasets[0];
            const meta = chart.getDatasetMeta(0);
            if (!conjunto || !meta || meta.hidden) return;
            const horizontal = chart.options.indexAxis === 'y';
            const ctx = chart.ctx;
            ctx.save();
            ctx.fillStyle = opcoes.cor;
            ctx.font = '600 11px Inter, sans-serif';
            ctx.textAlign = horizontal ? 'left' : 'center';
            ctx.textBaseline = horizontal ? 'middle' : 'bottom';
            meta.data.forEach((barra, i) => {
                const valor = conjunto.data[i];
                if (!valor) return;
                if (horizontal) ctx.fillText(formatarNumero(valor), barra.x + 6, barra.y);
                else ctx.fillText(formatarNumero(valor), barra.x, barra.y - 4);
            });
            ctx.restore();
        },
    };

    /** Barras horizontais de uma série só (ordenadas como chegam) */
    function configBarrasHorizontais(rotulos, valores, t, nomeSerie) {
        return {
            type: 'bar',
            data: { labels: rotulos, datasets: [{ label: nomeSerie, data: valores, backgroundColor: t.serie[0], borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 }] },
            plugins: [rotulosValores],
            options: opcoesBase(t, {
                indexAxis: 'y',
                layout: { padding: { right: 40 } },
                scales: { x: eixoValores(t), y: eixoCategorias(t) },
            }),
        };
    }

    /** Acima de MAX_BARRAS itens, o resto vira "Outros" */
    function limitar(lista, nome, total) {
        if (lista.length <= MAX_BARRAS) return lista;
        const resto = lista.slice(MAX_BARRAS - 1).reduce((s, l) => s + (l[total] || 0), 0);
        return lista.slice(0, MAX_BARRAS - 1).concat([{ [nome]: 'Outros', [total]: resto }]);
    }

    function rotuloPonto(chave, granularidade) {
        if (granularidade === 'mes') {
            const [a, m] = chave.split('-');
            return `${MESES[Number(m) - 1]}/${a.slice(2)}`;
        }
        const [, m, d] = chave.split('-');
        return `${d}/${m}`;
    }

    /**
     * Definições dos gráficos a partir da resposta do dashboard.php.
     * Cada uma: {id, dados (para a tabela), config(t)}. Gráfico sem dados volta com vazio = true.
     */
    function definicoes(d) {
        const defs = {};
        const agora = d.agora;

        // Evolução no período: criadas × concluídas (um eixo só)
        const pontos = d.serie.pontos;
        const rotulosSerie = pontos.map(p => rotuloPonto(p.chave, d.serie.granularidade));
        defs.evolucao = {
            vazio: !pontos.some(p => p.criadas || p.concluidas),
            colunas: [d.serie.granularidade === 'mes' ? 'Mês' : 'Dia', 'Criadas', 'Concluídas'],
            linhas: pontos.map((p, i) => [rotulosSerie[i], p.criadas, p.concluidas]),
            resumo: `Demandas criadas e concluídas no período: ${formatarNumero(d.indicadores.criadas.atual)} criadas e ${formatarNumero(d.indicadores.concluidas.atual)} concluídas.`,
            config: (t) => ({
                type: 'line',
                data: {
                    labels: rotulosSerie,
                    datasets: [
                        { label: 'Criadas', data: pontos.map(p => p.criadas), borderColor: t.serie[0], backgroundColor: t.serie[0], borderWidth: 2, cubicInterpolationMode: 'monotone', pointRadius: pontos.length > 40 ? 0 : 2, pointHoverRadius: 5 },
                        { label: 'Concluídas', data: pontos.map(p => p.concluidas), borderColor: t.serie[1], backgroundColor: t.serie[1], borderWidth: 2, cubicInterpolationMode: 'monotone', pointRadius: pontos.length > 40 ? 0 : 2, pointHoverRadius: 5 },
                    ],
                },
                options: opcoesBase(t, {
                    interaction: { mode: 'index', intersect: false },
                    plugins: Object.assign(opcoesBase(t).plugins, { legend: Object.assign(opcoesBase(t).plugins.legend, { display: true, position: 'top', align: 'end' }) }),
                    scales: {
                        x: eixoCategorias(t, { ticks: { color: t.textoFraco, maxRotation: 0, autoSkip: true, maxTicksLimit: 10, font: { family: 'Inter', size: 11 } } }),
                        y: eixoValores(t),
                    },
                }),
            }),
        };

        // Agora: ativas por status (maior para menor)
        const status = Object.entries(agora.por_status || {}).sort((a, b) => b[1] - a[1]);
        defs.status = {
            vazio: !status.length,
            colunas: ['Status', 'Demandas'],
            linhas: status.map(([s, n]) => [nomeStatus(s), n]),
            config: (t) => configBarrasHorizontais(status.map(([s]) => nomeStatus(s)), status.map(([, n]) => n), t, 'Demandas'),
        };

        // Agora: por prioridade, na ordem da prioridade (urgente primeiro)
        const prioridades = ORDEM_PRIORIDADE.concat(Object.keys(agora.por_prioridade || {}).filter(p => !ORDEM_PRIORIDADE.includes(p)))
            .filter(p => (agora.por_prioridade || {})[p] !== undefined);
        defs.prioridade = {
            vazio: !prioridades.length,
            colunas: ['Prioridade', 'Demandas'],
            linhas: prioridades.map(p => [nomePrioridade(p), agora.por_prioridade[p]]),
            config: (t) => configBarrasHorizontais(prioridades.map(nomePrioridade), prioridades.map(p => agora.por_prioridade[p]), t, 'Demandas'),
        };

        // Agora: por responsável (até 10; o resto em "Outros")
        const resp = limitar(agora.por_responsavel || [], 'nome', 'total');
        defs.responsavel = {
            vazio: !resp.length,
            colunas: ['Responsável', 'Demandas'],
            linhas: (agora.por_responsavel || []).map(l => [l.nome, l.total]),
            config: (t) => configBarrasHorizontais(resp.map(l => l.nome), resp.map(l => l.total), t, 'Demandas'),
        };

        // Agora: status por responsável (empilhado; cada status com a sua cor fixa)
        const porResp = {};
        const statusPresentes = new Set();
        (agora.status_por_responsavel || []).forEach(l => {
            porResp[l.responsavel] = porResp[l.responsavel] || {};
            porResp[l.responsavel][l.status] = l.total;
            statusPresentes.add(l.status);
        });
        const nomesResp = Object.keys(porResp)
            .sort((a, b) => Object.values(porResp[b]).reduce((s, n) => s + n, 0) - Object.values(porResp[a]).reduce((s, n) => s + n, 0))
            .slice(0, MAX_BARRAS);
        const statusOrdenados = ORDEM_STATUS.filter(s => statusPresentes.has(s));
        const outrosStatus = [...statusPresentes].filter(s => !ORDEM_STATUS.includes(s));
        defs.statusResponsavel = {
            vazio: !nomesResp.length,
            colunas: ['Responsável'].concat(statusOrdenados.map(nomeStatus), outrosStatus.length ? ['Outros'] : []),
            linhas: nomesResp.map(r => [r].concat(statusOrdenados.map(s => porResp[r][s] || 0),
                outrosStatus.length ? [outrosStatus.reduce((soma, s) => soma + (porResp[r][s] || 0), 0)] : [])),
            config: (t) => {
                const conjuntos = statusOrdenados.map(s => ({
                    label: nomeStatus(s), data: nomesResp.map(r => porResp[r][s] || 0),
                    backgroundColor: corDoStatus(s, t), borderColor: t.superficie, borderWidth: { right: 2 }, borderSkipped: false, maxBarThickness: 18,
                }));
                if (outrosStatus.length) {
                    conjuntos.push({ label: 'Outros', data: nomesResp.map(r => outrosStatus.reduce((soma, s) => soma + (porResp[r][s] || 0), 0)),
                        backgroundColor: t.outros, borderColor: t.superficie, borderWidth: { right: 2 }, borderSkipped: false, maxBarThickness: 18 });
                }
                const base = opcoesBase(t);
                return {
                    type: 'bar',
                    data: { labels: nomesResp, datasets: conjuntos },
                    options: opcoesBase(t, {
                        indexAxis: 'y',
                        interaction: { mode: 'index', intersect: false },
                        plugins: Object.assign(base.plugins, { legend: Object.assign(base.plugins.legend, { display: true, position: 'top', align: 'start' }) }),
                        scales: { x: eixoValores(t, { stacked: true }), y: eixoCategorias(t, { stacked: true }) },
                    }),
                };
            },
        };

        // Período: dia da semana (segunda a domingo)
        const dias = d.periodo_dados.por_dia_semana || [];
        defs.diaSemana = {
            vazio: !dias.some(Boolean),
            colunas: ['Dia', 'Demandas criadas'],
            linhas: DIAS.map((dia, i) => [dia, dias[i] || 0]),
            config: (t) => ({
                type: 'bar',
                data: { labels: DIAS, datasets: [{ label: 'Criadas', data: dias, backgroundColor: t.serie[0], borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28 }] },
                plugins: [rotulosValores],
                options: opcoesBase(t, { layout: { padding: { top: 18 } }, scales: { x: eixoCategorias(t), y: eixoValores(t) } }),
            }),
        };

        // Período: rankings
        [['solicitantes', 'top_solicitantes', 'Solicitante'], ['subcategorias', 'top_subcategorias', 'Subcategoria'], ['categorias', 'por_categoria', 'Categoria']]
            .forEach(([id, campo, coluna]) => {
                const todos = d.periodo_dados[campo] || [];
                const lista = limitar(todos, 'nome', 'total');
                defs[id] = {
                    vazio: !lista.length,
                    colunas: [coluna, 'Demandas'],
                    linhas: todos.map(l => [l.nome, l.total]),
                    config: (t) => configBarrasHorizontais(lista.map(l => l.nome), lista.map(l => l.total), t, 'Demandas'),
                };
            });

        return defs;
    }

    /** Altura do gráfico: barras horizontais crescem com o número de itens */
    function altura(def, padrao) {
        if (!def || def.vazio) return padrao;
        const cfg = def.config(tema());
        if (cfg.type === 'bar' && cfg.options.indexAxis === 'y') {
            const extraLegenda = cfg.options.plugins.legend.display ? 40 : 0;
            return Math.max(140, cfg.data.labels.length * 34 + 40 + extraLegenda);
        }
        return padrao;
    }

    /** Desenha (ou redesenha) um gráfico no canvas de id "dg-<id>" */
    function desenhar(id, def) {
        if (instancias[id]) {
            instancias[id].destroy();
            delete instancias[id];
        }
        const canvas = document.getElementById('dg-' + id);
        if (!canvas || !def || def.vazio || typeof Chart === 'undefined') return;
        Chart.defaults.font.family = 'Inter, sans-serif';
        instancias[id] = new Chart(canvas, def.config(tema()));
    }

    /** Descrição curta para leitor de tela (o canvas tem role="img") */
    function descricao(titulo, def) {
        if (def.vazio) return `${titulo}: sem dados.`;
        if (def.resumo) return def.resumo;
        const itens = def.linhas.slice(0, 5).map(l => `${l[0]}: ${l.slice(1).join(', ')}`).join('; ');
        return `${titulo}. ${itens}${def.linhas.length > 5 ? '; e outros' : ''}. Veja a tabela para todos os números.`;
    }

    /** HTML da tabela com os números do gráfico */
    function tabela(titulo, def) {
        if (def.vazio) return '<p class="dg-vazio">Sem dados para mostrar.</p>';
        return `<table class="dg-tabela-dados">
            <caption class="sr-only">${sanitizeHTML(titulo)}</caption>
            <thead><tr>${def.colunas.map((c, i) => `<th scope="col"${i ? ' class="dg-num"' : ''}>${sanitizeHTML(c)}</th>`).join('')}</tr></thead>
            <tbody>${def.linhas.map(l => `<tr>${l.map((c, i) => i ? `<td class="dg-num">${formatarNumero(c)}</td>` : `<th scope="row">${sanitizeHTML(String(c))}</th>`).join('')}</tr>`).join('')}</tbody>
        </table>`;
    }

    function destruirTodos() {
        Object.keys(instancias).forEach(id => { instancias[id].destroy(); delete instancias[id]; });
    }

    return { definicoes, desenhar, descricao, tabela, altura, destruirTodos, tema, formatarNumero };
})();
