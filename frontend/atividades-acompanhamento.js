/**
 * Acompanhamento do Registro de Atividades (admin e gestor; veio das telas de admin do METRICA360, 27/10/2026):
 * Painel de Atividades (com abono de pendências), Relatório de Atividades, Histórico de ausências e
 * Cruzamento de dados (atividades × satisfação do METRICA360). Grupo "Atividades" da sidebar, seção
 * #atividades; subtela em cd_subSecaoAtiva. Admin segue o setor escolhido no cabeçalho (urlComSetor).
 * Sem onclick/style inline: ações por data-acao com ouvintes delegados (CSS em atividades-acompanhamento.css).
 */
const ACOMP_TELAS = {
    'painel-atividades': { titulo: '📈 Painel de Atividades', carregar: () => carregarPainelAtividades() },
    'relatorio-atividades': { titulo: '📋 Relatório de Atividades', carregar: () => carregarRelatorioAtividades() },
    'historico-ausencias': { titulo: '🗓️ Histórico de Ausências', carregar: () => carregarHistoricoAusencias() },
    'cruzamento-dados': { titulo: '🔀 Cruzamento de Dados', carregar: () => carregarCruzamentoDados() },
};
let acompTela = 'painel-atividades';
let acompUltimoCruzamento = null;
const acompFiltros = { relatorio: { p: 1 }, historico: {}, cruzamento: {} };

function podeAcompanharAtividades() {
    const u = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    return u.tipo === 'admin' || u.tipo === 'gestor';
}

function mostrarAcompanhamentoAtividades(tela) {
    acompTela = ACOMP_TELAS[tela] ? tela : 'painel-atividades';
    mostrarSecao('atividades');
    localStorage.setItem('cd_subSecaoAtiva', acompTela);
    document.getElementById('atividades-titulo').textContent = ACOMP_TELAS[acompTela].titulo;
    const conteudo = document.getElementById('atividades-conteudo');
    ligarAcoesAcompanhamento(conteudo);
    conteudo.innerHTML = '<p class="acomp-aviso">Carregando...</p>';
    ACOMP_TELAS[acompTela].carregar();
}

function acompUrl(acao, params) {
    const q = new URLSearchParams({ acao });
    Object.entries(params || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v); });
    return urlComSetor('atividades-acompanhamento.php?' + q.toString());
}

function acompBr(iso) {
    if (!iso) return '';
    const [a, m, d] = String(iso).slice(0, 10).split('-');
    return `${d}/${m}/${a}`;
}

function acompDiaMes(iso) {
    return acompBr(iso).slice(0, 5);
}

function acompDiaSemana(iso) {
    const [a, m, d] = String(iso).slice(0, 10).split('-').map(Number);
    return ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][new Date(a, m - 1, d).getDay()];
}

function acompErro(e, padrao) {
    document.getElementById('atividades-conteudo').innerHTML =
        `<p class="acomp-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, padrao))}</p>`;
}

function rotuloPendencia(p) {
    const data = p.data || p.semana_ini;
    const tipo = p.tipo === 'entrega' ? 'entrega' : 'remoto';
    return p.data ? `${acompBr(data)} · ${tipo}` : `semana de ${acompBr(p.semana_ini)} · ${tipo}`;
}

// Botões de abonar / remover abono de uma pendência (Painel e Relatório)
function botoesPendencia(usuarioId, nome, p) {
    const data = p.data || p.semana_ini;
    if (p.status === 'abonado') {
        return `<span class="acomp-chip acomp-chip--abonado" title="${sanitizeHTML(p.justificativa || '')}">Abonado: ${sanitizeHTML(rotuloPendencia(p))}</span>
            <button type="button" class="acomp-mini" data-acao="remover-abono" data-id="${Number(p.abono_id)}"
                aria-label="Remover o abono de ${sanitizeHTML(nome)} em ${acompBr(data)}">✕</button>`;
    }
    return `<span class="acomp-chip acomp-chip--atrasado">Atrasado: ${sanitizeHTML(rotuloPendencia(p))}</span>
        <button type="button" class="acomp-mini" data-acao="abonar" data-usuario="${usuarioId}" data-nome="${sanitizeHTML(nome)}"
            data-data="${sanitizeHTML(data)}" data-tipo="${p.tipo === 'entrega' ? 'entrega' : 'remoto'}"
            aria-label="Abonar a pendência de ${sanitizeHTML(nome)} em ${acompBr(data)}">Abonar</button>`;
}

// ═══════════════ Painel de Atividades ═══════════════
async function carregarPainelAtividades() {
    try {
        const d = await Api.get(acompUrl('painel'));
        document.getElementById('atividades-conteudo').innerHTML = montarPainelAtividades(d);
    } catch (e) {
        acompErro(e, 'Erro ao carregar o painel de atividades.');
    }
}

