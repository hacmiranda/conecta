<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

// CORS e sessão segura
AuthMiddleware::configurarCORS(['POST']);
AuthMiddleware::configurarSessaoSegura();
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user = AuthMiddleware::verificarAuth();

        $database = new Database();
        $db = $database->getConnection();

        $auditoriaService = new AuditoriaService($db);
        $auditoriaService->registrarLogin($user['id'], $user['email'], 'logout');

        // Limpa dados da sessão, invalida o cookie e destrói a sessão
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        echo json_encode(['success' => true, 'message' => 'Logout realizado com sucesso']);

    } catch (Exception $e) {
        error_log('[LOGOUT] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao realizar logout']);
    }
}
