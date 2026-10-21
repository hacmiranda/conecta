/**
 * Dashboard (painel principal de KPIs/gráficos + modal de informações de cada gráfico).
 * A criação dos gráficos (Chart.js) já é um arquivo separado pré-existente
 * (graficos-dashboard.js), fora desta divisão — este arquivo só monta o HTML e chama
 * criarGraficoX(dados) depois. Extraído de script.js na Fase 12 (onda 8 da divisão por
 * domínio).
 */

// Função para mostrar dashboard
function mostrarDashboard() {
    mostrarSecao('dashboard');
    carregarDashboard();
}

// Carregar dados do dashboard
async function carregarDashboard() {
    try {
        const dados = await Api.get(urlComSetor('dashboard.php'));
        mostrarDashboardNaTela(dados);
    } catch (e) {
        document.getElementById('dashboard-content').innerHTML = '<p>Erro ao carregar dashboard.</p>';
    }
}

// Mostrar dashboard na tela
function mostrarDashboardNaTela(dados) {
    const content = document.getElementById('dashboard-content');

    function formatarTempo(segundos) {
        if (!segundos) return 'N/A';
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        return `${horas}h ${minutos}m`;
    }

    const taxaConclusao = dados.sla_indicadores?.taxa_conclusao || 0;

    let html = `
        <div class="dashboard-grid kpi-row-5">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">tag</span> Total de Demandas
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('total')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up" data-target="${dados.total_tickets}">0</div>
                <div class="dashboard-subtitle">Histórico geral do painel</div>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">check_circle</span> Encerrados
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('encerrados')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up" data-target="${dados.total_encerrados || 0}">0</div>
                <div class="dashboard-subtitle">Concluídos com sucesso</div>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">pending_actions</span> Ativos
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('ativos')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up" data-target="${dados.total_tickets - (dados.total_encerrados || 0)}">0</div>
                <div class="dashboard-subtitle">Aguardando time ou usuário</div>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">task_alt</span> Taxa de Conclusão
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('taxa-conclusao')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number flip-in">${taxaConclusao}%</div>
                <div class="dashboard-subtitle">Percentual de finalização</div>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">timer</span> Tempo Médio Total
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('tempo-total')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number scale-bounce">${formatarTempo(dados.tempoMedioSegundos)}</div>
                <div class="dashboard-subtitle">Até o encerramento da demanda</div>
            </div>
        </div>

        <div class="dashboard-grid kpi-row-5" style="margin-top: 1.5rem;">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">bolt</span> Tempo Médio 1º Atend.
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('primeiro-atendimento')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number slide-down">${formatarTempo(dados.tempoPrimeiroAtendimentoSegundos)}</div>
                <div class="dashboard-subtitle">Velocidade da primeira resposta</div>
            </div>
            <div class="dashboard-card fcr-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">rocket_launch</span> FCR (1º Contato)
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('fcr')" title="Clique para mais informações" aria-hidden="true" style="color: rgba(255,255,255,0.8);">info</span>
                </h3>
                <div class="dashboard-number flip-in fcr-number">${dados.fcr?.taxa || 0}%</div>
                <div class="fcr-subtitle">
                    ${dados.fcr?.resolvidos_primeiro_contato || 0} de ${dados.fcr?.total_resolvidos || 0} resolvidos na primeira interação
                </div>
            </div>
            <div class="dashboard-card kpi-sla-vencido">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">warning</span> SLA Vencido
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('sla-vencido')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up sla-vencido-number" data-target="${dados.sla_vencido || 0}">0</div>
                <div class="dashboard-subtitle">Demandas ativas com SLA expirado</div>
            </div>
            <div class="dashboard-card kpi-origem-externa">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">api</span> Via Canal Externo
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('origem-externa')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <div class="dashboard-number count-up origem-externa-number" data-target="${dados.total_origem_externa || 0}">0</div>
                <div class="dashboard-subtitle">Demandas recebidas por integração</div>
            </div>
        </div>

        <div class="dashboard-grid graficos-row-4" style="margin-top: 1.5rem;">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">donut_large</span> Demandas por Status <span class="text-caption">(apenas ativos)</span>
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('status')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-status" width="320" height="220"></canvas>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">local_fire_department</span> Demandas por Prioridade <span class="text-caption">(apenas ativos)</span>
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('prioridade')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-prioridade" width="400" height="260"></canvas>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">pie_chart</span> Distribuição por Responsável <span class="text-caption">(apenas ativos)</span>
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('agente')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-agente" width="400" height="260"></canvas>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">stacked_bar_chart</span> Status por Responsável <span class="text-caption">(apenas ativos)</span>
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('status-agente')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-status-agente" width="400" height="260"></canvas>
            </div>
        </div>

        <div class="dashboard-grid" style="margin-top: 1.2rem;">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">show_chart</span> Evolução Mensal
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('evolucao')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-mensal" width="600" height="250"></canvas>
            </div>
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span> Demandas Dia da Semana
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('dia-semana')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-dia-semana" width="400" height="250" style="display:block;margin:0 auto;"></canvas>
            </div>
        </div>

        <div class="dashboard-grid" style="margin-top: 1.2rem;">
            <div class="dashboard-card dashboard-card-fullrow">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">trending_up</span> Evolução Diária — Últimos 30 Dias
                    </div>
                    <span class="material-symbols-outlined info-icon" onclick="mostrarInfoGrafico('diarios-30d')" title="Clique para mais informações" aria-hidden="true">info</span>
                </h3>
                <canvas id="grafico-diarios-30d" width="900" height="200"></canvas>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">group</span> Top 5 Solicitantes
                    </div>
                </h3>
                <div class="dashboard-list">`;

    (dados.top_solicitantes || []).forEach((item, index) => {
        const trofeu = index === 0 ? ' <span class="material-symbols-outlined item-trofeu" aria-hidden="true">emoji_events</span>' : '';
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${sanitizeHTML(item.nome)}${trofeu}</span><span>${item.total}</span></div>`;
    });

    html += `</div></div><div class="dashboard-card"><h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">category</span> Top 5 Subcategorias
                    </div>
                </h3><div class="dashboard-list">`;

    (dados.top_categorias || []).forEach((item, index) => {
        const trofeu = index === 0 ? ' <span class="material-symbols-outlined item-trofeu" aria-hidden="true">emoji_events</span>' : '';
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${sanitizeHTML(item.nome || 'Sem subcategoria')}${trofeu}</span><span>${item.total}</span></div>`;
    });

    html += `</div></div><div class="dashboard-card"><h3 class="card-title-flex">
                    <div class="title-left">
                        <span class="material-symbols-outlined" aria-hidden="true">folder_open</span> Demandas por Categoria
                    </div>
                </h3><div class="dashboard-list">`;

    (dados.ticketsPorMacrocategoria || []).forEach((item, index) => {
        const trofeu = index === 0 ? ' <span class="material-symbols-outlined item-trofeu" aria-hidden="true">emoji_events</span>' : '';
        html += `<div class="dashboard-item dashboard-item-animate" data-delay="${index * 0.1}"><span>${sanitizeHTML(item.nome || 'Sem categoria')}${trofeu}</span><span>${item.quantidade}</span></div>`;
    });

    html += `</div></div></div>`;

    content.innerHTML = html;

    setTimeout(() => {
        criarGraficoStatus(dados);
        criarGraficoPrioridade(dados);
        criarGraficoMensal(dados);
        criarGraficoAgente(dados);
        criarGraficoResolucao(dados);
        criarGraficoDiaSemana(dados);
        criarGraficoStatusAgente(dados);
        criarGraficoFunil(dados);
        criarGraficoDiarios30d(dados);
        criarGraficoStatusMensal(dados);
        criarGraficoHeatmap(dados);

        // Animar itens do dashboard quando visíveis
        setTimeout(() => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const delay = entry.target.getAttribute('data-delay') || 0;
                        setTimeout(() => {
                            entry.target.style.animation = 'slideInLeft 0.5s ease forwards';
                        }, delay * 1000);
                        observer.unobserve(entry.target);
                    }
                });
            });

            document.querySelectorAll('.dashboard-item-animate').forEach(item => {
                observer.observe(item);
            });

            // Animar contadores
            document.querySelectorAll('.count-up').forEach(counter => {
                const target = parseInt(counter.getAttribute('data-target'));
                const duration = 2000;
                const increment = target / (duration / 16);
                let current = 0;

                const updateCounter = () => {
                    current += increment;
                    if (current < target) {
                        counter.textContent = Math.floor(current);
                        requestAnimationFrame(updateCounter);
                    } else {
                        counter.textContent = target;
                    }
                };
                updateCounter();
            });
        }, 200);
    }, 100);
}

