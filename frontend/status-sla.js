// Sistema de Status e SLA - Helpdesk CRPRS

// Configurações de status baseadas no banco de dados
const STATUS_CONFIG = {
    'aberto': {
        nome: 'Aberto',
        cor: '#f79226',
        icone: '🆕',
        descricao: 'O ticket foi criado, mas ainda não começou a ser tratado',
        congela_sla: false
    },
    'em_atendimento': {
        nome: 'Em atendimento',
        cor: '#17a2b8',
        icone: '🔧',
        descricao: 'O ticket está sendo analisado ou trabalhado por um atendente do helpdesk',
        congela_sla: false
    },
    'aguardando': {
        nome: 'Aguardando',
        cor: '#ffc107',
        icone: '⏳',
        descricao: 'Ticket na fila ou aguardando ações gerais (SLA pausado)',
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
        descricao: 'O ticket foi aberto por engano, ou a solicitação foi retirada pelo solicitante',
        congela_sla: false
    },
    'reaberto': {
        nome: 'Reaberto',
        cor: '#e83e8c',
        icone: '🔄',
        descricao: 'Quando um ticket já encerrado ou resolvido volta a apresentar problemas e precisa ser tratado novamente',
        congela_sla: false
    },
    // Mapeamento de status antigos para novos (compatibilidade)
    'andamento': {
        nome: 'Em atendimento',
        cor: '#17a2b8',
        icone: '🔧',
        descricao: 'O ticket está sendo analisado ou trabalhado por um atendente do helpdesk',
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
        descricao: 'O ticket foi criado, mas ainda não começou a ser tratado',
        congela_sla: false
    },
    'atendimento': {
        nome: 'Em atendimento',
        cor: '#17a2b8',
        icone: '🔧',
        descricao: 'O ticket está sendo analisado ou trabalhado por um atendente do helpdesk',
        congela_sla: false
    }
};

