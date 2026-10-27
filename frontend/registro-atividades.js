/**
 * Registro de Atividades (veio da "Área do Funcionário" do METRICA360, 27/10/2026).
 * O servidor (gestor/operador) informa, por dia, quantas vezes fez cada atividade do seu setor.
 * Rascunho salvo sozinho (cópia local na hora, servidor 30 s depois da última alteração ou ao sair da
 * observação); "Finalizar e enviar" trava o relatório. Avisa as pendências (dias/semanas sem envio).
 * Sem onclick/style inline: ações por data-acao com ouvintes delegados (CSS em registro-atividades.css).
 */
const RA_ESPERA_AUTOSAVE = 30000;
const RA_VALIDADE_LOCAL = 24 * 60 * 60 * 1000;
let raDados = null;
let raSujo = false;
let raTimer = null;
let raRelogio = null;

function mostrarRegistroAtividades(data) {
    mostrarSecao('registro-atividades');
    carregarRegistroAtividades(data);
}

function raChaveLocal() {
    return raDados ? `cd_ra_rascunho_${raDados.registro.id}` : null;
}

function raDataBr(iso) {
    if (!iso) return '';
    const [a, m, d] = String(iso).slice(0, 10).split('-');
    return `${d}/${m}/${a}`;
}

function raHora(dataHora) {
    return dataHora ? String(dataHora).slice(11, 16) : '—';
}

function raDiaSemana(iso, completo) {
    const nomes = completo
        ? ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado']
        : ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    const [a, m, d] = String(iso).slice(0, 10).split('-').map(Number);
    return nomes[new Date(a, m - 1, d).getDay()];
}

async function carregarRegistroAtividades(data) {
    const conteudo = document.getElementById('registro-atividades-conteudo');
    if (!conteudo) return;
    // Rascunho pendente de outro dia: grava antes de trocar
    if (raSujo && raDados && raDados.registro.status === 'rascunho') await salvarRascunhoAtividades(true);
    clearTimeout(raTimer);
    raSujo = false;
    conteudo.innerHTML = '<p class="ra-aviso">Carregando...</p>';
    ligarAcoesRegistroAtividades(conteudo);
    iniciarRelogioRegistro();
    try {
        raDados = await Api.get('registro-atividades.php' + (data ? `?data=${encodeURIComponent(data)}` : ''));
        conteudo.innerHTML = montarRegistroAtividades(raDados);
        aplicarAlturasGraficoRegistro();
        ordenarLinhasRegistro();
        atualizarContadorRegistro();
        verificarRascunhoLocalRegistro();
    } catch (e) {
        conteudo.innerHTML = `<p class="ra-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar o registro de atividades.'))}</p>`;
    }
}

function iniciarRelogioRegistro() {
    const alvo = document.getElementById('ra-relogio');
    if (!alvo) return;
    const atualizar = () => {
        const agora = new Date();
        alvo.textContent = agora.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
            + ' · ' + agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    };
    atualizar();
    clearInterval(raRelogio);
    raRelogio = setInterval(atualizar, 30000);
}

