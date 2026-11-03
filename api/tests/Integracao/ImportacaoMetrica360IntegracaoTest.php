<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/importacao-metrica360.php';

/**
 * Execução da importação do METRICA360 no banco local, em transação desfeita: setor novo nasce completo
 * (SetorService), conta nova com senha desconhecida e troca obrigatória, coordenador com setor adicional.
 */
final class ImportacaoMetrica360IntegracaoTest extends TestCase {

    private PDO $db;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function test_executar_cria_setores_contas_e_configuracoes(): void {
        $sufixo = bin2hex(random_bytes(3));
        $nomeA = "zz Setor Imp A $sufixo";
        $nomeB = "zz Setor Imp B $sufixo";
        $setoresOrigem = [
            ['id' => 1, 'nome' => $nomeA, 'ativo_painel' => 0, 'coordenador_id' => 5],
            ['id' => 2, 'nome' => $nomeB, 'ativo_painel' => 1, 'coordenador_id' => 5],
        ];
        $usuariosOrigem = [
            ['id' => 5, 'email' => "zz.coord.$sufixo@teste.local", 'name' => "zz Coord $sufixo", 'username' => '', 'role' => 'coordenador', 'setor_id' => 1, 'atendimento_externo' => 0],
            ['id' => 6, 'email' => "zz.op.$sufixo@teste.local", 'name' => "zz Op $sufixo", 'username' => '', 'role' => 'atendente', 'setor_id' => 2, 'atendimento_externo' => 1],
        ];
        $setoresDestino = $this->db->query("SELECT id, nome FROM setores")->fetchAll(PDO::FETCH_ASSOC);
        $plano = ImportacaoMetrica360::planejar($setoresOrigem, $usuariosOrigem, $setoresDestino, []);

        $categoria = ['nome' => "zz Cat $sufixo", 'emoji' => '⭐', 'ativo' => 1, 'ordem' => 99];
        $frase = ['texto' => "zz frase $sufixo", 'autor' => 'zz', 'ativo' => 1, 'ordem' => 99];
        $r = ImportacaoMetrica360::executar($this->db, $plano, [$categoria], [$frase], ['quarentena_dias' => '21', 'reenvio_horas' => '72']);

        $this->assertSame(2, $r['setores_criados']);
        $this->assertSame(2, $r['usuarios_criados']);
        $this->assertSame(1, $r['categorias']);
        $this->assertSame(1, $r['frases']);
        $this->assertSame(2, $r['regras']);

        // Setor novo nasce completo e respeita o "fora do Painel de Setores"
        $a = $this->db->prepare("SELECT id, pesquisa_painel FROM setores WHERE nome = ?");
        $a->execute([$nomeA]);
        $setorA = $a->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $setorA['pesquisa_painel']);
        $status = $this->db->prepare("SELECT COUNT(*) FROM setor_status WHERE setor_id = ?");
        $status->execute([$setorA['id']]);
        $this->assertGreaterThan(0, (int) $status->fetchColumn());

        // Coordenador: principal = o setor em que estava lotado, adicional = o outro
        $c = $this->db->prepare("SELECT id, tipo, setor_id, trocar_senha, senha FROM usuarios WHERE email = ?");
        $c->execute(["zz.coord.$sufixo@teste.local"]);
        $coord = $c->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('coordenador', $coord['tipo']);
        $this->assertSame((int) $setorA['id'], (int) $coord['setor_id']);
        $this->assertSame(1, (int) $coord['trocar_senha']);
        $this->assertStringStartsWith('$argon2id$', $coord['senha']);
        $extras = $this->db->prepare("SELECT COUNT(*) FROM usuario_setores WHERE usuario_id = ?");
        $extras->execute([$coord['id']]);
        $this->assertSame(1, (int) $extras->fetchColumn());

        // Operador com atendimento externo
        $o = $this->db->prepare("SELECT tipo, atendimento_externo FROM usuarios WHERE email = ?");
        $o->execute(["zz.op.$sufixo@teste.local"]);
        $op = $o->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('operador', $op['tipo']);
        $this->assertSame(1, (int) $op['atendimento_externo']);

        // Regras copiadas
        $q = $this->db->query("SELECT valor FROM pesquisa_config WHERE chave = 'quarentena_dias'");
        $this->assertSame('21', $q->fetchColumn());
    }

    public function test_executar_duas_vezes_nao_duplica(): void {
        $sufixo = bin2hex(random_bytes(3));
        $setoresOrigem = [['id' => 1, 'nome' => "zz Setor Dup $sufixo", 'ativo_painel' => 1, 'coordenador_id' => null]];
        $usuariosOrigem = [['id' => 6, 'email' => "zz.dup.$sufixo@teste.local", 'name' => "zz Dup $sufixo", 'username' => '', 'role' => 'atendente', 'setor_id' => 1, 'atendimento_externo' => 0]];
        $categoria = ['nome' => "zz Cat Dup $sufixo", 'emoji' => '', 'ativo' => 1, 'ordem' => 1];

        for ($i = 0; $i < 2; $i++) {
            $setoresDestino = $this->db->query("SELECT id, nome FROM setores")->fetchAll(PDO::FETCH_ASSOC);
            $usuariosDestino = $this->db->query("SELECT id, email, nome, tipo, setor_id, atendimento_externo FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
            $plano = ImportacaoMetrica360::planejar($setoresOrigem, $usuariosOrigem, $setoresDestino, $usuariosDestino);
            $r = ImportacaoMetrica360::executar($this->db, $plano, [$categoria], [], []);
            if ($i === 1) {
                $this->assertSame(0, $r['setores_criados']);
                $this->assertSame(0, $r['usuarios_criados']);
                $this->assertSame(0, $r['categorias']);
            }
        }
        $n = $this->db->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ?");
        $n->execute(["zz.dup.$sufixo@teste.local"]);
        $this->assertSame(1, (int) $n->fetchColumn());
    }
}
