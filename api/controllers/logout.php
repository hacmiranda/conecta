<?php
/**
 * Encerra a sessão atual: o token deixa de ser aceito (sessoes_revogadas) e o cookie é apagado.
 * Com {"todas": true}, encerra também as sessões dos outros dispositivos (token_versao).
 * Sem "todas", as demais sessões do usuário (outros dispositivos) continuam válidas.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarCSRF();

// {"todas": true}: encerra também as sessões de outros navegadores/dispositivos
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$todas = !empty($input['todas']);

try {
    $db = (new Database())->getConnection();
    AuthMiddleware::revogarSessao($db, $user);
    $auditoria = new AuditoriaService($db);
    if ($todas) {
        AuthMiddleware::encerrarTodasAsSessoes($db, (int) $user['id']);
        $auditoria->registrar((int) $user['id'], 'encerrar_todas_sessoes', 'usuarios', (int) $user['id'], null, null);
    }
    AuthMiddleware::apagarCookieSessao();
    $auditoria->registrarLogin($user['id'], $user['email'] ?? null, 'logout');
    echo json_encode(['success' => true, 'message' => 'Logout realizado com sucesso']);
} catch (Exception $e) {
    error_log('[LOGOUT] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao realizar logout']);
}
