<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/sla-service.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// GET - Listar prioridades (opcionalmente só as ativas de um setor, via ?setor_id=).
// Com setor_id cada item traz `sla`: os prazos DO SETOR em minutos úteis (e a jornada de 1 dia útil), ou null
// quando o setor está com o SLA desligado ou a prioridade não tem prazo nele. Sem setor_id vêm os valores do
// catálogo (modelo Padrão CRPRS), que a tela não deve mostrar como prazo.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    AuthMiddleware::verificarAuth(); // catálogo interno: exige login
    try {
        $setor_id = isset($_GET['setor_id']) && $_GET['setor_id'] !== '' ? (int) $_GET['setor_id'] : null;

        if ($setor_id) {
            $query = "SELECT p.nome, p.descricao, p.sem_sla, p.cor, p.icone, sp.ordem
                      FROM ticket_prioridades p
                      INNER JOIN setor_prioridades sp ON sp.prioridade_id = p.id AND sp.setor_id = :setor_id AND sp.ativo = 1
                      WHERE p.ativo = TRUE
                      ORDER BY sp.ordem";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':setor_id', $setor_id, PDO::PARAM_INT);
        } else {
            $query = "SELECT nome, descricao, sla_resposta_minutos, sla_resolucao_horas, sem_sla, cor, icone, ordem
                      FROM ticket_prioridades
                      WHERE ativo = TRUE
                      ORDER BY ordem";
            $stmt = $db->prepare($query);
        }

        $stmt->execute();
        $prioridades = $stmt->fetchAll();

        if ($setor_id) {
            $slaService = new SLAService($db);
            $jornada = $slaService->jornadaMinutos($setor_id);
            foreach ($prioridades as &$p) {
                $prazos = $slaService->prazosDoSetor($setor_id, $p['nome']);
                $p['sla'] = $prazos === null ? null : [
                    'resposta_minutos' => intdiv($prazos['resposta'], 60),
                    'resolucao_minutos' => intdiv($prazos['resolucao'], 60),
                    'jornada_minutos' => $jornada,
                ];
            }
            unset($p);
        }

        echo json_encode($prioridades);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar prioridades']);
    }
}
