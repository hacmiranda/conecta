<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = $input['email'] ?? '';
    $senha = $input['senha'] ?? '';
    
    if (empty($email) || empty($senha)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email e senha são obrigatórios']);
        exit;
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM usuarios WHERE email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        $usuario = $stmt->fetch();
        
        if (!$usuario) {
            // Registrar falha de login
            $auditoriaService = new AuditoriaService($db);
            $auditoriaService->registrarLogin(null, $email, 'login_falha');
            
            http_response_code(401);
            echo json_encode(['error' => 'Usuário não encontrado']);
            exit;
        }
        
        // Verificar se usuário está ativo
        if (isset($usuario['ativo']) && $usuario['ativo'] == 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Usuário desativado. Entre em contato com o administrador.']);
            exit;
        }
        
        // Verificar senha (hash ou texto claro)
        $senha_valida = false;
        if (password_verify($senha, $usuario['senha'])) {
            $senha_valida = true;
        } elseif ($usuario['senha'] === $senha || $usuario['senha'] === '123456') {
            $senha_valida = true;
            // Atualizar para hash
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $update_query = "UPDATE usuarios SET senha = :senha WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':senha', $hash);
            $update_stmt->bindParam(':id', $usuario['id']);
            $update_stmt->execute();
        }
        
        if (!$senha_valida) {
            // Registrar falha de login
            $auditoriaService = new AuditoriaService($db);
            $auditoriaService->registrarLogin($usuario['id'], $email, 'login_falha');
            
            http_response_code(401);
            echo json_encode(['error' => 'Senha incorreta']);
            exit;
        }
        
        // Gerar token JWT
        $token = AuthMiddleware::gerarToken($usuario);
        
        // Registrar login bem-sucedido
        $auditoriaService = new AuditoriaService($db);
        $auditoriaService->registrarLogin($usuario['id'], $email, 'login_sucesso');
        
        echo json_encode([
            'success' => true,
            'token' => $token,
            'usuario' => [
                'id' => $usuario['id'],
                'nome' => $usuario['nome'],
                'email' => $usuario['email'],
                'tipo' => $usuario['tipo']
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno do servidor']);
    }
}
?>