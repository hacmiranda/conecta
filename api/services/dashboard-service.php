<?php
/**
 * Dados do Dashboard (reescrito em 19/11/2026, junto com os gráficos em Chart.js).
 *
 * Dois tipos de número:
 *  - DO PERÍODO escolhido (e do período anterior de mesmo tamanho, para a tendência): demandas criadas,
 *    concluídas, taxa de conclusão, tempos médios, FCR, canal externo, série criadas × concluídas,
 *    dia da semana, solicitantes, subcategorias e categorias;
 *  - DE AGORA (fotografia das demandas ativas, não dependem do período): ativas por status, por prioridade,
 *    por responsável, status por responsável e SLA vencido.
 *
 * Regras comuns: demandas canceladas, mescladas (secundárias) e na lixeira não contam. "Criada no período"
 * pela data de abertura; "concluída no período" pela data em que chegou pela primeira vez a resolvido/
 * encerrado (ticket_sla.resolvido_em, que a reabertura apaga).
 *
 * O escopo (setor, operador) chega pronto do controller, em $filtroT (alias "t") e $contexto.
 */
require_once __DIR__ . '/periodo-service.php';
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/sla-service.php';
require_once __DIR__ . '/cache-arquivo-service.php';

class DashboardService {

    private PDO $db;
    private string $filtroT;
    private array $contexto;

    /**
     * @param string $filtroT  condições extras sobre "t" (lixeira, setor, operador), começando por " AND"
     * @param array  $contexto ['recorte_setor' => bool, 'escopo' => int[]|null, 'operador_id' => int|null]
     */
    public function __construct(PDO $db, string $filtroT, array $contexto) {
        $this->db = $db;
        $this->filtroT = $filtroT;
        $this->contexto = $contexto;
    }

    /** Tudo o que a tela precisa */
    public function montar(string $inicio, string $fim): array {
        [$antIni, $antFim] = PeriodoService::anterior($inicio, $fim);
        $atual = $this->indicadoresDoPeriodo($inicio, $fim);
        $anterior = $this->indicadoresDoPeriodo($antIni, $antFim);

        $indicadores = [];
        foreach ($atual as $chave => $valor) {
            $indicadores[$chave] = ['atual' => $valor, 'anterior' => $anterior[$chave]];
        }

        return [
            'periodo' => [
                'inicio' => $inicio, 'fim' => $fim, 'dias' => PeriodoService::dias($inicio, $fim),
                'anterior' => ['inicio' => $antIni, 'fim' => $antFim],
            ],
            'indicadores' => $indicadores,
            'serie' => $this->serie($inicio, $fim),
            'periodo_dados' => [
                'por_dia_semana' => $this->porDiaSemana($inicio, $fim),
                'top_solicitantes' => $this->topSolicitantes($inicio, $fim),
                'top_subcategorias' => $this->topSubcategorias($inicio, $fim),
                'por_categoria' => $this->porCategoria($inicio, $fim),
            ],
            'agora' => $this->agora(),
        ];
    }

    // ------------------------------------------------------------------ período

