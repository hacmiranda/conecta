/**
 * Configurações → Atividades (veio do METRICA360, 27/10/2026): atividades que cada setor registra no
 * "Registro de Atividades" e as regras de prazo (trabalho remoto e dias de entrega de relatório).
 * Admin: uma aba por setor ativo. Gestor: só o próprio setor. Operador não vê.
 * Sem onclick/style inline: ações por data-acao com ouvintes delegados (CSS em atividades-config.css).
 */
const DIAS_SEMANA_ATIV = [[1, 'Seg'], [2, 'Ter'], [3, 'Qua'], [4, 'Qui'], [5, 'Sex'], [6, 'Sáb'], [7, 'Dom']];
let ativCfgSetorAtual = null;
let ativCfgSetores = [];
let ativCfgDados = null;

function mostrarAtividadesConfig() {
    localStorage.setItem('cd_subSecaoAtiva', 'atividades');
    const titulo = document.getElementById('config-title');
    if (titulo) titulo.textContent = '📝 Atividades';
    if (!document.getElementById('config-content')) {
        setTimeout(mostrarAtividadesConfig, 200);
        return;
    }
    carregarPaginaAtividadesConfig();
}

async function carregarPaginaAtividadesConfig() {
    const content = document.getElementById('config-content');
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuario.tipo === 'admin';
    content.innerHTML = '<p class="ativcfg-aviso">Carregando...</p>';
    try {
        if (ehAdmin) {
            ativCfgSetores = (await Api.get('setores.php')).filter(s => Number(s.ativo) === 1);
            const salvo = localStorage.getItem('cd_atividadesConfigSetor');
            ativCfgSetorAtual = ativCfgSetores.some(s => String(s.id) === salvo) ? salvo : (ativCfgSetores[0] ? String(ativCfgSetores[0].id) : '');
        } else {
            ativCfgSetores = [];
            ativCfgSetorAtual = String(usuario.setor_id || '');
        }
        if (!ativCfgSetorAtual) {
            content.innerHTML = '<p class="ativcfg-aviso">Você não está vinculado a um setor.</p>';
            return;
        }
        const abas = ehAdmin ? `
            <div class="abas-usuarios ativcfg-abas" role="tablist" aria-label="Setores">
                ${ativCfgSetores.map(s => `
                    <button type="button" role="tab" class="aba-usuarios-btn" id="aba-ativcfg-${Number(s.id)}" data-acao="aba"
                        data-setor="${Number(s.id)}" aria-controls="painel-ativcfg" aria-selected="${String(s.id) === ativCfgSetorAtual}"
                        tabindex="${String(s.id) === ativCfgSetorAtual ? '0' : '-1'}">${sanitizeHTML(s.nome)}</button>`).join('')}
            </div>` : '';
        content.innerHTML = `
            <p class="ativcfg-intro">Cadastre as atividades que o setor registra no <strong>Registro de Atividades</strong> e
                as regras de prazo: quem trabalha remoto (cota semanal ou dias fixos) e os dias de entrega de relatório.
                Quem não envia no prazo aparece como pendente no <strong>Painel de Atividades</strong>.</p>
            ${abas}
            <div id="painel-ativcfg" role="${ehAdmin ? 'tabpanel' : 'region'}" aria-label="Configuração de atividades"></div>`;
        ligarAcoesAtividadesConfig(content);
        await carregarAtividadesDoSetorCfg();
    } catch (e) {
        content.innerHTML = `<p class="ativcfg-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar as atividades.'))}</p>`;
    }
}

async function carregarAtividadesDoSetorCfg() {
    const painel = document.getElementById('painel-ativcfg');
    if (!painel) return;
    painel.innerHTML = '<p class="ativcfg-aviso">Carregando...</p>';
    try {
        ativCfgDados = await Api.get(`atividades-config.php?setor_id=${encodeURIComponent(ativCfgSetorAtual)}`);
        painel.innerHTML = montarTiposCfg(ativCfgDados.tipos) + montarRegrasSetorCfg(ativCfgDados.setor) + montarUsuariosCfg(ativCfgDados.usuarios, ativCfgDados.setor);
    } catch (e) {
        painel.innerHTML = `<p class="ativcfg-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar as atividades.'))}</p>`;
    }
}