// Configurações de prioridade com SLA em horas úteis
const PRIORIDADE_CONFIG = {
    'urgente': {
        nome: 'Urgente',
        cor: '#dc3545',
        icone: '🔥',
        sla_resposta_minutos: 120,
        sla_resolucao_horas: 4,
        descricao: 'Sistema fora do ar, falha crítica que impede o trabalho'
    },
    'alta': {
        nome: 'Alta',
        cor: '#fd7e14',
        icone: '⬆️',
        sla_resposta_minutos: 240,
        sla_resolucao_horas: 8,
        descricao: 'Funcionalidade importante indisponível que afeta a produtividade'
    },
    'normal': {
        nome: 'Normal',
        cor: '#17a2b8',
        icone: '➡️',
        sla_resposta_minutos: 720,
        sla_resolucao_horas: 24,
        descricao: 'Dúvidas, solicitações rotineiras'
    },
    'baixa': {
        nome: 'Baixa',
        cor: '#28a745',
        icone: '⬇️',
        sla_resposta_minutos: 1440,
        sla_resolucao_horas: 40,
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

// Horários configuráveis (carregados do banco)
let HORARIOS_CONFIGURADOS = null;
let FERIADOS_CONFIGURADOS = [];

// Carregar horários e feriados do banco
function carregarHorariosConfigurados() {
    return new Promise((resolve, reject) => {
        const xhr1 = new XMLHttpRequest();
        xhr1.open('GET', '/crpdesk/api/controllers/horarios-sla.php', true);
        
        xhr1.onload = function() {
            if (xhr1.status === 200) {
                HORARIOS_CONFIGURADOS = JSON.parse(xhr1.responseText);
                
                const xhr2 = new XMLHttpRequest();
                xhr2.open('GET', '/crpdesk/api/controllers/horarios-sla.php?feriados=1', true);
                
                xhr2.onload = function() {
                    if (xhr2.status === 200) {
                        FERIADOS_CONFIGURADOS = JSON.parse(xhr2.responseText);
                        resolve();
                    } else {
                        reject('Erro ao carregar feriados');
                    }
                };
                
                xhr2.send();
            } else {
                reject('Erro ao carregar horários');
            }
        };
        
        xhr1.send();
    });
}

// Verificar se é feriado
function eFeriado(data) {
    if (!FERIADOS_CONFIGURADOS || FERIADOS_CONFIGURADOS.length === 0) return false;
    
    const dataStr = data.toISOString().split('T')[0];
    
    return FERIADOS_CONFIGURADOS.some(feriado => {
        if (feriado.recorrente) {
            const feriadoData = new Date(feriado.data + 'T00:00:00');
            return feriadoData.getMonth() === data.getMonth() && feriadoData.getDate() === data.getDate();
        } else {
            return feriado.data === dataStr;
        }
    });
}

// Verificar se uma data está em horário útil
function eHorarioUtil(data) {
    if (!HORARIOS_CONFIGURADOS) return false;
    
    const diaJS = data.getDay(); // 0=Domingo, 1=Segunda, ..., 6=Sábado
    const dia = diaJS === 0 ? 1 : diaJS + 1; // Converter para formato do banco: 1=Domingo, 2=Segunda, ..., 7=Sábado
    const hora = data.getHours();
    const minuto = data.getMinutes();
    
    // Verificar se é feriado
    if (eFeriado(data)) return false;
    
    // Buscar configuração do dia
    const diaConfig = HORARIOS_CONFIGURADOS.find(h => h.dia_semana == dia);
    if (!diaConfig || !diaConfig.ativo) return false;
    
    // Converter horários para minutos
    const horaAtualMinutos = hora * 60 + minuto;
    const [horaInicio, minInicio] = diaConfig.hora_inicio.split(':').map(Number);
    const [horaFim, minFim] = diaConfig.hora_fim.split(':').map(Number);
    const inicioMinutos = horaInicio * 60 + minInicio;
    const fimMinutos = horaFim * 60 + minFim;
    
    return horaAtualMinutos >= inicioMinutos && horaAtualMinutos < fimMinutos;
}

// Calcular segundos úteis entre duas datas
function calcularSegundosUteis(dataInicio, dataFim) {
    let segundosUteis = 0;
    let dataAtual = new Date(dataInicio);
    
    while (dataAtual < dataFim) {
        if (eHorarioUtil(dataAtual)) {
            segundosUteis++;
        }
        dataAtual.setSeconds(dataAtual.getSeconds() + 1);
        
        // Otimização: pular para próximo horário útil se estiver fora
        if (!eHorarioUtil(dataAtual)) {
            const dia = dataAtual.getDay();
            const hora = dataAtual.getHours();
            
            // Se for fim de semana, pular para segunda-feira 8h
            if (dia === 0) { // Domingo
                dataAtual.setDate(dataAtual.getDate() + 1);
                dataAtual.setHours(HORARIO_ATENDIMENTO.inicio, 0, 0, 0);
            } else if (dia === 6) { // Sábado
                dataAtual.setDate(dataAtual.getDate() + 2);
                dataAtual.setHours(HORARIO_ATENDIMENTO.inicio, 0, 0, 0);
            } else if (hora < HORARIO_ATENDIMENTO.inicio) {
                // Antes do horário, pular para início
                dataAtual.setHours(HORARIO_ATENDIMENTO.inicio, 0, 0, 0);
            } else if (hora >= HORARIO_ATENDIMENTO.fim) {
                // Depois do horário, pular para próximo dia útil
                dataAtual.setDate(dataAtual.getDate() + 1);
                dataAtual.setHours(HORARIO_ATENDIMENTO.inicio, 0, 0, 0);
            }
        }
    }
    
    return segundosUteis;
}

// Calcular segundos úteis de forma otimizada
function calcularSegundosUteisOtimizado(dataInicio, dataFim) {
    if (!HORARIOS_CONFIGURADOS) return 0;
    
    let segundosUteis = 0;
    let dataAtual = new Date(dataInicio);
    
    while (dataAtual < dataFim) {
        const diaJS = dataAtual.getDay(); // 0=Domingo, 1=Segunda, ..., 6=Sábado
        const dia = diaJS === 0 ? 1 : diaJS + 1; // Converter para formato do banco: 1=Domingo, 2=Segunda, ..., 7=Sábado
        
        // Buscar configuração do dia
        const diaConfig = HORARIOS_CONFIGURADOS.find(h => h.dia_semana == dia);
        
        // Pular dias inativos ou feriados
        if (!diaConfig || !diaConfig.ativo || eFeriado(dataAtual)) {
            dataAtual.setDate(dataAtual.getDate() + 1);
            dataAtual.setHours(0, 0, 0, 0);
            continue;
        }
        
        const [horaInicio, minInicio] = diaConfig.hora_inicio.split(':').map(Number);
        const [horaFim, minFim] = diaConfig.hora_fim.split(':').map(Number);
        
        const hora = dataAtual.getHours();
        const minuto = dataAtual.getMinutes();
        const horaAtualMinutos = hora * 60 + minuto;
        const inicioMinutos = horaInicio * 60 + minInicio;
        const fimMinutos = horaFim * 60 + minFim;
        
        // Pular horários fora do expediente
        if (horaAtualMinutos < inicioMinutos) {
            dataAtual.setHours(horaInicio, minInicio, 0, 0);
            continue;
        } else if (horaAtualMinutos >= fimMinutos) {
            dataAtual.setDate(dataAtual.getDate() + 1);
            dataAtual.setHours(0, 0, 0, 0);
            continue;
        }
        
        // Calcular segundos até o fim do expediente ou até dataFim
        const fimExpedienteHoje = new Date(dataAtual);
        fimExpedienteHoje.setHours(horaFim, minFim, 0, 0);
        
        const proximoLimite = dataFim < fimExpedienteHoje ? dataFim : fimExpedienteHoje;
        const segundosAteProximoLimite = Math.floor((proximoLimite - dataAtual) / 1000);
        
        segundosUteis += segundosAteProximoLimite;
        dataAtual = new Date(proximoLimite);
        
        // Se chegou ao fim do expediente, pular para próximo dia
        if (dataAtual >= fimExpedienteHoje && dataAtual < dataFim) {
            dataAtual.setDate(dataAtual.getDate() + 1);
            dataAtual.setHours(0, 0, 0, 0);
        }
    }
    
    return segundosUteis;
}

// Carregar status disponíveis
function carregarStatusDisponiveis() {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', '/helpdesk-crprs/api/controllers/ticket-status.php', true);
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                const status = JSON.parse(xhr.responseText);
                resolve(status);
            } else {
                reject('Erro ao carregar status');
            }
        };
        
        xhr.onerror = function() {
            reject('Erro de conexão');
        };
        
        xhr.send();
    });
}

