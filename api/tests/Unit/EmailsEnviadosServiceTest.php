<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/emails-enviados-service.php';

/** Período do filtro de "E-mails Enviados" do Dashboard (sem banco). */
final class EmailsEnviadosServiceTest extends TestCase {

    private DateTimeImmutable $hoje;

    protected function setUp(): void {
        $this->hoje = new DateTimeImmutable('2026-11-19');
    }

    public function test_sem_datas_vale_do_primeiro_dia_do_mes_ate_hoje(): void {
        $this->assertSame(['2026-11-01', '2026-11-19'], EmailsEnviadosService::normalizarPeriodo(null, '', $this->hoje));
    }

    public function test_periodo_valido_volta_igual(): void {
        $this->assertSame(['2026-10-01', '2026-10-31'], EmailsEnviadosService::normalizarPeriodo('2026-10-01', '2026-10-31', $this->hoje));
        $this->assertSame(['2026-11-19', '2026-11-19'], EmailsEnviadosService::normalizarPeriodo('2026-11-19', '2026-11-19', $this->hoje));
    }

    #[DataProvider('periodosInvalidos')]
    public function test_periodo_invalido_e_recusado(?string $inicio, ?string $fim): void {
        $this->expectException(InvalidArgumentException::class);
        EmailsEnviadosService::normalizarPeriodo($inicio, $fim, $this->hoje);
    }

    public static function periodosInvalidos(): array {
        return [
            'só o início' => ['2026-11-01', ''],
            'data inexistente' => ['2026-02-30', '2026-03-01'],
            'formato errado' => ['01/11/2026', '2026-11-19'],
            'início depois do fim' => ['2026-11-10', '2026-11-01'],
            'termina no futuro' => ['2026-11-01', '2026-11-20'],
            'mais de 366 dias' => ['2025-11-17', '2026-11-19'],
        ];
    }

    public function test_aceita_exatamente_366_dias(): void {
        $this->assertSame(['2025-11-19', '2026-11-19'], EmailsEnviadosService::normalizarPeriodo('2025-11-19', '2026-11-19', $this->hoje));
    }
}
