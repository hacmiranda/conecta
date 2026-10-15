<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/sla-service.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/timeline-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

/**
 * Verifica se o usuário logado pode visualizar a demanda informada
 * (regra centralizada em AcessoService).
 */
function usuarioPodeVerTicket(array $user, array $ticket): bool {
    return AcessoService::podeVerTicket($user, $ticket);
}

// GET - Listar tickets ou buscar ticket específico
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();
    $ticket_id = $_GET['id'] ?? null;

    try {
        if ($ticket_id) {
            // Buscar ticket específico
            $query = "SELECT t.*, 
                             u.nome as usuario_nome, u.email as usuario_email,
                             a.nome as agente_nome,
                             c.nome as categoria_nome,
                             f.nome as fechado_por_nome,
                             st.nome as setor_nome,
                             (SELECT COUNT(*) FROM historico WHERE ticket_id = t.id AND acao = 'merge') as eh_mesclado,
                             (SELECT valor_novo FROM historico WHERE ticket_id = t.id AND acao = 'merge' LIMIT 1) as tickets_mesclados
                      FROM tickets t 
                      LEFT JOIN usuarios u ON t.usuario_id = u.id 
                      LEFT JOIN usuarios a ON t.agente_id = a.id 
                      LEFT JOIN categorias c ON t.categoria_id = c.id 
                      LEFT JOIN usuarios f ON t.fechado_por = f.id
                      LEFT JOIN setores st ON t.setor_id = st.id
                      WHERE t.id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket) {
                http_response_code(404);
                echo json_encode(['error' => 'Demanda não encontrada']);
                exit;
            }

            if (!usuarioPodeVerTicket($user, $ticket)) {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso não autorizado a esta demanda']);
                exit;
            }

            // Linha do tempo cronológica (comentários + histórico + abertura)
            $dados_timeline = TimelineService::montar($db, (int) $ticket_id);

            // Anexos do próprio ticket (os dos comentários vêm dentro de cada comentário)
            $anexos_query = "SELECT id, ticket_id, nome_original, nome_arquivo, tamanho, tipo, criado_em FROM anexos WHERE ticket_id = :ticket_id AND comentario_id IS NULL ORDER BY criado_em ASC";
            $anexos_stmt = $db->prepare($anexos_query);
            $anexos_stmt->bindParam(':ticket_id', $ticket_id);
            $anexos_stmt->execute();
            $anexos = $anexos_stmt->fetchAll();

            $ticket['comentarios'] = $dados_timeline['timeline'];
            $ticket['anexos'] = $anexos;
            $ticket['historico'] = $dados_timeline['historico'];

            echo json_encode($ticket);
            
        } else {
            // Listagem por escopo: abertas | arquivo | transferidas | repassadas
            // (sem escopo, devolve tudo que o papel do usuário enxerga)
            $usuario_logado_id = (int) $user['id'];
            $tipo_logado = $user['tipo'] ?? '';
            $escopo = $_GET['escopo'] ?? (isset($_GET['arquivo']) ? 'arquivo' : '');

            if (!in_array($escopo, ['', 'abertas', 'arquivo', 'transferidas', 'repassadas'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Escopo inválido']);
                exit;
            }

            // Status que movem a demanda para o Arquivo (constantes internas, sem entrada do usuário)
            $arquivados = "'" . implode("','", AcessoService::STATUS_ARQUIVO) . "'";
            $setor_escopo = AcessoService::setorEscopo($user);

            $query = "SELECT t.*,
                             u.nome as usuario_nome, u.email as usuario_email,
                             a.nome as agente_nome,
                             c.nome as categoria_nome,
                             s.nome as setor_nome,
                             (SELECT COUNT(*) FROM anexos WHERE ticket_id = t.id) as tem_anexos,
                             0 as criado_por_email,
                             CASE WHEN cnl.ticket_id IS NOT NULL THEN 1 ELSE 0 END as tem_comentario_nao_lido,
                             (SELECT CONCAT(data_lembrete, ' ', hora_lembrete) FROM lembretes WHERE ticket_id = t.id AND notificado = FALSE ORDER BY data_lembrete ASC, hora_lembrete ASC LIMIT 1) as proximo_lembrete,
                             (SELECT COUNT(*) FROM historico WHERE ticket_id = t.id AND acao = 'merge') as eh_mesclado,
                             (SELECT valor_novo FROM historico WHERE ticket_id = t.id AND acao = 'merge' LIMIT 1) as tickets_mesclados
                      FROM tickets t
                      LEFT JOIN usuarios u ON t.usuario_id = u.id
                      LEFT JOIN usuarios a ON t.agente_id = a.id
                      LEFT JOIN categorias c ON t.categoria_id = c.id
                      LEFT JOIN setores s ON t.setor_id = s.id
                      LEFT JOIN comentarios_nao_lidos cnl ON t.id = cnl.ticket_id AND cnl.usuario_id = :usuario_id";

            $params = [':usuario_id' => $usuario_logado_id];
            $where = [];

            if ($escopo === 'transferidas') {
                // Demandas que passaram pelo setor e hoje estão em outro (gestor/admin)
                if (!in_array($tipo_logado, ['gestor', 'admin'], true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Escopo disponível apenas para gestores e administradores']);
                    exit;
                }
                if ($setor_escopo !== null) {
                    $where[] = "(t.setor_id IS NULL OR t.setor_id <> :setor_atual) AND EXISTS (
                                    SELECT 1 FROM ticket_setor_log l
                                    WHERE l.ticket_id = t.id AND l.setor_id = :setor_log AND l.saiu_em IS NOT NULL)";
                    $params[':setor_atual'] = $setor_escopo;
                    $params[':setor_log'] = $setor_escopo;
                } elseif ($tipo_logado === 'admin') {
                    $where[] = "EXISTS (SELECT 1 FROM ticket_setor_log l
                                        WHERE l.ticket_id = t.id AND l.saiu_em IS NOT NULL AND l.setor_id <> t.setor_id)";
                } else {
                    $where[] = "1 = 0";
                }
            } elseif ($escopo === 'repassadas') {
                // Demandas que o usuário atendeu e passou adiante, ainda abertas com outra pessoa
                if (!in_array($tipo_logado, ['operador', 'gestor'], true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Escopo disponível apenas para operadores e gestores']);
                    exit;
                }
                $where[] = "EXISTS (SELECT 1 FROM ticket_agente_log l
                                    WHERE l.ticket_id = t.id AND l.usuario_id = :self_log AND l.saiu_em IS NOT NULL)
                            AND (t.agente_id IS NULL OR t.agente_id <> :self_atual)
                            AND t.status NOT IN ($arquivados)";
                $params[':self_log'] = $usuario_logado_id;
                $params[':self_atual'] = $usuario_logado_id;
            } else {
                // Visibilidade base por papel
                if ($tipo_logado === 'admin') {
                    if ($setor_escopo !== null) {
                        $where[] = "t.setor_id = :setor_id";
                        $params[':setor_id'] = $setor_escopo;
                    }
                } elseif ($tipo_logado === 'gestor') {
                    $where[] = "t.setor_id = :setor_id";
                    $params[':setor_id'] = $setor_escopo ?? 0;
                } elseif ($tipo_logado === 'operador') {
                    if ($escopo === 'arquivo') {
                        // Arquivo do operador: o que é/foi dele (responsável atual, solicitante,
                        // quem encerrou ou quem já atendeu — ticket_agente_log)
                        $where[] = "(t.agente_id = :self_id OR t.usuario_id = :self_id2 OR t.fechado_por = :self_id3
                                     OR t.id IN (SELECT ticket_id FROM ticket_agente_log WHERE usuario_id = :self_id4))";
                        $params[':self_id'] = $usuario_logado_id;
                        $params[':self_id2'] = $usuario_logado_id;
                        $params[':self_id3'] = $usuario_logado_id;
                        $params[':self_id4'] = $usuario_logado_id;
                    } else {
                        $where[] = "(t.agente_id = :self_id OR t.usuario_id = :self_id2)";
                        $params[':self_id'] = $usuario_logado_id;
                        $params[':self_id2'] = $usuario_logado_id;
                    }
                } else {
                    // cliente: apenas as próprias demandas
                    $where[] = "t.usuario_id = :self_id";
                    $params[':self_id'] = $usuario_logado_id;
                }

                if ($escopo === 'abertas') {
                    $where[] = "t.status NOT IN ($arquivados)";
                } elseif ($escopo === 'arquivo') {
                    $where[] = "t.status IN ($arquivados)";
                }
            }

            if (!empty($where)) {
                $query .= " WHERE " . implode(' AND ', $where);
            }
            $query .= " ORDER BY t.criado_em DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $tickets = $stmt->fetchAll();

            echo json_encode($tickets);
        }
        
    } catch (Exception $e) {
        error_log('[TICKETS GET] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar demandas']);
    }
}

