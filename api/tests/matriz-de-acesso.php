<?php
/**
 * Matriz de acesso por papel (P4 da auditoria) — roda contra a API local de verdade
 * (não troca mocks por regras reais). Cria usuários e demandas temporários (prefixo
 * "zz_matriz_"), bate nos endpoints como cada papel bateria e confere o código HTTP
 * esperado, depois apaga tudo o que criou.
 *
 * Rodar antes de cada implantação:
 *   php api/tests/matriz-de-acesso.php
 *
 * Só funciona em CLI e recusa rodar com APP_ENV=production — é pensado para o banco
 * local de desenvolvimento, nunca contra dados reais.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/senha-service.php';

if (Environment::isProduction()) {
    fwrite(STDERR, "Recusado: APP_ENV indica produção. Este script só roda em ambiente local.\n");
    exit(1);
}

$baseUrl = rtrim((string) ($argv[1] ?? 'http://localhost/conecta/'), '/') . '/api/controllers/';

$db = (new Database())->getConnection();

$ok = 0;
$falhas = [];

function verifica(string $nome, bool $condicao, string $detalhe = ''): void {
    global $ok, $falhas;
    if ($condicao) {
        $ok++;
        echo "  OK  $nome\n";
    } else {
        $falhas[] = $nome;
        echo "  FALHOU  $nome" . ($detalhe !== '' ? "  ($detalhe)" : '') . "\n";
    }
}

/** Requisição HTTP de verdade (não chama funções PHP direto: testa a API tal como o navegador a usa). */
function req(string $metodo, string $url, ?array $tokenCsrf = null, ?array $corpo = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($tokenCsrf) {
        $headers[] = 'Authorization: Bearer ' . $tokenCsrf[0];
        $headers[] = 'X-CSRF-Token: ' . $tokenCsrf[1];
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    }
    $resposta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, json_decode((string) $resposta, true)];
}

/**
 * Requisição como o navegador faz: sessão no cookie conecta_sessao (sem Bearer) e X-CSRF-Token.
 * Devolve [código, json, valor do cookie conecta_sessao gravado na resposta (ou null)].
 */
function reqCookie(string $metodo, string $url, ?string $cookie, ?string $csrf = null, ?array $corpo = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($cookie !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, AuthMiddleware::COOKIE_SESSAO . '=' . $cookie);
    }
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    }
    $resposta = (string) curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tamanhoCabecalho = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $cabecalhos = substr($resposta, 0, $tamanhoCabecalho);
    $novoCookie = null;
    if (preg_match('/^Set-Cookie:\s*' . AuthMiddleware::COOKIE_SESSAO . '=([^;\r\n]*)([^\r\n]*)/mi', $cabecalhos, $m)) {
        $novoCookie = ['valor' => $m[1], 'atributos' => $m[2]];
    }
    return [$codigo, json_decode(substr($resposta, $tamanhoCabecalho), true), $novoCookie];
}

/** Token assinado com a chave real, com o "exp" que o teste quiser (null = sem exp, como os tokens antigos). */
function tokenComValidade(PDO $db, int $id, ?int $exp): string {
    $u = $db->query('SELECT * FROM usuarios WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    $payload = ['id' => $u['id'], 'email' => $u['email'], 'tipo' => $u['tipo'], 'setor_id' => $u['setor_id'],
                'tv' => (int) $u['token_versao'], 'jti' => bin2hex(random_bytes(16)), 'csrf' => bin2hex(random_bytes(16)), 'iat' => time()];
    if ($exp !== null) {
        $payload['exp'] = $exp;
    }
    return \Firebase\JWT\JWT::encode($payload, (string) Environment::get('jwt_secret'), 'HS256');
}