function montarPainelAtividades(d) {
    const p = d.periodos;
    const usuarios = d.setores.flatMap(s => s.usuarios);
    const soma = campo => usuarios.reduce((t, u) => t + u[campo], 0);
    const semEnvioHoje = usuarios.filter(u => u.situacao_hoje === 'atrasado' || u.situacao_hoje === 'no_prazo').length;
    const atrasos = usuarios.flatMap(u => u.atrasadas.map(x => ({ nome: u.nome, p: x })));
    const visao = (() => { try { return localStorage.getItem('cd_painelAtividadesVisao') || 'tabela'; } catch (e) { return 'tabela'; } })();
    const meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    const mes = meses[Number(p.mes_atual_ini.slice(5, 7)) - 1] + '/' + p.mes_atual_ini.slice(0, 4);

    const kpi = (rotulo, valor, sub, alerta) => `<li class="acomp-kpi${alerta ? ' acomp-kpi--alerta' : ''}">
        <span class="acomp-kpi-rotulo">${rotulo}</span><span class="acomp-kpi-valor">${valor}</span><span class="acomp-kpi-sub">${sub}</span></li>`;

    return `
        <p class="acomp-intro">Semana atual: <strong>${acompDiaMes(p.sem_atual_ini)} a ${acompDiaMes(p.sem_atual_fim)}</strong> · Mês atual: <strong>${mes}</strong></p>
        ${atrasos.length ? `
            <details class="acomp-alerta">
                <summary>⚠️ ${atrasos.length} pendência${atrasos.length !== 1 ? 's' : ''} de envio de relatório em atraso</summary>
                <ul class="acomp-chips">${atrasos.map(a => `<li class="acomp-chip">${sanitizeHTML(a.nome)} <em>${sanitizeHTML(rotuloPendencia(a.p))}</em></li>`).join('')}</ul>
            </details>` : ''}
        <ul class="acomp-kpis" aria-label="Indicadores do escopo">
            ${kpi('Servidores', usuarios.length, 'no escopo')}
            ${kpi('Sem envio hoje', semEnvioHoje, 'servidor' + (semEnvioHoje !== 1 ? 'es' : ''), semEnvioHoje > 0)}
            ${kpi('Relatórios na semana atual', soma('sem_atual'), `${acompDiaMes(p.sem_atual_ini)} a ${acompDiaMes(p.sem_atual_fim)}`)}
            ${kpi('Relatórios no mês atual', soma('mes_atual'), `${acompDiaMes(p.mes_atual_ini)} a ${acompDiaMes(p.mes_atual_fim)}`)}
            ${kpi('Relatórios na semana passada', soma('sem_ant'), `${acompDiaMes(p.sem_ant_ini)} a ${acompDiaMes(p.sem_ant_fim)}`)}
            ${kpi('Relatórios no mês passado', soma('mes_ant'), `${acompDiaMes(p.mes_ant_ini)} a ${acompDiaMes(p.mes_ant_fim)}`)}
        </ul>
        <div class="acomp-visao" role="group" aria-label="Forma de exibir">
            <button type="button" class="btn-small" data-acao="visao" data-visao="tabela" aria-pressed="${visao === 'tabela'}">☰ Tabela</button>
            <button type="button" class="btn-small" data-acao="visao" data-visao="cartoes" aria-pressed="${visao === 'cartoes'}">▦ Cartões</button>
        </div>
        ${d.setores.length ? d.setores.map(s => montarSetorPainel(s, visao)).join('')
            : '<p class="acomp-aviso">Nenhum gestor ou operador no seu escopo.</p>'}`;
}

function situacaoHoje(u) {
    return {
        fim_de_semana: '<span class="acomp-situacao acomp-situacao--neutra">Fim de semana</span>',
        enviou: '<span class="acomp-situacao acomp-situacao--ok">✓ Enviou hoje</span>',
        atrasado: '<span class="acomp-situacao acomp-situacao--atraso" title="Atrasado conforme o prazo do setor">✗ Em atraso</span>',
        no_prazo: '<span class="acomp-situacao acomp-situacao--prazo" title="Ainda dentro do prazo do setor">◷ Dentro do prazo</span>',
    }[u.situacao_hoje] || '';
}

function tendenciaHtml(t) {
    const seta = { up: '↗', down: '↘', flat: '→', 'sem-dado': '' }[t.direcao];
    return `<span class="acomp-tendencia acomp-tendencia--${t.direcao}">${seta} ${sanitizeHTML(t.rotulo)}</span>`;
}

function pendenciasUsuarioHtml(u) {
    const lista = [...u.atrasadas, ...u.abonadas];
    if (!lista.length) return '<span class="acomp-traco">—</span>';
    return `<div class="acomp-pendencias">${lista.map(p => `<span class="acomp-pendencia">${botoesPendencia(u.id, u.nome, p)}</span>`).join('')}</div>`;
}