// POST - Criar ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Obter usuário logado do token JWT (com fallback)
    try {
        $user = AuthMiddleware::verificarAuth();
        AuthMiddleware::verificarCSRF();
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Acesso não autorizado', 'details' => $e->getMessage()]);
        exit;
    }
    
    error_log("[DEBUG] Iniciando criação de ticket");
    
    $titulo_raw = $_POST['titulo'] ?? '';
    $descricao_raw = $_POST['descricao'] ?? '';
    $prioridade = $_POST['prioridade'] ?? 'normal';
    $categoria_id = $_POST['categoria_id'] ?? null;
    $email_solicitante = $_POST['email_solicitante'] ?? null;
    
    // Sanitização de entradas — remove tags e atributos perigosos
    $titulo = strip_tags($titulo_raw);

    // Tags permitidas na descrição (sem <img> para evitar src malicioso)
    $tags_permitidas = '<p><br><strong><em><u><s><h1><h2><h3><h4><h5><h6><ul><ol><li><a><blockquote><span>';
    $descricao = strip_tags($descricao_raw, $tags_permitidas);
    // Remove event handlers (onclick, onload, etc.)
    $descricao = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $descricao);
    // Remove protocolos perigosos em atributos href/src
    $descricao = preg_replace('/(href|src)\s*=\s*["\']?\s*(?:javascript|vbscript|data)[^"\'>\s]*/i', '$1="#"', $descricao);

    // Se email não informado, usar email do usuário logado
    if (empty($email_solicitante)) {
        $email_solicitante = $user['email'];
    }
    
    error_log("[DEBUG] Dados recebidos: titulo=$titulo, prioridade=$prioridade");
    
    if (empty($titulo) || empty($descricao)) {
        http_response_code(400);
        echo json_encode(['error' => 'Título e descrição são obrigatórios']);
        exit;
    }
    
    try {
        error_log("[DEBUG] Preparando inserção no banco");
        
        // Garantir que prioridade SEMPRE tenha um valor válido
        if (empty($prioridade) || !in_array($prioridade, ['baixa', 'normal', 'alta', 'urgente'])) {
            $prioridade = 'normal';
            error_log("[DEBUG] Prioridade ajustada para: normal");
        }
        
        error_log("[DEBUG] Prioridade final: $prioridade");

        // Determinar setor de destino: quem cria já vinculado a um setor (operador/gestor)
        // abre a demanda direto no próprio setor; sem setor (cliente/admin), a demanda cai no
        // setor "Geral" para triagem — de lá, um gestor a transfere para o setor correto.
        $setor_id = !empty($user['setor_id']) ? (int) $user['setor_id'] : null;

        // Admin pode abrir a demanda direto em um setor (o escolhido no cabeçalho)
        if ($setor_id === null && ($user['tipo'] ?? '') === 'admin' && !empty($_POST['setor_id']) && is_numeric($_POST['setor_id'])) {
            $setor_pedido = $db->prepare("SELECT id FROM setores WHERE id = ? AND ativo = 1");
            $setor_pedido->execute([(int) $_POST['setor_id']]);
            $setor_id = $setor_pedido->fetchColumn() ?: null;
        }

        if ($setor_id === null) {
            $setor_stmt = $db->prepare("SELECT id FROM setores WHERE nome = 'Geral' AND ativo = 1 LIMIT 1");
            $setor_stmt->execute();
            $setor_id = $setor_stmt->fetchColumn() ?: null;
        }

        $query = "INSERT INTO tickets (titulo, descricao, usuario_id, email_solicitante, prioridade, categoria_id, setor_id, status) VALUES (:titulo, :descricao, :usuario_id, :email_solicitante, :prioridade, :categoria_id, :setor_id, 'novo')";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':titulo', $titulo);
        $stmt->bindParam(':descricao', $descricao);
        $stmt->bindParam(':usuario_id', $user['id']);
        $stmt->bindParam(':email_solicitante', $email_solicitante);
        $stmt->bindParam(':prioridade', $prioridade);
        $stmt->bindParam(':categoria_id', $categoria_id);
        $stmt->bindParam(':setor_id', $setor_id);
        $stmt->execute();

        // SLA, janela de setor e evento de abertura na linha do tempo
        $ticket_id = $db->lastInsertId();
        AcessoService::registrarAbertura($db, (int) $ticket_id, $setor_id ? (int) $setor_id : null, (int) $user['id'], 'no sistema');

        error_log("[DEBUG] Ticket criado com ID: $ticket_id");
        
        // Processar anexos se houver
        if (isset($_FILES['anexos']) && is_array($_FILES['anexos']['name'])) {
            $upload_dir = dirname(__DIR__, 2) . '/backend/anexos/';

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0750, true);
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

            $total_arquivos = count($_FILES['anexos']['name']);

            for ($i = 0; $i < $total_arquivos; $i++) {
                if ($_FILES['anexos']['error'][$i] !== UPLOAD_ERR_OK) continue;

                $nome_original = basename($_FILES['anexos']['name'][$i]);
                $tmp_name      = $_FILES['anexos']['tmp_name'][$i];
                $size          = $_FILES['anexos']['size'][$i];

                // Valida extensão
                $extensao = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
                if (!isset($whitelist[$extensao])) continue;

                // Valida MIME type real do arquivo (não o informado pelo cliente)
                $finfo     = finfo_open(FILEINFO_MIME_TYPE);
                $mime_real = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);
                if ($mime_real !== $whitelist[$extensao]) continue;

                // Nome do arquivo no servidor: sem o nome original para evitar path traversal
                $nome_arquivo    = time() . '_' . uniqid() . '.' . $extensao;
                $caminho_completo = $upload_dir . $nome_arquivo;

                if (move_uploaded_file($tmp_name, $caminho_completo)) {
                    chmod($caminho_completo, 0640);
                    try {
                        $anexo_query = "INSERT INTO anexos (ticket_id, nome_original, nome_arquivo, tamanho, tipo)
                                       VALUES (:ticket_id, :nome_original, :nome_arquivo, :tamanho, :tipo)";
                        $anexo_stmt = $db->prepare($anexo_query);
                        $anexo_stmt->bindParam(':ticket_id',    $ticket_id);
                        $anexo_stmt->bindParam(':nome_original', $nome_original);
                        $anexo_stmt->bindParam(':nome_arquivo',  $nome_arquivo);
                        $anexo_stmt->bindParam(':tamanho',       $size);
                        $anexo_stmt->bindParam(':tipo',          $mime_real);
                        $anexo_stmt->execute();
                    } catch (Exception $e) {
                        error_log('[TICKETS POST] Erro ao inserir anexo: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Enviar email de confirmação e Push
        try {
            require_once __DIR__ . '/../services/email-sender.php';
            $emailSender = new EmailSender($db);
            $emailSender->enviarEmailNovoTicket($ticket_id);
            $emailSender->notificarAgentesNovoTicket($ticket_id);
            
            // Notificação interna: apenas os gestores do setor da demanda (admins se não houver gestor)
            $msg_novo_ticket = "Uma nova demanda (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") foi aberta por " . ($user['nome'] ?? 'Desconhecido') . ".";
            $notif_insert = "INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, 'Nova Demanda', ?, ?)";
            $stmt_insert = $db->prepare($notif_insert);

            foreach (AcessoService::destinatariosInternosDoSetor($db, $setor_id, (int) $user['id']) as $destinatario) {
                $stmt_insert->execute([$destinatario['id'], $msg_novo_ticket, $ticket_id]);
            }
        } catch (Exception $emailError) {
            error_log("[WARNING] Erro no envio de email/notificação: " . $emailError->getMessage());
        }
        
        // Buscar ticket criado
        $select_query = "SELECT t.*, u.nome as usuario_nome 
                        FROM tickets t 
                        LEFT JOIN usuarios u ON t.usuario_id = u.id 
                        WHERE t.id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $ticket_id);
        $select_stmt->execute();
        $ticket = $select_stmt->fetch();
        
        error_log("[DEBUG] Enviando resposta final");
        echo json_encode($ticket);
        error_log("[DEBUG] Resposta enviada com sucesso");
        
    } catch (Exception $e) {
        error_log("[ERROR] Erro ao criar ticket: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar demanda: ' . $e->getMessage()]);
    }
}

