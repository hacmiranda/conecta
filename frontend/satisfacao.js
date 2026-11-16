/**
 * Módulo Satisfação (antigo METRICA360, unificado ao Conecta em 03/11/2026): grupo "Satisfação" da sidebar,
 * seção #satisfacao, subtela em cd_subSecaoAtiva.
 *   Avaliações        a "Central de Feedbacks", igual à do METRICA360: em satisfacao-central.js (16/11/2026)
 *   Painel de Setores igual ao do METRICA360: em satisfacao-setores.js (16/11/2026)
 *   Convites          histórico dos convites enviados (somente leitura)
 * Quem vê: administrador, coordenador geral, supervisor e coordenador (Papeis.veSatisfacao). Os perfis de consulta
 * só fazem a triagem. O servidor limita o que cada um enxerga; o seletor de setor do cabeçalho vale aqui (urlComSetor).
 * Sem onclick/style inline: ações por data-acao com ouvintes delegados (CSS em satisfacao.css).
 */
const SAT_TELAS = {
    'avaliacoes': { titulo: '⭐ Central de Feedbacks', carregar: () => carregarCentralFeedbacks() },
    'painel-setores': { titulo: '🏢 Painel de Setores', carregar: () => carregarSatSetores() },
    'convites': { titulo: '✉️ Convites', carregar: () => carregarSatConvites() },
};
const SAT_SITUACOES_CONVITE = { avaliado: 'Avaliado', aguardando: 'Aguardando', expirado: 'Expirado' };

let satTela = 'avaliacoes';
const satEstado = {
    avaliacoes: { pagina: 1, dias: 30, filtros: {} },
    convites: { pagina: 1, filtros: {} },
    setores: { filtros: {} },
};

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

// ═══════════════ Avaliações e Painel de Setores ═══════════════
// Central de Feedbacks em satisfacao-central.js e Painel de Setores em satisfacao-setores.js (16/11/2026).

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
        if (acao === 'pagina') {
            satEstado.convites.pagina = Math.max(1, Number(alvo.dataset.pagina) || 1);
            carregarSatConvites();
        } else if (acao === 'limpar-convites') {
            satEstado.convites.filtros = {};
            satEstado.convites.pagina = 1;
            carregarSatConvites();
        }
    });

    conteudo.addEventListener('submit', (ev) => {
        const form = ev.target.closest('[data-acao-form]');
        if (!form) return;
        ev.preventDefault();
        const tipo = form.dataset.acaoForm;
        if (tipo === 'avaliacoes') {
            const filtros = filtrosDoFormSat(form);
            if (filtros.data_ini && filtros.data_fim && filtros.data_ini > filtros.data_fim) {
                mostrarAlert('Atenção', 'A data inicial deve ser anterior ou igual à data final.');
                return;
            }
            satEstado.avaliacoes.filtros = filtros;
            satEstado.avaliacoes.pagina = 1;
            carregarCentralFeedbacks();
        } else if (tipo === 'convites') {
            satEstado.convites.filtros = filtrosDoFormSat(form);
            satEstado.convites.pagina = 1;
            carregarSatConvites();
        } else if (tipo === 'setores') {
            const filtros = filtrosDoFormSat(form);
            if (filtros.data_ini && filtros.data_fim && filtros.data_ini > filtros.data_fim) {
                mostrarAlert('Atenção', 'A data inicial do período deve ser anterior ou igual à data final.');
                return;
            }
            satEstado.setores.filtros = filtros;
            carregarSatSetores();
        }
    });
}

// Itens do menu (sem onclick no HTML) e troca de setor no cabeçalho
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
