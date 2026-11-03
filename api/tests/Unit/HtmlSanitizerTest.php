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

    /** Auditoria 01/11/2026, BUG-01: o strip_tags apagava o texto depois de um "<" solto. */
    public function test_texto_mantem_sinal_de_menor_que_nao_e_tag(): void {
        $this->assertSame('valor <100 reais', HtmlSanitizer::texto('valor <100 reais'));
        $this->assertSame('5<10 e 3>2', HtmlSanitizer::texto('5<10 e 3>2'));
        $this->assertSame('x <= y', HtmlSanitizer::texto('x <= y'));
        $this->assertSame('a < b', HtmlSanitizer::texto('a < b'));
        $this->assertSame('x', HtmlSanitizer::texto('<script>x</script>'));
        $this->assertSame('Assunto  teste', HtmlSanitizer::texto('Assunto <urgente> teste'), 'parece tag: sai, como antes');
    }

    public function test_texto_nao_deixa_tag_se_formar_nem_inicio_de_tag_aberto(): void {
        // Remoção repetida: tirar <b> não pode "montar" um <img>
        $this->assertStringNotContainsString('<img', HtmlSanitizer::texto('<<b>img src=x onerror=alert(1)>'));
        // Início de tag sem ">" (o strip_tags apagava até o fim): fica, mas sem poder virar tag
        $this->assertSame('Nome < img src=x onerror=alert(1)//', HtmlSanitizer::texto('Nome <img src=x onerror=alert(1)//'));
        $this->assertSame('a < /b', HtmlSanitizer::texto('a </b'));
        $this->assertSame('ok', HtmlSanitizer::texto('<!-- comentário -->ok'));
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
