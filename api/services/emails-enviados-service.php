<?php
/**
 * Dashboard → E-mails Enviados (19/11/2026): só quantitativos.
 *
 * Conta as RESPOSTAS DA EQUIPE enviadas ao solicitante por e-mail (decisão do usuário): comentário de
 * um usuário interno com `visivel_solicitante = 1` e `interno = 0` (o "Enviar por e-mail" e o "Enviar e
 * Fechar"; `comentarios.php` só grava 1 quando o e-mail sai de fato). Avisos automáticos ficam de fora.
 *
 * O setor de cada resposta é o da demanda NO MOMENTO da resposta: o último setor em que ela entrou até
 * aquela hora (`ticket_setor_log`), ou o setor atual se não houver registro. Demandas na lixeira não contam.
 *
 * Janelas: ontem (o dia anterior), últimos 7 dias e últimos 30 dias (contando hoje) e o período escolhido.
 */
require_once __DIR__ . '/periodo-service.php';

class EmailsEnviadosService {

    /** Papéis listados na tabela por funcionário */
    const PAPEIS_FUNCIONARIO = ['gestor', 'operador'];

    /**
     * Período do filtro (regras em PeriodoService). Sem datas: do primeiro dia do mês até hoje.
     * @return array{0:string,1:string}
     * @throws InvalidArgumentException
     */
    public static function normalizarPeriodo(?string $inicio, ?string $fim, DateTimeImmutable $hoje): array {
        return PeriodoService::normalizar($inicio, $fim, $hoje, $hoje->format('Y-m-01'));
    }

