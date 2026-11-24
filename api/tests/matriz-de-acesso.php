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

// Dois setores reais e distintos do banco (não criamos setor — evita migrar views/relatórios que dependem deles).
// Sem coordenador: a seção [9] cadastra um coordenador de teste com o A como principal e o B como adicional, e
// cada setor tem um coordenador só (desde a importação do METRICA360, 16/11/2026, Cadastro e Cobrança têm).
$setores = $db->query("SELECT s.id FROM setores s WHERE s.ativo = 1
                         AND NOT EXISTS (SELECT 1 FROM usuario_setores us WHERE us.setor_id = s.id)
                         AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.tipo = 'coordenador' AND u.ativo = 1 AND u.setor_id = s.id)
                       ORDER BY s.id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($setores) < 2) {
    fwrite(STDERR, "É preciso ao menos 2 setores ativos e sem coordenador no banco local para rodar a matriz de acesso.\n");
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

    echo "\n[2b] Fila do setor: operador lê a demanda sem responsável e assume (17/11/2026)\n";
    $demandaFila = criarDemanda($db, 'zz demanda da fila do setor A', $setorA, null);
    $ticketsCriados[] = $demandaFila;
    [$c, $j] = req('GET', $baseUrl . "tickets.php?id=$demandaFila", $T['operadorA2']);
    verifica('operador do setor lê a demanda sem responsável (200, fila e pode assumir)',
        $c === 200 && ($j['fila_do_setor'] ?? false) === true && ($j['pode_assumir'] ?? false) === true, (string) $c);
    [$c, $j] = req('GET', $baseUrl . 'tickets.php?escopo=abertas', $T['operadorA2']);
    verifica('a demanda da fila aparece na lista do operador', $c === 200 && in_array($demandaFila, array_map('intval', array_column($j ?? [], 'id')), true), (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaFila", $T['gestorB']);
    verifica('gestor de outro setor não lê a demanda da fila (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaFila", $T['operadorA2'], ['prioridade' => 'alta']);
    verifica('operador não altera a demanda da fila antes de assumir (403)', $c === 403, (string) $c);
    [$c] = req('POST', $baseUrl . 'comentarios.php', $T['operadorA2'], ['ticket_id' => $demandaFila, 'comentario' => '<p>zz</p>']);
    verifica('operador não comenta a demanda da fila antes de assumir (403)', $c === 403, (string) $c);
    [$c] = req('POST', $baseUrl . 'demanda-acoes.php', $T['gestorB'], ['ticket_id' => $demandaFila, 'acao' => 'assumir']);
    verifica('gestor de outro setor não assume (403)', $c === 403, (string) $c);
    [$c] = req('POST', $baseUrl . 'demanda-acoes.php', $T['operadorA2'], ['ticket_id' => $demandaFila, 'acao' => 'assumir']);
    $resp = (int) $db->query("SELECT COALESCE(agente_id, 0) FROM tickets WHERE id = $demandaFila")->fetchColumn();
    verifica('operador assume a demanda sem comentário (200) e vira o responsável', $c === 200 && $resp === $operadorA2, (string) $c);
    [$c] = req('POST', $baseUrl . 'demanda-acoes.php', $T['operadorA'], ['ticket_id' => $demandaFila, 'acao' => 'assumir']);
    verifica('outro operador não assume depois (409)', $c === 409, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaFila", $T['operadorA']);
    verifica('assumida, ela sai da fila dos colegas (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaFila", $T['operadorA2'], ['prioridade' => 'alta']);
    verifica('quem assumiu altera (200)', $c === 200, (string) $c);

    echo "\n[2c] Tempo trabalhado na demanda (17/11/2026)\n";
    $tempos = $baseUrl . 'demanda-tempos.php';
    $hoje = date('Y-m-d');
    [$c, $j] = req('POST', $tempos, $T['operadorA'], ['ticket_id' => $demandaA, 'data_trabalho' => $hoje, 'hora_inicio' => '08:00', 'hora_fim' => '09:30']);
    $lancA = (int) ($j['itens'][0]['id'] ?? 0);
    verifica('responsável lança tempo (201) e o total soma 90 min', $c === 201 && ($j['total_minutos'] ?? 0) === 90, (string) $c);
    [$c] = req('POST', $tempos, $T['operadorA2'], ['ticket_id' => $demandaA, 'data_trabalho' => $hoje, 'duracao' => '1:00']);
    verifica('quem nunca atendeu a demanda não lança (403)', $c === 403, (string) $c);
    [$c] = req('POST', $tempos, $T['operadorA'], ['ticket_id' => $demandaA, 'data_trabalho' => $hoje, 'hora_inicio' => '10:00', 'hora_fim' => '09:00']);
    verifica('fim antes do início é recusado (400)', $c === 400, (string) $c);
    [$c] = req('PUT', $tempos . '?id=' . $lancA, $T['operadorA2'], ['data_trabalho' => $hoje, 'duracao' => '2:00']);
    verifica('colega não altera o lançamento de outro (403)', $c === 403, (string) $c);
    [$c, $j] = req('PUT', $tempos . '?id=' . $lancA, $T['gestorA'], ['data_trabalho' => $hoje, 'duracao' => '2:00']);
    verifica('gestão do setor corrige o lançamento (200)', $c === 200 && ($j['total_minutos'] ?? 0) === 120, (string) $c);
    [$c] = req('GET', $tempos . '?ticket_id=' . $demandaA, $T['gestorB']);
    verifica('gestor de outro setor não vê o tempo (403)', $c === 403, (string) $c);
    [$c, $j] = req('GET', $tempos . '?ticket_id=' . $demandaA, $T['operadorA']);
    verifica('responsável vê os lançamentos e pode lançar', $c === 200 && ($j['pode_lancar'] ?? false) === true && count($j['itens'] ?? []) === 1, (string) $c);
    [$c] = req('DELETE', $tempos . '?id=' . $lancA, $T['operadorA']);
    verifica('autor exclui o lançamento (200)', $c === 200, (string) $c);

    echo "\n[2d] Dashboard: E-mails Enviados (19/11/2026)\n";
    $emailsDash = $baseUrl . 'dashboard-emails.php';
    [$c] = req('GET', $emailsDash, $T['cliente']);
    verifica('solicitante não vê os e-mails enviados (401/403)', in_array($c, [401, 403], true), (string) $c);
    [$c, $j] = req('GET', $emailsDash, $T['operadorA']);
    verifica('operador vê só os próprios números, sem a tabela por setor',
        $c === 200 && ($j['mostra_setores'] ?? true) === false && ($j['setores'] ?? null) === []
        && array_column($j['funcionarios'] ?? [], 'usuario_id') === [$operadorA], (string) $c);
    [$c, $j] = req('GET', $emailsDash . "?setor_id=$setorB", $T['gestorA']);
    verifica('gestor fica no próprio setor mesmo pedindo outro', $c === 200 && array_column($j['setores'] ?? [], 'setor_id') === [$setorA], (string) $c);
    [$c, $j] = req('GET', $emailsDash . "?setor_id=$setorB", $T['admin']);
    verifica('admin escolhe o setor pelo seletor', $c === 200 && array_column($j['setores'] ?? [], 'setor_id') === [$setorB], (string) $c);
    [$c] = req('GET', $emailsDash . '?inicio=2026-11-10&fim=2026-11-01', $T['admin']);
    verifica('período invertido é recusado (400)', $c === 400, (string) $c);

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

    echo "\n[7f] Acompanhamento das atividades (admin: todos; gestor: só o próprio setor; operador: nada)\n";
    $acomp = $baseUrl . 'atividades-acompanhamento.php';
    [$c] = req('GET', "$acomp?acao=painel", $T['operadorA']);
    verifica('operador não abre o Painel de Atividades (403)', $c === 403, (string) $c);
    [$c, $j] = req('GET', "$acomp?acao=painel&setor_id=$setorB", $T['gestorA']);
    $setoresVistos = array_column($j['setores'] ?? [], 'id');
    verifica('gestor só vê o próprio setor, mesmo pedindo outro (200)', $c === 200 && !in_array((int) $setorB, array_map('intval', $setoresVistos), true), (string) $c);
    [$c] = req('GET', "$acomp?acao=relatorio&usuario_id=$gestorB", $T['gestorA']);
    verifica('gestor não filtra o relatório por servidor de outro setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$acomp?acao=detalhe&id=$regA", $T['gestorB']);
    verifica('gestor de outro setor não abre o relatório enviado (404)', $c === 404, (string) $c);
    [$c, $j] = req('GET', "$acomp?acao=detalhe&id=$regA", $T['gestorA']);
    verifica('gestor do setor abre o relatório enviado com os itens (200)', $c === 200 && count($j['itens'] ?? []) === 1, (string) $c);
    [$c] = req('POST', "$acomp?acao=abonar", $T['gestorB'], ['usuario_id' => $operadorA, 'data' => '2026-01-07', 'tipo' => 'entrega', 'justificativa' => 'zz_matriz abono']);
    verifica('gestor de outro setor não abona (403)', $c === 403, (string) $c);
    [$c] = req('POST', "$acomp?acao=abonar", $T['gestorA'], ['usuario_id' => $operadorA, 'data' => '2026-01-07', 'tipo' => 'entrega', 'justificativa' => 'zz']);
    verifica('justificativa curta é recusada (400)', $c === 400, (string) $c);
    [$c] = req('POST', "$acomp?acao=abonar", $T['gestorA'], ['usuario_id' => $operadorA, 'data' => '2026-01-05', 'tipo' => 'entrega', 'justificativa' => 'zz_matriz abono']);
    verifica('dia já enviado não é abonado (409)', $c === 409, (string) $c);
    [$c] = req('POST', "$acomp?acao=abonar", $T['gestorA'], ['usuario_id' => $operadorA, 'data' => '2026-01-07', 'tipo' => 'entrega', 'justificativa' => 'zz_matriz abono']);
    $abonoMatriz = (int) $db->query("SELECT id FROM atividades_abonos WHERE usuario_id = " . (int) $operadorA . " AND data_referencia = '2026-01-07'")->fetchColumn();
    verifica('gestor do setor abona a pendência (200)', $c === 200 && $abonoMatriz > 0, (string) $c);
    [$c] = req('DELETE', "$acomp?acao=abono&id=$abonoMatriz", $T['gestorB']);
    verifica('gestor de outro setor não remove o abono (403)', $c === 403, (string) $c);
    [$c] = req('DELETE', "$acomp?acao=abono&id=$abonoMatriz", $T['gestorA']);
    verifica('gestor do setor remove o abono (200)', $c === 200, (string) $c);
    [$c] = req('GET', "$acomp?acao=cruzamento&usuario_id=$gestorB", $T['admin']);
    verifica('admin cruza os dados de um servidor de qualquer setor (200)', $c === 200, (string) $c);
    // Cruzamento de Dados (17/11/2026): gestor não vê a página, nem por servidor nem por setor
    [$c] = req('GET', "$acomp?acao=cruzamento&usuario_id=$operadorA", $T['gestorA']);
    verifica('gestor não abre o Cruzamento por servidor, nem do próprio setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$acomp?acao=cruzamento-setor&setor_cruzamento=$setorA", $T['gestorA']);
    verifica('gestor não abre o Cruzamento por setor (403)', $c === 403, (string) $c);
    [$c, $j] = req('GET', "$acomp?acao=cruzamento-setor&setor_cruzamento=$setorA&data_ini=2026-01-01&data_fim=2026-01-31", $T['admin']);
    $daMatriz = array_column($j['servidores'] ?? [], 'id');
    verifica('admin cruza o setor: soma e linha por servidor (200)', $c === 200 && ($j['setor']['id'] ?? 0) == $setorA
        && in_array((int) $operadorA, array_map('intval', $daMatriz), true) && ($j['atividades']['total_enviados'] ?? 0) >= 1, (string) $c);
    [$c] = req('GET', "$acomp?acao=csv-cruzamento-setor&setor_cruzamento=$setorB", $T['admin']);
    verifica('admin exporta o CSV do cruzamento por setor (200)', $c === 200, (string) $c);
    [$c] = req('GET', "$acomp?acao=cruzamento-setor&setor_cruzamento=999999", $T['admin']);
    verifica('setor que não existe ou está fora do escopo é recusado (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$acomp?acao=historico&data_ini=2026-01-01&data_fim=2026-12-31", $T['admin']);
    verifica('admin vê o histórico de ausências de todos (200)', $c === 200, (string) $c);
    [$c] = req('GET', "$acomp?acao=historico&data_ini=2024-01-01&data_fim=2026-12-31", $T['admin']);
    verifica('período maior que um ano é recusado (400)', $c === 400, (string) $c);

    echo "\n[7e] Meu Painel (só com atendimento externo; dados do módulo Satisfação)\n";
    $painel = $baseUrl . 'meu-painel.php';
    [$c, $j] = req('GET', "$painel?acao=acesso", $T['operadorA']);
    verifica('consulta de acesso responde (200) e sem atendimento externo não tem acesso', $c === 200 && ($j['atendimento_externo'] ?? true) === false, (string) $c);
    [$c] = req('GET', $painel, $T['operadorA']);
    verifica('sem atendimento externo o painel é recusado (403)', $c === 403, (string) $c);
    $db->exec("UPDATE usuarios SET atendimento_externo = 1 WHERE id = $operadorA");
    [$c, $j] = req('GET', "$painel?acao=acesso", $T['operadorA']);
    verifica('com atendimento externo o acesso é liberado', $c === 200 && ($j['atendimento_externo'] ?? false) === true, (string) $c);
    [$c, $j] = req('GET', $painel, $T['operadorA']);
    verifica('com atendimento externo o painel abre (200)', $c === 200 && isset($j['indicadores'], $j['historico']), (string) $c);
    [$c] = req('POST', $painel, [$T['operadorA'][0], 'csrf-errado'], ['destinatario_nome' => 'zz', 'destinatario_email' => 'zz@example.com']);
    verifica('enviar convite sem CSRF correto é recusado (403)', $c === 403, (string) $c);
    [$c] = req('POST', $painel, $T['operadorA'], ['destinatario_nome' => '', 'destinatario_email' => 'invalido']);
    verifica('convite sem nome/e-mail válido é recusado (400)', $c === 400, (string) $c);
    [$c] = req('GET', $painel);
    verifica('sem sessão: 401', $c === 401, (string) $c);
    $db->exec("UPDATE usuarios SET atendimento_externo = 0 WHERE id = $operadorA");

    echo "\n[9] Papéis de coordenação: coordenador (vários setores), supervisor e coordenador geral (só consulta)\n";
    $coordenadorId = criarUsuario($db, 'zz Coordenador', 'coordenador', $setorA);
    $supervisorId  = criarUsuario($db, 'zz Supervisor', 'supervisor', $setorA);
    $coordGeralId  = criarUsuario($db, 'zz Coordenador Geral', 'coordenador_geral', $setorA);
    $usuariosCriados = array_merge($usuariosCriados, [$coordenadorId, $supervisorId, $coordGeralId]);
    $db->prepare('INSERT INTO usuario_setores (usuario_id, setor_id) VALUES (?, ?)')->execute([$coordenadorId, $setorB]);
    $T['coordenador'] = tokenDe($db, $coordenadorId);
    $T['supervisor']  = tokenDe($db, $supervisorId);
    $T['coordGeral']  = tokenDe($db, $coordGeralId);
    $usuarios = $baseUrl . 'usuarios.php';

    foreach (['supervisor' => 'supervisor', 'coordGeral' => 'coordenador geral'] as $chave => $rotulo) {
        [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaB", $T[$chave]);
        verifica("$rotulo lê a demanda de qualquer setor (200)", $c === 200, (string) $c);
        [$c, $j] = req('GET', $baseUrl . 'tickets.php?escopo=abertas', $T[$chave]);
        $ids = array_column($j ?? [], 'id');
        verifica("$rotulo vê as demandas abertas de todos os setores", $c === 200 && in_array($demandaA, $ids) && in_array($demandaB, $ids), (string) $c);
        [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaA", $T[$chave], ['prioridade' => 'alta']);
        verifica("$rotulo não altera demanda (403)", $c === 403, (string) $c);
        [$c] = req('DELETE', $baseUrl . "tickets.php?id=$demandaA", $T[$chave]);
        verifica("$rotulo não exclui demanda (403)", $c === 403, (string) $c);
        [$c] = req('POST', $baseUrl . 'comentarios.php', $T[$chave], ['ticket_id' => $demandaB, 'comentario' => '<p>zz</p>']);
        verifica("$rotulo não comenta (403)", $c === 403, (string) $c);
        [$c] = req('POST', $baseUrl . 'demanda-acoes.php', $T[$chave], ['ticket_id' => $demandaB, 'acao' => 'atribuir', 'usuario_id' => $operadorA]);
        verifica("$rotulo não encaminha demanda (403)", $c === 403, (string) $c);
        [$c, $j] = req('GET', $baseUrl . 'tickets.php?id=' . $demandaB, $T[$chave]);
        verifica("$rotulo recebe o aviso de perfil de consulta no detalhe", $c === 200 && ($j['perfil_consulta'] ?? false) === true && ($j['somente_leitura'] ?? false) === true, (string) $c);
        [$c] = req('GET', $baseUrl . 'dashboard.php', $T[$chave]);
        verifica("$rotulo abre o dashboard (200)", $c === 200, (string) $c);
        [$c] = req('GET', $baseUrl . 'atividades-acompanhamento.php?acao=painel', $T[$chave]);
        verifica("$rotulo abre o Painel de Atividades (200)", $c === 200, (string) $c);
        [$c] = req('POST', $baseUrl . 'atividades-acompanhamento.php?acao=abonar', $T[$chave], ['usuario_id' => $operadorA, 'data' => '2026-01-07', 'tipo' => 'entrega', 'justificativa' => 'zz_matriz abono']);
        verifica("$rotulo não abona pendência (403)", $c === 403, (string) $c);
        [$c] = req('GET', $usuarios, $T[$chave]);
        verifica("$rotulo não administra usuários (403)", $c === 403, (string) $c);
        [$c] = req('GET', $baseUrl . 'tickets.php?escopo=lixeira', $T[$chave]);
        verifica("$rotulo não acessa a lixeira (403)", $c === 403, (string) $c);
        [$c] = req('GET', $baseUrl . 'registro-atividades.php?data=2026-01-06', $T[$chave]);
        // Desde 19/11/2026 só gestor e operador registram atividades
        verifica("$rotulo não registra atividades (403)", $c === 403, (string) $c);
    }
    [$c] = req('POST', $usuarios, $T['supervisor'], ['nome' => 'zz x', 'email' => 'zz_matriz_x@teste.local', 'tipo' => 'operador', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorA]);
    verifica('supervisor não cria usuário (403)', $c === 403, (string) $c);

    // Coordenador: setor principal A + adicional B
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaA", $T['coordenador']);
    verifica('coordenador lê a demanda do setor principal (200)', $c === 200, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaB", $T['coordenador']);
    verifica('coordenador lê a demanda do setor adicional (200)', $c === 200, (string) $c);
    [$c] = req('PUT', $baseUrl . "tickets.php?id=$demandaB", $T['coordenador'], ['prioridade' => 'alta']);
    verifica('coordenador altera a demanda do setor adicional (200)', $c === 200, (string) $c);
    [$c, $j] = req('GET', $baseUrl . 'tickets.php?escopo=abertas', $T['coordenador']);
    $ids = array_column($j ?? [], 'id');
    verifica('coordenador vê as demandas dos dois setores sem escolher', $c === 200 && in_array($demandaA, $ids) && in_array($demandaB, $ids), (string) $c);
    [$c, $j] = req('GET', $baseUrl . "tickets.php?escopo=abertas&setor_id=$setorB", $T['coordenador']);
    $ids = array_column($j ?? [], 'id');
    verifica('coordenador escolhe o setor B no cabeçalho e só vê o B', $c === 200 && in_array($demandaB, $ids) && !in_array($demandaA, $ids), (string) $c);
    [$c] = req('GET', $baseUrl . 'tickets.php?escopo=abertas&setor_id=999999', $T['coordenador']);
    verifica('coordenador pedindo um setor que não é dele é recusado (403)', $c === 403, (string) $c);
    [$c] = req('GET', $baseUrl . 'atividades-acompanhamento.php?acao=painel', $T['coordenador']);
    verifica('coordenador abre o Painel de Atividades (200)', $c === 200, (string) $c);
    // Cruzamento por setor: o coordenador só vê os setores dele
    $acompC = $baseUrl . 'atividades-acompanhamento.php';
    [$c, $j] = req('GET', "$acompC?acao=cruzamento-setor", $T['coordenador']);
    $setoresC = array_map('intval', array_column($j['setores'] ?? [], 'id'));
    sort($setoresC);
    $esperadosC = [(int) $setorA, (int) $setorB];
    sort($esperadosC);
    verifica('coordenador vê no Cruzamento por setor só os setores dele', $c === 200 && $setoresC === $esperadosC, (string) $c);
    [$c] = req('GET', "$acompC?acao=cruzamento-setor&setor_cruzamento=$setorB", $T['coordenador']);
    verifica('coordenador cruza o setor adicional (200)', $c === 200, (string) $c);
    $outroSetor = (int) $db->query("SELECT id FROM setores WHERE ativo = 1 AND id NOT IN (" . (int) $setorA . ',' . (int) $setorB . ") LIMIT 1")->fetchColumn();
    [$c] = req('GET', "$acompC?acao=cruzamento-setor&setor_cruzamento=$outroSetor", $T['coordenador']);
    verifica('coordenador não cruza um setor que não é dele (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$acompC?acao=cruzamento&usuario_id=$operadorA", $T['coordenador']);
    verifica('coordenador abre o Cruzamento por servidor no setor dele (200)', $c === 200, (string) $c);
    [$c, $j] = req('GET', "$acompC?acao=cruzamento-setor", $T['supervisor']);
    verifica('supervisor vê todos os setores no Cruzamento por setor (200)', $c === 200 && in_array($outroSetor, array_map('intval', array_column($j['setores'] ?? [], 'id')), true), (string) $c);
    // Desde 19/11/2026 o coordenador cadastra gestores e operadores nos setores dele (está acima dos dois)
    [$c, $j] = req('POST', $usuarios, $T['coordenador'], ['nome' => 'zz Gestor Novo', 'email' => 'zz_matriz_g@teste.local', 'tipo' => 'gestor', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorA]);
    $gestorNovo = (int) ($j['id'] ?? 0);
    if ($gestorNovo) { $usuariosCriados[] = $gestorNovo; }
    verifica('coordenador cria gestor no setor dele (200)', $c === 200 && $gestorNovo > 0, (string) $c);
    [$c] = req('POST', $usuarios, $T['coordenador'], ['nome' => 'zz Coord Novo', 'email' => 'zz_matriz_cn@teste.local', 'tipo' => 'coordenador', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorA]);
    verifica('coordenador não cria coordenador (403)', $c === 403, (string) $c);
    [$c] = req('POST', $usuarios, $T['gestorA'], ['nome' => 'zz Gestor por gestor', 'email' => 'zz_matriz_gg@teste.local', 'tipo' => 'gestor', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorA]);
    verifica('gestor não cria gestor, só operador (403)', $c === 403, (string) $c);
    [$c, $j] = req('POST', $usuarios, $T['coordenador'], ['nome' => 'zz Operador do B', 'email' => 'zz_matriz_opb@teste.local', 'tipo' => 'operador', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorB]);
    $opB = (int) ($j['id'] ?? 0);
    if ($opB) { $usuariosCriados[] = $opB; }
    verifica('coordenador cria operador no setor adicional (200)', $c === 200 && $opB > 0 && (int) ($j['setor_id'] ?? 0) === (int) $setorB, (string) $c);
    $emailGestorA = (string) $db->query("SELECT email FROM usuarios WHERE id = $gestorA")->fetchColumn();
    [$c] = req('PUT', $usuarios . '/' . $gestorA, $T['coordenador'], ['nome' => 'zz Gestor A', 'email' => $emailGestorA, 'tipo' => 'coordenador', 'setor_id' => $setorA]);
    verifica('coordenador não promove gestor a coordenador (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $usuarios . '/' . $gestorA, $T['coordenador'], ['nome' => 'zz Gestor A', 'email' => $emailGestorA, 'tipo' => 'gestor', 'setor_id' => $setorA]);
    verifica('coordenador edita um gestor do setor dele (200)', $c === 200, (string) $c);
    [$c] = req('PUT', $usuarios . '/' . $coordenadorId, $T['coordenador'], ['nome' => 'zz Coordenador', 'email' => 'zz_matriz_x@teste.local', 'tipo' => 'gestor', 'setor_id' => $setorA]);
    verifica('coordenador não edita a si mesmo pela tela de usuários (403)', $c === 403, (string) $c);

    // Prioridades da Ativação por Setor e Personalização: só o administrador (19/11/2026)
    [$c] = req('GET', $baseUrl . "setor-config.php?tipo=prioridades&setor_id=$setorA", $T['coordenador']);
    verifica('coordenador não vê a ativação de prioridades (403)', $c === 403, (string) $c);
    [$c] = req('GET', $baseUrl . "setor-config.php?tipo=prioridades&setor_id=$setorA", $T['gestorA']);
    verifica('gestor não vê a ativação de prioridades (403)', $c === 403, (string) $c);
    [$c] = req('GET', $baseUrl . "setor-config.php?tipo=status&setor_id=$setorA", $T['gestorA']);
    verifica('gestor continua vendo a ativação de status (200)', $c === 200, (string) $c);
    [$c] = req('GET', $baseUrl . "setor-config.php?tipo=prioridades&setor_id=$setorA", $T['admin']);
    verifica('admin vê a ativação de prioridades (200)', $c === 200, (string) $c);
    [$c] = req('GET', $baseUrl . 'registro-atividades.php?data=2026-01-06', $T['coordenador']);
    verifica('coordenador não registra atividades (403)', $c === 403, (string) $c);

    // Um setor tem um coordenador só (principal ou adicional)
    [$c, $j] = req('POST', $usuarios, $T['admin'], ['nome' => 'zz Coordenador 2', 'email' => 'zz_matriz_c2@teste.local', 'tipo' => 'coordenador', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorA]);
    verifica('segundo coordenador no mesmo setor principal é recusado (409)', $c === 409, (string) $c);
    [$c] = req('POST', $usuarios, $T['admin'], ['nome' => 'zz Coordenador 3', 'email' => 'zz_matriz_c3@teste.local', 'tipo' => 'coordenador', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorB]);
    verifica('coordenador num setor que já é adicional de outro é recusado (409)', $c === 409, (string) $c);
    [$c] = req('POST', $usuarios, $T['gestorA'], ['nome' => 'zz Sup', 'email' => 'zz_matriz_sup@teste.local', 'tipo' => 'supervisor', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorA]);
    verifica('gestor não cria supervisor (403)', $c === 403, (string) $c);

    // O admin libera/retira setores adicionais; ao retirar, a demanda aberta do coordenador volta ao setor
    $demandaDoCoord = criarDemanda($db, 'zz demanda do coordenador no setor B', $setorB, $coordenadorId);
    $ticketsCriados[] = $demandaDoCoord;
    [$c] = req('PUT', $usuarios . '/' . $coordenadorId, $T['admin'], ['nome' => 'zz Coordenador', 'email' => (string) $db->query("SELECT email FROM usuarios WHERE id = $coordenadorId")->fetchColumn(),
        'tipo' => 'coordenador', 'setor_id' => $setorA, 'setores_adicionais' => []]);
    verifica('admin retira o setor adicional do coordenador (200)', $c === 200, (string) $c);
    $agenteDepois = $db->query("SELECT agente_id FROM tickets WHERE id = $demandaDoCoord")->fetchColumn();
    verifica('a demanda aberta dele no setor retirado voltou para o setor', $agenteDepois === null || $agenteDepois === false, (string) $agenteDepois);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaB", $T['coordenador']);
    verifica('sem o setor adicional, o coordenador não lê mais a demanda dele (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $usuarios . '/' . $coordenadorId, $T['admin'], ['nome' => 'zz Coordenador', 'email' => (string) $db->query("SELECT email FROM usuarios WHERE id = $coordenadorId")->fetchColumn(),
        'tipo' => 'coordenador', 'setor_id' => $setorA, 'setores_adicionais' => [$setorB]]);
    verifica('admin libera de novo o setor adicional (200)', $c === 200, (string) $c);
    [$c] = req('GET', $baseUrl . "tickets.php?id=$demandaB", $T['coordenador']);
    verifica('com o setor adicional liberado, o coordenador volta a ler (200)', $c === 200, (string) $c);

    echo "\n[7g] Relatório do setor enviado pelo coordenador (23/11/2026; usa o coordenador, o supervisor e o coordenador geral da seção [9])\n";
    // Fica depois da [9] porque precisa do coordenador (setor A principal + B adicional) e dos perfis de consulta
    $cfgSetor = $baseUrl . 'atividades-config.php?acao=relatorio-setor';
    $regSetor = $baseUrl . 'registro-atividades.php';
    $acompSetor = $baseUrl . 'atividades-acompanhamento.php';
    $maxVigencia = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM atividades_relatorio_setor')->fetchColumn();
    $maxAbonoSetor = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM atividades_abonos_setor')->fetchColumn();
    $coordOutroId = criarUsuario($db, 'zz Coordenador Outro', 'coordenador', $outroSetor);
    $usuariosCriados[] = $coordOutroId;
    $T['coordOutro'] = tokenDe($db, $coordOutroId);
    $corpoLiga = ['setor_id' => $setorA, 'ligado' => true, 'periodicidade' => 'semanal'];

    // Ligar e desligar: só o admin ou o coordenador do setor
    [$c] = req('PUT', $cfgSetor, $T['gestorA'], $corpoLiga);
    verifica('gestor não liga o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $cfgSetor, $T['operadorA'], $corpoLiga);
    verifica('operador não liga o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $cfgSetor, $T['coordOutro'], $corpoLiga);
    verifica('coordenador de outro setor não liga o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $cfgSetor, $T['coordenador'], $corpoLiga);
    $vigA = $db->query("SELECT id, inicio FROM atividades_relatorio_setor WHERE setor_id = " . (int) $setorA . " AND id > $maxVigencia ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    verifica('coordenador do setor liga o relatório do setor (200)', $c === 200 && $vigA !== false, (string) $c);
    $inicioA = (string) ($vigA['inicio'] ?? date('Y-m-d'));

    // Abrir o período e salvar
    [$c] = req('GET', "$regSetor?modo=setor&setor_id=$setorA&data=$inicioA", $T['coordOutro']);
    verifica('coordenador de outro setor não abre o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$regSetor?modo=setor&setor_id=$setorA&data=$inicioA", $T['gestorA']);
    verifica('gestor não abre o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$regSetor?modo=setor&setor_id=$setorA&data=2026-01-05", $T['coordenador']);
    verifica('data fora de qualquer vigência é recusada (400)', $c === 400, (string) $c);
    [$c, $j] = req('GET', "$regSetor?modo=setor&setor_id=$setorA&data=$inicioA", $T['coordenador']);
    $regSetorA = (int) ($j['registro']['id'] ?? 0);
    verifica('coordenador abre o período do setor e o rascunho é criado (200)', $c === 200 && $regSetorA > 0 && ($j['registro']['status'] ?? '') === 'rascunho' && ($j['periodo'] ?? '') === 'semana', (string) $c);

    // Desobrigação: o servidor do setor não envia mais o relatório da data coberta
    [$c, $j] = req('GET', "$regSetor?data=$inicioA", $T['operadorA']);
    $regDiaCoberto = (int) ($j['registro']['id'] ?? 0);
    verifica('operador do setor abre a data coberta e vem marcado como desobrigado (200)', $c === 200 && $regDiaCoberto > 0 && !empty($j['desobrigado']), (string) $c);
    [$c] = req('PUT', "$regSetor?id=$regDiaCoberto", $T['operadorA'], ['itens' => [['atividade_tipo_id' => $tipoRestante, 'quantidade' => 1]], 'modalidade' => 'remoto']);
    verifica('operador desobrigado não salva o relatório da data coberta (409)', $c === 409, (string) $c);
    [$c] = req('POST', "$regSetor?acao=finalizar&id=$regDiaCoberto", $T['operadorA'], ['itens' => [['atividade_tipo_id' => $tipoRestante, 'quantidade' => 1]], 'modalidade' => 'remoto']);
    verifica('operador desobrigado não envia o relatório da data coberta (409)', $c === 409, (string) $c);

    // Perfis que nunca enviam o relatório do setor
    foreach (['supervisor' => 'supervisor', 'coordGeral' => 'coordenador geral'] as $chave => $rotulo) {
        [$c] = req('PUT', "$regSetor?modo=setor&id=$regSetorA", $T[$chave], ['itens' => []]);
        verifica("$rotulo não salva o relatório do setor (403)", $c === 403, (string) $c);
        [$c] = req('POST', "$regSetor?modo=setor&acao=finalizar&id=$regSetorA", $T[$chave], ['itens' => []]);
        verifica("$rotulo não envia o relatório do setor (403)", $c === 403, (string) $c);
    }
    [$c] = req('PUT', "$regSetor?modo=setor&id=$regSetorA", $T['gestorA'], ['itens' => []]);
    verifica('gestor não salva o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$regSetor?modo=setor&id=$regSetorA", $T['coordOutro'], ['itens' => []]);
    verifica('coordenador de outro setor não salva o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$regSetor?modo=setor&acao=itens&id=$regSetorA", $T['coordOutro']);
    verifica('coordenador de outro setor não lê os itens do relatório do setor (403)', $c === 403, (string) $c);

    // O modo setor não alcança o relatório diário de ninguém, nem de quem é do próprio setor
    [$c] = req('PUT', "$regSetor?modo=setor&id=$regA", $T['coordenador'], ['itens' => []]);
    verifica('coordenador não altera o relatório diário de um servidor pelo modo setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$regSetor?modo=setor&acao=itens&id=$regA", $T['coordenador']);
    verifica('coordenador não lê os itens do relatório diário de um servidor pelo modo setor (403)', $c === 403, (string) $c);

    // Salvar e enviar
    [$c] = req('PUT', "$regSetor?modo=setor&id=$regSetorA", $T['coordenador'], ['itens' => [['atividade_tipo_id' => $tipoRestante, 'quantidade' => 3]]]);
    verifica('coordenador salva o rascunho do setor (200)', $c === 200, (string) $c);
    [$c, $j] = req('GET', "$regSetor?modo=setor&acao=itens&id=$regSetorA", $T['coordenador']);
    verifica('coordenador lê os itens do relatório do setor (200)', $c === 200 && count($j['itens'] ?? []) === 1, (string) $c);
    [$c] = req('POST', "$regSetor?modo=setor&acao=finalizar&id=$regSetorA", $T['coordenador'], ['itens' => [['atividade_tipo_id' => $tipoRestante, 'quantidade' => 3]]]);
    verifica('coordenador envia o relatório do setor (200)', $c === 200, (string) $c);
    [$c] = req('POST', "$regSetor?modo=setor&acao=finalizar&id=$regSetorA", $T['coordenador'], ['itens' => []]);
    verifica('o mesmo relatório do setor não é enviado duas vezes (409)', $c === 409, (string) $c);
    [$c] = req('PUT', "$regSetor?modo=setor&id=$regSetorA", $T['coordenador'], ['itens' => []]);
    verifica('relatório do setor enviado não aceita alteração (409)', $c === 409, (string) $c);

    // Período parcial: vigência semanal começando numa quarta-feira (a data do período é a segunda anterior)
    $segundaPassada = (new DateTimeImmutable('monday this week'))->modify('-7 days');
    $quartaPassada = $segundaPassada->modify('+2 days')->format('Y-m-d');
    $segundaPassada = $segundaPassada->format('Y-m-d');
    $db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim, criado_por) VALUES (?, 'semanal', ?, NULL, ?)")
       ->execute([$setorB, $quartaPassada, $coordenadorId]);
    [$c, $j] = req('GET', "$regSetor?modo=setor&setor_id=$setorB&data=$segundaPassada", $T['coordenador']);
    verifica('período parcial: a data de referência (a segunda anterior à vigência) abre o período (200)',
        $c === 200 && ($j['data'] ?? '') === $segundaPassada && ($j['periodo'] ?? '') === 'semana', "$c " . ($j['data'] ?? ''));

    // Abono: admin ou o coordenador do setor, nunca gestor, e nunca de período já enviado
    $corpoAbono = fn(int $setor, string $data) => ['setor_id' => $setor, 'periodo' => 'semana', 'data' => $data, 'justificativa' => 'zz_matriz abono do setor'];
    [$c] = req('POST', "$acompSetor?acao=abonar-setor", $T['gestorB'], $corpoAbono($setorB, $segundaPassada));
    verifica('gestor não abona o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('POST', "$acompSetor?acao=abonar-setor", $T['supervisor'], $corpoAbono($setorB, $segundaPassada));
    verifica('supervisor não abona o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('POST', "$acompSetor?acao=abonar-setor", $T['coordOutro'], $corpoAbono($setorB, $segundaPassada));
    verifica('coordenador de outro setor não abona o relatório do setor (403)', $c === 403, (string) $c);
    [$c] = req('POST', "$acompSetor?acao=abonar-setor", $T['coordenador'], $corpoAbono($setorB, $segundaPassada));
    verifica('coordenador abona o relatório do próprio setor (200)', $c === 200, (string) $c);
    [$c] = req('POST', "$acompSetor?acao=abonar-setor", $T['admin'], $corpoAbono($setorB, $segundaPassada));
    verifica('admin abona o relatório de qualquer setor (200)', $c === 200, (string) $c);
    [$c] = req('POST', "$acompSetor?acao=abonar-setor", $T['coordenador'], $corpoAbono($setorA, $inicioA));
    verifica('período já enviado não é abonado (409)', $c === 409, (string) $c);

    // Desligar: a vigência fecha no fim do período corrente
    [$c] = req('PUT', $cfgSetor, $T['coordenador'], ['setor_id' => $setorA, 'ligado' => false, 'periodicidade' => 'semanal']);
    $fimA = $db->query("SELECT fim FROM atividades_relatorio_setor WHERE id = " . (int) ($vigA['id'] ?? 0))->fetchColumn();
    verifica('coordenador desliga o relatório do setor (200) e a vigência ganha um fim', $c === 200 && $fimA !== null && $fimA !== false, (string) $c);

    echo "\n[7h] SLA por setor (24/11/2026; usa o coordenador, o supervisor e o coordenador geral da seção [9])\n";
    // Guarda o estado do setor A (interruptor e prazos) para devolver no fim; a auditoria nova é apagada na limpeza
    $slaUrl = $baseUrl . 'setor-sla.php';
    $slaAtivoOriginalA = (int) $db->query("SELECT sla_ativo FROM setores WHERE id = " . (int) $setorA)->fetchColumn();
    $slaPrazosOriginaisA = $db->query("SELECT id, sla_resposta_dias, sla_resposta_minutos, sla_resolucao_dias, sla_resolucao_minutos, sla_sem_prazo
                                         FROM setor_prioridades WHERE setor_id = " . (int) $setorA)->fetchAll(PDO::FETCH_ASSOC);
    $maxAuditoriaSla = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM auditoria')->fetchColumn();
    // Parte de um setor desligado, com a prioridade "normal" sem prazo definido
    $db->prepare("UPDATE setores SET sla_ativo = 0 WHERE id = ?")->execute([$setorA]);
    $db->prepare("UPDATE setor_prioridades sp JOIN ticket_prioridades p ON p.id = sp.prioridade_id
                  SET sp.sla_sem_prazo = 0, sp.sla_resposta_dias = NULL, sp.sla_resposta_minutos = NULL,
                      sp.sla_resolucao_dias = NULL, sp.sla_resolucao_minutos = NULL
                  WHERE sp.setor_id = ? AND p.nome = 'normal'")->execute([$setorA]);

    // Leitura: gestor do setor lê (sem poder alterar); gestor de outro setor e operador, não
    [$c, $j] = req('GET', "$slaUrl?setor_id=$setorA", $T['gestorA']);
    verifica('gestor do setor lê o SLA do setor (200, sem poder alterar)', $c === 200 && ($j['pode_alterar'] ?? null) === false && ($j['pode_aplicar_modelo'] ?? null) === false && !empty($j['prioridades']), (string) $c);
    [$c] = req('GET', "$slaUrl?setor_id=$setorA", $T['gestorB']);
    verifica('gestor de outro setor não lê o SLA do setor (403)', $c === 403, (string) $c);
    [$c] = req('GET', "$slaUrl?setor_id=$setorA", $T['operadorA']);
    verifica('operador não lê o SLA do setor (403)', $c === 403, (string) $c);
    [$c, $j] = req('GET', "$slaUrl?setor_id=$setorA", $T['admin']);
    verifica('admin lê o SLA do setor e pode alterar e aplicar o modelo (200)', $c === 200 && ($j['pode_alterar'] ?? null) === true && ($j['pode_aplicar_modelo'] ?? null) === true, (string) $c);
    $prioridadesSla = $j['prioridades'] ?? [];
    [$c, $j] = req('GET', "$slaUrl?setor_id=$setorA", $T['coordenador']);
    verifica('coordenador do setor lê o SLA e pode alterar, mas não aplicar o modelo (200)', $c === 200 && ($j['pode_alterar'] ?? null) === true && ($j['pode_aplicar_modelo'] ?? null) === false, (string) $c);
    [$c] = req('GET', "$slaUrl?setor_id=$setorA", $T['supervisor']);
    verifica('supervisor lê o SLA do setor (200, só leitura)', $c === 200, (string) $c);

    // Corpo válido: 1 hora de 1ª resposta e 10 horas de resolução em toda prioridade com SLA no catálogo
    $corpoSla = function (bool $ligar) use ($prioridadesSla): array {
        $itens = [];
        foreach ($prioridadesSla as $p) {
            $itens[] = !empty($p['sem_sla_catalogo'])
                ? ['prioridade' => $p['prioridade'], 'sem_prazo' => true]
                : ['prioridade' => $p['prioridade'], 'sem_prazo' => false,
                   'resposta' => ['dias' => 0, 'horas' => 1, 'minutos' => 0], 'resolucao' => ['dias' => 0, 'horas' => 10, 'minutos' => 0]];
        }
        return ['sla_ativo' => $ligar, 'prioridades' => $itens];
    };

    // Ligar com prioridade ativa sem prazo: recusado, e nada muda
    $corpoSemNormal = $corpoSla(true);
    $corpoSemNormal['prioridades'] = array_values(array_filter($corpoSemNormal['prioridades'], fn($p) => $p['prioridade'] !== 'normal'));
    [$c, $j] = req('PUT', "$slaUrl?setor_id=$setorA", $T['admin'], $corpoSemNormal);
    $ligadoDepois = (int) $db->query("SELECT sla_ativo FROM setores WHERE id = " . (int) $setorA)->fetchColumn();
    verifica('ligar o SLA com uma prioridade ativa sem prazo é recusado (400) e o setor continua desligado', $c === 400 && $ligadoDepois === 0 && stripos((string) ($j['error'] ?? ''), 'normal') !== false, "$c " . ($j['error'] ?? ''));

    // Validação estrita do corpo
    $corpoRuim = $corpoSla(false);
    $corpoRuim['sla_ativo'] = 'true';
    [$c] = req('PUT', "$slaUrl?setor_id=$setorA", $T['admin'], $corpoRuim);
    verifica('sla_ativo em texto ("true") é recusado (400)', $c === 400, (string) $c);
    $corpoRuim = $corpoSla(false);
    foreach ($corpoRuim['prioridades'] as $i => $p) {
        if (isset($p['resposta'])) {
            $corpoRuim['prioridades'][$i]['resposta']['horas'] = 1000;
            break;
        }
    }
    [$c] = req('PUT', "$slaUrl?setor_id=$setorA", $T['admin'], $corpoRuim);
    verifica('mais de 999 horas no prazo é recusado (400)', $c === 400, (string) $c);

    // Quem pode gravar (setor desligado: só grava os prazos, sem mexer em demandas)
    foreach (['gestorA' => 'gestor', 'operadorA' => 'operador', 'supervisor' => 'supervisor', 'coordGeral' => 'coordenador geral', 'coordOutro' => 'coordenador de outro setor'] as $chave => $rotulo) {
        [$c] = req('PUT', "$slaUrl?setor_id=$setorA", $T[$chave], $corpoSla(false));
        verifica("$rotulo não altera o SLA do setor (403)", $c === 403, (string) $c);
    }
    [$c, $j] = req('PUT', "$slaUrl?setor_id=$setorA", $T['admin'], $corpoSla(false));
    $normalAdmin = array_values(array_filter($j['prioridades'] ?? [], fn($p) => $p['prioridade'] === 'normal'))[0] ?? [];
    verifica('admin grava os prazos do setor (200)', $c === 200 && ($normalAdmin['resposta']['horas'] ?? null) === 1 && ($normalAdmin['resolucao']['horas'] ?? null) === 10, (string) $c);
    [$c] = req('PUT', "$slaUrl?setor_id=$setorA", $T['coordenador'], $corpoSla(false));
    verifica('coordenador do setor grava os prazos do setor (200)', $c === 200, (string) $c);
    $auditoriaSla = (int) $db->query("SELECT COUNT(*) FROM auditoria WHERE id > $maxAuditoriaSla AND tabela = 'setores' AND registro_id = " . (int) $setorA . " AND acao = 'alterar_sla_setor'")->fetchColumn();
    verifica('a alteração de SLA fica na auditoria', $auditoriaSla >= 1, (string) $auditoriaSla);

    // Modelo Padrão CRPRS: só o admin
    [$c] = req('POST', "$slaUrl?acao=aplicar-modelo&setor_id=$setorA", $T['coordenador'], []);
    verifica('coordenador não aplica o modelo de SLA (403)', $c === 403, (string) $c);
    [$c] = req('POST', "$slaUrl?acao=aplicar-modelo&setor_id=$setorA", $T['gestorA'], []);
    verifica('gestor não aplica o modelo de SLA (403)', $c === 403, (string) $c);
    [$c, $j] = req('POST', "$slaUrl?acao=aplicar-modelo&setor_id=$setorA", $T['admin'], []);
    verifica('admin aplica o modelo de SLA (200) sem ligar o setor', $c === 200 && ($j['sla_ativo'] ?? null) === false && !empty($j['prioridades']), (string) $c);

    echo "\n[10] Módulo Satisfação (avaliações, convites, triagem, configuração e página pública)\n";
    // Convites com avaliação: um no setor A, um no setor B e um sem setor
    $conviteIds = [];
    $criarConvite = function (?int $setor, int $nota) use ($db, $operadorA, &$conviteIds): array {
        $token = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO pesquisa_convites (usuario_id, setor_id, destinatario_nome, destinatario_email, token) VALUES (?, ?, 'zz', ?, ?)")
           ->execute([$operadorA, $setor, 'zz_matriz_' . bin2hex(random_bytes(3)) . '@exemplo.com', $token]);
        $id = (int) $db->lastInsertId();
        $conviteIds[] = $id;
        $db->prepare("INSERT INTO pesquisa_avaliacoes (convite_id, nota, comentario, status) VALUES (?, ?, 'zz_matriz comentario', 'pendente')")->execute([$id, $nota]);
        return [$id, (int) $db->lastInsertId(), $token];
    };
    [, $avA] = $criarConvite($setorA, 5);
    [, $avB] = $criarConvite($setorB, 2);
    [, $avSem] = $criarConvite(null, 3);
    $pp = $baseUrl . 'pesquisa-painel.php';
    $tri = $baseUrl . 'pesquisa-triagem.php';

    foreach (['gestorA' => 'gestor', 'operadorA' => 'operador'] as $chave => $rotulo) {
        [$c] = req('GET', "$pp?acao=resumo", $T[$chave]);
        verifica("$rotulo não vê o módulo Satisfação (403)", $c === 403, (string) $c);
        [$c] = req('PUT', "$tri?id=$avA", $T[$chave], ['status' => 'resolvido']);
        verifica("$rotulo não faz triagem (403)", $c === 403, (string) $c);
    }
    [$c] = req('GET', "$pp?acao=resumo");
    verifica('sem sessão: 401', $c === 401, (string) $c);

    $idsDe = fn($j) => array_column($j['itens'] ?? [], 'id');
    foreach (['admin' => 'admin', 'supervisor' => 'supervisor', 'coordGeral' => 'coordenador geral'] as $chave => $rotulo) {
        [$c, $j] = req('GET', "$pp?acao=lista&busca=zz_matriz", $T[$chave]);
        verifica("$rotulo vê as avaliações de todos os setores", $c === 200 && in_array($avA, $idsDe($j)) && in_array($avB, $idsDe($j)) && in_array($avSem, $idsDe($j)), (string) $c);
        [$c, $j] = req('GET', "$pp?acao=lista&busca=zz_matriz&setor_id=$setorB", $T[$chave]);
        verifica("$rotulo escolhe o setor B no cabeçalho e só vê o B", $c === 200 && $idsDe($j) === [$avB], (string) $c);
        [$c] = req('GET', "$pp?acao=resumo", $T[$chave]);
        verifica("$rotulo abre o resumo (200)", $c === 200, (string) $c);
        [$c] = req('GET', $baseUrl . 'pesquisa-convites.php', $T[$chave]);
        verifica("$rotulo abre o histórico de convites (200)", $c === 200, (string) $c);
        [$c] = req('GET', $baseUrl . 'pesquisa-setores.php', $T[$chave]);
        verifica("$rotulo abre o Painel de Setores (200)", $c === 200, (string) $c);
    }
    foreach (['supervisor' => 'supervisor', 'coordGeral' => 'coordenador geral'] as $chave => $rotulo) {
        [$c] = req('PUT', "$tri?id=$avA", $T[$chave], ['status' => 'em_tratativa', 'anotacao' => 'zz_matriz nota']);
        verifica("$rotulo faz a triagem (exceção da regra de consulta) (200)", $c === 200, (string) $c);
        [$c] = req('PUT', $baseUrl . 'pesquisa-config.php?acao=regras', $T[$chave], ['quarentena_dias' => 3]);
        verifica("$rotulo não altera a configuração da pesquisa (403)", $c === 403, (string) $c);
    }
    $status = $db->query("SELECT status FROM pesquisa_avaliacoes WHERE id = $avA")->fetchColumn();
    verifica('a triagem gravou o novo status', $status === 'em_tratativa', (string) $status);
    [$c] = req('PUT', "$tri?id=$avA", $T['supervisor'], ['status' => 'inventado']);
    verifica('status inválido na triagem é recusado (400)', $c === 400, (string) $c);
    [$c] = req('PUT', "$tri?id=999999999", $T['supervisor'], ['status' => 'resolvido']);
    verifica('avaliação inexistente: 404', $c === 404, (string) $c);

    // Coordenador (setor A principal + B adicional, como na seção [9]): vê e trata os dele, não trata avaliação sem setor
    [$c, $j] = req('GET', "$pp?acao=lista&busca=zz_matriz", $T['coordenador']);
    verifica('coordenador vê as avaliações dos seus setores e não as sem setor', $c === 200 && in_array($avA, $idsDe($j)) && in_array($avB, $idsDe($j)) && !in_array($avSem, $idsDe($j)), (string) $c);
    [$c] = req('GET', "$pp?acao=lista&setor_id=999999", $T['coordenador']);
    verifica('coordenador pedindo setor que não é dele é recusado (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$tri?id=$avB", $T['coordenador'], ['status' => 'resolvido']);
    verifica('coordenador trata avaliação do setor adicional (200)', $c === 200, (string) $c);
    [$c] = req('PUT', "$tri?id=$avSem", $T['coordenador'], ['status' => 'resolvido']);
    verifica('coordenador não trata avaliação de fora dos seus setores (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$tri?id=$avA", [$T['coordenador'][0], 'csrf-errado'], ['status' => 'resolvido']);
    verifica('triagem sem CSRF correto é recusada (403)', $c === 403, (string) $c);

    // Configuração da pesquisa: só o administrador
    $cfg = $baseUrl . 'pesquisa-config.php';
    [$c] = req('GET', $cfg, $T['admin']);
    verifica('admin abre a configuração da pesquisa (200)', $c === 200, (string) $c);
    [$c] = req('GET', $cfg, $T['coordenador']);
    verifica('coordenador não abre a configuração da pesquisa (403)', $c === 403, (string) $c);
    [$c] = req('PUT', "$cfg?acao=regras", $T['admin'], ['quarentena_dias' => 0]);
    verifica('regra fora do limite é recusada (400)', $c === 400, (string) $c);
    [$c] = req('PUT', $baseUrl . 'pesquisa-setores.php', $T['coordenador'], ['setor_id' => $setorA, 'pesquisa_painel' => 0]);
    verifica('coordenador não liga/desliga setor no painel (403)', $c === 403, (string) $c);

    // Página pública: sem sessão, só o token
    $pub = $baseUrl . 'pesquisa-publica.php';
    [$c, $j] = req('GET', "$pub?t=" . str_repeat('0', 64));
    verifica('token desconhecido: situação inválida, sem dados (200)', $c === 200 && ($j['situacao'] ?? '') === 'invalido' && !isset($j['servidor']), (string) $c);
    [$c] = req('POST', $pub, null, ['token' => str_repeat('0', 64), 'nota' => 5]);
    verifica('avaliar com token desconhecido é recusado (404)', $c === 404, (string) $c);
    $tokenValido = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO pesquisa_convites (usuario_id, setor_id, destinatario_nome, destinatario_email, token) VALUES (?, ?, 'zz', ?, ?)")
       ->execute([$operadorA, $setorA, 'zz_matriz_pub@exemplo.com', $tokenValido]);
    $conviteIds[] = (int) $db->lastInsertId();
    [$c, $j] = req('GET', "$pub?t=$tokenValido");
    verifica('token válido: abre o formulário (200)', $c === 200 && ($j['situacao'] ?? '') === 'ok', (string) $c);
    [$c] = req('POST', $pub, null, ['token' => $tokenValido, 'nota' => 9]);
    verifica('nota inválida é recusada (422)', $c === 422, (string) $c);
    [$c, $j] = req('POST', $pub, null, ['token' => $tokenValido, 'nota' => 5, 'comentario' => 'zz_matriz ótimo']);
    verifica('avaliação registrada (200) com o tipo positivo', $c === 200 && ($j['tipo'] ?? '') === 'positivo', (string) $c);
    [$c] = req('POST', $pub, null, ['token' => $tokenValido, 'nota' => 1]);
    verifica('o mesmo link não vale duas vezes (409)', $c === 409, (string) $c);
    [$c, $j] = req('GET', "$pub?t=$tokenValido");
    verifica('depois de avaliar o link mostra "já avaliado"', ($j['situacao'] ?? '') === 'ja_avaliado', (string) $c);
    verifica('a avaliação não guarda IP do cidadão', count($db->query("SHOW COLUMNS FROM pesquisa_avaliacoes LIKE 'ip%'")->fetchAll()) === 0);

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

    echo "\n[8b] Login por e-mail ou nome de usuário\n";
    $usernameA2 = 'zz_matriz_' . bin2hex(random_bytes(3));
    $db->prepare('UPDATE usuarios SET username = ? WHERE id = ?')->execute([$usernameA2, $operadorA2]);
    $db->prepare('UPDATE usuarios SET username = ? WHERE id = ?')->execute([$usernameA2 . 'c', $cliente]);
    [$c, $j] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['login' => $usernameA2, 'senha' => 'Zz@Teste12345']);
    verifica('entra pelo nome de usuário (200)', $c === 200 && (int) ($j['usuario']['id'] ?? 0) === $operadorA2, (string) $c);
    [$c] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['login' => '  ' . strtoupper($usernameA2) . ' ', 'senha' => 'Zz@Teste12345']);
    verifica('nome de usuário com maiúsculas e espaços também entra (200)', $c === 200, (string) $c);
    [$c, $j] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['login' => $usernameA2, 'senha' => 'Errada@12345']);
    verifica('senha errada pelo nome de usuário (401, mesma mensagem)', $c === 401 && ($j['error'] ?? '') === 'Credenciais inválidas', (string) $c);
    [$c, $j] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['login' => 'zz_matriz_nao_existe', 'senha' => 'Zz@Teste12345']);
    verifica('nome de usuário inexistente (401, mesma mensagem)', $c === 401 && ($j['error'] ?? '') === 'Credenciais inválidas', (string) $c);
    [$c] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['login' => $usernameA2 . 'c', 'senha' => 'Zz@Teste12345']);
    verifica('solicitante não entra nem pelo nome de usuário (401)', $c === 401, (string) $c);
    [$c] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['login' => '', 'senha' => 'Zz@Teste12345']);
    verifica('sem e-mail/usuário (400)', $c === 400, (string) $c);
    $ultimoLogin = $db->prepare("SELECT email FROM auditoria_login WHERE usuario_id = ? AND acao = 'login_sucesso' ORDER BY id DESC LIMIT 1");
    $ultimoLogin->execute([$operadorA2]);
    verifica('a auditoria grava o e-mail da conta, não o nome de usuário', str_starts_with((string) $ultimoLogin->fetchColumn(), 'zz_matriz_'));
    [$c] = req('POST', $baseUrl . 'usuarios.php', $T['admin'], ['nome' => 'zz y', 'username' => 'zz@matriz', 'email' => 'zz_matriz_y@teste.local', 'tipo' => 'operador', 'senha' => 'Zz@Teste12345', 'setor_id' => $setorA]);
    verifica('cadastro recusa nome de usuário com @ (400)', $c === 400, (string) $c);
    [$c] = req('PUT', $baseUrl . 'usuarios.php/' . $operadorA2, $T['gestorA'], ['nome' => 'zz Operador A2', 'username' => '', 'email' => $emailOperadorA2, 'tipo' => 'operador', 'setor_id' => $setorA]);
    $usernameDepois = (string) $db->query("SELECT username FROM usuarios WHERE id = $operadorA2")->fetchColumn();
    verifica('editar com o nome de usuário em branco mantém o atual', $c === 200 && $usernameDepois === $usernameA2, "$c $usernameDepois");

    echo "\n[8c] Promoção de solicitante externo a usuário interno (só admin, com senha inicial)\n";
    $solicitante = criarUsuario($db, 'zz Solicitante Promovido', 'cliente', null);
    $usuariosCriados[] = $solicitante;
    $db->prepare("UPDATE usuarios SET senha = NULL WHERE id = ?")->execute([$solicitante]); // como nasce do e-mail
    $emailSol = (string) $db->query("SELECT email FROM usuarios WHERE id = $solicitante")->fetchColumn();
    $promo = ['nome' => 'zz Solicitante Promovido', 'email' => $emailSol, 'tipo' => 'operador', 'setor_id' => $setorA, 'username' => 'zz_matriz_promovido'];
    [$c] = req('PUT', $baseUrl . 'usuarios.php/' . $solicitante, $T['gestorA'], $promo + ['senha' => 'Zz@Teste12345']);
    verifica('gestor não promove solicitante (403)', $c === 403, (string) $c);
    [$c] = req('PUT', $baseUrl . 'usuarios.php/' . $solicitante, $T['admin'], $promo);
    verifica('promoção sem senha inicial é recusada (400)', $c === 400, (string) $c);
    [$c] = req('PUT', $baseUrl . 'usuarios.php/' . $solicitante, $T['admin'], $promo + ['senha' => 'fraca']);
    verifica('promoção com senha fraca é recusada (400)', $c === 400, (string) $c);
    [$c, $j] = req('PUT', $baseUrl . 'usuarios.php/' . $solicitante, $T['admin'], $promo + ['senha' => 'Zz@Teste12345']);
    $promovido = $db->query("SELECT tipo, setor_id, trocar_senha, ativo FROM usuarios WHERE id = $solicitante")->fetch(PDO::FETCH_ASSOC);
    verifica('admin promove: vira operador do setor, com troca de senha no 1º acesso',
        $c === 200 && ($j['promovido'] ?? false) === true && $promovido['tipo'] === 'operador'
        && (int) $promovido['setor_id'] === (int) $setorA && (int) $promovido['trocar_senha'] === 1 && (int) $promovido['ativo'] === 1, (string) $c);
    [$c] = reqCookie('POST', $baseUrl . 'auth.php', null, null, ['login' => 'zz_matriz_promovido', 'senha' => 'Zz@Teste12345']);
    verifica('o promovido entra com a senha inicial (200)', $c === 200, (string) $c);
} finally {
    echo "\n[limpeza]\n";
    $idsTickets = implode(',', array_filter($ticketsCriados)) ?: '0';
    // Auditoria dos lançamentos de tempo feitos pelas contas de teste (seção [2c])
    $db->exec("DELETE FROM auditoria WHERE tabela = 'ticket_tempos' AND usuario_id IN (" . (implode(',', array_filter($usuariosCriados)) ?: '0') . ")");
    $db->exec("DELETE FROM notificacoes WHERE ticket_id IN ($idsTickets)");
    // Auditoria não tem chave estrangeira (fica mesmo se a demanda some): apaga a das demandas de teste
    $db->exec("DELETE FROM auditoria_tickets WHERE ticket_id IN ($idsTickets)");
    $db->exec("DELETE FROM auditoria WHERE tabela = 'tickets' AND registro_id IN ($idsTickets)");
    $db->exec("DELETE FROM tickets WHERE id IN ($idsTickets)"); // cascata cuida do resto
    $db->exec("DELETE FROM auditoria_login WHERE email LIKE 'zz_matriz_%'"); // logins de teste da seção [8]
    // Login com nome de usuário inexistente da seção [8b]: grava só a 1ª letra mascarada ("z***")
    $db->exec("DELETE FROM auditoria_login WHERE usuario_id IS NULL AND email = 'z***' AND criado_em >= NOW() - INTERVAL 1 HOUR");
    $db->exec("DELETE FROM filtros_email WHERE termo LIKE 'zz_matriz_%'"); // filtros de e-mail da seção [7b]
    $db->exec("DELETE FROM auditoria WHERE tabela = 'filtros_email' AND (dados_novos LIKE '%zz_matriz_%' OR dados_anteriores LIKE '%zz_matriz_%')");
    // Registro de Atividades (seção [7c]): registros dos usuários de teste, atividades zz_matriz_ e regras do setor A
    if ($usuariosCriados) {
        $idsUsuarios = implode(',', array_filter($usuariosCriados));
        $db->exec("DELETE FROM registros_atividades WHERE usuario_id IN ($idsUsuarios)");
        $db->exec("DELETE FROM auditoria WHERE tabela = 'registros_atividades' AND usuario_id IN ($idsUsuarios)");
        $db->exec("DELETE FROM auditoria WHERE tabela LIKE 'atividades_config_%' AND registro_id IN ($idsUsuarios)");
        $db->exec("DELETE FROM auditoria WHERE tabela = 'atividades_abonos' AND usuario_id IN ($idsUsuarios)");
    }
    // Relatório do setor (seção [7g]): vigências, abonos e auditoria criados pela seção
    if (isset($maxVigencia, $maxAbonoSetor)) {
        $db->prepare("DELETE FROM atividades_relatorio_setor WHERE id > ? AND setor_id IN (?, ?)")->execute([$maxVigencia, $setorA, $setorB]);
        $db->prepare("DELETE FROM atividades_abonos_setor WHERE id > ? AND setor_id IN (?, ?)")->execute([$maxAbonoSetor, $setorA, $setorB]);
        $db->exec("DELETE FROM auditoria WHERE tabela IN ('atividades_relatorio_setor', 'atividades_abonos_setor') AND criado_em >= NOW() - INTERVAL 1 HOUR"
            . " AND usuario_id IN (" . (implode(',', array_filter(array_merge($usuariosCriados, [$adminId]))) ?: '0') . ")");
    }
    // SLA por setor (seção [7h]): devolve o interruptor e os prazos do setor A e apaga a auditoria da seção
    if (isset($slaPrazosOriginaisA, $slaAtivoOriginalA)) {
        $db->prepare("UPDATE setores SET sla_ativo = ? WHERE id = ?")->execute([$slaAtivoOriginalA, $setorA]);
        $volta = $db->prepare("UPDATE setor_prioridades SET sla_resposta_dias = ?, sla_resposta_minutos = ?, sla_resolucao_dias = ?, sla_resolucao_minutos = ?, sla_sem_prazo = ? WHERE id = ?");
        foreach ($slaPrazosOriginaisA as $l) {
            $volta->execute([$l['sla_resposta_dias'], $l['sla_resposta_minutos'], $l['sla_resolucao_dias'], $l['sla_resolucao_minutos'], $l['sla_sem_prazo'], $l['id']]);
        }
        $db->prepare("DELETE FROM auditoria WHERE id > ? AND tabela = 'setores' AND registro_id = ? AND acao IN ('alterar_sla_setor', 'aplicar_modelo_sla_setor')")->execute([$maxAuditoriaSla, $setorA]);
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
    if (!empty($conviteIds)) {
        $db->exec('DELETE FROM pesquisa_convites WHERE id IN (' . implode(',', array_filter($conviteIds)) . ')'); // a cascata leva as avaliações
    }
    $db->exec("DELETE FROM pesquisa_convites WHERE destinatario_email LIKE 'zz_matriz_%'");
    $db->exec("DELETE FROM auditoria WHERE tabela = 'pesquisa_avaliacoes' AND criado_em >= NOW() - INTERVAL 1 HOUR");
    if ($usuariosCriados) {
        $idsUsuarios = implode(',', array_filter($usuariosCriados));
        // Seção [9]: usuários criados/alterados pela API deixam rastro na auditoria
        $db->exec("DELETE FROM auditoria WHERE tabela = 'usuarios' AND registro_id IN ($idsUsuarios)");
        $db->exec("DELETE FROM usuarios WHERE id IN ($idsUsuarios)");
    }
    echo "  dados de teste removidos.\n";
}

echo "\nResultado: $ok OK, " . count($falhas) . " falha(s)\n";
foreach ($falhas as $f) {
    echo "  - $f\n";
}
exit(count($falhas) > 0 ? 1 : 0);
