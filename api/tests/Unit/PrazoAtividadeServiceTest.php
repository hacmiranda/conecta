<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/prazo-atividade-service.php';

/**
 * Regras de prazo do Registro de Atividades (porte do PrazoRemotoModel do METRICA360). Mesmos cenários dos
 * testes de lá (PrazoRemotoModelTest, PrazoRemotoEntregaTest, EntregaRelatorioTest), na versão pura.
 * Semana de referência: segunda 10/08/2026 a sexta 14/08/2026; "agora" bem depois, para os prazos vencerem.
 */
final class PrazoAtividadeServiceTest extends TestCase {

    private const SEG = '2026-08-10';
    private const SEX = '2026-08-14';

    private function setor(array $campos = []): array {
        return $campos + ['modo' => 'semanal', 'prazo' => 0, 'entrega_ativa' => false,
                          'entrega_dias' => '1,2,3,4,5', 'entrega_inicio' => null, 'data_base' => null];
    }

    private function usuario(array $campos = []): array {
        return $campos + ['trabalho_remoto' => true, 'qtd_semana' => null, 'dias_remotos' => [],
                          'entrega_dias' => null, 'entrega_inicio' => null];
    }

    private function pendencias(array $setor, array $usuario, array $enviados = [], array $feriados = [],
                                array $abonos = [], ?string $agora = '2026-10-27 12:00:00'): array {
        return PrazoAtividadeService::calcularPendencias($setor, $usuario, self::SEG, self::SEX, $feriados, $enviados,
            $abonos, new DateTimeImmutable($agora));
    }

    private function doTipo(array $pendencias, string $tipo): array {
        return array_values(array_filter($pendencias, fn($p) => $p['tipo'] === $tipo));
    }

    // ── Prazo final ─────────────────────────────────────────────────────────────

    public function test_prazo_zero_vence_no_mesmo_dia_as_23_59(): void {
        $this->assertSame('2026-08-14 23:59:59', PrazoAtividadeService::calcularPrazoFinal('2026-08-14', 0)->format('Y-m-d H:i:s'));
    }

    public function test_prazo_um_pula_o_fim_de_semana(): void {
        $this->assertSame('2026-08-17', PrazoAtividadeService::calcularPrazoFinal('2026-08-14', 1)->format('Y-m-d'));
    }

    public function test_prazo_pula_feriado(): void {
        $this->assertSame('2026-08-18', PrazoAtividadeService::calcularPrazoFinal('2026-08-14', 1, ['2026-08-17'])->format('Y-m-d'));
    }

    public function test_feriado_recorrente_vale_todo_ano(): void {
        $linhas = [['data' => '2020-09-20', 'recorrente' => 1], ['data' => '2026-08-12', 'recorrente' => 0], ['data' => '2025-08-12', 'recorrente' => 0]];
        $this->assertSame(['2026-08-12', '2026-09-20', '2027-09-20'], PrazoAtividadeService::datasDeFeriado($linhas, '2026-01-01', '2027-12-31'));
    }

    // ── Dias de entrega ─────────────────────────────────────────────────────────

    public function test_normalizar_dias_descarta_invalidos_e_repetidos(): void {
        $this->assertSame([1, 3, 7], PrazoAtividadeService::normalizarDias('3,1,x,9,3,7'));
        $this->assertSame('2,4', PrazoAtividadeService::diasParaCsv(['4', 2, 2]));
    }

    public function test_dias_de_entrega_seguem_setor_excecao_ou_dispensa(): void {
        $this->assertSame([1, 2, 3, 4, 5], PrazoAtividadeService::diasDeEntrega(null, true, '1,2,3,4,5'));
        $this->assertSame([], PrazoAtividadeService::diasDeEntrega(null, false, '1,2,3,4,5'));
        $this->assertSame([2], PrazoAtividadeService::diasDeEntrega('2', false, '1,2,3,4,5'));
        $this->assertSame([], PrazoAtividadeService::diasDeEntrega('', true, '1,2,3,4,5'));
        $this->assertSame('2026-01-01', PrazoAtividadeService::inicioDaEntrega('1', '2026-01-01', '2025-01-01'));
        $this->assertSame('2025-01-01', PrazoAtividadeService::inicioDaEntrega(null, '2026-01-01', '2025-01-01'));
        $this->assertNull(PrazoAtividadeService::inicioDaEntrega(null, null, ''));
    }

