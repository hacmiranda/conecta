// Funções para gerenciar horários úteis e feriados (expediente e feriados são de cada setor)

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

// Expediente e feriados do setor (do usuário, ou o escolhido pelo admin no cabeçalho), juntos
function carregarHorariosSLA() {
    Promise.all([
        Api.get(urlComSetor('horarios-sla.php')),
        Api.get(urlComSetor('horarios-sla.php?feriados=1')),
    ]).then(([horarios, feriados]) => mostrarHorariosEFeriados(horarios || [], feriados || []))
      .catch(e => {
          document.getElementById('config-content').innerHTML =
              `<p role="alert">${sanitizeHTML(mensagemDeErro(e, 'Erro ao carregar horários e feriados.'))}</p>`;
      });
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
            <caption class="sr-only">Horários úteis de atendimento do setor</caption>
            <thead>
                <tr>
                    <th scope="col">Dia da Semana</th>
                    <th scope="col">Hora Início</th>
                    <th scope="col">Hora Fim</th>
                    <th scope="col">Ativo</th>
                    <th scope="col">Ações</th>
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
        
        <div id="form-feriado" style="display: none; background: #f7f4f0; padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
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
            <caption class="sr-only">Feriados cadastrados para o setor</caption>
            <thead>
                <tr>
                    <th scope="col">Data</th>
                    <th scope="col">Descrição</th>
                    <th scope="col">Recorrente</th>
                    <th scope="col">Ações</th>
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

    Api.put(urlComSetor('horarios-sla.php'), { dia_semana: dia, hora_inicio: inicio, hora_fim: fim, ativo: ativo })
        .then(() => mostrarToast('Sucesso', 'Horário atualizado com sucesso!', 'success'))
        .catch(e => mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao atualizar horário')));
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

    const campoTodos = document.getElementById('todos-setores-feriado');
    Api.post(urlComSetor('horarios-sla.php'), {
        data: data,
        descricao: descricao,
        recorrente: recorrente,
        todos_setores: campoTodos ? campoTodos.checked : false
    }).then(() => {
        mostrarToast('Sucesso', 'Feriado cadastrado com sucesso!', 'success');
        cancelarFormFeriado();
        mostrarHorariosSLA();
    }).catch(e => mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao cadastrar feriado')));
}

function excluirFeriado(id) {
    mostrarConfirm(
        'Excluir Feriado',
        'Tem certeza que deseja excluir este feriado?',
        function (confirmado) {
            if (!confirmado) return;
            Api.delete(urlComSetor(`horarios-sla.php?id=${encodeURIComponent(id)}`)).then(() => {
                mostrarToast('Sucesso', 'Feriado excluído com sucesso!', 'success');
                mostrarHorariosSLA();
            }).catch(e => mostrarAlert('Erro', mensagemDeErro(e, 'Erro ao excluir feriado')));
        }
    );
}
