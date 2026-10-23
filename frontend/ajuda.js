/**
 * Ajuda/Guias (SLA, Status das Demandas, Prioridades, Atalhos de Teclado, Tutorial, Dicas do
 * Sistema). "Fluxos de E-mail" (outro item do mesmo submenu) já é um arquivo separado
 * pré-existente (fluxos-email-func.js), fora desta divisão. Extraído de script.js na
 * Fase 12 (onda 7 da divisão por domínio).
 *
 * mostrarTutorialAjuda() é código morto: tutorial.js (carregado depois deste arquivo no
 * index.html) define sua própria versão da função, que sobrescreve esta. Mantida aqui, sem
 * remover, pela regra do projeto de nunca remover função existente sem perguntar antes.
 */

// Seção de Ajuda
function mostrarAjuda() {
    mostrarSecao('ajuda');
    // Cada item do submenu é responsável por chamar sua própria subsecção
}

function mostrarSLA() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_sla = document.getElementById('btn-sla'); if (_bbtn_sla) _bbtn_sla.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'sla');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        carregarHorariosDinamicos();
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>⏰ SLA - Acordo de Nível de Serviço</h3>
            <p>O SLA (Service Level Agreement) define os <strong>tempos máximos</strong> para resposta e resolução de demandas baseado na prioridade. Os prazos são contados em <strong>horas úteis</strong> (Segunda a Sexta, 8h às 17h).</p>

            <h4>📊 Como Funciona o SLA</h4>
            <p>O sistema monitora automaticamente dois tipos de SLA para cada demanda:</p>
            <ul>
                <li><strong>SLA de 1ª Resposta:</strong> Tempo máximo até o primeiro atendimento da equipe</li>
                <li><strong>SLA de Resolução:</strong> Tempo máximo até a resolução completa do problema</li>
            </ul>

            <h4>🚦 Indicadores Visuais</h4>
            <p>As demandas exibem ícones de alerta conforme o prazo se aproxima:</p>
            <ul>
                <li><strong>🟢 0-50% do prazo:</strong> Sem ícone - Tudo ok</li>
                <li><strong>🟡 50-79% do prazo:</strong> <img src="images/icone_atencao_amarelo.png" style="width: 16px; height: 16px; vertical-align: middle;"> Amarelo - Atenção, prazo se aproximando</li>
                <li><strong>🔴 80-100%+ do prazo:</strong> <img src="images/icone_atencao_vermelho.png" style="width: 16px; height: 16px; vertical-align: middle;"> Vermelho - Urgente ou vencido</li>
            </ul>

            <h4>📋 Tabela de Prazos por Prioridade</h4>
            <table class="sla-table">
                <caption class="sr-only">Prazos de SLA por prioridade</caption>
                <thead>
                    <tr>
                        <th scope="col">Prioridade</th>
                        <th scope="col">1ª Resposta</th>
                        <th scope="col">Resolução</th>
                        <th scope="col">Exemplos</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="status prioridade urgente">Urgente</span></td>
                        <td><strong>2 horas úteis</strong></td>
                        <td><strong>4 horas úteis</strong></td>
                        <td>Sistema fora do ar, falha crítica que impede o trabalho</td>
                    </tr>
                    <tr>
                        <td><span class="status prioridade alta">Alta</span></td>
                        <td><strong>4 horas úteis</strong></td>
                        <td><strong>8 horas úteis</strong></td>
                        <td>Funcionalidade importante indisponível que afeta a produtividade</td>
                    </tr>
                    <tr>
                        <td><span class="status prioridade normal">Normal</span></td>
                        <td><strong>12 horas úteis</strong></td>
                        <td><strong>24 horas úteis</strong></td>
                        <td>Dúvidas, solicitações rotineiras</td>
                    </tr>
                    <tr>
                        <td><span class="status prioridade baixa">Baixa</span></td>
                        <td><strong>24 horas úteis</strong></td>
                        <td><strong>40 horas úteis</strong></td>
                        <td>Melhorias, sugestões não urgentes</td>
                    </tr>
                </tbody>
            </table>

            <h4>🕰️ Horário de Atendimento</h4>
            <div id="horarios-atendimento-info" style="background: #fbeceb; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <p style="margin: 0; color: #a7241c;">⚙️ <strong>Configurável:</strong> Os horários de atendimento podem ser personalizados em <strong>Configurações → Horários e Feriados</strong></p>
            </div>
            <div id="horarios-dinamicos">Carregando horários...</div>

            <h4>⏸️ Pausa Automática do SLA</h4>
            <p>O SLA é <strong>pausado automaticamente</strong> quando a demanda está nos seguintes status:</p>
            <ul>
                <li>⏳ <strong>Aguardando:</strong> Em espera por motivo não especificado</li>
                <li>⏳ <strong>Aguardando resposta:</strong> Aguardando informações do solicitante</li>
                <li>🔄 <strong>Aguardando terceiros:</strong> Depende de fornecedor ou outro setor</li>
                <li>📋 <strong>Aguardando aprovação:</strong> Precisa de autorização do cliente ou gestor</li>
                <li>📅 <strong>Aguardando data:</strong> Aguardando data agendada para retomar o atendimento</li>
                <li>✅ <strong>Resolvido:</strong> Aguardando confirmação do cliente para encerrar</li>
            </ul>
            <p><em>Quando a demanda retorna ao atendimento, o SLA é retomado automaticamente, descontando o tempo pausado.</em></p>

            <h4>📊 Contabilização de Demandas</h4>
            <ul>
                <li>✅ <strong>Demandas Encerradas:</strong> Contabilizadas nas estatísticas e relatórios</li>
                <li>❌ <strong>Demandas Canceladas:</strong> NÃO são contabilizadas nas métricas de desempenho</li>
            </ul>
            <p><em>Demandas canceladas são aquelas abertas por engano ou cuja solicitação foi retirada pelo solicitante.</em></p>

            <h4>💡 Dicas para Cumprir o SLA</h4>
            <ul>
                <li>Atribua a prioridade correta ao criar a demanda</li>
                <li>Responda rapidamente às demandas urgentes e de alta prioridade</li>
                <li>Use os status de "aguardando" quando depender de terceiros para pausar o SLA</li>
                <li>Mantenha o solicitante informado sobre o andamento</li>
                <li>Documente as soluções para consultas futuras</li>
                <li>Cancele apenas demandas abertas por engano (não contam nas estatísticas)</li>
            </ul>
        </div>
    `;
    }, 10);
}

async function carregarHorariosDinamicos() {
    try {
        const horarios = await Api.get('horarios-sla.php');
        const feriados = await Api.get('horarios-sla.php?feriados=1');
        mostrarHorariosDinamicos(horarios, feriados);
    } catch (e) {
        // Mesmo comportamento de antes: silencioso em caso de erro (fica em "Carregando horários...")
    }
}

function mostrarHorariosDinamicos(horarios, feriados) {
    const container = document.getElementById('horarios-dinamicos');
    if (!container) return;

    const diasSemana = {
        1: 'Domingo',
        2: 'Segunda-feira',
        3: 'Terça-feira',
        4: 'Quarta-feira',
        5: 'Quinta-feira',
        6: 'Sexta-feira',
        7: 'Sábado'
    };

    let html = '<ul>';

    for (let dia = 1; dia <= 7; dia++) {
        const horario = horarios.find(h => h.dia_semana == dia);
        if (horario && horario.ativo == 1) {
            const inicio = horario.hora_inicio.substring(0, 5);
            const fim = horario.hora_fim.substring(0, 5);
            html += `<li><strong>${diasSemana[dia]}:</strong> ${inicio} às ${fim}</li>`;
        } else {
            html += `<li><strong>${diasSemana[dia]}:</strong> Sem atendimento</li>`;
        }
    }

    if (feriados.length > 0) {
        html += '<li style="margin-top: 0.5rem;"><strong>Feriados cadastrados:</strong> ' + feriados.length + ' feriado(s)</li>';
    }

    html += '</ul>';
    container.innerHTML = html;
}

function mostrarStatusGuia() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_status = document.getElementById('btn-status'); if (_bbtn_status) _bbtn_status.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'status');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>📊 Status das Demandas</h3>
            <p>Cada demanda passa por diferentes status durante seu ciclo de vida:</p>

            <div class="status-item">
                <span class="status-badge" style="background:#fff3e0;color:#e65100;">🆕 Novo</span>
                <div>
                    <strong>Demanda recém-criada</strong><br>
                    A demanda foi criada (via sistema ou e-mail), mas ainda não foi atribuída nem visualizada pela equipe.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fbeceb;color:#a7241c;">🔧 Em atendimento</span>
                <div>
                    <strong>Responsável trabalhando ativamente</strong><br>
                    A demanda está sendo analisada ou trabalhada pelo responsável.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fff8e1;color:#f57f17;">⏳ Aguardando</span>
                <div>
                    <strong>Em espera</strong><br>
                    A demanda está em espera por algum motivo não especificado.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fff8e1;color:#f57f17;">⏳ Aguardando resposta</span>
                <div>
                    <strong>Aguardando informações do solicitante</strong><br>
                    O responsável precisa de mais informações do solicitante para continuar.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fbeceb;color:#8b1d17;">🔄 Aguardando terceiros</span>
                <div>
                    <strong>Depende de terceiros</strong><br>
                    Quando depende de outro setor ou fornecedor externo para resolução.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fff3e0;color:#bf360c;">📋 Aguardando aprovação</span>
                <div>
                    <strong>Precisa de autorização</strong><br>
                    Necessário para situações em que uma solução ou ação precisa de aprovação do cliente ou gestor.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fbeceb;color:#00695c;">📅 Aguardando data</span>
                <div>
                    <strong>Agendado para data futura</strong><br>
                    O atendimento está pausado até uma data/hora específica agendada para retomar.
                    <br><em style="color:#e6a817;">⏸️ SLA pausado neste status</em>
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fbeceb;color:#a7241c;">💬 Nova Resposta</span>
                <div>
                    <strong>Solicitante respondeu</strong><br>
                    O solicitante enviou uma nova resposta ou comentário. A demanda aguarda retomada do atendimento pelo responsável.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#e8f5e9;color:#2e7d32;">✅ Resolvido</span>
                <div>
                    <strong>Solução aplicada</strong><br>
                    A solução foi aplicada, mas aguarda a confirmação do usuário/cliente para encerrar.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#f5f5f5;color:#424242;">🏁 Encerrado</span>
                <div>
                    <strong>Demanda finalizada</strong><br>
                    O problema foi solucionado e o atendimento foi oficialmente concluído.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#ffebee;color:#c62828;">❌ Cancelado</span>
                <div>
                    <strong>Demanda cancelada</strong><br>
                    A demanda foi aberta por engano ou a solicitação foi retirada pelo solicitante. <strong>Não é contabilizada</strong> nas métricas de desempenho.
                </div>
            </div>

            <div class="status-item">
                <span class="status-badge" style="background:#fce4ec;color:#880e4f;">🔄 Reaberto</span>
                <div>
                    <strong>Demanda reaberta</strong><br>
                    Quando uma demanda já encerrada ou resolvida volta a apresentar problemas e precisa ser tratada novamente.
                </div>
            </div>

            <h4>🔄 Transições de Status</h4>
            <p><strong>Fluxo normal:</strong> Novo → Em atendimento → Resolvido → Encerrado</p>
            <p><strong>Com interação:</strong> Novo → Em atendimento → Aguardando resposta → Nova Resposta → Em atendimento → Encerrado</p>
            <p><strong>Com terceiros:</strong> Novo → Em atendimento → Aguardando terceiros → Em atendimento → Resolvido → Encerrado</p>
            <p><strong>Com aprovação:</strong> Novo → Em atendimento → Aguardando aprovação → Resolvido → Encerrado</p>
            <p><strong>Com data agendada:</strong> Novo → Em atendimento → Aguardando data → Em atendimento → Resolvido → Encerrado</p>
            <p><strong>Reabertura:</strong> Encerrado → Reaberto → Em atendimento</p>
        </div>
    `;
    }, 10);
}

