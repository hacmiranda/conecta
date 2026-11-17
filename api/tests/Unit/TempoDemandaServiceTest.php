<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/tempo-demanda-service.php';

/**
 * Tempo trabalhado na demanda (17/11/2026): validação de um lançamento (início/fim ou duração).
 */
final class TempoDemandaServiceTest extends TestCase {

    private const HOJE = '2026-11-17';

    private function erro(array $entrada): string {
        try {
            TempoDemandaService::normalizar($entrada, self::HOJE);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $this->fail('Esperava erro de validação');
    }

    public function test_inicio_e_fim_calculam_os_minutos(): void {
        $r = TempoDemandaService::normalizar(['data_trabalho' => '2026-11-17', 'hora_inicio' => '8:30', 'hora_fim' => '10:15', 'descricao' => ' Análise '], self::HOJE);
        $this->assertSame(['data_trabalho' => '2026-11-17', 'hora_inicio' => '08:30:00', 'hora_fim' => '10:15:00', 'minutos' => 105, 'descricao' => 'Análise'], $r);
    }

    public function test_so_a_duracao(): void {
        $r = TempoDemandaService::normalizar(['data_trabalho' => '2026-11-10', 'duracao' => '1:30'], self::HOJE);
        $this->assertSame(90, $r['minutos']);
        $this->assertNull($r['hora_inicio']);
        $this->assertNull($r['descricao']);
        $this->assertSame(45, TempoDemandaService::normalizar(['data_trabalho' => '2026-11-10', 'minutos' => 45], self::HOJE)['minutos']);
    }

    public function test_recusa_o_que_nao_faz_sentido(): void {
        $this->assertStringContainsString('data', $this->erro(['duracao' => '1:00']));
        $this->assertStringContainsString('futuro', $this->erro(['data_trabalho' => '2026-11-18', 'duracao' => '1:00']));
        $this->assertStringContainsString('um ano', $this->erro(['data_trabalho' => '2025-01-01', 'duracao' => '1:00']));
        $this->assertStringContainsString('depois do início', $this->erro(['data_trabalho' => self::HOJE, 'hora_inicio' => '10:00', 'hora_fim' => '09:00']));
        $this->assertStringContainsString('início e o fim', $this->erro(['data_trabalho' => self::HOJE, 'hora_inicio' => '10:00']));
        $this->assertStringContainsString('Hora inválida', $this->erro(['data_trabalho' => self::HOJE, 'hora_inicio' => '25:00', 'hora_fim' => '26:00']));
        $this->assertStringContainsString('horas:minutos', $this->erro(['data_trabalho' => self::HOJE, 'duracao' => '90']));
        $this->assertStringContainsString('duração', $this->erro(['data_trabalho' => self::HOJE]));
        $this->assertStringContainsString('24 horas', $this->erro(['data_trabalho' => self::HOJE, 'duracao' => '25:00']));
    }

    public function test_quem_edita_um_lancamento(): void {
        $lanc = ['usuario_id' => 5];
        $ticket = ['setor_id' => 2, 'excluido_em' => null];
        $this->assertTrue(TempoDemandaService::podeEditar(['id' => 5, 'tipo' => 'operador', 'setor_id' => 2], $lanc, $ticket), 'autor');
        $this->assertFalse(TempoDemandaService::podeEditar(['id' => 6, 'tipo' => 'operador', 'setor_id' => 2], $lanc, $ticket), 'colega');
        $this->assertTrue(TempoDemandaService::podeEditar(['id' => 7, 'tipo' => 'gestor', 'setor_id' => 2], $lanc, $ticket), 'gestão do setor');
        $this->assertFalse(TempoDemandaService::podeEditar(['id' => 7, 'tipo' => 'gestor', 'setor_id' => 3], $lanc, $ticket), 'gestão de outro setor');
        $this->assertTrue(TempoDemandaService::podeEditar(['id' => 1, 'tipo' => 'admin', 'setor_id' => null], $lanc, $ticket));
        $this->assertFalse(TempoDemandaService::podeEditar(['id' => 5, 'tipo' => 'operador', 'setor_id' => 2], $lanc, ['setor_id' => 2, 'excluido_em' => '2026-11-17']), 'na lixeira');
    }
}
