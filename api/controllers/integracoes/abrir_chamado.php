<?php
/**
 * Integração M2M: sistemas satélites abrem demandas (POST, cabeçalho X-API-KEY = INTEGRATION_API_KEY_*).
 * Servidor a servidor: sem CORS. Limites: corpo até 8 MB, anexo pela regra do UploadService (até 20 MB,
 * extensão + conteúdo real), 10 chaves erradas por IP em 15 min, 120 pedidos por IP por minuto.
 * chave_idempotencia (opcional): repetir o mesmo pedido devolve a demanda já criada, sem duplicar.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

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
require_once __DIR__ . '/../../services/rate-limit-service.php';
require_once __DIR__ . '/../../services/rede-service.php';
require_once __DIR__ . '/../../services/upload-service.php';

const MAX_CORPO_BYTES = 8 * 1024 * 1024;

$ip = RedeService::ipCliente();

// Chave errada repetida a partir do mesmo IP: bloqueia antes de qualquer outra coisa
if (RateLimitService::verificar($ip, 'integracao_chave', 10, 900)['bloqueado']) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitas tentativas com chave inválida. Tente mais tarde.']);
    exit;
}

// Chave Mestra da API de Integração: definida exclusivamente no .env (INTEGRATION_API_KEY_LOCAL/_PROD).
// Sem chave configurada, a integração fica desativada (não existe valor padrão no código).
$expected_key = (string) Environment::get('integration_api_key', '');
if ($expected_key === '') {
    error_log('[M2M Integration] INTEGRATION_API_KEY não configurada no .env; integração desativada.');
    http_response_code(503);
    echo json_encode(['error' => 'Integração não configurada.']);
    exit;
}

$headers = getallheaders();
$api_key = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($api_key) || !hash_equals($expected_key, (string) $api_key)) {
    RateLimitService::registrar($ip, 'integracao_chave');
    http_response_code(401);
    echo json_encode(['error' => 'Acesso não autorizado. API KEY ausente ou inválida.']);
    exit;
}

if (RateLimitService::consumir($ip, 'integracao', 120, 60)['bloqueado']) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitos pedidos. Tente novamente em instantes.']);
    exit;
}

// Lê no máximo 8 MB + 1 byte: se passou, recusa sem processar
$corpo = (string) file_get_contents('php://input', false, null, 0, MAX_CORPO_BYTES + 1);
if (strlen($corpo) > MAX_CORPO_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'Pedido grande demais (máximo 8 MB, incluindo o anexo).']);
    exit;
}
$input = json_decode($corpo, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON inválido.']);
    exit;
}

// Resgatar Variáveis do Payload (Sistemas Satélites enviam isso)
// Origem: identificador curto (letras, números e _), usado no texto da demanda
$origem = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($input['origem'] ?? ''))) ?: 'desconhecida';
$origem = substr($origem, 0, 50);
$titulo = HtmlSanitizer::texto($input['titulo'] ?? '', 200);
$descricao_raw = (string) ($input['descricao'] ?? '');
$email_solicitante = strtolower(trim((string) ($input['email_solicitante'] ?? '')));
$nome_solicitante = HtmlSanitizer::texto($input['nome_solicitante'] ?? ('Usuário ' . strtoupper($origem)), 100);
$prioridade = strtolower((string) ($input['prioridade'] ?? 'normal'));
$categoria_id = isset($input['categoria_id']) && $input['categoria_id'] !== '' ? (int) $input['categoria_id'] : null;
$metadados = $input['metadados'] ?? null; // Array opcional para logs do navegador/sistema
$chave_idempotencia = substr(preg_replace('/[^A-Za-z0-9:_\-.]/', '', (string) ($input['chave_idempotencia'] ?? '')), 0, 64) ?: null;

// Extensão Anexos Base64 (Opcional - Prints de Erro)
$anexo_base64 = $input['anexo_base64'] ?? null;
$anexo_nome = basename(str_replace(chr(92), '/', (string) ($input['anexo_nome'] ?? 'print_erro_sistema.png')));

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

if (empty($titulo) || trim($descricao_raw) === '') {
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

// Anexo: base64 estrito e a mesma regra dos anexos da tela (extensão + conteúdo real, até 20 MB)
$anexo = null;
if (!empty($anexo_base64)) {
    $anexo_base64 = (string) $anexo_base64;
    if (strpos($anexo_base64, ',') !== false) {
        // Remove o prefixo "data:image/png;base64,"
        $anexo_base64 = substr($anexo_base64, strpos($anexo_base64, ',') + 1);
    }
    $conteudo = base64_decode($anexo_base64, true);
    if ($conteudo === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Anexo em base64 inválido.']);
        exit;
    }
    if (pathinfo($anexo_nome, PATHINFO_EXTENSION) === '') {
        $anexo_nome .= '.png';
    }
    $mime = UploadService::mimeDoConteudo($conteudo);
    $validacao = UploadService::validar($anexo_nome, $mime, strlen($conteudo));
    if (!$validacao['ok']) {
        http_response_code(400);
        echo json_encode(['error' => 'Anexo recusado: ' . $validacao['motivo'] . '.']);
        exit;
    }
    $anexo = ['conteudo' => $conteudo, 'nome' => $anexo_nome, 'extensao' => $validacao['extensao'], 'mime' => $mime];
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
    foreach (array_slice($metadados, 0, 50, true) as $key => $value) {
        $clean_key = htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $key)));
        $clean_value = htmlspecialchars(mb_substr(is_array($value) ? (string) json_encode($value) : (string) $value, 0, 2000));
        $descricao_final .= "<li style=\"margin-bottom: 4px;\"><strong style=\"color: #94a3b8;\">{$clean_key}:</strong> {$clean_value}</li>";
    }
    $descricao_final .= "</ul></div>";
}

/** Resposta de sucesso (também usada quando o pedido repetido já tinha virado demanda). */
function responderDemanda(int $ticket_id, ?string $protocolo, string $origem, bool $repetido = false): void {
    $rotulo = ProtocoloService::rotulo($protocolo, $ticket_id);
    echo json_encode([
        'success' => true,
        'ticket_id' => $ticket_id,
        'protocolo' => $rotulo,
        'origem' => $origem,
        'idempotente' => $repetido,
        'message' => ($repetido ? 'Pedido já recebido antes. Demanda existente: ' : 'Demanda do Satélite inserida limpa na Mesa de TI com identificador: ') . $rotulo,
    ]);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Pedido repetido (mesma origem + chave_idempotencia): devolve a demanda já criada
    if ($chave_idempotencia !== null) {
        $existe = $db->prepare("SELECT id, protocolo FROM tickets WHERE origem_api = ? AND chave_idempotencia = ? LIMIT 1");
        $existe->execute([$origem, $chave_idempotencia]);
        if ($ja = $existe->fetch(PDO::FETCH_ASSOC)) {
            responderDemanda((int) $ja['id'], $ja['protocolo'], $origem, true);
            exit;
        }
    }

    // 2. Validação Padrão de Prioridade
    if (!in_array($prioridade, ['baixa', 'normal', 'alta', 'urgente'])) {
        $prioridade = 'normal';
    }

    // 2.1 Setor de entrada: integrações externas caem em "Geral" para triagem
    $setor_stmt = $db->prepare("SELECT id FROM setores WHERE nome = 'Geral' AND ativo = 1 LIMIT 1");
    $setor_stmt->execute();
    $setor_id = $setor_stmt->fetchColumn() ?: null;

    // 2.2 Subcategoria: só vale se for do setor de entrada (senão a demanda nasce sem)
    if ($categoria_id !== null) {
        $cat = $db->prepare("SELECT COUNT(*) FROM categorias WHERE id = ? AND setor_id <=> ?");
        $cat->execute([$categoria_id, $setor_id]);
        if ((int) $cat->fetchColumn() === 0) {
            $categoria_id = null;
        }
    }

    $db->beginTransaction();

    // 1. Shadow Account (Procura E-mail ou Forja)
    $stmt_user = $db->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $stmt_user->execute([$email_solicitante]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $usuario_id = $user['id'];
    } else {
        // O e-mail nunca existiu no Conecta CRPRS: cria um solicitante externo (cliente, nunca entra no sistema)
        $senha_random = password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT);
        $stmt_create = $db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, ativo) VALUES (?, ?, ?, 'cliente', 1)");
        $stmt_create->execute([$nome_solicitante, $email_solicitante, $senha_random]);
        $usuario_id = $db->lastInsertId();
    }

    // 3. Gerar o Ticket com Status Novo Mapeado para API
    $protocolo = ProtocoloService::gerar($db);
    $query = "INSERT INTO tickets (protocolo, titulo, descricao, usuario_id, origem_api, chave_idempotencia, email_solicitante, prioridade, categoria_id, setor_id, status)
              VALUES (:protocolo, :titulo, :descricao, :usuario_id, :origem_api, :chave, :email_solicitante, :prioridade, :categoria_id, :setor_id, 'novo')";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':protocolo' => $protocolo,
        ':titulo' => $titulo,
        ':descricao' => $descricao_final, // Conteúdo carimbado pela Flag
        ':usuario_id' => $usuario_id,
        ':origem_api' => $origem,
        ':chave' => $chave_idempotencia,
        ':email_solicitante' => $email_solicitante,
        ':prioridade' => $prioridade,
        ':categoria_id' => $categoria_id,
        ':setor_id' => $setor_id,
    ]);
    $ticket_id = (int) $db->lastInsertId();

    // 4. SLA, janela de setor e evento de abertura na linha do tempo
    AcessoService::registrarAbertura($db, $ticket_id, $setor_id ? (int) $setor_id : null, (int) $usuario_id, 'via integração (' . $origem . ')');

    // 4.1 Anexo já validado
    if ($anexo !== null) {
        if (UploadService::salvarConteudo($db, $anexo['conteudo'], $anexo['nome'], $anexo['extensao'], $anexo['mime'], $ticket_id) === null) {
            throw new RuntimeException('Falha ao gravar o anexo');
        }
    }

    $db->commit();

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
        $msg_notificacao = "🤖 Novo ticket M2M (" . ProtocoloService::rotulo($protocolo, $ticket_id) . ") gerado a partir da: " . strtoupper(str_replace('_', ' ', $origem));
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

    responderDemanda($ticket_id, $protocolo, $origem);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    // Corrida com a mesma chave_idempotencia (índice único): o outro pedido já criou a demanda
    if ($chave_idempotencia !== null && $e instanceof PDOException && $e->getCode() === '23000') {
        $existe = $db->prepare("SELECT id, protocolo FROM tickets WHERE origem_api = ? AND chave_idempotencia = ? LIMIT 1");
        $existe->execute([$origem, $chave_idempotencia]);
        if ($ja = $existe->fetch(PDO::FETCH_ASSOC)) {
            responderDemanda((int) $ja['id'], $ja['protocolo'], $origem, true);
            exit;
        }
    }
    error_log("[M2M Integration FATAL] " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno ao registrar a demanda.']);
}
