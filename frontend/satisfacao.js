/**
 * Módulo Satisfação (antigo METRICA360, unificado ao Conecta em 03/11/2026): grupo "Satisfação" da sidebar,
 * seção #satisfacao, subtela em cd_subSecaoAtiva.
 *   Avaliações        indicadores, evolução da nota, categorias mais citadas e a lista com a triagem
 *                     (situação e anotação)
 *   Painel de Setores por setor, os servidores com convites enviados, taxa de resposta e notas
 *   Convites          histórico dos convites enviados (somente leitura)
 * Quem vê: administrador, coordenador geral, supervisor e coordenador (Papeis.veSatisfacao). Os perfis de consulta
 * só fazem a triagem. O servidor limita o que cada um enxerga; o seletor de setor do cabeçalho vale aqui (urlComSetor).
 * Sem onclick/style inline: ações por data-acao com ouvintes delegados (CSS em satisfacao.css).
 */
const SAT_TELAS = {
    'avaliacoes': { titulo: '⭐ Avaliações', carregar: () => carregarSatAvaliacoes() },
    'painel-setores': { titulo: '🏢 Painel de Setores', carregar: () => carregarSatSetores() },
    'convites': { titulo: '✉️ Convites', carregar: () => carregarSatConvites() },
};
const SAT_SITUACOES = { pendente: 'Pendente', em_tratativa: 'Em tratativa', resolvido: 'Resolvido' };
const SAT_SITUACOES_CONVITE = { avaliado: 'Avaliado', aguardando: 'Aguardando', expirado: 'Expirado' };

let satTela = 'avaliacoes';
const satEstado = {
    avaliacoes: { pagina: 1, dias: 30, filtros: {} },
    convites: { pagina: 1, filtros: {} },
    setores: { filtros: {} },
};
let satUltimaEvolucao = null;
let satListaAtual = [];
let satVisaoSetores = 'cartoes';

function podeVerSatisfacao() {
    return Papeis.veSatisfacao();
}

function mostrarSatisfacao(tela) {
    satTela = SAT_TELAS[tela] ? tela : 'avaliacoes';
    mostrarSecao('satisfacao');
    localStorage.setItem('cd_subSecaoAtiva', satTela);
    document.getElementById('satisfacao-titulo').textContent = SAT_TELAS[satTela].titulo;
    const conteudo = document.getElementById('satisfacao-conteudo');
    ligarAcoesSatisfacao(conteudo);
    conteudo.innerHTML = '<p class="sat-aviso">Carregando...</p>';
    SAT_TELAS[satTela].carregar();
}

function satUrl(arquivo, params) {
    const q = new URLSearchParams();
    Object.entries(params || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v); });
    const texto = q.toString();
    return urlComSetor(arquivo + (texto ? '?' + texto : ''));
}

function satLinkCsv(arquivo, params) {
    return window.APP_CONFIG.apiBase + satUrl(arquivo, Object.assign({ acao: 'csv' }, params));
}

function satBr(valor) {
    if (!valor) return '';
    const [data, hora] = String(valor).split(' ');
    const [a, m, d] = data.split('-');
    return `${d}/${m}/${a}` + (hora ? ' ' + hora.slice(0, 5) : '');
}

function satErro(e, padrao) {
    document.getElementById('satisfacao-conteudo').innerHTML =
        `<p class="sat-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, padrao))}</p>`;
}

function satEstrelas(nota) {
    const n = Math.max(0, Math.min(5, Number(nota) || 0));
    return `<span class="sat-estrelas" role="img" aria-label="Nota ${n} de 5">${'★'.repeat(n)}${'☆'.repeat(5 - n)}</span>`;
}

function satNumero(valor, casas) {
    return valor === null || valor === undefined ? '—' : Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: casas || 0, maximumFractionDigits: casas || 0 });
}

function satTendencia(atual, anterior, rotulo) {
    if (!anterior && !atual) return '';
    const dif = atual - anterior;
    const seta = dif > 0 ? '▲' : dif < 0 ? '▼' : '=';
    const classe = dif > 0 ? 'sat-sobe' : dif < 0 ? 'sat-desce' : 'sat-igual';
    return `<small class="sat-tendencia ${classe}">${seta} ${rotulo} (7 dias)</small>`;
}

