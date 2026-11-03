<?php
/**
 * Módulo Satisfação — Configurações → Pesquisa de satisfação (só administrador): regras de convite e do alerta,
 * categorias de avaliação (as etiquetas que o cidadão marca) e frases do dia.
 *
 *   GET                                       {regras, categorias, frases}
 *   PUT    ?acao=regras {chaves...}           salva as regras (valida cada uma)
 *   POST   ?acao=categoria {nome, emoji}      PUT ?acao=categoria&id= {nome?, emoji?, ativo?}   DELETE ?acao=categoria&id=
 *   PUT    ?acao=categorias-ordem {ids:[...]} ordem na tela
 *   POST   ?acao=frase {texto, autor}         PUT ?acao=frase&id= {texto?, autor?, ativo?}      DELETE ?acao=frase&id=
 *   PUT    ?acao=frases-ordem {ids:[...]}
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';
require_once __DIR__ . '/../services/pesquisa-config-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user);
$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo !== 'GET') {
    AuthMiddleware::verificarCSRF();
}
$acao = $_GET['acao'] ?? '';
$id = (int) ($_GET['id'] ?? 0);

function erroConfigPesquisa(int $codigo, string $mensagem): void {
    http_response_code($codigo);
    echo json_encode(['error' => $mensagem], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Grava a ordem de uma tabela a partir da lista de ids na ordem desejada (só ids inteiros). */
function reordenarPesquisa(PDO $db, string $tabela, array $ids): void {
    $stmt = $db->prepare("UPDATE $tabela SET ordem = ? WHERE id = ?"); // $tabela é fixo no código
    foreach (array_values($ids) as $posicao => $i) {
        $stmt->execute([$posicao + 1, (int) $i]);
    }
}

