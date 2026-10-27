<?php
require_once __DIR__ . '/prazo-atividade-service.php';

/**
 * Acompanhamento do Registro de Atividades (admin/gestor): Painel de Atividades, Relatório, Histórico de
 * ausências e Cruzamento de dados. Porte das consultas de RegistroAtividadeModel e CruzamentoDadosModel do
 * METRICA360 (27/10/2026). Entram gestores e operadores ativos. $setorId null = todos os setores (admin).
 */
class AtividadesAcompanhamentoService {

    public const POR_PAGINA = 20;

    private PDO $db;
    private PrazoAtividadeService $prazo;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->prazo = new PrazoAtividadeService($db);
    }

    /** Semana (segunda a domingo) e mês, atuais e anteriores, a partir de $hoje. */
    public static function periodos(?string $hoje = null): array {
        $h = new DateTimeImmutable($hoje ?? 'today');
        $n = (int) $h->format('N');
        $mesAnt = $h->modify('first day of last month');
        return [
            'sem_atual_ini' => $h->modify('-' . ($n - 1) . ' days')->format('Y-m-d'),
            'sem_atual_fim' => $h->modify('+' . (7 - $n) . ' days')->format('Y-m-d'),
            'sem_ant_ini'   => $h->modify('-' . ($n + 6) . ' days')->format('Y-m-d'),
            'sem_ant_fim'   => $h->modify('-' . $n . ' days')->format('Y-m-d'),
            'mes_atual_ini' => $h->format('Y-m-01'),
            'mes_atual_fim' => $h->format('Y-m-t'),
            'mes_ant_ini'   => $mesAnt->format('Y-m-01'),
            'mes_ant_fim'   => $mesAnt->format('Y-m-t'),
        ];
    }

    /** Tendência da semana atual contra a passada: [direcao, rotulo] (regra do painel do METRICA360). */
    public static function tendencia(int $atual, int $anterior): array {
        if ($anterior === 0 && $atual === 0) return ['sem-dado', 'Sem dados'];
        if ($anterior === 0) return ['up', '+' . $atual];
        if ($atual > $anterior) return ['up', '+' . ($atual - $anterior)];
        if ($atual < $anterior) return ['down', '-' . ($anterior - $atual)];
        return ['flat', '='];
    }

    /** Gestores e operadores ativos do escopo, com setor. */
    public function usuariosDoEscopo(?int $setorId): array {
        $sql = "SELECT u.id, u.nome, u.email, u.setor_id, s.nome AS setor_nome
                FROM usuarios u JOIN setores s ON s.id = u.setor_id
                WHERE u.ativo = 1 AND u.tipo IN ('gestor', 'operador')" . ($setorId ? " AND u.setor_id = ?" : "") . "
                ORDER BY s.nome, u.nome";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($setorId ? [$setorId] : []);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Painel de Atividades ────────────────────────────────────────────────────

    public function painel(?int $setorId, ?string $hoje = null): array {
        $hoje ??= date('Y-m-d');
        $p = self::periodos($hoje);

        // Relatórios enviados por período, por servidor
        $stmt = $this->db->prepare("
            SELECT u.id,
                   COUNT(CASE WHEN r.data_referencia BETWEEN ? AND ? THEN 1 END) AS sem_atual,
                   COUNT(CASE WHEN r.data_referencia BETWEEN ? AND ? THEN 1 END) AS sem_ant,
                   COUNT(CASE WHEN r.data_referencia BETWEEN ? AND ? THEN 1 END) AS mes_atual,
                   COUNT(CASE WHEN r.data_referencia BETWEEN ? AND ? THEN 1 END) AS mes_ant
            FROM usuarios u
            LEFT JOIN registros_atividades r ON r.usuario_id = u.id AND r.status = 'enviado'
            WHERE u.ativo = 1 AND u.tipo IN ('gestor', 'operador') AND u.setor_id IS NOT NULL" . ($setorId ? " AND u.setor_id = ?" : "") . "
            GROUP BY u.id");
        $params = [$p['sem_atual_ini'], $p['sem_atual_fim'], $p['sem_ant_ini'], $p['sem_ant_fim'],
                   $p['mes_atual_ini'], $p['mes_atual_fim'], $p['mes_ant_ini'], $p['mes_ant_fim']];
        if ($setorId) $params[] = $setorId;
        $stmt->execute($params);
        $contagens = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $contagens[(int) $c['id']] = $c;

        // Dias úteis da semana atual até hoje, e quais tiveram envio
        $diasUteis = [];
        for ($d = new DateTimeImmutable($p['sem_atual_ini']); $d->format('Y-m-d') <= $hoje; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') <= 5) $diasUteis[] = $d->format('Y-m-d');
        }
        $enviadosSemana = [];
        $stmt = $this->db->prepare("SELECT usuario_id, data_referencia FROM registros_atividades WHERE status = 'enviado' AND data_referencia BETWEEN ? AND ?");
        $stmt->execute([$p['sem_atual_ini'], $hoje]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) $enviadosSemana[(int) $l['usuario_id']][$l['data_referencia']] = true;

        // Pendências dos últimos 60 dias (como no METRICA360)
        $inicio = (new DateTimeImmutable($hoje))->modify('-60 days')->format('Y-m-d');
        $setores = [];
        $fimDeSemana = (int) (new DateTimeImmutable($hoje))->format('N') >= 6;
        foreach ($this->usuariosDoEscopo($setorId) as $u) {
            $id = (int) $u['id'];
            $c = $contagens[$id] ?? ['sem_atual' => 0, 'sem_ant' => 0, 'mes_atual' => 0, 'mes_ant' => 0];
            $pend = $this->prazo->pendenciasUsuario($id, $inicio, $hoje);
            $atrasadas = array_values(array_filter($pend, fn($x) => $x['status'] === 'atrasado'));
            $abonadas = array_values(array_filter($pend, fn($x) => $x['status'] === 'abonado'));
            $enviouHoje = isset($enviadosSemana[$id][$hoje]);
            $semAtual = (int) $c['sem_atual'];
            $semAnt = (int) $c['sem_ant'];
            [$direcao, $rotulo] = self::tendencia($semAtual, $semAnt);

            $sid = (int) $u['setor_id'];
            if (!isset($setores[$sid])) {
                $setores[$sid] = ['id' => $sid, 'nome' => $u['setor_nome'], 'usuarios' => []];
            }
            $setores[$sid]['usuarios'][] = [
                'id'             => $id,
                'nome'           => $u['nome'],
                'sem_atual'      => $semAtual,
                'sem_ant'        => $semAnt,
                'mes_atual'      => (int) $c['mes_atual'],
                'mes_ant'        => (int) $c['mes_ant'],
                'situacao_hoje'  => $fimDeSemana ? 'fim_de_semana' : ($enviouHoje ? 'enviou' : ($atrasadas ? 'atrasado' : 'no_prazo')),
                'tendencia'      => ['direcao' => $direcao, 'rotulo' => $rotulo],
                'dias_sem_envio' => array_values(array_filter($diasUteis, fn($d) => !isset($enviadosSemana[$id][$d]))),
                'atrasadas'      => $atrasadas,
                'abonadas'       => $abonadas,
            ];
        }
        return ['periodos' => $p, 'hoje' => $hoje, 'setores' => array_values($setores)];
    }

    // ── Relatório ───────────────────────────────────────────────────────────────

    /** WHERE dos relatórios enviados (servidores ativos do escopo). @return array{0:string,1:array} */
    private function filtros(?int $setorId, ?int $usuarioId, string $ini, string $fim, string $modalidade): array {
        $conds = ["r.status = 'enviado'", "u.tipo IN ('gestor', 'operador')"];
        $params = [];
        if ($setorId) { $conds[] = 'u.setor_id = ?'; $params[] = $setorId; }
        if ($usuarioId) { $conds[] = 'r.usuario_id = ?'; $params[] = $usuarioId; }
        if ($ini !== '') { $conds[] = 'r.data_referencia >= ?'; $params[] = $ini; }
        if ($fim !== '') { $conds[] = 'r.data_referencia <= ?'; $params[] = $fim; }
        if (in_array($modalidade, PrazoAtividadeService::MODALIDADES, true)) {
            $conds[] = "COALESCE(r.modalidade, 'remoto') = ?";
            $params[] = $modalidade;
        }
        return [implode(' AND ', $conds), $params];
    }

    public function relatorio(?int $setorId, ?int $usuarioId, string $ini, string $fim, string $modalidade, int $pagina, int $porPagina = self::POR_PAGINA): array {
        [$where, $params] = $this->filtros($setorId, $usuarioId, $ini, $fim, $modalidade);
        $base = "FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id LEFT JOIN setores s ON s.id = u.setor_id WHERE $where";

        $stmt = $this->db->prepare("SELECT COUNT(*) $base");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $pagina = max(1, $pagina);
        $stmt = $this->db->prepare("
            SELECT r.id, r.data_referencia, r.enviado_em, COALESCE(r.modalidade, 'remoto') AS modalidade,
                   u.nome AS usuario_nome, s.nome AS setor_nome,
                   (SELECT COUNT(*) FROM registros_atividades_itens i WHERE i.registro_id = r.id AND i.quantidade > 0) AS total_atividades,
                   (SELECT COALESCE(SUM(i.quantidade), 0) FROM registros_atividades_itens i WHERE i.registro_id = r.id) AS total_tarefas
            $base
            ORDER BY r.data_referencia DESC, u.nome
            LIMIT " . (int) $porPagina . " OFFSET " . (int) (($pagina - 1) * $porPagina));
        $stmt->execute($params);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($itens as &$i) {
            $i['id'] = (int) $i['id'];
            $i['total_atividades'] = (int) $i['total_atividades'];
            $i['total_tarefas'] = (int) $i['total_tarefas'];
        }
        unset($i);

        // Indicadores do período (sem o filtro de servidor/modalidade, como no METRICA360)
        [$whereM, $paramsM] = $this->filtros($setorId, null, $ini, $fim, '');
        $stmt = $this->db->prepare("
            SELECT COALESCE(r.modalidade, 'remoto') AS modalidade, COUNT(*) AS relatorios, COUNT(DISTINCT r.usuario_id) AS usuarios,
                   COALESCE(SUM((SELECT COUNT(*) FROM registros_atividades_itens i WHERE i.registro_id = r.id AND i.quantidade > 0)), 0) AS atividades,
                   COALESCE(SUM((SELECT COALESCE(SUM(i.quantidade), 0) FROM registros_atividades_itens i WHERE i.registro_id = r.id)), 0) AS tarefas
            FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id
            WHERE $whereM GROUP BY COALESCE(r.modalidade, 'remoto')");
        $stmt->execute($paramsM);
        $vazio = ['relatorios' => 0, 'usuarios' => 0, 'atividades' => 0, 'tarefas' => 0];
        $porModalidade = ['remoto' => $vazio, 'presencial' => $vazio];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $porModalidade[$l['modalidade']] = array_map('intval', array_intersect_key($l, $vazio));
        }
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT r.usuario_id) FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id WHERE $whereM");
        $stmt->execute($paramsM);

        return [
            'itens'         => $itens,
            'total'         => $total,
            'pagina'        => $pagina,
            'por_pagina'    => $porPagina,
            'total_paginas' => (int) ceil($total / $porPagina),
            'metricas'      => [
                'total_registros' => $porModalidade['remoto']['relatorios'] + $porModalidade['presencial']['relatorios'],
                'total_usuarios'  => (int) $stmt->fetchColumn(),
                'total_atividades' => $porModalidade['remoto']['atividades'] + $porModalidade['presencial']['atividades'],
            ],
            'por_modalidade' => $porModalidade,
        ];
    }

    /** Relatório com itens preenchidos; null se não existe ou está fora do setor pedido. */
    public function detalhe(int $registroId, ?int $setorId): ?array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.data_referencia, r.enviado_em, r.status, COALESCE(r.modalidade, 'remoto') AS modalidade,
                   u.nome AS usuario_nome, u.setor_id, s.nome AS setor_nome
            FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id LEFT JOIN setores s ON s.id = u.setor_id
            WHERE r.id = ? AND r.status = 'enviado'");
        $stmt->execute([$registroId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r || ($setorId && (int) $r['setor_id'] !== $setorId)) return null;
        $stmt = $this->db->prepare("
            SELECT t.nome AS atividade_nome, i.quantidade, i.observacao
            FROM registros_atividades_itens i JOIN atividades_tipos t ON t.id = i.atividade_tipo_id
            WHERE i.registro_id = ? AND i.quantidade > 0 ORDER BY t.ordem, t.nome");
        $stmt->execute([$registroId]);
        $r['itens'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $r;
    }

    // ── Pendências do escopo (alerta do relatório) ─────────────────────────────

    /** Pendências atrasadas e abonadas dos últimos 60 dias, por servidor. */
    public function pendenciasDoEscopo(?int $setorId, ?string $hoje = null): array {
        $hoje ??= date('Y-m-d');
        $inicio = (new DateTimeImmutable($hoje))->modify('-60 days')->format('Y-m-d');
        $lista = [];
        foreach ($this->usuariosDoEscopo($setorId) as $u) {
            foreach ($this->prazo->pendenciasUsuario((int) $u['id'], $inicio, $hoje) as $p) {
                if (in_array($p['status'], ['atrasado', 'abonado'], true)) {
                    $lista[] = ['usuario_id' => (int) $u['id'], 'usuario_nome' => $u['nome'], 'pendencia' => $p];
                }
            }
        }
        return $lista;
    }

    // ── Histórico de ausências ──────────────────────────────────────────────────

    /**
     * Ausências por servidor no período (porte de historicoAusencias do METRICA360). Pendência ainda
     * dentro do prazo ('pendente') não conta como ausência.
     */
    public function historicoAusencias(?int $setorId, string $ini, string $fim): array {
        $setores = $setorId ? [$setorId] : array_map('intval', $this->db->query("SELECT id FROM setores ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN));
        $nomes = $this->db->query("SELECT id, nome FROM setores")->fetchAll(PDO::FETCH_KEY_PAIR);
        $iniSemana = (new DateTimeImmutable($ini))->modify('Monday this week')->format('Y-m-d');
        $fimSemana = (new DateTimeImmutable($fim))->modify('Sunday this week')->format('Y-m-d');
        $resultado = [];

        foreach ($setores as $sid) {
            foreach ($this->prazo->statusSetor($sid, $ini, $fim) as $u) {
                $semanas = [];
                $dias = [];
                foreach ($u['pendencias'] as $p) {
                    if ($p['status'] === 'pendente') continue;
                    $abonado = $p['status'] === 'abonado';
                    if (($p['tipo'] ?? 'remoto') === 'entrega' || !isset($p['semana_ini'])) {
                        $dias[] = ['data' => $p['data'], 'abonado' => $abonado, 'tipo' => $p['tipo'] ?? 'remoto'];
                    } else {
                        $semanas[] = [
                            'semana'   => (new DateTimeImmutable($p['semana_ini']))->format('o-\WW'),
                            'de'       => $p['semana_ini'],
                            'ate'      => $p['semana_fim'],
                            'esperado' => $p['esperado'],
                            'enviado'  => $p['enviado'],
                            'faltaram' => $abonado ? 0 : $p['faltam'],
                            'abonados' => $abonado ? $p['faltam'] : 0,
                        ];
                    }
                }
                if (!$semanas && !$dias) continue;

                $uid = (int) $u['usuario_id'];
                $ausencias = array_sum(array_column($semanas, 'faltaram')) + count(array_filter($dias, fn($d) => !$d['abonado']));
                if ($semanas) {
                    // Enviados limitados à cota de cada semana (semanas acima da cota não compensam as outras)
                    $cota = (int) ($this->prazo->configUsuario($uid)['qtd_semana'] ?? 0);
                    $stmt = $this->db->prepare("SELECT YEARWEEK(data_referencia, 3) AS s, COUNT(*) AS n FROM registros_atividades
                                                WHERE usuario_id = ? AND status = 'enviado' AND data_referencia BETWEEN ? AND ? GROUP BY s");
                    $stmt->execute([$uid, $iniSemana, $fimSemana]);
                    $enviado = 0;
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) $enviado += min((int) $l['n'], $cota);
                } else {
                    $stmt = $this->db->prepare("SELECT COUNT(*) FROM registros_atividades WHERE usuario_id = ? AND status = 'enviado' AND data_referencia BETWEEN ? AND ?");
                    $stmt->execute([$uid, $ini, $fim]);
                    $enviado = (int) $stmt->fetchColumn();
                }
                $resultado[] = [
                    'usuario_id'      => $uid,
                    'nome'            => $u['usuario_nome'],
                    'setor_nome'      => $nomes[$sid] ?? '',
                    'total_esperado'  => $enviado + $ausencias,
                    'total_enviado'   => $enviado,
                    'total_ausencias' => $ausencias,
                    'total_abonados'  => array_sum(array_column($semanas, 'abonados')) + count(array_filter($dias, fn($d) => $d['abonado'])),
                    'semanas'         => $semanas,
                    'dias'            => $dias,
                ];
            }
        }
        return $resultado;
    }

    // ── Cruzamento de dados (lado das atividades) ──────────────────────────────

    public function atividadesDoUsuario(int $usuarioId, string $ini, string $fim): array {
        $stmt = $this->db->prepare("
            SELECT r.data_referencia,
                   (SELECT COUNT(*) FROM registros_atividades_itens i WHERE i.registro_id = r.id AND i.quantidade > 0) AS atividades,
                   (SELECT COALESCE(SUM(i.quantidade), 0) FROM registros_atividades_itens i WHERE i.registro_id = r.id) AS tarefas
            FROM registros_atividades r
            WHERE r.usuario_id = ? AND r.status = 'enviado' AND r.data_referencia BETWEEN ? AND ?");
        $stmt->execute([$usuarioId, $ini, $fim]);
        $porDia = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) $porDia[$l['data_referencia']] = $l;

        $serie = [];
        $tarefas = 0;
        for ($d = new DateTimeImmutable($ini); $d->format('Y-m-d') <= $fim; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $l = $porDia[$k] ?? null;
            $tarefas += $l ? (int) $l['tarefas'] : 0;
            $serie[] = ['data' => $k, 'enviado' => $l !== null, 'atividades' => $l ? (int) $l['atividades'] : 0, 'tarefas' => $l ? (int) $l['tarefas'] : 0];
        }

        $stmt = $this->db->prepare("
            SELECT t.nome AS atividade_nome, SUM(i.quantidade) AS total
            FROM registros_atividades_itens i
            JOIN registros_atividades r ON r.id = i.registro_id
            JOIN atividades_tipos t ON t.id = i.atividade_tipo_id
            WHERE r.usuario_id = ? AND r.status = 'enviado' AND r.data_referencia BETWEEN ? AND ? AND i.quantidade > 0
            GROUP BY t.id, t.nome ORDER BY total DESC LIMIT 5");
        $stmt->execute([$usuarioId, $ini, $fim]);
        $top = array_map(fn($r) => ['atividade_nome' => $r['atividade_nome'], 'total' => (int) $r['total']], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $atrasadas = 0;
        $abonadas = 0;
        foreach ($this->prazo->pendenciasUsuario($usuarioId, $ini, $fim) as $p) {
            if ($p['status'] === 'atrasado') $atrasadas++;
            elseif ($p['status'] === 'abonado') $abonadas++;
        }
        $enviados = count($porDia);
        return [
            'total_enviados'       => $enviados,
            'total_esperado'       => $enviados + $atrasadas + $abonadas,
            'total_tarefas'        => $tarefas,
            'atividades_top'       => $top,
            'pendencias_atrasadas' => $atrasadas,
            'pendencias_abonadas'  => $abonadas,
            'serie_diaria'         => $serie,
        ];
    }

    // ── CSV ─────────────────────────────────────────────────────────────────────

    /** Célula de CSV sem fórmula (=, +, -, @ no início viram texto). */
    public static function celulaCsv($valor): string {
        $v = (string) $valor;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    /** Escreve o CSV (BOM UTF-8, separador ;) na saída. */
    public static function enviarCsv(string $arquivo, array $cabecalho, iterable $linhas): void {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $arquivo) . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $cabecalho, ';');
        foreach ($linhas as $l) {
            fputcsv($out, array_map([self::class, 'celulaCsv'], $l), ';');
        }
        fclose($out);
    }
}
