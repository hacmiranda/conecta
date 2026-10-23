/**
 * Ajusta a altura do quadro do e-mail ao conteúdo (mockups/paginas/*.html).
 * Funciona quando a página é aberta por http://; por file:// o navegador bloqueia a leitura do
 * iframe e a altura padrão do CSS continua valendo.
 */
(function () {
    'use strict';

    function ajustar(quadro) {
        try {
            const doc = quadro.contentDocument;
            if (!doc || !doc.documentElement) return;
            // Zera antes de medir: com a altura atual, scrollHeight nunca fica menor que o quadro
            quadro.style.height = '0px';
            quadro.style.height = Math.max(doc.documentElement.scrollHeight, 200) + 'px';
        } catch (e) { /* file://: mantém a altura padrão */ }
    }

    document.querySelectorAll('.email-quadro').forEach(function (quadro) {
        quadro.addEventListener('load', function () { ajustar(quadro); });
        ajustar(quadro);
    });
})();
