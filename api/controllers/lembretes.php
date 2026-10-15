<?php
/**
 * Lembretes de uma demanda.
 * - GET ?ticket_id=  lembretes da demanda (quem pode ver a demanda)
 * - GET              lembretes pendentes do próprio usuário (destinatário ou autor)
 * - POST             cria (quem pode operar a demanda); destinatário opcional, do setor da demanda
 * - DELETE ?id=      exclui um lembrete ainda não enviado (autor, gestor do setor ou admin)
 * O envio é feito pelo agendador (api/cron/verificar-lembretes.php).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
if (($user['tipo'] ?? '') === 'cliente') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

$db = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$camposLista = "l.id, l.ticket_id, l.data_lembrete, l.hora_lembrete, l.descricao, l.agente_id, l.notificado,
                l.cancelado_em, l.cancelado_motivo, l.criado_por, l.criado_em,
                u.nome AS agente_nome, c.nome AS criado_por_nome";

try {
    if ($method === 'GET') {
        if (isset($_GET['ticket_id'])) {
            $ticket = AcessoService::exigirVerTicket($db, $user, $_GET['ticket_id']);
            $stmt = $db->prepare("SELECT $camposLista
                                  FROM lembretes l
                                  LEFT JOIN usuarios u ON l.agente_id = u.id
                                  LEFT JOIN usuarios c ON l.criado_por = c.id
                                  WHERE l.ticket_id = ?
                                  ORDER BY l.data_lembrete ASC, l.hora_lembrete ASC");
            $stmt->execute([(int) $ticket['id']]);
        } else {
            // Somente os lembretes do próprio usuário (nunca os de outros setores)
            $stmt = $db->prepare("SELECT $camposLista, t.titulo AS ticket_titulo
                                  FROM lembretes l
                                  INNER JOIN tickets t ON l.ticket_id = t.id
                                  LEFT JOIN usuarios u ON l.agente_id = u.id
                                  LEFT JOIN usuarios c ON l.criado_por = c.id
                                  WHERE l.notificado = 0 AND l.cancelado_em IS NULL
                                    AND (l.agente_id = :eu OR l.criado_por = :eu2)
                                    AND TIMESTAMP(l.data_lembrete, l.hora_lembrete) >= NOW()
                                  ORDER BY l.data_lembrete ASC, l.hora_lembrete ASC");
            $stmt->execute([':eu' => (int) $user['id'], ':eu2' => (int) $user['id']]);
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    AuthMiddleware::verificarCSRF();

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $ticket = AcessoService::exigirOperarTicket($db, $user, $data['ticket_id'] ?? 0);

        if (in_array($ticket['status'], AcessoService::STATUS_ARQUIVO, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Não é possível agendar lembrete em demanda encerrada ou cancelada']);
            exit;
        }

        $dataLembrete = (string) ($data['data_lembrete'] ?? '');
        $horaLembrete = (string) ($data['hora_lembrete'] ?? '');
        $quando = DateTime::createFromFormat('Y-m-d H:i', $dataLembrete . ' ' . substr($horaLembrete, 0, 5));
        if (!$quando || $quando->format('Y-m-d') !== $dataLembrete) {
            http_response_code(400);
            echo json_encode(['error' => 'Data ou hora inválida']);
            exit;
        }
        if ($quando < new DateTime('-1 minute')) {
            http_response_code(400);
            echo json_encode(['error' => 'Escolha uma data e hora futuras']);
            exit;
        }

        $descricao = HtmlSanitizer::texto($data['descricao'] ?? '', 500);
        if ($descricao === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Descreva o lembrete']);
            exit;
        }

        // Destinatário opcional: operador/gestor ativo do setor da demanda
        $agenteId = !empty($data['agente_id']) ? (int) $data['agente_id'] : null;
        if ($agenteId !== null) {
            $dest = $db->prepare("SELECT 1 FROM usuarios WHERE id = ? AND ativo = 1 AND tipo IN ('operador','gestor') AND setor_id = ?");
            $dest->execute([$agenteId, (int) $ticket['setor_id']]);
            if (!$dest->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'O destinatário precisa ser da equipe do setor da demanda']);
                exit;
            }
        }

        $ins = $db->prepare("INSERT INTO lembretes (ticket_id, data_lembrete, hora_lembrete, descricao, agente_id, criado_por)
                             VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([(int) $ticket['id'], $quando->format('Y-m-d'), $quando->format('H:i:00'), $descricao, $agenteId, (int) $user['id']]);
        $lembreteId = (int) $db->lastInsertId();

        (new AuditoriaService($db))->registrar($user['id'], 'criar_lembrete', 'lembretes', $lembreteId, null,
            json_encode(['ticket_id' => (int) $ticket['id'], 'quando' => $quando->format('Y-m-d H:i'), 'agente_id' => $agenteId]));

        $stmt = $db->prepare("SELECT $camposLista FROM lembretes l
                              LEFT JOIN usuarios u ON l.agente_id = u.id
                              LEFT JOIN usuarios c ON l.criado_por = c.id
                              WHERE l.id = ?");
        $stmt->execute([$lembreteId]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }

    if ($method === 'DELETE') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID do lembrete não fornecido']);
            exit;
        }

        $sel = $db->prepare("SELECT * FROM lembretes WHERE id = ?");
        $sel->execute([$id]);
        $lembrete = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$lembrete) {
            http_response_code(404);
            echo json_encode(['error' => 'Lembrete não encontrado']);
            exit;
        }

        // Autor do lembrete, ou quem gere o setor da demanda (gestor do setor / admin)
        $ticket = AcessoService::carregarTicketOuFalhar($db, $lembrete['ticket_id']);
        $ehAutor = (int) $lembrete['criado_por'] === (int) $user['id'] && AcessoService::podeVerTicket($user, $ticket);
        $ehGestao = in_array($user['tipo'], ['gestor', 'admin'], true) && AcessoService::podeOperarTicket($user, $ticket);
        if (!$ehAutor && !$ehGestao) {
            http_response_code(403);
            echo json_encode(['error' => 'Você não pode excluir este lembrete']);
            exit;
        }
        if ((int) $lembrete['notificado'] === 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Lembrete já enviado não pode ser excluído']);
            exit;
        }

        $db->prepare("DELETE FROM lembretes WHERE id = ?")->execute([$id]);
        (new AuditoriaService($db))->registrar($user['id'], 'excluir_lembrete', 'lembretes', $id, json_encode($lembrete), null);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
} catch (Exception $e) {
    error_log('[LEMBRETES] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar lembrete']);
}
