/**
 * Módulo Satisfação — Painel de Setores (16/11/2026: igual à tela "admin-painel-setores" do METRICA360,
 * src/views/admin/painel_setores.php e public/js/pages/admin-painel-setores*.js de lá).
 *   Resumo global, barra de ferramentas (CSV, PDF, ordenação, cartões/tabela), por setor a faixa com o
 *   coordenador, os destaques e os totais, e um cartão por atendente com "Detalhes".
 * Dados: api/controllers/pesquisa-setores.php (PesquisaPainelService::painelSetores). O filtro de período é do
 * Conecta (o METRICA360 só o aceitava pelo endereço); com ele aplicado, o cartão mostra o número do período.
 * Sem style/onclick inline: ações por data-sps com ouvintes delegados; larguras e alturas vêm de data-* e são
 * aplicadas aqui (geometria calculada). CSS em satisfacao-setores.css. Usa os utilitários de satisfacao.js.
 */
const SPS_ORDENS = [
    ['nome', 'Nome A–Z'],
    ['nota-desc', 'Melhor nota'],
    ['nota-asc', 'Pior nota'],
    ['mes-desc', 'Mais convites (mês)'],
    ['aval-desc', 'Mais avaliações'],
];
const SPS_CHAVE_VISAO = 'cd_painelSetoresVisao';
const SPS_RECARGA_MS = 5 * 60 * 1000;
const SPS_DONUT_CIRC = 2 * Math.PI * 19;
const SPS_DICA_MEDIA_GERAL = 'Soma de todas as notas individuais de todos os atendentes, dividida pelo total de avaliações respondidas. Mesma lógica utilizada pelo Google Reviews.';
const SPS_DICA_MEDIA_SETOR = 'Soma de todas as notas dos atendentes deste setor, dividida pelo total de avaliações respondidas. Mesma lógica do Google Reviews — atendentes com mais avaliações têm mais peso no resultado.';

let spsOrdem = 'nome';
let spsTimerRecarga = null;

function spsLerVisao() {
    try { return localStorage.getItem(SPS_CHAVE_VISAO) === 'tabela' ? 'tabela' : 'cartoes'; } catch (e) { return 'cartoes'; }
}

function spsGravarVisao(visao) {
    try { localStorage.setItem(SPS_CHAVE_VISAO, visao); } catch (e) { /* sem armazenamento: vale só nesta visita */ }
}

// ── Formatação ──────────────────────────────────────────────────────────────
function spsData(iso) {
    if (!iso) return '';
    const [a, m, d] = String(iso).slice(0, 10).split('-');
    return `${d}/${m}/${a}`;
}

function spsNota(valor) {
    return satNumero(valor, 1);
}

function spsIniciais(nome) {
    const partes = String(nome || '').trim().split(/\s+/);
    return ((partes[0] || '').charAt(0) + (partes[1] || '').charAt(0)).toUpperCase();
}

function spsFaixa(nota) {
    if (nota === null || nota === undefined) return 'vazia';
    return nota >= 4 ? 'alta' : nota >= 3 ? 'media' : 'baixa';
}

function spsTaxa(sv) {
    return sv.total_convites > 0 ? Math.round(sv.total_avaliados / sv.total_convites * 100) : null;
}

function spsClasseTaxa(taxa) {
    return taxa === null ? '' : taxa >= 60 ? 'alta' : taxa >= 40 ? 'media' : 'baixa';
}

// Foto do usuário (só o nome do arquivo); sem foto, as iniciais. Foto que não carrega volta às iniciais.
function spsFoto(nome, avatar, classeImg) {
    if (avatar) {
        const arquivo = encodeURIComponent(String(avatar).split(/[\\/]/).pop());
        return `<img class="${classeImg}" src="${sanitizeHTML(window.APP_CONFIG.rootBase + 'backend/uploads/avatars/' + arquivo)}" alt="" data-iniciais="${sanitizeHTML(spsIniciais(nome))}">`;
    }
    return sanitizeHTML(spsIniciais(nome));
}

// Média ponderada pelas avaliações (lógica do Google Reviews: soma das notas ÷ total de avaliações)
function spsMediaPonderada(servidores) {
    let soma = 0;
    let total = 0;
    servidores.forEach(sv => {
        if (sv.media_nota !== null && sv.total_avaliados > 0) {
            soma += sv.media_nota * sv.total_avaliados;
            total += sv.total_avaliados;
        }
    });
    return total > 0 ? Math.round(soma / total * 10) / 10 : null;
}

function spsDelta(atual, anterior) {
    const d = atual - anterior;
    return { dir: d > 0 ? 'up' : d < 0 ? 'down' : 'flat', texto: d > 0 ? '+' + d : d < 0 ? String(d) : '=' };
}

