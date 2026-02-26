<?php
/**
 * Serviço de Rate Limiting — CRPDESK
 * Controla o número de tentativas por IP usando arquivos JSON.
 * Usado principalmente para proteger o endpoint de login contra força bruta.
 */

class RateLimitService {

    /** Diretório onde os contadores são armazenados */
    private static function getDiretorio(): string {
        $dir = __DIR__ . '/../../backend/logs/rate_limits/';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * Gera o caminho do arquivo de controle para um IP e ação
     */
    private static function getCaminho(string $ip, string $acao): string {
        // Normaliza IP e ação para nome de arquivo seguro
        $chave = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $ip . '_' . $acao);
        return self::getDiretorio() . $chave . '.json';
    }

    /**
     * Carrega os dados de rate limit de um IP/ação
     */
    private static function carregar(string $ip, string $acao): array {
        $caminho = self::getCaminho($ip, $acao);
        if (!file_exists($caminho)) {
            return ['tentativas' => 0, 'primeira_tentativa' => 0, 'bloqueado_ate' => 0];
        }
        $dados = json_decode(file_get_contents($caminho), true);
        return is_array($dados) ? $dados : ['tentativas' => 0, 'primeira_tentativa' => 0, 'bloqueado_ate' => 0];
    }

    /**
     * Persiste os dados de rate limit
     */
    private static function salvar(string $ip, string $acao, array $dados): void {
        $caminho = self::getCaminho($ip, $acao);
        file_put_contents($caminho, json_encode($dados), LOCK_EX);
    }

    /**
     * Verifica se o IP está bloqueado para determinada ação.
     *
     * @param string $ip             Endereço IP do cliente
     * @param string $acao           Identificador da ação (ex: 'login')
     * @param int    $limite         Número máximo de tentativas permitidas
     * @param int    $janela_segundos Janela de tempo em segundos
     * @return array ['bloqueado' => bool, 'tentativas' => int, 'segundos_restantes' => int]
     */
    public static function verificar(string $ip, string $acao, int $limite = 5, int $janela_segundos = 900): array {
        $dados = self::carregar($ip, $acao);
        $agora = time();

        // Verifica se há bloqueio explícito ativo
        if ($dados['bloqueado_ate'] > $agora) {
            return [
                'bloqueado'         => true,
                'tentativas'        => $dados['tentativas'],
                'segundos_restantes' => $dados['bloqueado_ate'] - $agora,
            ];
        }

        // Reseta janela se já expirou
        if ($dados['primeira_tentativa'] > 0 && ($agora - $dados['primeira_tentativa']) > $janela_segundos) {
            self::limpar($ip, $acao);
            return ['bloqueado' => false, 'tentativas' => 0, 'segundos_restantes' => 0];
        }

        // Verifica limite de tentativas
        if ($dados['tentativas'] >= $limite) {
            // Aplica bloqueio
            $dados['bloqueado_ate'] = $agora + $janela_segundos;
            self::salvar($ip, $acao, $dados);
            return [
                'bloqueado'          => true,
                'tentativas'         => $dados['tentativas'],
                'segundos_restantes' => $janela_segundos,
            ];
        }

        return [
            'bloqueado'          => false,
            'tentativas'         => $dados['tentativas'],
            'segundos_restantes' => 0,
        ];
    }

    /**
     * Registra uma tentativa falha para o IP/ação
     */
    public static function registrar(string $ip, string $acao): void {
        $dados = self::carregar($ip, $acao);
        $agora = time();

        if ($dados['primeira_tentativa'] === 0) {
            $dados['primeira_tentativa'] = $agora;
        }
        $dados['tentativas']++;
        self::salvar($ip, $acao, $dados);
    }

    /**
     * Limpa o contador para o IP/ação (chamado após sucesso)
     */
    public static function limpar(string $ip, string $acao): void {
        $caminho = self::getCaminho($ip, $acao);
        if (file_exists($caminho)) {
            unlink($caminho);
        }
    }
}
