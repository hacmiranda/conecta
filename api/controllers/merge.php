<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/demanda-service.php';
require_once __DIR__ . '/../services/protocolo-service.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// POST - Mesclar tickets
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true);
    $ticket_principal = $input['ticket_principal'] ?? null;
    $tickets_secundarios = $input['tickets_secundarios'] ?? [];
    
    if (!$ticket_principal || empty($tickets_secundarios)) {
        http_response_code(400);
        echo json_encode(['error' => 'Demanda principal e demandas secundárias são obrigatórias']);
        exit;
    }
    
    // Isolamento: gestor só mescla demandas do próprio setor (admin: qualquer uma)
    $tickets_secundarios = array_values(array_unique(array_map('intval', (array) $tickets_secundarios)));
    $ticket_principal = (int) $ticket_principal;
    if (in_array($ticket_principal, $tickets_secundarios, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'A demanda principal não pode ser mesclada nela mesma']);
        exit;
    }
    $dados_principal = AcessoService::exigirOperarTicket($db, $user, $ticket_principal);
    foreach ($tickets_secundarios as $id_secundario) {
        $dados_secundario = AcessoService::exigirOperarTicket($db, $user, $id_secundario);
        // Demandas de setores diferentes não se misturam (comentários e anexos mudariam de setor)
        if ((int) $dados_secundario['setor_id'] !== (int) $dados_principal['setor_id']) {
            http_response_code(400);
            echo json_encode(['error' => 'Só é possível mesclar demandas do mesmo setor']);
            exit;
        }
    }

    try {
        $db->beginTransaction();
        
        // Buscar dados do ticket principal
        $query = "SELECT categoria_id, protocolo, mesclada_em FROM tickets WHERE id = :id FOR UPDATE";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $ticket_principal);
        $stmt->execute();
        $ticket_principal_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($ticket_principal_data['mesclada_em'])) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'A demanda principal já foi mesclada em outra']);
            exit;
        }
        $categoria_principal = $ticket_principal_data['categoria_id'];
        $principal_rotulo = ProtocoloService::rotulo($ticket_principal_data['protocolo'] ?? null, (int) $ticket_principal);

        // Buscar dados dos tickets secundários
        $tickets_secundarios_info = [];
        foreach ($tickets_secundarios as $ticket_id) {
            $query = "SELECT id, titulo, descricao, categoria_id, protocolo, status, mesclada_em FROM tickets WHERE id = :id FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $ticket_id);
            $stmt->execute();
            $secundaria = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($secundaria['mesclada_em'])) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'A demanda ' . ProtocoloService::rotulo($secundaria['protocolo'] ?? null, (int) $secundaria['id']) . ' já foi mesclada em outra']);
                exit;
            }
            $tickets_secundarios_info[] = $secundaria;
        }
        
        // Transferir comentários dos tickets secundários para o principal
        foreach ($tickets_secundarios as $ticket_id) {
            $query = "UPDATE comentarios SET ticket_id = :principal WHERE ticket_id = :secundario";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':principal', $ticket_principal);
            $stmt->bindParam(':secundario', $ticket_id);
            $stmt->execute();
        }
        
        // Transferir anexos dos tickets secundários para o principal
        foreach ($tickets_secundarios as $ticket_id) {
            $query = "UPDATE anexos SET ticket_id = :principal WHERE ticket_id = :secundario";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':principal', $ticket_principal);
            $stmt->bindParam(':secundario', $ticket_id);
            $stmt->execute();
        }
        
        // Adicionar ao histórico do ticket principal
        $tickets_rotulos = implode(', ', array_map(function ($info) {
            return ProtocoloService::rotulo($info['protocolo'] ?? null, (int) $info['id']);
        }, $tickets_secundarios_info));

        // Linha do tempo da demanda principal
        $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'merge', NULL, ?)")
           ->execute([$ticket_principal, $user['id'], mb_substr((string) $tickets_rotulos, 0, 255)]);

        // Adicionar comentário detalhado de merge no ticket principal
        $comentario_merge = "🔗 **MESCLAGEM DE DEMANDAS**\n\n";
        $comentario_merge .= "Demandas mescladas: " . $tickets_rotulos . "\n\n";
        foreach ($tickets_secundarios_info as $info) {
            $rotulo_info = ProtocoloService::rotulo($info['protocolo'] ?? null, (int) $info['id']);
            $comentario_merge .= "**Demanda " . $rotulo_info . "**\n";
            $comentario_merge .= "Assunto: " . htmlspecialchars((string) $info['titulo'], ENT_QUOTES, 'UTF-8') . "\n";
            $comentario_merge .= "Descrição: " . strip_tags((string) $info['descricao']) . "\n\n";
        }

        $query = "INSERT INTO comentarios (ticket_id, usuario_id, comentario) VALUES (:ticket_id, :usuario_id, :comentario)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_principal);
        $stmt->bindParam(':usuario_id', $user['id']);
        $stmt->bindParam(':comentario', $comentario_merge);
        $stmt->execute();
        
        // Fechar tickets secundários
        foreach ($tickets_secundarios_info as $info) {
            $ticket_id = $info['id'];
            
            // Herdar categoria do ticket principal se não tiver categoria
            if (!$info['categoria_id'] && $categoria_principal) {
                $query = "UPDATE tickets SET categoria_id = :categoria WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':categoria', $categoria_principal);
                $stmt->bindParam(':id', $ticket_id);
                $stmt->execute();
            }
            
            // Vínculo com a principal: respostas por e-mail com o protocolo desta seguem para a principal,
            // e a mesclada fica fora dos indicadores (não conta como atendida)
            $db->prepare("UPDATE tickets SET mesclada_em = ? WHERE id = ?")->execute([$ticket_principal, $ticket_id]);

            // Encerra pela regra única (SLA, histórico, janelas de setor/responsável, lembretes).
            // Já arquivada (encerrada ou cancelada, mesclagem pelo Arquivo) continua como está.
            if (!in_array($info['status'], AcessoService::STATUS_ARQUIVO, true)) {
                DemandaService::mudarStatus($db, (int) $ticket_id, 'encerrado', (int) $user['id']);
            }

            // Linha do tempo da demanda mesclada
            $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'merge', NULL, ?)")
               ->execute([$ticket_id, $user['id'], $principal_rotulo]);

            // Adicionar comentário detalhado nos tickets fechados
            $comentario_fechado = "🔗 **TICKET MESCLADO**\n\n";
            $comentario_fechado .= "Este ticket foi mesclado ao ticket principal " . $principal_rotulo;

            $query = "INSERT INTO comentarios (ticket_id, usuario_id, comentario) VALUES (:ticket_id, :usuario_id, :comentario)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->bindParam(':usuario_id', $user['id']);
            $stmt->bindParam(':comentario', $comentario_fechado);
            $stmt->execute();
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Demandas mescladas com sucesso',
            'ticket_principal' => $ticket_principal,
            'tickets_mesclados' => count($tickets_secundarios)
        ]);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        http_response_code(500);
        error_log('[MERGE] Erro: ' . $e->getMessage());
        echo json_encode(['error' => 'Erro ao mesclar demandas']);
    }
}
?>