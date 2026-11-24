<?php
/**
 * SLA por setor: interruptor, prazos por prioridade e modelo "Padrão CRPRS".
 *   GET  ?setor_id=                     leitura (admin, visão total, coordenador e gestor do setor)
 *   PUT  ?setor_id=                     {sla_ativo, prioridades: [...]} (admin ou coordenador do setor)
 *   POST ?acao=aplicar-modelo&setor_id= copia o catálogo para o setor (só admin; não liga o setor)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/sla-setor-service.php';

AuthMiddleware::configurarCORS(['GET', 'PUT', 'POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarLeituraGestao($user);

$metodo = $_SERVER['REQUEST_METHOD'];
if (!in_array($metodo, ['GET', 'PUT', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$setorId = isset($_GET['setor_id']) ? (int) $_GET['setor_id'] : 0;
if ($setorId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'setor_id é obrigatório']);
    exit;
}
if (!AuthMiddleware::mesmoSetor($user, $setorId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Você não tem acesso a este setor']);
    exit;
}
if ($metodo !== 'GET') {
    AuthMiddleware::verificarCSRF();
}

/** Resposta completa: o formato do serviço mais o que depende de quem pede. */
function respostaSla(array $dados, array $user, int $setorId): array {
    $dados['pode_alterar'] = AcessoService::podeConfigurarSlaSetor($user, $setorId);
    $dados['pode_aplicar_modelo'] = AcessoService::podeAplicarModeloSla($user);
    return $dados;
}

try {
    $servico = new SlaSetorService($db);

    if ($metodo === 'GET') {
        echo json_encode(respostaSla($servico->ler($setorId), $user, $setorId));
        exit;
    }

    if ($metodo === 'PUT') {
        if (!AcessoService::podeConfigurarSlaSetor($user, $setorId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Só o administrador ou o coordenador do setor altera o SLA']);
            exit;
        }
        $entrada = json_decode(file_get_contents('php://input'), true);
        if (!is_array($entrada)) {
            http_response_code(400);
            echo json_encode(['error' => 'Corpo inválido']);
            exit;
        }
        $acao = 'alterar_sla_setor';
        $db->beginTransaction();
        $r = $servico->salvar($setorId, $entrada);
    } else {
        if (($_GET['acao'] ?? '') !== 'aplicar-modelo') {
            http_response_code(400);
            echo json_encode(['error' => 'Ação desconhecida']);
            exit;
        }
        if (!AcessoService::podeAplicarModeloSla($user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Só o administrador aplica o modelo de SLA']);
            exit;
        }
        $acao = 'aplicar_modelo_sla_setor';
        $db->beginTransaction();
        $r = $servico->aplicarModelo($setorId);
    }

    // Auditoria só quando algo mudou de fato
    if ($r['antes'] != $r['depois']) {
        (new AuditoriaService($db))->registrar(
            $user['id'], $acao, 'setores', $setorId,
            json_encode($r['antes']), json_encode($r['depois'])
        );
    }
    $db->commit();
    echo json_encode(respostaSla($r['depois'], $user, $setorId));
} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[SETOR-SLA] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar o SLA do setor']);
}
