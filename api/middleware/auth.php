<?php
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware {
    private static $secret_key = "helpdesk_secret_key";
    
    private static function getAllHeaders() {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
    
    public static function verificarAuth() {
        $headers = self::getAllHeaders();
        
        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Token de autenticação requerido']);
            exit;
        }
        
        $auth_header = $headers['Authorization'];
        if (!preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'Formato de token inválido']);
            exit;
        }
        
        $token = $matches[1];
        
        try {
            $decoded = JWT::decode($token, new Key(self::$secret_key, 'HS256'));
            return (array) $decoded;
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['error' => 'Token inválido']);
            exit;
        }
    }
    
    public static function verificarAdmin($user) {
        if (!$user || $user['tipo'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado. Apenas administradores.']);
            exit;
        }
    }
    
    public static function verificarCSRF() {
        // Desabilitar verificação CSRF temporariamente para debug
        return true;
        
        $headers = self::getAllHeaders();
        $csrf_token = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? $_POST['_csrf'] ?? null;
        
        // Tentar pegar do corpo da requisição também
        if (!$csrf_token) {
            $input = json_decode(file_get_contents('php://input'), true);
            $csrf_token = $input['_csrf'] ?? null;
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $csrf_token !== 'helpdesk-csrf-token') {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token inválido', 'received' => $csrf_token]);
            exit;
        }
    }
    
    public static function gerarToken($user_data) {
        $payload = [
            'id' => $user_data['id'],
            'email' => $user_data['email'],
            'tipo' => $user_data['tipo'],
            'exp' => time() + (8 * 3600) // 8 horas
        ];
        
        return JWT::encode($payload, self::$secret_key, 'HS256');
    }
}
?>