function caixasDiasCfg(nome, marcados, rotulo) {
    const set = new Set((marcados || []).map(Number));
    return `<fieldset class="ativcfg-dias"><legend class="sr-only">${sanitizeHTML(rotulo)}</legend>
        ${DIAS_SEMANA_ATIV.map(([n, r]) => `<label class="ativcfg-dia"><input type="checkbox" name="${nome}" value="${n}"${set.has(n) ? ' checked' : ''}> ${r}</label>`).join('')}
    </fieldset>`;
}

function montarTiposCfg(tipos) {
    const linhas = tipos.map(t => `
        <tr class="${t.ativo ? '' : 'ativcfg-inativo'}">
            <td><label class="sr-only" for="ordem-tipo-${t.id}">Ordem de ${sanitizeHTML(t.nome)}</label>
                <input type="number" min="0" max="32000" class="ativcfg-ordem" id="ordem-tipo-${t.id}" value="${Number(t.ordem)}"
                    data-acao="ordem" data-id="${t.id}"></td>
            <td>${sanitizeHTML(t.nome)}</td>
            <td><label class="switch"><input type="checkbox" role="switch" data-acao="ativar-tipo" data-id="${t.id}"
                aria-label="Atividade ${sanitizeHTML(t.nome)} ativa" ${t.ativo ? 'checked' : ''}><span class="slider"></span></label></td>
            <td class="ativcfg-acoes">
                <button type="button" class="btn-small" data-acao="renomear" data-id="${t.id}" data-nome="${sanitizeHTML(t.nome)}"
                    aria-label="Renomear ${sanitizeHTML(t.nome)}">✏️ Renomear</button>
                ${t.em_uso
                    ? '<span class="ativcfg-dica">Já registrada: só desativar</span>'
                    : `<button type="button" class="btn-small btn-perigo" data-acao="excluir-tipo" data-id="${t.id}" data-nome="${sanitizeHTML(t.nome)}"
                        aria-label="Excluir ${sanitizeHTML(t.nome)}">🗑️ Excluir</button>`}
            </td>
        </tr>`).join('');
    return `
        <h3 class="ativcfg-titulo">Atividades do setor</h3>
        <form class="ativcfg-form" data-acao-form="criar-tipos" autocomplete="off">
            <div class="form-group ativcfg-cresce">
                <label for="ativcfg-nomes">Novas atividades (uma por linha)</label>
                <textarea id="ativcfg-nomes" rows="3" maxlength="5000" placeholder="Ex.: Atendimento presencial&#10;Resposta de e-mail&#10;Análise de processo"></textarea>
            </div>
            <button type="submit" class="btn-primary">➕ Adicionar</button>
        </form>
        ${tipos.length ? `
        <div class="ativcfg-tabela-wrap"><table class="ativcfg-tabela">
            <caption class="sr-only">Atividades cadastradas no setor</caption>
            <thead><tr><th scope="col">Ordem</th><th scope="col">Atividade</th><th scope="col">Ativa</th><th scope="col">Ações</th></tr></thead>
            <tbody>${linhas}</tbody>
        </table></div>` : '<p class="ativcfg-aviso">Nenhuma atividade cadastrada. O Registro de Atividades do setor fica vazio até haver alguma.</p>'}`;
}

