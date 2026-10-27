<?php
/**
 * Filtros de e-mail por setor (tabela filtros_email): e-mail cujo remetente, destinatário, assunto ou
 * corpo contém o texto cadastrado não abre demanda. Admin cadastra em qualquer setor e também filtros
 * globais (setor_id NULL); gestor só no próprio setor e vê (sem alterar) os globais. Operador não acessa.
 *
 *   GET    ?setor_id=N|global           lista (setor + globais, ou só os globais)
 *   GET    ?acao=barrados&setor_id=N    últimos e-mails barrados pelos filtros do setor
 *   POST   {setor_id|"global", campo, termo, observacao}
 *   POST   ?acao=testar {setor_id, remetente, destinatario, assunto, corpo}   (não grava nada)
 *   PUT    ?id= {campo?, termo?, observacao?, ativo?}
 *   DELETE ?id=
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/filtro-email-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarGestor($user); // admin e gestor; operador recebe 403
$ehAdmin = ($user['tipo'] ?? '') === 'admin';
$metodo = $_SERVER['REQUEST_METHOD'];

if (in_array($metodo, ['POST', 'PUT', 'DELETE'], true)) {
    AuthMiddleware::verificarCSRF();
}

$db = (new Database())->getConnection();

function erro(int $codigo, string $mensagem): void {
    http_response_code($codigo);
    echo json_encode(['error' => $mensagem]);
    exit;
}

/** Interpreta setor_id: "global" => null (só admin); número => id do setor, conferindo o acesso. */
function resolverSetor($valor, array $user, bool $ehAdmin, bool $escrita): ?int {
    if ($valor === 'global') {
        if (!$ehAdmin) {
            erro(403, 'Apenas o administrador gerencia filtros de todos os setores');
        }
        return null;
    }
    $id = (int) $valor;
    if ($id <= 0) {
        erro(400, 'Informe o setor');
    }
    if (!AuthMiddleware::mesmoSetor($user, $id)) {
        erro(403, 'Você só pode configurar o próprio setor');
    }
    return $id;
}

/** Linha do filtro, conferindo que o usuário pode alterá-la. */
function filtroEditavel(PDO $db, int $id, array $user, bool $ehAdmin): array {
    $stmt = $db->prepare("SELECT * FROM filtros_email WHERE id = ?");
    $stmt->execute([$id]);
    $filtro = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$filtro) {
        erro(404, 'Filtro não encontrado');
    }
    if ($filtro['setor_id'] === null) {
        if (!$ehAdmin) {
            erro(403, 'Filtros de todos os setores só o administrador altera');
        }
    } elseif (!AuthMiddleware::mesmoSetor($user, (int) $filtro['setor_id'])) {
        erro(403, 'Você só pode configurar o próprio setor');
    }
    return $filtro;
}

/** Já existe filtro igual (mesmo setor, campo e texto normalizado)? Confere no código por causa do setor NULL. */
function existeIgual(PDO $db, ?int $setorId, string $campo, string $normalizado, int $exceto = 0): bool {
    $stmt = $db->prepare("SELECT 1 FROM filtros_email WHERE setor_id <=> ? AND campo = ? AND termo_normalizado = ? AND id <> ? LIMIT 1");
    $stmt->execute([$setorId, $campo, $normalizado, $exceto]);
    return (bool) $stmt->fetchColumn();
}

