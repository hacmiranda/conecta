<?php
/**
 * Acompanhamento do Registro de Atividades (admin e gestor; veio das telas de admin do METRICA360, 27/10/2026).
 * Escopo: AcessoService::setorEscopo (admin escolhe ?setor_id, vazio = todos; gestor sempre o próprio setor).
 *
 *   GET    ?acao=painel                                             Painel de Atividades
 *   GET    ?acao=relatorio&usuario_id=&data_ini=&data_fim=&modalidade=&p=   Relatório (20 por página) + pendências
 *   GET    ?acao=detalhe&id=                                        itens de um relatório enviado
 *   GET    ?acao=historico&data_ini=&data_fim=                      Histórico de ausências
 *   GET    ?acao=cruzamento&usuario_id=&data_ini=&data_fim=         Cruzamento atividades × satisfação (METRICA360)
 *   GET    ?acao=csv-relatorio | csv-historico | csv-cruzamento     mesmos filtros, arquivo CSV
 *   POST   ?acao=abonar {usuario_id, data, tipo, justificativa}
 *   DELETE ?acao=abono&id=
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';
require_once __DIR__ . '/../services/atividades-acompanhamento-service.php';
require_once __DIR__ . '/../services/metrica360-client.php';

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
AuthMiddleware::verificarGestor($user); // admin e gestor; operador recebe 403
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
$setorId = AcessoService::setorEscopo($user);
if (($user['tipo'] ?? '') !== 'admin' && !$setorId) erroAcomp(403, 'Você não está vinculado a um setor.');

/** Servidor do escopo (ou 403). */
function usuarioDoEscopo(AtividadesAcompanhamentoService $s, ?int $setorId, int $id): array {
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
        $usuarioId = (int) ($_GET['usuario_id'] ?? 0) ?: null;
        if ($usuarioId) usuarioDoEscopo($servico, $setorId, $usuarioId);
        $modalidade = (string) ($_GET['modalidade'] ?? '');
        $ini = dataFiltro('data_ini');
        $fim = dataFiltro('data_fim');
        if ($acao === 'csv-relatorio') {
            $r = $servico->relatorio($setorId, $usuarioId, $ini, $fim, $modalidade, 1, 100000);
            AtividadesAcompanhamentoService::enviarCsv('relatorio-atividades-' . date('Ymd-His') . '.csv',
                ['Servidor', 'Setor', 'Data', 'Modalidade', 'Atividades', 'Tarefas', 'Enviado em'],
                array_map(fn($l) => [$l['usuario_nome'], $l['setor_nome'] ?? '', brData($l['data_referencia']),
                    $l['modalidade'] === 'presencial' ? 'Presencial' : 'Remoto', $l['total_atividades'], $l['total_tarefas'],
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
                foreach ($u['semanas'] as $s) {
                    $linhas[] = [$u['nome'], $u['setor_nome'], $s['semana'], brData($s['de']) . ' a ' . brData($s['ate']), $s['esperado'], $s['enviado'], $s['faltaram'], $s['abonados']];
                }
                foreach ($u['dias'] as $d) {
                    $linhas[] = [$u['nome'], $u['setor_nome'], brData($d['data']), brData($d['data']), 1, $d['abonado'] ? 1 : 0, $d['abonado'] ? 0 : 1, $d['abonado'] ? 1 : 0];
                }
            }
            AtividadesAcompanhamentoService::enviarCsv("historico-ausencias_{$ini}_{$fim}.csv",
                ['Servidor', 'Setor', 'Semana', 'Período', 'Esperado', 'Enviado', 'Faltaram', 'Abonados'], $linhas);
            exit;
        }
        echo json_encode(['data_ini' => $ini, 'data_fim' => $fim, 'ausencias' => $lista], JSON_UNESCAPED_UNICODE);
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

        // Satisfação: no METRICA360, pelo e-mail do servidor
        $satisfacao = null;
        $avisoSatisfacao = null;
        $r = (new Metrica360Client())->get('/api/integracao/satisfacao', ['email' => $alvo['email'], 'inicio' => $ini, 'fim' => $fim]);
        $status = $r['dados']['status'] ?? '';
        if ($r['http'] === 200 && $status === 'ok') {
            $satisfacao = $r['dados'];
            unset($satisfacao['status']);
            foreach ($satisfacao['comentarios'] as &$c) {
                $c['comment'] = trim((string) preg_replace('/\s*\[Categorias:[^\]]*\]/i', '', (string) $c['comment']));
            }
            unset($c);
        } elseif ($status === 'sem_funcionario') {
            $avisoSatisfacao = 'Este servidor não está cadastrado no METRICA360 com o mesmo e-mail: não há notas a cruzar.';
        } else {
            $avisoSatisfacao = 'O METRICA360 não respondeu agora: a parte de satisfação não pôde ser carregada.';
        }

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