function montarRegrasSetorCfg(s) {
    return `
        <h3 class="ativcfg-titulo">Regras de prazo do setor</h3>
        <form class="ativcfg-form ativcfg-regras" data-acao-form="setor" autocomplete="off">
            <fieldset class="ativcfg-grupo">
                <legend>Controle do trabalho remoto</legend>
                <label class="ativcfg-opcao"><input type="radio" name="ativcfg-modo" value="semanal"${s.modo !== 'diario' ? ' checked' : ''}>
                    Semanal: cada servidor envia N relatórios remotos por semana</label>
                <label class="ativcfg-opcao"><input type="radio" name="ativcfg-modo" value="diario"${s.modo === 'diario' ? ' checked' : ''}>
                    Diário: cada servidor tem dias fixos de trabalho remoto</label>
            </fieldset>
            <div class="form-group">
                <label for="ativcfg-prazo">Prazo para enviar (dias úteis depois do dia)</label>
                <input type="number" id="ativcfg-prazo" min="0" max="15" value="${Number(s.prazo)}">
            </div>
            <div class="form-group">
                <label for="ativcfg-base">Cobrar a partir de (opcional)</label>
                <input type="date" id="ativcfg-base" value="${sanitizeHTML(s.data_base || '')}">
            </div>
            <fieldset class="ativcfg-grupo ativcfg-cresce">
                <legend>Entrega de relatório (todos do setor, remotos ou não)</legend>
                <label class="ativcfg-opcao"><input type="checkbox" id="ativcfg-entrega-ativa"${s.entrega_ativa ? ' checked' : ''}> Cobrar entrega nos dias:</label>
                ${caixasDiasCfg('ativcfg-entrega-dias', s.entrega_dias, 'Dias de entrega do setor')}
                <div class="form-group">
                    <label for="ativcfg-entrega-inicio">A partir de</label>
                    <input type="date" id="ativcfg-entrega-inicio" value="${sanitizeHTML(s.entrega_inicio || '')}">
                </div>
            </fieldset>
            <button type="submit" class="btn-primary">💾 Salvar regras do setor</button>
        </form>`;
}

function montarUsuariosCfg(usuarios, setor) {
    if (!usuarios.length) {
        return '<h3 class="ativcfg-titulo">Regras por servidor</h3><p class="ativcfg-aviso">Nenhum gestor ou operador ativo neste setor.</p>';
    }
    const diario = setor.modo === 'diario';
    const linhas = usuarios.map(u => `
        <tr data-usuario="${u.id}">
            <th scope="row">${sanitizeHTML(u.nome)}<br><small>${u.tipo === 'gestor' ? 'Gestor' : 'Operador'}</small></th>
            <td><label class="switch"><input type="checkbox" role="switch" name="remoto" aria-label="${sanitizeHTML(u.nome)} faz trabalho remoto"
                ${u.trabalho_remoto ? 'checked' : ''}><span class="slider"></span></label></td>
            <td>${diario
                ? caixasDiasCfg('dias-remotos', u.dias_remotos, `Dias remotos de ${u.nome}`)
                : `<label class="sr-only" for="qtd-${u.id}">Relatórios remotos por semana de ${sanitizeHTML(u.nome)}</label>
                   <input type="number" id="qtd-${u.id}" name="qtd" min="0" max="7" class="ativcfg-ordem" value="${u.qtd_semana ?? ''}" placeholder="0">`}</td>
            <td>
                <label class="sr-only" for="entrega-${u.id}">Entrega de relatório de ${sanitizeHTML(u.nome)}</label>
                <select id="entrega-${u.id}" name="entrega-modo">
                    <option value="seguir"${u.entrega_modo === 'seguir' ? ' selected' : ''}>Segue o setor</option>
                    <option value="dispensado"${u.entrega_modo === 'dispensado' ? ' selected' : ''}>Dispensado</option>
                    <option value="personalizado"${u.entrega_modo === 'personalizado' ? ' selected' : ''}>Dias próprios</option>
                </select>
                <div class="ativcfg-dias-proprios"${u.entrega_modo === 'personalizado' ? '' : ' hidden'}>
                    ${caixasDiasCfg('entrega-dias', u.entrega_dias, `Dias de entrega de ${u.nome}`)}
                </div>
            </td>
            <td><button type="button" class="btn-small" data-acao="salvar-usuario" data-id="${u.id}" aria-label="Salvar regras de ${sanitizeHTML(u.nome)}">💾 Salvar</button></td>
        </tr>`).join('');
    return `
        <h3 class="ativcfg-titulo">Regras por servidor</h3>
        <p class="ativcfg-intro">${diario
            ? 'Modo diário: marque os dias fixos de trabalho remoto de cada servidor.'
            : 'Modo semanal: informe quantos relatórios remotos cada servidor envia por semana (vazio ou 0 = sem cota).'}</p>
        <div class="ativcfg-tabela-wrap"><table class="ativcfg-tabela">
            <caption class="sr-only">Regras de prazo por servidor</caption>
            <thead><tr><th scope="col">Servidor</th><th scope="col">Trabalho remoto</th>
                <th scope="col">${diario ? 'Dias remotos' : 'Relatórios remotos por semana'}</th><th scope="col">Entrega de relatório</th><th scope="col">Ações</th></tr></thead>
            <tbody>${linhas}</tbody>
        </table></div>`;
}

