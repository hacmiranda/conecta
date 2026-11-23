/**
 * Registro de Atividades, modo "relatório do setor" (23/11/2026): o coordenador preenche e envia o
 * relatório semanal ou mensal do setor (API registro-atividades.php?modo=setor).
 * Reaproveita a lista de atividades, o −/+, a observação, a busca, as favoritas e o rascunho automático de
 * registro-atividades.js (que se comporta pelo modo atual, `raModo`/`raDados.modo`); aqui ficam só o que é do setor:
 * o seletor de setor, o campo de semana/mês, as pendências do setor e o título.
 * Sem onclick/style inline: ações por data-acao com os ouvintes delegados de registro-atividades.js.
 */
const RegistroSetor = (function () {
    'use strict';

    const CHAVE_SETOR = 'cd_raSetorEscolhido';
    const TITULO_PADRAO = 'Registro de Atividades';
    const SUBTITULO_PADRAO = 'Registre as atividades realizadas durante o expediente.';
    let setorId = null;

    // ---------- datas (UTC, sem depender do fuso do navegador) ----------
    function partes(iso) {
        const [a, m, d] = String(iso).slice(0, 10).split('-').map(Number);
        return new Date(Date.UTC(a, m - 1, d));
    }

    function iso(dt) {
        return dt.toISOString().slice(0, 10);
    }

    function somarDias(isoData, dias) {
        const dt = partes(isoData);
        dt.setUTCDate(dt.getUTCDate() + dias);
        return iso(dt);
    }

    function diaDaSemana(dt) {
        return (dt.getUTCDay() + 6) % 7; // 0 = segunda
    }

    /** "2026-11-23" → "2026-W48" (valor do <input type="week">) */
    function valorSemana(isoData) {
        const dt = partes(isoData);
        dt.setUTCDate(dt.getUTCDate() - diaDaSemana(dt) + 3); // quinta-feira da mesma semana
        const ano = dt.getUTCFullYear();
        const jan4 = new Date(Date.UTC(ano, 0, 4));
        const semana = 1 + Math.round(((dt - jan4) / 86400000 - 3 + diaDaSemana(jan4)) / 7);
        return `${ano}-W${String(semana).padStart(2, '0')}`;
    }

    /** "2026-W48" → segunda-feira daquela semana ("2026-11-23") */
    function segundaDaSemana(valor) {
        const [ano, semana] = valor.split('-W').map(Number);
        const jan4 = new Date(Date.UTC(ano, 0, 4));
        jan4.setUTCDate(jan4.getUTCDate() - diaDaSemana(jan4) + (semana - 1) * 7);
        return iso(jan4);
    }

    function ultimoDiaDoMes(isoData) {
        const dt = partes(isoData);
        return iso(new Date(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 0)));
    }

    // ---------- setores do coordenador ----------
    function setoresDoUsuario() {
        const lista = (Papeis.usuario() || {}).relatorio_setor;
        return Array.isArray(lista) ? lista : [];
    }

    function escolherSetor(preferido) {
        const lista = setoresDoUsuario();
        const existe = (id) => lista.some(s => Number(s.setor_id) === Number(id));
        let lembrado = null;
        try { lembrado = localStorage.getItem(CHAVE_SETOR); } catch (e) { /* sem armazenamento */ }
        if (preferido && existe(preferido)) return Number(preferido);
        if (lembrado && existe(lembrado)) return Number(lembrado);
        return lista.length ? Number(lista[0].setor_id) : null;
    }

    function nomeDoSetor(id) {
        const s = setoresDoUsuario().find(x => Number(x.setor_id) === Number(id));
        return s ? s.nome : '';
    }

    // ---------- título da tela ----------
    function definirTitulo() {
        const alvo = document.getElementById('registro-atividades-titulo-texto');
        if (alvo) alvo.textContent = `Relatório do setor — ${nomeDoSetor(setorId)}`;
        const sub = document.getElementById('ra-subtitulo');
        if (sub) sub.textContent = 'Informe as atividades realizadas pelo setor no período e envie o relatório.';
    }

    function restaurarTitulo() {
        const alvo = document.getElementById('registro-atividades-titulo-texto');
        if (alvo) alvo.textContent = TITULO_PADRAO;
        const sub = document.getElementById('ra-subtitulo');
        if (sub) sub.textContent = SUBTITULO_PADRAO;
    }

    // ---------- API ----------
    async function buscar(data) {
        const base = `registro-atividades.php?modo=setor&setor_id=${setorId}`;
        try {
            return await Api.get(base + (data ? `&data=${encodeURIComponent(data)}` : ''));
        } catch (e) {
            if (!data || e.status !== 400) throw e;
        }
        // Início do período fora da vigência (período parcial após uma troca): tenta o fim da semana e do mês
        let ultimo;
        for (const alternativa of [somarDias(data, 6), ultimoDiaDoMes(data)]) {
            try {
                return await Api.get(`${base}&data=${encodeURIComponent(alternativa)}`);
            } catch (e) {
                ultimo = e;
                if (e.status !== 400) break;
            }
        }
        throw ultimo;
    }

    // ---------- montagem ----------
    function montarSeletorSetor() {
        const lista = setoresDoUsuario();
        if (lista.length < 2) return '';
        const opcoes = lista.map(s => `<option value="${Number(s.setor_id)}"${Number(s.setor_id) === setorId ? ' selected' : ''}>${sanitizeHTML(s.nome)}</option>`).join('');
        return `<div class="form-group ra-campo-setor">
            <label for="ra-setor">Setor</label>
            <select id="ra-setor" data-acao-mudar="setor">${opcoes}</select>
        </div>`;
    }

    function montarCampoPeriodo(d) {
        const semanal = d.periodo === 'semana';
        const tipo = semanal ? 'week' : 'month';
        const valor = semanal ? valorSemana(d.data) : d.data.slice(0, 7);
        const max = semanal ? valorSemana(d.hoje) : d.hoje.slice(0, 7);
        return `<div class="form-group ra-campo-data">
            <label for="ra-data">${semanal ? 'Semana do relatório' : 'Mês do relatório'}</label>
            <input type="${tipo}" id="ra-data" value="${sanitizeHTML(valor)}" max="${sanitizeHTML(max)}" data-acao-mudar="data">
        </div>`;
    }

    function montarPendencias(pendencias, dataAtual) {
        if (!pendencias.length) return '';
        return `<ul class="ra-pendencias" aria-label="Relatórios do setor pendentes">${pendencias.map(p => {
            const atrasado = p.status === 'atrasado';
            const texto = p.periodo === 'mes'
                ? `Relatório de ${p.data_referencia.slice(5, 7)}/${p.data_referencia.slice(0, 4)}.`
                : `Relatório da semana de ${raDataBr(p.data_referencia)} a ${raDataBr(p.periodo_fim)}.`;
            const abrir = p.data_referencia !== dataAtual
                ? `<button type="button" class="btn-small" data-acao="abrir-dia" data-data="${sanitizeHTML(p.periodo_fim)}">Preencher</button>` : '';
            return `<li class="ra-faixa ${atrasado ? 'ra-faixa--atrasado' : 'ra-faixa--pendente'}">
                <span><strong>${atrasado ? '⚠️ Atrasado' : '🕒 Pendente'}</strong> ${sanitizeHTML(texto)}</span>${abrir}</li>`;
        }).join('')}</ul>`;
    }

    function montarTopo(d) {
        return `<div class="ra-topo-setor">${montarSeletorSetor()}${montarCampoPeriodo(d)}</div>
            ${montarPendencias(d.pendencias, d.data)}`;
    }

    // Só o seletor de setor (quando a data pedida não está em vigor e a API recusou)
    function montarTopoVazio() {
        const seletor = montarSeletorSetor();
        return seletor ? `<div class="ra-topo-setor">${seletor}</div>` : '';
    }

    function descricaoPeriodo(d) {
        if (d.periodo === 'mes') return `${d.data.slice(5, 7)}/${d.data.slice(0, 4)}`;
        return `semana de ${raDataBr(d.data)} a ${raDataBr(d.periodo_fim)}`;
    }

    // ---------- ações ----------
    /** Valor do campo semana/mês → dia que identifica o período (segunda-feira ou dia 1º) */
    function dataDoCampo(campo) {
        return campo.type === 'week' ? segundaDaSemana(campo.value) : `${campo.value}-01`;
    }

    function trocarSetor(id) {
        if (!id || id === setorId) return;
        setorId = id;
        try { localStorage.setItem(CHAVE_SETOR, String(id)); } catch (e) { /* sem armazenamento */ }
        definirTitulo();
        carregarRegistroAtividades();
    }

    function abrir(idSetor, data) {
        setorId = escolherSetor(idSetor);
        if (!setorId) return;
        raModo = 'setor';
        mostrarSecao('registro-atividades');
        definirTitulo();
        carregarRegistroAtividades(data);
    }

    return { abrir, buscar, montarTopo, montarTopoVazio, descricaoPeriodo, dataDoCampo, trocarSetor, restaurarTitulo };
})();
