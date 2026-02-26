<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

$database = new Database();
$db = $database->getConnection();

// GET - Listar usuários
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    
    try {
        $query = "SELECT id, nome, email, tipo, ativo, avatar, criado_em FROM usuarios ORDER BY nome";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $usuarios = $stmt->fetchAll();
        
        echo json_encode($usuarios);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar usuários']);
    }
}

// POST - Criar usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    AuthMiddleware::verificarCSRF();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $nome = $input['nome'] ?? '';
    $email = $input['email'] ?? '';
    $tipo = $input['tipo'] ?? 'usuario';
    
    if (empty($nome) || empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome e email são obrigatórios']);
        exit;
    }
    
    try {
        // Verificar se email já existe
        $check_query = "SELECT id FROM usuarios WHERE email = :email";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':email', $email);
        $check_stmt->execute();
        
        if ($check_stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Email já existe']);
            exit;
        }
        
        $senha_hash = password_hash('123456', PASSWORD_DEFAULT);
        
        $query = "INSERT INTO usuarios (nome, email, tipo, senha) VALUES (:nome, :email, :tipo, :senha)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':senha', $senha_hash);
        $stmt->execute();
        
        $usuario_id = $db->lastInsertId();
        
        // Registrar auditoria
        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_usuario', 'usuarios', $usuario_id, null, json_encode(['nome' => $nome, 'email' => $email, 'tipo' => $tipo]));
        
        // Buscar usuário criado
        $select_query = "SELECT id, nome, email, tipo, avatar, criado_em FROM usuarios WHERE id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $usuario_id);
        $select_stmt->execute();
        $usuario = $select_stmt->fetch();
        
        echo json_encode($usuario);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar usuário']);
    }
}

// PUT - Atualizar usuário
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    AuthMiddleware::verificarCSRF();
    
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $usuario_id = end($path_parts);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Ativar/Desativar usuário
    if (isset($input['ativo'])) {
        $ativo = $input['ativo'] ? 1 : 0;
        
        try {
            // Buscar dados do usuário ANTES da alteração
            $select_query = "SELECT nome, email, tipo, ativo FROM usuarios WHERE id = ?";
            $select_stmt = $db->prepare($select_query);
            $select_stmt->execute([$usuario_id]);
            $usuario_data = $select_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Atualizar status
            $stmt = $db->prepare("UPDATE usuarios SET ativo = ? WHERE id = ?");
            $stmt->execute([$ativo, $usuario_id]);
            
            // Registrar auditoria
            $auditoria = new AuditoriaService($db);
            $acao = $ativo ? 'ativar_usuario' : 'desativar_usuario';
            $dados_novos = $usuario_data;
            $dados_novos['ativo'] = $ativo;
            $auditoria->registrar($user['id'], $acao, 'usuarios', $usuario_id, json_encode($usuario_data), json_encode($dados_novos));
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao atualizar usuário: ' . $e->getMessage()]);
        }
        exit;
    }
    
    $nome = $input['nome'] ?? '';
    $email = $input['email'] ?? '';
    $tipo = $input['tipo'] ?? '';
    
    if (empty($nome) || empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome e email são obrigatórios']);
        exit;
    }
    
    try {
        $query = "UPDATE usuarios SET nome = :nome, email = :email, tipo = :tipo WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':id', $usuario_id);
        $stmt->execute();
        
        // Registrar auditoria
        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_usuario', 'usuarios', $usuario_id, null, json_encode(['nome' => $nome, 'email' => $email, 'tipo' => $tipo]));
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar usuário']);
    }
}

// DELETE - Excluir usuário
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $user = AuthMiddleware::verificarAuth();
        AuthMiddleware::verificarAdmin($user);
        AuthMiddleware::verificarCSRF();
        
        // Tentar obter ID da URL ou do path
        $usuario_id = $_GET['id'] ?? null;
        
        if (!$usuario_id) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $path_parts = explode('/', $path);
            $usuario_id = end($path_parts);
        }
        
        if (!$usuario_id || !is_numeric($usuario_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'ID do usuário inválido ou não fornecido']);
            exit;
        }

        // Garante que o ID é um inteiro positivo antes de qualquer uso
        $usuario_id = intval($usuario_id);
        if ($usuario_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID do usuário inválido']);
            exit;
        }

        // Nomes de tabela são hardcoded neste array (nunca vêm de input do usuário),
        // portanto a interpolação em SELECT COUNT(*) é segura por design.
        $tabelas = [
            ['tabela' => 'tickets',               'colunas' => ['usuario_id', 'agente_id', 'fechado_por'], 'msg' => 'tickets'],
            ['tabela' => 'comentarios',            'colunas' => ['usuario_id'],                             'msg' => 'comentários'],
            ['tabela' => 'ticket_historico',       'colunas' => ['usuario_id'],                             'msg' => 'histórico'],
            ['tabela' => 'ticket_historico_status','colunas' => ['usuario_id'],                             'msg' => 'histórico de status'],
            ['tabela' => 'historico',              'colunas' => ['usuario_id'],                             'msg' => 'registros de histórico'],
        ];

        foreach ($tabelas as $t) {
            $where = implode(' OR ', array_map(fn($col) => "$col = ?", $t['colunas']));
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM {$t['tabela']} WHERE $where");
            $stmt->execute(array_fill(0, count($t['colunas']), $usuario_id));
            if ($stmt->fetch()['total'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => "Usuário possui {$t['msg']} associados"]);
                exit;
            }
        }
        
        // Verificar histórico de status
        $check4 = $db->prepare("SELECT COUNT(*) as total FROM ticket_historico_status WHERE usuario_id = ?");
        $check4->execute([$usuario_id]);
        if ($check4->fetch()['total'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Usuário possui histórico de alterações de status']);
            exit;
        }
        
        // Buscar dados do usuário antes de excluir
        $select_query = "SELECT nome, email, tipo FROM usuarios WHERE id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
        $select_stmt->execute();
        $usuario_data = $select_stmt->fetch();
        
        $query = "DELETE FROM usuarios WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // Registrar auditoria
        if ($usuario_data) {
            $auditoria = new AuditoriaService($db);
            $auditoria->registrar($user['id'], 'excluir_usuario', 'usuarios', $usuario_id, json_encode($usuario_data), null);
        }
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado']);
            exit;
        }
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir usuário: ' . $e->getMessage()]);
    }
}
?>