    /**
     * Quantitativos por setor e por funcionário.
     *
     * @param int[]|null $escopo        setores (null = todos)
     * @param int|null   $somenteUsuario operador: só as respostas dele, sem a tabela por setor
     * @return array{periodo:array, janelas:array, setores:array, funcionarios:array}
     */
    public static function resumo(PDO $db, ?array $escopo, ?int $somenteUsuario, string $inicio, string $fim, DateTimeImmutable $hoje): array {
        $hojeTxt = $hoje->format('Y-m-d');
        $ontem = $hoje->modify('-1 day')->format('Y-m-d');
        $seteDias = $hoje->modify('-6 days')->format('Y-m-d');
        $trintaDias = $hoje->modify('-29 days')->format('Y-m-d');
        $desde = min($inicio, $trintaDias, $ontem);

        // Uma linha por resposta: dia, autor e setor da demanda naquele momento
        $base = "SELECT DATE(c.criado_em) AS dia, c.usuario_id,
                        COALESCE((SELECT l.setor_id FROM ticket_setor_log l
                                  WHERE l.ticket_id = c.ticket_id AND l.entrou_em <= c.criado_em
                                  ORDER BY l.entrou_em DESC, l.id DESC LIMIT 1), t.setor_id) AS setor_id
                 FROM comentarios c
                 JOIN tickets t ON t.id = c.ticket_id AND t.excluido_em IS NULL
                 JOIN usuarios u ON u.id = c.usuario_id AND u.tipo <> 'cliente'
                 WHERE c.visivel_solicitante = 1 AND c.interno = 0
                   AND c.criado_em >= :desde AND c.criado_em < DATE_ADD(:hoje, INTERVAL 1 DAY)"
            . ($somenteUsuario !== null ? ' AND c.usuario_id = ' . (int) $somenteUsuario : '');

        $somas = "SUM(r.dia = :ontem) AS ontem,
                  SUM(r.dia >= :sete) AS semana,
                  SUM(r.dia >= :trinta) AS mes,
                  SUM(r.dia BETWEEN :inicio AND :fim) AS periodo";
        $params = [':desde' => $desde, ':hoje' => $hojeTxt, ':ontem' => $ontem, ':sete' => $seteDias,
                   ':trinta' => $trintaDias, ':inicio' => $inicio, ':fim' => $fim];
        $filtroSetor = AcessoService::filtroSetorSql('r.setor_id', $escopo);

        $zeros = ['ontem' => 0, 'semana' => 0, 'mes' => 0, 'periodo' => 0];
        $numeros = function (array $l) {
            return ['ontem' => (int) $l['ontem'], 'semana' => (int) $l['semana'], 'mes' => (int) $l['mes'], 'periodo' => (int) $l['periodo']];
        };

        // ---- por setor (o operador não recebe esta tabela)
        $setores = [];
        if ($somenteUsuario === null) {
            // Setores ativos do escopo aparecem mesmo zerados (os de coordenação, só se tiverem respostas)
            $sql = "SELECT id, nome FROM setores s WHERE s.ativo = 1 AND s.setor_coordenacao = 0 AND "
                 . AcessoService::filtroSetorSql('s.id', $escopo);
            foreach ($db->query($sql) as $s) {
                $setores[(int) $s['id']] = ['setor_id' => (int) $s['id'], 'nome' => $s['nome']] + $zeros;
            }
            $stmt = $db->prepare("SELECT r.setor_id, s.nome, $somas FROM ($base) r
                                  LEFT JOIN setores s ON s.id = r.setor_id
                                  WHERE $filtroSetor GROUP BY r.setor_id, s.nome");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $id = (int) $l['setor_id'];
                $setores[$id] = ['setor_id' => $id, 'nome' => $l['nome'] ?? 'Sem setor'] + $numeros($l);
            }
        }

        // ---- por funcionário (gestor e operador)
        $papeis = AcessoService::listaSql(self::PAPEIS_FUNCIONARIO);
        $funcionarios = [];
        // Gestores e operadores ativos do escopo aparecem mesmo zerados
        $sql = "SELECT u.id, u.nome, u.tipo, s.nome AS setor_nome FROM usuarios u
                LEFT JOIN setores s ON s.id = u.setor_id
                WHERE u.ativo = 1 AND u.tipo IN ($papeis) AND " . AcessoService::filtroSetorSql('u.setor_id', $escopo)
             . ($somenteUsuario !== null ? ' AND u.id = ' . (int) $somenteUsuario : '');
        foreach ($db->query($sql) as $u) {
            $funcionarios[(int) $u['id']] = ['usuario_id' => (int) $u['id'], 'nome' => $u['nome'], 'tipo' => $u['tipo'],
                                             'setor_nome' => $u['setor_nome']] + $zeros;
        }
        // Respostas nos setores do escopo (inclui quem hoje está lotado em outro setor ou inativo)
        $stmt = $db->prepare("SELECT r.usuario_id, u.nome, u.tipo, s.nome AS setor_nome, $somas FROM ($base) r
                              JOIN usuarios u ON u.id = r.usuario_id AND u.tipo IN ($papeis)
                              LEFT JOIN setores s ON s.id = u.setor_id
                              WHERE $filtroSetor GROUP BY r.usuario_id, u.nome, u.tipo, s.nome");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $id = (int) $l['usuario_id'];
            $funcionarios[$id] = ['usuario_id' => $id, 'nome' => $l['nome'], 'tipo' => $l['tipo'],
                                  'setor_nome' => $l['setor_nome']] + $numeros($l);
        }

        // Mais respostas no período primeiro; empate pelo nome
        $ordenar = function (array $lista) {
            usort($lista, function ($a, $b) {
                return [$b['periodo'], $b['mes'], $a['nome']] <=> [$a['periodo'], $a['mes'], $b['nome']];
            });
            return $lista;
        };

        return [
            'periodo' => ['inicio' => $inicio, 'fim' => $fim],
            'janelas' => ['ontem' => $ontem, 'semana_inicio' => $seteDias, 'mes_inicio' => $trintaDias, 'hoje' => $hojeTxt],
            'setores' => $ordenar(array_values($setores)),
            'funcionarios' => $ordenar(array_values($funcionarios)),
        ];
    }
}
