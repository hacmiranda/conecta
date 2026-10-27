/**
 * Meu Painel: envio de pesquisas de satisfação (antiga tela "Envio de Pesquisas" da Área do Funcionário
 * do METRICA360, 27/10/2026). Os convites e as notas ficam no METRICA360; a API do Conecta só repassa.
 * Aparece para quem tem cadastro no METRICA360 com "atendimento externo" (verificarAcessoMeuPainel).
 * Sem onclick/style inline: ações por data-acao com ouvintes delegados (CSS em meu-painel.css).
 */
const MP_ELFSIGHT_SCRIPT = 'https://static.elfsight.com/platform/platform.js';
const MP_ELFSIGHT_APP = 'elfsight-app-89b439f9-8807-4f6b-9dc5-062a12b28e17';
let mpFiltro = '';
let mpPagina = 1;
let mpRecarga = null;
let mpRelogio = null;

// Mostra o item do menu só para quem o METRICA360 reconhece com atendimento externo
async function verificarAcessoMeuPainel() {
    const item = document.getElementById('nav-meu-painel-item');
    if (!item) return;
    try {
        const r = await Api.get('meu-painel.php?acao=acesso');
        item.hidden = !(r.existe && r.atendimento_externo);
    } catch (e) {
        item.hidden = true;
    }
}

function mostrarMeuPainel() {
    mostrarSecao('meu-painel');
    carregarMeuPainel();
    iniciarRelogioMeuPainel();
    iniciarRecargaMeuPainel();
}

function iniciarRelogioMeuPainel() {
    const alvo = document.getElementById('mp-relogio');
    if (!alvo) return;
    const atualizar = () => {
        const agora = new Date();
        alvo.textContent = agora.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
            + ' · ' + agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    };
    atualizar();
    clearInterval(mpRelogio);
    mpRelogio = setInterval(atualizar, 30000);
}

// Recarrega a cada 5 min (como no METRICA360), só com a tela aberta e visível e sem ninguém digitando
function iniciarRecargaMeuPainel() {
    clearInterval(mpRecarga);
    mpRecarga = setInterval(() => {
        const secao = document.getElementById('meu-painel');
        if (!secao || !secao.classList.contains('active')) return clearInterval(mpRecarga);
        if (document.hidden || document.querySelector('.modal.show')) return;
        const foco = document.activeElement;
        if (foco && secao.contains(foco) && foco.matches('input, textarea')) return;
        carregarMeuPainel(true);
    }, 5 * 60 * 1000);
}

async function carregarMeuPainel(silencioso) {
    const conteudo = document.getElementById('meu-painel-conteudo');
    if (!conteudo) return;
    ligarAcoesMeuPainel(conteudo);
    if (!silencioso) conteudo.innerHTML = '<p class="mp-aviso">Carregando...</p>';
    try {
        const params = new URLSearchParams({ p: String(mpPagina) });
        if (mpFiltro) params.set('status', mpFiltro);
        const d = await Api.get('meu-painel.php?' + params.toString());
        const rascunho = {
            nome: document.getElementById('mp-nome')?.value || '',
            email: document.getElementById('mp-email')?.value || '',
        };
        conteudo.innerHTML = montarMeuPainel(d);
        // Recarga automática não apaga o que estava sendo digitado
        document.getElementById('mp-nome').value = rascunho.nome;
        document.getElementById('mp-email').value = rascunho.email;
        animarIndicadoresMeuPainel();
        carregarWidgetGoogleReviews();
    } catch (e) {
        if (silencioso) return;
        conteudo.innerHTML = `
            <div class="mp-cartao mp-erro" role="alert">
                <p>${sanitizeHTML(mensagemDeErro(e, 'Não foi possível carregar o painel.'))}</p>
                ${!e.status || e.status >= 500 ? '<button type="button" class="btn-primary" data-acao="tentar">↻ Tentar de novo</button>' : ''}
            </div>`;
    }
}

function rotuloDias(n) {
    return `${n} dia${Number(n) === 1 ? '' : 's'}`;
}

function dataRelativaMeuPainel(dataHora) {
    if (!dataHora) return '';
    const data = new Date(String(dataHora).replace(' ', 'T'));
    const hoje = new Date();
    const dias = Math.floor((new Date(hoje.toDateString()) - new Date(data.toDateString())) / 86400000);
    const hora = data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    if (dias === 0) return `Hoje às ${hora}`;
    if (dias === 1) return `Ontem às ${hora}`;
    if (dias < 7) return `Há ${dias} dias`;
    return data.toLocaleDateString('pt-BR');
}

