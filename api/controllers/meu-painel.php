<?php
/**
 * "Meu Painel" (antiga tela "Envio de Pesquisas" da Área do Funcionário do METRICA360, 27/10/2026).
 * Convites e notas continuam no METRICA360: este controller só repassa ao METRICA360 (Metrica360Client,
 * X-API-KEY), identificando o servidor pelo e-mail do usuário logado, lido do banco (nunca da requisição).
 *
 *   GET  ?acao=acesso        {disponivel, existe, atendimento_externo} — mostra/esconde o item do menu
 *   GET  ?status=&p=         indicadores, histórico (15 por página), regras e frase do dia
 *   POST {destinatario_nome, destinatario_email}   envia um convite de avaliação (mesma regra do METRICA360)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/metrica360-client.php';
require_once __DIR__ . '/../services/log-service.php';

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

/** Tradução das respostas do METRICA360 que valem para qualquer rota. */
function falhaMetrica360(array $r): void {
    $status = $r['dados']['status'] ?? '';
    if ($r['http'] === 404 || $status === 'sem_funcionario') {
        erroPainel(404, 'Seu e-mail não está cadastrado no METRICA360. Peça ao administrador do METRICA360 que cadastre você com o mesmo e-mail do Conecta.');
    }
    if ($r['http'] === 403 || $status === 'sem_atendimento_externo') {
        erroPainel(403, 'O envio de pesquisas é só para quem faz atendimento externo (marcado no cadastro do METRICA360).');
    }
    error_log('[MEU-PAINEL] METRICA360 respondeu HTTP ' . $r['http'] . ' (' . $status . ')');
    erroPainel(503, 'O METRICA360 não respondeu agora. Tente de novo em alguns minutos.');
}

try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT email FROM usuarios WHERE id = ?");
    $stmt->execute([(int) $user['id']]);
    $email = (string) $stmt->fetchColumn();

    $cliente = new Metrica360Client();
    if (!$cliente->configurado()) {
        if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'acesso') {
            echo json_encode(['disponivel' => false, 'existe' => false, 'atendimento_externo' => false]);
            exit;
        }
        erroPainel(503, 'A integração com o METRICA360 não está configurada (METRICA360_URL/METRICA360_API_KEY no .env).');
    }

    if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'acesso') {
        $r = $cliente->get('/api/integracao/funcionario', ['email' => $email]);
        if ($r['http'] !== 200 || ($r['dados']['status'] ?? '') !== 'ok') {
            echo json_encode(['disponivel' => false, 'existe' => false, 'atendimento_externo' => false]);
            exit;
        }
        echo json_encode([
            'disponivel'          => true,
            'existe'              => (bool) ($r['dados']['existe'] ?? false),
            'atendimento_externo' => (bool) ($r['dados']['atendimento_externo'] ?? false),
        ]);
        exit;
    }

    if ($metodo === 'GET') {
        $status = in_array($_GET['status'] ?? '', ['avaliado', 'aguardando', 'expirado'], true) ? $_GET['status'] : '';
        $r = $cliente->get('/api/integracao/painel', ['email' => $email, 'status' => $status, 'p' => max(1, (int) ($_GET['p'] ?? 1))]);
        if ($r['http'] !== 200 || ($r['dados']['status'] ?? '') !== 'ok') falhaMetrica360($r);
        $dados = $r['dados'];
        unset($dados['status']);
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST') {
        $corpo = json_decode(file_get_contents('php://input'), true) ?? [];
        $nome = trim(strip_tags((string) ($corpo['destinatario_nome'] ?? '')));
        $destino = filter_var(trim((string) ($corpo['destinatario_email'] ?? '')), FILTER_VALIDATE_EMAIL);
        if ($nome === '' || !$destino) erroPainel(400, 'Informe o nome e um e-mail válido do cidadão.');

        $r = $cliente->post('/api/integracao/convite-manual', [
            'responsavel_email'  => $email,
            'destinatario_nome'  => mb_substr($nome, 0, 150),
            'destinatario_email' => $destino,
        ]);
        $status = $r['dados']['status'] ?? '';

        if ($status === 'enviado') {
            (new AuditoriaService($db))->registrar($user['id'], 'enviar_convite_avaliacao', 'convites_avaliacao',
                (int) ($r['dados']['convite_id'] ?? 0) ?: null, null,
                json_encode(['destinatario' => LogService::mascararEmail($destino)], JSON_UNESCAPED_UNICODE));
            http_response_code(201);
            echo json_encode(['success' => true, 'mensagem' => "Convite enviado com sucesso para {$destino}."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($status === 'quarentena') {
            $dias = (int) ($r['dados']['quarentena_dias'] ?? 0);
            $mensagem = 'Já existe um convite enviado para este e-mail' . ($dias ? " nos últimos {$dias} dia(s)" : ' recentemente') . '.';
            if (!empty($r['dados']['proximo_envio'])) {
                $mensagem .= ' Novo convite liberado a partir de ' . date('d/m/Y \à\s H:i', strtotime($r['dados']['proximo_envio'])) . '.';
            }
            erroPainel(409, $mensagem);
        }
        if ($status === 'dados_invalidos') erroPainel(400, 'Informe o nome e um e-mail válido do cidadão.');
        if ($status === 'falha_email') erroPainel(502, 'Não foi possível enviar o e-mail. Tente novamente ou contate o suporte.');
        falhaMetrica360($r);
    }

    erroPainel(405, 'Método não permitido');
} catch (Exception $e) {
    error_log('[MEU-PAINEL] Erro: ' . $e->getMessage());
    erroPainel(500, 'Erro ao carregar o painel');
}
