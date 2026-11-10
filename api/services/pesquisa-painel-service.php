<?php
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/pesquisa-config-service.php';

/**
 * Consultas do módulo Satisfação: painel de avaliações, histórico de convites, Painel de Setores,
 * "Meu Painel" do servidor e o cruzamento com as atividades. Portadas do FeedbackModel, ConviteAvaliacaoModel,
 * SetorModel, CategoriaModel e CruzamentoDadosModel do METRICA360.
 *
 * Todas as listas recebem o ESCOPO de setores de quem consulta (AcessoService::escopoSetores:
 * null = todos, lista = só esses; lista vazia = nada) e filtram pelo setor gravado no convite.
 */
class PesquisaPainelService {

    const STATUS_AVALIACAO = ['pendente', 'em_tratativa', 'resolvido'];

    // ── Filtros ─────────────────────────────────────────────────────────────

    /** Lê e limpa os filtros da URL (data AAAA-MM-DD, status, nota, busca, servidor). */
    public static function lerFiltros(array $get): array {
        $data = function (string $chave) use ($get): string {
            $v = trim((string) ($get[$chave] ?? ''));
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return ($d && $d->format('Y-m-d') === $v) ? $v : '';
        };
        return [
            'status'     => in_array($get['status'] ?? '', self::STATUS_AVALIACAO, true) ? $get['status'] : '',
            'nota'       => (int) ($get['nota'] ?? 0) >= 1 && (int) $get['nota'] <= 5 ? (int) $get['nota'] : 0,
            'busca'      => mb_substr(trim((string) ($get['busca'] ?? '')), 0, 100),
            'data_ini'   => $data('data_ini'),
            'data_fim'   => $data('data_fim'),
            'usuario_id' => max(0, (int) ($get['usuario_id'] ?? 0)),
            'situacao'   => in_array($get['situacao'] ?? '', ['avaliado', 'aguardando', 'expirado'], true) ? $get['situacao'] : '',
            'origem'     => in_array($get['origem'] ?? '', ['manual', 'demanda'], true) ? $get['origem'] : '',
        ];
    }

    private static function escapeLike(string $t): string {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%';
    }

    /** WHERE das avaliações (alias a = avaliação, c = convite). @return array{0:string,1:array} */
    private static function whereAvaliacoes(?array $escopo, array $f, bool $comPeriodo = true): array {
        $conds = [AcessoService::filtroSetorSql('c.setor_id', $escopo)];
        $p = [];
        if ($f['status'] !== '') { $conds[] = 'a.status = :status'; $p[':status'] = $f['status']; }
        if ($f['nota'] > 0) { $conds[] = 'a.nota = :nota'; $p[':nota'] = $f['nota']; }
        if ($f['busca'] !== '') { $conds[] = 'a.comentario LIKE :busca'; $p[':busca'] = self::escapeLike($f['busca']); }
        if ($f['usuario_id'] > 0) { $conds[] = 'c.usuario_id = :usuario'; $p[':usuario'] = $f['usuario_id']; }
        if ($comPeriodo && $f['data_ini'] !== '') { $conds[] = 'DATE(a.criado_em) >= :ini'; $p[':ini'] = $f['data_ini']; }
        if ($comPeriodo && $f['data_fim'] !== '') { $conds[] = 'DATE(a.criado_em) <= :fim'; $p[':fim'] = $f['data_fim']; }
        return [implode(' AND ', $conds), $p];
    }

    private static function executar(PDO $db, string $sql, array $params): PDOStatement {
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt;
    }

    private const DE_AVALIACOES = "FROM pesquisa_avaliacoes a
        JOIN pesquisa_convites c ON c.id = a.convite_id
        LEFT JOIN usuarios u ON u.id = c.usuario_id
        LEFT JOIN setores s ON s.id = c.setor_id";

    // ── Avaliações ──────────────────────────────────────────────────────────

