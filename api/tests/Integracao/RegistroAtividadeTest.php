<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/registro-atividade-service.php';

/**
 * Registro de Atividades com banco: rascunho, gravação, envio (que trava o relatório), dono, favoritos,
 * histórico e pendências lidas do banco. Banco local em transação desfeita; recusa produção.
 */
final class RegistroAtividadeTest extends TestCase {

    private PDO $db;
    private RegistroAtividadeService $svc;
    private int $setor;
    private int $outroSetor;
    private int $usuario;
    private int $outro;
    private int $tipoA;
    private int $tipoB;
    private int $tipoOutroSetor;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
        $this->svc = new RegistroAtividadeService($this->db);

        $setores = $this->db->query("SELECT id FROM setores WHERE ativo = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if (count($setores) < 2) {
            $this->markTestSkipped('Precisa de dois setores ativos.');
        }
        [$this->setor, $this->outroSetor] = array_map('intval', $setores);
        $this->usuario = $this->criarUsuario($this->setor);
        $this->outro   = $this->criarUsuario($this->setor);
        $this->tipoA = $this->criarTipo($this->setor, 'zz Atendimento');
        $this->tipoB = $this->criarTipo($this->setor, 'zz Ofício');
        $this->tipoOutroSetor = $this->criarTipo($this->outroSetor, 'zz De outro setor');
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function criarUsuario(int $setor): int {
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha) VALUES ('zz Registro', ?, 'x', 'operador', ?, 1, 0)")
                 ->execute(['zz.registro.' . bin2hex(random_bytes(4)) . '@teste.local', $setor]);
        return (int) $this->db->lastInsertId();
    }

    private function criarTipo(int $setor, string $nome): int {
        $this->db->prepare("INSERT INTO atividades_tipos (setor_id, nome) VALUES (?, ?)")->execute([$setor, $nome . ' ' . bin2hex(random_bytes(3))]);
        return (int) $this->db->lastInsertId();
    }

    public function test_rascunho_e_unico_por_dia(): void {
        $a = $this->svc->buscarOuCriarRascunho($this->usuario, '2026-08-10');
        $b = $this->svc->buscarOuCriarRascunho($this->usuario, '2026-08-10');
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame('rascunho', $a['status']);
    }

    public function test_salva_itens_so_do_setor_e_limita_valores(): void {
        $reg = $this->svc->buscarOuCriarRascunho($this->usuario, '2026-08-10');
        $itens = RegistroAtividadeService::normalizarItens([
            ['atividade_tipo_id' => $this->tipoA, 'quantidade' => 99999, 'observacao' => '<b>ok</b>'],
            ['atividade_tipo_id' => $this->tipoB, 'quantidade' => -5],
            ['atividade_tipo_id' => $this->tipoOutroSetor, 'quantidade' => 3],
        ], $this->svc->tiposAtivosDoSetor($this->setor));

        $this->assertTrue($this->svc->salvarRascunho((int) $reg['id'], $this->usuario, $itens, 'presencial'));
        $gravados = array_column($this->svc->itens((int) $reg['id']), null, 'atividade_tipo_id');
        $this->assertSame(9999, $gravados[$this->tipoA]['quantidade']);
        $this->assertSame('ok', $gravados[$this->tipoA]['observacao']);
        $this->assertSame(0, $gravados[$this->tipoB]['quantidade']);
        $this->assertArrayNotHasKey($this->tipoOutroSetor, $gravados);
        $this->assertSame('presencial', $this->svc->buscar((int) $reg['id'])['modalidade']);
    }

    public function test_enviado_nao_muda_mais_e_so_o_dono_envia(): void {
        $reg = $this->svc->buscarOuCriarRascunho($this->usuario, '2026-08-11');
        $id = (int) $reg['id'];

        $this->assertFalse($this->svc->finalizar($id, $this->outro, 'remoto'), 'outro usuário não envia');
        $this->assertFalse(RegistroAtividadeService::dono($this->svc->buscar($id), $this->outro));

        $this->assertTrue($this->svc->finalizar($id, $this->usuario, null));
        $enviado = $this->svc->buscar($id);
        $this->assertSame('enviado', $enviado['status']);
        $this->assertSame('remoto', $enviado['modalidade'], 'sem modalidade informada, fica remoto');

        $this->assertFalse($this->svc->finalizar($id, $this->usuario, 'remoto'), 'não envia duas vezes');
        $this->assertFalse($this->svc->salvarRascunho($id, $this->usuario, [['atividade_tipo_id' => $this->tipoA, 'quantidade' => 1, 'observacao' => '']], null));
    }

    public function test_historico_conta_atividades_e_tarefas(): void {
        $reg = $this->svc->buscarOuCriarRascunho($this->usuario, '2026-08-12');
        $this->svc->salvarRascunho((int) $reg['id'], $this->usuario, [
            ['atividade_tipo_id' => $this->tipoA, 'quantidade' => 3, 'observacao' => ''],
            ['atividade_tipo_id' => $this->tipoB, 'quantidade' => 0, 'observacao' => ''],
        ], null);
        $this->svc->finalizar((int) $reg['id'], $this->usuario, null);
        $this->svc->buscarOuCriarRascunho($this->usuario, '2026-08-13'); // rascunho não entra

        $h = $this->svc->historico($this->usuario);
        $this->assertCount(1, $h);
        $this->assertSame(1, $h[0]['total_itens']);
        $this->assertSame(3, $h[0]['total_tarefas']);
        $this->assertCount(1, $this->svc->itens((int) $reg['id'], true));
    }

    public function test_favorito_liga_desliga_e_recusa_outro_setor(): void {
        $this->assertTrue($this->svc->alternarFavorito($this->usuario, $this->setor, $this->tipoB));
        $lista = $this->svc->atividadesDoSetor($this->setor, $this->usuario);
        $this->assertSame($this->tipoB, $lista[0]['id']);
        $this->assertTrue($lista[0]['favorito']);

        $this->assertFalse($this->svc->alternarFavorito($this->usuario, $this->setor, $this->tipoB));
        $this->assertNull($this->svc->alternarFavorito($this->usuario, $this->setor, $this->tipoOutroSetor));
    }

    public function test_pendencias_e_abono_pelo_banco(): void {
        $this->db->prepare("INSERT INTO atividades_config_setor (setor_id, entrega_ativa, entrega_dias) VALUES (?, 1, '1,2,3,4,5')
                            ON DUPLICATE KEY UPDATE entrega_ativa = 1, entrega_dias = '1,2,3,4,5', data_base = NULL, entrega_inicio = NULL, prazo_dias_atraso = 0")
                 ->execute([$this->setor]);
        $this->db->prepare("INSERT INTO atividades_config_usuario (usuario_id, trabalho_remoto) VALUES (?, 0)")->execute([$this->usuario]);
        $reg = $this->svc->buscarOuCriarRascunho($this->usuario, '2026-08-10');
        $this->svc->finalizar((int) $reg['id'], $this->usuario, 'presencial');

        $prazo = new PrazoAtividadeService($this->db);
        $datas = array_column($prazo->pendenciasUsuario($this->usuario, '2026-08-10', '2026-08-14'), 'data');
        // Feriados do setor também contam (se houver algum nessa semana no banco local, ele some da lista)
        $this->assertNotContains('2026-08-10', $datas);
        $this->assertContains('2026-08-11', $datas);

        $this->assertFalse($prazo->abonar($this->usuario, '2026-08-10', 'já enviado', $this->outro, 'entrega'));
        $this->assertTrue($prazo->abonar($this->usuario, '2026-08-11', 'Atestado', $this->outro, 'entrega'));
        $porData = array_column($prazo->pendenciasUsuario($this->usuario, '2026-08-10', '2026-08-14'), null, 'data');
        $this->assertSame('abonado', $porData['2026-08-11']['status']);

        $this->assertContains($this->usuario, array_column($prazo->statusSetor($this->setor, '2026-08-10', '2026-08-14'), 'usuario_id'));
        $this->assertSame([1, 2, 3, 4, 5], $prazo->diasDeEntregaDoUsuario($this->usuario));
    }

    public function test_data_valida(): void {
        $this->assertSame('2026-08-10', RegistroAtividadeService::dataValida('2026-08-10', '2026-10-27'));
        $this->assertNull(RegistroAtividadeService::dataValida('2026-11-01', '2026-10-27'));
        $this->assertNull(RegistroAtividadeService::dataValida('2019-12-31', '2026-10-27'));
        $this->assertNull(RegistroAtividadeService::dataValida('2026-02-30', '2026-10-27'));
        $this->assertNull(RegistroAtividadeService::dataValida("2026-08-10' OR 1", '2026-10-27'));
    }
}
