<?php
use PHPUnit\Framework\TestCase;

/**
 * SenhaService::validar é a mesma regra exibida na tela "Alterar senha" — qualquer
 * mudança aqui sem atualizar o frontend deixa as duas telas dizendo coisas diferentes.
 */
final class SenhaServiceTest extends TestCase {

    public function test_aceita_senha_que_cumpre_todas_as_regras(): void {
        $this->assertNull(SenhaService::validar('Senha@Forte123'));
    }

    public function test_recusa_senha_curta(): void {
        $this->assertStringContainsString('mínimo 10', (string) SenhaService::validar('Ab1!cd'));
    }

    public function test_recusa_sem_maiuscula(): void {
        $this->assertStringContainsString('maiúscula', (string) SenhaService::validar('senha@forte123'));
    }

    public function test_recusa_sem_minuscula(): void {
        $this->assertStringContainsString('minúscula', (string) SenhaService::validar('SENHA@FORTE123'));
    }

    public function test_recusa_sem_numero(): void {
        $this->assertStringContainsString('número', (string) SenhaService::validar('Senha@Forte!!'));
    }

    public function test_recusa_sem_caractere_especial(): void {
        $this->assertStringContainsString('especial', (string) SenhaService::validar('SenhaForte123'));
    }

    public function test_tamanho_minimo_exato_passa(): void {
        // Exatamente 10 caracteres, cumprindo as 4 classes
        $this->assertNull(SenhaService::validar('Abcdef1#gh'));
    }

    public function test_hash_eh_verificavel_e_usa_argon2id_quando_disponivel(): void {
        $hash = SenhaService::hash('Senha@Forte123');
        $this->assertTrue(password_verify('Senha@Forte123', $hash));
        if (defined('PASSWORD_ARGON2ID')) {
            $info = password_get_info($hash);
            $this->assertSame('argon2id', $info['algoName']);
        }
    }

    public function test_hash_nunca_grava_a_senha_em_texto_puro(): void {
        $hash = SenhaService::hash('Senha@Forte123');
        $this->assertStringNotContainsString('Senha@Forte123', $hash);
    }
}
