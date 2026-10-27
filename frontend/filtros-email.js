/**
 * Configurações → Filtros de E-mail (auditoria posterior, 27/10/2026).
 * E-mail cujo remetente, destinatário, assunto ou corpo contém o texto cadastrado não abre demanda.
 * Admin: aba "Todos os setores" (filtros globais) + uma aba por setor (gerada de setores.php: setor novo
 * ganha aba sozinho). Gestor: só a página do próprio setor, com os globais em modo leitura.
 * Sem onclick/style inline: ações por data-acao com um ouvinte delegado (CSS em filtros-email.css).
 */
let filtrosSetorAtual = null;      // 'global' ou o id do setor
let filtrosSetoresLista = [];      // setores (só o admin carrega)
let filtrosCampos = {};            // campo => rótulo, vindos da API

function mostrarFiltrosEmail() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active'));
    localStorage.setItem('cd_subSecaoAtiva', 'filtros-email');
    const titulo = document.getElementById('config-title');
    if (titulo) titulo.textContent = '🚫 Filtros de E-mail';

    const content = document.getElementById('config-content');
    if (!content) {
        setTimeout(mostrarFiltrosEmail, 200);
        return;
    }
    carregarPaginaFiltrosEmail();
}

async function carregarPaginaFiltrosEmail() {
    const content = document.getElementById('config-content');
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuario.tipo === 'admin';
    content.innerHTML = '<p class="filtros-aviso">Carregando...</p>';

    try {
        if (ehAdmin) {
            filtrosSetoresLista = (await Api.get('setores.php')).filter(s => Number(s.ativo) === 1);
            const salvo = localStorage.getItem('cd_filtrosEmailSetor');
            const existe = salvo === 'global' || filtrosSetoresLista.some(s => String(s.id) === salvo);
            filtrosSetorAtual = existe ? salvo : (filtrosSetoresLista[0] ? String(filtrosSetoresLista[0].id) : 'global');
        } else {
            filtrosSetoresLista = [];
            filtrosSetorAtual = String(usuario.setor_id || '');
        }
        if (!filtrosSetorAtual) {
            content.innerHTML = '<p class="filtros-aviso">Você não está vinculado a um setor.</p>';
            return;
        }
        content.innerHTML = montarEsqueletoFiltros(ehAdmin);
        ligarAcoesFiltrosEmail(content);
        await carregarFiltrosDoSetor();
    } catch (e) {
        content.innerHTML = `<p class="filtros-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar os filtros.'))}</p>`;
    }
}

function montarEsqueletoFiltros(ehAdmin) {
    const abas = ehAdmin ? `
        <div class="abas-usuarios filtros-abas" role="tablist" aria-label="Setores">
            ${[{ id: 'global', nome: '🌐 Todos os setores' }, ...filtrosSetoresLista.map(s => ({ id: String(s.id), nome: s.nome }))].map(s => `
                <button type="button" role="tab" class="aba-usuarios-btn" id="aba-filtros-${sanitizeHTML(s.id)}"
                    data-acao="aba" data-setor="${sanitizeHTML(s.id)}" aria-controls="painel-filtros"
                    aria-selected="${s.id === filtrosSetorAtual}" tabindex="${s.id === filtrosSetorAtual ? '0' : '-1'}">${sanitizeHTML(s.nome)}</button>`).join('')}
        </div>` : '';
    return `
        <p class="filtros-intro">E-mail que chegar na caixa e coincidir com um filtro <strong>não abre demanda</strong>
            (fica só no registro de e-mails barrados). A comparação ignora maiúsculas e acentos e vale quando o campo
            <em>contém</em> o texto. Respostas de quem já participa de uma demanda continuam virando comentário.</p>
        ${abas}
        <div id="painel-filtros" role="${ehAdmin ? 'tabpanel' : 'region'}" aria-label="Filtros de e-mail" aria-live="polite"></div>`;
}

async function carregarFiltrosDoSetor() {
    const painel = document.getElementById('painel-filtros');
    if (!painel) return;
    painel.innerHTML = '<p class="filtros-aviso">Carregando...</p>';
    try {
        const dados = await Api.get(`filtros-email.php?setor_id=${encodeURIComponent(filtrosSetorAtual)}`);
        filtrosCampos = dados.campos || {};
        painel.innerHTML = montarPainelFiltros(dados);
        carregarBarradosDoSetor();
    } catch (e) {
        painel.innerHTML = `<p class="filtros-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar os filtros.'))}</p>`;
    }
}

function opcoesCampoFiltro(selecionado) {
    return Object.entries(filtrosCampos).map(([chave, rotulo]) =>
        `<option value="${sanitizeHTML(chave)}"${chave === selecionado ? ' selected' : ''}>${sanitizeHTML(rotulo)}</option>`).join('');
}

