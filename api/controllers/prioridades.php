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

// GET - Listar prioridades com SLA (opcionalmente só as ativas de um setor, via ?setor_id=)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $setor_id = isset($_GET['setor_id']) && $_GET['setor_id'] !== '' ? (int) $_GET['setor_id'] : null;

        if ($setor_id) {
            $query = "SELECT p.nome, p.descricao, p.sla_resposta_minutos, p.sla_resolucao_horas, p.cor, p.icone, sp.ordem
                      FROM ticket_prioridades p
                      INNER JOIN setor_prioridades sp ON sp.prioridade_id = p.id AND sp.setor_id = :setor_id AND sp.ativo = 1
                      WHERE p.ativo = TRUE
                      ORDER BY sp.ordem";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':setor_id', $setor_id, PDO::PARAM_INT);
        } else {
            $query = "SELECT nome, descricao, sla_resposta_minutos, sla_resolucao_horas, cor, icone, ordem
                      FROM ticket_prioridades
                      WHERE ativo = TRUE
                      ORDER BY ordem";
            $stmt = $db->prepare($query);
        }

        $stmt->execute();
        $prioridades = $stmt->fetchAll();

        echo json_encode($prioridades);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar prioridades']);
    }
}
?>