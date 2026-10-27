<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/atividades-acompanhamento-service.php';

/** Partes puras do acompanhamento das atividades: períodos, tendência e CSV sem fórmula. */
final class AtividadesAcompanhamentoServiceTest extends TestCase {

    public function test_periodos_de_semana_e_mes(): void {
        $p = AtividadesAcompanhamentoService::periodos('2026-10-27'); // terça-feira
        $this->assertSame(['2026-10-26', '2026-11-01'], [$p['sem_atual_ini'], $p['sem_atual_fim']]);
        $this->assertSame(['2026-10-19', '2026-10-25'], [$p['sem_ant_ini'], $p['sem_ant_fim']]);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$p['mes_atual_ini'], $p['mes_atual_fim']]);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$p['mes_ant_ini'], $p['mes_ant_fim']]);

        $domingo = AtividadesAcompanhamentoService::periodos('2026-11-01');
        $this->assertSame('2026-10-26', $domingo['sem_atual_ini']);
        $marco = AtividadesAcompanhamentoService::periodos('2026-03-31');
        $this->assertSame(['2026-02-01', '2026-02-28'], [$marco['mes_ant_ini'], $marco['mes_ant_fim']]);
    }

    public function test_tendencia_da_semana(): void {
        $this->assertSame(['sem-dado', 'Sem dados'], AtividadesAcompanhamentoService::tendencia(0, 0));
        $this->assertSame(['up', '+3'], AtividadesAcompanhamentoService::tendencia(3, 0));
        $this->assertSame(['up', '+2'], AtividadesAcompanhamentoService::tendencia(5, 3));
        $this->assertSame(['down', '-2'], AtividadesAcompanhamentoService::tendencia(1, 3));
        $this->assertSame(['flat', '='], AtividadesAcompanhamentoService::tendencia(2, 2));
    }

    public function test_csv_neutraliza_formulas(): void {
        $this->assertSame("'=HYPERLINK(\"x\")", AtividadesAcompanhamentoService::celulaCsv('=HYPERLINK("x")'));
        $this->assertSame("'+1", AtividadesAcompanhamentoService::celulaCsv('+1'));
        $this->assertSame("'@soma", AtividadesAcompanhamentoService::celulaCsv('@soma'));
        $this->assertSame('Maria da Silva', AtividadesAcompanhamentoService::celulaCsv('Maria da Silva'));
        $this->assertSame('3', AtividadesAcompanhamentoService::celulaCsv(3));
    }
}
