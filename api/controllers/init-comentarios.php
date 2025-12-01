<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Criar tabela comentarios_nao_lidos
    $create_table = "CREATE TABLE IF NOT EXISTS comentarios_nao_lidos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_ticket (ticket_id),
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    )";
    
    $db->exec($create_table);
    
    echo json_encode(['success' => true, 'message' => 'Tabela comentarios_nao_lidos criada']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao criar tabela: ' . $e->getMessage()]);
}
?>