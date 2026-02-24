<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao conectar ao banco: ' . $e->getMessage()]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// PUT - Marcar comentários como lidos
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['marcar_lido'])) {
    $ticket_id = $_GET['ticket_id'] ?? null;
    $usuario_id = $_GET['usuario_id'] ?? 1; // TODO: Pegar do token JWT
    
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID do ticket é obrigatório']);
        exit;
    }
    
    try {
        $query = "DELETE FROM comentarios_nao_lidos WHERE ticket_id = :ticket_id AND usuario_id = :usuario_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao marcar como lido']);
    }
}


// POST - Adicionar comentário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Obter usuário logado do token JWT (com fallback)
    $usuario_id = null;
    try {
        require_once __DIR__ . '/../middleware/auth.php';
        $user = AuthMiddleware::verificarAuth();
        $usuario_id = $user['id'];
        error_log("[COMENTARIOS] Usuário autenticado via JWT: ID {$usuario_id}");
    } catch (Exception $e) {
        // Fallback: tentar pegar do POST/input (compatibilidade)
        error_log("[COMENTARIOS] JWT falhou, usando fallback: " . $e->getMessage());
        // Será definido abaixo baseado em FormData ou JSON
    }
    
    // Verificar se é FormData (com anexos) ou JSON
    $isFormData = isset($_POST['ticket_id']);
    
    if ($isFormData) {
        $ticket_id = $_POST['ticket_id'] ?? null;
        $comentario_raw = $_POST['comentario'] ?? '';
        if ($usuario_id === null) {
            $usuario_id = $_POST['usuario_id'] ?? 1;
        }
        $enviar_email = isset($_POST['enviar_email']) && $_POST['enviar_email'] === 'true';
        $com_copia = $_POST['com_copia'] ?? null;
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $ticket_id = $input['ticket_id'] ?? null;
        $comentario_raw = $input['comentario'] ?? '';
        if ($usuario_id === null) {
            $usuario_id = $input['usuario_id'] ?? 1;
        }
        $enviar_email = $input['enviar_email'] ?? false;
        $com_copia = $input['com_copia'] ?? null;
    }
    
    // Sanitização de Entradas (Mitigação XSS)
    $comentario = strip_tags($comentario_raw, '<p><br><strong><em><u><s><h1><h2><h3><h4><h5><h6><ul><ol><li><a><blockquote><span><img>');
    $comentario = preg_replace('/on[a-z]+\s*=\s*(["\']).*?\1/i', '', $comentario);
    $comentario = preg_replace('/javascript:/i', '#', $comentario);
    
    if ($usuario_id !== null) {
        error_log("[COMENTARIOS] Usando usuario_id final: {$usuario_id}");
    }
    
    if (!$ticket_id || empty($comentario)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ticket ID e comentário são obrigatórios']);
        exit;
    }
    
    try {
        $query = "INSERT INTO comentarios (ticket_id, usuario_id, comentario) 
                 VALUES (:ticket_id, :usuario_id, :comentario)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':comentario', $comentario);
        $stmt->execute();
        
        $comentario_id = $db->lastInsertId();
        
        // === LÓGICA DE SLA: Primeira resposta ===
        // Marcar primeira resposta se for de agente/admin
        /* DESABILITADO - Coluna não existe no banco
        try {
            $user_query = "SELECT tipo FROM usuarios WHERE id = ?";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->execute([$usuario_id]);
            $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user_data && in_array($user_data['tipo'], ['agente', 'admin'])) {
                $sla_update = "UPDATE ticket_sla 
                              SET sla_primeira_resposta_cumprido = 1, 
                                  sla_primeira_resposta_cumprido_em = NOW() 
                              WHERE ticket_id = ? 
                                AND sla_primeira_resposta_cumprido = 0";
                $sla_stmt = $db->prepare($sla_update);
                $sla_stmt->execute([$ticket_id]);
            }
        } catch (Exception $e) {
            error_log("Erro ao atualizar SLA primeira resposta: " . $e->getMessage());
        }
        */
        
        // Buscar dados do comentário recém-criado
        $query_comentario = "SELECT c.*, u.nome as autor_nome 
                            FROM comentarios c 
                            LEFT JOIN usuarios u ON c.usuario_id = u.id 
                            WHERE c.id = :comentario_id";
        $stmt_comentario = $db->prepare($query_comentario);
        $stmt_comentario->bindParam(':comentario_id', $comentario_id);
        $stmt_comentario->execute();
        $comentario_data = $stmt_comentario->fetch(PDO::FETCH_ASSOC);
        
        // Processar anexos se houver
        error_log('isFormData: ' . ($isFormData ? 'true' : 'false'));
        error_log('FILES: ' . print_r($_FILES, true));
        
        if ($isFormData && isset($_FILES['anexos'])) {
            $anexos_dir = dirname(__DIR__, 2) . '/backend/anexos/';
            
            if (!is_dir($anexos_dir)) {
                mkdir($anexos_dir, 0777, true);
            }
            
            $tipos_permitidos = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
            
            $files = $_FILES['anexos'];
            $total_files = is_array($files['name']) ? count($files['name']) : 1;
            
            for ($i = 0; $i < $total_files; $i++) {
                $nome_original = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmp_name = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $tamanho = is_array($files['size']) ? $files['size'][$i] : $files['size'];
                $tipo = is_array($files['type']) ? $files['type'][$i] : $files['type'];
                
                if (empty($nome_original)) continue;
                
                $extensao = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
                
                if (!in_array($extensao, $tipos_permitidos)) {
                    continue;
                }
                
                $nome_unico = time() . '_' . uniqid() . '_' . $nome_original;
                $caminho_destino = $anexos_dir . $nome_unico;
                
                if (move_uploaded_file($tmp_name, $caminho_destino)) {
                    try {
                        $query_anexo = "INSERT INTO anexos (ticket_id, comentario_id, nome_original, nome_arquivo, tamanho, tipo) 
                                       VALUES (:ticket_id, :comentario_id, :nome_original, :nome_arquivo, :tamanho, :tipo)";
                        $stmt_anexo = $db->prepare($query_anexo);
                        $stmt_anexo->bindParam(':ticket_id', $ticket_id);
                        $stmt_anexo->bindParam(':comentario_id', $comentario_id);
                        $stmt_anexo->bindParam(':nome_original', $nome_original);
                        $stmt_anexo->bindParam(':nome_arquivo', $nome_unico);
                        $stmt_anexo->bindParam(':tamanho', $tamanho);
                        $stmt_anexo->bindParam(':tipo', $tipo);
                        $stmt_anexo->execute();
                    } catch (Exception $e) {
                        error_log('Erro ao inserir anexo: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Não registrar no histórico - o comentário já aparece na timeline
        
        // Marcar como não lido para outros agentes
        try {
            require_once __DIR__ . '/../services/email-sender.php';
            $emailSender = new EmailSender($db);
            
            // Buscar dados do ticket
            $query_ticket = "SELECT agente_id FROM tickets WHERE id = ?";
            $stmt_ticket = $db->prepare($query_ticket);
            $stmt_ticket->execute([$ticket_id]);
            $ticket = $stmt_ticket->fetch();
            
            $agentes_notificar = [];
            
            // Buscar tipo do usuário que está comentando
            $query_tipo_autor = "SELECT tipo, nome FROM usuarios WHERE id = ?";
            $stmt_tipo_autor = $db->prepare($query_tipo_autor);
            $stmt_tipo_autor->execute([$usuario_id]);
            $autor_data = $stmt_tipo_autor->fetch(PDO::FETCH_ASSOC);
            $autor_tipo = $autor_data['tipo'] ?? 'cliente';
            $autor_nome = $autor_data['nome'] ?? 'Usuário';
            
            if ($ticket && $ticket['agente_id']) {
                // Ticket tem responsável: notificar apenas ele (se não for o autor)
                if ($ticket['agente_id'] != $usuario_id) {
                    $agentes_notificar[] = $ticket['agente_id'];
                    
                    // --- NOVA LÓGICA: Notificação agente → agente ---
                    // Se o autor é agente/admin comentando em ticket de outro agente:
                    // 1. Mudar status para nova_resposta
                    // 2. Enviar e-mail específico ao agente responsável
                    if (in_array($autor_tipo, ['agente', 'admin'])) {
                        // 1. Alterar status para nova_resposta
                        $status_query = "SELECT status FROM tickets WHERE id = ?";
                        $status_stmt = $db->prepare($status_query);
                        $status_stmt->execute([$ticket_id]);
                        $status_atual = $status_stmt->fetchColumn();
                        
                        if (!in_array($status_atual, ['encerrado', 'fechado', 'cancelado', 'nova_resposta'])) {
                            try {
                                $upd_status = "UPDATE tickets SET status = 'nova_resposta' WHERE id = ?";
                                $db->prepare($upd_status)->execute([$ticket_id]);
                                
                                // Registrar no histórico
                                $hist = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'status', ?, 'nova_resposta')";
                                $db->prepare($hist)->execute([$ticket_id, $usuario_id, $status_atual]);
                                
                                error_log("[COMENTARIOS] Status do ticket #$ticket_id alterado para 'nova_resposta' por agente/admin ID $usuario_id");
                            } catch (Exception $e) {
                                error_log("[COMENTARIOS] Erro ao atualizar status: " . $e->getMessage());
                            }
                        }
                        
                        // 2. Enviar e-mail ao agente responsável
                        try {
                            $emailSender->notificarComentarioEntreAgentes($ticket_id, $comentario_id, $autor_nome, $ticket['agente_id']);
                            error_log("[COMENTARIOS] E-mail de notificação agente→agente enviado para agente_id {$ticket['agente_id']}");
                        } catch (Exception $e) {
                            error_log("[COMENTARIOS] Erro ao enviar e-mail agente→agente: " . $e->getMessage());
                        }
                    }
                    // --- FIM NOVA LÓGICA ---
                }
            } else {
                // Ticket sem responsável: notificar todos os agentes exceto o autor
                $query_agentes = "SELECT id FROM usuarios WHERE tipo IN ('agente', 'admin') AND id != ?";
                $stmt_agentes = $db->prepare($query_agentes);
                $stmt_agentes->execute([$usuario_id]);
                $agentes_notificar = $stmt_agentes->fetchAll(PDO::FETCH_COLUMN);
            }
            
            // Inserir registro e notificação para cada agente
            $msg_notificacao = "Novo comentário no ticket (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") adicionado por " . ($autor_nome ?? 'Usuário') . ".";
            $notif_query = "INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, 'Novo Comentário', ?, ?)";
            $stmt_notif = $db->prepare($notif_query);

            foreach ($agentes_notificar as $agente_id) {
                // Notificação de Não Lido original
                $query_nao_lido = "INSERT INTO comentarios_nao_lidos (ticket_id, usuario_id, comentario_id) 
                                  VALUES (?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE comentario_id = ?, criado_em = CURRENT_TIMESTAMP";
                $stmt_nao_lido = $db->prepare($query_nao_lido);
                $stmt_nao_lido->execute([$ticket_id, $agente_id, $comentario_id, $comentario_id]);
                
                // Push Interno
                if ($agente_id != $usuario_id) {
                    $stmt_notif->execute([$agente_id, $msg_notificacao, $ticket_id]);
                }
            }
            
            // Enviar email se solicitado
            if ($enviar_email) {
                error_log('[COMENTARIO] Tentando enviar email para solicitante');
                try {
                    $emailSender->enviarComentarioParaSolicitante($ticket_id, $comentario_id, $com_copia);
                    error_log('[COMENTARIO] Email enviado com sucesso');
                } catch (Exception $emailEx) {
                    error_log('[COMENTARIO] Erro ao enviar email: ' . $emailEx->getMessage());
                    throw $emailEx;
                }
                
                // Registrar envio de email no histórico
                try {
                    // Buscar email do solicitante
                    $query_email = "SELECT u.email FROM tickets t LEFT JOIN usuarios u ON t.usuario_id = u.id WHERE t.id = ?";
                    $stmt_email = $db->prepare($query_email);
                    $stmt_email->execute([$ticket_id]);
                    $email_data = $stmt_email->fetch();
                    
                    if ($email_data && $email_data['email']) {
                        $hist_query = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_novo) VALUES (?, ?, 'email_enviado', ?)";
                        $hist_stmt = $db->prepare($hist_query);
                        $hist_stmt->execute([$ticket_id, $usuario_id, 'O comentário acima foi enviado por e-mail para: ' . $email_data['email']]);
                    }
                    
                    if ($com_copia) {
                        $hist_query = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_novo) VALUES (?, ?, 'email_copia', ?)";
                        $hist_stmt = $db->prepare($hist_query);
                        $hist_stmt->execute([$ticket_id, $usuario_id, 'Cópia do comentário acima enviada por e-mail para: ' . $com_copia]);
                    }
                } catch (Exception $e) {
                    error_log("Erro ao registrar envio de email no histórico: " . $e->getMessage());
                }
            }
            
        } catch (Exception $emailError) {
            error_log("Erro no envio de email: " . $emailError->getMessage());
        }
        
        // Buscar anexos do comentário
        $anexos = [];
        try {
            $query_anexos = "SELECT * FROM anexos WHERE comentario_id = ?";
            $stmt_anexos = $db->prepare($query_anexos);
            $stmt_anexos->execute([$comentario_id]);
            $anexos = $stmt_anexos->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar anexos: " . $e->getMessage());
        }
        
        echo json_encode(['success' => true, 'comentario_id' => $comentario_id, 'comentario' => $comentario_data, 'anexos' => $anexos]);
        
    } catch (Exception $e) {
        error_log('Erro ao adicionar comentário: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao adicionar comentário: ' . $e->getMessage()]);
    }
}
?>