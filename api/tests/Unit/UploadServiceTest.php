<?php
use PHPUnit\Framework\TestCase;

/** Regra única de anexos: extensão da lista + conteúdo real compatível + até 20 MB. */
final class UploadServiceTest extends TestCase {

    public function test_aceita_tipos_validos_com_variacoes_reais_de_mime(): void {
        $this->assertTrue(UploadService::validar('relatorio.pdf', 'application/pdf', 1000)['ok']);
        $this->assertTrue(UploadService::validar('planilha.CSV', 'text/plain', 1000)['ok']);
        $this->assertTrue(UploadService::validar('antigo.doc', 'application/CDFV2', 1000)['ok']);
    }

    public function test_recusa_extensao_fora_da_lista(): void {
        $this->assertSame('Tipo não permitido', UploadService::validar('pacote.zip', 'application/zip', 1000)['motivo']);
        $this->assertSame('Tipo não permitido', UploadService::validar('script.php', 'text/x-php', 1000)['motivo']);
    }

    public function test_recusa_conteudo_que_nao_bate_com_a_extensao(): void {
        $r = UploadService::validar('foto.png', 'text/html', 1000);
        $this->assertFalse($r['ok']);
        $this->assertSame('Conteúdo não corresponde ao tipo do arquivo', $r['motivo']);
    }

    public function test_recusa_vazio_e_acima_de_20mb(): void {
        $this->assertFalse(UploadService::validar('a.pdf', 'application/pdf', 0)['ok']);
        $this->assertFalse(UploadService::validar('a.pdf', 'application/pdf', UploadService::MAX_BYTES_ARQUIVO + 1)['ok']);
        $this->assertTrue(UploadService::validar('a.pdf', 'application/pdf', UploadService::MAX_BYTES_ARQUIVO)['ok']);
    }

    public function test_mime_do_conteudo(): void {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->assertSame('image/png', UploadService::mimeDoConteudo($png));
    }
}
