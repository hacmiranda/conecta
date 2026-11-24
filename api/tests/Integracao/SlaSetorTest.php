<?php
use PHPUnit\Framework\TestCase;

/**
 * Esquema da migração 44 (SLA por setor: interruptor e prazos por prioridade do setor).
 * Banco local em transação desfeita; recusa produção. Não executa DDL (faria commit implícito).
 */
final class SlaSetorTest extends TestCase {

    private PDO $db;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function criarSetor(): int {
        $this->db->prepare("INSERT INTO setores (nome, ativo) VALUES (?, 1)")->execute(['zz_sla_' . bin2hex(random_bytes(4))]);
        return (int) $this->db->lastInsertId();
    }

    public function test_setor_novo_nasce_com_sla_desligado(): void {
        $id = $this->criarSetor();
        $st = $this->db->prepare("SELECT sla_ativo FROM setores WHERE id = ?");
        $st->execute([$id]);
        $this->assertSame(0, (int) $st->fetchColumn());
    }

    public function test_prioridade_do_setor_nasce_sem_prazo(): void {
        $id = $this->criarSetor();
        $prio = (int) $this->db->query("SELECT id FROM ticket_prioridades ORDER BY id LIMIT 1")->fetchColumn();
        $this->db->prepare("INSERT INTO setor_prioridades (setor_id, prioridade_id, ativo, ordem) VALUES (?, ?, 1, 1)")->execute([$id, $prio]);
        $st = $this->db->prepare("SELECT * FROM setor_prioridades WHERE setor_id = ? AND prioridade_id = ?");
        $st->execute([$id, $prio]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        foreach (['sla_resposta_dias', 'sla_resposta_minutos', 'sla_resolucao_dias', 'sla_resolucao_minutos'] as $c) {
            $this->assertNull($l[$c], $c);
        }
        $this->assertSame(0, (int) $l['sla_sem_prazo']);
    }

    // ---- Pausas ao ligar/desligar, na abertura e na transferência (Tarefa 3) ----

    private int $usuarioTeste = 0;

    /** Setor de teste com expediente "sempre aberto" (tempo útil previsível) e prazo de 1 h / 1 dia na normal e na baixa. */
    private function setorComSla(bool $ativo): int {
        $id = $this->criarSetor();
        $this->db->prepare("UPDATE setores SET sla_ativo = ? WHERE id = ?")->execute([$ativo ? 1 : 0, $id]);
        for ($dia = 1; $dia <= 7; $dia++) {
            $this->db->prepare("INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo) VALUES (?, ?, '00:00:00', '23:59:59', 1)")
                     ->execute([$id, $dia]);
        }
        foreach (['normal', 'baixa'] as $nome) {
            $prio = (int) $this->db->query("SELECT id FROM ticket_prioridades WHERE nome = '$nome'")->fetchColumn();
            $this->db->prepare("INSERT INTO setor_prioridades (setor_id, prioridade_id, ativo, ordem, sla_sem_prazo,
                                sla_resposta_dias, sla_resposta_minutos, sla_resolucao_dias, sla_resolucao_minutos)
                                VALUES (?, ?, 1, 1, 0, 0, 60, 1, 0)")->execute([$id, $prio]);
        }
        return $id;
    }

    /** Demanda de teste criada há $dias dias, com abertura registrada (como o sistema faz). */
    private function criarDemanda(int $setor, string $status = 'novo', string $prioridade = 'normal', int $dias = 10): int {
        if ($this->usuarioTeste === 0) {
            $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha) VALUES ('zz Sla', ?, 'x', 'gestor', ?, 1, 0)")
                     ->execute(['zz.sla.' . bin2hex(random_bytes(4)) . '@teste.local', $setor]);
            $this->usuarioTeste = (int) $this->db->lastInsertId();
        }
        $this->db->prepare("INSERT INTO tickets (titulo, descricao, usuario_id, status, prioridade, setor_id, criado_em)
                            VALUES ('zz sla', '<p>zz</p>', ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))")
                 ->execute([$this->usuarioTeste, $status, $prioridade, $setor, $dias]);
        $id = (int) $this->db->lastInsertId();
        AcessoService::registrarAbertura($this->db, $id, $setor, $this->usuarioTeste, 'no sistema');
        return $id;
    }

