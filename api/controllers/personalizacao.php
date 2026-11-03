<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/auditoria-service.php';

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
        
        // Definir caminhos (absolutos, a partir deste arquivo: o caminho relativo dependia do diretório de
        // trabalho do PHP; auditoria 01/11/2026, SEG-13)
        $imagens = dirname(__DIR__, 2) . '/frontend/images/';
        if ($tipo === 'header') {
            $destinos = [
                $imagens . 'logo_vazado.png',
                $imagens . 'blocked.png'
            ];
        } else {
            $destinos = [$imagens . 'logo_login.png'];
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
            // Auditoria obrigatória (regra do projeto): troca de logotipo da aplicação
            try {
                (new AuditoriaService((new Database())->getConnection()))->registrar((int) $user['id'], 'alterar_logo', 'personalizacao', null,
                    null, json_encode(['tipo' => $tipo, 'arquivos' => array_map('basename', $destinos), 'tamanho' => (int) $file['size'], 'sha256' => hash_file('sha256', $temp_path)]));
            } catch (Throwable $e) {
                error_log('[PERSONALIZACAO] Erro ao auditar a troca de logo: ' . $e->getMessage());
            }
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
