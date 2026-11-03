<?php
/**
 * Logger único do backend: grava em backend/logs/<canal>-AAAA-MM-DD.log (um arquivo por dia), no
 * formato "[data] NIVEL mensagem". Substitui os echo/file_put_contents espalhados em
 * email-service.php e nos crons (Fase 7 das melhorias adiadas).
 *
 * - erro() também chama error_log(), para aparecer no log de erros do PHP.
 * - detalhe() só grava com LOG_DETALHADO=1 no .env (andamento mensagem a mensagem do cron de
 *   e-mail; antes eram 98% das linhas e o arquivo passava de 3 MB por dia).
 * - Rotação (auditoria 01/11/2026, DES-02): ao abrir o arquivo do dia, apaga os do mesmo canal com
 *   mais de LOG_RETENCAO_DIAS dias (padrão 30). O arquivo antigo sem data (<canal>.log) não é tocado.
 * - Em CLI (cron rodado manualmente), toda chamada também imprime no terminal,
 *   preservando a saída que o operador via ao rodar o cron na mão.
 * - Testes usam outro diretório (usarDiretorio), para não escrever no log real (MAN-05).
 * - Nunca logar senha/token/dado pessoal sensível (regra de segurança do projeto).
 */
class LogService {

    const RETENCAO_PADRAO_DIAS = 30;

    /** Diretório dos logs (null = backend/logs). */
    private static ?string $diretorio = null;

    /** Canais cuja limpeza já foi conferida nesta execução ("canal|data"). */
    private static array $conferidos = [];

    /** Em CLI, também imprime no terminal (desligado nos testes). */
    private static bool $ecoar = true;

    public static function info(string $canal, string $mensagem): void {
        self::gravar($canal, 'INFO', $mensagem);
    }

    /** Andamento detalhado: só grava com LOG_DETALHADO=1 no .env. */
    public static function detalhe(string $canal, string $mensagem): void {
        if (self::detalhado()) {
            self::gravar($canal, 'INFO', $mensagem);
        }
    }

    public static function aviso(string $canal, string $mensagem): void {
        self::gravar($canal, 'AVISO', $mensagem);
    }

    public static function erro(string $canal, string $mensagem): void {
        self::gravar($canal, 'ERRO', $mensagem);
        error_log("[$canal] $mensagem");
    }

    /** E-mail para log: "j***@dominio.com" (nunca o endereço inteiro). */
    public static function mascararEmail(string $email): string {
        $arroba = strpos($email, '@');
        if ($arroba === false) {
            return '(sem @)';
        }
        return substr($email, 0, 1) . '***@' . substr($email, $arroba + 1);
    }

    /** Troca o diretório dos logs (testes). null volta ao padrão. */
    public static function usarDiretorio(?string $diretorio, bool $ecoarNoTerminal = true): void {
        self::$diretorio = $diretorio !== null ? rtrim($diretorio, '/\\') : null;
        self::$ecoar = $ecoarNoTerminal;
        self::$conferidos = [];
    }

    public static function diretorio(): string {
        return self::$diretorio ?? dirname(__DIR__, 2) . '/backend/logs';
    }

    /** Nome do arquivo do canal num dia (AAAA-MM-DD). */
    public static function nomeArquivo(string $canal, string $data): string {
        return $canal . '-' . $data . '.log';
    }

    /**
     * Apaga os arquivos do canal com data anterior a ($hoje - $dias). Só mexe em arquivos no formato
     * <canal>-AAAA-MM-DD.log (outro canal com o mesmo prefixo, ex.: "email-cron-x", não casa).
     * Devolve quantos foram apagados.
     */
    public static function limparAntigos(string $diretorio, string $canal, int $dias, string $hoje): int {
        $limite = date('Y-m-d', strtotime($hoje . ' -' . max(1, $dias) . ' days'));
        $padrao = '/^' . preg_quote($canal, '/') . '-(\d{4}-\d{2}-\d{2})\.log$/';
        $apagados = 0;
        $arquivos = @scandir($diretorio);
        foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
            if (preg_match($padrao, (string) $arquivo, $m) && $m[1] < $limite) {
                if (@unlink($diretorio . '/' . $arquivo)) {
                    $apagados++;
                }
            }
        }
        return $apagados;
    }

    private static function detalhado(): bool {
        if (!class_exists('Environment')) {
            return false;
        }
        return in_array(strtolower(trim((string) Environment::get('log_detalhado', '0'))), ['1', 'true', 'sim', 'on'], true);
    }

    private static function retencaoDias(): int {
        $dias = class_exists('Environment') ? (int) Environment::get('log_retencao_dias', self::RETENCAO_PADRAO_DIAS) : self::RETENCAO_PADRAO_DIAS;
        return $dias > 0 ? $dias : self::RETENCAO_PADRAO_DIAS;
    }

    private static function gravar(string $canal, string $nivel, string $mensagem): void {
        $linha = '[' . date('Y-m-d H:i:s') . "] $nivel $mensagem";

        $dir = self::diretorio();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $hoje = date('Y-m-d');
        $arquivo = $dir . '/' . self::nomeArquivo($canal, $hoje);

        // Primeiro registro do dia neste canal (nesta execução): apaga os arquivos vencidos
        $chave = $canal . '|' . $hoje;
        if (!isset(self::$conferidos[$chave])) {
            self::$conferidos[$chave] = true;
            if (!file_exists($arquivo)) {
                self::limparAntigos($dir, $canal, self::retencaoDias(), $hoje);
            }
        }
        @file_put_contents($arquivo, $linha . "\n", FILE_APPEND);

        if (PHP_SAPI === 'cli' && self::$ecoar) {
            echo $linha . "\n";
        }
    }
}