try {
    if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'barrados') {
        $setorId = resolverSetor($_GET['setor_id'] ?? '', $user, $ehAdmin, false);
        if ($setorId === null) {
            // Todos os setores: só o administrador
            $stmt = $db->query("SELECT ep.processado_em, ep.email_remetente, ep.assunto, ep.observacao, f.campo, f.termo, f.setor_id
                                FROM emails_processados ep JOIN filtros_email f ON f.id = ep.filtro_email_id
                                ORDER BY ep.id DESC LIMIT 20");
        } else {
            $stmt = $db->prepare("SELECT ep.processado_em, ep.email_remetente, ep.assunto, ep.observacao, f.campo, f.termo, f.setor_id
                                  FROM emails_processados ep
                                  JOIN filtros_email f ON f.id = ep.filtro_email_id
                                  JOIN setor_emails se ON se.id = ep.setor_email_id
                                  WHERE se.setor_id = ? ORDER BY ep.id DESC LIMIT 20");
            $stmt->execute([$setorId]);
        }
        echo json_encode(['barrados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($metodo === 'GET') {
        $setorId = resolverSetor($_GET['setor_id'] ?? '', $user, $ehAdmin, false);
        $colunas = "id, setor_id, campo, termo, observacao, ativo, ocorrencias, ultima_ocorrencia, criado_em";
        $resposta = ['campos' => FiltroEmailService::CAMPOS, 'pode_editar_globais' => $ehAdmin];
        if ($setorId !== null) {
            $stmt = $db->prepare("SELECT $colunas FROM filtros_email WHERE setor_id = ? ORDER BY campo, termo");
            $stmt->execute([$setorId]);
            $resposta['filtros'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $resposta['filtros'] = [];
        }
        // Globais: o admin edita na aba própria; o gestor só enxerga (valem para o setor dele também)
        $stmt = $db->query("SELECT $colunas FROM filtros_email WHERE setor_id IS NULL ORDER BY campo, termo");
        $globais = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($setorId === null) {
            $resposta['filtros'] = $globais;
        } else {
            $resposta['filtros_globais'] = $globais;
        }
        echo json_encode($resposta);
        exit;
    }

    $entrada = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($metodo === 'POST' && ($_GET['acao'] ?? '') === 'testar') {
        $setorId = resolverSetor($entrada['setor_id'] ?? '', $user, $ehAdmin, false);
        FiltroEmailService::limparCache();
        $barrou = FiltroEmailService::coincide(FiltroEmailService::ativosDaCaixa($db, $setorId), [
            'remetente'     => (string) ($entrada['remetente'] ?? ''),
            'destinatarios' => [(string) ($entrada['destinatario'] ?? '')],
            'assunto'       => (string) ($entrada['assunto'] ?? ''),
            'corpo'         => (string) ($entrada['corpo'] ?? ''),
        ]);
        echo json_encode([
            'barrado' => $barrou !== null,
            'filtro'  => $barrou ? ['id' => (int) $barrou['id'], 'descricao' => FiltroEmailService::descrever($barrou), 'global' => $barrou['setor_id'] === null] : null,
        ]);
        exit;
    }

    $auditoria = new AuditoriaService($db);

    if ($metodo === 'POST') {
        $setorId = resolverSetor($entrada['setor_id'] ?? '', $user, $ehAdmin, true);
        $campo = (string) ($entrada['campo'] ?? '');
        $valido = FiltroEmailService::validarTermo($campo, (string) ($entrada['termo'] ?? ''));
        if (isset($valido['erro'])) {
            erro(400, $valido['erro']);
        }
        if (existeIgual($db, $setorId, $campo, $valido['normalizado'])) {
            erro(409, 'Já existe um filtro igual neste setor');
        }
        $observacao = HtmlSanitizer::texto((string) ($entrada['observacao'] ?? ''), 255) ?: null;
        $db->prepare("INSERT INTO filtros_email (setor_id, campo, termo, termo_normalizado, observacao, criado_por) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$setorId, $campo, $valido['termo'], $valido['normalizado'], $observacao, $user['id']]);
        $id = (int) $db->lastInsertId();
        FiltroEmailService::limparCache();
        $auditoria->registrar($user['id'], 'criar_filtro_email', 'filtros_email', $id, null,
            json_encode(['setor_id' => $setorId, 'campo' => $campo, 'termo' => $valido['termo']]));
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        erro(400, 'ID do filtro é obrigatório');
    }
    $filtro = filtroEditavel($db, $id, $user, $ehAdmin);

    if ($metodo === 'PUT') {
        $campo = array_key_exists('campo', $entrada) ? (string) $entrada['campo'] : $filtro['campo'];
        $termo = array_key_exists('termo', $entrada) ? (string) $entrada['termo'] : $filtro['termo'];
        $valido = FiltroEmailService::validarTermo($campo, $termo);
        if (isset($valido['erro'])) {
            erro(400, $valido['erro']);
        }
        $setorId = $filtro['setor_id'] === null ? null : (int) $filtro['setor_id'];
        if (existeIgual($db, $setorId, $campo, $valido['normalizado'], $id)) {
            erro(409, 'Já existe um filtro igual neste setor');
        }
        $observacao = array_key_exists('observacao', $entrada)
            ? (HtmlSanitizer::texto((string) $entrada['observacao'], 255) ?: null)
            : $filtro['observacao'];
        $ativo = array_key_exists('ativo', $entrada) ? ($entrada['ativo'] ? 1 : 0) : (int) $filtro['ativo'];
        $db->prepare("UPDATE filtros_email SET campo = ?, termo = ?, termo_normalizado = ?, observacao = ?, ativo = ?, atualizado_por = ? WHERE id = ?")
           ->execute([$campo, $valido['termo'], $valido['normalizado'], $observacao, $ativo, $user['id'], $id]);
        FiltroEmailService::limparCache();
        $auditoria->registrar($user['id'], 'alterar_filtro_email', 'filtros_email', $id,
            json_encode(['campo' => $filtro['campo'], 'termo' => $filtro['termo'], 'ativo' => (int) $filtro['ativo']]),
            json_encode(['campo' => $campo, 'termo' => $valido['termo'], 'ativo' => $ativo]));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($metodo === 'DELETE') {
        $db->prepare("DELETE FROM filtros_email WHERE id = ?")->execute([$id]);
        FiltroEmailService::limparCache();
        $auditoria->registrar($user['id'], 'excluir_filtro_email', 'filtros_email', $id,
            json_encode(['setor_id' => $filtro['setor_id'], 'campo' => $filtro['campo'], 'termo' => $filtro['termo']]), null);
        echo json_encode(['success' => true]);
        exit;
    }

    erro(405, 'Método não permitido');
} catch (Exception $e) {
    error_log('[FILTROS-EMAIL] Erro: ' . $e->getMessage());
    erro(500, 'Erro ao processar os filtros de e-mail');
}
