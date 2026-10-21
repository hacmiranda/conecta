/**
 * Ativação por Setor (status/prioridades ativos por setor) e E-mail por Setor (caixa
 * IMAP/SMTP do setor + tela "Configurar Email"). Extraído de script.js na Fase 12
 * (onda 4 da divisão por domínio). Os dois domínios compartilham `setorConfigAtual`/
 * `abaConfigSetorAtiva` e se chamam entre si, por isso ficam no mesmo arquivo.
 */

let setorConfigAtual = null;
let abaConfigSetorAtiva = 'status';

async function mostrarConfigSetor() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active'));

    localStorage.setItem('cd_subSecaoAtiva', 'setor-config');
    const configTitle = document.getElementById('config-title');
    if (configTitle) configTitle.textContent = '🧭 Ativação por Setor';

    const content = document.getElementById('config-content');
    if (!content) {
        setTimeout(() => mostrarConfigSetor(), 200);
        return;
    }

    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');

    if (usuario.tipo === 'admin') {
        try {
            const setores = await Api.get('setores.php');
            renderizarPainelConfigSetor(setores, setores[0] ? setores[0].id : null);
        } catch (e) {
            content.innerHTML = '<p>Erro ao carregar setores.</p>';
        }
    } else {
        renderizarPainelConfigSetor([], usuario.setor_id);
    }
}

function renderizarPainelConfigSetor(setores, setorInicialId) {
    const content = document.getElementById('config-content');
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const ehAdmin = usuario.tipo === 'admin';

    setorConfigAtual = setorInicialId;
    abaConfigSetorAtiva = 'status';

    const seletorHtml = ehAdmin ? `
        <div class="form-group" style="max-width: 320px;">
            <label for="setor-config-select">Setor:</label>
            <select id="setor-config-select" onchange="setorConfigAtual = parseInt(this.value); carregarAbaConfigSetor('status')">
                ${setores.map(s => `<option value="${s.id}" ${s.id == setorInicialId ? 'selected' : ''}>${sanitizeHTML(s.nome)}</option>`).join('')}
            </select>
        </div>
    ` : '';

    content.innerHTML = `
        <p style="color:#666;margin-bottom:1rem;">Ative/desative e reordene quais status e prioridades aparecem para este setor. O catálogo continua compartilhado com os outros setores — nada é apagado aqui, só escondido. As categorias são próprias de cada setor e ficam em Configurações → Categorias.</p>
        ${seletorHtml}
        <div class="tabs-container" style="margin-bottom:1rem;">
            <button type="button" class="tab-btn active" id="tab-config-status" onclick="carregarAbaConfigSetor('status')">🚦 Status</button>
            <button type="button" class="tab-btn" id="tab-config-prioridades" onclick="carregarAbaConfigSetor('prioridades')">🔥 Prioridades</button>
        </div>
        <div id="config-setor-lista"></div>
    `;

    if (!setorConfigAtual) {
        document.getElementById('config-setor-lista').innerHTML = '<p style="color:#999;font-style:italic;">Nenhum setor disponível.</p>';
        return;
    }

    carregarAbaConfigSetor('status');
}

async function carregarAbaConfigSetor(tipo) {
    abaConfigSetorAtiva = tipo;
    document.querySelectorAll('.tabs-container .tab-btn').forEach(b => b.classList.remove('active'));
    const btnAtivo = document.getElementById('tab-config-' + tipo);
    if (btnAtivo) btnAtivo.classList.add('active');

    if (tipo === 'emails') {
        carregarEmailsSetor();
        return;
    }

    const lista = document.getElementById('config-setor-lista');
    if (!lista || !setorConfigAtual) return;
    lista.innerHTML = '<p>Carregando...</p>';

    try {
        const itens = await Api.get(`setor-config.php?tipo=${tipo}&setor_id=${setorConfigAtual}`);
        renderizarListaConfigSetor(itens, tipo);
    } catch (e) {
        lista.innerHTML = '<p>Erro ao carregar configuração.</p>';
    }
}

