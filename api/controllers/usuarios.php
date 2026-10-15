<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

/**
 * Gera um username único (nome.sobrenome) a partir do nome completo.
 * Usado quando o formulário de criação não informa um username explícito.
 */
function gerarUsernameUnico(PDO $db, string $nomeCompleto): string {
    $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nomeCompleto);
    $base = strtolower(trim($transliterado !== false ? $transliterado : $nomeCompleto));
    $base = preg_replace('/[^a-z0-9\s]/', '', $base);
    $partes = array_values(array_filter(preg_split('/\s+/', trim($base))));

    if (count($partes) === 0) {
        $base = 'usuario';
    } elseif (count($partes) === 1) {
        $base = $partes[0];
    } else {
        $base = $partes[0] . '.' . end($partes);
    }

    $candidato = $base;
    $sufixo = 2;
    $check = $db->prepare("SELECT id FROM usuarios WHERE username = :username");
    while (true) {
        $check->execute([':username' => $candidato]);
        if (!$check->fetch()) {
            break;
        }
        $candidato = $base . $sufixo;
        $sufixo++;
    }
    return $candidato;
}

// GET - Listar usuários (admin vê todos / pode filtrar por setor_id; gestor só vê o próprio setor)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);

    try {
        $where = [];
        $params = [];

        if ($user['tipo'] === 'admin') {
            if (isset($_GET['setor_id']) && $_GET['setor_id'] !== '') {
                $where[] = 'u.setor_id = :setor_id';
                $params[':setor_id'] = (int) $_GET['setor_id'];
            }
        } else {
            // Gestor: sempre restrito ao próprio setor
            $where[] = 'u.setor_id = :setor_id';
            $params[':setor_id'] = $user['setor_id'];
        }

        $sql = "SELECT u.id, u.nome, u.username, u.email, u.tipo, u.cargo, u.setor_id, u.ativo, u.avatar, u.criado_em,
                       s.nome as setor_nome
                FROM usuarios u
                LEFT JOIN setores s ON s.id = u.setor_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY u.nome';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar usuários']);
    }
}

// POST - Criar usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true);
    $nome = trim($input['nome'] ?? '');
    $email = trim($input['email'] ?? '');
    $tipo = $input['tipo'] ?? 'operador';
    $cargo = trim($input['cargo'] ?? '') ?: null;
    $username = trim($input['username'] ?? '');
    $setor_id = !empty($input['setor_id']) ? (int) $input['setor_id'] : null;

    if (empty($nome) || empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome e email são obrigatórios']);
        exit;
    }

    // Gestor só pode criar usuários comuns dentro do próprio setor
    if ($user['tipo'] === 'gestor') {
        if (!in_array($tipo, ['operador'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Gestores só podem cadastrar usuários com papel "operador"']);
            exit;
        }
        $setor_id = $user['setor_id'];
    }

    if (in_array($tipo, ['operador', 'gestor'], true) && empty($setor_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Setor é obrigatório para os papéis operador/gestor']);
        exit;
    }

    try {
        $check_query = "SELECT id FROM usuarios WHERE email = :email";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':email', $email);
        $check_stmt->execute();

        if ($check_stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Email já existe']);
            exit;
        }

        if (empty($username)) {
            $username = gerarUsernameUnico($db, $nome);
        } else {
            $checkUser = $db->prepare("SELECT id FROM usuarios WHERE username = :username");
            $checkUser->execute([':username' => $username]);
            if ($checkUser->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Nome de usuário já existe']);
                exit;
            }
        }

        $senha_hash = password_hash('123456', PASSWORD_DEFAULT);

        $query = "INSERT INTO usuarios (nome, username, email, tipo, cargo, setor_id, senha)
                  VALUES (:nome, :username, :email, :tipo, :cargo, :setor_id, :senha)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':cargo', $cargo);
        $stmt->bindParam(':setor_id', $setor_id);
        $stmt->bindParam(':senha', $senha_hash);
        $stmt->execute();

        $usuario_id = $db->lastInsertId();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_usuario', 'usuarios', $usuario_id, null, json_encode(['nome' => $nome, 'email' => $email, 'tipo' => $tipo, 'setor_id' => $setor_id]));

        $select_query = "SELECT id, nome, username, email, tipo, cargo, setor_id, avatar, criado_em FROM usuarios WHERE id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $usuario_id);
        $select_stmt->execute();
        echo json_encode($select_stmt->fetch());
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar usuário']);
    }
}

