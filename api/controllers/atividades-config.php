<?php
/**
 * Configurações → Atividades (vindo do METRICA360, 27/10/2026): o que cada setor registra no Registro de
 * Atividades e as regras de prazo. Admin configura qualquer setor; gestor só o próprio. Operador não acessa.
 *
 *   GET    ?setor_id=N                         tipos, regras do setor e regras de cada servidor do setor
 *   POST   ?acao=tipos {setor_id, nomes}       cria atividades (uma por linha; repetidas são ignoradas)
 *   PUT    ?acao=tipo&id= {nome?, ativo?, ordem?}
 *   DELETE ?acao=tipo&id=                      só atividade que nunca foi registrada (senão: desative)
 *   PUT    ?acao=setor {setor_id, modo, prazo, entrega_ativa, entrega_dias[], entrega_inicio, data_base}
 *   PUT    ?acao=usuario&id= {trabalho_remoto, qtd_semana, dias_remotos[], entrega_modo, entrega_dias[]}
 *          entrega_modo: seguir (segue o setor) | dispensado | personalizado (dias próprios)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';
require_once __DIR__ . '/../services/prazo-atividade-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarGestor($user); // admin e gestor; operador recebe 403
$metodo = $_SERVER['REQUEST_METHOD'];
$acao = $_GET['acao'] ?? '';

if (in_array($metodo, ['POST', 'PUT', 'DELETE'], true)) {
    AuthMiddleware::verificarCSRF();
}

$db = (new Database())->getConnection();
$auditoria = new AuditoriaService($db);

function erroAtividades(int $codigo, string $mensagem): void {
    http_response_code($codigo);
    echo json_encode(['error' => $mensagem]);
    exit;
}

/** Setor existente e do alcance do usuário (admin: qualquer; gestor: o próprio). */
function setorPermitido(PDO $db, array $user, $valor): int {
    $id = (int) $valor;
    if ($id <= 0) erroAtividades(400, 'Informe o setor');
    if (!AuthMiddleware::mesmoSetor($user, $id)) erroAtividades(403, 'Você só pode configurar o próprio setor');
    $stmt = $db->prepare("SELECT 1 FROM setores WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) erroAtividades(404, 'Setor não encontrado');
    return $id;
}

function dataOuNull($valor): ?string {
    $valor = trim((string) ($valor ?? ''));
    if ($valor === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    if (!$d || $d->format('Y-m-d') !== $valor) erroAtividades(400, 'Data inválida');
    return $valor;
}

try {
    $corpo = json_decode(file_get_contents('php://input'), true) ?? [];

    // ── Leitura ───────────────────────────────────────────────────────────────
    if ($metodo === 'GET') {
        $setorId = setorPermitido($db, $user, $_GET['setor_id'] ?? 0);
        $prazo = new PrazoAtividadeService($db);

        $stmt = $db->prepare("
            SELECT t.id, t.nome, t.ativo, t.ordem,
                   EXISTS (SELECT 1 FROM registros_atividades_itens i WHERE i.atividade_tipo_id = t.id) AS em_uso
            FROM atividades_tipos t WHERE t.setor_id = ? ORDER BY t.ordem, t.nome");
        $stmt->execute([$setorId]);
        $tipos = array_map(fn($t) => ['id' => (int) $t['id'], 'nome' => $t['nome'], 'ativo' => (int) $t['ativo'] === 1,
            'ordem' => (int) $t['ordem'], 'em_uso' => (int) $t['em_uso'] === 1], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $db->prepare("SELECT id, nome, tipo FROM usuarios WHERE setor_id = ? AND ativo = 1 AND tipo IN ('gestor', 'operador') ORDER BY nome");
        $stmt->execute([$setorId]);
        $usuarios = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $c = $prazo->configUsuario((int) $u['id']);
            $usuarios[] = [
                'id' => (int) $u['id'], 'nome' => $u['nome'], 'tipo' => $u['tipo'],
                'trabalho_remoto' => $c['trabalho_remoto'], 'qtd_semana' => $c['qtd_semana'], 'dias_remotos' => $c['dias_remotos'],
                'entrega_modo' => $c['entrega_dias'] === null ? 'seguir' : ($c['entrega_dias'] === '' ? 'dispensado' : 'personalizado'),
                'entrega_dias' => PrazoAtividadeService::normalizarDias($c['entrega_dias']),
                'entrega_inicio' => $c['entrega_inicio'],
            ];
        }

        $setor = $prazo->configSetor($setorId);
        $setor['entrega_dias'] = PrazoAtividadeService::normalizarDias($setor['entrega_dias']);
        echo json_encode(['setor_id' => $setorId, 'setor' => $setor, 'tipos' => $tipos, 'usuarios' => $usuarios]);
        exit;
    }

    // ── Atividades (tipos) ───────────────────────────────────────────────────
    if ($metodo === 'POST' && $acao === 'tipos') {
        $setorId = setorPermitido($db, $user, $corpo['setor_id'] ?? 0);
        $linhas = preg_split('/\r\n|\r|\n/', (string) ($corpo['nomes'] ?? ''));
        $stmtOrdem = $db->prepare("SELECT COALESCE(MAX(ordem), 0) FROM atividades_tipos WHERE setor_id = ?");
        $stmtOrdem->execute([$setorId]);
        $proximaOrdem = (int) $stmtOrdem->fetchColumn();
        $existe = $db->prepare("SELECT 1 FROM atividades_tipos WHERE setor_id = ? AND nome = ?");
        $ins = $db->prepare("INSERT INTO atividades_tipos (setor_id, nome, ordem) VALUES (?, ?, ?)");
        $criadas = 0;
        $ignoradas = 0;
        $vistos = [];
        foreach ($linhas as $linha) {
            $nome = HtmlSanitizer::texto($linha, 150);
            if ($nome === '') continue;
            $chave = mb_strtolower($nome);
            $existe->execute([$setorId, $nome]);
            if (isset($vistos[$chave]) || $existe->fetchColumn()) { $ignoradas++; continue; }
            $vistos[$chave] = true;
            $ins->execute([$setorId, $nome, ++$proximaOrdem]);
            $auditoria->registrar($user['id'], 'criar_atividade_tipo', 'atividades_tipos', (int) $db->lastInsertId(), null,
                json_encode(['setor_id' => $setorId, 'nome' => $nome], JSON_UNESCAPED_UNICODE));
            $criadas++;
        }
        if ($criadas === 0 && $ignoradas === 0) erroAtividades(400, 'Informe ao menos um nome de atividade');
        http_response_code(201);
        echo json_encode(['success' => true, 'criadas' => $criadas, 'ignoradas' => $ignoradas]);
        exit;
    }

    if (in_array($metodo, ['PUT', 'DELETE'], true) && $acao === 'tipo') {
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM atividades_tipos WHERE id = ?");
        $stmt->execute([$id]);
        $tipo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tipo) erroAtividades(404, 'Atividade não encontrada');
        setorPermitido($db, $user, $tipo['setor_id']);

        if ($metodo === 'DELETE') {
            $uso = $db->prepare("SELECT 1 FROM registros_atividades_itens WHERE atividade_tipo_id = ? LIMIT 1");
            $uso->execute([$id]);
            if ($uso->fetchColumn()) erroAtividades(409, 'Esta atividade já foi registrada por alguém e não pode ser excluída. Desative-a.');
            $db->prepare("DELETE FROM atividades_tipos WHERE id = ?")->execute([$id]);
            $auditoria->registrar($user['id'], 'excluir_atividade_tipo', 'atividades_tipos', $id, json_encode($tipo, JSON_UNESCAPED_UNICODE), null);
            echo json_encode(['success' => true]);
            exit;
        }

        $novo = $tipo;
        if (array_key_exists('nome', $corpo)) {
            $nome = HtmlSanitizer::texto((string) $corpo['nome'], 150);
            if ($nome === '') erroAtividades(400, 'Informe o nome da atividade');
            $dup = $db->prepare("SELECT 1 FROM atividades_tipos WHERE setor_id = ? AND nome = ? AND id <> ?");
            $dup->execute([$tipo['setor_id'], $nome, $id]);
            if ($dup->fetchColumn()) erroAtividades(409, 'Já existe uma atividade com esse nome no setor');
            $novo['nome'] = $nome;
        }
        if (array_key_exists('ativo', $corpo)) $novo['ativo'] = !empty($corpo['ativo']) ? 1 : 0;
        if (array_key_exists('ordem', $corpo)) $novo['ordem'] = max(0, min(32000, (int) $corpo['ordem']));
        $db->prepare("UPDATE atividades_tipos SET nome = ?, ativo = ?, ordem = ? WHERE id = ?")
           ->execute([$novo['nome'], $novo['ativo'], $novo['ordem'], $id]);
        $auditoria->registrar($user['id'], 'alterar_atividade_tipo', 'atividades_tipos', $id,
            json_encode($tipo, JSON_UNESCAPED_UNICODE), json_encode($novo, JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Regras do setor ──────────────────────────────────────────────────────
    if ($metodo === 'PUT' && $acao === 'setor') {
        $setorId = setorPermitido($db, $user, $corpo['setor_id'] ?? 0);
        $antes = (new PrazoAtividadeService($db))->configSetor($setorId);
        $modo = ($corpo['modo'] ?? '') === 'diario' ? 'diario' : 'semanal';
        $prazo = max(0, min(15, (int) ($corpo['prazo'] ?? 0)));
        $entregaAtiva = !empty($corpo['entrega_ativa']) ? 1 : 0;
        $dias = PrazoAtividadeService::diasParaCsv((array) ($corpo['entrega_dias'] ?? []));
        if ($entregaAtiva && $dias === '') erroAtividades(400, 'Selecione ao menos um dia de entrega');
        if ($dias === '') $dias = PrazoAtividadeService::DIAS_PADRAO;
        $inicio = dataOuNull($corpo['entrega_inicio'] ?? null);
        $base = dataOuNull($corpo['data_base'] ?? null);

        $db->prepare("
            INSERT INTO atividades_config_setor (setor_id, modo_controle_remoto, prazo_dias_atraso, entrega_ativa, entrega_dias, entrega_inicio, data_base, atualizado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE modo_controle_remoto = VALUES(modo_controle_remoto), prazo_dias_atraso = VALUES(prazo_dias_atraso),
                entrega_ativa = VALUES(entrega_ativa), entrega_dias = VALUES(entrega_dias), entrega_inicio = VALUES(entrega_inicio),
                data_base = VALUES(data_base), atualizado_por = VALUES(atualizado_por)")
           ->execute([$setorId, $modo, $prazo, $entregaAtiva, $dias, $inicio, $base, $user['id']]);
        $depois = ['modo' => $modo, 'prazo' => $prazo, 'entrega_ativa' => (bool) $entregaAtiva, 'entrega_dias' => $dias,
                   'entrega_inicio' => $inicio, 'data_base' => $base];
        $auditoria->registrar($user['id'], 'alterar_regras_atividades_setor', 'atividades_config_setor', $setorId,
            json_encode($antes, JSON_UNESCAPED_UNICODE), json_encode($depois, JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Regras de um servidor ────────────────────────────────────────────────
    if ($metodo === 'PUT' && $acao === 'usuario') {
        $usuarioId = (int) ($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, nome, setor_id, tipo FROM usuarios WHERE id = ? AND tipo IN ('gestor', 'operador')");
        $stmt->execute([$usuarioId]);
        $alvo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$alvo || !$alvo['setor_id']) erroAtividades(404, 'Servidor não encontrado');
        setorPermitido($db, $user, $alvo['setor_id']);

        $prazo = new PrazoAtividadeService($db);
        $antes = $prazo->configUsuario($usuarioId);

        $remoto = !empty($corpo['trabalho_remoto']) ? 1 : 0;
        $qtd = ($corpo['qtd_semana'] ?? '') === '' || $corpo['qtd_semana'] === null ? null : max(0, min(7, (int) $corpo['qtd_semana']));
        $diasRemotos = PrazoAtividadeService::normalizarDias((array) ($corpo['dias_remotos'] ?? []));

        $modoEntrega = $corpo['entrega_modo'] ?? 'seguir';
        if ($modoEntrega === 'dispensado') {
            $entregaDias = '';
            $entregaInicio = null;
        } elseif ($modoEntrega === 'personalizado') {
            $entregaDias = PrazoAtividadeService::diasParaCsv((array) ($corpo['entrega_dias'] ?? []));
            if ($entregaDias === '') erroAtividades(400, 'Selecione ao menos um dia de entrega');
            // Mantém a data de início enquanto já era personalizado; senão cobra a partir de hoje (regra do METRICA360)
            $jaPersonalizado = $antes['entrega_dias'] !== null && $antes['entrega_dias'] !== '';
            $entregaInicio = $jaPersonalizado && $antes['entrega_inicio'] ? $antes['entrega_inicio'] : date('Y-m-d');
        } else {
            $entregaDias = null;
            $entregaInicio = null;
        }

        $db->prepare("
            INSERT INTO atividades_config_usuario (usuario_id, trabalho_remoto, qtd_dias_remoto_semana, entrega_dias, entrega_inicio, atualizado_por)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE trabalho_remoto = VALUES(trabalho_remoto), qtd_dias_remoto_semana = VALUES(qtd_dias_remoto_semana),
                entrega_dias = VALUES(entrega_dias), entrega_inicio = VALUES(entrega_inicio), atualizado_por = VALUES(atualizado_por)")
           ->execute([$usuarioId, $remoto, $qtd, $entregaDias, $entregaInicio, $user['id']]);
        $db->prepare("DELETE FROM atividades_dias_remotos WHERE usuario_id = ?")->execute([$usuarioId]);
        $ins = $db->prepare("INSERT INTO atividades_dias_remotos (usuario_id, dia_semana) VALUES (?, ?)");
        foreach ($diasRemotos as $d) $ins->execute([$usuarioId, $d]);

        $auditoria->registrar($user['id'], 'alterar_regras_atividades_usuario', 'atividades_config_usuario', $usuarioId,
            json_encode($antes, JSON_UNESCAPED_UNICODE), json_encode($prazo->configUsuario($usuarioId), JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true]);
        exit;
    }

    erroAtividades(405, 'Método não permitido');
} catch (Exception $e) {
    error_log('[ATIVIDADES-CONFIG] Erro: ' . $e->getMessage());
    erroAtividades(500, 'Erro ao processar a configuração de atividades');
}
