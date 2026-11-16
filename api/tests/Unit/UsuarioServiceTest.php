<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/usuario-service.php';

/**
 * Regras do nome de usuário e do campo do login (16/11/2026): o login aceita e-mail ou nome de usuário e
 * decide pelo "@", por isso um nome de usuário nunca pode ter "@" (não se confunde com o e-mail de outra pessoa).
 */
final class UsuarioServiceTest extends TestCase {

    public function test_aceita_nome_de_usuario_no_padrao(): void {
        $this->assertTrue(UsuarioService::usernameValido('hugo.miranda'));
        $this->assertTrue(UsuarioService::usernameValido('cadastro5'));
        $this->assertTrue(UsuarioService::usernameValido('ana_paula-2'));
        $this->assertTrue(UsuarioService::usernameValido(str_repeat('a', 50)));
    }

    public function test_recusa_nome_de_usuario_fora_do_padrao(): void {
        $this->assertFalse(UsuarioService::usernameValido('ab'), 'curto demais');
        $this->assertFalse(UsuarioService::usernameValido(str_repeat('a', 51)), 'longo demais');
        $this->assertFalse(UsuarioService::usernameValido('Hugo'), 'maiúscula');
        $this->assertFalse(UsuarioService::usernameValido('a@b.com'), 'arroba');
        $this->assertFalse(UsuarioService::usernameValido('nome sobrenome'), 'espaço');
        $this->assertFalse(UsuarioService::usernameValido('joão'), 'acento');
        $this->assertFalse(UsuarioService::usernameValido(''), 'vazio');
    }

    public function test_campo_do_login_decide_pelo_arroba(): void {
        $this->assertSame('email', UsuarioService::campoDoLogin('hugo.miranda@crprs.org.br'));
        $this->assertSame('username', UsuarioService::campoDoLogin('hugo.miranda'));
    }

    public function test_normalizar_login_tira_espacos_e_maiusculas(): void {
        $this->assertSame('hugo.miranda', UsuarioService::normalizarLogin('  Hugo.Miranda '));
        $this->assertSame('hugo@crprs.org.br', UsuarioService::normalizarLogin("HUGO@crprs.org.br\n"));
    }
}
