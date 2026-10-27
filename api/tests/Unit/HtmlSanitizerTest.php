<?php
use PHPUnit\Framework\TestCase;

/**
 * HtmlSanitizer::texto(): campos de texto simples (nome, usuário, cargo, título) gravados sem nenhuma tag.
 * A proteção de atributo (aspas em value="...") é do frontend, que escapa com sanitizeHTML() na exibição.
 */
final class HtmlSanitizerTest extends TestCase {

    public function test_texto_remove_tags_e_eventos(): void {
        $this->assertSame('Maria', HtmlSanitizer::texto('<img src=x onerror=alert(1)>Maria'));
        $this->assertSame('alert(1)Ana', HtmlSanitizer::texto('<script>alert(1)</script>Ana'));
    }

    public function test_texto_respeita_o_tamanho_maximo(): void {
        $this->assertSame(100, mb_strlen(HtmlSanitizer::texto(str_repeat('á', 150), 100)));
    }

    public function test_texto_tira_caracteres_de_controle_e_espacos_nas_pontas(): void {
        $this->assertSame('Cargo', HtmlSanitizer::texto("  Car\x00go \x07 "));
    }

    public function test_rico_remove_script_e_eventos_mas_mantem_formatacao(): void {
        $limpo = HtmlSanitizer::rico('<p onclick="x()"><strong>ok</strong><script>alert(1)</script></p>');
        $this->assertStringContainsString('<strong>ok</strong>', $limpo);
        $this->assertStringNotContainsString('script', $limpo);
        $this->assertStringNotContainsString('onclick', $limpo);
    }

    public function test_texto_para_busca_tira_imagens_e_tags(): void {
        $base64 = str_repeat('QUJD', 5000);
        $html = '<p>Pedido de <strong>certid&atilde;o</strong></p><img src="data:image/png;base64,' . $base64 . '"><style>.x{}</style><div>Fulana&nbsp;Silva</div>';
        // Antes a busca varria o base64 das imagens (e casava com qualquer sequência de letras dele)
        $this->assertSame('Pedido de certidão Fulana Silva', HtmlSanitizer::textoParaBusca($html));
        $this->assertSame(10, mb_strlen(HtmlSanitizer::textoParaBusca(str_repeat('a ', 50), 10)));
    }
}
