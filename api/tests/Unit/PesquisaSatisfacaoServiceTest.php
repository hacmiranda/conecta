<?php
use PHPUnit\Framework\TestCase;

/**
 * Pesquisa de satisfação pelo METRICA360: quem é solicitante externo e como cada resposta do
 * METRICA360 vira o status do pedido. A elegibilidade completa (setor, lixeira, uma por demanda)
 * depende do banco e é conferida na verificação ponta a ponta.
 */
final class PesquisaSatisfacaoServiceTest extends TestCase {

    private const INTERNOS = ['crprs.org.br'];

    public function test_email_externo_recebe_pesquisa(): void {
        $this->assertTrue(PesquisaSatisfacaoService::emailExterno('maria@gmail.com', self::INTERNOS));
        $this->assertTrue(PesquisaSatisfacaoService::emailExterno(' joao@empresa.com.br ', self::INTERNOS));
    }

    public function test_email_interno_e_subdominio_nao_recebem(): void {
        $this->assertFalse(PesquisaSatisfacaoService::emailExterno('danuza.daudt@crprs.org.br', self::INTERNOS));
        $this->assertFalse(PesquisaSatisfacaoService::emailExterno('Fulano@CRPRS.ORG.BR', self::INTERNOS));
        $this->assertFalse(PesquisaSatisfacaoService::emailExterno('caixa@mail.crprs.org.br', self::INTERNOS));
    }

    public function test_dominio_parecido_nao_e_interno(): void {
        // "outrocrprs.org.br" não é subdomínio de crprs.org.br
        $this->assertTrue(PesquisaSatisfacaoService::emailExterno('a@outrocrprs.org.br', self::INTERNOS));
    }

    public function test_email_vazio_ou_invalido_nao_recebe(): void {
        $this->assertFalse(PesquisaSatisfacaoService::emailExterno('', self::INTERNOS));
        $this->assertFalse(PesquisaSatisfacaoService::emailExterno('sem-arroba', self::INTERNOS));
    }

    public function test_varios_dominios_internos(): void {
        $internos = ['crprs.org.br', 'cfp.org.br'];
        $this->assertFalse(PesquisaSatisfacaoService::emailExterno('a@cfp.org.br', $internos));
        $this->assertTrue(PesquisaSatisfacaoService::emailExterno('a@gmail.com', $internos));
    }

    public function test_convite_criado_ou_ja_existente_conta_como_enviado(): void {
        $this->assertSame('enviado', PesquisaSatisfacaoService::interpretarResposta(201, '{"status":"enviado","convite_id":9}')['status']);
        $this->assertSame('enviado', PesquisaSatisfacaoService::interpretarResposta(200, '{"status":"ja_existe","convite_id":9}')['status']);
    }

    public function test_quarentena_e_sem_funcionario_encerram_sem_nova_tentativa(): void {
        $this->assertSame('quarentena', PesquisaSatisfacaoService::interpretarResposta(200, '{"status":"quarentena"}')['status']);
        $this->assertSame('sem_funcionario', PesquisaSatisfacaoService::interpretarResposta(422, '{"status":"sem_funcionario"}')['status']);
    }

    public function test_pedido_recusado_vira_falha_definitiva(): void {
        $this->assertSame('falha', PesquisaSatisfacaoService::interpretarResposta(401, '{"status":"nao_autorizado"}')['status']);
        $this->assertSame('falha', PesquisaSatisfacaoService::interpretarResposta(422, '{"status":"dados_invalidos"}')['status']);
    }

    public function test_fora_do_ar_ou_erro_do_servidor_tenta_de_novo(): void {
        $this->assertSame('tentar_de_novo', PesquisaSatisfacaoService::interpretarResposta(0, '')['status']);
        $this->assertSame('tentar_de_novo', PesquisaSatisfacaoService::interpretarResposta(503, '{"status":"falha_email"}')['status']);
        $this->assertSame('tentar_de_novo', PesquisaSatisfacaoService::interpretarResposta(500, '<html>erro</html>')['status']);
        $this->assertSame('tentar_de_novo', PesquisaSatisfacaoService::interpretarResposta(429, '{"status":"muitas_tentativas"}')['status']);
    }

    public function test_codigo_do_email_esta_no_catalogo_por_setor(): void {
        $this->assertArrayHasKey(PesquisaSatisfacaoService::CODIGO_EMAIL, NotificacaoEmailService::CATALOGO);
    }
}
