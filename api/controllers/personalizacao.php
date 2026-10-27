<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Verificar autenticação e admin
$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user);
AuthMiddleware::verificarCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Upload de logo
    if (isset($_FILES['logo'])) {
        $tipo = $_POST['tipo'] ?? '';
        
        if (!in_array($tipo, ['header', 'login'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Tipo inválido']);
            exit;
        }
        
        $file = $_FILES['logo'];
        
        // Validar tipo pelo conteúdo real (o "type" do upload é o que o navegador diz, não o arquivo)
        $mimeReal = '';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeReal = (string) finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
        $imagem = $mimeReal === 'image/png' ? @getimagesize($file['tmp_name']) : false;
        if (!$imagem || ($imagem[2] ?? null) !== IMAGETYPE_PNG) {
            http_response_code(400);
            echo json_encode(['error' => 'Apenas PNG é aceito']);
            exit;
        }
        
        // Validar tamanho
        if ($file['size'] > 500000) {
            http_response_code(400);
            echo json_encode(['error' => 'Arquivo muito grande']);
            exit;
        }
        
        // Definir caminhos
        if ($tipo === 'header') {
            $destinos = [
                '../../frontend/images/logo_vazado.png',
                '../../frontend/images/blocked.png'
            ];
        } else {
            $destinos = ['../../frontend/images/logo_login.png'];
        }
        
        // Fazer backup e salvar
        $temp_path = $file['tmp_name'];
        $sucesso = true;
        
        foreach ($destinos as $destino) {
            // Backup
            if (file_exists($destino)) {
                copy($destino, $destino . '.backup');
            }
            
            // Copiar arquivo
            if (!copy($temp_path, $destino)) {
                $sucesso = false;
                break;
            }
        }
        
        if ($sucesso) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao salvar arquivo']);
        }
        exit;
    }
    
    // A opção de título do cabeçalho foi descontinuada: o cabeçalho é fixo (logo + Conecta CRPRS + setor)
}

http_response_code(400);
echo json_encode(['error' => 'Requisição inválida']);
