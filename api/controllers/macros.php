<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../services/html-sanitizer.php';

    // Auth check
    $user = AuthMiddleware::verificarAuth();
    $usuario_id = $user['id'];
    $is_admin = $user['tipo'] === 'admin';

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

// GET: List macros
if ($method === 'GET') {
    try {
        // Can list global macros + user macros if desired. For now, all macros exist for all agents
        $query = "SELECT m.*, u.nome as autor FROM macros m LEFT JOIN usuarios u ON m.usuario_id = u.id ORDER BY titulo ASC";
        $stmt = $db->query($query);
        $macros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($macros);
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao listar macros']);
    }
    exit;
}

// POST: Add new macro
if ($method === 'POST') {
    AuthMiddleware::verificarCSRF();
    $data = json_decode(file_get_contents("php://input"));
    if ($data) {
        $data->titulo = HtmlSanitizer::texto($data->titulo ?? '', 255);
        $data->texto = HtmlSanitizer::rico($data->texto ?? '');
    }
    if(!empty($data->titulo) && !empty($data->texto)) {
        try {
            $query = "INSERT INTO macros(titulo, texto, usuario_id) VALUES (:titulo, :texto, :usuario_id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':titulo', $data->titulo);
            $stmt->bindParam(':texto', $data->texto);
            $stmt->bindParam(':usuario_id', $usuario_id);
            if ($stmt->execute()) {
                echo json_encode(['message' => 'Macro criada com sucesso', 'id' => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao criar macro']);
            }
        } catch(Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar macro']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Dados incompletos']);
    }
    exit;
}

// PUT / DELETE need ID from URL like ?id=1
if ($method === 'PUT') {
    AuthMiddleware::verificarCSRF();
    $id = isset($_GET['id']) ? $_GET['id'] : die();
    $data = json_decode(file_get_contents("php://input"));
    
    // Check permission - admin or owner
    $check_query = "SELECT usuario_id FROM macros WHERE id = :id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':id', $id);
    $check_stmt->execute();
    $macro = $check_stmt->fetch(PDO::FETCH_ASSOC);
    if(!$macro) {
        http_response_code(404);
        echo json_encode(['error' => 'Macro não encontrada']);
        exit;
    }
    if(!$is_admin && $macro['usuario_id'] != $usuario_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão para editar esta macro']);
        exit;
    }

    if ($data) {
        $data->titulo = HtmlSanitizer::texto($data->titulo ?? '', 255);
        $data->texto = HtmlSanitizer::rico($data->texto ?? '');
    }
    if(!empty($data->titulo) && !empty($data->texto)) {
        try {
            $query = "UPDATE macrosSET titulo = :titulo, texto = :texto WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':titulo', $data->titulo);
            $stmt->bindParam(':texto', $data->texto);
            $stmt->bindParam(':id', $id);
            if ($stmt->execute()) {
                echo json_encode(['message' => 'Macro atualizada com sucesso']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao atualizar macro']);
            }
        } catch(Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao atualizar macro']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Dados incompletos']);
    }
    exit;
}

if ($method === 'DELETE') {
    AuthMiddleware::verificarCSRF();
    $id = isset($_GET['id']) ? $_GET['id'] : die();
    // Check permission - admin or owner
    $check_query = "SELECT usuario_id FROM macros WHERE id = :id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':id', $id);
    $check_stmt->execute();
    $macro = $check_stmt->fetch(PDO::FETCH_ASSOC);
    if(!$macro) {
        http_response_code(404);
        echo json_encode(['error' => 'Macro não encontrada']);
        exit;
    }
    if(!$is_admin && $macro['usuario_id'] != $usuario_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão para deletar esta macro']);
        exit;
    }

    try {
        $query = "DELETE FROM macros WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        if ($stmt->execute()) {
            echo json_encode(['message' => 'Macro excluída com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao excluir macro']);
        }
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir macro']);
    }
    exit;
}
?>
