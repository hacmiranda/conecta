<?php
/**
 * Configuração de Ambiente
 * Detecta automaticamente localhost ou produção
 */

class Environment {
    private static $isProduction = null;
    
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
    private static $envCache = null;

    /**
     * Carrega variáveis do arquivo .env (se existir)
     */
    private static function loadEnv() {
        if (self::$envCache === null) {
            self::$envCache = [];
            $envPath = __DIR__ . '/.env';
            if (file_exists($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos(trim($line), '#') === 0) continue;
                    @list($k, $v) = explode('=', $line, 2);
                    if ($k) self::$envCache[trim($k)] = trim($v ?? '');
                }
            }
        }
        return self::$envCache;
    }
    
    /**
     * Retorna configuração baseada no ambiente (.env com fallback)
     */
    public static function get($key, $default = null) {
        $env = self::loadEnv();
        if (isset($env[$key]) && $env[$key] !== '') {
            return $env[$key];
        }
        
        $config = self::isProduction() ? self::production() : self::localhost();
        return $config[$key] ?? $default;
    }
    
    /**
     * Configurações de LOCALHOST
     */
    private static function localhost() {
        return [
            // Banco de Dados
            'db_host' => 'localhost',
            'db_name' => 'crpdesk',
            'db_user' => 'root',
            'db_pass' => '',
            'db_charset' => 'utf8mb4',
            
            // Email IMAP/SMTP (usar conta de teste)
            'imap_host' => 'email-ssl.com.br',
            'imap_port' => 993,
            'imap_user' => 'suporte2@crprs.org.br',
            'imap_pass' => 'e8bxR46rPbhS8YEx6SpT@',
            
            'smtp_host' => 'email-ssl.com.br',
            'smtp_port' => 465,
            'smtp_user' => 'suporte2@crprs.org.br',
            'smtp_pass' => 'e8bxR46rPbhS8YEx6SpT@',
            
            'from_email' => 'suporte2@crprs.org.br',
            'from_name' => 'Helpdesk CRPRS [DEV]',
            
            // Caminhos
            'base_url' => 'http://localhost/crpdesk',
            'upload_path' => 'C:/xampp/htdocs/crpdesk/backend/anexos/',
            'log_path' => 'C:/xampp/htdocs/crpdesk/backend/logs/',
            
            // Debug
            'debug' => true,
            'display_errors' => true,
            'debug' => true,
            'display_errors' => true,
            
            // Segurança
            'jwt_secret' => 'crpdesk_dev_secret_key_2026_!@#',
            'csrf_secret' => 'crpdesk_dev_csrf_secret_998877',
        ];
    }
    
    /**
     * Configurações de PRODUÇÃO
     */
    private static function production() {
        return [
            // Banco de Dados
            'db_host' => 'crpdesk.vpshost0269.mysql.dbaas.com.br',
            'db_name' => 'crpdesk',
            'db_user' => 'crpdesk',
            'db_pass' => 'WlxjB#YRSsu7s',
            'db_charset' => 'utf8mb4',
            
            // Email IMAP/SMTP
            'imap_host' => 'email-ssl.com.br',
            'imap_port' => 993,
            'imap_user' => 'suporte@crprs.org.br',
            'imap_pass' => 'Acvrg#huj45!asdloU',
            
            'smtp_host' => 'email-ssl.com.br',
            'smtp_port' => 465,
            'smtp_user' => 'suporte@crprs.org.br',
            'smtp_pass' => 'Acvrg#huj45!asdloU',
            
            'from_email' => 'suporte@crprs.org.br',
            'from_name' => 'Helpdesk CRPRS',
            
            // Caminhos
            'base_url' => 'https://crp07.org.br/crpdesk',
            'upload_path' => __DIR__ . '/../../backend/anexos/',
            'log_path' => __DIR__ . '/../../backend/logs/',
            
            // Debug
            'debug' => false,
            'display_errors' => false,
            // Debug
            'debug' => false,
            'display_errors' => false,
            
            // Segurança (Alterar em produção real se possível)
            'jwt_secret' => 'cRpD3sK_Pr0d_S3cR3t_K3y_mV1t0_F0rT3_!@#_2026',
            'csrf_secret' => 'cRpD3sK_Pr0d_CsRf_S3cR3t_XyZ_987',
        ];
    }
}
