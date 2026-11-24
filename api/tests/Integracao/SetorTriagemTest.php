<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/setor-service.php';

/**
 * Setor de triagem (para onde vai a demanda sem setor de destino): o configurado no .env, senão o Geral,
 * e só se estiver ativo. Banco local em transação desfeita; recusa produção.
 */
final class SetorTriagemTest extends TestCase {

    private PDO $db;
    private int $geral;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
        $geral = $this->db->query("SELECT id FROM setores WHERE nome = 'Geral' LIMIT 1")->fetchColumn();
        if (!$geral) {
            $this->markTestSkipped('Precisa do setor Geral.');
        }
        $this->geral = (int) $geral;
        $this->db->prepare("UPDATE setores SET ativo = 1 WHERE id = ?")->execute([$this->geral]);
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function criarSetor(bool $ativo): int {
        $this->db->prepare("INSERT INTO setores (nome, ativo) VALUES (?, ?)")
                 ->execute(['zz Triagem ' . bin2hex(random_bytes(3)), $ativo ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    public function test_sem_configuracao_usa_o_geral_ativo(): void {
        $this->assertSame($this->geral, SetorService::setorDeTriagem($this->db, ''));
    }

    public function test_setor_configurado_e_ativo_vence_o_geral(): void {
        $setor = $this->criarSetor(true);
        $this->assertSame($setor, SetorService::setorDeTriagem($this->db, (string) $setor));
    }

    public function test_setor_configurado_inativo_ou_inexistente_cai_no_geral(): void {
        $inativo = $this->criarSetor(false);
        $this->assertSame($this->geral, SetorService::setorDeTriagem($this->db, (string) $inativo));
        $this->assertSame($this->geral, SetorService::setorDeTriagem($this->db, '999999999'));
        $this->assertSame($this->geral, SetorService::setorDeTriagem($this->db, 'abc'));
    }

    public function test_geral_desativado_e_nada_configurado_devolve_null(): void {
        $this->db->prepare("UPDATE setores SET ativo = 0 WHERE id = ?")->execute([$this->geral]);
        $this->assertNull(SetorService::setorDeTriagem($this->db, ''));
    }

    public function test_geral_desativado_com_setor_configurado_usa_o_configurado(): void {
        $this->db->prepare("UPDATE setores SET ativo = 0 WHERE id = ?")->execute([$this->geral]);
        $setor = $this->criarSetor(true);
        $this->assertSame($setor, SetorService::setorDeTriagem($this->db, (string) $setor));
    }

    public function test_setor_novo_herda_o_expediente_do_geral_mesmo_desativado(): void {
        $this->db->prepare("UPDATE setores SET ativo = 0 WHERE id = ?")->execute([$this->geral]);
        $esperado = (int) $this->db->query("SELECT COUNT(*) FROM horarios_uteis WHERE setor_id = {$this->geral}")->fetchColumn();
        $novo = SetorService::criar($this->db, 'zz Novo ' . bin2hex(random_bytes(3)));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM horarios_uteis WHERE setor_id = ?");
        $stmt->execute([$novo]);
        $this->assertSame($esperado, (int) $stmt->fetchColumn());
    }
}
