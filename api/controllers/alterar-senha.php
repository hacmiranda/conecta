<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/senha-service.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// POST - Alterar senha
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Autenticação obrigatória: o usuário é sempre o do token (nunca o informado no corpo)
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true);
    $usuario_id = (int) $user['id'];
$senha_atual = $input['senha_atual'] ?? '';
    $nova_senha = $input['nova_senha'] ?? '';
    
    if (empty($senha_atual) || empty($nova_senha)) {
        http_response_code(400);
        echo json_encode(['error' => 'Senha atual e nova senha são obrigatórias']);
        exit;
    }

    $erro_senha = SenhaService::validar((string) $nova_senha);
    if ($erro_senha !== null) {
        http_response_code(400);
        echo json_encode(['error' => $erro_senha]);
        exit;
    }

    try {
        // Buscar usuário
        $query = "SELECT senha FROM usuarios WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $usuario_id);
        $stmt->execute();
        $usuario = $stmt->fetch();
        
        if (!$usuario) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado']);
            exit;
        }
        
        // Verificar senha atual
        if (!password_verify($senha_atual, $usuario['senha']) && $usuario['senha'] !== $senha_atual) {
            http_response_code(400);
            echo json_encode(['error' => 'Senha atual incorreta']);
            exit;
        }
        
        // Atualizar senha
        $nova_senha_hash = SenhaService::hash((string) $nova_senha);
$update_query = "UPDATE usuarios SET senha = :senha WHERE id = :id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':senha', $nova_senha_hash);
        $update_stmt->bindParam(':id', $usuario_id);
        $update_stmt->execute();
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao alterar senha']);
    }
}
?>