    /** KPIs do painel de avaliações (sem os filtros de tela: o escopo de setores vale sempre). */
    public static function indicadores(PDO $db, ?array $escopo): array {
        [$w, $p] = self::whereAvaliacoes($escopo, ['status' => '', 'nota' => 0, 'busca' => '', 'usuario_id' => 0, 'data_ini' => '', 'data_fim' => '']);
        $m = self::executar($db, "SELECT COUNT(a.id) AS total, COALESCE(AVG(a.nota), 0) AS media,
                    SUM(a.status = 'pendente') AS pendentes, SUM(a.status = 'em_tratativa') AS tratativa, SUM(a.status = 'resolvido') AS resolvidos,
                    SUM(DATE(a.criado_em) = CURDATE()) AS hoje,
                    SUM(a.criado_em >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS total_atual,
                    COALESCE(AVG(CASE WHEN a.criado_em >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN a.nota END), 0) AS media_atual,
                    SUM(a.criado_em >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND a.criado_em < DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS total_anterior,
                    COALESCE(AVG(CASE WHEN a.criado_em >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND a.criado_em < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN a.nota END), 0) AS media_anterior
                 " . self::DE_AVALIACOES . " WHERE $w", $p)->fetch(PDO::FETCH_ASSOC);
        return [
            'total'      => (int) $m['total'],
            'media'      => round((float) $m['media'], 1),
            'pendentes'  => (int) $m['pendentes'],
            'tratativa'  => (int) $m['tratativa'],
            'resolvidos' => (int) $m['resolvidos'],
            'hoje'       => (int) $m['hoje'],
            'tendencia'  => [
                'total_atual' => (int) $m['total_atual'], 'total_anterior' => (int) $m['total_anterior'],
                'media_atual' => round((float) $m['media_atual'], 1), 'media_anterior' => round((float) $m['media_anterior'], 1),
            ],
        ];
    }

    /**
     * Série diária de nota média (zero-preenchida: dia sem avaliação volta com avg null, para o gráfico mostrar o vão).
     * Honra status/nota/busca; o período é a janela de $dias.
     */
    public static function evolucao(PDO $db, ?array $escopo, array $f, int $dias = 30): array {
        $dias = max(7, min(365, $dias));
        [$w, $p] = self::whereAvaliacoes($escopo, $f, false);
        $p[':dias'] = $dias - 1; // a série tem $dias dias, contando hoje
        $linhas = self::executar($db, "SELECT DATE(a.criado_em) AS data_dia, COUNT(a.id) AS qtd, AVG(a.nota) AS avg_nota
                 " . self::DE_AVALIACOES . " WHERE $w AND a.criado_em >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
                 GROUP BY DATE(a.criado_em) ORDER BY data_dia", $p)->fetchAll(PDO::FETCH_ASSOC);
        $porDia = array_column($linhas, null, 'data_dia');
        $serie = [];
        $cursor = (new DateTimeImmutable('today'))->modify('-' . ($dias - 1) . ' days');
        $fim = new DateTimeImmutable('today');
        while ($cursor <= $fim) {
            $d = $cursor->format('Y-m-d');
            $serie[] = isset($porDia[$d])
                ? ['data_dia' => $d, 'qtd' => (int) $porDia[$d]['qtd'], 'avg_nota' => round((float) $porDia[$d]['avg_nota'], 1)]
                : ['data_dia' => $d, 'qtd' => 0, 'avg_nota' => null];
            $cursor = $cursor->modify('+1 day');
        }
        return $serie;
    }

    /** Avaliações paginadas (20 por página), com servidor, setor e categorias marcadas. */
    public static function listarAvaliacoes(PDO $db, ?array $escopo, array $f, int $pagina, int $porPagina = 20, bool $todas = false): array {
        [$w, $p] = self::whereAvaliacoes($escopo, $f);
        $total = (int) self::executar($db, "SELECT COUNT(*) " . self::DE_AVALIACOES . " WHERE $w", $p)->fetchColumn();
        $pagina = max(1, $pagina);
        $porPagina = max(1, min(100, $porPagina));
        $limite = $todas ? ' LIMIT 50000' : ' LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
        $itens = self::executar($db, "SELECT a.id, a.nota, a.comentario, a.anotacao, a.status, a.criado_em,
                    c.protocolo, c.origem, u.nome AS servidor_nome, s.nome AS setor_nome, c.setor_id
                 " . self::DE_AVALIACOES . " WHERE $w ORDER BY a.criado_em DESC, a.id DESC" . $limite, $p)->fetchAll(PDO::FETCH_ASSOC);

        if ($itens) {
            $ids = implode(',', array_map(fn($i) => (int) $i['id'], $itens));
            $cats = $db->query("SELECT ac.avaliacao_id, pc.nome, pc.emoji FROM pesquisa_avaliacao_categorias ac
                                JOIN pesquisa_categorias pc ON pc.id = ac.categoria_id
                                WHERE ac.avaliacao_id IN ($ids) ORDER BY pc.ordem, pc.id")->fetchAll(PDO::FETCH_ASSOC);
            $porAvaliacao = [];
            foreach ($cats as $c) {
                $porAvaliacao[(int) $c['avaliacao_id']][] = ['nome' => $c['nome'], 'emoji' => $c['emoji']];
            }
            foreach ($itens as &$i) {
                $i['id'] = (int) $i['id'];
                $i['nota'] = (int) $i['nota'];
                $i['categorias'] = $porAvaliacao[$i['id']] ?? [];
            }
            unset($i);
        }
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina,
                'total_paginas' => max(1, (int) ceil($total / $porPagina))];
    }

    /** Categorias mais marcadas: elogios (nota 4 e 5) e críticas (nota até 3), no escopo. */
    public static function categorias(PDO $db, ?array $escopo): array {
        [$w, $p] = self::whereAvaliacoes($escopo, ['status' => '', 'nota' => 0, 'busca' => '', 'usuario_id' => 0, 'data_ini' => '', 'data_fim' => '']);
        $linhas = self::executar($db, "SELECT pc.id, pc.nome, pc.emoji, COUNT(*) AS total,
                    SUM(a.nota >= 4) AS elogios, SUM(a.nota <= 3) AS criticas, ROUND(AVG(a.nota), 1) AS media
                 FROM pesquisa_avaliacao_categorias ac
                 JOIN pesquisa_categorias pc ON pc.id = ac.categoria_id AND pc.ativo = 1
                 JOIN pesquisa_avaliacoes a ON a.id = ac.avaliacao_id
                 JOIN pesquisa_convites c ON c.id = a.convite_id
                 WHERE $w GROUP BY pc.id, pc.nome, pc.emoji ORDER BY total DESC", $p)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($linhas as &$l) {
            foreach (['id', 'total', 'elogios', 'criticas'] as $k) $l[$k] = (int) $l[$k];
            $l['media'] = (float) $l['media'];
        }
        unset($l);
        return $linhas;
    }

    /** Triagem: muda o status e/ou a anotação de uma avaliação. Devolve false se ela não existe. */
    public static function triar(PDO $db, int $avaliacaoId, ?string $status, ?string $anotacao): bool {
        $sets = [];
        $p = [];
        if ($status !== null) { $sets[] = 'status = ?'; $p[] = $status; }
        if ($anotacao !== null) { $sets[] = 'anotacao = ?'; $p[] = $anotacao !== '' ? $anotacao : null; }
        if (!$sets) return true;
        $p[] = $avaliacaoId;
        $db->prepare("UPDATE pesquisa_avaliacoes SET " . implode(', ', $sets) . " WHERE id = ?")->execute($p);
        return true;
    }

    /** Setor da avaliação (do convite), ou false se ela não existe. @return array{setor_id:?int}|false */
    public static function setorDaAvaliacao(PDO $db, int $avaliacaoId): array|false {
        $stmt = $db->prepare("SELECT c.setor_id FROM pesquisa_avaliacoes a JOIN pesquisa_convites c ON c.id = a.convite_id WHERE a.id = ?");
        $stmt->execute([$avaliacaoId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? ['setor_id' => $r['setor_id'] !== null ? (int) $r['setor_id'] : null] : false;
    }

    // ── Convites ────────────────────────────────────────────────────────────

    /** WHERE dos convites (alias c = convite, u = servidor). @return array{0:string,1:array} */
    private static function whereConvites(?array $escopo, array $f, ?string $busca = null): array {
        $conds = [AcessoService::filtroSetorSql('c.setor_id', $escopo)];
        $p = [];
        if ($f['usuario_id'] > 0) { $conds[] = 'c.usuario_id = :usuario'; $p[':usuario'] = $f['usuario_id']; }
        if ($f['situacao'] === 'avaliado') $conds[] = 'c.avaliado_em IS NOT NULL';
        elseif ($f['situacao'] === 'expirado') $conds[] = 'c.expirado = 1';
        elseif ($f['situacao'] === 'aguardando') $conds[] = 'c.avaliado_em IS NULL AND c.expirado = 0';
        if ($f['data_ini'] !== '') { $conds[] = 'DATE(c.enviado_em) >= :ini'; $p[':ini'] = $f['data_ini']; }
        if ($f['data_fim'] !== '') { $conds[] = 'DATE(c.enviado_em) <= :fim'; $p[':fim'] = $f['data_fim']; }
        if ($f['origem'] !== '') { $conds[] = 'c.origem = :origem'; $p[':origem'] = $f['origem']; }
        if ($f['busca'] !== '') {
            $conds[] = '(c.destinatario_nome LIKE :b1 OR c.destinatario_email LIKE :b2)';
            $p[':b1'] = $p[':b2'] = self::escapeLike($f['busca']);
        }
        return [implode(' AND ', $conds), $p];
    }

    private const DE_CONVITES = "FROM pesquisa_convites c
        LEFT JOIN usuarios u ON u.id = c.usuario_id
        LEFT JOIN setores s ON s.id = c.setor_id
        LEFT JOIN pesquisa_avaliacoes a ON a.convite_id = c.id";

    /** Indicadores do histórico de convites (respeitam os filtros). */
    public static function indicadoresConvites(PDO $db, ?array $escopo, array $f): array {
        [$w, $p] = self::whereConvites($escopo, $f);
        $r = self::executar($db, "SELECT COUNT(*) AS total, COUNT(c.avaliado_em) AS avaliados,
                    SUM(c.avaliado_em IS NULL AND c.expirado = 0) AS aguardando, SUM(c.expirado = 1) AS expirados,
                    ROUND(AVG(a.nota), 1) AS media_nota " . self::DE_CONVITES . " WHERE $w", $p)->fetch(PDO::FETCH_ASSOC);
        $total = (int) $r['total'];
        $avaliados = (int) $r['avaliados'];
        return [
            'total' => $total, 'avaliados' => $avaliados,
            'aguardando' => (int) $r['aguardando'], 'expirados' => (int) $r['expirados'],
            'taxa' => $total > 0 ? (int) round($avaliados / $total * 100) : 0,
            'media_nota' => $r['media_nota'] !== null ? (float) $r['media_nota'] : null,
        ];
    }

    /** Convites paginados (25 por página), sem token. */
    public static function listarConvites(PDO $db, ?array $escopo, array $f, int $pagina, int $porPagina = 25, bool $todos = false): array {
        [$w, $p] = self::whereConvites($escopo, $f);
        $total = (int) self::executar($db, "SELECT COUNT(*) " . self::DE_CONVITES . " WHERE $w", $p)->fetchColumn();
        $pagina = max(1, $pagina);
        $porPagina = max(1, min(100, $porPagina));
        $limite = $todos ? ' LIMIT 50000' : ' LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
        $itens = self::executar($db, "SELECT c.id, c.destinatario_nome, c.destinatario_email, c.enviado_em, c.avaliado_em, c.expirado,
                    c.origem, c.protocolo, u.nome AS servidor_nome, s.nome AS setor_nome, a.nota, a.comentario
                 " . self::DE_CONVITES . " WHERE $w ORDER BY c.enviado_em DESC, c.id DESC" . $limite, $p)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($itens as &$i) {
            $i['id'] = (int) $i['id'];
            $i['situacao'] = self::situacaoDoConvite($i);
            $i['nota'] = $i['nota'] !== null ? (int) $i['nota'] : null;
            unset($i['expirado']);
        }
        unset($i);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina,
                'total_paginas' => max(1, (int) ceil($total / $porPagina))];
    }

    /** avaliado | expirado | aguardando. Função pura. */
    public static function situacaoDoConvite(array $c): string {
        if (($c['avaliado_em'] ?? null) !== null) return 'avaliado';
        return !empty($c['expirado']) ? 'expirado' : 'aguardando';
    }

    /** Servidores que já enviaram convites no escopo (para o filtro). */
    public static function servidoresComConvites(PDO $db, ?array $escopo): array {
        return $db->query("SELECT DISTINCT u.id, u.nome FROM pesquisa_convites c JOIN usuarios u ON u.id = c.usuario_id
                           WHERE " . AcessoService::filtroSetorSql('c.setor_id', $escopo) . " ORDER BY u.nome")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Painel de Setores ───────────────────────────────────────────────────

    /** Semana (segunda a sexta) e mês, atuais e anteriores, a partir de $hoje. Função pura. */
    public static function periodos(string $hoje, string $ini = '', string $fim = ''): array {
        $h = new DateTimeImmutable($hoje);
        $p = [
            'sem_atual_ini' => $h->modify('Monday this week')->format('Y-m-d'),
            'sem_atual_fim' => $h->modify('Friday this week')->format('Y-m-d'),
            'sem_ant_ini'   => $h->modify('Monday last week')->format('Y-m-d'),
            'sem_ant_fim'   => $h->modify('Friday last week')->format('Y-m-d'),
            'mes_atual_ini' => $h->format('Y-m-01'),
            'mes_atual_fim' => $h->format('Y-m-t'),
            'mes_ant_ini'   => $h->modify('first day of last month')->format('Y-m-d'),
            'mes_ant_fim'   => $h->modify('last day of last month')->format('Y-m-d'),
        ];
        $p['custom_ini'] = $ini ?: $p['mes_atual_ini'];
        $p['custom_fim'] = $fim ?: $p['mes_atual_fim'];
        return $p;
    }

    /**
     * Setores que aparecem no painel (pesquisa_painel = 1), cada um com seus servidores e os números de convites
     * e notas. Servidor = quem está lotado no setor como operador ou gestor, mais quem já enviou convites por
     * aquele setor. Os números de cada servidor contam só os convites do setor (pesquisa_convites.setor_id),
     * como o minigráfico e as demais telas: o coordenador de vários setores aparece em cada um, com os seus.
     */
    public static function painelSetores(PDO $db, ?array $escopo, string $ini = '', string $fim = ''): array {
        $p = self::periodos(date('Y-m-d'), $ini, $fim);
        $bind = [];
        foreach ($p as $k => $v) {
            $bind[':' . $k] = $v;
        }
        $linhas = self::executar($db, "SELECT s.id AS setor_id, s.nome AS setor_nome,
                (SELECT cu.nome FROM usuarios cu WHERE cu.tipo = 'coordenador' AND cu.ativo = 1
                    AND (cu.setor_id = s.id OR cu.id IN (SELECT usuario_id FROM usuario_setores WHERE setor_id = s.id)) LIMIT 1) AS coordenador_nome,
                u.id AS usuario_id, u.nome AS usuario_nome, u.avatar AS usuario_avatar,
                SUM(DATE(c.enviado_em) BETWEEN :sem_atual_ini AND :sem_atual_fim) AS sem_atual,
                SUM(DATE(c.enviado_em) BETWEEN :sem_ant_ini AND :sem_ant_fim) AS sem_ant,
                SUM(DATE(c.enviado_em) BETWEEN :mes_atual_ini AND :mes_atual_fim) AS mes_atual,
                SUM(DATE(c.enviado_em) BETWEEN :mes_ant_ini AND :mes_ant_fim) AS mes_ant,
                SUM(DATE(c.enviado_em) BETWEEN :custom_ini AND :custom_fim) AS custom,
                COUNT(c.id) AS total_convites, COUNT(a.id) AS total_avaliados, ROUND(AVG(a.nota), 1) AS media_nota,
                MAX(c.enviado_em) AS ultimo_envio,
                SUM(a.nota = 5) AS n5, SUM(a.nota = 4) AS n4, SUM(a.nota = 3) AS n3, SUM(a.nota = 2) AS n2, SUM(a.nota = 1) AS n1,
                (SELECT a2.comentario FROM pesquisa_avaliacoes a2 JOIN pesquisa_convites c2 ON c2.id = a2.convite_id
                    WHERE c2.usuario_id = u.id AND c2.setor_id = s.id AND a2.comentario IS NOT NULL ORDER BY a2.criado_em DESC LIMIT 1) AS ultimo_comentario
             FROM setores s
             LEFT JOIN usuarios u ON u.ativo = 1 AND u.tipo IN ('operador', 'gestor', 'coordenador', 'supervisor', 'coordenador_geral')
                  AND ((u.setor_id = s.id AND u.tipo IN ('operador', 'gestor'))
                       OR EXISTS (SELECT 1 FROM pesquisa_convites x WHERE x.usuario_id = u.id AND x.setor_id = s.id))
             LEFT JOIN pesquisa_convites c ON c.usuario_id = u.id AND c.setor_id = s.id
             LEFT JOIN pesquisa_avaliacoes a ON a.convite_id = c.id
             WHERE s.ativo = 1 AND s.pesquisa_painel = 1 AND " . AcessoService::filtroSetorSql('s.id', $escopo) . "
             GROUP BY s.id, s.nome, u.id, u.nome, u.avatar ORDER BY s.nome, u.nome", $bind)->fetchAll(PDO::FETCH_ASSOC);

        $historico = self::historicoMensal($db, $escopo);
        $setores = [];
        foreach ($linhas as $r) {
            $sid = (int) $r['setor_id'];
            $setores[$sid] ??= ['id' => $sid, 'nome' => $r['setor_nome'], 'coordenador_nome' => $r['coordenador_nome'], 'servidores' => []];
            if ($r['usuario_id'] === null) continue;
            $uid = (int) $r['usuario_id'];
            $setores[$sid]['servidores'][] = [
                'id' => $uid, 'nome' => $r['usuario_nome'], 'avatar' => $r['usuario_avatar'],
                'sem_atual' => (int) $r['sem_atual'], 'sem_ant' => (int) $r['sem_ant'],
                'mes_atual' => (int) $r['mes_atual'], 'mes_ant' => (int) $r['mes_ant'], 'custom' => (int) $r['custom'],
                'total_convites' => (int) $r['total_convites'], 'total_avaliados' => (int) $r['total_avaliados'],
                'media_nota' => $r['media_nota'] !== null ? (float) $r['media_nota'] : null,
                'ultimo_envio' => $r['ultimo_envio'], 'ultimo_comentario' => $r['ultimo_comentario'],
                'rating_dist' => [5 => (int) $r['n5'], 4 => (int) $r['n4'], 3 => (int) $r['n3'], 2 => (int) $r['n2'], 1 => (int) $r['n1']],
                'historico_mensal' => $historico[$sid][$uid] ?? [],
            ];
        }
        return ['periodos' => $p, 'setores' => array_values($setores)];
    }

    /** Convites por mês dos últimos 6 meses, por setor e servidor. @return array<int, array<int, array<int, array{mes:string,label:string,total:int}>>> */
    private static function historicoMensal(PDO $db, ?array $escopo): array {
        $hoje = new DateTimeImmutable('today');
        $inicio = $hoje->modify('first day of -5 months')->format('Y-m-01');
        $fim = $hoje->format('Y-m-t') . ' 23:59:59';
        $linhas = self::executar($db, "SELECT c.setor_id, c.usuario_id, DATE_FORMAT(c.enviado_em, '%Y-%m') AS mes, COUNT(*) AS total
                 FROM pesquisa_convites c JOIN setores s ON s.id = c.setor_id AND s.pesquisa_painel = 1
                 WHERE c.enviado_em BETWEEN :ini AND :fim AND " . AcessoService::filtroSetorSql('c.setor_id', $escopo) . "
                 GROUP BY c.setor_id, c.usuario_id, mes", [':ini' => $inicio, ':fim' => $fim])->fetchAll(PDO::FETCH_ASSOC);
        $rotulos = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        $meses = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = $hoje->modify("first day of -$i months");
            $meses[] = ['mes' => $d->format('Y-m'), 'label' => $rotulos[(int) $d->format('n') - 1]];
        }
        $indexado = [];
        foreach ($linhas as $l) {
            $indexado[(int) $l['setor_id']][(int) $l['usuario_id']][$l['mes']] = (int) $l['total'];
        }
        $resultado = [];
        foreach ($indexado as $sid => $porServidor) {
            foreach ($porServidor as $uid => $dados) {
                foreach ($meses as $m) {
                    $resultado[$sid][$uid][] = ['mes' => $m['mes'], 'label' => $m['label'], 'total' => $dados[$m['mes']] ?? 0];
                }
            }
        }
        return $resultado;
    }

    // ── Frase do dia, Meu Painel e Cruzamento ────────────────────────────────

    /** Frase do dia: gira em sequência pelas frases ativas, uma por dia (estado em pesquisa_config). */
    public static function fraseDoDia(PDO $db): ?array {
        $frases = $db->query("SELECT texto, autor FROM pesquisa_frases WHERE ativo = 1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$frases) return null;
        $total = count($frases);
        $hoje = date('Y-m-d');
        $gravado = $db->query("SELECT chave, valor FROM pesquisa_config WHERE chave IN ('frase_dia_indice', 'frase_dia_data')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $indice = (int) ($gravado['frase_dia_indice'] ?? 0);
        $ultima = (string) ($gravado['frase_dia_data'] ?? '');
        if ($ultima !== $hoje) {
            if ($ultima !== '') $indice = ($indice + 1) % $total;
            $indice %= $total;
            $grava = $db->prepare("INSERT INTO pesquisa_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
            $grava->execute(['frase_dia_indice', (string) $indice]);
            $grava->execute(['frase_dia_data', $hoje]);
        } else {
            $indice %= $total;
        }
        return ['texto' => (string) $frases[$indice]['texto'], 'autor' => (string) $frases[$indice]['autor']];
    }

    /** Dados da tela "Meu Painel" do servidor (indicadores, histórico de 15 por página, regras e frase). */
    public static function meuPainel(PDO $db, int $usuarioId, string $situacao, int $pagina): array {
        $f = self::lerFiltros(['situacao' => $situacao]);
        $f['usuario_id'] = $usuarioId;
        $pagina = max(1, $pagina);
        $todos = self::indicadoresConvites($db, null, ['usuario_id' => $usuarioId] + self::lerFiltros([]));
        $lista = self::listarConvites($db, null, $f, $pagina, 15);
        $itens = array_map(function (array $c): array {
            $comentario = trim((string) ($c['comentario'] ?? ''));
            return [
                'id' => $c['id'], 'enviado_em' => (string) $c['enviado_em'], 'situacao' => $c['situacao'], 'nota' => $c['nota'],
                'comentario' => $comentario !== '' ? mb_strimwidth($comentario, 0, 120, '…') : '',
                'origem' => (string) $c['origem'], 'protocolo' => ltrim((string) ($c['protocolo'] ?? ''), '#'),
            ];
        }, $lista['itens']);
        return [
            'indicadores' => $todos,
            'regras' => PesquisaConfig::regrasConvite($db),
            'frase' => self::fraseDoDia($db),
            'historico' => ['filtro' => $f['situacao'], 'pagina' => $lista['pagina'], 'por_pagina' => 15, 'total' => $lista['total'],
                            'total_paginas' => max(1, (int) ceil($lista['total'] / 15)), 'itens' => $itens],
        ];
    }

    /** Satisfação de um servidor num período (Cruzamento com as atividades): agregado, comentários e série diária. */
    public static function satisfacaoDoServidor(PDO $db, int $usuarioId, string $ini, string $fim): array {
        $agr = self::executar($db, "SELECT COUNT(c.id) AS total_convites, COUNT(a.id) AS total_avaliados, AVG(a.nota) AS media,
                    SUM(a.nota = 5) AS n5, SUM(a.nota = 4) AS n4, SUM(a.nota = 3) AS n3, SUM(a.nota = 2) AS n2, SUM(a.nota = 1) AS n1
                 FROM pesquisa_convites c LEFT JOIN pesquisa_avaliacoes a ON a.convite_id = c.id
                 WHERE c.usuario_id = :u AND DATE(c.enviado_em) BETWEEN :ini AND :fim",
                 [':u' => $usuarioId, ':ini' => $ini, ':fim' => $fim])->fetch(PDO::FETCH_ASSOC);
        $totalConvites = (int) $agr['total_convites'];
        $totalAvaliados = (int) $agr['total_avaliados'];

        $comentarios = self::executar($db, "SELECT a.nota AS rating, a.comentario AS comment, a.criado_em AS created_at
                 FROM pesquisa_convites c JOIN pesquisa_avaliacoes a ON a.convite_id = c.id
                 WHERE c.usuario_id = :u AND DATE(c.enviado_em) BETWEEN :ini AND :fim AND a.comentario IS NOT NULL AND a.comentario <> ''
                 ORDER BY a.criado_em DESC LIMIT 5", [':u' => $usuarioId, ':ini' => $ini, ':fim' => $fim])->fetchAll(PDO::FETCH_ASSOC);

        $porDia = array_column(self::executar($db, "SELECT DATE(c.enviado_em) AS data_dia, COUNT(a.id) AS qtd, AVG(a.nota) AS avg_rating
                 FROM pesquisa_convites c JOIN pesquisa_avaliacoes a ON a.convite_id = c.id
                 WHERE c.usuario_id = :u AND DATE(c.enviado_em) BETWEEN :ini AND :fim GROUP BY DATE(c.enviado_em)",
                 [':u' => $usuarioId, ':ini' => $ini, ':fim' => $fim])->fetchAll(PDO::FETCH_ASSOC), null, 'data_dia');
        $serie = [];
        for ($d = new DateTimeImmutable($ini); $d <= new DateTimeImmutable($fim); $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $serie[] = isset($porDia[$k])
                ? ['data_dia' => $k, 'qtd' => (int) $porDia[$k]['qtd'], 'avg_rating' => round((float) $porDia[$k]['avg_rating'], 1)]
                : ['data_dia' => $k, 'qtd' => 0, 'avg_rating' => null];
        }
        return [
            'total_convites' => $totalConvites, 'total_avaliados' => $totalAvaliados,
            'media_nota' => $agr['media'] !== null ? round((float) $agr['media'], 1) : null,
            'taxa_resposta' => $totalConvites > 0 ? (int) round($totalAvaliados / $totalConvites * 100) : null,
            'rating_dist' => [5 => (int) $agr['n5'], 4 => (int) $agr['n4'], 3 => (int) $agr['n3'], 2 => (int) $agr['n2'], 1 => (int) $agr['n1']],
            'comentarios' => $comentarios, 'serie_diaria' => $serie,
        ];
    }
}
