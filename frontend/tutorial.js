// Sistema de Tutorial Interativo
const tutorialSteps = [
    {
        target: 'body',
        title: '👋 Bem-vindo ao Conecta CRPRS!',
        description: 'Olá! Este é um tutorial rápido para ajudá-lo a conhecer as principais funcionalidades do sistema. Você pode pular a qualquer momento ou seguir os passos para aprender.',
        position: 'center',
        isWelcome: true
    },
    {
        target: '.btn-nav:nth-child(1)',
        title: '🎫 Lista de Demandas',
        description: 'Aqui você visualiza todas as demandas abertas. Clique no número da demanda para ver detalhes completos.',
        position: 'bottom'
    },
    {
        target: '#btn-nav-arquivo',
        title: '🗂️ Arquivo',
        description: 'Acesse o histórico de demandas encerradas ou canceladas. Você pode reabrir demandas se necessário.',
        position: 'bottom'
    },
    {
        target: '.btn-nav:nth-child(3)',
        title: '📊 Dashboard',
        description: 'Acompanhe métricas em tempo real: total de demandas, tempo médio de atendimento, gráficos de status e muito mais.',
        position: 'bottom'
    },
    {
        target: '.btn-nav:nth-child(4)',
        title: '📈 Relatórios',
        description: 'Gere relatórios personalizados filtrando por período, status, prioridade, subcategoria ou responsável.',
        position: 'bottom'
    },
    {
        target: '#btn-nav-ajuda',
        title: '❓ Ajuda',
        description: 'Consulte informações sobre SLA, status das demandas, prioridades e atalhos de teclado disponíveis.',
        position: 'bottom'
    },
    {
        target: '#btn-novo-ticket',
        title: '➕ Criar Nova Demanda',
        description: 'Clique aqui para abrir uma nova demanda. Preencha título, descrição, prioridade, subcategoria e anexe arquivos se necessário.',
        position: 'left'
    },
    {
        target: '#btn-merge',
        title: '🔗 Mesclar Demandas',
        description: 'Use esta função para unir demandas duplicadas ou relacionadas em uma única demanda principal.',
        position: 'left'
    },
    {
        target: '.tickets-tabela th:nth-child(1)',
        title: '🔢 Número da Demanda',
        description: 'Clique no número da demanda para acessar os detalhes completos, incluindo comentários, histórico de alterações e anexos.',
        position: 'right',
        arrow: 'down'
    },
    {
        target: '.tickets-tabela th:nth-child(5)',
        title: '👤 Atribuir Responsável',
        description: 'Clique no nome do responsável (ou "Não atribuído") para encaminhar ou alterar o responsável pela demanda.',
        position: 'left',
        arrow: 'down'
    },
    {
        target: '.tickets-tabela th:nth-child(6)',
        title: '📁 Categorizar Demanda',
        description: 'Clique na subcategoria para alterar ou definir a subcategoria da demanda. Isso ajuda na organização e relatórios.',
        position: 'left',
        arrow: 'down'
    },
    {
        target: '.tickets-tabela th:nth-child(7)',
        title: '📊 Alterar Status',
        description: 'Clique no status para alterar entre: Aberto, Em atendimento, Aguardando, Resolvido, Encerrado, etc.',
        position: 'left',
        arrow: 'down'
    },
    {
        target: '.tickets-tabela th:nth-child(8)',
        title: '🔥 Alterar Prioridade',
        description: 'Clique na prioridade para alterar entre: Baixa, Normal, Alta ou Urgente. Isso afeta o SLA da demanda.',
        position: 'left',
        arrow: 'down'
    },
    {
        target: 'body',
        title: '✅ Tutorial Concluído!',
        description: 'Parabéns! Você conheceu as principais funcionalidades do Conecta CRPRS. Você pode reabrir este tutorial a qualquer momento acessando o menu <strong>Ajuda > Tutorial Interativo</strong>.',
        position: 'center',
        isFinal: true
    }
];

let currentStep = 0;
let tutorialActive = false;

function iniciarTutorial() {
    // Verificar se já viu o tutorial
    const jaViuTutorial = localStorage.getItem('cd_tutorialCompleto');
    if (jaViuTutorial === 'true') return;

    // Ir para lista de tickets abertos
    mostrarSecao('tickets');
    carregarTickets();

    tutorialActive = true;
    currentStep = 0;

    // Aguardar carregamento dos tickets antes de iniciar
    setTimeout(() => {
        criarElementosTutorial();
        mostrarPasso(0);
    }, 500);
}

