<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// GET - Listar agentes (funcionários internos: operador/gestor/admin)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();

    try {
        if ($user['tipo'] === 'admin') {
            $query = "SELECT id, nome, email, setor_id FROM usuarios WHERE tipo IN ('admin', 'gestor', 'operador') AND ativo = 1 ORDER BY nome";
            $stmt = $db->prepare($query);
            $stmt->execute();
        } else {
            // Não-admin: só vê a equipe do próprio setor
            $query = "SELECT id, nome, email, setor_id FROM usuarios WHERE tipo IN ('gestor', 'operador') AND ativo = 1 AND setor_id = :setor_id ORDER BY nome";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':setor_id', $user['setor_id']);
            $stmt->execute();
        }
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar agentes']);
    }
}