function satOpcoes(mapa, atual, vazio) {
    return `<option value="">${vazio}</option>` + Object.entries(mapa)
        .map(([v, r]) => `<option value="${v}" ${v === atual ? 'selected' : ''}>${r}</option>`).join('');
}

function satPaginacao(pagina, total, destino) {
    if (total <= 1) return '';
    return `<nav class="sat-paginacao" aria-label="Páginas">
        <button type="button" class="btn-small" data-acao="pagina" data-destino="${destino}" data-pagina="${pagina - 1}" ${pagina <= 1 ? 'disabled' : ''}>← Anterior</button>
        <span>Página ${pagina} de ${total}</span>
        <button type="button" class="btn-small" data-acao="pagina" data-destino="${destino}" data-pagina="${pagina + 1}" ${pagina >= total ? 'disabled' : ''}>Próxima →</button>
    </nav>`;
}

// ═══════════════ Avaliações ═══════════════
async function carregarSatAvaliacoes() {
    const est = satEstado.avaliacoes;
    try {
        const filtros = est.filtros;
        const [resumo, lista] = await Promise.all([
            Api.get(satUrl('pesquisa-painel.php', Object.assign({ acao: 'resumo', dias: est.dias }, filtros))),
            Api.get(satUrl('pesquisa-painel.php', Object.assign({ acao: 'lista', pagina: est.pagina }, filtros))),
        ]);
        satListaAtual = lista.itens;
        const ind = resumo.indicadores;
        const meta = Number(resumo.meta_nota_media) || 0;
        const kpi = (valor, rotulo, extra) => `<div class="sat-kpi"><strong>${valor}</strong><span>${rotulo}</span>${extra || ''}</div>`;

        document.getElementById('satisfacao-conteudo').innerHTML = `
            ${resumo.frase ? `<blockquote class="sat-frase">“${sanitizeHTML(resumo.frase.texto)}”${resumo.frase.autor ? `<footer>— ${sanitizeHTML(resumo.frase.autor)}</footer>` : ''}</blockquote>` : ''}
            <div class="sat-kpis">
                ${kpi(ind.total, 'Avaliações', satTendencia(ind.tendencia.total_atual, ind.tendencia.total_anterior, 'novas'))}
                ${kpi(satNumero(ind.media, 1) + ' ★', 'Nota média', (meta ? `<small class="sat-meta">meta ${satNumero(meta, 1)}</small>` : '') + satTendencia(ind.tendencia.media_atual, ind.tendencia.media_anterior, 'média'))}
                ${kpi(ind.pendentes, 'Pendentes')}
                ${kpi(ind.tratativa, 'Em tratativa')}
                ${kpi(ind.resolvidos, 'Resolvidas')}
                ${kpi(ind.hoje, 'Hoje')}
            </div>

            <section class="sat-bloco" aria-labelledby="sat-evolucao-titulo">
                <div class="sat-bloco-topo">
                    <h3 id="sat-evolucao-titulo">Evolução da nota média</h3>
                    <div class="sat-periodos" role="group" aria-label="Período do gráfico">
                        ${[7, 30, 90, 180].map(d => `<button type="button" class="btn-small ${d === resumo.dias ? 'sat-ativo' : ''}" data-acao="dias" data-dias="${d}" aria-pressed="${d === resumo.dias}">${d} dias</button>`).join('')}
                    </div>
                </div>
                <canvas id="sat-grafico" class="sat-grafico" role="img" aria-label="Evolução da nota média"></canvas>
            </section>

            ${blocoCategoriasSat(resumo.categorias)}

            <section class="sat-bloco" aria-labelledby="sat-lista-titulo">
                <h3 id="sat-lista-titulo">Avaliações recebidas</h3>
                <form class="sat-filtros" data-acao-form="avaliacoes">
                    <label>Situação <select name="status">${satOpcoes(SAT_SITUACOES, filtros.status || '', 'Todas')}</select></label>
                    <label>Nota <select name="nota">${satOpcoes({ 1: '1 ★', 2: '2 ★', 3: '3 ★', 4: '4 ★', 5: '5 ★' }, String(filtros.nota || ''), 'Todas')}</select></label>
                    <label>De <input type="date" name="data_ini" value="${sanitizeHTML(filtros.data_ini || '')}"></label>
                    <label>Até <input type="date" name="data_fim" value="${sanitizeHTML(filtros.data_fim || '')}"></label>
                    <label class="sat-busca">Buscar no comentário <input type="search" name="busca" value="${sanitizeHTML(filtros.busca || '')}"></label>
                    <button type="submit" class="btn-primary">Filtrar</button>
                    <button type="button" class="btn-small" data-acao="limpar-avaliacoes">Limpar</button>
                    <a class="btn-small" href="${sanitizeHTML(satLinkCsv('pesquisa-painel.php', filtros))}" download>⬇️ Exportar CSV</a>
                </form>
                ${tabelaAvaliacoesSat(lista)}
                ${satPaginacao(lista.pagina, lista.total_paginas, 'avaliacoes')}
            </section>`;
        satUltimaEvolucao = { serie: resumo.evolucao, meta };
        desenharGraficoSat();
        aplicarLargurasSat();
    } catch (e) {
        satErro(e, 'Erro ao carregar as avaliações.');
    }
}

