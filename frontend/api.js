/**
 * Cliente HTTP único do Conecta CRPRS (Fase 12 da auditoria, P1).
 * Resolve só o transporte — montar URL, cabeçalhos de autenticação/CSRF, serializar e
 * desserializar JSON, e decidir sucesso/erro pelo código HTTP. Cada chamador continua
 * decidindo como reagir ao erro (toast, alert, silencioso), exatamente como decide hoje.
 *
 * O 401/403 (sessão expirada) já é tratado globalmente em script.js, que intercepta todo
 * fetch() da aplicação — Api não precisa (e não deve) tratar isso de novo.
 */
const Api = {
    async request(metodo, caminho, { corpo, signal } = {}) {
        const url = caminho.startsWith('http') ? caminho : window.APP_CONFIG.apiBase + caminho;
        const headers = getAuthHeaders();
        if (corpo !== undefined) {
            headers['Content-Type'] = 'application/json';
        }
        const resposta = await fetch(url, {
            method: metodo,
            headers,
            signal,
            body: corpo !== undefined ? JSON.stringify(corpo) : undefined,
        });
        const texto = await resposta.text();
        let dados = null;
        if (texto) {
            try {
                dados = JSON.parse(texto);
            } catch (e) {
                dados = null; // resposta não-JSON (ex.: erro HTML do servidor): segue com dados = null
            }
        }
        if (!resposta.ok) {
            const erro = new Error((dados && dados.error) || `Erro ${resposta.status}`);
            erro.status = resposta.status;
            erro.dados = dados;
            throw erro;
        }
        return dados;
    },

    get(caminho, opts) {
        return this.request('GET', caminho, opts);
    },
    post(caminho, corpo, opts) {
        return this.request('POST', caminho, { ...opts, corpo });
    },
    put(caminho, corpo, opts) {
        return this.request('PUT', caminho, { ...opts, corpo });
    },
    delete(caminho, opts) {
        return this.request('DELETE', caminho, opts);
    },
};
