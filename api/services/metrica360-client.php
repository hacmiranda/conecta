<?php
require_once __DIR__ . '/../config/environment.php';

/**
 * Cliente HTTP do METRICA360 (rotas /api/integracao/*, cabeçalho X-API-KEY = METRICA360_API_KEY_*).
 * Usado pela pesquisa de satisfação (fila) e pelo "Meu Painel"/"Cruzamento de dados" (na hora).
 * Certificado sempre validado; sem seguir redirecionamento (a chave não vai para outro endereço).
 */
class Metrica360Client {

    private string $url;
    private string $chave;

    public function __construct(?string $url = null, ?string $chave = null) {
        $this->url   = rtrim($url ?? (string) Environment::get('metrica360_url', ''), '/');
        $this->chave = $chave ?? (string) Environment::get('metrica360_api_key', '');
    }

    public function configurado(): bool {
        return $this->url !== '' && $this->chave !== '';
    }

    /**
     * @param string $caminho ex.: '/api/integracao/painel'
     * @return array{http:int, corpo:string, dados:?array} http 0 = sem conexão (ou não configurado)
     */
    public function requisitar(string $metodo, string $caminho, array $query = [], ?array $corpo = null, int $timeout = 10): array {
        if (!$this->configurado()) {
            return ['http' => 0, 'corpo' => '', 'dados' => null];
        }
        $endereco = $this->url . $caminho . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($endereco);
        $opcoes = [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'X-API-KEY: ' . $this->chave],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($corpo !== null) {
            $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opcoes);
        $resposta = curl_exec($ch);
        $http = $resposta === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resposta === false) {
            error_log('[METRICA360] Falha de conexão (' . $caminho . '): ' . curl_error($ch));
        }
        curl_close($ch);

        $texto = $resposta === false ? '' : (string) $resposta;
        $dados = json_decode($texto, true);
        return ['http' => $http, 'corpo' => $texto, 'dados' => is_array($dados) ? $dados : null];
    }

    public function get(string $caminho, array $query = []): array {
        return $this->requisitar('GET', $caminho, $query);
    }

    public function post(string $caminho, array $corpo): array {
        return $this->requisitar('POST', $caminho, [], $corpo);
    }
}