// ---------- montagem ----------
function montarRegistroAtividades(d) {
    const enviado = d.registro.status === 'enviado';
    return `
        <div class="ra-colunas${d.historico.length ? '' : ' ra-colunas--sem-historico'}">
            <div class="ra-principal">
                ${montarPendenciasRegistro(d.pendencias, d.data)}
                <div class="ra-barra-data">
                    <p class="ra-dica">ℹ️ Escolha a data de referência. Você pode preencher qualquer dia anterior.</p>
                    <div class="form-group ra-campo-data">
                        <label for="ra-data">Data do relatório</label>
                        <input type="date" id="ra-data" value="${sanitizeHTML(d.data)}" min="${sanitizeHTML(d.data_minima)}" max="${sanitizeHTML(d.hoje)}" data-acao-mudar="data">
                    </div>
                </div>
                <div class="ra-faixa ra-faixa--restaurar" id="ra-restaurar" hidden role="alert">
                    <span>💾 Encontramos um rascunho salvo neste navegador. Deseja restaurá-lo?</span>
                    <span class="ra-faixa-acoes">
                        <button type="button" class="btn-small" data-acao="restaurar">Restaurar</button>
                        <button type="button" class="btn-small btn-secondary" data-acao="ignorar-local">Ignorar</button>
                    </span>
                </div>
                ${enviado
                    ? `<div class="ra-faixa ra-faixa--enviado" role="status"><strong>✅ Relatório enviado com sucesso!</strong>
                        <span>Enviado às ${sanitizeHTML(raHora(d.registro.enviado_em))}. Os dados estão bloqueados para edição.</span></div>`
                    : `<div class="ra-faixa ra-faixa--rascunho" role="status"><strong>📝 Rascunho em andamento</strong>
                        <span>Preencha as quantidades e clique em <strong>Finalizar e enviar</strong> quando terminar.</span></div>`}
                ${d.atividades.length ? montarFormularioRegistro(d, enviado) : `
                    <div class="ra-cartao ra-vazio">
                        <strong>Nenhuma atividade cadastrada para o seu setor.</strong>
                        <span>Peça ao gestor do setor ou ao administrador que cadastre as atividades em Configurações → Atividades.</span>
                    </div>`}
            </div>
            ${d.historico.length ? montarHistoricoRegistro(d.historico) : ''}
        </div>`;
}

function montarPendenciasRegistro(pendencias, dataAtual) {
    if (!pendencias.length) return '';
    return `<ul class="ra-pendencias" aria-label="Relatórios pendentes">${pendencias.map(p => {
        const atrasado = p.status === 'atrasado';
        const texto = p.data
            ? `${p.tipo === 'entrega' ? 'Relatório do dia' : 'Trabalho remoto de'} ${raDataBr(p.data)}.`
            : `Semana de ${raDataBr(p.semana_ini)} a ${raDataBr(p.semana_fim)}: faltam ${Number(p.faltam)} envio(s).`;
        const abrir = p.data && p.data !== dataAtual
            ? `<button type="button" class="btn-small" data-acao="abrir-dia" data-data="${sanitizeHTML(p.data)}">Preencher</button>` : '';
        return `<li class="ra-faixa ${atrasado ? 'ra-faixa--atrasado' : 'ra-faixa--pendente'}">
            <span><strong>${atrasado ? '⚠️ Atrasado' : '🕒 Pendente'}</strong> ${sanitizeHTML(texto)}</span>${abrir}</li>`;
    }).join('')}</ul>`;
}

