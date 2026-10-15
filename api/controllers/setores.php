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

// GET - Listar setores (qualquer usuário autenticado; admin também pode incluir inativos)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();

    try {
        $incluirInativos = ($user['tipo'] === 'admin') && isset($_GET['incluir_inativos']);
        $query = "SELECT id, nome, ativo, criado_em FROM setores"
            . ($incluirInativos ? "" : " WHERE ativo = 1")
            . " ORDER BY nome";
        $stmt = $db->prepare($query);
        $stmt->execute();
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar setores']);
    }
}

// POST - Criar setor (admin apenas)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true);
    $nome = trim($input['nome'] ?? '');

    if (empty($nome)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome do setor é obrigatório']);
        exit;
    }

    try {
        $check = $db->prepare("SELECT id FROM setores WHERE nome = :nome");
        $check->bindParam(':nome', $nome);
        $check->execute();
        if ($check->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Já existe um setor com esse nome']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO setores (nome, ativo) VALUES (:nome, 1)");
        $stmt->bindParam(':nome', $nome);
        $stmt->execute();
        $setor_id = $db->lastInsertId();

        // Novo setor começa com todos os status e prioridades ativos; as categorias são próprias do setor
        // Expediente e feriados recorrentes são herdados do setor Geral (cada setor edita os seus depois)
        $db->prepare("INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo)
                      SELECT ?, h.dia_semana, h.hora_inicio, h.hora_fim, h.ativo
                      FROM horarios_uteis h INNER JOIN setores g ON g.id = h.setor_id AND g.nome = 'Geral'")
           ->execute([$setor_id]);
        $db->prepare("INSERT INTO feriados (setor_id, `data`, descricao, recorrente)
                      SELECT ?, f.`data`, f.descricao, f.recorrente
                      FROM feriados f INNER JOIN setores g ON g.id = f.setor_id AND g.nome = 'Geral'
                      WHERE f.recorrente = 1")
           ->execute([$setor_id]);
$db->prepare("INSERT INTO setor_status (setor_id, status_id, ativo, ordem) SELECT ?, id, 1, ordem FROM ticket_status")->execute([$setor_id]);
        $db->prepare("INSERT INTO setor_prioridades (setor_id, prioridade_id, ativo, ordem) SELECT ?, id, 1, ordem FROM ticket_prioridades")->execute([$setor_id]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_setor', 'setores', $setor_id, null, json_encode(['nome' => $nome]));

        echo json_encode(['id' => (int) $setor_id, 'nome' => $nome, 'ativo' => 1]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar setor']);
    }
}

// PUT - Atualizar setor (admin edita tudo; gestor só o nome do próprio setor)
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user); // criar/renomear/ativar setores: exclusivo do administrador
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $setor_id = (int) end($path_parts);

    if (!AuthMiddleware::mesmoSetor($user, $setor_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode editar o próprio setor']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        // Ativar/Desativar — somente admin
        if (isset($input['ativo'])) {
            AuthMiddleware::verificarAdmin($user);
            $ativo = $input['ativo'] ? 1 : 0;
            $stmt = $db->prepare("UPDATE setores SET ativo = :ativo WHERE id = :id");
            $stmt->bindParam(':ativo', $ativo, PDO::PARAM_INT);
            $stmt->bindParam(':id', $setor_id, PDO::PARAM_INT);
            $stmt->execute();

            $auditoria = new AuditoriaService($db);
            $auditoria->registrar($user['id'], $ativo ? 'ativar_setor' : 'desativar_setor', 'setores', $setor_id, null, json_encode(['ativo' => $ativo]));

            echo json_encode(['success' => true]);
            exit;
        }

        $nome = trim($input['nome'] ?? '');
        if (empty($nome)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome do setor é obrigatório']);
            exit;
        }

        $stmt = $db->prepare("UPDATE setores SET nome = :nome WHERE id = :id");
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':id', $setor_id, PDO::PARAM_INT);
        $stmt->execute();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_setor', 'setores', $setor_id, null, json_encode(['nome' => $nome]));

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar setor']);
    }
}

// DELETE - Excluir setor (admin apenas; bloqueado se houver usuários ou tickets vinculados)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    AuthMiddleware::verificarCSRF();

    $setor_id = $_GET['id'] ?? null;
    if (!$setor_id || !is_numeric($setor_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID do setor inválido ou não fornecido']);
        exit;
    }
    $setor_id = (int) $setor_id;

    try {
        $vinculos = [
            ['tabela' => 'usuarios', 'coluna' => 'setor_id', 'msg' => 'usuários'],
            ['tabela' => 'tickets',  'coluna' => 'setor_id', 'msg' => 'demandas'],
        ];
        foreach ($vinculos as $v) {
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM {$v['tabela']} WHERE {$v['coluna']} = ?");
            $stmt->execute([$setor_id]);
            if ($stmt->fetch()['total'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => "Setor possui vínculos com: {$v['msg']}. Desative-o em vez de excluir."]);
                exit;
            }
        }

        $select = $db->prepare("SELECT nome FROM setores WHERE id = :id");
        $select->bindParam(':id', $setor_id, PDO::PARAM_INT);
        $select->execute();
        $setor_data = $select->fetch();

        $stmt = $db->prepare("DELETE FROM setores WHERE id = :id");
        $stmt->bindParam(':id', $setor_id, PDO::PARAM_INT);
        $stmt->execute();

        if ($setor_data) {
            $auditoria = new AuditoriaService($db);
            $auditoria->registrar($user['id'], 'excluir_setor', 'setores', $setor_id, json_encode($setor_data), null);
        }

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Setor não encontrado']);
            exit;
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir setor']);
    }
}
