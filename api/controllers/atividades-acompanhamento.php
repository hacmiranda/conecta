<?php
/**
 * Acompanhamento do Registro de Atividades (admin e gestor; veio das telas de admin do METRICA360, 27/10/2026).
 * Escopo: AcessoService::setorEscopo (admin escolhe ?setor_id, vazio = todos; gestor sempre o próprio setor).
 *
 *   GET    ?acao=painel                                             Painel de Atividades
 *   GET    ?acao=relatorio&usuario_id= (-1 = só relatórios do setor)&data_ini=&data_fim=&modalidade=&p=   Relatório (20 por página) + pendências
 *   GET    ?acao=detalhe&id=                                        itens de um relatório enviado
 *   GET    ?acao=historico&data_ini=&data_fim=                      Histórico de ausências
 *   GET    ?acao=cruzamento&usuario_id=&data_ini=&data_fim=         Cruzamento atividades × satisfação (módulo Satisfação)
 *   GET    ?acao=cruzamento-setor&setor_cruzamento=&data_ini=&data_fim=   Cruzamento por setor (soma do setor + uma linha por servidor)
 *   GET    ?acao=csv-relatorio | csv-historico | csv-cruzamento | csv-cruzamento-setor   mesmos filtros, arquivo CSV
 *   O Cruzamento (as duas formas) é só de administrador, coordenador geral, supervisor e coordenador (nos setores dele).
 *   POST   ?acao=abonar {usuario_id, data, tipo, justificativa}
 *   DELETE ?acao=abono&id=
 *   POST   ?acao=abonar-setor {setor_id, periodo (semana|mes), data, justificativa}   abono do relatório do setor (admin ou coordenador do setor)
 *   DELETE ?acao=abono-setor&id=
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';
require_once __DIR__ . '/../services/atividades-acompanhamento-service.php';
require_once __DIR__ . '/../services/pesquisa-painel-service.php';

$acao = $_GET['acao'] ?? '';
$ehCsv = str_starts_with($acao, 'csv-');
if (!$ehCsv) {
    header('Content-Type: application/json');
}

AuthMiddleware::configurarCORS(['GET', 'POST', 'DELETE']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarLeituraGestao($user); // gestão e visão total; operador recebe 403 (as escritas da visão total caem na trava central)
$metodo = $_SERVER['REQUEST_METHOD'];
if (in_array($metodo, ['POST', 'DELETE'], true)) {
    AuthMiddleware::verificarCSRF();
}

function erroAcomp(int $codigo, string $mensagem): void {
    if (!headers_sent()) header('Content-Type: application/json');
    http_response_code($codigo);
    echo json_encode(['error' => $mensagem]);
    exit;
}

function dataFiltro(string $chave): string {
    $v = trim((string) ($_GET[$chave] ?? ''));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

function brData(?string $iso): string {
    return $iso ? date('d/m/Y', strtotime($iso)) : '';
}

/** Texto do período de uma linha do relatório (dia, semana ou mês) para o CSV. */
function rotuloPeriodo(array $l): string {
    if (($l['periodo'] ?? 'dia') === 'dia') return brData($l['data_referencia']);
    return ($l['periodo'] === 'mes' ? 'Mês ' : 'Semana ') . brData($l['data_referencia']) . ' a ' . brData($l['periodo_fim'] ?? null);
}

/** O setor está dentro do escopo da pessoa (null = todos)? */
function setorNoEscopo(int|array|null $escopo, int $setorId): bool {
    return $escopo === null || in_array($setorId, $escopo, true);
}

/** Período com padrão e limite de 366 dias (cruzamento/histórico). */
function periodo(string $iniPadrao, string $fimPadrao): array {
    $ini = dataFiltro('data_ini') ?: $iniPadrao;
    $fim = dataFiltro('data_fim') ?: $fimPadrao;
    if ($ini > $fim) [$ini, $fim] = [$fim, $ini];
    if ((strtotime($fim) - strtotime($ini)) > 366 * 86400) erroAcomp(400, 'Escolha um período de no máximo um ano.');
    return [$ini, $fim];
}