function blocoCategoriasSat(categorias) {
    if (!categorias.length) {
        return '';
    }
    const maximo = Math.max(...categorias.map(c => c.total), 1);
    const lista = (campo, titulo, classe) => {
        const linhas = categorias.filter(c => c[campo] > 0).sort((a, b) => b[campo] - a[campo]).slice(0, 5);
        return `<div class="sat-cat-coluna"><h4>${titulo}</h4>${linhas.length ? linhas.map(c => `
            <div class="sat-cat-linha"><span>${sanitizeHTML(c.emoji)} ${sanitizeHTML(c.nome)}</span>
                <span class="sat-barra"><i class="${classe}" data-largura="${Math.round(c[campo] / maximo * 100)}"></i></span><strong>${c[campo]}</strong></div>`).join('') : '<p class="sat-vazio">Nenhuma ainda.</p>'}</div>`;
    };
    return `<section class="sat-bloco" aria-labelledby="sat-cat-titulo"><h3 id="sat-cat-titulo">Categorias mais citadas</h3>
        <div class="sat-cat-grade">${lista('elogios', '👍 Elogios (notas 4 e 5)', 'sat-barra-boa')}${lista('criticas', '⚠️ Críticas (notas até 3)', 'sat-barra-ruim')}</div></section>`;
}

function tabelaAvaliacoesSat(lista) {
    if (!lista.itens.length) {
        return '<p class="sat-vazio">Nenhuma avaliação encontrada.</p>';
    }
    const podeTriar = true; // quem chega aqui já é do módulo; o servidor confere o setor de cada avaliação
    const linhas = lista.itens.map(a => `
        <tr data-avaliacao="${a.id}">
            <td data-label="Data">${satBr(a.criado_em)}</td>
            <td data-label="Nota">${satEstrelas(a.nota)}</td>
            <td data-label="Comentário" class="sat-comentario">
                ${a.comentario ? `<p>${sanitizeHTML(a.comentario)}</p>` : '<p class="sat-vazio">Sem comentário</p>'}
                ${a.categorias.length ? `<p class="sat-chips">${a.categorias.map(c => `<span class="sat-chip">${sanitizeHTML(c.emoji)} ${sanitizeHTML(c.nome)}</span>`).join('')}</p>` : ''}
                ${a.anotacao ? `<p class="sat-anotacao"><strong>Anotação:</strong> ${sanitizeHTML(a.anotacao)}</p>` : ''}
            </td>
            <td data-label="Servidor">${sanitizeHTML(a.servidor_nome || '—')}<br><small>${sanitizeHTML(a.setor_nome || 'Sem setor')}${a.protocolo ? ' · ' + sanitizeHTML(a.protocolo) : ''}</small></td>
            <td data-label="Situação">
                ${podeTriar ? `<select data-acao="situacao" data-id="${a.id}" aria-label="Situação da avaliação de ${satBr(a.criado_em)}">${satOpcoes(SAT_SITUACOES, a.status, '').replace('<option value=""></option>', '')}</select>` : sanitizeHTML(SAT_SITUACOES[a.status] || a.status)}
                <button type="button" class="btn-small" data-acao="anotar" data-id="${a.id}">📝 Anotar</button>
            </td>
        </tr>`).join('');
    return `<div class="sat-tabela-rolagem"><table class="sat-tabela">
        <caption class="sr-only">Avaliações recebidas</caption>
        <thead><tr><th scope="col">Data</th><th scope="col">Nota</th><th scope="col">Comentário</th><th scope="col">Servidor</th><th scope="col">Situação</th></tr></thead>
        <tbody>${linhas}</tbody></table></div>`;
}

