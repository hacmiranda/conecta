<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
require_once __DIR__ . '/../config/environment.php';

class AuthMiddleware {

    /**
     * Sessão: JWT num cookie HttpOnly (o JavaScript não lê; um XSS em outro sistema do mesmo
     * domínio não consegue roubá-lo), com validade de 12 h renovada a cada uso quando faltam
     * menos de 2 h. O cabeçalho "Authorization: Bearer" continua aceito (testes e integrações).
     * O CSRF continua: claim csrf do JWT conferido com o cabeçalho X-CSRF-Token.
     */
    const COOKIE_SESSAO = 'conecta_sessao';
    const VALIDADE_SEGUNDOS = 12 * 3600;
    const RENOVAR_ABAIXO_DE = 2 * 3600;

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
        if ($origin !== '' && in_array($origin, self::origensPermitidas(), true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        $metodosStr = implode(', ', array_map('strtoupper', $metodos));
        header('Access-Control-Allow-Methods: ' . $metodosStr . ', OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
        header('Access-Control-Allow-Credentials: true');
    }

    /**
     * Token da requisição: o cookie de sessão (navegador) ou, se não houver, "Authorization: Bearer".
     */
    private static function tokenDaRequisicao(): ?string {
        $cookie = $_COOKIE[self::COOKIE_SESSAO] ?? '';
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        $auth_header = null;
        foreach (self::getAllHeaders() as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $auth_header = $value;
                break;
            }
        }
        if (!$auth_header) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        }
        if ($auth_header && preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Decodifica e valida o token (assinatura e validade). Token sem "exp" (emitido antes da
     * sessão com validade) é recusado. Devolve null se inválido.
     */
    private static function decodificar(string $token): ?array {
        try {
            $payload = (array) JWT::decode($token, new Key(self::getSecretKey(), 'HS256'));
        } catch (Exception $e) {
            return null;
        }
        return empty($payload['exp']) ? null : $payload;
    }

    /** Caminho do cookie: o do sistema (ex.: /conecta), para não ir a outros sistemas do mesmo domínio. */
    private static function caminhoCookie(): string {
        $caminho = (string) (parse_url((string) Environment::get('base_url', ''), PHP_URL_PATH) ?? '');
        $caminho = rtrim($caminho, '/');
        return $caminho !== '' ? $caminho : '/';
    }

    private static function requisicaoHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
    }

    /** Grava o token no cookie de sessão (HttpOnly, SameSite=Strict, Secure em HTTPS). */
    public static function definirCookieSessao(string $token): void {
        setcookie(self::COOKIE_SESSAO, $token, [
            'expires'  => time() + self::VALIDADE_SEGUNDOS,
            'path'     => self::caminhoCookie(),
            'secure'   => self::requisicaoHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** Apaga o cookie de sessão (Sair, sessão encerrada). */
    public static function apagarCookieSessao(): void {
        setcookie(self::COOKIE_SESSAO, '', [
            'expires'  => time() - 3600,
            'path'     => self::caminhoCookie(),
            'secure'   => self::requisicaoHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Verifica o token (cookie de sessão ou Authorization: Bearer).
     * Retorna o payload decodificado ou encerra com 401.
     */
    public static function verificarAuth(): array {
        $token = self::tokenDaRequisicao();
        if ($token === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Token de autenticação requerido']);
            exit;
        }

        $payload = self::decodificar($token);
        if ($payload === null) {
            self::apagarCookieSessao();
            http_response_code(401);
            echo json_encode(['error' => 'Sua sessão expirou. Entre novamente.', 'sessao_encerrada' => true]);
            exit;
        }

        $payload = self::sincronizarComBanco($payload);

        // Renova a sessão em uso: mesmo jti (o "Sair" continua valendo) e mesmo csrf (o cabeçalho
        // X-CSRF-Token guardado no navegador continua certo), validade de novo em 12 h
        if ((int) $payload['exp'] - time() < self::RENOVAR_ABAIXO_DE) {
            self::definirCookieSessao(self::gerarToken([
                'id'           => $payload['id'],
                'email'        => $payload['email'] ?? '',
                'tipo'         => $payload['tipo'],
                'setor_id'     => $payload['setor_id'],
                'token_versao' => $payload['tv'] ?? 0,
            ], (string) ($payload['jti'] ?? ''), (string) ($payload['csrf'] ?? '')));
        }
        return $payload;
    }

    /** Endpoints liberados enquanto o usuário precisa trocar a senha */
    const LIBERADOS_TROCA_SENHA = ['alterar-senha.php', 'logout.php'];

    /**
     * O JWT (válido por até 12 h) guarda papel/setor no momento do login. Para que mudança de setor,
     * de papel ou desativação do usuário valham imediatamente, o estado atual é relido do
     * banco a cada requisição. Também encerra a sessão quando:
     * - a versão de sessões do usuário mudou (troca/redefinição de senha, desativação);
     * - esta sessão foi encerrada pelo botão "Sair" (sessoes_revogadas);
     * - o usuário é um solicitante externo (cliente), que não entra no sistema.
     * Com troca de senha pendente, só alterar-senha e logout respondem (403 nos demais).
     */
    private static function sincronizarComBanco(array $payload): array {
        try {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare("SELECT nome, tipo, setor_id, ativo, token_versao, trocar_senha FROM usuarios WHERE id = ?");
            $stmt->execute([(int) ($payload['id'] ?? 0)]);
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);

            $revogada = false;
            if (!empty($payload['jti'])) {
                $rev = $db->prepare("SELECT 1 FROM sessoes_revogadas WHERE jti = ?");
                $rev->execute([(string) $payload['jti']]);
                $revogada = (bool) $rev->fetchColumn();
            }
        } catch (Exception $e) {
            error_log('[AUTH] Falha ao sincronizar usuário com o banco: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erro interno de autenticação']);
            exit;
        }

        if (!$atual || (int) $atual['ativo'] !== 1 || $atual['tipo'] === 'cliente') {
            self::apagarCookieSessao();
            http_response_code(401);
            echo json_encode(['error' => 'Usuário inativo ou inexistente', 'sessao_encerrada' => true]);
            exit;
        }

        if ($revogada || (int) ($payload['tv'] ?? 0) !== (int) $atual['token_versao']) {
            self::apagarCookieSessao();
            http_response_code(401);
            echo json_encode(['error' => 'Sua sessão foi encerrada. Entre novamente.', 'sessao_encerrada' => true]);
            exit;
        }

        $payload['nome']         = $atual['nome'];
        $payload['tipo']         = $atual['tipo'];
        $payload['setor_id']     = $atual['setor_id'] !== null ? (int) $atual['setor_id'] : null;
        $payload['trocar_senha'] = (int) $atual['trocar_senha'] === 1;

        $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($payload['trocar_senha'] && !in_array($script, self::LIBERADOS_TROCA_SENHA, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Troque sua senha para continuar.', 'trocar_senha' => true]);
            exit;
        }
        return $payload;
    }

    /**
     * Encerra a sessão atual (botão "Sair"): o identificador do token passa a ser recusado.
     */
    public static function revogarSessao(PDO $db, array $user): void {
        if (!empty($user['jti'])) {
            $db->prepare("INSERT IGNORE INTO sessoes_revogadas (jti, usuario_id) VALUES (?, ?)")
               ->execute([(string) $user['jti'], (int) $user['id']]);
        }
        // Faxina: um token tem no máximo 12 h, então revogações com mais de 2 dias não servem mais
        $db->exec("DELETE FROM sessoes_revogadas WHERE revogado_em < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    }

    /**
     * Encerra todas as sessões do usuário (troca/redefinição de senha, desativação).
     * Devolve a nova versão.
     */
    public static function encerrarTodasAsSessoes(PDO $db, int $usuarioId): int {
        $db->prepare("UPDATE usuarios SET token_versao = token_versao + 1 WHERE id = ?")->execute([$usuarioId]);
        $stmt = $db->prepare("SELECT token_versao FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        return (int) $stmt->fetchColumn();
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

        // Claim csrf do JWT da requisição (cookie de sessão ou Authorization: Bearer)
        $token = self::tokenDaRequisicao();
        $payload = $token !== null ? self::decodificar($token) : null;
        $csrf_esperado = $payload['csrf'] ?? null;
        if (!$csrf_esperado) {
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
        $token = self::tokenDaRequisicao();
        $payload = $token !== null ? self::decodificar($token) : null;
        return $payload['csrf'] ?? bin2hex(random_bytes(16));
    }

    /** Claim csrf de um token recém-gerado (devolvido ao navegador no login). */
    public static function csrfDoToken(string $token): string {
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/')), true);
        return (string) ($payload['csrf'] ?? '');
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
     * 'exp' = 12 h (verificarAuth renova a sessão em uso); 'tv' = versão de sessões do usuário;
     * 'jti' = identificador desta sessão (para o "Sair"). Na renovação, jti e csrf são mantidos.
     */
    public static function gerarToken(array $user_data, string $jti = '', string $csrf = ''): string {
        $agora = time();
        $payload = [
            'id'       => $user_data['id'],
            'email'    => $user_data['email'],
            'tipo'     => $user_data['tipo'],
            'setor_id' => $user_data['setor_id'] ?? null,
            'tv'       => (int) ($user_data['token_versao'] ?? 0),
            'jti'      => $jti !== '' ? $jti : bin2hex(random_bytes(16)),
            'csrf'     => $csrf !== '' ? $csrf : bin2hex(random_bytes(16)),
            'iat'      => $agora,
            'exp'      => $agora + self::VALIDADE_SEGUNDOS,
        ];
        return JWT::encode($payload, self::getSecretKey(), 'HS256');
    }
}
