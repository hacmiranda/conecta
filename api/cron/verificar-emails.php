<?php
// Cron job para verificar emails

// Proteção: Verificar chave de segurança (apenas para acesso web)
$chave_secreta = 'crp07_cron_2025';
if (php_sapi_name() !== 'cli' && (!isset($_GET['key']) || $_GET['key'] !== $chave_secreta)) {
    http_response_code(403);
    die('Acesso negado');
}

// Definir diretório base
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config/environment.php';
require_once $base_dir . '/config/database.php';
require_once $base_dir . '/config/email-config.php';
require_once $base_dir . '/services/email-service.php';

// Log de execução
$log_file = '../../backend/logs/email-cron.log';
$log_dir = dirname($log_file);
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

echo "<h2>Verificando emails...</h2>";

try {
    echo "<p>Iniciando verificação de emails...</p>";
    log_message("Iniciando verificação de emails...");
    
    echo "<p>Conectando ao banco de dados...</p>";
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<p>Criando serviço de email...</p>";
    $emailService = new EmailService($db);
    
    echo "<p>Verificando emails...</p>";
    $resultado = $emailService->verificarEmails();
    
    if ($resultado) {
        echo "<p style='color: green;'>✅ Verificação de emails concluída com sucesso</p>";
        log_message("Verificação de emails concluída com sucesso");
    } else {
        echo "<p style='color: red;'>❌ Erro na verificação de emails</p>";
        log_message("Erro na verificação de emails");
    }
    
    // Verificar lembretes
    echo "<p>Verificando lembretes...</p>";
    log_message("Verificando lembretes...");
    include 'verificar-lembretes.php';
    log_message("Verificação de lembretes concluída");
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro crítico: " . $e->getMessage() . "</p>";
    log_message("Erro crítico: " . $e->getMessage());
}

echo "<p>Fim da execução</p>";
log_message("Fim da execução\n");
?>