async function salvarTriagemSat(id, corpo, mensagem) {
    try {
        await Api.put(`pesquisa-triagem.php?id=${id}`, corpo);
        mostrarToast('Sucesso', mensagem, 'success');
        carregarSatAvaliacoes();
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao salvar a triagem.'));
        carregarSatAvaliacoes();
    }
}

function anotarAvaliacaoSat(id) {
    const atual = satListaAtual.find(a => Number(a.id) === Number(id));
    mostrarPrompt('Anotação da avaliação', 'Registre o que foi feito com esta avaliação (até 2000 caracteres):', (confirmado) => {
        // O modal devolve só true/false; o texto fica no campo do próprio modal
        if (!confirmado) return;
        const texto = document.getElementById('modal-prompt-input').value;
        salvarTriagemSat(id, { anotacao: String(texto).trim() }, 'Anotação salva.');
    });
    const campo = document.getElementById('modal-prompt-input');
    if (campo && atual && atual.anotacao) campo.value = atual.anotacao;
}

// Gráfico de barras (quantidade de avaliações) + linha (nota média) + linha tracejada da meta, em canvas
function desenharGraficoSat() {
    const canvas = document.getElementById('sat-grafico');
    if (!canvas || !satUltimaEvolucao) return;
    const { serie, meta } = satUltimaEvolucao;
    const css = getComputedStyle(document.body);
    const cor = (nome, reserva) => css.getPropertyValue(nome).trim() || reserva;
    const texto = cor('--neutro-600', '#5f5852');
    const grade = cor('--neutro-200', '#ddd6cd');
    const vinho = cor('--vinho-600', '#a7241c');
    const verde = cor('--verde-500', '#70ab2a');
    const laranja = cor('--laranja-500', '#f89327');

    const largura = Math.max(280, canvas.parentElement.clientWidth - 8);
    const altura = 260;
    const dpr = window.devicePixelRatio || 1;
    canvas.width = largura * dpr;
    canvas.height = altura * dpr;
    canvas.style.width = largura + 'px';
    canvas.style.height = altura + 'px';
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, largura, altura);

    const m = { e: 34, d: 12, t: 12, b: 28 };
    const w = largura - m.e - m.d;
    const h = altura - m.t - m.b;
    const x = (i) => m.e + (serie.length > 1 ? (i / (serie.length - 1)) * w : w / 2);
    const yNota = (n) => m.t + h - ((n - 1) / 4) * h;
    ctx.font = '11px Inter, sans-serif';

    ctx.strokeStyle = grade;
    ctx.fillStyle = texto;
    ctx.lineWidth = 1;
    for (let n = 1; n <= 5; n++) {
        const y = yNota(n);
        ctx.beginPath(); ctx.moveTo(m.e, y); ctx.lineTo(largura - m.d, y); ctx.stroke();
        ctx.textAlign = 'right'; ctx.fillText(String(n), m.e - 6, y + 4);
    }

    const maxQtd = Math.max(...serie.map(s => s.qtd), 1);
    const larguraBarra = Math.max(2, Math.min(22, w / serie.length - 2));
    ctx.fillStyle = verde;
    ctx.globalAlpha = 0.35;
    serie.forEach((s, i) => {
        if (!s.qtd) return;
        const alturaBarra = (s.qtd / maxQtd) * (h * 0.4);
        ctx.fillRect(x(i) - larguraBarra / 2, m.t + h - alturaBarra, larguraBarra, alturaBarra);
    });
    ctx.globalAlpha = 1;

    if (meta >= 1 && meta <= 5) {
        ctx.strokeStyle = laranja; ctx.setLineDash([6, 4]); ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(m.e, yNota(meta)); ctx.lineTo(largura - m.d, yNota(meta)); ctx.stroke();
        ctx.setLineDash([]);
    }

    ctx.strokeStyle = vinho; ctx.fillStyle = vinho; ctx.lineWidth = 2;
    let anterior = null;
    serie.forEach((s, i) => {
        if (s.avg_nota === null) { anterior = null; return; }
        const px = x(i), py = yNota(s.avg_nota);
        if (anterior) { ctx.beginPath(); ctx.moveTo(anterior[0], anterior[1]); ctx.lineTo(px, py); ctx.stroke(); }
        ctx.beginPath(); ctx.arc(px, py, 3, 0, Math.PI * 2); ctx.fill();
        anterior = [px, py];
    });

    ctx.fillStyle = texto; ctx.textAlign = 'center';
    const passo = Math.max(1, Math.ceil(serie.length / 8));
    serie.forEach((s, i) => {
        if (i % passo === 0 || i === serie.length - 1) {
            ctx.fillText(s.data_dia.slice(8, 10) + '/' + s.data_dia.slice(5, 7), x(i), altura - 8);
        }
    });

    const comNota = serie.filter(s => s.avg_nota !== null);
    canvas.setAttribute('aria-label', `Evolução da nota média nos últimos ${serie.length} dias: ${comNota.length} dia(s) com avaliação`
        + (comNota.length ? `, última nota média ${satNumero(comNota[comNota.length - 1].avg_nota, 1)}` : '') + (meta ? `; meta ${satNumero(meta, 1)}` : '') + '.');
}

