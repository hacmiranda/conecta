<?php
use PHPUnit\Framework\TestCase;

/**
 * AcessoService é o único lugar que decide quem vê/altera uma demanda (ver CLAUDE.md:
 * "Always use it; never filter by role ad hoc"). Estes testes travam exatamente a matriz
 * admin / gestor / operador / cliente — qualquer regressão aqui é uma falha de isolamento
 * entre setores, então tratamos como o teste mais importante do projeto.
 */
final class AcessoServiceTest extends TestCase {

    private function usuario(string $tipo, ?int $id = 1, ?int $setorId = null): array {
        return ['id' => $id, 'tipo' => $tipo, 'setor_id' => $setorId];
    }

    private function demanda(array $campos = []): array {
        return array_merge(['id' => 10, 'setor_id' => 2, 'agente_id' => null, 'usuario_id' => null, 'excluido_em' => null], $campos);
    }

    // ---------- podeVerTicket ----------

    public function test_admin_ve_qualquer_demanda_de_qualquer_setor(): void {
        $admin = $this->usuario('admin', 1, null);
        $this->assertTrue(AcessoService::podeVerTicket($admin, $this->demanda(['setor_id' => 99])));
    }

    public function test_gestor_ve_demanda_do_proprio_setor(): void {
        $gestor = $this->usuario('gestor', 2, 2);
        $this->assertTrue(AcessoService::podeVerTicket($gestor, $this->demanda(['setor_id' => 2])));
    }

    public function test_gestor_nao_ve_demanda_de_outro_setor(): void {
        $gestor = $this->usuario('gestor', 2, 2);
        $this->assertFalse(AcessoService::podeVerTicket($gestor, $this->demanda(['setor_id' => 3])));
    }

    public function test_operador_ve_demanda_em_que_e_o_responsavel(): void {
        $operador = $this->usuario('operador', 5, 2);
        $this->assertTrue(AcessoService::podeVerTicket($operador, $this->demanda(['agente_id' => 5])));
    }

    public function test_operador_ve_demanda_que_ele_mesmo_abriu(): void {
        $operador = $this->usuario('operador', 5, 2);
        $this->assertTrue(AcessoService::podeVerTicket($operador, $this->demanda(['usuario_id' => 5])));
    }

    public function test_operador_nao_ve_demanda_de_colega_nao_atribuida_a_ele(): void {
        $operador = $this->usuario('operador', 5, 2);
        $this->assertFalse(AcessoService::podeVerTicket($operador, $this->demanda(['agente_id' => 6])));
    }

    public function test_cliente_ve_apenas_a_propria_demanda(): void {
        $cliente = $this->usuario('cliente', 7);
        $this->assertTrue(AcessoService::podeVerTicket($cliente, $this->demanda(['usuario_id' => 7])));
        $this->assertFalse(AcessoService::podeVerTicket($cliente, $this->demanda(['usuario_id' => 8])));
    }

    public function test_demanda_na_lixeira_so_o_admin_ve(): void {
        $naLixeira = $this->demanda(['excluido_em' => '2026-10-15 10:00:00', 'agente_id' => 5]);
        $operador = $this->usuario('operador', 5, 2);
        $gestor = $this->usuario('gestor', 2, 2);
        $admin = $this->usuario('admin', 1, null);

        $this->assertFalse(AcessoService::podeVerTicket($operador, $naLixeira));
        $this->assertFalse(AcessoService::podeVerTicket($gestor, $naLixeira));
        $this->assertTrue(AcessoService::podeVerTicket($admin, $naLixeira));
    }

    // ---------- podeOperarTicket ----------

    public function test_gestor_opera_demanda_do_proprio_setor(): void {
        $gestor = $this->usuario('gestor', 2, 2);
        $this->assertTrue(AcessoService::podeOperarTicket($gestor, $this->demanda(['setor_id' => 2])));
    }

    public function test_operador_so_opera_se_for_o_responsavel_atual(): void {
        $operador = $this->usuario('operador', 5, 2);
        $this->assertTrue(AcessoService::podeOperarTicket($operador, $this->demanda(['agente_id' => 5])));
        // Quem abriu mas não é o responsável atual não opera (só vê, via podeVerTicket)
        $this->assertFalse(AcessoService::podeOperarTicket($operador, $this->demanda(['usuario_id' => 5, 'agente_id' => 6])));
    }

    public function test_cliente_nunca_opera_demanda(): void {
        $cliente = $this->usuario('cliente', 7);
        $this->assertFalse(AcessoService::podeOperarTicket($cliente, $this->demanda(['usuario_id' => 7])));
    }

    public function test_ninguem_opera_demanda_na_lixeira_nem_o_admin(): void {
        $admin = $this->usuario('admin', 1, null);
        $naLixeira = $this->demanda(['excluido_em' => '2026-10-15 10:00:00']);
        $this->assertFalse(AcessoService::podeOperarTicket($admin, $naLixeira));
    }

    // ---------- setorEscopo ----------

    public function test_setor_escopo_do_admin_segue_o_parametro_da_url(): void {
        $admin = $this->usuario('admin', 1, null);
        $_GET['setor_id'] = '3';
        $this->assertSame(3, AcessoService::setorEscopo($admin));
        $_GET['setor_id'] = '';
        $this->assertNull(AcessoService::setorEscopo($admin));
        unset($_GET['setor_id']);
    }

    public function test_setor_escopo_de_gestor_e_operador_ignora_o_parametro_da_url(): void {
        $gestor = $this->usuario('gestor', 2, 2);
        $_GET['setor_id'] = '99'; // tentativa de ver outro setor pela URL
        $this->assertSame(2, AcessoService::setorEscopo($gestor));
        unset($_GET['setor_id']);
    }
}
