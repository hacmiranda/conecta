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
 *
 * Relatório do setor (só o coordenador do setor; ?modo=setor):
 *   GET  ?modo=setor&setor_id=&data=   tela do período (semana/mês) do relatório do setor; cria o rascunho
 *   PUT  ?modo=setor&id= {itens[]}                 salva o rascunho do setor
 *   POST ?modo=setor&acao=finalizar&id= {itens[]}  salva e envia (depois não muda mais)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/registro-atividade-service.php';
require_once __DIR__ . '/../services/prazo-atividade-service.php';
require_once __DIR__ . '/../services/relatorio-setor-service.php';

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

/** O período (data_referencia = início do calendário) pertence a alguma vigência? */
function periodoEmVigencia(array $vigencias, string $periodo, string $dataReferencia): bool {
    foreach ($vigencias as $v) {
        $fimCal = PrazoAtividadeService::fimDoPeriodo($v['periodicidade'], $dataReferencia);
        foreach (PrazoAtividadeService::periodosDaVigencia($v, $dataReferencia, $fimCal) as $p) {
            if ($p['periodo'] === $periodo && $p['data_referencia'] === $dataReferencia) return true;
        }
    }
    return false;
}

$usuarioId = (int) $user['id'];
$modoSetor = ($_GET['modo'] ?? '') === 'setor';
$setorId = isset($user['setor_id']) && $user['setor_id'] !== null ? (int) $user['setor_id'] : 0;
if ($modoSetor) {
    // Relatório do setor: só o coordenador (perfis de consulta, admin, gestor e operador recebem 403)
    if (($user['tipo'] ?? '') !== 'coordenador') {
        erroRegistro(403, 'O relatório do setor é enviado pelo coordenador do setor.');
    }
} elseif (!in_array($user['tipo'] ?? '', AcessoService::PAPEIS_REGISTRAM_ATIVIDADES, true) || $setorId <= 0) {
    erroRegistro(403, 'O Registro de Atividades é para a equipe vinculada a um setor (o administrador não registra).');
}

$db = (new Database())->getConnection();
$servico = new RegistroAtividadeService($db);
$prazo = new PrazoAtividadeService($db);