$db = (new Database())->getConnection();
$servico = new AtividadesAcompanhamentoService($db);
$setorId = AcessoService::escopoSetores($user); // null = todos; lista = um ou mais setores
if ($setorId === []) erroAcomp(403, 'Você não está vinculado a um setor.');

/** Servidor do escopo (ou 403). */
function usuarioDoEscopo(AtividadesAcompanhamentoService $s, int|array|null $setorId, int $id): array {
    foreach ($s->usuariosDoEscopo($setorId) as $u) {
        if ((int) $u['id'] === $id) return $u;
    }
    erroAcomp(403, 'Servidor fora do seu setor.');
}

try {
    if ($metodo === 'GET' && $acao === 'painel') {
        echo json_encode($servico->painel($setorId), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'GET' && in_array($acao, ['relatorio', 'csv-relatorio'], true)) {
        $usuarioId = (int) ($_GET['usuario_id'] ?? 0) ?: null; // -1 = só os relatórios do setor
        if ($usuarioId === -1 || $usuarioId < -1) $usuarioId = $usuarioId === -1 ? -1 : null;
        if ($usuarioId > 0) usuarioDoEscopo($servico, $setorId, $usuarioId);
        $modalidade = (string) ($_GET['modalidade'] ?? '');
        $ini = dataFiltro('data_ini');
        $fim = dataFiltro('data_fim');
        if ($acao === 'csv-relatorio') {
            $r = $servico->relatorio($setorId, $usuarioId, $ini, $fim, $modalidade, 1, 100000);
            AtividadesAcompanhamentoService::enviarCsv('relatorio-atividades-' . date('Ymd-His') . '.csv',
                ['Tipo', 'Servidor', 'Setor', 'Data', 'Período', 'Modalidade', 'Atividades', 'Tarefas', 'Enviado em'],
                array_map(fn($l) => [$l['tipo'] === 'setor' ? 'Relatório do setor' : 'Servidor', $l['usuario_nome'], $l['setor_nome'] ?? '', brData($l['data_referencia']),
                    rotuloPeriodo($l), $l['modalidade'] === 'setor' ? 'Setor' : ($l['modalidade'] === 'presencial' ? 'Presencial' : 'Remoto'), $l['total_atividades'], $l['total_tarefas'],
                    $l['enviado_em'] ? date('d/m/Y H:i', strtotime($l['enviado_em'])) : ''], $r['itens']));
            exit;
        }
        $r = $servico->relatorio($setorId, $usuarioId, $ini, $fim, $modalidade, (int) ($_GET['p'] ?? 1));
        $r['pendencias'] = $servico->pendenciasDoEscopo($setorId);
        $r['usuarios'] = array_map(fn($u) => ['id' => (int) $u['id'], 'nome' => $u['nome'], 'setor_nome' => $u['setor_nome']],
            $servico->usuariosDoEscopo($setorId));
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'GET' && $acao === 'detalhe') {
        $d = $servico->detalhe((int) ($_GET['id'] ?? 0), $setorId);
        if (!$d) erroAcomp(404, 'Relatório não encontrado no seu setor.');
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'GET' && in_array($acao, ['historico', 'csv-historico'], true)) {
        [$ini, $fim] = periodo(date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month')));
        $lista = $servico->historicoAusencias($setorId, $ini, $fim);
        if ($acao === 'csv-historico') {
            $linhas = [];
            foreach ($lista as $u) {
                $tipoLinha = ($u['tipo'] ?? '') === 'setor' ? 'Relatório do setor' : 'Servidor';
                foreach ($u['semanas'] as $s) {
                    $linhas[] = [$tipoLinha, $u['nome'], $u['setor_nome'], $s['semana'], brData($s['de']) . ' a ' . brData($s['ate']), $s['esperado'], $s['enviado'], $s['faltaram'], $s['abonados']];
                }
                foreach ($u['dias'] as $d) {
                    $linhas[] = [$tipoLinha, $u['nome'], $u['setor_nome'], brData($d['data']), brData($d['data']), 1, $d['abonado'] ? 1 : 0, $d['abonado'] ? 0 : 1, $d['abonado'] ? 1 : 0];
                }
            }
            AtividadesAcompanhamentoService::enviarCsv("historico-ausencias_{$ini}_{$fim}.csv",
                ['Tipo', 'Servidor', 'Setor', 'Semana', 'Período', 'Esperado', 'Enviado', 'Faltaram', 'Abonados'], $linhas);
            exit;
        }
        echo json_encode(['data_ini' => $ini, 'data_fim' => $fim, 'ausencias' => $lista], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Cruzamento de Dados (17/11/2026): só administrador, coordenador geral, supervisor e coordenador (este nos setores dele,
    // pelo escopo); gestor não vê a página.
    if (str_contains($acao, 'cruzamento') && !AcessoService::ehVisaoTotal($user) && ($user['tipo'] ?? '') !== 'coordenador') {
        erroAcomp(403, 'O Cruzamento de Dados é só para coordenação e administração.');
    }

    if ($metodo === 'GET' && in_array($acao, ['cruzamento-setor', 'csv-cruzamento-setor'], true)) {
        // Setores que a pessoa pode ver (seguindo o seletor do cabeçalho); o setor escolhido vai em setor_cruzamento
        // (setor_id é o parâmetro do seletor, que urlComSetor anexa)
        // Setores de coordenação (só o coordenador, sem relatórios nem pesquisa; migração 42) ficam de fora
        $sql = "SELECT id, nome FROM setores WHERE ativo = 1 AND setor_coordenacao = 0" . ($setorId === null ? '' : ' AND ' . AcessoService::filtroSetorSql('id', $setorId)) . " ORDER BY nome";
        $setores = array_map(fn($s) => ['id' => (int) $s['id'], 'nome' => $s['nome']], $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        [$ini, $fim] = periodo(date('Y-m-01'), date('Y-m-t'));
        $pedido = (int) ($_GET['setor_cruzamento'] ?? 0);
        if (!$pedido && count($setores) === 1) $pedido = $setores[0]['id'];
        $resposta = ['data_ini' => $ini, 'data_fim' => $fim, 'setores' => $setores];
        if (!$pedido) {
            if ($ehCsv) erroAcomp(400, 'Escolha um setor.');
            echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $alvo = null;
        foreach ($setores as $s) if ($s['id'] === $pedido) $alvo = $s;
        if (!$alvo) erroAcomp(403, 'Você não tem acesso a este setor.');

        $atividades = $servico->atividadesDoSetor($pedido, $ini, $fim);
        $satisfacao = PesquisaPainelService::satisfacaoDoSetor($db, $pedido, $ini, $fim);
        // Uma linha por servidor: quem está lotado no setor e quem enviou convites por ele no período
        $servidores = [];
        foreach ($atividades['servidores'] as $s) $servidores[$s['id']] = $s + ['convites' => 0, 'avaliados' => 0, 'media_nota' => null];
        foreach ($satisfacao['por_servidor'] as $id => $s) {
            $servidores[$id] ??= ['id' => $id, 'nome' => $s['nome'], 'total_enviados' => null, 'total_esperado' => null,
                'total_tarefas' => null, 'pendencias_atrasadas' => null, 'pendencias_abonadas' => null];
            $servidores[$id] = array_merge($servidores[$id], ['convites' => $s['convites'], 'avaliados' => $s['avaliados'], 'media_nota' => $s['media_nota']]);
        }
        $servidores = array_values($servidores);
        usort($servidores, fn($a, $b) => strcoll($a['nome'], $b['nome']));
        unset($atividades['servidores'], $satisfacao['por_servidor']);

        if ($ehCsv) {
            $nota = fn($v) => $v !== null ? number_format((float) $v, 1, ',', '') : '';
            AtividadesAcompanhamentoService::enviarCsv('cruzamento-setor-' . $alvo['nome'] . "_{$ini}_{$fim}.csv",
                ['Servidor', 'Relatórios enviados', 'Relatórios esperados', 'Tarefas', 'Pendências atrasadas', 'Abonadas', 'Convites enviados', 'Avaliações', 'Nota média'],
                array_map(fn($s) => [$s['nome'], $s['total_enviados'] ?? '', $s['total_esperado'] ?? '', $s['total_tarefas'] ?? '',
                    $s['pendencias_atrasadas'] ?? '', $s['pendencias_abonadas'] ?? '', $s['convites'], $s['avaliados'], $nota($s['media_nota'])], $servidores));
            exit;
        }
        $resposta += ['setor' => $alvo, 'atividades' => $atividades, 'satisfacao' => $satisfacao, 'servidores' => $servidores,
            'aviso_satisfacao' => $satisfacao['total_convites'] === 0 ? 'O setor não enviou convites de avaliação neste período: não há notas a cruzar.' : null];
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'GET' && in_array($acao, ['cruzamento', 'csv-cruzamento'], true)) {
        $usuarios = $servico->usuariosDoEscopo($setorId);
        $usuarioId = (int) ($_GET['usuario_id'] ?? 0);
        [$ini, $fim] = periodo(date('Y-m-01'), date('Y-m-t'));
        $resposta = ['data_ini' => $ini, 'data_fim' => $fim,
            'usuarios' => array_map(fn($u) => ['id' => (int) $u['id'], 'nome' => $u['nome'], 'setor_nome' => $u['setor_nome']], $usuarios)];
        if (!$usuarioId) {
            if ($ehCsv) erroAcomp(400, 'Escolha um servidor.');
            echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $alvo = usuarioDoEscopo($servico, $setorId, $usuarioId);
        $atividades = $servico->atividadesDoUsuario($usuarioId, $ini, $fim);

        // Satisfação: as notas das avaliações recebidas pelos convites enviados por este servidor (módulo Satisfação)
        $satisfacao = PesquisaPainelService::satisfacaoDoServidor($db, $usuarioId, $ini, $fim);
        $avisoSatisfacao = $satisfacao['total_convites'] === 0 ? 'Este servidor não enviou convites de avaliação neste período: não há notas a cruzar.' : null;
        if ($ehCsv) {
            $satPorDia = [];
            foreach ($satisfacao['serie_diaria'] ?? [] as $d) $satPorDia[$d['data_dia']] = $d;
            $linhas = [];
            foreach ($atividades['serie_diaria'] as $d) {
                $s = $satPorDia[$d['data']] ?? ['qtd' => 0, 'avg_rating' => null];
                $linhas[] = [brData($d['data']), $s['avg_rating'] !== null ? number_format((float) $s['avg_rating'], 1, ',', '') : '',
                    $s['qtd'], $d['enviado'] ? 'Sim' : 'Não', $d['atividades'], $d['tarefas']];
            }
            AtividadesAcompanhamentoService::enviarCsv('cruzamento-dados-' . $alvo['nome'] . '-' . date('Ymd-His') . '.csv',
                ['Data', 'Nota do dia', 'Qtd. avaliações no dia', 'Relatório enviado', 'Atividades no dia', 'Tarefas no dia'], $linhas);
            exit;
        }
        $resposta += ['usuario' => ['id' => $usuarioId, 'nome' => $alvo['nome']], 'atividades' => $atividades,
                      'satisfacao' => $satisfacao, 'aviso_satisfacao' => $avisoSatisfacao];
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST' && $acao === 'abonar') {
        $corpo = json_decode(file_get_contents('php://input'), true) ?? [];
        $usuarioId = (int) ($corpo['usuario_id'] ?? 0);
        $data = (string) ($corpo['data'] ?? '');
        $tipo = ($corpo['tipo'] ?? '') === 'entrega' ? 'entrega' : 'remoto';
        $justificativa = HtmlSanitizer::texto((string) ($corpo['justificativa'] ?? ''), 1000);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$d || $d->format('Y-m-d') !== $data || mb_strlen($justificativa) < 5) {
            erroAcomp(400, 'Informe uma justificativa com pelo menos 5 caracteres.');
        }
        $alvo = usuarioDoEscopo($servico, $setorId, $usuarioId);
        if (!(new PrazoAtividadeService($db))->abonar($usuarioId, $data, $justificativa, (int) $user['id'], $tipo)) {
            erroAcomp(409, 'Esta pendência já foi resolvida (relatório enviado ou cota atingida): não há o que abonar.');
        }
        (new AuditoriaService($db))->registrar($user['id'], 'abonar_atividade', 'atividades_abonos', null, null,
            json_encode(['usuario_id' => $usuarioId, 'data' => $data, 'tipo' => $tipo, 'justificativa' => $justificativa], JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($metodo === 'POST' && $acao === 'abonar-setor') {
        $corpo = json_decode(file_get_contents('php://input'), true) ?? [];
        $sid = (int) ($corpo['setor_id'] ?? 0);
        $periodo = (string) ($corpo['periodo'] ?? '');
        $data = (string) ($corpo['data'] ?? '');
        $justificativa = HtmlSanitizer::texto((string) ($corpo['justificativa'] ?? ''), 1000);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$sid || !in_array($periodo, ['semana', 'mes'], true) || !$d || $d->format('Y-m-d') !== $data || mb_strlen($justificativa) < 5) {
            erroAcomp(400, 'Informe o setor, o período e uma justificativa com pelo menos 5 caracteres.');
        }
        if (!setorNoEscopo($setorId, $sid) || !AcessoService::podeAbonarRelatorioSetor($user, $sid)) {
            erroAcomp(403, 'Só o administrador ou o coordenador do setor abona o relatório do setor.');
        }
        $prazo = new PrazoAtividadeService($db);
        if ($prazo->relatorioSetorEnviado($sid, $periodo, $data)) {
            erroAcomp(409, 'Este relatório do setor já foi enviado: não há o que abonar.');
        }
        if (!$prazo->abonarRelatorioSetor($sid, $periodo, $data, $justificativa, (int) $user['id'])) {
            erroAcomp(409, 'Esta data não é o início de um período cobrado do relatório do setor: não há o que abonar.');
        }
        (new AuditoriaService($db))->registrar($user['id'], 'abonar_relatorio_setor', 'atividades_abonos_setor', null, null,
            json_encode(['setor_id' => $sid, 'periodo' => $periodo, 'data' => $data, 'justificativa' => $justificativa], JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($metodo === 'DELETE' && $acao === 'abono-setor') {
        $id = (int) ($_GET['id'] ?? 0);
        $prazo = new PrazoAtividadeService($db);
        $abono = $prazo->buscarAbonoSetor($id);
        if (!$abono) erroAcomp(404, 'Abono não encontrado.');
        $sid = (int) $abono['setor_id'];
        if (!setorNoEscopo($setorId, $sid) || !AcessoService::podeAbonarRelatorioSetor($user, $sid)) {
            erroAcomp(403, 'Só o administrador ou o coordenador do setor remove o abono do relatório do setor.');
        }
        $prazo->removerAbonoSetor($id);
        (new AuditoriaService($db))->registrar($user['id'], 'remover_abono_relatorio_setor', 'atividades_abonos_setor', $id,
            json_encode($abono, JSON_UNESCAPED_UNICODE), null);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($metodo === 'DELETE' && $acao === 'abono') {
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM atividades_abonos WHERE id = ?");
        $stmt->execute([$id]);
        $abono = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$abono) erroAcomp(404, 'Abono não encontrado.');
        usuarioDoEscopo($servico, $setorId, (int) $abono['usuario_id']);
        $db->prepare("DELETE FROM atividades_abonos WHERE id = ?")->execute([$id]);
        (new AuditoriaService($db))->registrar($user['id'], 'remover_abono_atividade', 'atividades_abonos', $id,
            json_encode($abono, JSON_UNESCAPED_UNICODE), null);
        echo json_encode(['success' => true]);
        exit;
    }

    erroAcomp(405, 'Ação não permitida');
} catch (Exception $e) {
    error_log('[ATIVIDADES-ACOMPANHAMENTO] Erro: ' . $e->getMessage());
    erroAcomp(500, 'Erro ao carregar o acompanhamento de atividades');
}
