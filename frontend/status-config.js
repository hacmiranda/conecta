// Configuração de Status - Conecta CRPRS
// Mapeamento de status do banco para exibição no frontend

const STATUS_CONFIG = {
    'novo': {
        nome: 'Novo',
        icone: '🆕',
        cor: '#f89327'
    },
    'em_atendimento': {
        nome: 'Em atendimento',
        icone: '🔧',
        cor: '#17a2b8'
    },
    'aguardando': {
        nome: 'Aguardando',
        icone: '⏳',
        cor: '#ffc107'
    },
    'aguardando_resposta': {
        nome: 'Aguardando resposta',
        icone: '⏳',
        cor: '#ffc107'
    },
    'aguardando_terceiros': {
        nome: 'Aguardando terceiros',
        icone: '🔄',
        cor: '#8b1d17'
    },
    'aguardando_aprovacao': {
        nome: 'Aguardando aprovação',
        icone: '📋',
        cor: '#fd7e14'
    },
    'aguardando_data': {
        nome: 'Aguardando data',
        icone: '📅',
        cor: '#20c997'
    },
    'resolvido': {
        nome: 'Resolvido',
        icone: '✅',
        cor: '#28a745'
    },
    'encerrado': {
        nome: 'Encerrado',
        icone: '🏁',
        cor: '#5f5852'
    },
    'cancelado': {
        nome: 'Cancelado',
        icone: '❌',
        cor: '#dc3545'
    },
    'reaberto': {
        nome: 'Reaberto',
        icone: '🔄',
        cor: '#a7241c'
    },
    'nova_resposta': {
        nome: 'Nova Resposta',
        icone: '💬',
        cor: '#17a2b8'
    }
};

// Função para formatar nome do status
function formatarNomeStatus(status) {
    if (!status) return 'Novo';

    const config = STATUS_CONFIG[status.toLowerCase()];
    return config ? config.nome : status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
}

// Função para formatar nome da prioridade
function formatarNomePrioridade(prioridade) {
    if (!prioridade) return 'Normal';

    const prioridades = {
        'baixa': 'Baixa',
        'normal': 'Normal',
        'alta': 'Alta',
        'urgente': 'Urgente'
    };

    return prioridades[prioridade.toLowerCase()] || prioridade.charAt(0).toUpperCase() + prioridade.slice(1);
}
