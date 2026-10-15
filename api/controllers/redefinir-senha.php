<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['token'] ?? '';
    $senha = $data['senha'] ?? '';
    
    if (empty($token) || empty($senha)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token e senha são obrigatórios']);
        exit;
    }
    
    try {
        // Verificar se o token existe e é válido
        $query = "SELECT * FROM tokens_recuperacao WHERE token = :token AND usado = 0 AND expira_em > NOW()";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            http_response_code(400);
            echo json_encode(['error' => 'Token inválido ou expirado']);
            exit;
        }
        
        // Atualizar senha do usuário
        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
        $update_query = "UPDATE usuarios SET senha = :senha WHERE id = :id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':senha', $senhaHash);
        $update_stmt->bindParam(':id', $tokenData['usuario_id']);
        $update_stmt->execute();
        
        // Marcar token como usado
        $mark_query = "UPDATE tokens_recuperacao SET usado = 1 WHERE id = :id";
        $mark_stmt = $db->prepare($mark_query);
        $mark_stmt->bindParam(':id', $tokenData['id']);
        $mark_stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Senha redefinida com sucesso!']);
        
    } catch (Exception $e) {
        http_response_code(500);
        error_log('[REDEFINIR-SENHA] Erro: ' . $e->getMessage());
        echo json_encode(['error' => 'Erro ao redefinir senha']);
    }
}
?>
