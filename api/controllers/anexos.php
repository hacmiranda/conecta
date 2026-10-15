<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';

// CORS restrito ao domínio permitido
AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Autenticação JWT obrigatória — anexos não são públicos
$user = AuthMiddleware::verificarAuth();

$database = new Database();
$db = $database->getConnection();

// GET — Download ou visualização inline de anexo
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $anexo_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $action   = $_GET['action'] ?? 'download';

    if (!$anexo_id) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => 'ID do anexo inválido']);
        exit;
    }

    try {
        $query = "SELECT * FROM anexos WHERE id = :id";
        $stmt  = $db->prepare($query);
        $stmt->bindParam(':id', $anexo_id, PDO::PARAM_INT);
        $stmt->execute();
        $anexo = $stmt->fetch();

        if (!$anexo) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode(['error' => 'Anexo não encontrado']);
            exit;
        }

        // Isolamento: só quem pode ver a demanda do anexo pode baixá-lo
        $ticket_do_anexo = $anexo['ticket_id'] ?? null;
        if (empty($ticket_do_anexo) && !empty($anexo['comentario_id'])) {
            $stmt_c = $db->prepare("SELECT ticket_id FROM comentarios WHERE id = ?");
            $stmt_c->execute([(int) $anexo['comentario_id']]);
            $ticket_do_anexo = $stmt_c->fetchColumn();
        }
        if (empty($ticket_do_anexo)) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            exit;
        }
        header('Content-Type: application/json');
        AcessoService::exigirVerTicket($db, $user, $ticket_do_anexo);
        header_remove('Content-Type');

        // Diretório base de anexos (caminho absoluto e normalizado)
        $base_dir = realpath(dirname(__DIR__, 2) . '/backend/anexos');
        if ($base_dir === false) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'Diretório de anexos indisponível']);
            exit;
        }

        // Previne path traversal: extrai apenas o nome base do arquivo
        $nome_seguro     = basename($anexo['nome_arquivo']);
        $caminho_arquivo = $base_dir . DIRECTORY_SEPARATOR . $nome_seguro;

        // Verifica que o caminho resolvido está dentro do diretório permitido
        $caminho_real = realpath($caminho_arquivo);
        if ($caminho_real === false || strpos($caminho_real, $base_dir . DIRECTORY_SEPARATOR) !== 0) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            exit;
        }

        if (!file_exists($caminho_real)) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode(['error' => 'Arquivo não encontrado no servidor']);
            exit;
        }

        // MIME type validado durante o upload (armazenado no banco via finfo)
        $mime_type = $anexo['tipo'] ?: 'application/octet-stream';

        // Sanitiza nome original para o header (evita injeção de header)
        $nome_download = rawurlencode(basename($anexo['nome_original'] ?? $nome_seguro));

        if ($action === 'view') {
            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: inline; filename="' . $nome_download . '"');
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $nome_download . '"');
        }

        header('Content-Length: ' . filesize($caminho_real));
        header('Cache-Control: private, must-revalidate');
        header('Pragma: private');
        header('X-Content-Type-Options: nosniff');

        readfile($caminho_real);
        exit;

    } catch (Exception $e) {
        error_log('[ANEXOS GET] Erro: ' . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao processar anexo']);
    }
}
