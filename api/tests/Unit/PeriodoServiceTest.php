<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/periodo-service.php';

/** Período dos painéis: padrão, período anterior e pontos da série (sem banco). */
final class PeriodoServiceTest extends TestCase {

    public function test_sem_datas_usa_o_padrao_ate_hoje(): void {
        $hoje = new DateTimeImmutable('2026-11-19');
        $this->assertSame(['2026-10-21', '2026-11-19'], PeriodoService::normalizar('', null, $hoje, '2026-10-21'));
    }

    public function test_periodo_anterior_tem_o_mesmo_tamanho_e_termina_na_vespera(): void {
        $this->assertSame(['2026-09-21', '2026-10-20'], PeriodoService::anterior('2026-10-21', '2026-11-19'));
        $this->assertSame(['2026-11-18', '2026-11-18'], PeriodoService::anterior('2026-11-19', '2026-11-19'));
        // Fevereiro: 28 dias antes de 1º de março
        $this->assertSame(['2026-02-01', '2026-02-28'], PeriodoService::anterior('2026-03-01', '2026-03-28'));
    }

    public function test_dias_conta_os_dois_extremos(): void {
        $this->assertSame(30, PeriodoService::dias('2026-10-21', '2026-11-19'));
        $this->assertSame(1, PeriodoService::dias('2026-11-19', '2026-11-19'));
    }

    public function test_ate_92_dias_a_serie_e_por_dia(): void {
        $p = PeriodoService::pontos('2026-11-17', '2026-11-19');
        $this->assertSame('dia', $p['granularidade']);
        $this->assertSame(['2026-11-17', '2026-11-18', '2026-11-19'], $p['chaves']);
        $this->assertCount(92, PeriodoService::pontos('2026-08-20', '2026-11-19')['chaves']);
    }

    public function test_acima_de_92_dias_a_serie_e_por_mes_sem_mes_futuro(): void {
        $p = PeriodoService::pontos('2025-11-20', '2026-11-19');
        $this->assertSame('mes', $p['granularidade']);
        $this->assertSame('2025-11', $p['chaves'][0]);
        $this->assertSame('2026-11', end($p['chaves']));
        $this->assertCount(13, $p['chaves']);
    }

    public function test_periodo_invalido_e_recusado(): void {
        $this->expectException(InvalidArgumentException::class);
        PeriodoService::normalizar('2026-11-20', '2026-11-19', new DateTimeImmutable('2026-11-19'), '2026-11-01');
    }
}