function montarSetorPainel(s, visao) {
    const soma = campo => s.usuarios.reduce((t, u) => t + u[campo], 0);
    const cabecalho = `
        <div class="acomp-setor-cabecalho">
            <h3 class="acomp-setor-titulo">${sanitizeHTML(s.nome)}</h3>
            <span class="acomp-setor-contagem">${s.usuarios.length} servidor${s.usuarios.length !== 1 ? 'es' : ''}</span>
            <dl class="acomp-setor-kpis">
                <div><dt>Sem. atual</dt><dd>${soma('sem_atual')}</dd></div>
                <div><dt>Sem. passada</dt><dd>${soma('sem_ant')}</dd></div>
                <div><dt>Mês atual</dt><dd>${soma('mes_atual')}</dd></div>
                <div><dt>Mês passado</dt><dd>${soma('mes_ant')}</dd></div>
            </dl>
        </div>`;
    if (visao === 'cartoes') {
        return `<section class="acomp-setor" aria-label="${sanitizeHTML(s.nome)}">${cabecalho}
            <ul class="acomp-cartoes">${s.usuarios.map(u => `
                <li class="acomp-cartao acomp-cartao--${u.situacao_hoje}">
                    <div class="acomp-cartao-topo"><strong>${sanitizeHTML(u.nome)}</strong>${situacaoHoje(u)}</div>
                    <dl class="acomp-cartao-numeros">
                        <div><dt>Semana passada</dt><dd>${u.sem_ant}</dd></div>
                        <div><dt>Semana atual</dt><dd>${u.sem_atual}</dd></div>
                        <div><dt>Mês passado</dt><dd>${u.mes_ant}</dd></div>
                        <div><dt>Mês atual</dt><dd>${u.mes_atual}</dd></div>
                    </dl>
                    <p class="acomp-cartao-linha">Tendência da semana: ${tendenciaHtml(u.tendencia)} <small>vs semana passada</small></p>
                    <p class="acomp-cartao-linha">Dias sem envio (semana atual):
                        ${u.dias_sem_envio.length
                            ? u.dias_sem_envio.map(d => `<span class="acomp-chip">${acompDiaSemana(d)} ${acompDiaMes(d)}</span>`).join(' ')
                            : '<span class="acomp-ok">✓ Todos os dias enviados</span>'}</p>
                    <div class="acomp-cartao-linha">Pendências de envio: ${pendenciasUsuarioHtml(u)}</div>
                </li>`).join('')}</ul></section>`;
    }
    return `<section class="acomp-setor" aria-label="${sanitizeHTML(s.nome)}">${cabecalho}
        <div class="acomp-tabela-wrap"><table class="acomp-tabela">
            <caption class="sr-only">Servidores do setor ${sanitizeHTML(s.nome)}</caption>
            <thead><tr><th scope="col">Servidor</th><th scope="col">Hoje</th><th scope="col">Sem. passada</th><th scope="col">Sem. atual</th>
                <th scope="col">Mês passado</th><th scope="col">Mês atual</th><th scope="col">Tendência</th><th scope="col">Pendências</th></tr></thead>
            <tbody>${s.usuarios.map(u => `<tr>
                <th scope="row">${sanitizeHTML(u.nome)}</th><td>${situacaoHoje(u)}</td>
                <td>${u.sem_ant}</td><td>${u.sem_atual}</td><td>${u.mes_ant}</td><td>${u.mes_atual}</td>
                <td>${tendenciaHtml(u.tendencia)}</td><td>${pendenciasUsuarioHtml(u)}</td></tr>`).join('')}</tbody>
        </table></div></section>`;
}

// ═══════════════ Relatório de Atividades ═══════════════
async function carregarRelatorioAtividades() {
    const f = acompFiltros.relatorio;
    try {
        const d = await Api.get(acompUrl('relatorio', f));
        document.getElementById('atividades-conteudo').innerHTML = montarRelatorioAtividades(d, f);
    } catch (e) {
        acompErro(e, 'Erro ao carregar o relatório de atividades.');
    }
}

function opcoesUsuarios(usuarios, selecionado, rotuloTodos) {
    return `${rotuloTodos !== null ? `<option value="">${rotuloTodos}</option>` : ''}${usuarios.map(u =>
        `<option value="${u.id}"${String(u.id) === String(selecionado || '') ? ' selected' : ''}>${sanitizeHTML(u.nome)} (${sanitizeHTML(u.setor_nome)})</option>`).join('')}`;
}

