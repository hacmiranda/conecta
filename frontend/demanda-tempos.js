/**
 * Tempo trabalhado na demanda (17/11/2026): seção "⏱️ Tempo trabalhado" do detalhe (#tempos-demanda).
 * Lista os lançamentos, o total e o total por pessoa; formulário para lançar (data + início e fim, ou só a
 * duração, e uma descrição opcional); editar/excluir os próprios (a gestão do setor e o admin, todos).
 * API: demanda-tempos.php. Sem onclick/style inline: ações por data-tempo com ouvintes delegados.
 * CSS em demanda-tempos.css.
 */
let temposTicketId = null;
let temposLista = [];
let temposEditando = null;   // id do lançamento em edição (null = novo)

function temposHoras(minutos) {
    const m = Math.max(0, Number(minutos) || 0);
    const h = Math.floor(m / 60);
    const r = m % 60;
    return h ? `${h}h${r ? String(r).padStart(2, '0') : ''}` : `${r} min`;
}

function temposHoje() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

async function carregarTemposDemanda(ticketId) {
    temposTicketId = Number(ticketId);
    temposEditando = null;
    const caixa = document.getElementById('tempos-demanda');
    if (!caixa) return;
    try {
        desenharTemposDemanda(await Api.get(`demanda-tempos.php?ticket_id=${temposTicketId}`));
    } catch (e) {
        caixa.innerHTML = `<h3 id="tempos-demanda-titulo" class="tempos-titulo">⏱️ Tempo trabalhado</h3>
            <p class="tempos-vazio" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Não foi possível carregar o tempo trabalhado.'))}</p>`;
    }
}

function desenharTemposDemanda(d) {
    const caixa = document.getElementById('tempos-demanda');
    if (!caixa) return;
    temposLista = d.itens || [];
    const resumo = d.total_minutos > 0
        ? `<p class="tempos-total"><strong>Total: ${temposHoras(d.total_minutos)}</strong>${(d.por_pessoa || []).length > 1
            ? ' — ' + d.por_pessoa.map(p => `${sanitizeHTML(p.nome)}: ${temposHoras(p.minutos)}`).join(' · ') : ''}</p>`
        : '';
    const linhas = temposLista.map(t => `<tr>
        <td data-label="Data">${sanitizeHTML(t.data_trabalho.split('-').reverse().join('/'))}</td>
        <td data-label="Horário">${t.hora_inicio ? `${sanitizeHTML(t.hora_inicio)}–${sanitizeHTML(t.hora_fim)}` : '—'}</td>
        <td data-label="Duração"><strong>${temposHoras(t.minutos)}</strong></td>
        <td data-label="Quem">${sanitizeHTML(t.usuario_nome)}</td>
        <td data-label="Descrição">${t.descricao ? sanitizeHTML(t.descricao) : '<span class="tempos-sem">—</span>'}</td>
        <td class="tempos-acoes">${t.pode_editar ? `
            <button type="button" class="tempos-botao-icone" data-tempo="editar" data-id="${t.id}" aria-label="Editar lançamento de ${sanitizeHTML(t.data_trabalho.split('-').reverse().join('/'))}" title="Editar">✏️</button>
            <button type="button" class="tempos-botao-icone" data-tempo="excluir" data-id="${t.id}" aria-label="Excluir lançamento de ${sanitizeHTML(t.data_trabalho.split('-').reverse().join('/'))}" title="Excluir">🗑️</button>` : ''}</td>
    </tr>`).join('');

    caixa.innerHTML = `
        <h3 id="tempos-demanda-titulo" class="tempos-titulo">⏱️ Tempo trabalhado</h3>
        ${resumo}
        ${temposLista.length ? `<div class="tempos-rolagem"><table class="tempos-tabela">
            <caption class="sr-only">Lançamentos de tempo trabalhado nesta demanda</caption>
            <thead><tr><th scope="col">Data</th><th scope="col">Horário</th><th scope="col">Duração</th><th scope="col">Quem</th><th scope="col">Descrição</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>${linhas}</tbody></table></div>`
            : '<p class="tempos-vazio">Nenhum tempo lançado nesta demanda.</p>'}
        ${d.pode_lancar ? temposFormulario() : ''}`;
}

