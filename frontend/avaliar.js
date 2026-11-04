/**
 * Página pública de avaliação (avaliar.html). O token do convite vem no fragmento da URL (#t=...), que o navegador
 * não envia ao servidor nem aparece em logs. Fala só com api/controllers/pesquisa-publica.php (sem login, sem cookie).
 * Fluxo igual ao formulário do METRICA360: nota → categorias e comentário aparecem → envio → modal positivo (4 e 5,
 * com o convite para o Google, se o link estiver configurado) ou crítico (1 a 3) → tela de agradecimento.
 */
(function () {
    'use strict';

    const API = (window.APP_CONFIG && window.APP_CONFIG.apiBase ? window.APP_CONFIG.apiBase : '/conecta/api/controllers/') + 'pesquisa-publica.php';
    const $ = (id) => document.getElementById(id);

    function tokenDaUrl() {
        const m = /(?:^#|&)t=([a-f0-9]{64})(?:&|$)/i.exec(window.location.hash || '');
        return m ? m[1].toLowerCase() : '';
    }

    const token = tokenDaUrl();
    let modalAberto = null;
    let tipoResultado = '';

    function mostrar(secao) {
        ['av-carregando', 'av-aviso', 'av-formulario', 'av-obrigado'].forEach(id => { $(id).hidden = id !== secao; });
    }

    // Textos do formulário do METRICA360
    const AVISOS = {
        invalido: ['🔒', 'Acesso restrito', 'Este formulário só pode ser acessado através do link enviado por e-mail pelo CRPRS. Se você recebeu um convite de avaliação, clique no link contido no e-mail.'],
        expirado: ['⏰', 'Link expirado', 'Este link de avaliação não está mais válido. Se desejar avaliar o atendimento, entre em contato com o CRPRS para solicitar um novo convite.'],
        ja_avaliado: ['✅', 'Avaliação já registrada', 'Este link de avaliação já foi utilizado anteriormente.'],
        muitas: ['⏳', 'Muitas tentativas', 'Aguarde alguns minutos e abra o link de novo.'],
        falha: ['⚠️', 'Não foi possível carregar', 'Tente de novo em alguns instantes.'],
        sem_conexao: ['📡', 'Sem conexão', 'Não foi possível falar com o servidor. Verifique sua internet e tente de novo.'],
    };

    function aviso(chave) {
        const [icone, titulo, texto] = AVISOS[chave] || AVISOS.invalido;
        $('av-aviso-icone').textContent = icone;
        $('av-aviso-titulo').textContent = titulo;
        $('av-aviso-texto').textContent = texto;
        mostrar('av-aviso');
        $('av-painel').focus();
    }

    async function chamar(opcoes, query) {
        const resp = await fetch(API + (query || ''), Object.assign({ headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, credentials: 'omit', cache: 'no-store' }, opcoes));
        let dados = null;
        try { dados = await resp.json(); } catch (e) { /* resposta sem JSON */ }
        return { http: resp.status, dados: dados || {} };
    }

    async function iniciar() {
        if (!token) return aviso('invalido');
        try {
            const { http, dados } = await chamar({ method: 'GET' }, '?t=' + encodeURIComponent(token));
            if (http === 429) return aviso('muitas');
            if (http !== 200) return aviso('falha');
            if (dados.situacao !== 'ok') return aviso(AVISOS[dados.situacao] ? dados.situacao : 'invalido');
            montarCategorias(dados.categorias || []);
            mostrar('av-formulario');
        } catch (e) {
            aviso('sem_conexao');
        }
    }

    let temCategorias = false;

    function montarCategorias(lista) {
        const caixa = $('av-categorias');
        caixa.textContent = '';
        lista.forEach(c => {
            const rotulo = document.createElement('label');
            rotulo.className = 'av-chip';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'categoria';
            input.value = String(c.id);
            const texto = document.createElement('span');
            texto.textContent = (c.emoji ? c.emoji + ' ' : '') + c.nome;
            rotulo.append(input, texto);
            caixa.appendChild(rotulo);
        });
        temCategorias = lista.length > 0;
    }

    // Primeira nota escolhida: libera o envio e revela categorias e comentário
    function aoEscolherNota(nota) {
        if (navigator.vibrate) navigator.vibrate(40);
        $('av-categorias-grupo').hidden = !temCategorias;
        $('av-comentario-grupo').hidden = false;
        $('av-comentario').placeholder = nota <= 3
            ? 'Por favor, explique o que podemos melhorar… (opcional)'
            : 'Compartilhe o que mais gostou! (opcional)';
        $('av-enviar').disabled = false;
        $('av-dica-envio').hidden = true;
    }

    function erro(texto) {
        const alvo = $('av-erro');
        alvo.textContent = texto;
        alvo.hidden = !texto;
    }

    // ── Modais ──
    function abrirModal(id, focar) {
        modalAberto = $(id);
        modalAberto.hidden = false;
        (focar || modalAberto.querySelector('button')).focus();
    }

    function fecharModal() {
        if (!modalAberto) return;
        modalAberto.hidden = true;
        modalAberto = null;
        mostrarAgradecimento(tipoResultado);
    }

    // Tab e Shift+Tab ficam dentro do modal aberto; ESC fecha
    function teclaNoModal(ev) {
        if (!modalAberto) return;
        if (ev.key === 'Escape') {
            ev.preventDefault();
            fecharModal();
            return;
        }
        if (ev.key !== 'Tab') return;
        const focaveis = Array.from(modalAberto.querySelectorAll('a[href], button')).filter(el => el.offsetParent !== null);
        if (focaveis.length === 0) return;
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        if (ev.shiftKey && document.activeElement === primeiro) {
            ev.preventDefault();
            ultimo.focus();
        } else if (!ev.shiftKey && document.activeElement === ultimo) {
            ev.preventDefault();
            primeiro.focus();
        }
    }

    function mostrarAgradecimento(tipo) {
        const positivo = tipo === 'positivo';
        $('av-obrigado-icone').textContent = positivo ? '🌟' : '💚';
        $('av-obrigado-titulo').textContent = positivo ? 'Que ótimo! Obrigado!' : 'Recebemos seu retorno';
        $('av-obrigado-traco').classList.toggle('critico', !positivo);
        $('av-obrigado-texto').textContent = positivo
            ? 'Sua satisfação é o nosso maior reconhecimento. Continuaremos trabalhando para manter esse padrão de excelência.'
            : 'Sentimos muito pela experiência. Nossa equipe vai analisar seu caso com prioridade e trabalhar para melhorar.';
        mostrar('av-obrigado');
        $('av-painel').focus();
    }

    function aposEnviar(dados) {
        tipoResultado = dados.tipo === 'positivo' ? 'positivo' : 'critico';
        if (tipoResultado === 'positivo') {
            // Sem link do Google configurado, o modal só agradece
            const google = /^https:\/\//i.test(dados.google_url || '');
            $('av-google').hidden = !google;
            if (google) $('av-google-link').href = dados.google_url;
            $('av-fechar-positivo').textContent = google ? 'Prefiro não avaliar no Google agora' : 'Fechar';
            abrirModal('av-modal-positivo', google ? $('av-google-link') : $('av-fechar-positivo'));
        } else {
            abrirModal('av-modal-critico', $('av-fechar-critico'));
        }
    }

    async function enviar(ev) {
        ev.preventDefault();
        erro('');
        const nota = document.querySelector('input[name="nota"]:checked');
        if (!nota) return erro('Selecione uma nota para continuar.');
        const botao = $('av-enviar');
        botao.disabled = true;
        $('av-enviando').hidden = false;
        try {
            const { http, dados } = await chamar({
                method: 'POST',
                body: JSON.stringify({
                    token,
                    nota: Number(nota.value),
                    comentario: $('av-comentario').value,
                    categorias: Array.from(document.querySelectorAll('input[name="categoria"]:checked')).map(c => Number(c.value)),
                }),
            });
            $('av-enviando').hidden = true;
            if (http === 200 && dados.sucesso) return aposEnviar(dados);
            if (dados.codigo && AVISOS[dados.codigo]) return aviso(dados.codigo);
            erro(dados.mensagem || 'Erro ao enviar. Tente novamente.');
        } catch (e) {
            $('av-enviando').hidden = true;
            erro('Erro ao enviar. Verifique sua internet e tente novamente.');
        }
        botao.disabled = false;
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('input[name="nota"]').forEach(r => r.addEventListener('change', () => aoEscolherNota(Number(r.value))));
        $('av-comentario').addEventListener('input', () => { $('av-contador').textContent = $('av-comentario').value.length + ' / 1000'; });
        $('av-form').addEventListener('submit', enviar);
        document.querySelectorAll('[data-fechar-modal]').forEach(b => b.addEventListener('click', fecharModal));
        // Clique no fundo escuro também fecha
        document.querySelectorAll('.av-modal-fundo').forEach(f => f.addEventListener('click', ev => { if (ev.target === f) fecharModal(); }));
        document.addEventListener('keydown', teclaNoModal);
        iniciar();
    });
})();
