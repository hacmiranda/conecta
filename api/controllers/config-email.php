<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../middleware/auth.php';

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user);

$method = $_SERVER['REQUEST_METHOD'];

$config_file = dirname(__DIR__) . '/config/email-config.php';

if ($method === 'GET') {
    // Ler configurações atuais
    if (file_exists($config_file)) {
        $content = file_get_contents($config_file);
        
        preg_match("/const IMAP_HOST = '(.+?)';/", $content, $imap_host);
        preg_match("/const IMAP_PORT = (\d+);/", $content, $imap_port);
        preg_match("/const IMAP_USERNAME = '(.+?)';/", $content, $imap_user);
        preg_match("/const SMTP_HOST = '(.+?)';/", $content, $smtp_host);
        preg_match("/const SMTP_PORT = (\d+);/", $content, $smtp_port);
        preg_match("/const SMTP_USERNAME = '(.+?)';/", $content, $smtp_user);
        preg_match("/const FROM_EMAIL = '(.+?)';/", $content, $from_email);
        preg_match("/const FROM_NAME = '(.+?)';/", $content, $from_name);
        
        echo json_encode([
            'imap_host' => $imap_host[1] ?? '',
            'imap_port' => $imap_port[1] ?? 993,
            'imap_username' => $imap_user[1] ?? '',
            'smtp_host' => $smtp_host[1] ?? '',
            'smtp_port' => $smtp_port[1] ?? 465,
            'smtp_username' => $smtp_user[1] ?? '',
            'from_email' => $from_email[1] ?? '',
            'from_name' => $from_name[1] ?? ''
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Arquivo de configuração não encontrado']);
    }
} elseif ($method === 'PUT') {
    // Atualizar configurações
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Ler senhas atuais se não forem fornecidas
    $content = file_get_contents($config_file);
    preg_match("/const IMAP_PASSWORD = '(.+?)';/", $content, $current_imap_pass);
    preg_match("/const SMTP_PASSWORD = '(.+?)';/", $content, $current_smtp_pass);
    
    $imap_password = !empty($data['imap_password']) ? $data['imap_password'] : ($current_imap_pass[1] ?? '');
    $smtp_password = !empty($data['smtp_password']) ? $data['smtp_password'] : ($current_smtp_pass[1] ?? '');
    
    $new_content = "<?php\nclass EmailConfig {\n";
    $new_content .= "    const IMAP_HOST = '{$data['imap_host']}';\n";
    $new_content .= "    const IMAP_PORT = {$data['imap_port']};\n";
    $new_content .= "    const IMAP_USERNAME = '{$data['imap_username']}';\n";
    $new_content .= "    const IMAP_PASSWORD = '{$imap_password}';\n";
    $new_content .= "    const SMTP_HOST = '{$data['smtp_host']}';\n";
    $new_content .= "    const SMTP_PORT = {$data['smtp_port']};\n";
    $new_content .= "    const SMTP_USERNAME = '{$data['smtp_username']}';\n";
    $new_content .= "    const SMTP_PASSWORD = '{$smtp_password}';\n";
    $new_content .= "    const FROM_EMAIL = '{$data['from_email']}';\n";
    $new_content .= "    const FROM_NAME = '{$data['from_name']}';\n";
    $new_content .= "    const MAX_ANEXOS_EMAIL = 5;\n";
    $new_content .= "    const MAX_TAMANHO_EMAIL = 20 * 1024 * 1024;\n";
    $new_content .= "    const TIPOS_PERMITIDOS = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];\n";
    $new_content .= "    const INTERVALO_VERIFICACAO = 1;\n";
    $new_content .= "}\n";
    
    if (file_put_contents($config_file, $new_content)) {
        echo json_encode(['success' => true, 'message' => 'Configurações salvas com sucesso']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao salvar configurações']);
    }
}