function temposFormulario() {
    return `<form class="tempos-form" id="tempos-form" data-tempo-form novalidate>
        <fieldset>
            <legend id="tempos-form-legenda">Lançar tempo trabalhado</legend>
            <div class="tempos-campos">
                <div class="tempos-campo">
                    <label for="tempos-data">Data</label>
                    <input type="date" id="tempos-data" name="data_trabalho" value="${temposHoje()}" max="${temposHoje()}" required>
                </div>
                <div class="tempos-campo tempos-modo" role="radiogroup" aria-label="Como informar">
                    <span class="tempos-rotulo" aria-hidden="true">Informar</span>
                    <label class="tempos-opcao"><input type="radio" name="modo" value="horario" checked data-tempo="modo"> Início e fim</label>
                    <label class="tempos-opcao"><input type="radio" name="modo" value="duracao" data-tempo="modo"> Só a duração</label>
                </div>
                <div class="tempos-campo" data-grupo="horario">
                    <label for="tempos-inicio">Início</label>
                    <input type="time" id="tempos-inicio" name="hora_inicio">
                </div>
                <div class="tempos-campo" data-grupo="horario">
                    <label for="tempos-fim">Fim</label>
                    <input type="time" id="tempos-fim" name="hora_fim">
                </div>
                <div class="tempos-campo" data-grupo="duracao" hidden>
                    <label for="tempos-duracao">Duração (h:mm)</label>
                    <input type="text" id="tempos-duracao" name="duracao" inputmode="numeric" placeholder="1:30" pattern="\\d{1,2}:[0-5]\\d" maxlength="5">
                </div>
                <div class="tempos-campo tempos-descricao">
                    <label for="tempos-descricao">Descrição (opcional)</label>
                    <input type="text" id="tempos-descricao" name="descricao" maxlength="255" placeholder="O que foi feito">
                </div>
            </div>
            <p class="tempos-erro" id="tempos-erro" role="alert" aria-live="polite" hidden></p>
            <div class="tempos-botoes">
                <button type="submit" class="btn-primary" id="tempos-enviar">Lançar</button>
                <button type="button" class="btn-secondary" data-tempo="cancelar" hidden>Cancelar edição</button>
            </div>
        </fieldset>
    </form>`;
}

function temposAlternarModo(modo) {
    const form = document.getElementById('tempos-form');
    if (!form) return;
    form.querySelectorAll('[data-grupo]').forEach(g => { g.hidden = g.dataset.grupo !== modo; });
    form.querySelector(`input[name="modo"][value="${modo}"]`).checked = true;
}

function temposEditar(id) {
    const t = temposLista.find(x => Number(x.id) === Number(id));
    const form = document.getElementById('tempos-form');
    if (!t || !form) return;
    temposEditando = t.id;
    form.elements.data_trabalho.value = t.data_trabalho;
    form.elements.descricao.value = t.descricao || '';
    if (t.hora_inicio) {
        temposAlternarModo('horario');
        form.elements.hora_inicio.value = t.hora_inicio;
        form.elements.hora_fim.value = t.hora_fim;
    } else {
        temposAlternarModo('duracao');
        form.elements.duracao.value = `${Math.floor(t.minutos / 60)}:${String(t.minutos % 60).padStart(2, '0')}`;
    }
    document.getElementById('tempos-form-legenda').textContent = 'Corrigir lançamento';
    document.getElementById('tempos-enviar').textContent = 'Salvar correção';
    form.querySelector('[data-tempo="cancelar"]').hidden = false;
    form.elements.data_trabalho.focus();
}

async function temposEnviar(form) {
    const erro = document.getElementById('tempos-erro');
    const modo = form.querySelector('input[name="modo"]:checked').value;
    const corpo = { ticket_id: temposTicketId, data_trabalho: form.elements.data_trabalho.value, descricao: form.elements.descricao.value };
    if (modo === 'horario') {
        corpo.hora_inicio = form.elements.hora_inicio.value;
        corpo.hora_fim = form.elements.hora_fim.value;
    } else {
        corpo.duracao = form.elements.duracao.value.trim();
    }
    const botao = document.getElementById('tempos-enviar');
    botao.disabled = true;
    erro.hidden = true;
    try {
        const d = temposEditando
            ? await Api.put(`demanda-tempos.php?id=${temposEditando}`, corpo)
            : await Api.post('demanda-tempos.php', corpo);
        mostrarToast('Sucesso', temposEditando ? 'Lançamento corrigido.' : 'Tempo lançado.', 'success');
        temposEditando = null;
        desenharTemposDemanda(d);
    } catch (e) {
        erro.textContent = mensagemDeErro(e, 'Não foi possível salvar o tempo.');
        erro.hidden = false;
        botao.disabled = false;
    }
}

function temposExcluir(id) {
    mostrarConfirm('Excluir lançamento', 'Excluir este lançamento de tempo?', async (ok) => {
        if (!ok) return;
        try {
            desenharTemposDemanda(await Api.delete(`demanda-tempos.php?id=${id}`));
            mostrarToast('Sucesso', 'Lançamento excluído.', 'success');
        } catch (e) {
            mostrarAlert('Erro', mensagemDeErro(e, 'Não foi possível excluir o lançamento.'));
        }
    }, true);
}

document.addEventListener('click', (ev) => {
    const alvo = ev.target.closest('#tempos-demanda [data-tempo]');
    if (!alvo || alvo.tagName === 'INPUT') return;
    const acao = alvo.dataset.tempo;
    if (acao === 'editar') temposEditar(alvo.dataset.id);
    else if (acao === 'excluir') temposExcluir(alvo.dataset.id);
    else if (acao === 'cancelar') carregarTemposDemanda(temposTicketId);
});

document.addEventListener('change', (ev) => {
    const radio = ev.target.closest('#tempos-demanda input[data-tempo="modo"]');
    if (radio) temposAlternarModo(radio.value);
});

document.addEventListener('submit', (ev) => {
    const form = ev.target.closest('#tempos-demanda [data-tempo-form]');
    if (!form) return;
    ev.preventDefault();
    temposEnviar(form);
});
