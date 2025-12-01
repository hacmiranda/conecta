<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user = AuthMiddleware::verificarAuth();
        
        $database = new Database();
        $db = $database->getConnection();
        
        // Registrar logout
        $auditoriaService = new AuditoriaService($db);
        $auditoriaService->registrarLogin($user['id'], $user['email'], 'logout');
        
        // Destruir sessão
        session_destroy();
        
        echo json_encode(['success' => true, 'message' => 'Logout realizado com sucesso']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao realizar logout']);
    }
}
?>