// ═══════════════ Painel de Setores ═══════════════
async function carregarSatSetores() {
    const filtros = satEstado.setores.filtros;
    try {
        const d = await Api.get(satUrl('pesquisa-setores.php', filtros));
        const p = d.periodos;
        const csv = window.APP_CONFIG.apiBase + satUrl('pesquisa-setores.php', Object.assign({ acao: 'csv' }, filtros));
        const total = d.setores.reduce((n, s) => n + s.servidores.length, 0);
        document.getElementById('satisfacao-conteudo').innerHTML = `
            <form class="sat-filtros" data-acao-form="setores">
                <label>Período personalizado — de <input type="date" name="data_ini" value="${sanitizeHTML(filtros.data_ini || p.custom_ini)}"></label>
                <label>até <input type="date" name="data_fim" value="${sanitizeHTML(filtros.data_fim || p.custom_fim)}"></label>
                <button type="submit" class="btn-primary">Aplicar</button>
                <div class="sat-periodos" role="group" aria-label="Forma de exibição">
                    <button type="button" class="btn-small ${satVisaoSetores === 'cartoes' ? 'sat-ativo' : ''}" data-acao="visao" data-visao="cartoes" aria-pressed="${satVisaoSetores === 'cartoes'}">Cartões</button>
                    <button type="button" class="btn-small ${satVisaoSetores === 'tabela' ? 'sat-ativo' : ''}" data-acao="visao" data-visao="tabela" aria-pressed="${satVisaoSetores === 'tabela'}">Tabela</button>
                </div>
                <a class="btn-small" href="${sanitizeHTML(csv)}" download>⬇️ Exportar CSV</a>
            </form>
            ${d.setores.length ? (satVisaoSetores === 'tabela' ? tabelaSetoresSat(d) : d.setores.map(s => cartaoSetorSat(s, p)).join('')) : '<p class="sat-vazio">Nenhum setor no painel.</p>'}
            <p class="sat-rodape">${total} servidor(es) em ${d.setores.length} setor(es). Semana: segunda a sexta. Taxa de resposta = avaliações recebidas ÷ convites enviados.</p>`;
        requestAnimationFrame(aplicarLargurasSat);
    } catch (e) {
        satErro(e, 'Erro ao carregar o painel de setores.');
    }
}

