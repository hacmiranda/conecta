<?php
// Script de diagnóstico para Produção (crpdesk/api/test_auth_env.php)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain');
echo "=== CRPDESK PRODUÇÃO DEBUG ===\n\n";

echo "1. Versão do PHP: " . PHP_VERSION . "\n";
echo "2. Constante PASSWORD_ARGON2ID: " . (defined('PASSWORD_ARGON2ID') ? 'Sim' : 'Não') . "\n";
echo "3. Função session_set_cookie_params aceita array? " . (PHP_VERSION_ID >= 70300 ? 'Sim' : 'Não') . "\n";

echo "\n4. Testando inclusão do banco de dados...\n";
try {
    require_once __DIR__ . '/config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    echo "- Conexão DB OK\n";
} catch (Exception $e) {
    echo "- Erro DB: " . $e->getMessage() . "\n";
}

echo "\n5. Listando diretórios disponíveis na raiz do projeto (crpdesk/):\n";
$dirs = scandir(__DIR__ . '/../');
foreach ($dirs as $d) {
    if ($d !== '.' && $d !== '..') {
        echo "- $d" . (is_dir(__DIR__ . '/../' . $d) ? " (Pasta)" : " (Arquivo)") . "\n";
    }
}

echo "\n6. Testando inclusão do JWT...\n";
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Firebase\JWT\JWT')) {
        echo "- JWT Autoload OK\n";
    } else {
        echo "- ERRO JWT: Classe não encontrada\n";
    }
} catch (Exception $e) {
    echo "- Erro JWT: " . $e->getMessage() . "\n";
}

echo "\n6. Testando inclusão do AuthMiddleware...\n";
try {
    require_once __DIR__ . '/middleware/auth.php';
    echo "- AuthMiddleware OK\n";
} catch (Exception $e) {
    echo "- Erro Middleware: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
