<?php
/**
 * Ponte de SSO — troca um token emitido pela identidade central do painel
 * agregador por um JWT interno do Conecta CRPRS (mesmo formato do login normal).
 *
 * Contrato esperado do token recebido (JWT HS256, assinado com SSO_SHARED_SECRET):
 *   - email  (string, obrigatório) — usado para localizar/provisionar o usuário local
 *   - nome   (string, obrigatório na primeira vez que o usuário aparece)
 *   - role   (string, opcional)    — mapeado para o enum local `tipo` (ver mapearTipo())
 *   - setor_id (int, opcional)     — setor local a vincular no primeiro acesso (fica NULL se omitido;
 *                                    um admin precisa atribuir um setor depois para papéis usuario/supervisor)
 *   - exp    (int, obrigatório)    — expiração padrão do JWT
 *
 * Após validar o token central, este endpoint cria o usuário local se ainda não existir
 * (find-or-create por email) e devolve a MESMA estrutura de resposta do login tradicional
 * (api/controllers/auth.php), para que o restante do app funcione sem nenhuma alteração.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

/**
 * Mapeia o papel (role) recebido da identidade central para o enum local `tipo`.
 */
function mapearTipo(?string $roleCentral): string {
    $role = strtolower(trim((string) $roleCentral));
    if ($role === 'admin' || $role === 'administrador') {
        return 'admin';
    }
    if ($role === 'supervisor') {
        return 'supervisor';
    }
    if ($role === 'agente' || $role === 'agent' || $role === 'usuario' || $role === 'user') {
        return 'usuario';
    }
    return 'cliente';
}

$input = json_decode(file_get_contents('php://input'), true);
$ssoToken = trim($input['sso_token'] ?? '');

if (empty($ssoToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'Token SSO é obrigatório']);
    exit;
}

$ssoSecret = Environment::get('sso_shared_secret');
if (!$ssoSecret || strlen($ssoSecret) < 32) {
    error_log('[AUTH-SSO] SSO_SHARED_SECRET não configurado ou muito curto. Configure no arquivo .env.');
    http_response_code(500);
    echo json_encode(['error' => 'SSO não configurado neste ambiente']);
    exit;
}

try {
    $payload = (array) JWT::decode($ssoToken, new Key($ssoSecret, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Token SSO inválido ou expirado']);
    exit;
}

$email = trim($payload['email'] ?? '');
$nome = trim($payload['nome'] ?? '');
$tipo = mapearTipo($payload['role'] ?? null);
$setorIdSso = isset($payload['setor_id']) ? (int) $payload['setor_id'] : null;

if (empty($email)) {
    http_response_code(400);
    echo json_encode(['error' => 'Token SSO não contém e-mail do usuário']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT u.*, s.nome as setor_nome FROM usuarios u LEFT JOIN setores s ON s.id = u.setor_id WHERE u.email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $usuario = $stmt->fetch();

    $auditoriaService = new AuditoriaService($db);

    if (!$usuario) {
        // Provisionamento automático (find-or-create): primeira vez que este usuário
        // acessa o Conecta CRPRS via SSO. Senha aleatória e inutilizável — o acesso
        // por senha local nunca será usado por contas provisionadas via SSO.
        if (empty($nome)) {
            http_response_code(400);
            echo json_encode(['error' => 'Token SSO não contém nome do usuário (obrigatório no primeiro acesso)']);
            exit;
        }

        $senhaAleatoria = bin2hex(random_bytes(32));
        $algoHash = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $senhaHash = password_hash($senhaAleatoria, $algoHash);

        $insert = $db->prepare(
            "INSERT INTO usuarios (nome, email, tipo, ativo, senha, setor_id) VALUES (:nome, :email, :tipo, 1, :senha, :setor_id)"
        );
        $insert->execute([
            ':nome'     => $nome,
            ':email'    => $email,
            ':tipo'     => $tipo,
            ':senha'    => $senhaHash,
            ':setor_id' => $setorIdSso,
        ]);

        $setorNomeSso = null;
        if ($setorIdSso) {
            $setorStmt = $db->prepare("SELECT nome FROM setores WHERE id = :id");
            $setorStmt->execute([':id' => $setorIdSso]);
            $setorNomeSso = $setorStmt->fetchColumn() ?: null;
        }

        $usuario = [
            'id'         => (int) $db->lastInsertId(),
            'nome'       => $nome,
            'email'      => $email,
            'tipo'       => $tipo,
            'avatar'     => null,
            'cargo'      => null,
            'setor_id'   => $setorIdSso,
            'setor_nome' => $setorNomeSso,
        ];

        $auditoriaService->registrarLogin($usuario['id'], $email, 'login_sucesso');
    } else {
        if (isset($usuario['ativo']) && $usuario['ativo'] == 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Usuário desativado. Entre em contato com o administrador.']);
            exit;
        }
        $auditoriaService->registrarLogin($usuario['id'], $email, 'login_sucesso');
    }

    // Reaproveita o mesmo emissor de token do login tradicional — a partir daqui,
    // o restante do app (verificarAuth, CSRF, etc.) funciona sem nenhuma alteração.
    $token = AuthMiddleware::gerarToken($usuario);

    $jwtParts = explode('.', $token);
    $jwtPayload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $jwtParts[1])), true);
    $csrfToken = $jwtPayload['csrf'] ?? bin2hex(random_bytes(16));

    echo json_encode([
        'success'    => true,
        'token'      => $token,
        'csrf_token' => $csrfToken,
        'usuario'    => [
            'id'         => $usuario['id'],
            'nome'       => $usuario['nome'],
            'email'      => $usuario['email'],
            'tipo'       => $usuario['tipo'],
            'avatar'     => $usuario['avatar'] ?? null,
            'cargo'      => $usuario['cargo'] ?? null,
            'setor_id'   => $usuario['setor_id'] ?? null,
            'setor_nome' => $usuario['setor_nome'] ?? null,
        ],
    ]);

} catch (Exception $e) {
    error_log('[AUTH-SSO POST] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
