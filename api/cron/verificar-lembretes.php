<?php
/**
 * Dispara os lembretes vencidos (e cancela os que não fazem mais sentido).
 *
 * Execução: pela linha de comando (php api/cron/verificar-lembretes.php) ou por HTTP com a
 * chave CRON_KEY_*. Também é chamado ao final de verificar-emails.php (a cada execução do
 * cron de e-mails), então não precisa de agendamento próprio em produção.
 */
require_once __DIR__ . '/acesso-cron.php';
cronExigirAcesso();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/email-sender.php';
require_once __DIR__ . '/../services/lembrete-service.php';

try {
    $db_lembretes = (new Database())->getConnection();

    if (!cronTravar($db_lembretes, 'lembretes')) {
        echo "Lembretes: outra execução em andamento\n";
    } else {
        $resumo = LembreteService::dispararVencidos($db_lembretes, new EmailSender($db_lembretes));
        $linha = "Lembretes: {$resumo['enviados']} enviado(s), {$resumo['cancelados']} cancelado(s), {$resumo['erros']} erro(s)";
        echo $linha . "\n";
        if ($resumo['enviados'] || $resumo['cancelados'] || $resumo['erros']) {
            cronLog('lembretes-cron.log', $linha);
        }
        // Notificações do sino já lidas há mais de 90 dias (a tabela crescia sem limite)
        $db_lembretes->exec("DELETE FROM notificacoes WHERE lida = 1 AND criado_em < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }
} catch (Exception $e) {
    error_log('[CRON LEMBRETES] Erro: ' . $e->getMessage());
    cronLog('lembretes-cron.log', 'Erro: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    echo "Lembretes: erro (ver log)\n";
}

// Envia a fila de e-mails (inclui os avisos dos lembretes acima)
include __DIR__ . '/enviar-fila.php';
