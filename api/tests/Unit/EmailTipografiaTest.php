<?php
use PHPUnit\Framework\TestCase;

/**
 * Respostas da equipe nos e-mails: fonte e tamanho únicos, mesmo que o texto traga estilos próprios.
 */
final class EmailTipografiaTest extends TestCase {

    public function test_envolve_o_texto_na_fonte_e_tamanho_padrao(): void {
        $html = EmailTipografia::padronizar('<p>Olá</p>');
        $this->assertStringContainsString('Segoe UI', $html);
        $this->assertStringContainsString('font-size:15px', $html);
        $this->assertStringContainsString('<p>Olá</p>', $html);
    }

    public function test_descarta_tamanho_e_fonte_do_texto(): void {
        $html = EmailTipografia::padronizar('<p><span style="font-size: 32px; font-family: Courier; color: #ff0000">Grande</span></p>');
        $this->assertStringNotContainsString('32px', $html);
        $this->assertStringNotContainsString('Courier', $html);
        // A cor escolhida continua
        $this->assertStringContainsString('color: #ff0000', $html);
    }

    public function test_remove_estilo_vazio_e_line_height(): void {
        $html = EmailTipografia::padronizar('<p style="line-height:3;font-size:30px">x</p>');
        $this->assertStringContainsString('<p>x</p>', $html);
    }

    public function test_tira_tags_font_e_atributos_antigos(): void {
        $html = EmailTipografia::padronizar('<font size="7" face="Arial">Texto</font><table size="3"></table>');
        $this->assertStringNotContainsString('<font', $html);
        $this->assertStringNotContainsString('size="', $html);
        $this->assertStringContainsString('Texto', $html);
    }

    public function test_titulos_viram_paragrafo_em_negrito_no_mesmo_tamanho(): void {
        $html = EmailTipografia::padronizar('<h1>Título grande</h1><h3 class="x">Outro</h3>');
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<h3', $html);
        $this->assertSame(2, substr_count($html, 'font-weight:bold'));
        $this->assertStringContainsString('Título grande', $html);
    }

    public function test_preserva_negrito_listas_e_links(): void {
        $entrada = '<p><strong>a</strong> <em>b</em></p><ul><li>c</li></ul><a href="https://crprs.org.br">d</a>';
        $html = EmailTipografia::padronizar($entrada);
        $this->assertStringContainsString($entrada, $html);
    }
}
