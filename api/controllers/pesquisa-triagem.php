<?php
/**
 * Módulo Satisfação — triagem de uma avaliação: mudar o status (pendente, em tratativa, resolvido) e/ou a anotação.
 * Quem faz: admin, coordenador geral, supervisor (todos os setores) e coordenador (as avaliações dos setores dele).
 * Os perfis de consulta (coordenador geral e supervisor) não alteram mais nada no sistema; esta é a exceção
 * liberada em AuthMiddleware::ESCRITAS_LIBERADAS_SOMENTE_LEITURA.
 *
 *   PUT ?id=N   {status?, anotacao?}
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';
require_once __DIR__ . '/../services/pesquisa-painel-service.php';

AuthMiddleware::configurarCORS(['PUT']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarSatisfacao($user);
AuthMiddleware::verificarCSRF();

try {
    $db = (new Database())->getConnection();
    $id = (int) ($_GET['id'] ?? 0);
    $corpo = json_decode(file_get_contents('php://input'), true) ?? [];

    $avaliacao = $id > 0 ? PesquisaPainelService::setorDaAvaliacao($db, $id) : false;
    if (!$avaliacao) {
        http_response_code(404);
        echo json_encode(['error' => 'Avaliação não encontrada']);
        exit;
    }
    // O coordenador só trata as avaliações dos setores dele; a visão total, todas
    $meus = AcessoService::setoresDoUsuario($user);
    if ($meus !== null && ($avaliacao['setor_id'] === null || !in_array($avaliacao['setor_id'], $meus, true))) {
        http_response_code(403);
        echo json_encode(['error' => 'Esta avaliação não é de um setor seu']);
        exit;
    }

    $status = null;
    if (array_key_exists('status', $corpo)) {
        if (!in_array($corpo['status'], PesquisaPainelService::STATUS_AVALIACAO, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Situação inválida']);
            exit;
        }
        $status = $corpo['status'];
    }
    $anotacao = array_key_exists('anotacao', $corpo) ? HtmlSanitizer::texto((string) $corpo['anotacao'], 2000) : null;

    PesquisaPainelService::triar($db, $id, $status, $anotacao);
    (new AuditoriaService($db))->registrar($user['id'], 'triar_avaliacao', 'pesquisa_avaliacoes', $id, null,
        json_encode(['status' => $status, 'anotacao_alterada' => $anotacao !== null], JSON_UNESCAPED_UNICODE));
    echo json_encode(['success' => true, 'status' => $status]);
} catch (Throwable $e) {
    error_log('[PESQUISA TRIAGEM] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar a triagem']);
}
