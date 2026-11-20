/**
 * Nome do setor em vermelho logo depois do título (h2) das telas que seguem o setor:
 * o escolhido no seletor do cabeçalho (admin, visão total, coordenador; "Todos os setores"
 * quando nenhum foi escolhido) ou o próprio setor (gestor e operador).
 *
 * Telas de alcance geral (Ajuda, Sobre, Alterar senha, Meu Painel, detalhe da demanda) e as
 * subtelas de Configurações que são globais ou têm seletor de setor próprio ficam sem o nome.
 * Vários títulos são reescritos pelas telas (textContent); um MutationObserver devolve o nome.
 */
(function () {
    'use strict';

    // Seções com o nome do setor no título
    const SECOES = ['tickets', 'fechados', 'lixeira', 'dashboard', 'relatorios', 'satisfacao',
        'atividades', 'registro-atividades', 'novo', 'config'];

    // Em Configurações, só as subtelas que seguem o seletor do cabeçalho
    // (Usuários: só na aba Equipe; a aba Solicitantes lista gente sem setor)
    const SUBTELAS_CONFIG = ['categorias', 'horarios-sla', 'config-email', 'usuarios', 'setor-config', 'filtros-email', 'atividades'];

    function lerUsuario() {
        try { return JSON.parse(localStorage.getItem('cd_usuario') || 'null'); } catch (e) { return null; }
    }

    function lerSubtela() {
        try { return localStorage.getItem('cd_subSecaoAtiva') || ''; } catch (e) { return ''; }
    }

    // Nome a mostrar ('' = nenhum)
    function nomeDoSetor() {
        const usuario = lerUsuario();
        if (!usuario || typeof Papeis === 'undefined') return '';
        if (!Papeis.variosSetores(usuario)) return usuario.setor_nome || '';
        const seletor = document.getElementById('seletor-setor');
        const caixa = document.getElementById('cabecalho-setor-seletor');
        if (!seletor || !caixa || caixa.hidden || seletor.selectedIndex < 0) return '';
        return seletor.options[seletor.selectedIndex].textContent;
    }

    function deveMostrar(secaoId) {
        if (secaoId === 'config') {
            const subtela = lerSubtela();
            if (subtela === 'usuarios' && typeof abaUsuariosAtiva !== 'undefined' && abaUsuariosAtiva === 'solicitantes') return false;
            return SUBTELAS_CONFIG.indexOf(subtela) !== -1;
        }
        return true;
    }

    // Garante (ou tira) o nome no h2 de uma seção, sem mexer se já está certo
    function aplicar(secaoId) {
        const secao = document.getElementById(secaoId);
        const titulo = secao && secao.querySelector('h2');
        if (!titulo) return;
        const nome = deveMostrar(secaoId) ? nomeDoSetor() : '';
        let marca = titulo.querySelector(':scope > .titulo-setor');
        if (!nome) {
            if (marca) marca.remove();
            return;
        }
        if (!marca) {
            marca = document.createElement('span');
            marca.className = 'titulo-setor';
            titulo.appendChild(marca);
        }
        if (marca.dataset.nome !== nome) {
            marca.dataset.nome = nome;
            marca.innerHTML = '<span class="titulo-setor-sep" aria-hidden="true">·</span> ';
            marca.appendChild(document.createTextNode(nome));
        }
    }

    function aplicarTodas() {
        SECOES.forEach(aplicar);
    }

    let pendente = false;
    function agendar() {
        if (pendente) return;
        pendente = true;
        setTimeout(function () { pendente = false; aplicarTodas(); }, 0);
    }

    function iniciar() {
        if (!lerUsuario()) return;
        const observador = new MutationObserver(agendar);
        SECOES.forEach(function (id) {
            const secao = document.getElementById(id);
            const titulo = secao && secao.querySelector('h2');
            if (titulo) observador.observe(titulo, { childList: true });
            // Troca de seção (classe "active"): reconfere a subtela de Configurações
            if (secao) observador.observe(secao, { attributes: true, attributeFilter: ['class'] });
        });
        // O seletor do cabeçalho é preenchido depois (setores.php) e mostrado ao terminar
        const caixa = document.getElementById('cabecalho-setor-seletor');
        if (caixa) observador.observe(caixa, { attributes: true, attributeFilter: ['hidden'], childList: true, subtree: true });
        document.addEventListener('setor-contexto-alterado', agendar);
        document.addEventListener('change', function (ev) {
            if (ev.target && ev.target.id === 'seletor-setor') agendar();
        });
        // Abrir uma subtela de Configurações pelo menu
        document.addEventListener('click', agendar);
        aplicarTodas();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