// ==== Fase 4: E-mails por Setor ====

const LIMITE_EMAILS_POR_SETOR = 1;

async function carregarEmailsSetor() {
    const lista = document.getElementById('config-setor-lista');
    if (!lista || !setorConfigAtual) return;
    lista.innerHTML = '<p>Carregando...</p>';

    try {
        const caixas = await Api.get(`setor-emails.php?setor_id=${setorConfigAtual}`);
        renderizarEmailsSetor(caixas);
    } catch (e) {
        lista.innerHTML = '<p>Erro ao carregar e-mails do setor.</p>';
    }
}

function renderizarEmailsSetor(caixas) {
    const lista = document.getElementById('config-setor-lista');

    const cardsHtml = caixas.map(caixa => `
        <div class="config-setor-item ${caixa.ativo ? '' : 'config-setor-item-inativo'}" style="flex-direction:column;align-items:flex-start;gap:0.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;width:100%;">
                <div>
                    <strong>${sanitizeHTML(caixa.email)}</strong>
                    <div style="font-size:0.85rem;color:#666;">Recebimento (IMAP): ${sanitizeHTML(caixa.imap_host)}:${parseInt(caixa.imap_port, 10)} · usuário: ${sanitizeHTML(caixa.imap_user)}</div>
                    <div style="font-size:0.85rem;color:#666;">Envio (SMTP): ${caixa.smtp_host ? sanitizeHTML(caixa.smtp_host) + ':' + parseInt(caixa.smtp_port, 10) + ' (' + (caixa.smtp_seguranca === 'tls' ? 'STARTTLS' : 'SSL') + ')' : 'servidor global do sistema'} · remetente: ${sanitizeHTML(caixa.nome_remetente || 'Conecta CRPRS - (nome do setor)')}</div>
                    <div class="config-certificado">
                        <label>
                            <input type="checkbox" ${parseInt(caixa.validar_certificado, 10) === 1 ? 'checked' : ''} onchange="alternarCertificadoEmailSetor(${parseInt(caixa.id, 10)}, this.checked)">
                            Validar o certificado do servidor de e-mail <span style="color:#666;">(recomendado; desligue só se o servidor usar certificado inválido)</span>
                        </label>
                    </div>
                </div>
                <label class="switch-label">
                    <label class="switch">
                        <input type="checkbox" ${caixa.ativo ? 'checked' : ''} onchange="alternarAtivoEmailSetor(${caixa.id}, this.checked)">
                        <span class="slider"></span>
                    </label>
                </label>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="button" class="btn-small" onclick="excluirEmailSetor(${caixa.id})">🗑️ Excluir</button>
            </div>
        </div>
    `).join('');

    const podeAdicionar = caixas.length < LIMITE_EMAILS_POR_SETOR;

    lista.innerHTML = `
        <p style="color:#666;margin-bottom:1rem;">Cada setor tem um endereço de e-mail. Os e-mails recebidos nele viram demandas automaticamente neste setor e as respostas às demandas do setor são enviadas por esse mesmo endereço.</p>
        <div class="config-setor-itens">${cardsHtml}</div>
        ${podeAdicionar ? `
            <button type="button" class="btn-primary" style="margin-top:1rem;" onclick="mostrarFormularioEmailSetor()">➕ Cadastrar E-mail do Setor</button>
            <div id="form-email-setor-container"></div>
        ` : `<p style="margin-top:1rem;color:#999;font-style:italic;">Este setor já possui um endereço de e-mail. Para trocá-lo, exclua o atual e cadastre outro.</p>`}

        <div style="margin-top:2rem;border-top:1px solid #eee;padding-top:1rem;">
            <h4 style="margin-bottom:0.75rem;">📨 Log de E-mails Recebidos</h4>
            <div id="log-emails-processados-setor"><p>Carregando...</p></div>
        </div>
    `;

    carregarLogEmailsProcessadosSetor();
}

