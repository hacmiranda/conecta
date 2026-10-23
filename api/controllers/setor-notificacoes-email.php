<?php
/**
 * Ativar/desativar, por setor, cada e-mail que o sistema envia (NotificacaoEmailService). Ausência
 * de linha = ativado: um setor novo (ou um código novo) nasce com tudo ligado.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/notificacao-email-service.php';

AuthMiddleware::configurarCORS(['GET', 'PUT']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

/**
 * Admin gerencia qualquer setor; gestor só o próprio; operador nunca chega aqui
 * (verificarGestor já barra). Encerra com 403 quando não autorizado.
 */
function autorizarSetor(array $user, int $setor_id): void {
    AuthMiddleware::verificarGestor($user);
    if (!AuthMiddleware::mesmoSetor($user, $setor_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode configurar o próprio setor']);
        exit;
    }
}

$user = AuthMiddleware::verificarAuth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $setor_id = isset($_GET['setor_id']) ? (int) $_GET['setor_id'] : 0;
    if (!$setor_id) {
        http_response_code(400);
        echo json_encode(['error' => 'setor_id é obrigatório']);
        exit;
    }
    autorizarSetor($user, $setor_id);

    try {
        $stmt = $db->prepare("SELECT codigo, ativo FROM setor_notificacoes_email WHERE setor_id = ?");
        $stmt->execute([$setor_id]);
        $desligados = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'ativo', 'codigo');

        $grupos = [];
        foreach (NotificacaoEmailService::CATALOGO as $codigo => [$grupo, $nome, $quando]) {
            $grupos[$grupo]['grupo'] = $grupo;
            $grupos[$grupo]['nome_grupo'] = NotificacaoEmailService::nomeGrupo($grupo);
            $grupos[$grupo]['itens'][] = [
                'codigo' => $codigo,
                'nome' => $nome,
                'quando' => $quando,
                'ativo' => array_key_exists($codigo, $desligados) ? (bool) $desligados[$codigo] : true,
            ];
        }

        echo json_encode(['grupos' => array_values($grupos)]);
    } catch (Exception $e) {
        error_log('[SETOR-NOTIFICACOES-EMAIL GET] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar a configuração de e-mails do setor']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $setor_id = isset($input['setor_id']) ? (int) $input['setor_id'] : 0;
    $codigo = $input['codigo'] ?? null;
    $grupo = $input['grupo'] ?? null;

    if (!$setor_id || !isset($input['ativo']) || (!$codigo && !$grupo)) {
        http_response_code(400);
        echo json_encode(['error' => 'setor_id, ativo e (codigo ou grupo) são obrigatórios']);
        exit;
    }
    autorizarSetor($user, $setor_id);

    $ativo = $input['ativo'] ? 1 : 0;

    // Um código só: precisa existir no catálogo. Um grupo inteiro: aplica a todos os códigos dele.
    if ($codigo) {
        if (!isset(NotificacaoEmailService::CATALOGO[$codigo])) {
            http_response_code(400);
            echo json_encode(['error' => 'Código de e-mail desconhecido']);
            exit;
        }
        $codigos = [$codigo];
    } else {
        if (!in_array($grupo, [NotificacaoEmailService::GRUPO_SOLICITANTE, NotificacaoEmailService::GRUPO_INTERNO], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Grupo desconhecido']);
            exit;
        }
        $codigos = array_keys(array_filter(NotificacaoEmailService::CATALOGO, fn($c) => $c[0] === $grupo));
    }

    try {
        $db->beginTransaction();
        $upsert = $db->prepare(
            "INSERT INTO setor_notificacoes_email (setor_id, codigo, ativo, atualizado_por)
             VALUES (:setor_id, :codigo, :ativo, :usuario_id)
             ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), atualizado_por = VALUES(atualizado_por)"
        );
        foreach ($codigos as $c) {
            $anterior = NotificacaoEmailService::ativa($db, $setor_id, $c);
            $upsert->execute([':setor_id' => $setor_id, ':codigo' => $c, ':ativo' => $ativo, ':usuario_id' => $user['id']]);
            if ($anterior !== (bool) $ativo) {
                $auditoria = new AuditoriaService($db);
                $auditoria->registrar(
                    $user['id'], 'alterar_notificacao_email', 'setor_notificacoes_email', $setor_id,
                    json_encode(['codigo' => $c, 'ativo' => $anterior]),
                    json_encode(['codigo' => $c, 'ativo' => (bool) $ativo])
                );
            }
        }
        $db->commit();
        NotificacaoEmailService::limparCache();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[SETOR-NOTIFICACOES-EMAIL PUT] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao salvar a configuração de e-mails do setor']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
