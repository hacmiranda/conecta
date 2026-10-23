<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Em produção, restrinja aos IPs da Locaweb
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Apenas método POST é permitido']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../services/acesso-service.php';
require_once __DIR__ . '/../../services/html-sanitizer.php';
require_once __DIR__ . '/../../services/protocolo-service.php';

// Validar API KEY da Transação (Segurança Server-to-Server)
$headers = getallheaders();
$api_key = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

// Chave Mestra da API de Integração: definida exclusivamente no .env (INTEGRATION_API_KEY_LOCAL/_PROD).
// Sem chave configurada, a integração fica desativada (não existe valor padrão no código).
$expected_key = (string) Environment::get('integration_api_key', '');

if ($expected_key === '') {
    error_log('[M2M Integration] INTEGRATION_API_KEY não configurada no .env; integração desativada.');
    http_response_code(503);
    echo json_encode(['error' => 'Integração não configurada.']);
    exit;
}

if (empty($api_key) || !hash_equals($expected_key, (string) $api_key)) {
    http_response_code(401);
    echo json_encode(['error' => 'Acesso não autorizado. API KEY ausente ou inválida.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON inválido.']);
    exit;
}

// Resgatar Variáveis do Payload (Sistemas Satélites enviam isso)
// Origem: identificador curto (letras, números e _), usado no texto da demanda
$origem = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($input['origem'] ?? ''))) ?: 'desconhecida';
$origem = substr($origem, 0, 50);
$titulo = HtmlSanitizer::texto($input['titulo'] ?? '', 200);
$descricao_raw = $input['descricao'] ?? '';
$email_solicitante = strtolower(trim($input['email_solicitante'] ?? ''));
$nome_solicitante = HtmlSanitizer::texto($input['nome_solicitante'] ?? ('Usuário ' . strtoupper($origem)), 100);
$prioridade = strtolower($input['prioridade'] ?? 'normal');
$categoria_id = $input['categoria_id'] ?? null;
$metadados = $input['metadados'] ?? null; // Array opcional para logs do navegador/sistema

// Extensão Anexos Base64 (Opcional - Prints de Erro)
$anexo_base64 = $input['anexo_base64'] ?? null;
$anexo_nome = $input['anexo_nome'] ?? 'print_erro_sistema.png';

// Lógica de Atribuição de E-mail (Ouvidoria)
if ($origem === 'ouvidoria_interna') {
    // Tickets criados de dentro do painel restrito da ouvidoria (Funcionários/Ouvidor)
    $email_solicitante = 'ouvidoria@crprs.org.br';
    $nome_solicitante = 'Gestão da Ouvidoria CRPRS';
} else if ($origem === 'ouvidoria_publica') {
    // Tickets criados pelas telas de denúncia/manifestação externa
    if (empty($email_solicitante)) {
        http_response_code(400);
        echo json_encode(['error' => 'Para manifestações públicas, o e-mail do cidadão é obrigatório para devolução do retorno da TI.']);
        exit;
    }
} else if (empty($email_solicitante)) {
    // Trava Curinga para outras origens (ex: Contratos)
    $email_solicitante = 'sistema_interno@crprs.org.br';
}

if (empty($titulo) || empty($descricao_raw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Título e descrição (corpo) da demanda são obrigatórios.']);
    exit;
}

// Sanitização contra XSS (HTML seguro do editor)
$descricao = HtmlSanitizer::rico($descricao_raw);

if ($email_solicitante !== '' && !filter_var($email_solicitante, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'E-mail do solicitante inválido.']);
    exit;
}

