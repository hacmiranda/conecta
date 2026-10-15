<?php
/**
 * Log de e-mails recebidos e processados por caixa (setor_emails), para acompanhamento de
 * quais e-mails viraram demanda/comentário e quais falharam em cada caixa cadastrada.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user); // log de e-mails: exclusivo do administrador

$database = new Database();
$db = $database->getConnection();

$ehGestor = ($user['tipo'] ?? '') === 'gestor';
$pagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$limite = isset($_GET['limite']) ? min(200, max(1, (int) $_GET['limite'])) : 50;
$offset = ($pagina - 1) * $limite;

$filtros = [];
$params = [];

if ($ehGestor) {
    $filtros[] = "se.setor_id = :setor_id_escopo";
    $params[':setor_id_escopo'] = $user['setor_id'] ?? 0;
} elseif (isset($_GET['setor_id']) && $_GET['setor_id'] !== '') {
    $filtros[] = "se.setor_id = :setor_id_filtro";
    $params[':setor_id_filtro'] = (int) $_GET['setor_id'];
}

if (isset($_GET['setor_email_id']) && $_GET['setor_email_id'] !== '') {
    $filtros[] = "ep.setor_email_id = :setor_email_id";
    $params[':setor_email_id'] = (int) $_GET['setor_email_id'];
}

if (isset($_GET['tipo']) && $_GET['tipo'] !== '') {
    $filtros[] = "ep.tipo = :tipo";
    $params[':tipo'] = $_GET['tipo'];
}

$where = count($filtros) > 0 ? 'WHERE ' . implode(' AND ', $filtros) : '';

try {
    $queryCount = "SELECT COUNT(*) as total
                   FROM emails_processados ep
                   LEFT JOIN setor_emails se ON ep.setor_email_id = se.id
                   $where";
    $stmtCount = $db->prepare($queryCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $total = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

    $query = "SELECT ep.id, ep.message_id, ep.email_remetente, ep.assunto, ep.ticket_id, ep.tipo,
                     ep.processado_em, ep.tentativas,
                     se.id as setor_email_id, se.email as setor_email_endereco,
                     s.id as setor_id, s.nome as setor_nome,
                     t.titulo as ticket_titulo
              FROM emails_processados ep
              LEFT JOIN setor_emails se ON ep.setor_email_id = se.id
              LEFT JOIN setores s ON se.setor_id = s.id
              LEFT JOIN tickets t ON ep.ticket_id = t.id
              $where
              ORDER BY ep.processado_em DESC
              LIMIT :limite OFFSET :offset";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'registros' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'pagina' => $pagina,
        'total_paginas' => (int) ceil($total / $limite),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar log de e-mails processados']);
}
