<?php
/**
 * Recuperação de e-mails perdidos: reprocessa uma janela maior (padrão 30 dias, máx. 60)
 * em todas as caixas dos setores (ou na global do .env). E-mails já processados são
 * reconhecidos pelo Message-ID e ignorados; os que ficaram de fora viram demanda/comentário
 * pelas mesmas regras do cron normal.
 *
 * Uso manual:
 *   php api/cron/verificar-emails-perdidos.php [dias]
 *   ou por HTTP com a chave CRON_KEY_*:  .../verificar-emails-perdidos.php?dias=30
 */
require_once __DIR__ . '/acesso-cron.php';
cronExigirAcesso();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email-config.php';
require_once __DIR__ . '/../services/email-service.php';

$dias = PHP_SAPI === 'cli' ? (int) ($argv[1] ?? 30) : (int) ($_GET['dias'] ?? 30);
$dias = max(1, min(60, $dias ?: 30));

$db = (new Database())->getConnection();

// Usa a mesma trava do cron normal: os dois nunca rodam ao mesmo tempo
if (!cronTravar($db, 'emails')) {
    echo "E-mails: outra execução em andamento; tente de novo em alguns minutos\n";
    exit;
}

cronLog('verificacao-manual.log', "Início da recuperação de e-mails ($dias dias)");

ob_start();
try {
    $resultado = (new EmailService($db))->verificarEmails($dias);
    $situacao = $resultado ? "Recuperação concluída ($dias dias)" : "Recuperação concluída com erros ($dias dias)";
} catch (Exception $e) {
    $situacao = 'Recuperação: erro crítico (ver log)';
    error_log('[CRON EMAILS PERDIDOS] Erro crítico: ' . $e->getMessage());
}
$detalhe = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], "\n", ob_get_clean()))));

cronLog('verificacao-manual.log', $situacao . "\n" . $detalhe);
if (PHP_SAPI === 'cli') {
    echo $detalhe . "\n";
}
echo $situacao . "\n";
