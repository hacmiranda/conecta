<?php
/**
 * Configuração pública da tela de login (sem sessão).
 *
 * Diz à tela se o Turnstile está ligado, com a mesma regra que auth.php aplica ao validar:
 * só em produção (APP_ENV) e com o segredo definido no .env. Antes a tela decidia pelo endereço
 * digitado (só localhost/127.0.0.1 contavam como local), então quem abria o sistema local pelo IP
 * da máquina na rede recebia um CAPTCHA que a Cloudflare recusa para esse endereço.
 */
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/turnstile.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

try {
    $ativo = TURNSTILE_ENABLED && TURNSTILE_SECRET_KEY !== '';
    echo json_encode([
        'turnstile' => $ativo,
        'turnstile_site_key' => $ativo ? TURNSTILE_SITE_KEY : null,
    ]);
} catch (Throwable $e) {
    error_log('[LOGIN CONFIG] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao carregar a configuração do login']);
}