function mostrarPrioridadeGuia() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_prioridade = document.getElementById('btn-prioridade'); if (_bbtn_prioridade) _bbtn_prioridade.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'prioridade');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>🔥 Prioridades das Demandas</h3>
            <p>A prioridade determina a urgência e o SLA da demanda:</p>

            <div class="prioridade-item">
                <span class="status prioridade urgente">Urgente</span>
                <div>
                    <strong>Impacto crítico no negócio</strong><br>
                    Sistema principal fora do ar, falha de segurança, perda de dados, falha crítica que impede o trabalho.
                    <br><em>SLA: 2 horas úteis resposta / 4 horas úteis resolução</em>
                </div>
            </div>

            <div class="prioridade-item">
                <span class="status prioridade alta">Alta</span>
                <div>
                    <strong>Impacto significativo</strong><br>
                    Funcionalidade importante indisponível, erro que afeta vários usuários, afeta a produtividade.
                    <br><em>SLA: 4 horas úteis resposta / 8 horas úteis resolução</em>
                </div>
            </div>

            <div class="prioridade-item">
                <span class="status prioridade normal">Normal</span>
                <div>
                    <strong>Demanda rotineira</strong><br>
                    Dúvidas, solicitações de suporte, problemas menores, solicitações rotineiras.
                    <br><em>SLA: 12 horas úteis resposta / 24 horas úteis resolução</em>
                </div>
            </div>

            <div class="prioridade-item">
                <span class="status prioridade baixa">Baixa</span>
                <div>
                    <strong>Melhorias e sugestões</strong><br>
                    Solicitações de melhoria, sugestões não urgentes, documentação.
                    <br><em>SLA: 24 horas úteis resposta / 40 horas úteis resolução</em>
                </div>
            </div>

            <h4>🎯 Como Definir a Prioridade</h4>
            <ul>
                <li><strong>Impacto:</strong> Quantos usuários são afetados?</li>
                <li><strong>Urgência:</strong> Quão rápido precisa ser resolvido?</li>
                <li><strong>Criticidade:</strong> Quão importante é para o negócio?</li>
            </ul>
        </div>
    `;
    }, 10);
}

function mostrarAtalhosGuia() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_atalhos = document.getElementById('btn-atalhos'); if (_bbtn_atalhos) _bbtn_atalhos.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'atalhos');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card">
            <h3>⌨️ Atalhos de Teclado</h3>
            <p>Use estes atalhos para navegar mais rapidamente pelo sistema:</p>

            <div class="atalho-item">
                <span>Criar nova demanda</span>
                <span class="atalho-tecla">Alt + N</span>
            </div>

            <div class="atalho-item">
                <span>Ir para lista de demandas</span>
                <span class="atalho-tecla">Alt + T</span>
            </div>

            <div class="atalho-item">
                <span>Abrir dashboard</span>
                <span class="atalho-tecla">Alt + D</span>
            </div>

            <div class="atalho-item">
                <span>Ver demandas fechadas</span>
                <span class="atalho-tecla">Alt + F</span>
            </div>

            <div class="atalho-item">
                <span>Fechar modals/janelas</span>
                <span class="atalho-tecla">ESC</span>
            </div>

            <h4>📝 Dicas de Produtividade</h4>
            <ul>
                <li>Use <strong>Tab</strong> para navegar entre campos nos formulários</li>
                <li>Pressione <strong>Enter</strong> para confirmar ações em modals</li>
                <li>Use as <strong>setas</strong> para navegar nas listas de subcategorias</li>
                <li>Digite <strong>*palavra*</strong> para preservar formatação em subcategorias</li>
            </ul>
        </div>
    `;
    }, 10);
}

