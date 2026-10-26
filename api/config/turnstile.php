<?php
/**
 * Cloudflare Turnstile - Validação de CAPTCHA
 */

require_once __DIR__ . '/environment.php';

define('TURNSTILE_SITE_KEY', '0x4AAAAAACSR9w_nSv0Z5QIO');
// Segredo só pelo .env (TURNSTILE_SECRET_KEY_LOCAL/_PROD); a chave pública acima vai no HTML do login
define('TURNSTILE_SECRET_KEY', (string) Environment::get('turnstile_secret_key', ''));
define('TURNSTILE_ENABLED', Environment::isProduction());

/**
 * Valida o token Turnstile com a API da Cloudflare
 * 
 * @param string $token Token recebido do formulário
 * @param string $ip IP do usuário (opcional)
 * @return bool True se válido, False se inválido
 */
function validateTurnstile($token, $ip = null) {
    if (!TURNSTILE_ENABLED) {
        return true;
    }

    // Sem segredo configurado em produção: não trava o login de todos (o limite de tentativas continua valendo)
    if (TURNSTILE_SECRET_KEY === '') {
        error_log('[TURNSTILE] TURNSTILE_SECRET_KEY_PROD não configurada no .env: CAPTCHA do login desativado');
        return true;
    }

    if (empty($token)) {
        return false;
    }
    
    $data = [
        'secret' => TURNSTILE_SECRET_KEY,
        'response' => $token
    ];
    
    if ($ip) {
        $data['remoteip'] = $ip;
    }
    
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log('Turnstile cURL Error: ' . $curlError);
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log('Turnstile HTTP Error: ' . $httpCode);
        return false;
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['success'])) {
        error_log('Turnstile Response: ' . $response);
        return false;
    }
    
    if (!$result['success'] && isset($result['error-codes'])) {
        error_log('Turnstile Error: ' . implode(', ', $result['error-codes']));
    }
    
    return $result['success'] === true;
}
