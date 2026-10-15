<?php
/**
 * Caixa de e-mail do setor (1 por setor): recebe por IMAP — o e-mail recebido vira demanda no
 * setor — e envia por SMTP, de modo que as respostas ao solicitante saem pelo endereço do setor.
 * Exclusivo do administrador. As senhas ficam cifradas em repouso e nunca voltam ao frontend.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/crypto-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

const MAX_CAIXAS_POR_SETOR = 1;

$database = new Database();
$db = $database->getConnection();

// Configuração de e-mail: exclusiva do administrador
$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user);

/**
 * Normaliza os campos SMTP recebidos (host em branco = servidor global do .env).
 */
function camposSmtp(array $input, array $padrao = []): array {
    $seguranca = $input['smtp_seguranca'] ?? ($padrao['smtp_seguranca'] ?? 'ssl');
    return [
        'smtp_host'      => trim((string) ($input['smtp_host'] ?? ($padrao['smtp_host'] ?? ''))),
        'smtp_port'      => (int) ($input['smtp_port'] ?? ($padrao['smtp_port'] ?? 465)) ?: 465,
        'smtp_seguranca' => in_array($seguranca, ['ssl', 'tls'], true) ? $seguranca : 'ssl',
        'smtp_user'      => trim((string) ($input['smtp_user'] ?? ($padrao['smtp_user'] ?? ''))),
        'nome_remetente' => trim((string) ($input['nome_remetente'] ?? ($padrao['nome_remetente'] ?? ''))),
    ];
}

/**
 * Testa o login SMTP (sem enviar mensagem).
 */
function testarSmtp(string $host, int $port, string $seguranca, string $usuario, string $senha, bool $validarCertificado = true): array {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $usuario;
        $mail->Password = $senha;
        $mail->SMTPSecure = $seguranca === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Timeout = 15;
        if (!$validarCertificado) {
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }

        if ($mail->smtpConnect()) {
            $mail->smtpClose();
            return ['sucesso' => true, 'mensagem' => 'Login SMTP bem-sucedido.'];
        }
        return ['sucesso' => false, 'mensagem' => 'Falha na conexão SMTP.'];
    } catch (Exception $e) {
        return ['sucesso' => false, 'mensagem' => 'Falha na conexão SMTP: ' . $e->getMessage()];
    }
}

// GET - Caixa do setor (nunca inclui senhas)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $setor_id = isset($_GET['setor_id']) ? (int) $_GET['setor_id'] : 0;
    if (!$setor_id) {
        http_response_code(400);
        echo json_encode(['error' => 'setor_id é obrigatório']);
        exit;
    }

    try {
        $stmt = $db->prepare("SELECT id, setor_id, email, imap_host, imap_port, imap_user,
                                     smtp_host, smtp_port, smtp_seguranca, smtp_user, nome_remetente, validar_certificado,
                                     (smtp_pass_cifrada IS NOT NULL AND smtp_pass_cifrada <> '') AS smtp_senha_propria,
                                     ativo, criado_em
                              FROM setor_emails WHERE setor_id = ? ORDER BY id");
        $stmt->execute([$setor_id]);
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        error_log('[SETOR-EMAILS GET] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar e-mails do setor']);
    }
    exit;
}

