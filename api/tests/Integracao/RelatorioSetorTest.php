<?php
use PHPUnit\Framework\TestCase;

/**
 * Esquema da migração 43 (setor no registro de atividades e relatório do setor enviado pelo coordenador).
 * Banco local em transação desfeita; recusa produção. Não executa DDL (faria commit implícito).
 */
final class RelatorioSetorTest extends TestCase {

    private PDO $db;
    private int $setor;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
        $this->setor = (int) $this->db->query("SELECT id FROM setores WHERE ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($this->setor <= 0) {
            $this->markTestSkipped('Precisa de um setor ativo.');
        }
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function criarUsuario(int $setor): int {
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha) VALUES ('zz Relatorio', ?, 'x', 'operador', ?, 1, 0)")
                 ->execute(['zz.relsetor.' . bin2hex(random_bytes(4)) . '@teste.local', $setor]);
        return (int) $this->db->lastInsertId();
    }

    public function test_migracao_preencheu_setor_dos_registros_existentes(): void {
        $nulos = (int) $this->db->query("SELECT COUNT(*) FROM registros_atividades WHERE setor_id IS NULL")->fetchColumn();
        $this->assertSame(0, $nulos);

        $porRegistro = $this->db->query("SELECT r.setor_id, SUM(i.quantidade) AS q FROM registros_atividades r
            JOIN registros_atividades_itens i ON i.registro_id = r.id GROUP BY r.setor_id ORDER BY r.setor_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $porUsuario = $this->db->query("SELECT u.setor_id, SUM(i.quantidade) AS q FROM registros_atividades r
            JOIN usuarios u ON u.id = r.usuario_id JOIN registros_atividades_itens i ON i.registro_id = r.id
            WHERE u.setor_id = r.setor_id GROUP BY u.setor_id ORDER BY u.setor_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertEquals($porUsuario, $porRegistro);
    }

    public function test_chave_setor_impede_dois_relatorios_do_mesmo_setor_e_periodo(): void {
        $a = $this->criarUsuario($this->setor);
        $b = $this->criarUsuario($this->setor);
        $sql = "INSERT INTO registros_atividades (usuario_id, setor_id, periodo, data_referencia, status) VALUES (?, ?, 'semana', '2026-08-10', 'rascunho')";
        $this->db->prepare($sql)->execute([$a, $this->setor]);

        try {
            $this->db->prepare($sql)->execute([$b, $this->setor]);
            $this->fail('Era esperada uma violação de unicidade.');
        } catch (PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
    }

    public function test_relatorios_diarios_do_mesmo_setor_continuam_livres(): void {
        $a = $this->criarUsuario($this->setor);
        $b = $this->criarUsuario($this->setor);
        $sql = "INSERT INTO registros_atividades (usuario_id, setor_id, data_referencia) VALUES (?, ?, '2026-08-10')";
        $this->db->prepare($sql)->execute([$a, $this->setor]);
        $this->db->prepare($sql)->execute([$b, $this->setor]);
        $this->assertTrue(true);
    }
}
