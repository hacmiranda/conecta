<?php
use PHPUnit\Framework\TestCase;

/** Faixas de proxies confiáveis (PROXIES_CONFIAVEIS): só delas o IP repassado em cabeçalho é aceito. */
final class RedeServiceTest extends TestCase {

    public function test_ip_exato_e_faixa_ipv4(): void {
        $this->assertTrue(RedeService::ipNaLista('10.0.0.5', ['10.0.0.5']));
        $this->assertTrue(RedeService::ipNaLista('173.245.48.10', ['173.245.48.0/20']));
        $this->assertTrue(RedeService::ipNaLista('173.245.63.255', ['173.245.48.0/20']));
        $this->assertFalse(RedeService::ipNaLista('173.245.64.0', ['173.245.48.0/20']));
    }

    public function test_faixa_ipv6(): void {
        $this->assertTrue(RedeService::ipNaLista('2400:cb00::1', ['2400:cb00::/32']));
        $this->assertFalse(RedeService::ipNaLista('2401:cb00::1', ['2400:cb00::/32']));
    }

    public function test_ipv4_nao_casa_faixa_ipv6_e_lixo_e_ignorado(): void {
        $this->assertFalse(RedeService::ipNaLista('10.0.0.1', ['2400:cb00::/32']));
        $this->assertFalse(RedeService::ipNaLista('nao-e-ip', ['10.0.0.0/8']));
        $this->assertFalse(RedeService::ipNaLista('10.0.0.1', ['lixo', '']));
    }

    /** Auditoria 01/11/2026, SEG-07: o X-Forwarded-For é lido da direita para a esquerda. */
    public function test_ip_do_encaminhamento_ignora_o_que_o_cliente_inventou(): void {
        $proxies = ['10.0.0.0/8'];
        // O cliente mandou "1.2.3.4" pronto; o proxy acrescentou o IP real dele (203.0.113.9)
        $this->assertSame('203.0.113.9', RedeService::ipDoEncaminhamento('1.2.3.4, 203.0.113.9', '10.0.0.2', $proxies));
        $this->assertSame('203.0.113.9', RedeService::ipDoEncaminhamento('1.2.3.4, 203.0.113.9, 10.0.0.7', '10.0.0.2', $proxies));
        $this->assertSame('10.0.0.5', RedeService::ipDoEncaminhamento('10.0.0.5, 10.0.0.7', '10.0.0.2', $proxies), 'todos confiáveis: o mais à esquerda');
        $this->assertSame('10.0.0.2', RedeService::ipDoEncaminhamento('', '10.0.0.2', $proxies));
        $this->assertSame('10.0.0.2', RedeService::ipDoEncaminhamento('1.2.3.4, lixo, 10.0.0.7', '10.0.0.2', $proxies));
    }

    public function test_sem_proxy_confiavel_usa_o_ip_da_conexao(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
        // PROXIES_CONFIAVEIS vazio no .env de teste: cabeçalhos do cliente não valem
        $this->assertSame('203.0.113.9', RedeService::ipCliente());
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['REMOTE_ADDR']);
    }
}
