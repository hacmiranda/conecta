/**
 * Relatórios (filtros, geração, exportação para Excel/CSV e PDF). Extraído de script.js na
 * Fase 12 (onda 5 da divisão por domínio).
 */

// Função para mostrar relatórios
function mostrarRelatorios() {
    mostrarSecao('relatorios');
    salvarSecaoAtiva('relatorios');
    carregarFiltrosRelatorio();
}

// Carregar opções dos filtros
function carregarFiltrosRelatorio() {
    // Carregar categorias
    Api.get(urlComSetor('categorias.php')).then(categorias => {
        const select = document.getElementById('filtro-categoria');
        categorias.forEach(cat => {
            const option = document.createElement('option');
            option.value = cat.id;
            option.textContent = cat.nome;
            select.appendChild(option);
        });
    }).catch(() => { });

    // Carregar agentes
    Api.get('agentes.php').then(agentes => {
        const select = document.getElementById('filtro-agente');
        agentes.forEach(agente => {
            const option = document.createElement('option');
            option.value = agente.id;
            option.textContent = agente.nome;
            select.appendChild(option);
        });
    }).catch(() => { });
}

// Controlar exibição de datas personalizadas
document.addEventListener('DOMContentLoaded', function () {
    const filtroPeriodo = document.getElementById('filtro-periodo');
    const datasPersonalizadas = document.getElementById('datas-personalizadas');

    if (filtroPeriodo) {
        filtroPeriodo.addEventListener('change', function () {
            if (this.value === 'personalizado') {
                datasPersonalizadas.style.display = 'block';
            } else {
                datasPersonalizadas.style.display = 'none';
            }
        });
    }
});

// Gerar relatório
async function gerarRelatorio() {
    const periodo = document.getElementById('filtro-periodo').value;
    const status = document.getElementById('filtro-status').value;
    const prioridade = document.getElementById('filtro-prioridade').value;
    const categoria = document.getElementById('filtro-categoria').value;
    const agente = document.getElementById('filtro-agente').value;

    let dataInicio, dataFim;

    // Calcular datas baseado no período
    const hoje = new Date();
    switch (periodo) {
        case 'mes-atual':
            dataInicio = new Date(hoje.getFullYear(), hoje.getMonth(), 1).toISOString().split('T')[0];
            dataFim = hoje.toISOString().split('T')[0];
            break;
        case 'mes-anterior':
            const mesAnterior = new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1);
            dataInicio = mesAnterior.toISOString().split('T')[0];
            dataFim = new Date(hoje.getFullYear(), hoje.getMonth(), 0).toISOString().split('T')[0];
            break;
        case 'trimestre':
            const trimestre = Math.floor(hoje.getMonth() / 3) * 3;
            dataInicio = new Date(hoje.getFullYear(), trimestre, 1).toISOString().split('T')[0];
            dataFim = hoje.toISOString().split('T')[0];
            break;
        case 'ano':
            dataInicio = new Date(hoje.getFullYear(), 0, 1).toISOString().split('T')[0];
            dataFim = hoje.toISOString().split('T')[0];
            break;
        case 'personalizado':
            dataInicio = document.getElementById('data-inicio').value;
            dataFim = document.getElementById('data-fim').value;
            break;
    }

    const params = new URLSearchParams({
        dataInicio,
        dataFim,
        status,
        prioridade,
        categoria,
        agente
    });

    try {
        const dados = await Api.get(urlComSetor(`relatorios.php?${params}`));
        exibirRelatorio(dados);
    } catch (e) {
        document.getElementById('relatorio-resultado').innerHTML = `<p>${sanitizeHTML((e.dados && e.dados.error) || 'Erro ao gerar relatório.')}</p>`;
    }
}

