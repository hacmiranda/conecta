<?php
/**
 * Lê as caixas de e-mail (uma por setor, ou a global do .env) e abre/atualiza demandas.
 * Ao final, dispara os lembretes vencidos.
 *
 * Execução:
 *   - linha de comando:  php api/cron/verificar-emails.php
 *   - HTTP (cron-job.org, a cada 2 min): .../api/cron/verificar-emails.php
 *       com o header "X-Cron-Key: <CRON_KEY_PROD>"
 *
 * Por HTTP a resposta traz só um resumo; o detalhe fica em backend/logs/email-cron.log.
 */
require_once __DIR__ . '/acesso-cron.php';
cronExigirAcesso();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email-config.php';
require_once __DIR__ . '/../services/email-service.php';

$db = (new Database())->getConnection();

// Erro fatal na leitura (falta de memória, tempo esgotado), que nenhum catch pega: os lembretes e a fila
// rodam mesmo assim, na finalização do PHP (auditoria 01/11/2026, BUG-08). A mensagem que causou o erro
// já teve a tentativa contada (EmailService::processarEmail) e é deixada de lado depois de 5.
$cronEmailsSeguiu = false;
register_shutdown_function(function () use (&$cronEmailsSeguiu) {
    if ($cronEmailsSeguiu) {
        return;
    }
    $erro = error_get_last();
    $texto = $erro ? ($erro['message'] . ' em ' . basename((string) $erro['file']) . ':' . $erro['line']) : 'motivo desconhecido';
    error_log('[CRON EMAILS] Leitura interrompida por erro fatal: ' . $texto);
    cronLog('email-cron.log', 'Leitura interrompida por erro fatal (' . $texto . '); seguindo com lembretes e fila');
    cronFolgaParaFinalizar();
    include __DIR__ . '/verificar-lembretes.php';
});

// Outra execução lendo as caixas: pula só a leitura; lembretes e fila seguem (antes o "exit" pulava os dois)
if (!cronTravar($db, 'emails')) {
    echo "E-mails: outra execução em andamento\n";
} else {
    cronLog('email-cron.log', 'Início da verificação de e-mails');

    // O EmailService grava o andamento via LogService (backend/logs/email-cron.log, e também no
    // terminal quando rodado por CLI)
    try {
        $resultado = (new EmailService($db))->verificarEmails(3);
        $situacao = $resultado ? 'E-mails: verificação concluída' : 'E-mails: verificação concluída com erros';
    } catch (Throwable $e) {
        // Throwable: um TypeError/Error não pode mais impedir os lembretes e a fila logo abaixo
        $resultado = false;
        $situacao = 'E-mails: erro crítico (ver log)';
        error_log('[CRON EMAILS] Erro crítico: ' . get_class($e) . ': ' . $e->getMessage());
    }

    cronLog('email-cron.log', $situacao);
    echo $situacao . "\n";
}

// Lembretes vencidos (mesma execução do cron)
$cronEmailsSeguiu = true;
include __DIR__ . '/verificar-lembretes.php';
