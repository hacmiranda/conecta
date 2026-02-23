<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../middleware/auth.php';

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Ler configurações atuais através do Environment que varre o .env internamente
    echo json_encode([
        'imap_host' => Environment::get('imap_host', ''),
        'imap_port' => Environment::get('imap_port', 993),
        'imap_username' => Environment::get('imap_user', ''),
        'smtp_host' => Environment::get('smtp_host', ''),
        'smtp_port' => Environment::get('smtp_port', 465),
        'smtp_username' => Environment::get('smtp_user', ''),
        'from_email' => Environment::get('from_email', ''),
        'from_name' => Environment::get('from_name', '')
    ]);
} elseif ($method === 'PUT') {
    // Atualizar configurações isoladas em .env
    $data = json_decode(file_get_contents('php://input'), true);
    
    $env_file = dirname(__DIR__) . '/config/.env';
    $current_env = [];
    
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            @list($k, $v) = explode('=', $line, 2);
            if ($k) $current_env[trim($k)] = trim($v ?? '');
        }
    }
    
    // Atualizar chaves específicas no Cache de Memória / Array do Env
    $current_env['imap_host'] = $data['imap_host'] ?? $current_env['imap_host'] ?? '';
    $current_env['imap_port'] = $data['imap_port'] ?? $current_env['imap_port'] ?? '993';
    $current_env['imap_user'] = $data['imap_username'] ?? $current_env['imap_user'] ?? '';
    if (!empty($data['imap_password'])) $current_env['imap_pass'] = $data['imap_password'];
    
    $current_env['smtp_host'] = $data['smtp_host'] ?? $current_env['smtp_host'] ?? '';
    $current_env['smtp_port'] = $data['smtp_port'] ?? $current_env['smtp_port'] ?? '465';
    $current_env['smtp_user'] = $data['smtp_username'] ?? $current_env['smtp_user'] ?? '';
    if (!empty($data['smtp_password'])) $current_env['smtp_pass'] = $data['smtp_password'];
    
    $current_env['from_email'] = $data['from_email'] ?? $current_env['from_email'] ?? '';
    $current_env['from_name'] = $data['from_name'] ?? $current_env['from_name'] ?? '';

    // Relançar de volta pro Arquivo .env
    $new_content_env = "# Configurações de Email - Gerado Pelo Sistema\n";
    foreach ($current_env as $k => $v) {
        $new_content_env .= "{$k}={$v}\n";
    }
    
    if (file_put_contents($env_file, $new_content_env)) {
        echo json_encode(['success' => true, 'message' => 'Configurações de rede salvas com sucesso em ambiente seguro (.env)']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao salvar segredos e configurações localmente']);
    }
}
