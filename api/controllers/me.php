<?php
/**
 * Dados atuais do usuário logado (GET), lidos do banco a cada chamada.
 *
 * O servidor já relê papel e setor em toda requisição (AuthMiddleware::verificarAuth), mas a tela usava
 * o que ficou guardado em localStorage.cd_usuario no login: um gestor rebaixado continuava vendo os menus
 * de gestor (e recebendo 403) até entrar de novo. A tela chama este endpoint ao carregar e, se o papel ou
 * o setor mudou, atualiza o que guardou (auditoria 01/11/2026, INC-06). Mesmos campos da resposta do login.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';

AuthMiddleware::configurarCORS(['GET']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$user = AuthMiddleware::verificarAuth();

try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT u.id, u.nome, u.email, u.tipo, u.avatar, u.cargo, u.setor_id, u.trocar_senha, u.atendimento_externo, s.nome AS setor_nome
                          FROM usuarios u LEFT JOIN setores s ON s.id = u.setor_id WHERE u.id = ?");
    $stmt->execute([(int) $user['id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }

    echo json_encode(['usuario' => [
        'id'           => (int) $usuario['id'],
        'nome'         => $usuario['nome'],
        'email'        => $usuario['email'],
        'tipo'         => $usuario['tipo'],
        'avatar'       => $usuario['avatar'] ?? null,
        'cargo'        => $usuario['cargo'] ?? null,
        'setor_id'     => $usuario['setor_id'] !== null ? (int) $usuario['setor_id'] : null,
        'setor_nome'   => $usuario['setor_nome'] ?? null,
        'setores'      => AcessoService::setoresComNome($db, $usuario),
        'somente_leitura' => AcessoService::ehSomenteLeitura($usuario),
        'atendimento_externo' => (int) ($usuario['atendimento_externo'] ?? 0) === 1,
        'trocar_senha' => (int) ($usuario['trocar_senha'] ?? 0) === 1,
    ]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ME] Erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao carregar os dados do usuário']);
}
