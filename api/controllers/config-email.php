<?php
/**
 * Remetente global do sistema (somente leitura).
 * A configuração fica no api/config/.env (IMAP_*, SMTP_*, FROM_*), editado pelo responsável
 * técnico — nunca pelo navegador. As caixas de cada setor são geridas em setor-emails.php.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../config/email-config.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'O remetente global é configurado no arquivo .env do servidor']);
    exit;
}

echo json_encode([
    'imap_host'      => (string) EmailConfig::get('IMAP_HOST'),
    'imap_port'      => (int) EmailConfig::get('IMAP_PORT'),
    'imap_username'  => (string) EmailConfig::get('IMAP_USERNAME'),
    'imap_senha_definida' => (bool) EmailConfig::get('IMAP_PASSWORD'),
    'smtp_host'      => (string) EmailConfig::get('SMTP_HOST'),
    'smtp_port'      => (int) EmailConfig::get('SMTP_PORT'),
    'smtp_username'  => (string) EmailConfig::get('SMTP_USERNAME'),
    'smtp_senha_definida' => (bool) EmailConfig::get('SMTP_PASSWORD'),
    'from_email'     => (string) EmailConfig::get('FROM_EMAIL'),
    'from_name'      => (string) EmailConfig::get('FROM_NAME'),
]);
