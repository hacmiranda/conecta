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

// GET - Listar categorias (opcionalmente só as ativas de um setor, via ?setor_id=)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $setor_id = isset($_GET['setor_id']) && $_GET['setor_id'] !== '' ? (int) $_GET['setor_id'] : null;

        if ($setor_id) {
            $query = "SELECT c.*, m.nome as macrocategoria_nome
                      FROM categorias c
                      LEFT JOIN macrocategorias m ON c.macrocategoria_id = m.id
                      INNER JOIN setor_categorias sc ON sc.categoria_id = c.id AND sc.setor_id = :setor_id AND sc.ativo = 1
                      ORDER BY sc.ordem, c.nome";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':setor_id', $setor_id, PDO::PARAM_INT);
        } else {
            $query = "SELECT c.*, m.nome as macrocategoria_nome
                      FROM categorias c
                      LEFT JOIN macrocategorias m ON c.macrocategoria_id = m.id
                      ORDER BY m.nome, c.nome";
            $stmt = $db->prepare($query);
        }

        $stmt->execute();
        $categorias = $stmt->fetchAll();

        echo json_encode($categorias);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar subcategorias']);
    }
}

// POST - Criar categoria
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarSupervisor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true);
    $nome = $input['nome'] ?? '';
    $macrocategoria_id = $input['macrocategoria_id'] ?? null;
    
    if (empty($nome)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }
    
    try {
        // Verificar se categoria já existe
        $check_query = "SELECT id FROM categorias WHERE nome = :nome";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':nome', $nome);
        $check_stmt->execute();
        
        if ($check_stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Subcategoria já existe']);
            exit;
        }
        
        $query = "INSERT INTO categorias (nome, macrocategoria_id) VALUES (:nome, :macrocategoria_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':macrocategoria_id', $macrocategoria_id);
        $stmt->execute();
        
        $categoria_id = $db->lastInsertId();

        // Nova subcategoria começa ativa em todos os setores (mesmo comportamento de hoje: catálogo 100% compartilhado)
        $db->prepare("INSERT INTO setor_categorias (setor_id, categoria_id, ativo, ordem) SELECT id, ?, 1, ? FROM setores")->execute([$categoria_id, $categoria_id]);

        // Registrar auditoria
        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_categoria', 'categorias', $categoria_id, null, json_encode(['nome' => $nome, 'macrocategoria_id' => $macrocategoria_id]));
        
        // Buscar categoria criada
        $select_query = "SELECT c.*, m.nome as macrocategoria_nome 
                        FROM categorias c 
                        LEFT JOIN macrocategorias m ON c.macrocategoria_id = m.id 
                        WHERE c.id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $categoria_id);
        $select_stmt->execute();
        $categoria = $select_stmt->fetch();
        
        echo json_encode($categoria);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar subcategoria']);
    }
}

// PUT - Atualizar categoria
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarSupervisor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $categoria_id = end($path_parts);
    
    $input = json_decode(file_get_contents('php://input'), true);
    $nome = $input['nome'] ?? '';
    $macrocategoria_id = $input['macrocategoria_id'] ?? null;
    
    if (empty($nome)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }
    
    try {
        $query = "UPDATE categorias SET nome = :nome, macrocategoria_id = :macrocategoria_id WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':macrocategoria_id', $macrocategoria_id);
        $stmt->bindParam(':id', $categoria_id);
        $stmt->execute();
        
        // Registrar auditoria
        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_categoria', 'categorias', $categoria_id, null, json_encode(['nome' => $nome, 'macrocategoria_id' => $macrocategoria_id]));
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar subcategoria']);
    }
}

// DELETE - Excluir categoria
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarSupervisor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $categoria_id = end($path_parts);
    
    try {
        // Verificar se categoria está em uso
        $check_query = "SELECT COUNT(*) as total FROM tickets WHERE categoria_id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $categoria_id);
        $check_stmt->execute();
        $result = $check_stmt->fetch();
        
        if ($result['total'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Subcategoria não pode ser excluída pois está sendo usada em demandas']);
            exit;
        }
        
        // Buscar dados da categoria antes de excluir
        $select_query = "SELECT nome, macrocategoria_id FROM categorias WHERE id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $categoria_id);
        $select_stmt->execute();
        $categoria_data = $select_stmt->fetch();
        
        $query = "DELETE FROM categorias WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $categoria_id);
        $stmt->execute();
        
        // Registrar auditoria
        if ($categoria_data) {
            $auditoria = new AuditoriaService($db);
            $auditoria->registrar($user['id'], 'excluir_categoria', 'categorias', $categoria_id, json_encode($categoria_data), null);
        }
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir subcategoria']);
    }
}
?>