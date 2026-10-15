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
 * Id do setor "Geral" (expediente de referência quando o admin ainda não escolheu um setor).
 */
function setorGeralId(PDO $db): int {
    $stmt = $db->query("SELECT id FROM setores WHERE nome = 'Geral' LIMIT 1");
    return (int) $stmt->fetchColumn();
}

/**
 * Setor que a leitura deve considerar: o do usuário (gestor/operador), o escolhido pelo admin
 * ou, sem escolha, o setor Geral.
 */
function setorDeLeitura(PDO $db, array $user): int {
    $setor = AcessoService::setorEscopo($user);
    return $setor !== null ? $setor : setorGeralId($db);
}

/**
 * Setor em que a escrita acontece: gestor sempre no próprio setor; admin no setor informado
 * (corpo ou ?setor_id). Encerra com 400 se o admin não indicou nenhum.
 */
function setorDeEscritaHorario(array $user, array $input): int {
    if (($user['tipo'] ?? '') !== 'admin') {
        return (int) ($user['setor_id'] ?? 0);
    }
    $pedido = $input['setor_id'] ?? ($_GET['setor_id'] ?? null);
    if ($pedido === null || $pedido === '' || !is_numeric($pedido)) {
        http_response_code(400);
        echo json_encode(['error' => 'Selecione um setor no cabeçalho para alterar horários e feriados']);
        exit;
    }
    return (int) $pedido;
}

// GET - Mapa de expediente e feriados por setor (usado no cálculo de SLA exibido na tela)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['mapa'])) {
    $user = AuthMiddleware::verificarAuth();
    try {
        $filtro = '';
        $params = [];
        if (($user['tipo'] ?? '') !== 'admin') {
            $filtro = ' WHERE setor_id = ?';
            $params[] = (int) ($user['setor_id'] ?? 0);
        }

        $mapa = [];
        $stmt = $db->prepare("SELECT setor_id, dia_semana, hora_inicio, hora_fim, ativo FROM horarios_uteis{$filtro} ORDER BY dia_semana");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $mapa[$linha['setor_id']]['horarios'][] = $linha;
        }

        $stmt = $db->prepare("SELECT setor_id, `data`, descricao, recorrente FROM feriados{$filtro}");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $mapa[$linha['setor_id']]['feriados'][] = $linha;
        }

        foreach ($mapa as $setor => $dados) {
            $mapa[$setor] = [
                'horarios' => $dados['horarios'] ?? [],
                'feriados' => $dados['feriados'] ?? [],
            ];
        }

        echo json_encode($mapa);
    } catch (Exception $e) {
        error_log('[HORARIOS-SLA GET mapa] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar expediente dos setores']);
    }
    exit;
}

// GET - Buscar horários úteis do setor
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['feriados'])) {
    $user = AuthMiddleware::verificarAuth();
    try {
        $setor_id = setorDeLeitura($db, $user);

        $stmt = $db->prepare("SELECT * FROM horarios_uteis WHERE setor_id = ? ORDER BY dia_semana");
        $stmt->execute([$setor_id]);

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('[HORARIOS-SLA GET] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar horários']);
    }
}

// GET - Buscar feriados do setor
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['feriados'])) {
    $user = AuthMiddleware::verificarAuth();
    try {
        $setor_id = setorDeLeitura($db, $user);

        $stmt = $db->prepare("SELECT * FROM feriados WHERE setor_id = ? ORDER BY data DESC");
        $stmt->execute([$setor_id]);

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('[HORARIOS-SLA GET feriados] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar feriados']);
    }
}

