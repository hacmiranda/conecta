/**
 * Cabeçalho do Conecta CRPRS: nome do setor logo abaixo de "Conecta CRPRS" e,
 * para o administrador, o seletor de setor que define o contexto das telas
 * (painel, Arquivo, Dashboard, Relatórios e Configurações).
 *
 * Gestor e operador veem apenas o nome do próprio setor — o servidor sempre
 * limita o que eles enxergam, independentemente do que o navegador enviar.
 */
(function () {
    'use strict';

    const CHAVE_CONTEXTO = 'cd_setor_contexto';

    // Seções que precisam recarregar os dados quando o admin troca de setor
    const RECARREGADORES = {
        tickets: function () { if (typeof carregarTickets === 'function') carregarTickets(); },
        fechados: function () { if (typeof carregarTicketsFechados === 'function') carregarTicketsFechados(); },
        dashboard: function () { if (typeof carregarDashboard === 'function') carregarDashboard(); },
        relatorios: function () { if (typeof carregarFiltrosRelatorio === 'function') carregarFiltrosRelatorio(); }
    };

    function lerUsuario() {
        try {
            return JSON.parse(localStorage.getItem('cd_usuario') || 'null');
        } catch (e) {
            return null;
        }
    }

    // Setor do contexto atual: do próprio usuário, ou o escolhido pelo admin (null = todos)
    function obterSetorContexto() {
        const usuario = lerUsuario();
        if (!usuario) return null;
        if (usuario.tipo !== 'admin') {
            return usuario.setor_id ? Number(usuario.setor_id) : null;
        }
        let salvo = null;
        try {
            salvo = localStorage.getItem(CHAVE_CONTEXTO);
        } catch (e) { }
        return salvo ? Number(salvo) : null;
    }

    // Acrescenta setor_id à URL quando o admin escolheu um setor
    function anexarSetor(url) {
        const usuario = lerUsuario();
        if (!usuario || usuario.tipo !== 'admin') return url;
        const setor = obterSetorContexto();
        if (setor === null) return url;
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'setor_id=' + encodeURIComponent(setor);
    }

    function recarregarSecaoAtiva() {
        const ativa = document.querySelector('.secao.active');
        const secaoId = ativa ? ativa.id : '';

        if (RECARREGADORES[secaoId]) {
            RECARREGADORES[secaoId]();
        } else if (secaoId === 'config') {
            // Telas de configuração seguem o setor escolhido: recarrega mantendo a sub-seção
            window.location.reload();
        }

        document.dispatchEvent(new CustomEvent('setor-contexto-alterado', {
            detail: { setorId: obterSetorContexto() }
        }));
    }

    function montarSeletorAdmin() {
        const caixa = document.getElementById('cabecalho-setor-seletor');
        const seletor = document.getElementById('seletor-setor');
        if (!caixa || !seletor) return;

        const xhr = new XMLHttpRequest();
        xhr.open('GET', window.APP_CONFIG.apiBase + 'setores.php', true);
        if (typeof setSecurityHeaders === 'function') setSecurityHeaders(xhr);

        xhr.onload = function () {
            if (xhr.status !== 200) return;

            let setores = [];
            try {
                setores = JSON.parse(xhr.responseText);
            } catch (e) {
                return;
            }

            const salvo = obterSetorContexto();
            seletor.innerHTML = '';

            const todos = document.createElement('option');
            todos.value = '';
            todos.textContent = 'Todos os setores';
            seletor.appendChild(todos);

            setores.filter(function (s) { return Number(s.ativo) === 1; }).forEach(function (s) {
                const opcao = document.createElement('option');
                opcao.value = String(s.id);
                opcao.textContent = s.nome;
                seletor.appendChild(opcao);
            });

            // Setor salvo que não existe mais (ou foi desativado) volta para "todos"
            const existe = Array.prototype.some.call(seletor.options, function (o) {
                return salvo !== null && o.value === String(salvo);
            });
            seletor.value = existe ? String(salvo) : '';
            if (!existe) {
                try { localStorage.removeItem(CHAVE_CONTEXTO); } catch (e) { }
            }

            caixa.hidden = false;
        };

        seletor.addEventListener('change', function () {
            try {
                if (seletor.value) {
                    localStorage.setItem(CHAVE_CONTEXTO, seletor.value);
                } else {
                    localStorage.removeItem(CHAVE_CONTEXTO);
                }
            } catch (e) { }
            recarregarSecaoAtiva();
        });

        xhr.send();
    }

    function iniciar() {
        const usuario = lerUsuario();
        if (!usuario) return;

        if (usuario.tipo === 'admin') {
            montarSeletorAdmin();
            return;
        }

        const rotulo = document.getElementById('cabecalho-setor');
        if (rotulo && usuario.setor_nome) {
            rotulo.textContent = usuario.setor_nome;
            rotulo.hidden = false;
        }
    }

    window.CabecalhoSetor = {
        obterSetorContexto: obterSetorContexto,
        anexarSetor: anexarSetor
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
