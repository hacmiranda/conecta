<?php
/**
 * "Meu Painel" (antiga tela "Envio de Pesquisas" da Área do Funcionário do METRICA360): o servidor com
 * "atendimento externo" marcado no cadastro envia convites de avaliação e acompanha os dele. Desde a
 * unificação com o METRICA360 (03/11/2026) tudo é do próprio Conecta (módulo Satisfação, tabelas pesquisa_*).
 *
 *   GET  ?acao=acesso        {disponivel, existe, atendimento_externo} — mostra/esconde o item do menu
 *   GET  ?status=&p=         indicadores, histórico (15 por página), regras e frase do dia
 *   POST {destinatario_nome, destinatario_email}   envia um convite de avaliação (quarentena por e-mail)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/log-service.php';
require_once __DIR__ . '/../services/pesquisa-painel-service.php';
require_once __DIR__ . '/../services/pesquisa-convite-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'POST') {
    AuthMiddleware::verificarCSRF();
}

function erroPainel(int $codigo, string $mensagem, array $extra = []): void {
    http_response_code($codigo);
    echo json_encode(['error' => $mensagem] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $usuarioId = (int) $user['id'];
    $externo = !empty($user['atendimento_externo']);

    if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'acesso') {
        echo json_encode(['disponivel' => true, 'existe' => true, 'atendimento_externo' => $externo]);
        exit;
    }

    // O Meu Painel só vale para quem faz atendimento externo (marcado no cadastro do usuário)
    if (!$externo) {
        erroPainel(403, 'O envio de pesquisas é só para quem faz atendimento externo (marcado no cadastro do usuário pelo administrador).');
    }

    if ($metodo === 'GET') {
        $status = in_array($_GET['status'] ?? '', ['avaliado', 'aguardando', 'expirado'], true) ? $_GET['status'] : '';
        echo json_encode(PesquisaPainelService::meuPainel($db, $usuarioId, $status, max(1, (int) ($_GET['p'] ?? 1))), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST') {
        $corpo = json_decode(file_get_contents('php://input'), true) ?? [];
        $nome = trim(strip_tags((string) ($corpo['destinatario_nome'] ?? '')));
        $destino = filter_var(trim((string) ($corpo['destinatario_email'] ?? '')), FILTER_VALIDATE_EMAIL);
        if ($nome === '' || !$destino) erroPainel(400, 'Informe o nome e um e-mail válido do cidadão.');

        $r = (new PesquisaConviteService($db))->enviar($usuarioId, mb_substr($nome, 0, 150), mb_substr($destino, 0, 255), ['origem' => 'manual']);

        if ($r['status'] === 'enviado') {
            (new AuditoriaService($db))->registrar($user['id'], 'enviar_convite_avaliacao', 'pesquisa_convites',
                $r['convite_id'], null, json_encode(['destinatario' => LogService::mascararEmail($destino)], JSON_UNESCAPED_UNICODE));
            http_response_code(201);
            echo json_encode(['success' => true, 'mensagem' => "Convite enviado com sucesso para {$destino}."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($r['status'] === 'quarentena') {
            $dias = PesquisaConfig::regrasConvite($db)['quarentena_dias'];
            $mensagem = "Já existe um convite enviado para este e-mail nos últimos {$dias} dia(s).";
            if (!empty($r['proximo_envio'])) {
                $mensagem .= ' Novo convite liberado a partir de ' . date('d/m/Y \à\s H:i', (int) $r['proximo_envio']) . '.';
            }
            erroPainel(409, $mensagem);
        }
        erroPainel(502, 'Não foi possível enviar o e-mail. Tente novamente ou contate o suporte.');
    }

    erroPainel(405, 'Método não permitido');
} catch (Throwable $e) {
    error_log('[MEU-PAINEL] Erro: ' . $e->getMessage());
    erroPainel(500, 'Erro ao carregar o painel');
}
