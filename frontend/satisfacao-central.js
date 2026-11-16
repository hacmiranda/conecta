/**
 * Módulo Satisfação — Central de Feedbacks (subtela "avaliacoes"; 16/11/2026: igual à "Central de Feedbacks" do
 * METRICA360, src/views/admin/dashboard.php, public/css/dashboard.css e public/js/dashboard.js de lá).
 *   Parte fixa (montada uma vez por visita): subtítulo, frase do dia e o widget do Google Reviews.
 *   Parte dinâmica (refeita a cada filtro/página/período): Métricas Gerais, Evolução de Nota Média, Categorias
 *   mais mencionadas, filtros e "Feedbacks Recebidos" com status (menu), anotação (painel lateral) e paginação.
 * Dados: pesquisa-painel.php (?acao=resumo|lista|csv|pendentes); triagem em pesquisa-triagem.php. Respeita o
 * seletor de setor do cabeçalho (satUrl → urlComSetor). Usa os utilitários e o estado de satisfacao.js.
 * Sem style/onclick inline: ações por data-cfb com ouvintes delegados; geometria calculada por data-*.
 * CSS em satisfacao-central.css.
 */
const CFB_ELFSIGHT_SCRIPT = 'https://static.elfsight.com/platform/platform.js';
const CFB_ELFSIGHT_APP = 'elfsight-app-89b439f9-8807-4f6b-9dc5-062a12b28e17';
const CFB_RECARGA_MS = 5 * 60 * 1000;
const CFB_STATUS = {
    pendente: { rotulo: 'Pendente', icone: 'hourglass_empty' },
    em_tratativa: { rotulo: 'Em Tratativa', icone: 'autorenew' },
    resolvido: { rotulo: 'Resolvido', icone: 'check_circle' },
};

let cfbLista = [];
let cfbGrafico = null;      // { serie, meta, pontos: [{x, i}] } para redesenhar e para a dica do mouse
let cfbTimerRecarga = null;
let cfbFocoAntesDoPainel = null;

// ── Formatação ──────────────────────────────────────────────────────────────
function cfbData(valor) {
    return new Date(String(valor).replace(' ', 'T'));
}

// "12 min", "3h", "1 dia", "5 dias", "2 meses" (igual ao fbTempoDecorrido do METRICA360)
function cfbTempoDecorrido(valor) {
    const seg = Math.max(0, (Date.now() - cfbData(valor).getTime()) / 1000);
    if (seg < 3600) return Math.floor(seg / 60) + ' min';
    if (seg < 86400) return Math.floor(seg / 3600) + 'h';
    const dias = Math.floor(seg / 86400);
    if (dias === 1) return '1 dia';
    if (dias < 30) return dias + ' dias';
    const meses = Math.floor(dias / 30);
    return meses === 1 ? '1 mês' : meses + ' meses';
}

function cfbFaixaNota(nota) {
    return nota >= 4 ? 'alta' : nota >= 3 ? 'media' : 'baixa';
}

// Selo de tendência (7 dias x 7 anteriores). invertido: subir é ruim (pendentes)
function cfbTendencia(atual, anterior, invertido, decimal) {
    const dif = decimal ? Math.round((atual - anterior) * 10) / 10 : atual - anterior;
    if (dif === 0) return '<span class="cfb-tendencia cfb-tend-igual"><span class="material-symbols-outlined" aria-hidden="true">remove</span> 0</span>';
    const sobe = dif > 0;
    const bom = invertido ? !sobe : sobe;
    const valor = decimal ? satNumero(Math.abs(dif), 1) : Math.abs(dif);
    return `<span class="cfb-tendencia ${bom ? 'cfb-tend-bom' : 'cfb-tend-ruim'}" title="Últimos 7 dias comparados aos 7 anteriores">`
        + `<span class="material-symbols-outlined" aria-hidden="true">${sobe ? 'trending_up' : 'trending_down'}</span> ${sobe ? '+' : '−'}${valor}</span>`;
}

function cfbTemFiltro(f) {
    return !!(f.status || f.nota || f.busca || f.data_ini || f.data_fim);
}

// ── Carga ───────────────────────────────────────────────────────────────────
async function carregarCentralFeedbacks(opcoes) {
    const est = satEstado.avaliacoes;
    const conteudo = document.getElementById('satisfacao-conteudo');
    const silencioso = !!(opcoes && opcoes.silencioso);
    try {
        const [resumo, lista] = await Promise.all([
            Api.get(satUrl('pesquisa-painel.php', Object.assign({ acao: 'resumo', dias: est.dias }, est.filtros))),
            Api.get(satUrl('pesquisa-painel.php', Object.assign({ acao: 'lista', pagina: est.pagina }, est.filtros))),
        ]);
        if (satTela !== 'avaliacoes') return; // trocou de tela enquanto carregava
        cfbLista = lista.itens;
        let dinamico = document.getElementById('cfb-dinamico');
        if (!dinamico) {
            // Primeira carga da visita: parte fixa (frase e Google Reviews) + parte dinâmica
            conteudo.innerHTML = `<div class="cfb-central">
                <p class="cfb-subtitulo">Gerencie e faça a triagem dos feedbacks recebidos dos cidadãos.</p>
                ${cfbFrase(resumo.frase)}
                ${cfbGoogleReviews()}
                <div id="cfb-dinamico"></div>
            </div>`;
            dinamico = document.getElementById('cfb-dinamico');
            cfbCarregarWidgetGoogle();
        }
        dinamico.innerHTML = cfbMetricas(resumo.indicadores) + cfbGraficoHtml(resumo) + cfbCategorias(resumo.categorias)
            + cfbFiltros(est.filtros) + cfbCartaoLista(lista, est.filtros);
        cfbGrafico = { serie: resumo.evolucao || [], meta: Number(resumo.meta_nota_media) || 0, pontos: [] };
        requestAnimationFrame(() => {
            cfbAplicarGeometria(dinamico);
            cfbDesenharGrafico();
            if (!silencioso) cfbContarNumeros(dinamico);
        });
        cfbMostrarContador(resumo.indicadores.pendentes);
        cfbAgendarRecarga();
    } catch (e) {
        if (silencioso) { cfbAgendarRecarga(); return; }
        satErro(e, 'Erro ao carregar a Central de Feedbacks.');
    }
}