function montarFormularioRegistro(d, enviado) {
    const modalidade = d.exige_modalidade ? (enviado
        ? `<p class="ra-modalidade-fixa">Modalidade do dia: <strong>${d.registro.modalidade === 'presencial' ? '🏢 Presencial' : '🏠 Remoto'}</strong></p>`
        : `<fieldset class="ra-modalidade">
            <legend>Como foi o seu trabalho neste dia?</legend>
            ${[['remoto', '🏠 Remoto'], ['presencial', '🏢 Presencial']].map(([v, r]) => `
                <label class="ra-modalidade-opcao"><input type="radio" name="ra-modalidade" value="${v}" data-acao-mudar="modalidade"
                    ${d.modalidade_sugerida === v ? 'checked' : ''} required> ${r}</label>`).join('')}
           </fieldset>`) : '';

    if (enviado) {
        const linhas = d.atividades.map(a => `
            <tr class="${a.quantidade > 0 ? 'ra-preenchida' : ''}">
                <td>${sanitizeHTML(a.nome)}</td>
                <td>${a.quantidade > 0 ? `<span class="ra-selo-qtd">${a.quantidade}</span>` : '<span class="ra-traco">—</span>'}</td>
                <td>${a.observacao ? sanitizeHTML(a.observacao) : '<span class="ra-traco">—</span>'}</td>
            </tr>`).join('');
        return `${modalidade}
            <div class="ra-cartao">
                <h3 class="ra-cartao-titulo">Atividades do dia</h3>
                <div class="ra-tabela-wrap"><table class="ra-tabela">
                    <caption class="sr-only">Atividades registradas em ${raDataBr(d.data)}</caption>
                    <thead><tr><th scope="col">Atividade</th><th scope="col">Quantidade</th><th scope="col">Observação</th></tr></thead>
                    <tbody>${linhas}</tbody>
                </table></div>
            </div>`;
    }

    const linhas = d.atividades.map((a, i) => `
        <tr class="ra-linha${a.quantidade > 0 ? ' ra-preenchida' : ''}" data-tipo="${a.id}" data-ordem="${i}" data-favorito="${a.favorito ? 1 : 0}">
            <th scope="row"><span class="ra-nome">
                <button type="button" class="ra-estrela${a.favorito ? ' ra-estrela--ativa' : ''}" data-acao="favorito" data-id="${a.id}"
                    aria-pressed="${a.favorito}" aria-label="Favorita: ${sanitizeHTML(a.nome)}" title="${a.favorito ? 'Remover dos favoritos' : 'Adicionar aos favoritos'}">★</button>
                <span class="ra-nome-texto">${sanitizeHTML(a.nome)}</span></span>
            </th>
            <td>
                <div class="ra-contador">
                    <button type="button" class="ra-passo" data-acao="menos" aria-label="Diminuir ${sanitizeHTML(a.nome)}">−</button>
                    <input type="number" class="ra-qtd" min="0" max="9999" step="1" inputmode="numeric" value="${Number(a.quantidade)}"
                        aria-label="Quantidade de ${sanitizeHTML(a.nome)}" data-acao-mudar="qtd">
                    <button type="button" class="ra-passo" data-acao="mais" aria-label="Aumentar ${sanitizeHTML(a.nome)}">+</button>
                </div>
            </td>
            <td>
                <button type="button" class="ra-nota${a.observacao ? ' ra-nota--com' : ''}" data-acao="nota" aria-expanded="${a.observacao ? 'true' : 'false'}"
                    aria-controls="ra-obs-${a.id}" aria-label="${a.observacao ? 'Ver ou editar a observação' : 'Adicionar observação'}: ${sanitizeHTML(a.nome)}">${a.observacao ? '✎ Nota' : '+ Nota'}</button>
            </td>
        </tr>
        <tr class="ra-linha-obs" id="ra-obs-${a.id}"${a.observacao ? '' : ' hidden'} data-obs-de="${a.id}">
            <td colspan="3">
                <label class="sr-only" for="ra-obs-campo-${a.id}">Observação para ${sanitizeHTML(a.nome)}</label>
                <input type="text" class="ra-obs" id="ra-obs-campo-${a.id}" maxlength="500" value="${sanitizeHTML(a.observacao)}"
                    placeholder="Observação opcional para esta atividade…" data-acao-mudar="obs">
            </td>
        </tr>`).join('');

    return `${modalidade}
        <div class="ra-cartao">
            <div class="ra-cartao-cabecalho">
                <h3 class="ra-cartao-titulo">Atividades do dia</h3>
                <span class="ra-contagem" aria-live="polite"><span id="ra-preenchidas">0</span> de ${d.atividades.length} preenchidas</span>
                <button type="button" class="btn-small btn-secondary" data-acao="zerar">↺ Zerar tudo</button>
            </div>
            <div class="form-group ra-busca">
                <label for="ra-busca" class="sr-only">Encontrar atividade</label>
                <input type="search" id="ra-busca" placeholder="🔍 Encontrar atividade..." data-acao-mudar="busca">
            </div>
            <div class="ra-tabela-wrap"><table class="ra-tabela">
                <caption class="sr-only">Atividades do dia ${raDataBr(d.data)}: quantidade e observação</caption>
                <thead><tr><th scope="col">Atividade</th><th scope="col">Quantidade</th><th scope="col"><span class="sr-only">Observação</span>Nota</th></tr></thead>
                <tbody id="ra-corpo">${linhas}</tbody>
            </table></div>
            <div class="ra-acoes">
                <p class="ra-autosave" role="status"><span class="ra-ponto" id="ra-ponto"></span> <span id="ra-autosave">Pronto</span></p>
                <div class="ra-acoes-botoes">
                    <button type="button" class="btn-secondary ra-botao" data-acao="salvar">💾 Salvar rascunho</button>
                    <button type="button" class="btn-primary ra-botao" data-acao="finalizar">📤 Finalizar e enviar</button>
                </div>
            </div>
        </div>`;
}