async function carregarLogEmailsProcessadosSetor() {
    const container = document.getElementById('log-emails-processados-setor');
    if (!container || !setorConfigAtual) return;

    try {
        const dados = await Api.get(`emails-processados.php?setor_id=${setorConfigAtual}&limite=20`);
        if (!dados.registros || dados.registros.length === 0) {
            container.innerHTML = '<p style="color:#999;font-style:italic;">Nenhum e-mail processado ainda para este setor.</p>';
            return;
        }

        const rotuloTipo = { novo_ticket: '🆕 Nova demanda', comentario: '💬 Comentário', erro: '❌ Erro' };

        container.innerHTML = `
            <div class="config-setor-itens">
                ${dados.registros.map(r => `
                    <div class="config-setor-item" style="flex-direction:column;align-items:flex-start;gap:0.25rem;">
                        <div style="display:flex;justify-content:space-between;width:100%;">
                            <strong>${sanitizeHTML(r.assunto || '(sem assunto)')}</strong>
                            <span style="font-size:0.85rem;color:#666;">${new Date(r.processado_em).toLocaleString('pt-BR')}</span>
                        </div>
                        <div style="font-size:0.85rem;color:#666;">
                            De: ${sanitizeHTML(r.email_remetente)} · Caixa: ${sanitizeHTML(r.setor_email_endereco || '—')} · ${sanitizeHTML(rotuloTipo[r.tipo] || r.tipo)}
                            ${r.ticket_id ? ` · Demanda #${String(r.ticket_id).padStart(5, '0')}` : ''}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    } catch (e) {
        container.innerHTML = '<p>Erro ao carregar log de e-mails.</p>';
    }
}

function mostrarFormularioEmailSetor() {
    const container = document.getElementById('form-email-setor-container');
    if (!container) return;

    container.innerHTML = `
        <form id="form-nova-caixa-email" style="margin-top:1rem;border-top:1px solid #eee;padding-top:1rem;" onsubmit="salvarEmailSetor(event)">
            <div class="form-row">
                <div class="form-group">
                    <label for="novo-email-endereco">E-mail:</label>
                    <input type="email" id="novo-email-endereco" required placeholder="setor@crprs.org.br">
                </div>
                <div class="form-group">
                    <label for="novo-email-usuario">Usuário IMAP:</label>
                    <input type="text" id="novo-email-usuario" required placeholder="geralmente igual ao e-mail">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="novo-email-host">Host IMAP:</label>
                    <input type="text" id="novo-email-host" required placeholder="email-ssl.com.br">
                </div>
                <div class="form-group">
                    <label for="novo-email-porta">Porta IMAP:</label>
                    <input type="number" id="novo-email-porta" required value="993">
                </div>
            </div>
            <div class="form-group">
                <label for="novo-email-senha">Senha IMAP:</label>
                <input type="password" id="novo-email-senha" required autocomplete="new-password">
            </div>

            <h4 style="margin:1rem 0 0.25rem 0;">📤 Envio (SMTP)</h4>
            <p style="color:#666;font-size:0.85rem;margin:0 0 0.75rem 0;">Em branco = usa o servidor SMTP do sistema e as mesmas credenciais do IMAP.</p>
            <div class="form-row">
                <div class="form-group">
                    <label for="novo-smtp-host">Host SMTP:</label>
                    <input type="text" id="novo-smtp-host" placeholder="mesmo servidor do sistema">
                </div>
                <div class="form-group">
                    <label for="novo-smtp-porta">Porta SMTP:</label>
                    <input type="number" id="novo-smtp-porta" value="465">
                </div>
                <div class="form-group">
                    <label for="novo-smtp-seguranca">Segurança:</label>
                    <select id="novo-smtp-seguranca">
                        <option value="ssl">SSL/TLS (porta 465)</option>
                        <option value="tls">STARTTLS (porta 587)</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="novo-smtp-usuario">Usuário SMTP (opcional):</label>
                    <input type="text" id="novo-smtp-usuario" placeholder="igual ao usuário IMAP">
                </div>
                <div class="form-group">
                    <label for="novo-smtp-senha">Senha SMTP (opcional):</label>
                    <input type="password" id="novo-smtp-senha" autocomplete="new-password" placeholder="igual à senha IMAP">
                </div>
            </div>
            <div class="form-group">
                <label for="novo-nome-remetente">Nome do remetente (opcional):</label>
                <input type="text" id="novo-nome-remetente" maxlength="100" placeholder="Conecta CRPRS - Cadastro">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="novo-validar-certificado" checked>
                    Validar o certificado do servidor de e-mail (recomendado)
                </label>
            </div>
            <div id="resultado-teste-email" style="margin-bottom:0.75rem;"></div>
            <div style="display:flex;gap:0.5rem;">
                <button type="button" class="btn-small" onclick="testarEmailSetor()">🔌 Testar Conexão</button>
                <button type="submit" class="btn-primary">Salvar</button>
                <button type="button" class="btn-small" onclick="document.getElementById('form-email-setor-container').innerHTML=''">Cancelar</button>
            </div>
        </form>
    `;
}