// Recarga a cada 5 min (como no METRICA360): só nesta tela, aba visível, sem modal, painel ou menu abertos e sem digitação
function cfbAgendarRecarga() {
    clearTimeout(cfbTimerRecarga);
    cfbTimerRecarga = setTimeout(() => {
        const secao = document.getElementById('satisfacao');
        if (satTela !== 'avaliacoes' || !secao || !secao.classList.contains('active')) return;
        const ativo = document.activeElement;
        const digitando = ativo && ativo.matches('input, textarea, select, [contenteditable="true"]');
        const ocupado = document.getElementById('modal-overlay')?.classList.contains('show')
            || cfbPainelAberto() || document.querySelector('.cfb-status-menu:not([hidden])');
        if (document.hidden || digitando || ocupado) {
            cfbAgendarRecarga();
        } else {
            carregarCentralFeedbacks({ silencioso: true });
        }
    }, CFB_RECARGA_MS);
}

// ── Parte fixa ──────────────────────────────────────────────────────────────
function cfbFrase(frase) {
    if (!frase) return '';
    const podeEditar = Papeis.admin() && document.getElementById('btn-config-pesquisa');
    return `<div class="cfb-frase" role="note" aria-label="Frase do dia">
        <span class="material-symbols-outlined cfb-frase-aspas" aria-hidden="true">format_quote</span>
        <div class="cfb-frase-conteudo">
            <p class="cfb-frase-texto">${sanitizeHTML(frase.texto)}</p>
            ${frase.autor ? `<span class="cfb-frase-autor">— ${sanitizeHTML(frase.autor)}</span>` : ''}
        </div>
        ${podeEditar ? `<button type="button" class="cfb-frase-editar" data-cfb="editar-frases" title="Gerenciar frases" aria-label="Gerenciar frases do dia">
            <span class="material-symbols-outlined" aria-hidden="true">edit_note</span></button>` : ''}
    </div>`;
}

function cfbGoogleReviews() {
    return `<section class="cfb-caixa cfb-google" aria-label="Avaliação pública no Google Reviews">
        <div class="cfb-caixa-topo"><h3 class="cfb-caixa-titulo cfb-google-titulo">
            <span class="material-symbols-outlined" aria-hidden="true">reviews</span> Google Reviews — CRPRS</h3></div>
        <div class="cfb-google-corpo"><div class="${CFB_ELFSIGHT_APP}" data-elfsight-app-lazy></div></div>
    </section>`;
}

// Script do Elfsight uma vez só (o Meu Painel usa o mesmo); já carregado, o próprio widget acha a caixa nova
function cfbCarregarWidgetGoogle() {
    if (document.querySelector(`script[src="${CFB_ELFSIGHT_SCRIPT}"]`)) return;
    const s = document.createElement('script');
    s.src = CFB_ELFSIGHT_SCRIPT;
    s.async = true;
    s.onerror = () => {
        const caixa = document.querySelector(`#satisfacao-conteudo .${CFB_ELFSIGHT_APP}`);
        if (caixa) caixa.innerHTML = '<p class="cfb-aviso">Não foi possível carregar as avaliações do Google agora.</p>';
    };
    document.head.appendChild(s);
}

// ── Métricas Gerais ─────────────────────────────────────────────────────────
function cfbMetricas(ind) {
    const t = ind.tendencia || {};
    const total = ind.total;
    const pctPend = total > 0 ? Math.round(ind.pendentes / total * 100) : 0;
    const taxaResol = total > 0 ? Math.round(ind.resolvidos / total * 100) : 0;
    const pctMedia = Math.min(100, (ind.media / 5) * 100);
    const faixaResol = taxaResol >= 60 ? 'verde' : taxaResol >= 30 ? 'laranja' : 'vinho';
    const faixaMedia = pctMedia >= 80 ? 'verde' : pctMedia >= 60 ? 'laranja' : 'vinho';
    const barra = (pct, faixa, rotulo) => `<div class="cfb-kpi-barra" role="img" aria-label="${rotulo}: ${Math.round(pct)}%">
        <div class="cfb-kpi-barra-cheia cfb-cor-${faixa}" data-largura="${pct}"></div></div>`;
    const kpi = (cor, icone, valor, rotulo, extra, opcoes) => `<div class="cfb-kpi cfb-kpi-${cor}">
        <div class="cfb-kpi-icone" aria-hidden="true"><span class="material-symbols-outlined">${icone}</span></div>
        <div class="cfb-kpi-info">
            <div class="cfb-kpi-valor" data-contar="${valor}"${opcoes && opcoes.decimal ? ' data-decimal="1"' : ''}>${opcoes && opcoes.decimal ? satNumero(valor, 1) : valor}</div>
            <div class="cfb-kpi-rotulo">${rotulo}</div>
            ${extra || ''}
        </div>
    </div>`;
    return `<section class="cfb-metricas" aria-labelledby="cfb-metricas-titulo">
        <h3 class="cfb-caixa-titulo" id="cfb-metricas-titulo"><span class="material-symbols-outlined" aria-hidden="true">insights</span> Métricas Gerais</h3>
        <div class="cfb-kpis">
            ${kpi('total', 'analytics', total, 'Total de avaliações ' + cfbTendencia(t.total_atual || 0, t.total_anterior || 0))}
            ${kpi('pendente', 'mark_email_unread', ind.pendentes, 'Pendentes ' + cfbTendencia(t.pendentes_atual || 0, t.pendentes_anterior || 0, true), barra(pctPend, 'vinho', 'Pendentes do total'))}
            ${kpi('tratativa', 'autorenew', ind.tratativa, 'Em tratativa')}
            ${kpi('resolvido', 'task_alt', ind.resolvidos, 'Resolvidos ' + cfbTendencia(t.resolvidos_atual || 0, t.resolvidos_anterior || 0), barra(taxaResol, faixaResol, 'Taxa de resolução'))}
            ${kpi('hoje', 'today', ind.hoje, 'Hoje')}
            ${kpi('media', 'star', ind.media, 'Média CRPRS ' + cfbTendencia(t.media_atual || 0, t.media_anterior || 0, false, true), barra(pctMedia, faixaMedia, 'Média sobre 5'), { decimal: true })}
        </div>
    </section>`;
}

// Números sobem de 0 até o valor (como no METRICA360); sem animação para quem pediu menos movimento
function cfbContarNumeros(raiz) {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    raiz.querySelectorAll('.cfb-kpi-valor[data-contar]').forEach(el => {
        const alvo = Number(el.dataset.contar) || 0;
        const decimal = el.dataset.decimal === '1';
        const inicio = performance.now();
        const passo = (agora) => {
            const t = Math.min(1, (agora - inicio) / 700);
            const v = alvo * (1 - Math.pow(1 - t, 3));
            el.textContent = decimal ? satNumero(v, 1) : String(Math.round(v));
            if (t < 1) requestAnimationFrame(passo);
        };
        requestAnimationFrame(passo);
    });
}

