// Sistema de Status e SLA - Conecta CRPRS

// Configurações de status baseadas no banco de dados
const STATUS_CONFIG = {
    'aberto': {
        nome: 'Aberto',
        cor: '#f79226',
        icone: '🆕',
        descricao: 'A demanda foi criada, mas ainda não começou a ser tratada',
        congela_sla: false
    },
    'em_atendimento': {
        nome: 'Em atendimento',
        cor: '#17a2b8',
        icone: '🔧',
        descricao: 'A demanda está sendo analisada ou trabalhada por um atendente responsável',
        congela_sla: false
    },
    'aguardando': {
        nome: 'Aguardando',
        cor: '#ffc107',
        icone: '⏳',
        descricao: 'Demanda na fila ou aguardando ações gerais (SLA pausado)',
        congela_sla: true
    },
    'aguardando_resposta': {
        nome: 'Aguardando resposta',
        cor: '#ffc107',
        icone: '⏳',
        descricao: 'O atendente precisa de mais informações do usuário/cliente para continuar',
        congela_sla: true
    },
    'aguardando_terceiros': {
        nome: 'Aguardando terceiros',
        cor: '#6f42c1',
        icone: '🔄',
        descricao: 'Quando depende de outro setor ou fornecedor externo para resolução',
        congela_sla: true
    },
    'aguardando_aprovacao': {
        nome: 'Aguardando aprovação',
        cor: '#fd7e14',
        icone: '📋',
        descricao: 'Necessário para situações em que uma solução ou ação precisa de autorização do cliente ou gestor',
        congela_sla: true
    },
    'resolvido': {
        nome: 'Resolvido',
        cor: '#28a745',
        icone: '✅',
        descricao: 'A solução foi aplicada, mas aguarda a confirmação do usuário/cliente para encerrar',
        congela_sla: false
    },
    'encerrado': {
        nome: 'Encerrado',
        cor: '#6c757d',
        icone: '🏁',
        descricao: 'O problema foi solucionado, e o atendimento foi oficialmente concluído',
        congela_sla: false
    },
    'cancelado': {
        nome: 'Cancelado',
        cor: '#dc3545',
        icone: '❌',
        descricao: 'A demanda foi aberta por engano, ou a solicitação foi retirada pelo solicitante',
        congela_sla: false
    },
    'reaberto': {
        nome: 'Reaberto',
        cor: '#e83e8c',
        icone: '🔄',
        descricao: 'Quando uma demanda já encerrada ou resolvida volta a apresentar problemas e precisa ser tratada novamente',
        congela_sla: false
    },
    'nova_resposta': {
        nome: 'Nova Resposta',
        cor: '#17a2b8',
        icone: '💬',
        descricao: 'O solicitante respondeu à demanda. Requer atenção do responsável.',
        congela_sla: false
    },
    // Mapeamento de status antigos para novos (compatibilidade)
    'andamento': {
        nome: 'Em atendimento',
        cor: '#17a2b8',
        icone: '🔧',
        descricao: 'A demanda está sendo analisada ou trabalhada por um atendente responsável',
        congela_sla: false
    },
    'espera': {
        nome: 'Aguardando resposta',
        cor: '#ffc107',
        icone: '⏳',
        descricao: 'O atendente precisa de mais informações do usuário/cliente para continuar',
        congela_sla: true
    },
    'fechado': {
        nome: 'Encerrado',
        cor: '#6c757d',
        icone: '🏁',
        descricao: 'O problema foi solucionado, e o atendimento foi oficialmente concluído',
        congela_sla: false
    },
    'novo': {
        nome: 'Novo',
        cor: '#f79226',
        icone: '🆕',
        descricao: 'A demanda foi criada, mas ainda não começou a ser tratada',
        congela_sla: false
    },
    'atendimento': {
        nome: 'Em atendimento',
        cor: '#17a2b8',
        icone: '🔧',
        descricao: 'A demanda está sendo analisada ou trabalhada por um atendente responsável',
        congela_sla: false
    },
    'aguardando_data': {
        nome: 'Aguardando data',
        cor: '#20c997',
        icone: '📅',
        descricao: 'Aguardando uma data agendada para prosseguir',
        congela_sla: true
    }
};

