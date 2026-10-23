<?php
use PHPUnit\Framework\TestCase;

/**
 * Protocolo único do Conselho (#CRPRS2026-0001). gerar()/rotuloPorId()/buscarId() usam banco
 * de verdade e são cobertos pela matriz de acesso e pelos testes manuais no navegador; aqui só
 * o que é puro (formatação e leitura do assunto do e-mail).
 */
final class ProtocoloServiceTest extends TestCase {

    public function test_rotulo_com_protocolo(): void {
        $this->assertSame('#CRPRS2026-0001', ProtocoloService::rotulo('CRPRS2026-0001', 5));
    }

    public function test_rotulo_sem_protocolo_cai_no_id(): void {
        $this->assertSame('#00005', ProtocoloService::rotulo(null, 5));
        $this->assertSame('#00005', ProtocoloService::rotulo('', 5));
    }

    public function test_extrai_do_assunto_com_colchetes_e_prefixo_re(): void {
        $this->assertSame('CRPRS2026-0001', ProtocoloService::extrair('RE: [#CRPRS2026-0001] Resposta da Equipe - Dúvida'));
    }

    public function test_extrai_minusculo_e_sem_cerquilha(): void {
        $this->assertSame('CRPRS2026-0042', ProtocoloService::extrair('Re: crprs2026-0042 - assunto qualquer'));
    }

    public function test_extrai_com_espacos_internos(): void {
        $this->assertSame('CRPRS2027-0123', ProtocoloService::extrair('[# CRPRS 2027 - 0123 ]'));
    }

    public function test_nao_reconhece_o_formato_antigo_de_5_digitos(): void {
        $this->assertNull(ProtocoloService::extrair('RE: [#00123] Demanda criada'));
    }

    public function test_nao_reconhece_texto_sem_protocolo(): void {
        $this->assertNull(ProtocoloService::extrair('Dúvida sobre inscrição'));
    }

    public function test_nao_confunde_outro_texto_com_protocolo(): void {
        $this->assertNull(ProtocoloService::extrair('CRP2026-0001')); // falta o "RS" de CRPRS
    }
}
