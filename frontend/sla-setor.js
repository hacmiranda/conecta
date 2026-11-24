/**
 * Ativação por Setor → aba "⏱️ SLA": liga/desliga a contagem do SLA do setor e edita os prazos
 * por prioridade. Só mostra/esconde: quem decide é o servidor (`setor-sla.php`), que devolve
 * `pode_alterar` e `pode_aplicar_modelo`. Ações por `data-acao` com ouvinte delegado único.
 */
const SlaSetor = (function () {
    let dados = null;
    let setorAtual = null;

    const CAMPOS = [
        { chave: 'dias', rotulo: 'dias', max: 365 },
        { chave: 'horas', rotulo: 'horas', max: 999 },
        { chave: 'minutos', rotulo: 'min', max: 59 }
    ];

    function area() {
        return document.getElementById('config-setor-lista');
    }

    function textoJornada(minutos) {
        const h = Math.floor(minutos / 60);
        const m = minutos % 60;
        return m ? `${h}h${String(m).padStart(2, '0')}` : `${h}h`;
    }

    // Minutos de um prazo em "12h" ou "1h30min"
    function textoPrazo(minutos) {
        const h = Math.floor(minutos / 60);
        const m = minutos % 60;
        return m ? `${h}h${m}min` : `${h}h`;
    }

    // Rótulo do frontend (ex.: "Baixíssima (Sem SLA)"); cai no nome da API
    function rotuloPrioridade(prio) {
        const cfg = window.PRIORIDADE_CONFIG && window.PRIORIDADE_CONFIG[prio.prioridade];
        return (cfg && cfg.nome) || prio.nome;
    }

    function campoNumero(prio, bloco, c, valor, desabilitado) {
        const id = `sla-${prio.prioridade}-${bloco}-${c.chave}`;
        const nome = bloco === 'resposta' ? '1ª resposta' : 'Resolução';
        return `<span class="sla-campo">
            <input type="number" id="${sanitizeHTML(id)}" class="sla-numero" inputmode="numeric" min="0" max="${c.max}" step="1"
                value="${Number(valor) || 0}" data-sla-bloco="${bloco}" data-sla-unidade="${c.chave}"
                aria-label="${sanitizeHTML(rotuloPrioridade(prio))}: ${nome}, ${c.rotulo}" ${desabilitado ? 'disabled' : ''}>
            <label for="${sanitizeHTML(id)}" class="sla-unidade">${c.rotulo}</label>
        </span>`;
    }

    function linhaPrioridade(prio, somenteLeitura) {
        const semCatalogo = !!prio.sem_sla_catalogo;
        const semPrazo = semCatalogo || !!prio.sem_prazo;
        const trava = somenteLeitura || semCatalogo;
        return `<tr data-sla-linha="${sanitizeHTML(prio.prioridade)}">
            <th scope="row">${sanitizeHTML(rotuloPrioridade(prio))}</th>
            <td>${CAMPOS.map(c => campoNumero(prio, 'resposta', c, prio.resposta && prio.resposta[c.chave], trava)).join('')}</td>
            <td>${CAMPOS.map(c => campoNumero(prio, 'resolucao', c, prio.resolucao && prio.resolucao[c.chave], trava)).join('')}</td>
            <td class="sla-col-sem">
                <input type="checkbox" data-sla-sem ${semPrazo ? 'checked' : ''} ${trava ? 'disabled' : ''}
                    aria-label="${sanitizeHTML(rotuloPrioridade(prio))}: sem SLA">
                ${semCatalogo ? '<span class="sla-nota">Sempre sem SLA</span>' : ''}
            </td>
        </tr>`;
    }

    function renderizar() {
        const lista = area();
        if (!lista || !dados) return;
        const podeAlterar = !!dados.pode_alterar;
        const ativas = dados.prioridades.filter(p => p.ativa_no_setor);

        lista.innerHTML = `
        <div class="sla-painel">
            <div class="sla-interruptor">
                <label class="switch">
                    <input type="checkbox" id="sla-ativo" role="switch" ${dados.sla_ativo ? 'checked' : ''} ${podeAlterar ? '' : 'disabled'}>
                    <span class="slider"></span>
                </label>
                <label for="sla-ativo" class="sla-interruptor-rotulo">Contar SLA neste setor</label>
            </div>
            <p class="sla-texto">Ligado, as demandas do setor ganham o selo de SLA e a contagem de prazo (1ª resposta e resolução) em horário útil. Desligado, as demandas continuam normais, só sem prazo nem selo. Você pode preparar os prazos antes de ligar.</p>
            <p class="sla-texto">1 dia útil = <strong>${textoJornada(dados.jornada_minutos)}</strong> (jornada do setor). Prazos em dias contam jornadas inteiras de expediente.</p>
            ${podeAlterar ? '' : '<p class="sla-aviso-leitura" role="status">Você pode consultar, mas só o administrador ou o coordenador do setor altera o SLA.</p>'}
            <div class="sla-tabela-rolagem">
            <table class="sla-tabela">
                <caption class="sr-only">Prazos de SLA por prioridade deste setor</caption>
                <thead><tr>
                    <th scope="col">Prioridade</th>
                    <th scope="col">1ª resposta</th>
                    <th scope="col">Resolução</th>
                    <th scope="col">Sem SLA</th>
                </tr></thead>
                <tbody>${ativas.map(p => linhaPrioridade(p, !podeAlterar)).join('')}</tbody>
            </table>
            </div>
            <div class="sla-acoes">
                ${podeAlterar ? '<button type="button" class="btn-primary" data-acao="sla-salvar">Salvar</button>' : ''}
                ${dados.pode_aplicar_modelo ? '<button type="button" class="btn-secondary" data-acao="sla-modelo">Aplicar Padrão CRPRS</button>' : ''}
            </div>
        </div>`;
    }

    async function carregar(setorId) {
        setorAtual = setorId;
        const lista = area();
        if (!lista) return;
        if (!setorId) {
            lista.innerHTML = '<p class="aviso-escolher-setor" role="status">Escolha um setor no seletor de setor da barra lateral.</p>';
            return;
        }
        lista.innerHTML = '<p>Carregando...</p>';
        try {
            const resposta = await Api.get(`setor-sla.php?setor_id=${setorId}`);
            // Resposta fora de ordem (o setor mudou durante a espera): descarta, para o Salvar não gravar no setor errado
            if (setorAtual !== setorId) return;
            dados = resposta;
            renderizar();
        } catch (e) {
            if (setorAtual !== setorId) return;
            lista.innerHTML = `<p>${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar o SLA do setor.'))}</p>`;
        }
    }

    function lerFormulario() {
        const prioridades = dados.prioridades.map(p => {
            const linha = document.querySelector(`[data-sla-linha="${CSS.escape(p.prioridade)}"]`);
            // Prioridade inativa no setor não aparece: vai como veio, para não apagar a configuração dela
            if (!linha) {
                return { prioridade: p.prioridade, sem_prazo: !!p.sem_prazo, resposta: p.resposta, resolucao: p.resolucao };
            }
            const bloco = (nome) => {
                const o = {};
                CAMPOS.forEach(c => {
                    const el = linha.querySelector(`[data-sla-bloco="${nome}"][data-sla-unidade="${c.chave}"]`);
                    o[c.chave] = el && el.value !== '' ? Number(el.value) : 0;
                });
                return o;
            };
            return {
                prioridade: p.prioridade,
                sem_prazo: linha.querySelector('[data-sla-sem]').checked,
                resposta: bloco('resposta'),
                resolucao: bloco('resolucao')
            };
        });
        return { sla_ativo: document.getElementById('sla-ativo').checked, prioridades };
    }

    async function enviarSalvar() {
        mostrarLoader();
        try {
            dados = await Api.put(`setor-sla.php?setor_id=${setorAtual}`, lerFormulario());
            ocultarLoader();
            renderizar();
            mostrarToast('Sucesso', 'SLA do setor salvo.', 'success');
        } catch (e) {
            ocultarLoader();
            mostrarAlert('Erro', mensagemDeErro(e, 'Não foi possível salvar o SLA.'));
        }
    }

    // Confere no navegador (inteiro entre min e max) antes de chamar a API; devolve o aviso ou ''
    function erroNosCampos() {
        for (const el of document.querySelectorAll('.sla-numero:not(:disabled)')) {
            const n = Number(el.value);
            if (el.value !== '' && (!Number.isInteger(n) || n < Number(el.min) || n > Number(el.max))) {
                const tr = el.closest('tr');
                const nome = tr ? tr.querySelector('th').textContent.trim() : '';
                const bloco = el.dataset.slaBloco === 'resposta' ? '1ª resposta' : 'Resolução';
                return `${nome}, ${bloco}: ${el.dataset.slaUnidade} deve ser um número inteiro de ${el.min} a ${el.max}.`;
            }
        }
        return '';
    }

    function salvar() {
        if (!dados || !dados.pode_alterar) return;
        const erro = erroNosCampos();
        if (erro) {
            mostrarAlert('Valor inválido', erro);
            return;
        }
        const ligar = document.getElementById('sla-ativo').checked;
        if (dados.sla_ativo && !ligar) {
            mostrarConfirm('Desligar SLA do setor',
                'Desligando, as demandas do setor perdem o selo e a contagem de SLA. Deseja continuar?',
                (ok) => { if (ok) enviarSalvar(); }, true);
            return;
        }
        enviarSalvar();
    }

    function nomeDe(codigo) {
        const p = dados.prioridades.find(x => x.prioridade === codigo);
        return p ? rotuloPrioridade(p) : codigo;
    }

    function aplicarModelo() {
        if (!dados || !dados.pode_aplicar_modelo) return;
        const linhas = (dados.modelo || []).map(m => m.sem_sla
            ? `${nomeDe(m.prioridade)}: sem SLA`
            : `${nomeDe(m.prioridade)}: 1ª resposta ${textoPrazo(m.resposta_minutos)}, resolução ${textoPrazo(m.resolucao_minutos)}`);
        mostrarConfirm('Aplicar modelo Padrão CRPRS',
            'Os prazos de todas as prioridades deste setor serão substituídos por:\n' + linhas.join('\n') + '\n\nO interruptor do setor não muda. Deseja aplicar?',
            async (ok) => {
                document.getElementById('modal-confirm-message')?.classList.remove('sla-quebra-linhas');
                if (!ok) return;
                mostrarLoader();
                try {
                    dados = await Api.post(`setor-sla.php?acao=aplicar-modelo&setor_id=${setorAtual}`, {});
                    ocultarLoader();
                    renderizar();
                    mostrarToast('Sucesso', 'Padrão CRPRS aplicado.', 'success');
                } catch (e) {
                    ocultarLoader();
                    mostrarAlert('Erro', mensagemDeErro(e, 'Não foi possível aplicar o modelo.'));
                }
            });
        document.getElementById('modal-confirm-message')?.classList.add('sla-quebra-linhas');
    }

    // Um só ouvinte, registrado uma vez
    document.addEventListener('click', (ev) => {
        const alvo = ev.target.closest('[data-acao="sla-salvar"], [data-acao="sla-modelo"]');
        if (!alvo) return;
        if (alvo.dataset.acao === 'sla-salvar') salvar();
        else aplicarModelo();
    });

    return { carregar };
})();
