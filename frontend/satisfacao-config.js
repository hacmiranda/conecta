/**
 * Configurações → Pesquisa de satisfação (só administrador): regras de convite e do alerta de nota baixa,
 * categorias de avaliação (as etiquetas que o cidadão marca) e frases do dia. Veio de "Configurações" do METRICA360.
 * Sem onclick/style inline: ações por data-acao com ouvinte delegado (CSS em satisfacao.css).
 */
let satCfgDados = null;

function mostrarPesquisaConfig() {
    localStorage.setItem('cd_subSecaoAtiva', 'pesquisa-config');
    const titulo = document.getElementById('config-title');
    if (titulo) titulo.textContent = '⭐ Pesquisa de satisfação';
    if (!document.getElementById('config-content')) {
        setTimeout(mostrarPesquisaConfig, 200);
        return;
    }
    carregarPesquisaConfig();
}

async function carregarPesquisaConfig() {
    const content = document.getElementById('config-content');
    ligarAcoesPesquisaConfig(content);
    content.innerHTML = '<p class="sat-aviso">Carregando...</p>';
    try {
        satCfgDados = await Api.get('pesquisa-config.php');
        content.innerHTML = montarRegrasCfg(satCfgDados.regras) + montarCategoriasCfg(satCfgDados.categorias) + montarFrasesCfg(satCfgDados.frases);
    } catch (e) {
        content.innerHTML = `<p class="sat-aviso" role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar as configurações da pesquisa.'))}</p>`;
    }
}

function montarRegrasCfg(r) {
    const campo = (nome, rotulo, ajuda, tipo, extra) => `<label class="satcfg-campo">${rotulo}
        <input type="${tipo}" name="${nome}" value="${sanitizeHTML(r[nome] ?? '')}" ${extra || ''}>${ajuda ? `<small>${ajuda}</small>` : ''}</label>`;
    const caixa = (nome, rotulo, ajuda) => `<label class="satcfg-campo"><span><input type="checkbox" name="${nome}" ${r[nome] === '1' ? 'checked' : ''}> ${rotulo}</span>${ajuda ? `<small>${ajuda}</small>` : ''}</label>`;
    return `<section class="sat-bloco" aria-labelledby="satcfg-regras-titulo">
        <h3 id="satcfg-regras-titulo">Regras de convite</h3>
        <form data-acao-form="regras">
            <div class="satcfg-grade">
                ${caixa('quarentena_ativa', 'Quarentena por e-mail', 'Não envia novo convite ao mesmo e-mail dentro do prazo ao lado.')}
                ${campo('quarentena_dias', 'Dias de quarentena', 'De 1 a 365.', 'number', 'min="1" max="365" required')}
                ${campo('reenvio_horas', 'Reenviar após (horas)', 'Um único reenvio automático para quem não respondeu. De 1 a 720.', 'number', 'min="1" max="720" required')}
                ${campo('expiracao_token_dias', 'Validade do link (dias)', 'Depois disso o link deixa de funcionar. De 1 a 90.', 'number', 'min="1" max="90" required')}
                ${campo('meta_nota_media', 'Meta de nota média', 'Linha de referência do gráfico (1 a 5).', 'text', 'inputmode="decimal" required')}
                ${caixa('notificar_avaliacao_critica', 'Avisar por e-mail sobre notas 1 e 2')}
                ${campo('email_avaliacao_critica', 'E-mail que recebe o aviso', 'Deixe vazio para não avisar.', 'email')}
                ${campo('google_review_url', 'Link do Google Avaliações', 'Quem dá nota 4 ou 5 é convidado a avaliar também no Google. Vazio = sem convite. Precisa começar com https://', 'url')}
            </div>
            <p class="satcfg-nova"><button type="submit" class="btn-primary">Salvar regras</button></p>
        </form></section>`;
}