// PUT - Atualizar usuário
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', $path);
    $usuario_id = (int) end($path_parts);

    $input = json_decode(file_get_contents('php://input'), true);

    // Confirma que o alvo pertence ao setor do gestor (admin passa direto)
    $alvo_stmt = $db->prepare("SELECT nome, username, email, tipo, cargo, setor_id, ativo FROM usuarios WHERE id = ?");
    $alvo_stmt->execute([$usuario_id]);
    $alvo = $alvo_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$alvo) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }

    if ($user['tipo'] === 'gestor' && !AuthMiddleware::mesmoSetor($user, $alvo['setor_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode editar usuários do próprio setor']);
        exit;
    }

    // Ativar/Desativar usuário
    if (isset($input['ativo'])) {
        $ativo = $input['ativo'] ? 1 : 0;

        try {
            $stmt = $db->prepare("UPDATE usuarios SET ativo = ? WHERE id = ?");
            $stmt->execute([$ativo, $usuario_id]);

            $auditoria = new AuditoriaService($db);
            $acao = $ativo ? 'ativar_usuario' : 'desativar_usuario';
            $dados_novos = $alvo;
            $dados_novos['ativo'] = $ativo;
            $auditoria->registrar($user['id'], $acao, 'usuarios', $usuario_id, json_encode($alvo), json_encode($dados_novos));

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao atualizar usuário: ' . $e->getMessage()]);
        }
        exit;
    }

    $nome = trim($input['nome'] ?? '');
    $email = trim($input['email'] ?? '');
    $tipo = $input['tipo'] ?? '';
    $cargo = trim($input['cargo'] ?? '') ?: null;
    $username = trim($input['username'] ?? '');
    if (!array_key_exists('setor_id', $input)) {
        $setor_id = $alvo['setor_id'];
    } else {
        $setor_id = !empty($input['setor_id']) ? (int) $input['setor_id'] : null;
    }

    if (empty($nome) || empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome e email são obrigatórios']);
        exit;
    }

    // Gestor não pode promover para gestor/admin nem mudar o setor do usuário
    if ($user['tipo'] === 'gestor') {
        if (!in_array($tipo, ['operador'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Gestores não podem alterar o papel para gestor/admin']);
            exit;
        }
        $setor_id = $alvo['setor_id'];
    }

    if (empty($username)) {
        $username = gerarUsernameUnico($db, $nome);
    } elseif ($username !== $alvo['username']) {
        $checkUser = $db->prepare("SELECT id FROM usuarios WHERE username = :username AND id != :id");
        $checkUser->execute([':username' => $username, ':id' => $usuario_id]);
        if ($checkUser->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome de usuário já existe']);
            exit;
        }
    }

    try {
        $query = "UPDATE usuarios SET nome = :nome, username = :username, email = :email, tipo = :tipo, cargo = :cargo, setor_id = :setor_id WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':cargo', $cargo);
        $stmt->bindParam(':setor_id', $setor_id);
        $stmt->bindParam(':id', $usuario_id);
        $stmt->execute();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_usuario', 'usuarios', $usuario_id, json_encode($alvo), json_encode(['nome' => $nome, 'username' => $username, 'email' => $email, 'tipo' => $tipo, 'cargo' => $cargo, 'setor_id' => $setor_id]));

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar usuário']);
    }
}

// DELETE - Excluir usuário (admin apenas)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $user = AuthMiddleware::verificarAuth();
        AuthMiddleware::verificarAdmin($user);
        AuthMiddleware::verificarCSRF();

        $usuario_id = $_GET['id'] ?? null;

        if (!$usuario_id) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $path_parts = explode('/', $path);
            $usuario_id = end($path_parts);
        }

        if (!$usuario_id || !is_numeric($usuario_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'ID do usuário inválido ou não fornecido']);
            exit;
        }

        $usuario_id = intval($usuario_id);
        if ($usuario_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID do usuário inválido']);
            exit;
        }

        // Nomes de tabela são hardcoded neste array (nunca vêm de input do usuário),
        // portanto a interpolação em SELECT COUNT(*) é segura por design.
        $tabelas = [
            ['tabela' => 'tickets',               'colunas' => ['usuario_id', 'agente_id', 'fechado_por'], 'msg' => 'demandas'],
            ['tabela' => 'comentarios',            'colunas' => ['usuario_id'],                             'msg' => 'comentários'],
            ['tabela' => 'ticket_historico',       'colunas' => ['usuario_id'],                             'msg' => 'histórico'],
            ['tabela' => 'ticket_historico_status','colunas' => ['usuario_id'],                             'msg' => 'histórico de status'],
            ['tabela' => 'historico',              'colunas' => ['usuario_id'],                             'msg' => 'registros de histórico'],
        ];

        foreach ($tabelas as $t) {
            $where = implode(' OR ', array_map(fn($col) => "$col = ?", $t['colunas']));
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM {$t['tabela']} WHERE $where");
            $stmt->execute(array_fill(0, count($t['colunas']), $usuario_id));
            if ($stmt->fetch()['total'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => "Usuário possui vínculos com: {$t['msg']}"]);
                exit;
            }
        }

        // Verificar histórico de status
        $check4 = $db->prepare("SELECT COUNT(*) as total FROM ticket_historico_status WHERE usuario_id = ?");
        $check4->execute([$usuario_id]);
        if ($check4->fetch()['total'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Usuário possui histórico de alterações de status']);
            exit;
        }

        // Buscar dados do usuário antes de excluir
        $select_query = "SELECT nome, email, tipo FROM usuarios WHERE id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
        $select_stmt->execute();
        $usuario_data = $select_stmt->fetch();

        $query = "DELETE FROM usuarios WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
        $stmt->execute();

        // Registrar auditoria
        if ($usuario_data) {
            $auditoria = new AuditoriaService($db);
            $auditoria->registrar($user['id'], 'excluir_usuario', 'usuarios', $usuario_id, json_encode($usuario_data), null);
        }

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado']);
            exit;
        }

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir usuário: ' . $e->getMessage()]);
    }
}