// PUT - Atualizar ticket
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Autenticação
    try {
        $user = AuthMiddleware::verificarAuth();
        AuthMiddleware::verificarCSRF();
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Acesso não autorizado']);
        exit;
    }
    
    $ticket_id = $_GET['id'] ?? null;
    
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da demanda é obrigatório']);
        exit;
    }
    
    // Obter dados do input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Usuário que realiza a ação é o logado
    $usuario_id = $user['id'];
    
    // Log para debug
    error_log("[ATRIBUICAO] Ticket ID: $ticket_id");
    error_log("[ATRIBUICAO] Input: " . json_encode($input));
    
    try {
        $updates = [];
        $params = [':id' => $ticket_id];
        
        // Buscar dados atuais ANTES da atualização
        $current_query = "SELECT titulo, descricao, status, prioridade, categoria_id, agente_id, setor_id, usuario_id FROM tickets WHERE id = ?";
        $current_stmt = $db->prepare($current_query);
        $current_stmt->execute([$ticket_id]);
        $current_data = $current_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current_data) {
            error_log("[ATRIBUICAO ERRO] Ticket $ticket_id não encontrado");
            http_response_code(404);
            echo json_encode(['error' => 'Demanda não encontrada']);
            exit;
        }
        
        // Isolamento: admin, gestor do setor da demanda ou operador responsável
        if (!AcessoService::podeOperarTicket($user, $current_data)) {
            http_response_code(403);
            echo json_encode(['error' => 'Você não tem permissão para alterar esta demanda']);
            exit;
        }

        error_log("[ATRIBUICAO] Status atual: {$current_data['status']}");

        // Valida status e prioridade contra o catálogo (somente valores ativos)
        if (isset($input['status'])) {
            $chk = $db->prepare("SELECT 1 FROM ticket_status WHERE nome = ? AND ativo = 1");
            $chk->execute([$input['status']]);
            if (!$chk->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'Status inválido']);
                exit;
            }
        }
        if (isset($input['prioridade'])) {
            $chk = $db->prepare("SELECT 1 FROM ticket_prioridades WHERE nome = ? AND ativo = 1");
            $chk->execute([$input['prioridade']]);
            if (!$chk->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'Prioridade inválida']);
                exit;
            }
        }

        // Atribuir/remover responsável é ato de gestão: operador usa as ações de demanda-acoes.php
        if (array_key_exists('agente_id', $input)) {
            if (!in_array($user['tipo'], ['admin', 'gestor'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Apenas gestores ou administradores atribuem responsável']);
                exit;
            }
            if (!empty($input['agente_id'])) {
                $dest = $db->prepare("SELECT setor_id FROM usuarios WHERE id = ? AND ativo = 1 AND tipo IN ('operador','gestor')");
                $dest->execute([(int) $input['agente_id']]);
                $destRow = $dest->fetch(PDO::FETCH_ASSOC);
                if (!$destRow || (int) $destRow['setor_id'] !== (int) $current_data['setor_id']) {
                    http_response_code(400);
                    echo json_encode(['error' => 'O responsável precisa ser um usuário ativo do setor da demanda']);
                    exit;
                }
            }
        }

        // Registrar auditoria para cancelamento ANTES da alteração
        if (isset($input['status']) && $input['status'] === 'cancelado') {
            $auditoria = new AuditoriaService($db);
            $dados_novos = $current_data;
            $dados_novos['status'] = 'cancelado';
            $auditoria->registrar($usuario_id, 'cancelar_ticket', 'tickets', $ticket_id, json_encode($current_data), json_encode($dados_novos));
        }
        
        // Processar todas as alterações
        if (isset($input['status'])) {
            // Validação de fechamento removida: permite encerrar tickets sem categoria e sem responsável
            /*
            if ($input['status'] === 'encerrado') {
                // ... validação removida ...
            }
            */
            
            $updates[] = 'status = :status';
            $params[':status'] = $input['status'];
            
            if ($input['status'] === 'encerrado' || $input['status'] === 'cancelado') {
                $updates[] = 'fechado_em = NOW()';
                // Quem encerrou vem sempre do token, nunca do corpo da requisição
                $updates[] = 'fechado_por = :fechado_por';
                $params[':fechado_por'] = $usuario_id;
            }
        }
        
        if (isset($input['prioridade'])) {
            $updates[] = 'prioridade = :prioridade';
            $params[':prioridade'] = $input['prioridade'];
        }
        
        if (isset($input['categoria_id'])) {
            $updates[] = 'categoria_id = :categoria_id';
            $params[':categoria_id'] = $input['categoria_id'];
        }
        
        if (isset($input['agente_id'])) {
            error_log("[ATRIBUICAO] Atribuindo agente ID: {$input['agente_id']}");
            $updates[] = 'agente_id = :agente_id';
            $params[':agente_id'] = $input['agente_id'];
            $updates[] = 'atribuido_em = ' . ($input['agente_id'] ? 'NOW()' : 'NULL');

            // Se está atribuindo agente e ticket está em 'novo', mudar para 'em_atendimento'
            if ($input['agente_id'] && $current_data['status'] === 'novo') {
                error_log("[ATRIBUICAO] Mudando status de 'novo' para 'em_atendimento'");
                $updates[] = 'status = :status_atendimento';
                $params[':status_atendimento'] = 'em_atendimento';
                $input['status'] = 'em_atendimento'; // Para registrar no histórico
            }
        }

        // Executar UPDATE se houver alterações
        if (!empty($updates)) {
            $query = "UPDATE tickets SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            
            // === LÓGICA DE SLA (substituindo triggers) ===
            if (isset($input['status']) && $input['status'] !== $current_data['status']) {
                // Buscar configuração de status
                $status_query = "SELECT congela_sla FROM ticket_status WHERE nome = ?";
                $status_stmt = $db->prepare($status_query);
                
                // Status novo
                $status_stmt->execute([$input['status']]);
                $status_novo = $status_stmt->fetch(PDO::FETCH_ASSOC);
                $congela_novo = $status_novo['congela_sla'] ?? false;
                
                // Status anterior
                $status_stmt->execute([$current_data['status']]);
                $status_anterior = $status_stmt->fetch(PDO::FETCH_ASSOC);
                $congela_anterior = $status_anterior['congela_sla'] ?? false;
                
                // Pausar SLA
                if ($congela_novo && !$congela_anterior) {
                    $sla_update = "UPDATE ticket_sla SET pausado = TRUE, pausado_em = NOW() WHERE ticket_id = ?";
                    $sla_stmt = $db->prepare($sla_update);
                    $sla_stmt->execute([$ticket_id]);
                }
                
                // Retomar SLA
                if (!$congela_novo && $congela_anterior) {
                    // Buscar tempo pausado atual
                    $sla_select = "SELECT COALESCE(tempo_pausado_segundos, 0) as tempo_pausado, pausado_em FROM ticket_sla WHERE ticket_id = ?";
                    $sla_select_stmt = $db->prepare($sla_select);
                    $sla_select_stmt->execute([$ticket_id]);
                    $sla_data = $sla_select_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($sla_data && $sla_data['pausado_em']) {
                        // Calcular segundos úteis pausados
                        $slaService = new SLAService($db);
                        $segundos_pausados = $slaService->calcularSegundosUteis($sla_data['pausado_em'], date('Y-m-d H:i:s'));
                        
                        $novo_tempo_pausado = $sla_data['tempo_pausado'] + $segundos_pausados;
                        
                        $sla_update = "UPDATE ticket_sla SET pausado = FALSE, tempo_pausado_segundos = ?, pausado_em = NULL WHERE ticket_id = ?";
                        $sla_stmt = $db->prepare($sla_update);
                        $sla_stmt->execute([$novo_tempo_pausado, $ticket_id]);
                    }
                }
                
                // Primeira resposta (quando sai de 'novo')
                if ($current_data['status'] === 'novo' && $input['status'] !== 'novo') {
                    $ticket_query = "SELECT criado_em FROM tickets WHERE id = ?";
                    $ticket_stmt = $db->prepare($ticket_query);
                    $ticket_stmt->execute([$ticket_id]);
                    $ticket_info = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($ticket_info) {
                        $slaService = new SLAService($db);
                        $segundos_resposta = $slaService->calcularSegundosUteis($ticket_info['criado_em'], date('Y-m-d H:i:s'));
                        
                        $sla_update = "UPDATE ticket_sla SET primeira_resposta_em = NOW(), tempo_primeira_resposta_segundos = ? WHERE ticket_id = ?";
                        $sla_stmt = $db->prepare($sla_update);
                        $sla_stmt->execute([$segundos_resposta, $ticket_id]);
                    }
                }
                
                // Resolução (quando fecha/encerra)
                if (in_array($input['status'], ['resolvido', 'encerrado'])) {
                    $ticket_query = "SELECT criado_em FROM tickets WHERE id = ?";
                    $ticket_stmt = $db->prepare($ticket_query);
                    $ticket_stmt->execute([$ticket_id]);
                    $ticket_info = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Buscar tempo pausado
                    $sla_select = "SELECT COALESCE(tempo_pausado_segundos, 0) as tempo_pausado FROM ticket_sla WHERE ticket_id = ?";
                    $sla_select_stmt = $db->prepare($sla_select);
                    $sla_select_stmt->execute([$ticket_id]);
                    $sla_data = $sla_select_stmt->fetch(PDO::FETCH_ASSOC);
                    $tempo_pausado = $sla_data['tempo_pausado'] ?? 0;
                    
                    if ($ticket_info) {
                        $slaService = new SLAService($db);
                        $segundos_totais = $slaService->calcularSegundosUteis($ticket_info['criado_em'], date('Y-m-d H:i:s'));
                        
                        $tempo_resolucao = $segundos_totais - $tempo_pausado;
                        
                        $sla_update = "UPDATE ticket_sla SET resolvido_em = NOW(), tempo_resolucao_segundos = ? WHERE ticket_id = ?";
                        $sla_stmt = $db->prepare($sla_update);
                        $sla_stmt->execute([$tempo_resolucao, $ticket_id]);
                    }
                }
            }
            
            // Registrar histórico e auditoria
            // $usuario_id já é definido pelo token JWT agora
            $auditoria = new AuditoriaService($db);
            
            try {
                if (isset($input['status']) && $input['status'] !== $current_data['status']) {
                    $hist_query = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'status', ?, ?)";
                    $hist_stmt = $db->prepare($hist_query);
                    $hist_stmt->execute([$ticket_id, $usuario_id, $current_data['status'], $input['status']]);
                    error_log("[HISTORICO] Status alterado: {$current_data['status']} -> {$input['status']}");
                    
                    // Auditoria
                    $auditoria->registrarTicket($ticket_id, $usuario_id, 'status', $current_data['status'], $input['status']);

                    // Encerrar/cancelar fecha as janelas de setor e responsável; reabrir as reabre
                    $era_arquivada = in_array($current_data['status'], AcessoService::STATUS_ARQUIVO, true);
                    $vai_arquivar  = in_array($input['status'], AcessoService::STATUS_ARQUIVO, true);
                    if ($vai_arquivar && !$era_arquivada) {
                        AcessoService::fecharJanelasDaDemanda($db, (int) $ticket_id);
                    } elseif ($era_arquivada && !$vai_arquivar) {
                        // Se a mesma requisição troca o responsável, a janela dele é aberta mais abaixo
                        $troca_agente = array_key_exists('agente_id', $input) && $input['agente_id'] != $current_data['agente_id'];
                        $agente_atual = $troca_agente ? null : $current_data['agente_id'];
                        AcessoService::reabrirJanelasDaDemanda($db, (int) $ticket_id,
                            $current_data['setor_id'] ? (int) $current_data['setor_id'] : null,
                            $agente_atual ? (int) $agente_atual : null);
                    }
                }
                
                if (isset($input['prioridade']) && $input['prioridade'] !== $current_data['prioridade']) {
                    $hist_query = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'prioridade', ?, ?)";
                    $hist_stmt = $db->prepare($hist_query);
                    $hist_stmt->execute([$ticket_id, $usuario_id, $current_data['prioridade'], $input['prioridade']]);
                    error_log("[HISTORICO] Prioridade alterada: {$current_data['prioridade']} -> {$input['prioridade']}");
                    
                    // Auditoria
                    $auditoria->registrarTicket($ticket_id, $usuario_id, 'prioridade', $current_data['prioridade'], $input['prioridade']);
                }
                
                if (isset($input['categoria_id']) && $input['categoria_id'] != $current_data['categoria_id']) {
                    // Buscar nomes das categorias
                    $cat_ant_nome = 'Sem subcategoria';
                    if ($current_data['categoria_id']) {
                        $cat_query = "SELECT nome FROM categorias WHERE id = ?";
                        $cat_stmt = $db->prepare($cat_query);
                        $cat_stmt->execute([$current_data['categoria_id']]);
                        $cat_data = $cat_stmt->fetch();
                        $cat_ant_nome = $cat_data['nome'] ?? $current_data['categoria_id'];
                    }
                    
                    $cat_nova_nome = $input['categoria_id'];
                    if ($input['categoria_id']) {
                        $cat_query = "SELECT nome FROM categorias WHERE id = ?";
                        $cat_stmt = $db->prepare($cat_query);
                        $cat_stmt->execute([$input['categoria_id']]);
                        $cat_data = $cat_stmt->fetch();
                        $cat_nova_nome = $cat_data['nome'] ?? $input['categoria_id'];
                    }
                    
                    $hist_query = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'categoria', ?, ?)";
                    $hist_stmt = $db->prepare($hist_query);
                    $hist_stmt->execute([$ticket_id, $usuario_id, $cat_ant_nome, $cat_nova_nome]);
                    error_log("[HISTORICO] Categoria alterada: {$cat_ant_nome} -> {$cat_nova_nome}");
                    
                    // Auditoria
                    $auditoria->registrarTicket($ticket_id, $usuario_id, 'categoria', $cat_ant_nome, $cat_nova_nome);
                }
                
                if (isset($input['agente_id']) && $input['agente_id'] != $current_data['agente_id']) {
                    // Buscar nomes dos agentes
                    $agente_ant_nome = 'Não atribuído';
                    if ($current_data['agente_id']) {
                        $ag_query = "SELECT nome FROM usuarios WHERE id = ?";
                        $ag_stmt = $db->prepare($ag_query);
                        $ag_stmt->execute([$current_data['agente_id']]);
                        $ag_data = $ag_stmt->fetch();
                        $agente_ant_nome = $ag_data['nome'] ?? $current_data['agente_id'];
                    }
                    
                    $agente_novo_nome = 'Não atribuído';
                    if ($input['agente_id']) {
                        $ag_query = "SELECT nome FROM usuarios WHERE id = ?";
                        $ag_stmt = $db->prepare($ag_query);
                        $ag_stmt->execute([$input['agente_id']]);
                        $ag_data = $ag_stmt->fetch();
                        $agente_novo_nome = $ag_data['nome'] ?? $input['agente_id'];
                    }
                    
                    $era_sem_atribuicao = empty($current_data['agente_id']);

                    $hist_query = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'atribuicao', ?, ?)";
                    $hist_stmt = $db->prepare($hist_query);
                    $hist_stmt->execute([$ticket_id, $usuario_id, $agente_ant_nome, $agente_novo_nome]);
                    error_log("[HISTORICO] Atribuição alterada: {$agente_ant_nome} -> {$agente_novo_nome}");

                    // Auditoria
                    $auditoria->registrarTicket($ticket_id, $usuario_id, 'agente', $agente_ant_nome, $agente_novo_nome);

                    // Histórico de responsáveis: fecha a janela do agente anterior e abre a do novo
                    if ($current_data['agente_id']) {
                        $db->prepare("UPDATE ticket_agente_log SET saiu_em = NOW() WHERE ticket_id = ? AND usuario_id = ? AND saiu_em IS NULL")
                           ->execute([$ticket_id, $current_data['agente_id']]);
                    }
                    if ($input['agente_id']) {
                        $db->prepare("INSERT INTO ticket_agente_log (ticket_id, usuario_id) VALUES (?, ?)")
                           ->execute([$ticket_id, $input['agente_id']]);
                    }
                    
                    // Notificar atribuição
                    try {
                        require_once __DIR__ . '/../services/email-sender.php';
                        $emailSender = new EmailSender($db);
                        $user_query = "SELECT nome FROM usuarios WHERE id = ?";
                        $user_stmt = $db->prepare($user_query);
                        $user_stmt->execute([$usuario_id]);
                        $user_data = $user_stmt->fetch();
                        $emailSender->notificarAtribuicaoTicket($ticket_id, $input['agente_id'], $user_data['nome'] ?? 'Sistema', $era_sem_atribuicao);
                        
                        // Push Notificação Interna
                        $msg_notificacao = "O ticket (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") foi atribuído a você por " . ($user_data['nome'] ?? 'Sistema') . ".";
                        $notif_query = "INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, ?, ?, ?)";
                        $notif_stmt = $db->prepare($notif_query);
                        $notif_stmt->execute([$input['agente_id'], 'Ticket Atribuído', $msg_notificacao, $ticket_id]);
                        
                    } catch (Exception $emailError) {
                        error_log("[WARNING] Erro no envio de email/notificacao de atribuição: " . $emailError->getMessage());
                    }
                }
            } catch (Exception $e) {
                error_log("[ERROR] Erro ao inserir histórico: " . $e->getMessage());
            }
            
            // Notificar encerramento por email se necessário
            if (isset($input['status']) && $input['status'] === 'encerrado') {
                try {
                    require_once __DIR__ . '/../services/email-sender.php';
                    $emailSender = new EmailSender($db);
                    $emailSender->notificarFechamentoTicket($ticket_id);
                } catch (Exception $emailError) {
                    error_log("[WARNING] Erro no envio de email: " . $emailError->getMessage());
                }
            }
            
            // Notificar reabertura
            if (isset($input['status']) && ($input['status'] === 'reaberto' || $input['status'] === 'aberto' || $input['status'] === 'atendimento') && ($current_data['status'] === 'encerrado' || $current_data['status'] === 'fechado')) {
                try {
                    require_once __DIR__ . '/../services/email-sender.php';
                    $emailSender = new EmailSender($db);
                    $emailSender->notificarReaberturaTicket($ticket_id);
                } catch (Exception $emailError) {
                    error_log("[WARNING] Erro no envio de email de reabertura: " . $emailError->getMessage());
                }
            }
        }
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        error_log("[ATRIBUICAO ERRO] Exceção: " . $e->getMessage());
        error_log("[ATRIBUICAO ERRO] Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'error' => 'Erro ao atualizar demanda',
            'details' => $e->getMessage(),
            'line' => $e->getLine()
        ]);
    }
}