// Carregar prioridades disponíveis
function carregarPrioridadesDisponiveis() {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', '/helpdesk-crprs/api/controllers/prioridades.php', true);
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                const prioridades = JSON.parse(xhr.responseText);
                resolve(prioridades);
            } else {
                reject('Erro ao carregar prioridades');
            }
        };
        
        xhr.onerror = function() {
            reject('Erro de conexão');
        };
        
        xhr.send();
    });
}

// Alterar status do ticket com novo sistema
function alterarStatusNovo(ticketId) {
    const statusDisponiveis = [
        { id: 'aberto', nome: formatarNomeStatus('aberto') },
        { id: 'em_atendimento', nome: formatarNomeStatus('em_atendimento') },
        { id: 'aguardando_resposta', nome: formatarNomeStatus('aguardando_resposta') },
        { id: 'aguardando_terceiros', nome: formatarNomeStatus('aguardando_terceiros') },
        { id: 'aguardando_aprovacao', nome: formatarNomeStatus('aguardando_aprovacao') },
        { id: 'resolvido', nome: formatarNomeStatus('resolvido') },
        { id: 'encerrado', nome: formatarNomeStatus('encerrado') },
        { id: 'cancelado', nome: formatarNomeStatus('cancelado') },
        { id: 'reaberto', nome: formatarNomeStatus('reaberto') }
    ];
    
    const opcoes = statusDisponiveis.map(status => ({
        id: status.id,
        nome: `${STATUS_CONFIG[status.id]?.icone || ''} ${status.nome}`,
        descricao: STATUS_CONFIG[status.id]?.descricao || `Status: ${status.nome}`
    }));
    
    mostrarSelectComDescricao(
        'Alterar Status',
        'Selecione o novo status do ticket:',
        opcoes,
        function(statusEscolhido) {
            if (statusEscolhido) {
                executarAlteracaoStatusNovo(ticketId, statusEscolhido.id);
            }
        }
    );
}

