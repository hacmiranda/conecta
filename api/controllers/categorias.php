<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';

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
function setorDeEscrita(array $user, array $input): int {
    if (($user['tipo'] ?? '') !== 'admin') {
        return (int) ($user['setor_id'] ?? 0);
    }
    $pedido = $input['setor_id'] ?? ($_GET['setor_id'] ?? null);
    if ($pedido === null || $pedido === '' || !is_numeric($pedido)) {
        http_response_code(400);
        echo json_encode(['error' => 'Selecione um setor no cabeçalho para gerenciar as subcategorias']);
        exit;
    }
    return (int) $pedido;
}

/**
 * Valida que a categoria pai (macrocategoria) existe e é do mesmo setor.
 */
function validarMacrocategoriaDoSetor(PDO $db, $macrocategoria_id, int $setor_id): void {
    if ($macrocategoria_id === null || $macrocategoria_id === '') {
        return;
    }
    $stmt = $db->prepare("SELECT 1 FROM macrocategorias WHERE id = ? AND setor_id = ?");
    $stmt->execute([(int) $macrocategoria_id, $setor_id]);
    if (!$stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'Categoria inválida para este setor']);
        exit;
    }
}

/**
 * Já existe subcategoria com este nome na mesma categoria do setor? (`<=>` trata "sem categoria"
 * como um grupo próprio — o índice único do banco não cobre o caso NULL.)
 */
function subcategoriaDuplicada(PDO $db, string $nome, int $setor_id, $macrocategoria_id, int $ignorar_id = 0): bool {
    $macro = ($macrocategoria_id === null || $macrocategoria_id === '') ? null : (int) $macrocategoria_id;
    $stmt = $db->prepare("SELECT 1 FROM categorias
                          WHERE nome = :nome AND setor_id = :setor_id AND macrocategoria_id <=> :macro AND id <> :id");
    $stmt->bindValue(':nome', $nome);
    $stmt->bindValue(':setor_id', $setor_id, PDO::PARAM_INT);
    $stmt->bindValue(':macro', $macro, $macro === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':id', $ignorar_id, PDO::PARAM_INT);
    $stmt->execute();
    return (bool) $stmt->fetchColumn();
}

/**
 * Carrega a subcategoria e exige que o usuário possa geri-la (admin ou gestor do mesmo setor).
 */
function categoriaGerenciavel(PDO $db, array $user, $categoria_id): array {
    $stmt = $db->prepare("SELECT id, nome, macrocategoria_id, setor_id FROM categorias WHERE id = ?");
    $stmt->execute([(int) $categoria_id]);
    $categoria = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$categoria) {
        http_response_code(404);
        echo json_encode(['error' => 'Subcategoria não encontrada']);
        exit;
    }
    if (!AuthMiddleware::mesmoSetor($user, (int) $categoria['setor_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode gerenciar as subcategorias do seu setor']);
        exit;
    }
    return $categoria;
}

// GET - Listar subcategorias do setor (gestor/operador: o próprio; admin: ?setor_id ou todos)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();
    try {
        $setor_id = AcessoService::setorEscopo($user);

        // rotulo = "Subcategoria - Categoria": o nome sozinho pode se repetir entre categorias
        $query = "SELECT c.*, m.nome as macrocategoria_nome, s.nome as setor_nome,
                         CONCAT_WS(' - ', c.nome, m.nome) as rotulo
                  FROM categorias c
                  LEFT JOIN macrocategorias m ON c.macrocategoria_id = m.id
                  LEFT JOIN setores s ON c.setor_id = s.id";
        $params = [];
        if ($setor_id !== null) {
            $query .= " WHERE c.setor_id = :setor_id";
            $params[':setor_id'] = $setor_id;
        }
        $query .= " ORDER BY m.nome, c.nome";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());

    } catch (Exception $e) {
        error_log('[CATEGORIAS GET] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar subcategorias']);
    }
}

