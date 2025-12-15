<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

// GET - Download ou visualizar anexo
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $anexo_id = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? 'download'; // download ou view
    
    if (!$anexo_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID do anexo é obrigatório']);
        exit;
    }
    
    try {
        // Buscar anexo no banco
        $query = "SELECT * FROM anexos WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $anexo_id);
        $stmt->execute();
        $anexo = $stmt->fetch();
        
        if (!$anexo) {
            http_response_code(404);
            echo json_encode(['error' => 'Anexo não encontrado']);
            exit;
        }
        
        // Caminho do arquivo (absoluto)
        $caminho_arquivo = dirname(__DIR__, 2) . '/backend/anexos/' . $anexo['nome_arquivo'];
        
        // Log de debug
        error_log("[ANEXO DEBUG] ID: {$anexo_id}");
        error_log("[ANEXO DEBUG] Nome arquivo DB: {$anexo['nome_arquivo']}");
        error_log("[ANEXO DEBUG] Caminho completo: $caminho_arquivo");
        error_log("[ANEXO DEBUG] Arquivo existe? " . (file_exists($caminho_arquivo) ? 'SIM' : 'NAO'));
        
        if (!file_exists($caminho_arquivo)) {
            error_log("[ANEXO DEBUG] ERRO 404 - Arquivo não encontrado!");
            http_response_code(404);
            echo json_encode(['error' => 'Arquivo não encontrado no servidor', 'caminho' => $caminho_arquivo, 'nome_db' => $anexo['nome_arquivo']]);
            exit;
        }
        
        // Definir headers apropriados
        $mime_type = $anexo['tipo'] ?: 'application/octet-stream';
        
        if ($action === 'view') {
            // Para visualização inline
            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: inline; filename="' . $anexo['nome_original'] . '"');
        } else {
            // Para download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $anexo['nome_original'] . '"');
        }
        
        header('Content-Length: ' . filesize($caminho_arquivo));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Enviar arquivo
        readfile($caminho_arquivo);
        exit;
        
    } catch (Exception $e) {
        error_log("[ANEXO DEBUG] Exception: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao processar anexo']);
    }
}
?>