function montarHistoricoRegistro(historico) {
    const ultimos = historico.slice(0, 5).reverse();
    const maximo = Math.max(1, ...ultimos.map(h => h.total_itens));
    const barras = ultimos.map(h => {
        const titulo = `${raDiaSemana(h.data_referencia, true)}, ${raDataBr(h.data_referencia)}: ${h.total_itens} atividade(s), ${h.total_tarefas} tarefa(s)`;
        const altura = Math.max(6, (h.total_itens / maximo) * 100);
        return `<li class="ra-grafico-coluna">
            <span class="ra-grafico-barra${h.total_itens > 0 && h.total_itens === maximo ? ' ra-grafico-barra--max' : ''}" data-altura="${altura.toFixed(1)}"
                title="${sanitizeHTML(titulo)}"></span>
            <span class="ra-grafico-rotulo" aria-hidden="true">${raDiaSemana(h.data_referencia)}<small>${raDataBr(h.data_referencia).slice(0, 5)}</small></span>
            <span class="sr-only">${sanitizeHTML(titulo)}</span>
        </li>`;
    }).join('');
    const itens = historico.map(h => `
        <li class="ra-hist-item">
            <button type="button" class="ra-hist-botao" data-acao="historico" data-id="${h.id}" aria-expanded="false" aria-controls="ra-hist-${h.id}">
                <span class="ra-hist-data">${raDiaSemana(h.data_referencia)}, ${raDataBr(h.data_referencia)}</span>
                <span class="ra-hist-total">Atividades desenvolvidas: ${String(h.total_itens).padStart(2, '0')} • Tarefas realizadas: ${String(h.total_tarefas).padStart(2, '0')}</span>
                <span class="ra-hist-enviado">Enviado ${h.enviado_em ? `${raDataBr(h.enviado_em)} às ${raHora(h.enviado_em)}` : ''}</span>
            </button>
            <div class="ra-hist-detalhes" id="ra-hist-${h.id}" hidden></div>
        </li>`).join('');
    return `
        <aside class="ra-historico ra-cartao" aria-labelledby="ra-hist-titulo">
            <h3 class="ra-cartao-titulo" id="ra-hist-titulo">Histórico de relatórios <small>${historico.length} registro(s)</small></h3>
            <p class="ra-grafico-titulo">Atividades desenvolvidas nos últimos ${ultimos.length} envios</p>
            <ul class="ra-grafico" aria-label="Atividades desenvolvidas nos últimos ${ultimos.length} envios">${barras}</ul>
            <ul class="ra-hist-lista">${itens}</ul>
        </aside>`;
}

// Altura das barras pela variável CSS (sem style= no HTML montado)
function aplicarAlturasGraficoRegistro() {
    document.querySelectorAll('#registro-atividades .ra-grafico-barra').forEach(b => {
        b.style.setProperty('--ra-altura', b.dataset.altura + '%');
    });
}