// ── Evolução de Nota Média ──────────────────────────────────────────────────
function cfbGraficoHtml(resumo) {
    const temDados = (resumo.evolucao || []).some(s => s.qtd > 0);
    return `<section class="cfb-caixa" aria-labelledby="cfb-grafico-titulo">
        <div class="cfb-caixa-topo">
            <h3 class="cfb-caixa-titulo" id="cfb-grafico-titulo"><span class="material-symbols-outlined" aria-hidden="true">trending_up</span> Evolução de Nota Média</h3>
            <div class="cfb-periodos" role="group" aria-label="Período do gráfico">
                ${[7, 30, 90, 180].map(d => `<button type="button" class="cfb-periodo ${d === resumo.dias ? 'cfb-ativo' : ''}" data-cfb="dias" data-dias="${d}" aria-pressed="${d === resumo.dias}">${d} dias</button>`).join('')}
            </div>
        </div>
        <div class="cfb-grafico-corpo">
            ${temDados
                ? '<canvas id="cfb-grafico" class="cfb-grafico" role="img" aria-label="Evolução da nota média"></canvas>'
                : '<div class="cfb-grafico-vazio"><span class="material-symbols-outlined" aria-hidden="true">show_chart</span><p>Sem dados de avaliação neste período.</p></div>'}
        </div>
    </section>`;
}

// Linha suave da nota (com área), pontos pelo volume do dia, barras de volume (eixo da direita),
// linha tracejada da meta com rótulo, eixos "Nota"/"Volume" e legenda — o mesmo desenho do Chart.js de lá
function cfbDesenharGrafico() {
    const canvas = document.getElementById('cfb-grafico');
    if (!canvas || !cfbGrafico) return;
    const { serie, meta } = cfbGrafico;
    const css = getComputedStyle(canvas);
    const cor = (nome) => css.getPropertyValue(nome).trim();
    const corTexto = cor('--cfb-suave');
    const corGrade = cor('--cfb-grade');
    const corLinha = cor('--cfb-linha');
    const corBarra = cor('--cfb-barra');
    const corMeta = cor('--cfb-meta');

    // Largura útil da caixa (clientWidth conta o espaçamento interno; sem descontar, o canvas passava da borda)
    const estiloCaixa = getComputedStyle(canvas.parentElement);
    const largura = Math.max(280, canvas.parentElement.clientWidth - parseFloat(estiloCaixa.paddingLeft) - parseFloat(estiloCaixa.paddingRight));
    const altura = 300;
    const dpr = window.devicePixelRatio || 1;
    canvas.width = largura * dpr;
    canvas.height = altura * dpr;
    canvas.style.width = largura + 'px';   // geometria calculada (o canvas precisa do tamanho em px)
    canvas.style.height = altura + 'px';
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, largura, altura);
    ctx.font = '11px Inter, sans-serif';

    const m = { e: 48, d: 48, t: 34, b: 40 };
    const w = largura - m.e - m.d;
    const h = altura - m.t - m.b;
    const n = serie.length;
    const passoX = w / Math.max(1, n);
    const x = (i) => m.e + passoX * (i + 0.5);
    const yNota = (v) => m.t + h - (v / 5) * h;
    const maxQtd = Math.max(1, ...serie.map(s => s.qtd));
    const escalaVol = Math.max(1, Math.ceil(maxQtd * 1.25));
    const yVol = (v) => m.t + h - (v / escalaVol) * h;

    // Legenda
    ctx.textAlign = 'left';
    ctx.fillStyle = corLinha; ctx.beginPath(); ctx.arc(m.e + 6, 14, 5, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = corTexto; ctx.fillText('Nota Diária', m.e + 16, 18);
    ctx.fillStyle = corBarra; ctx.fillRect(m.e + 100, 9, 12, 10);
    ctx.fillStyle = corTexto; ctx.fillText('Volume', m.e + 118, 18);

    // Grade e eixo da nota (0 a 5)
    ctx.strokeStyle = corGrade; ctx.lineWidth = 1;
    for (let v = 0; v <= 5; v++) {
        const y = yNota(v);
        ctx.beginPath(); ctx.moveTo(m.e, y); ctx.lineTo(largura - m.d, y); ctx.stroke();
        ctx.fillStyle = corTexto; ctx.textAlign = 'right'; ctx.fillText(String(v), m.e - 8, y + 4);
    }
    // Eixo do volume (direita)
    ctx.textAlign = 'left';
    const passosVol = Math.min(5, escalaVol);
    for (let k = 0; k <= passosVol; k++) {
        const v = Math.round(escalaVol / passosVol * k);
        ctx.fillText(String(v), largura - m.d + 8, yVol(v) + 4);
    }
    // Títulos dos eixos
    ctx.save(); ctx.font = '600 11px Inter, sans-serif'; ctx.fillStyle = corTexto;
    ctx.translate(12, m.t + h / 2); ctx.rotate(-Math.PI / 2); ctx.textAlign = 'center'; ctx.fillText('Nota', 0, 0); ctx.restore();
    ctx.save(); ctx.font = '600 11px Inter, sans-serif'; ctx.fillStyle = corTexto;
    ctx.translate(largura - 10, m.t + h / 2); ctx.rotate(Math.PI / 2); ctx.textAlign = 'center'; ctx.fillText('Volume', 0, 0); ctx.restore();

    // Barras de volume, com cantos arredondados em cima
    const larguraBarra = Math.max(2, Math.min(26, passoX * 0.6));
    ctx.fillStyle = corBarra;
    serie.forEach((s, i) => {
        if (!s.qtd) return;
        const topo = yVol(s.qtd);
        const bx = x(i) - larguraBarra / 2;
        const raio = Math.min(6, larguraBarra / 2, (m.t + h - topo) / 2);
        ctx.beginPath();
        ctx.moveTo(bx, m.t + h);
        ctx.lineTo(bx, topo + raio);
        ctx.quadraticCurveTo(bx, topo, bx + raio, topo);
        ctx.lineTo(bx + larguraBarra - raio, topo);
        ctx.quadraticCurveTo(bx + larguraBarra, topo, bx + larguraBarra, topo + raio);
        ctx.lineTo(bx + larguraBarra, m.t + h);
        ctx.closePath();
        ctx.fill();
    });

    // Linha da nota: trechos contínuos (dia sem avaliação deixa um vão), curva suave e área com degradê
    const trechos = [];
    let atual = [];
    serie.forEach((s, i) => {
        if (s.avg_nota === null) { if (atual.length) trechos.push(atual); atual = []; return; }
        atual.push({ x: x(i), y: yNota(s.avg_nota), qtd: s.qtd });
    });
    if (atual.length) trechos.push(atual);
    const curva = (pts) => {
        ctx.moveTo(pts[0].x, pts[0].y);
        for (let k = 1; k < pts.length; k++) {
            const a = pts[k - 1], b = pts[k];
            const cx = (a.x + b.x) / 2;
            ctx.bezierCurveTo(cx, a.y, cx, b.y, b.x, b.y);
        }
    };
    const degrade = ctx.createLinearGradient(0, m.t, 0, m.t + h);
    degrade.addColorStop(0, cor('--cfb-area-topo'));
    degrade.addColorStop(1, cor('--cfb-area-base'));
    trechos.forEach(pts => {
        if (pts.length > 1) {
            ctx.beginPath(); curva(pts);
            ctx.lineTo(pts[pts.length - 1].x, m.t + h); ctx.lineTo(pts[0].x, m.t + h); ctx.closePath();
            ctx.fillStyle = degrade; ctx.fill();
            ctx.beginPath(); curva(pts);
            ctx.strokeStyle = corLinha; ctx.lineWidth = 2.5; ctx.stroke();
        }
        pts.forEach(p => {
            ctx.beginPath(); ctx.arc(p.x, p.y, 3 + (p.qtd / maxQtd) * 5, 0, Math.PI * 2);
            ctx.fillStyle = corLinha; ctx.fill();
        });
    });

    // Meta (linha tracejada com rótulo)
    if (meta > 0 && meta <= 5) {
        const y = yNota(meta);
        ctx.strokeStyle = corMeta; ctx.setLineDash([6, 4]); ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(m.e, y); ctx.lineTo(largura - m.d, y); ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = corMeta; ctx.textAlign = 'right'; ctx.font = '600 11px Inter, sans-serif';
        ctx.fillText('Meta ' + satNumero(meta, 1), largura - m.d - 4, y - 6);
        ctx.font = '11px Inter, sans-serif';
    }

    // Datas no eixo X (sem amontoar)
    ctx.fillStyle = corTexto; ctx.textAlign = 'center';
    const pulo = Math.max(1, Math.ceil(n / 10));
    serie.forEach((s, i) => {
        if (i % pulo === 0 || i === n - 1) ctx.fillText(s.data_dia.slice(8, 10) + '/' + s.data_dia.slice(5, 7), x(i), altura - 14);
    });

    cfbGrafico.pontos = serie.map((s, i) => ({ x: x(i), i }));
    cfbGrafico.limites = { e: m.e, d: largura - m.d };
    const comNota = serie.filter(s => s.avg_nota !== null);
    canvas.setAttribute('aria-label', `Evolução da nota média nos últimos ${n} dias: ${comNota.length} dia(s) com avaliação, `
        + `${serie.reduce((t, s) => t + s.qtd, 0)} avaliação(ões)`
        + (comNota.length ? `, última nota média ${satNumero(comNota[comNota.length - 1].avg_nota, 1)}` : '') + (meta ? `; meta ${satNumero(meta, 1)}` : '') + '.');
}