function montarRelatorioAtividades(d, f) {
    const noPeriodo = f.data_ini || f.data_fim ? ' no período' : '';
    const m = d.metricas;
    const pm = d.por_modalidade;
    const atrasadas = d.pendencias.filter(x => x.pendencia.status === 'atrasado').length;
    const csv = window.APP_CONFIG.apiBase + acompUrl('csv-relatorio', { usuario_id: f.usuario_id, data_ini: f.data_ini, data_fim: f.data_fim, modalidade: f.modalidade });
    return `
        <p class="acomp-intro">Acompanhe as atividades registradas pelos servidores.</p>
        <ul class="acomp-kpis acomp-kpis--3">
            <li class="acomp-kpi"><span class="acomp-kpi-rotulo">Relatórios enviados${noPeriodo}</span><span class="acomp-kpi-valor">${m.total_registros}</span></li>
            <li class="acomp-kpi"><span class="acomp-kpi-rotulo">Servidores com envio${noPeriodo}</span><span class="acomp-kpi-valor">${m.total_usuarios}</span></li>
            <li class="acomp-kpi"><span class="acomp-kpi-rotulo">Atividades registradas${noPeriodo}</span><span class="acomp-kpi-valor">${m.total_atividades}</span></li>
        </ul>
        ${pm.presencial.relatorios ? `
            <section class="acomp-bloco" aria-labelledby="acomp-comparativo">
                <h3 id="acomp-comparativo" class="acomp-bloco-titulo">Remoto × Presencial${noPeriodo}</h3>
                <div class="acomp-tabela-wrap"><table class="acomp-tabela">
                    <caption class="sr-only">Comparativo de relatórios remotos e presenciais</caption>
                    <thead><tr><th scope="col">Modalidade</th><th scope="col">Relatórios</th><th scope="col">Servidores</th>
                        <th scope="col">Atividades por relatório</th><th scope="col">Tarefas por relatório</th></tr></thead>
                    <tbody>${[['remoto', 'Remoto'], ['presencial', 'Presencial']].map(([k, r]) => {
                        const x = pm[k]; const rel = Math.max(1, x.relatorios);
                        return `<tr><th scope="row">${r}</th><td>${x.relatorios}</td><td>${x.usuarios}</td>
                            <td>${(x.atividades / rel).toFixed(1).replace('.', ',')}</td><td>${(x.tarefas / rel).toFixed(1).replace('.', ',')}</td></tr>`;
                    }).join('')}</tbody>
                </table></div>
            </section>` : ''}
        ${d.pendencias.length ? `
            <details class="acomp-alerta"${atrasadas ? ' open' : ''}>
                <summary>⚠️ ${atrasadas} pendência${atrasadas !== 1 ? 's' : ''} de envio de relatório em atraso (últimos 60 dias)</summary>
                <ul class="acomp-chips">${d.pendencias.map(x => `<li class="acomp-pendencia"><strong>${sanitizeHTML(x.usuario_nome)}</strong> ${botoesPendencia(x.usuario_id, x.usuario_nome, x.pendencia)}</li>`).join('')}</ul>
            </details>` : ''}
        <form class="acomp-filtros" data-acao-form="relatorio" autocomplete="off">
            <div class="form-group"><label for="acomp-f-usuario">Servidor</label>
                <select id="acomp-f-usuario" name="usuario_id">${opcoesUsuarios(d.usuarios, f.usuario_id, 'Todos')}</select></div>
            <div class="form-group"><label for="acomp-f-modalidade">Modalidade</label>
                <select id="acomp-f-modalidade" name="modalidade">
                    <option value="">Todas</option>
                    <option value="remoto"${f.modalidade === 'remoto' ? ' selected' : ''}>Remoto</option>
                    <option value="presencial"${f.modalidade === 'presencial' ? ' selected' : ''}>Presencial</option>
                </select></div>
            <div class="form-group"><label for="acomp-f-ini">Data início</label><input type="date" id="acomp-f-ini" name="data_ini" value="${sanitizeHTML(f.data_ini || '')}"></div>
            <div class="form-group"><label for="acomp-f-fim">Data fim</label><input type="date" id="acomp-f-fim" name="data_fim" value="${sanitizeHTML(f.data_fim || '')}"></div>
            <div class="acomp-filtros-botoes">
                <button type="submit" class="btn-primary">🔍 Filtrar</button>
                <button type="button" class="btn-secondary" data-acao="limpar-relatorio">Limpar</button>
            </div>
        </form>
        <section class="acomp-bloco" aria-labelledby="acomp-registros">
            <div class="acomp-bloco-cabecalho">
                <h3 id="acomp-registros" class="acomp-bloco-titulo">Registros <small>${d.total} relatório${d.total !== 1 ? 's' : ''} encontrado${d.total !== 1 ? 's' : ''}</small></h3>
                <a class="btn-small" href="${sanitizeHTML(csv)}" download>⬇️ Exportar CSV</a>
            </div>
            ${d.itens.length ? `<ul class="acomp-registros">${d.itens.map(r => `
                <li class="acomp-registro">
                    <button type="button" class="acomp-registro-botao" data-acao="detalhe" data-id="${r.id}" aria-expanded="false" aria-controls="acomp-det-${r.id}">
                        <span class="acomp-registro-nome"><strong>${sanitizeHTML(r.usuario_nome)}</strong> <small>${sanitizeHTML(r.setor_nome || '—')}</small>
                            <span class="acomp-selo acomp-selo--${r.modalidade}">${r.modalidade === 'presencial' ? 'Presencial' : 'Remoto'}</span></span>
                        <span class="acomp-registro-data">${acompDiaSemana(r.data_referencia)}, ${acompBr(r.data_referencia)}
                            ${r.enviado_em ? `<small>Enviado ${acompBr(r.enviado_em)} às ${String(r.enviado_em).slice(11, 16)}</small>` : ''}</span>
                        <span class="acomp-registro-qtd">Atividades: ${String(r.total_atividades).padStart(2, '0')} • Tarefas: ${String(r.total_tarefas).padStart(2, '0')}</span>
                    </button>
                    <div class="acomp-registro-detalhe" id="acomp-det-${r.id}" hidden></div>
                </li>`).join('')}</ul>${paginacaoAcomp(d.pagina, d.total_paginas)}`
            : '<p class="acomp-aviso">Nenhum relatório encontrado. Tente ajustar os filtros.</p>'}
        </section>`;
}

function paginacaoAcomp(pagina, total) {
    if (total <= 1) return '';
    const paginas = [];
    for (let i = 1; i <= total; i++) if (i === 1 || i === total || Math.abs(i - pagina) <= 2) paginas.push(i);
    let anterior = null;
    const partes = paginas.map(i => {
        const reticencias = anterior !== null && i - anterior > 1 ? '<span class="acomp-reticencias">…</span>' : '';
        anterior = i;
        return reticencias + `<button type="button" class="acomp-pagina${i === pagina ? ' acomp-pagina--atual' : ''}" data-acao="pagina" data-pagina="${i}"${i === pagina ? ' aria-current="page"' : ''}>${i}</button>`;
    });
    return `<nav class="acomp-paginacao" aria-label="Páginas dos registros">${partes.join('')}</nav>`;
}

