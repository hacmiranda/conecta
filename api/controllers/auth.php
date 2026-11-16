<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/rate-limit-service.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/rede-service.php';
require_once __DIR__ . '/../services/log-service.php';
require_once __DIR__ . '/../services/usuario-service.php';
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
// O campo aceita e-mail ou nome de usuário (16/11/2026): com "@" procura pelo e-mail, sem "@" pelo
// nome de usuário (que nunca tem "@"). "login" é o campo novo; "email" continua aceito (testes, integrações).
$login = UsuarioService::normalizarLogin((string) ($input['login'] ?? $input['email'] ?? ''));
$senha = (string) ($input['senha'] ?? '');
$turnstileToken = $input['cf-turnstile-response'] ?? '';
$ip = RedeService::ipCliente();

// Limite de tentativas desligável temporariamente pelo .env (LOGIN_LIMITE_TENTATIVAS_* = 0); sem a chave, ligado
$limiteLigado = RateLimitService::limiteDeLoginLigado();

// Por IP: até 20 tentativas em 15 min (o escritório inteiro pode sair pelo mesmo IP). A tentativa é
// registrada antes de conferir (consumir), então requisições simultâneas não furam o limite.
if ($limiteLigado) {
    $rateLimit = RateLimitService::consumir($ip, 'login', 20, 900);
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

if ($login === '' || $senha === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Informe o e-mail ou usuário e a senha']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $auditoriaService = new AuditoriaService($db);

    // Coluna escolhida por uma regra fixa (UsuarioService::campoDoLogin), nunca pelo texto recebido
    $coluna = UsuarioService::campoDoLogin($login) === 'email' ? 'u.email' : 'u.username';
    $stmt = $db->prepare("SELECT u.*, s.nome AS setor_nome FROM usuarios u LEFT JOIN setores s ON s.id = u.setor_id WHERE $coluna = :login");
    $stmt->execute([':login' => $login]);
    $usuario = $stmt->fetch();

    // Por conta não há bloqueio (senão qualquer um trancaria a conta do administrador errando a senha):
    // a partir da 6ª falha em 15 min, cada resposta de erro demora mais (até 5 s). A contagem é pela conta
    // encontrada, não pelo texto digitado: alternar e-mail e nome de usuário não zera o atraso.
    $chaveConta = 'conta_' . ($usuario ? 'id' . $usuario['id'] : sha1($login));
    // Na auditoria vai o e-mail da conta (mesmo quando a pessoa entrou pelo nome de usuário)
    $emailAuditoria = $usuario ? (string) $usuario['email'] : LogService::mascararLogin($login);

    // Senha conferida antes de qualquer outra informação sobre a conta (não revela se o e-mail existe).
    // Só aceita hash: senhas em texto puro (legado) não entram mais. Sem conta (ou sem senha), confere
    // contra um hash fictício com os mesmos parâmetros: a resposta leva o mesmo tempo e não denuncia
    // quais e-mails têm conta.
    $hashFicticio = '$argon2id$v=19$m=65536,t=4,p=1$Q2hxY2ZDeUJVb0VZbUxCaQ$qS7JzbaV5iVA6T19eg8B6xdtyepC2IPAlvkOqFkJX6k';
    $temSenha = $usuario && !empty($usuario['senha']);
    $senhaConfere = password_verify($senha, $temSenha ? (string) $usuario['senha'] : $hashFicticio);
    $senhaValida = $temSenha && $senhaConfere;

    // Solicitantes externos (criados a partir de e-mails recebidos) não entram no sistema
    if ($senhaValida && $usuario['tipo'] === 'cliente') {
        $senhaValida = false;
    }

    if (!$senhaValida) {
        if ($limiteLigado) {
            RateLimitService::registrar($chaveConta, 'login_conta');
            $falhasConta = RateLimitService::contar($chaveConta, 'login_conta', 900);
            if ($falhasConta > 5) {
                usleep(min($falhasConta - 5, 5) * 1000000);
            }
        }
        // E-mail inteiro só se a conta existe; senão mascarado (às vezes é a senha digitada no campo errado)
        $auditoriaService->registrarLogin($usuario['id'] ?? null, $emailAuditoria, 'login_falha');
        http_response_code(401);
        echo json_encode(['error' => 'Credenciais inválidas']);
        exit;
    }

    // Conta desativada: informado só a quem acertou a senha
    if ((int) ($usuario['ativo'] ?? 1) === 0) {
        $auditoriaService->registrarLogin($usuario['id'], $emailAuditoria, 'login_falha');
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

    // Só a contagem da conta é zerada: zerar a do IP deixava quem tem uma conta válida intercalar logins
    // certos para seguir testando senhas de outras contas (auditoria 01/11/2026, SEG-06)
    RateLimitService::limpar($chaveConta, 'login_conta');

    // Gera o token (claims csrf, tv, jti e exp) e grava no cookie de sessão HttpOnly:
    // o navegador guarda só o csrf (cabeçalho X-CSRF-Token), nunca o token
    $token = AuthMiddleware::gerarToken($usuario);
    AuthMiddleware::definirCookieSessao($token);

    $auditoriaService->registrarLogin($usuario['id'], $emailAuditoria, 'login_sucesso');

    echo json_encode([
        'success'    => true,
        'csrf_token' => AuthMiddleware::csrfDoToken($token),
        'usuario'    => [
            'id'           => $usuario['id'],
            'nome'         => $usuario['nome'],
            'email'        => $usuario['email'],
            'tipo'         => $usuario['tipo'],
            'avatar'       => $usuario['avatar'] ?? null,
            'cargo'        => $usuario['cargo'] ?? null,
            'setor_id'     => $usuario['setor_id'] ?? null,
            'setor_nome'   => $usuario['setor_nome'] ?? null,
            'setores'      => AcessoService::setoresComNome($db, $usuario),
            'somente_leitura' => AcessoService::ehSomenteLeitura($usuario),
            'atendimento_externo' => (int) ($usuario['atendimento_externo'] ?? 0) === 1,
            'trocar_senha' => (int) ($usuario['trocar_senha'] ?? 0) === 1,
        ],
    ]);
} catch (Exception $e) {
    error_log('[AUTH POST] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
