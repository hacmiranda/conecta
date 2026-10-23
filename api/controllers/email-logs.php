<?php

header('Content-Type: application/json');

// Apenas agentes e admins podem consultar logs de e-mail
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$user = AuthMiddleware::verificarAuth();
if (!in_array($user['tipo'] ?? '', ['operador', 'gestor', 'admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

// --- Parâmetros de filtro e paginação ---
$pagina  = max(1, (int)($_GET['pagina']  ?? 1));
$limite  = min(100, max(10, (int)($_GET['limite'] ?? 50)));
$offset  = ($pagina - 1) * $limite;

$status    = $_GET['status']    ?? null;   // sucesso | falha
$tipo      = $_GET['tipo']      ?? null;   // novo_ticket | comentario | ...
$ticket_id = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : null;
$busca     = trim($_GET['busca'] ?? '');   // destinatario ou assunto
$periodo   = (int)($_GET['periodo'] ?? 7); // dias para trás

// --- Monta WHERE ---
$where  = ['el.enviado_em >= DATE_SUB(NOW(), INTERVAL :periodo DAY)'];
$params = [':periodo' => $periodo];

if ($status)    { $where[] = 'el.status = :status';       $params[':status']    = $status;    }
if ($tipo)      { $where[] = 'el.tipo = :tipo';           $params[':tipo']      = $tipo;      }
if ($ticket_id) { $where[] = 'el.ticket_id = :ticket_id'; $params[':ticket_id'] = $ticket_id; }
if ($busca)     {
    $where[]            = '(el.destinatario LIKE :busca OR el.assunto LIKE :busca)';
    $params[':busca']   = "%$busca%";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// --- Total de registros ---
$count_sql  = "SELECT COUNT(*) FROM email_logs el $whereSQL";
$count_stmt = $db->prepare($count_sql);
foreach ($params as $k => $v) { $count_stmt->bindValue($k, $v); }
$count_stmt->execute();
$total = (int)$count_stmt->fetchColumn();

// --- Dados paginados ---
$sql = "SELECT
            el.id,
            el.enviado_em,
            el.destinatario,
            el.assunto,
            el.tipo,
            el.ticket_id,
            el.status,
            el.erro,
            t.titulo AS ticket_titulo,
            t.protocolo AS ticket_protocolo
        FROM email_logs el
        LEFT JOIN tickets t ON t.id = el.ticket_id
        $whereSQL
        ORDER BY el.enviado_em DESC
        LIMIT :limite OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Estatísticas rápidas do período ---
$stats_sql  = "SELECT
                   COUNT(*)                                       AS total,
                   SUM(status = 'sucesso')                        AS sucessos,
                   SUM(status = 'falha')                          AS falhas,
                   ROUND(SUM(status = 'sucesso') / COUNT(*) * 100, 1) AS taxa_sucesso
               FROM email_logs el
               $whereSQL";
$stats_stmt = $db->prepare($stats_sql);
foreach ($params as $k => $v) { $stats_stmt->bindValue($k, $v); }
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// --- Agrupamento por tipo (para gráfico) ---
$tipo_sql  = "SELECT tipo, COUNT(*) AS qtd, SUM(status='falha') AS falhas
              FROM email_logs el
              $whereSQL
              GROUP BY tipo ORDER BY qtd DESC";
$tipo_stmt = $db->prepare($tipo_sql);
foreach ($params as $k => $v) { $tipo_stmt->bindValue($k, $v); }
$tipo_stmt->execute();
$por_tipo = $tipo_stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success'    => true,
    'pagina'     => $pagina,
    'limite'     => $limite,
    'total'      => $total,
    'paginas'    => (int)ceil($total / $limite),
    'stats'      => $stats,
    'por_tipo'   => $por_tipo,
    'logs'       => $logs,
]);
