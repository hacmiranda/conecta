<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/email-fila-service.php';

/**
 * Fila de e-mails (auditoria 01/11/2026, SEG-03): o e-mail de recuperação de senha não fica guardado
 * com o link (token em claro) depois de enviado. Banco local, transação desfeita no fim.
 */
final class EmailFilaTest extends TestCase {

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

    private function enfileirar(string $tipo, string $corpo): array {
        $this->db->prepare("INSERT INTO email_fila (tipo, destinatario, assunto, corpo, eh_html) VALUES (?, 'zz@teste.local', 'zz', ?, 1)")
                 ->execute([$tipo, $corpo]);
        $id = (int) $this->db->lastInsertId();
        $s = $this->db->prepare("SELECT * FROM email_fila WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    private function linha(int $id): array {
        $s = $this->db->prepare("SELECT status, corpo FROM email_fila WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    public function test_recuperacao_de_senha_enviada_fica_sem_o_corpo(): void {
        $msg = $this->enfileirar('recuperacao_senha', '<a href="https://x/redefinir-senha.html#token=abc123">Redefinir</a>');
        EmailFilaService::marcarEnviado($this->db, $msg);
        $linha = $this->linha((int) $msg['id']);
        $this->assertSame('enviado', $linha['status']);
        $this->assertSame('', $linha['corpo'], 'o token em claro não fica no banco');
    }

    public function test_outros_tipos_mantem_o_corpo(): void {
        $msg = $this->enfileirar('novo_ticket', '<p>Demanda criada</p>');
        EmailFilaService::marcarEnviado($this->db, $msg);
        $this->assertSame('<p>Demanda criada</p>', $this->linha((int) $msg['id'])['corpo']);
    }
}