function spsTendencia(atual, anterior) {
    const d = atual - anterior;
    return { dir: d > 0 ? 'up' : d < 0 ? 'down' : 'flat', texto: d > 0 ? '▲ ' + d : d < 0 ? '▼ ' + Math.abs(d) : '= 0' };
}

function spsDica(texto, rotulo, claro) {
    return `<span class="sps-info${claro ? ' sps-info-claro' : ''}" tabindex="0" role="note" aria-label="${sanitizeHTML(rotulo + ': ' + texto)}" data-vg-title="${sanitizeHTML(rotulo)}" data-vg-tooltip="${sanitizeHTML(texto)}">?</span>`;
}

// ── Carga ───────────────────────────────────────────────────────────────────
async function carregarSatSetores(opcoes) {
    const filtros = satEstado.setores.filtros;
    const conteudo = document.getElementById('satisfacao-conteudo');
    // Recarga automática: mantém abertos os "Detalhes" que estavam abertos
    const abertos = opcoes && opcoes.silencioso
        ? Array.from(conteudo.querySelectorAll('.sps-detalhes-botao[aria-expanded="true"]')).map(b => b.getAttribute('aria-controls'))
        : [];
    try {
        const d = await Api.get(satUrl('pesquisa-setores.php', filtros));
        if (satTela !== 'painel-setores') return; // trocou de tela enquanto carregava
        conteudo.innerHTML = montarPainelSetores(d, filtros);
        abertos.forEach(id => {
            const botao = conteudo.querySelector(`.sps-detalhes-botao[aria-controls="${CSS.escape(id)}"]`);
            if (botao) spsAlternarDetalhes(botao, true);
        });
        spsOrdenarDom(conteudo);
        requestAnimationFrame(() => spsAplicarGeometria(conteudo));
        spsAgendarRecarga();
    } catch (e) {
        if (!(opcoes && opcoes.silencioso)) satErro(e, 'Erro ao carregar o painel de setores.');
        else spsAgendarRecarga();
    }
}

// Recarga a cada 5 min (como no METRICA360), só com a aba visível, nesta tela, sem modal e sem alguém digitando
function spsAgendarRecarga() {
    clearTimeout(spsTimerRecarga);
    spsTimerRecarga = setTimeout(() => {
        const secao = document.getElementById('satisfacao');
        if (satTela !== 'painel-setores' || !secao || !secao.classList.contains('active')) return;
        const ativo = document.activeElement;
        const digitando = ativo && secao.contains(ativo) && ativo.matches('input, textarea, select, [contenteditable="true"]');
        const modal = document.getElementById('modal-overlay')?.classList.contains('show');
        if (document.hidden || digitando || modal) {
            spsAgendarRecarga();
        } else {
            carregarSatSetores({ silencioso: true });
        }
    }, SPS_RECARGA_MS);
}

// ── Montagem ────────────────────────────────────────────────────────────────
function montarPainelSetores(d, filtros) {
    const p = d.periodos;
    const setores = d.setores || [];
    const temFiltro = !!(filtros.data_ini && filtros.data_fim);
    const agora = new Date();
    const hora = String(agora.getHours()).padStart(2, '0') + ':' + String(agora.getMinutes()).padStart(2, '0');
    const csv = window.APP_CONFIG.apiBase + satUrl('pesquisa-setores.php', Object.assign({ acao: 'csv' }, filtros));
    const visao = spsLerVisao();

    return `<div class="sps-painel">
        <p class="sps-subtitulo">Desempenho e atendimentos por setor.</p>
        <div class="sps-atualizado" role="status"><span class="sps-ponto" aria-hidden="true"></span>Atualizado às ${hora}</div>
        ${setores.length ? spsResumoGlobal(setores) : ''}
        <div class="sps-ferramentas">
            <form class="sps-periodo" data-acao-form="setores" aria-label="Período personalizado">
                <label>Período personalizado — de <input type="date" name="data_ini" value="${sanitizeHTML(filtros.data_ini || p.custom_ini)}"></label>
                <label>até <input type="date" name="data_fim" value="${sanitizeHTML(filtros.data_fim || p.custom_fim)}"></label>
                <button type="submit" class="sps-botao sps-botao-claro">Aplicar</button>
            </form>
            <div class="sps-acoes">
                <a class="sps-botao sps-botao-csv" href="${sanitizeHTML(csv)}" download aria-label="Exportar CSV" title="Exportar CSV">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span> CSV</a>
                <button type="button" class="sps-botao sps-botao-pdf" data-sps="imprimir" aria-label="Salvar PDF" title="Salvar PDF">
                    <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span> PDF</button>
                <span class="sps-separador" aria-hidden="true"></span>
                <select class="sps-ordem" data-sps="ordem" aria-label="Ordenar atendentes por">
                    ${SPS_ORDENS.map(([v, r]) => `<option value="${v}" ${v === spsOrdem ? 'selected' : ''}>${r}</option>`).join('')}
                </select>
                <button type="button" class="sps-botao sps-botao-icone ${visao === 'cartoes' ? 'sps-ativo' : ''}" data-sps="visao" data-visao="cartoes" aria-pressed="${visao === 'cartoes'}" aria-label="Visualizar cards" title="Cards">
                    <span class="material-symbols-outlined" aria-hidden="true">grid_view</span></button>
                <button type="button" class="sps-botao sps-botao-icone ${visao === 'tabela' ? 'sps-ativo' : ''}" data-sps="visao" data-visao="tabela" aria-pressed="${visao === 'tabela'}" aria-label="Visualizar tabela" title="Tabela">
                    <span class="material-symbols-outlined" aria-hidden="true">table_rows</span></button>
            </div>
        </div>
        ${setores.length ? setores.map(s => spsSetor(s, p, temFiltro, filtros, visao)).join('') : spsSemSetores()}
    </div>`;
}

