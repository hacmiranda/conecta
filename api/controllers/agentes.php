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

// GET - Listar agentes
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $query = "SELECT id, nome, email FROM usuarios WHERE tipo IN ('admin', 'agente') AND ativo = 1 ORDER BY nome";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $agentes = $stmt->fetchAll();
        
        echo json_encode($agentes);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar agentes']);
    }
}
?>