<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
require_once __DIR__ . '/../config/environment.php';

class AuthMiddleware {

    private static $origensPermitidasCache = null;

    /**
     * Lista de origens permitidas para CORS.
     * Configurável via CORS_ALLOWED_ORIGINS_LOCAL/_PROD no .env (lista separada por vírgula),
     * permitindo adicionar a origem de um painel agregador sem alterar código.
     * Sem .env, usa o default definido em Environment (localhost()/production()).
     */
    private static function origensPermitidas(): array {
        if (self::$origensPermitidasCache === null) {
            $config = Environment::get('cors_allowed_origins', '');
            self::$origensPermitidasCache = array_filter(array_map('trim', explode(',', $config)));
        }
        return self::$origensPermitidasCache;
    }

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
        if (in_array($origin, self::origensPermitidas(), true)) {
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
     * Verifica se o usuário autenticado é gestor ou administrador.
     * Encerra com 403 se não for.
     */
    public static function verificarGestor(array $user): void {
        $tipo = $user['tipo'] ?? '';
        if (!$user || ($tipo !== 'gestor' && $tipo !== 'admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado. Apenas gestores ou administradores.']);
            exit;
        }
    }

    /**
     * Verifica se o usuário pertence ao setor informado.
     * Administrador sempre retorna true (enxerga todos os setores).
     */
    public static function mesmoSetor(array $user, ?int $setorId): bool {
        if (($user['tipo'] ?? '') === 'admin') {
            return true;
        }
        $setorUsuario = $user['setor_id'] ?? null;
        return $setorUsuario !== null && $setorId !== null && (int) $setorUsuario === (int) $setorId;
    }

    /**
     * Valida o token CSRF enviado no header X-CSRF-Token.
     * Estratégia stateless: compara com o claim 'csrf' do JWT da requisição.
     * Elimina dependência de sessão PHP — compatível com Cloudflare e múltiplos workers.
     */
    public static function verificarCSRF(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return;
        }

        $headers = self::getAllHeaders();

        // Obter X-CSRF-Token do header
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

        // Obter Authorization header para extrair o claim csrf do JWT
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

        if (!$auth_header || !preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF inválido']);
            exit;
        }

        try {
            $decoded = JWT::decode($matches[1], new Key(self::getSecretKey(), 'HS256'));
            $csrf_esperado = $decoded->csrf ?? null;
        } catch (Exception $e) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF inválido']);
            exit;
        }

        if (!$csrf_esperado || !hash_equals($csrf_esperado, $csrf_token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF inválido']);
            exit;
        }
    }

    /**
     * Extrai o token CSRF do JWT presente na requisição atual.
     * Usado em auth.php após gerar o token para retornar o csrf ao cliente.
     */
    public static function gerarCSRFToken(): string {
        $headers = self::getAllHeaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                if (preg_match('/Bearer\s(\S+)/', $value, $m)) {
                    try {
                        $decoded = JWT::decode($m[1], new Key(self::getSecretKey(), 'HS256'));
                        return $decoded->csrf ?? '';
                    } catch (Exception $e) {}
                }
            }
        }
        return bin2hex(random_bytes(16));
    }

    /**
     * Configura parâmetros seguros do cookie de sessão.
     * Deve ser chamado ANTES de session_start().
     */
    public static function configurarSessaoSegura(): void {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                   || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        } else {
            session_set_cookie_params(0, '/; samesite=Strict', '', $isHttps, true);
        }
    }

    /**
     * Gera um token JWT para o usuário autenticado.
     * Inclui claim 'csrf' para validação stateless (sem sessão PHP).
     * Sem 'exp': a sessão não expira (firebase/php-jwt só valida expiração quando o claim existe).
     */
    public static function gerarToken(array $user_data): string {
        $payload = [
            'id'       => $user_data['id'],
            'email'    => $user_data['email'],
            'tipo'     => $user_data['tipo'],
            'setor_id' => $user_data['setor_id'] ?? null,
            'csrf'     => bin2hex(random_bytes(16)),
            'iat'      => time(),
        ];
        return JWT::encode($payload, self::getSecretKey(), 'HS256');
    }
}
