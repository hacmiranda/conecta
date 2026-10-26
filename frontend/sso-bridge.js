// Ponte de SSO — só entra em ação quando o Conecta CRPRS está incorporado em um
// <iframe> por um painel agregador. Fora de um iframe, este script não faz nada.
(function () {
    'use strict';

    if (window.self === window.top) return; // não está incorporado — nada a fazer
    if (localStorage.getItem('cd_usuario')) return; // já autenticado nesta origem (sessão no cookie)

    const origensConfiaveis = (window.APP_CONFIG && window.APP_CONFIG.trustedParentOrigins) || [];

    function origemConfiavel(origin) {
        return origensConfiaveis.some(function (padrao) {
            if (padrao.endsWith(':*')) {
                return origin.startsWith(padrao.slice(0, -1));
            }
            return origin === padrao;
        });
    }

    function mostrarAguardando() {
        const overlay = document.createElement('div');
        overlay.id = 'sso-bridge-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');

        const box = document.createElement('div');
        box.className = 'sso-bridge-box';

        const spinner = document.createElement('div');
        spinner.className = 'sso-bridge-spinner';
        spinner.setAttribute('aria-hidden', 'true');

        const texto = document.createElement('p');
        texto.id = 'sso-bridge-texto';
        texto.textContent = 'Conectando sua sessão do painel…';

        box.appendChild(spinner);
        box.appendChild(texto);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
    }

    function mostrarErro() {
        const texto = document.getElementById('sso-bridge-texto');
        const spinner = document.querySelector('.sso-bridge-spinner');
        if (spinner) spinner.style.display = 'none';
        if (texto) {
            texto.textContent = 'Não foi possível conectar sua sessão do painel. Recarregue a página ou acesse diretamente.';
        }
    }

    function trocarTokenSSO(ssoToken) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_CONFIG.apiBase + 'auth-sso.php', true);
        xhr.setRequestHeader('Content-Type', 'application/json');

        xhr.onload = function () {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    localStorage.setItem('cd_usuario', JSON.stringify(response.usuario));
                    if (response.csrf_token) {
                        localStorage.setItem('cd_csrf_token', response.csrf_token);
                    }
                    window.location.href = './index.html';
                } catch (e) {
                    mostrarErro();
                }
            } else {
                mostrarErro();
            }
        };

        xhr.onerror = mostrarErro;
        xhr.send(JSON.stringify({ sso_token: ssoToken }));
    }

    document.addEventListener('DOMContentLoaded', mostrarAguardando);

    window.addEventListener('message', function (event) {
        if (!origemConfiavel(event.origin)) return;
        const dados = event.data;
        if (!dados || dados.type !== 'SSO_TOKEN' || !dados.token) return;
        trocarTokenSSO(dados.token);
    });

    // Avisa o painel pai que a ponte está pronta para receber o token.
    // Broadcast com targetOrigin '*' é intencional: a mensagem não carrega dado
    // sensível (só um sinal de "pronto"), e o iframe ainda não sabe a origem exata
    // do painel até que ele se identifique enviando o token.
    window.parent.postMessage({ type: 'SSO_BRIDGE_READY' }, '*');
})();
