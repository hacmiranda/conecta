<?php
use PHPUnit\Framework\TestCase;

/**
 * Assinatura de quem responde, dentro do card "Resposta da equipe" (só no e-mail, não fica gravada no comentário).
 */
final class EmailAssinaturaRespostaTest extends TestCase {

    public function test_monta_atenciosamente_nome_e_setor_nessa_ordem(): void {
        $html = EmailAssinaturaResposta::html('Carlos Pereira', 'Cadastro');
        $atenciosamente = strpos($html, 'Atenciosamente,');
        $nome = strpos($html, 'Carlos Pereira');
        $setor = strpos($html, 'Cadastro');
        $this->assertNotFalse($atenciosamente);
        $this->assertTrue($atenciosamente < $nome && $nome < $setor, 'ordem: Atenciosamente, nome, setor');
    }

    public function test_nome_e_setor_sao_escapados(): void {
        $html = EmailAssinaturaResposta::html('<script>x</script>', 'Setor <b>A</b>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Setor &lt;b&gt;A&lt;/b&gt;', $html);
    }

    public function test_sem_setor_mostra_so_o_nome(): void {
        $html = EmailAssinaturaResposta::html('Carlos Pereira', '  ');
        $this->assertStringContainsString('Carlos Pereira', $html);
        $this->assertSame(2, substr_count($html, '<p'));
    }

    public function test_sem_nome_nao_ha_assinatura(): void {
        $this->assertSame('', EmailAssinaturaResposta::html('  ', 'Cadastro'));
    }
}
