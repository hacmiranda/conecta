<?php
/**
 * Tempo trabalhado na demanda (17/11/2026, migração 41). Regras em TempoDemandaService.
 *
 *   GET    ?ticket_id=N                       lançamentos, totais por pessoa e total (quem pode ler a demanda)
 *   POST   {ticket_id, data_trabalho, hora_inicio + hora_fim | duracao, descricao?}   lança (próprio tempo)
 *   PUT    ?id=N {mesmos campos}              corrige um lançamento (autor, gestão do setor ou admin)
 *   DELETE ?id=N                              exclui um lançamento (autor, gestão do setor ou admin)
 * Perfis de consulta não escrevem (trava central do AuthMiddleware).
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/tempo-demanda-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo !== 'GET') {
    AuthMiddleware::verificarCSRF();
}

function respostaTempos(PDO $db, array $user, array $ticket): array {
    $dados = TempoDemandaService::listar($db, (int) $ticket['id']);
    foreach ($dados['itens'] as &$i) {
        $i['pode_editar'] = TempoDemandaService::podeEditar($user, $i, $ticket);
    }
    unset($i);
    $dados['pode_lancar'] = TempoDemandaService::podeLancar($db, $user, $ticket);
    return $dados;
}

/** Lançamento existente + a demanda dele, conferindo quem pode editar (404/403) */
function lancamentoEditavel(PDO $db, array $user, int $id): array {
    $stmt = $db->prepare("SELECT * FROM ticket_tempos WHERE id = ?");
    $stmt->execute([$id]);
    $lanc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lanc) {
        http_response_code(404);
        echo json_encode(['error' => 'Lançamento não encontrado']);
        exit;
    }
    $ticket = AcessoService::carregarTicketOuFalhar($db, $lanc['ticket_id']);
    if (!TempoDemandaService::podeEditar($user, $lanc, $ticket)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode alterar os seus lançamentos.']);
        exit;
    }
    return [$lanc, $ticket];
}

try {
    $db = (new Database())->getConnection();
    $auditoria = new AuditoriaService($db);
    $corpo = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($metodo === 'GET') {
        $ticket = AcessoService::exigirLerTicket($db, $user, $_GET['ticket_id'] ?? 0);
        echo json_encode(respostaTempos($db, $user, $ticket), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST') {
        $ticket = AcessoService::carregarTicketOuFalhar($db, $corpo['ticket_id'] ?? 0);
        if (!TempoDemandaService::podeLancar($db, $user, $ticket)) {
            http_response_code(403);
            echo json_encode(['error' => 'Só quem atende ou já atendeu esta demanda lança tempo nela.']);
            exit;
        }
        $l = TempoDemandaService::normalizar($corpo, date('Y-m-d'));
        $db->prepare("INSERT INTO ticket_tempos (ticket_id, usuario_id, data_trabalho, hora_inicio, hora_fim, minutos, descricao)
                      VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([(int) $ticket['id'], (int) $user['id'], $l['data_trabalho'], $l['hora_inicio'], $l['hora_fim'], $l['minutos'], $l['descricao']]);
        $id = (int) $db->lastInsertId();
        $auditoria->registrar($user['id'], 'lancar_tempo_demanda', 'ticket_tempos', $id, null, json_encode($l + ['ticket_id' => (int) $ticket['id']], JSON_UNESCAPED_UNICODE));
        http_response_code(201);
        echo json_encode(respostaTempos($db, $user, $ticket), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = (int) ($_GET['id'] ?? 0);
    [$lanc, $ticket] = lancamentoEditavel($db, $user, $id);

    if ($metodo === 'PUT') {
        $l = TempoDemandaService::normalizar($corpo, date('Y-m-d'));
        $db->prepare("UPDATE ticket_tempos SET data_trabalho = ?, hora_inicio = ?, hora_fim = ?, minutos = ?, descricao = ? WHERE id = ?")
           ->execute([$l['data_trabalho'], $l['hora_inicio'], $l['hora_fim'], $l['minutos'], $l['descricao'], $id]);
        $auditoria->registrar($user['id'], 'alterar_tempo_demanda', 'ticket_tempos', $id, json_encode($lanc, JSON_UNESCAPED_UNICODE), json_encode($l, JSON_UNESCAPED_UNICODE));
        echo json_encode(respostaTempos($db, $user, $ticket), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'DELETE') {
        $db->prepare("DELETE FROM ticket_tempos WHERE id = ?")->execute([$id]);
        $auditoria->registrar($user['id'], 'excluir_tempo_demanda', 'ticket_tempos', $id, json_encode($lanc, JSON_UNESCAPED_UNICODE), null);
        echo json_encode(respostaTempos($db, $user, $ticket), JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[DEMANDA TEMPOS] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar o tempo trabalhado']);
}
