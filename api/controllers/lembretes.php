<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

// Verificar autenticação
$user = AuthMiddleware::verificarAuth();

try {
    if ($method === 'GET') {
        // Listar lembretes de um ticket ou todos os lembretes pendentes
        if (isset($_GET['ticket_id'])) {
            $ticket_id = $_GET['ticket_id'];
            
            $query = "SELECT l.*, u.nome as agente_nome, c.nome as criado_por_nome
                     FROM lembretes l
                     LEFT JOIN usuarios u ON l.agente_id = u.id
                     LEFT JOIN usuarios c ON l.criado_por = c.id
                     WHERE l.ticket_id = :ticket_id
                     ORDER BY l.data_lembrete ASC, l.hora_lembrete ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            // Listar lembretes pendentes (não notificados)
            $query = "SELECT l.*, t.titulo as ticket_titulo, u.nome as agente_nome
                     FROM lembretes l
                     INNER JOIN tickets t ON l.ticket_id = t.id
                     LEFT JOIN usuarios u ON l.agente_id = u.id
                     WHERE l.notificado = FALSE
                     AND CONCAT(l.data_lembrete, ' ', l.hora_lembrete) >= NOW()
                     ORDER BY l.data_lembrete ASC, l.hora_lembrete ASC";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    } elseif ($method === 'POST') {
        // Criar novo lembrete
        $data = json_decode(file_get_contents('php://input'), true);
        
        $query = "INSERT INTO lembretes (ticket_id, data_lembrete, hora_lembrete, descricao, agente_id, criado_por)
                 VALUES (:ticket_id, :data_lembrete, :hora_lembrete, :descricao, :agente_id, :criado_por)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ticket_id', $data['ticket_id']);
        $stmt->bindParam(':data_lembrete', $data['data_lembrete']);
        $stmt->bindParam(':hora_lembrete', $data['hora_lembrete']);
        $stmt->bindParam(':descricao', $data['descricao']);
        $stmt->bindParam(':agente_id', $data['agente_id']);
        $stmt->bindParam(':criado_por', $user['id']);
        
        if ($stmt->execute()) {
            $lembrete_id = $db->lastInsertId();
            
            // Buscar lembrete criado
            $query = "SELECT l.*, u.nome as agente_nome, c.nome as criado_por_nome
                     FROM lembretes l
                     LEFT JOIN usuarios u ON l.agente_id = u.id
                     LEFT JOIN usuarios c ON l.criado_por = c.id
                     WHERE l.id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $lembrete_id);
            $stmt->execute();
            
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar lembrete']);
        }
    } elseif ($method === 'DELETE') {
        // Excluir lembrete
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID do lembrete não fornecido']);
            exit;
        }
        
        $query = "DELETE FROM lembretes WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao excluir lembrete']);
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
