/**
 * Alterar Senha (menu do usuário) e Personalização do sistema (menu Configurações, inclui o
 * código morto "Modo Escuro (Configurações)", que mais nenhum botão chama — o modo escuro
 * real usa toggleModoEscuro() direto, em script.js). Extraído de script.js na Fase 12
 * (onda 6 da divisão por domínio).
 */

// Verificar complexidade da senha
function verificarComplexidade() {
    const senha = document.getElementById('nova-senha').value;
    const btnAlterar = document.getElementById('btn-alterar');

    if (!senha || !btnAlterar) return;

    const checks = {
        tamanho: senha.length >= 10,
        maiuscula: /[A-Z]/.test(senha),
        minuscula: /[a-z]/.test(senha),
        numero: /[0-9]/.test(senha),
        especial: /[!@#$%^&*(),.?":{}|<>]/.test(senha)
    };

    // Atualizar indicadores visuais
    document.getElementById('check-tamanho').innerHTML = checks.tamanho ? '✅ Mínimo 10 caracteres' : '❌ Mínimo 10 caracteres';
    document.getElementById('check-maiuscula').innerHTML = checks.maiuscula ? '✅ 1 letra maiúscula' : '❌ 1 letra maiúscula';
    document.getElementById('check-minuscula').innerHTML = checks.minuscula ? '✅ 1 letra minúscula' : '❌ 1 letra minúscula';
    document.getElementById('check-numero').innerHTML = checks.numero ? '✅ 1 número' : '❌ 1 número';
    document.getElementById('check-especial').innerHTML = checks.especial ? '✅ 1 caractere especial' : '❌ 1 caractere especial';

    // Habilitar botão se todos os critérios forem atendidos
    const todasValidas = Object.values(checks).every(check => check);
    btnAlterar.disabled = !todasValidas;
}

// Alterar senha
async function alterarSenha(event) {
    event.preventDefault();

    const senhaAtual = document.getElementById('senha-atual').value;
    const novaSenha = document.getElementById('nova-senha').value;
    const confirmarSenha = document.getElementById('confirmar-senha').value;

    if (novaSenha !== confirmarSenha) {
        mostrarAlert('Erro', 'As senhas não coincidem.');
        return;
    }

    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));

    try {
        const resposta = await Api.post('alterar-senha.php', {
            senha_atual: senhaAtual,
            nova_senha: novaSenha
        });

        if (resposta && resposta.token) {
            localStorage.setItem('cd_token', resposta.token);
            localStorage.setItem('cd_csrf_token', resposta.csrf_token || '');
        }
        if (usuario && usuario.trocar_senha) {
            usuario.trocar_senha = false;
            localStorage.setItem('cd_usuario', JSON.stringify(usuario));
            mostrarAlert('Senha alterada', 'Senha alterada com sucesso! O sistema será carregado.', () => window.location.reload());
            return;
        }
        mostrarToast('Sucesso', 'Senha alterada com sucesso! As outras sessões abertas foram encerradas.', 'success');
        document.getElementById('form-alterar-senha').reset();
        document.querySelectorAll('.check-item').forEach(item => {
            item.innerHTML = item.innerHTML.replace('✅', '❌');
        });
        document.getElementById('btn-alterar').disabled = true;
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao alterar senha');
    }
}

// === PERSONALIZAÇÃO DO SISTEMA ===

function mostrarPersonalizacao() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_personalizacao = document.getElementById('btn-personalizacao'); if (_bbtn_personalizacao) _bbtn_personalizacao.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'personalizacao');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F3A8) + ' Personalização';

    // A personalização vale para o sistema inteiro: só o administrador altera
    const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    const somenteLeitura = usuarioLogado.tipo !== 'admin';

    const content = document.getElementById('config-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
            <div class="form-usuario">
                ${somenteLeitura ? `
                <p class="aviso-somente-leitura" role="status">
                    <strong>Somente leitura:</strong> a personaliza&ccedil;&atilde;o vale para todos os setores e s&oacute; pode ser alterada por administradores.
                </p>` : `
                <p class="aviso-personalizacao">
                    <strong>Importante:</strong> Se as altera&ccedil;&otilde;es n&atilde;o aparecerem imediatamente, atualize a p&aacute;gina (F5 ou Ctrl+R).
                </p>`}

                <div class="personalizacao-bloco">
                    <h4>Logo do Sistema <small class="personalizacao-dica">(PNG, 40x40px)</small></h4>
                    <p class="personalizacao-descricao">
                        Aplicado em: <strong>Cabe&ccedil;alho</strong>, <strong>Favicon</strong>, <strong>Loader</strong> e <strong>Modais de Recupera&ccedil;&atilde;o de Senha</strong>.
                    </p>
                    <div class="personalizacao-upload">
                        <label for="logo-header" class="sr-only">Arquivo PNG do logo</label>
                        <input type="file" id="logo-header" accept="image/png" ${somenteLeitura ? 'disabled' : ''}>
                        <button class="btn-primary" onclick="uploadLogoHeader()" ${somenteLeitura ? 'disabled' : ''}>Upload Logo</button>
                    </div>
                </div>
            </div>
        `;
    }, 10);
}

// Upload de arquivo (multipart/form-data): Api.js só serializa JSON, então este continua em
// XMLHttpRequest puro — mesmo padrão já usado em avatar.js para o upload de avatar.
function uploadLogoHeader() {
    const input = document.getElementById('logo-header');
    if (!input.files[0]) {
        mostrarAlert('Erro', 'Selecione um arquivo PNG');
        return;
    }

    const formData = new FormData();
    formData.append('logo', input.files[0]);
    formData.append('tipo', 'header');

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'personalizacao.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Logo do header atualizado!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao fazer upload');
        }
    };

    xhr.send(formData);
}

function uploadLogoLogin() {
    const input = document.getElementById('logo-login');
    if (!input.files[0]) {
        mostrarAlert('Erro', 'Selecione um arquivo PNG');
        return;
    }

    const formData = new FormData();
    formData.append('logo', input.files[0]);
    formData.append('tipo', 'login');

    mostrarLoader();

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'personalizacao.php', true);
    setSecurityHeaders(xhr);

    xhr.onload = function () {
        ocultarLoader();
        if (xhr.status === 200) {
            mostrarToast('Sucesso', 'Logo de login atualizado!', 'success');
        } else {
            const response = JSON.parse(xhr.responseText);
            mostrarAlert('Erro', response.error || 'Erro ao fazer upload');
        }
    };

    xhr.send(formData);
}

// === MODO ESCURO (CONFIGURAÇÕES) ===
function mostrarModoEscuro() {
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    document.getElementById('btn-modo-escuro-config').classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'modo-escuro');

    const content = document.getElementById('config-content');
    const isDark = document.body.classList.contains('modo-escuro');

    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="form-usuario">
            <h3>🌙 Modo Escuro</h3>
            <p>Ative o modo escuro para reduzir o cansaço visual em ambientes com pouca luz.</p>
            <div style="margin-top: 1.5rem;">
                <label class="switch-label" style="font-size: 1.1rem;">
                    <label class="switch">
                        <input type="checkbox" id="toggle-modo-escuro" ${isDark ? 'checked' : ''} onchange="toggleModoEscuro()">
                        <span class="slider"></span>
                    </label>
                    <span id="label-modo-escuro">${isDark ? 'Desativar Modo Escuro' : 'Ativar Modo Escuro'}</span>
                </label>
            </div>
        </div>
    `;
    }, 10);
}