function taxaSat(sv) {
    return sv.total_convites > 0 ? Math.round(sv.total_avaliados / sv.total_convites * 100) : null;
}

function cartaoSetorSat(setor, p) {
    const melhor = Math.max(...setor.servidores.map(s => s.media_nota ?? 0), 0);
    const linhas = setor.servidores.map(sv => {
        const taxa = taxaSat(sv);
        const maxMes = Math.max(...(sv.historico_mensal || []).map(m => m.total), 1);
        return `<tr>
            <th scope="row">${sanitizeHTML(sv.nome)}${melhor > 0 && sv.media_nota === melhor && sv.total_avaliados >= 3 ? ' <span class="sat-destaque" title="Maior nota média do setor">★ Destaque</span>' : ''}</th>
            <td data-label="Semana atual">${sv.sem_atual}</td><td data-label="Semana anterior">${sv.sem_ant}</td>
            <td data-label="Mês atual">${sv.mes_atual}</td><td data-label="Mês anterior">${sv.mes_ant}</td>
            <td data-label="No período">${sv.custom}</td>
            <td data-label="Taxa de resposta">${taxa === null ? '—' : taxa + '%'}</td>
            <td data-label="Nota média">${sv.media_nota === null ? '—' : satEstrelas(Math.round(sv.media_nota)) + ' ' + satNumero(sv.media_nota, 1)}</td>
            <td data-label="Últimos 6 meses"><span class="sat-mini" role="img" aria-label="Convites por mês: ${(sv.historico_mensal || []).map(m => m.label + ' ' + m.total).join(', ')}">${(sv.historico_mensal || []).map(m => `<i data-altura="${Math.round(m.total / maxMes * 100)}" title="${m.label}: ${m.total}"></i>`).join('')}</span></td>
        </tr>`;
    }).join('');
    return `<section class="sat-bloco" aria-label="Setor ${sanitizeHTML(setor.nome)}">
        <div class="sat-bloco-topo"><h3>${sanitizeHTML(setor.nome)}</h3>${setor.coordenador_nome ? `<small>Coordenação: ${sanitizeHTML(setor.coordenador_nome)}</small>` : ''}</div>
        ${setor.servidores.length ? `<div class="sat-tabela-rolagem"><table class="sat-tabela">
            <thead><tr><th scope="col">Servidor</th><th scope="col">Semana atual</th><th scope="col">Semana anterior</th><th scope="col">Mês atual</th><th scope="col">Mês anterior</th><th scope="col">No período</th><th scope="col">Taxa de resposta</th><th scope="col">Nota média</th><th scope="col">Últimos 6 meses</th></tr></thead>
            <tbody>${linhas}</tbody></table></div>` : '<p class="sat-vazio">Nenhum servidor neste setor.</p>'}
    </section>`;
}

function tabelaSetoresSat(d) {
    const linhas = d.setores.flatMap(s => s.servidores.map(sv => {
        const taxa = taxaSat(sv);
        return `<tr><td data-label="Setor">${sanitizeHTML(s.nome)}</td><th scope="row">${sanitizeHTML(sv.nome)}</th><td data-label="Convites no mês">${sv.mes_atual}</td>
            <td data-label="Total de convites">${sv.total_convites}</td><td data-label="Avaliados">${sv.total_avaliados}</td>
            <td data-label="Taxa">${taxa === null ? '—' : taxa + '%'}</td><td data-label="Nota média">${satNumero(sv.media_nota, 1)}</td></tr>`;
    })).join('');
    return `<div class="sat-tabela-rolagem"><table class="sat-tabela"><caption class="sr-only">Servidores por setor</caption>
        <thead><tr><th scope="col">Setor</th><th scope="col">Servidor</th><th scope="col">Convites no mês</th><th scope="col">Total de convites</th><th scope="col">Avaliados</th><th scope="col">Taxa</th><th scope="col">Nota média</th></tr></thead>
        <tbody>${linhas}</tbody></table></div>`;
}