// Executar alteração de status com novo sistema
function executarAlteracaoStatusNovo(ticketId, novoStatus, observacoes = null) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/helpdesk-crprs/api/controllers/tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    
    // Adicionar token de autenticação
    const token = localStorage.getItem('token');
    if (token) {
        xhr.setRequestHeader('Authorization', `Bearer ${token}`);
    }
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            const statusNome = STATUS_CONFIG[novoStatus]?.nome || novoStatus;
            mostrarToast('Sucesso', `Status alterado para "${statusNome}" com sucesso!`, 'success');
            
            // Recarregar lista de tickets
            carregarTickets();
            
            // Se estiver na página de detalhes, recarregar detalhes
            const ticketAtivo = localStorage.getItem('ticketAtivo');
            if (ticketAtivo && parseInt(ticketAtivo) === ticketId) {
                setTimeout(() => verDetalhes(ticketId), 300);
            }
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao alterar status');
        }
    };
    
    xhr.onerror = function() {
        mostrarAlert('Erro', 'Erro de conexão');
    };
    
    const dados = { status: novoStatus };
    if (observacoes) {
        dados.observacoes = observacoes;
    }
    
    xhr.send(JSON.stringify(dados));
}

// Alterar prioridade com novo sistema
function alterarPrioridadeNova(ticketId) {
    carregarPrioridadesDisponiveis().then(prioridadesList => {
        const opcoes = prioridadesList.map(prioridade => ({
            id: prioridade.nome,
            nome: `${prioridade.icone} ${prioridade.nome}`,
            descricao: `${prioridade.descricao} (SLA: ${prioridade.sla_resposta_minutos}min resposta / ${prioridade.sla_resolucao_horas}h resolução)`
        }));
        
        mostrarSelectComDescricao(
            'Alterar Prioridade',
            'Selecione a nova prioridade do ticket:',
            opcoes,
            function(prioridadeEscolhida) {
                if (prioridadeEscolhida) {
                    executarAlteracaoPrioridadeNova(ticketId, prioridadeEscolhida.id);
                }
            }
        );
    }).catch(error => {
        mostrarAlert('Erro', error);
    });
}

// Executar alteração de prioridade
function executarAlteracaoPrioridadeNova(ticketId, novaPrioridade) {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', `/helpdesk-crprs/api/controllers/tickets.php?id=${ticketId}`, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    
    // Adicionar token de autenticação
    const token = localStorage.getItem('token');
    if (token) {
        xhr.setRequestHeader('Authorization', `Bearer ${token}`);
    }
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            const prioridadeNome = PRIORIDADE_CONFIG[novaPrioridade]?.nome || novaPrioridade;
            mostrarToast('Sucesso', `Prioridade alterada para "${prioridadeNome}" com sucesso!`, 'success');
            
            // Recarregar lista de tickets
            carregarTickets();
            
            // Se estiver na página de detalhes, recarregar detalhes
            const ticketAtivo = localStorage.getItem('ticketAtivo');
            if (ticketAtivo && parseInt(ticketAtivo) === ticketId) {
                setTimeout(() => verDetalhes(ticketId), 300);
            }
        } else {
            mostrarAlert('Erro', 'Erro ao alterar prioridade');
        }
    };
    
    xhr.onerror = function() {
        mostrarAlert('Erro', 'Erro de conexão');
    };
    
    xhr.send(JSON.stringify({ prioridade: novaPrioridade }));
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
        
        div.onclick = function() {
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
    
    modalCallback = function(confirmado) {
        const valor = confirmado ? document.getElementById('modal-prompt-input').value : null;
        if (callback) callback(valor);
    };
    
    setTimeout(() => document.getElementById('modal-prompt-input').focus(), 100);
}

