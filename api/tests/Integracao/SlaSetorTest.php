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
}
