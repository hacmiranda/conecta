<?php
use PHPUnit\Framework\TestCase;

/**
 * Ativação de cada e-mail por setor: sem linha = ativado (setor novo, ou código novo, nascem
 * com tudo ligado). ativa() usa banco — aqui testamos só o catálogo e o cache; o comportamento
 * contra o banco de verdade é conferido manualmente/matriz de acesso.
 */
final class NotificacaoEmailServiceTest extends TestCase {

    public function test_catalogo_tem_os_dois_grupos(): void {
        $grupos = array_unique(array_column(NotificacaoEmailService::CATALOGO, 0));
        sort($grupos);
        $this->assertSame([NotificacaoEmailService::GRUPO_INTERNO, NotificacaoEmailService::GRUPO_SOLICITANTE], $grupos);
    }

    public function test_cada_item_do_catalogo_tem_nome_e_descricao(): void {
        foreach (NotificacaoEmailService::CATALOGO as $codigo => [$grupo, $nome, $quando]) {
            $this->assertNotSame('', $nome, $codigo);
            $this->assertNotSame('', $quando, $codigo);
        }
    }

    public function test_sem_setor_sempre_ativo(): void {
        // Não precisa de PDO real: o caminho sem setor retorna antes de tocar no banco
        $this->assertTrue(NotificacaoEmailService::ativa($this->pdoNuncaUsado(), null, 'sol_demanda_criada'));
    }

    public function test_nome_grupo(): void {
        $this->assertSame('Para solicitantes', NotificacaoEmailService::nomeGrupo(NotificacaoEmailService::GRUPO_SOLICITANTE));
        $this->assertSame('Para agentes internos', NotificacaoEmailService::nomeGrupo(NotificacaoEmailService::GRUPO_INTERNO));
    }

    private function pdoNuncaUsado(): PDO {
        // Um PDO real, mas que nunca deve ser consultado neste teste (sem setor_id, ativa()
        // retorna antes de preparar qualquer statement); evita duplicar um mock de PDO.
        return new class extends PDO {
            public function __construct() {}
            public function prepare(string $query, array $options = []): PDOStatement|false {
                throw new RuntimeException('ativa() não deveria consultar o banco sem setor_id');
            }
        };
    }
}