function linhaCategoriaCfg(c) {
    return `<div class="satcfg-linha" data-categoria="${c.id}">
        <input type="text" class="satcfg-emoji" value="${sanitizeHTML(c.emoji)}" maxlength="10" aria-label="Emoji da categoria ${sanitizeHTML(c.nome)}">
        <input type="text" class="satcfg-nome" value="${sanitizeHTML(c.nome)}" maxlength="80" aria-label="Nome da categoria">
        <label><input type="checkbox" class="satcfg-ativo" ${Number(c.ativo) === 1 ? 'checked' : ''}> Ativa</label>
        <span class="satcfg-acoes">
            <button type="button" class="btn-small" data-acao="subir" data-tipo="categorias" aria-label="Subir ${sanitizeHTML(c.nome)}">↑</button>
            <button type="button" class="btn-small" data-acao="descer" data-tipo="categorias" aria-label="Descer ${sanitizeHTML(c.nome)}">↓</button>
            <button type="button" class="btn-small" data-acao="salvar-categoria">Salvar</button>
            <button type="button" class="btn-small" data-acao="excluir-categoria" ${Number(c.em_uso) > 0 ? 'title="Já usada em avaliações: desative em vez de excluir"' : ''}>Excluir</button>
        </span></div>`;
}

function montarCategoriasCfg(lista) {
    return `<section class="sat-bloco" aria-labelledby="satcfg-cat-titulo">
        <h3 id="satcfg-cat-titulo">Categorias de avaliação</h3>
        <p class="sat-rodape">O cidadão marca as categorias ao avaliar. Categoria já usada só pode ser desativada.</p>
        <div id="satcfg-categorias">${lista.map(linhaCategoriaCfg).join('') || '<p class="sat-vazio">Nenhuma categoria.</p>'}</div>
        <form class="satcfg-linha satcfg-nova" data-acao-form="nova-categoria">
            <input type="text" name="emoji" maxlength="10" placeholder="😀" aria-label="Emoji da nova categoria">
            <input type="text" name="nome" maxlength="80" placeholder="Nome da nova categoria" aria-label="Nome da nova categoria" required>
            <span></span><button type="submit" class="btn-small">Adicionar</button>
        </form></section>`;
}

function linhaFraseCfg(f) {
    return `<div class="satcfg-linha satcfg-linha-frase" data-frase="${f.id}">
        <textarea class="satcfg-texto" rows="2" maxlength="500" aria-label="Texto da frase">${sanitizeHTML(f.texto)}</textarea>
        <input type="text" class="satcfg-autor" value="${sanitizeHTML(f.autor)}" maxlength="120" placeholder="Autor" aria-label="Autor da frase">
        <label><input type="checkbox" class="satcfg-ativo" ${Number(f.ativo) === 1 ? 'checked' : ''}> Ativa</label>
        <span class="satcfg-acoes">
            <button type="button" class="btn-small" data-acao="subir" data-tipo="frases" aria-label="Subir esta frase">↑</button>
            <button type="button" class="btn-small" data-acao="descer" data-tipo="frases" aria-label="Descer esta frase">↓</button>
            <button type="button" class="btn-small" data-acao="salvar-frase">Salvar</button>
            <button type="button" class="btn-small" data-acao="excluir-frase">Excluir</button>
        </span></div>`;
}

function montarFrasesCfg(lista) {
    return `<section class="sat-bloco" aria-labelledby="satcfg-frases-titulo">
        <h3 id="satcfg-frases-titulo">Frases do dia</h3>
        <p class="sat-rodape">Uma frase por dia, em sequência, no painel de avaliações e no Meu Painel.</p>
        <div id="satcfg-frases">${lista.map(linhaFraseCfg).join('') || '<p class="sat-vazio">Nenhuma frase.</p>'}</div>
        <form class="satcfg-linha satcfg-linha-frase satcfg-nova" data-acao-form="nova-frase">
            <textarea name="texto" rows="2" maxlength="500" placeholder="Nova frase" aria-label="Texto da nova frase" required></textarea>
            <input type="text" name="autor" maxlength="120" placeholder="Autor" aria-label="Autor da nova frase">
            <span></span><button type="submit" class="btn-small">Adicionar</button>
        </form></section>`;
}

async function acaoConfigPesquisa(promessa, mensagem) {
    try {
        await promessa;
        if (mensagem) mostrarToast('Sucesso', mensagem, 'success');
        await carregarPesquisaConfig();
    } catch (e) {
        mostrarAlert('Erro', mensagemDeErro(e, 'Não foi possível salvar.'));
    }
}