try {
    $db = (new Database())->getConnection();
    $auditoria = new AuditoriaService($db);
    $corpo = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($metodo === 'GET') {
        echo json_encode([
            'regras'     => PesquisaConfig::todas($db),
            'categorias' => $db->query("SELECT c.id, c.nome, c.emoji, c.ativo, c.ordem,
                                (SELECT COUNT(*) FROM pesquisa_avaliacao_categorias ac WHERE ac.categoria_id = c.id) AS em_uso
                                FROM pesquisa_categorias c ORDER BY c.ordem, c.id")->fetchAll(PDO::FETCH_ASSOC),
            'frases'     => $db->query("SELECT id, texto, autor, ativo, ordem FROM pesquisa_frases ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Regras ───────────────────────────────────────────────────────────────
    if ($metodo === 'PUT' && $acao === 'regras') {
        [$limpos, $erros] = PesquisaConfig::validar($corpo);
        if ($erros) erroConfigPesquisa(400, implode(' ', $erros));
        $antes = PesquisaConfig::todas($db);
        PesquisaConfig::salvar($db, $limpos);
        $auditoria->registrar($user['id'], 'alterar_regras_pesquisa', 'pesquisa_config', null, json_encode($antes, JSON_UNESCAPED_UNICODE), json_encode($limpos, JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'regras' => PesquisaConfig::todas($db)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Categorias ───────────────────────────────────────────────────────────
    if ($acao === 'categoria') {
        if ($metodo === 'POST' || $metodo === 'PUT') {
            $nome = HtmlSanitizer::texto((string) ($corpo['nome'] ?? ''), 80);
            $emoji = mb_substr(trim((string) ($corpo['emoji'] ?? '')), 0, 10);
            if ($metodo === 'POST') {
                if ($nome === '') erroConfigPesquisa(400, 'Informe o nome da categoria.');
                $dup = $db->prepare("SELECT 1 FROM pesquisa_categorias WHERE nome = ?");
                $dup->execute([$nome]);
                if ($dup->fetchColumn()) erroConfigPesquisa(409, 'Já existe uma categoria com esse nome.');
                $ordem = (int) $db->query("SELECT COALESCE(MAX(ordem), 0) + 1 FROM pesquisa_categorias")->fetchColumn();
                $db->prepare("INSERT INTO pesquisa_categorias (nome, emoji, ordem) VALUES (?, ?, ?)")->execute([$nome, $emoji, $ordem]);
                $novo = (int) $db->lastInsertId();
                $auditoria->registrar($user['id'], 'criar_categoria_pesquisa', 'pesquisa_categorias', $novo, null, json_encode(['nome' => $nome]));
                http_response_code(201);
                echo json_encode(['success' => true, 'id' => $novo]);
                exit;
            }
            $atual = $db->prepare("SELECT * FROM pesquisa_categorias WHERE id = ?");
            $atual->execute([$id]);
            $cat = $atual->fetch(PDO::FETCH_ASSOC);
            if (!$cat) erroConfigPesquisa(404, 'Categoria não encontrada.');
            $novoNome = array_key_exists('nome', $corpo) ? $nome : $cat['nome'];
            if ($novoNome === '') erroConfigPesquisa(400, 'Informe o nome da categoria.');
            $dup = $db->prepare("SELECT 1 FROM pesquisa_categorias WHERE nome = ? AND id <> ?");
            $dup->execute([$novoNome, $id]);
            if ($dup->fetchColumn()) erroConfigPesquisa(409, 'Já existe uma categoria com esse nome.');
            $db->prepare("UPDATE pesquisa_categorias SET nome = ?, emoji = ?, ativo = ? WHERE id = ?")->execute([
                $novoNome, array_key_exists('emoji', $corpo) ? $emoji : $cat['emoji'],
                array_key_exists('ativo', $corpo) ? (!empty($corpo['ativo']) ? 1 : 0) : (int) $cat['ativo'], $id]);
            $auditoria->registrar($user['id'], 'alterar_categoria_pesquisa', 'pesquisa_categorias', $id, json_encode($cat, JSON_UNESCAPED_UNICODE), json_encode($corpo, JSON_UNESCAPED_UNICODE));
            echo json_encode(['success' => true]);
            exit;
        }
        if ($metodo === 'DELETE') {
            $uso = $db->prepare("SELECT 1 FROM pesquisa_avaliacao_categorias WHERE categoria_id = ? LIMIT 1");
            $uso->execute([$id]);
            if ($uso->fetchColumn()) erroConfigPesquisa(409, 'Esta categoria já foi marcada em avaliações: desative-a em vez de excluir.');
            $db->prepare("DELETE FROM pesquisa_categorias WHERE id = ?")->execute([$id]);
            $auditoria->registrar($user['id'], 'excluir_categoria_pesquisa', 'pesquisa_categorias', $id, null, null);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    if ($metodo === 'PUT' && $acao === 'categorias-ordem') {
        reordenarPesquisa($db, 'pesquisa_categorias', (array) ($corpo['ids'] ?? []));
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Frases do dia ────────────────────────────────────────────────────────
    if ($acao === 'frase') {
        if ($metodo === 'POST' || $metodo === 'PUT') {
            $texto = HtmlSanitizer::texto((string) ($corpo['texto'] ?? ''), 500);
            $autor = HtmlSanitizer::texto((string) ($corpo['autor'] ?? ''), 120);
            if ($metodo === 'POST') {
                if ($texto === '') erroConfigPesquisa(400, 'Informe o texto da frase.');
                $ordem = (int) $db->query("SELECT COALESCE(MAX(ordem), 0) + 1 FROM pesquisa_frases")->fetchColumn();
                $db->prepare("INSERT INTO pesquisa_frases (texto, autor, ordem) VALUES (?, ?, ?)")->execute([$texto, $autor, $ordem]);
                $novo = (int) $db->lastInsertId();
                $auditoria->registrar($user['id'], 'criar_frase_pesquisa', 'pesquisa_frases', $novo, null, null);
                http_response_code(201);
                echo json_encode(['success' => true, 'id' => $novo]);
                exit;
            }
            $atual = $db->prepare("SELECT * FROM pesquisa_frases WHERE id = ?");
            $atual->execute([$id]);
            $fr = $atual->fetch(PDO::FETCH_ASSOC);
            if (!$fr) erroConfigPesquisa(404, 'Frase não encontrada.');
            $novoTexto = array_key_exists('texto', $corpo) ? $texto : $fr['texto'];
            if ($novoTexto === '') erroConfigPesquisa(400, 'Informe o texto da frase.');
            $db->prepare("UPDATE pesquisa_frases SET texto = ?, autor = ?, ativo = ? WHERE id = ?")->execute([
                $novoTexto, array_key_exists('autor', $corpo) ? $autor : $fr['autor'],
                array_key_exists('ativo', $corpo) ? (!empty($corpo['ativo']) ? 1 : 0) : (int) $fr['ativo'], $id]);
            $auditoria->registrar($user['id'], 'alterar_frase_pesquisa', 'pesquisa_frases', $id, null, null);
            echo json_encode(['success' => true]);
            exit;
        }
        if ($metodo === 'DELETE') {
            $db->prepare("DELETE FROM pesquisa_frases WHERE id = ?")->execute([$id]);
            $auditoria->registrar($user['id'], 'excluir_frase_pesquisa', 'pesquisa_frases', $id, null, null);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    if ($metodo === 'PUT' && $acao === 'frases-ordem') {
        reordenarPesquisa($db, 'pesquisa_frases', (array) ($corpo['ids'] ?? []));
        echo json_encode(['success' => true]);
        exit;
    }

    erroConfigPesquisa(400, 'Ação inválida');
} catch (Throwable $e) {
    error_log('[PESQUISA CONFIG] Erro: ' . $e->getMessage());
    erroConfigPesquisa(500, 'Erro ao salvar as configurações da pesquisa');
}
