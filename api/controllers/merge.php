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

// POST - Mesclar tickets
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarSupervisor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true);
    $ticket_principal = $input['ticket_principal'] ?? null;
    $tickets_secundarios = $input['tickets_secundarios'] ?? [];
    
    if (!$ticket_principal || empty($tickets_secundarios)) {
        http_response_code(400);
        echo json_encode(['error' => 'Demanda principal e demandas secundárias são obrigatórias']);
        exit;
    }
    
    try {
        // Criar tabela histórico se não existir (fora da transação)
        $create_table = "CREATE TABLE IF NOT EXISTS historico (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            usuario_id INT NOT NULL,
            acao VARCHAR(50) NOT NULL,
            valor_anterior TEXT,
            valor_novo TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        )";
        $db->exec($create_table);
        
        $db->beginTransaction();
        
        // Buscar dados do ticket principal
        $query = "SELECT categoria_id FROM tickets WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $ticket_principal);
        $stmt->execute();
        $ticket_principal_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $categoria_principal = $ticket_principal_data['categoria_id'];
        
        // Buscar dados dos tickets secundários
        $tickets_secundarios_info = [];
        foreach ($tickets_secundarios as $ticket_id) {
            $query = "SELECT id, titulo, descricao, categoria_id FROM tickets WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $ticket_id);
            $stmt->execute();
            $tickets_secundarios_info[] = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Transferir comentários dos tickets secundários para o principal
        foreach ($tickets_secundarios as $ticket_id) {
            $query = "UPDATE comentarios SET ticket_id = :principal WHERE ticket_id = :secundario";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':principal', $ticket_principal);
            $stmt->bindParam(':secundario', $ticket_id);
            $stmt->execute();
        }
        
        // Transferir anexos dos tickets secundários para o principal
        foreach ($tickets_secundarios as $ticket_id) {
            $query = "UPDATE anexos SET ticket_id = :principal WHERE ticket_id = :secundario";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':principal', $ticket_principal);
            $stmt->bindParam(':secundario', $ticket_id);
            $stmt->execute();
        }
        
        // Adicionar ao histórico do ticket principal
        $tickets_ids = implode(', #', array_map(function($id) {
            return str_pad($id, 5, '0', STR_PAD_LEFT);
        }, $tickets_secundarios));
        
        $query = "INSERT INTO historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (:ticket_id, :usuario_id, 'merge', '', :valor_novo)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_principal);
        $stmt->bindParam(':usuario_id', $user['id']);
        $stmt->bindParam(':valor_novo', $tickets_ids);
        $stmt->execute();

        // Adicionar comentário detalhado de merge no ticket principal
        $comentario_merge = "🔗 **MESCLAGEM DE DEMANDAS**\n\n";
        $comentario_merge .= "Demandas mescladas: #" . $tickets_ids . "\n\n";
        foreach ($tickets_secundarios_info as $info) {
            $ticket_num = str_pad($info['id'], 5, '0', STR_PAD_LEFT);
            $comentario_merge .= "**Demanda #" . $ticket_num . "**\n";
            $comentario_merge .= "Assunto: " . $info['titulo'] . "\n";
            $comentario_merge .= "Descrição: " . $info['descricao'] . "\n\n";
        }

        $query = "INSERT INTO comentarios (ticket_id, usuario_id, comentario) VALUES (:ticket_id, :usuario_id, :comentario)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_principal);
        $stmt->bindParam(':usuario_id', $user['id']);
        $stmt->bindParam(':comentario', $comentario_merge);
        $stmt->execute();
        
        // Fechar tickets secundários
        foreach ($tickets_secundarios_info as $info) {
            $ticket_id = $info['id'];
            
            // Herdar categoria do ticket principal se não tiver categoria
            if (!$info['categoria_id'] && $categoria_principal) {
                $query = "UPDATE tickets SET categoria_id = :categoria WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':categoria', $categoria_principal);
                $stmt->bindParam(':id', $ticket_id);
                $stmt->execute();
            }
            
            // Fechar ticket com status 'encerrado'
            $query = "UPDATE tickets SET status = 'encerrado', fechado_em = NOW(), fechado_por = :fechado_por WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':fechado_por', $user['id']);
            $stmt->bindParam(':id', $ticket_id);
            $stmt->execute();

            // Adicionar ao histórico do ticket secundário
            $query = "INSERT INTO historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (:ticket_id, :usuario_id, 'merge', '', :valor_novo)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->bindParam(':usuario_id', $user['id']);
            $principal_formatted = str_pad($ticket_principal, 5, '0', STR_PAD_LEFT);
            $stmt->bindParam(':valor_novo', $principal_formatted);
            $stmt->execute();

            // Adicionar comentário detalhado nos tickets fechados
            $ticket_num = str_pad($ticket_id, 5, '0', STR_PAD_LEFT);
            $principal_num = str_pad($ticket_principal, 5, '0', STR_PAD_LEFT);
            $comentario_fechado = "🔗 **TICKET MESCLADO**\n\n";
            $comentario_fechado .= "Este ticket foi mesclado ao ticket principal #" . $principal_num;

            $query = "INSERT INTO comentarios (ticket_id, usuario_id, comentario) VALUES (:ticket_id, :usuario_id, :comentario)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->bindParam(':usuario_id', $user['id']);
            $stmt->bindParam(':comentario', $comentario_fechado);
            $stmt->execute();
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Demandas mescladas com sucesso',
            'ticket_principal' => $ticket_principal,
            'tickets_mesclados' => count($tickets_secundarios)
        ]);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao mesclar demandas: ' . $e->getMessage()]);
    }
}
?>