    private function linhaSla(int $ticket): array {
        $st = $this->db->prepare("SELECT * FROM ticket_sla WHERE ticket_id = ?");
        $st->execute([$ticket]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    private function situacao(int $ticket): ?array {
        $st = $this->db->prepare("SELECT criado_em, status, prioridade, setor_id FROM tickets WHERE id = ?");
        $st->execute([$ticket]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        return (new SLAService($this->db))->situacao($t, $this->linhaSla($ticket), (int) $t['setor_id']);
    }

    public function test_demanda_nova_em_setor_desligado_nasce_pausada(): void {
        $setor = $this->setorComSla(false);
        $id = $this->criarDemanda($setor);
        $this->assertNotNull($this->linhaSla($id)['pausado_em']);
        $this->assertNull($this->situacao($id));
    }

    public function test_demanda_nova_em_setor_ligado_nasce_contando(): void {
        $setor = $this->setorComSla(true);
        $id = $this->criarDemanda($setor, 'novo', 'normal', 0);
        $this->assertNull($this->linhaSla($id)['pausado_em']);
    }

    public function test_desligar_pausa_as_abertas_e_some_o_selo(): void {
        $setor = $this->setorComSla(true);
        $id = $this->criarDemanda($setor, 'novo', 'normal', 2);
        $this->assertNotNull($this->situacao($id));
        $this->db->prepare("UPDATE setores SET sla_ativo = 0 WHERE id = ?")->execute([$setor]);
        $this->assertSame(1, DemandaService::aplicarSlaDoSetor($this->db, $setor));
        $this->assertNotNull($this->linhaSla($id)['pausado_em']);
        $this->assertNull($this->situacao($id));
        // Repetir não muda mais nada
        $this->assertSame(0, DemandaService::aplicarSlaDoSetor($this->db, $setor));
    }

    public function test_ligar_retoma_sem_contar_o_periodo_desligado(): void {
        $setor = $this->setorComSla(false);
        $id = $this->criarDemanda($setor, 'novo', 'normal', 10);
        $this->db->prepare("UPDATE setores SET sla_ativo = 1 WHERE id = ?")->execute([$setor]);
        $this->assertSame(1, DemandaService::aplicarSlaDoSetor($this->db, $setor));
        $sla = $this->linhaSla($id);
        $this->assertNull($sla['pausado_em']);
        $this->assertGreaterThan(9 * 86400, (int) $sla['tempo_pausado_segundos']);
        $sit = $this->situacao($id);
        $this->assertFalse($sit['vencido']);
        $this->assertSame('resposta', $sit['tipo']);
        $this->assertGreaterThan(3500, $sit['segundos_restantes']);
        $this->assertLessThanOrEqual(3600, $sit['segundos_restantes']);
    }

    public function test_ligar_mantem_pausada_a_demanda_em_status_que_congela(): void {
        $status = (string) $this->db->query("SELECT nome FROM ticket_status WHERE congela_sla = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $this->assertNotSame('', $status);
        $setor = $this->setorComSla(false);
        $id = $this->criarDemanda($setor, $status, 'normal', 5);
        $this->db->prepare("UPDATE setores SET sla_ativo = 1 WHERE id = ?")->execute([$setor]);
        $this->assertSame(0, DemandaService::aplicarSlaDoSetor($this->db, $setor));
        $this->assertNotNull($this->linhaSla($id)['pausado_em']);
    }

    public function test_transferir_para_setor_desligado_pausa_e_voltar_retoma(): void {
        $ligado = $this->setorComSla(true);
        $desligado = $this->setorComSla(false);
        $id = $this->criarDemanda($ligado, 'novo', 'normal', 3);
        $this->assertNull($this->linhaSla($id)['pausado_em']);

        $this->db->prepare("UPDATE tickets SET setor_id = ? WHERE id = ?")->execute([$desligado, $id]);
        $this->assertTrue(DemandaService::conferirPausaSla($this->db, $id));
        $this->assertNotNull($this->linhaSla($id)['pausado_em']);

        // Passou um dia parada no setor desligado; ao voltar, esse tempo não conta
        $this->db->prepare("UPDATE ticket_sla SET pausado_em = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE ticket_id = ?")->execute([$id]);
        $this->db->prepare("UPDATE tickets SET setor_id = ? WHERE id = ?")->execute([$ligado, $id]);
        $this->assertTrue(DemandaService::conferirPausaSla($this->db, $id));
        $sla = $this->linhaSla($id);
        $this->assertNull($sla['pausado_em']);
        $this->assertGreaterThan(80000, (int) $sla['tempo_pausado_segundos']);
    }

    public function test_marcar_prioridade_sem_prazo_pausa_so_ela(): void {
        $setor = $this->setorComSla(true);
        $normal = $this->criarDemanda($setor, 'novo', 'normal', 1);
        $baixa = $this->criarDemanda($setor, 'novo', 'baixa', 1);
        $this->db->prepare("UPDATE setor_prioridades sp JOIN ticket_prioridades p ON p.id = sp.prioridade_id
                            SET sp.sla_sem_prazo = 1 WHERE sp.setor_id = ? AND p.nome = 'baixa'")->execute([$setor]);
        $this->assertSame(1, DemandaService::aplicarSlaDoSetor($this->db, $setor, 'baixa'));
        $this->assertNotNull($this->linhaSla($baixa)['pausado_em']);
        $this->assertNull($this->linhaSla($normal)['pausado_em']);
    }

    public function test_conferir_cria_a_linha_de_sla_que_faltava(): void {
        $setor = $this->setorComSla(false);
        $id = $this->criarDemanda($setor);
        $this->db->prepare("DELETE FROM ticket_sla WHERE ticket_id = ?")->execute([$id]);
        $this->assertTrue(DemandaService::conferirPausaSla($this->db, $id));
        $this->assertNotNull($this->linhaSla($id)['pausado_em']);
    }
}
