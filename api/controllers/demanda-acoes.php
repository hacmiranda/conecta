<?php
/**
 * Ações de fluxo multi-setor sobre uma demanda: atribuir, devolver ao supervisor,
 * repassar a um colega do mesmo setor, ou transferir para outro setor.
 * Todas as ações exigem um comentário (registrado na timeline da demanda) e são
 * auditadas em ticket_historico + auditoria_tickets.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarCSRF();

if (($user['tipo'] ?? '') === 'cliente') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$ticket_id = isset($input['ticket_id']) ? (int) $input['ticket_id'] : 0;
$acao = trim($input['acao'] ?? '');
$comentario = trim(strip_tags($input['comentario'] ?? ''));

$acoes_validas = ['atribuir', 'devolver', 'reatribuir_colega', 'transferir_setor'];

if (!$ticket_id || !in_array($acao, $acoes_validas, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'ticket_id e acao (válida) são obrigatórios']);
    exit;
}

if (empty($comentario)) {
    http_response_code(400);
    echo json_encode(['error' => 'Um comentário é obrigatório para registrar esta ação']);
    exit;
}

try {
    $ticket_stmt = $db->prepare("SELECT id, setor_id, agente_id, status, usuario_id FROM tickets WHERE id = ?");
    $ticket_stmt->execute([$ticket_id]);
    $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['error' => 'Demanda não encontrada']);
        exit;
    }

    // Todas as ações exigem que o usuário logado enxergue o setor atual da demanda
    // (admin sempre; supervisor no próprio setor; usuario só se for o responsável atual).
    $tipo = $user['tipo'];
    $mesmoSetorAtual = AuthMiddleware::mesmoSetor($user, $ticket['setor_id'] !== null ? (int) $ticket['setor_id'] : null);

    if ($tipo === 'usuario' && (int) ($ticket['agente_id'] ?? 0) !== (int) $user['id'] && !$mesmoSetorAtual) {
        http_response_code(403);
        echo json_encode(['error' => 'Você não tem acesso a esta demanda']);
        exit;
    }
    if ($tipo === 'supervisor' && !$mesmoSetorAtual) {
        http_response_code(403);
        echo json_encode(['error' => 'Esta demanda não pertence ao seu setor']);
        exit;
    }
    if ($acao === 'atribuir') {
        AuthMiddleware::verificarSupervisor($user);
    }
    if (in_array($acao, ['devolver', 'reatribuir_colega', 'transferir_setor'], true)
        && $tipo === 'usuario' && (int) ($ticket['agente_id'] ?? 0) !== (int) $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas o responsável atual pela demanda pode executar esta ação']);
        exit;
    }

    $db->beginTransaction();
    $auditoria = new AuditoriaService($db);

    $nome_ator_stmt = $db->prepare("SELECT nome FROM usuarios WHERE id = ?");
    $nome_ator_stmt->execute([$user['id']]);
    $nome_ator = $nome_ator_stmt->fetchColumn() ?: 'Sistema';

    $notificar = []; // [usuario_id => mensagem]

    if ($acao === 'atribuir') {
        $destino_id = isset($input['usuario_id']) ? (int) $input['usuario_id'] : 0;
        if (!$destino_id) {
            throw new InvalidArgumentException('usuario_id do destinatário é obrigatório');
        }

        $destino_stmt = $db->prepare("SELECT id, nome, setor_id, tipo, ativo FROM usuarios WHERE id = ?");
        $destino_stmt->execute([$destino_id]);
        $destino = $destino_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$destino || !$destino['ativo'] || !AuthMiddleware::mesmoSetor($user, (int) $destino['setor_id'])) {
            throw new InvalidArgumentException('Destinatário inválido ou fora do seu setor');
        }

        $agente_anterior_nome = 'Não atribuído';
        if ($ticket['agente_id']) {
            $ant_stmt = $db->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $ant_stmt->execute([$ticket['agente_id']]);
            $agente_anterior_nome = $ant_stmt->fetchColumn() ?: $agente_anterior_nome;
        }

        $status_novo = $ticket['status'] === 'novo' ? 'em_atendimento' : $ticket['status'];
        $upd = $db->prepare("UPDATE tickets SET agente_id = :agente_id, atribuido_em = NOW(), status = :status WHERE id = :id");
        $upd->execute([':agente_id' => $destino_id, ':status' => $status_novo, ':id' => $ticket_id]);

        if ($ticket['agente_id']) {
            $db->prepare("UPDATE ticket_agente_log SET saiu_em = NOW() WHERE ticket_id = ? AND usuario_id = ? AND saiu_em IS NULL")
               ->execute([$ticket_id, $ticket['agente_id']]);
        }
        $db->prepare("INSERT INTO ticket_agente_log (ticket_id, usuario_id) VALUES (?, ?)")->execute([$ticket_id, $destino_id]);

        $hist_valor_anterior = $agente_anterior_nome;
        $hist_valor_novo = $destino['nome'];
        $hist_acao = 'atribuicao_supervisor';

        $auditoria->registrarTicket($ticket_id, $user['id'], 'agente', $agente_anterior_nome, $destino['nome']);
        $notificar[$destino_id] = "A demanda (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") foi atribuída a você por {$nome_ator}.";

    } elseif ($acao === 'devolver') {
        $agente_anterior_nome = $nome_ator;

        $upd = $db->prepare("UPDATE tickets SET agente_id = NULL, atribuido_em = NULL WHERE id = :id");
        $upd->execute([':id' => $ticket_id]);

        if ($ticket['agente_id']) {
            $db->prepare("UPDATE ticket_agente_log SET saiu_em = NOW() WHERE ticket_id = ? AND usuario_id = ? AND saiu_em IS NULL")
               ->execute([$ticket_id, $ticket['agente_id']]);
        }

        $hist_valor_anterior = $agente_anterior_nome;
        $hist_valor_novo = 'Não atribuído';
        $hist_acao = 'devolucao';

        $auditoria->registrarTicket($ticket_id, $user['id'], 'agente', $agente_anterior_nome, 'Não atribuído');

        // Notifica os supervisores do setor atual da demanda
        if ($ticket['setor_id']) {
            $sup_stmt = $db->prepare("SELECT id FROM usuarios WHERE setor_id = ? AND tipo = 'supervisor' AND ativo = 1");
            $sup_stmt->execute([$ticket['setor_id']]);
            foreach ($sup_stmt->fetchAll(PDO::FETCH_COLUMN) as $sup_id) {
                $notificar[$sup_id] = "{$nome_ator} devolveu a demanda (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") para o setor.";
            }
        }

    } elseif ($acao === 'reatribuir_colega') {
        $destino_id = isset($input['usuario_id']) ? (int) $input['usuario_id'] : 0;
        if (!$destino_id) {
            throw new InvalidArgumentException('usuario_id do colega é obrigatório');
        }

        $destino_stmt = $db->prepare("SELECT id, nome, setor_id, ativo FROM usuarios WHERE id = ?");
        $destino_stmt->execute([$destino_id]);
        $destino = $destino_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$destino || !$destino['ativo'] || (int) $destino['setor_id'] !== (int) $ticket['setor_id']) {
            throw new InvalidArgumentException('Colega inválido ou fora do setor da demanda');
        }

        $upd = $db->prepare("UPDATE tickets SET agente_id = :agente_id, atribuido_em = NOW() WHERE id = :id");
        $upd->execute([':agente_id' => $destino_id, ':id' => $ticket_id]);

        if ($ticket['agente_id']) {
            $db->prepare("UPDATE ticket_agente_log SET saiu_em = NOW() WHERE ticket_id = ? AND usuario_id = ? AND saiu_em IS NULL")
               ->execute([$ticket_id, $ticket['agente_id']]);
        }
        $db->prepare("INSERT INTO ticket_agente_log (ticket_id, usuario_id) VALUES (?, ?)")->execute([$ticket_id, $destino_id]);

        $hist_valor_anterior = $nome_ator;
        $hist_valor_novo = $destino['nome'];
        $hist_acao = 'reatribuicao_colega';

        $auditoria->registrarTicket($ticket_id, $user['id'], 'agente', $nome_ator, $destino['nome']);
        $notificar[$destino_id] = "{$nome_ator} repassou a demanda (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") para você.";

    } else { // transferir_setor
        $setor_destino_id = isset($input['setor_id']) ? (int) $input['setor_id'] : 0;
        if (!$setor_destino_id) {
            throw new InvalidArgumentException('setor_id de destino é obrigatório');
        }
        if ($ticket['setor_id'] && $setor_destino_id === (int) $ticket['setor_id']) {
            throw new InvalidArgumentException('A demanda já está neste setor');
        }

        $setor_stmt = $db->prepare("SELECT id, nome FROM setores WHERE id = ? AND ativo = 1");
        $setor_stmt->execute([$setor_destino_id]);
        $setor_destino = $setor_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$setor_destino) {
            throw new InvalidArgumentException('Setor de destino inválido');
        }

        $setor_origem_nome = 'Nenhum';
        if ($ticket['setor_id']) {
            $orig_stmt = $db->prepare("SELECT nome FROM setores WHERE id = ?");
            $orig_stmt->execute([$ticket['setor_id']]);
            $setor_origem_nome = $orig_stmt->fetchColumn() ?: $setor_origem_nome;
        }

        // Fecha a janela atual em ticket_setor_log e abre uma nova para o setor destino
        if ($ticket['setor_id']) {
            $fechar_stmt = $db->prepare("UPDATE ticket_setor_log SET saiu_em = NOW() WHERE ticket_id = ? AND setor_id = ? AND saiu_em IS NULL");
            $fechar_stmt->execute([$ticket_id, $ticket['setor_id']]);
        }
        $abrir_stmt = $db->prepare("INSERT INTO ticket_setor_log (ticket_id, setor_id) VALUES (?, ?)");
        $abrir_stmt->execute([$ticket_id, $setor_destino_id]);

        $upd = $db->prepare("UPDATE tickets SET setor_id = :setor_id, agente_id = NULL, atribuido_em = NULL WHERE id = :id");
        $upd->execute([':setor_id' => $setor_destino_id, ':id' => $ticket_id]);

        if ($ticket['agente_id']) {
            $db->prepare("UPDATE ticket_agente_log SET saiu_em = NOW() WHERE ticket_id = ? AND usuario_id = ? AND saiu_em IS NULL")
               ->execute([$ticket_id, $ticket['agente_id']]);
        }

        $hist_valor_anterior = $setor_origem_nome;
        $hist_valor_novo = $setor_destino['nome'];
        $hist_acao = 'transferencia_setor';

        $auditoria->registrarTicket($ticket_id, $user['id'], 'setor', $setor_origem_nome, $setor_destino['nome']);

        $sup_stmt = $db->prepare("SELECT id FROM usuarios WHERE setor_id = ? AND tipo = 'supervisor' AND ativo = 1");
        $sup_stmt->execute([$setor_destino_id]);
        foreach ($sup_stmt->fetchAll(PDO::FETCH_COLUMN) as $sup_id) {
            $notificar[$sup_id] = "{$nome_ator} transferiu uma demanda (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") para o setor {$setor_destino['nome']}.";
        }
    }

    // Comentário obrigatório: registrado na timeline da demanda
    $com_stmt = $db->prepare("INSERT INTO comentarios (ticket_id, usuario_id, comentario) VALUES (?, ?, ?)");
    $com_stmt->execute([$ticket_id, $user['id'], $comentario]);

    // Histórico da ação
    $hist_stmt = $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, ?, ?, ?)");
    $hist_stmt->execute([$ticket_id, $user['id'], $hist_acao, $hist_valor_anterior, $hist_valor_novo]);

    $db->commit();

    // Notificações internas (fora da transação — não deve derrubar a ação principal)
    if (!empty($notificar)) {
        $notif_stmt = $db->prepare("INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, ?, ?, ?)");
        foreach ($notificar as $destino_id => $mensagem) {
            try {
                $notif_stmt->execute([$destino_id, 'Demanda', $mensagem, $ticket_id]);
            } catch (Exception $e) {
                error_log('[DEMANDA-ACOES] Erro ao notificar usuário ' . $destino_id . ': ' . $e->getMessage());
            }
        }
    }

    echo json_encode(['success' => true]);

} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[DEMANDA-ACOES] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar ação da demanda']);
}