// Larguras/alturas vêm de data-*: o estilo fica no CSS (sem style inline)
function aplicarLargurasSat() {
    document.querySelectorAll('#satisfacao-conteudo [data-largura]').forEach(el => { el.style.width = el.dataset.largura + '%'; });
    document.querySelectorAll('#satisfacao-conteudo [data-altura]').forEach(el => { el.style.height = Math.max(8, Number(el.dataset.altura)) + '%'; });
}

// ═══════════════ Convites ═══════════════
async function carregarSatConvites() {
    const est = satEstado.convites;
    try {
        const d = await Api.get(satUrl('pesquisa-convites.php', Object.assign({ pagina: est.pagina }, est.filtros)));
        const ind = d.indicadores;
        const f = est.filtros;
        const kpi = (valor, rotulo) => `<div class="sat-kpi"><strong>${valor}</strong><span>${rotulo}</span></div>`;
        const csv = window.APP_CONFIG.apiBase + satUrl('pesquisa-convites.php', Object.assign({ acao: 'csv' }, f));
        const linhas = d.itens.map(c => `<tr>
            <td data-label="Enviado em">${satBr(c.enviado_em)}</td>
            <td data-label="Servidor">${sanitizeHTML(c.servidor_nome || '—')}<br><small>${sanitizeHTML(c.setor_nome || 'Sem setor')}</small></td>
            <td data-label="Destinatário">${sanitizeHTML(c.destinatario_nome)}<br><small>${sanitizeHTML(c.destinatario_email)}</small></td>
            <td data-label="Situação"><span class="sat-selo sat-selo-${c.situacao}">${SAT_SITUACOES_CONVITE[c.situacao]}</span></td>
            <td data-label="Nota">${c.nota ? satEstrelas(c.nota) : '—'}</td>
            <td data-label="Origem">${c.origem === 'demanda' ? `Demanda${c.protocolo ? ' · ' + sanitizeHTML(c.protocolo) : ''}` : 'Manual'}</td></tr>`).join('');
        document.getElementById('satisfacao-conteudo').innerHTML = `
            <div class="sat-kpis">
                ${kpi(ind.total, 'Convites')}${kpi(ind.avaliados, 'Avaliados')}${kpi(ind.aguardando, 'Aguardando')}${kpi(ind.expirados, 'Expirados')}
                ${kpi(ind.taxa + '%', 'Taxa de resposta')}${kpi(satNumero(ind.media_nota, 1), 'Nota média')}
            </div>
            <form class="sat-filtros" data-acao-form="convites">
                <label>Situação <select name="situacao">${satOpcoes(SAT_SITUACOES_CONVITE, f.situacao || '', 'Todas')}</select></label>
                <label>Origem <select name="origem">${satOpcoes({ manual: 'Manual', demanda: 'Ao encerrar demanda' }, f.origem || '', 'Todas')}</select></label>
                <label>Servidor <select name="usuario_id"><option value="">Todos</option>${d.servidores.map(s => `<option value="${s.id}" ${String(s.id) === String(f.usuario_id || '') ? 'selected' : ''}>${sanitizeHTML(s.nome)}</option>`).join('')}</select></label>
                <label>De <input type="date" name="data_ini" value="${sanitizeHTML(f.data_ini || '')}"></label>
                <label>Até <input type="date" name="data_fim" value="${sanitizeHTML(f.data_fim || '')}"></label>
                <label class="sat-busca">Nome ou e-mail <input type="search" name="busca" value="${sanitizeHTML(f.busca || '')}"></label>
                <button type="submit" class="btn-primary">Filtrar</button>
                <button type="button" class="btn-small" data-acao="limpar-convites">Limpar</button>
                <a class="btn-small" href="${sanitizeHTML(csv)}" download>⬇️ Exportar CSV</a>
            </form>
            ${d.itens.length ? `<div class="sat-tabela-rolagem"><table class="sat-tabela"><caption class="sr-only">Convites enviados</caption>
                <thead><tr><th scope="col">Enviado em</th><th scope="col">Servidor</th><th scope="col">Destinatário</th><th scope="col">Situação</th><th scope="col">Nota</th><th scope="col">Origem</th></tr></thead>
                <tbody>${linhas}</tbody></table></div>` : '<p class="sat-vazio">Nenhum convite encontrado.</p>'}
            ${satPaginacao(d.pagina, d.total_paginas, 'convites')}`;
    } catch (e) {
        satErro(e, 'Erro ao carregar os convites.');
    }
}

