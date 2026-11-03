<?php
/**
 * Acesso aos crons de api/cron/.
 * - Linha de comando (agendador do servidor): liberado.
 * - HTTP (cron externo, ex.: cron-job.org): exige a chave CRON_KEY_LOCAL/CRON_KEY_PROD do .env,
 *   enviada no header "X-Cron-Key" (só o cabeçalho: na URL, ?key=, a chave ficaria nos logs do servidor
 *   e do proxy). Sem chave configurada,
 *   o acesso por HTTP fica bloqueado.
 */
require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/../services/log-service.php';

function cronExigirAcesso(): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $esperada = (string) Environment::get('cron_key', '');
    $recebida = $_SERVER['HTTP_X_CRON_KEY'] ?? '';

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
 * Depois de um erro fatal (memória ou tempo esgotados), dá folga para a finalização do PHP ainda rodar
 * os lembretes e a fila: mais 128 MB de memória e mais 120 s (verificar-emails.php).
 */
function cronFolgaParaFinalizar(): void {
    $limite = trim((string) ini_get('memory_limit'));
    if ($limite !== '' && $limite !== '-1') {
        $unidade = strtolower(substr($limite, -1));
        $bytes = (int) $limite * (['g' => 1073741824, 'm' => 1048576, 'k' => 1024][$unidade] ?? 1);
        @ini_set('memory_limit', (string) ($bytes + 128 * 1048576));
    }
    @set_time_limit(120);
}

/**
 * Registra uma linha no log do cron (backend/logs/<arquivo>-AAAA-MM-DD.log), via LogService.
 * Mantém a assinatura antiga (nome do arquivo com ".log") para não mudar as
 * ~10 chamadas já existentes nos crons; só delega para o logger único.
 */
function cronLog(string $arquivo, string $mensagem): void {
    $canal = preg_replace('/\.log$/', '', $arquivo);
    LogService::info($canal, $mensagem);
}
