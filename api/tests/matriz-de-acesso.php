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
