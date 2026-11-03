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

    /** Auditoria 01/11/2026, BUG-02: resolver direto de um status que pausa desconta a pausa em andamento. */
    public function test_resolver_a_partir_de_aguardando_desconta_a_pausa_em_andamento(): void {
        $this->mudar('em_atendimento');
        $this->mudar('aguardando_resposta');
        $sla = new SLAService($this->db);
        if (!$sla->congelaSla('aguardando_resposta') || !$sla->congelaSla('resolvido')) {
            $this->markTestSkipped('O catálogo local não congela o SLA nesses status.');
        }
        // Toda a vida da demanda (3 dias) em pausa ainda aberta
        $criado = $this->demanda()['criado_em'];
        $this->db->prepare("UPDATE ticket_sla SET pausado = TRUE, pausado_em = ?, tempo_pausado_segundos = 0 WHERE ticket_id = ?")
                 ->execute([$criado, $this->ticket]);
        if ($sla->calcularSegundosUteis($criado, date('Y-m-d H:i:s'), $this->setor) <= 0) {
            $this->markTestSkipped('Sem horário útil nos últimos 3 dias para medir.');
        }

        $this->mudar('resolvido');

        // Antes: o trecho pausado entrava como tempo trabalhado (resolução = os 3 dias úteis)
        $this->assertLessThanOrEqual(5, (int) $this->sla()['tempo_resolucao_segundos']);
    }

    /** Auditoria 01/11/2026, BUG-04: descrição com imagem grande não impede mais o registro de auditoria. */
    public function test_auditoria_sem_a_descricao_grava_mesmo_com_imagem_grande(): void {
        $descricao = '<p>zz</p><img src="data:image/png;base64,' . str_repeat('QUJD', 50000) . '">'; // ~200 KB
        $this->db->prepare("UPDATE tickets SET descricao = ? WHERE id = ?")->execute([$descricao, $this->ticket]);

        $resumo = DemandaService::resumoParaAuditoria($this->db, $this->ticket);
        $this->assertArrayNotHasKey('descricao', $resumo);
        $this->assertSame(hash('sha256', $descricao), $resumo['descricao_sha256']);
        $this->assertLessThan(1000, strlen(json_encode($resumo)));

        $this->assertTrue((new AuditoriaService($this->db))->registrar($this->autor, 'excluir_ticket_definitivo', 'tickets', $this->ticket, json_encode($resumo), null));
        $linha = $this->db->prepare("SELECT dados_anteriores FROM auditoria WHERE acao = 'excluir_ticket_definitivo' AND registro_id = ? ORDER BY id DESC LIMIT 1");
        $linha->execute([$this->ticket]);
        $this->assertStringContainsString('"titulo":"zz fluxo"', (string) $linha->fetchColumn());
    }

    /** Auditoria 01/11/2026, BUG-09: o lembrete vencido retoma a demanda pela regra única de status. */
    public function test_lembrete_retoma_aguardando_data_pela_regra_unica(): void {
        require_once __DIR__ . '/../../services/lembrete-service.php';
        require_once __DIR__ . '/../../services/catalogo-service.php';
        if (!CatalogoService::statusPermitido($this->db, 'em_atendimento', $this->setor)) {
            $this->markTestSkipped('"em_atendimento" não está ativo no setor de teste.');
        }
        $this->mudar('em_atendimento');
        $this->mudar('aguardando_data');

        $m = new ReflectionMethod(LembreteService::class, 'retomarSeAguardandoData');
        $m->invoke(null, $this->db, ['ticket_id' => $this->ticket, 'ticket_status' => 'aguardando_data',
                                    'ticket_setor_id' => $this->setor, 'criado_por' => $this->autor]);

        $this->assertSame('em_atendimento', $this->demanda()['status']);
        $h = $this->db->prepare("SELECT COUNT(*) FROM ticket_historico WHERE ticket_id = ? AND acao = 'status' AND valor_anterior = 'aguardando_data' AND valor_novo = 'em_atendimento'");
        $h->execute([$this->ticket]);
        $this->assertSame(1, (int) $h->fetchColumn());

        // Status mudou antes do lembrete vencer: não mexe
        $this->mudar('nova_resposta', ['resposta_da_equipe' => false]);
        $m->invoke(null, $this->db, ['ticket_id' => $this->ticket, 'ticket_status' => 'aguardando_data',
                                    'ticket_setor_id' => $this->setor, 'criado_por' => $this->autor]);
        $this->assertSame('nova_resposta', $this->demanda()['status']);
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
