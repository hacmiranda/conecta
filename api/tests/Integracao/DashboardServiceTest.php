<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/dashboard-service.php';

/**
 * Dashboard com banco: o que conta no período, no período anterior e na fotografia de agora.
 * Banco local em transação desfeita; tudo filtrado por um setor de teste criado aqui.
 */
final class DashboardServiceTest extends TestCase {

    private PDO $db;
    private int $setor;
    private int $cliente;
    private int $operador;
    private DateTimeImmutable $hoje;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
        $this->hoje = new DateTimeImmutable('today');
        $this->db->exec("INSERT INTO setores (nome, ativo) VALUES ('zz Dashboard " . bin2hex(random_bytes(3)) . "', 1)");
        $this->setor = (int) $this->db->lastInsertId();
        $this->cliente = $this->usuario('cliente', null);
        $this->operador = $this->usuario('operador', $this->setor);
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function usuario(string $tipo, ?int $setor): int {
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha) VALUES (?, ?, 'x', ?, ?, 1, 0)")
                 ->execute(['zz Dash ' . $tipo, 'zz.dash.' . bin2hex(random_bytes(4)) . '@teste.local', $tipo, $setor]);
        return (int) $this->db->lastInsertId();
    }

    private function dia(int $diasAtras): string {
        return $this->hoje->modify("-$diasAtras days")->format('Y-m-d') . ' 10:00:00';
    }

    /** Demanda criada há N dias; $resolvidaHa = dias atrás em que foi concluída (ou null) */
    private function demanda(int $criadaHa, string $status = 'em_atendimento', ?int $resolvidaHa = null, array $extra = []): int {
        $this->db->prepare("INSERT INTO tickets (titulo, descricao, usuario_id, agente_id, status, prioridade, setor_id, criado_em, origem_api, mesclada_em)
                            VALUES ('zz', 'zz', ?, ?, ?, ?, ?, ?, ?, ?)")
                 ->execute([$this->cliente, array_key_exists('agente', $extra) ? $extra['agente'] : $this->operador, $status, $extra['prioridade'] ?? 'normal', $this->setor,
                            $this->dia($criadaHa), $extra['origem'] ?? null, $extra['mesclada'] ?? null]);
        $id = (int) $this->db->lastInsertId();
        $this->db->prepare("INSERT INTO ticket_sla (ticket_id, resolvido_em) VALUES (?, ?)")
                 ->execute([$id, $resolvidaHa === null ? null : $this->dia($resolvidaHa)]);
        return $id;
    }

    private function servico(): DashboardService {
        return new DashboardService($this->db, ' AND t.excluido_em IS NULL AND t.setor_id = ' . $this->setor,
            ['recorte_setor' => true, 'escopo' => [$this->setor], 'operador_id' => null]);
    }

    public function test_indicadores_do_periodo_e_do_anterior(): void {
        $this->demanda(2);                                     // criada no período, ativa
        $this->demanda(3, 'encerrado', 1);                     // criada e concluída no período
        $this->demanda(5, 'em_atendimento', null, ['origem' => 'portal']); // externa
        $this->demanda(40, 'resolvido', 4);                    // criada antes, concluída no período
        $this->demanda(12, 'em_atendimento');                  // período anterior
        $this->demanda(4, 'cancelado');                        // cancelada: não conta
        $principal = $this->demanda(6);
        $this->demanda(6, 'cancelado', null, ['mesclada' => $principal]); // mesclada: não conta

        $inicio = $this->hoje->modify('-9 days')->format('Y-m-d');
        $r = $this->servico()->montar($inicio, $this->hoje->format('Y-m-d'));
        $i = $r['indicadores'];

        $this->assertSame(4, $i['criadas']['atual']);          // 2, 3, 5 e 6 dias
        $this->assertSame(1, $i['criadas']['anterior']);       // a de 12 dias
        $this->assertSame(2, $i['concluidas']['atual']);       // concluídas há 1 e 4 dias
        $this->assertSame(25.0, $i['taxa_conclusao']['atual']); // 1 das 4 criadas
        $this->assertSame(1, $i['externas']['atual']);
        $this->assertSame(100.0, $i['fcr']['atual']);          // ninguém comentou de novo
        $this->assertSame(['inicio' => $this->hoje->modify('-19 days')->format('Y-m-d'), 'fim' => $this->hoje->modify('-10 days')->format('Y-m-d')],
            $r['periodo']['anterior']);

        $serie = $r['serie'];
        $this->assertSame('dia', $serie['granularidade']);
        $this->assertCount(10, $serie['pontos']);
        $this->assertSame(4, array_sum(array_column($serie['pontos'], 'criadas')));
        $this->assertSame(2, array_sum(array_column($serie['pontos'], 'concluidas')));
        $this->assertSame(4, array_sum($r['periodo_dados']['por_dia_semana']));
    }

    public function test_fotografia_de_agora_ignora_o_periodo(): void {
        $this->demanda(200, 'novo', null, ['prioridade' => 'alta']);
        $this->demanda(2, 'aguardando');
        $this->demanda(2, 'aguardando', null, ['agente' => null]);
        $this->demanda(3, 'encerrado', 1);                    // arquivada: fora de "agora"

        $hoje = $this->hoje->format('Y-m-d');
        $agora = $this->servico()->montar($hoje, $hoje)['agora'];

        $this->assertSame(3, $agora['ativas']);
        $this->assertSame(1, $agora['sem_responsavel']);
        $this->assertEquals(['aguardando' => 2, 'novo' => 1], $agora['por_status']); // a ordem não importa
        $this->assertSame(1, $agora['por_prioridade']['alta']);
        $this->assertSame([['nome' => 'zz Dash operador', 'total' => 2]], $agora['por_responsavel']);
    }
}
