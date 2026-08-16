<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/rate-limit-service.php';
require_once __DIR__ . '/../config/turnstile.php';

// Configura CORS e sessão segura antes de qualquer output
AuthMiddleware::configurarCORS(['POST']);
AuthMiddleware::configurarSessaoSegura();
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $senha = $input['senha'] ?? '';
    $turnstileToken = $input['cf-turnstile-response'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Verifica rate limiting antes de qualquer processamento
    $rateLimit = RateLimitService::verificar($ip, 'login', 5, 900);
    if ($rateLimit['bloqueado']) {
        $minutos = (int) ceil($rateLimit['segundos_restantes'] / 60);
        http_response_code(429);
        echo json_encode([
            'error' => "Muitas tentativas de login. Tente novamente em {$minutos} minuto(s).",
        ]);
        exit;
    }

    // Valida Turnstile
    if (!validateTurnstile($turnstileToken, $ip)) {
        http_response_code(400);
        echo json_encode(['error' => 'Verificação de segurança falhou. Tente novamente.']);
        exit;
    }

    if (empty($email) || empty($senha)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email e senha são obrigatórios']);
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
            RateLimitService::registrar($ip, 'login');
            $auditoriaService->registrarLogin(null, $email, 'login_falha');
            http_response_code(401);
            // Mensagem genérica para não revelar se o email existe
            echo json_encode(['error' => 'Credenciais inválidas']);
            exit;
        }

        // Verifica se usuário está ativo
        if (isset($usuario['ativo']) && $usuario['ativo'] == 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Usuário desativado. Entre em contato com o administrador.']);
            exit;
        }

        // Verifica senha
        $senhaValida = false;

        if (password_verify($senha, $usuario['senha'])) {
            $senhaValida = true;

            $algoHash = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            // Verifica se precisa de rehash
            if (password_needs_rehash($usuario['senha'], $algoHash)) {
                $novoHash = password_hash($senha, $algoHash);
                $update = $db->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
                $update->execute([':senha' => $novoHash, ':id' => $usuario['id']]);
            }
        } elseif ($usuario['senha'] === $senha) {
            // Migração de legado: senha em texto plano → converter para hash atual
            $algoHash = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $novoHash = password_hash($senha, $algoHash);
            $update = $db->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
            $update->execute([':senha' => $novoHash, ':id' => $usuario['id']]);
            $senhaValida = true;
        }

        if (!$senhaValida) {
            RateLimitService::registrar($ip, 'login');
            $auditoriaService->registrarLogin($usuario['id'], $email, 'login_falha');
            http_response_code(401);
            echo json_encode(['error' => 'Credenciais inválidas']);
            exit;
        }

        // Login bem-sucedido: limpa rate limit
        RateLimitService::limpar($ip, 'login');

        // Gera token JWT (já inclui claim 'csrf' stateless)
        $token = AuthMiddleware::gerarToken($usuario);

        // Extrai CSRF do JWT gerado (stateless — sem sessão PHP)
        $jwtParts = explode('.', $token);
        $jwtPayload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $jwtParts[1])), true);
        $csrfToken = $jwtPayload['csrf'] ?? bin2hex(random_bytes(16));

        $auditoriaService->registrarLogin($usuario['id'], $email, 'login_sucesso');

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
        error_log('[AUTH POST] Erro: ' . $e->getMessage());
        http_response_code(500);
        // [DEBUG TEMP] Mostra o erro real em vez de ocultar
        echo json_encode(['error' => 'Erro interno do servidor: ' . $e->getMessage()]);
    }
}