// Configurações de prioridade (ícone/cor/nome/descrição). Os prazos de SLA (resposta/resolução)
// não ficam mais fixos aqui — vêm do catálogo do servidor (ticket_prioridades, tela "Alterar
// Prioridade") e o SLA de cada demanda já chega calculado em ticket.sla (Fase 8 das melhorias
// adiadas: SLAService::situacao, sem duplicar os prazos no front).
const PRIORIDADE_CONFIG = {
    'urgente': {
        nome: 'Urgente',
        cor: '#dc3545',
        icone: '🔥',
        descricao: 'Sistema fora do ar, falha crítica que impede o trabalho'
    },
    'alta': {
        nome: 'Alta',
        cor: '#fd7e14',
        icone: '⬆️',
        descricao: 'Funcionalidade importante indisponível que afeta a produtividade'
    },
    'normal': {
        nome: 'Normal',
        cor: '#17a2b8',
        icone: '➡️',
        descricao: 'Dúvidas, solicitações rotineiras'
    },
    'baixa': {
        nome: 'Baixa',
        cor: '#28a745',
        icone: '⬇️',
        descricao: 'Melhorias, sugestões não urgentes'
    }
};

// Função para formatar nome de status (remover underline e capitalizar)
function formatarNomeStatus(status) {
    if (!status) return '';

    // Verificar se existe configuração para este status
    if (STATUS_CONFIG[status]) {
        return STATUS_CONFIG[status].nome;
    }

    // Fallback: remover underline e capitalizar primeira letra
    return status
        .replace(/_/g, ' ')
        .split(' ')
        .map(palavra => palavra.charAt(0).toUpperCase() + palavra.slice(1).toLowerCase())
        .join(' ');
}

// Função para formatar nome de prioridade
function formatarNomePrioridade(prioridade) {
    if (!prioridade) return 'Normal';

    if (PRIORIDADE_CONFIG[prioridade]) {
        return PRIORIDADE_CONFIG[prioridade].nome;
    }

    return prioridade.charAt(0).toUpperCase() + prioridade.slice(1).toLowerCase();
}

// Carregar status disponíveis
function carregarStatusDisponiveis(setorId) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const filtro = setorId ? `?setor_id=${setorId}` : '';
        xhr.open('GET', window.APP_CONFIG.apiBase + 'ticket-status.php' + filtro, true);
        setSecurityHeaders(xhr);

        xhr.onload = function () {
            if (xhr.status === 200) {
                const status = JSON.parse(xhr.responseText);
                resolve(status);
            } else {
                reject('Erro ao carregar status');
            }
        };

        xhr.onerror = function () {
            reject('Erro de conexão');
        };

        xhr.send();
    });
}

// Carregar prioridades disponíveis
function carregarPrioridadesDisponiveis(setorId) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const filtro = setorId ? `?setor_id=${setorId}` : '';
        xhr.open('GET', window.APP_CONFIG.apiBase + 'prioridades.php' + filtro, true);
        setSecurityHeaders(xhr);

        xhr.onload = function () {
            if (xhr.status === 200) {
                const prioridades = JSON.parse(xhr.responseText);
                resolve(prioridades);
            } else {
                reject('Erro ao carregar prioridades');
            }
        };

        xhr.onerror = function () {
            reject('Erro de conexão');
        };

        xhr.send();
    });
}

// Modal de seleção com descrição
function mostrarSelectComDescricao(titulo, mensagem, opcoes, callback) {
    document.getElementById('modal-select-title').textContent = titulo;
    document.getElementById('modal-select-message').textContent = mensagem;

    const optionsDiv = document.getElementById('modal-select-options');
    optionsDiv.innerHTML = '';

    opcoes.forEach((opcao) => {
        const div = document.createElement('div');
        div.className = 'select-option-descricao';
        div.innerHTML = `
            <div class="opcao-nome">${opcao.nome}</div>
            ${opcao.descricao ? `<div class="opcao-descricao">${opcao.descricao}</div>` : ''}
        `;

        div.onclick = function () {
            document.querySelectorAll('.select-option-descricao').forEach(opt => opt.classList.remove('selected'));
            div.classList.add('selected');
            setTimeout(() => {
                fecharModal();
                if (callback) callback(opcao);
            }, 200);
        };

        optionsDiv.appendChild(div);
    });

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-select').classList.add('show');
}

// Modal de prompt para observações
function mostrarPromptObservacoes(titulo, mensagem, callback) {
    document.getElementById('modal-prompt-title').textContent = titulo;
    document.getElementById('modal-prompt-message').textContent = mensagem;
    document.getElementById('modal-prompt-input').value = '';
    document.getElementById('modal-prompt-input').placeholder = 'Digite suas observações...';

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        const valor = confirmado ? document.getElementById('modal-prompt-input').value : null;
        if (callback) callback(valor);
    };

    setTimeout(() => document.getElementById('modal-prompt-input').focus(), 100);
}

