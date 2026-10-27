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
    } catch (Exception $e) {
        $resultado = false;
        $situacao = 'E-mails: erro crítico (ver log)';
        error_log('[CRON EMAILS] Erro crítico: ' . $e->getMessage());
    }

    cronLog('email-cron.log', $situacao);
    echo $situacao . "\n";
}

// Lembretes vencidos (mesma execução do cron)
include __DIR__ . '/verificar-lembretes.php';
