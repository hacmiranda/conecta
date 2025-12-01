<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

try {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user);
    
    $database = new Database();
    $db = $database->getConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $filtros = [];
        $params = [];
        
        if (isset($_GET['acao'])) {
            $filtros[] = "a.acao = :acao";
            $params[':acao'] = $_GET['acao'];
        }
        
        if (isset($_GET['tabela'])) {
            $filtros[] = "a.tabela = :tabela";
            $params[':tabela'] = $_GET['tabela'];
        }
        
        if (isset($_GET['usuario_id'])) {
            $filtros[] = "a.usuario_id = :usuario_id";
            $params[':usuario_id'] = $_GET['usuario_id'];
        }
        
        if (isset($_GET['data_inicio'])) {
            $filtros[] = "DATE(a.criado_em) >= :data_inicio";
            $params[':data_inicio'] = $_GET['data_inicio'];
        }
        
        if (isset($_GET['data_fim'])) {
            $filtros[] = "DATE(a.criado_em) <= :data_fim";
            $params[':data_fim'] = $_GET['data_fim'];
        }
        
        $where = count($filtros) > 0 ? 'WHERE ' . implode(' AND ', $filtros) : '';
        
        $query = "SELECT a.*, 
                         u.nome as usuario_nome, 
                         u.email as usuario_email,
                         CASE 
                             WHEN a.acao IN ('ativar_usuario', 'desativar_usuario', 'excluir_usuario') THEN
                                 CONCAT(
                                     COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dados_anteriores, '$.nome')), ''),
                                     ' (',
                                     COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dados_anteriores, '$.email')), ''),
                                     ')'
                                 )
                             ELSE NULL
                         END as usuario_afetado
                  FROM auditoria a
                  LEFT JOIN usuarios u ON a.usuario_id = u.id
                  $where
                  ORDER BY a.criado_em DESC
                  LIMIT 500";
        
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($registros);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