// Favoritas primeiro, depois as preenchidas, depois as demais (ordem original); divisor "Demais atividades"
function ordenarLinhasRegistro() {
    const corpo = document.getElementById('ra-corpo');
    if (!corpo) return;
    corpo.querySelector('.ra-divisor')?.remove();
    const linhas = [...corpo.querySelectorAll('tr.ra-linha')];
    const qtd = l => Number(l.querySelector('.ra-qtd').value) || 0;
    linhas.sort((a, b) => {
        const fa = a.dataset.favorito === '1', fb = b.dataset.favorito === '1';
        if (fa !== fb) return fa ? -1 : 1;
        const qa = qtd(a), qb = qtd(b);
        if ((qa > 0) === (qb > 0)) return Number(a.dataset.ordem) - Number(b.dataset.ordem);
        return qb - qa;
    });
    const favoritas = linhas.filter(l => l.dataset.favorito === '1').length;
    linhas.forEach((linha, i) => {
        if (i === favoritas && favoritas > 0 && favoritas < linhas.length) {
            const divisor = document.createElement('tr');
            divisor.className = 'ra-divisor';
            divisor.innerHTML = '<td colspan="3">Demais atividades</td>';
            corpo.appendChild(divisor);
        }
        corpo.appendChild(linha);
        const obs = corpo.querySelector(`tr[data-obs-de="${linha.dataset.tipo}"]`);
        if (obs) corpo.appendChild(obs);
    });
}

function coletarItensRegistro() {
    return [...document.querySelectorAll('#ra-corpo tr.ra-linha')].map(l => ({
        atividade_tipo_id: Number(l.dataset.tipo),
        quantidade: Math.max(0, Math.min(9999, Number(l.querySelector('.ra-qtd').value) || 0)),
        observacao: document.getElementById(`ra-obs-campo-${l.dataset.tipo}`)?.value || '',
    }));
}

function modalidadeEscolhidaRegistro() {
    return document.querySelector('input[name="ra-modalidade"]:checked')?.value || null;
}

function atualizarContadorRegistro() {
    const linhas = [...document.querySelectorAll('#ra-corpo tr.ra-linha')];
    linhas.forEach(l => l.classList.toggle('ra-preenchida', (Number(l.querySelector('.ra-qtd').value) || 0) > 0));
    const alvo = document.getElementById('ra-preenchidas');
    if (alvo) alvo.textContent = linhas.filter(l => l.classList.contains('ra-preenchida')).length;
}

function statusAutosave(texto, estado) {
    const t = document.getElementById('ra-autosave');
    const p = document.getElementById('ra-ponto');
    if (t) t.textContent = texto;
    if (p) p.className = 'ra-ponto' + (estado ? ` ra-ponto--${estado}` : '');
}

// ---------- rascunho ----------
function salvarLocalRegistro() {
    if (!raDados || raDados.registro.status !== 'rascunho') return;
    try {
        localStorage.setItem(raChaveLocal(), JSON.stringify({ ts: Date.now(), itens: coletarItensRegistro(), modalidade: modalidadeEscolhidaRegistro() }));
    } catch (e) { /* navegador sem armazenamento: segue só com o servidor */ }
}

function marcarAlteracaoRegistro() {
    raSujo = true;
    salvarLocalRegistro();
    clearTimeout(raTimer);
    raTimer = setTimeout(() => salvarRascunhoAtividades(true), RA_ESPERA_AUTOSAVE);
    statusAutosave('Alterações não salvas', 'pendente');
}

