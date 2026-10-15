<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

/**
 * Catálogo de status (somente leitura). A alteração do status de uma demanda é feita
 * em tickets.php (PUT), que valida o setor, registra histórico/SLA e avisa os envolvidos.
 */
// GET - Listar status disponíveis (opcionalmente só os ativos de um setor, via ?setor_id=)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    AuthMiddleware::verificarAuth(); // catálogo interno: exige login
    try {
        $setor_id = isset($_GET['setor_id']) && $_GET['setor_id'] !== '' ? (int) $_GET['setor_id'] : null;

        if ($setor_id) {
            $query = "SELECT t.*
                      FROM ticket_status t
                      INNER JOIN setor_status st ON st.status_id = t.id AND st.setor_id = :setor_id AND st.ativo = 1
                      WHERE t.ativo = TRUE
                      ORDER BY st.ordem";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':setor_id', $setor_id, PDO::PARAM_INT);
        } else {
            $query = "SELECT * FROM ticket_status WHERE ativo = TRUE ORDER BY ordem";
            $stmt = $db->prepare($query);
        }

        $stmt->execute();
        $status = $stmt->fetchAll();

        echo json_encode($status);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar status']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Para alterar o status use tickets.php.']);
}