    /** Limites do período para o SQL: [início, dia seguinte ao fim] (fim exclusivo) */
    private static function limites(string $inicio, string $fim): array {
        return [':ini' => $inicio . ' 00:00:00', ':fimx' => (new DateTimeImmutable($fim))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
    }

    /** Condição "não cancelada, não mesclada" + escopo */
    private function base(): string {
        return "t.status <> 'cancelado' AND t.mesclada_em IS NULL" . $this->filtroT;
    }

    private function valor(string $sql, array $params) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function linhas(string $sql, array $params = []): array {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Indicadores de um período (usado para o atual e para o anterior) */
    private function indicadoresDoPeriodo(string $inicio, string $fim): array {
        $p = self::limites($inicio, $fim);
        $criadaNoPeriodo = "t.criado_em >= :ini AND t.criado_em < :fimx";
        $concluidos = AcessoService::listaSql(AcessoService::STATUS_CONCLUIDOS);

        $criadas = $this->linhas("SELECT COUNT(*) AS criadas,
                                         SUM(t.status IN ($concluidos)) AS concluidas_das_criadas,
                                         SUM(t.origem_api IS NOT NULL AND t.origem_api <> 'interna') AS externas
                                  FROM tickets t WHERE $criadaNoPeriodo AND {$this->base()}", $p)[0];
        $totalCriadas = (int) $criadas['criadas'];

        // Concluídas no período e, delas, as resolvidas sem o solicitante precisar escrever de novo (FCR)
        $conc = $this->linhas("SELECT COUNT(*) AS concluidas,
                                      SUM((SELECT COUNT(*) FROM comentarios c WHERE c.ticket_id = t.id AND c.usuario_id = t.usuario_id) = 0) AS primeiro_contato
                               FROM tickets t JOIN ticket_sla ts ON ts.ticket_id = t.id
                               WHERE ts.resolvido_em >= :ini AND ts.resolvido_em < :fimx AND {$this->base()}", $p)[0];
        $totalConc = (int) $conc['concluidas'];

        // Tempos médios em horas úteis (mesma regra de antes: SLAService), das demandas criadas no período
        $tempos = ['primeira_resposta' => 0, 'resolucao' => 0];
        try {
            $sla = new SLAService($this->db);
            $linhas = $this->linhas("SELECT t.criado_em, t.setor_id, t.status, t.prioridade,
                                            ts.primeira_resposta_em, ts.tempo_primeira_resposta_segundos,
                                            ts.resolvido_em, ts.tempo_resolucao_segundos
                                     FROM tickets t JOIN ticket_sla ts ON ts.ticket_id = t.id
                                     WHERE $criadaNoPeriodo AND {$this->base()}", $p);
            $tempos['primeira_resposta'] = (int) $sla->mediaPrimeiraResposta($linhas);
            $tempos['resolucao'] = (int) $sla->mediaResolucao(array_filter($linhas,
                fn($l) => in_array($l['status'], AcessoService::STATUS_CONCLUIDOS, true)));
        } catch (Throwable $e) {
            error_log('[DASHBOARD] Tempos médios: ' . $e->getMessage());
        }

        return [
            'criadas' => $totalCriadas,
            'concluidas' => $totalConc,
            'taxa_conclusao' => $totalCriadas > 0 ? round((int) $criadas['concluidas_das_criadas'] / $totalCriadas * 100, 1) : null,
            'tempo_primeira_resposta' => $tempos['primeira_resposta'] ?: null,
            'tempo_resolucao' => $tempos['resolucao'] ?: null,
            'fcr' => $totalConc > 0 ? round((int) $conc['primeiro_contato'] / $totalConc * 100, 1) : null,
            'externas' => (int) $criadas['externas'],
        ];
    }

    /** Criadas × concluídas, ponto a ponto (dia ou mês, conforme o tamanho do período), sem buracos */
    private function serie(string $inicio, string $fim): array {
        $pontos = PeriodoService::pontos($inicio, $fim);
        $formato = $pontos['granularidade'] === 'dia' ? '%Y-%m-%d' : '%Y-%m';
        $p = self::limites($inicio, $fim);
        $criadas = array_column($this->linhas("SELECT DATE_FORMAT(t.criado_em, '$formato') AS k, COUNT(*) AS n FROM tickets t
                                               WHERE t.criado_em >= :ini AND t.criado_em < :fimx AND {$this->base()} GROUP BY k", $p), 'n', 'k');
        $concluidas = array_column($this->linhas("SELECT DATE_FORMAT(ts.resolvido_em, '$formato') AS k, COUNT(*) AS n
                                                  FROM tickets t JOIN ticket_sla ts ON ts.ticket_id = t.id
                                                  WHERE ts.resolvido_em >= :ini AND ts.resolvido_em < :fimx AND {$this->base()} GROUP BY k", $p), 'n', 'k');
        $saida = [];
        foreach ($pontos['chaves'] as $k) {
            $saida[] = ['chave' => $k, 'criadas' => (int) ($criadas[$k] ?? 0), 'concluidas' => (int) ($concluidas[$k] ?? 0)];
        }
        return ['granularidade' => $pontos['granularidade'], 'pontos' => $saida];
    }

    /** Criadas por dia da semana, de segunda (1) a domingo (7) */
    private function porDiaSemana(string $inicio, string $fim): array {
        $linhas = array_column($this->linhas("SELECT WEEKDAY(t.criado_em) + 1 AS d, COUNT(*) AS n FROM tickets t
                                              WHERE t.criado_em >= :ini AND t.criado_em < :fimx AND {$this->base()} GROUP BY d",
                                             self::limites($inicio, $fim)), 'n', 'd');
        $saida = [];
        for ($d = 1; $d <= 7; $d++) {
            $saida[] = (int) ($linhas[$d] ?? 0);
        }
        return $saida;
    }

    private function topSolicitantes(string $inicio, string $fim): array {
        return $this->inteiros($this->linhas("SELECT u.nome, COUNT(*) AS total FROM tickets t JOIN usuarios u ON u.id = t.usuario_id
                                              WHERE t.criado_em >= :ini AND t.criado_em < :fimx AND {$this->base()}
                                              GROUP BY u.id, u.nome ORDER BY total DESC, u.nome LIMIT 5", self::limites($inicio, $fim)));
    }

    private function topSubcategorias(string $inicio, string $fim): array {
        return $this->inteiros($this->linhas("SELECT CONCAT_WS(' - ', c.nome, mc.nome) AS nome, COUNT(*) AS total
                                              FROM tickets t JOIN categorias c ON c.id = t.categoria_id
                                              LEFT JOIN macrocategorias mc ON mc.id = c.macrocategoria_id
                                              WHERE t.criado_em >= :ini AND t.criado_em < :fimx AND {$this->base()}
                                              GROUP BY c.id, c.nome, mc.nome ORDER BY total DESC, nome LIMIT 5", self::limites($inicio, $fim)));
    }

    private function porCategoria(string $inicio, string $fim): array {
        return $this->inteiros($this->linhas("SELECT m.nome, COUNT(*) AS total
                                              FROM tickets t JOIN categorias c ON c.id = t.categoria_id
                                              JOIN macrocategorias m ON m.id = c.macrocategoria_id
                                              WHERE t.criado_em >= :ini AND t.criado_em < :fimx AND {$this->base()}
                                              GROUP BY m.id, m.nome ORDER BY total DESC, m.nome", self::limites($inicio, $fim)));
    }

    private function inteiros(array $linhas): array {
        return array_map(fn($l) => ['nome' => $l['nome'], 'total' => (int) $l['total']], $linhas);
    }

    // ------------------------------------------------------------------ agora

    /** Fotografia das demandas ativas (não depende do período) */
    private function agora(): array {
        $arquivo = AcessoService::listaSql(AcessoService::STATUS_ARQUIVO);
        $ativas = "t.status NOT IN ($arquivo) AND t.mesclada_em IS NULL" . $this->filtroT;

        $porStatus = array_map('intval', array_column($this->linhas("SELECT t.status, COUNT(*) AS n FROM tickets t WHERE $ativas GROUP BY t.status"), 'n', 'status'));
        $porPrioridade = array_map('intval', array_column($this->linhas("SELECT t.prioridade, COUNT(*) AS n FROM tickets t WHERE $ativas GROUP BY t.prioridade"), 'n', 'prioridade'));

        // Responsáveis: a equipe do recorte (com zero, se não tiver nenhuma); o operador vê só a si mesmo
        $filtroJoin = ' AND t.excluido_em IS NULL AND t.mesclada_em IS NULL';
        $filtroUsuario = '';
        if (!empty($this->contexto['recorte_setor'])) {
            $filtroJoin .= ' AND ' . AcessoService::filtroSetorSql('t.setor_id', $this->contexto['escopo']);
            $filtroUsuario .= ' AND ' . AcessoService::filtroSetorSql('u.setor_id', $this->contexto['escopo']);
        }
        if (!empty($this->contexto['operador_id'])) {
            $filtroUsuario .= ' AND u.id = ' . (int) $this->contexto['operador_id'];
        }
        $porResponsavel = $this->inteiros($this->linhas("SELECT u.nome, COUNT(t.id) AS total FROM usuarios u
                            LEFT JOIN tickets t ON t.agente_id = u.id AND t.status NOT IN ($arquivo)$filtroJoin
                            WHERE u.tipo IN ('admin', 'coordenador', 'gestor', 'operador') AND u.ativo = 1$filtroUsuario
                            GROUP BY u.id, u.nome ORDER BY total DESC, u.nome"));
        // Sem demanda: só ocupa espaço no gráfico
        $porResponsavel = array_values(array_filter($porResponsavel, fn($l) => $l['total'] > 0));
        $semResponsavel = (int) $this->valor("SELECT COUNT(*) FROM tickets t WHERE $ativas AND t.agente_id IS NULL", []);

        $statusPorResponsavel = $this->linhas("SELECT u.nome AS responsavel, t.status, COUNT(*) AS total
                            FROM tickets t JOIN usuarios u ON u.id = t.agente_id
                            WHERE $ativas AND t.status <> 'resolvido'" . (!empty($this->contexto['operador_id']) ? ' AND u.id = ' . (int) $this->contexto['operador_id'] : '') . "
                            GROUP BY u.id, u.nome, t.status ORDER BY u.nome");
        foreach ($statusPorResponsavel as &$l) {
            $l['total'] = (int) $l['total'];
        }
        unset($l);

        return [
            'ativas' => array_sum($porStatus),
            'sem_responsavel' => $semResponsavel,
            'sla_vencido' => $this->slaVencido(),
            'por_status' => $porStatus,
            'por_prioridade' => $porPrioridade,
            'por_responsavel' => $porResponsavel,
            'status_por_responsavel' => $statusPorResponsavel,
        ];
    }

    /** Ativas com SLA vencido (resposta ou resolução). Cálculo demanda a demanda: guardado 60 s por escopo */
    private function slaVencido(): int {
        $emAndamento = AcessoService::listaSql(array_merge(AcessoService::STATUS_ARQUIVO, AcessoService::STATUS_CONCLUIDOS));
        $vencido = CacheArquivoService::ler('dashboard-sla-vencido', $this->filtroT);
        if (is_int($vencido)) {
            return $vencido;
        }
        $vencido = 0;
        $sla = new SLAService($this->db);
        $linhas = $this->linhas("SELECT t.criado_em, t.status, t.prioridade, t.setor_id,
                                        ts.primeira_resposta_em, ts.resolvido_em, ts.tempo_pausado_segundos, ts.pausado_em
                                 FROM tickets t LEFT JOIN ticket_sla ts ON ts.ticket_id = t.id
                                 WHERE t.status NOT IN ($emAndamento) AND t.mesclada_em IS NULL{$this->filtroT}");
        foreach ($linhas as $aberta) {
            $situacao = $sla->situacao($aberta, $aberta, $aberta['setor_id'] !== null ? (int) $aberta['setor_id'] : null);
            if ($situacao && $situacao['vencido']) {
                $vencido++;
            }
        }
        CacheArquivoService::gravar('dashboard-sla-vencido', $this->filtroT, $vencido, 60);
        return $vencido;
    }
}