async function abrirDetalheRegistro(botao) {
    const painel = document.getElementById(botao.getAttribute('aria-controls'));
    const abrir = botao.getAttribute('aria-expanded') !== 'true';
    botao.setAttribute('aria-expanded', String(abrir));
    painel.hidden = !abrir;
    if (!abrir || painel.dataset.carregado) return;
    painel.innerHTML = '<p class="acomp-aviso">Carregando…</p>';
    try {
        const d = await Api.get(acompUrl('detalhe', { id: botao.dataset.id }));
        painel.dataset.carregado = '1';
        painel.innerHTML = d.itens.length ? `<table class="acomp-tabela acomp-tabela--compacta">
            <caption class="sr-only">Atividades do relatório</caption>
            <thead><tr><th scope="col">Atividade</th><th scope="col">Quantidade</th><th scope="col">Observação</th></tr></thead>
            <tbody>${d.itens.map(i => `<tr><td>${sanitizeHTML(i.atividade_nome)}</td><td>${Number(i.quantidade)}</td>
                <td>${i.observacao ? sanitizeHTML(i.observacao) : '<span class="acomp-traco">—</span>'}</td></tr>`).join('')}</tbody></table>`
            : '<p class="acomp-aviso">Nenhuma atividade com quantidade neste relatório.</p>';
    } catch (e) {
        painel.innerHTML = `<p class="acomp-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar o relatório.'))}</p>`;
    }
}

// ═══════════════ Histórico de ausências ═══════════════
async function carregarHistoricoAusencias() {
    const f = acompFiltros.historico;
    try {
        const d = await Api.get(acompUrl('historico', f));
        document.getElementById('atividades-conteudo').innerHTML = montarHistoricoAusencias(d);
    } catch (e) {
        acompErro(e, 'Erro ao carregar o histórico de ausências.');
    }
}

function montarHistoricoAusencias(d) {
    const lista = d.ausencias;
    const total = lista.reduce((t, u) => t + u.total_ausencias, 0);
    const abonos = lista.reduce((t, u) => t + u.total_abonados, 0);
    const csv = window.APP_CONFIG.apiBase + acompUrl('csv-historico', { data_ini: d.data_ini, data_fim: d.data_fim });
    return `
        <p class="acomp-intro">Relatórios que deveriam ter sido enviados (trabalho remoto e dias de entrega) e não foram, já vencido o prazo.
            Período: <strong>${acompBr(d.data_ini)} a ${acompBr(d.data_fim)}</strong>.</p>
        <form class="acomp-filtros" data-acao-form="historico" autocomplete="off">
            <div class="form-group"><label for="acomp-h-ini">Data início</label><input type="date" id="acomp-h-ini" name="data_ini" value="${sanitizeHTML(d.data_ini)}"></div>
            <div class="form-group"><label for="acomp-h-fim">Data fim</label><input type="date" id="acomp-h-fim" name="data_fim" value="${sanitizeHTML(d.data_fim)}"></div>
            <div class="acomp-filtros-botoes">
                <button type="submit" class="btn-primary">🔍 Filtrar</button>
                <a class="btn-small" href="${sanitizeHTML(csv)}" download>⬇️ Exportar CSV</a>
            </div>
        </form>
        <ul class="acomp-kpis acomp-kpis--3">
            <li class="acomp-kpi"><span class="acomp-kpi-rotulo">Servidores com ausência</span><span class="acomp-kpi-valor">${lista.length}</span></li>
            <li class="acomp-kpi${total ? ' acomp-kpi--alerta' : ''}"><span class="acomp-kpi-rotulo">Relatórios faltantes</span><span class="acomp-kpi-valor">${total}</span></li>
            <li class="acomp-kpi"><span class="acomp-kpi-rotulo">Abonos</span><span class="acomp-kpi-valor">${abonos}</span></li>
        </ul>
        ${lista.length ? `<ul class="acomp-registros">${lista.map((u, i) => {
            const pct = u.total_esperado > 0 ? Math.round(u.total_enviado / u.total_esperado * 100) : 0;
            return `<li class="acomp-registro">
                <button type="button" class="acomp-registro-botao" data-acao="expandir" aria-expanded="false" aria-controls="acomp-hist-${i}">
                    <span class="acomp-registro-nome"><strong>${sanitizeHTML(u.nome)}</strong> <small>${sanitizeHTML(u.setor_nome)}</small></span>
                    <span class="acomp-registro-qtd">
                        <span class="acomp-selo ${pct >= 80 ? 'acomp-selo--aviso' : 'acomp-selo--atraso'}">${u.total_enviado}/${u.total_esperado} relatórios (${pct}%)</span>
                        <span class="acomp-selo acomp-selo--atraso">${u.total_ausencias} faltante${u.total_ausencias !== 1 ? 's' : ''}</span>
                        ${u.semanas.length ? `<span class="acomp-selo acomp-selo--aviso">${u.semanas.length} semana${u.semanas.length !== 1 ? 's' : ''}</span>` : ''}
                        ${u.dias.length ? `<span class="acomp-selo acomp-selo--aviso">${u.dias.length} dia${u.dias.length !== 1 ? 's' : ''}</span>` : ''}
                    </span>
                </button>
                <div class="acomp-registro-detalhe" id="acomp-hist-${i}" hidden>
                    ${u.semanas.length ? `<table class="acomp-tabela acomp-tabela--compacta"><caption class="sr-only">Semanas com falta de ${sanitizeHTML(u.nome)}</caption>
                        <thead><tr><th scope="col">Semana</th><th scope="col">Período</th><th scope="col">Esperado</th><th scope="col">Enviado</th><th scope="col">Faltaram</th><th scope="col">Abonados</th></tr></thead>
                        <tbody>${u.semanas.map(s => `<tr><td>${sanitizeHTML(s.semana)}</td><td>${acompBr(s.de)} a ${acompBr(s.ate)}</td>
                            <td>${s.esperado}</td><td>${s.enviado}</td><td>${s.faltaram}</td><td>${s.abonados}</td></tr>`).join('')}</tbody></table>` : ''}
                    ${u.dias.length ? `<table class="acomp-tabela acomp-tabela--compacta"><caption class="sr-only">Dias sem relatório de ${sanitizeHTML(u.nome)}</caption>
                        <thead><tr><th scope="col">Data</th><th scope="col">Tipo</th><th scope="col">Situação</th></tr></thead>
                        <tbody>${u.dias.map(x => `<tr><td>${acompDiaSemana(x.data)}, ${acompBr(x.data)}</td><td>${x.tipo === 'entrega' ? 'Entrega' : 'Remoto'}</td>
                            <td>${x.abonado ? '<span class="acomp-selo acomp-selo--neutro">Abonado</span>' : '<span class="acomp-selo acomp-selo--atraso">Faltou</span>'}</td></tr>`).join('')}</tbody></table>` : ''}
                </div>
            </li>`;
        }).join('')}</ul>` : '<p class="acomp-aviso">✓ Nenhuma ausência no período: todos cumpriram as regras de envio (ou o período é anterior ao início da cobrança).</p>'}`;
}