// Dica ao passar o mouse no gráfico (dia, nota média e volume)
function cfbDicaGrafico(ev) {
    const canvas = ev.target;
    if (!cfbGrafico || !cfbGrafico.pontos.length || !window.CanvasTooltip) return;
    const ret = canvas.getBoundingClientRect();
    const mx = ev.clientX - ret.left;
    if (mx < cfbGrafico.limites.e || mx > cfbGrafico.limites.d) { CanvasTooltip.hide(); return; }
    const perto = cfbGrafico.pontos.reduce((a, b) => (Math.abs(b.x - mx) < Math.abs(a.x - mx) ? b : a));
    const s = cfbGrafico.serie[perto.i];
    const [a, mes, d] = s.data_dia.split('-');
    CanvasTooltip.show(ev, `<strong>${d}/${mes}/${a}</strong><br>Nota Diária: ${s.avg_nota !== null ? satNumero(s.avg_nota, 1) : '—'}<br>Volume: ${s.qtd}`);
}

// ── Categorias mais mencionadas ─────────────────────────────────────────────
function cfbCategorias(categorias) {
    if (!categorias || !categorias.length) return '';
    const coluna = (campo, titulo, icone, classe) => {
        const linhas = categorias.filter(c => Number(c[campo]) > 0).sort((a, b) => b[campo] - a[campo]).slice(0, 5);
        return `<div class="cfb-cat-coluna">
            <h4 class="cfb-cat-titulo cfb-cat-${classe}"><span class="material-symbols-outlined" aria-hidden="true">${icone}</span> ${titulo}</h4>
            <ul class="cfb-cat-lista" aria-label="Categorias ${titulo.toLowerCase()}">
            ${linhas.length ? linhas.map(c => {
                const pct = c.total > 0 ? Math.round(c[campo] / c.total * 100) : 0;
                return `<li class="cfb-cat-item">
                    <div class="cfb-cat-topo"><span class="cfb-cat-emoji" aria-hidden="true">${sanitizeHTML(c.emoji)}</span>
                        <span class="cfb-cat-nome">${sanitizeHTML(c.nome)}</span><span class="cfb-cat-qtd cfb-cat-qtd-${classe}">${Number(c[campo])}</span></div>
                    <div class="cfb-cat-trilha"><div class="cfb-cat-barra cfb-cat-barra-${classe}" data-largura="${pct}" role="img" aria-label="${pct}% das menções de ${sanitizeHTML(c.nome)}"></div></div>
                </li>`;
            }).join('') : '<li class="cfb-cat-vazio">Nenhuma categoria ainda.</li>'}
            </ul>
        </div>`;
    };
    return `<section class="cfb-caixa" aria-labelledby="cfb-cat-titulo">
        <div class="cfb-caixa-topo"><h3 class="cfb-caixa-titulo" id="cfb-cat-titulo"><span class="material-symbols-outlined" aria-hidden="true">label</span> Categorias mais mencionadas</h3></div>
        <div class="cfb-cat-colunas">${coluna('elogios', 'Mais elogiadas', 'thumb_up', 'elogio')}${coluna('criticas', 'Mais criticadas', 'thumb_down', 'critica')}</div>
    </section>`;
}

