<?php
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config/database.php';
require_once $base_dir . '/services/email-sender.php';

$database = new Database();
$db = $database->getConnection();
$emailSender = new EmailSender($db);

try {
    // Buscar lembretes que devem ser notificados
    $query = "SELECT l.*, t.titulo as ticket_titulo, t.id as ticket_numero, t.status as ticket_status, 
                     u.nome as agente_nome, u.email as agente_email,
                     c.email as autor_email
             FROM lembretes l
             INNER JOIN tickets t ON l.ticket_id = t.id
             LEFT JOIN usuarios u ON l.agente_id = u.id
             LEFT JOIN usuarios c ON l.criado_por = c.id
             WHERE l.notificado = FALSE
             AND CONCAT(l.data_lembrete, ' ', l.hora_lembrete) <= NOW()";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $lembretes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($lembretes as $lembrete) {
        $ticket_formatado_email = str_pad($lembrete['ticket_numero'], 5, '0', STR_PAD_LEFT);
        $assunto = "🔔 [Lembrete] Ticket #" . $ticket_formatado_email . " - {$lembrete['ticket_titulo']}";
        $mensagem = "
            <h2>🔔 Lembrete de Ticket</h2>
            <p><strong>Ticket:</strong> #" . $ticket_formatado_email . " - {$lembrete['ticket_titulo']}</p>
            <p><strong>Lembrete:</strong> {$lembrete['descricao']}</p>
            <p><strong>Data/Hora:</strong> " . date('d/m/Y H:i', strtotime($lembrete['data_lembrete'] . ' ' . $lembrete['hora_lembrete'])) . "</p>
            <p><a href='http://localhost/crpdesk/frontend/index.html'>Acessar Ticket</a></p>
        ";
        
        $notif_titulo = "🔔 Lembrete: Ticket #" . $ticket_formatado_email . " - " . $lembrete['ticket_titulo'];
        $notif_msg = "Seu lembrete agendado: " . $lembrete['descricao'];

        if ($lembrete['agente_id']) {
            // Lembrete Direcionado (Apenas para o Agente selecionado)
            if ($lembrete['agente_email']) {
                $emailSender->enviarEmail($lembrete['agente_email'], $assunto, $mensagem);
            }
            // Inserir notificação visual no sistema
            $notif_query = "INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (:usuario_id, :titulo, :mensagem, :ticket_id)";
            $notif_stmt = $db->prepare($notif_query);
            $notif_stmt->bindParam(':usuario_id', $lembrete['agente_id']);
            $notif_stmt->bindParam(':titulo', $notif_titulo);
            $notif_stmt->bindParam(':mensagem', $notif_msg);
            $notif_stmt->bindParam(':ticket_id', $lembrete['ticket_id']);
            $notif_stmt->execute();
        } else {
            // "Todos os agentes": Buscar todos os agentes/admins ativos
            $agentes_query = "SELECT id, email FROM usuarios WHERE tipo IN ('agente', 'admin') AND ativo = 1";
            $agentes_stmt = $db->prepare($agentes_query);
            $agentes_stmt->execute();
            $todosAgentes = $agentes_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($todosAgentes as $ag) {
                if ($ag['email']) {
                    $emailSender->enviarEmail($ag['email'], $assunto, $mensagem);
                }
                
                $notif_query = "INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (:usuario_id, :titulo, :mensagem, :ticket_id)";
                $notif_stmt = $db->prepare($notif_query);
                $notif_stmt->bindParam(':usuario_id', $ag['id']);
                $notif_stmt->bindParam(':titulo', $notif_titulo);
                $notif_stmt->bindParam(':mensagem', $notif_msg);
                $notif_stmt->bindParam(':ticket_id', $lembrete['ticket_id']);
                $notif_stmt->execute();
            }
        }

        
        // Se o ticket está em "aguardando_data", mudar para "em_atendimento"
        if ($lembrete['ticket_status'] === 'aguardando_data') {
            $updateTicketQuery = "UPDATE tickets SET status = 'em_atendimento' WHERE id = :ticket_id";
            $updateTicketStmt = $db->prepare($updateTicketQuery);
            $updateTicketStmt->bindParam(':ticket_id', $lembrete['ticket_id']);
            $updateTicketStmt->execute();
            
            $ticket_formatado = str_pad($lembrete['ticket_numero'], 5, '0', STR_PAD_LEFT);
            echo "Ticket #" . $ticket_formatado . " mudou de 'aguardando_data' para 'em_atendimento'\n";
        }
        
        // Marcar como notificado
        $updateQuery = "UPDATE lembretes SET notificado = TRUE WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':id', $lembrete['id']);
        $updateStmt->execute();
        
        echo "Lembrete #{$lembrete['id']} notificado\n";
    }
    
    echo "Verificação de lembretes concluída\n";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