function coletarDadosFormEmailSetor() {
    return {
        email: document.getElementById('novo-email-endereco').value.trim(),
        imap_user: document.getElementById('novo-email-usuario').value.trim(),
        imap_host: document.getElementById('novo-email-host').value.trim(),
        imap_port: parseInt(document.getElementById('novo-email-porta').value, 10) || 993,
        imap_pass: document.getElementById('novo-email-senha').value,
        smtp_host: document.getElementById('novo-smtp-host').value.trim(),
        smtp_port: parseInt(document.getElementById('novo-smtp-porta').value, 10) || 465,
        smtp_seguranca: document.getElementById('novo-smtp-seguranca').value,
        smtp_user: document.getElementById('novo-smtp-usuario').value.trim(),
        smtp_pass: document.getElementById('novo-smtp-senha').value,
        nome_remetente: document.getElementById('novo-nome-remetente').value.trim(),
        validar_certificado: document.getElementById('novo-validar-certificado').checked
    };
}

async function testarEmailSetor() {
    const dados = coletarDadosFormEmailSetor();
    const resultadoDiv = document.getElementById('resultado-teste-email');

    if (!dados.email || !dados.imap_user || !dados.imap_host || !dados.imap_pass) {
        resultadoDiv.innerHTML = '<p style="color:#dc3545;">Preencha todos os campos antes de testar.</p>';
        return;
    }

    resultadoDiv.innerHTML = '<p>Testando conexão...</p>';

    try {
        const resp = await Api.post('setor-emails.php', Object.assign({ acao: 'testar' }, dados));
        const cor = resp.sucesso ? '#28a745' : '#dc3545';
        resultadoDiv.innerHTML = `<p style="color:${cor};">${resp.sucesso ? '✅' : '❌'} ${sanitizeHTML(resp.mensagem)}</p>`;
    } catch (e) {
        resultadoDiv.innerHTML = '<p style="color:#dc3545;">Erro ao testar conexão.</p>';
    }
}

async function salvarEmailSetor(event) {
    event.preventDefault();
    const dados = coletarDadosFormEmailSetor();

    mostrarLoader();

    try {
        await Api.post('setor-emails.php', Object.assign({ setor_id: setorConfigAtual }, dados));
        mostrarToast('Sucesso', 'Caixa de e-mail cadastrada!', 'success');
        carregarEmailsSetor();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao cadastrar caixa de e-mail');
    } finally {
        ocultarLoader();
    }
}

async function alternarAtivoEmailSetor(id, ativo) {
    try {
        await Api.put(`setor-emails.php/${id}`, { ativo: ativo });
        mostrarToast('Sucesso', 'Caixa de e-mail atualizada!', 'success');
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar caixa de e-mail');
        carregarEmailsSetor();
    }
}

async function alternarCertificadoEmailSetor(id, validar) {
    try {
        await Api.put(`setor-emails.php/${id}`, { validar_certificado: validar });
        mostrarToast('Sucesso', validar ? 'Certificado do servidor passa a ser validado.' : 'Atenção: o certificado do servidor deixou de ser validado.', validar ? 'success' : 'warning');
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar a caixa de e-mail');
        carregarEmailsSetor();
    }
}