// ═══════════════ Cruzamento de dados ═══════════════
async function carregarCruzamentoDados() {
    const f = acompFiltros.cruzamento;
    try {
        const d = await Api.get(acompUrl('cruzamento', f));
        document.getElementById('atividades-conteudo').innerHTML = montarCruzamentoDados(d, f);
        acompUltimoCruzamento = d.usuario ? d : null;
        if (d.usuario) desenharGraficoCruzamento(d);
    } catch (e) {
        acompErro(e, 'Erro ao carregar o cruzamento de dados.');
    }
}

function montarCruzamentoDados(d, f) {
    const filtros = `
        <form class="acomp-filtros" data-acao-form="cruzamento" autocomplete="off">
            <div class="form-group"><label for="acomp-c-usuario">Servidor</label>
                <select id="acomp-c-usuario" name="usuario_id" required>${opcoesUsuarios(d.usuarios, f.usuario_id, 'Selecione...')}</select></div>
            <div class="form-group"><label for="acomp-c-ini">Data início</label><input type="date" id="acomp-c-ini" name="data_ini" value="${sanitizeHTML(d.data_ini)}"></div>
            <div class="form-group"><label for="acomp-c-fim">Data fim</label><input type="date" id="acomp-c-fim" name="data_fim" value="${sanitizeHTML(d.data_fim)}"></div>
            <div class="acomp-filtros-botoes">
                <button type="submit" class="btn-primary">🔍 Cruzar dados</button>
                ${d.usuario ? `<a class="btn-small" href="${sanitizeHTML(window.APP_CONFIG.apiBase + acompUrl('csv-cruzamento', { usuario_id: d.usuario.id, data_ini: d.data_ini, data_fim: d.data_fim }))}" download>⬇️ Exportar CSV</a>` : ''}
            </div>
        </form>`;
    if (!d.usuario) {
        return `<p class="acomp-intro">Compare a satisfação dos cidadãos (notas do METRICA360) com as atividades registradas por um servidor.</p>
            ${filtros}<p class="acomp-aviso">Escolha um servidor no filtro acima e, se quiser, ajuste o período.</p>`;
    }
    const s = d.satisfacao;
    const a = d.atividades;
    let satisfacao;
    if (!s) {
        satisfacao = `<p class="acomp-aviso" role="status">${sanitizeHTML(d.aviso_satisfacao || '')}</p>`;
    } else if (s.media_nota === null) {
        satisfacao = '<p class="acomp-nota">—</p><p class="acomp-aviso">Nenhuma avaliação de convite deste servidor no período.</p>';
    } else {
        const maior = Math.max(1, ...Object.values(s.rating_dist).map(Number));
        satisfacao = `
            <p class="acomp-nota">${Number(s.media_nota).toFixed(1).replace('.', ',')}★</p>
            <p class="acomp-nota-sub">${s.total_avaliados} de ${s.total_convites} convites avaliados${s.taxa_resposta !== null ? ` (${s.taxa_resposta}% de resposta)` : ''}</p>
            <ul class="acomp-distribuicao" aria-label="Distribuição das notas">${[5, 4, 3, 2, 1].map(n => {
                const q = Number(s.rating_dist[n] || 0);
                return `<li><span>${n}★</span><meter min="0" max="${maior}" value="${q}" aria-label="${q} avaliação(ões) com ${n} estrela(s)"></meter><span>${q}</span></li>`;
            }).join('')}</ul>
            ${s.comentarios.length ? `<ul class="acomp-comentarios">${s.comentarios.map(c => `<li>“${sanitizeHTML(c.comment)}”
                <small>${'★'.repeat(Number(c.rating))} · ${acompBr(c.created_at)}</small></li>`).join('')}</ul>` : ''}`;
    }
    return `
        ${filtros}
        <p class="acomp-intro"><strong>${sanitizeHTML(d.usuario.nome)}</strong> · ${acompBr(d.data_ini)} a ${acompBr(d.data_fim)}</p>
        <div class="acomp-colunas">
            <section class="acomp-bloco" aria-labelledby="acomp-c-sat">
                <h3 class="acomp-bloco-titulo" id="acomp-c-sat">⭐ Satisfação</h3>
                <p class="acomp-dica">Só avaliações de convites enviados por este servidor (avaliações públicas sem convite não entram).</p>
                ${satisfacao}
            </section>
            <section class="acomp-bloco" aria-labelledby="acomp-c-ativ">
                <h3 class="acomp-bloco-titulo" id="acomp-c-ativ">📝 Atividades e produtividade</h3>
                <dl class="acomp-mini-kpis">
                    <div><dt>Relatórios enviados / esperados</dt><dd>${a.total_enviados} / ${a.total_esperado}</dd></div>
                    <div><dt>Tarefas realizadas</dt><dd>${a.total_tarefas}</dd></div>
                    <div class="${a.pendencias_atrasadas ? 'acomp-mini-kpis--alerta' : ''}"><dt>Pendências em atraso</dt><dd>${a.pendencias_atrasadas}</dd></div>
                    <div><dt>Pendências abonadas</dt><dd>${a.pendencias_abonadas}</dd></div>
                </dl>
                ${a.atividades_top.length ? `<h4 class="acomp-subtitulo">Atividades mais realizadas</h4>
                    <ol class="acomp-top">${a.atividades_top.map(t => `<li><span>${sanitizeHTML(t.atividade_nome)}</span><strong>${t.total}</strong></li>`).join('')}</ol>`
                    : '<p class="acomp-aviso">Nenhuma atividade registrada no período.</p>'}
            </section>
        </div>
        <section class="acomp-bloco" aria-labelledby="acomp-c-graf">
            <h3 class="acomp-bloco-titulo" id="acomp-c-graf">Nota diária × Tarefas por dia</h3>
            <p class="acomp-dica">A nota conta pelo dia em que o convite foi enviado; as tarefas, pelo dia trabalhado. Podem não coincidir quando a resposta chega depois.</p>
            <div class="acomp-grafico-wrap"><canvas id="acomp-grafico-cruzamento" width="900" height="260" role="img"></canvas></div>
            <p class="acomp-legenda"><span class="acomp-legenda-barra"></span> Tarefas no dia <span class="acomp-legenda-linha"></span> Nota média do dia (0 a 5)</p>
        </section>`;
}

