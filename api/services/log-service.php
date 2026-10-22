<?php
/**
 * Logger único do backend: grava em backend/logs/<canal>.log, no formato
 * "[data] NIVEL mensagem". Substitui os echo/file_put_contents espalhados em
 * email-service.php e nos crons (Fase 7 das melhorias adiadas).
 *
 * - erro() também chama error_log(), para aparecer no log de erros do PHP.
 * - Em CLI (cron rodado manualmente), toda chamada também imprime no terminal,
 *   preservando a saída que o operador via ao rodar o cron na mão.
 * - Nunca logar senha/token/dado pessoal sensível (regra de segurança do projeto).
 */
class LogService {

    public static function info(string $canal, string $mensagem): void {
        self::gravar($canal, 'INFO', $mensagem);
    }

    public static function aviso(string $canal, string $mensagem): void {
        self::gravar($canal, 'AVISO', $mensagem);
    }

    public static function erro(string $canal, string $mensagem): void {
        self::gravar($canal, 'ERRO', $mensagem);
        error_log("[$canal] $mensagem");
    }

    private static function gravar(string $canal, string $nivel, string $mensagem): void {
        $linha = '[' . date('Y-m-d H:i:s') . "] $nivel $mensagem";

        $dir = dirname(__DIR__, 2) . '/backend/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir . '/' . $canal . '.log', $linha . "\n", FILE_APPEND);

        if (PHP_SAPI === 'cli') {
            echo $linha . "\n";
        }
    }
}
