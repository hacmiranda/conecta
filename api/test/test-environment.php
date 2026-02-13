<?php
/**
 * Script de Teste de Ambiente
 * Verifica se as configurações estão corretas
 */

require_once '../config/environment.php';
require_once '../config/database.php';
require_once '../config/email-config.php';

echo "<h1>🔧 Teste de Configuração de Ambiente</h1>";

// Detectar ambiente
$env = Environment::isProduction() ? 'PRODUÇÃO' : 'LOCALHOST';
echo "<h2>Ambiente Detectado: <strong style='color: " . (Environment::isProduction() ? 'green' : 'blue') . "'>$env</strong></h2>";

echo "<hr>";

// Testar Banco de Dados
echo "<h3>📊 Banco de Dados</h3>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Configuração</th><th>Valor</th></tr>";
echo "<tr><td>Host</td><td>" . Environment::get('db_host') . "</td></tr>";
echo "<tr><td>Database</td><td>" . Environment::get('db_name') . "</td></tr>";
echo "<tr><td>Usuário</td><td>" . Environment::get('db_user') . "</td></tr>";
echo "<tr><td>Senha</td><td>" . str_repeat('*', strlen(Environment::get('db_pass'))) . "</td></tr>";
echo "</table>";

try {
    $database = new Database();
    $db = $database->getConnection();
    echo "<p style='color: green;'>✅ Conexão com banco de dados: <strong>SUCESSO</strong></p>";
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM tickets");
    $result = $stmt->fetch();
    echo "<p>Total de tickets no banco: <strong>{$result['total']}</strong></p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro na conexão: " . $e->getMessage() . "</p>";
}

echo "<hr>";

// Testar Email
echo "<h3>📧 Configuração de Email</h3>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Configuração</th><th>Valor</th></tr>";
echo "<tr><td>IMAP Host</td><td>" . EmailConfig::get('IMAP_HOST') . "</td></tr>";
echo "<tr><td>IMAP Port</td><td>" . EmailConfig::get('IMAP_PORT') . "</td></tr>";
echo "<tr><td>From Name</td><td>" . EmailConfig::get('FROM_NAME') . "</td></tr>";
echo "</table>";

echo "<hr>";
echo "<p><strong>Teste concluído!</strong></p>";
?>