// ── Filtros ─────────────────────────────────────────────────────────────────
function cfbFiltros(f) {
    const ativo = cfbTemFiltro(f);
    const opcoesStatus = Object.entries(CFB_STATUS).map(([v, s]) => `<option value="${v}" ${f.status === v ? 'selected' : ''}>${s.rotulo}</option>`).join('');
    const opcoesNota = [1, 2, 3, 4, 5].map(n => `<option value="${n}" ${String(f.nota || '') === String(n) ? 'selected' : ''}>${'★'.repeat(n)}</option>`).join('');
    return `<section class="cfb-filtros" aria-label="Filtros de busca">
        <button type="button" class="cfb-filtros-botao ${ativo ? 'cfb-aberto' : ''}" data-cfb="filtros" aria-expanded="${ativo}" aria-controls="cfb-filtros-form">
            <span class="material-symbols-outlined" aria-hidden="true">tune</span> Filtros
            <span class="material-symbols-outlined cfb-seta" aria-hidden="true">expand_more</span>
        </button>
        <form id="cfb-filtros-form" class="cfb-filtros-barra" data-acao-form="avaliacoes" role="search" ${ativo ? '' : 'hidden'}>
            <div class="cfb-campo"><label for="cfb-f-status">Status</label><select id="cfb-f-status" name="status"><option value="">Todos</option>${opcoesStatus}</select></div>
            <div class="cfb-campo"><label for="cfb-f-nota">Nota</label><select id="cfb-f-nota" name="nota"><option value="">Todas</option>${opcoesNota}</select></div>
            <div class="cfb-campo"><label for="cfb-f-ini">De</label><input type="date" id="cfb-f-ini" name="data_ini" value="${sanitizeHTML(f.data_ini || '')}"></div>
            <div class="cfb-campo"><label for="cfb-f-fim">Até</label><input type="date" id="cfb-f-fim" name="data_fim" value="${sanitizeHTML(f.data_fim || '')}"></div>
            <div class="cfb-campo"><label for="cfb-f-busca">Buscar no relato</label><input type="search" id="cfb-f-busca" name="busca" placeholder="Palavra-chave..." value="${sanitizeHTML(f.busca || '')}"></div>
            <div class="cfb-filtros-acoes">
                <button type="submit" class="cfb-botao cfb-botao-primario"><span class="material-symbols-outlined" aria-hidden="true">search</span> Filtrar</button>
                ${ativo ? `<span class="cfb-filtro-ativo">Filtros Ativos</span>
                    <button type="button" class="cfb-botao cfb-botao-claro" data-cfb="limpar"><span class="material-symbols-outlined" aria-hidden="true">clear_all</span> Limpar</button>` : ''}
            </div>
        </form>
    </section>`;
}

// ── Feedbacks Recebidos ─────────────────────────────────────────────────────
function cfbCartaoLista(lista, f) {
    const total = lista.total;
    const csv = satLinkCsv('pesquisa-painel.php', f);
    return `<section class="cfb-cartao" aria-labelledby="cfb-lista-titulo">
        <div class="cfb-cartao-topo">
            <div class="cfb-cartao-topo-esq">
                <span class="material-symbols-outlined" aria-hidden="true">table_rows</span>
                <h3 class="cfb-cartao-titulo" id="cfb-lista-titulo">Feedbacks Recebidos</h3>
                ${total > 0 ? `<span class="cfb-cartao-qtd">${total.toLocaleString('pt-BR')} registro${total !== 1 ? 's' : ''}</span>` : ''}
            </div>
            ${total > 0 ? `<div class="cfb-cartao-topo-dir">
                <a class="cfb-exportar cfb-exportar-csv" href="${sanitizeHTML(csv)}" download title="Exportar CSV com filtros atuais">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span> CSV</a>
                <button type="button" class="cfb-exportar cfb-exportar-pdf" data-cfb="imprimir" title="Salvar PDF">
                    <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span> PDF</button>
            </div>` : ''}
        </div>
        <div class="cfb-tabela-rolagem">${cfbTabela(lista, f)}</div>
        ${cfbPaginacao(lista, f)}
    </section>`;
}

