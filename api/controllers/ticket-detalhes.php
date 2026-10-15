<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/timeline-service.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// GET - Detalhes completos do ticket
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();
    $ticket_id = $_GET['id'] ?? null;
    
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da demanda é obrigatório']);
        exit;
    }
    
    try {
        // Isolamento por setor/papel (404/403)
        AcessoService::exigirVerTicket($db, $user, $ticket_id);

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
            echo json_encode(['error' => 'Demanda não encontrada']);
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
        
        // Linha do tempo cronológica completa (comentários + histórico + abertura)
        $dados_timeline = TimelineService::montar($db, (int) $ticket_id);

        // Montar resposta
        $response = [
            'ticket' => $ticket,
            'comentarios' => $comentarios,
            'anexos' => $anexos,
            'historico' => $dados_timeline['historico'],
            'timeline' => $dados_timeline['timeline']
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar detalhes da demanda']);
    }
}
?>