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

    public function test_migracao_deixou_o_setor_de_ti_igual_ao_catalogo(): void {
        $setor = $this->db->query("SELECT id, sla_ativo FROM setores WHERE nome = 'Setor de TI'")->fetch(PDO::FETCH_ASSOC);
        if (!$setor) {
            $this->markTestSkipped('Banco sem o Setor de TI.');
        }
        $this->assertSame(1, (int) $setor['sla_ativo']);

        $linhas = $this->db->prepare("SELECT sp.*, p.sla_resposta_minutos AS cat_resp, p.sla_resolucao_horas AS cat_resol, p.sem_sla AS cat_sem
            FROM setor_prioridades sp JOIN ticket_prioridades p ON p.id = sp.prioridade_id WHERE sp.setor_id = ?");
        $linhas->execute([(int) $setor['id']]);
        $todas = $linhas->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($todas);
        foreach ($todas as $l) {
            $this->assertSame(0, (int) $l['sla_resposta_dias']);
            $this->assertSame((int) $l['cat_resp'], (int) $l['sla_resposta_minutos']);
            $this->assertSame(0, (int) $l['sla_resolucao_dias']);
            $this->assertSame((int) $l['cat_resol'] * 60, (int) $l['sla_resolucao_minutos']);
            $this->assertSame((int) $l['cat_sem'], (int) $l['sla_sem_prazo']);
        }
    }

    public function test_migracao_desligou_os_demais_setores(): void {
        $outros = (int) $this->db->query("SELECT COUNT(*) FROM setores WHERE nome <> 'Setor de TI' AND sla_ativo = 1")->fetchColumn();
        $this->assertSame(0, $outros);
    }

    public function test_demandas_abertas_de_setor_desligado_estao_pausadas(): void {
        $naoPausadas = (int) $this->db->query("SELECT COUNT(*) FROM tickets t
            JOIN setores st ON st.id = t.setor_id
            JOIN ticket_sla s ON s.ticket_id = t.id
            WHERE st.sla_ativo = 0 AND t.excluido_em IS NULL AND t.mesclada_em IS NULL
              AND t.status NOT IN ('resolvido','encerrado','cancelado','fechado')
              AND s.pausado_em IS NULL")->fetchColumn();
        $this->assertSame(0, $naoPausadas);
    }
}