function moverItemPesquisaCfg(tipo, linha, delta) {
    const seletor = tipo === 'categorias' ? '[data-categoria]' : '[data-frase]';
    const atributo = tipo === 'categorias' ? 'categoria' : 'frase';
    const ids = Array.from(document.querySelectorAll('#satcfg-' + tipo + ' ' + seletor)).map(el => Number(el.dataset[atributo]));
    const atual = ids.indexOf(Number(linha.dataset[atributo]));
    const destino = atual + delta;
    if (atual < 0 || destino < 0 || destino >= ids.length) return;
    [ids[atual], ids[destino]] = [ids[destino], ids[atual]];
    acaoConfigPesquisa(Api.put('pesquisa-config.php?acao=' + (tipo === 'categorias' ? 'categorias-ordem' : 'frases-ordem'), { ids }), null);
}

function ligarAcoesPesquisaConfig(content) {
    if (content.dataset.satCfgLigado) return;
    content.dataset.satCfgLigado = '1';

    content.addEventListener('click', (ev) => {
        const botao = ev.target.closest('[data-acao]');
        if (!botao || !content.querySelector('#satcfg-regras-titulo')) return;
        const acao = botao.dataset.acao;
        const linhaCat = botao.closest('[data-categoria]');
        const linhaFrase = botao.closest('[data-frase]');

        if (acao === 'subir' || acao === 'descer') {
            const tipo = botao.dataset.tipo;
            moverItemPesquisaCfg(tipo, tipo === 'categorias' ? linhaCat : linhaFrase, acao === 'subir' ? -1 : 1);
        } else if (acao === 'salvar-categoria' && linhaCat) {
            acaoConfigPesquisa(Api.put('pesquisa-config.php?acao=categoria&id=' + linhaCat.dataset.categoria, {
                nome: linhaCat.querySelector('.satcfg-nome').value, emoji: linhaCat.querySelector('.satcfg-emoji').value,
                ativo: linhaCat.querySelector('.satcfg-ativo').checked
            }), 'Categoria salva.');
        } else if (acao === 'excluir-categoria' && linhaCat) {
            mostrarConfirm('Excluir categoria', 'Excluir esta categoria? Se ela já foi usada em avaliações, desative-a em vez disso.', (ok) => {
                if (ok) acaoConfigPesquisa(Api.delete('pesquisa-config.php?acao=categoria&id=' + linhaCat.dataset.categoria), 'Categoria excluída.');
            }, true);
        } else if (acao === 'salvar-frase' && linhaFrase) {
            acaoConfigPesquisa(Api.put('pesquisa-config.php?acao=frase&id=' + linhaFrase.dataset.frase, {
                texto: linhaFrase.querySelector('.satcfg-texto').value, autor: linhaFrase.querySelector('.satcfg-autor').value,
                ativo: linhaFrase.querySelector('.satcfg-ativo').checked
            }), 'Frase salva.');
        } else if (acao === 'excluir-frase' && linhaFrase) {
            mostrarConfirm('Excluir frase', 'Excluir esta frase do dia?', (ok) => {
                if (ok) acaoConfigPesquisa(Api.delete('pesquisa-config.php?acao=frase&id=' + linhaFrase.dataset.frase), 'Frase excluída.');
            }, true);
        }
    });

    content.addEventListener('submit', (ev) => {
        const form = ev.target.closest('[data-acao-form]');
        if (!form || !content.querySelector('#satcfg-regras-titulo')) return;
        ev.preventDefault();
        const dados = Object.fromEntries(new FormData(form).entries());
        const tipo = form.dataset.acaoForm;
        if (tipo === 'regras') {
            const caixas = ['quarentena_ativa', 'notificar_avaliacao_critica'];
            caixas.forEach(n => { dados[n] = form.elements[n].checked; });
            acaoConfigPesquisa(Api.put('pesquisa-config.php?acao=regras', dados), 'Regras salvas.');
        } else if (tipo === 'nova-categoria') {
            acaoConfigPesquisa(Api.post('pesquisa-config.php?acao=categoria', dados), 'Categoria adicionada.');
        } else if (tipo === 'nova-frase') {
            acaoConfigPesquisa(Api.post('pesquisa-config.php?acao=frase', dados), 'Frase adicionada.');
        }
    });
}

// Item do menu Configurações (sem onclick no HTML)
document.addEventListener('click', (ev) => {
    if (!ev.target.closest('#btn-config-pesquisa')) return;
    mostrarSecao('config');
    document.getElementById('config-content').innerHTML = '';
    mostrarPesquisaConfig();
});