try {
    $corpo = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($metodo === 'GET' && $acao === 'itens') {
        $registro = $servico->buscar((int) ($_GET['id'] ?? 0));
        $permitido = $modoSetor
            ? ($registro !== null && $registro['periodo'] !== 'dia' && AcessoService::podeEnviarRelatorioSetor($user, (int) $registro['setor_id']))
            : RegistroAtividadeService::dono($registro, $usuarioId);
        if (!$permitido) erroRegistro(403, 'Relatório não encontrado');
        echo json_encode(['itens' => $servico->itens((int) $registro['id'], true)]);
        exit;
    }

    if ($metodo === 'GET' && $modoSetor) {
        $setorAlvo = (int) ($_GET['setor_id'] ?? 0);
        if ($setorAlvo <= 0 || !AcessoService::podeEnviarRelatorioSetor($user, $setorAlvo)) {
            erroRegistro(403, 'Você não envia o relatório deste setor.');
        }
        $hoje = date('Y-m-d');
        $inicioJanela = (new DateTimeImmutable('-180 days'))->format('Y-m-d');
        $vigencias = $prazo->vigenciasDoSetor($setorAlvo);
        $abertas = array_values(array_filter($prazo->pendenciasRelatorioSetor($setorAlvo, $inicioJanela, $hoje),
            fn($p) => $p['status'] !== 'abonado'));

        $data = RegistroAtividadeService::dataValida($_GET['data'] ?? null);
        if ($data !== null) {
            $alvo = PrazoAtividadeService::resolverPeriodoDoSetor($vigencias, $data);
        } else {
            // Sem data: a pendência mais antiga; sem pendência, o período corrente
            $alvo = PrazoAtividadeService::resolverPeriodoDoSetor($vigencias, $abertas ? $abertas[0]['periodo_fim'] : $hoje);
        }
        if ($alvo === null) erroRegistro(400, 'O relatório do setor não está em vigor nessa data.');

        $stmt = $db->prepare('SELECT id, nome FROM setores WHERE id = ?');
        $stmt->execute([$setorAlvo]);
        $setor = $stmt->fetch(PDO::FETCH_ASSOC);

        $registro = $servico->buscarOuCriarRascunhoSetor($setorAlvo, $alvo['periodo'], $alvo['data_referencia'], $usuarioId);
        $itens = array_column($servico->itens((int) $registro['id']), null, 'atividade_tipo_id');
        $atividades = array_map(function ($a) use ($itens) {
            $i = $itens[$a['id']] ?? null;
            return $a + ['quantidade' => $i ? $i['quantidade'] : 0, 'observacao' => $i ? $i['observacao'] : ''];
        }, $servico->atividadesDoSetor($setorAlvo, $usuarioId));

        echo json_encode([
            'modo'        => 'setor',
            'setor'       => ['id' => (int) $setor['id'], 'nome' => $setor['nome']],
            'periodo'     => $alvo['periodo'],
            'data'        => $alvo['data_referencia'],
            'periodo_fim' => $alvo['periodo_fim'],
            'hoje'        => $hoje,
            'registro'    => [
                'id'         => (int) $registro['id'],
                'status'     => $registro['status'],
                'enviado_em' => $registro['enviado_em'],
            ],
            'atividades'  => $atividades,
            'pendencias'  => $abertas,
            'historico'   => $servico->historicoSetor($setorAlvo, 10),
        ], JSON_UNESCAPED_UNICODE);
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

        $registro = $servico->buscarOuCriarRascunho($usuarioId, $setorId, $data);
        $itens = array_column($servico->itens((int) $registro['id']), null, 'atividade_tipo_id');
        $atividades = array_map(function ($a) use ($itens) {
            $i = $itens[$a['id']] ?? null;
            return $a + ['quantidade' => $i ? $i['quantidade'] : 0, 'observacao' => $i ? $i['observacao'] : ''];
        }, $servico->atividadesDoSetor($setorId, $usuarioId));

        // Setor com o relatório enviado pelo coordenador: o servidor está desobrigado nesta data
        $vigenciaDoDia = PrazoAtividadeService::vigenciaQueCobre($prazo->vigenciasDoSetor($setorId), $data);
        $desobrigado = null;
        if ($vigenciaDoDia) {
            $coordenador = (new RelatorioSetorService($db))->coordenadorDoSetor($setorId);
            $desobrigado = ['desde' => $vigenciaDoDia['inicio'], 'coordenador' => $coordenador['nome'] ?? null];
        }

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
            'desobrigado'         => $desobrigado,
            'exige_modalidade'    => $exigeModalidade,
            'modalidade_sugerida' => $exigeModalidade
                ? PrazoAtividadeService::modalidadeSugerida($registro['modalidade'], $prazo->configSetor($setorId), $prazo->configUsuario($usuarioId), $data)
                : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST' && $acao === 'favorito') {
        if ($modoSetor) {
            $setorId = (int) ($_GET['setor_id'] ?? 0);
            if ($setorId <= 0 || !AcessoService::podeEnviarRelatorioSetor($user, $setorId)) erroRegistro(403, 'Você não envia o relatório deste setor.');
        }
        $estado = $servico->alternarFavorito($usuarioId, $setorId, (int) ($corpo['atividade_tipo_id'] ?? 0));
        if ($estado === null) erroRegistro(404, 'Atividade não encontrada no seu setor');
        echo json_encode(['success' => true, 'favorito' => $estado]);
        exit;
    }

    if ($modoSetor && (($metodo === 'PUT') || ($metodo === 'POST' && $acao === 'finalizar'))) {
        $registroId = (int) ($_GET['id'] ?? 0);
        $registro = $servico->buscar($registroId);
        if ($registro === null || $registro['periodo'] === 'dia'
            || !AcessoService::podeEnviarRelatorioSetor($user, (int) $registro['setor_id'])) {
            erroRegistro(403, 'Relatório não encontrado');
        }
        if ($registro['status'] !== 'rascunho') erroRegistro(409, 'Este relatório já foi enviado e não pode mais ser alterado.');
        $setorDoRegistro = (int) $registro['setor_id'];
        if (!periodoEmVigencia($prazo->vigenciasDoSetor($setorDoRegistro), $registro['periodo'], $registro['data_referencia'])) {
            erroRegistro(400, 'O relatório do setor não está em vigor nesse período.');
        }

        $itens = RegistroAtividadeService::normalizarItens($corpo['itens'] ?? [], $servico->tiposAtivosDoSetor($setorDoRegistro));
        $finalizar = $metodo === 'POST';

        $db->beginTransaction();
        if (!$servico->salvarRascunhoSetor($registroId, $itens)) {
            $db->rollBack();
            erroRegistro(409, 'Este relatório já foi enviado e não pode mais ser alterado.');
        }
        if ($finalizar) {
            if (!$servico->finalizarSetor($registroId, $usuarioId)) {
                $db->rollBack();
                erroRegistro(409, 'Este relatório já foi enviado e não pode mais ser alterado.');
            }
            (new AuditoriaService($db))->registrar($usuarioId, 'enviar_relatorio_setor', 'registros_atividades', $registroId, null,
                json_encode(['setor_id' => $setorDoRegistro, 'periodo' => $registro['periodo'], 'data' => $registro['data_referencia'],
                             'atividades' => count(array_filter($itens, fn($i) => $i['quantidade'] > 0))], JSON_UNESCAPED_UNICODE));
        }
        $db->commit();

        $atualizado = $servico->buscar($registroId);
        echo json_encode(['success' => true, 'status' => $atualizado['status'], 'enviado_em' => $atualizado['enviado_em']]);
        exit;
    }

    if (($metodo === 'PUT') || ($metodo === 'POST' && $acao === 'finalizar')) {
        $registroId = (int) ($_GET['id'] ?? 0);
        $registro = $servico->buscar($registroId);
        if (!RegistroAtividadeService::dono($registro, $usuarioId)) erroRegistro(403, 'Relatório não encontrado');
        if ($registro['status'] !== 'rascunho') erroRegistro(409, 'Este relatório já foi enviado e não pode mais ser alterado.');

        // Servidor desobrigado: o relatório da data é do setor, enviado pelo coordenador
        $vigenciaDoDia = PrazoAtividadeService::vigenciaQueCobre($prazo->vigenciasDoSetor($setorId), $registro['data_referencia']);
        if ($vigenciaDoDia) {
            erroRegistro(409, 'O relatório deste setor é enviado pelo coordenador desde ' . (new DateTimeImmutable($vigenciaDoDia['inicio']))->format('d/m/Y') . '.');
        }

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
