<?php
/**
 * Módulo Satisfação — Painel de Setores: por setor, os servidores com convites enviados, taxa de resposta e notas.
 *
 *   GET [?data_ini=&data_fim=]          painel (só setores com "pesquisa_painel" ligado; visibilidade como o painel de avaliações)
 *   GET ?acao=csv[&data_ini=&data_fim=] exportação
 *   PUT {setor_id, pesquisa_painel}     liga/desliga o setor no painel (só administrador)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/atividades-acompanhamento-service.php';
require_once __DIR__ . '/../services/pesquisa-painel-service.php';

AuthMiddleware::configurarCORS(['GET', 'PUT']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarSatisfacao($user);

try {
    $db = (new Database())->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        AuthMiddleware::verificarAdmin($user);
        AuthMiddleware::verificarCSRF();
        $corpo = json_decode(file_get_contents('php://input'), true) ?? [];
        $setorId = (int) ($corpo['setor_id'] ?? 0);
        $ligado = !empty($corpo['pesquisa_painel']) ? 1 : 0;
        $stmt = $db->prepare("UPDATE setores SET pesquisa_painel = ? WHERE id = ?");
        $stmt->execute([$ligado, $setorId]);
        $existe = $db->prepare("SELECT 1 FROM setores WHERE id = ?");
        $existe->execute([$setorId]);
        if (!$existe->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Setor não encontrado']);
            exit;
        }
        (new AuditoriaService($db))->registrar($user['id'], 'alterar_setor_painel_satisfacao', 'setores', $setorId, null, json_encode(['pesquisa_painel' => $ligado]));
        echo json_encode(['success' => true, 'pesquisa_painel' => $ligado === 1]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido']);
        exit;
    }

    $filtros = PesquisaPainelService::lerFiltros($_GET);
    $escopo = AcessoService::escopoSetores($user);
    $dados = PesquisaPainelService::painelSetores($db, $escopo, $filtros['data_ini'], $filtros['data_fim']);

    if (($_GET['acao'] ?? '') === 'csv') {
        $p = $dados['periodos'];
        $linhas = [];
        foreach ($dados['setores'] as $s) {
            foreach ($s['servidores'] as $sv) {
                $taxa = $sv['total_convites'] > 0 ? round($sv['total_avaliados'] / $sv['total_convites'] * 100) . '%' : '';
                $linhas[] = [$s['nome'], $s['coordenador_nome'] ?? '', $sv['nome'], $sv['sem_atual'], $sv['sem_ant'], $sv['mes_atual'], $sv['mes_ant'],
                    $sv['custom'], $sv['total_convites'], $sv['total_avaliados'], $taxa,
                    $sv['media_nota'] !== null ? number_format($sv['media_nota'], 1, ',', '') : ''];
            }
        }
        AtividadesAcompanhamentoService::enviarCsv('painel-setores-' . date('Ymd-His') . '.csv',
            ['Setor', 'Coordenador(a)', 'Servidor', 'Convites na semana atual', 'Convites na semana anterior', 'Convites no mês atual',
             'Convites no mês anterior', 'Convites no período (' . $p['custom_ini'] . ' a ' . $p['custom_fim'] . ')', 'Total de convites',
             'Total avaliado', 'Taxa de resposta', 'Nota média'], $linhas);
        exit;
    }
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[PESQUISA SETORES] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao carregar o painel de setores']);
}
