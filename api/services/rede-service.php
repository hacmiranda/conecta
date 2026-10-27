<?php
/**
 * IP do cliente. Por padrão é o REMOTE_ADDR (a conexão real). Os cabeçalhos CF-Connecting-IP e
 * X-Forwarded-For só valem quando a conexão vem de um proxy confiável (PROXIES_CONFIAVEIS no .env:
 * IPs ou faixas CIDR separados por vírgula, ex. as faixas da Cloudflare): fora disso, qualquer
 * cliente poderia mandar esses cabeçalhos e escolher o IP que aparece na auditoria e no limite de
 * tentativas.
 */
require_once __DIR__ . '/../config/environment.php';

class RedeService {

    public static function ipCliente(): string {
        $remoto = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $confiaveis = array_filter(array_map('trim', explode(',', (string) Environment::get('proxies_confiaveis', ''))));
        if (!$confiaveis || !self::ipNaLista($remoto, $confiaveis)) {
            return $remoto;
        }
        $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        // X-Forwarded-For: "cliente, proxy1, proxy2" — o primeiro é o cliente
        $xff = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $primeiro = trim($xff[0] ?? '');
        return $primeiro !== '' && filter_var($primeiro, FILTER_VALIDATE_IP) ? $primeiro : $remoto;
    }

    /** O IP está em alguma das faixas (IP exato ou CIDR, IPv4 ou IPv6)? Função pura (testável). */
    public static function ipNaLista(string $ip, array $faixas): bool {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($faixas as $faixa) {
            [$rede, $bits] = array_pad(explode('/', $faixa, 2), 2, null);
            $redeBin = @inet_pton(trim((string) $rede));
            if ($redeBin === false || strlen($redeBin) !== strlen($bin)) {
                continue;
            }
            $bits = $bits === null ? strlen($bin) * 8 : max(0, min(strlen($bin) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            $resto = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($redeBin, 0, $bytes)) {
                continue;
            }
            if ($resto === 0) {
                return true;
            }
            $mascara = chr((0xFF << (8 - $resto)) & 0xFF);
            if ((ord($bin[$bytes]) & ord($mascara)) === (ord($redeBin[$bytes]) & ord($mascara))) {
                return true;
            }
        }
        return false;
    }
}
