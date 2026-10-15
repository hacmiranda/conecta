<?php
/**
 * Painel de configuração por setor: ativar/desativar e reordenar quais itens do
 * catálogo global (categorias, status, prioridades) aparecem para um setor.
 * Não duplica nem edita o catálogo em si — só a visibilidade/ordem por setor.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET', 'PUT', 'POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// Categorias não entram aqui: pertencem a cada setor (categorias.setor_id) e são geridas em categorias.php
const TIPOS = [
    'status' => [
        'tabela_config' => 'setor_status',
        'coluna_item'   => 'status_id',
        'tabela_item'   => 'ticket_status',
        'coluna_nome'   => 'nome',
    ],
    'prioridades' => [
        'tabela_config' => 'setor_prioridades',
        'coluna_item'   => 'prioridade_id',
        'tabela_item'   => 'ticket_prioridades',
        'coluna_nome'   => 'nome',
    ],
];

/**
 * Verifica se o usuário pode gerenciar a configuração do setor informado
 * (admin sempre; gestor só o próprio setor) e encerra com 403 caso não.
 */
function autorizarSetor(array $user, int $setor_id): void {
    AuthMiddleware::verificarGestor($user);
    if (!AuthMiddleware::mesmoSetor($user, $setor_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode configurar o próprio setor']);
        exit;
    }
}

$user = AuthMiddleware::verificarAuth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tipo = $_GET['tipo'] ?? '';
    $setor_id = isset($_GET['setor_id']) ? (int) $_GET['setor_id'] : 0;

    if (!isset(TIPOS[$tipo]) || !$setor_id) {
        http_response_code(400);
        echo json_encode(['error' => 'tipo e setor_id são obrigatórios']);
        exit;
    }

    autorizarSetor($user, $setor_id);

    $cfg = TIPOS[$tipo];

    try {
        $query = "SELECT i.id, i.{$cfg['coluna_nome']} as nome, sc.ativo, sc.ordem
                  FROM {$cfg['tabela_item']} i
                  INNER JOIN {$cfg['tabela_config']} sc ON sc.{$cfg['coluna_item']} = i.id AND sc.setor_id = :setor_id
                  ORDER BY sc.ordem, i.{$cfg['coluna_nome']}";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':setor_id', $setor_id, PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar configuração do setor']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $tipo = $input['tipo'] ?? '';
    $setor_id = isset($input['setor_id']) ? (int) $input['setor_id'] : 0;
    $item_id = isset($input['item_id']) ? (int) $input['item_id'] : 0;

    if (!isset(TIPOS[$tipo]) || !$setor_id || !$item_id || !isset($input['ativo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'tipo, setor_id, item_id e ativo são obrigatórios']);
        exit;
    }

    autorizarSetor($user, $setor_id);

    $cfg = TIPOS[$tipo];
    $ativo = $input['ativo'] ? 1 : 0;

    try {
        $query = "UPDATE {$cfg['tabela_config']} SET ativo = :ativo WHERE setor_id = :setor_id AND {$cfg['coluna_item']} = :item_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':ativo' => $ativo, ':setor_id' => $setor_id, ':item_id' => $item_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar configuração']);
    }
    exit;
}

// POST — mover um item para cima/baixo na ordem do setor (troca a "ordem" com o vizinho)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $tipo = $input['tipo'] ?? '';
    $setor_id = isset($input['setor_id']) ? (int) $input['setor_id'] : 0;
    $item_id = isset($input['item_id']) ? (int) $input['item_id'] : 0;
    $direcao = $input['direcao'] ?? '';

    if (!isset(TIPOS[$tipo]) || !$setor_id || !$item_id || !in_array($direcao, ['up', 'down'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'tipo, setor_id, item_id e direcao (up/down) são obrigatórios']);
        exit;
    }

    autorizarSetor($user, $setor_id);

    $cfg = TIPOS[$tipo];

    try {
        $lista_stmt = $db->prepare("SELECT {$cfg['coluna_item']} as item_id, ordem FROM {$cfg['tabela_config']} WHERE setor_id = ? ORDER BY ordem, {$cfg['coluna_item']}");
        $lista_stmt->execute([$setor_id]);
        $lista = $lista_stmt->fetchAll(PDO::FETCH_ASSOC);

        $posicao = null;
        foreach ($lista as $i => $linha) {
            if ((int) $linha['item_id'] === $item_id) {
                $posicao = $i;
                break;
            }
        }

        if ($posicao === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Item não encontrado na configuração deste setor']);
            exit;
        }

        $vizinho_pos = $direcao === 'up' ? $posicao - 1 : $posicao + 1;
        if ($vizinho_pos < 0 || $vizinho_pos >= count($lista)) {
            echo json_encode(['success' => true]); // já está na ponta, nada a fazer
            exit;
        }

        $atual = $lista[$posicao];
        $vizinho = $lista[$vizinho_pos];

        $db->beginTransaction();
        $upd = $db->prepare("UPDATE {$cfg['tabela_config']} SET ordem = :ordem WHERE setor_id = :setor_id AND {$cfg['coluna_item']} = :item_id");
        $upd->execute([':ordem' => $vizinho['ordem'], ':setor_id' => $setor_id, ':item_id' => $atual['item_id']]);
        $upd->execute([':ordem' => $atual['ordem'], ':setor_id' => $setor_id, ':item_id' => $vizinho['item_id']]);
        $db->commit();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao reordenar']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
