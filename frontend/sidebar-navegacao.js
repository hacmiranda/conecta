/**
 * Sidebar: destaque do submenu da tela atual e botão de recolher/expandir.
 *
 * Destaque: cada item de Ajuda/Configurações tem `data-subsecao` igual ao valor que a sua tela
 * grava em `cd_subSecaoAtiva`. Depois de qualquer navegação, o item da subtela aberta ganha
 * `.active` + `aria-current="page"` e o grupo dele fica aberto.
 *
 * Recolher: classe `recolhida` em #app-sidebar, salva em `cd_sidebarRecolhida`. Recolhida, a
 * sidebar mostra só os ícones; abrir um grupo (Ajuda/Configurações) ou o menu do avatar expande
 * a sidebar antes, porque esses menus não cabem na largura reduzida.
 */
(function () {
    'use strict';

    const CHAVE_RECOLHIDA = 'cd_sidebarRecolhida';

    function lerArmazenamento(chave) {
        try { return localStorage.getItem(chave); } catch (e) { return null; }
    }

    function gravarArmazenamento(chave, valor) {
        try { localStorage.setItem(chave, valor); } catch (e) { /* sem armazenamento: só não lembra */ }
    }

    // ===== Destaque do submenu =====

    function marcarSubmenuAtivo(abrirGrupo) {
        const secaoAtiva = document.querySelector('.secao.active');
        const secao = secaoAtiva ? secaoAtiva.id : '';
        const subsecao = lerArmazenamento('cd_subSecaoAtiva');

        document.querySelectorAll('.sidebar-nav .nav-dropdown-item[data-subsecao]').forEach(function (item) {
            item.classList.remove('active');
            item.removeAttribute('aria-current');
        });

        if ((secao !== 'ajuda' && secao !== 'config') || !subsecao) return;

        const dropdown = document.getElementById('nav-' + secao + '-dropdown');
        if (!dropdown) return;
        const item = dropdown.querySelector('.nav-dropdown-item[data-subsecao="' + CSS.escape(subsecao) + '"]');
        if (!item) return;

        item.classList.add('active');
        item.setAttribute('aria-current', 'page');

        // Ao navegar, abre o grupo para o item destacado ficar visível (não com a sidebar recolhida;
        // um clique qualquer não reabre um grupo que o usuário fechou)
        const sidebar = document.getElementById('app-sidebar');
        if (abrirGrupo && !dropdown.classList.contains('open') && !(sidebar && sidebar.classList.contains('recolhida'))
            && typeof toggleNavDropdown === 'function') {
            toggleNavDropdown(secao);
        }
    }

    // As telas gravam a subseção logo no início do clique; adiar um ciclo garante o valor novo
    function agendarMarcacao(abrirGrupo) {
        setTimeout(function () { marcarSubmenuAtivo(abrirGrupo === true); }, 0);
    }

    // ===== Recolher/expandir =====

    function textoDoBotao(btn) {
        const rotulo = btn.querySelector('.nav-label');
        return rotulo ? rotulo.textContent.trim() : '';
    }

    function aplicarRecolhida(recolhida, salvar) {
        const sidebar = document.getElementById('app-sidebar');
        const botao = document.getElementById('btn-recolher-sidebar');
        if (!sidebar || !botao) return;

        sidebar.classList.toggle('recolhida', recolhida);
        botao.setAttribute('aria-expanded', String(!recolhida));
        botao.setAttribute('aria-label', recolhida ? 'Expandir menu' : 'Recolher menu');
        const icone = botao.querySelector('.nav-icon');
        if (icone) icone.textContent = recolhida ? 'keyboard_double_arrow_right' : 'keyboard_double_arrow_left';
        const rotulo = botao.querySelector('.nav-label');
        if (rotulo) rotulo.textContent = recolhida ? 'Expandir menu' : 'Recolher menu';

        // Recolhida: dica ao passar o mouse com o nome de cada item (o texto continua para leitores de tela)
        sidebar.querySelectorAll('.sidebar-nav .btn-nav, #btn-recolher-sidebar').forEach(function (btn) {
            if (recolhida) {
                btn.title = textoDoBotao(btn);
            } else {
                btn.removeAttribute('title');
            }
        });

        if (salvar) gravarArmazenamento(CHAVE_RECOLHIDA, recolhida ? '1' : '0');
    }

    function sidebarRecolhida() {
        const sidebar = document.getElementById('app-sidebar');
        return !!(sidebar && sidebar.classList.contains('recolhida'));
    }

    function iniciar() {
        const sidebar = document.getElementById('app-sidebar');
        const botao = document.getElementById('btn-recolher-sidebar');
        if (!sidebar || !botao) return;

        aplicarRecolhida(lerArmazenamento(CHAVE_RECOLHIDA) === '1', false);

        botao.addEventListener('click', function () {
            aplicarRecolhida(!sidebarRecolhida(), true);
        });

        // Fase de captura: roda antes do onclick do próprio botão do grupo/avatar
        sidebar.addEventListener('click', function (e) {
            if (!sidebarRecolhida()) return;
            const grupo = e.target.closest('.nav-group-toggle');
            const avatar = e.target.closest('#header-avatar-btn');
            if (!grupo && !avatar) return;

            aplicarRecolhida(false, true);
            // Grupo que já estava aberto: só expande a sidebar, sem fechar o grupo
            if (grupo) {
                const dropdown = document.getElementById(grupo.getAttribute('aria-controls'));
                if (dropdown && dropdown.classList.contains('open')) e.stopPropagation();
            }
        }, true);

        // Depois de qualquer clique (sidebar, menu do avatar, atalhos), reavalia o destaque
        document.addEventListener('click', agendarMarcacao);

        // Navegação sem clique (atalhos de teclado, restauração da tela salva)
        if (typeof window.mostrarSecao === 'function') {
            const mostrarSecaoOriginal = window.mostrarSecao;
            window.mostrarSecao = function () {
                const resultado = mostrarSecaoOriginal.apply(this, arguments);
                agendarMarcacao(true);
                return resultado;
            };
        }

        agendarMarcacao(true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