function montarMeuPainel(d) {
    const ind = d.indicadores;
    const r = d.regras;
    const h = d.historico;
    const nota = ind.media_nota === null ? '—' : Number(ind.media_nota).toFixed(1).replace('.', ',');
    const abas = [['', 'Todos', ind.total], ['avaliado', 'Avaliados', ind.avaliados], ['aguardando', 'Aguardando', ind.aguardando]];
    return `
        ${d.frase ? `<figure class="mp-frase"><blockquote>${sanitizeHTML(d.frase.texto)}</blockquote>
            ${d.frase.autor ? `<figcaption>— ${sanitizeHTML(d.frase.autor)}</figcaption>` : ''}</figure>` : ''}

        <section class="mp-cartao mp-google" aria-labelledby="mp-google-titulo">
            <h3 class="mp-cartao-titulo" id="mp-google-titulo">⭐ Google Reviews — CRPRS</h3>
            <div class="${MP_ELFSIGHT_APP}" data-elfsight-app-lazy></div>
        </section>

        <ul class="mp-indicadores" aria-label="Indicadores dos seus convites">
            <li class="mp-indicador"><span class="mp-indicador-valor" data-contar="${Number(ind.total)}">${Number(ind.total)}</span><span class="mp-indicador-rotulo">Convites enviados</span></li>
            <li class="mp-indicador"><span class="mp-indicador-valor" data-contar="${Number(ind.avaliados)}">${Number(ind.avaliados)}</span><span class="mp-indicador-rotulo">Já avaliaram</span></li>
            <li class="mp-indicador">
                <span class="mp-indicador-valor" data-contar="${Number(ind.taxa)}" data-sufixo="%">${Number(ind.taxa)}%</span><span class="mp-indicador-rotulo">Taxa de resposta</span>
                <progress class="mp-progresso" max="100" value="${Number(ind.taxa)}" aria-label="Taxa de resposta: ${Number(ind.taxa)}%">${Number(ind.taxa)}%</progress>
            </li>
            <li class="mp-indicador"><span class="mp-indicador-valor">${nota}</span><span class="mp-indicador-rotulo">Minha nota média</span></li>
        </ul>

        <div class="mp-grade">
            <section class="mp-cartao" aria-labelledby="mp-form-titulo">
                <h3 class="mp-cartao-titulo" id="mp-form-titulo">📨 Enviar convite de avaliação</h3>
                <form id="mp-form" data-acao-form="enviar" novalidate autocomplete="off">
                    <div class="form-group">
                        <label for="mp-nome">Nome do cidadão</label>
                        <input type="text" id="mp-nome" maxlength="150" required placeholder="Ex.: João da Silva" aria-describedby="mp-erro-nome">
                        <span class="mp-erro-campo" id="mp-erro-nome" role="alert" hidden>Informe o nome do cidadão.</span>
                    </div>
                    <div class="form-group">
                        <label for="mp-email">E-mail do cidadão</label>
                        <input type="email" id="mp-email" maxlength="255" required placeholder="joao@email.com" aria-describedby="mp-erro-email">
                        <span class="mp-erro-campo" id="mp-erro-email" role="alert" hidden>Informe um e-mail válido.</span>
                    </div>
                    <button type="submit" class="btn-primary mp-enviar" id="mp-enviar">📤 Enviar pesquisa por e-mail</button>
                </form>
            </section>

            <section class="mp-cartao" aria-labelledby="mp-como-titulo">
                <h3 class="mp-cartao-titulo" id="mp-como-titulo">ℹ️ Como funciona</h3>
                <ol class="mp-passos">
                    <li>Preencha o <strong>nome e e-mail</strong> do cidadão atendido e clique em Enviar.</li>
                    <li>O cidadão recebe um <strong>link exclusivo e de uso único</strong> para avaliar o atendimento de 1 a 5 estrelas.</li>
                    <li>Se não houver resposta em <strong>${Number(r.reenvio_horas)} horas</strong>, o sistema reenvia o convite <strong>automaticamente uma única vez</strong>.</li>
                    <li>Sem resposta em <strong>${rotuloDias(r.expiracao_token_dias)}</strong>, o link expira e o status passa para <strong>Expirado</strong>.</li>
                    <li>A nota e o comentário aparecem no <strong>histórico abaixo</strong> assim que a avaliação é concluída.</li>
                </ol>
                ${r.quarentena_ativa ? `<p class="mp-regra">🚫 Novos convites para o mesmo e-mail ficam <strong>bloqueados por ${rotuloDias(r.quarentena_dias)}</strong> após o último envio.</p>` : ''}
            </section>
        </div>

        <section class="mp-cartao" aria-labelledby="mp-hist-titulo">
            <div class="mp-hist-cabecalho">
                <h3 class="mp-cartao-titulo" id="mp-hist-titulo">🕘 Histórico de convites</h3>
                <div class="abas-usuarios mp-abas" role="tablist" aria-label="Filtrar convites por situação">
                    ${abas.map(([v, rot, n]) => `<button type="button" role="tab" class="aba-usuarios-btn" data-acao="filtro" data-filtro="${v}"
                        aria-selected="${mpFiltro === v}" tabindex="${mpFiltro === v ? 0 : -1}" aria-controls="mp-tabela">${rot} <span class="mp-contagem">${Number(n)}</span></button>`).join('')}
                </div>
            </div>
            <div id="mp-tabela" role="tabpanel">${montarHistoricoMeuPainel(h)}</div>
        </section>`;
}