// Formatar tempo em formato legível
function formatarTempo(segundos) {
    if (segundos <= 0) return 'Vencido';

    const horas = Math.floor(segundos / 3600);
    const minutos = Math.floor((segundos % 3600) / 60);

    if (horas > 0) {
        return `${horas}h ${minutos}m úteis`;
    } else {
        return `${minutos}m úteis`;
    }
}

// Atualizar indicadores de SLA na interface. O SLA em si (tipo/segundos_restantes/percentual/
// vencido/pausado) já vem calculado pelo servidor em ticket.sla (SLAService::situacao, Fase 8 das
// melhorias adiadas) — aqui só decrementamos localmente entre uma busca e outra (a cada 60s, sem
// nova requisição); o valor exato volta a vir do servidor na próxima vez que a lista recarregar.
function atualizarIndicadoresSLA() {
    if (!ticketsAtuais || ticketsAtuais.length === 0) return;

    document.querySelectorAll('[data-ticket-id]').forEach(row => {
        const ticketId = parseInt(row.getAttribute('data-ticket-id'));
        const ticket = ticketsAtuais.find(t => t.id === ticketId);

        if (ticket && ticket.status !== 'encerrado' && ticket.status !== 'fechado') {
            const slaInfo = ticket.sla;

            // Remover indicador existente
            const indicadorExistente = row.querySelector('.sla-indicator');
            if (indicadorExistente) {
                indicadorExistente.remove();
            }

            if (!slaInfo) return;

            // Decaimento local: só avança o relógio se não estiver pausado (igual ao servidor).
            // Ao vencer localmente, o percentual sobe para 100 também — senão o ícone ficaria
            // escondido (abaixo do limiar de 50%) até a lista recarregar do servidor.
            if (!slaInfo.pausado) {
                slaInfo.segundos_restantes -= 60;
                if (slaInfo.segundos_restantes <= 0) {
                    slaInfo.vencido = true;
                    slaInfo.percentual = 100;
                }
            }

            // Apenas mostrar ícone se percentual >= 50% e não estiver pausado
            if (slaInfo.percentual >= 50 && !slaInfo.pausado) {
                const indicador = document.createElement('img');
                indicador.className = 'sla-indicator';

                // Definir ícone baseado no percentual
                if (slaInfo.percentual >= 80) {
                    indicador.src = 'images/icone_atencao_vermelho.png';
                    indicador.alt = 'SLA Urgente/Vencido';
                } else if (slaInfo.percentual >= 50) {
                    indicador.src = 'images/icone_atencao_amarelo.png';
                    indicador.alt = 'SLA Atenção';
                }

                const tipoTexto = slaInfo.tipo === 'resposta' ? 'Primeira Resposta' : 'Resolução';
                const vencidoTexto = slaInfo.vencido ? 'Vencido' : formatarTempo(slaInfo.segundos_restantes);
                const percentualTexto = Math.round(slaInfo.percentual);
                const pausadoTexto = slaInfo.pausado ? ' SLA pausado (aguardando resposta).' : '';

                const vgTitle = slaInfo.percentual >= 80 ? 'URGENTE — SLA' : 'ATENÇÃO — SLA';
                const vgBody = slaInfo.vencido
                    ? `SLA de ${tipoTexto} vencido! ${percentualTexto}% do prazo utilizado.${pausadoTexto}`
                    : `SLA de ${tipoTexto}: ${percentualTexto}% do prazo utilizado. Tempo restante: ${vencidoTexto}.${pausadoTexto}`;

                indicador.setAttribute('data-vg-title', vgTitle);
                indicador.setAttribute('data-vg-tooltip', vgBody);
                indicador.style.marginLeft = '0.25rem';
                indicador.style.width = '16px';
                indicador.style.height = '16px';
                indicador.style.verticalAlign = 'middle';

                const primeiraColuna = row.querySelector('td');
                if (primeiraColuna) {
                    primeiraColuna.appendChild(indicador);
                }
            }
        }
    });
}

// Inicializar sistema de SLA: o cálculo em si já vem do servidor em cada ticket (ticket.sla),
// então só precisa atualizar os indicadores periodicamente — nada para pré-carregar.
function inicializarSistemaSLA() {
    setInterval(atualizarIndicadoresSLA, 60000);
    setTimeout(atualizarIndicadoresSLA, 1000);
}

// Exportar funções para uso global
window.STATUS_CONFIG = STATUS_CONFIG;
window.PRIORIDADE_CONFIG = PRIORIDADE_CONFIG;
window.inicializarSistemaSLA = inicializarSistemaSLA;