function montarPainelFiltros(dados) {
    const global = filtrosSetorAtual === 'global';
    const nomeSetor = global ? 'todos os setores' : nomeDoSetorFiltro(filtrosSetorAtual);
    const globais = (dados.filtros_globais || []);
    return `
        <h3 class="filtros-titulo">Filtros de ${sanitizeHTML(nomeSetor)}</h3>
        <form class="filtros-form" id="form-filtro-email" data-acao-form="criar" autocomplete="off">
            <div class="form-group">
                <label for="filtro-campo">O campo</label>
                <select id="filtro-campo" required>${opcoesCampoFiltro('assunto')}</select>
            </div>
            <div class="form-group filtros-termo">
                <label for="filtro-termo">contém o texto</label>
                <input type="text" id="filtro-termo" maxlength="255" required minlength="3"
                    placeholder="Ex.: @empresa.com.br, newsletter, cancelar inscrição">
            </div>
            <div class="form-group filtros-obs">
                <label for="filtro-obs">Para que serve (opcional)</label>
                <input type="text" id="filtro-obs" maxlength="255" placeholder="Ex.: propaganda de fornecedor">
            </div>
            <button type="submit" class="btn-primary">➕ Adicionar filtro</button>
        </form>
        ${montarTabelaFiltros(dados.filtros || [], true)}
        ${!global && globais.length ? `
            <h3 class="filtros-titulo">Filtros de todos os setores <small>(só o administrador altera)</small></h3>
            ${montarTabelaFiltros(globais, false)}` : ''}
        <h3 class="filtros-titulo">Testar um e-mail</h3>
        <form class="filtros-teste" id="form-teste-filtro" data-acao-form="testar" autocomplete="off">
            <div class="form-group"><label for="teste-remetente">Remetente</label><input type="text" id="teste-remetente" placeholder="Nome &lt;pessoa@exemplo.com&gt;"></div>
            <div class="form-group"><label for="teste-destinatario">Destinatário</label><input type="text" id="teste-destinatario"></div>
            <div class="form-group"><label for="teste-assunto">Assunto</label><input type="text" id="teste-assunto"></div>
            <div class="form-group filtros-teste-corpo"><label for="teste-corpo">Corpo</label><textarea id="teste-corpo" rows="3"></textarea></div>
            <button type="submit" class="btn-voltar">🔍 Verificar</button>
            <p id="resultado-teste-filtro" class="filtros-resultado" role="status"></p>
        </form>
        <h3 class="filtros-titulo">Últimos e-mails barrados</h3>
        <div id="filtros-barrados"><p class="filtros-aviso">Carregando...</p></div>`;
}

function nomeDoSetorFiltro(id) {
    const s = filtrosSetoresLista.find(x => String(x.id) === String(id));
    if (s) return s.nome;
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    return usuario.setor_nome || 'seu setor';
}

function montarTabelaFiltros(filtros, editavel) {
    if (!filtros.length) {
        return `<p class="filtros-aviso">${editavel ? 'Nenhum filtro cadastrado.' : ''}</p>`;
    }
    const linhas = filtros.map(f => `
        <tr class="${Number(f.ativo) ? '' : 'filtro-inativo'}">
            <td>${sanitizeHTML(filtrosCampos[f.campo] || f.campo)}</td>
            <td><code>${sanitizeHTML(f.termo)}</code></td>
            <td>${f.observacao ? sanitizeHTML(f.observacao) : '<span class="filtros-vazio">—</span>'}</td>
            <td>${Number(f.ocorrencias) ? `${Number(f.ocorrencias)}${f.ultima_ocorrencia ? `<br><small>${sanitizeHTML(new Date(f.ultima_ocorrencia.replace(' ', 'T')).toLocaleString('pt-BR'))}</small>` : ''}` : '0'}</td>
            <td>${editavel ? `
                <label class="switch"><input type="checkbox" role="switch" data-acao="alternar" data-id="${Number(f.id)}"
                    aria-label="Filtro ${sanitizeHTML(f.termo)} ativo" ${Number(f.ativo) ? 'checked' : ''}><span class="slider"></span></label>`
                : (Number(f.ativo) ? 'Ativo' : 'Desligado')}</td>
            ${editavel ? `<td class="filtros-acoes">
                <button type="button" class="btn-small" data-acao="editar" data-id="${Number(f.id)}" data-campo="${sanitizeHTML(f.campo)}"
                    data-termo="${sanitizeHTML(f.termo)}" data-obs="${sanitizeHTML(f.observacao || '')}" aria-label="Editar o filtro ${sanitizeHTML(f.termo)}">✏️ Editar</button>
                <button type="button" class="btn-small btn-perigo" data-acao="excluir" data-id="${Number(f.id)}" data-termo="${sanitizeHTML(f.termo)}"
                    aria-label="Excluir o filtro ${sanitizeHTML(f.termo)}">🗑️ Excluir</button></td>` : ''}
        </tr>`).join('');
    return `
        <div class="filtros-tabela-wrap">
        <table class="filtros-tabela">
            <caption class="sr-only">${editavel ? 'Filtros cadastrados' : 'Filtros de todos os setores'}</caption>
            <thead><tr><th scope="col">Campo</th><th scope="col">Contém</th><th scope="col">Para que serve</th>
                <th scope="col">Barrados</th><th scope="col">${editavel ? 'Ativo' : 'Situação'}</th>${editavel ? '<th scope="col">Ações</th>' : ''}</tr></thead>
            <tbody>${linhas}</tbody>
        </table></div>`;
}