function diasMarcados(raiz, nome) {
    return [...raiz.querySelectorAll(`input[name="${nome}"]:checked`)].map(i => Number(i.value));
}

// ---- ações (ouvintes delegados, ligados uma vez por montagem) ----
function ligarAcoesAtividadesConfig(container) {
    if (container.dataset.ativcfgLigado) return;
    container.dataset.ativcfgLigado = '1';

    container.addEventListener('click', (ev) => {
        const alvo = ev.target.closest('[data-acao]');
        if (!alvo || alvo.tagName === 'INPUT') return;
        const id = Number(alvo.dataset.id);
        if (alvo.dataset.acao === 'aba') trocarAbaAtividadesCfg(alvo.dataset.setor);
        else if (alvo.dataset.acao === 'renomear') renomearTipoCfg(id, alvo.dataset.nome);
        else if (alvo.dataset.acao === 'excluir-tipo') excluirTipoCfg(id, alvo.dataset.nome);
        else if (alvo.dataset.acao === 'salvar-usuario') salvarUsuarioCfg(id, alvo.closest('tr'));
    });
    container.addEventListener('change', (ev) => {
        const alvo = ev.target;
        if (alvo.matches('[data-acao="ativar-tipo"]')) alterarTipoCfg(Number(alvo.dataset.id), { ativo: alvo.checked }, alvo);
        else if (alvo.matches('[data-acao="ordem"]')) alterarTipoCfg(Number(alvo.dataset.id), { ordem: Number(alvo.value) || 0 });
        else if (alvo.matches('select[name="entrega-modo"]')) {
            alvo.closest('td').querySelector('.ativcfg-dias-proprios').hidden = alvo.value !== 'personalizado';
        }
    });
    container.addEventListener('submit', (ev) => {
        const form = ev.target.closest('[data-acao-form]');
        if (!form) return;
        ev.preventDefault();
        if (form.dataset.acaoForm === 'criar-tipos') criarTiposCfg();
        else if (form.dataset.acaoForm === 'setor') salvarRegrasSetorCfg(form);
    });
    container.addEventListener('keydown', (ev) => {
        const aba = ev.target.closest('[role="tab"]');
        if (!aba || (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft')) return;
        const abas = [...container.querySelectorAll('[role="tab"]')];
        const destino = abas[(abas.indexOf(aba) + (ev.key === 'ArrowRight' ? 1 : -1) + abas.length) % abas.length];
        ev.preventDefault();
        trocarAbaAtividadesCfg(destino.dataset.setor).then(() => document.getElementById(`aba-ativcfg-${destino.dataset.setor}`)?.focus());
    });
}

async function trocarAbaAtividadesCfg(setor) {
    if (setor === ativCfgSetorAtual) return;
    ativCfgSetorAtual = setor;
    localStorage.setItem('cd_atividadesConfigSetor', setor);
    document.querySelectorAll('#config-content [role="tab"]').forEach(a => {
        const ativa = a.dataset.setor === setor;
        a.setAttribute('aria-selected', String(ativa));
        a.tabIndex = ativa ? 0 : -1;
    });
    await carregarAtividadesDoSetorCfg();
}

async function criarTiposCfg() {
    const campo = document.getElementById('ativcfg-nomes');
    try {
        const r = await Api.post('atividades-config.php?acao=tipos', { setor_id: ativCfgSetorAtual, nomes: campo.value });
        mostrarToast('Sucesso', `${r.criadas} atividade(s) adicionada(s)${r.ignoradas ? `, ${r.ignoradas} repetida(s) ignorada(s)` : ''}.`, 'success');
        await carregarAtividadesDoSetorCfg();
        document.getElementById('ativcfg-nomes')?.focus();
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao adicionar as atividades'));
    }
}

async function alterarTipoCfg(id, dados, caixa) {
    try {
        await Api.put(`atividades-config.php?acao=tipo&id=${id}`, dados);
        mostrarToast('Sucesso', 'Atividade atualizada.', 'success');
        if ('ativo' in dados) await carregarAtividadesDoSetorCfg();
    } catch (e) {
        if (caixa) caixa.checked = !dados.ativo;
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao alterar a atividade'));
    }
}

function renomearTipoCfg(id, nome) {
    mostrarPrompt('Renomear atividade', 'Novo nome da atividade:', async (novo) => {
        if (novo === null || novo === undefined || !String(novo).trim()) return;
        try {
            await Api.put(`atividades-config.php?acao=tipo&id=${id}`, { nome: String(novo).trim() });
            mostrarToast('Sucesso', 'Atividade renomeada.', 'success');
            await carregarAtividadesDoSetorCfg();
        } catch (e) {
            mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao renomear a atividade'));
        }
    });
    document.getElementById('modal-prompt-input').value = nome;
}

function excluirTipoCfg(id, nome) {
    mostrarConfirm('Excluir atividade', `Excluir a atividade "${nome}"?`, async (ok) => {
        if (!ok) return;
        try {
            await Api.delete(`atividades-config.php?acao=tipo&id=${id}`);
            mostrarToast('Sucesso', 'Atividade excluída.', 'success');
            await carregarAtividadesDoSetorCfg();
        } catch (e) {
            mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao excluir a atividade'));
        }
    }, true);
}

async function salvarRegrasSetorCfg(form) {
    try {
        await Api.put('atividades-config.php?acao=setor', {
            setor_id: ativCfgSetorAtual,
            modo: form.querySelector('input[name="ativcfg-modo"]:checked')?.value || 'semanal',
            prazo: Number(document.getElementById('ativcfg-prazo').value) || 0,
            data_base: document.getElementById('ativcfg-base').value,
            entrega_ativa: document.getElementById('ativcfg-entrega-ativa').checked,
            entrega_dias: diasMarcados(form, 'ativcfg-entrega-dias'),
            entrega_inicio: document.getElementById('ativcfg-entrega-inicio').value,
        });
        mostrarToast('Sucesso', 'Regras do setor salvas.', 'success');
        await carregarAtividadesDoSetorCfg();
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao salvar as regras do setor'));
    }
}

async function salvarUsuarioCfg(id, linha) {
    const qtd = linha.querySelector('input[name="qtd"]');
    try {
        await Api.put(`atividades-config.php?acao=usuario&id=${id}`, {
            trabalho_remoto: linha.querySelector('input[name="remoto"]').checked,
            // No modo diário a cota não aparece: mantém a gravada
            qtd_semana: qtd ? qtd.value : (ativCfgDados.usuarios.find(u => u.id === id)?.qtd_semana ?? ''),
            dias_remotos: linha.querySelector('input[name="dias-remotos"]')
                ? diasMarcados(linha, 'dias-remotos')
                : (ativCfgDados.usuarios.find(u => u.id === id)?.dias_remotos || []),
            entrega_modo: linha.querySelector('select[name="entrega-modo"]').value,
            entrega_dias: diasMarcados(linha, 'entrega-dias'),
        });
        mostrarToast('Sucesso', 'Regras do servidor salvas.', 'success');
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao salvar as regras do servidor'));
    }
}

// Item do menu (sem onclick no HTML)
document.addEventListener('click', (ev) => {
    if (!ev.target.closest('#btn-config-atividades')) return;
    mostrarSecao('config');
    document.getElementById('config-content').innerHTML = '';
    mostrarAtividadesConfig();
});
