<?php
/**
 * Runner de migrações do Conecta CRPRS (somente linha de comando).
 *
 *   php database/migrar.php                    aplica, em ordem, as migrações pendentes
 *   php database/migrar.php --status           lista aplicadas e pendentes
 *   php database/migrar.php --marcar-ate=20    marca 9..20 como aplicadas SEM executar
 *                                              (para bancos que já receberam essas migrações à mão)
 *
 * As migrações são os arquivos database/<número>_*.sql com número >= 9 (o esquema base
 * é database/squema_crpdesk.sql). Cada arquivo roda uma única vez; o controle fica na tabela
 * `migracoes`. FAÇA BACKUP antes (mysqldump) — DDL do MySQL não volta atrás.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/config/database.php';

const PRIMEIRA_MIGRACAO = 9;

$db = (new Database())->getConnection();
$db->exec("CREATE TABLE IF NOT EXISTS migracoes (
    nome VARCHAR(150) NOT NULL PRIMARY KEY,
    aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

// Arquivos de migração em ordem numérica
$arquivos = [];
foreach (glob(__DIR__ . '/*.sql') as $caminho) {
    $nome = basename($caminho);
    if (preg_match('/^(\d+)_.+\.sql$/', $nome, $m) && (int) $m[1] >= PRIMEIRA_MIGRACAO) {
        $arquivos[(int) $m[1]] = $nome;
    }
}
ksort($arquivos);

$aplicadas = $db->query("SELECT nome FROM migracoes")->fetchAll(PDO::FETCH_COLUMN);

$opcoes = getopt('', ['status', 'marcar-ate:']);

if (isset($opcoes['status'])) {
    foreach ($arquivos as $numero => $nome) {
        echo (in_array($nome, $aplicadas, true) ? '[aplicada] ' : '[PENDENTE] ') . $nome . "\n";
    }
    exit(0);
}

if (isset($opcoes['marcar-ate'])) {
    $limite = (int) $opcoes['marcar-ate'];
    $ins = $db->prepare("INSERT IGNORE INTO migracoes (nome) VALUES (?)");
    foreach ($arquivos as $numero => $nome) {
        if ($numero <= $limite) {
            $ins->execute([$nome]);
            echo "marcada como aplicada: $nome\n";
        }
    }
    exit(0);
}

/**
 * Divide o conteúdo SQL em comandos (ignora comentários "--" e separa por ";" no fim da linha).
 */
function comandosDoArquivo(string $sql): array {
    $linhas = [];
    foreach (preg_split('/\R/', $sql) as $linha) {
        if (preg_match('/^\s*--/', $linha)) {
            continue;
        }
        $linhas[] = $linha;
    }
    $comandos = [];
    $atual = '';
    foreach ($linhas as $linha) {
        $atual .= $linha . "\n";
        if (preg_match('/;\s*$/', $linha)) {
            $comandos[] = trim($atual);
            $atual = '';
        }
    }
    if (trim($atual) !== '') {
        $comandos[] = trim($atual);
    }
    return array_values(array_filter($comandos, fn($c) => trim($c, " \n\t;") !== ''));
}

$pendentes = array_filter($arquivos, fn($nome) => !in_array($nome, $aplicadas, true));
if (!$pendentes) {
    echo "Nenhuma migração pendente.\n";
    exit(0);
}

foreach ($pendentes as $numero => $nome) {
    echo "Aplicando $nome ...\n";
    $comandos = comandosDoArquivo(file_get_contents(__DIR__ . '/' . $nome));
    try {
        foreach ($comandos as $i => $comando) {
            $db->exec($comando);
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "ERRO em $nome (comando " . ($i + 1) . " de " . count($comandos) . "): " . $e->getMessage() . "\n");
        fwrite(STDERR, "Corrija e, se parte do arquivo já rodou, ajuste à mão antes de repetir. Nada foi marcado como aplicado.\n");
        exit(1);
    }
    $db->prepare("INSERT INTO migracoes (nome) VALUES (?)")->execute([$nome]);
    echo "  ok (" . count($comandos) . " comandos)\n";
}
echo "Concluído.\n";