// Tag Tática: Identidade Visual da API no Corpo da Descrição
$descricao_final = "<div style=\"background-color: #f1f5f9; border-left: 4px solid #3b82f6; padding: 12px; margin-bottom: 20px; border-radius: 4px;\">";
$descricao_final .= "<p style=\"margin: 0; font-size: 13px; color: #475569;\"><strong>🤖 AVISO DE INTEGRAÇÃO M2M</strong><br>";
$descricao_final .= "Esta demanda foi protocolada automaticamente via API (<strong>" . htmlspecialchars(strtoupper(str_replace('_', ' ', $origem))) . "</strong>).<br>";
$descricao_final .= "Solicitante Identificado Repasse: <strong>" . htmlspecialchars($nome_solicitante . ' (' . $email_solicitante . ')') . "</strong></p></div>";
$descricao_final .= $descricao;

// Bloco Hacker: Embutir Logs Técnicos do Navegador/Sistema no rodapé do ticket
if (!empty($metadados) && is_array($metadados)) {
    $descricao_final .= "<br><br><div style=\"background-color: #1e293b; color: #10b981; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 11px; margin-top: 15px;\">";
    $descricao_final .= "<strong style=\"color: #f8fafc;\">🖥️ LOGS E METADADOS DE DEPURACÃO:</strong><br>";
    $descricao_final .= "<ul style=\"list-style: none; padding-left: 0; margin-top: 8px;\">";
    foreach ($metadados as $key => $value) {
        $clean_key = htmlspecialchars(ucfirst(str_replace('_', ' ', $key)));
        $clean_value = htmlspecialchars(is_array($value) ? json_encode($value) : $value);
        $descricao_final .= "<li style=\"margin-bottom: 4px;\"><strong style=\"color: #94a3b8;\">{$clean_key}:</strong> {$clean_value}</li>";
    }
    $descricao_final .= "</ul></div>";
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 1. Shadow Account (Procura E-mail ou Forja)
    $stmt_user = $db->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $stmt_user->execute([$email_solicitante]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $usuario_id = $user['id'];
    } else {
        // O e-mail nunca existiu no Conecta CRPRS. Forja a conta e libera pra uso em Background.
        $senha_random = password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT);
        $stmt_create = $db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, ativo) VALUES (?, ?, ?, 'cliente', 1)");
        $stmt_create->execute([$nome_solicitante, $email_solicitante, $senha_random]);
        $usuario_id = $db->lastInsertId();
    }
    
    // 2. Validação Padrão de Prioridade
    if (!in_array($prioridade, ['baixa', 'normal', 'alta', 'urgente'])) {
        $prioridade = 'normal';
    }
    
    // 2.1 Setor de entrada: integrações externas caem em "Geral" para triagem
    $setor_stmt = $db->prepare("SELECT id FROM setores WHERE nome = 'Geral' AND ativo = 1 LIMIT 1");
    $setor_stmt->execute();
    $setor_id = $setor_stmt->fetchColumn() ?: null;

    // 3. Gerar o Ticket com Status Novo Mapeado para API
    $protocolo = ProtocoloService::gerar($db);
    $query = "INSERT INTO tickets (protocolo, titulo, descricao, usuario_id, origem_api, email_solicitante, prioridade, categoria_id, setor_id, status) VALUES (:protocolo, :titulo, :descricao, :usuario_id, :origem_api, :email_solicitante, :prioridade, :categoria_id, :setor_id, 'novo')";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':protocolo', $protocolo);
    $stmt->bindParam(':titulo', $titulo);
    $stmt->bindParam(':descricao', $descricao_final); // Conteúdo carimbado pela Flag
    $stmt->bindParam(':usuario_id', $usuario_id);
    $stmt->bindParam(':origem_api', $origem);
    $stmt->bindParam(':email_solicitante', $email_solicitante);
    $stmt->bindParam(':prioridade', $prioridade);
    $stmt->bindParam(':categoria_id', $categoria_id);
    $stmt->bindParam(':setor_id', $setor_id);

    $stmt->execute();
    $ticket_id = $db->lastInsertId();

    
    // 4. SLA, janela de setor e evento de abertura na linha do tempo
    AcessoService::registrarAbertura($db, (int) $ticket_id, $setor_id ? (int) $setor_id : null, (int) $usuario_id, 'via integração (' . $origem . ')');
    
    // 4.1 Processar Anexo Base64 do Satélite (Opcional)
    if (!empty($anexo_base64)) {
        try {
            // Remover metadado "data:image/png;base64," do começo da string, se houver
            if (strpos($anexo_base64, ',') !== false) {
                $anexo_parts = explode(',', $anexo_base64);
                $anexo_base64 = $anexo_parts[1];
            }
            
            $file_data = base64_decode($anexo_base64);
            if ($file_data !== false) {
                // Prepara diretório de destino
                $upload_dir = dirname(__DIR__, 3) . '/backend/anexos/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $extensao = strtolower(pathinfo($anexo_nome, PATHINFO_EXTENSION));
                if (empty($extensao)) $extensao = 'png';
                
                // Bloqueio de Segurança para Uploads Livres
                $tipos_permitidos = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
                
                if (in_array($extensao, $tipos_permitidos)) {
                    // Nome Randomizado Único
                    $nome_unico = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', $anexo_nome);
                    $caminho_destino = $upload_dir . $nome_unico;
                    
                    if (file_put_contents($caminho_destino, $file_data)) {
                        $tamanho = filesize($caminho_destino);
                        
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $tipo_mime = finfo_file($finfo, $caminho_destino);
                        finfo_close($finfo);
                        
                        $query_anexo = "INSERT INTO anexos (ticket_id, nome_original, nome_arquivo, tamanho, tipo) VALUES (:ticket_id, :nome_original, :nome_arquivo, :tamanho, :tipo)";
                        $stmt_anexo = $db->prepare($query_anexo);
                        $stmt_anexo->execute([
                            ':ticket_id' => $ticket_id,
                            ':nome_original' => $anexo_nome,
                            ':nome_arquivo' => $nome_unico,
                            ':tamanho' => $tamanho,
                            ':tipo' => $tipo_mime
                        ]);
                    }
                }
            }
        } catch (Exception $ex) {
            error_log("[M2M Integration] Falha ignorada ao salvar Base64 anexado: " . $ex->getMessage());
        }
    }
    
    // 5. Motor de Notificação (Push Disparador)
    try {
        require_once __DIR__ . '/../../services/email-sender.php';
        $emailSender = new EmailSender($db);
        $emailSender->enviarEmailNovoTicket($ticket_id);
        $emailSender->notificarAgentesNovoTicket($ticket_id);
    } catch(Exception $e) {
        error_log("[M2M Integration] E-mail de abertura abortado. Falha na lib: " . $e->getMessage());
    }
    
    // Notificar Sino dos Agentes Logados no HD
    try {
        $msg_notificacao = "🤖 Novo ticket M2M (" . ProtocoloService::rotulo($protocolo, (int) $ticket_id) . ") gerado a partir da: " . strtoupper(str_replace('_', ' ', $origem));
        // Apenas os gestores do setor de entrada (admins se não houver gestor)
        $agentes = array_column(AcessoService::destinatariosInternosDoSetor($db, $setor_id ? (int) $setor_id : null), 'id');
        
        if (!empty($agentes)) {
            $stmt_notif = $db->prepare("INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, '📡 Interceptação API', ?, ?)");
            foreach ($agentes as $ag) {
                $stmt_notif->execute([$ag, $msg_notificacao, $ticket_id]);
            }
        }
    } catch(Exception $e) {
        error_log("[M2M Integration] Erro preencher Sino App de Agentes: " . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'ticket_id' => $ticket_id,
        'protocolo' => ProtocoloService::rotulo($protocolo, (int) $ticket_id),
        'origem' => $origem,
        'message' => "Demanda do Satélite inserida limpa na Mesa de TI com identificador: " . ProtocoloService::rotulo($protocolo, (int) $ticket_id)
    ]);
    
} catch (Exception $e) {
    error_log("[M2M Integration FATAL] " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno ao registrar a demanda.']);
}