/** Token + CSRF para um usuário, sem passar pelo login.php (mais rápido e não depende do CAPTCHA). */
function tokenDe(PDO $db, int $id): array {
    $usuario = $db->query('SELECT * FROM usuarios WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    $token = AuthMiddleware::gerarToken($usuario);
    $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    return [$token, $payload['csrf']];
}

function criarUsuario(PDO $db, string $nome, string $tipo, ?int $setorId, bool $ativo = true): int {
    $db->prepare('INSERT INTO usuarios (nome, email, tipo, setor_id, senha, ativo, trocar_senha) VALUES (?, ?, ?, ?, ?, ?, 0)')
       ->execute([$nome, 'zz_matriz_' . bin2hex(random_bytes(4)) . '@teste.local', $tipo, $setorId, SenhaService::hash('Zz@Teste12345'), $ativo ? 1 : 0]);
    return (int) $db->lastInsertId();
}

function criarDemanda(PDO $db, string $titulo, int $setorId, ?int $agenteId = null): int {
    $db->prepare("INSERT INTO tickets (titulo, descricao, usuario_id, email_solicitante, status, prioridade, setor_id, agente_id)
                  VALUES (?, '<p>zz</p>', 1, 'zz_matriz@teste.local', 'novo', 'normal', ?, ?)")
       ->execute([$titulo, $setorId, $agenteId]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO ticket_sla (ticket_id) VALUES (?)')->execute([$id]);
    $db->prepare('INSERT INTO ticket_setor_log (ticket_id, setor_id) VALUES (?, ?)')->execute([$id, $setorId]);
    if ($agenteId) {
        $db->prepare('INSERT INTO ticket_agente_log (ticket_id, usuario_id) VALUES (?, ?)')->execute([$id, $agenteId]);
    }
    return $id;
}

// Dois setores reais e distintos do banco (não criamos setor — evita migrar views/relatórios que dependem deles)
$setores = $db->query("SELECT id FROM setores WHERE ativo = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($setores) < 2) {
    fwrite(STDERR, "É preciso ao menos 2 setores ativos no banco local para rodar a matriz de acesso.\n");
    exit(1);
}
[$setorA, $setorB] = $setores;

$usuariosCriados = [];
$ticketsCriados = [];

try {
    $adminId    = (int) $db->query('SELECT id FROM usuarios WHERE tipo = "admin" AND ativo = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if (!$adminId) {
        fwrite(STDERR, "É preciso ao menos um administrador ativo no banco local.\n");
        exit(1);
    }

    $gestorA    = criarUsuario($db, 'zz Gestor A', 'gestor', $setorA);
    $operadorA  = criarUsuario($db, 'zz Operador A', 'operador', $setorA);
    $operadorA2 = criarUsuario($db, 'zz Operador A2', 'operador', $setorA);
    $gestorB    = criarUsuario($db, 'zz Gestor B', 'gestor', $setorB);
    $cliente    = criarUsuario($db, 'zz Cliente', 'cliente', null);
    $inativo    = criarUsuario($db, 'zz Inativo', 'operador', $setorA, ativo: false);
    $usuariosCriados = [$gestorA, $operadorA, $operadorA2, $gestorB, $cliente, $inativo];

    $demandaA = criarDemanda($db, 'zz demanda setor A', $setorA, $operadorA);
    $demandaB = criarDemanda($db, 'zz demanda setor B', $setorB, $gestorB);
    $ticketsCriados[] = $demandaA;
    $ticketsCriados[] = $demandaB;

    $T = [
        'admin'      => tokenDe($db, $adminId),
        'gestorA'    => tokenDe($db, $gestorA),
        'gestorB'    => tokenDe($db, $gestorB),
        'operadorA'  => tokenDe($db, $operadorA),
        'operadorA2' => tokenDe($db, $operadorA2),
        'cliente'    => tokenDe($db, $cliente),
        'inativo'    => tokenDe($db, $inativo),
    ];

    echo "\n[1] Leitura da demanda (GET tickets.php?id=)\n";
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", $T['admin']);
    verifica('admin lê demanda de qualquer setor', $c === 200, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", $T['gestorA']);
    verifica('gestor do setor lê a demanda', $c === 200, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", $T['gestorB']);
    verifica('gestor de outro setor não lê (403)', $c === 403, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", $T['operadorA']);
    verifica('operador responsável lê a demanda', $c === 200, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", $T['operadorA2']);
    verifica('operador do mesmo setor mas não responsável não lê (403)', $c === 403, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", $T['cliente']);
    // cliente é recusado antes mesmo de chegar à regra de acesso da demanda (AuthMiddleware::verificarAuth): 401, não 403
    verifica('cliente nunca entra no sistema, nem para ler a própria demanda (401)', $c === 401, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA");
    verifica('sem token: 401', $c === 401, (string) $c);
    [$c] = req('GET', $baseUrl . 'tickets.php?id=999999999', $T['admin']);
    verifica('demanda inexistente: 404', $c === 404, (string) $c);

    echo "\n[2] Alteração da demanda (PUT tickets.php?id=)\n";
    [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaA", $T['gestorB'], ['prioridade' => 'alta']);
    verifica('gestor de outro setor não altera (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaA", $T['operadorA2'], ['prioridade' => 'alta']);
    verifica('operador não responsável não altera (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaA", $T['operadorA'], ['prioridade' => 'alta']);
    verifica('operador responsável altera (200)', $c === 200, (string) $c);

    echo "\n[3] Escopo por setor (?setor_id= é ignorado para quem não é admin)\n";
    [$c, $j] = req('GET', $baseUrl . "tickets.php?escopo=abertas&setor_id=$setorB", $T['gestorA']);
    $vazamento = array_filter($j ?? [], fn($t) => (int) $t['id'] === $demandaB);
    verifica('gestor não vê o setor de outro mesmo pedindo na URL', $c === 200 && count($vazamento) === 0, (string) $c);
    [$c, $j] = req('GET', $baseUrl . "tickets.php?escopo=abertas&setor_id=$setorB", $T['admin']);
    verifica('admin, ao pedir o setor B na URL, vê a demanda do setor B', $c === 200 && in_array($demandaB, array_column($j ?? [], 'id')), (string) $c);

    echo "\n[4] Lixeira (só o administrador)\n";
    [$c] = req('DELETE', $baseUrl . "tickets.php?id=$demandaA", $T['operadorA']);
    verifica('responsável envia para a lixeira (200)', $c === 200, (string) $c);
    [$c] = req('GET', $baseUrl . 'tickets.php?escopo=lixeira', $T['gestorA']);
    verifica('gestor não acessa a lixeira (403)', $c === 403, (string) $c);
    [$c, $j] = req('GET', $baseUrl . 'tickets.php?escopo=lixeira', $T['admin']);
    verifica('admin acessa a lixeira e vê a demanda', $c === 200 && in_array($demandaA, array_column($j ?? [], 'id')));
    [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaA&acao=restaurar", $T['admin'], []);
    verifica('admin restaura a demanda (200)', $c === 200, (string) $c);

    echo "\n[5] Conta desativada e token sem CSRF\n";
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", $T['inativo']);
    verifica('usuário desativado: 401 mesmo com token válido', $c === 401, (string) $c);
    [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaA", [$T['operadorA'][0], 'token-csrf-errado'], ['prioridade' => 'normal']);
    verifica('CSRF incorreto é recusado (403)', $c === 403, (string) $c);

    echo "\n[6] Log de e-mails enviados (só o administrador; tem dados de todos os setores)\n";
    [$c] = req('GET', $baseUrl . 'email-logs.php?periodo=36500', $T['operadorA']);
    verifica('operador não lê o log de e-mails (403)', $c === 403, (string) $c);
    [$c] = req('GET', $baseUrl . 'email-logs.php', $T['gestorA']);
    verifica('gestor não lê o log de e-mails (403)', $c === 403, (string) $c);
    [$c] = req('GET', $baseUrl . 'email-logs.php?periodo=36500', $T['admin']);
    verifica('admin lê o log de e-mails (200)', $c === 200, (string) $c);

    echo "\n[7] Notificações (alterar exige CSRF)\n";
    [$c] = req('PUT', $baseUrl . 'notificacoes.php', [$T['operadorA'][0], 'token-csrf-errado'], []);
    verifica('marcar como lidas com CSRF incorreto é recusado (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $baseUrl . 'notificacoes.php', $T['operadorA'], []);
    verifica('marcar como lidas com CSRF correto (200)', $c === 200, (string) $c);

    echo "\n[7b] Filtros de e-mail (admin: qualquer setor e globais; gestor: só o próprio setor; operador: nada)\n";
    $filtros = $baseUrl . 'filtros-email.php';
    $corpoFiltro = fn($setor, string $termo) => ['setor_id' => $setor, 'campo' => 'assunto', 'termo' => $termo, 'observacao' => 'zz teste'];
    [$c] = req('GET', "$filtros?setor_id=$setorA", $T['operadorA']);
    verifica('operador não lê os filtros (403)', $c === 403, (string) $c);
    [$c] = req('POST', $filtros, $T['operadorA'], $corpoFiltro($setorA, 'zz_matriz_op'));
    verifica('operador não cria filtro (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$filtros?setor_id=$setorA", $T['gestorA']);
    verifica('gestor lê os filtros do próprio setor (200)', $c === 200, (string) $c);
    [$c] = req('GET', "$filtros?setor_id=$setorB", $T['gestorA']);
    verifica('gestor não lê os filtros de outro setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$filtros?setor_id=global", $T['gestorA']);
    verifica('gestor não abre a página dos filtros globais (403)', $c === 403, (string) $c);
    [$c, $j] = req('POST', $filtros, $T['gestorA'], $corpoFiltro($setorA, 'zz_matriz_gestor'));
    $filtroGestor = (int) ($j['id'] ?? 0);
    verifica('gestor cria filtro no próprio setor (201)', $c === 201 && $filtroGestor > 0, (string) $c);
    [$c] = req('POST', $filtros, $T['gestorA'], $corpoFiltro($setorA, 'ZZ_Matriz_Gestor'));
    verifica('filtro igual (mesmo com outra caixa de letra) é recusado (409)', $c === 409, (string) $c);
    [$c] = req('POST', $filtros, $T['gestorA'], $corpoFiltro($setorB, 'zz_matriz_cruzado'));
    verifica('gestor não cria filtro em outro setor (403)', $c === 403, (string) $c);
    [$c] = req('POST', $filtros, $T['gestorA'], $corpoFiltro('global', 'zz_matriz_global'));
    verifica('gestor não cria filtro global (403)', $c === 403, (string) $c);
    [$c] = req('POST', $filtros, $T['gestorA'], $corpoFiltro($setorA, 'ab'));
    verifica('termo curto demais é recusado (400)', $c === 400, (string) $c);
    [$c] = req('POST', $filtros, ['token-sem-csrf-valido', 'csrf-errado'], $corpoFiltro($setorA, 'zz_matriz_csrf'));
    verifica('criar sem sessão/CSRF válidos é recusado (401/403)', in_array($c, [401, 403], true), (string) $c);

    [$c, $j] = req('POST', $filtros, $T['admin'], $corpoFiltro($setorB, 'zz_matriz_admin_b'));
    $filtroAdminB = (int) ($j['id'] ?? 0);
    verifica('admin cria filtro em qualquer setor (201)', $c === 201 && $filtroAdminB > 0, (string) $c);
    [$c, $j] = req('POST', $filtros, $T['admin'], $corpoFiltro('global', 'zz_matriz_global'));
    $filtroGlobal = (int) ($j['id'] ?? 0);
    verifica('admin cria filtro global (201)', $c === 201 && $filtroGlobal > 0, (string) $c);
    [$c, $j] = req('GET', "$filtros?setor_id=$setorA", $T['gestorA']);
    $globaisVistos = array_column($j['filtros_globais'] ?? [], 'id');
    verifica('gestor enxerga o filtro global (só leitura)', in_array($filtroGlobal, array_map('intval', $globaisVistos), true) && ($j['pode_editar_globais'] ?? true) === false, (string) $c);

    [$c] = req('PUT', "$filtros?id=$filtroAdminB", $T['gestorA'], ['ativo' => false]);
    verifica('gestor não altera filtro de outro setor (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$filtros?id=$filtroGlobal", $T['gestorA'], ['ativo' => false]);
    verifica('gestor não altera filtro global (403)', $c === 403, (string) $c);
    [$c] = req('DELETE', "$filtros?id=$filtroGlobal", $T['gestorA']);
    verifica('gestor não exclui filtro global (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$filtros?id=$filtroGestor", $T['gestorA'], ['ativo' => false, 'observacao' => 'zz desligado']);
    verifica('gestor liga/desliga e edita o filtro do próprio setor (200)', $c === 200, (string) $c);
    [$c, $j] = req('POST', "$filtros?acao=testar", $T['gestorA'], ['setor_id' => $setorA, 'assunto' => 'ZZ_matriz_gestor aqui']);
    verifica('teste com o filtro desligado não barra', $c === 200 && ($j['barrado'] ?? true) === false, (string) $c);
    req('PUT', "$filtros?id=$filtroGestor", $T['gestorA'], ['ativo' => true]);
    [$c, $j] = req('POST', "$filtros?acao=testar", $T['gestorA'], ['setor_id' => $setorA, 'assunto' => 'ZZ_matriz_gestor aqui']);
    verifica('teste com o filtro ligado barra e diz qual', $c === 200 && ($j['barrado'] ?? false) === true && !empty($j['filtro']['descricao']), (string) $c);
    [$c, $j] = req('POST', "$filtros?acao=testar", $T['gestorA'], ['setor_id' => $setorA, 'assunto' => 'zz_matriz_admin_b']);
    verifica('filtro de outro setor não vale no teste do setor A', $c === 200 && ($j['barrado'] ?? true) === false, (string) $c);
    [$c] = req('GET', "$filtros?acao=barrados&setor_id=$setorB", $T['gestorA']);
    verifica('gestor não vê os e-mails barrados de outro setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$filtros?acao=barrados&setor_id=$setorA", $T['gestorA']);
    verifica('gestor vê os e-mails barrados do próprio setor (200)', $c === 200, (string) $c);
    [$c] = req('DELETE', "$filtros?id=$filtroGestor", $T['gestorA']);
    verifica('gestor exclui o filtro do próprio setor (200)', $c === 200, (string) $c);
    [$c] = req('DELETE', "$filtros?id=$filtroGlobal", $T['admin']);
    verifica('admin exclui o filtro global (200)', $c === 200, (string) $c);

    echo "\n[7c] Configurações → Atividades (admin: qualquer setor; gestor: só o próprio; operador: nada)\n";
    $cfgAtiv = $baseUrl . 'atividades-config.php';
    $regrasOriginaisA = $db->query("SELECT * FROM atividades_config_setor WHERE setor_id = " . (int) $setorA)->fetch(PDO::FETCH_ASSOC) ?: null;
    [$c] = req('GET', "$cfgAtiv?setor_id=$setorA", $T['operadorA']);
    verifica('operador não lê a configuração de atividades (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$cfgAtiv?setor_id=$setorB", $T['gestorA']);
    verifica('gestor não lê a configuração de outro setor (403)', $c === 403, (string) $c);
    [$c, $j] = req('POST', "$cfgAtiv?acao=tipos", $T['gestorA'], ['setor_id' => $setorA, 'nomes' => "zz_matriz_ativ_1\nzz_matriz_ativ_2\nzz_matriz_ativ_1"]);
    verifica('gestor cria atividades no próprio setor, repetida ignorada (201)', $c === 201 && ($j['criadas'] ?? 0) === 2 && ($j['ignoradas'] ?? 0) === 1, (string) $c);
    [$c] = req('POST', "$cfgAtiv?acao=tipos", $T['gestorA'], ['setor_id' => $setorB, 'nomes' => 'zz_matriz_ativ_b']);
    verifica('gestor não cria atividade em outro setor (403)', $c === 403, (string) $c);
    [$c] = req('POST', "$cfgAtiv?acao=tipos", [$T['gestorA'][0], 'csrf-errado'], ['setor_id' => $setorA, 'nomes' => 'zz_matriz_ativ_csrf']);
    verifica('criar atividade sem CSRF correto é recusado (403)', $c === 403, (string) $c);
    [$c, $j] = req('GET', "$cfgAtiv?setor_id=$setorA", $T['gestorA']);
    $tipoMatriz = 0;
    foreach ($j['tipos'] ?? [] as $t) { if ($t['nome'] === 'zz_matriz_ativ_1') $tipoMatriz = (int) $t['id']; }
    verifica('gestor lê as atividades do próprio setor (200)', $c === 200 && $tipoMatriz > 0, (string) $c);
    [$c] = req('PUT', "$cfgAtiv?acao=tipo&id=$tipoMatriz", $T['gestorB'], ['ativo' => false]);
    verifica('gestor de outro setor não altera a atividade (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$cfgAtiv?acao=tipo&id=$tipoMatriz", $T['gestorA'], ['nome' => 'zz_matriz_ativ_2']);
    verifica('renomear para um nome que já existe no setor é recusado (409)', $c === 409, (string) $c);
    [$c] = req('PUT', "$cfgAtiv?acao=setor", $T['gestorA'], ['setor_id' => $setorA, 'modo' => 'semanal', 'prazo' => 1, 'entrega_ativa' => true, 'entrega_dias' => []]);
    verifica('entrega ligada sem nenhum dia é recusada (400)', $c === 400, (string) $c);
    [$c] = req('PUT', "$cfgAtiv?acao=setor", $T['gestorB'], ['setor_id' => $setorA, 'modo' => 'diario']);
    verifica('gestor não altera regras de outro setor (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$cfgAtiv?acao=usuario&id=$operadorA", $T['gestorB'], ['trabalho_remoto' => true, 'qtd_semana' => 2]);
    verifica('gestor não altera regras de servidor de outro setor (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$cfgAtiv?acao=usuario&id=$operadorA", $T['gestorA'], ['trabalho_remoto' => true, 'qtd_semana' => 2, 'entrega_modo' => 'personalizado', 'entrega_dias' => [1, 3]]);
    verifica('gestor define as regras de um servidor do setor (200)', $c === 200, (string) $c);
    [$c] = req('PUT', "$cfgAtiv?acao=usuario&id=$cliente", $T['admin'], ['trabalho_remoto' => true]);
    verifica('cliente não tem regras de atividade (404)', $c === 404, (string) $c);
    [$c] = req('DELETE', "$cfgAtiv?acao=tipo&id=$tipoMatriz", $T['gestorA']);
    verifica('gestor exclui atividade nunca registrada (200)', $c === 200, (string) $c);

    echo "\n[7d] Registro de Atividades (cada um só os próprios relatórios)\n";
    $reg = $baseUrl . 'registro-atividades.php';
    [, $j] = req('GET', "$cfgAtiv?setor_id=$setorA", $T['gestorA']);
    $tipoRestante = 0;
    foreach ($j['tipos'] ?? [] as $t) { if ($t['nome'] === 'zz_matriz_ativ_2') $tipoRestante = (int) $t['id']; }
    [$c] = req('GET', $reg, $T['admin']);
    verifica('admin (sem setor) não tem Registro de Atividades (403)', $c === 403, (string) $c);
    [$c, $j] = req('GET', "$reg?data=2026-01-05", $T['operadorA']);
    $regA = (int) ($j['registro']['id'] ?? 0);
    verifica('operador abre o próprio dia e o rascunho é criado (200)', $c === 200 && $regA > 0 && ($j['registro']['status'] ?? '') === 'rascunho', (string) $c);
    [$c] = req('GET', "$reg?acao=itens&id=$regA", $T['operadorA2']);
    verifica('outro operador não lê os itens do relatório (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$reg?id=$regA", $T['operadorA2'], ['itens' => [['atividade_tipo_id' => $tipoRestante, 'quantidade' => 5]]]);
    verifica('outro operador não altera o relatório (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$reg?id=$regA", [$T['operadorA'][0], 'csrf-errado'], ['itens' => []]);
    verifica('salvar sem CSRF correto é recusado (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$reg?id=$regA", $T['operadorA'], ['itens' => [['atividade_tipo_id' => $tipoRestante, 'quantidade' => 5, 'observacao' => 'zz']]]);
    verifica('operador salva o próprio rascunho (200)', $c === 200, (string) $c);
    [$c] = req('POST', "$reg?acao=finalizar&id=$regA", $T['operadorA'], ['itens' => [['atividade_tipo_id' => $tipoRestante, 'quantidade' => 5]], 'modalidade' => 'remoto']);
    verifica('operador envia o relatório (200)', $c === 200, (string) $c);
    [$c] = req('PUT', "$reg?id=$regA", $T['operadorA'], ['itens' => [['atividade_tipo_id' => $tipoRestante, 'quantidade' => 9]]]);
    verifica('relatório enviado não aceita alteração (409)', $c === 409, (string) $c);
    [$c] = req('POST', "$reg?acao=favorito", $T['operadorA'], ['atividade_tipo_id' => $tipoRestante]);
    verifica('favoritar atividade do próprio setor (200)', $c === 200, (string) $c);
    [$c] = req('POST', "$reg?acao=favorito", $T['gestorB'], ['atividade_tipo_id' => $tipoRestante]);
    verifica('favoritar atividade de outro setor é recusado (404)', $c === 404, (string) $c);
    [$c] = req('DELETE', "$cfgAtiv?acao=tipo&id=$tipoRestante", $T['gestorA']);
    verifica('atividade já registrada não pode ser excluída (409)', $c === 409, (string) $c);

    echo "\n[8] Sessão no cookie HttpOnly, com validade\n";
    $emailOperadorA = (string) $db->query("SELECT email FROM usuarios WHERE id = $operadorA")->fetchColumn();
    [$c, $j, $ck] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['email' => $emailOperadorA, 'senha' => 'Zz@Teste12345']);
    verifica('login grava o cookie de sessão (200)', $c === 200 && $ck !== null && $ck['valor'] !== '', (string) $c);
    verifica('cookie é HttpOnly e SameSite=Strict', $ck !== null && stripos($ck['atributos'], 'httponly') !== false && stripos($ck['atributos'], 'samesite=strict') !== false, $ck['atributos'] ?? '');
    verifica('login não devolve o token no corpo (só o csrf)', is_array($j) && !isset($j['token']) && !empty($j['csrf_token']));
    $cookieA = $ck['valor'] ?? '';
    $csrfA = $j['csrf_token'] ?? '';
    [$c] = reqCookie('GET', $baseUrl . "tickets.php?id=$demandaA", $cookieA);
    verifica('só com o cookie (sem Bearer) a API responde (200)', $c === 200, (string) $c);
    [$c] = reqCookie('PUT', $baseUrl . "tickets.php?id=$demandaA", $cookieA, null, ['prioridade' => 'alta']);
    verifica('com cookie mas sem X-CSRF-Token a alteração é recusada (403)', $c === 403, (string) $c);
    [$c] = reqCookie('PUT', $baseUrl . "tickets.php?id=$demandaA", $cookieA, $csrfA, ['prioridade' => 'alta']);
    verifica('com cookie e X-CSRF-Token a alteração passa (200)', $c === 200, (string) $c);

    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", [tokenComValidade($db, $operadorA, time() - 60), 'x']);
    verifica('token vencido é recusado (401)', $c === 401, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", [tokenComValidade($db, $operadorA, null), 'x']);
    verifica('token sem validade (versões antigas) é recusado (401)', $c === 401, (string) $c);
    [$c, , $ck] = reqCookie('GET', $baseUrl . "tickets.php?id=$demandaA", tokenComValidade($db, $operadorA, time() + 3600));
    verifica('sessão a menos de 2 h do fim é renovada (cookie novo)', $c === 200 && $ck !== null && $ck['valor'] !== '', (string) $c);
    [$c, , $ck] = reqCookie('GET', $baseUrl . "tickets.php?id=$demandaA", tokenComValidade($db, $operadorA, time() + 11 * 3600));
    verifica('sessão com folga não é renovada a cada requisição', $c === 200 && $ck === null, (string) $c);

    [$c, , $ck] = reqCookie('POST', $baseUrl . 'logout.php', $cookieA, $csrfA, []);
    // O PHP grava "deleted" com data no passado (Max-Age=0) quando o valor é vazio: o navegador apaga o cookie
    verifica('Sair apaga o cookie (200)', $c === 200 && $ck !== null && in_array($ck['valor'], ['', 'deleted'], true) && stripos($ck['atributos'], 'Max-Age=0') !== false, (string) $c . ' ' . ($ck['atributos'] ?? ''));
    [$c] = reqCookie('GET', $baseUrl . "tickets.php?id=$demandaA", $cookieA);
    verifica('depois de Sair o cookie antigo não vale mais (401)', $c === 401, (string) $c);

    $emailOperadorA2 = (string) $db->query("SELECT email FROM usuarios WHERE id = $operadorA2")->fetchColumn();
    [, $j1, $ck1] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['email' => $emailOperadorA2, 'senha' => 'Zz@Teste12345']);
    [, , $ck2] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['email' => $emailOperadorA2, 'senha' => 'Zz@Teste12345']);
    [$c] = reqCookie('POST', $baseUrl . 'logout.php', $ck1['valor'] ?? '', $j1['csrf_token'] ?? '', ['todas' => true]);
    verifica('Sair de todos os dispositivos (200)', $c === 200, (string) $c);
    [$c] = reqCookie('GET', $baseUrl . 'notificacoes.php', $ck2['valor'] ?? '');
    verifica('a outra sessão também foi encerrada (401)', $c === 401, (string) $c);
} finally {
    echo "\n[limpeza]\n";
    $idsTickets = implode(',', array_filter($ticketsCriados)) ?: '0';
    $db->exec("DELETE FROM notificacoes WHERE ticket_id IN ($idsTickets)");
    // Auditoria não tem chave estrangeira (fica mesmo se a demanda some): apaga a das demandas de teste
    $db->exec("DELETE FROM auditoria_tickets WHERE ticket_id IN ($idsTickets)");
    $db->exec("DELETE FROM auditoria WHERE tabela = 'tickets' AND registro_id IN ($idsTickets)");
    $db->exec("DELETE FROM tickets WHERE id IN ($idsTickets)"); // cascata cuida do resto
    $db->exec("DELETE FROM auditoria_login WHERE email LIKE 'zz_matriz_%'"); // logins de teste da seção [8]
    $db->exec("DELETE FROM filtros_email WHERE termo LIKE 'zz_matriz_%'"); // filtros de e-mail da seção [7b]
    $db->exec("DELETE FROM auditoria WHERE tabela = 'filtros_email' AND (dados_novos LIKE '%zz_matriz_%' OR dados_anteriores LIKE '%zz_matriz_%')");
    // Registro de Atividades (seção [7c]): registros dos usuários de teste, atividades zz_matriz_ e regras do setor A
    if ($usuariosCriados) {
        $idsUsuarios = implode(',', array_filter($usuariosCriados));
        $db->exec("DELETE FROM registros_atividades WHERE usuario_id IN ($idsUsuarios)");
        $db->exec("DELETE FROM auditoria WHERE tabela = 'registros_atividades' AND usuario_id IN ($idsUsuarios)");
        $db->exec("DELETE FROM auditoria WHERE tabela LIKE 'atividades_config_%' AND registro_id IN ($idsUsuarios)");
    }
    $db->exec("DELETE FROM atividades_tipos WHERE nome LIKE 'zz_matriz_%'");
    $db->exec("DELETE FROM auditoria WHERE tabela = 'atividades_tipos' AND (dados_novos LIKE '%zz_matriz_%' OR dados_anteriores LIKE '%zz_matriz_%')");
    if (array_key_exists('regrasOriginaisA', get_defined_vars())) {
        $db->prepare("DELETE FROM atividades_config_setor WHERE setor_id = ?")->execute([$setorA]);
        $db->exec("DELETE FROM auditoria WHERE tabela = 'atividades_config_setor' AND registro_id = " . (int) $setorA . " AND criado_em >= NOW() - INTERVAL 1 HOUR AND usuario_id IN (" . (implode(',', array_filter($usuariosCriados)) ?: '0') . ")");
        if ($regrasOriginaisA) {
            $colunas = array_keys($regrasOriginaisA);
            $db->prepare("INSERT INTO atividades_config_setor (" . implode(',', $colunas) . ") VALUES (" . implode(',', array_fill(0, count($colunas), '?')) . ")")
               ->execute(array_values($regrasOriginaisA));
        }
    }
    if ($usuariosCriados) {
        $idsUsuarios = implode(',', array_filter($usuariosCriados));
        $db->exec("DELETE FROM usuarios WHERE id IN ($idsUsuarios)");
    }
    echo "  dados de teste removidos.\n";
}

echo "\nResultado: $ok OK, " . count($falhas) . " falha(s)\n";
foreach ($falhas as $f) {
    echo "  - $f\n";
}
exit(count($falhas) > 0 ? 1 : 0);
