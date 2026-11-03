<?php
/**
 * Módulo Satisfação — lado do cidadão (página pública frontend/avaliar.html). Sem login: o token do convite,
 * enviado por e-mail, é a única chave (uso único, com validade). Não devolve nada do servidor nem do convite.
 *
 *   GET  ?t=TOKEN                          {situacao: ok|invalido|ja_avaliado|expirado, categorias, expiracao_dias}
 *   POST {token, nota, comentario, categorias:[ids]}   registra a avaliação
 *        → {sucesso, tipo: positivo|critico, google_url}  (nota 4 e 5 abrem o Google Avaliações, se configurado)
 * Limite de tentativas por IP: 10 avaliações a cada 5 minutos e 60 consultas a cada 5 minutos.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/rate-limit-service.php';
require_once __DIR__ . '/../services/rede-service.php';
require_once __DIR__ . '/../services/pesquisa-avaliacao-service.php';

$metodo = $_SERVER['REQUEST_METHOD'];
if (!in_array($metodo, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
    exit;
}

function respostaPublica(int $codigo, array $dados): void {
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $ip = RedeService::ipCliente();
    $limite = RateLimitService::consumir($ip, $metodo === 'POST' ? 'pesquisa_avaliacao' : 'pesquisa_consulta', $metodo === 'POST' ? 10 : 60, 300);
    if ($limite['bloqueado']) {
        respostaPublica(429, ['sucesso' => false, 'mensagem' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.']);
    }
    $db = (new Database())->getConnection();

    if ($metodo === 'GET') {
        $estado = PesquisaAvaliacaoService::estado($db, (string) ($_GET['t'] ?? ''));
        respostaPublica(200, $estado);
    }

    $corpo = json_decode(file_get_contents('php://input'), true) ?? [];
    $resultado = PesquisaAvaliacaoService::registrar(
        $db,
        (string) ($corpo['token'] ?? ''),
        (int) ($corpo['nota'] ?? 0),
        (string) ($corpo['comentario'] ?? ''),
        is_array($corpo['categorias'] ?? null) ? $corpo['categorias'] : []
    );

    $erros = [
        'nota_invalida' => [422, 'Por favor, selecione uma nota de 1 a 5 estrelas.'],
        'invalido'      => [404, 'Link de avaliação inválido. Use o link enviado no convite por e-mail.'],
        'ja_avaliado'   => [409, 'Este link de avaliação já foi utilizado.'],
        'expirado'      => [410, 'Este link de avaliação expirou. Solicite um novo atendimento ou convite.'],
    ];
    if (isset($erros[$resultado['codigo']])) {
        respostaPublica($erros[$resultado['codigo']][0], ['sucesso' => false, 'codigo' => $resultado['codigo'], 'mensagem' => $erros[$resultado['codigo']][1]]);
    }
    respostaPublica(200, ['sucesso' => true, 'tipo' => $resultado['tipo'], 'google_url' => $resultado['google_url'] ?? '']);
} catch (Throwable $e) {
    error_log('[PESQUISA PUBLICA] Erro: ' . $e->getMessage());
    respostaPublica(500, ['sucesso' => false, 'mensagem' => 'Ocorreu um problema ao registrar sua avaliação. Tente novamente.']);
}