function montarHistoricoMeuPainel(h) {
    if (!h.itens.length) {
        return `<p class="mp-aviso">${mpFiltro ? 'Nenhum convite encontrado com este filtro.' : 'Nenhum convite enviado ainda. Use o formulário acima para começar.'}</p>`;
    }
    const situacao = {
        avaliado: '<span class="mp-selo mp-selo--avaliado">✓ Avaliado</span>',
        expirado: '<span class="mp-selo mp-selo--expirado">⊘ Expirado</span>',
        aguardando: '<span class="mp-selo mp-selo--aguardando">◷ Aguardando</span>',
    };
    const linhas = h.itens.map(c => `
        <tr>
            <td>${sanitizeHTML(dataRelativaMeuPainel(c.enviado_em))}
                ${c.origem === 'conecta' ? `<span class="mp-selo-origem" title="Enviado automaticamente pelo Conecta ao encerrar a demanda">Conecta${c.protocolo ? ' · #' + sanitizeHTML(c.protocolo) : ''}</span>` : ''}</td>
            <td>${situacao[c.situacao] || situacao.aguardando}</td>
            <td>${c.nota ? `<span class="mp-estrelas" aria-label="${Number(c.nota)} de 5 estrelas">${'★'.repeat(c.nota)}${'☆'.repeat(5 - c.nota)}</span>` : '<span class="mp-traco">—</span>'}</td>
            <td>${c.comentario ? sanitizeHTML(c.comentario) : '<span class="mp-traco">—</span>'}</td>
        </tr>`).join('');
    return `
        <div class="mp-tabela-wrap"><table class="mp-tabela">
            <caption class="sr-only">Histórico de convites de avaliação enviados</caption>
            <thead><tr><th scope="col">Enviado</th><th scope="col">Situação</th><th scope="col">Nota</th><th scope="col">Comentário</th></tr></thead>
            <tbody>${linhas}</tbody>
        </table></div>
        ${montarPaginacaoMeuPainel(h)}`;
}

function montarPaginacaoMeuPainel(h) {
    if (h.total_paginas <= 1) return '';
    const p = h.pagina;
    const botoes = [];
    const botao = (n, rotulo, aria) => `<button type="button" class="mp-pagina${n === p ? ' mp-pagina--atual' : ''}" data-acao="pagina" data-pagina="${n}"
        ${n === p ? 'aria-current="page"' : ''} ${aria ? `aria-label="${aria}"` : ''}>${rotulo}</button>`;
    if (p > 1) botoes.push(botao(p - 1, '‹', 'Página anterior'));
    const ini = Math.max(1, p - 2), fim = Math.min(h.total_paginas, p + 2);
    if (ini > 1) botoes.push(botao(1, '1'), ini > 2 ? '<span class="mp-reticencias">…</span>' : '');
    for (let i = ini; i <= fim; i++) botoes.push(botao(i, String(i)));
    if (fim < h.total_paginas) botoes.push(fim < h.total_paginas - 1 ? '<span class="mp-reticencias">…</span>' : '', botao(h.total_paginas, String(h.total_paginas)));
    if (p < h.total_paginas) botoes.push(botao(p + 1, '›', 'Próxima página'));
    return `<nav class="mp-paginacao" aria-label="Páginas do histórico">${botoes.join('')}<span class="mp-pagina-info">Página ${p} de ${h.total_paginas}</span></nav>`;
}

