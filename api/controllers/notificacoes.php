<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';

AuthMiddleware::configurarCORS(['GET', 'PUT', 'DELETE']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
$usuario_id = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

// Alterações exigem o token CSRF, como as demais APIs
if (in_array($method, ['PUT', 'DELETE'], true)) {
    AuthMiddleware::verificarCSRF();
}

// Só as notificações do(s) setor(es) do usuário (19/11/2026): a demanda da notificação precisa estar hoje num
// setor do escopo dele. Gestor e operador: o próprio setor; coordenador: os seus (ou o escolhido no cabeçalho);
// admin, supervisor e coordenador geral: o setor escolhido no cabeçalho (?setor_id=; vazio = todos). Aviso sem
// demanda (ticket_id NULL) aparece sempre. Vale também para "marcar todas" e "excluir todas".
$filtroSetor = AcessoService::filtroSetorSql('t.setor_id', AcessoService::escopoSetores($user));
$doEscopo = "(n.ticket_id IS NULL OR EXISTS (SELECT 1 FROM tickets t WHERE t.id = n.ticket_id AND {$filtroSetor}))";

try {
    $conn = (new Database())->getConnection();

    if ($method === 'GET') {
        // Notificações do usuário (as 50 mais recentes) e quantas não foram lidas
        $stmt = $conn->prepare("SELECT n.* FROM notificacoes n WHERE n.usuario_id = ? AND {$doEscopo} ORDER BY n.criado_em DESC LIMIT 50");
        $stmt->execute([$usuario_id]);
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtCount = $conn->prepare("SELECT COUNT(*) FROM notificacoes n WHERE n.usuario_id = ? AND n.lida = 0 AND {$doEscopo}");
        $stmtCount->execute([$usuario_id]);

        $resposta = [
            'notificacoes' => $notificacoes,
            'nao_lidas' => (int) $stmtCount->fetchColumn(),
        ];
        // Falhas de envio de e-mail nas últimas 24 h, para o administrador (o único que pode agir sobre
        // elas). Antes o sino fazia uma segunda chamada (email-alert.php) a cada 30 s, e gestor/operador
        // recebiam 403 nela.
        if (($user['tipo'] ?? '') === 'admin') {
            $falhas = $conn->query("SELECT COUNT(*) AS falhas_24h, MAX(enviado_em) AS ultima_falha FROM email_logs
                                    WHERE status = 'falha' AND enviado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch(PDO::FETCH_ASSOC);
            $resposta['alerta_email'] = ['falhas_24h' => (int) ($falhas['falhas_24h'] ?? 0), 'ultima_falha' => $falhas['ultima_falha'] ?? null];
        }
        echo json_encode($resposta);
    } elseif ($method === 'PUT') {
        // Marcar uma (?id=) ou todas como lidas
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Notificação inválida']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$id, $usuario_id]);
        } else {
            $stmt = $conn->prepare("UPDATE notificacoes n SET n.lida = 1 WHERE n.usuario_id = ? AND {$doEscopo}");
            $stmt->execute([$usuario_id]);
        }
        echo json_encode(['success' => true]);
    } elseif ($method === 'DELETE') {
        // Excluir todas as notificações do usuário (as que ele está vendo, do escopo de setores)
        $stmt = $conn->prepare("DELETE n FROM notificacoes n WHERE n.usuario_id = ? AND {$doEscopo}");
        $stmt->execute([$usuario_id]);
        echo json_encode(['success' => true]);
    } elseif ($method === 'POST') {
        // Notificações são criadas só pelo backend (tickets, comentários, ações de fluxo, lembretes)
        http_response_code(403);
        echo json_encode(['error' => 'Notificações devem ser criadas via triggers de Demandas ou Comentários internamente']);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido']);
    }
} catch (Exception $e) {
    error_log('[NOTIFICACOES] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar notificações']);
}
