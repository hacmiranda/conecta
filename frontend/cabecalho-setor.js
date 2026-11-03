/**
 * Cabeçalho do Conecta CRPRS: nome do setor logo abaixo de "Conecta CRPRS" e,
 * para quem enxerga mais de um setor (administrador, coordenador geral, supervisor e coordenador
 * com setores adicionais), o seletor de setor que define o contexto das telas
 * (painel, Arquivo, Dashboard, Relatórios e Configurações).
 *
 * Gestor, operador e coordenador de um setor só veem o nome do próprio setor — o servidor
 * sempre limita o que cada um enxerga, independentemente do que o navegador enviar.
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

    // Setor do contexto atual: do próprio usuário, ou o escolhido no seletor (null = todos os que ele enxerga)
    function obterSetorContexto() {
        const usuario = lerUsuario();
        if (!usuario) return null;
        if (!Papeis.variosSetores(usuario)) {
            return usuario.setor_id ? Number(usuario.setor_id) : null;
        }
        let salvo = null;
        try {
            salvo = localStorage.getItem(CHAVE_CONTEXTO);
        } catch (e) { }
        const escolhido = salvo ? Number(salvo) : null;
        // Coordenador: o setor guardado precisa ser um dos dele (o servidor também confere)
        if (escolhido !== null && !Papeis.temSetor(escolhido, usuario)) return null;
        return escolhido;
    }

    // Acrescenta setor_id à URL quando foi escolhido um setor no seletor
    function anexarSetor(url) {
        const usuario = lerUsuario();
        if (!usuario || !Papeis.variosSetores(usuario)) return url;
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

    function montarSeletor() {
        const caixa = document.getElementById('cabecalho-setor-seletor');
        const seletor = document.getElementById('seletor-setor');
        if (!caixa || !seletor) return;

        const usuario = lerUsuario();
        const listaDoUsuario = Papeis.setores(usuario); // null = visão total (todos os setores ativos)
        const fonte = listaDoUsuario === null
            ? Api.get('setores.php')
            : Promise.resolve(listaDoUsuario.map(function (s) { return { id: s.id, nome: s.nome, ativo: 1 }; }));

        // Sem a lista (erro ou sem conexão) o seletor só não aparece, como antes
        fonte.then(function (resposta) {
            const setores = Array.isArray(resposta) ? resposta : [];

            const salvo = obterSetorContexto();
            seletor.innerHTML = '';

            const todos = document.createElement('option');
            todos.value = '';
            todos.textContent = listaDoUsuario === null ? 'Todos os setores' : 'Todos os meus setores';
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
        }).catch(function () { /* seletor fica escondido */ });

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

    }

    function iniciar() {
        const usuario = lerUsuario();
        if (!usuario) return;

        if (Papeis.variosSetores(usuario)) {
            montarSeletor();
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