async function carregarBarradosDoSetor() {
    const caixa = document.getElementById('filtros-barrados');
    if (!caixa) return;
    try {
        const { barrados } = await Api.get(`filtros-email.php?acao=barrados&setor_id=${encodeURIComponent(filtrosSetorAtual)}`);
        if (!barrados || !barrados.length) {
            caixa.innerHTML = '<p class="filtros-aviso">Nenhum e-mail foi barrado ainda.</p>';
            return;
        }
        caixa.innerHTML = `
            <div class="filtros-tabela-wrap"><table class="filtros-tabela">
                <caption class="sr-only">Últimos e-mails barrados pelos filtros</caption>
                <thead><tr><th scope="col">Quando</th><th scope="col">Remetente</th><th scope="col">Assunto</th><th scope="col">Filtro</th></tr></thead>
                <tbody>${barrados.map(b => `<tr>
                    <td>${sanitizeHTML(new Date(b.processado_em.replace(' ', 'T')).toLocaleString('pt-BR'))}</td>
                    <td>${sanitizeHTML(b.email_remetente)}</td>
                    <td>${sanitizeHTML(b.assunto || '')}</td>
                    <td>${sanitizeHTML((filtrosCampos[b.campo] || b.campo) + ' contém "' + b.termo + '"')}${b.setor_id === null ? ' <small>(todos os setores)</small>' : ''}</td>
                </tr>`).join('')}</tbody></table></div>`;
    } catch (e) {
        caixa.innerHTML = `<p class="filtros-aviso">${sanitizeHTML(mensagemDeErro(e, 'Não foi possível carregar o histórico.'))}</p>`;
    }
}

// ---- ações (um ouvinte só, ligado uma vez por montagem da página) ----
function ligarAcoesFiltrosEmail(container) {
    if (container.dataset.filtrosLigado) return;
    container.dataset.filtrosLigado = '1';

    container.addEventListener('click', (ev) => {
        const alvo = ev.target.closest('[data-acao]');
        if (!alvo || alvo.tagName === 'INPUT') return;
        if (alvo.dataset.acao === 'aba') trocarAbaFiltros(alvo.dataset.setor);
        else if (alvo.dataset.acao === 'editar') editarFiltroEmail(alvo.dataset);
        else if (alvo.dataset.acao === 'excluir') excluirFiltroEmail(Number(alvo.dataset.id), alvo.dataset.termo);
    });
    container.addEventListener('change', (ev) => {
        const alvo = ev.target.closest('[data-acao="alternar"]');
        if (alvo) alternarFiltroEmail(Number(alvo.dataset.id), alvo.checked, alvo);
    });
    container.addEventListener('submit', (ev) => {
        const form = ev.target.closest('[data-acao-form]');
        if (!form) return;
        ev.preventDefault();
        if (form.dataset.acaoForm === 'criar') criarFiltroEmail();
        else if (form.dataset.acaoForm === 'testar') testarFiltroEmail();
    });
    // Setas ←/→ movem entre as abas e já ativam (padrão WAI-ARIA de tabs)
    container.addEventListener('keydown', (ev) => {
        const aba = ev.target.closest('[role="tab"]');
        if (!aba || (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft')) return;
        const abas = [...container.querySelectorAll('[role="tab"]')];
        const i = abas.indexOf(aba);
        const destino = abas[(i + (ev.key === 'ArrowRight' ? 1 : -1) + abas.length) % abas.length];
        ev.preventDefault();
        trocarAbaFiltros(destino.dataset.setor).then(() => document.getElementById(`aba-filtros-${destino.dataset.setor}`)?.focus());
    });
}

async function trocarAbaFiltros(setor) {
    if (setor === filtrosSetorAtual) return;
    filtrosSetorAtual = setor;
    localStorage.setItem('cd_filtrosEmailSetor', setor);
    document.querySelectorAll('#config-content [role="tab"]').forEach(a => {
        const ativa = a.dataset.setor === setor;
        a.setAttribute('aria-selected', String(ativa));
        a.tabIndex = ativa ? 0 : -1;
    });
    await carregarFiltrosDoSetor();
}

function corpoFiltro(campo, termo, observacao) {
    return { setor_id: filtrosSetorAtual, campo, termo, observacao };
}

// Cria o filtro; se o formulário está em modo de edição (botão "Editar" da tabela), salva essa edição
async function criarFiltroEmail() {
    const form = document.getElementById('form-filtro-email');
    const campo = document.getElementById('filtro-campo').value;
    const termo = document.getElementById('filtro-termo').value.trim();
    const obs = document.getElementById('filtro-obs').value.trim();
    const editando = form && form.dataset.editando ? Number(form.dataset.editando) : 0;
    try {
        if (editando) {
            await Api.put(`filtros-email.php?id=${editando}`, { campo, termo, observacao: obs });
            mostrarToast('Sucesso', 'Filtro atualizado!', 'success');
        } else {
            await Api.post('filtros-email.php', corpoFiltro(campo, termo, obs));
            mostrarToast('Sucesso', 'Filtro adicionado!', 'success');
        }
        await carregarFiltrosDoSetor();
        document.getElementById('filtro-termo')?.focus();
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, editando ? 'Erro ao salvar o filtro' : 'Erro ao adicionar o filtro'));
    }
}

