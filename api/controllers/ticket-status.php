<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

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

// PUT - Atualizar status do ticket
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarCSRF();
    
    $ticket_id = $_GET['ticket_id'] ?? null;
    $input = json_decode(file_get_contents('php://input'), true);
    $novo_status = $input['status'] ?? null;
    $observacoes = $input['observacoes'] ?? null;
    
    if (!$ticket_id || !$novo_status) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da demanda e status são obrigatórios']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Isolamento: admin, gestor do setor ou operador responsável
        AcessoService::exigirOperarTicket($db, $user, $ticket_id);

        // O status precisa existir no catálogo
        $chk = $db->prepare("SELECT 1 FROM ticket_status WHERE nome = ? AND ativo = 1");
        $chk->execute([$novo_status]);
        if (!$chk->fetchColumn()) {
            throw new Exception('Status inválido');
        }

        // Buscar ticket atual
        $query = "SELECT status, prioridade FROM tickets WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $ticket_id);
        $stmt->execute();
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            throw new Exception('Demanda não encontrada');
        }
        
        $status_anterior = $ticket['status'];
        
        // Atualizar status do ticket
        $update_query = "UPDATE tickets SET status = :status, status_anterior = :status_anterior";
        $params = [
            ':status' => $novo_status,
            ':status_anterior' => $status_anterior,
            ':id' => $ticket_id
        ];
        
        // Adicionar campos específicos baseado no status
        if ($novo_status === 'encerrado') {
            $update_query .= ", fechado_por = :fechado_por, fechado_em = NOW()";
            $params[':fechado_por'] = $user['id'];
        } elseif ($status_anterior === 'encerrado' && $novo_status !== 'encerrado') {
            $update_query .= ", fechado_por = NULL, fechado_em = NULL";
        }
        
        if ($novo_status === 'reaberto') {
            $update_query .= ", reaberto_contador = reaberto_contador + 1";
        }
        
        $update_query .= " WHERE id = :id";
        
        $stmt = $db->prepare($update_query);
        $stmt->execute($params);
        
        // Adicionar observações ao histórico se fornecidas
        if ($observacoes) {
            $hist_query = "UPDATE ticket_historico_status 
                          SET observacoes = :observacoes 
                          WHERE ticket_id = :ticket_id 
                          AND status_novo = :status 
                          AND data_fim IS NULL";
            $hist_stmt = $db->prepare($hist_query);
            $hist_stmt->execute([
                ':observacoes' => $observacoes,
                ':ticket_id' => $ticket_id,
                ':status' => $novo_status
            ]);
        }
        
        // Verificar e atualizar SLA vencido
        verificarSLAVencido($db, $ticket_id);
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Status atualizado com sucesso']);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[TICKET-STATUS PUT] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar status']);
    }
}

// Função para verificar SLA vencido
function verificarSLAVencido($db, $ticket_id) {
    $query = "SELECT 
                t.criado_em,
                s.sla_resposta_minutos,
                s.sla_resolucao_horas,
                s.primeira_resposta_em,
                s.resolvido_em,
                s.tempo_pausado_segundos,
                s.pausado
              FROM tickets t
              JOIN ticket_sla s ON t.id = s.ticket_id
              WHERE t.id = :ticket_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':ticket_id', $ticket_id);
    $stmt->execute();
    $sla = $stmt->fetch();
    
    if (!$sla) return;
    
    $agora = new DateTime();
    $criado_em = new DateTime($sla['criado_em']);
    $tempo_decorrido = $agora->getTimestamp() - $criado_em->getTimestamp();
    
    if (!$sla['pausado']) {
        $tempo_decorrido -= $sla['tempo_pausado_segundos'];
    }
    
    $sla_resposta_vencido = false;
    $sla_resolucao_vencido = false;
    
    // Verificar SLA de resposta
    if (!$sla['primeira_resposta_em']) {
        $sla_resposta_segundos = $sla['sla_resposta_minutos'] * 60;
        $sla_resposta_vencido = $tempo_decorrido > $sla_resposta_segundos;
    }
    
    // Verificar SLA de resolução
    if (!$sla['resolvido_em']) {
        $sla_resolucao_segundos = $sla['sla_resolucao_horas'] * 3600;
        $sla_resolucao_vencido = $tempo_decorrido > $sla_resolucao_segundos;
    }
    
    // Atualizar flags de SLA vencido
    $update_sla = "UPDATE ticket_sla 
                   SET sla_resposta_vencido = :resp_vencido,
                       sla_resolucao_vencido = :resol_vencido
                   WHERE ticket_id = :ticket_id";
    
    $stmt = $db->prepare($update_sla);
    $stmt->execute([
        ':resp_vencido' => $sla_resposta_vencido,
        ':resol_vencido' => $sla_resolucao_vencido,
        ':ticket_id' => $ticket_id
    ]);
    
    // Atualizar flag no ticket
    $sla_vencido = $sla_resposta_vencido || $sla_resolucao_vencido;
    $update_ticket = "UPDATE tickets SET sla_vencido = :sla_vencido WHERE id = :ticket_id";
    $stmt = $db->prepare($update_ticket);
    $stmt->execute([
        ':sla_vencido' => $sla_vencido,
        ':ticket_id' => $ticket_id
    ]);
}
?>