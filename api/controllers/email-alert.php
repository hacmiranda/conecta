<?php
// Endpoint leve: retorna contagem de falhas de e-mail recentes (últimas 24h).
// Usado pelo frontend para exibir alertas visuais sem precisar acessar o log completo.
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/../middleware/auth.php';
$user = AuthMiddleware::verificarAuth();
if (!in_array($user['tipo'] ?? '', ['agente', 'admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();

    // Falhas nas últimas 24 horas
    $stmt = $db->query(
        "SELECT COUNT(*) AS falhas_24h,
                MAX(enviado_em) AS ultima_falha
         FROM email_logs
         WHERE status = 'falha'
           AND enviado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'falhas_24h'  => (int)($row['falhas_24h'] ?? 0),
        'ultima_falha'=> $row['ultima_falha'] ?? null,
    ]);

} catch (Exception $e) {
    // Tabela pode não existir em instâncias mais antigas — retornar zero silenciosamente
    echo json_encode([
        'success'    => true,
        'falhas_24h' => 0,
        'ultima_falha'=> null,
    ]);
}