function cfbTabela(lista, f) {
    if (!lista.itens.length) {
        return `<div class="cfb-vazio" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">inbox</span>
            ${cfbTemFiltro(f)
                ? '<h4>Nenhum feedback encontrado</h4><p>Nenhum resultado para os filtros selecionados. <button type="button" class="cfb-link" data-cfb="limpar">Limpar filtros</button>.</p>'
                : '<h4>Nenhum feedback recebido ainda</h4><p>Quando os cidadãos enviarem avaliações, elas aparecerão aqui.</p>'}
        </div>`;
    }
    const circ = 2 * Math.PI * 15;
    const linhas = lista.itens.map(a => {
        const criado = cfbData(a.criado_em);
        const minutos = (Date.now() - criado.getTime()) / 60000;
        const dias = Math.floor(minutos / 1440);
        const st = CFB_STATUS[a.status] || { rotulo: a.status, icone: 'help' };
        const relato = String(a.comentario || '');
        const longo = relato.length > 120;
        const temAnotacao = !!(a.anotacao && String(a.anotacao).trim());
        const data = criado.toLocaleDateString('pt-BR');
        const hora = criado.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        return `<tr class="cfb-linha">
            <td><div class="cfb-registro">
                <div class="cfb-registro-id">#${a.id}${minutos < 60 ? ' <span class="cfb-novo">Novo</span>' : ''}</div>
                <div class="cfb-registro-data">${data}</div>
                <div class="cfb-registro-hora">${hora} · ${cfbTempoDecorrido(a.criado_em)}</div>
            </div></td>
            <td><div class="cfb-nota">
                <div class="cfb-anel" role="img" aria-label="Nota ${a.nota} de 5">
                    <svg width="38" height="38" viewBox="0 0 38 38" aria-hidden="true">
                        <circle class="cfb-anel-fundo" cx="19" cy="19" r="15"></circle>
                        <circle class="cfb-anel-arco cfb-anel-${cfbFaixaNota(a.nota)}" cx="19" cy="19" r="15"
                            stroke-dasharray="${circ.toFixed(2)}" stroke-dashoffset="${(circ - circ * a.nota / 5).toFixed(2)}"></circle>
                    </svg>
                    <span class="cfb-anel-valor" aria-hidden="true">${a.nota}</span>
                </div>
                <div aria-hidden="true">
                    <div class="cfb-estrelas"><span class="cfb-estrela-on">${'★'.repeat(a.nota)}</span><span class="cfb-estrela-off">${'★'.repeat(5 - a.nota)}</span></div>
                    <span class="cfb-nota-num">${a.nota}/5</span>
                </div>
            </div></td>
            <td class="cfb-relato">
                ${relato
                    ? `<div class="cfb-relato-texto ${longo ? 'cfb-relato-cortado' : ''}" id="cfb-relato-${a.id}">${sanitizeHTML(relato)}</div>
                       ${longo ? `<button type="button" class="cfb-expandir" data-cfb="relato" aria-expanded="false" aria-controls="cfb-relato-${a.id}">
                           <span class="material-symbols-outlined" aria-hidden="true">expand_more</span> <span>Ver tudo</span></button>` : ''}`
                    : '<span class="cfb-sem-relato">Sem relato</span>'}
                ${a.categorias && a.categorias.length ? `<p class="cfb-chips">${a.categorias.map(c => `<span class="cfb-chip">${sanitizeHTML(c.emoji)} ${sanitizeHTML(c.nome)}</span>`).join('')}</p>` : ''}
            </td>
            <td class="cfb-servidor">${sanitizeHTML(a.servidor_nome || '—')}<small>${sanitizeHTML(a.setor_nome || 'Sem setor')}${a.protocolo ? ' · ' + sanitizeHTML(a.protocolo) : ''}</small></td>
            <td>
                <div class="cfb-status-caixa">
                    <button type="button" class="cfb-status cfb-status-${sanitizeHTML(a.status)}" data-cfb="status" data-id="${a.id}"
                        aria-haspopup="true" aria-expanded="false" aria-controls="cfb-status-menu-${a.id}" aria-label="Status: ${st.rotulo}. Alterar">
                        <span class="material-symbols-outlined" aria-hidden="true">${st.icone}</span> ${st.rotulo}
                        <span class="material-symbols-outlined cfb-status-seta" aria-hidden="true">expand_more</span>
                    </button>
                    <div class="cfb-status-menu" id="cfb-status-menu-${a.id}" hidden>
                        ${Object.entries(CFB_STATUS).map(([v, s]) => `<button type="button" class="cfb-status-item ${a.status === v ? 'cfb-selecionado' : ''}" data-cfb="definir-status" data-id="${a.id}" data-status="${v}" ${a.status === v ? 'aria-current="true"' : ''}>
                            <span class="material-symbols-outlined cfb-si-${v}" aria-hidden="true">${s.icone}</span> ${s.rotulo}</button>`).join('')}
                    </div>
                </div>
                ${a.status === 'pendente' && dias >= 3 ? `<div class="cfb-tempo ${dias >= 7 ? 'cfb-tempo-urgente' : ''}">${dias >= 7 ? 'Há' : 'há'} ${cfbTempoDecorrido(a.criado_em)}</div>` : ''}
            </td>
            <td class="cfb-acoes">
                <div class="cfb-acoes-linha">
                    <button type="button" class="cfb-acao ${temAnotacao ? 'cfb-tem-nota' : ''}" data-cfb="anotar" data-id="${a.id}"
                        title="${temAnotacao ? 'Editar anotação' : 'Adicionar anotação'}" aria-label="${temAnotacao ? 'Editar anotação do feedback #' + a.id : 'Adicionar anotação ao feedback #' + a.id}">
                        <span class="material-symbols-outlined" aria-hidden="true">edit_note</span></button>
                    <button type="button" class="cfb-acao cfb-acao-ver" data-cfb="anotar" data-id="${a.id}" title="Ver detalhes" aria-label="Ver detalhes do feedback #${a.id}">
                        <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span></button>
                </div>
            </td>
        </tr>`;
    }).join('');
    return `<table class="cfb-tabela">
        <caption class="sr-only">Lista de feedbacks recebidos — página ${lista.pagina} de ${lista.total_paginas}</caption>
        <thead><tr><th scope="col">Registro</th><th scope="col">Avaliação</th><th scope="col">Relato do Cidadão</th><th scope="col">Servidor</th><th scope="col">Status</th><th scope="col" class="cfb-col-acoes">Ações</th></tr></thead>
        <tbody>${linhas}</tbody></table>`;
}

function cfbPaginacao(lista, f) {
    const { total, pagina, por_pagina: porPagina, total_paginas: totalPag } = lista;
    if (total === 0) return '';
    const plural = total !== 1 ? 's' : '';
    if (totalPag <= 1) {
        return `<div class="cfb-paginacao-rodape"><div class="cfb-pag-info">Exibindo <strong>${total.toLocaleString('pt-BR')}</strong> feedback${plural}</div></div>`;
    }
    const botao = (p, conteudo, rotulo, desabilitado) => desabilitado
        ? `<span class="cfb-pag-desab" aria-hidden="true">${conteudo}</span>`
        : `<button type="button" class="cfb-pag" data-cfb="pagina" data-pagina="${p}" aria-label="${rotulo}">${conteudo}</button>`;
    const icone = nome => `<span class="material-symbols-outlined" aria-hidden="true">${nome}</span>`;
    const ini = Math.max(1, pagina - 2);
    const fim = Math.min(totalPag, pagina + 2);
    let numeros = '';
    if (ini > 1) numeros += botao(1, '1', 'Página 1') + (ini > 2 ? '<span class="cfb-pag-reticencias" aria-hidden="true">…</span>' : '');
    for (let p = ini; p <= fim; p++) {
        numeros += p === pagina ? `<span class="cfb-pag-atual" aria-current="page">${p}</span>` : botao(p, String(p), 'Página ' + p);
    }
    if (fim < totalPag) numeros += (fim < totalPag - 1 ? '<span class="cfb-pag-reticencias" aria-hidden="true">…</span>' : '') + botao(totalPag, String(totalPag), 'Página ' + totalPag);
    return `<div class="cfb-paginacao-rodape">
        <div class="cfb-pag-info" aria-live="polite">Exibindo <strong>${Math.min((pagina - 1) * porPagina + 1, total)}–${Math.min(pagina * porPagina, total)}</strong>
            de <strong>${total.toLocaleString('pt-BR')}</strong> feedback${plural}${cfbTemFiltro(f) ? ' (filtrado)' : ''}</div>
        <nav class="cfb-paginacao" aria-label="Paginação">
            ${botao(1, icone('first_page'), 'Primeira página', pagina <= 1)}${botao(pagina - 1, icone('chevron_left'), 'Anterior', pagina <= 1)}
            ${numeros}
            ${botao(pagina + 1, icone('chevron_right'), 'Próxima', pagina >= totalPag)}${botao(totalPag, icone('last_page'), 'Última', pagina >= totalPag)}
        </nav>
    </div>`;
}

