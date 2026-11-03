<?php
/**
 * Módulo Satisfação — histórico dos convites de avaliação enviados (somente leitura).
 * Mesma visibilidade do painel de avaliações (admin/coordenador geral/supervisor: todos os setores; coordenador: os dele).
 *
 *   GET ?pagina=&situacao=avaliado|aguardando|expirado&origem=manual|demanda&usuario_id=&busca=&data_ini=&data_fim=
 *   GET ?acao=csv&...                     exportação (auditada)
 * Não devolve o token do convite.
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

    if (($_GET['acao'] ?? '') === 'csv') {
        $lista = PesquisaPainelService::listarConvites($db, $escopo, $filtros, 1, 100, true);
        (new AuditoriaService($db))->registrar($user['id'], 'exportar_convites', 'pesquisa_convites', null, null, json_encode(['linhas' => count($lista['itens'])]));
        $rotulo = ['avaliado' => 'Avaliado', 'aguardando' => 'Aguardando', 'expirado' => 'Expirado'];
        $linhas = array_map(fn($c) => [
            $c['id'], $c['servidor_nome'] ?? '', $c['setor_nome'] ?? '', $c['destinatario_nome'], $c['destinatario_email'],
            date('d/m/Y H:i', strtotime($c['enviado_em'])), $rotulo[$c['situacao']] ?? $c['situacao'], $c['nota'] ?? '',
            $c['comentario'] ?? '', $c['origem'] === 'demanda' ? 'Demanda' : 'Manual', $c['protocolo'] ?? '',
        ], $lista['itens']);
        AtividadesAcompanhamentoService::enviarCsv('convites-' . date('Ymd-His') . '.csv',
            ['ID', 'Servidor', 'Setor', 'Destinatário', 'E-mail', 'Enviado em', 'Situação', 'Nota', 'Comentário', 'Origem', 'Protocolo'], $linhas);
        exit;
    }

    $lista = PesquisaPainelService::listarConvites($db, $escopo, $filtros, (int) ($_GET['pagina'] ?? 1));
    $lista['indicadores'] = PesquisaPainelService::indicadoresConvites($db, $escopo, $filtros);
    $lista['servidores'] = PesquisaPainelService::servidoresComConvites($db, $escopo);
    echo json_encode($lista, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[PESQUISA CONVITES] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao carregar os convites']);
}
