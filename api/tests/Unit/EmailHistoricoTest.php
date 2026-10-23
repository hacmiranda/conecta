<?php
use PHPUnit\Framework\TestCase;

/**
 * Bloco "demanda registrada + histórico" dos e-mails ao solicitante. A regra de quais comentários
 * entram (sem notas internas) é do SQL de EmailHistorico::buscar e é conferida contra o banco local;
 * aqui fica o que dá para testar sem banco: a renderização.
 */
final class EmailHistoricoTest extends TestCase {

    private function demanda(): array {
        return [
            'id' => 7, 'titulo' => 'Dúvida <b>x</b>', 'descricao' => '<p>Descrição original</p>',
            'criado_em' => '2026-10-23 14:35:00', 'usuario_id' => 1,
            'email_solicitante' => 'maria@exemplo.com.br', 'usuario_email' => 'maria@exemplo.com.br',
        ];
    }

    private function mensagem(int $usuario, string $tipo, string $nome, string $texto, array $anexos = []): array {
        return ['id' => $usuario * 10, 'usuario_id' => $usuario, 'nome' => $nome, 'tipo' => $tipo,
                'criado_em' => '2026-10-24 09:00:00', 'comentario' => $texto, 'anexos' => $anexos];
    }

    public function test_mostra_a_demanda_registrada_mesmo_sem_conversa(): void {
        $html = EmailHistorico::renderizar($this->demanda(), [], 0);
        $this->assertStringContainsString('Demanda registrada', $html);
        $this->assertStringContainsString('#00007', $html);
        $this->assertStringContainsString('Descrição original', $html);
        $this->assertStringNotContainsString('Histórico da conversa', $html);
    }

    public function test_titulo_da_demanda_e_escapado(): void {
        $html = EmailHistorico::renderizar($this->demanda(), [], 0);
        $this->assertStringContainsString('Dúvida &lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function test_identifica_equipe_e_solicitante_e_lista_anexos(): void {
        $msgs = [
            $this->mensagem(2, 'operador', 'Carlos', '<p>Resposta da equipe</p>'),
            $this->mensagem(1, 'cliente', 'Maria', '<p>Resposta da Maria</p>', ['contrato.pdf']),
        ];
        $html = EmailHistorico::renderizar($this->demanda(), $msgs, 2);
        $this->assertStringContainsString('Histórico da conversa', $html);
        $this->assertStringContainsString('>CRPRS<', $html);
        $this->assertStringContainsString('>Solicitante<', $html);
        $this->assertStringContainsString('Anexos: contrato.pdf', $html);
    }

    public function test_remove_script_e_troca_imagem_colada(): void {
        $msgs = [$this->mensagem(1, 'cliente', 'Maria', 'oi <script>alert(1)</script><img src="data:image/png;base64,AAAA">')];
        $html = EmailHistorico::renderizar($this->demanda(), $msgs, 1);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('base64', $html);
        $this->assertStringContainsString('Imagem disponível no sistema', $html);
    }

    public function test_texto_do_usuario_nao_vira_marcador_do_modelo(): void {
        $msgs = [$this->mensagem(1, 'cliente', 'Maria', '{{ASSINATURA_EMAIL}} e {{DEMANDA_E_HISTORICO}}')];
        $html = EmailHistorico::renderizar($this->demanda(), $msgs, 1);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_avisa_quando_ha_mais_mensagens_do_que_o_limite(): void {
        $msgs = [$this->mensagem(1, 'cliente', 'Maria', 'oi')];
        $html = EmailHistorico::renderizar($this->demanda(), $msgs, 45);
        $this->assertStringContainsString('1 mensagens mais recentes de 45', $html);
    }

    public function test_todos_os_modelos_ao_solicitante_tem_o_marcador(): void {
        $modelos = glob(__DIR__ . '/../../../backend/email-template*.html');
        $this->assertCount(7, $modelos);
        foreach ($modelos as $arquivo) {
            $this->assertStringContainsString('{{DEMANDA_E_HISTORICO}}', (string) file_get_contents($arquivo), basename($arquivo));
        }
    }
}