async function salvarRascunhoAtividades(silencioso) {
    if (!raDados || raDados.registro.status !== 'rascunho') return;
    clearTimeout(raTimer);
    const botao = document.querySelector('#registro-atividades [data-acao="salvar"]');
    if (botao) botao.disabled = true;
    statusAutosave('Salvando…', 'salvando');
    salvarLocalRegistro();
    const id = raDados.registro.id;
    try {
        await Api.put(`registro-atividades.php?id=${id}`, { itens: coletarItensRegistro(), modalidade: modalidadeEscolhidaRegistro() });
        raSujo = false;
        statusAutosave('Salvo às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }), 'salvo');
        try { localStorage.removeItem(`cd_ra_rascunho_${id}`); } catch (e) { /* sem armazenamento */ }
        if (!silencioso) mostrarToast('Sucesso', 'Rascunho salvo!', 'success');
    } catch (e) {
        if (e && !e.status) {
            statusAutosave('Salvo localmente', 'falha');
            if (!silencioso) mostrarToast('Atenção', 'Sem conexão. O rascunho ficou salvo neste navegador.', 'warning');
        } else {
            statusAutosave('Falha ao salvar', 'falha');
            if (!silencioso) mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao salvar o rascunho.'));
        }
    } finally {
        if (botao) botao.disabled = false;
    }
}

function verificarRascunhoLocalRegistro() {
    if (!raDados || raDados.registro.status !== 'rascunho') return;
    try {
        const salvo = JSON.parse(localStorage.getItem(raChaveLocal()) || 'null');
        if (!salvo) return;
        if (Date.now() - salvo.ts > RA_VALIDADE_LOCAL || !(salvo.itens || []).some(i => Number(i.quantidade) > 0)) {
            localStorage.removeItem(raChaveLocal());
            return;
        }
        document.getElementById('ra-restaurar').hidden = false;
    } catch (e) { /* armazenamento indisponível ou cópia corrompida */ }
}

function restaurarRascunhoLocal() {
    try {
        const salvo = JSON.parse(localStorage.getItem(raChaveLocal()) || 'null');
        if (salvo) {
            (salvo.itens || []).forEach(i => {
                const linha = document.querySelector(`#ra-corpo tr.ra-linha[data-tipo="${Number(i.atividade_tipo_id)}"]`);
                if (!linha) return;
                linha.querySelector('.ra-qtd').value = Math.max(0, Math.min(9999, Number(i.quantidade) || 0));
                const obs = document.getElementById(`ra-obs-campo-${Number(i.atividade_tipo_id)}`);
                if (obs) {
                    obs.value = i.observacao || '';
                    atualizarBotaoNota(obs);
                }
            });
            if (salvo.modalidade) {
                const opcao = document.querySelector(`input[name="ra-modalidade"][value="${salvo.modalidade === 'presencial' ? 'presencial' : 'remoto'}"]`);
                if (opcao) opcao.checked = true;
            }
        }
        localStorage.removeItem(raChaveLocal());
    } catch (e) { /* armazenamento indisponível */ }
    document.getElementById('ra-restaurar').hidden = true;
    atualizarContadorRegistro();
    ordenarLinhasRegistro();
    marcarAlteracaoRegistro();
    mostrarToast('Sucesso', 'Rascunho restaurado!', 'success');
}

function atualizarBotaoNota(campo) {
    const linha = document.querySelector(`#ra-corpo tr.ra-linha[data-tipo="${campo.closest('tr').dataset.obsDe}"]`);
    const botao = linha?.querySelector('.ra-nota');
    if (!botao) return;
    const tem = campo.value.trim() !== '';
    botao.classList.toggle('ra-nota--com', tem);
    botao.textContent = tem ? '✎ Nota' : '+ Nota';
}

// ---------- envio ----------
function finalizarRegistroAtividades() {
    if (raDados.exige_modalidade && !modalidadeEscolhidaRegistro()) {
        mostrarAlert('Atenção', 'Informe se o trabalho do dia foi remoto ou presencial.', () => {
            document.querySelector('input[name="ra-modalidade"]')?.focus();
        });
        return;
    }
    const itens = coletarItensRegistro();
    const preenchidas = itens.filter(i => i.quantidade > 0).length;
    const total = itens.reduce((s, i) => s + i.quantidade, 0);
    mostrarConfirm('Confirmar envio do relatório',
        `Data do relatório: ${raDataBr(raDados.data)}\nAtividades preenchidas: ${preenchidas} de ${itens.length}\nTotal de ocorrências: ${total}\n\n`
        + 'Depois de enviado, o relatório fica bloqueado para edição. Confira os dados antes de confirmar.',
        async (ok) => {
            document.getElementById('modal-confirm-message')?.classList.remove('ra-quebra-linhas');
            if (!ok) return;
            const id = raDados.registro.id;
            const botao = document.querySelector('#registro-atividades [data-acao="finalizar"]');
            if (botao) botao.disabled = true;
            try {
                clearTimeout(raTimer);
                await Api.post(`registro-atividades.php?acao=finalizar&id=${id}`, { itens, modalidade: modalidadeEscolhidaRegistro() });
                raSujo = false;
                try { localStorage.removeItem(`cd_ra_rascunho_${id}`); } catch (e) { /* sem armazenamento */ }
                mostrarToast('Sucesso', 'Relatório enviado!', 'success');
                await carregarRegistroAtividades(raDados.data);
            } catch (e) {
                if (botao) botao.disabled = false;
                mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao enviar o relatório.'));
            }
        });
    document.getElementById('modal-confirm-message')?.classList.add('ra-quebra-linhas');
}

function zerarRegistroAtividades() {
    const zerar = () => {
        document.querySelectorAll('#ra-corpo .ra-qtd').forEach(c => { c.value = 0; });
        atualizarContadorRegistro();
        marcarAlteracaoRegistro();
        mostrarToast('Aviso', 'Quantidades zeradas.', 'warning');
    };
    const algum = [...document.querySelectorAll('#ra-corpo .ra-qtd')].some(c => Number(c.value) > 0);
    if (!algum) return zerar();
    mostrarConfirm('Zerar todas as quantidades?',
        'Isso zera todas as quantidades preenchidas neste formulário. As observações não são apagadas.',
        ok => { if (ok) zerar(); }, true);
}

async function alternarFavoritoRegistro(botao) {
    try {
        const r = await Api.post('registro-atividades.php?acao=favorito', { atividade_tipo_id: Number(botao.dataset.id) });
        const linha = botao.closest('tr');
        linha.dataset.favorito = r.favorito ? '1' : '0';
        botao.classList.toggle('ra-estrela--ativa', r.favorito);
        botao.setAttribute('aria-pressed', String(r.favorito));
        botao.title = r.favorito ? 'Remover dos favoritos' : 'Adicionar aos favoritos';
        ordenarLinhasRegistro();
        botao.focus();
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Não foi possível atualizar o favorito.'));
    }
}

async function abrirHistoricoRegistro(botao) {
    const painel = document.getElementById(`ra-hist-${botao.dataset.id}`);
    const abrir = botao.getAttribute('aria-expanded') !== 'true';
    botao.setAttribute('aria-expanded', String(abrir));
    painel.hidden = !abrir;
    if (!abrir || painel.dataset.carregado) return;
    painel.innerHTML = '<p class="ra-aviso">Carregando…</p>';
    try {
        const { itens } = await Api.get(`registro-atividades.php?acao=itens&id=${Number(botao.dataset.id)}`);
        painel.dataset.carregado = '1';
        painel.innerHTML = itens.length
            ? `<ul class="ra-hist-itens">${itens.map(i => `<li><span>${sanitizeHTML(i.atividade_nome)}</span><strong>${Number(i.quantidade)}</strong>
                ${i.observacao ? `<small>${sanitizeHTML(i.observacao)}</small>` : ''}</li>`).join('')}</ul>`
            : '<p class="ra-aviso">Nenhuma atividade com quantidade neste dia.</p>';
    } catch (e) {
        painel.innerHTML = `<p class="ra-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar os itens.'))}</p>`;
    }
}

// ---------- ouvintes (uma vez) ----------
function ligarAcoesRegistroAtividades(conteudo) {
    if (conteudo.dataset.raLigado) return;
    conteudo.dataset.raLigado = '1';

    conteudo.addEventListener('click', (ev) => {
        const alvo = ev.target.closest('[data-acao]');
        if (!alvo) return;
        const acao = alvo.dataset.acao;
        if (acao === 'menos' || acao === 'mais') {
            const campo = alvo.closest('.ra-contador').querySelector('.ra-qtd');
            const valor = (Number(campo.value) || 0) + (acao === 'mais' ? 1 : -1);
            campo.value = Math.max(0, Math.min(9999, valor));
            atualizarContadorRegistro();
            marcarAlteracaoRegistro();
        } else if (acao === 'nota') {
            const linhaObs = document.getElementById(alvo.getAttribute('aria-controls'));
            const abrir = linhaObs.hidden;
            linhaObs.hidden = !abrir;
            alvo.setAttribute('aria-expanded', String(abrir));
            if (abrir) linhaObs.querySelector('input').focus();
        } else if (acao === 'favorito') alternarFavoritoRegistro(alvo);
        else if (acao === 'zerar') zerarRegistroAtividades();
        else if (acao === 'salvar') salvarRascunhoAtividades(false);
        else if (acao === 'finalizar') finalizarRegistroAtividades();
        else if (acao === 'restaurar') restaurarRascunhoLocal();
        else if (acao === 'ignorar-local') {
            try { localStorage.removeItem(raChaveLocal()); } catch (e) { /* sem armazenamento */ }
            document.getElementById('ra-restaurar').hidden = true;
        } else if (acao === 'abrir-dia') carregarRegistroAtividades(alvo.dataset.data);
        else if (acao === 'historico') abrirHistoricoRegistro(alvo);
    });

    conteudo.addEventListener('input', (ev) => {
        const tipo = ev.target.dataset.acaoMudar;
        if (tipo === 'qtd') {
            atualizarContadorRegistro();
            marcarAlteracaoRegistro();
        } else if (tipo === 'obs') {
            atualizarBotaoNota(ev.target);
            marcarAlteracaoRegistro();
        } else if (tipo === 'busca') {
            const termo = ev.target.value.trim().toLocaleLowerCase('pt-BR');
            document.querySelectorAll('#ra-corpo tr.ra-linha').forEach(l => {
                const some = termo !== '' && !l.querySelector('.ra-nome-texto').textContent.toLocaleLowerCase('pt-BR').includes(termo);
                l.hidden = some;
                const obs = document.querySelector(`#ra-corpo tr[data-obs-de="${l.dataset.tipo}"]`);
                if (obs && some) obs.hidden = true;
            });
            const divisor = document.querySelector('#ra-corpo .ra-divisor');
            if (divisor) divisor.hidden = termo !== '';
        }
    });

    conteudo.addEventListener('change', (ev) => {
        const tipo = ev.target.dataset.acaoMudar;
        if (tipo === 'modalidade') marcarAlteracaoRegistro();
        else if (tipo === 'data' && ev.target.value && ev.target.checkValidity()) carregarRegistroAtividades(ev.target.value);
        else if (tipo === 'qtd') {
            ev.target.value = Math.max(0, Math.min(9999, Number(ev.target.value) || 0));
            atualizarContadorRegistro();
        }
    });

    // Observação: ao sair do campo, grava logo (sem esperar os 30 s)
    conteudo.addEventListener('focusout', (ev) => {
        if (ev.target.dataset.acaoMudar === 'obs' && raSujo) salvarRascunhoAtividades(true);
    });
}

// Alteração não gravada no servidor: avisa antes de fechar/recarregar a página
window.addEventListener('beforeunload', (ev) => {
    if (raSujo && raDados && raDados.registro.status === 'rascunho') {
        salvarLocalRegistro();
        ev.preventDefault();
        ev.returnValue = '';
    }
});

// Item do menu (sem onclick no HTML)
document.addEventListener('click', (ev) => {
    if (ev.target.closest('#btn-nav-registro-atividades')) mostrarRegistroAtividades();
});
