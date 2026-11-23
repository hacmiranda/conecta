<?php
require_once __DIR__ . '/prazo-atividade-service.php';
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/relatorio-setor-service.php';

/**
 * Acompanhamento do Registro de Atividades (admin/gestor): Painel de Atividades, Relatório, Histórico de
 * ausências e Cruzamento de dados. Porte das consultas de RegistroAtividadeModel e CruzamentoDadosModel do
 * METRICA360 (27/10/2026). Entram a equipe que registra atividades (AcessoService::PAPEIS_REGISTRAM_ATIVIDADES). $setorId: null = todos os setores; int = um setor; int[] = vários (coordenador).
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

    /** Normaliza o escopo: null/0 = todos os setores; int = um; int[] = vários. @return int[]|null */
    public static function escopo(int|array|null $setorId): ?array {
        if (is_array($setorId)) return array_values(array_map('intval', $setorId));
        return $setorId ? [(int) $setorId] : null;
    }

    /** Lista SQL dos papéis que registram atividades (valores fixos do código). */
    private static function tiposSql(): string {
        return AcessoService::listaSql(AcessoService::PAPEIS_REGISTRAM_ATIVIDADES);
    }

    /** " AND coluna IN (...)" do escopo; vazio quando o escopo é todos os setores. */
    private static function andSetor(string $coluna, int|array|null $setorId): string {
        $escopo = self::escopo($setorId);
        return $escopo === null ? '' : ' AND ' . AcessoService::filtroSetorSql($coluna, $escopo);
    }

    /** Condição: relatório do setor (periodo <> 'dia') entra sem olhar o papel de quem enviou (o coordenador). */
    private static function condTipo(): string {
        return "(r.periodo <> 'dia' OR u.tipo IN (" . self::tiposSql() . "))";
    }

    /** Modalidade do relatório para as somas: 'setor' nos relatórios do setor. */
    private const SQL_MODALIDADE = "CASE WHEN r.periodo <> 'dia' THEN 'setor' ELSE COALESCE(r.modalidade, 'remoto') END";

    /** Vigências por setor, lidas uma vez por consulta do serviço. */
    private array $cacheVigencias = [];

    private function vigencias(int $setorId): array {
        return $this->cacheVigencias[$setorId] ??= $this->prazo->vigenciasDoSetor($setorId);
    }

    /** Fim do período de um relatório do setor (recortado pela vigência); o fim do calendário se não achar vigência. */
    private function fimDoPeriodoDoSetor(int $setorId, string $periodo, string $data): string {
        $periodicidade = $periodo === 'mes' ? 'mensal' : 'semanal';
        $fim = PrazoAtividadeService::fimDoPeriodo($periodicidade, $data);
        foreach ($this->vigencias($setorId) as $v) {
            if ($v['periodicidade'] === $periodicidade && $v['inicio'] <= $fim && ($v['fim'] === null || $v['fim'] >= $data)) {
                return $v['fim'] !== null ? min($fim, $v['fim']) : $fim;
            }
        }
        return $fim;
    }

    /**
     * Setores do escopo que têm (ou tiveram) vigência do relatório do setor desde $desde.
     * @return array<int,string> id => nome
     */
    private function setoresComRelatorioSetor(int|array|null $setorId, string $desde, string $hoje): array {
        $sql = "SELECT DISTINCT s.id, s.nome FROM atividades_relatorio_setor v JOIN setores s ON s.id = v.setor_id
                WHERE v.inicio <= ? AND (v.fim IS NULL OR v.fim >= ?)" . self::andSetor('s.id', $setorId) . " ORDER BY s.nome";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$hoje, $desde]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Equipe que registra atividades (ativos) do escopo, com setor. */
    public function usuariosDoEscopo(int|array|null $setorId): array {
        $sql = "SELECT u.id, u.nome, u.email, u.setor_id, s.nome AS setor_nome
                FROM usuarios u JOIN setores s ON s.id = u.setor_id
                WHERE u.ativo = 1 AND u.tipo IN (" . self::tiposSql() . ")" . self::andSetor('u.setor_id', $setorId) . "
                ORDER BY s.nome, u.nome";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Painel de Atividades ────────────────────────────────────────────────────

    public function painel(int|array|null $setorId, ?string $hoje = null): array {
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
            LEFT JOIN registros_atividades r ON r.usuario_id = u.id AND r.status = 'enviado' AND r.periodo = 'dia' AND r.setor_id = u.setor_id
            WHERE u.ativo = 1 AND u.tipo IN (" . self::tiposSql() . ") AND u.setor_id IS NOT NULL" . self::andSetor('u.setor_id', $setorId) . "
            GROUP BY u.id");
        $params = [$p['sem_atual_ini'], $p['sem_atual_fim'], $p['sem_ant_ini'], $p['sem_ant_fim'],
                   $p['mes_atual_ini'], $p['mes_atual_fim'], $p['mes_ant_ini'], $p['mes_ant_fim']];
        $stmt->execute($params);
        $contagens = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $contagens[(int) $c['id']] = $c;

        // Dias úteis da semana atual até hoje, e quais tiveram envio
        $diasUteis = [];
        for ($d = new DateTimeImmutable($p['sem_atual_ini']); $d->format('Y-m-d') <= $hoje; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') <= 5) $diasUteis[] = $d->format('Y-m-d');
        }
        $enviadosSemana = [];
        $stmt = $this->db->prepare("SELECT usuario_id, setor_id, data_referencia FROM registros_atividades WHERE status = 'enviado' AND periodo = 'dia' AND data_referencia BETWEEN ? AND ?");
        $stmt->execute([$p['sem_atual_ini'], $hoje]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) $enviadosSemana[(int) $l['usuario_id'] . '|' . (int) $l['setor_id']][$l['data_referencia']] = true;

        // Pendências dos últimos 60 dias (como no METRICA360)
        $inicio = (new DateTimeImmutable($hoje))->modify('-60 days')->format('Y-m-d');
        $setores = [];
        $fimDeSemana = (int) (new DateTimeImmutable($hoje))->format('N') >= 6;
        $obrigacaoSetor = []; // setor => relatório do setor cobre hoje?
        foreach ($this->usuariosDoEscopo($setorId) as $u) {
            $id = (int) $u['id'];
            $chaveEnvio = $id . '|' . (int) $u['setor_id'];
            $c = $contagens[$id] ?? ['sem_atual' => 0, 'sem_ant' => 0, 'mes_atual' => 0, 'mes_ant' => 0];
            $pend = $this->prazo->pendenciasUsuario($id, $inicio, $hoje);
            $atrasadas = array_values(array_filter($pend, fn($x) => $x['status'] === 'atrasado'));
            $abonadas = array_values(array_filter($pend, fn($x) => $x['status'] === 'abonado'));
            $enviouHoje = isset($enviadosSemana[$chaveEnvio][$hoje]);
            $semAtual = (int) $c['sem_atual'];
            $semAnt = (int) $c['sem_ant'];
            [$direcao, $rotulo] = self::tendencia($semAtual, $semAnt);

            $sid = (int) $u['setor_id'];
            if (!isset($setores[$sid])) {
                $setores[$sid] = ['id' => $sid, 'nome' => $u['setor_nome'], 'usuarios' => []];
            }
            $obrigacaoSetor[$sid] ??= PrazoAtividadeService::desobrigadoEm($this->vigencias($sid), $hoje);
            $setores[$sid]['usuarios'][] = [
                'id'             => $id,
                'nome'           => $u['nome'],
                'sem_atual'      => $semAtual,
                'sem_ant'        => $semAnt,
                'mes_atual'      => (int) $c['mes_atual'],
                'mes_ant'        => (int) $c['mes_ant'],
                'situacao_hoje'  => $fimDeSemana ? 'fim_de_semana' : ($enviouHoje ? 'enviou' : ($atrasadas ? 'atrasado' : 'no_prazo')),
                'tendencia'      => ['direcao' => $direcao, 'rotulo' => $rotulo],
                'dias_sem_envio' => array_values(array_filter($diasUteis, fn($d) => !isset($enviadosSemana[$chaveEnvio][$d]))),
                'atrasadas'      => $atrasadas,
                'abonadas'       => $abonadas,
                'desobrigado'    => $obrigacaoSetor[$sid],
            ];
        }

        // Relatório do setor (coordenador): também para setor sem servidor com regras
        foreach ($this->setoresComRelatorioSetor($setorId, $inicio, $hoje) as $sid => $nome) {
            $setores[$sid] ??= ['id' => (int) $sid, 'nome' => $nome, 'usuarios' => []];
        }
        $coordenadores = new RelatorioSetorService($this->db);
        foreach ($setores as $sid => &$setor) {
            $setor['relatorio_setor'] = $this->relatorioDoSetorNoPainel((int) $sid, $inicio, $hoje, $coordenadores);
        }
        unset($setor);
        uasort($setores, fn($a, $b) => strcoll($a['nome'], $b['nome']));
        return ['periodos' => $p, 'hoje' => $hoje, 'setores' => array_values($setores)];
    }

    /**
     * Bloco `relatorio_setor` de um setor no painel: null sem vigência nos últimos 60 dias nem pendência;
     * `atual`/`anterior` = período corrente e anterior da vigência atual (sem vigência atual, só `pendencias`).
     */
    private function relatorioDoSetorNoPainel(int $sid, string $inicio, string $hoje, RelatorioSetorService $coordenadores): ?array {
        $vigencias = $this->vigencias($sid);
        $pend = $this->prazo->pendenciasRelatorioSetor($sid, $inicio, $hoje);
        $recentes = array_filter($vigencias, fn($v) => $v['inicio'] <= $hoje && ($v['fim'] === null || $v['fim'] >= $inicio));
        if (!$recentes && !$pend) return null;

        $atualVig = PrazoAtividadeService::vigenciaQueCobre($vigencias, $hoje);
        $referencia = $atualVig ?? ($recentes ? end($recentes) : null);
        $atual = $anterior = null;
        if ($atualVig) {
            $per = $atualVig['periodicidade'];
            $nome = $per === 'mensal' ? 'mes' : 'semana';
            $iniAtual = PrazoAtividadeService::inicioDoPeriodo($per, $hoje);
            $fimAtual = PrazoAtividadeService::fimDoPeriodo($per, $hoje);
            $iniAnt = PrazoAtividadeService::inicioDoPeriodo($per, (new DateTimeImmutable($iniAtual))->modify('-1 day')->format('Y-m-d'));
            $fimAnt = PrazoAtividadeService::fimDoPeriodo($per, $iniAnt);
            $stmt = $this->db->prepare("SELECT data_referencia FROM registros_atividades
                WHERE setor_id = ? AND periodo = ? AND status = 'enviado' AND data_referencia IN (?, ?)");
            $stmt->execute([$sid, $nome, $iniAtual, $iniAnt]);
            $enviados = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
            $atual = [
                'data_referencia' => $iniAtual,
                'periodo_fim'     => $atualVig['fim'] !== null ? min($fimAtual, $atualVig['fim']) : $fimAtual,
                'enviado'         => isset($enviados[$iniAtual]),
            ];
            // O período anterior só existe se a vigência já valia nele
            if ($fimAnt >= $atualVig['inicio']) {
                $anterior = ['data_referencia' => $iniAnt, 'periodo_fim' => $fimAnt, 'enviado' => isset($enviados[$iniAnt])];
            }
        }
        return [
            'periodicidade' => $referencia['periodicidade'] ?? null,
            'coordenador'   => $coordenadores->coordenadorDoSetor($sid),
            'atual'         => $atual,
            'anterior'      => $anterior,
            'pendencias'    => $pend,
        ];
    }

    // ── Relatório ───────────────────────────────────────────────────────────────

    /** WHERE dos relatórios enviados (servidores ativos do escopo). @return array{0:string,1:array} */
    private function filtros(int|array|null $setorId, ?int $usuarioId, string $ini, string $fim, string $modalidade): array {
        $conds = ["r.status = 'enviado'", self::condTipo()];
        $params = [];
        $escopo = self::escopo($setorId);
        // O setor é o do registro (o servidor pode ter mudado de setor depois de enviar)
        if ($escopo !== null) { $conds[] = AcessoService::filtroSetorSql('r.setor_id', $escopo); }
        if ($usuarioId === -1) { $conds[] = "r.periodo <> 'dia'"; } // só os relatórios do setor
        elseif ($usuarioId) { $conds[] = 'r.usuario_id = ?'; $params[] = $usuarioId; }
        if ($ini !== '') { $conds[] = 'r.data_referencia >= ?'; $params[] = $ini; }
        if ($fim !== '') { $conds[] = 'r.data_referencia <= ?'; $params[] = $fim; }
        if ($modalidade === 'setor') {
            $conds[] = "r.periodo <> 'dia'";
        } elseif (in_array($modalidade, PrazoAtividadeService::MODALIDADES, true)) {
            $conds[] = "r.periodo = 'dia' AND COALESCE(r.modalidade, 'remoto') = ?";
            $params[] = $modalidade;
        }
        return [implode(' AND ', $conds), $params];
    }

    public function relatorio(int|array|null $setorId, ?int $usuarioId, string $ini, string $fim, string $modalidade, int $pagina, int $porPagina = self::POR_PAGINA): array {
        [$where, $params] = $this->filtros($setorId, $usuarioId, $ini, $fim, $modalidade);
        $base = "FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id LEFT JOIN setores s ON s.id = r.setor_id WHERE $where";

        $stmt = $this->db->prepare("SELECT COUNT(*) $base");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $pagina = max(1, $pagina);
        $stmt = $this->db->prepare("
            SELECT r.id, r.data_referencia, r.enviado_em, r.periodo, r.setor_id, " . self::SQL_MODALIDADE . " AS modalidade,
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
            $i['tipo'] = $i['periodo'] === 'dia' ? 'servidor' : 'setor';
            $i['periodo_fim'] = $i['periodo'] === 'dia' ? $i['data_referencia']
                : $this->fimDoPeriodoDoSetor((int) $i['setor_id'], $i['periodo'], $i['data_referencia']);
            unset($i['setor_id']);
            $i['total_atividades'] = (int) $i['total_atividades'];
            $i['total_tarefas'] = (int) $i['total_tarefas'];
        }
        unset($i);

        // Indicadores do período (sem o filtro de servidor/modalidade, como no METRICA360)
        [$whereM, $paramsM] = $this->filtros($setorId, null, $ini, $fim, '');
        $stmt = $this->db->prepare("
            SELECT " . self::SQL_MODALIDADE . " AS grupo, COUNT(*) AS relatorios,
                   COUNT(DISTINCT CASE WHEN r.periodo = 'dia' THEN r.usuario_id END) AS usuarios,
                   COALESCE(SUM((SELECT COUNT(*) FROM registros_atividades_itens i WHERE i.registro_id = r.id AND i.quantidade > 0)), 0) AS atividades,
                   COALESCE(SUM((SELECT COALESCE(SUM(i.quantidade), 0) FROM registros_atividades_itens i WHERE i.registro_id = r.id)), 0) AS tarefas
            FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id
            WHERE $whereM GROUP BY grupo");
        $stmt->execute($paramsM);
        $vazio = ['relatorios' => 0, 'usuarios' => 0, 'atividades' => 0, 'tarefas' => 0];
        $porModalidade = ['remoto' => $vazio, 'presencial' => $vazio, 'setor' => $vazio];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $porModalidade[$l['grupo']] = array_map('intval', array_intersect_key($l, $vazio));
        }
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT CASE WHEN r.periodo = 'dia' THEN r.usuario_id END) FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id WHERE $whereM");
        $stmt->execute($paramsM);

        return [
            'itens'         => $itens,
            'total'         => $total,
            'pagina'        => $pagina,
            'por_pagina'    => $porPagina,
            'total_paginas' => (int) ceil($total / $porPagina),
            'metricas'      => [
                'total_registros' => $porModalidade['remoto']['relatorios'] + $porModalidade['presencial']['relatorios'] + $porModalidade['setor']['relatorios'],
                'total_usuarios'  => (int) $stmt->fetchColumn(),
                'total_atividades' => $porModalidade['remoto']['atividades'] + $porModalidade['presencial']['atividades'] + $porModalidade['setor']['atividades'],
            ],
            'por_modalidade' => $porModalidade,
        ];
    }

    /** Relatório com itens preenchidos; null se não existe ou está fora do setor pedido. */
    public function detalhe(int $registroId, int|array|null $setorId): ?array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.data_referencia, r.enviado_em, r.status, r.periodo, " . self::SQL_MODALIDADE . " AS modalidade,
                   u.nome AS usuario_nome, r.setor_id, s.nome AS setor_nome
            FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id LEFT JOIN setores s ON s.id = r.setor_id
            WHERE r.id = ? AND r.status = 'enviado'");
        $stmt->execute([$registroId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $escopoDetalhe = self::escopo($setorId);
        if (!$r || ($escopoDetalhe !== null && !in_array((int) $r['setor_id'], $escopoDetalhe, true))) return null;
        $stmt = $this->db->prepare("
            SELECT t.nome AS atividade_nome, i.quantidade, i.observacao
            FROM registros_atividades_itens i JOIN atividades_tipos t ON t.id = i.atividade_tipo_id
            WHERE i.registro_id = ? AND i.quantidade > 0 ORDER BY t.ordem, t.nome");
        $stmt->execute([$registroId]);
        $r['itens'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $r['tipo'] = $r['periodo'] === 'dia' ? 'servidor' : 'setor';
        $r['periodo_fim'] = $r['periodo'] === 'dia' ? $r['data_referencia']
            : $this->fimDoPeriodoDoSetor((int) $r['setor_id'], $r['periodo'], $r['data_referencia']);
        return $r;
    }

    // ── Pendências do escopo (alerta do relatório) ─────────────────────────────

    /** Pendências atrasadas e abonadas dos últimos 60 dias, por servidor. */
    public function pendenciasDoEscopo(int|array|null $setorId, ?string $hoje = null): array {
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
        // Relatório do setor (enviado pelo coordenador)
        foreach ($this->setoresComRelatorioSetor($setorId, $inicio, $hoje) as $sid => $nome) {
            foreach ($this->prazo->pendenciasRelatorioSetor((int) $sid, $inicio, $hoje) as $p) {
                if (in_array($p['status'], ['atrasado', 'abonado'], true)) {
                    $lista[] = ['setor_id' => (int) $sid, 'setor_nome' => $nome, 'pendencia' => $p];
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
    public function historicoAusencias(int|array|null $setorId, string $ini, string $fim): array {
        $setores = self::escopo($setorId) ?? array_map('intval', $this->db->query("SELECT id FROM setores ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN));
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
                                                WHERE usuario_id = ? AND setor_id = ? AND status = 'enviado' AND periodo = 'dia' AND data_referencia BETWEEN ? AND ? GROUP BY s");
                    $stmt->execute([$uid, $sid, $iniSemana, $fimSemana]);
                    $enviado = 0;
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) $enviado += min((int) $l['n'], $cota);
                } else {
                    $stmt = $this->db->prepare("SELECT COUNT(*) FROM registros_atividades WHERE usuario_id = ? AND setor_id = ? AND status = 'enviado' AND periodo = 'dia' AND data_referencia BETWEEN ? AND ?");
                    $stmt->execute([$uid, $sid, $ini, $fim]);
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
            // Relatório do setor (coordenador): uma linha por setor com os períodos sem envio
            $linhaSetor = $this->linhaDoSetorNoHistorico($sid, $nomes[$sid] ?? '', $ini, $fim);
            if ($linhaSetor) $resultado[] = $linhaSetor;
        }
        return $resultado;
    }

    /** Linha "Relatório do setor" do histórico de ausências, ou null se não há período sem envio. */
    private function linhaDoSetorNoHistorico(int $sid, string $nomeSetor, string $ini, string $fim): ?array {
        $semanas = [];
        foreach ($this->prazo->pendenciasRelatorioSetor($sid, $ini, $fim) as $p) {
            if ($p['status'] === 'pendente') continue;
            $abonado = $p['status'] === 'abonado';
            $semanas[] = [
                'semana'   => $p['periodo'] === 'mes' ? date('m/Y', strtotime($p['data_referencia']))
                                                      : (new DateTimeImmutable($p['data_referencia']))->format('o-\WW'),
                'periodo'  => $p['periodo'],
                'de'       => $p['data_referencia'],
                'ate'      => $p['periodo_fim'],
                'esperado' => 1,
                'enviado'  => 0,
                'faltaram' => $abonado ? 0 : 1,
                'abonados' => $abonado ? 1 : 0,
            ];
        }
        if (!$semanas) return null;
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM registros_atividades
            WHERE setor_id = ? AND periodo <> 'dia' AND status = 'enviado' AND data_referencia BETWEEN ? AND ?");
        $stmt->execute([$sid, $ini, $fim]);
        $enviado = (int) $stmt->fetchColumn();
        $ausencias = array_sum(array_column($semanas, 'faltaram'));
        return [
            'tipo'            => 'setor',
            'usuario_id'      => null,
            'nome'            => 'Relatório do setor',
            'setor_nome'      => $nomeSetor,
            'total_esperado'  => $enviado + $ausencias,
            'total_enviado'   => $enviado,
            'total_ausencias' => $ausencias,
            'total_abonados'  => array_sum(array_column($semanas, 'abonados')),
            'semanas'         => $semanas,
            'dias'            => [],
        ];
    }

    // ── Cruzamento de dados (lado das atividades) ──────────────────────────────

    public function atividadesDoUsuario(int $usuarioId, string $ini, string $fim, ?int $setorId = null): array {
        $andSetor = $setorId !== null ? ' AND r.setor_id = ' . (int) $setorId : '';
        $stmt = $this->db->prepare("
            SELECT r.data_referencia,
                   (SELECT COUNT(*) FROM registros_atividades_itens i WHERE i.registro_id = r.id AND i.quantidade > 0) AS atividades,
                   (SELECT COALESCE(SUM(i.quantidade), 0) FROM registros_atividades_itens i WHERE i.registro_id = r.id) AS tarefas
            FROM registros_atividades r
            WHERE r.usuario_id = ? AND r.status = 'enviado' AND r.periodo = 'dia' AND r.data_referencia BETWEEN ? AND ?" . $andSetor);
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
            WHERE r.usuario_id = ? AND r.status = 'enviado' AND r.periodo = 'dia' AND r.data_referencia BETWEEN ? AND ? AND i.quantidade > 0" . $andSetor . "
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

    /**
     * Atividades de um SETOR no período (Cruzamento de Dados por setor, 17/11/2026): soma dos servidores lotados
     * (série diária de tarefas, relatórios enviados/esperados, pendências), as atividades mais feitas e os números
     * de cada servidor (mesmas regras de atividadesDoUsuario).
     */
    public function atividadesDoSetor(int $setorId, string $ini, string $fim): array {
        $servidores = [];
        $porDia = [];
        $tot = ['total_enviados' => 0, 'total_esperado' => 0, 'total_tarefas' => 0, 'pendencias_atrasadas' => 0, 'pendencias_abonadas' => 0];
        foreach ($this->usuariosDoEscopo([$setorId]) as $u) {
            $a = $this->atividadesDoUsuario((int) $u['id'], $ini, $fim, $setorId);
            foreach ($a['serie_diaria'] as $d) {
                $porDia[$d['data']] ??= ['data' => $d['data'], 'enviados' => 0, 'atividades' => 0, 'tarefas' => 0];
                $porDia[$d['data']]['enviados'] += $d['enviado'] ? 1 : 0;
                $porDia[$d['data']]['atividades'] += $d['atividades'];
                $porDia[$d['data']]['tarefas'] += $d['tarefas'];
            }
            foreach (array_keys($tot) as $k) $tot[$k] += $a[$k];
            $servidores[] = ['id' => (int) $u['id'], 'nome' => $u['nome'], 'total_enviados' => $a['total_enviados'],
                'total_esperado' => $a['total_esperado'], 'total_tarefas' => $a['total_tarefas'],
                'pendencias_atrasadas' => $a['pendencias_atrasadas'], 'pendencias_abonadas' => $a['pendencias_abonadas']];
        }
        // Relatório do setor (coordenador): conta no dia de início do período (data_referencia)
        $setorNaSerie = false;
        $stmt = $this->db->prepare("
            SELECT r.data_referencia,
                   (SELECT COUNT(*) FROM registros_atividades_itens i WHERE i.registro_id = r.id AND i.quantidade > 0) AS atividades,
                   (SELECT COALESCE(SUM(i.quantidade), 0) FROM registros_atividades_itens i WHERE i.registro_id = r.id) AS tarefas
            FROM registros_atividades r
            WHERE r.setor_id = ? AND r.periodo <> 'dia' AND r.status = 'enviado' AND r.data_referencia BETWEEN ? AND ?");
        $stmt->execute([$setorId, $ini, $fim]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $setorNaSerie = true;
            $porDia[$l['data_referencia']] ??= ['data' => $l['data_referencia'], 'enviados' => 0, 'atividades' => 0, 'tarefas' => 0];
            $porDia[$l['data_referencia']]['enviados'] += 1;
            $porDia[$l['data_referencia']]['atividades'] += (int) $l['atividades'];
            $porDia[$l['data_referencia']]['tarefas'] += (int) $l['tarefas'];
            $tot['total_enviados'] += 1;
            $tot['total_esperado'] += 1;
            $tot['total_tarefas'] += (int) $l['tarefas'];
        }
        foreach ($this->prazo->pendenciasRelatorioSetor($setorId, $ini, $fim) as $p) {
            if ($p['status'] === 'atrasado') { $tot['pendencias_atrasadas']++; $tot['total_esperado']++; }
            elseif ($p['status'] === 'abonado') { $tot['pendencias_abonadas']++; $tot['total_esperado']++; }
        }

        // Dias sem nenhum servidor também entram (o gráfico mostra o calendário real)
        $serie = [];
        for ($d = new DateTimeImmutable($ini); $d->format('Y-m-d') <= $fim; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $l = $porDia[$k] ?? ['data' => $k, 'enviados' => 0, 'atividades' => 0, 'tarefas' => 0];
            $serie[] = ['data' => $k, 'enviado' => $l['enviados'] > 0, 'enviados' => $l['enviados'], 'atividades' => $l['atividades'], 'tarefas' => $l['tarefas']];
        }
        $stmt = $this->db->prepare("
            SELECT t.nome AS atividade_nome, SUM(i.quantidade) AS total
            FROM registros_atividades_itens i
            JOIN registros_atividades r ON r.id = i.registro_id
            JOIN atividades_tipos t ON t.id = i.atividade_tipo_id
            WHERE t.setor_id = ? AND r.status = 'enviado' AND r.data_referencia BETWEEN ? AND ? AND i.quantidade > 0
            GROUP BY t.id, t.nome ORDER BY total DESC LIMIT 5");
        $stmt->execute([$setorId, $ini, $fim]);
        $top = array_map(fn($r) => ['atividade_nome' => $r['atividade_nome'], 'total' => (int) $r['total']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $tot + ['atividades_top' => $top, 'serie_diaria' => $serie, 'servidores' => $servidores, 'relatorio_setor_na_serie' => $setorNaSerie];
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
