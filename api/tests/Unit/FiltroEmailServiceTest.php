<?php
use PHPUnit\Framework\TestCase;

/** Regra dos filtros de e-mail (FiltroEmailService): normalização, campos, prioridade e validação. */
final class FiltroEmailServiceTest extends TestCase {

    private function filtro(string $campo, string $termo, array $extra = []): array {
        return array_merge(['id' => 1, 'setor_id' => 2, 'campo' => $campo, 'termo' => $termo,
            'termo_normalizado' => FiltroEmailService::normalizar($termo), 'ativo' => 1], $extra);
    }

    private function email(array $dados = []): array {
        return array_merge(['remetente' => 'Fulano <fulano@exemplo.com.br>', 'destinatarios' => ['setor@crprs.org.br'],
            'assunto' => 'Pedido de certidão', 'corpo' => 'Bom dia, preciso de uma certidão.'], $dados);
    }

    public function test_normalizar_tira_acento_maiuscula_e_espacos_repetidos(): void {
        $this->assertSame('promocao imperdivel', FiltroEmailService::normalizar("  PROMOÇÃO   Imperdível "));
        $this->assertSame('coracao ola nao', FiltroEmailService::normalizar('Coração Olá Não'));
    }

    public function test_acento_e_maiuscula_nao_importam(): void {
        $f = [$this->filtro('assunto', 'Promoção')];
        $this->assertNotNull(FiltroEmailService::coincide($f, $this->email(['assunto' => 'SUPER PROMOCAO DA SEMANA'])));
        $this->assertNull(FiltroEmailService::coincide($f, $this->email()));
    }

    public function test_remetente_por_dominio_endereco_e_nome(): void {
        $this->assertNotNull(FiltroEmailService::coincide([$this->filtro('remetente', '@exemplo.com.br')], $this->email()));
        $this->assertNotNull(FiltroEmailService::coincide([$this->filtro('remetente', 'fulano@exemplo.com.br')], $this->email()));
        $this->assertNotNull(FiltroEmailService::coincide([$this->filtro('remetente', 'fulano')], $this->email()));
        $this->assertNull(FiltroEmailService::coincide([$this->filtro('remetente', '@outro.com')], $this->email()));
    }

    public function test_destinatario_considera_para_e_cc(): void {
        $email = $this->email(['destinatarios' => ['setor@crprs.org.br', 'lista-geral@crprs.org.br']]);
        $this->assertNotNull(FiltroEmailService::coincide([$this->filtro('destinatario', 'lista-geral@')], $email));
        $this->assertNull(FiltroEmailService::coincide([$this->filtro('destinatario', 'outra-lista@')], $email));
    }

    public function test_corpo(): void {
        $f = [$this->filtro('corpo', 'cancelar inscrição')];
        $this->assertNotNull(FiltroEmailService::coincide($f, $this->email(['corpo' => "Para CANCELAR\nINSCRICAO clique aqui"])));
        $this->assertNull(FiltroEmailService::coincide($f, $this->email()));
    }

    public function test_cada_filtro_so_olha_o_proprio_campo(): void {
        // "certidão" está no assunto e no corpo, mas o filtro é de remetente
        $this->assertNull(FiltroEmailService::coincide([$this->filtro('remetente', 'certidão')], $this->email()));
    }

    public function test_filtro_inativo_nao_barra(): void {
        $this->assertNull(FiltroEmailService::coincide([$this->filtro('assunto', 'certidão', ['ativo' => 0])], $this->email()));
    }

    public function test_devolve_o_primeiro_na_ordem_recebida_setor_antes_do_global(): void {
        $filtros = [
            $this->filtro('assunto', 'certidão', ['id' => 10, 'setor_id' => 2]),
            $this->filtro('assunto', 'certidão', ['id' => 3, 'setor_id' => null]),
        ];
        $this->assertSame(10, (int) FiltroEmailService::coincide($filtros, $this->email())['id']);
    }

    public function test_validar_termo(): void {
        $this->assertArrayHasKey('erro', FiltroEmailService::validarTermo('assunto', 'ab'), 'curto demais barraria quase tudo');
        $this->assertArrayHasKey('erro', FiltroEmailService::validarTermo('assunto', '  a  '));
        $this->assertArrayHasKey('erro', FiltroEmailService::validarTermo('invalido', 'promoção'));
        $ok = FiltroEmailService::validarTermo('remetente', ' <b>@Exemplo.com</b> ');
        $this->assertSame('@Exemplo.com', $ok['termo'], 'tags saem e o texto digitado é preservado');
        $this->assertSame('@exemplo.com', $ok['normalizado']);
    }

    public function test_descrever(): void {
        $this->assertSame('Remetente contém "@exemplo.com"', FiltroEmailService::descrever($this->filtro('remetente', '@exemplo.com')));
    }
}