// Dica de atalhos
function mostrarDicaAtalhos() {
    const dica = document.createElement('div');
    dica.className = 'atalhos-info';
    dica.innerHTML = 'Alt+N: Novo | Alt+T: Demandas | Alt+D: Dashboard | Alt+F: Fechados';
    document.body.appendChild(dica);

    setTimeout(() => dica.classList.add('show'), 1000);
    setTimeout(() => dica.classList.remove('show'), 5000);
}

// Mostrar modal de dicas
function mostrarDicas() {
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-dicas').classList.add('show');

    // Verificar se deve mostrar checkbox
    const checkbox = document.getElementById('nao-mostrar-dicas');
    const naoMostrar = localStorage.getItem('cd_naoMostrarDicas') === 'true';
    checkbox.checked = naoMostrar;
}

// Fechar modal de dicas
function fecharModalDicas() {
    document.getElementById('modal-overlay').classList.remove('show');
    document.getElementById('modal-dicas').classList.remove('show');
}

// Toggle para não mostrar dicas na inicialização
function toggleDicasIniciais() {
    const checkbox = document.getElementById('nao-mostrar-dicas');
    localStorage.setItem('cd_naoMostrarDicas', checkbox.checked);
}

// Mostrar dicas automaticamente na primeira visita
function verificarPrimeiraVisita() {
    const naoMostrar = localStorage.getItem('cd_naoMostrarDicas') === 'true';
    const jaVisitou = localStorage.getItem('cd_jaVisitou') === 'true';

    if (!naoMostrar && !jaVisitou) {
        setTimeout(() => {
            mostrarDicas();
            localStorage.setItem('cd_jaVisitou', 'true');
        }, 2000); // Mostrar após 2 segundos
    }
}

// === TUTORIAL INTERATIVO ===
function mostrarTutorialAjuda() { reiniciarTutorial(); }

// Função iniciarTutorial está em tutorial.js

// === DICAS DO SISTEMA ===
function mostrarDicasAjuda() { mostrarDicas(); }
