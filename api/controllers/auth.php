<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/rate-limit-service.php';
require_once __DIR__ . '/../config/turnstile.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim((string) ($input['email'] ?? '')));
$senha = (string) ($input['senha'] ?? '');
$turnstileToken = $input['cf-turnstile-response'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// Limite também por conta (impede tentar muitas senhas de um e-mail trocando de IP)
$chaveConta = 'conta_' . sha1($email);

// Verifica rate limiting antes de qualquer processamento
foreach ([[$ip, 5], [$chaveConta, 10]] as [$chave, $limite]) {
    $rateLimit = RateLimitService::verificar($chave, 'login', $limite, 900);
    if ($rateLimit['bloqueado']) {
        $minutos = (int) ceil($rateLimit['segundos_restantes'] / 60);
        http_response_code(429);
        echo json_encode(['error' => "Muitas tentativas de login. Tente novamente em {$minutos} minuto(s)."]);
        exit;
    }
}

// Valida Turnstile
if (!validateTurnstile($turnstileToken, $ip)) {
    http_response_code(400);
    echo json_encode(['error' => 'Verificação de segurança falhou. Tente novamente.']);
    exit;
}

if ($email === '' || $senha === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Email e senha são obrigatórios']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $auditoriaService = new AuditoriaService($db);

    $stmt = $db->prepare("SELECT u.*, s.nome AS setor_nome FROM usuarios u LEFT JOIN setores s ON s.id = u.setor_id WHERE u.email = :email");
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    // Senha conferida antes de qualquer outra informação sobre a conta (não revela se o e-mail existe).
    // Só aceita hash: senhas em texto puro (legado) não entram mais.
    $senhaValida = $usuario && !empty($usuario['senha']) && password_verify($senha, (string) $usuario['senha']);

    // Solicitantes externos (criados a partir de e-mails recebidos) não entram no sistema
    if ($senhaValida && $usuario['tipo'] === 'cliente') {
        $senhaValida = false;
    }

    if (!$senhaValida) {
        RateLimitService::registrar($ip, 'login');
        RateLimitService::registrar($chaveConta, 'login');
        $auditoriaService->registrarLogin($usuario['id'] ?? null, $email, 'login_falha');
        http_response_code(401);
        echo json_encode(['error' => 'Credenciais inválidas']);
        exit;
    }

    // Conta desativada: informado só a quem acertou a senha
    if ((int) ($usuario['ativo'] ?? 1) === 0) {
        $auditoriaService->registrarLogin($usuario['id'], $email, 'login_falha');
        http_response_code(403);
        echo json_encode(['error' => 'Usuário desativado. Entre em contato com o administrador.']);
        exit;
    }

    // Atualiza o hash para o algoritmo atual, se necessário
    $algoHash = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    if (password_needs_rehash($usuario['senha'], $algoHash)) {
        $db->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id")
           ->execute([':senha' => password_hash($senha, $algoHash), ':id' => $usuario['id']]);
    }

    RateLimitService::limpar($ip, 'login');
    RateLimitService::limpar($chaveConta, 'login');

    // Gera token JWT (já inclui os claims csrf, tv e jti)
    $token = AuthMiddleware::gerarToken($usuario);
    $jwtPayload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

    $auditoriaService->registrarLogin($usuario['id'], $email, 'login_sucesso');

    echo json_encode([
        'success'    => true,
        'token'      => $token,
        'csrf_token' => $jwtPayload['csrf'] ?? '',
        'usuario'    => [
            'id'           => $usuario['id'],
            'nome'         => $usuario['nome'],
            'email'        => $usuario['email'],
            'tipo'         => $usuario['tipo'],
            'avatar'       => $usuario['avatar'] ?? null,
            'cargo'        => $usuario['cargo'] ?? null,
            'setor_id'     => $usuario['setor_id'] ?? null,
            'setor_nome'   => $usuario['setor_nome'] ?? null,
            'trocar_senha' => (int) ($usuario['trocar_senha'] ?? 0) === 1,
        ],
    ]);
} catch (Exception $e) {
    error_log('[AUTH POST] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