// Lista vazia. Com um setor escolhido no cabeçalho, o motivo quase sempre é esse setor estar fora do Painel de
// Setores (setores.pesquisa_painel = 0): a tela diz isso em vez de mandar cadastrar setores.
function spsSemSetores() {
    const setorId = window.CabecalhoSetor ? window.CabecalhoSetor.obterSetorContexto() : null;
    if (!setorId) {
        return spsVazio('corporate_fare', 'Nenhum setor encontrado', 'Cadastre setores e vincule atendentes nas Configurações para visualizar o painel.');
    }
    const seletor = document.getElementById('seletor-setor');
    const opcao = seletor ? Array.from(seletor.options).find(o => Number(o.value) === Number(setorId)) : null;
    let nome = opcao ? opcao.textContent.trim() : '';
    if (!nome) {
        try { nome = (JSON.parse(localStorage.getItem('cd_usuario') || '{}').setor_nome) || ''; } catch (e) { nome = ''; }
    }
    const temSeletor = seletor && !document.getElementById('cabecalho-setor-seletor')?.hidden;
    return spsVazio('corporate_fare', 'Setor fora do Painel de Setores',
        `O setor ${nome ? '<strong>' + sanitizeHTML(nome) + '</strong>' : 'escolhido'}${temSeletor ? ', escolhido no cabeçalho,' : ''} não faz parte do Painel de Setores.`
        + (temSeletor ? ' Escolha <strong>Todos os setores</strong> no cabeçalho para ver os setores do painel.' : ''));
}

function spsVazio(icone, titulo, texto) {
    return `<div class="sps-vazio">
        <div class="sps-vazio-icone"><span class="material-symbols-outlined" aria-hidden="true">${icone}</span></div>
        <div class="sps-vazio-titulo">${titulo}</div>
        <div class="sps-vazio-texto">${texto}</div>
    </div>`;
}

function spsResumoGlobal(setores) {
    const todos = setores.flatMap(s => s.servidores);
    const convitesMes = todos.reduce((n, sv) => n + sv.mes_atual, 0);
    const avaliacoes = todos.reduce((n, sv) => n + sv.total_avaliados, 0);
    const convites = todos.reduce((n, sv) => n + sv.total_convites, 0);
    const media = spsMediaPonderada(todos);
    const taxa = convites > 0 ? Math.round(avaliacoes / convites * 100) : null;
    const classeTaxa = taxa === null ? '' : taxa >= 60 ? 'sps-kpi-sucesso' : taxa >= 40 ? 'sps-kpi-atencao' : 'sps-kpi-perigo';
    const kpi = (rotulo, valor, classe) => `<div class="sps-kpi ${classe || ''}"><span class="sps-kpi-rotulo">${rotulo}</span><span class="sps-kpi-valor">${valor}</span></div>`;
    return `<div class="sps-kpis">
        ${kpi('Setores', setores.length)}
        ${kpi('Atendentes', todos.length)}
        ${kpi('Convites (mês)', convitesMes)}
        ${kpi('Avaliações', avaliacoes)}
        ${kpi('Média Geral do CRPRS ' + spsDica(SPS_DICA_MEDIA_GERAL, 'Como é calculada a média geral'), media !== null ? spsNota(media) + '★' : '—', 'sps-kpi-destaque')}
        ${kpi('Taxa de Resposta', taxa !== null ? taxa + '%' : '—', classeTaxa)}
    </div>`;
}

