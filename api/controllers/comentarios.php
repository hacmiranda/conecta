<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';
AuthMiddleware::configurarCORS(['POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Exception $e) {
    http_response_code(500);
    error_log('[COMENTARIOS] Erro: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno do servidor']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// PUT - Marcar comentários como lidos
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['marcar_lido'])) {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarCSRF();

    $ticket_id = $_GET['ticket_id'] ?? null;
    // O usuário vem sempre do token; o parâmetro usuario_id da URL é ignorado
    $usuario_id = (int) $user['id'];
    
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da demanda é obrigatório']);
        exit;
    }

    AcessoService::exigirLerTicket($db, $user, $ticket_id);
    
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
    // Autenticação e CSRF obrigatórios; o autor do comentário é sempre o usuário do token
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarCSRF();
    $usuario_id = (int) $user['id'];
    
    // Verificar se é FormData (com anexos) ou JSON
    $isFormData = isset($_POST['ticket_id']);
    
    if ($isFormData) {
        $ticket_id = $_POST['ticket_id'] ?? null;
        $comentario_raw = $_POST['comentario'] ?? '';
        $enviar_email = !empty($_POST['enviar_email']) && $_POST['enviar_email'] !== '0';
        $com_copia = $_POST['com_copia'] ?? null;
        $interno = !empty($_POST['interno']) && $_POST['interno'] !== '0';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $ticket_id = $input['ticket_id'] ?? null;
        $comentario_raw = $input['comentario'] ?? '';
        $enviar_email = $input['enviar_email'] ?? false;
        $com_copia = $input['com_copia'] ?? null;
        $interno = !empty($input['interno']);
    }

    // Nota interna nunca vai por e-mail para o solicitante nem tem cópia
    if ($interno) {
        $enviar_email = false;
        $com_copia = null;
    }

    $comentario = (string) $comentario_raw;

    // Converte classes de tamanho do Quill para estilos inline (compatível com e-mail e histórico sem CSS do Quill)
    $quill_sizes = ['small' => '0.75em', 'large' => '1.5em', 'huge' => '2.5em'];
    $comentario = preg_replace_callback(
        '/<span\b([^>]*)class="ql-size-(small|large|huge)"([^>]*)>/i',
        function ($m) use ($quill_sizes) {
            $before  = $m[1];
            $tamanho = $m[2];
            $after   = $m[3];
            $fs      = $quill_sizes[$tamanho];
            $attrs   = trim($before . $after);
            // Injeta font-size no style existente ou cria novo atributo style
            if (preg_match('/style="([^"]*)"/i', $attrs, $sm)) {
                $novo_style = 'style="font-size:' . $fs . '; ' . trim($sm[1], '; ') . '"';
                $attrs = preg_replace('/style="[^"]*"/i', $novo_style, $attrs);
            } else {
                $attrs .= ($attrs ? ' ' : '') . 'style="font-size:' . $fs . ';"';
            }
            return '<span ' . trim($attrs) . '>';
        },
        $comentario
    );

    // Sanitização (XSS): mantém só o HTML seguro do editor
    $comentario = HtmlSanitizer::rico($comentario);

    if (!$ticket_id || trim(strip_tags($comentario, '<img>')) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ID da demanda e comentário são obrigatórios']);
        exit;
    }

    // Isolamento: só comenta quem pode ver a demanda (404/403)
    $ticket_comentario = AcessoService::exigirVerTicket($db, $user, $ticket_id);
    if (!empty($ticket_comentario['excluido_em'])) {
        http_response_code(400);
        echo json_encode(['error' => 'A demanda está na lixeira']);
        exit;
    }

    // Cópia por e-mail: endereços válidos (até 5), separados por vírgula ou ponto e vírgula
    if (!empty($com_copia)) {
        $enderecos = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $com_copia))));
        foreach ($enderecos as $endereco) {
            if (!filter_var($endereco, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'E-mail de cópia inválido: ' . $endereco]);
                exit;
            }
        }
        if (count($enderecos) > 5) {
            http_response_code(400);
            echo json_encode(['error' => 'Informe no máximo 5 endereços em cópia']);
            exit;
        }
        $com_copia = implode(', ', $enderecos);
    }

    try {
        // Entra no histórico mostrado ao solicitante só o que foi enviado a ele por e-mail
        // (nota interna nunca entra; comentário sem e-mail fica só para a equipe)
        $query = "INSERT INTO comentarios (ticket_id, usuario_id, comentario, interno, visivel_solicitante)
                 VALUES (:ticket_id, :usuario_id, :comentario, :interno, :visivel)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':comentario', $comentario);
        $stmt->bindValue(':interno', $interno ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':visivel', (!$interno && $enviar_email) ? 1 : 0, PDO::PARAM_INT);
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
            
            if ($user_data && in_array($user_data['tipo'], ['operador', 'gestor', 'admin'])) {
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
        if ($isFormData && isset($_FILES['anexos'])) {
            $anexos_dir = dirname(__DIR__, 2) . '/backend/anexos/';

            if (!is_dir($anexos_dir)) {
                mkdir($anexos_dir, 0750, true);
            }

            // Whitelist dupla: extensão + MIME type real
            $whitelist = [
                'pdf'  => 'application/pdf',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'txt'  => 'text/plain',
                'csv'  => 'text/csv',
                'xls'  => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
            ];

            $files       = $_FILES['anexos'];
            $total_files = is_array($files['name']) ? count($files['name']) : 1;

            for ($i = 0; $i < $total_files; $i++) {
                $nome_raw  = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
                $tmp_name  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $tamanho   = is_array($files['size'])     ? $files['size'][$i]     : $files['size'];
                $erro      = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];

                if ($erro !== UPLOAD_ERR_OK || empty($nome_raw)) continue;

                // Sanitiza o nome original removendo componentes de path
                $nome_original = basename($nome_raw);
                $extensao      = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));

                if (!isset($whitelist[$extensao])) continue;

                // Valida MIME type real do arquivo
                $finfo     = finfo_open(FILEINFO_MIME_TYPE);
                $mime_real = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);
                if ($mime_real !== $whitelist[$extensao]) continue;

                // Nome no servidor sem o nome original
                $nome_unico      = time() . '_' . uniqid() . '.' . $extensao;
                $caminho_destino = $anexos_dir . $nome_unico;

                if (move_uploaded_file($tmp_name, $caminho_destino)) {
                    chmod($caminho_destino, 0640);
                    try {
                        $query_anexo = "INSERT INTO anexos (ticket_id, comentario_id, nome_original, nome_arquivo, tamanho, tipo)
                                       VALUES (:ticket_id, :comentario_id, :nome_original, :nome_arquivo, :tamanho, :tipo)";
                        $stmt_anexo = $db->prepare($query_anexo);
                        $stmt_anexo->bindParam(':ticket_id',     $ticket_id);
                        $stmt_anexo->bindParam(':comentario_id', $comentario_id);
                        $stmt_anexo->bindParam(':nome_original', $nome_original);
                        $stmt_anexo->bindParam(':nome_arquivo',  $nome_unico);
                        $stmt_anexo->bindParam(':tamanho',       $tamanho);
                        $stmt_anexo->bindParam(':tipo',          $mime_real);
                        $stmt_anexo->execute();
                    } catch (Exception $e) {
                        error_log('[COMENTARIOS POST] Erro ao inserir anexo: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Não registrar no histórico - o comentário já aparece na timeline
        
        // Resultado do envio por e-mail ao solicitante (null = não solicitado)
        $email_resultado = null;
        $email_destino = null;

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
                    if (in_array($autor_tipo, ['operador', 'gestor', 'admin'])) {
                        // 1. Alterar status para nova_resposta
                        $status_query = "SELECT status FROM tickets WHERE id = ?";
                        $status_stmt = $db->prepare($status_query);
                        $status_stmt->execute([$ticket_id]);
                        $status_atual = $status_stmt->fetchColumn();
                        
                        if (!in_array($status_atual, array_merge(AcessoService::STATUS_ARQUIVO, ['nova_resposta']), true)) {
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
                        
                        // 2. Enviar e-mail ao agente responsável — nota interna nunca vai por e-mail,
                        // nem para outro agente (só a notificação interna já registrada acima)
                        if (!$interno) {
                            try {
                                $emailSender->notificarComentarioEntreAgentes($ticket_id, $comentario_id, $autor_nome, $ticket['agente_id']);
                                error_log("[COMENTARIOS] E-mail de notificação agente→agente enviado para agente_id {$ticket['agente_id']}");
                            } catch (Exception $e) {
                                error_log("[COMENTARIOS] Erro ao enviar e-mail agente→agente: " . $e->getMessage());
                            }
                        }
                    }
                    // --- FIM NOVA LÓGICA ---
                }
            } else {
                // Demanda sem responsável: notificar os gestores do setor (admins se não houver), exceto o autor
                $stmt_setor = $db->prepare("SELECT setor_id FROM tickets WHERE id = ?");
                $stmt_setor->execute([$ticket_id]);
                $setor_demanda = $stmt_setor->fetchColumn();
                $agentes_notificar = array_column(
                    AcessoService::destinatariosInternosDoSetor($db, $setor_demanda ? (int) $setor_demanda : null, $usuario_id),
                    'id'
                );
            }
            
            // Inserir registro e notificação para cada agente
            $msg_notificacao = "Novo comentário na demanda (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") adicionado por " . ($autor_nome ?? 'Usuário') . ".";
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
                $email_resultado = false;
                try {
                    $email_resultado = (bool) $emailSender->enviarComentarioParaSolicitante($ticket_id, $comentario_id, $com_copia);
                } catch (Exception $emailEx) {
                    error_log('[COMENTARIO] Erro ao enviar email: ' . $emailEx->getMessage());
                }

                // Registrar envio de email no histórico (só quando de fato saiu)
                try {
                    $query_email = "SELECT COALESCE(NULLIF(t.email_solicitante, ''), u.email) AS destino
                                    FROM tickets t LEFT JOIN usuarios u ON t.usuario_id = u.id WHERE t.id = ?";
                    $stmt_email = $db->prepare($query_email);
                    $stmt_email->execute([$ticket_id]);
                    $email_destino = $stmt_email->fetchColumn() ?: null;

                    if ($email_resultado && $email_destino) {
                        $hist_query = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_novo) VALUES (?, ?, 'email_enviado', ?)";
                        $hist_stmt = $db->prepare($hist_query);
                        $hist_stmt->execute([$ticket_id, $usuario_id, 'O comentário acima foi enviado por e-mail para: ' . $email_destino]);
                    }

                    if ($email_resultado && $com_copia) {
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
        
        echo json_encode(['success' => true, 'comentario_id' => $comentario_id, 'comentario' => $comentario_data, 'anexos' => $anexos,
            'email_enviado' => $email_resultado, 'email_na_fila' => $email_resultado, 'email_destino' => $email_destino]);
        
    } catch (Exception $e) {
        error_log('Erro ao adicionar comentário: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao adicionar comentário']);
    }
}
?>