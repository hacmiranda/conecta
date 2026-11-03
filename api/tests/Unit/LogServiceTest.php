<?php
use PHPUnit\Framework\TestCase;

/**
 * LogService: um arquivo por dia e limpeza dos antigos (auditoria 01/11/2026, DES-02).
 */
final class LogServiceTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/conecta-logtest-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void {
        foreach ((array) glob($this->dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        // Volta ao diretório dos testes definido no bootstrap
        LogService::usarDiretorio(sys_get_temp_dir() . '/conecta-testes-logs', false);
    }

    public function test_nome_do_arquivo_leva_a_data(): void {
        $this->assertSame('email-cron-2026-11-01.log', LogService::nomeArquivo('email-cron', '2026-11-01'));
    }

    public function test_grava_no_arquivo_do_dia(): void {
        LogService::usarDiretorio($this->dir, false);
        LogService::info('canal-teste', 'linha um');
        $arquivo = $this->dir . '/' . LogService::nomeArquivo('canal-teste', date('Y-m-d'));
        $this->assertFileExists($arquivo);
        $this->assertStringContainsString('INFO linha um', (string) file_get_contents($arquivo));
    }

    public function test_limpeza_apaga_so_os_vencidos_do_mesmo_canal(): void {
        foreach (['email-cron-2026-09-01.log', 'email-cron-2026-10-01.log', 'email-cron-2026-10-31.log',
                  'email-cron.log', 'email-cron-x-2026-01-01.log', 'outro-2026-01-01.log'] as $f) {
            file_put_contents($this->dir . '/' . $f, 'x');
        }
        $apagados = LogService::limparAntigos($this->dir, 'email-cron', 30, '2026-11-01');

        $this->assertSame(2, $apagados, 'Mais de 30 dias: 01/09 e 01/10');
        $this->assertFileDoesNotExist($this->dir . '/email-cron-2026-09-01.log');
        $this->assertFileDoesNotExist($this->dir . '/email-cron-2026-10-01.log');
        $this->assertFileExists($this->dir . '/email-cron-2026-10-31.log');
        $this->assertFileExists($this->dir . '/email-cron.log', 'Arquivo antigo sem data não é tocado');
        $this->assertFileExists($this->dir . '/email-cron-x-2026-01-01.log', 'Outro canal com o mesmo prefixo');
        $this->assertFileExists($this->dir . '/outro-2026-01-01.log');
    }
}