function excluirEmailSetor(id) {
    mostrarConfirm('Excluir Caixa de E-mail', 'Tem certeza que deseja excluir esta caixa de e-mail? Novos e-mails recebidos nela deixarão de virar demandas.', async function (confirmado) {
        if (!confirmado) return;

        try {
            await Api.delete(`setor-emails.php/${id}`);
            mostrarToast('Sucesso', 'Caixa de e-mail excluída!', 'success');
            carregarEmailsSetor();
        } catch (e) {
            mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao excluir caixa de e-mail');
        }
    });
}

function formatarNomeItemConfigSetor(tipo, nome) {
    if (tipo === 'status') return typeof formatarNomeStatus === 'function' ? formatarNomeStatus(nome) : nome;
    if (tipo === 'prioridades') return typeof formatarNomePrioridade === 'function' ? formatarNomePrioridade(nome) : nome;
    return nome;
}

function renderizarListaConfigSetor(itens, tipo) {
    const lista = document.getElementById('config-setor-lista');

    if (itens.length === 0) {
        lista.innerHTML = '<p style="color:#999;font-style:italic;">Nenhum item cadastrado no catálogo global ainda.</p>';
        return;
    }

    lista.innerHTML = `
        <div class="config-setor-itens">
            ${itens.map((item, index) => `
                <div class="config-setor-item ${item.ativo ? '' : 'config-setor-item-inativo'}">
                    <label class="switch-label" style="flex:1;">
                        <label class="switch">
                            <input type="checkbox" ${item.ativo ? 'checked' : ''} onchange="alternarAtivoConfigSetor(${item.id}, '${tipo}', this.checked)">
                            <span class="slider"></span>
                        </label>
                        <span>${sanitizeHTML(formatarNomeItemConfigSetor(tipo, item.nome))}</span>
                    </label>
                    <div class="config-setor-item-ordem">
                        <button type="button" class="btn-small" ${index === 0 ? 'disabled' : ''} onclick="moverItemConfigSetor(${item.id}, '${tipo}', 'up')" title="Mover para cima">▲</button>
                        <button type="button" class="btn-small" ${index === itens.length - 1 ? 'disabled' : ''} onclick="moverItemConfigSetor(${item.id}, '${tipo}', 'down')" title="Mover para baixo">▼</button>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

async function alternarAtivoConfigSetor(itemId, tipo, ativo) {
    try {
        await Api.put('setor-config.php', { tipo: tipo, setor_id: setorConfigAtual, item_id: itemId, ativo: ativo });
        mostrarToast('Sucesso', 'Configuração atualizada!', 'success');
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar configuração');
        carregarAbaConfigSetor(tipo);
    }
}

async function moverItemConfigSetor(itemId, tipo, direcao) {
    try {
        await Api.post('setor-config.php', { tipo: tipo, setor_id: setorConfigAtual, item_id: itemId, direcao: direcao });
        carregarAbaConfigSetor(tipo);
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao reordenar');
    }
}

// === CONFIGURAÇÃO DE EMAIL ===
function mostrarConfigEmail() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_config_email = document.getElementById('btn-config-email'); if (_bbtn_config_email) _bbtn_config_email.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'config-email');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F4E7) + ' Configurar Email';

    const content = document.getElementById('config-content');

    // Setor em contexto (escolhido pelo admin no cabeçalho)
    const setorEmailId = window.CabecalhoSetor ? window.CabecalhoSetor.obterSetorContexto() : null;
    const seletorCab = document.getElementById('seletor-setor');
    const setorEmailNome = (seletorCab && seletorCab.value) ? seletorCab.options[seletorCab.selectedIndex].textContent : '';

    const secaoSetor = setorEmailId ? `
        <div class="config-email-setor">
            <h3>🏢 E-mail do setor: ${sanitizeHTML(setorEmailNome)}</h3>
            <div id="config-setor-lista"><p>Carregando...</p></div>
        </div>` : `
        <div class="config-email-setor">
            <h3>🏢 E-mail do setor</h3>
            <p style="color:#666;">Selecione um setor no cabeçalho para configurar o endereço de e-mail dele.</p>
        </div>`;

    content.innerHTML = `
        ${secaoSetor}
        <div class="config-email-container">
            <h3>📧 Remetente do sistema (global)</h3>
            <p style="color: #666; margin-bottom: 2rem;">Usado nos e-mails do sistema (recuperação de senha, lembretes, avisos internos) e como alternativa para setores sem endereço próprio.</p>

            <p class="config-email-aviso">Somente leitura: estes dados ficam no arquivo <code>.env</code> do servidor e são alterados pelo responsável técnico.</p>
            <dl class="config-email-global">
                <dt>Servidor IMAP (recebimento)</dt><dd id="cfg-imap">—</dd>
                <dt>Usuário IMAP</dt><dd id="cfg-imap-usuario">—</dd>
                <dt>Servidor SMTP (envio)</dt><dd id="cfg-smtp">—</dd>
                <dt>Usuário SMTP</dt><dd id="cfg-smtp-usuario">—</dd>
                <dt>Remetente</dt><dd id="cfg-remetente">—</dd>
            </dl>
            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="button" onclick="testarConexaoEmail()" class="btn-secondary">🔍 Testar Conexão</button>
            </div>
        </div>
    `;

    carregarConfigEmail();

    if (setorEmailId) {
        setorConfigAtual = setorEmailId;
        carregarEmailsSetor();
    }
}