// ── Geometria ───────────────────────────────────────────────────────────────
function cfbAplicarGeometria(raiz) {
    raiz.querySelectorAll('[data-largura]').forEach(el => { el.style.width = el.dataset.largura + '%'; });
}

// ── Status (menu do selo) ───────────────────────────────────────────────────
function cfbFecharMenus(exceto) {
    document.querySelectorAll('#satisfacao-conteudo .cfb-status-menu:not([hidden])').forEach(menu => {
        if (menu === exceto) return;
        menu.hidden = true;
        const botao = document.querySelector(`[aria-controls="${menu.id}"]`);
        if (botao) botao.setAttribute('aria-expanded', 'false');
    });
}

function cfbAlternarMenu(botao) {
    const menu = document.getElementById(botao.getAttribute('aria-controls'));
    if (!menu) return;
    const abrir = menu.hidden;
    cfbFecharMenus(menu);
    menu.hidden = !abrir;
    botao.setAttribute('aria-expanded', String(abrir));
    if (abrir) (menu.querySelector('.cfb-selecionado') || menu.querySelector('button'))?.focus();
}

async function cfbDefinirStatus(id, status) {
    cfbFecharMenus();
    const item = cfbLista.find(a => Number(a.id) === Number(id));
    if (item && item.status === status) return;
    try {
        await Api.put(`pesquisa-triagem.php?id=${id}`, { status });
        mostrarToast('Sucesso', `Status atualizado para ${CFB_STATUS[status].rotulo}.`, 'success');
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao atualizar o status.'));
    }
    carregarCentralFeedbacks({ silencioso: true });
}

// ── Painel lateral de anotação ──────────────────────────────────────────────
function cfbPainel() {
    let painel = document.getElementById('cfb-painel');
    if (painel) return painel;
    const fundo = document.createElement('div');
    fundo.className = 'cfb-painel-fundo';
    fundo.id = 'cfb-painel-fundo';
    fundo.hidden = true;
    fundo.dataset.cfb = 'fechar-painel';
    painel = document.createElement('aside');
    painel.className = 'cfb-painel';
    painel.id = 'cfb-painel';
    painel.hidden = true;
    painel.setAttribute('role', 'dialog');
    painel.setAttribute('aria-modal', 'true');
    painel.setAttribute('aria-labelledby', 'cfb-painel-titulo');
    painel.innerHTML = `
        <div class="cfb-painel-topo">
            <h2 id="cfb-painel-titulo"><span class="material-symbols-outlined" aria-hidden="true">edit_note</span> Anotação Interna</h2>
            <button type="button" class="cfb-painel-fechar" data-cfb="fechar-painel" aria-label="Fechar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
        </div>
        <div class="cfb-painel-corpo">
            <div class="cfb-painel-meta"><span class="cfb-painel-id" id="cfb-painel-id"></span><div class="cfb-painel-nota" id="cfb-painel-nota"></div><div class="cfb-painel-servidor" id="cfb-painel-servidor"></div></div>
            <div class="cfb-painel-relato" id="cfb-painel-relato"></div>
            <label class="cfb-painel-rotulo" for="cfb-painel-texto">Anotação interna (não é vista pelo cidadão)</label>
            <textarea class="cfb-painel-texto" id="cfb-painel-texto" maxlength="2000" placeholder="Escreva suas observações sobre este feedback..."></textarea>
        </div>
        <div class="cfb-painel-rodape">
            <button type="button" class="cfb-botao cfb-botao-claro" data-cfb="fechar-painel"><span class="material-symbols-outlined" aria-hidden="true">close</span> Cancelar</button>
            <button type="button" class="cfb-botao cfb-botao-primario" data-cfb="salvar-anotacao"><span class="material-symbols-outlined" aria-hidden="true">save</span> Salvar anotação</button>
        </div>`;
    document.body.appendChild(fundo);
    document.body.appendChild(painel);
    return painel;
}

function cfbPainelAberto() {
    const p = document.getElementById('cfb-painel');
    return !!(p && !p.hidden);
}

function cfbAbrirPainel(id, origem) {
    const a = cfbLista.find(x => Number(x.id) === Number(id));
    if (!a) return;
    const painel = cfbPainel();
    painel.dataset.id = String(a.id);
    document.getElementById('cfb-painel-id').textContent = '#' + a.id + ' · ' + cfbData(a.criado_em).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    document.getElementById('cfb-painel-nota').innerHTML = `<span class="cfb-estrelas"><span class="cfb-estrela-on">${'★'.repeat(a.nota)}</span><span class="cfb-estrela-off">${'★'.repeat(5 - a.nota)}</span></span> <span class="cfb-nota-num">${a.nota}/5</span>`;
    document.getElementById('cfb-painel-servidor').textContent = (a.servidor_nome || '—') + ' · ' + (a.setor_nome || 'Sem setor') + (a.protocolo ? ' · ' + a.protocolo : '');
    const relato = document.getElementById('cfb-painel-relato');
    relato.textContent = a.comentario || 'Sem relato';
    relato.classList.toggle('cfb-sem-relato', !a.comentario);
    document.getElementById('cfb-painel-texto').value = a.anotacao || '';
    cfbFocoAntesDoPainel = origem || document.activeElement;
    document.getElementById('cfb-painel-fundo').hidden = false;
    painel.hidden = false;
    requestAnimationFrame(() => {
        painel.classList.add('cfb-aberto');
        document.getElementById('cfb-painel-texto').focus();
    });
}

function cfbFecharPainel() {
    const painel = document.getElementById('cfb-painel');
    if (!painel || painel.hidden) return;
    painel.classList.remove('cfb-aberto');
    document.getElementById('cfb-painel-fundo').hidden = true;
    painel.hidden = true;
    if (cfbFocoAntesDoPainel && document.contains(cfbFocoAntesDoPainel)) cfbFocoAntesDoPainel.focus();
    cfbFocoAntesDoPainel = null;
}

async function cfbSalvarAnotacao() {
    const painel = document.getElementById('cfb-painel');
    const id = Number(painel.dataset.id);
    const texto = document.getElementById('cfb-painel-texto').value.trim();
    const botao = painel.querySelector('[data-cfb="salvar-anotacao"]');
    botao.disabled = true;
    try {
        await Api.put(`pesquisa-triagem.php?id=${id}`, { anotacao: texto });
        mostrarToast('Sucesso', 'Anotação salva.', 'success');
        cfbFecharPainel();
        carregarCentralFeedbacks({ silencioso: true });
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao salvar a anotação.'));
    } finally {
        botao.disabled = false;
    }
}