// POST - Criar subcategoria no setor
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nome = HtmlSanitizer::texto($input['nome'] ?? '', 100);
    $macrocategoria_id = $input['macrocategoria_id'] ?? null;

    if ($nome === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }

    $setor_id = setorDeEscrita($user, $input);

    try {
        validarMacrocategoriaDoSetor($db, $macrocategoria_id, $setor_id);

        // Nome único dentro da categoria (o mesmo nome pode existir em outra categoria do setor)
        if (subcategoriaDuplicada($db, $nome, $setor_id, $macrocategoria_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Já existe uma subcategoria com este nome nesta categoria']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO categorias (nome, macrocategoria_id, setor_id) VALUES (:nome, :macrocategoria_id, :setor_id)");
        $stmt->bindValue(':nome', $nome);
        $stmt->bindValue(':macrocategoria_id', $macrocategoria_id === '' ? null : $macrocategoria_id);
        $stmt->bindValue(':setor_id', $setor_id, PDO::PARAM_INT);
        $stmt->execute();

        $categoria_id = $db->lastInsertId();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_categoria', 'categorias', $categoria_id, null, json_encode(['nome' => $nome, 'macrocategoria_id' => $macrocategoria_id, 'setor_id' => $setor_id]));

        $select_stmt = $db->prepare("SELECT c.*, m.nome as macrocategoria_nome,
                                            CONCAT_WS(' - ', c.nome, m.nome) as rotulo
                                     FROM categorias c
                                     LEFT JOIN macrocategorias m ON c.macrocategoria_id = m.id
                                     WHERE c.id = :id");
        $select_stmt->execute([':id' => $categoria_id]);
        echo json_encode($select_stmt->fetch());

    } catch (Exception $e) {
        error_log('[CATEGORIAS POST] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar subcategoria']);
    }
}

// PUT - Atualizar subcategoria
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $categoria_id = end($path_parts);

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nome = HtmlSanitizer::texto($input['nome'] ?? '', 100);
    $macrocategoria_id = $input['macrocategoria_id'] ?? null;

    if ($nome === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório']);
        exit;
    }

    $atual = categoriaGerenciavel($db, $user, $categoria_id);

    try {
        validarMacrocategoriaDoSetor($db, $macrocategoria_id, (int) $atual['setor_id']);

        if (subcategoriaDuplicada($db, $nome, (int) $atual['setor_id'], $macrocategoria_id, (int) $atual['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Já existe uma subcategoria com este nome nesta categoria']);
            exit;
        }

        $stmt = $db->prepare("UPDATE categorias SET nome = :nome, macrocategoria_id = :macrocategoria_id WHERE id = :id");
        $stmt->bindValue(':nome', $nome);
        $stmt->bindValue(':macrocategoria_id', $macrocategoria_id === '' ? null : $macrocategoria_id);
        $stmt->bindValue(':id', (int) $atual['id'], PDO::PARAM_INT);
        $stmt->execute();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_categoria', 'categorias', $atual['id'], json_encode(['nome' => $atual['nome'], 'macrocategoria_id' => $atual['macrocategoria_id']]), json_encode(['nome' => $nome, 'macrocategoria_id' => $macrocategoria_id]));

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        error_log('[CATEGORIAS PUT] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar subcategoria']);
    }
}

// DELETE - Excluir subcategoria
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $categoria_id = end($path_parts);

    $categoria_data = categoriaGerenciavel($db, $user, $categoria_id);

    try {
        // Em uso por alguma demanda?
        $check_stmt = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE categoria_id = :id");
        $check_stmt->execute([':id' => $categoria_data['id']]);
        if ($check_stmt->fetch()['total'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Subcategoria não pode ser excluída pois está sendo usada em demandas']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM categorias WHERE id = :id");
        $stmt->execute([':id' => $categoria_data['id']]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'excluir_categoria', 'categorias', $categoria_data['id'], json_encode(['nome' => $categoria_data['nome'], 'macrocategoria_id' => $categoria_data['macrocategoria_id']]), null);

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        error_log('[CATEGORIAS DELETE] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir subcategoria']);
    }
}
?>