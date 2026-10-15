<?php
/**
 * Configuração de Ambiente — Conecta CRPRS
 * Detecta automaticamente localhost ou produção.
 * Credenciais sensíveis são carregadas exclusivamente do arquivo .env.
 */

class Environment {
    private static $isProduction = null;
    private static $envCache = null;

    /**
     * Detecta se está em produção ou localhost
     */
    public static function isProduction() {
        if (self::$isProduction === null) {
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            self::$isProduction = !in_array($host, ['localhost', '127.0.0.1', '::1']);
        }
        return self::$isProduction;
    }

    /**
     * Carrega variáveis do arquivo .env
     */
    private static function loadEnv() {
        if (self::$envCache === null) {
            self::$envCache = [];
            $envPath = __DIR__ . '/.env';
            if (file_exists($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $linha = trim($line);
                    if ($linha === '' || $linha[0] === '#') continue;
                    $partes = explode('=', $linha, 2);
                    if (count($partes) === 2) {
                        self::$envCache[trim($partes[0])] = trim($partes[1]);
                    }
                }
            }
        }
        return self::$envCache;
    }

    /**
     * Retorna configuração baseada no ambiente.
     * Credenciais vêm do .env com sufixo _LOCAL ou _PROD.
     * Configurações não sensíveis têm fallback nos arrays abaixo.
     */
    public static function get($key, $default = null) {
        $env = self::loadEnv();
        $sufixo = self::isProduction() ? '_PROD' : '_LOCAL';

        // Tenta chave com sufixo de ambiente (ex: DB_PASS_PROD, JWT_SECRET_LOCAL)
        $chaveEnv = strtoupper($key) . $sufixo;
        if (isset($env[$chaveEnv]) && $env[$chaveEnv] !== '') {
            return $env[$chaveEnv];
        }

        // Tenta chave sem sufixo (compatibilidade com valores genéricos no .env)
        $chaveSimples = strtoupper($key);
        if (isset($env[$chaveSimples]) && $env[$chaveSimples] !== '') {
            return $env[$chaveSimples];
        }

        // Fallback para configurações não sensíveis (sem credenciais hardcoded)
        $config = self::isProduction() ? self::production() : self::localhost();
        return $config[$key] ?? $default;
    }

    /**
     * Configurações não sensíveis de LOCALHOST.
     * Credenciais (senhas, tokens) NÃO estão aqui — devem vir do .env.
     */
    private static function localhost() {
        return [
            // Banco de Dados
            'db_host'        => 'localhost',
            'db_name'        => 'db_conecta',
            'db_user'        => 'root',
            'db_charset'     => 'utf8mb4',

            // Email
            'imap_host'      => 'email-ssl.com.br',
            'imap_port'      => 993,
            'smtp_host'      => 'email-ssl.com.br',
            'smtp_port'      => 465,
            'from_name'      => 'Conecta CRPRS [DEV]',
            'from_email'     => 'suporte@crprs.org.br',

            // Caminhos
            // upload_path/log_path usam __DIR__ (igual à produção) para não quebrar
            // se a pasta raiz do projeto for renomeada — só base_url e cors_allowed_origins
            // (que são URLs, não caminhos de disco) precisam do nome da pasta.
            'base_url'       => 'http://localhost/conecta',
            'upload_path'    => __DIR__ . '/../../backend/anexos/',
            'log_path'       => __DIR__ . '/../../backend/logs/',

            // Módulo/Embedding — origens CORS e chave de validação do token SSO
            // (sobrescrever via CORS_ALLOWED_ORIGINS_LOCAL / SSO_SHARED_SECRET_LOCAL no .env)
            'cors_allowed_origins' => 'http://localhost,http://localhost/conecta,http://127.0.0.1,http://127.0.0.1/conecta',
            'sso_shared_secret'    => '',

            // Debug habilitado apenas em desenvolvimento
            'debug'          => true,
            'display_errors' => true,
        ];
    }

    /**
     * Configurações não sensíveis de PRODUÇÃO.
     * Credenciais (senhas, tokens) NÃO estão aqui — devem vir do .env.
     */
    private static function production() {
        return [
            // Banco de Dados
            'db_host'        => 'crpdesk.vpshost0269.mysql.dbaas.com.br',
            'db_name'        => 'crpdesk',
            'db_user'        => 'crpdesk',
            'db_charset'     => 'utf8mb4',

            // Email
            'imap_host'      => 'email-ssl.com.br',
            'imap_port'      => 993,
            'smtp_host'      => 'email-ssl.com.br',
            'smtp_port'      => 465,
            'from_name'      => 'Conecta CRPRS',
            'from_email'     => 'suporte@crprs.org.br',

            // Caminhos
            'base_url'       => 'https://crp07.org.br/crpdesk',
            'upload_path'    => __DIR__ . '/../../backend/anexos/',
            'log_path'       => __DIR__ . '/../../backend/logs/',

            // Módulo/Embedding — origens CORS e chave de validação do token SSO
            // (sobrescrever via CORS_ALLOWED_ORIGINS_PROD / SSO_SHARED_SECRET_PROD no .env)
            'cors_allowed_origins' => 'https://crp07.org.br,https://www.crp07.org.br',
            'sso_shared_secret'    => '',

            // Debug desabilitado em produção
            'debug'          => false,
            'display_errors' => false,
        ];
    }
}