function criarElementosTutorial() {
    // Remover elementos antigos se existirem
    const oldOverlay = document.getElementById('tutorial-overlay');
    const oldSpotlight = document.getElementById('tutorial-spotlight');
    const oldCard = document.getElementById('tutorial-card');
    const oldArrow = document.getElementById('tutorial-arrow');

    if (oldOverlay) oldOverlay.remove();
    if (oldSpotlight) oldSpotlight.remove();
    if (oldCard) oldCard.remove();
    if (oldArrow) oldArrow.remove();

    // Overlay
    const overlay = document.createElement('div');
    overlay.id = 'tutorial-overlay';
    overlay.className = 'tutorial-overlay';
    document.body.appendChild(overlay);

    // Spotlight
    const spotlight = document.createElement('div');
    spotlight.id = 'tutorial-spotlight';
    spotlight.className = 'tutorial-spotlight';
    document.body.appendChild(spotlight);

    // Card
    const card = document.createElement('div');
    card.id = 'tutorial-card';
    card.className = 'tutorial-card';
    document.body.appendChild(card);
}

function mostrarTutorialAjuda() {
    reiniciarTutorial();
}

function mostrarPasso(step) {
    console.log('Tutorial - Passo:', step, 'Total:', tutorialSteps.length);

    if (step >= tutorialSteps.length) {
        finalizarTutorial();
        return;
    }

    currentStep = step;
    const passo = tutorialSteps[step];
    console.log('Tutorial - Configuração do passo:', passo);
    const target = document.querySelector(passo.target);

    if (!target) {
        // Se elemento não existe, pular para próximo
        mostrarPasso(step + 1);
        return;
    }

    // Mostrar overlay e spotlight
    const overlay = document.getElementById('tutorial-overlay');
    const spotlight = document.getElementById('tutorial-spotlight');
    const card = document.getElementById('tutorial-card');
    const arrow = document.getElementById('tutorial-arrow');

    // Remover seta anterior
    if (arrow) arrow.remove();

    // Posicionar spotlight no elemento
    const rect = target.getBoundingClientRect();

    // Sempre ativar overlay para bloquear cliques
    overlay.classList.add('show');

    if (passo.isWelcome || passo.isFinal) {
        // Para telas de boas-vindas e final, overlay escuro e esconder spotlight
        overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.85)';
        spotlight.style.display = 'none';
        spotlight.style.boxShadow = 'none';
        console.log('Tela inicial/final - Overlay escuro ativado');
    } else {
        // Para destaques, overlay transparente e spotlight com box-shadow
        overlay.style.backgroundColor = 'transparent';
        spotlight.style.display = 'block';
        spotlight.style.boxShadow = '0 0 0 9999px rgba(0, 0, 0, 0.5)';
        spotlight.style.top = (rect.top - 5) + 'px';
        spotlight.style.left = (rect.left - 5) + 'px';
        spotlight.style.width = (rect.width + 10) + 'px';
        spotlight.style.height = (rect.height + 10) + 'px';
        console.log('Passo intermediário - Spotlight ativado');

        // Adicionar seta se necessário
        if (passo.arrow) {
            criarSeta(rect, passo.arrow);
        }
    }

    // Posicionar card
    posicionarCard(card, rect, passo.position);

    // Conteúdo do card
    const isWelcome = passo.isWelcome;
    const isFinal = passo.isFinal;

    if (isWelcome) {
        card.innerHTML = `
            <h2 style="text-align: center; margin-bottom: 1rem;">${passo.title}</h2>
            <p style="text-align: center; font-size: 1.1rem; margin-bottom: 2rem;">${passo.description}</p>
            <div class="tutorial-buttons" style="justify-content: center; gap: 1rem;">
                <button class="btn-tutorial btn-tutorial-skip" onclick="pularTutorial()">Pular Tutorial</button>
                <button class="btn-tutorial btn-tutorial-next" onclick="proximoPasso()">Iniciar Tutorial</button>
            </div>
        `;
    } else if (isFinal) {
        card.innerHTML = `
            <h2 style="text-align: center; margin-bottom: 1rem;">${passo.title}</h2>
            <p style="text-align: center; font-size: 1.1rem; margin-bottom: 2rem;">${passo.description}</p>
            <div class="tutorial-buttons" style="justify-content: center;">
                <button class="btn-tutorial btn-tutorial-finish" onclick="finalizarTutorial()">Concluir</button>
            </div>
        `;
    } else {
        card.innerHTML = `
            <h3>${passo.title}</h3>
            <p>${passo.description}</p>
            <div class="tutorial-footer">
                <div class="tutorial-progress">${step + 1} de ${tutorialSteps.length}</div>
                <div class="tutorial-buttons">
                    <button class="btn-tutorial btn-tutorial-skip" onclick="pularTutorial()">Pular</button>
                    <button class="btn-tutorial btn-tutorial-next" onclick="proximoPasso()">Próximo</button>
                </div>
            </div>
        `;
    }
}

