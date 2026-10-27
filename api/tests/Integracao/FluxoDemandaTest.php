<?php
use PHPUnit\Framework\TestCase;

/**
 * Máquina de estados da demanda (DemandaService): SLA (pausa, primeira resposta, resolução, reabertura),
 * histórico e janelas. Roda no banco local, dentro de uma transação desfeita no fim de cada teste
 * (nada fica gravado). Recusa rodar em produção.
 */
final class FluxoDemandaTest extends TestCase {

    private PDO $db;
    private int $ticket;
    private int $autor;
    private int $setor;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();

        $this->setor = (int) $this->db->query("SELECT id FROM setores WHERE ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha) VALUES ('zz Fluxo', ?, 'x', 'gestor', ?, 1, 0)")
                 ->execute(['zz.fluxo.' . bin2hex(random_bytes(4)) . '@teste.local', $this->setor]);
        $this->autor = (int) $this->db->lastInsertId();
        $this->db->prepare("INSERT INTO tickets (titulo, descricao, usuario_id, status, prioridade, setor_id, criado_em)
                            VALUES ('zz fluxo', '<p>zz</p>', ?, 'novo', 'normal', ?, DATE_SUB(NOW(), INTERVAL 3 DAY))")
                 ->execute([$this->autor, $this->setor]);
        $this->ticket = (int) $this->db->lastInsertId();
        $this->db->prepare("INSERT INTO ticket_sla (ticket_id) VALUES (?)")->execute([$this->ticket]);
        $this->db->prepare("INSERT INTO ticket_setor_log (ticket_id, setor_id) VALUES (?, ?)")->execute([$this->ticket, $this->setor]);
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function sla(): array {
        $s = $this->db->prepare("SELECT * FROM ticket_sla WHERE ticket_id = ?");
        $s->execute([$this->ticket]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    private function demanda(): array {
        $s = $this->db->prepare("SELECT * FROM tickets WHERE id = ?");
        $s->execute([$this->ticket]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    private function mudar(string $status, array $opcoes = []): array {
        return DemandaService::mudarStatus($this->db, $this->ticket, $status, $this->autor, $opcoes);
    }

    public function test_sair_de_novo_pela_equipe_marca_a_primeira_resposta(): void {
        $this->mudar('em_atendimento');
        $this->assertNotNull($this->sla()['primeira_resposta_em']);
    }

    public function test_resposta_do_solicitante_nao_conta_como_primeira_resposta(): void {
        $this->mudar('nova_resposta', ['resposta_da_equipe' => false]);
        $this->assertNull($this->sla()['primeira_resposta_em']);
    }

    public function test_pausa_e_retomada_quando_o_solicitante_responde(): void {
        $this->mudar('em_atendimento');
        $this->mudar('aguardando_resposta');
        $this->assertNotNull($this->sla()['pausado_em'], 'aguardando resposta pausa o SLA');

        // A resposta do solicitante (e-mail) passa por mudarStatus e retoma o SLA
        $this->mudar('nova_resposta', ['resposta_da_equipe' => false]);
        $sla = $this->sla();
        $this->assertNull($sla['pausado_em'], 'nova resposta retoma o SLA');
        $this->assertSame(0, (int) $sla['pausado']);
        $this->assertGreaterThanOrEqual(0, (int) $sla['tempo_pausado_segundos']);
    }

    public function test_encerrar_e_reabrir_volta_a_contar_o_sla(): void {
        $this->mudar('em_atendimento');
        $this->mudar('encerrado');
        $this->assertNotNull($this->sla()['resolvido_em']);
        $this->assertNotNull($this->demanda()['fechado_em']);

        $marcas = $this->mudar('reaberto');
        $this->assertTrue($marcas['reaberto']);
        $this->assertNull($this->sla()['resolvido_em'], 'reabertura apaga a resolução: o SLA volta a contar');
        $d = $this->demanda();
        $this->assertNull($d['fechado_em']);
        $this->assertSame(1, (int) $d['reaberto_contador']);

        $sla = (new SLAService($this->db))->situacao($d, $this->sla(), $this->setor);
        $this->assertNotNull($sla, 'demanda reaberta volta a ter selo de SLA');

        $janela = $this->db->prepare("SELECT COUNT(*) FROM ticket_setor_log WHERE ticket_id = ? AND saiu_em IS NULL");
        $janela->execute([$this->ticket]);
        $this->assertSame(1, (int) $janela->fetchColumn(), 'janela do setor reaberta');
    }

    public function test_resolvido_depois_encerrado_mantem_a_data_da_resolucao(): void {
        $this->mudar('em_atendimento');
        $this->mudar('resolvido');
        $this->db->prepare("UPDATE ticket_sla SET resolvido_em = '2026-01-02 10:00:00' WHERE ticket_id = ?")->execute([$this->ticket]);
        $this->mudar('encerrado');
        $this->assertSame('2026-01-02 10:00:00', $this->sla()['resolvido_em']);
    }

    public function test_mesmo_status_nao_gera_historico(): void {
        $this->assertSame([], $this->mudar('novo'));
        $h = $this->db->prepare("SELECT COUNT(*) FROM ticket_historico WHERE ticket_id = ? AND acao = 'status'");
        $h->execute([$this->ticket]);
        $this->assertSame(0, (int) $h->fetchColumn());
    }

    public function test_marcas_para_depois_do_commit(): void {
        $this->mudar('em_atendimento');
        $marcas = $this->mudar('encerrado');
        $this->assertTrue($marcas['encerrado']);
        $this->assertTrue($marcas['pesquisa']);
        $this->assertFalse($marcas['reaberto']);
    }
}
