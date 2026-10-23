<?php
use PHPUnit\Framework\TestCase;

/**
 * Cabeçalho padrão dos e-mails ao solicitante: o marcador dos modelos precisa virar o mesmo
 * HTML em todos (só o título muda) e o título nunca pode injetar HTML.
 */
final class EmailCabecalhoTest extends TestCase {

    public function test_html_traz_titulo_e_instituicao(): void {
        $html = EmailCabecalho::html('Demanda criada');
        $this->assertStringContainsString('Demanda criada', $html);
        $this->assertStringContainsString('Conselho Regional de Psicologia do RS', $html);
    }

    public function test_titulo_e_escapado(): void {
        $html = EmailCabecalho::html('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_aplicar_troca_o_marcador_do_modelo(): void {
        $saida = EmailCabecalho::aplicar('<body>{{CABECALHO:Resposta da equipe}}<p>corpo</p></body>');
        $this->assertStringNotContainsString('{{CABECALHO', $saida);
        $this->assertStringContainsString('Resposta da equipe', $saida);
        $this->assertStringContainsString('<p>corpo</p>', $saida);
    }

    public function test_todos_os_modelos_ao_solicitante_usam_o_cabecalho_padrao(): void {
        $modelos = glob(__DIR__ . '/../../../backend/email-template*.html');
        $this->assertCount(7, $modelos);
        foreach ($modelos as $arquivo) {
            $this->assertMatchesRegularExpression('/\{\{CABECALHO:[^}]+\}\}/', (string) file_get_contents($arquivo), basename($arquivo));
        }
    }
}