function spsSetor(setor, p, temFiltro, filtros, visao) {
    const sv = setor.servidores;
    const soma = campo => sv.reduce((n, x) => n + x[campo], 0);
    const semAtual = soma('sem_atual');
    const mesAtual = soma('mes_atual');
    const dSem = spsDelta(semAtual, soma('sem_ant'));
    const dMes = spsDelta(mesAtual, soma('mes_ant'));
    const media = spsMediaPonderada(sv);
    const pct = media !== null ? Math.round(media / 5 * 100) : 0;

    // Melhor e pior nota do setor (a pior não aparece se for a mesma pessoa)
    let melhor = null;
    let pior = null;
    sv.forEach(x => {
        if (x.media_nota === null) return;
        if (!melhor || x.media_nota > melhor.media_nota) melhor = x;
        if (!pior || x.media_nota < pior.media_nota) pior = x;
    });
    if (pior && melhor && pior.id === melhor.id) pior = null;
    const primeiroNome = nome => String(nome || '').trim().split(/\s+/)[0];
    const sep = '<div class="sps-setor-sep" aria-hidden="true"></div>';
    const idTitulo = `sps-setor-${setor.id}`;

    return `<section class="sps-setor ${visao === 'tabela' ? 'sps-visao-tabela' : 'sps-visao-cartoes'}" aria-labelledby="${idTitulo}">
        <div class="sps-setor-cabecalho">
            <h3 class="sps-setor-titulo" id="${idTitulo}">
                <span class="material-symbols-outlined" aria-hidden="true">corporate_fare</span>
                <span class="sps-setor-nome">${sanitizeHTML(setor.nome)}</span>
            </h3>
            ${setor.coordenador_nome ? `${sep}<div class="sps-coord">
                <div class="sps-coord-foto">${spsFoto(setor.coordenador_nome, setor.coordenador_avatar, 'sps-foto-img')}</div>
                <div><div class="sps-coord-rotulo">Coordenador(a)</div><div class="sps-coord-nome">${sanitizeHTML(setor.coordenador_nome)}</div></div>
            </div>` : ''}
            ${sep}<span class="sps-contagem">${sv.length} atendente${sv.length !== 1 ? 's' : ''}</span>
            ${melhor || pior ? `${sep}<div class="sps-destaques">
                ${melhor ? `<div class="sps-dest sps-dest-melhor" title="Melhor nota: ${spsNota(melhor.media_nota)}">
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_upward</span>
                    <span><span class="sr-only">Melhor nota: </span>${sanitizeHTML(primeiroNome(melhor.nome))} (${spsNota(melhor.media_nota)})</span></div>` : ''}
                ${pior ? `<div class="sps-dest sps-dest-pior" title="Menor nota: ${spsNota(pior.media_nota)}">
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_downward</span>
                    <span><span class="sr-only">Menor nota: </span>${sanitizeHTML(primeiroNome(pior.nome))} (${spsNota(pior.media_nota)})</span></div>` : ''}
            </div>` : ''}
            ${sep}<div class="sps-setor-kpis" role="group" aria-label="Totais do setor">
                <div class="sps-setor-kpi"><strong>${semAtual}</strong><span>Sem. atual</span><span class="sps-delta sps-${dSem.dir}">${dSem.texto}</span></div>
                <div class="sps-setor-kpi"><strong>${mesAtual}</strong><span>Mês atual</span><span class="sps-delta sps-${dMes.dir}">${dMes.texto}</span></div>
                <div class="sps-setor-kpi"><strong>${soma('total_avaliados')}</strong><span>Avaliações</span></div>
                ${media !== null ? `<div class="sps-setor-kpi sps-setor-kpi-media"><strong>${spsNota(media)}★</strong>
                    <span>Média setor ${spsDica(SPS_DICA_MEDIA_SETOR, 'Como é calculada a média do setor', true)}</span></div>` : ''}
            </div>
        </div>
        <div class="sps-media" role="meter" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100"
             aria-label="Média do setor: ${media !== null ? spsNota(media) + ' de 5' : 'sem dados'}">
            ${media !== null ? `<span class="sps-media-rotulo" data-esquerda="${Math.max(0, Math.min(pct - 4, 92))}">${spsNota(media)}/5</span>` : ''}
            <div class="sps-media-barra sps-faixa-${spsFaixa(media)}" data-largura="${media !== null ? pct : 100}"></div>
        </div>
        <div class="sps-setor-corpo">
            ${sv.length ? `<div class="sps-grade">${sv.map(x => spsCartao(x, setor, p, temFiltro, filtros, melhor)).join('')}</div>
                ${spsTabela(setor, melhor)}`
                : spsVazio('person_off', 'Nenhum atendente vinculado', 'Acesse Configurações para associar atendentes a este setor.')}
        </div>
    </section>`;
}

