<?php
/**
 * Dashboard (reescrito em 19/11/2026): GET ?setor_id= (seletor da barra lateral) &inicio=&fim= (AAAA-MM-DD).
 * Sem período: os últimos 30 dias. Os números vêm de DashboardService (período, período anterior e a
 * fotografia das demandas ativas). Período inválido ou acima de 366 dias: 400.
 */
header('Content-Type: application/json');
header('Cache-Control: private, max-age=60'); // alivia as várias contagens do MySQL

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/periodo-service.php';
require_once __DIR__ . '/../services/dashboard-service.php';

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

// Escopo por papel: visão total vê tudo ou o setor escolhido; gestor e coordenador, os próprios setores;
// operador, só as demandas dele (responsável ou que abriu); cliente, as que abriu. Os ids vêm do banco/JWT
// e são convertidos em inteiros antes de entrar no SQL.
$tipo = $user['tipo'] ?? '';
$filtroT = ' AND t.excluido_em IS NULL'; // lixeira fica de fora
$porSetor = AcessoService::ehVisaoTotal($user) || AcessoService::ehGestao($user);
$escopo = $porSetor ? AcessoService::escopoSetores($user) : null; // ?setor_id= fora dos setores do coordenador: 403
$recorteSetor = $porSetor && $escopo !== null;
$operadorId = null;

if ($recorteSetor) {
    $filtroT .= ' AND ' . AcessoService::filtroSetorSql('t.setor_id', $escopo);
} elseif ($tipo === 'operador') {
    $operadorId = (int) $user['id'];
    $filtroT .= ' AND (t.agente_id = ' . $operadorId . ' OR t.usuario_id = ' . $operadorId . ')';
} elseif ($tipo === 'cliente') {
    $filtroT .= ' AND t.usuario_id = ' . (int) $user['id'];
} elseif (!$porSetor) {
    http_response_code(403); // papel desconhecido nunca enxerga tudo
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

try {
    $hoje = new DateTimeImmutable('today');
    [$inicio, $fim] = PeriodoService::normalizar($_GET['inicio'] ?? null, $_GET['fim'] ?? null, $hoje,
        $hoje->modify('-29 days')->format('Y-m-d'));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

try {
    $servico = new DashboardService($db, $filtroT, [
        'recorte_setor' => $recorteSetor,
        'escopo' => $escopo,
        'operador_id' => $operadorId,
    ]);
    echo json_encode($servico->montar($inicio, $fim));
} catch (Throwable $e) {
    error_log('[DASHBOARD] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar dados do dashboard']);
}