async function alternarFiltroEmail(id, ativo, caixa) {
    try {
        await Api.put(`filtros-email.php?id=${id}`, { ativo });
        mostrarToast('Sucesso', ativo ? 'Filtro ligado.' : 'Filtro desligado.', 'success');
        await carregarFiltrosDoSetor();
    } catch (e) {
        caixa.checked = !ativo;
        mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao alterar o filtro'));
    }
}

function editarFiltroEmail(d) {
    // Edição no próprio formulário de cima: preenche e troca o botão por "Salvar" até concluir
    const form = document.getElementById('form-filtro-email');
    if (!form) return;
    document.getElementById('filtro-campo').value = d.campo;
    document.getElementById('filtro-termo').value = d.termo;
    document.getElementById('filtro-obs').value = d.obs || '';
    form.dataset.editando = d.id;
    const botao = form.querySelector('button[type="submit"]');
    botao.textContent = '💾 Salvar alterações';
    if (!form.querySelector('[data-acao="cancelar-edicao"]')) {
        const cancelar = document.createElement('button');
        cancelar.type = 'button';
        cancelar.className = 'btn-voltar';
        cancelar.dataset.acao = 'cancelar-edicao';
        cancelar.textContent = 'Cancelar';
        botao.after(cancelar);
    }
    document.getElementById('filtro-termo').focus();
}

function excluirFiltroEmail(id, termo) {
    mostrarConfirm('Excluir filtro', `Excluir o filtro "${termo}"? E-mails que coincidiam com ele voltarão a abrir demanda.`, async function (confirmado) {
        if (!confirmado) return;
        try {
            await Api.delete(`filtros-email.php?id=${id}`);
            mostrarToast('Sucesso', 'Filtro excluído.', 'success');
            await carregarFiltrosDoSetor();
        } catch (e) {
            mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao excluir o filtro'));
        }
    }, true);
}

async function testarFiltroEmail() {
    const saida = document.getElementById('resultado-teste-filtro');
    try {
        const r = await Api.post('filtros-email.php?acao=testar', {
            setor_id: filtrosSetorAtual,
            remetente: document.getElementById('teste-remetente').value,
            destinatario: document.getElementById('teste-destinatario').value,
            assunto: document.getElementById('teste-assunto').value,
            corpo: document.getElementById('teste-corpo').value,
        });
        saida.className = 'filtros-resultado ' + (r.barrado ? 'filtros-resultado--barrado' : 'filtros-resultado--passa');
        saida.textContent = r.barrado
            ? `🚫 Seria barrado: ${r.filtro.descricao}${r.filtro.global ? ' (filtro de todos os setores)' : ''}. Não abriria demanda.`
            : '✅ Nenhum filtro barra este e-mail: ele abriria demanda normalmente.';
    } catch (e) {
        saida.className = 'filtros-resultado';
        saida.textContent = mensagemDeErro(e, 'Erro ao testar');
    }
}

// Item do menu e cancelamento da edição (sem onclick no HTML)
document.addEventListener('click', (ev) => {
    const item = ev.target.closest('#btn-config-filtros-email');
    if (item) {
        mostrarSecao('config');
        document.getElementById('config-content').innerHTML = '';
        mostrarFiltrosEmail();
        return;
    }
    const cancelar = ev.target.closest('[data-acao="cancelar-edicao"]');
    if (cancelar) {
        carregarFiltrosDoSetor();
    }
});
