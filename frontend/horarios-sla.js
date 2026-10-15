// Funções para gerenciar horários úteis e feriados (expediente e feriados são de cada setor)

// Extrai a mensagem de erro enviada pelo servidor (ou usa o texto padrão)
function mensagemErroServidor(xhr, padrao) {
    try {
        return JSON.parse(xhr.responseText).error || padrao;
    } catch (e) {
        return padrao;
    }
}

// Nome do setor cujo expediente está sendo exibido (próprio setor ou o escolhido pelo admin)
function nomeSetorDoExpediente() {
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (usuario.tipo !== 'admin') return usuario.setor_nome || '';
    const seletor = document.getElementById('seletor-setor');
    if (seletor && seletor.value) return seletor.options[seletor.selectedIndex].textContent;
    return 'Geral (nenhum setor escolhido no cabeçalho)';
}

function mostrarHorariosSLA() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active'));
    const _bhor = document.getElementById('btn-horarios-sla'); if(_bhor) _bhor.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'horarios-sla');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x23F0) + ' Hor\u00e1rios e Feriados';
    
    const content = document.getElementById('config-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        carregarHorariosSLA();
    }, 10);
}

function carregarHorariosSLA() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'horarios-sla.php'), true);
    setSecurityHeaders(xhr);
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            const horarios = JSON.parse(xhr.responseText);
            carregarFeriados(horarios);
        } else {
            document.getElementById('config-content').innerHTML = '<p>Erro ao carregar horários.</p>';
        }
    };
    
    xhr.send();
}

function carregarFeriados(horarios) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', urlComSetor(window.APP_CONFIG.apiBase + 'horarios-sla.php?feriados=1'), true);
    setSecurityHeaders(xhr);
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            const feriados = JSON.parse(xhr.responseText);
            mostrarHorariosEFeriados(horarios, feriados);
        } else {
            document.getElementById('config-content').innerHTML = '<p>Erro ao carregar feriados.</p>';
        }
    };
    
    xhr.send();
}

function mostrarHorariosEFeriados(horarios, feriados) {
    const content = document.getElementById('config-content');
    
    const diasSemana = {
        1: 'Domingo',
        2: 'Segunda-feira',
        3: 'Terça-feira',
        4: 'Quarta-feira',
        5: 'Quinta-feira',
        6: 'Sexta-feira',
        7: 'Sábado'
    };
    
    let horariosHtml = '';
    for (let dia = 1; dia <= 7; dia++) {
        const horario = horarios.find(h => h.dia_semana == dia);
        const ativo = horario ? horario.ativo == 1 : false;
        const inicio = horario ? horario.hora_inicio : '08:00:00';
        const fim = horario ? horario.hora_fim : '17:00:00';
        
        horariosHtml += `
            <tr>
                <td>${diasSemana[dia]}</td>
                <td><input type="time" id="inicio-${dia}" value="${inicio.substring(0,5)}" ${!ativo ? 'disabled' : ''}></td>
                <td><input type="time" id="fim-${dia}" value="${fim.substring(0,5)}" ${!ativo ? 'disabled' : ''}></td>
                <td>
                    <label class="switch">
                        <input type="checkbox" id="ativo-${dia}" ${ativo ? 'checked' : ''} onchange="toggleDia(${dia})">
                        <span class="slider"></span>
                    </label>
                </td>
                <td><button class="btn-primary" onclick="salvarHorario(${dia})">Salvar</button></td>
            </tr>
        `;
    }
    
    let feriadosHtml = '';
    feriados.forEach(feriado => {
        const dataFormatada = new Date(feriado.data + 'T00:00:00').toLocaleDateString('pt-BR');
        feriadosHtml += `
            <tr>
                <td>${dataFormatada}</td>
                <td>${sanitizeHTML(feriado.descricao)}</td>
                <td>${feriado.recorrente ? 'Sim' : 'Não'}</td>
                <td><button class="btn-excluir" onclick="excluirFeriado(${feriado.id})">Excluir</button></td>
            </tr>
        `;
    });
    
    content.innerHTML = `
        <h3 style="margin-bottom: 0.4rem;">⏰ Horários Úteis de Atendimento</h3>
        <p style="margin-top: 0; margin-bottom: 1rem; color: #666;">Setor: <strong>${nomeSetorDoExpediente()}</strong>. Configure os horários em que o sistema deve considerar como horas úteis para cálculo de SLA deste setor.</p>
        
        <table class="tickets-tabela" style="margin-bottom: 2.5rem;">
            <thead>
                <tr>
                    <th>Dia da Semana</th>
                    <th>Hora Início</th>
                    <th>Hora Fim</th>
                    <th>Ativo</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                ${horariosHtml}
            </tbody>
        </table>
        
        <h3 style="margin-bottom: 0.4rem;">📅 Calendário de Feriados</h3>
        <p style="margin-top: 0; margin-bottom: 1rem; color: #666;">Cadastre os feriados em que não haverá expediente. Estes dias não serão contabilizados no cálculo de SLA.</p>
        
        <div style="margin-bottom: 1.25rem;">
            <button class="btn-primary" onclick="mostrarFormFeriado()">➕ Adicionar Feriado</button>
        </div>
        
        <div id="form-feriado" style="display: none; background: #f8f9fa; padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
            <h4 style="margin-top: 0; margin-bottom: 1rem;">Novo Feriado</h4>
            <div class="form-group">
                <label>Data:</label>
                <input type="date" id="data-feriado" required>
            </div>
            <div class="form-group">
                <label>Descrição:</label>
                <input type="text" id="descricao-feriado" placeholder="Ex: Natal" required>
            </div>
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" id="recorrente-feriado">
                        <span class="slider"></span>
                    </label>
                    <span>Repetir todo ano (feriado recorrente)</span>
                </label>
            </div>
            ${(JSON.parse(localStorage.getItem('cd_usuario') || '{}').tipo === 'admin') ? `
            <div class="form-group">
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" id="todos-setores-feriado">
                        <span class="slider"></span>
                    </label>
                    <span>Aplicar a todos os setores (ex.: feriado nacional)</span>
                </label>
            </div>` : ''}
            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                <button class="btn-primary" onclick="salvarFeriado()">Salvar</button>
                <button class="btn-secondary" onclick="cancelarFormFeriado()">Cancelar</button>
            </div>
        </div>
        
        <table class="tickets-tabela">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Recorrente</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                ${feriadosHtml || '<tr><td colspan="4" style="text-align: center; color: #999;">Nenhum feriado cadastrado</td></tr>'}
            </tbody>
        </table>
    `;
}