// ── Contador de pendentes no menu ───────────────────────────────────────────
function cfbMostrarContador(qtd) {
    ['nav-satisfacao-pendentes', 'nav-avaliacoes-pendentes'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        const n = Number(qtd) || 0;
        el.hidden = n <= 0;
        el.querySelector('.cfb-contador-num').textContent = n > 99 ? '99+' : String(n);
        el.querySelector('.sr-only').textContent = ` ${n} pendente${n !== 1 ? 's' : ''}`;
    });
}

async function cfbAtualizarContador() {
    if (!window.Papeis || !Papeis.veSatisfacao()) return;
    try {
        const r = await Api.get(satUrl('pesquisa-painel.php', { acao: 'pendentes' }));
        cfbMostrarContador(r.pendentes);
    } catch (e) { /* sem o número o menu segue funcionando */ }
}

// PDF: impressão do navegador só com a Central (sidebar, filtros, ações e Google escondidos pelo CSS de impressão)
function cfbImprimir() {
    document.body.classList.add('cfb-imprimindo');
    window.addEventListener('afterprint', () => document.body.classList.remove('cfb-imprimindo'), { once: true });
    window.print();
}

// ── Ouvintes ────────────────────────────────────────────────────────────────
document.addEventListener('click', (ev) => {
    const alvo = ev.target.closest('[data-cfb]');
    if (!alvo || !(alvo.closest('#satisfacao-conteudo') || alvo.closest('#cfb-painel') || alvo.id === 'cfb-painel-fundo')) {
        if (!ev.target.closest('.cfb-status-caixa')) cfbFecharMenus();
        return;
    }
    const acao = alvo.dataset.cfb;
    const est = satEstado.avaliacoes;
    if (acao !== 'status' && acao !== 'definir-status') cfbFecharMenus();
    switch (acao) {
        case 'dias':
            est.dias = Number(alvo.dataset.dias) || 30;
            carregarCentralFeedbacks();
            break;
        case 'filtros': {
            const form = document.getElementById('cfb-filtros-form');
            const abrir = form.hidden;
            form.hidden = !abrir;
            alvo.setAttribute('aria-expanded', String(abrir));
            alvo.classList.toggle('cfb-aberto', abrir);
            break;
        }
        case 'limpar':
            est.filtros = {};
            est.pagina = 1;
            carregarCentralFeedbacks();
            break;
        case 'pagina':
            est.pagina = Math.max(1, Number(alvo.dataset.pagina) || 1);
            carregarCentralFeedbacks();
            document.getElementById('cfb-lista-titulo')?.scrollIntoView({ block: 'start' });
            break;
        case 'relato': {
            const texto = document.getElementById(alvo.getAttribute('aria-controls'));
            const abrir = alvo.getAttribute('aria-expanded') !== 'true';
            texto.classList.toggle('cfb-relato-cortado', !abrir);
            alvo.setAttribute('aria-expanded', String(abrir));
            alvo.querySelector('.material-symbols-outlined').textContent = abrir ? 'expand_less' : 'expand_more';
            alvo.querySelector('span:last-child').textContent = abrir ? 'Ver menos' : 'Ver tudo';
            break;
        }
        case 'status':
            cfbAlternarMenu(alvo);
            break;
        case 'definir-status':
            cfbDefinirStatus(alvo.dataset.id, alvo.dataset.status);
            break;
        case 'anotar':
            cfbAbrirPainel(alvo.dataset.id, alvo);
            break;
        case 'fechar-painel':
            cfbFecharPainel();
            break;
        case 'salvar-anotacao':
            cfbSalvarAnotacao();
            break;
        case 'imprimir':
            cfbImprimir();
            break;
        case 'editar-frases':
            document.getElementById('btn-config-pesquisa')?.click();
            break;
    }
});

// Teclado: ESC fecha menu/painel; setas no menu de status; Tab preso no painel
document.addEventListener('keydown', (ev) => {
    const menu = ev.target.closest && ev.target.closest('.cfb-status-menu');
    if (ev.key === 'Escape') {
        if (cfbPainelAberto()) { ev.preventDefault(); cfbFecharPainel(); return; }
        const aberto = document.querySelector('#satisfacao-conteudo .cfb-status-menu:not([hidden])');
        if (aberto) {
            ev.preventDefault();
            const botao = document.querySelector(`[aria-controls="${aberto.id}"]`);
            cfbFecharMenus();
            botao?.focus();
        }
        return;
    }
    if (menu && (ev.key === 'ArrowDown' || ev.key === 'ArrowUp')) {
        ev.preventDefault();
        const itens = Array.from(menu.querySelectorAll('button'));
        const i = itens.indexOf(document.activeElement);
        itens[(i + (ev.key === 'ArrowDown' ? 1 : itens.length - 1)) % itens.length].focus();
        return;
    }
    if (ev.key === 'Tab' && cfbPainelAberto()) {
        const focaveis = Array.from(document.getElementById('cfb-painel').querySelectorAll('button, textarea')).filter(el => !el.disabled);
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        if (ev.shiftKey && document.activeElement === primeiro) { ev.preventDefault(); ultimo.focus(); }
        else if (!ev.shiftKey && document.activeElement === ultimo) { ev.preventDefault(); primeiro.focus(); }
    }
});

// Dica do gráfico
document.addEventListener('mousemove', (ev) => {
    if (ev.target && ev.target.id === 'cfb-grafico') cfbDicaGrafico(ev);
});
document.addEventListener('mouseout', (ev) => {
    if (ev.target && ev.target.id === 'cfb-grafico' && window.CanvasTooltip) CanvasTooltip.hide();
});

window.addEventListener('resize', () => {
    if (document.getElementById('cfb-grafico')) {
        clearTimeout(window._cfbRedimensionar);
        window._cfbRedimensionar = setTimeout(cfbDesenharGrafico, 200);
    }
});

// O modo escuro muda as cores do gráfico (lidas dos tokens)
new MutationObserver(() => { if (document.getElementById('cfb-grafico')) cfbDesenharGrafico(); })
    .observe(document.body, { attributes: true, attributeFilter: ['class'] });

// Contador do menu: ao abrir o sistema e ao trocar o setor do cabeçalho
document.addEventListener('setor-contexto-alterado', cfbAtualizarContador);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(cfbAtualizarContador, 800));
} else {
    setTimeout(cfbAtualizarContador, 800);
}
