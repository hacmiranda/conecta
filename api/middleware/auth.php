<?php
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
require_once __DIR__ . '/../config/environment.php';

class AuthMiddleware {

    /** Lista de origens permitidas para CORS */
    private static $origensPermitidas = [
        'http://localhost',
        'http://localhost/crpdesk',
        'http://127.0.0.1',
        'http://127.0.0.1/crpdesk',
        'https://crp07.org.br',
        'https://www.crp07.org.br',
    ];

    /**
     * Retorna a chave secreta JWT.
     * Encerra com erro 500 se não configurada ou muito curta.
     */
    private static function getSecretKey(): string {
        $secret = Environment::get('jwt_secret');
        if (!$secret || strlen($secret) < 32) {
            error_log('[AUTH] JWT_SECRET não configurado ou muito curto. Configure no arquivo .env.');
            http_response_code(500);
            echo json_encode(['error' => 'Erro de configuração do servidor']);
            exit;
        }
        return $secret;
    }

    /**
     * Obtém todos os headers HTTP da requisição
     */
    private static function getAllHeaders(): array {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    /**
     * Configura headers CORS com whitelist de origens.
     * Deve ser chamado no início de cada controller.
     *
     * @param array $metodos Métodos HTTP permitidos (ex: ['GET', 'POST'])
     */
    public static function configurarCORS(array $metodos = ['GET', 'POST', 'OPTIONS']): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, self::$origensPermitidas, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            header('Access-Control-Allow-Origin: null');
        }
        $metodosStr = implode(', ', array_map('strtoupper', $metodos));
        header('Access-Control-Allow-Methods: ' . $metodosStr . ', OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
        header('Access-Control-Allow-Credentials: true');
    }

    /**
     * Verifica o token JWT no header Authorization.
     * Retorna o payload decodificado ou encerra com 401.
     */
    public static function verificarAuth(): array {
        $headers = self::getAllHeaders();
        $auth_header = null;

        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $auth_header = $value;
                break;
            }
        }

        if (!$auth_header) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        }

        if (!$auth_header) {
            http_response_code(401);
            echo json_encode(['error' => 'Token de autenticação requerido']);
            exit;
        }

        if (!preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'Formato de token inválido']);
            exit;
        }

        $token = $matches[1];

        try {
            $secret = self::getSecretKey();
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            return (array) $decoded;
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['error' => 'Token inválido ou expirado']);
            exit;
        }
    }

    /**
     * Verifica se o usuário autenticado é administrador.
     * Encerra com 403 se não for.
     */
    public static function verificarAdmin(array $user): void {
        if (!$user || ($user['tipo'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado. Apenas administradores.']);
            exit;
        }
    }

    /**
     * Valida o token CSRF enviado no header X-CSRF-Token.
     * Compara com o token armazenado na sessão PHP.
     * Deve ser chamado em endpoints POST/PUT/DELETE.
     */
    public static function verificarCSRF(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return;
        }

        $headers = self::getAllHeaders();
        $csrf_token = null;

        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'x-csrf-token') {
                $csrf_token = $value;
                break;
            }
        }

        if (!$csrf_token) {
            $csrf_token = $_POST['_csrf'] ?? null;
        }

        if (empty($csrf_token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF ausente']);
            exit;
        }

        if (session_status() === PHP_SESSION_NONE) {
            self::configurarSessaoSegura();
            session_start();
        }

        $token_sessao = $_SESSION['csrf_token'] ?? null;

        if (!$token_sessao || !hash_equals($token_sessao, $csrf_token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF inválido']);
            exit;
        }
    }

    /**
     * Gera e armazena um token CSRF na sessão.
     * Retorna o token para envio ao cliente.
     */
    public static function gerarCSRFToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            self::configurarSessaoSegura();
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Configura parâmetros seguros do cookie de sessão.
     * Deve ser chamado ANTES de session_start().
     */
    public static function configurarSessaoSegura(): void {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                   || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Gera um token JWT para o usuário autenticado.
     */
    public static function gerarToken(array $user_data): string {
        $payload = [
            'id'    => $user_data['id'],
            'email' => $user_data['email'],
            'tipo'  => $user_data['tipo'],
            'iat'   => time(),
            'exp'   => time() + (8 * 3600),
        ];
        return JWT::encode($payload, self::getSecretKey(), 'HS256');
    }
}