// Exibir relatório na tela
function exibirRelatorio(dados) {
    dadosRelatorioAtual = dados;
    const container = document.getElementById('relatorio-resultado');

    function formatarTempo(segundos) {
        if (!segundos) return 'N/A';
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        return `${horas}h ${minutos}m`;
    }

    let html = `
        <div class="relatorio-resumo">
            <div class="resumo-card">
                <h3>Total de Demandas</h3>
                <div class="resumo-numero">${dados.estatisticas.totalTickets}</div>
            </div>
            <div class="resumo-card">
                <h3>Demandas Encerradas</h3>
                <div class="resumo-numero">${dados.estatisticas.ticketsEncerrados || 0}</div>
            </div>
            <div class="resumo-card">
                <h3>Tempo Médio Total</h3>
                <div class="resumo-numero">${formatarTempo(dados.estatisticas.tempoMedioSegundos)}</div>
            </div>
            <div class="resumo-card">
                <h3>Tempo Médio 1º Atendimento</h3>
                <div class="resumo-numero">${formatarTempo(dados.estatisticas.tempoMedioPrimeiroAtendimentoSegundos)}</div>
            </div>
        </div>

        <div class="relatorio-tabela">
            <table class="tickets-tabela">
                <caption class="sr-only">Relatório de demandas</caption>
                <thead>
                    <tr>
                        <th scope="col">Nº Demanda</th>
                        <th scope="col">Abertura</th>
                        <th scope="col">Assunto</th>
                        <th scope="col">Solicitante</th>
                        <th scope="col">Responsável</th>
                        <th scope="col">Subcategoria</th>
                        <th scope="col">Status</th>
                        <th scope="col">Prioridade</th>
                        <th scope="col">Fechamento</th>
                    </tr>
                </thead>
                <tbody>
    `;

    dados.tickets.forEach(ticket => {
        const dataAbertura = new Date(ticket.criado_em);
        const dataAberturaFormatada = dataAbertura.toLocaleDateString('pt-BR');
        const horaAberturaFormatada = dataAbertura.toLocaleTimeString('pt-BR');

        let dataFechamento = '-';
        if (ticket.fechado_em) {
            const dataFech = new Date(ticket.fechado_em);
            dataFechamento = dataFech.toLocaleDateString('pt-BR') + '<br>' + dataFech.toLocaleTimeString('pt-BR');
        }

        html += `
            <tr>
                <td>
                    #${String(ticket.id).padStart(5, '0')}
                    ${ticket.tem_anexos > 0 ? '<img src="images/clipe-de-anexo.png" class="icone-anexo" title="Demanda com anexos" alt="Anexo">' : ''}
                </td>
                <td>${dataAberturaFormatada}<br><small style="color: #666;">${horaAberturaFormatada}</small></td>
                <td>${sanitizeHTML(ticket.titulo)}</td>
                <td>${sanitizeHTML(ticket.usuario_nome || 'N/A')}</td>
                <td>${sanitizeHTML(ticket.agente_nome || 'Não atribuído')}</td>
                <td>${sanitizeHTML(ticket.categoria_nome || 'Sem subcategoria')}</td>
                <td>${ticket.status.charAt(0).toUpperCase() + ticket.status.slice(1)}</td>
                <td>${ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1)}</td>
                <td><small style=""color: #666;"">${dataFechamento}</small></td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    container.innerHTML = html;
}

// Exportar relatório
let dadosRelatorioAtual = null;

function exportarRelatorio(formato) {
    if (!dadosRelatorioAtual) {
        mostrarAlert('Erro', 'Gere um relatório antes de exportar.');
        return;
    }

    if (formato === 'excel') {
        exportarExcel();
    } else if (formato === 'pdf') {
        exportarPDF();
    }
}

function exportarExcel() {
    const dados = dadosRelatorioAtual;
    let csv = '\uFEFF'; // BOM UTF-8
    csv += 'Nº Demanda;Data Abertura;Hora Abertura;Assunto;Solicitante;Responsável;Subcategoria;Status;Prioridade;Data Fechamento;Hora Fechamento\n';

    dados.tickets.forEach(ticket => {
        const dataAbertura = new Date(ticket.criado_em);
        const dataAberturaFormatada = dataAbertura.toLocaleDateString('pt-BR');
        const horaAberturaFormatada = dataAbertura.toLocaleTimeString('pt-BR');

        let dataFechamento = '-';
        let horaFechamento = '-';
        if (ticket.fechado_em) {
            const dataFech = new Date(ticket.fechado_em);
            dataFechamento = dataFech.toLocaleDateString('pt-BR');
            horaFechamento = dataFech.toLocaleTimeString('pt-BR');
        }

        const numero = String(ticket.id).padStart(5, '0');
        const titulo = ticket.titulo.replace(/;/g, ',');
        const solicitante = (ticket.usuario_nome || 'N/A').replace(/;/g, ',');
        const responsavel = (ticket.agente_nome || 'Não atribuído').replace(/;/g, ',');
        const categoria = (ticket.categoria_nome || 'Sem subcategoria').replace(/;/g, ',');
        const status = ticket.status.charAt(0).toUpperCase() + ticket.status.slice(1);
        const prioridade = ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1);

        csv += `#${numero};${dataAberturaFormatada};${horaAberturaFormatada};${titulo};${solicitante};${responsavel};${categoria};${status};${prioridade};${dataFechamento};${horaFechamento}\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `relatorio_tickets_${new Date().toISOString().split('T')[0]}.csv`;
    link.click();

    mostrarToast('Sucesso', 'Relatório exportado para Excel!', 'success');
}

function exportarPDF() {
    const dados = dadosRelatorioAtual;

    const printWindow = window.open('', '', 'width=800,height=600');
    printWindow.document.write(`
        <html>
        <head>
            <title>Relatório de Demandas</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #f79226; text-align: center; }
                .resumo { display: flex; justify-content: space-around; margin: 20px 0; }
                .resumo-card { text-align: center; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
                .resumo-card h3 { margin: 0; font-size: 14px; color: #666; }
                .resumo-numero { font-size: 24px; font-weight: bold; color: #f79226; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
                th { background-color: #f79226; color: white; }
                tr:nth-child(even) { background-color: #f9f9f9; }
                @media print { body { margin: 0; } }
            </style>
        </head>
        <body>
            <h1>Relatório de Demandas - Conecta CRPRS</h1>
            <p style="text-align: center; color: #666;">Gerado em: ${new Date().toLocaleString('pt-BR')}</p>

            <div class="resumo">
                <div class="resumo-card">
                    <h3>Total de Demandas</h3>
                    <div class="resumo-numero">${dados.estatisticas.totalTickets}</div>
                </div>
                <div class="resumo-card">
                    <h3>Demandas Encerradas</h3>
                    <div class="resumo-numero">${dados.estatisticas.ticketsEncerrados || 0}</div>
                </div>
            </div>

            <table>
                <caption class="sr-only">Relatório de demandas para impressão</caption>
                <thead>
                    <tr>
                        <th scope="col">Nº</th>
                        <th scope="col">Abertura</th>
                        <th scope="col">Assunto</th>
                        <th scope="col">Solicitante</th>
                        <th scope="col">Responsável</th>
                        <th scope="col">Subcategoria</th>
                        <th scope="col">Status</th>
                        <th scope="col">Prioridade</th>
                        <th scope="col">Fechamento</th>
                    </tr>
                </thead>
                <tbody>
    `);

    dados.tickets.forEach(ticket => {
        const dataAbertura = new Date(ticket.criado_em);
        const dataAberturaFormatada = dataAbertura.toLocaleDateString('pt-BR');
        const horaAberturaFormatada = dataAbertura.toLocaleTimeString('pt-BR');

        let dataFechamento = '-';
        if (ticket.fechado_em) {
            const dataFech = new Date(ticket.fechado_em);
            dataFechamento = dataFech.toLocaleDateString('pt-BR') + '<br>' + dataFech.toLocaleTimeString('pt-BR');
        }

        const numero = String(ticket.id).padStart(5, '0');
        printWindow.document.write(`
            <tr>
                <td>#${numero}</td>
                <td>${dataAberturaFormatada}<br><small>${horaAberturaFormatada}</small></td>
                <td>${sanitizeHTML(ticket.titulo)}</td>
                <td>${sanitizeHTML(ticket.usuario_nome || 'N/A')}</td>
                <td>${sanitizeHTML(ticket.agente_nome || 'Não atribuído')}</td>
                <td>${sanitizeHTML(ticket.categoria_nome || 'Sem subcategoria')}</td>
                <td>${ticket.status.charAt(0).toUpperCase() + ticket.status.slice(1)}</td>
                <td>${ticket.prioridade.charAt(0).toUpperCase() + ticket.prioridade.slice(1)}</td>
                <td><small style=""color: #666;"">${dataFechamento}</small></td>
            </tr>
        `);
    });

    printWindow.document.write(`
                </tbody>
            </table>
        </body>
        </html>
    `);

    printWindow.document.close();
    setTimeout(() => {
        printWindow.print();
    }, 250);

    mostrarToast('Sucesso', 'Relatório preparado para impressão/PDF!', 'success');
}
