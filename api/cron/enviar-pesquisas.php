<?php
/**
 * Módulo Satisfação: cria os convites dos pedidos de pesquisa de satisfação (tabela pesquisa_satisfacao, com novas
 * tentativas em caso de falha) e processa a expiração e o reenvio automático dos convites sem resposta.
 *
 * Execução: pela linha de comando (php api/cron/enviar-pesquisas.php) ou por HTTP com a chave CRON_KEY_*.
 * Também é chamado ao final de enviar-fila.php, então o cron externo existente já cuida disso.
 */
require_once __DIR__ . '/acesso-cron.php';
cronExigirAcesso();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/pesquisa-satisfacao-service.php';
require_once __DIR__ . '/../services/pesquisa-processador-service.php';

try {
    $db_pesquisa = (new Database())->getConnection();

    if (!cronTravar($db_pesquisa, 'pesquisa-satisfacao')) {
        echo "Pesquisas de satisfação: outra execução em andamento\n";
    } else {
        $resumo = PesquisaSatisfacaoService::processarFila($db_pesquisa);
        $convites = PesquisaProcessadorService::processar($db_pesquisa);
        $linha = "Pesquisas de satisfação: {$resumo['enviados']} enviada(s), {$resumo['nao_enviados']} não enviada(s) "
            . "(quarentena/sem responsável), {$resumo['pendentes']} para nova tentativa, {$resumo['falhas']} desistência(s); "
            . "convites: {$convites['expirados']} expirado(s), {$convites['reenviados']} reenviado(s)";
        echo $linha . "\n";
        if ($resumo['enviados'] || $resumo['nao_enviados'] || $resumo['pendentes'] || $resumo['falhas'] || $convites['expirados'] || $convites['reenviados']) {
            cronLog('pesquisa-satisfacao-cron.log', $linha);
        }
    }
} catch (Throwable $e) {
    error_log('[CRON PESQUISA] Erro: ' . $e->getMessage());
    cronLog('pesquisa-satisfacao-cron.log', 'Erro: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    echo "Pesquisas de satisfação: erro (ver log)\n";
}
