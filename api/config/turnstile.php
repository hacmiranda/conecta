<?php
/**
 * Cloudflare Turnstile - Validação de CAPTCHA
 */

require_once __DIR__ . '/environment.php';

define('TURNSTILE_SITE_KEY', '0x4AAAAAACSR9w_nSv0Z5QIO');
define('TURNSTILE_SECRET_KEY', '***REMOVIDO***');
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
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
