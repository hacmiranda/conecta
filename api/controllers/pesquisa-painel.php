<?php
/**
 * Módulo Satisfação — painel de avaliações (antigo "painel admin" do METRICA360).
 * Quem vê: admin, coordenador geral, supervisor (todos os setores) e coordenador (os dele); o ?setor_id= do
 * cabeçalho escolhe um setor dentro do que a pessoa já enxerga.
 *
 *   GET ?acao=resumo[&dias=7|30|90|180]   indicadores, evolução da nota, categorias, meta e frase do dia
 *   GET ?acao=lista&pagina=&status=&nota=&busca=&data_ini=&data_fim=   avaliações (20 por página)
 *   GET ?acao=csv&...mesmos filtros       exportação (auditada)
 * A triagem (status e anotação) é em pesquisa-triagem.php.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/atividades-acompanhamento-service.php';
require_once __DIR__ . '/../services/pesquisa-painel-service.php';

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
AuthMiddleware::verificarSatisfacao($user);

try {
    $db = (new Database())->getConnection();
    $escopo = AcessoService::escopoSetores($user);
    $filtros = PesquisaPainelService::lerFiltros($_GET);
    $acao = $_GET['acao'] ?? 'resumo';

    if ($acao === 'resumo') {
        $dias = (int) ($_GET['dias'] ?? 30);
        $dias = in_array($dias, [7, 30, 90, 180], true) ? $dias : 30;
        echo json_encode([
            'indicadores' => PesquisaPainelService::indicadores($db, $escopo),
            'evolucao'    => PesquisaPainelService::evolucao($db, $escopo, $filtros, $dias),
            'dias'        => $dias,
            'categorias'  => PesquisaPainelService::categorias($db, $escopo),
            'meta_nota_media' => (float) PesquisaConfig::get($db, 'meta_nota_media'),
            'frase'       => PesquisaPainelService::fraseDoDia($db),
            'pode_triar'  => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'lista') {
        echo json_encode(PesquisaPainelService::listarAvaliacoes($db, $escopo, $filtros, (int) ($_GET['pagina'] ?? 1)), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'csv') {
        $lista = PesquisaPainelService::listarAvaliacoes($db, $escopo, $filtros, 1, 100, true);
        (new AuditoriaService($db))->registrar($user['id'], 'exportar_avaliacoes', 'pesquisa_avaliacoes', null, null, json_encode(['linhas' => count($lista['itens'])]));
        $linhas = array_map(fn($a) => [
            $a['id'], $a['nota'], $a['comentario'] ?? '', implode(', ', array_column($a['categorias'], 'nome')),
            ['pendente' => 'Pendente', 'em_tratativa' => 'Em tratativa', 'resolvido' => 'Resolvido'][$a['status']] ?? $a['status'],
            $a['anotacao'] ?? '', $a['servidor_nome'] ?? '', $a['setor_nome'] ?? '', date('d/m/Y H:i', strtotime($a['criado_em'])),
        ], $lista['itens']);
        AtividadesAcompanhamentoService::enviarCsv('avaliacoes-' . date('Ymd-His') . '.csv',
            ['ID', 'Nota', 'Comentário', 'Categorias', 'Situação', 'Anotações', 'Servidor', 'Setor', 'Data'], $linhas);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida']);
} catch (Throwable $e) {
    error_log('[PESQUISA PAINEL] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao carregar as avaliações']);
}
