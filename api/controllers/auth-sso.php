<?php
/**
 * Ponte de SSO — troca um token emitido pela identidade central do painel
 * agregador por um JWT interno do Conecta CRPRS (mesmo formato do login normal).
 *
 * Contrato esperado do token recebido (JWT HS256, assinado com SSO_SHARED_SECRET):
 *   - email  (string, obrigatório) — usado para localizar o usuário local
 *   - exp    (int, obrigatório)    — expiração padrão do JWT
 *   (nome, role e setor_id são lidos, mas não criam nem alteram usuários)
 *
 * Após validar o token central, este endpoint localiza o usuário local pelo e-mail.
 * Só entra quem já foi cadastrado por um administrador ou gestor (não há criação automática)
 * e nunca um solicitante externo. Devolve a MESMA estrutura de resposta do login tradicional
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
    // O painel central pode enviar o vocabulário antigo (supervisor/usuario/agente); ambos são aceitos
    if ($role === 'gestor' || $role === 'supervisor') {
        return 'gestor';
    }
    if ($role === 'operador' || $role === 'usuario' || $role === 'agente' || $role === 'agent' || $role === 'user') {
        return 'operador';
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

// Token de SSO é de uso imediato: precisa de "exp" no máximo 5 min depois de "iat" e de "jti" (uso único).
// "iss"/"aud" são conferidos quando SSO_ISSUER_*/SSO_AUDIENCE_* estão no .env.
$exp = (int) ($payload['exp'] ?? 0);
$iat = (int) ($payload['iat'] ?? 0);
$jti = (string) ($payload['jti'] ?? '');
$emissorEsperado = (string) Environment::get('sso_issuer', '');
$publicoEsperado = (string) Environment::get('sso_audience', '');
$publicos = isset($payload['aud']) ? (array) $payload['aud'] : [];
if ($exp === 0 || $iat === 0 || $exp - $iat > 300 || $jti === ''
    || ($emissorEsperado !== '' && ($payload['iss'] ?? '') !== $emissorEsperado)
    || ($publicoEsperado !== '' && !in_array($publicoEsperado, $publicos, true))) {
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

    // Somente usuários já cadastrados por um administrador ou gestor entram (sem criação automática).
    // Solicitantes externos (cliente) nunca entram.
    if (!$usuario || $usuario['tipo'] === 'cliente') {
        $auditoriaService->registrarLogin($usuario['id'] ?? null, $email, 'login_falha');
        http_response_code(403);
        echo json_encode(['error' => 'Usuário não cadastrado no Conecta CRPRS. Procure o gestor do seu setor.']);
        exit;
    }
    if ((int) ($usuario['ativo'] ?? 1) === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuário desativado. Entre em contato com o administrador.']);
        exit;
    }
    // Uso único do token de SSO: o hash do jti fica em sessoes_revogadas (some na faxina de 2 dias,
    // muito depois do "exp" de 5 min); se já estiver lá, o token foi reaproveitado
    $marca = $db->prepare("INSERT IGNORE INTO sessoes_revogadas (jti, usuario_id) VALUES (?, ?)");
    $marca->execute([md5('sso:' . $jti), (int) $usuario['id']]);
    if ($marca->rowCount() === 0) {
        $auditoriaService->registrarLogin($usuario['id'], $email, 'login_falha');
        http_response_code(401);
        echo json_encode(['error' => 'Token SSO já utilizado']);
        exit;
    }

    $auditoriaService->registrarLogin($usuario['id'], $email, 'login_sucesso');

    // Mesma sessão do login tradicional: token no cookie HttpOnly, o navegador guarda só o csrf
    $token = AuthMiddleware::gerarToken($usuario);
    AuthMiddleware::definirCookieSessao($token);

    echo json_encode([
        'success'    => true,
        'csrf_token' => AuthMiddleware::csrfDoToken($token),
        'usuario'    => [
            'id'         => $usuario['id'],
            'nome'       => $usuario['nome'],
            'email'      => $usuario['email'],
            'tipo'       => $usuario['tipo'],
            'avatar'     => $usuario['avatar'] ?? null,
            'cargo'      => $usuario['cargo'] ?? null,
            'setor_id'   => $usuario['setor_id'] ?? null,
            'setor_nome' => $usuario['setor_nome'] ?? null,
            'trocar_senha' => (int) ($usuario['trocar_senha'] ?? 0) === 1,
        ],
    ]);

} catch (Exception $e) {
    error_log('[AUTH-SSO POST] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
