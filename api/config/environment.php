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
    
    /**
     * Retorna configuração baseada no ambiente
     */
    public static function get($key) {
        $config = self::isProduction() ? self::production() : self::localhost();
        return $config[$key] ?? null;
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
            'imap_pass' => '***REMOVIDO***',
            
            'smtp_host' => 'email-ssl.com.br',
            'smtp_port' => 465,
            'smtp_user' => 'suporte2@crprs.org.br',
            'smtp_pass' => '***REMOVIDO***',
            
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
            'jwt_secret' => '***REMOVIDO***',
            'csrf_secret' => '***REMOVIDO***',
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
            'db_pass' => '***REMOVIDO***',
            'db_charset' => 'utf8mb4',
            
            // Email IMAP/SMTP
            'imap_host' => 'email-ssl.com.br',
            'imap_port' => 993,
            'imap_user' => 'suporte@crprs.org.br',
            'imap_pass' => '***REMOVIDO***',
            
            'smtp_host' => 'email-ssl.com.br',
            'smtp_port' => 465,
            'smtp_user' => 'suporte@crprs.org.br',
            'smtp_pass' => '***REMOVIDO***',
            
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
            'jwt_secret' => '***REMOVIDO***',
            'csrf_secret' => '***REMOVIDO***',
        ];
    }
}
