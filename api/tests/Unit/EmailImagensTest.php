<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * Imagens coladas nos comentários (base64) viram imagem inline no e-mail.
 */
final class EmailImagensTest extends TestCase {

    // PNG 1x1 válido
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function test_troca_a_imagem_base64_por_cid_e_anexa_ao_email(): void {
        $mail = new PHPMailer(true);
        $html = EmailImagens::embutir($mail, '<p>oi</p><img src="data:image/png;base64,' . self::PNG . '" alt="print" />');

        $this->assertStringNotContainsString('base64', $html);
        $this->assertMatchesRegularExpression('/src="cid:img1-[0-9a-f]{8}@conecta"/', $html);
        $this->assertStringContainsString('alt="print"', $html);
        $this->assertCount(1, $mail->getAttachments());
        $this->assertSame('inline', $mail->getAttachments()[0][6]);
    }

    public function test_sem_imagem_devolve_o_texto_igual(): void {
        $mail = new PHPMailer(true);
        $this->assertSame('<p>sem imagem</p>', EmailImagens::embutir($mail, '<p>sem imagem</p>'));
        $this->assertCount(0, $mail->getAttachments());
    }

    public function test_base64_invalido_nao_anexa_nada(): void {
        $mail = new PHPMailer(true);
        $html = EmailImagens::embutir($mail, '<img src="data:image/png;base64,@@@@">');
        // O padrão só aceita caracteres base64: o texto fica como está, sem anexar nada
        $this->assertCount(0, $mail->getAttachments());
        $this->assertIsString($html);
    }

    public function test_respeita_o_limite_de_quantidade(): void {
        $mail = new PHPMailer(true);
        $img = '<img src="data:image/png;base64,' . self::PNG . '">';
        $html = EmailImagens::embutir($mail, str_repeat($img, EmailImagens::MAX_IMAGENS + 2));

        $this->assertCount(EmailImagens::MAX_IMAGENS, $mail->getAttachments());
        $this->assertSame(2, substr_count($html, 'Imagem não incluída'));
    }
}
