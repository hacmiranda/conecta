<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/senha-service.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/usuario-service.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';

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
    return UsuarioService::gerarUsernameUnico($db, $nomeCompleto);
}

/**
 * Papéis que quem está logado pode atribuir: admin (todos, menos solicitante); gestor e coordenador (operador).
 * Solicitante externo (cliente) nunca é cadastrado por aqui.
 */
function papeisPermitidos(array $user): array {
    return $user['tipo'] === 'admin'
        ? ['admin', 'coordenador_geral', 'supervisor', 'coordenador', 'gestor', 'operador']
        : ['operador'];
}

/** Todo papel de equipe, menos o administrador, tem um setor de lotação (o Registro de Atividades depende dele). */
function papelExigeSetor(string $tipo): bool {
    return $tipo !== 'admin' && $tipo !== 'cliente';
}

/**
 * Nome do coordenador que já responde pelo setor (como principal ou adicional), ou null se está livre.
 * Cada setor tem no máximo um coordenador. `$exceto` é o usuário que está sendo salvo.
 */
function coordenadorDoSetor(PDO $db, int $setorId, int $exceto = 0): ?string {
    $stmt = $db->prepare("SELECT u.nome FROM usuarios u
                          WHERE u.tipo = 'coordenador' AND u.id <> ? AND u.ativo = 1
                            AND (u.setor_id = ? OR u.id IN (SELECT usuario_id FROM usuario_setores WHERE setor_id = ?))
                          LIMIT 1");
    $stmt->execute([$exceto, $setorId, $setorId]);
    $nome = $stmt->fetchColumn();
    return $nome === false ? null : (string) $nome;
}

function exigirSetorSemCoordenador(PDO $db, int $setorId, int $usuarioId): void {
    $outro = coordenadorDoSetor($db, $setorId, $usuarioId);
    if ($outro !== null) {
        http_response_code(409);
        echo json_encode(['error' => 'Este setor já tem coordenador(a): ' . $outro . '. Cada setor tem apenas um(a).']);
        exit;
    }
}

/** Setores adicionais já gravados para o coordenador. @return int[] */
function setoresAdicionaisDe(PDO $db, int $usuarioId): array {
    $stmt = $db->prepare("SELECT setor_id FROM usuario_setores WHERE usuario_id = ? ORDER BY criado_em");
    $stmt->execute([$usuarioId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Lê e valida a lista `setores_adicionais` enviada pelo admin: ids de setores ativos, sem repetir nem
 * incluir o principal, e sem coordenador em outro lugar. Encerra com 400/409 se algo não servir.
 *
 * @return int[]
 */
function validarSetoresAdicionais(PDO $db, $valor, ?int $principal, int $usuarioId): array {
    $ids = [];
    foreach ((array) $valor as $v) {
        $id = (int) $v;
        if ($id > 0 && $id !== (int) $principal && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    $existe = $db->prepare("SELECT 1 FROM setores WHERE id = ? AND ativo = 1");
    foreach ($ids as $id) {
        $existe->execute([$id]);
        if (!$existe->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['error' => 'Setor adicional inválido ou inativo']);
            exit;
        }
        exigirSetorSemCoordenador($db, $id, $usuarioId);
    }
    return $ids;
}

/** Troca os setores adicionais do coordenador (apaga os antigos e grava os novos). */
function gravarSetoresAdicionais(PDO $db, int $usuarioId, array $ids, int $autorId): void {
    $db->prepare("DELETE FROM usuario_setores WHERE usuario_id = ?")->execute([$usuarioId]);
    $ins = $db->prepare("INSERT INTO usuario_setores (usuario_id, setor_id, criado_por) VALUES (?, ?, ?)");
    foreach ($ids as $id) {
        $ins->execute([$usuarioId, $id, $autorId]);
    }
}

/**
 * Quantos administradores ativos existem além do usuário informado.
 */
function outrosAdminsAtivos(PDO $db, int $exceto): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE tipo = 'admin' AND ativo = 1 AND id <> ?");
    $stmt->execute([$exceto]);
    return (int) $stmt->fetchColumn();
}

/**
 * Confere se quem está logado pode administrar o usuário alvo (403 se não puder):
 * admin administra todos; gestor só os operadores do próprio setor.
 */
function exigirAdministrarUsuario(array $user, array $alvo): void {
    if ($user['tipo'] === 'admin') {
        return;
    }
    if ($alvo['tipo'] !== 'operador' || !AuthMiddleware::mesmoSetor($user, $alvo['setor_id'] !== null ? (int) $alvo['setor_id'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Gestores e coordenadores só administram operadores dos próprios setores']);
        exit;
    }
}

function carregarUsuarioAlvo(PDO $db, int $id): array {
    $stmt = $db->prepare("SELECT id, nome, username, email, tipo, cargo, setor_id, ativo FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $alvo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$alvo) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }
    return $alvo;
}

// GET ?demandas_abertas=ID — quantas demandas abertas o usuário tem como responsável
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['demandas_abertas'])) {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    $alvo = carregarUsuarioAlvo($db, (int) $_GET['demandas_abertas']);
    exigirAdministrarUsuario($user, $alvo);
    echo json_encode(['total' => AcessoService::contarDemandasAbertasDoResponsavel($db, (int) $alvo['id'])]);
    exit;
}

// POST ?acao=redefinir_senha — admin/gestor define uma senha nova; o usuário troca no próximo acesso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['acao'] ?? '') === 'redefinir_senha') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);
    AuthMiddleware::verificarCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $alvo = carregarUsuarioAlvo($db, (int) ($input['usuario_id'] ?? 0));
    exigirAdministrarUsuario($user, $alvo);

    if ($alvo['tipo'] === 'cliente') {
        http_response_code(400);
        echo json_encode(['error' => 'Solicitantes externos não têm acesso ao sistema']);
        exit;
    }
    if ((int) $alvo['id'] === (int) $user['id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Para trocar a sua própria senha, use "Alterar senha"']);
        exit;
    }

    $senha = (string) ($input['senha'] ?? '');
    $erro = SenhaService::validar($senha);
    if ($erro !== null) {
        http_response_code(400);
        echo json_encode(['error' => $erro]);
        exit;
    }

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE usuarios SET senha = ?, trocar_senha = 1 WHERE id = ?")->execute([SenhaService::hash($senha), $alvo['id']]);
        $db->prepare("DELETE FROM tokens_recuperacao WHERE usuario_id = ?")->execute([$alvo['id']]);
        AuthMiddleware::encerrarTodasAsSessoes($db, (int) $alvo['id']);
        $db->commit();
        (new AuditoriaService($db))->registrar($user['id'], 'redefinir_senha_usuario', 'usuarios', $alvo['id'], null, null);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[USUARIOS REDEFINIR-SENHA] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao redefinir a senha']);
    }
    exit;
}

// GET - Listar usuários(admin vê todos / pode filtrar por setor_id; gestor só vê o próprio setor)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarGestor($user);

    try {
        $where = [];
        $params = [];

        // ?tipo=equipe|solicitantes — sem o parâmetro, comportamento atual (equipe).
        // "solicitantes" (tipo = cliente) só existe para admin: cliente nunca tem setor,
        // então gestor (restrito ao próprio setor) não os veria mesmo sem este filtro.
        $tipoLista = $_GET['tipo'] ?? 'equipe';
        if ($tipoLista === 'solicitantes') {
            if ($user['tipo'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Apenas administradores veem a lista de solicitantes']);
                exit;
            }
            $where[] = "u.tipo = 'cliente'";
        } else {
            $where[] = "u.tipo <> 'cliente'";
        }

        if ($user['tipo'] === 'admin') {
            if (isset($_GET['setor_id']) && $_GET['setor_id'] !== '') {
                $where[] = 'u.setor_id = :setor_id';
                $params[':setor_id'] = (int) $_GET['setor_id'];
            }
        } else {
            // Gestor: o próprio setor; coordenador: os seus setores (principal e adicionais)
            $where[] = AcessoService::filtroSetorSql('u.setor_id', AcessoService::setoresDoUsuario($user) ?? []);
        }

        $sql = "SELECT u.id, u.nome, u.username, u.email, u.tipo, u.cargo, u.setor_id, u.ativo, u.avatar, u.criado_em,
                       u.atendimento_externo, s.nome as setor_nome
                FROM usuarios u
                LEFT JOIN setores s ON s.id = u.setor_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY u.nome';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $lista = $stmt->fetchAll();

        // Setores adicionais dos coordenadores da lista (nome incluso, para a tela de edição)
        $coordenadores = array_map(fn($u) => (int) $u['id'], array_filter($lista, fn($u) => $u['tipo'] === 'coordenador'));
        $extrasPorUsuario = [];
        if ($coordenadores) {
            $linhas = $db->query("SELECT us.usuario_id, s.id, s.nome FROM usuario_setores us JOIN setores s ON s.id = us.setor_id
                                  WHERE us.usuario_id IN (" . implode(',', $coordenadores) . ") ORDER BY us.criado_em")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($linhas as $l) {
                $extrasPorUsuario[(int) $l['usuario_id']][] = ['id' => (int) $l['id'], 'nome' => $l['nome']];
            }
        }
        foreach ($lista as &$linha) {
            $linha['atendimento_externo'] = (int) $linha['atendimento_externo'] === 1;
            $linha['setores_adicionais'] = $extrasPorUsuario[(int) $linha['id']] ?? [];
        }
        unset($linha);
        echo json_encode($lista);
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

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $nome = HtmlSanitizer::texto($input['nome'] ?? '', 100); // texto puro (é exibido em várias telas)
    $email = strtolower(trim($input['email'] ?? ''));
    $tipo = $input['tipo'] ?? 'operador';
    $cargo = HtmlSanitizer::texto($input['cargo'] ?? '', 100) ?: null;
    $username = HtmlSanitizer::texto($input['username'] ?? '', 100);
    $setor_id = !empty($input['setor_id']) ? (int) $input['setor_id'] : null;
    $senha = (string) ($input['senha'] ?? '');

    if (empty($nome) || empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome e email são obrigatórios']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail inválido']);
        exit;
    }
    if (!in_array($tipo, papeisPermitidos($user), true)) {
        http_response_code(403);
        echo json_encode(['error' => $user['tipo'] !== 'admin' ? 'Gestores e coordenadores só podem cadastrar usuários com papel "operador"' : 'Papel inválido']);
        exit;
    }

    // Senha inicial definida por quem cadastra(mesmas regras da troca de senha)
    if ($senha === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Informe a senha inicial do usuário']);
        exit;
    }
    $erro_senha = SenhaService::validar($senha);
    if ($erro_senha !== null) {
        http_response_code(400);
        echo json_encode(['error' => $erro_senha]);
        exit;
    }

    // Gestor só cria operadores no próprio setor; coordenador, nos seus setores (padrão: o principal)
    if (AcessoService::ehGestao($user)) {
        if ($tipo !== 'operador') {
            http_response_code(403);
            echo json_encode(['error' => 'Gestores e coordenadores só podem cadastrar usuários com papel "operador"']);
            exit;
        }
        if ($user['tipo'] === 'coordenador' && $setor_id !== null && AcessoService::temAcessoAoSetor($user, $setor_id)) {
            // mantém o setor escolhido, que é um dos dele
        } else {
            $setor_id = $user['setor_id'];
        }
    }
    if ($tipo === 'admin') {
        $setor_id = null; // administrador não pertence a setor
    }

    if (papelExigeSetor($tipo) && empty($setor_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Informe o setor de lotação (obrigatório para todos os papéis, menos administrador)']);
        exit;
    }

    // Coordenador: um por setor; setores adicionais só o admin define
    $setores_adicionais = [];
    if ($tipo === 'coordenador') {
        exigirSetorSemCoordenador($db, (int) $setor_id, 0);
        if ($user['tipo'] === 'admin') {
            $setores_adicionais = validarSetoresAdicionais($db, $input['setores_adicionais'] ?? [], (int) $setor_id, 0);
        }
    }
    $atendimento_externo = ($user['tipo'] === 'admin' && !empty($input['atendimento_externo'])) ? 1 : 0;

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

        $senha_hash = SenhaService::hash($senha);

        // trocar_senha = 1: a senha inicial é trocada pelo próprio usuário no primeiro acesso
        $db->beginTransaction();
        $query = "INSERT INTO usuarios (nome, username, email, tipo, cargo, setor_id, atendimento_externo, senha, trocar_senha)
                  VALUES (:nome, :username, :email, :tipo, :cargo, :setor_id, :atendimento_externo, :senha, 1)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':cargo', $cargo);
        $stmt->bindParam(':setor_id', $setor_id);
        $stmt->bindParam(':atendimento_externo', $atendimento_externo);
        $stmt->bindParam(':senha', $senha_hash);
        $stmt->execute();

        $usuario_id = $db->lastInsertId();
        if ($setores_adicionais) {
            gravarSetoresAdicionais($db, (int) $usuario_id, $setores_adicionais, (int) $user['id']);
        }
        $db->commit();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'criar_usuario', 'usuarios', $usuario_id, null, json_encode(['nome' => $nome, 'email' => $email, 'tipo' => $tipo, 'setor_id' => $setor_id, 'setores_adicionais' => $setores_adicionais, 'atendimento_externo' => $atendimento_externo]));

        $select_query = "SELECT id, nome, username, email, tipo, cargo, setor_id, avatar, criado_em FROM usuarios WHERE id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $usuario_id);
        $select_stmt->execute();
        echo json_encode($select_stmt->fetch());
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[USUARIOS POST] Erro: ' . $e->getMessage());
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
    $alvo_stmt = $db->prepare("SELECT nome, username, email, tipo, cargo, setor_id, ativo, atendimento_externo FROM usuarios WHERE id = ?");
    $alvo_stmt->execute([$usuario_id]);
    $alvo = $alvo_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$alvo) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }

    if (AcessoService::ehGestao($user) && !AuthMiddleware::mesmoSetor($user, $alvo['setor_id'] !== null ? (int) $alvo['setor_id'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você só pode editar usuários dos próprios setores']);
        exit;
    }

    // Gestor e coordenador administram apenas operadores: não editam (nem rebaixam) gestores, coordenadores, admins ou a si mesmos
    if (AcessoService::ehGestao($user) && $alvo['tipo'] !== 'operador') {
        http_response_code(403);
        echo json_encode(['error' => 'Gestores e coordenadores só podem editar usuários com papel de operador']);
        exit;
    }

    // Ativar/Desativar usuário
    if (isset($input['ativo'])) {
        $ativo = $input['ativo'] ? 1 : 0;

        if (!$ativo && $usuario_id === (int) $user['id']) {
            http_response_code(400);
            echo json_encode(['error' => 'Você não pode desativar o próprio usuário']);
            exit;
        }
        if (!$ativo && $alvo['tipo'] === 'admin' && outrosAdminsAtivos($db, $usuario_id) === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Não é possível desativar o último administrador ativo']);
            exit;
        }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE usuarios SET ativo = ? WHERE id = ?");
            $stmt->execute([$ativo, $usuario_id]);

            // Desativado: as demandas abertas dele voltam para o setor e as sessões dele são encerradas
            $devolvidas = 0;
            if (!$ativo) {
                $devolvidas = AcessoService::devolverDemandasDoResponsavel($db, $usuario_id, (int) $user['id'], 'responsável desativado');
                AuthMiddleware::encerrarTodasAsSessoes($db, $usuario_id);
            }
            $db->commit();

            $auditoria = new AuditoriaService($db);
            $acao = $ativo ? 'ativar_usuario' : 'desativar_usuario';
            $dados_novos = $alvo;
            $dados_novos['ativo'] = $ativo;
            $dados_novos['demandas_devolvidas'] = $devolvidas;
            $auditoria->registrar($user['id'], $acao, 'usuarios', $usuario_id, json_encode($alvo), json_encode($dados_novos));

            echo json_encode(['success' => true, 'demandas_devolvidas' => $devolvidas]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[USUARIOS PUT] Erro: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao atualizar usuário']);
        }
        exit;
    }

    $nome = HtmlSanitizer::texto($input['nome'] ?? '', 100); // texto puro (é exibido em várias telas)
    $email = strtolower(trim($input['email'] ?? ''));
    $tipo = $input['tipo'] ?? '';
    $cargo = HtmlSanitizer::texto($input['cargo'] ?? '', 100) ?: null;
    $username = HtmlSanitizer::texto($input['username'] ?? '', 100);
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

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail inválido']);
        exit;
    }
    $dup = $db->prepare("SELECT 1 FROM usuarios WHERE LOWER(email) = ? AND id <> ?");
    $dup->execute([$email, $usuario_id]);
    if ($dup->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'Email já existe']);
        exit;
    }

    // Papel: só os permitidos a quem edita (solicitante externo continua como está)
    if ($alvo['tipo'] === 'cliente' && $tipo === 'cliente') {
        // edição de dados de um solicitante externo (sem mudar o papel)
    } elseif (!in_array($tipo, papeisPermitidos($user), true)) {
        http_response_code(403);
        echo json_encode(['error' => $user['tipo'] !== 'admin' ? 'Você não pode alterar o papel para um acima de operador' : 'Papel inválido']);
        exit;
    }

    // Gestor e coordenador não mudam o setor do usuário
    if (AcessoService::ehGestao($user)) {
        $setor_id = $alvo['setor_id'];
    }
    if ($tipo === 'admin') {
        $setor_id = null; // administrador não pertence a setor
    }
    if (papelExigeSetor($tipo) && empty($setor_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Informe o setor de lotação (obrigatório para todos os papéis, menos administrador)']);
        exit;
    }

    // Coordenador: um por setor (principal e adicionais). Os adicionais só o admin altera;
    // fora de um coordenador não há setores adicionais
    $extras_antes = $alvo['tipo'] === 'coordenador' ? setoresAdicionaisDe($db, $usuario_id) : [];
    $extras_depois = $extras_antes;
    if ($tipo !== 'coordenador') {
        $extras_depois = [];
    } else {
        exigirSetorSemCoordenador($db, (int) $setor_id, $usuario_id);
        if ($user['tipo'] === 'admin' && array_key_exists('setores_adicionais', $input)) {
            $extras_depois = validarSetoresAdicionais($db, $input['setores_adicionais'], (int) $setor_id, $usuario_id);
        } else {
            // O principal mudou para um setor que já era adicional: ele deixa de ser adicional
            $extras_depois = array_values(array_diff($extras_depois, [(int) $setor_id]));
        }
    }
    $atendimento_externo = (int) ($alvo['atendimento_externo'] ?? 0);
    if ($user['tipo'] === 'admin' && array_key_exists('atendimento_externo', $input)) {
        $atendimento_externo = !empty($input['atendimento_externo']) ? 1 : 0;
    }
    if ($alvo['tipo'] === 'admin' && $tipo !== 'admin' && outrosAdminsAtivos($db, $usuario_id) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Não é possível retirar o papel do último administrador ativo']);
        exit;
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
        $db->beginTransaction();
        $query = "UPDATE usuarios SET nome = :nome, username = :username, email = :email, tipo = :tipo, cargo = :cargo, setor_id = :setor_id, atendimento_externo = :atendimento_externo WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':cargo', $cargo);
        $stmt->bindParam(':setor_id', $setor_id);
        $stmt->bindParam(':atendimento_externo', $atendimento_externo);
        $stmt->bindParam(':id', $usuario_id);
        $stmt->execute();

        // Setores adicionais do coordenador (só muda se o conjunto mudou)
        sort($extras_antes);
        $extras_novos = $extras_depois;
        sort($extras_novos);
        if ($extras_antes !== $extras_novos) {
            gravarSetoresAdicionais($db, $usuario_id, $extras_depois, (int) $user['id']);
        }

        // As demandas abertas dele nos setores que perdeu voltam para o setor. Quem deixou de poder
        // atender (virou admin, supervisor ou coordenador geral) devolve todas.
        $devolvidas = 0;
        $setores_antes = array_values(array_unique(array_filter(array_merge(
            [(int) $alvo['setor_id']], $alvo['tipo'] === 'coordenador' ? $extras_antes : []))));
        $setores_depois = array_values(array_unique(array_filter(array_merge(
            [(int) $setor_id], $tipo === 'coordenador' ? $extras_depois : []))));
        if (!in_array($tipo, AcessoService::PAPEIS_ATENDEM, true)) {
            if ($setores_antes) {
                $devolvidas = AcessoService::devolverDemandasDoResponsavel($db, $usuario_id, (int) $user['id'], 'responsável mudou de setor');
            }
        } else {
            foreach (array_diff($setores_antes, $setores_depois) as $setor_perdido) {
                $devolvidas += AcessoService::devolverDemandasDoResponsavel($db, $usuario_id, (int) $user['id'], 'responsável mudou de setor', (int) $setor_perdido);
            }
        }
        $db->commit();

        $auditoria = new AuditoriaService($db);
        $auditoria->registrar($user['id'], 'atualizar_usuario','usuarios', $usuario_id, json_encode($alvo), json_encode(['nome' => $nome, 'username' => $username, 'email' => $email, 'tipo' => $tipo, 'cargo' => $cargo, 'setor_id' => $setor_id, 'atendimento_externo' => $atendimento_externo]));
        if ($extras_antes !== $extras_novos) {
            $auditoria->registrar($user['id'], 'alterar_setores_coordenador', 'usuarios', $usuario_id, json_encode($extras_antes), json_encode($extras_novos));
        }

        echo json_encode(['success' => true, 'demandas_devolvidas' => $devolvidas]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[USUARIOS PUT] Erro: ' . $e->getMessage());
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
        if ($usuario_id === (int) $user['id']) {
            http_response_code(400);
            echo json_encode(['error' => 'Você não pode excluir o próprio usuário']);
            exit;
        }
        $tipoAlvo = $db->prepare("SELECT tipo FROM usuarios WHERE id = ?");
        $tipoAlvo->execute([$usuario_id]);
        if ($tipoAlvo->fetchColumn() === 'admin' && outrosAdminsAtivos($db, $usuario_id) === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Não é possível excluir o último administrador ativo']);
            exit;
        }

        // Nomes de tabela são hardcoded neste array (nunca vêm de input do usuário),
        // portanto a interpolação em SELECT COUNT(*) é segura por design.
        $tabelas = [
            ['tabela' => 'tickets',               'colunas' => ['usuario_id', 'agente_id', 'fechado_por'], 'msg' => 'demandas'],
            ['tabela' => 'comentarios',            'colunas' => ['usuario_id'],                             'msg' => 'comentários'],
            ['tabela' => 'ticket_historico',       'colunas' => ['usuario_id'],                             'msg' => 'histórico'],
            ['tabela' => 'ticket_agente_log',      'colunas' => ['usuario_id'],                             'msg' => 'atendimento de demandas'],
            // Chave estrangeira RESTRICT: sem esta linha, a exclusão dava 500 genérico (auditoria 01/11/2026, BUG-19)
            ['tabela' => 'registros_atividades',   'colunas' => ['usuario_id'],                             'msg' => 'registro de atividades'],
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
        error_log('[USUARIOS DELETE] Erro: ' . $e->getMessage());
        echo json_encode(['error' => 'Erro ao excluir usuário']);
    }
}