    public function test_setor_ligado_cobra_todos_os_dias_uteis_mesmo_sem_trabalho_remoto(): void {
        $p = $this->doTipo($this->pendencias($this->setor(['entrega_ativa' => true]), $this->usuario(['trabalho_remoto' => false])), 'entrega');
        $this->assertSame(['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14'], array_column($p, 'data'));
        $this->assertSame('atrasado', $p[0]['status']);
    }

    public function test_dentro_do_prazo_fica_pendente(): void {
        $p = $this->pendencias($this->setor(['entrega_ativa' => true, 'prazo' => 2]), $this->usuario(['trabalho_remoto' => false]),
            [], [], [], '2026-08-14 10:00:00');
        $this->assertSame('pendente', $p[4]['status']);
        $this->assertSame('atrasado', $p[0]['status']); // 10/08 + 2 dias úteis = 12/08
    }

    public function test_setor_desligado_nao_cobra_entrega(): void {
        $this->assertSame([], $this->pendencias($this->setor(), $this->usuario(['trabalho_remoto' => false])));
    }

    public function test_dias_e_inicio_do_setor(): void {
        $this->assertSame(['2026-08-11', '2026-08-13'],
            array_column($this->pendencias($this->setor(['entrega_ativa' => true, 'entrega_dias' => '2,4']), $this->usuario()), 'data'));
        $this->assertSame(['2026-08-12', '2026-08-13', '2026-08-14'],
            array_column($this->pendencias($this->setor(['entrega_ativa' => true, 'entrega_inicio' => '2026-08-12']), $this->usuario()), 'data'));
    }

    public function test_dia_enviado_em_qualquer_modalidade_nao_gera_entrega(): void {
        $p = $this->pendencias($this->setor(['entrega_ativa' => true]), $this->usuario(),
            ['2026-08-10' => 'presencial', '2026-08-11' => 'remoto']);
        $this->assertSame(['2026-08-12', '2026-08-13', '2026-08-14'], array_column($p, 'data'));
    }

    public function test_feriado_nao_gera_pendencia_de_entrega(): void {
        $p = $this->pendencias($this->setor(['entrega_ativa' => true]), $this->usuario(), [], ['2026-08-12']);
        $this->assertNotContains('2026-08-12', array_column($p, 'data'));
        $this->assertCount(4, $p);
    }

    public function test_excecoes_individuais(): void {
        $this->assertSame([], $this->pendencias($this->setor(['entrega_ativa' => true]), $this->usuario(['entrega_dias' => ''])));
        $this->assertSame(['2026-08-10'], array_column($this->pendencias($this->setor(['entrega_ativa' => true]),
            $this->usuario(['entrega_dias' => '1', 'entrega_inicio' => '2026-08-01'])), 'data'));
        $this->assertSame(['2026-08-13'], array_column($this->pendencias($this->setor(),
            $this->usuario(['entrega_dias' => '2,4', 'entrega_inicio' => '2026-08-12'])), 'data'));
    }

    // ── Trabalho remoto ─────────────────────────────────────────────────────────

    public function test_modo_diario_cobra_dia_fixo_sem_envio_e_ignora_enviado(): void {
        $setor = $this->setor(['modo' => 'diario']);
        $p = $this->pendencias($setor, $this->usuario(['dias_remotos' => [1, 3]]), ['2026-08-12' => 'remoto']);
        $this->assertSame(['2026-08-10'], array_column($p, 'data'));
        $this->assertSame('remoto', $p[0]['tipo']);
    }

    public function test_modo_diario_dia_remoto_e_de_entrega_gera_uma_pendencia_so(): void {
        $p = $this->pendencias($this->setor(['entrega_ativa' => true, 'modo' => 'diario']), $this->usuario(['dias_remotos' => [1]]));
        $this->assertCount(5, $p);
        $this->assertSame(['2026-08-10'], array_column($this->doTipo($p, 'remoto'), 'data'));
        $this->assertCount(4, $this->doTipo($p, 'entrega'));
    }