function toggleDia(dia) {
    const ativo = document.getElementById(`ativo-${dia}`).checked;
    document.getElementById(`inicio-${dia}`).disabled = !ativo;
    document.getElementById(`fim-${dia}`).disabled = !ativo;
}

function salvarHorario(dia) {
    const ativo = document.getElementById(`ativo-${dia}`).checked;
    const inicio = document.getElementById(`inicio-${dia}`).value + ':00';
    const fim = document.getElementById(`fim-${dia}`).value + ':00';
    
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', urlComSetor(window.APP_CONFIG.apiBase + 'horarios-sla.php'), true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Horário atualizado com sucesso!', 'success');
        } else {
            mostrarAlert('Erro', mensagemErroServidor(xhr, 'Erro ao atualizar horário'));
        }
    };
    
    xhr.send(JSON.stringify({
        dia_semana: dia,
        hora_inicio: inicio,
        hora_fim: fim,
        ativo: ativo
    }));
}

function mostrarFormFeriado() {
    document.getElementById('form-feriado').style.display = 'block';
}

function cancelarFormFeriado() {
    document.getElementById('form-feriado').style.display = 'none';
    document.getElementById('data-feriado').value = '';
    document.getElementById('descricao-feriado').value = '';
    document.getElementById('recorrente-feriado').checked = false;
    const campoTodosLimpar = document.getElementById('todos-setores-feriado');
    if (campoTodosLimpar) campoTodosLimpar.checked = false;
}

function salvarFeriado() {
    const data = document.getElementById('data-feriado').value;
    const descricao = document.getElementById('descricao-feriado').value;
    const recorrente = document.getElementById('recorrente-feriado').checked;
    
    if (!data || !descricao) {
        mostrarAlert('Erro', 'Preencha todos os campos obrigatórios');
        return;
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', urlComSetor(window.APP_CONFIG.apiBase + 'horarios-sla.php'), true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Feriado cadastrado com sucesso!', 'success');
            cancelarFormFeriado();
            mostrarHorariosSLA();
        } else {
            mostrarAlert('Erro', mensagemErroServidor(xhr, 'Erro ao cadastrar feriado'));
        }
    };
    
    const campoTodos = document.getElementById('todos-setores-feriado');

    xhr.send(JSON.stringify({
        data: data,
        descricao: descricao,
        recorrente: recorrente,
        todos_setores: campoTodos ? campoTodos.checked : false
    }));
}

function excluirFeriado(id) {
    mostrarConfirm(
        'Excluir Feriado',
        'Tem certeza que deseja excluir este feriado?',
        function(confirmado) {
            if (!confirmado) return;
            
            const xhr = new XMLHttpRequest();
            xhr.open('DELETE', urlComSetor(`${window.APP_CONFIG.apiBase}horarios-sla.php?id=${id}`), true);
            setSecurityHeaders(xhr);
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    mostrarToast('Sucesso', 'Feriado excluído com sucesso!', 'success');
                    mostrarHorariosSLA();
                } else {
                    mostrarAlert('Erro', mensagemErroServidor(xhr, 'Erro ao excluir feriado'));
                }
            };
            
            xhr.send();
        }
    );
}
