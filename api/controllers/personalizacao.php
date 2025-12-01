<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

require_once __DIR__ . '/../middleware/auth.php';

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
        
        // Validar tipo
        if ($file['type'] !== 'image/png') {
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
                '../../frontend/images/logo_crpdesk.jpg',
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
    
    // Salvar título
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['tipo']) && $data['tipo'] === 'titulo') {
        $titulo = trim($data['titulo'] ?? '');
        
        if (strlen($titulo) > 20) {
            http_response_code(400);
            echo json_encode(['error' => 'Título muito longo']);
            exit;
        }
        
        // Salvar em arquivo de configuração
        $config = ['titulo' => $titulo];
        file_put_contents('../../frontend/config.json', json_encode($config));
        
        echo json_encode(['success' => true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Requisição inválida']);
