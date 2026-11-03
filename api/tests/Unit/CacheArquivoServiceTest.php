<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/cache-arquivo-service.php';

/** Cache curto em arquivo (auditoria 01/11/2026, DES-07/DES-10). */
final class CacheArquivoServiceTest extends TestCase {

    private string $pasta;

    protected function setUp(): void {
        $this->pasta = sys_get_temp_dir() . '/conecta-cache-' . bin2hex(random_bytes(4));
        CacheArquivoService::usarPasta($this->pasta);
    }

    protected function tearDown(): void {
        foreach ((array) glob($this->pasta . '/*/*') as $f) {
            @unlink($f);
        }
        foreach ((array) glob($this->pasta . '/*') as $d) {
            @rmdir($d);
        }
        @rmdir($this->pasta);
        CacheArquivoService::usarPasta(null);
    }

    public function test_grava_le_e_expira(): void {
        $this->assertNull(CacheArquivoService::ler('g', 'fulana@crprs.org.br'));
        CacheArquivoService::gravar('g', 'fulana@crprs.org.br', ['disponivel' => true], 60);
        $this->assertSame(['disponivel' => true], CacheArquivoService::ler('g', 'fulana@crprs.org.br'));
        $this->assertSame(0, count(glob($this->pasta . '/g/*fulana*')), 'o e-mail não aparece no nome do arquivo');

        CacheArquivoService::gravar('g', 'x', 7, 60);
        $arquivo = glob($this->pasta . '/g/' . sha1('x') . '.json')[0];
        file_put_contents($arquivo, json_encode(['expira' => time() - 1, 'valor' => 7]));
        $this->assertNull(CacheArquivoService::ler('g', 'x'), 'vencido');
    }

    public function test_zero_e_um_valor_valido(): void {
        CacheArquivoService::gravar('g', 'escopo', 0, 60);
        $this->assertSame(0, CacheArquivoService::ler('g', 'escopo'));
    }
}
