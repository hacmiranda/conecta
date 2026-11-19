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
    // Abas por setor: admin (todos os setores) e coordenador com mais de um setor (os dele)
    const ehAdmin = Papeis.variosSetores(usuario);
    content.innerHTML = '<p class="ativcfg-aviso">Carregando...</p>';
    try {
        if (ehAdmin) {
            const doUsuario = Papeis.setores(usuario);
            ativCfgSetores = (doUsuario === null ? await Api.get('setores.php') : doUsuario).filter(s => s.ativo === undefined || Number(s.ativo) === 1);
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
                Cada seção abaixo explica o que faz.
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
        <p class="ativcfg-intro">O sistema cobra relatórios por <strong>dois motivos independentes</strong>:
            o <strong>trabalho remoto</strong> (só de quem trabalha remoto) e a <strong>entrega de relatório</strong>
            (de todos do setor). Quando os dois caem no mesmo dia ou na mesma semana, a falta é cobrada uma vez só.
            Fins de semana e feriados do setor nunca são cobrados.</p>
        <form class="ativcfg-form ativcfg-regras" data-acao-form="setor" autocomplete="off">
            <fieldset class="ativcfg-grupo ativcfg-cresce">
                <legend>1. Vale para as duas cobranças</legend>
                <div class="form-group">
                    <label for="ativcfg-prazo">Prazo para enviar (dias úteis)</label>
                    <input type="number" id="ativcfg-prazo" min="0" max="15" value="${Number(s.prazo)}" aria-describedby="ajuda-ativcfg-prazo">
                    <p class="ativcfg-ajuda" id="ajuda-ativcfg-prazo">Folga depois do dia cobrado (no semanal, depois da sexta-feira).
                        Até o fim do prazo a falta aparece como <em>pendente</em>; depois, como <em>atrasada</em>. 0 = vence no mesmo dia, às 23:59.</p>
                </div>
                <div class="form-group">
                    <label for="ativcfg-base">Começar a cobrar em</label>
                    <input type="date" id="ativcfg-base" value="${sanitizeHTML(s.data_base || '')}" aria-describedby="ajuda-ativcfg-base">
                    <p class="ativcfg-ajuda" id="ajuda-ativcfg-base">Nada antes desta data é cobrado. Em branco, vale o dia em que você salvar.</p>
                </div>
            </fieldset>
            <fieldset class="ativcfg-grupo ativcfg-cresce">
                <legend>2. Trabalho remoto</legend>
                <p class="ativcfg-ajuda">Escolha como medir. A cota ou os dias de cada pessoa são definidos em
                    <strong>Regras por servidor</strong>, logo abaixo.</p>
                <label class="ativcfg-opcao"><input type="radio" name="ativcfg-modo" value="semanal"${s.modo !== 'diario' ? ' checked' : ''}>
                    <span><strong>Semanal:</strong> um número de relatórios remotos por semana (segunda a domingo).
                    Só contam os enviados como “Remoto”. A cota nunca passa dos dias úteis da semana.</span></label>
                <label class="ativcfg-opcao"><input type="radio" name="ativcfg-modo" value="diario"${s.modo === 'diario' ? ' checked' : ''}>
                    <span><strong>Diário:</strong> dias fixos de trabalho remoto; em cada um deles é preciso enviar o relatório.</span></label>
            </fieldset>
            <fieldset class="ativcfg-grupo ativcfg-cresce">
                <legend>3. Entrega de relatório</legend>
                <p class="ativcfg-ajuda">Vale para todos do setor, trabalhem remoto ou não. Cada servidor pode seguir estes dias,
                    ser dispensado ou ter dias próprios.</p>
                <label class="ativcfg-opcao"><input type="checkbox" id="ativcfg-entrega-ativa"${s.entrega_ativa ? ' checked' : ''}> Cobrar entrega nos dias:</label>
                ${caixasDiasCfg('ativcfg-entrega-dias', s.entrega_dias, 'Dias de entrega do setor')}
                <div class="form-group">
                    <label for="ativcfg-entrega-inicio">Começar a cobrar a entrega em</label>
                    <input type="date" id="ativcfg-entrega-inicio" value="${sanitizeHTML(s.entrega_inicio || '')}" aria-describedby="ajuda-ativcfg-entrega-inicio">
                    <p class="ativcfg-ajuda" id="ajuda-ativcfg-entrega-inicio">Só para a entrega; nunca antes do “Começar a cobrar em” acima.
                        Em branco, vale o dia em que você salvar.</p>
                </div>
            </fieldset>
            <button type="submit" class="btn-primary">💾 Salvar regras do setor</button>
        </form>`;
}

/** "Seg, Qua e Sex" a partir de [1, 3, 5]. */
function nomesDiasCfg(dias) {
    const marcados = (dias || []).map(Number);
    const nomes = DIAS_SEMANA_ATIV.filter(([n]) => marcados.includes(n)).map(([, r]) => r);
    return nomes.length > 1 ? `${nomes.slice(0, -1).join(', ')} e ${nomes[nomes.length - 1]}` : (nomes[0] || '');
}

/** "2026-11-19" → "19/11/2026". */
function formatarDataCfg(iso) {
    const [a, m, d] = String(iso).slice(0, 10).split('-');
    return d && m && a ? `${d}/${m}/${a}` : String(iso);
}

/** Valores de uma linha da tabela de servidores (o que está na tela, salvo ou não). */
function valoresLinhaCfg(linha, u) {
    const qtd = linha.querySelector('input[name="qtd"]');
    return {
        remoto: linha.querySelector('input[name="remoto"]').checked,
        qtd: qtd ? Number(qtd.value) || 0 : Number(u.qtd_semana) || 0,
        diasRemotos: linha.querySelector('input[name="dias-remotos"]') ? diasMarcados(linha, 'dias-remotos') : (u.dias_remotos || []),
        entregaModo: linha.querySelector('select[name="entrega-modo"]').value,
        entregaDias: diasMarcados(linha, 'entrega-dias'),
    };
}

/** Frases do que, de fato, é cobrado do servidor com estes valores e as regras salvas do setor. */
function resumoCobrancaCfg(setor, v) {
    const partes = [];
    if (v.remoto) {
        if (setor.modo === 'diario') {
            partes.push(v.diasRemotos.length
                ? `Remoto: relatório em ${nomesDiasCfg(v.diasRemotos)}`
                : 'Remoto ligado, mas sem dias marcados: nada é cobrado');
        } else {
            partes.push(v.qtd > 0
                ? `Remoto: ${v.qtd} relatório${v.qtd > 1 ? 's' : ''} “Remoto” por semana`
                : 'Remoto ligado, mas sem cota: nada é cobrado');
        }
    }
    if (v.entregaModo === 'seguir' && setor.entrega_ativa && (setor.entrega_dias || []).length) {
        partes.push(`Entrega: ${nomesDiasCfg(setor.entrega_dias)} (do setor)`);
    } else if (v.entregaModo === 'personalizado') {
        partes.push(v.entregaDias.length ? `Entrega: ${nomesDiasCfg(v.entregaDias)} (dias próprios)` : 'Dias próprios: marque ao menos um dia');
    }
    return partes.length ? partes : ['Não é cobrado'];
}

function montarResumoCfg(setor, v) {
    const partes = resumoCobrancaCfg(setor, v);
    const nada = partes.length === 1 && partes[0] === 'Não é cobrado';
    return `<ul class="ativcfg-resumo${nada ? ' ativcfg-resumo--nada' : ''}">${partes.map(p => `<li>${sanitizeHTML(p)}</li>`).join('')}</ul>`;
}

/** Redesenha o resumo e trava/destrava a cota de uma linha depois de uma mudança na tela. */
function atualizarLinhaUsuarioCfg(linha) {
    const u = ativCfgDados.usuarios.find(x => x.id === Number(linha.dataset.usuario));
    if (!u) return;
    const v = valoresLinhaCfg(linha, u);
    // Sem trabalho remoto, a cota/dias ficam guardados mas não valem: campos desabilitados
    linha.querySelectorAll('.ativcfg-remoto-detalhe input').forEach(i => { i.disabled = !v.remoto; });
    linha.querySelector('.ativcfg-dias-proprios').hidden = v.entregaModo !== 'personalizado';
    linha.querySelector('.ativcfg-resumo-celula').innerHTML = montarResumoCfg(ativCfgDados.setor, v);
}

function montarUsuariosCfg(usuarios, setor) {
    if (!usuarios.length) {
        return '<h3 class="ativcfg-titulo">Regras por servidor</h3><p class="ativcfg-aviso">Nenhum gestor ou operador ativo neste setor.</p>';
    }
    const diario = setor.modo === 'diario';
    const linhas = usuarios.map(u => {
        const valores = { remoto: u.trabalho_remoto, qtd: Number(u.qtd_semana) || 0, diasRemotos: u.dias_remotos || [],
                          entregaModo: u.entrega_modo, entregaDias: u.entrega_dias || [] };
        const bloqueado = u.trabalho_remoto ? '' : ' disabled';
        const detalheRemoto = diario
            ? caixasDiasCfg('dias-remotos', u.dias_remotos, `Dias remotos de ${u.nome}`).replace(/<input /g, `<input${bloqueado} `)
            : `<label for="qtd-${u.id}">Relatórios por semana</label>
               <input type="number" id="qtd-${u.id}" name="qtd" min="0" max="7" class="ativcfg-ordem" value="${u.qtd_semana ?? ''}" placeholder="0"${bloqueado}>`;
        return `
        <tr data-usuario="${u.id}">
            <th scope="row">${sanitizeHTML(u.nome)}<br><small>${Papeis.rotulo(u.tipo)}</small></th>
            <td>
                <label class="ativcfg-opcao"><span class="switch"><input type="checkbox" role="switch" name="remoto"
                    ${u.trabalho_remoto ? 'checked' : ''}><span class="slider"></span></span>
                    <span>Trabalha remoto<span class="sr-only"> (${sanitizeHTML(u.nome)})</span></span></label>
                <div class="ativcfg-remoto-detalhe">${detalheRemoto}</div>
            </td>
            <td>
                <label class="sr-only" for="entrega-${u.id}">Entrega de relatório de ${sanitizeHTML(u.nome)}</label>
                <select id="entrega-${u.id}" name="entrega-modo">
                    <option value="seguir"${u.entrega_modo === 'seguir' ? ' selected' : ''}>Segue o setor</option>
                    <option value="dispensado"${u.entrega_modo === 'dispensado' ? ' selected' : ''}>Dispensado</option>
                    <option value="personalizado"${u.entrega_modo === 'personalizado' ? ' selected' : ''}>Dias próprios</option>
                </select>
                <div class="ativcfg-dias-proprios"${u.entrega_modo === 'personalizado' ? '' : ' hidden'}>
                    ${caixasDiasCfg('entrega-dias', u.entrega_dias, `Dias de entrega de ${u.nome}`)}
                    <p class="ativcfg-ajuda">${u.entrega_modo === 'personalizado' && u.entrega_inicio
                        ? `Cobrados desde ${sanitizeHTML(formatarDataCfg(u.entrega_inicio))}.`
                        : 'Cobrados a partir do dia em que você salvar.'} Valem mesmo com a entrega do setor desligada.</p>
                </div>
            </td>
            <td class="ativcfg-resumo-celula">${montarResumoCfg(setor, valores)}</td>
            <td><button type="button" class="btn-small" data-acao="salvar-usuario" data-id="${u.id}" aria-label="Salvar regras de ${sanitizeHTML(u.nome)}">💾 Salvar</button></td>
        </tr>`;
    }).join('');
    return `
        <h3 class="ativcfg-titulo">Regras por servidor</h3>
        <p class="ativcfg-intro">${diario
            ? 'O setor está no modo <strong>diário</strong>: para quem trabalha remoto, marque os dias fixos de trabalho remoto.'
            : 'O setor está no modo <strong>semanal</strong>: para quem trabalha remoto, informe quantos relatórios remotos envia por semana.'}
            Quem não tem “Trabalha remoto” ligado só é cobrado pela entrega de relatório.
            A coluna <strong>O que é cobrado</strong> mostra o resultado; salve cada linha.</p>
        <div class="ativcfg-tabela-wrap"><table class="ativcfg-tabela">
            <caption class="sr-only">Regras de prazo por servidor</caption>
            <thead><tr><th scope="col">Servidor</th><th scope="col">Trabalho remoto</th>
                <th scope="col">Entrega de relatório</th><th scope="col">O que é cobrado</th><th scope="col">Ações</th></tr></thead>
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
        else if (alvo.closest('tr[data-usuario]')) atualizarLinhaUsuarioCfg(alvo.closest('tr[data-usuario]'));
    });
    // Cota digitada: o resumo acompanha
    container.addEventListener('input', (ev) => {
        if (ev.target.matches('input[name="qtd"]')) atualizarLinhaUsuarioCfg(ev.target.closest('tr[data-usuario]'));
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
    mostrarPrompt('Renomear atividade', 'Novo nome da atividade:', async (confirmado) => {
        // O modal devolve só true/false; o texto fica no campo do próprio modal
        const novo = document.getElementById('modal-prompt-input').value;
        if (!confirmado || !novo.trim()) return;
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
        await carregarAtividadesDoSetorCfg(); // relê a data de início dos dias próprios
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
