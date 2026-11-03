<?php
use PHPUnit\Framework\TestCase;

/**
 * Pesquisa de satisfação: quem é solicitante externo e como o resultado do convite vira o status do pedido. A elegibilidade completa (setor, lixeira, uma por demanda)
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
        $this->assertSame('enviado', PesquisaSatisfacaoService::interpretarResultado('enviado')['status']);
        $this->assertSame('enviado', PesquisaSatisfacaoService::interpretarResultado('ja_existe')['status']);
    }

    public function test_quarentena_encerra_sem_nova_tentativa(): void {
        $this->assertSame('quarentena', PesquisaSatisfacaoService::interpretarResultado('quarentena')['status']);
    }

    public function test_falha_no_email_ou_resultado_inesperado_tenta_de_novo(): void {
        $this->assertSame('tentar_de_novo', PesquisaSatisfacaoService::interpretarResultado('falha_email')['status']);
        $this->assertSame('tentar_de_novo', PesquisaSatisfacaoService::interpretarResultado('')['status']);
        $this->assertNotEmpty(PesquisaSatisfacaoService::interpretarResultado('falha_email')['erro']);
    }

    public function test_nome_do_convite_vem_do_cadastro_ou_do_inicio_do_email(): void {
        $this->assertSame('Maria Souza', PesquisaSatisfacaoService::nomeParaConvite('  Maria Souza ', 'x@y.com'));
        $this->assertSame('Joao Silva', PesquisaSatisfacaoService::nomeParaConvite('', 'joao.silva@gmail.com'));
        $this->assertSame('Ana Maria Lima', PesquisaSatisfacaoService::nomeParaConvite('', 'ana_maria-lima@x.org'));
        $this->assertSame('Solicitante', PesquisaSatisfacaoService::nomeParaConvite('', '@x.org'));
    }
    public function test_codigo_do_email_esta_no_catalogo_por_setor(): void {
        $this->assertArrayHasKey(PesquisaSatisfacaoService::CODIGO_EMAIL, NotificacaoEmailService::CATALOGO);
    }
}