// Contagem animada dos indicadores (sem animação para quem pediu menos movimento)
function animarIndicadoresMeuPainel() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    document.querySelectorAll('#meu-painel [data-contar]').forEach(el => {
        const alvo = Number(el.dataset.contar) || 0;
        const sufixo = el.dataset.sufixo || '';
        const inicio = performance.now();
        const passo = (agora) => {
            const t = Math.min(1, (agora - inicio) / 700);
            el.textContent = Math.round(alvo * t) + sufixo;
            if (t < 1) requestAnimationFrame(passo);
        };
        requestAnimationFrame(passo);
    });
}

// Widget do Google Reviews (Elfsight): carrega o script uma vez; sem internet a tela segue funcionando
function carregarWidgetGoogleReviews() {
    if (document.querySelector(`script[src="${MP_ELFSIGHT_SCRIPT}"]`)) return;
    const s = document.createElement('script');
    s.src = MP_ELFSIGHT_SCRIPT;
    s.async = true;
    s.onerror = () => {
        const caixa = document.querySelector(`#meu-painel .${MP_ELFSIGHT_APP}`);
        if (caixa) caixa.innerHTML = '<p class="mp-aviso">Não foi possível carregar as avaliações do Google agora.</p>';
    };
    document.head.appendChild(s);
}

async function enviarConviteMeuPainel() {
    const nome = document.getElementById('mp-nome');
    const email = document.getElementById('mp-email');
    const erroNome = document.getElementById('mp-erro-nome');
    const erroEmail = document.getElementById('mp-erro-email');
    erroNome.hidden = nome.value.trim() !== '';
    erroEmail.hidden = email.value.trim() !== '' && email.checkValidity();
    nome.setAttribute('aria-invalid', String(!erroNome.hidden));
    email.setAttribute('aria-invalid', String(!erroEmail.hidden));
    if (!erroNome.hidden) return nome.focus();
    if (!erroEmail.hidden) return email.focus();

    const botao = document.getElementById('mp-enviar');
    botao.disabled = true;
    botao.textContent = '⏳ Enviando…';
    try {
        const r = await Api.post('meu-painel.php', { destinatario_nome: nome.value.trim(), destinatario_email: email.value.trim() });
        mostrarToast('Sucesso', r.mensagem || 'Convite enviado!', 'success');
        nome.value = '';
        email.value = '';
        mpFiltro = '';
        mpPagina = 1;
        await carregarMeuPainel();
    } catch (e) {
        // Quarentena ou falha: os campos continuam preenchidos (como no METRICA360)
        mostrarAlert('Convite não enviado', mensagemDeErro(e, 'Não foi possível enviar o convite.'));
        botao.disabled = false;
        botao.textContent = '📤 Enviar pesquisa por e-mail';
    }
}

function ligarAcoesMeuPainel(conteudo) {
    if (conteudo.dataset.mpLigado) return;
    conteudo.dataset.mpLigado = '1';
    conteudo.addEventListener('click', (ev) => {
        const alvo = ev.target.closest('[data-acao]');
        if (!alvo) return;
        if (alvo.dataset.acao === 'filtro') trocarFiltroMeuPainel(alvo.dataset.filtro);
        else if (alvo.dataset.acao === 'pagina') { mpPagina = Number(alvo.dataset.pagina) || 1; carregarMeuPainel(); }
        else if (alvo.dataset.acao === 'tentar') carregarMeuPainel();
    });
    conteudo.addEventListener('submit', (ev) => {
        if (!ev.target.closest('[data-acao-form="enviar"]')) return;
        ev.preventDefault();
        enviarConviteMeuPainel();
    });
    conteudo.addEventListener('keydown', (ev) => {
        const aba = ev.target.closest('[role="tab"]');
        if (!aba || (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft')) return;
        const abas = [...conteudo.querySelectorAll('[role="tab"]')];
        const destino = abas[(abas.indexOf(aba) + (ev.key === 'ArrowRight' ? 1 : -1) + abas.length) % abas.length];
        ev.preventDefault();
        trocarFiltroMeuPainel(destino.dataset.filtro, true);
    });
}

async function trocarFiltroMeuPainel(filtro, focar) {
    mpFiltro = filtro || '';
    mpPagina = 1;
    await carregarMeuPainel();
    if (focar) document.querySelector(`#meu-painel [role="tab"][data-filtro="${mpFiltro}"]`)?.focus();
}

// Item do menu (sem onclick no HTML)
document.addEventListener('click', (ev) => {
    if (ev.target.closest('#btn-nav-meu-painel')) mostrarMeuPainel();
});