    public function test_modo_semanal_conta_o_deficit_da_cota(): void {
        $p = $this->pendencias($this->setor(), $this->usuario(['qtd_semana' => 2]), ['2026-08-11' => 'remoto']);
        $this->assertCount(1, $p);
        $this->assertSame(['semana_ini' => '2026-08-10', 'semana_fim' => '2026-08-16', 'esperado' => 2, 'enviado' => 1, 'faltam' => 1],
            array_intersect_key($p[0], array_flip(['semana_ini', 'semana_fim', 'esperado', 'enviado', 'faltam'])));
    }

    public function test_modo_semanal_nao_cobra_duas_vezes_o_mesmo_dia(): void {
        $p = $this->pendencias($this->setor(['entrega_ativa' => true]), $this->usuario(['qtd_semana' => 1]));
        $this->assertCount(5, $this->doTipo($p, 'entrega'));
        $this->assertSame([], $this->doTipo($p, 'remoto'));
    }

    public function test_cota_remota_conta_so_relatorio_remoto(): void {
        $presencial = array_fill_keys(['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14'], 'presencial');
        $p = $this->pendencias($this->setor(['entrega_ativa' => true]), $this->usuario(['qtd_semana' => 1]), $presencial);
        $this->assertSame([], $this->doTipo($p, 'entrega'));
        $this->assertSame(1, $this->doTipo($p, 'remoto')[0]['faltam']);

        // Relatório antigo sem modalidade conta como remoto
        $this->assertSame([], $this->pendencias($this->setor(), $this->usuario(['qtd_semana' => 1]), ['2026-08-11' => null]));
    }

    public function test_regra_remota_so_para_quem_tem_trabalho_remoto(): void {
        $this->assertSame([], $this->pendencias($this->setor(), $this->usuario(['trabalho_remoto' => false, 'qtd_semana' => 1])));
    }

    // ── Abonos e data base ──────────────────────────────────────────────────────

    public function test_abono_marca_status_e_e_do_tipo_certo(): void {
        $abono = ['abono_id' => 9, 'justificativa' => 'Atestado', 'abonado_por' => 'Gestor', 'abonado_em' => '2026-08-20'];
        $p = $this->pendencias($this->setor(['entrega_ativa' => true, 'modo' => 'diario']), $this->usuario(['dias_remotos' => [3]]),
            [], [], ['entrega' => ['2026-08-11' => $abono], 'remoto' => []]);
        $porData = array_column($p, null, 'data');
        $this->assertSame('abonado', $porData['2026-08-11']['status']);
        $this->assertSame('Atestado', $porData['2026-08-11']['justificativa']);
        $this->assertSame('atrasado', $porData['2026-08-12']['status']); // remoto do dia 12 não foi abonado

        $semanal = $this->pendencias($this->setor(), $this->usuario(['qtd_semana' => 1]), [], [], ['remoto' => ['2026-08-10' => $abono]]);
        $this->assertSame('abonado', $semanal[0]['status']);
    }

    public function test_nada_antes_da_data_base(): void {
        $this->assertSame([], $this->pendencias($this->setor(['entrega_ativa' => true, 'data_base' => '2026-09-01']), $this->usuario()));
        $this->assertSame(['2026-08-13', '2026-08-14'],
            array_column($this->pendencias($this->setor(['entrega_ativa' => true, 'data_base' => '2026-08-13']), $this->usuario()), 'data'));
    }

    public function test_modalidade_sugerida(): void {
        $diario = $this->setor(['modo' => 'diario']);
        $u = $this->usuario(['dias_remotos' => [1]]);
        $this->assertSame('presencial', PrazoAtividadeService::modalidadeSugerida(null, $diario, $u, '2026-08-11'));
        $this->assertSame('remoto', PrazoAtividadeService::modalidadeSugerida(null, $diario, $u, '2026-08-10'));
        $this->assertSame('presencial', PrazoAtividadeService::modalidadeSugerida('presencial', $diario, $u, '2026-08-10'));
        $this->assertNull(PrazoAtividadeService::modalidadeSugerida(null, $this->setor(), $u, '2026-08-10'));
    }
}
