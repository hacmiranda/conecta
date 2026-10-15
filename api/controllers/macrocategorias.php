<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// GET - Listar macrocategorias
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $query = "SELECT * FROM macrocategorias ORDER BY nome";
        $stmt = $db->prepare($query);
        $stmt->execute();
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar categorias']);
    }
}

// POST - Criar macrocategoria
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true);
    $nome = trim($input['nome'] ?? '');

    if (empty($nome)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }

    try {
        $check_stmt = $db->prepare("SELECT id FROM macrocategorias WHERE nome = :nome");
        $check_stmt->bindParam(':nome', $nome);
        $check_stmt->execute();
        if ($check_stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Categoria já existe']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO macrocategorias (nome) VALUES (:nome)");
        $stmt->bindParam(':nome', $nome);
        $stmt->execute();
        $macrocategoria_id = $db->lastInsertId();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_macrocategoria', 'macrocategorias', $macrocategoria_id, null, json_encode(['nome' => $nome]));

        echo json_encode(['id' => (int) $macrocategoria_id, 'nome' => $nome]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar categoria']);
    }
}

// PUT - Atualizar macrocategoria
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $macrocategoria_id = end($path_parts);

    $input = json_decode(file_get_contents('php://input'), true);
    $nome = trim($input['nome'] ?? '');

    if (empty($nome)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }

    try {
        $select_stmt = $db->prepare("SELECT nome FROM macrocategorias WHERE id = :id");
        $select_stmt->bindParam(':id', $macrocategoria_id);
        $select_stmt->execute();
        $dados_anteriores = $select_stmt->fetch();

        $stmt = $db->prepare("UPDATE macrocategorias SET nome = :nome WHERE id = :id");
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':id', $macrocategoria_id);
        $stmt->execute();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_macrocategoria', 'macrocategorias', $macrocategoria_id, $dados_anteriores ? json_encode($dados_anteriores) : null, json_encode(['nome' => $nome]));

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar categoria']);
    }
}

// DELETE - Excluir macrocategoria
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $macrocategoria_id = end($path_parts);

    try {
        // Bloquear exclusão se houver categorias (subcategorias) vinculadas
        $check_stmt = $db->prepare("SELECT COUNT(*) as total FROM categorias WHERE macrocategoria_id = :id");
        $check_stmt->bindParam(':id', $macrocategoria_id);
        $check_stmt->execute();
        if ($check_stmt->fetch()['total'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Categoria não pode ser excluída pois possui subcategorias vinculadas']);
            exit;
        }

        $select_stmt = $db->prepare("SELECT nome FROM macrocategorias WHERE id = :id");
        $select_stmt->bindParam(':id', $macrocategoria_id);
        $select_stmt->execute();
        $macrocategoria_data = $select_stmt->fetch();

        $stmt = $db->prepare("DELETE FROM macrocategorias WHERE id = :id");
        $stmt->bindParam(':id', $macrocategoria_id);
        $stmt->execute();

        if ($macrocategoria_data) {
            $auditoria = new AuditoriaService($db);
            $auditoria->registrar($user['id'], 'excluir_macrocategoria', 'macrocategorias', $macrocategoria_id, json_encode($macrocategoria_data), null);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir categoria']);
    }
}
