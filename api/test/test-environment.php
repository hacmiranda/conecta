<?php
/**
 * Teste de configuração do ambiente — somente pela linha de comando:
 *   php api/test/test-environment.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email-config.php';

echo "Teste de configuração do ambiente\n";
echo "Ambiente: " . (Environment::isProduction() ? 'PRODUÇÃO' : 'LOCAL') . " (APP_ENV no .env)\n\n";

echo "Banco de dados\n";
echo "  Host:     " . Environment::get('db_host') . "\n";
echo "  Database: " . Environment::get('db_name') . "\n";
echo "  Usuário:  " . Environment::get('db_user') . "\n";
echo "  Senha:    " . (Environment::get('db_pass') ? 'definida' : 'NÃO definida') . "\n";

try {
    $db = (new Database())->getConnection();
    $total = $db->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
    echo "  Conexão:  OK ($total demandas)\n";
} catch (Exception $e) {
    echo "  Conexão:  ERRO - " . $e->getMessage() . "\n";
}

echo "\nE-mail (remetente global)\n";
echo "  IMAP: " . EmailConfig::get('IMAP_HOST') . ':' . EmailConfig::get('IMAP_PORT') . "\n";
echo "  SMTP: " . EmailConfig::get('SMTP_HOST') . ':' . EmailConfig::get('SMTP_PORT') . "\n";
echo "  Remetente: " . EmailConfig::get('FROM_NAME') . ' <' . EmailConfig::get('FROM_EMAIL') . ">\n";

echo "\nChaves\n";
foreach (['jwt_secret', 'setor_email_encryption_key', 'integration_api_key', 'cron_key'] as $chave) {
    echo '  ' . str_pad(strtoupper($chave), 28) . (Environment::get($chave) ? 'definida' : 'NÃO definida') . "\n";
}
