<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// POST - Upload de avatar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user = AuthMiddleware::verificarAuth();
        AuthMiddleware::verificarCSRF();

        $userId = (int) ($_POST['user_id'] ?? 0);
        
        // Verificar permissão: admin ou próprio usuário
        if ($user['tipo'] !== 'admin' && $userId != $user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            exit;
        }
        
        if (!isset($_FILES['avatar'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nenhum arquivo enviado']);
            exit;
        }
        
        $file = $_FILES['avatar'];
        
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Falha no envio do arquivo']);
            exit;
        }

        // Validar tipo pelo conteúdo; a extensão gravada vem do tipo real (nunca do nome enviado)
        $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!isset($allowedTypes[$mimeType]) || @getimagesize($file['tmp_name']) === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Tipo de arquivo inválido. Use JPG, PNG ou GIF']);
            exit;
        }
        
        // Validar tamanho (5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'Arquivo muito grande. Máximo 5MB']);
            exit;
        }
        
        // Gerar nome único
        $extension = $allowedTypes[$mimeType];
        $filename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $uploadDir = __DIR__ . '/../../backend/uploads/avatars/';

        // Criar diretório se não existir
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                error_log("[AVATAR] Falha ao criar diretório: $uploadDir");
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao preparar diretório de upload']);
                exit;
            }
        }

        $uploadPath = $uploadDir . $filename;
        
        // Buscar avatar antigo
        $select_query = "SELECT avatar FROM usuarios WHERE id = ?";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->execute([$userId]);
        $userData = $select_stmt->fetch();
        
        // Remover avatar antigo
        if (!empty($userData['avatar'])) {
            $oldFile = __DIR__ . '/../../backend/uploads/avatars/' . basename($userData['avatar']);
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }
        
        // Upload
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao fazer upload do arquivo']);
            exit;
        }
        
        // Atualizar banco
        $update_query = "UPDATE usuarios SET avatar = ? WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([$filename, $userId]);
        
        // Registrar auditoria
        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_avatar', 'usuarios', $userId, 
            json_encode(['avatar_antigo' => $userData['avatar']]), 
            json_encode(['avatar_novo' => $filename]));
        
        echo json_encode([
            'success' => true,
            'avatar' => $filename
        ]);
        
    } catch (Exception $e) {
        error_log("[AVATAR] Erro: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao processar avatar']);
    }
}
?>
