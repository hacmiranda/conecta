<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

// GET - Detalhes completos do ticket
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ticket_id = $_GET['id'] ?? null;
    
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID do ticket é obrigatório']);
        exit;
    }
    
    try {
        // Buscar ticket
        $query = "SELECT t.*, 
                         u.nome as usuario_nome, u.email as usuario_email,
                         a.nome as agente_nome,
                         c.nome as categoria_nome,
                         f.nome as fechado_por_nome
                  FROM tickets t 
                  LEFT JOIN usuarios u ON t.usuario_id = u.id 
                  LEFT JOIN usuarios a ON t.agente_id = a.id 
                  LEFT JOIN categorias c ON t.categoria_id = c.id 
                  LEFT JOIN usuarios f ON t.fechado_por = f.id
                  WHERE t.id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $ticket_id);
        $stmt->execute();
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['error' => 'Ticket não encontrado']);
            exit;
        }
        
        // Buscar comentários
        $comentarios_query = "SELECT c.*, u.nome as usuario_nome 
                             FROM comentarios c 
                             LEFT JOIN usuarios u ON c.usuario_id = u.id 
                             WHERE c.ticket_id = :ticket_id 
                             ORDER BY c.criado_em ASC";
        
        $comentarios_stmt = $db->prepare($comentarios_query);
        $comentarios_stmt->bindParam(':ticket_id', $ticket_id);
        $comentarios_stmt->execute();
        $comentarios = $comentarios_stmt->fetchAll();
        
        // Buscar anexos
        $anexos_query = "SELECT * FROM anexos WHERE ticket_id = :ticket_id ORDER BY criado_em ASC";
        $anexos_stmt = $db->prepare($anexos_query);
        $anexos_stmt->bindParam(':ticket_id', $ticket_id);
        $anexos_stmt->execute();
        $anexos = $anexos_stmt->fetchAll();
        
        // Buscar histórico
        $historico_query = "SELECT h.*, u.nome as usuario_nome 
                           FROM ticket_historico h 
                           LEFT JOIN usuarios u ON h.usuario_id = u.id 
                           WHERE h.ticket_id = :ticket_id 
                           ORDER BY h.criado_em ASC";
        
        $historico_stmt = $db->prepare($historico_query);
        $historico_stmt->bindParam(':ticket_id', $ticket_id);
        $historico_stmt->execute();
        $historico = $historico_stmt->fetchAll();
        
        // Montar resposta
        $response = [
            'ticket' => $ticket,
            'comentarios' => $comentarios,
            'anexos' => $anexos,
            'historico' => $historico
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar detalhes do ticket']);
    }
}
?>