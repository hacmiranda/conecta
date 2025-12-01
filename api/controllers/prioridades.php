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

// GET - Listar prioridades com SLA
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $query = "SELECT 
                    nome,
                    descricao,
                    sla_resposta_minutos,
                    sla_resolucao_horas,
                    cor,
                    icone,
                    ordem
                  FROM ticket_prioridades 
                  WHERE ativo = TRUE 
                  ORDER BY ordem";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $prioridades = $stmt->fetchAll();
        
        echo json_encode($prioridades);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar prioridades']);
    }
}
?>