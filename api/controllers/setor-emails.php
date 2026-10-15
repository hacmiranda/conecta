<?php
/**
 * CRUD das caixas de e-mail (IMAP) por setor — até 2 por setor. A senha nunca
 * é devolvida ao frontend (nem cifrada); só é gravada/decifrada no servidor.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/crypto-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

const MAX_CAIXAS_POR_SETOR = 2;

$database = new Database();
$db = $database->getConnection();

function autorizarSetorEmail(array $user, int $setor_id): void {
    AuthMiddleware::verificarGestor($user);
    if (!AuthMiddleware::mesmoSetor($user, $setor_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode configurar e-mails do próprio setor']);
        exit;
    }
}

$user = AuthMiddleware::verificarAuth();

// GET - Listar caixas de um setor (nunca inclui a senha)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $setor_id = isset($_GET['setor_id']) ? (int) $_GET['setor_id'] : 0;
    if (!$setor_id) {
        http_response_code(400);
        echo json_encode(['error' => 'setor_id é obrigatório']);
        exit;
    }
    autorizarSetorEmail($user, $setor_id);

    try {
        $stmt = $db->prepare("SELECT id, setor_id, email, imap_host, imap_port, imap_user, ativo, criado_em FROM setor_emails WHERE setor_id = ? ORDER BY id");
        $stmt->execute([$setor_id]);
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar e-mails do setor']);
    }
    exit;
}

// POST - Criar caixa de e-mail (ou testar credenciais, sem salvar, via acao=testar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AuthMiddleware::verificarCSRF();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (($input['acao'] ?? '') === 'testar') {
        AuthMiddleware::verificarGestor($user);

        $host = trim($input['imap_host'] ?? '');
        $port = (int) ($input['imap_port'] ?? 993);
        $imap_user = trim($input['imap_user'] ?? '');
        $senha = (string) ($input['imap_pass'] ?? '');

        if (!$host || !$port || !$imap_user || $senha === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Preencha host, porta, usuário e senha para testar']);
            exit;
        }

        if (!function_exists('imap_open')) {
            echo json_encode(['sucesso' => false, 'mensagem' => "Extensão PHP IMAP não está habilitada neste servidor."]);
            exit;
        }

        $mailbox = "{{$host}:{$port}/imap/ssl}INBOX";
        $inbox = @imap_open($mailbox, $imap_user, $senha, 0, 1);

        if ($inbox) {
            $total = imap_num_msg($inbox);
            imap_close($inbox);
            echo json_encode(['sucesso' => true, 'mensagem' => "Conexão IMAP bem-sucedida! {$total} e-mails na caixa."]);
        } else {
            $erro = imap_last_error();
            echo json_encode(['sucesso' => false, 'mensagem' => $erro ? "Falha na conexão IMAP: {$erro}" : 'Falha na conexão IMAP']);
        }
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

    autorizarSetorEmail($user, $setor_id);

    try {
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM setor_emails WHERE setor_id = ?");
        $count_stmt->execute([$setor_id]);
        if ((int) $count_stmt->fetchColumn() >= MAX_CAIXAS_POR_SETOR) {
            http_response_code(400);
            echo json_encode(['error' => 'Este setor já atingiu o limite de ' . MAX_CAIXAS_POR_SETOR . ' caixas de e-mail']);
            exit;
        }

        $senha_cifrada = CryptoService::encrypt($imap_pass);

        $stmt = $db->prepare("INSERT INTO setor_emails (setor_id, email, imap_host, imap_port, imap_user, imap_pass_cifrada, ativo) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$setor_id, $email, $imap_host, $imap_port, $imap_user, $senha_cifrada]);
        $id = $db->lastInsertId();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_setor_email', 'setor_emails', $id, null, json_encode(['setor_id' => $setor_id, 'email' => $email]));

        echo json_encode(['id' => (int) $id, 'setor_id' => $setor_id, 'email' => $email, 'imap_host' => $imap_host, 'imap_port' => $imap_port, 'imap_user' => $imap_user, 'ativo' => 1]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao cadastrar e-mail do setor']);
    }
    exit;
}

// PUT - Atualizar caixa de e-mail (senha só é alterada se enviada; string vazia = mantém a atual)
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

        autorizarSetorEmail($user, (int) $atual['setor_id']);

        $email = isset($input['email']) ? trim($input['email']) : $atual['email'];
        $imap_host = isset($input['imap_host']) ? trim($input['imap_host']) : $atual['imap_host'];
        $imap_port = isset($input['imap_port']) ? (int) $input['imap_port'] : (int) $atual['imap_port'];
        $imap_user = isset($input['imap_user']) ? trim($input['imap_user']) : $atual['imap_user'];
        $ativo = isset($input['ativo']) ? ($input['ativo'] ? 1 : 0) : (int) $atual['ativo'];
        $senha_cifrada = $atual['imap_pass_cifrada'];

        if (!empty($input['imap_pass'])) {
            $senha_cifrada = CryptoService::encrypt((string) $input['imap_pass']);
        }

        $stmt = $db->prepare("UPDATE setor_emails SET email = ?, imap_host = ?, imap_port = ?, imap_user = ?, imap_pass_cifrada = ?, ativo = ? WHERE id = ?");
        $stmt->execute([$email, $imap_host, $imap_port, $imap_user, $senha_cifrada, $ativo, $id]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_setor_email', 'setor_emails', $id, null, json_encode(['email' => $email, 'ativo' => $ativo]));

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
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

        autorizarSetorEmail($user, (int) $atual['setor_id']);

        $db->prepare("DELETE FROM setor_emails WHERE id = ?")->execute([$id]);

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'excluir_setor_email', 'setor_emails', $id, json_encode($atual), null);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir e-mail do setor']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