// PUT - Atualizar horário útil do setor
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && !isset($_GET['feriado'])) {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $setor_id = setorDeEscritaHorario($user, $input);

    $dia_semana = (int) ($input['dia_semana'] ?? 0);
    $hora_inicio = $input['hora_inicio'] ?? '';
    $hora_fim = $input['hora_fim'] ?? '';
    $ativo = !empty($input['ativo']) ? 1 : 0;

    if ($dia_semana < 1 || $dia_semana > 7
        || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora_inicio)
        || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora_fim)) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados de horário inválidos']);
        exit;
    }
    if ($hora_fim <= $hora_inicio) {
        http_response_code(400);
        echo json_encode(['error' => 'O horário final deve ser maior que o inicial']);
        exit;
    }

    try {
        $anterior = $db->prepare("SELECT hora_inicio, hora_fim, ativo FROM horarios_uteis WHERE setor_id = ? AND dia_semana = ?");
        $anterior->execute([$setor_id, $dia_semana]);
        $dados_anteriores = $anterior->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo)
                              VALUES (:setor_id, :dia_semana, :hora_inicio, :hora_fim, :ativo)
                              ON DUPLICATE KEY UPDATE hora_inicio = VALUES(hora_inicio), hora_fim = VALUES(hora_fim), ativo = VALUES(ativo)");
        $stmt->execute([
            ':setor_id' => $setor_id,
            ':dia_semana' => $dia_semana,
            ':hora_inicio' => $hora_inicio,
            ':hora_fim' => $hora_fim,
            ':ativo' => $ativo,
        ]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_horario', 'horarios_uteis', $setor_id,
            $dados_anteriores ? json_encode($dados_anteriores) : null,
            json_encode(['setor_id' => $setor_id, 'dia_semana' => $dia_semana, 'hora_inicio' => $hora_inicio, 'hora_fim' => $hora_fim, 'ativo' => $ativo]));

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('[HORARIOS-SLA PUT] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar horário']);
    }
}

// POST - Adicionar feriado ao setor (admin pode aplicar a todos os setores)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $data = $input['data'] ?? '';
    $descricao = trim($input['descricao'] ?? '');
    $recorrente = !empty($input['recorrente']) ? 1 : 0;
    $todosSetores = ($user['tipo'] ?? '') === 'admin' && !empty($input['todos_setores']);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $descricao === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Data e descrição são obrigatórias']);
        exit;
    }

    try {
        if ($todosSetores) {
            $setores = $db->query("SELECT id FROM setores WHERE ativo = 1")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $setores = [setorDeEscritaHorario($user, $input)];
        }

        $stmt = $db->prepare("INSERT INTO feriados (setor_id, `data`, descricao, recorrente)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), recorrente = VALUES(recorrente)");
        $ultimo_id = null;
        foreach ($setores as $setor_id) {
            $stmt->execute([(int) $setor_id, $data, $descricao, $recorrente]);
            $ultimo_id = $db->lastInsertId();
        }

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_feriado', 'feriados', (int) $ultimo_id, null,
            json_encode(['data' => $data, 'descricao' => $descricao, 'recorrente' => $recorrente, 'setores' => array_map('intval', $setores)]));

        echo json_encode(['success' => true, 'id' => $ultimo_id]);
    } catch (Exception $e) {
        error_log('[HORARIOS-SLA POST] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao adicionar feriado']);
    }
}

// DELETE - Remover feriado do setor
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $id = $_GET['id'] ?? null;

    if (!$id || !is_numeric($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID não fornecido']);
        exit;
    }

    try {
        $busca = $db->prepare("SELECT id, setor_id, `data`, descricao, recorrente FROM feriados WHERE id = ?");
        $busca->execute([(int) $id]);
        $feriado = $busca->fetch(PDO::FETCH_ASSOC);

        if (!$feriado) {
            http_response_code(404);
            echo json_encode(['error' => 'Feriado não encontrado']);
            exit;
        }
        if (!AuthMiddleware::mesmoSetor($user, (int) $feriado['setor_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Você só pode alterar os feriados do seu setor']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM feriados WHERE id = ?");
        $stmt->execute([(int) $id]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'excluir_feriado', 'feriados', (int) $id, json_encode($feriado), null);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('[HORARIOS-SLA DELETE] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao remover feriado']);
    }
}
?>
