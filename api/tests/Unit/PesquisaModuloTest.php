<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/pesquisa-config-service.php';
require_once __DIR__ . '/../../services/pesquisa-convite-service.php';
require_once __DIR__ . '/../../services/pesquisa-avaliacao-service.php';
require_once __DIR__ . '/../../services/pesquisa-painel-service.php';

/**
 * Regras puras do módulo Satisfação: validação das regras, situação e validade do link, texto do convite e filtros.
 * O fluxo com banco está em Integracao/PesquisaModuloIntegracaoTest.
 */
final class PesquisaModuloTest extends TestCase {

    // ---------- regras (PesquisaConfig::validar) ----------

    public function test_regras_validas_sao_aceitas_e_normalizadas(): void {
        [$limpos, $erros] = PesquisaConfig::validar([
            'quarentena_ativa' => true, 'quarentena_dias' => '20', 'reenvio_horas' => 72, 'expiracao_token_dias' => '10',
            'meta_nota_media' => '4,5', 'notificar_avaliacao_critica' => '0', 'email_avaliacao_critica' => ' Gestor@CRPRS.org.br ',
            'google_review_url' => 'https://g.page/r/abc',
        ]);
        $this->assertSame([], $erros);
        $this->assertSame('1', $limpos['quarentena_ativa']);
        $this->assertSame('20', $limpos['quarentena_dias']);
        $this->assertSame('4.5', $limpos['meta_nota_media']);
        $this->assertSame('0', $limpos['notificar_avaliacao_critica']);
        $this->assertSame('gestor@crprs.org.br', $limpos['email_avaliacao_critica']);
    }

    public function test_regras_fora_do_limite_sao_recusadas(): void {
        [, $erros] = PesquisaConfig::validar(['quarentena_dias' => 0, 'reenvio_horas' => 9999, 'expiracao_token_dias' => 'abc', 'meta_nota_media' => 6]);
        $this->assertCount(4, $erros);
    }

    public function test_email_e_link_do_google_invalidos_sao_recusados(): void {
        [, $erros] = PesquisaConfig::validar(['email_avaliacao_critica' => 'sem-arroba', 'google_review_url' => 'http://inseguro.com']);
        $this->assertCount(2, $erros);
        [$limpos, $erros] = PesquisaConfig::validar(['email_avaliacao_critica' => '', 'google_review_url' => '']);
        $this->assertSame([], $erros);
        $this->assertSame('', $limpos['google_review_url']);
    }

    public function test_chaves_desconhecidas_sao_ignoradas(): void {
        [$limpos] = PesquisaConfig::validar(['qualquer_coisa' => 'x', 'quarentena_dias' => 5]);
        $this->assertSame(['quarentena_dias' => '5'], $limpos);
    }

    // ---------- link: situação e validade ----------

    public function test_situacao_do_token(): void {
        $agora = strtotime('2026-11-10 12:00:00');
        $novo = ['enviado_em' => '2026-11-08 12:00:00', 'avaliado_em' => null, 'expirado' => 0];
        $this->assertSame('ok', PesquisaAvaliacaoService::situacao($novo, 7, $agora));
        $this->assertSame('invalido', PesquisaAvaliacaoService::situacao(null, 7, $agora));
        $this->assertSame('ja_avaliado', PesquisaAvaliacaoService::situacao(['avaliado_em' => '2026-11-09 10:00:00'] + $novo, 7, $agora));
        $this->assertSame('expirado', PesquisaAvaliacaoService::situacao(['expirado' => 1] + $novo, 7, $agora));
        $this->assertSame('expirado', PesquisaAvaliacaoService::situacao(['enviado_em' => '2026-11-01 12:00:00'] + $novo, 7, $agora), 'passou o prazo mesmo sem o cron');
    }

    public function test_validade_conta_em_dias_a_partir_do_envio(): void {
        $convite = ['enviado_em' => '2026-11-01 12:00:00', 'expirado' => 0];
        $this->assertFalse(PesquisaAvaliacaoService::conviteExpirado($convite, 7, strtotime('2026-11-08 12:00:00')));
        $this->assertTrue(PesquisaAvaliacaoService::conviteExpirado($convite, 7, strtotime('2026-11-08 12:00:01')));
    }

    // ---------- e-mail do convite ----------

    public function test_frase_cita_o_protocolo_quando_ha(): void {
        $this->assertStringContainsString('<strong>#CRPRS2026-0001</strong>', PesquisaConviteService::frase('#CRPRS2026-0001', true));
        $this->assertStringContainsString('#CRPRS2026-0001', PesquisaConviteService::frase('CRPRS2026-0001', false));
        $this->assertStringNotContainsString('<strong>', PesquisaConviteService::frase('CRPRS2026-0001', false));
        $this->assertStringContainsString('recente solicitação', PesquisaConviteService::frase('', true));
    }

    public function test_email_do_convite_tem_cabecalho_link_e_nome_escapado(): void {
        [$assunto, $html] = PesquisaConviteService::montarEmail('Ana <b>Lima</b>', 'https://x.org/conecta/frontend/avaliar.html#t=abc', '#CRPRS2026-0002');
        $this->assertStringContainsString('Avalie', $assunto);
        $this->assertStringNotContainsString('{{', $html, 'todos os marcadores foram trocados');
        $this->assertStringContainsString('Ana &lt;b&gt;Lima&lt;/b&gt;', $html);
        $this->assertStringContainsString('avaliar.html#t=abc', $html);
        $this->assertStringContainsString('#CRPRS2026-0002', $html);
        $this->assertStringContainsString('Conselho Regional de Psicologia', $html, 'cabeçalho padrão');
    }

    // ---------- filtros e períodos ----------

    public function test_filtros_invalidos_sao_descartados(): void {
        $f = PesquisaPainelService::lerFiltros(['status' => 'x', 'nota' => '9', 'data_ini' => '2026-02-30', 'data_fim' => '2026-11-03',
                                                'situacao' => 'avaliado', 'origem' => 'outra', 'busca' => '  oi  ', 'usuario_id' => '-4']);
        $this->assertSame('', $f['status']);
        $this->assertSame(0, $f['nota']);
        $this->assertSame('', $f['data_ini']);
        $this->assertSame('2026-11-03', $f['data_fim']);
        $this->assertSame('avaliado', $f['situacao']);
        $this->assertSame('', $f['origem']);
        $this->assertSame('oi', $f['busca']);
        $this->assertSame(0, $f['usuario_id']);
    }

    public function test_periodos_do_painel_de_setores(): void {
        $p = PesquisaPainelService::periodos('2026-11-04'); // quarta-feira
        $this->assertSame('2026-11-02', $p['sem_atual_ini']);
        $this->assertSame('2026-11-06', $p['sem_atual_fim']);
        $this->assertSame('2026-10-26', $p['sem_ant_ini']);
        $this->assertSame('2026-11-01', $p['mes_atual_ini']);
        $this->assertSame('2026-11-30', $p['mes_atual_fim']);
        $this->assertSame('2026-10-31', $p['mes_ant_fim']);
        $this->assertSame('2026-11-01', $p['custom_ini'], 'sem período escolhido vale o mês atual');
    }

    public function test_situacao_do_convite(): void {
        $this->assertSame('avaliado', PesquisaPainelService::situacaoDoConvite(['avaliado_em' => '2026-11-01', 'expirado' => 1]));
        $this->assertSame('expirado', PesquisaPainelService::situacaoDoConvite(['avaliado_em' => null, 'expirado' => 1]));
        $this->assertSame('aguardando', PesquisaPainelService::situacaoDoConvite(['avaliado_em' => null, 'expirado' => 0]));
    }
}
