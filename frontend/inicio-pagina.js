/**
 * Script de início de página: roda no ponto exato onde ficava o <script> inline do
 * index.html (Fase 9 das melhorias adiadas — tirar scripts inline para poder apertar a
 * Content-Security-Policy no futuro). Comportamento idêntico, só movido para arquivo externo.
 */

// Verificar se está logado
const usuario = JSON.parse(localStorage.getItem('cd_usuario') || 'null');
if (!usuario) {
    window.location.href = './login.html';
} else {
    // Esconder configurações para quem não é administrador
    if (usuario.tipo !== 'admin') {
        const btnConfig = document.querySelector('button[onclick="mostrarSecao(\'config\')"]');
        if (btnConfig) {
            btnConfig.style.display = 'none';
        }
    }

    // Atualizar ícone do modo escuro
    const isDark = localStorage.getItem('cd_modoEscuro') === 'true';
    if (isDark) {
        document.body.classList.add('modo-escuro');
        document.getElementById('icone-modo-escuro').textContent = '☀️';
        document.getElementById('texto-modo-escuro').textContent = 'Modo Claro';
    }
}


// Fechar menu ao clicar fora
document.addEventListener('click', function (e) {
    const dropdown = document.getElementById('header-avatar-dropdown');
    const btn = document.getElementById('header-avatar-btn');
    if (dropdown && btn && !dropdown.contains(e.target) && !btn.contains(e.target)) {
        dropdown.classList.remove('show');
    }
});

// Cada grupo do menu abre/fecha de forma independente — igual ao acordeão da
// sidebar do sistema de eventos: clicar em um grupo não recolhe os demais que
// já estavam abertos.
function toggleNavDropdown(name) {
    const dropdown = document.getElementById('nav-' + name + '-dropdown');
    const btn = document.getElementById('btn-nav-' + name);
    const isOpen = dropdown.classList.contains('open');
    const vaiAbrir = !isOpen;

    // Notificações usa position:fixed (foge do overflow:auto da sidebar) —
    // por isso a posição precisa ser calculada a partir do botão ao abrir.
    if (vaiAbrir && name === 'notificacoes') {
        const rect = btn.getBoundingClientRect();
        const larguraPainel = 320;
        let left = rect.right + 8;
        if (left + larguraPainel > window.innerWidth) {
            left = Math.max(8, window.innerWidth - larguraPainel - 8);
        }
        let top = rect.top;
        if (top + 400 > window.innerHeight) {
            top = Math.max(8, window.innerHeight - 408);
        }
        dropdown.style.top = Math.round(top) + 'px';
        dropdown.style.left = Math.round(left) + 'px';
    }

    dropdown.classList.toggle('open', vaiAbrir);
    btn.setAttribute('aria-expanded', String(vaiAbrir));
}

function fecharNavDropdown(name) {
    const dropdown = document.getElementById('nav-' + name + '-dropdown');
    const btn = document.getElementById('btn-nav-' + name);
    if (dropdown) dropdown.classList.remove('open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
}