function spsCartao(at, setor, p, temFiltro, filtros, melhor) {
    const nota = at.media_nota;
    const faixa = spsFaixa(nota);
    const taxa = spsTaxa(at);
    const ehDestaque = melhor && melhor.id === at.id;
    const idDetalhes = `sps-det-${setor.id}-${at.id}`;

    // Minibarras: 3 a 20 px, proporcionais ao maior dos dois números (semana e mês)
    const altura = (valor, maximo) => Math.max(3, Math.round(valor / maximo * 20));
    const maxSem = Math.max(at.sem_ant, at.sem_atual, 1);
    const maxMes = Math.max(at.mes_ant, at.mes_atual, 1);
    const tSem = spsTendencia(at.sem_atual, at.sem_ant);
    const tMes = spsTendencia(at.mes_atual, at.mes_ant);
    const estat = (valor, rotulo, ini, fim, barras, tendencia) => `<div class="sps-estat">
        <div class="sps-estat-valor">${valor}</div>
        <div class="sps-dica-periodo"><div class="sps-estat-rotulo">${rotulo}<span class="sr-only"> (${spsData(ini)} – ${spsData(fim)})</span></div>
            <div class="sps-dica-texto" aria-hidden="true">${spsData(ini)} – ${spsData(fim)}</div></div>
        <div class="sps-minibarras" aria-hidden="true">${barras}</div>
        ${tendencia ? `<span class="sps-tendencia sps-${tendencia.dir}">${tendencia.texto}</span>` : ''}
    </div>`;
    const barra = (classe, px) => `<div class="sps-minibarra ${classe}" data-altura-px="${px}"></div>`;

    const offset = taxa !== null ? SPS_DONUT_CIRC * (1 - taxa / 100) : SPS_DONUT_CIRC;

    return `<article class="sps-cartao" data-nome="${sanitizeHTML(at.nome)}" data-nota="${nota ?? -1}" data-mes="${at.mes_atual}" data-aval="${at.total_avaliados}">
        <div class="sps-cartao-faixa sps-faixa-${faixa}" aria-hidden="true"></div>
        <div class="sps-cartao-interno">
            <div class="sps-cartao-topo">
                <div class="sps-anel sps-faixa-${faixa}"><div class="sps-foto">${spsFoto(at.nome, at.avatar, 'sps-foto-img')}</div></div>
                <div class="sps-cartao-info">
                    <div class="sps-cartao-nome">${sanitizeHTML(at.nome)}</div>
                    ${ehDestaque ? '<span class="sps-selo-destaque" title="Melhor nota média do setor">★ Destaque</span>' : ''}
                    <div class="sps-cartao-papel">${sanitizeHTML(Papeis.rotulo(at.tipo))}</div>
                </div>
                <div class="sps-nota sps-nota-${faixa}" role="img" aria-label="${nota !== null ? 'Média ' + spsNota(nota) + ' de 5' : 'Sem avaliações'}">${nota !== null ? spsNota(nota) + '★' : '—'}</div>
            </div>
            <div class="sps-estats">
                ${estat(at.sem_ant, 'Sem. passada', p.sem_ant_ini, p.sem_ant_fim, barra('sps-ant', altura(at.sem_ant, maxSem)))}
                ${estat(at.sem_atual, 'Sem. atual', p.sem_atual_ini, p.sem_atual_fim, barra('sps-ant', altura(at.sem_ant, maxSem)) + barra('sps-atual sps-' + tSem.dir, altura(at.sem_atual, maxSem)), tSem)}
                ${estat(at.mes_ant, 'Mês passado', p.mes_ant_ini, p.mes_ant_fim, barra('sps-ant', altura(at.mes_ant, maxMes)))}
                ${estat(at.mes_atual, 'Mês atual', p.mes_atual_ini, p.mes_atual_fim, barra('sps-ant', altura(at.mes_ant, maxMes)) + barra('sps-atual sps-' + tMes.dir, altura(at.mes_atual, maxMes)), tMes)}
            </div>
            ${temFiltro ? `<div class="sps-periodo-custom">
                <span class="sps-periodo-custom-rotulo"><span class="material-symbols-outlined" aria-hidden="true">date_range</span>${spsData(filtros.data_ini)} – ${spsData(filtros.data_fim)}</span>
                <span class="sps-periodo-custom-valor">${at.custom} atend.</span>
            </div>` : ''}
            <div class="sps-taxa">
                <div class="sps-donut" role="img" aria-label="${taxa !== null ? 'Taxa de resposta: ' + taxa + '%' : 'Sem convites'}">
                    <svg viewBox="0 0 42 42" aria-hidden="true">
                        <circle class="sps-donut-trilha" cx="21" cy="21" r="19"></circle>
                        <circle class="sps-donut-arco ${taxa !== null ? 'sps-taxa-' + spsClasseTaxa(taxa) : ''}" cx="21" cy="21" r="19"
                            stroke-dasharray="${SPS_DONUT_CIRC.toFixed(2)}" stroke-dashoffset="${SPS_DONUT_CIRC.toFixed(2)}" data-offset="${offset.toFixed(2)}"></circle>
                    </svg>
                    <div class="sps-donut-pct" aria-hidden="true">${taxa !== null ? taxa + '%' : '—'}</div>
                </div>
                <div class="sps-taxa-info">
                    <div class="sps-taxa-rotulo">Taxa de resposta</div>
                    <div class="sps-taxa-detalhe">${at.total_avaliados} de ${at.total_convites} convites</div>
                </div>
            </div>
            <div class="sps-avaliacoes">
                <div class="sps-avaliacoes-linha">
                    <div class="sps-avaliacoes-info"><strong>${at.total_avaliados}</strong> avalia${at.total_avaliados !== 1 ? 'ções' : 'ção'}</div>
                    ${nota !== null ? `<div class="sps-nota-valor" aria-hidden="true">${spsNota(nota)}/5</div>` : '<span class="sps-sem-dados">Sem avaliações</span>'}
                </div>
                ${nota !== null ? `<div class="sps-progresso" role="img" aria-label="Média: ${spsNota(nota)} de 5">
                    <div class="sps-progresso-trilha"><div class="sps-progresso-barra sps-faixa-${faixa}" data-largura="${Math.round(nota / 5 * 100)}"></div></div>
                </div>` : ''}
            </div>
            <button type="button" class="sps-detalhes-botao" data-sps="detalhes" aria-expanded="false" aria-controls="${idDetalhes}">
                <span>Detalhes<span class="sr-only"> de ${sanitizeHTML(at.nome)}</span></span>
                <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
            </button>
            <div class="sps-detalhes" id="${idDetalhes}" hidden>${spsDetalhes(at)}</div>
        </div>
    </article>`;
}

