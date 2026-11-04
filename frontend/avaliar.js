/**
 * Página pública de avaliação (avaliar.html). O token do convite vem no fragmento da URL (#t=...), que o navegador
 * não envia ao servidor nem aparece em logs. Fala só com api/controllers/pesquisa-publica.php (sem login).
 * Estados: carregando → aviso (link inválido, vencido ou já usado) | formulário → agradecimento.
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

    function mostrar(secao) {
        ['av-carregando', 'av-aviso', 'av-formulario', 'av-obrigado'].forEach(id => { $(id).hidden = id !== secao; });
    }

    function aviso(titulo, texto) {
        $('av-aviso-titulo').textContent = titulo;
        $('av-aviso-texto').textContent = texto;
        mostrar('av-aviso');
        $('av-aviso').focus && $('av-aviso').setAttribute('tabindex', '-1');
        $('av-aviso').focus();
    }

    const AVISOS = {
        invalido: ['Acesso restrito', 'Este link de avaliação não é válido. Use o link enviado no convite por e-mail.'],
        ja_avaliado: ['Avaliação já registrada', 'Este atendimento já foi avaliado. Muito obrigado pela sua participação!'],
        expirado: ['Link expirado', 'O prazo para avaliar este atendimento terminou. Agradecemos o seu interesse.'],
    };

    async function chamar(opcoes, query) {
        const resp = await fetch(API + (query || ''), Object.assign({ headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, credentials: 'omit', cache: 'no-store' }, opcoes));
        let dados = null;
        try { dados = await resp.json(); } catch (e) { /* resposta sem JSON */ }
        return { http: resp.status, dados: dados || {} };
    }

    async function iniciar() {
        if (!token) {
            return aviso(...AVISOS.invalido);
        }
        try {
            const { http, dados } = await chamar({ method: 'GET' }, '?t=' + encodeURIComponent(token));
            if (http === 429) return aviso('Muitas tentativas', 'Aguarde alguns minutos e abra o link de novo.');
            if (http !== 200) return aviso('Não foi possível carregar', 'Tente de novo em alguns instantes.');
            if (dados.situacao !== 'ok') return aviso(...(AVISOS[dados.situacao] || AVISOS.invalido));
            montarCategorias(dados.categorias || []);
            mostrar('av-formulario');
        } catch (e) {
            aviso('Sem conexão', 'Não foi possível falar com o servidor. Verifique sua internet e tente de novo.');
        }
    }

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
        $('av-categorias-grupo').hidden = lista.length === 0;
    }

    function erro(texto) {
        const alvo = $('av-erro');
        alvo.textContent = texto;
        alvo.hidden = !texto;
    }

    function agradecer(dados) {
        const positivo = dados.tipo === 'positivo';
        $('av-obrigado-titulo').textContent = positivo ? 'Muito obrigado!' : 'Obrigado pelo seu retorno';
        $('av-obrigado-texto').textContent = positivo
            ? 'Ficamos felizes em saber que o seu atendimento foi bom. Sua opinião nos ajuda a melhorar sempre.'
            : 'Lamentamos que a sua experiência não tenha sido a ideal. A sua opinião foi registrada e será analisada pela nossa equipe.';
        const google = positivo && /^https:\/\//i.test(dados.google_url || '');
        $('av-google').hidden = !google;
        if (google) $('av-google-link').href = dados.google_url;
        mostrar('av-obrigado');
        $('av-obrigado').setAttribute('tabindex', '-1');
        $('av-obrigado').focus();
    }

    async function enviar(ev) {
        ev.preventDefault();
        erro('');
        const nota = document.querySelector('input[name="nota"]:checked');
        if (!nota) return erro('Escolha uma nota de 1 a 5 estrelas.');
        const botao = $('av-enviar');
        botao.disabled = true;
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
            if (http === 200 && dados.sucesso) return agradecer(dados);
            if (dados.codigo && AVISOS[dados.codigo]) return aviso(...AVISOS[dados.codigo]);
            erro(dados.mensagem || 'Não foi possível enviar. Tente novamente.');
        } catch (e) {
            erro('Sem conexão. Verifique sua internet e tente novamente.');
        }
        botao.disabled = false;
    }

    document.addEventListener('DOMContentLoaded', () => {
        // A nota escolhida libera categorias e comentário (nota baixa convida a explicar)
        document.querySelectorAll('input[name="nota"]').forEach(r => r.addEventListener('change', () => {
            $('av-detalhes').hidden = false;
            $('av-comentario').placeholder = Number(r.value) <= 3 ? 'Conte-nos o que podemos melhorar' : 'Quer deixar um elogio ou sugestão?';
        }));
        $('av-comentario').addEventListener('input', () => { $('av-contador').textContent = $('av-comentario').value.length + ' / 1000'; });
        $('av-form').addEventListener('submit', enviar);
        iniciar();
    });
})();
