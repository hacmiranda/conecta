/**
 * Papéis do sistema no navegador (espelho de AcessoService, no backend).
 * Só serve para mostrar ou esconder telas e botões: quem decide o que cada papel pode fazer é sempre o servidor.
 *
 * admin               todos os setores, altera tudo
 * coordenador_geral   todos os setores, só consulta (faz a triagem das avaliações)
 * supervisor          todos os setores, só consulta (faz a triagem das avaliações)
 * coordenador         como o gestor, em um ou mais setores (o admin libera os adicionais)
 * gestor              um setor
 * operador            um setor, só as demandas em que atua
 */
const Papeis = (function () {
    const VISAO_TOTAL = ['admin', 'coordenador_geral', 'supervisor'];
    const SOMENTE_LEITURA = ['coordenador_geral', 'supervisor'];
    const GESTAO = ['gestor', 'coordenador'];
    const EQUIPE = ['admin', 'coordenador_geral', 'supervisor', 'coordenador', 'gestor', 'operador'];
    const REGISTRAM_ATIVIDADES = ['coordenador_geral', 'supervisor', 'coordenador', 'gestor', 'operador'];
    const SATISFACAO = ['admin', 'coordenador_geral', 'supervisor', 'coordenador'];
    const ROTULOS = {
        admin: 'Administrador',
        coordenador_geral: 'Coordenador(a) Geral',
        supervisor: 'Supervisor(a)',
        coordenador: 'Coordenador(a)',
        gestor: 'Gestor',
        operador: 'Operador',
        cliente: 'Solicitante'
    };

    function usuarioDaSessao() {
        try {
            return JSON.parse(localStorage.getItem('cd_usuario') || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    const tipoDe = (u) => (u || usuarioDaSessao()).tipo || '';

    /**
     * Setores do usuário: null = todos (visão total); lista [{id, nome}] nos demais casos.
     * Sessões antigas, sem a lista, caem no setor principal.
     */
    function setores(u) {
        const usuario = u || usuarioDaSessao();
        if (VISAO_TOTAL.includes(usuario.tipo)) return null;
        if (Array.isArray(usuario.setores)) return usuario.setores;
        return usuario.setor_id ? [{ id: Number(usuario.setor_id), nome: usuario.setor_nome || '' }] : [];
    }

    return {
        usuario: usuarioDaSessao,
        rotulo: (tipo) => ROTULOS[tipo] || 'Operador',
        admin: (u) => tipoDe(u) === 'admin',
        visaoTotal: (u) => VISAO_TOTAL.includes(tipoDe(u)),
        somenteLeitura: (u) => SOMENTE_LEITURA.includes(tipoDe(u)),
        /** Gestor ou coordenador (o administrador não conta: é tratado à parte) */
        gestao: (u) => GESTAO.includes(tipoDe(u)),
        /** Quem administra o setor: admin, gestor ou coordenador */
        administraSetor: (u) => tipoDe(u) === 'admin' || GESTAO.includes(tipoDe(u)),
        equipe: (u) => EQUIPE.includes(tipoDe(u)),
        registraAtividades: (u) => {
            const usuario = u || usuarioDaSessao();
            return REGISTRAM_ATIVIDADES.includes(usuario.tipo) && !!usuario.setor_id;
        },
        veSatisfacao: (u) => SATISFACAO.includes(tipoDe(u)),
        setores,
        /** Mostra o seletor de setor no cabeçalho: visão total ou mais de um setor */
        variosSetores: (u) => {
            const lista = setores(u);
            return lista === null || lista.length > 1;
        },
        /** O usuário enxerga este setor? */
        temSetor: (setorId, u) => {
            const lista = setores(u);
            return lista === null || lista.some(s => Number(s.id) === Number(setorId));
        }
    };
})();
