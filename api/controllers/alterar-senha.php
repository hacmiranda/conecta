<?php
/**
 * Troca da própria senha (também usada na troca obrigatória do primeiro acesso).
 * Encerra todas as outras sessões do usuário e devolve um token novo para a sessão atual.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/senha-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/rate-limit-service.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Autenticação obrigatória: o usuário é sempre o do token (nunca o informado no corpo)
$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarCSRF();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$usuario_id = (int) $user['id'];
$senha_atual = (string) ($input['senha_atual'] ?? '');
$nova_senha = (string) ($input['nova_senha'] ?? '');

if ($senha_atual === '' || $nova_senha === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Senha atual e nova senha são obrigatórias']);
    exit;
}

$erro_senha = SenhaService::validar($nova_senha);
if ($erro_senha !== null) {
    http_response_code(400);
    echo json_encode(['error' => $erro_senha]);
    exit;
}

if (hash_equals($senha_atual, $nova_senha)) {
    http_response_code(400);
    echo json_encode(['error' => 'A nova senha precisa ser diferente da atual']);
    exit;
}

// Tentativas de senha atual errada também têm limite
$chave = 'troca_' . $usuario_id;
$limite = RateLimitService::verificar($chave, 'alterar_senha', 5, 900);
if ($limite['bloqueado']) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitas tentativas. Tente novamente em ' . (int) ceil($limite['segundos_restantes'] / 60) . ' minuto(s).']);
    exit;
}

try {
    $db = (new Database())->getConnection();

    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || empty($usuario['senha']) || !password_verify($senha_atual, (string) $usuario['senha'])) {
        RateLimitService::registrar($chave, 'alterar_senha');
        http_response_code(400);
        echo json_encode(['error' => 'Senha atual incorreta']);
        exit;
    }
    RateLimitService::limpar($chave, 'alterar_senha');

    $db->beginTransaction();
    $db->prepare("UPDATE usuarios SET senha = ?, trocar_senha = 0 WHERE id = ?")
       ->execute([SenhaService::hash($nova_senha), $usuario_id]);
    $db->prepare("DELETE FROM tokens_recuperacao WHERE usuario_id = ?")->execute([$usuario_id]);
    $usuario['token_versao'] = AuthMiddleware::encerrarTodasAsSessoes($db, $usuario_id);
    $db->commit();

    (new AuditoriaService($db))->registrar($usuario_id, 'alterar_senha', 'usuarios', $usuario_id, null, null);

    // Token novo para esta sessão (as outras foram encerradas)
    $token = AuthMiddleware::gerarToken($usuario);
    $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    echo json_encode(['success' => true, 'token' => $token, 'csrf_token' => $payload['csrf'] ?? '']);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[ALTERAR-SENHA] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao alterar senha']);
}
