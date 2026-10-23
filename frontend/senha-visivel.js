/**
 * Botão "mostrar/ocultar senha" em todo campo de senha do sistema.
 *
 * Procura os <input type="password"> da página e acompanha os que surgem depois (modais e
 * formulários montados por innerHTML), colocando um botão com ícone de olho ao lado de cada um.
 * Campos que já têm botão próprio (ex.: tela de login, `.btn-toggle-pass`) são ignorados.
 */
(function () {
    'use strict';

    const ICONE_MOSTRAR = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
        + '<path fill="currentColor" d="M12 5C6.5 5 2.7 8.6 1 12c1.7 3.4 5.5 7 11 7s9.3-3.6 11-7c-1.7-3.4-5.5-7-11-7zm0 '
        + '11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/></svg>';
    const ICONE_OCULTAR = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
        + '<path fill="currentColor" d="M2.3 3.7 3.7 2.3l18 18-1.4 1.4-3.3-3.3A11.9 11.9 0 0 1 12 19C6.5 19 2.7 15.4 1 12c.8-1.7 '
        + '2.1-3.4 3.8-4.8L2.3 3.7zm7.4 7.4a2.5 2.5 0 0 0 3.2 3.2l-3.2-3.2zM12 5c5.5 0 9.3 3.6 11 7-.7 1.5-1.8 3-3.2 '
        + '4.2l-3.3-3.3A4.5 4.5 0 0 0 11.1 7.5L8.6 5.5C9.7 5.2 10.8 5 12 5z"/></svg>';

    let contador = 0;

    function aplicarEstado(input, botao, visivel) {
        input.type = visivel ? 'text' : 'password';
        botao.setAttribute('aria-pressed', String(visivel));
        botao.setAttribute('aria-label', visivel ? 'Ocultar senha' : 'Mostrar senha');
        botao.title = visivel ? 'Ocultar senha' : 'Mostrar senha';
        botao.innerHTML = visivel ? ICONE_OCULTAR : ICONE_MOSTRAR;
    }

    function prepararCampo(input) {
        if (input.dataset.senhaVisivel) return;
        const pai = input.parentElement;
        if (!pai || pai.querySelector('.btn-toggle-pass, .senha-olho')) return;
        input.dataset.senhaVisivel = '1';

        if (!input.id) input.id = 'campo-senha-' + (++contador);

        const envoltorio = document.createElement('span');
        envoltorio.className = 'campo-senha';
        pai.insertBefore(envoltorio, input);
        envoltorio.appendChild(input);
        input.classList.add('campo-senha-input');

        const botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'senha-olho';
        botao.setAttribute('aria-controls', input.id);
        aplicarEstado(input, botao, false);
        botao.addEventListener('click', function () {
            aplicarEstado(input, botao, input.type === 'password');
            input.focus();
        });
        envoltorio.appendChild(botao);
    }

    function varrer(raiz) {
        if (!raiz || !raiz.querySelectorAll) return;
        if (raiz.matches && raiz.matches('input[type="password"]')) prepararCampo(raiz);
        raiz.querySelectorAll('input[type="password"]').forEach(prepararCampo);
    }

    function iniciar() {
        varrer(document.body);
        new MutationObserver(function (mudancas) {
            mudancas.forEach(function (m) { m.addedNodes.forEach(varrer); });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
