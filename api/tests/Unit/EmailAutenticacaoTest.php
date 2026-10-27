<?php
use PHPUnit\Framework\TestCase;

/**
 * Remetente forjado no e-mail recebido: o resultado de SPF/DKIM/DMARC vem do primeiro
 * Authentication-Results (o do servidor que recebeu); os de baixo podem ter vindo de quem enviou.
 */
final class EmailAutenticacaoTest extends TestCase {

    public function test_dmarc_fail_reprova(): void {
        $h = "Authentication-Results: mx.crprs.org.br; spf=pass smtp.mailfrom=x.com; dkim=none; dmarc=fail header.from=crprs.org.br\r\nFrom: a@crprs.org.br\r\n";
        $this->assertSame('falhou', EmailService::avaliarAutenticacao($h));
    }

    public function test_spf_fail_sem_dkim_reprova_mas_com_dkim_pass_passa(): void {
        $this->assertSame('falhou', EmailService::avaliarAutenticacao("Authentication-Results: mx; spf=fail; dkim=none\r\n"));
        $this->assertSame('passou', EmailService::avaliarAutenticacao("Authentication-Results: mx; spf=softfail; dkim=pass header.d=gmail.com\r\n"));
    }

    public function test_resultado_em_varias_linhas(): void {
        $h = "Authentication-Results: mx.crprs.org.br;\r\n\tspf=pass smtp.mailfrom=gmail.com;\r\n\tdkim=pass header.d=gmail.com;\r\n\tdmarc=pass\r\n";
        $this->assertSame('passou', EmailService::avaliarAutenticacao($h));
    }

    public function test_so_o_primeiro_cabecalho_vale(): void {
        // O de cima (servidor que recebeu) reprovou; o de baixo, "pass", veio pronto de quem enviou
        $h = "Authentication-Results: mx.crprs.org.br; dmarc=fail\r\nAuthentication-Results: falso; dmarc=pass; dkim=pass\r\n";
        $this->assertSame('falhou', EmailService::avaliarAutenticacao($h));
    }

    public function test_sem_cabecalho_e_desconhecido(): void {
        $this->assertSame('desconhecido', EmailService::avaliarAutenticacao("From: a@b.com\r\nSubject: oi\r\n"));
        $this->assertSame('desconhecido', EmailService::avaliarAutenticacao("Authentication-Results: mx; none\r\n"));
    }
}