// Calcular tempo restante de SLA (considerando apenas horas úteis)
function calcularTempoRestanteSLA(ticket) {
    if (!ticket.criado_em) return null;
    
    const agora = new Date();
    const criacao = new Date(ticket.criado_em);
    
    // Calcular tempo decorrido em horas úteis
    const tempoDecorridoUtil = calcularSegundosUteisOtimizado(criacao, agora);
    
    // Subtrair tempo pausado (se houver)
    const tempoPausado = ticket.tempo_pausado_segundos || 0;
    const tempoDecorrido = tempoDecorridoUtil - tempoPausado;
    
    const prioridade = PRIORIDADE_CONFIG[ticket.prioridade] || PRIORIDADE_CONFIG['normal'];
    
    // Verificar se SLA está pausado
    const statusPausaSLA = ['aguardando', 'aguardando_resposta', 'aguardando_terceiros', 'aguardando_aprovacao', 'aguardando_data', 'resolvido'];
    const slaPausado = statusPausaSLA.includes(ticket.status);
    
    // SLA de resposta (se ainda não teve primeira resposta)
    if (!ticket.primeira_resposta_em) {
        const slaRespostaSegundos = prioridade.sla_resposta_minutos * 60;
        const tempoRestante = slaRespostaSegundos - tempoDecorrido;
        
        return {
            tipo: 'resposta',
            tempoRestante: tempoRestante,
            vencido: tempoRestante <= 0 && !slaPausado,
            percentual: Math.min(100, (tempoDecorrido / slaRespostaSegundos) * 100),
            pausado: slaPausado
        };
    }
    
    // SLA de resolução (se ainda não foi resolvido)
    if (!ticket.resolvido_em && !['resolvido', 'encerrado', 'cancelado'].includes(ticket.status)) {
        const slaResolucaoSegundos = prioridade.sla_resolucao_horas * 3600;
        const tempoRestante = slaResolucaoSegundos - tempoDecorrido;
        
        return {
            tipo: 'resolucao',
            tempoRestante: tempoRestante,
            vencido: tempoRestante <= 0 && !slaPausado,
            percentual: Math.min(100, (tempoDecorrido / slaResolucaoSegundos) * 100),
            pausado: slaPausado
        };
    }
    
    return null;
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

// Obter cor do SLA baseado no percentual
function obterCorSLA(percentual) {
    if (percentual >= 100) return '#dc3545'; // Vermelho - vencido
    if (percentual >= 80) return '#fd7e14';  // Laranja - crítico
    if (percentual >= 50) return '#ffc107';  // Amarelo - atenção
    return '#28a745'; // Verde - ok
}

// Atualizar indicadores de SLA na interface
function atualizarIndicadoresSLA() {
    if (!ticketsAtuais || ticketsAtuais.length === 0) return;
    
    document.querySelectorAll('[data-ticket-id]').forEach(row => {
        const ticketId = parseInt(row.getAttribute('data-ticket-id'));
        const ticket = ticketsAtuais.find(t => t.id === ticketId);
        
        if (ticket && ticket.status !== 'encerrado' && ticket.status !== 'fechado') {
            const slaInfo = calcularTempoRestanteSLA(ticket);
            
            if (slaInfo) {
                // Remover indicador existente
                const indicadorExistente = row.querySelector('.sla-indicator');
                if (indicadorExistente) {
                    indicadorExistente.remove();
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
                    const statusTexto = slaInfo.pausado ? '\n⏸️ SLA pausado (status aguardando)' : '';
                    const vencidoTexto = slaInfo.vencido ? 'Vencido' : formatarTempo(slaInfo.tempoRestante);
                    const percentualTexto = Math.round(slaInfo.percentual);
                    
                    indicador.title = `🕒 SLA de ${tipoTexto}\n` +
                                     `📈 Progresso: ${percentualTexto}%\n` +
                                     `⏱️ Tempo: ${vencidoTexto}${statusTexto}\n\n` +
                                     `💡 O SLA mede o tempo útil (horário comercial)\n` +
                                     `desde a abertura do ticket até a ${slaInfo.tipo === 'resposta' ? 'atribuição' : 'resolução'}.`;
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
        }
    });
}

// Inicializar sistema de SLA
function inicializarSistemaSLA() {
    // Carregar horários configurados
    carregarHorariosConfigurados().then(() => {
        // Atualizar indicadores a cada minuto
        setInterval(atualizarIndicadoresSLA, 60000);
        
        // Atualizar na primeira carga
        setTimeout(atualizarIndicadoresSLA, 1000);
    }).catch(error => {
        console.error('Erro ao carregar horários:', error);
    });
}

// Exportar funções para uso global
window.alterarStatusNovo = alterarStatusNovo;
window.alterarPrioridadeNova = alterarPrioridadeNova;
window.STATUS_CONFIG = STATUS_CONFIG;
window.PRIORIDADE_CONFIG = PRIORIDADE_CONFIG;
window.inicializarSistemaSLA = inicializarSistemaSLA;