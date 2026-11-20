<?php
/**
 * Dashboard → E-mails Enviados: quantitativos das respostas da equipe enviadas ao solicitante, por setor
 * e por funcionário (gestor e operador), ontem / últimos 7 dias / últimos 30 dias / período escolhido.
 * Regra em EmailsEnviadosService.
 *
 * GET ?setor_id= (seletor do cabeçalho) &inicio=AAAA-MM-DD &fim=AAAA-MM-DD
 * Escopo igual ao do Dashboard: visão total e gestão pelo AcessoService::escopoSetores; o operador vê só
 * os próprios números (sem a tabela por setor); os demais papéis recebem 403.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/emails-enviados-service.php';

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
$db = (new Database())->getConnection();

$porSetor = AcessoService::ehVisaoTotal($user) || AcessoService::ehGestao($user);
if ($porSetor) {
    $escopo = AcessoService::escopoSetores($user); // ?setor_id= fora dos setores do coordenador: 403
    $somenteUsuario = null;
} elseif (($user['tipo'] ?? '') === 'operador') {
    $escopo = null;
    $somenteUsuario = (int) $user['id'];
} else {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

try {
    $hoje = new DateTimeImmutable('today');
    [$inicio, $fim] = EmailsEnviadosService::normalizarPeriodo($_GET['inicio'] ?? null, $_GET['fim'] ?? null, $hoje);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

try {
    $dados = EmailsEnviadosService::resumo($db, $escopo, $somenteUsuario, $inicio, $fim, $hoje);
    $dados['mostra_setores'] = $somenteUsuario === null;
    echo json_encode($dados);
} catch (Throwable $e) {
    error_log('[DASHBOARD EMAILS] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao carregar os e-mails enviados']);
}