// DELETE - Excluir ticket
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $user = AuthMiddleware::verificarAuth();
        AuthMiddleware::verificarAdmin($user); // Apenas admin pode excluir
        AuthMiddleware::verificarCSRF();
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Acesso não autorizado']);
        exit;
    }
    
    $ticket_id = $_GET['id'] ?? null;
    
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da demanda é obrigatório']);
        exit;
    }
    
    try {
        // Buscar dados do ticket antes de excluir (incluindo dados do solicitante)
        $select_query = "SELECT t.titulo, t.descricao, t.status, t.prioridade, t.categoria_id, t.agente_id, 
                                u.nome as solicitante_nome, u.email as solicitante_email 
                         FROM tickets t 
                         LEFT JOIN usuarios u ON t.usuario_id = u.id 
                         WHERE t.id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $ticket_id);
        $select_stmt->execute();
        $ticket_data = $select_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket_data) {
            http_response_code(404);
            echo json_encode(['error' => 'Demanda não encontrada']);
            exit;
        }
        
        // Registrar auditoria ANTES de excluir
        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'excluir_ticket', 'tickets', $ticket_id, json_encode($ticket_data), null);
        
        // Excluir ticket
        $query = "DELETE FROM tickets WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $ticket_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir demanda: ' . $e->getMessage()]);
    }
}
?>
