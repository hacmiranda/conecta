<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/acesso-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

/**
 * Setor em que a escrita acontece: gestor sempre no próprio setor; admin no setor informado
 * (corpo ou ?setor_id). Encerra com 400 quando o admin não indicou nenhum.
 */
function setorDeEscritaMacro(array $user, array $input): int {
    if (($user['tipo'] ?? '') !== 'admin') {
        return (int) ($user['setor_id'] ?? 0);
    }
    $pedido = $input['setor_id'] ?? ($_GET['setor_id'] ?? null);
    if ($pedido === null || $pedido === '' || !is_numeric($pedido)) {
        http_response_code(400);
        echo json_encode(['error' => 'Selecione um setor no cabeçalho para gerenciar as categorias']);
        exit;
    }
    return (int) $pedido;
}

/**
 * Carrega a categoria e exige que o usuário possa geri-la (admin ou gestor do mesmo setor).
 */
function macrocategoriaGerenciavel(PDO $db, array $user, $macrocategoria_id): array {
    $stmt = $db->prepare("SELECT id, nome, setor_id FROM macrocategorias WHERE id = ?");
    $stmt->execute([(int) $macrocategoria_id]);
    $macro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$macro) {
        http_response_code(404);
        echo json_encode(['error' => 'Categoria não encontrada']);
        exit;
    }
    if (!AuthMiddleware::mesmoSetor($user, (int) $macro['setor_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode gerenciar as categorias do seu setor']);
        exit;
    }
    return $macro;
}

// GET - Listar categorias do setor (gestor/operador: o próprio; admin: ?setor_id ou todos)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();
    try {
        $setor_id = AcessoService::setorEscopo($user);

        if ($setor_id !== null) {
            $stmt = $db->prepare("SELECT * FROM macrocategorias WHERE setor_id = :setor_id ORDER BY nome");
            $stmt->execute([':setor_id' => $setor_id]);
        } else {
            $stmt = $db->prepare("SELECT m.*, s.nome as setor_nome FROM macrocategorias m LEFT JOIN setores s ON m.setor_id = s.id ORDER BY m.nome");
            $stmt->execute();
        }
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        error_log('[MACROCATEGORIAS GET] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar categorias']);
    }
}

// POST - Criar categoria no setor
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nome = trim($input['nome'] ?? '');

    if ($nome === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }

    $setor_id = setorDeEscritaMacro($user, $input);

    try {
        $check_stmt = $db->prepare("SELECT id FROM macrocategorias WHERE nome = :nome AND setor_id = :setor_id");
        $check_stmt->execute([':nome' => $nome, ':setor_id' => $setor_id]);
        if ($check_stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Categoria já existe neste setor']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO macrocategorias (nome, setor_id) VALUES (:nome, :setor_id)");
        $stmt->execute([':nome' => $nome, ':setor_id' => $setor_id]);
        $macrocategoria_id = $db->lastInsertId();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_macrocategoria', 'macrocategorias', $macrocategoria_id, null, json_encode(['nome' => $nome, 'setor_id' => $setor_id]));

        echo json_encode(['id' => (int) $macrocategoria_id, 'nome' => $nome, 'setor_id' => $setor_id]);
    } catch (Exception $e) {
        error_log('[MACROCATEGORIAS POST] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar categoria']);
    }
}

// PUT - Atualizar categoria
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $macrocategoria_id = end($path_parts);

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nome = trim($input['nome'] ?? '');

    if ($nome === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }

    $macro = macrocategoriaGerenciavel($db, $user, $macrocategoria_id);

    try {
        $dup = $db->prepare("SELECT id FROM macrocategorias WHERE nome = :nome AND setor_id = :setor_id AND id <> :id");
        $dup->execute([':nome' => $nome, ':setor_id' => $macro['setor_id'], ':id' => $macro['id']]);
        if ($dup->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Já existe uma categoria com este nome no setor']);
            exit;
        }

        $stmt = $db->prepare("UPDATE macrocategorias SET nome = :nome WHERE id = :id");
        $stmt->execute([':nome' => $nome, ':id' => $macro['id']]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_macrocategoria', 'macrocategorias', $macro['id'], json_encode(['nome' => $macro['nome']]), json_encode(['nome' => $nome]));

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('[MACROCATEGORIAS PUT] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar categoria']);
    }
}

// DELETE - Excluir categoria
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $macrocategoria_id = end($path_parts);

    $macro = macrocategoriaGerenciavel($db, $user, $macrocategoria_id);

    try {
        // Bloquear exclusão se houver subcategorias vinculadas
        $check_stmt = $db->prepare("SELECT COUNT(*) as total FROM categorias WHERE macrocategoria_id = :id");
        $check_stmt->execute([':id' => $macro['id']]);
        if ($check_stmt->fetch()['total'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Categoria não pode ser excluída pois possui subcategorias vinculadas']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM macrocategorias WHERE id = :id");
        $stmt->execute([':id' => $macro['id']]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'excluir_macrocategoria', 'macrocategorias', $macro['id'], json_encode(['nome' => $macro['nome']]), null);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('[MACROCATEGORIAS DELETE] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir categoria']);
    }
}