function spsDetalhes(at) {
    const titulo = (icone, texto) => `<div class="sps-det-titulo"><span class="material-symbols-outlined" aria-hidden="true">${icone}</span>${texto}</div>`;

    let envio = '<div class="sps-envio"><span class="material-symbols-outlined" aria-hidden="true">schedule</span> Nenhum envio registrado</div>';
    if (at.ultimo_envio) {
        const quando = new Date(String(at.ultimo_envio).replace(' ', 'T'));
        const dias = Math.max(0, Math.floor((Date.now() - quando.getTime()) / 86400000));
        const classe = dias <= 7 ? 'sps-envio-recente' : dias <= 30 ? 'sps-envio-atencao' : 'sps-envio-inativo';
        const icone = dias <= 7 ? 'check_circle' : dias <= 30 ? 'warning' : 'error';
        envio = `<div class="sps-envio ${classe}"><span class="material-symbols-outlined" aria-hidden="true">${icone}</span>
            ${spsData(at.ultimo_envio)} <span>(${dias === 0 ? 'hoje' : dias === 1 ? 'ontem' : 'há ' + dias + ' dias'})</span></div>`;
    }

    let notas = '';
    if (at.total_avaliados > 0) {
        const dist = at.rating_dist || {};
        const maximo = Math.max(1, ...[1, 2, 3, 4, 5].map(n => dist[n] || 0));
        const linhas = [5, 4, 3, 2, 1].map(n => `<div class="sps-dist-linha">
            <span class="sps-dist-estrela">${n}★</span>
            <div class="sps-dist-trilha"><div class="sps-dist-barra sps-dist-${n}" data-largura="${Math.round((dist[n] || 0) / maximo * 100)}"></div></div>
            <span class="sps-dist-qtd">${dist[n] || 0}</span></div>`).join('');
        const pos = (dist[4] || 0) + (dist[5] || 0);
        const neg = (dist[1] || 0) + (dist[2] || 0);
        notas = `<div>${titulo('bar_chart', 'Distribuição de notas')}
                <div class="sps-dist" role="img" aria-label="Notas: ${[5, 4, 3, 2, 1].map(n => n + ' estrelas ' + (dist[n] || 0)).join(', ')}">${linhas}</div></div>
            <div>${titulo('sentiment_satisfied', 'Sentimento')}
                <div class="sps-sentimento">
                    <div class="sps-sent-item"><span class="sps-sent-emoji" aria-hidden="true">😊</span><span class="sps-sent-valor sps-sent-pos">${pos}</span><span class="sps-sent-rotulo">Positivo</span></div>
                    <div class="sps-sent-item"><span class="sps-sent-emoji" aria-hidden="true">😐</span><span class="sps-sent-valor sps-sent-neu">${dist[3] || 0}</span><span class="sps-sent-rotulo">Neutro</span></div>
                    <div class="sps-sent-item"><span class="sps-sent-emoji" aria-hidden="true">😞</span><span class="sps-sent-valor sps-sent-neg">${neg}</span><span class="sps-sent-rotulo">Negativo</span></div>
                </div></div>`;
    }

    const hist = at.historico_mensal || [];
    let evolucao = '';
    if (hist.length) {
        // Mesmo desenho do METRICA360: linha e área num SVG de 260×40
        const largura = 260, alturaSvg = 40, pad = 4;
        const maximo = Math.max(1, ...hist.map(h => h.total));
        const pts = hist.map((h, i) => ({
            x: Math.round((pad + (i / Math.max(1, hist.length - 1)) * (largura - 2 * pad)) * 10) / 10,
            y: Math.round((pad + (alturaSvg - 2 * pad) - (h.total / maximo) * (alturaSvg - 2 * pad)) * 10) / 10,
        }));
        const linha = pts.map((pt, i) => (i === 0 ? 'M' : 'L') + pt.x + ',' + pt.y).join(' ');
        const area = `${linha} L${pts[pts.length - 1].x},${alturaSvg - pad} L${pts[0].x},${alturaSvg - pad} Z`;
        evolucao = `<div>${titulo('trending_up', 'Evolução (6 meses)')}
            <div class="sps-evolucao">
                <svg viewBox="0 0 ${largura} ${alturaSvg}" preserveAspectRatio="none" aria-hidden="true">
                    <path class="sps-evolucao-area" d="${area}"/><path class="sps-evolucao-linha" d="${linha}"/>
                    ${pts.map(pt => `<circle class="sps-evolucao-ponto" cx="${pt.x}" cy="${pt.y}" r="2.5"/>`).join('')}
                </svg>
                <div class="sps-evolucao-rotulos">${hist.map(h => `<span>${sanitizeHTML(h.label)} (${h.total})</span>`).join('')}</div>
            </div></div>`;
    }

    const comentario = String(at.ultimo_comentario || '');
    const comentarioCurto = comentario.length > 200 ? comentario.slice(0, 199) + '…' : comentario;

    return `<div class="sps-det-interno">
        <div>${titulo('send', 'Último envio')}${envio}</div>
        ${notas}
        ${evolucao}
        <div>${titulo('chat_bubble', 'Último comentário')}
            ${comentario ? `<div class="sps-comentario">${sanitizeHTML(comentarioCurto)}</div>` : '<div class="sps-comentario-vazio">Nenhum comentário recebido.</div>'}</div>
    </div>`;
}

