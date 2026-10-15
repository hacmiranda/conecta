/**
 * Conecta CRPRS — Acessibilidade transversal (Fase 12, itens A1-A3).
 * Sem dependências: aplica-se a qualquer tela e a qualquer conteúdo renderizado depois.
 *
 * A1: modais customizados (#modal-overlay) ganham foco preso, fecham com ESC e devolvem
 *     o foco a quem abriu — sem precisar alterar cada função que hoje já abre/fecha um modal
 *     (mostrarAlert, mostrarConfirm, mostrarSelect, abrirModalMacros, openAvatarModal...).
 * A2: qualquer <div>/<span>/<tr>/<li> com onclick (hoje só clicável pelo mouse) passa a
 *     receber foco pelo teclado (Tab) e a disparar o onclick com Enter/Espaço.
 * A3: toasts ganham uma região aria-live, para leitores de tela anunciarem "demanda salva",
 *     erros etc. sem precisar que o usuário navegue até lá.
 */
(function () {
    'use strict';

    // ===================== A2: elementos clicáveis por onclick =====================

    const SELETOR_CLICAVEIS = 'div[onclick], span[onclick], tr[onclick], li[onclick]';

    function tornarAcessivel(el) {
        // aria-hidden="true" é uma decisão deliberada de excluir da árvore de acessibilidade;
        // dar foco por Tab a um elemento assim criaria uma parada "fantasma" para quem usa teclado.
        if (el.getAttribute('aria-hidden') === 'true') return;
        if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
        if (!el.hasAttribute('role')) el.setAttribute('role', 'button');
    }

    function varrerClicaveis(raiz) {
        if (raiz.matches && raiz.matches(SELETOR_CLICAVEIS)) tornarAcessivel(raiz);
        if (raiz.querySelectorAll) raiz.querySelectorAll(SELETOR_CLICAVEIS).forEach(tornarAcessivel);
    }

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ' && ev.key !== 'Spacebar') return;
        const el = ev.target;
        if (!el || !el.hasAttribute || !el.hasAttribute('onclick')) return;
        if (!['DIV', 'SPAN', 'TR', 'LI'].includes(el.tagName)) return;
        ev.preventDefault();
        el.click();
    });

    // ===================== A1: modais acessíveis (foco, ESC, retorno) =====================

    let modalAberto = null;
    let focoAnterior = null;

    function elementosFocaveis(modal) {
        return Array.from(modal.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(el => el.offsetParent !== null);
    }

    function garantirAtributosDialogo(modal) {
        if (!modal.hasAttribute('role')) modal.setAttribute('role', 'dialog');
        if (!modal.hasAttribute('aria-modal')) modal.setAttribute('aria-modal', 'true');
        if (!modal.hasAttribute('aria-labelledby') && !modal.hasAttribute('aria-label')) {
            const titulo = modal.querySelector('h2, h3, h4');
            if (titulo) {
                if (!titulo.id) titulo.id = (modal.id || 'modal') + '-titulo-auto';
                modal.setAttribute('aria-labelledby', titulo.id);
            }
        }
    }

    function aoAbrirModal(modal) {
        modalAberto = modal;
        focoAnterior = document.activeElement;
        garantirAtributosDialogo(modal);
        const alvos = elementosFocaveis(modal);
        (alvos[0] || modal).focus({ preventScroll: true });
    }

    function aoFecharModal() {
        if (focoAnterior && document.contains(focoAnterior) && typeof focoAnterior.focus === 'function') {
            focoAnterior.focus({ preventScroll: true });
        }
        focoAnterior = null;
        modalAberto = null;
    }

    // ESC: aciona o botão de fechar/cancelar que o próprio modal já usa (não duplica lógica)
    document.addEventListener('keydown', function (ev) {
        if (!modalAberto) return;

        if (ev.key === 'Escape') {
            const botaoFechar = modalAberto.querySelector('[data-fecha-modal], .btn-close-modal');
            if (botaoFechar) {
                ev.preventDefault();
                botaoFechar.click();
            }
            return;
        }

        if (ev.key === 'Tab') {
            const alvos = elementosFocaveis(modalAberto);
            if (!alvos.length) return;
            const primeiro = alvos[0];
            const ultimo = alvos[alvos.length - 1];
            if (ev.shiftKey && document.activeElement === primeiro) {
                ev.preventDefault();
                ultimo.focus();
            } else if (!ev.shiftKey && document.activeElement === ultimo) {
                ev.preventDefault();
                primeiro.focus();
            }
        }
    });

    function observarModais(raiz) {
        raiz.querySelectorAll('.modal').forEach(modal => {
            observerModal.observe(modal, { attributes: true, attributeFilter: ['class'] });
        });
    }

    const observerModal = new MutationObserver(function (mutacoes) {
        mutacoes.forEach(function (m) {
            const modal = m.target;
            const aberto = modal.classList.contains('show');
            // Outro modal já está de fato aberto (ex.: as dicas iniciais, com setTimeout próprio):
            // não disputa o foco com ele; quem abriu primeiro o mantém.
            const outroJaAberto = modalAberto && modalAberto !== modal && modalAberto.classList.contains('show');
            if (aberto && modal !== modalAberto && !outroJaAberto) {
                aoAbrirModal(modal);
            } else if (!aberto && modal === modalAberto) {
                aoFecharModal();
            }
        });
    });

    // ===================== Conteúdo renderizado dinamicamente =====================
    // Telas e modais são montados via innerHTML depois do carregamento inicial: um observer
    // no <body> garante que A1/A2 continuem valendo para o que a aplicação cria depois.
    const observerConteudo = new MutationObserver(function (mutacoes) {
        mutacoes.forEach(function (m) {
            m.addedNodes.forEach(function (n) {
                if (n.nodeType !== 1) return;
                varrerClicaveis(n);
                observarModais(n);
            });
        });
    });

    function iniciar() {
        varrerClicaveis(document);
        observarModais(document);
        observerConteudo.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    // ===================== A3: toasts anunciados por leitor de tela =====================

    function aplicarAriaLiveToasts() {
        const container = document.getElementById('toast-container');
        if (!container) return;
        if (!container.hasAttribute('role')) container.setAttribute('role', 'status');
        if (!container.hasAttribute('aria-live')) container.setAttribute('aria-live', 'polite');
        if (!container.hasAttribute('aria-atomic')) container.setAttribute('aria-atomic', 'true');

        // Toasts de erro/perigo são mais urgentes: role="alert" no próprio toast
        new MutationObserver(function (mutacoes) {
            mutacoes.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1 || !n.classList || !n.classList.contains('toast')) return;
                    if (n.classList.contains('error') || n.classList.contains('danger')) {
                        n.setAttribute('role', 'alert');
                    }
                });
            });
        }).observe(container, { childList: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', aplicarAriaLiveToasts);
    } else {
        aplicarAriaLiveToasts();
    }
})();
