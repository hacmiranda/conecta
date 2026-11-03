<?php
/**
 * Importa do METRICA360 para o Conecta as PESSOAS, os SETORES e as CONFIGURAÇÕES da pesquisa de satisfação
 * (categorias de avaliação, frases do dia e regras de convite). Convites, avaliações e auditoria do METRICA360
 * eram de teste e não vêm.
 *
 *   php database/importar-metrica360.php                       ensaio: mostra o que seria feito, não grava nada
 *   php database/importar-metrica360.php --executar            grava no banco do Conecta (local)
 *   php database/importar-metrica360.php --relatorio=ARQ.txt   também grava o relatório num arquivo
 *   php database/importar-metrica360.php --origem-env=CAMINHO  .env do METRICA360 (padrão: ../metrica360/.env.local)
 *
 * A origem é lida só para leitura (sessão somente leitura) e precisa ser um banco LOCAL: o script recusa um
 * host remoto, para nunca tocar no banco de produção do METRICA360. No Conecta, recusa rodar com APP_ENV de
 * produção (o banco de produção vem da réplica do banco local já importado).
 * Setores são ligados pelo nome, nunca pelo id (os ids dos dois sistemas estão trocados).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/config/database.php';
require_once __DIR__ . '/../api/services/importacao-metrica360.php';

if (Environment::isProduction()) {
    fwrite(STDERR, "Recusado: APP_ENV indica produção. A importação roda no banco local; a produção recebe a réplica.\n");
    exit(1);
}

$executar = false;
$relatorioArquivo = null;
$envOrigem = realpath(__DIR__ . '/../../metrica360') ? __DIR__ . '/../../metrica360/.env.local' : null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--executar') {
        $executar = true;
    } elseif (strpos($arg, '--relatorio=') === 0) {
        $relatorioArquivo = substr($arg, 12);
    } elseif (strpos($arg, '--origem-env=') === 0) {
        $envOrigem = substr($arg, 13);
    } else {
        fwrite(STDERR, "Opção desconhecida: $arg\n");
        exit(1);
    }
}
if ($envOrigem === null || !is_file($envOrigem)) {
    fwrite(STDERR, "Não achei o .env do METRICA360. Informe com --origem-env=CAMINHO.\n");
    exit(1);
}

// Conexão com a origem: só leitura e só local
$env = [];
foreach (file($envOrigem, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
    if ($linha[0] === '#' || strpos($linha, '=') === false) {
        continue;
    }
    [$k, $v] = explode('=', $linha, 2);
    $env[trim($k)] = trim($v, " \t\"'");
}
if (!in_array($env['DB_HOST'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: a origem não é um banco local (" . ($env['DB_HOST'] ?? '?') . "). Use uma cópia local do METRICA360.\n");
    exit(1);
}
$origem = new PDO(
    "mysql:host={$env['DB_HOST']};port=" . ($env['DB_PORT'] ?? 3306) . ";dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'], $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$origem->exec('SET SESSION TRANSACTION READ ONLY');

$db = (new Database())->getConnection();

$setoresOrigem = $origem->query("SELECT id, nome, ativo_painel, coordenador_id FROM setores ORDER BY nome")->fetchAll();
$usuariosOrigem = $origem->query("SELECT id, email, name, username, role, setor_id, atendimento_externo FROM users WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
$categorias = $origem->query("SELECT nome, emoji, ativo, ordem FROM categorias ORDER BY ordem, id")->fetchAll();
$frases = $origem->query("SELECT texto, autor, ativo, ordem FROM frases_motivacionais ORDER BY ordem, id")->fetchAll();
$config = $origem->query("SELECT chave, valor FROM configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
$contagens = [
    'convites'   => (int) $origem->query("SELECT COUNT(*) FROM convites_avaliacao")->fetchColumn(),
    'feedbacks'  => (int) $origem->query("SELECT COUNT(*) FROM feedbacks")->fetchColumn(),
    'categorias' => count($categorias),
    'frases'     => count($frases),
];

$setoresDestino = $db->query("SELECT id, nome FROM setores")->fetchAll(PDO::FETCH_ASSOC);
$usuariosDestino = $db->query("SELECT id, email, nome, tipo, setor_id, atendimento_externo FROM usuarios WHERE tipo <> 'cliente'")->fetchAll(PDO::FETCH_ASSOC);

$plano = ImportacaoMetrica360::planejar($setoresOrigem, $usuariosOrigem, $setoresDestino, $usuariosDestino);

$resultado = null;
if ($executar) {
    $resultado = ImportacaoMetrica360::executar($db, $plano, $categorias, $frases, $config);
}

$texto = ImportacaoMetrica360::relatorio($plano, $contagens, $executar);
if ($resultado) {
    $texto .= "\nGRAVADO: " . $resultado['setores_criados'] . ' setores, ' . $resultado['usuarios_criados'] . ' contas, '
        . $resultado['atendimento_externo_ligado'] . ' atendimentos externos ligados, ' . $resultado['categorias'] . ' categorias, '
        . $resultado['frases'] . ' frases, ' . $resultado['regras'] . " regras.\n";
} else {
    $texto .= "\nNada foi gravado. Para gravar: php database/importar-metrica360.php --executar\n";
}
echo $texto;
if ($relatorioArquivo !== null) {
    file_put_contents($relatorioArquivo, $texto);
    echo "Relatório salvo em $relatorioArquivo\n";
}