// Barras de tarefas (eixo esquerdo) + linha da nota (eixo direito, 0 a 5), desenhadas no canvas, cores dos tokens
function desenharGraficoCruzamento(d) {
    const canvas = document.getElementById('acomp-grafico-cruzamento');
    if (!canvas || !canvas.getContext) return;
    const estilo = getComputedStyle(document.getElementById('atividades'));
    const cor = nome => estilo.getPropertyValue(nome).trim();
    const serie = d.atividades.serie_diaria;
    const notas = {};
    (d.satisfacao?.serie_diaria || []).forEach(x => { notas[x.data_dia] = x.avg_rating; });

    const largura = canvas.parentElement.clientWidth || 900;
    canvas.width = largura;
    const ctx = canvas.getContext('2d');
    const m = { e: 36, d: 36, t: 12, b: 28 };
    const w = canvas.width - m.e - m.d;
    const h = canvas.height - m.t - m.b;
    // Eixo das tarefas em múltiplos de 5 (rótulos inteiros, sem repetir)
    const maxTarefas = Math.max(5, Math.ceil(Math.max(0, ...serie.map(x => x.tarefas)) / 5) * 5);
    const passo = w / Math.max(1, serie.length);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.font = '11px Inter, sans-serif';
    ctx.fillStyle = cor('--acomp-texto-suave');
    ctx.strokeStyle = cor('--acomp-borda');

    // Linhas de grade e eixos
    for (let i = 0; i <= 5; i++) {
        const y = m.t + h - (h * i / 5);
        ctx.beginPath(); ctx.moveTo(m.e, y); ctx.lineTo(m.e + w, y); ctx.stroke();
        ctx.textAlign = 'right'; ctx.fillText(String(Math.round(maxTarefas * i / 5)), m.e - 6, y + 4);
        ctx.textAlign = 'left'; ctx.fillText(String(i), m.e + w + 6, y + 4);
    }
    // Barras de tarefas
    ctx.fillStyle = cor('--acomp-grafico-barra');
    serie.forEach((x, i) => {
        const alt = h * x.tarefas / maxTarefas;
        ctx.fillRect(m.e + i * passo + passo * 0.2, m.t + h - alt, passo * 0.6, alt);
    });
    // Rótulos de data (até ~12)
    ctx.fillStyle = cor('--acomp-texto-suave');
    ctx.textAlign = 'center';
    const cada = Math.max(1, Math.ceil(serie.length / 12));
    serie.forEach((x, i) => { if (i % cada === 0) ctx.fillText(acompDiaMes(x.data), m.e + i * passo + passo / 2, canvas.height - 8); });
    // Linha da nota (liga só dias com nota)
    ctx.strokeStyle = cor('--acomp-grafico-linha');
    ctx.fillStyle = cor('--acomp-grafico-linha');
    ctx.lineWidth = 2;
    let anterior = null;
    serie.forEach((x, i) => {
        const nota = notas[x.data];
        if (nota === undefined || nota === null) return;
        const px = m.e + i * passo + passo / 2;
        const py = m.t + h - h * Number(nota) / 5;
        if (anterior) { ctx.beginPath(); ctx.moveTo(anterior[0], anterior[1]); ctx.lineTo(px, py); ctx.stroke(); }
        ctx.beginPath(); ctx.arc(px, py, 3, 0, Math.PI * 2); ctx.fill();
        anterior = [px, py];
    });
    ctx.lineWidth = 1;

    const comNota = serie.filter(x => notas[x.data] !== undefined && notas[x.data] !== null).length;
    canvas.setAttribute('aria-label', `Gráfico de ${serie.length} dias: ${d.atividades.total_tarefas} tarefas no total; ${comNota} dia(s) com nota de avaliação.`);
}

