<?php
/**
 * Cache curto em arquivo (backend/cache/<grupo>/, pasta fora do alcance da web), para respostas caras que
 * podem valer por alguns minutos: o acesso ao "Meu Painel" (consulta ao METRICA360, até 10 s) e o card
 * "SLA vencido" do dashboard (auditoria 01/11/2026, DES-07 e DES-10).
 *
 * A chave vira sha1 no nome do arquivo (nunca entra dado pessoal no nome). Falha de leitura ou gravação
 * só faz o valor ser calculado de novo: o cache nunca é obrigatório.
 */
class CacheArquivoService {

    private static ?string $base = null;

    /** Troca a pasta base (testes). null volta ao padrão. */
    public static function usarPasta(?string $pasta): void {
        self::$base = $pasta !== null ? rtrim($pasta, '/\\') : null;
    }

    private static function arquivo(string $grupo, string $chave): string {
        $grupo = preg_replace('/[^a-z0-9_-]/i', '', $grupo) ?: 'geral';
        $base = self::$base ?? dirname(__DIR__, 2) . '/backend/cache';
        return $base . '/' . $grupo . '/' . sha1($chave) . '.json';
    }

    /** Valor guardado e ainda válido, ou null. */
    public static function ler(string $grupo, string $chave) {
        $arquivo = self::arquivo($grupo, $chave);
        if (!is_file($arquivo)) {
            return null;
        }
        $dados = json_decode((string) @file_get_contents($arquivo), true);
        if (!is_array($dados) || (int) ($dados['expira'] ?? 0) < time() || !array_key_exists('valor', $dados)) {
            return null;
        }
        return $dados['valor'];
    }

    /** Guarda o valor (serializável em JSON) por $segundos. */
    public static function gravar(string $grupo, string $chave, $valor, int $segundos): void {
        $arquivo = self::arquivo($grupo, $chave);
        $pasta = dirname($arquivo);
        if (!is_dir($pasta)) {
            @mkdir($pasta, 0750, true);
        }
        $json = json_encode(['expira' => time() + max(1, $segundos), 'valor' => $valor], JSON_UNESCAPED_UNICODE);
        $temporario = $arquivo . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if ($json === false || @file_put_contents($temporario, $json) === false || !@rename($temporario, $arquivo)) {
            @unlink($temporario);
        }
    }

    /** Apaga o valor guardado. */
    public static function apagar(string $grupo, string $chave): void {
        @unlink(self::arquivo($grupo, $chave));
    }
}