async function carregarConfigEmail() {
    try {
        const config = await Api.get('config-email.php');
        const preencher = (id, texto) => { const el = document.getElementById(id); if (el) el.textContent = texto || '—'; };
        const senha = (definida) => definida ? ' (senha definida)' : ' (senha NÃO definida)';
        preencher('cfg-imap', config.imap_host ? `${config.imap_host}:${config.imap_port}` : '');
        preencher('cfg-imap-usuario', config.imap_username ? config.imap_username + senha(config.imap_senha_definida) : '');
        preencher('cfg-smtp', config.smtp_host ? `${config.smtp_host}:${config.smtp_port}` : '');
        preencher('cfg-smtp-usuario', config.smtp_username ? config.smtp_username + senha(config.smtp_senha_definida) : '');
        preencher('cfg-remetente', config.from_email ? `${config.from_name || ''} <${config.from_email}>` : '');
    } catch (e) {
        // Mesmo comportamento de antes: silencioso em caso de erro (o xhr.onload original só agia em status 200)
    }
}

async function testarConexaoEmail() {
    // Testa o remetente global configurado no .env (nenhum dado é enviado pelo navegador)
    mostrarLoader();

    try {
        const resultado = await Api.post('testar-email.php', {});

        let mensagem = '<div style="text-align: left;">';

        if (resultado.imap.sucesso) {
            mensagem += '<p style="color: #28a745;">✅ <strong>IMAP:</strong> ' + sanitizeHTML(resultado.imap.mensagem) + '</p>';
        } else {
            mensagem += '<p style="color: #dc3545;">❌ <strong>IMAP:</strong> ' + sanitizeHTML(resultado.imap.mensagem) + '</p>';
        }

        if (resultado.smtp.sucesso) {
            mensagem += '<p style="color: #28a745;">✅ <strong>SMTP:</strong> ' + sanitizeHTML(resultado.smtp.mensagem) + '</p>';
        } else {
            mensagem += '<p style="color: #dc3545;">❌ <strong>SMTP:</strong> ' + sanitizeHTML(resultado.smtp.mensagem) + '</p>';
        }

        mensagem += '</div>';

        const titulo = (resultado.imap.sucesso && resultado.smtp.sucesso) ? 'Teste Bem-Sucedido' : 'Teste com Falhas';
        mostrarAlert(titulo, mensagem);
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao testar conexão');
    } finally {
        ocultarLoader();
    }
}