function criarSeta(targetRect, direction) {
    const arrow = document.createElement('div');
    arrow.id = 'tutorial-arrow';
    arrow.className = 'tutorial-arrow';

    if (direction === 'down') {
        arrow.style.top = (targetRect.bottom + 10) + 'px';
        arrow.style.left = (targetRect.left + targetRect.width / 2 - 15) + 'px';
        arrow.innerHTML = '↓';
    }

    document.body.appendChild(arrow);
}

function posicionarCard(card, targetRect, position) {
    const cardWidth = 400;
    const cardHeight = 200;
    const margin = 20;

    let top, left;

    if (position === 'center') {
        top = (window.innerHeight / 2) - (cardHeight / 2);
        left = (window.innerWidth / 2) - (cardWidth / 2);
        card.style.top = top + 'px';
        card.style.left = left + 'px';
        return;
    }

    switch (position) {
        case 'bottom':
            top = targetRect.bottom + margin;
            left = targetRect.left + (targetRect.width / 2) - (cardWidth / 2);
            break;
        case 'top':
            top = targetRect.top - cardHeight - margin;
            left = targetRect.left + (targetRect.width / 2) - (cardWidth / 2);
            break;
        case 'left':
            top = targetRect.top + (targetRect.height / 2) - (cardHeight / 2);
            left = targetRect.left - cardWidth - margin;
            break;
        case 'right':
            top = targetRect.top + (targetRect.height / 2) - (cardHeight / 2);
            left = targetRect.right + margin;
            break;
        default:
            top = targetRect.bottom + margin;
            left = targetRect.left;
    }

    // Ajustar se sair da tela
    if (left < 20) left = 20;
    if (left + cardWidth > window.innerWidth - 20) left = window.innerWidth - cardWidth - 20;
    if (top < 20) top = 20;
    if (top + cardHeight > window.innerHeight - 20) top = window.innerHeight - cardHeight - 20;

    card.style.top = top + 'px';
    card.style.left = left + 'px';
}

function proximoPasso() {
    mostrarPasso(currentStep + 1);
}

function pularTutorial() {
    localStorage.setItem('cd_tutorialCompleto', 'true');
    finalizarTutorial();
}

function finalizarTutorial() {
    tutorialActive = false;
    localStorage.setItem('cd_tutorialCompleto', 'true');

    // Remover elementos
    const overlay = document.getElementById('tutorial-overlay');
    const spotlight = document.getElementById('tutorial-spotlight');
    const card = document.getElementById('tutorial-card');
    const arrow = document.getElementById('tutorial-arrow');

    if (overlay) overlay.remove();
    if (spotlight) spotlight.remove();
    if (card) card.remove();
    if (arrow) arrow.remove();

    mostrarToast('Tutorial Concluído', 'Você pode acessar a ajuda a qualquer momento clicando no menu Ajuda!', 'success');
}

// Função para reiniciar tutorial (pode ser chamada do menu)
function reiniciarTutorial() {
    localStorage.removeItem('cd_tutorialCompleto');

    // Ir para lista de tickets abertos
    mostrarSecao('tickets');
    carregarTickets();

    tutorialActive = true;
    currentStep = 0;

    // Aguardar carregamento dos tickets antes de iniciar
    setTimeout(() => {
        criarElementosTutorial();
        mostrarPasso(0);
    }, 500);
}

// Iniciar tutorial após 2 segundos do carregamento (primeiro login)
setTimeout(() => {
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    // Com troca de senha pendente, o tutorial espera o próximo carregamento
    if (usuario && !usuario.trocar_senha && (['operador', 'gestor', 'admin'].includes(usuario.tipo))) {
        iniciarTutorial();
    }
}, 2000);
