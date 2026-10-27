<?php
/**
 * "Esqueci a senha": envia um link de redefinição (válido por 1 hora).
 * - A resposta é sempre a mesma, exista ou não o e-mail (não revela quem tem conta).
 * - Só a equipe interna (operador, gestor, admin) ativa recebe o link; solicitantes
 *   externos não têm acesso ao sistema.
 * - Limite de pedidos por IP e por e-mail; o token é guardado só como hash (SHA-256).
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
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
$email = strtolower(trim((string) ($data['email'] ?? '')));
require_once __DIR__ . '/../services/rede-service.php';
$ip = RedeService::ipCliente();
$respostaPadrao = ['success' => true, 'message' => 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.'];

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe um e-mail válido']);
    exit;
}

$chaveEmail = 'recuperar_' . sha1($email);
// Registra antes de conferir (pedidos simultâneos não furam o limite)
foreach ([[$ip, 5], [$chaveEmail, 3]] as [$chave, $max]) {
    $limite = RateLimitService::consumir($chave, 'recuperar_senha', $max, 3600);
    if ($limite['bloqueado']) {
        http_response_code(429);
        echo json_encode(['error' => 'Muitos pedidos de recuperação. Tente novamente mais tarde.']);
        exit;
    }
}

try {
    $db = (new Database())->getConnection();

    $stmt = $db->prepare("SELECT id, nome, email FROM usuarios
                          WHERE email = :email AND ativo = 1 AND tipo IN ('operador', 'gestor', 'admin')");
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        echo json_encode($respostaPadrao);
        exit;
    }

    // Um link válido por vez
    $db->prepare("DELETE FROM tokens_recuperacao WHERE usuario_id = ?")->execute([$usuario['id']]);

    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO tokens_recuperacao (usuario_id, token, expira_em, usado) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), 0)")
       ->execute([$usuario['id'], hash('sha256', $token)]);

    require_once __DIR__ . '/../services/email-sender.php';
    $emailSender = new EmailSender($db);

    // Token no fragmento (#): não vai para logs de servidor/proxy nem no Referer
    $link = rtrim((string) Environment::get('base_url'), '/') . '/frontend/redefinir-senha.html#token=' . $token;
    $linkHtml = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $nomeHtml = htmlspecialchars($usuario['nome'], ENT_QUOTES, 'UTF-8');

    $mensagem = "
        <h2>Recuperação de Senha</h2>
        <p>Olá, {$nomeHtml}!</p>
        <p>Você solicitou a recuperação de senha do Conecta CRPRS.</p>
        <p>Clique no link abaixo para redefinir sua senha:</p>
        <p><a href=\"{$linkHtml}\" style=\"background-color: #f89327; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;\">Redefinir Senha</a></p>
        <p>Ou copie e cole este link no navegador:</p>
        <p>{$linkHtml}</p>
        <p><strong>Este link expira em 1 hora.</strong></p>
        <p>Se você não solicitou esta recuperação, ignore este email.</p>
    ";

    // Pela fila de e-mails (o cron envia em até ~2 min): a resposta leva o mesmo tempo com ou sem conta,
    // e não denuncia quais e-mails estão cadastrados. Falha ao enfileirar fica só no log.
    if (!$emailSender->enviarEmail($usuario['email'], 'Recuperação de Senha - Conecta CRPRS', $mensagem)) {
        error_log('[RECUPERAR-SENHA] Falha ao enfileirar o e-mail de recuperação do usuário #' . $usuario['id']);
    }

    echo json_encode($respostaPadrao);
} catch (Exception $e) {
    error_log('[RECUPERAR-SENHA] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno ao processar a recuperação.']);
}