function spsTabela(setor, melhor) {
    const linhas = setor.servidores.map(at => {
        const taxa = spsTaxa(at);
        const n = at.media_nota;
        return `<tr data-nome="${sanitizeHTML(at.nome)}" data-nota="${n ?? -1}" data-mes="${at.mes_atual}" data-aval="${at.total_avaliados}">
            <td><div class="sps-tabela-nome"><div class="sps-tabela-foto">${spsFoto(at.nome, at.avatar, 'sps-foto-img')}</div>
                ${sanitizeHTML(at.nome)}${melhor && melhor.id === at.id ? ' <span class="sps-tabela-destaque" title="Melhor nota média do setor">★<span class="sr-only"> Destaque</span></span>' : ''}</div></td>
            <td>${sanitizeHTML(Papeis.rotulo(at.tipo))}</td>
            <td>${at.sem_ant}</td><td>${at.sem_atual}</td><td>${at.mes_ant}</td><td>${at.mes_atual}</td>
            <td>${at.total_avaliados}</td>
            <td>${taxa !== null ? taxa + '%' : '—'}</td>
            <td><span class="sps-nota-pilula sps-nota-${spsFaixa(n)}">${n !== null ? spsNota(n) + '★' : '—'}</span></td>
        </tr>`;
    }).join('');
    return `<div class="sps-tabela-rolagem"><table class="sps-tabela">
        <caption class="sr-only">Atendentes do setor ${sanitizeHTML(setor.nome)}</caption>
        <thead><tr><th scope="col">Atendente</th><th scope="col">Perfil</th><th scope="col">Sem. ant.</th><th scope="col">Sem. atual</th>
            <th scope="col">Mês ant.</th><th scope="col">Mês atual</th><th scope="col">Avaliações</th><th scope="col">Taxa resp.</th><th scope="col">Nota</th></tr></thead>
        <tbody>${linhas}</tbody></table></div>`;
}