// POST - Criar caixa do setor, ou testar credenciais (acao=testar) sem salvar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AuthMiddleware::verificarCSRF();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (($input['acao'] ?? '') === 'testar') {
        $host = trim($input['imap_host'] ?? '');
        $port = (int) ($input['imap_port'] ?? 993);
        $imap_user = trim($input['imap_user'] ?? '');
        $senha = (string) ($input['imap_pass'] ?? '');

        // Teste de uma caixa já salva: usa a senha armazenada quando não for informada
        if (!empty($input['setor_email_id'])) {
            $salva = $db->prepare("SELECT * FROM setor_emails WHERE id = ?");
            $salva->execute([(int) $input['setor_email_id']]);
            $caixa = $salva->fetch(PDO::FETCH_ASSOC);
            if ($caixa) {
                $host = $host ?: $caixa['imap_host'];
                $imap_user = $imap_user ?: $caixa['imap_user'];
                try {
                    $senha = $senha !== '' ? $senha : CryptoService::decrypt($caixa['imap_pass_cifrada']);
                } catch (Exception $e) {
                    echo json_encode(['sucesso' => false, 'mensagem' => 'Não foi possível ler a senha salva. Informe a senha novamente.']);
                    exit;
                }
            }
        }

        if (!$host || !$port || !$imap_user || $senha === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Preencha host, porta, usuário e senha para testar']);
            exit;
        }

        $resultado = ['imap' => null, 'smtp' => null];
        $validar_cert = isset($input['validar_certificado'])
            ? (bool) $input['validar_certificado']
            : (!isset($caixa) || !$caixa || (int) ($caixa['validar_certificado'] ?? 1) === 1);

        // IMAP
        if (!function_exists('imap_open')) {
            $resultado['imap'] = ['sucesso' => false, 'mensagem' => 'Extensão PHP IMAP não está habilitada neste servidor.'];
        } else {
            $mailbox = "{{$host}:{$port}/imap/ssl" . ($validar_cert ? '' : '/novalidate-cert') . "}INBOX";
            $inbox = @imap_open($mailbox, $imap_user, $senha, 0, 1);
            if ($inbox) {
                $total = imap_num_msg($inbox);
                imap_close($inbox);
                $resultado['imap'] = ['sucesso' => true, 'mensagem' => "Conexão IMAP bem-sucedida! {$total} e-mails na caixa."];
            } else {
                $erro = imap_last_error();
                // Limpa a fila de erros do IMAP: senão o PHP imprime um aviso após o JSON no encerramento
                imap_errors();
                imap_alerts();
                $resultado['imap'] = ['sucesso' => false, 'mensagem' => $erro ? "Falha na conexão IMAP: {$erro}" : 'Falha na conexão IMAP'];
            }
        }

        // SMTP (credenciais próprias, ou as do IMAP quando em branco)
        $smtp = camposSmtp($input);
        $smtp_host = $smtp['smtp_host'] !== '' ? $smtp['smtp_host'] : (string) Environment::get('smtp_host');
        $smtp_user = $smtp['smtp_user'] !== '' ? $smtp['smtp_user'] : $imap_user;
        $smtp_senha = (string) ($input['smtp_pass'] ?? '');
        if ($smtp_senha === '') {
            $smtp_senha = $senha;
        }
        $resultado['smtp'] = testarSmtp($smtp_host, $smtp['smtp_port'], $smtp['smtp_seguranca'], $smtp_user, $smtp_senha, $validar_cert);

        $resultado['sucesso'] = $resultado['imap']['sucesso'] && $resultado['smtp']['sucesso'];
        $resultado['mensagem'] = 'IMAP: ' . $resultado['imap']['mensagem'] . ' | SMTP: ' . $resultado['smtp']['mensagem'];
        echo json_encode($resultado);
        exit;
    }

    $setor_id = isset($input['setor_id']) ? (int) $input['setor_id'] : 0;
    $email = trim($input['email'] ?? '');
    $imap_host = trim($input['imap_host'] ?? '');
    $imap_port = (int) ($input['imap_port'] ?? 993);
    $imap_user = trim($input['imap_user'] ?? '');
    $imap_pass = (string) ($input['imap_pass'] ?? '');

    if (!$setor_id || !$email || !$imap_host || !$imap_port || !$imap_user || $imap_pass === '') {
        http_response_code(400);
        echo json_encode(['error' => 'setor_id, email, imap_host, imap_port, imap_user e imap_pass são obrigatórios']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail inválido']);
        exit;
    }

    try {
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM setor_emails WHERE setor_id = ?");
        $count_stmt->execute([$setor_id]);
        if ((int) $count_stmt->fetchColumn() >= MAX_CAIXAS_POR_SETOR) {
            http_response_code(400);
            echo json_encode(['error' => 'Este setor já possui um endereço de e-mail. Edite ou exclua o atual.']);
            exit;
        }

        $smtp = camposSmtp($input);
        $senha_cifrada = CryptoService::encrypt($imap_pass);
        $smtp_senha_cifrada = !empty($input['smtp_pass']) ? CryptoService::encrypt((string) $input['smtp_pass']) : null;

        $stmt = $db->prepare("INSERT INTO setor_emails
                (setor_id, email, imap_host, imap_port, imap_user, imap_pass_cifrada,
                 smtp_host, smtp_port, smtp_seguranca, smtp_user, smtp_pass_cifrada, nome_remetente, validar_certificado, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$setor_id, $email, $imap_host, $imap_port, $imap_user, $senha_cifrada,
                        $smtp['smtp_host'], $smtp['smtp_port'], $smtp['smtp_seguranca'],
                        $smtp['smtp_user'] !== '' ? $smtp['smtp_user'] : null, $smtp_senha_cifrada,
                        $smtp['nome_remetente'] !== '' ? $smtp['nome_remetente'] : null,
                        !isset($input['validar_certificado']) || $input['validar_certificado'] ? 1 : 0]);
        $id = $db->lastInsertId();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_setor_email', 'setor_emails', $id, null, json_encode(['setor_id' => $setor_id, 'email' => $email]));

        echo json_encode(['id' => (int) $id, 'setor_id' => $setor_id, 'email' => $email, 'imap_host' => $imap_host, 'imap_port' => $imap_port, 'imap_user' => $imap_user, 'ativo' => 1]);
    } catch (Exception $e) {
        error_log('[SETOR-EMAILS POST] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao cadastrar e-mail do setor']);
    }
    exit;
}