// Função para mostrar informações sobre os gráficos
function mostrarInfoGrafico(tipo) {
    const infos = {
        'total': {
            titulo: '🎫 Total de Demandas',
            descricao: 'Mostra o número total de demandas registradas no sistema.',
            analise: 'Use para acompanhar o volume geral de demandas. Um aumento pode indicar necessidade de mais recursos ou problemas recorrentes.'
        },
        'encerrados': {
            titulo: '✅ Demandas Encerradas',
            descricao: 'Total de demandas com status "encerrado".',
            analise: 'Indica o volume de demandas finalizadas. Use para acompanhar a produtividade da equipe ao longo do tempo.'
        },
        'ativos': {
            titulo: '🔄 Demandas Ativas',
            descricao: 'Total de demandas em andamento (todos os status exceto encerrado).',
            analise: 'Mostra a carga de trabalho atual da equipe. Valores altos podem indicar sobrecarga ou necessidade de mais recursos.'
        },
        'tempo-total': {
            titulo: '⏱️ Tempo Médio Total',
            descricao: 'Tempo médio desde a abertura até o fechamento das demandas.',
            analise: 'Indica a eficiência geral do atendimento. Valores altos podem indicar gargalos no processo ou demandas complexas.'
        },
        'primeiro-atendimento': {
            titulo: '⚡ Tempo Médio 1º Atendimento',
            descricao: 'Tempo médio até a primeira resposta da equipe à demanda.',
            analise: 'Métrica crítica de SLA. Valores altos indicam demora na resposta inicial, afetando a satisfação do usuário.'
        },
        'taxa-conclusao': {
            titulo: '✅ Taxa de Conclusão',
            descricao: 'Percentual de demandas concluídas em relação ao total.',
            analise: 'Indica a efetividade da equipe em resolver demandas. Valores baixos podem indicar demandas abandonadas ou mal gerenciadas.'
        },
        'fcr': {
            titulo: '🚀 FCR (First Contact Resolution)',
            descricao: 'Taxa de demandas resolvidas logo no primeiro contato/resposta, sem precisarem de interações adicionais ou vaivém com o solicitante.',
            analise: 'A principal métrica de eficiência do Suporte. Valores altos significam que a equipe é altamente assertiva e tem forte domínio sobre os problemas dos usuários logo de primeira.'
        },
        'status': {
            titulo: '📊 Demandas por Status',
            descricao: 'Distribuição das demandas pelos diferentes status do sistema.',
            analise: 'Identifique gargalos: muitas demandas "Abertas" indicam falta de atribuição, muitas "Aguardando" podem indicar dependências externas.'
        },
        'prioridade': {
            titulo: '🔥 Demandas por Prioridade',
            descricao: 'Distribuição das demandas por nível de prioridade (Urgente, Alta, Normal, Baixa).',
            analise: 'Verifique se há excesso de demandas urgentes (pode indicar problemas sistêmicos) ou se as prioridades estão bem distribuídas.'
        },
        'evolucao': {
            titulo: '📈 Evolução Mensal',
            descricao: 'Gráfico de linha mostrando a quantidade de demandas criadas por mês nos últimos 12 meses.',
            analise: 'Identifique tendências: crescimento constante, sazonalidade ou picos específicos que requerem atenção.'
        },
        'agente': {
            titulo: '🥧 Distribuição por Responsável',
            descricao: 'Gráfico de pizza mostrando a distribuição de demandas entre os responsáveis.',
            analise: 'Verifique o balanceamento da carga de trabalho. Desbalanceamento pode indicar necessidade de redistribuição ou treinamento.'
        },
        'resolucao': {
            titulo: '📊 Taxa de Resolução',
            descricao: 'Comparação entre demandas abertas e fechadas.',
            analise: 'Se há mais abertos que fechados, a equipe pode estar sobrecarregada ou há problemas de eficiência.'
        },
        'dia-semana': {
            titulo: '📅 Demandas por Dia da Semana',
            descricao: 'Distribuição de demandas criadas por dia da semana (Domingo a Sábado).',
            analise: 'Identifique padrões: dias com mais demanda podem requerer mais pessoas disponíveis ou plantões.'
        },
        'status-agente': {
            titulo: '📊 Status por Responsável',
            descricao: 'Gráfico de barras empilhadas mostrando a distribuição de status das demandas por responsável.',
            analise: 'Compare o desempenho dos responsáveis: quem tem mais demandas resolvidas, quem tem mais pendentes, etc.'
        },
        'sla-vencido': {
            titulo: 'SLA Vencido',
            descricao: 'Quantidade de demandas ativas cujo prazo de SLA já foi ultrapassado.',
            analise: 'Valores altos indicam que a equipe não está conseguindo atender dentro dos prazos acordados. Priorize essas demandas imediatamente e avalie se os prazos de SLA estão adequados ao volume de demanda.'
        },
        'origem-externa': {
            titulo: 'Via Canal Externo',
            descricao: 'Demandas criadas automaticamente por integração com canais externos, como e-mail recebido na caixa de suporte.',
            analise: 'Indica o volume de demandas que chegam por canais automáticos. Um volume alto pode justificar regras de triagem automática ou templates de resposta rápida.'
        },
        'diarios-30d': {
            titulo: 'Evolução Diária — Últimos 30 Dias',
            descricao: 'Gráfico de linha mostrando a quantidade de demandas criadas por dia nos últimos 30 dias. O ponto verde marca o dia de maior volume.',
            analise: 'Identifique picos de demanda recentes. Picos isolados podem indicar incidentes pontuais; picos recorrentes em dias específicos sugerem padrões que podem ser antecipados.'
        },
        'status-mensal': {
            titulo: 'Status por Mês',
            descricao: 'Barras empilhadas mostrando a distribuição de demandas por status em cada mês do ano atual.',
            analise: 'Acompanhe a evolução da equipe ao longo do ano. Um aumento na fatia de demandas encerradas ao longo dos meses indica melhora na eficiência. Meses com muitas demandas em aberto merecem atenção.'
        },
        'heatmap': {
            titulo: 'Heatmap: Dia x Hora',
            descricao: 'Mapa de calor cruzando dia da semana com hora do dia, mostrando em quais momentos há maior concentração de abertura de demandas.',
            analise: 'Use para dimensionar a equipe: horários com células mais intensas (ciano) são os de maior demanda. Garanta que haja agentes disponíveis nesses períodos.'
        },
        'funil': {
            titulo: '🔄 Funil de Conversão',
            descricao: 'Visualiza a progressão das demandas através dos status: Aberto → Em Atendimento → Resolvido → Encerrado.',
            analise: 'Identifique gargalos: onde as demandas ficam acumuladas? Muitas "Abertas" indica falta de atribuição, muitas "Em Atendimento" pode indicar complexidade ou falta de recursos.'
        }
    };

    const info = infos[tipo];
    if (!info) return;

    const conteudo = `
        <div style="text-align: left;">
            <h4 style="margin-top: 0; color: var(--laranja);">${info.titulo}</h4>
            <p><strong>O que é:</strong><br>${info.descricao}</p>
            <p><strong>Como analisar:</strong><br>${info.analise}</p>
        </div>
    `;

    document.getElementById('modal-alert-title').textContent = 'Informações do Gráfico';
    document.getElementById('modal-alert-message').innerHTML = conteudo;

    const header = document.querySelector('#modal-alert .modal-header');
    const btnOk = document.querySelector('#modal-alert .btn-modal');

    header.classList.remove('sucesso', 'erro');
    btnOk.classList.remove('btn-success-modal', 'btn-danger-modal', 'btn-primary-modal');
    btnOk.classList.add('btn-primary-modal');

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-alert').classList.add('show');
    modalCallback = null;
}