// ── Comportamento ───────────────────────────────────────────────────────────
// Larguras, alturas e posições calculadas (barras, minibarras, rótulo da média, arco da taxa)
function spsAplicarGeometria(raiz) {
    raiz.querySelectorAll('.sps-painel [data-largura]').forEach(el => { el.style.width = el.dataset.largura + '%'; });
    raiz.querySelectorAll('.sps-painel [data-altura-px]').forEach(el => { el.style.height = el.dataset.alturaPx + 'px'; });
    raiz.querySelectorAll('.sps-painel [data-esquerda]').forEach(el => { el.style.left = el.dataset.esquerda + '%'; });
    raiz.querySelectorAll('.sps-painel .sps-donut-arco[data-offset]').forEach(el => el.setAttribute('stroke-dashoffset', el.dataset.offset));
}

function spsComparar(a, b, chave) {
    const nota = el => parseFloat(el.dataset.nota);
    switch (chave) {
        case 'nota-desc': return nota(b) - nota(a);
        case 'nota-asc': {
            const na = nota(a), nb = nota(b);
            if (na < 0 && nb < 0) return 0;
            if (na < 0) return 1;
            if (nb < 0) return -1;
            return na - nb;
        }
        case 'mes-desc': return parseInt(b.dataset.mes, 10) - parseInt(a.dataset.mes, 10);
        case 'aval-desc': return parseInt(b.dataset.aval, 10) - parseInt(a.dataset.aval, 10);
        default: return (a.dataset.nome || '').localeCompare(b.dataset.nome || '', 'pt-BR');
    }
}

// Reordena cartões e linhas sem montar de novo (os "Detalhes" abertos continuam abertos)
function spsOrdenarDom(raiz) {
    raiz.querySelectorAll('.sps-grade, .sps-tabela tbody').forEach(lista => {
        Array.from(lista.children).sort((a, b) => spsComparar(a, b, spsOrdem)).forEach(el => lista.appendChild(el));
    });
}

function spsAlternarDetalhes(botao, abrir) {
    const painel = document.getElementById(botao.getAttribute('aria-controls'));
    if (!painel) return;
    const abre = abrir === undefined ? botao.getAttribute('aria-expanded') !== 'true' : abrir;
    botao.setAttribute('aria-expanded', String(abre));
    botao.classList.toggle('sps-aberto', abre);
    painel.hidden = !abre;
}

function spsTrocarVisao(visao) {
    spsGravarVisao(visao);
    const conteudo = document.getElementById('satisfacao-conteudo');
    conteudo.querySelectorAll('[data-sps="visao"]').forEach(b => {
        const ativo = b.dataset.visao === visao;
        b.classList.toggle('sps-ativo', ativo);
        b.setAttribute('aria-pressed', String(ativo));
    });
    conteudo.querySelectorAll('.sps-setor').forEach(s => {
        s.classList.toggle('sps-visao-cartoes', visao === 'cartoes');
        s.classList.toggle('sps-visao-tabela', visao === 'tabela');
    });
}

// PDF: a impressão do navegador, só com o painel (sidebar e ferramentas escondidas pelo CSS de impressão)
function spsImprimir() {
    document.body.classList.add('sps-imprimindo');
    window.addEventListener('afterprint', () => document.body.classList.remove('sps-imprimindo'), { once: true });
    window.print();
}

document.addEventListener('click', (ev) => {
    const alvo = ev.target.closest('#satisfacao-conteudo [data-sps]');
    if (!alvo || alvo.tagName === 'SELECT') return;
    const acao = alvo.dataset.sps;
    if (acao === 'visao') spsTrocarVisao(alvo.dataset.visao === 'tabela' ? 'tabela' : 'cartoes');
    else if (acao === 'detalhes') spsAlternarDetalhes(alvo);
    else if (acao === 'imprimir') spsImprimir();
});

document.addEventListener('change', (ev) => {
    const select = ev.target.closest('#satisfacao-conteudo select[data-sps="ordem"]');
    if (!select) return;
    spsOrdem = SPS_ORDENS.some(([v]) => v === select.value) ? select.value : 'nome';
    spsOrdenarDom(document.getElementById('satisfacao-conteudo'));
});

// Foto que não carrega (arquivo apagado): volta às iniciais
document.addEventListener('error', (ev) => {
    const img = ev.target;
    if (img && img.matches && img.matches('#satisfacao-conteudo img.sps-foto-img')) {
        img.replaceWith(document.createTextNode(img.dataset.iniciais || ''));
    }
}, true);
