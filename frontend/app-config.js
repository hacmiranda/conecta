// Configuração de deploy do Conecta CRPRS.
// Centraliza o caminho base da API para permitir relocação do módulo
// (ex.: quando incorporado a um painel agregador) sem editar cada chamada.
window.APP_CONFIG = {
    // Raiz da aplicação no servidor (com barra final); base de api/, backend/ e demais caminhos
    rootBase: '/conecta/',
    apiBase: '/conecta/api/controllers/',

    // Origens de painéis agregadores autorizados a incorporar este módulo em <iframe>
    // e enviar o token de SSO via postMessage. Aceita porta exata ("http://host:8080")
    // ou qualquer porta com sufixo ":*" (útil em desenvolvimento local).
    // Deve ficar em sincronia com a diretiva frame-ancestors do .htaccess.
    trustedParentOrigins: [
        'http://localhost:*',
        'http://127.0.0.1:*'
    ]
};