// ═══════════════ Abonos ═══════════════
function abonarPendencia(b) {
    const tipo = b.dataset.tipo === 'entrega' ? 'entrega' : 'remoto';
    mostrarPrompt('Abonar pendência',
        `${b.dataset.nome}: ${tipo} de ${acompBr(b.dataset.data)}. Justificativa (pelo menos 5 caracteres):`,
        async (confirmado) => {
            // O modal devolve só true/false; o texto fica no campo do próprio modal
            if (!confirmado) return;
            const justificativa = document.getElementById('modal-prompt-input').value;
            try {
                await Api.post(acompUrl('abonar'), { usuario_id: Number(b.dataset.usuario), data: b.dataset.data, tipo, justificativa: String(justificativa).trim() });
                mostrarToast('Sucesso', 'Pendência abonada.', 'success');
                ACOMP_TELAS[acompTela].carregar();
            } catch (e) {
                mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao abonar a pendência.'));
            }
        });
}

function removerAbono(id) {
    mostrarConfirm('Remover abono', 'Remover este abono? A pendência volta a aparecer como atrasada ou pendente.', async (ok) => {
        if (!ok) return;
        try {
            await Api.delete(acompUrl('abono', { id }));
            mostrarToast('Sucesso', 'Abono removido.', 'success');
            ACOMP_TELAS[acompTela].carregar();
        } catch (e) {
            mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao remover o abono.'));
        }
    }, true);
}

// ═══════════════ Ouvintes ═══════════════
function ligarAcoesAcompanhamento(conteudo) {
    if (conteudo.dataset.acompLigado) return;
    conteudo.dataset.acompLigado = '1';

    conteudo.addEventListener('click', (ev) => {
        const alvo = ev.target.closest('[data-acao]');
        if (!alvo) return;
        const acao = alvo.dataset.acao;
        if (acao === 'visao') {
            try { localStorage.setItem('cd_painelAtividadesVisao', alvo.dataset.visao); } catch (e) { /* sem armazenamento */ }
            carregarPainelAtividades();
        } else if (acao === 'abonar') abonarPendencia(alvo);
        else if (acao === 'remover-abono') removerAbono(Number(alvo.dataset.id));
        else if (acao === 'detalhe') abrirDetalheRegistro(alvo);
        else if (acao === 'expandir') {
            const painel = document.getElementById(alvo.getAttribute('aria-controls'));
            const abrir = alvo.getAttribute('aria-expanded') !== 'true';
            alvo.setAttribute('aria-expanded', String(abrir));
            painel.hidden = !abrir;
        } else if (acao === 'pagina') {
            acompFiltros.relatorio.p = Number(alvo.dataset.pagina) || 1;
            carregarRelatorioAtividades();
        } else if (acao === 'limpar-relatorio') {
            acompFiltros.relatorio = { p: 1 };
            carregarRelatorioAtividades();
        }
    });

    conteudo.addEventListener('submit', (ev) => {
        const form = ev.target.closest('[data-acao-form]');
        if (!form) return;
        ev.preventDefault();
        const dados = Object.fromEntries(new FormData(form).entries());
        const tipo = form.dataset.acaoForm;
        if (tipo === 'relatorio') {
            acompFiltros.relatorio = { ...dados, p: 1 };
            carregarRelatorioAtividades();
        } else if (tipo === 'historico') {
            acompFiltros.historico = dados;
            carregarHistoricoAusencias();
        } else if (tipo === 'cruzamento') {
            if (!dados.usuario_id) return mostrarAlert('Atenção', 'Escolha um servidor.');
            acompFiltros.cruzamento = dados;
            carregarCruzamentoDados();
        }
    });
}

// Itens do menu (sem onclick no HTML) e troca de setor no cabeçalho (admin)
document.addEventListener('click', (ev) => {
    const item = ev.target.closest('#nav-atividades-dropdown .nav-dropdown-item[data-subsecao]');
    if (item) mostrarAcompanhamentoAtividades(item.dataset.subsecao);
});
document.addEventListener('setor-contexto-alterado', () => {
    if (document.getElementById('atividades')?.classList.contains('active')) ACOMP_TELAS[acompTela].carregar();
});
window.addEventListener('resize', () => {
    // Gráfico do cruzamento acompanha a largura da tela
    if (acompUltimoCruzamento && document.getElementById('acomp-grafico-cruzamento')) {
        clearTimeout(window._acompResize);
        window._acompResize = setTimeout(() => desenharGraficoCruzamento(acompUltimoCruzamento), 200);
    }
});
