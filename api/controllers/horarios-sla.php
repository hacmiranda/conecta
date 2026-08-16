<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// GET - Buscar horários úteis
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['feriados'])) {
    try {
        $query = "SELECT * FROM horarios_uteis ORDER BY dia_semana";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($horarios);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar horários']);
    }
}

// GET - Buscar feriados
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['feriados'])) {
    try {
        $query = "SELECT * FROM feriados ORDER BY data DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $feriados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($feriados);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar feriados']);
    }
}

// PUT - Atualizar horário útil
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && !isset($_GET['feriado'])) {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        $query = "UPDATE horarios_uteis SET hora_inicio = :hora_inicio, hora_fim = :hora_fim, ativo = :ativo WHERE dia_semana = :dia_semana";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':hora_inicio', $input['hora_inicio']);
        $stmt->bindParam(':hora_fim', $input['hora_fim']);
        $stmt->bindParam(':ativo', $input['ativo'], PDO::PARAM_BOOL);
        $stmt->bindParam(':dia_semana', $input['dia_semana']);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar horário']);
    }
}

// POST - Adicionar feriado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        $query = "INSERT INTO feriados (data, descricao, recorrente) VALUES (:data, :descricao, :recorrente)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':data', $input['data']);
        $stmt->bindParam(':descricao', $input['descricao']);
        $stmt->bindParam(':recorrente', $input['recorrente'], PDO::PARAM_BOOL);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao adicionar feriado']);
    }
}

// DELETE - Remover feriado
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID não fornecido']);
        exit;
    }
    
    try {
        $query = "DELETE FROM feriados WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao remover feriado']);
    }
}
?>
