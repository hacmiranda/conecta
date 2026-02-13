<?php
require_once __DIR__ . '/environment.php';

class EmailConfig {
    const IMAP_HOST = null;
    const IMAP_PORT = null;
    const IMAP_USERNAME = null;
    const IMAP_PASSWORD = null;
    const SMTP_HOST = null;
    const SMTP_PORT = null;
    const SMTP_USERNAME = null;
    const SMTP_PASSWORD = null;
    const FROM_EMAIL = null;
    const FROM_NAME = null;
    const MAX_ANEXOS_EMAIL = 5;
    const MAX_TAMANHO_EMAIL = 20971520; // 20MB
    const TIPOS_PERMITIDOS = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
    const INTERVALO_VERIFICACAO = 1;
    
    public static function get($key) {
        $map = [
            'IMAP_HOST' => 'imap_host',
            'IMAP_PORT' => 'imap_port',
            'IMAP_USERNAME' => 'imap_user',
            'IMAP_PASSWORD' => 'imap_pass',
            'SMTP_HOST' => 'smtp_host',
            'SMTP_PORT' => 'smtp_port',
            'SMTP_USERNAME' => 'smtp_user',
            'SMTP_PASSWORD' => 'smtp_pass',
            'FROM_EMAIL' => 'from_email',
            'FROM_NAME' => 'from_name',
        ];
        
        if (isset($map[$key])) {
            return Environment::get($map[$key]);
        }
        
        return constant('self::' . $key);
    }
}
