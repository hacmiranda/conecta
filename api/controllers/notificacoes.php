<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

try {
    $user = AuthMiddleware::verificarAuth();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();
$usuario_id = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Buscar notificações do usuário
    $stmt = $conn->prepare("SELECT * FROM notificacoes WHERE usuario_id = ? ORDER BY criado_em DESC LIMIT 50");
    $stmt->execute([$usuario_id]);
    $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Buscar quantidade de não lidas
    $stmtCount = $conn->prepare("SELECT COUNT(*) as nao_lidas FROM notificacoes WHERE usuario_id = ? AND lida = 0");
    $stmtCount->execute([$usuario_id]);
    $count = $stmtCount->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'notificacoes' => $notificacoes,
        'nao_lidas' => $count['nao_lidas']
    ]);
} elseif ($method === 'PUT') {
    // Marcar como lida
    if (isset($_GET['id'])) {
        $id = $_GET['id'];
        $stmt = $conn->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$id, $usuario_id]);
        echo json_encode(['success' => true]);
    } else {
        // Marcar todas como lidas
        $stmt = $conn->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        echo json_encode(['success' => true]);
    }
} elseif ($method === 'DELETE') {
    // Excluir todas as notificações do usuário
    $stmt = $conn->prepare("DELETE FROM notificacoes WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    echo json_encode(['success' => true]);
} elseif ($method === 'POST') {
    // Criar notificação (uso interno: a api também é chamada por XMLHttpRequest mas 
    // geralmente é o backend do comentário que deveria inserir no BD direto. 
    // Porém, para manter a lógica isolada caso queiramos testar, expomos um POST interno restrito 
    // O POST deve ser apenas se for admin ou o proprio sistema gerando. Para segurança, 
    // idealmente outros controllers fazem `INSERT INTO notificacoes`.)
    
    // Vamos processar a criação de Notificação via requisição interna se precisar, mas
    // o ticket e comentario podem inserir diretamente.
    http_response_code(403);
    echo json_encode(['error' => 'Notificações devem ser criadas via triggers de Demandas ou Comentários internamente']);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
}
