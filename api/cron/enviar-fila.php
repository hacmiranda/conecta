<?php
/**
 * Envia os e-mails da fila (email_fila), com novas tentativas em caso de falha.
 *
 * Execução: pela linha de comando (php api/cron/enviar-fila.php) ou por HTTP com a chave CRON_KEY_*.
 * Também é chamado ao final de verificar-lembretes.php (e, portanto, de verificar-emails.php),
 * então o cron externo existente já envia a fila a cada execução.
 */
require_once __DIR__ . '/acesso-cron.php';
cronExigirAcesso();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/email-fila-service.php';

try {
    $db_fila = (new Database())->getConnection();

    if (!cronTravar($db_fila, 'fila-emails')) {
        echo "Fila de e-mails: outra execução em andamento\n";
    } else {
        $resumo = EmailFilaService::processar($db_fila);
        $linha = "Fila de e-mails: {$resumo['enviados']} enviado(s), {$resumo['pendentes']} para nova tentativa, {$resumo['falhas']} desistência(s)";
        echo $linha . "\n";
        if ($resumo['enviados'] || $resumo['pendentes'] || $resumo['falhas']) {
            cronLog('fila-emails-cron.log', $linha);
        }
    }
} catch (Throwable $e) {
    error_log('[CRON FILA] Erro: ' . $e->getMessage());
    cronLog('fila-emails-cron.log', 'Erro: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    echo "Fila de e-mails: erro (ver log)\n";
}
