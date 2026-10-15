<?php
/**
 * Redefinição de senha pelo link do "Esqueci a senha".
 * Aplica as mesmas regras de senha do sistema e encerra todas as sessões abertas do usuário.
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

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$token = (string) ($data['token'] ?? '');
$senha = (string) ($data['senha'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($token === '' || $senha === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token e senha são obrigatórios']);
    exit;
}

$limite = RateLimitService::verificar($ip, 'redefinir_senha', 10, 900);
if ($limite['bloqueado']) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitas tentativas. Tente novamente mais tarde.']);
    exit;
}

$erro_senha = SenhaService::validar($senha);
if ($erro_senha !== null) {
    http_response_code(400);
    echo json_encode(['error' => $erro_senha]);
    exit;
}

try {
    $db = (new Database())->getConnection();

    $stmt = $db->prepare("SELECT t.id, t.usuario_id FROM tokens_recuperacao t
                          INNER JOIN usuarios u ON u.id = t.usuario_id
                          WHERE t.token = ? AND t.usado = 0 AND t.expira_em > NOW()
                            AND u.ativo = 1 AND u.tipo IN ('operador', 'gestor', 'admin')");
    $stmt->execute([hash('sha256', $token)]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenData) {
        RateLimitService::registrar($ip, 'redefinir_senha');
        http_response_code(400);
        echo json_encode(['error' => 'Link inválido ou expirado. Peça um novo em "Esqueci a senha".']);
        exit;
    }

    $usuarioId = (int) $tokenData['usuario_id'];
    $db->beginTransaction();
    $db->prepare("UPDATE usuarios SET senha = ?, trocar_senha = 0 WHERE id = ?")
       ->execute([SenhaService::hash($senha), $usuarioId]);
    $db->prepare("DELETE FROM tokens_recuperacao WHERE usuario_id = ?")->execute([$usuarioId]);
    AuthMiddleware::encerrarTodasAsSessoes($db, $usuarioId);
    $db->commit();

    (new AuditoriaService($db))->registrar($usuarioId, 'redefinir_senha', 'usuarios', $usuarioId, null, null);

    echo json_encode(['success' => true, 'message' => 'Senha redefinida com sucesso!']);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[REDEFINIR-SENHA] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao redefinir senha']);
}
