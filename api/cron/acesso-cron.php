<?php
/**
 * Acesso aos crons de api/cron/.
 * - Linha de comando (agendador do servidor): liberado.
 * - HTTP (cron externo, ex.: cron-job.org): exige a chave CRON_KEY_LOCAL/CRON_KEY_PROD do .env,
 *   enviada no header "X-Cron-Key" (preferível) ou no parâmetro ?key=. Sem chave configurada,
 *   o acesso por HTTP fica bloqueado.
 */
require_once __DIR__ . '/../config/environment.php';

function cronExigirAcesso(): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $esperada = (string) Environment::get('cron_key', '');
    $recebida = $_SERVER['HTTP_X_CRON_KEY'] ?? ($_GET['key'] ?? '');

    if (strlen($esperada) < 16 || !is_string($recebida) || !hash_equals($esperada, $recebida)) {
        http_response_code(403);
        exit("Acesso negado\n");
    }

    // O cron externo pode cortar a conexão: o processamento continua até o fim
    ignore_user_abort(true);
    set_time_limit(300);
}

/**
 * Impede duas execuções simultâneas do mesmo cron (trava nomeada do MySQL).
 * Devolve false se outra execução já estiver em andamento.
 */
function cronTravar(PDO $db, string $nome): bool {
    $stmt = $db->prepare("SELECT GET_LOCK(?, 0)");
    $stmt->execute(['conecta_cron_' . $nome]);
    return (int) $stmt->fetchColumn() === 1;
}

/**
 * Registra uma linha no log do cron (backend/logs/<arquivo>).
 */
function cronLog(string $arquivo, string $mensagem): void {
    $dir = dirname(__DIR__, 2) . '/backend/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($dir . '/' . $arquivo, '[' . date('Y-m-d H:i:s') . "] $mensagem\n", FILE_APPEND);
}
