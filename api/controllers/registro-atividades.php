<?php
/**
 * Registro de Atividades do usuário logado (veio da "Área do Funcionário" do METRICA360, 27/10/2026).
 * Gestor e operador com setor; cada um só vê e altera os próprios relatórios.
 *
 *   GET  ?data=YYYY-MM-DD          tela do dia (sem data: a pendência mais antiga, ou hoje); cria o rascunho
 *   GET  ?acao=itens&id=           itens preenchidos de um relatório do histórico
 *   PUT  ?id= {itens[], modalidade}             salva o rascunho (403 se não é seu ou já foi enviado)
 *   POST ?acao=finalizar&id= {itens[], modalidade}  salva e envia (depois não muda mais)
 *   POST ?acao=favorito {atividade_tipo_id}     liga/desliga a estrela
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/registro-atividade-service.php';
require_once __DIR__ . '/../services/prazo-atividade-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
$metodo = $_SERVER['REQUEST_METHOD'];
$acao = $_GET['acao'] ?? '';

if (in_array($metodo, ['POST', 'PUT'], true)) {
    AuthMiddleware::verificarCSRF();
}

function erroRegistro(int $codigo, string $mensagem): void {
    http_response_code($codigo);
    echo json_encode(['error' => $mensagem]);
    exit;
}

$usuarioId = (int) $user['id'];
$setorId = isset($user['setor_id']) && $user['setor_id'] !== null ? (int) $user['setor_id'] : 0;
if (!in_array($user['tipo'] ?? '', ['gestor', 'operador'], true) || $setorId <= 0) {
    erroRegistro(403, 'O Registro de Atividades é para gestores e operadores vinculados a um setor.');
}

$db = (new Database())->getConnection();
$servico = new RegistroAtividadeService($db);
$prazo = new PrazoAtividadeService($db);

try {
    $corpo = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($metodo === 'GET' && $acao === 'itens') {
        $registro = $servico->buscar((int) ($_GET['id'] ?? 0));
        if (!RegistroAtividadeService::dono($registro, $usuarioId)) erroRegistro(403, 'Relatório não encontrado');
        echo json_encode(['itens' => $servico->itens((int) $registro['id'], true)]);
        exit;
    }

    if ($metodo === 'GET') {
        $hoje = date('Y-m-d');
        // Pendências dos últimos 180 dias (o suficiente para o atraso acumulado, sem varrer o histórico todo)
        $inicioJanela = (new DateTimeImmutable('-180 days'))->format('Y-m-d');
        $abertas = array_values(array_filter($prazo->pendenciasUsuario($usuarioId, $inicioJanela, $hoje),
            fn($p) => $p['status'] !== 'abonado'));

        $data = RegistroAtividadeService::dataValida($_GET['data'] ?? null);
        if ($data === null) {
            $data = $abertas ? ($abertas[0]['data'] ?? $abertas[0]['semana_ini']) : $hoje;
        }

        $registro = $servico->buscarOuCriarRascunho($usuarioId, $data);
        $itens = array_column($servico->itens((int) $registro['id']), null, 'atividade_tipo_id');
        $atividades = array_map(function ($a) use ($itens) {
            $i = $itens[$a['id']] ?? null;
            return $a + ['quantidade' => $i ? $i['quantidade'] : 0, 'observacao' => $i ? $i['observacao'] : ''];
        }, $servico->atividadesDoSetor($setorId, $usuarioId));

        $exigeModalidade = !empty($prazo->diasDeEntregaDoUsuario($usuarioId));
        echo json_encode([
            'data'                => $data,
            'hoje'                => $hoje,
            'data_minima'         => RegistroAtividadeService::DATA_MINIMA,
            'registro'            => [
                'id'         => (int) $registro['id'],
                'status'     => $registro['status'],
                'enviado_em' => $registro['enviado_em'],
                'modalidade' => $registro['modalidade'],
            ],
            'atividades'          => $atividades,
            'pendencias'          => $abertas,
            'historico'           => $servico->historico($usuarioId, 10),
            'exige_modalidade'    => $exigeModalidade,
            'modalidade_sugerida' => $exigeModalidade
                ? PrazoAtividadeService::modalidadeSugerida($registro['modalidade'], $prazo->configSetor($setorId), $prazo->configUsuario($usuarioId), $data)
                : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST' && $acao === 'favorito') {
        $estado = $servico->alternarFavorito($usuarioId, $setorId, (int) ($corpo['atividade_tipo_id'] ?? 0));
        if ($estado === null) erroRegistro(404, 'Atividade não encontrada no seu setor');
        echo json_encode(['success' => true, 'favorito' => $estado]);
        exit;
    }

    if (($metodo === 'PUT') || ($metodo === 'POST' && $acao === 'finalizar')) {
        $registroId = (int) ($_GET['id'] ?? 0);
        $registro = $servico->buscar($registroId);
        if (!RegistroAtividadeService::dono($registro, $usuarioId)) erroRegistro(403, 'Relatório não encontrado');
        if ($registro['status'] !== 'rascunho') erroRegistro(409, 'Este relatório já foi enviado e não pode mais ser alterado.');

        $itens = RegistroAtividadeService::normalizarItens($corpo['itens'] ?? [], $servico->tiposAtivosDoSetor($setorId));
        $modalidade = in_array($corpo['modalidade'] ?? null, PrazoAtividadeService::MODALIDADES, true) ? $corpo['modalidade'] : null;
        $finalizar = $metodo === 'POST';

        // Quem tem dias de entrega precisa dizer se o dia foi remoto ou presencial; os demais ficam como remoto
        if ($finalizar) {
            if (!empty($prazo->diasDeEntregaDoUsuario($usuarioId))) {
                if ($modalidade === null) erroRegistro(400, 'Informe se o trabalho do dia foi remoto ou presencial antes de enviar.');
            } else {
                $modalidade = 'remoto';
            }
        }

        $db->beginTransaction();
        if (!$servico->salvarRascunho($registroId, $usuarioId, $itens, $modalidade)) {
            $db->rollBack();
            erroRegistro(409, 'Este relatório já foi enviado e não pode mais ser alterado.');
        }
        if ($finalizar) {
            if (!$servico->finalizar($registroId, $usuarioId, $modalidade)) {
                $db->rollBack();
                erroRegistro(409, 'Não foi possível enviar o relatório.');
            }
            (new AuditoriaService($db))->registrar($usuarioId, 'enviar_registro_atividades', 'registros_atividades', $registroId, null,
                json_encode(['data' => $registro['data_referencia'], 'modalidade' => $modalidade,
                             'atividades' => count(array_filter($itens, fn($i) => $i['quantidade'] > 0))], JSON_UNESCAPED_UNICODE));
        }
        $db->commit();

        $atualizado = $servico->buscar($registroId);
        echo json_encode(['success' => true, 'status' => $atualizado['status'], 'enviado_em' => $atualizado['enviado_em']]);
        exit;
    }

    erroRegistro(405, 'Método não permitido');
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[REGISTRO-ATIVIDADES] Erro: ' . $e->getMessage());
    erroRegistro(500, 'Erro ao processar o registro de atividades');
}
