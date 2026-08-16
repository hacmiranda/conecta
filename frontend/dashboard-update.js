// Atualização da função mostrarDashboardNaTela
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
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>🎫 Total de Demandas</h3>
                <div class="dashboard-number">${dados.total_tickets}</div>
            </div>
            <div class="dashboard-card">
                <h3>⏱️ Tempo Médio Total</h3>
                <div class="dashboard-number">${formatarTempo(dados.tempoMedioSegundos)}</div>
            </div>
            <div class="dashboard-card">
                <h3>⚡ Tempo Médio 1º Atendimento</h3>
                <div class="dashboard-number">${formatarTempo(dados.tempoPrimeiroAtendimentoSegundos)}</div>
            </div>
            <div class="dashboard-card">
                <h3>✅ Taxa de Conclusão</h3>
                <div class="dashboard-number">${taxaConclusao}%</div>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>📊 Demandas por Status</h3>
                <canvas id="grafico-status" width="400" height="300"></canvas>
            </div>
            <div class="dashboard-card">
                <h3>🔥 Demandas por Prioridade</h3>
                <canvas id="grafico-prioridade" width="400" height="200"></canvas>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>📈 Evolução Mensal</h3>
                <canvas id="grafico-mensal" width="600" height="250"></canvas>
            </div>
            <div class="dashboard-card">
                <h3>🥧 Distribuição por Agente</h3>
                <canvas id="grafico-agente" width="400" height="250"></canvas>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>📊 Taxa de Resolução</h3>
                <canvas id="grafico-resolucao" width="400" height="200"></canvas>
            </div>
            <div class="dashboard-card">
                <h3>📅 Demandas por Dia da Semana</h3>
                <canvas id="grafico-dia-semana" width="400" height="200"></canvas>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>📊 Status por Agente</h3>
                <canvas id="grafico-status-agente" width="600" height="300"></canvas>
            </div>
            <div class="dashboard-card">
                <h3>🔄 Funil de Conversão</h3>
                <canvas id="grafico-funil" width="400" height="300"></canvas>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>🏆 Top 5 Subcategorias</h3>
                <div class="dashboard-list">`;
    
    (dados.top_categorias || []).forEach(item => {
        html += `<div class="dashboard-item"><span>${item.nome || 'Sem subcategoria'}</span><span>${item.total}</span></div>`;
    });
    
    html += `</div></div><div class="dashboard-card"><h3>📁 Demandas por Categoria</h3><div class="dashboard-list">`;
    
    (dados.ticketsPorMacrocategoria || []).forEach(item => {
        html += `<div class="dashboard-item"><span>${item.nome || 'Sem categoria'}</span><span>${item.quantidade}</span></div>`;
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
    }, 100);
}