// PUT - Atualizar caixa (senhas só mudam se enviadas; vazio = mantém a atual)
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $id = (int) end($path_parts);

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    try {
        $atual_stmt = $db->prepare("SELECT * FROM setor_emails WHERE id = ?");
        $atual_stmt->execute([$id]);
        $atual = $atual_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$atual) {
            http_response_code(404);
            echo json_encode(['error' => 'E-mail de setor não encontrado']);
            exit;
        }

        $email = isset($input['email']) ? trim($input['email']) : $atual['email'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'E-mail inválido']);
            exit;
        }
        $imap_host = isset($input['imap_host']) ? trim($input['imap_host']) : $atual['imap_host'];
        $imap_port = isset($input['imap_port']) ? (int) $input['imap_port'] : (int) $atual['imap_port'];
        $imap_user = isset($input['imap_user']) ? trim($input['imap_user']) : $atual['imap_user'];
        $ativo = isset($input['ativo']) ? ($input['ativo'] ? 1 : 0) : (int) $atual['ativo'];
        $validar_cert_nova = isset($input['validar_certificado']) ? ($input['validar_certificado'] ? 1 : 0) : (int) $atual['validar_certificado'];
        $senha_cifrada = $atual['imap_pass_cifrada'];
        $smtp_senha_cifrada = $atual['smtp_pass_cifrada'];

        if (!empty($input['imap_pass'])) {
            $senha_cifrada = CryptoService::encrypt((string) $input['imap_pass']);
        }
        if (!empty($input['smtp_pass'])) {
            $smtp_senha_cifrada = CryptoService::encrypt((string) $input['smtp_pass']);
        }
        // Limpa a senha SMTP própria (volta a usar a senha do IMAP)
        if (!empty($input['limpar_smtp_pass'])) {
            $smtp_senha_cifrada = null;
        }

        $smtp = camposSmtp($input, $atual);

        $stmt = $db->prepare("UPDATE setor_emails SET email = ?, imap_host = ?, imap_port = ?, imap_user = ?, imap_pass_cifrada = ?,
                                     smtp_host = ?, smtp_port = ?, smtp_seguranca = ?, smtp_user = ?, smtp_pass_cifrada = ?, nome_remetente = ?, validar_certificado = ?, ativo = ?
                              WHERE id = ?");
        $stmt->execute([$email, $imap_host, $imap_port, $imap_user, $senha_cifrada,
                        $smtp['smtp_host'], $smtp['smtp_port'], $smtp['smtp_seguranca'],
                        $smtp['smtp_user'] !== '' ? $smtp['smtp_user'] : null, $smtp_senha_cifrada,
                        $smtp['nome_remetente'] !== '' ? $smtp['nome_remetente'] : null, $validar_cert_nova, $ativo, $id]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_setor_email', 'setor_emails', $id, null, json_encode(['email' => $email, 'ativo' => $ativo, 'validar_certificado' => $validar_cert_nova]));

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('[SETOR-EMAILS PUT] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar e-mail do setor']);
    }
    exit;
}

// DELETE - Remover caixa de e-mail
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $id = (int) end($path_parts);

    try {
        $atual_stmt = $db->prepare("SELECT setor_id, email FROM setor_emails WHERE id = ?");
        $atual_stmt->execute([$id]);
        $atual = $atual_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$atual) {
            http_response_code(404);
            echo json_encode(['error' => 'E-mail de setor não encontrado']);
            exit;
        }

        $db->prepare("DELETE FROM setor_emails WHERE id = ?")->execute([$id]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'excluir_setor_email', 'setor_emails', $id, json_encode($atual), null);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('[SETOR-EMAILS DELETE] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir e-mail do setor']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