// ═══════════════ Ouvintes ═══════════════
function filtrosDoFormSat(form) {
    const dados = Object.fromEntries(new FormData(form).entries());
    Object.keys(dados).forEach(k => { if (dados[k] === '') delete dados[k]; });
    return dados;
}

function ligarAcoesSatisfacao(conteudo) {
    if (conteudo.dataset.satLigado) return;
    conteudo.dataset.satLigado = '1';

    conteudo.addEventListener('click', (ev) => {
        const alvo = ev.target.closest('[data-acao]');
        if (!alvo || alvo.tagName === 'SELECT' || alvo.tagName === 'FORM') return;
        const acao = alvo.dataset.acao;
        if (acao === 'dias') {
            satEstado.avaliacoes.dias = Number(alvo.dataset.dias) || 30;
            carregarSatAvaliacoes();
        } else if (acao === 'anotar') {
            anotarAvaliacaoSat(Number(alvo.dataset.id));
        } else if (acao === 'pagina') {
            const destino = alvo.dataset.destino;
            satEstado[destino].pagina = Math.max(1, Number(alvo.dataset.pagina) || 1);
            (destino === 'convites' ? carregarSatConvites : carregarSatAvaliacoes)();
        } else if (acao === 'limpar-avaliacoes') {
            satEstado.avaliacoes.filtros = {};
            satEstado.avaliacoes.pagina = 1;
            carregarSatAvaliacoes();
        } else if (acao === 'limpar-convites') {
            satEstado.convites.filtros = {};
            satEstado.convites.pagina = 1;
            carregarSatConvites();
        } else if (acao === 'visao') {
            satVisaoSetores = alvo.dataset.visao === 'tabela' ? 'tabela' : 'cartoes';
            carregarSatSetores();
        }
    });

    conteudo.addEventListener('change', (ev) => {
        const select = ev.target.closest('select[data-acao="situacao"]');
        if (select) salvarTriagemSat(Number(select.dataset.id), { status: select.value }, 'Situação atualizada.');
    });

    conteudo.addEventListener('submit', (ev) => {
        const form = ev.target.closest('[data-acao-form]');
        if (!form) return;
        ev.preventDefault();
        const tipo = form.dataset.acaoForm;
        if (tipo === 'avaliacoes') {
            satEstado.avaliacoes.filtros = filtrosDoFormSat(form);
            satEstado.avaliacoes.pagina = 1;
            carregarSatAvaliacoes();
        } else if (tipo === 'convites') {
            satEstado.convites.filtros = filtrosDoFormSat(form);
            satEstado.convites.pagina = 1;
            carregarSatConvites();
        } else if (tipo === 'setores') {
            satEstado.setores.filtros = filtrosDoFormSat(form);
            carregarSatSetores();
        }
    });
}

// Itens do menu (sem onclick no HTML), troca de setor no cabeçalho e redimensionamento do gráfico
document.addEventListener('click', (ev) => {
    const item = ev.target.closest('#nav-satisfacao-dropdown .nav-dropdown-item[data-subsecao]');
    if (item) mostrarSatisfacao(item.dataset.subsecao);
});
document.addEventListener('setor-contexto-alterado', () => {
    if (document.getElementById('satisfacao')?.classList.contains('active')) {
        satEstado.avaliacoes.pagina = 1;
        satEstado.convites.pagina = 1;
        SAT_TELAS[satTela].carregar();
    }
});
window.addEventListener('resize', () => {
    if (satUltimaEvolucao && document.getElementById('sat-grafico')) {
        clearTimeout(window._satResize);
        window._satResize = setTimeout(desenharGraficoSat, 200);
    }
});
