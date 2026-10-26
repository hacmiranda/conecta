<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET', 'PUT', 'DELETE']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
$usuario_id = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

// Alterações exigem o token CSRF, como as demais APIs
if (in_array($method, ['PUT', 'DELETE'], true)) {
    AuthMiddleware::verificarCSRF();
}

try {
    $conn = (new Database())->getConnection();

    if ($method === 'GET') {
        // Notificações do usuário (as 50 mais recentes) e quantas não foram lidas
        $stmt = $conn->prepare("SELECT * FROM notificacoes WHERE usuario_id = ? ORDER BY criado_em DESC LIMIT 50");
        $stmt->execute([$usuario_id]);
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtCount = $conn->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0");
        $stmtCount->execute([$usuario_id]);

        echo json_encode([
            'notificacoes' => $notificacoes,
            'nao_lidas' => (int) $stmtCount->fetchColumn(),
        ]);
    } elseif ($method === 'PUT') {
        // Marcar uma (?id=) ou todas como lidas
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Notificação inválida']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$id, $usuario_id]);
        } else {
            $stmt = $conn->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ?");
            $stmt->execute([$usuario_id]);
        }
        echo json_encode(['success' => true]);
    } elseif ($method === 'DELETE') {
        // Excluir todas as notificações do usuário
        $stmt = $conn->prepare("DELETE FROM notificacoes WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        echo json_encode(['success' => true]);
    } elseif ($method === 'POST') {
        // Notificações são criadas só pelo backend (tickets, comentários, ações de fluxo, lembretes)
        http_response_code(403);
        echo json_encode(['error' => 'Notificações devem ser criadas via triggers de Demandas ou Comentários internamente']);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido']);
    }
} catch (Exception $e) {
    error_log('[NOTIFICACOES] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar notificações']);
}
