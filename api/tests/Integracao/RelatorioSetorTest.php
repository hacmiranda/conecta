<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/relatorio-setor-service.php';
require_once __DIR__ . '/../../services/registro-atividade-service.php';

/**
 * Esquema da migração 43 (setor no registro de atividades e relatório do setor enviado pelo coordenador).
 * Banco local em transação desfeita; recusa produção. Não executa DDL (faria commit implícito).
 */
final class RelatorioSetorTest extends TestCase {

    private PDO $db;
    private int $setor;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
        $this->setor = (int) $this->db->query("SELECT id FROM setores WHERE ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($this->setor <= 0) {
            $this->markTestSkipped('Precisa de um setor ativo.');
        }
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function criarUsuario(int $setor): int {
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha) VALUES ('zz Relatorio', ?, 'x', 'operador', ?, 1, 0)")
                 ->execute(['zz.relsetor.' . bin2hex(random_bytes(4)) . '@teste.local', $setor]);
        return (int) $this->db->lastInsertId();
    }

    public function test_migracao_preencheu_setor_dos_registros_existentes(): void {
        $nulos = (int) $this->db->query("SELECT COUNT(*) FROM registros_atividades WHERE setor_id IS NULL")->fetchColumn();
        $this->assertSame(0, $nulos);

        $porRegistro = $this->db->query("SELECT r.setor_id, SUM(i.quantidade) AS q FROM registros_atividades r
            JOIN registros_atividades_itens i ON i.registro_id = r.id GROUP BY r.setor_id ORDER BY r.setor_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $porUsuario = $this->db->query("SELECT u.setor_id, SUM(i.quantidade) AS q FROM registros_atividades r
            JOIN usuarios u ON u.id = r.usuario_id JOIN registros_atividades_itens i ON i.registro_id = r.id
            WHERE u.setor_id = r.setor_id GROUP BY u.setor_id ORDER BY u.setor_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertEquals($porUsuario, $porRegistro);
    }

    public function test_chave_setor_impede_dois_relatorios_do_mesmo_setor_e_periodo(): void {
        $a = $this->criarUsuario($this->setor);
        $b = $this->criarUsuario($this->setor);
        $sql = "INSERT INTO registros_atividades (usuario_id, setor_id, periodo, data_referencia, status) VALUES (?, ?, 'semana', '2026-08-10', 'rascunho')";
        $this->db->prepare($sql)->execute([$a, $this->setor]);

        try {
            $this->db->prepare($sql)->execute([$b, $this->setor]);
            $this->fail('Era esperada uma violação de unicidade.');
        } catch (PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
    }

    public function test_relatorios_diarios_do_mesmo_setor_continuam_livres(): void {
        $a = $this->criarUsuario($this->setor);
        $b = $this->criarUsuario($this->setor);
        $sql = "INSERT INTO registros_atividades (usuario_id, setor_id, data_referencia) VALUES (?, ?, '2026-08-10')";
        $this->db->prepare($sql)->execute([$a, $this->setor]);
        $this->db->prepare($sql)->execute([$b, $this->setor]);
        $this->assertTrue(true);
    }

    /** Linha do servidor em statusSetor (a chave 'desobrigado' precisa existir). */
    private function linhaDoStatus(PrazoAtividadeService $svc, int $usuarioId): array {
        foreach ($svc->statusSetor($this->setor, '2026-08-10', '2026-08-16') as $l) {
            if ($l['usuario_id'] === $usuarioId) return $l;
        }
        $this->fail('Servidor não apareceu em statusSetor.');
    }

    public function test_pendencias_do_servidor_respeitam_a_vigencia_do_setor(): void {
        require_once __DIR__ . '/../../services/prazo-atividade-service.php';
        $u = $this->criarUsuario($this->setor);
        $this->db->prepare("INSERT INTO atividades_config_usuario (usuario_id, trabalho_remoto, qtd_dias_remoto_semana, entrega_dias) VALUES (?, 1, 3, '')")->execute([$u]);
        $this->db->prepare("INSERT INTO atividades_config_setor (setor_id, modo_controle_remoto, prazo_dias_atraso, entrega_ativa, data_base) VALUES (?, 'semanal', 0, 0, NULL)
            ON DUPLICATE KEY UPDATE modo_controle_remoto = 'semanal', prazo_dias_atraso = 0, entrega_ativa = 0, data_base = NULL")->execute([$this->setor]);
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);

        $svc = new PrazoAtividadeService($this->db);
        $antes = $svc->pendenciasUsuario($u, '2026-08-10', '2026-08-16');
        $this->assertCount(1, $antes);
        $this->assertFalse($this->linhaDoStatus($svc, $u)['desobrigado']);

        $this->db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim) VALUES (?, 'semanal', '2026-08-10', NULL)")->execute([$this->setor]);
        $this->assertSame([], $svc->pendenciasUsuario($u, '2026-08-10', '2026-08-16'));
        $this->assertCount(1, $svc->vigenciasDoSetor($this->setor));
        $this->assertTrue($this->linhaDoStatus($svc, $u)['desobrigado']);

        // Relatório do setor enviado não conta como relatório individual do servidor
        $this->db->prepare("INSERT INTO registros_atividades (usuario_id, setor_id, periodo, data_referencia, status) VALUES (?, ?, 'semana', '2026-08-10', 'enviado')")->execute([$u, $this->setor]);
        $this->db->prepare("UPDATE atividades_relatorio_setor SET fim = '2026-08-09' WHERE setor_id = ?")->execute([$this->setor]);
        $this->assertCount(1, $svc->pendenciasUsuario($u, '2026-08-10', '2026-08-16'));
    }

    // ---------- RelatorioSetorService (Tarefa 4) ----------

    private function servico(bool $comCoordenador = true): RelatorioSetorService {
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        if ($comCoordenador) {
            $this->criarCoordenador($this->setor);
        }
        return new RelatorioSetorService($this->db);
    }

    private function criarCoordenador(int $setor, int $ativo = 1): int {
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha) VALUES ('zz Coord', ?, 'x', 'coordenador', ?, ?, 0)")
                 ->execute(['zz.coord.' . bin2hex(random_bytes(4)) . '@teste.local', $setor, $ativo]);
        return (int) $this->db->lastInsertId();
    }

    private function linhas(): array {
        $stmt = $this->db->prepare("SELECT periodicidade, inicio, fim FROM atividades_relatorio_setor WHERE setor_id = ? ORDER BY inicio");
        $stmt->execute([$this->setor]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_ligar_comeca_no_inicio_da_semana(): void {
        $svc = $this->servico();
        $r = $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-18'); // quarta
        $this->assertNull($r['antes']['atual']);
        $this->assertSame('2026-11-16', $r['depois']['atual']['inicio']);
        $this->assertNull($r['depois']['atual']['fim']);
        $this->assertSame('semanal', $svc->vigenciaAtual($this->setor, '2026-11-18')['periodicidade']);
    }

    public function test_ligar_mensal_comeca_no_dia_primeiro(): void {
        $svc = $this->servico();
        $r = $svc->alterar($this->setor, true, 'mensal', 1, '2026-11-18');
        $this->assertSame('2026-11-01', $r['depois']['atual']['inicio']);
    }

    public function test_desligar_vale_ate_o_fim_do_periodo(): void {
        $svc = $this->servico();
        $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-18');
        $r = $svc->alterar($this->setor, false, 'semanal', 1, '2026-11-18');
        $this->assertSame('2026-11-22', $r['depois']['atual']['fim']); // domingo
        $this->assertNull($svc->vigenciaAtual($this->setor, '2026-11-23'));
        // Desligar de novo não muda nada
        $r2 = $svc->alterar($this->setor, false, 'semanal', 1, '2026-11-18');
        $this->assertSame($r['depois'], $r2['depois']);
    }

    public function test_religar_antes_do_fim_cancela_o_fim(): void {
        $svc = $this->servico();
        $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-18');
        $svc->alterar($this->setor, false, 'semanal', 1, '2026-11-18');
        $r = $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-20');
        $this->assertNull($r['depois']['atual']['fim']);
        $this->assertCount(1, $this->linhas());
        $enc = $this->db->prepare("SELECT encerrado_por, encerrado_em FROM atividades_relatorio_setor WHERE setor_id = ?");
        $enc->execute([$this->setor]);
        $this->assertSame([null, null], array_values($enc->fetch(PDO::FETCH_NUM)));
    }

    public function test_trocar_periodicidade_abre_a_nova_no_dia_seguinte(): void {
        $svc = $this->servico();
        $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-18');
        $r = $svc->alterar($this->setor, true, 'mensal', 1, '2026-11-18');
        $this->assertSame('2026-11-22', $r['depois']['atual']['fim']);
        $this->assertSame('mensal', $r['depois']['proxima']['periodicidade']);
        $this->assertSame('2026-11-23', $r['depois']['proxima']['inicio']);
        $this->assertSame('2026-11-23', $svc->proximaVigencia($this->setor, '2026-11-18')['inicio']);
        // Repetir o mesmo pedido não muda nada (mesmas linhas)
        $antes = $this->linhas();
        $r2 = $svc->alterar($this->setor, true, 'mensal', 1, '2026-11-18');
        $this->assertSame($antes, $this->linhas());
        $this->assertSame($r['depois'], $r2['depois']);
        // Trocar de volta apaga a próxima e cancela o fim
        $r3 = $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-19');
        $this->assertNull($r3['depois']['proxima']);
        $this->assertNull($r3['depois']['atual']['fim']);
        $this->assertCount(1, $this->linhas());
    }

    public function test_desligar_apaga_a_proxima_vigencia(): void {
        $svc = $this->servico();
        $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-18');
        $svc->alterar($this->setor, true, 'mensal', 1, '2026-11-18');
        $r = $svc->alterar($this->setor, false, 'mensal', 1, '2026-11-18');
        $this->assertNull($r['depois']['proxima']);
        $this->assertSame('2026-11-22', $r['depois']['atual']['fim']);
        $this->assertCount(1, $this->linhas());
    }

    public function test_ligar_logo_depois_de_desligar_nao_sobrepoe_vigencias(): void {
        $svc = $this->servico();
        $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-18');
        $svc->alterar($this->setor, false, 'semanal', 1, '2026-11-18'); // fim 22/11
        // Em 23/11 a vigência semanal já acabou; a mensal não pode voltar ao dia 01/11
        $r = $svc->alterar($this->setor, true, 'mensal', 1, '2026-11-23');
        $this->assertSame('2026-11-23', $r['depois']['atual']['inicio']);
        $linhas = $this->linhas();
        $this->assertCount(2, $linhas);
        $this->assertLessThan($linhas[1]['inicio'], $linhas[0]['fim']);
    }

    public function test_ligar_mensal_logo_apos_desligar_semanal_no_mesmo_dia_comeca_na_segunda_seguinte(): void {
        $svc = $this->servico();
        $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-18');
        $svc->alterar($this->setor, false, 'semanal', 1, '2026-11-18'); // atual com fim 22/11
        // Mesmo dia: a atual ainda cobre hoje; ligar mensal troca (fecha no domingo, mensal abre na segunda)
        $r = $svc->alterar($this->setor, true, 'mensal', 1, '2026-11-18');
        $this->assertSame('2026-11-22', $r['depois']['atual']['fim']);
        $this->assertSame('2026-11-23', $r['depois']['proxima']['inicio']);
        $this->assertCount(2, $this->linhas());
    }

    public function test_nao_liga_sem_coordenador_ativo(): void {
        $svc = $this->servico(false);
        $this->criarCoordenador($this->setor, 0); // inativo não conta
        $this->expectException(InvalidArgumentException::class);
        $svc->alterar($this->setor, true, 'semanal', 1, '2026-11-18');
    }

    public function test_periodicidade_invalida_e_recusada(): void {
        $svc = $this->servico();
        $this->expectException(InvalidArgumentException::class);
        $svc->alterar($this->setor, true, 'diaria', 1, '2026-11-18');
    }

    public function test_coordenador_adicional_conta_como_coordenador_do_setor(): void {
        $svc = $this->servico(false);
        $stmt = $this->db->prepare("SELECT id FROM setores WHERE ativo = 1 AND id <> ? ORDER BY id LIMIT 1");
        $stmt->execute([$this->setor]);
        $outro = (int) $stmt->fetchColumn();
        if ($outro <= 0) {
            $this->markTestSkipped('Precisa de dois setores ativos.');
        }
        $coord = $this->criarCoordenador($outro);
        $this->db->prepare("INSERT INTO usuario_setores (usuario_id, setor_id) VALUES (?, ?)")->execute([$coord, $this->setor]);
        $this->assertSame($coord, $svc->coordenadorDoSetor($this->setor)['id']);
    }

    public function test_setores_do_coordenador_so_com_vigencia_de_hoje(): void {
        $svc = $this->servico();
        $coord = (int) $this->db->query("SELECT id FROM usuarios WHERE tipo = 'coordenador' AND setor_id = {$this->setor} ORDER BY id DESC LIMIT 1")->fetchColumn();
        $user = ['id' => $coord, 'tipo' => 'coordenador', 'setor_id' => $this->setor, 'setores_extras' => []];
        $this->assertSame([], RelatorioSetorService::setoresDoCoordenador($this->db, $user));
        $svc->alterar($this->setor, true, 'mensal', 1);
        $lista = RelatorioSetorService::setoresDoCoordenador($this->db, $user);
        $this->assertSame([$this->setor], array_column($lista, 'setor_id'));
        $this->assertSame('mensal', $lista[0]['periodicidade']);
        $this->assertSame([], RelatorioSetorService::setoresDoCoordenador($this->db, ['id' => 1, 'tipo' => 'operador', 'setor_id' => $this->setor]));
    }

    // ---------- Envio do relatório do setor (Tarefa 5) ----------

    private function criarTipo(int $setor): int {
        $this->db->prepare("INSERT INTO atividades_tipos (setor_id, nome) VALUES (?, ?)")->execute([$setor, 'zz tipo ' . bin2hex(random_bytes(3))]);
        return (int) $this->db->lastInsertId();
    }

    private function item(int $tipo, int $qtd): array {
        return ['atividade_tipo_id' => $tipo, 'quantidade' => $qtd, 'observacao' => ''];
    }

    private function outroSetor(): int {
        $id = (int) $this->db->query("SELECT id FROM setores WHERE ativo = 1 AND id <> {$this->setor} ORDER BY id LIMIT 1")->fetchColumn();
        if ($id <= 0) {
            $this->markTestSkipped('Precisa de dois setores ativos.');
        }
        return $id;
    }

    public function test_coordenador_envia_relatorio_semanal_do_setor(): void {
        $coord = $this->criarCoordenador($this->setor);
        $tipo = $this->criarTipo($this->setor);
        $svc = new RegistroAtividadeService($this->db);

        $reg = $svc->buscarOuCriarRascunhoSetor($this->setor, 'semana', '2026-08-10', $coord);
        $this->assertSame('rascunho', $reg['status']);
        $this->assertSame($coord, (int) $reg['usuario_id']);
        $this->assertTrue($svc->salvarRascunhoSetor((int) $reg['id'], [$this->item($tipo, 7)]));
        $this->assertTrue($svc->finalizarSetor((int) $reg['id'], $coord));

        $enviado = $svc->buscar((int) $reg['id']);
        $this->assertSame('enviado', $enviado['status']);
        $this->assertNull($enviado['modalidade']);
        $this->assertSame('semana', $enviado['periodo']);
        $this->assertSame(7, $svc->itens((int) $reg['id'])[0]['quantidade']);

        $hist = $svc->historicoSetor($this->setor);
        $this->assertSame((int) $reg['id'], $hist[0]['id']);
        $this->assertSame('semana', $hist[0]['periodo']);
        $this->assertSame('zz Coord', $hist[0]['usuario_nome']);
        $this->assertSame(7, $hist[0]['total_tarefas']);
    }

    public function test_coordenador_de_dois_setores_envia_os_dois_no_mesmo_periodo(): void {
        $outro = $this->outroSetor();
        $coord = $this->criarCoordenador($this->setor);
        $svc = new RegistroAtividadeService($this->db);
        $a = $svc->buscarOuCriarRascunhoSetor($this->setor, 'semana', '2026-08-10', $coord);
        $b = $svc->buscarOuCriarRascunhoSetor($outro, 'semana', '2026-08-10', $coord);
        $this->assertNotSame($a['id'], $b['id']);
        $this->assertTrue($svc->finalizarSetor((int) $a['id'], $coord));
        $this->assertTrue($svc->finalizarSetor((int) $b['id'], $coord));
    }

    public function test_novo_coordenador_continua_o_mesmo_rascunho(): void {
        $antigo = $this->criarCoordenador($this->setor);
        $novo = $this->criarCoordenador($this->setor);
        $tipo = $this->criarTipo($this->setor);
        $svc = new RegistroAtividadeService($this->db);
        $reg = $svc->buscarOuCriarRascunhoSetor($this->setor, 'mes', '2026-08-01', $antigo);
        $svc->salvarRascunhoSetor((int) $reg['id'], [$this->item($tipo, 3)]);

        $continuado = $svc->buscarOuCriarRascunhoSetor($this->setor, 'mes', '2026-08-01', $novo);
        $this->assertSame($reg['id'], $continuado['id']);
        $this->assertSame(3, $svc->itens((int) $reg['id'])[0]['quantidade']);
        $this->assertTrue($svc->finalizarSetor((int) $reg['id'], $novo));
        $this->assertSame($novo, (int) $svc->buscar((int) $reg['id'])['usuario_id']);
    }

    public function test_relatorio_enviado_nao_muda(): void {
        $coord = $this->criarCoordenador($this->setor);
        $tipo = $this->criarTipo($this->setor);
        $svc = new RegistroAtividadeService($this->db);
        $reg = $svc->buscarOuCriarRascunhoSetor($this->setor, 'semana', '2026-08-10', $coord);
        $svc->salvarRascunhoSetor((int) $reg['id'], [$this->item($tipo, 2)]);
        $this->assertTrue($svc->finalizarSetor((int) $reg['id'], $coord));

        $this->assertFalse($svc->salvarRascunhoSetor((int) $reg['id'], [$this->item($tipo, 9)]));
        $this->assertFalse($svc->finalizarSetor((int) $reg['id'], $this->criarCoordenador($this->setor)));
        $this->assertSame(2, $svc->itens((int) $reg['id'])[0]['quantidade']);
        $this->assertSame($coord, (int) $svc->buscar((int) $reg['id'])['usuario_id']);
    }

    public function test_servidor_desobrigado_recebe_409_e_fora_da_vigencia_envia_normal(): void {
        $prazo = new PrazoAtividadeService($this->db);
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $this->db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim) VALUES (?, 'semanal', '2026-08-10', '2026-08-23')")->execute([$this->setor]);
        $vigencias = $prazo->vigenciasDoSetor($this->setor);

        // A regra que o controller usa para o 409 (e para o campo desobrigado do GET)
        $dentro = PrazoAtividadeService::vigenciaQueCobre($vigencias, '2026-08-12');
        $this->assertSame('2026-08-10', $dentro['inicio']);
        $this->assertNull(PrazoAtividadeService::vigenciaQueCobre($vigencias, '2026-08-09'));
        $this->assertNull(PrazoAtividadeService::vigenciaQueCobre($vigencias, '2026-08-24'));

        // Fora da vigência o relatório individual segue o caminho normal
        $u = $this->criarUsuario($this->setor);
        $svc = new RegistroAtividadeService($this->db);
        $reg = $svc->buscarOuCriarRascunho($u, $this->setor, '2026-08-24');
        $this->assertTrue($svc->finalizar((int) $reg['id'], $u, 'remoto'));
    }

    public function test_pendencias_do_relatorio_do_setor_leem_envios_e_abonos(): void {
        $coord = $this->criarCoordenador($this->setor);
        $this->db->prepare("INSERT INTO atividades_config_setor (setor_id, modo_controle_remoto, prazo_dias_atraso, entrega_ativa, data_base) VALUES (?, 'semanal', 0, 0, NULL)
            ON DUPLICATE KEY UPDATE prazo_dias_atraso = 0, data_base = NULL")->execute([$this->setor]);
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $this->db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim) VALUES (?, 'semanal', '2026-08-03', NULL)")->execute([$this->setor]);

        $prazo = new PrazoAtividadeService($this->db);
        $agora = new DateTimeImmutable('2026-08-20 12:00:00');
        $datas = fn(array $l) => array_column($l, 'data_referencia');
        $this->assertSame(['2026-08-03', '2026-08-10'], $datas($prazo->pendenciasRelatorioSetor($this->setor, '2026-08-01', '2026-08-20', $agora)));

        $svc = new RegistroAtividadeService($this->db);
        $reg = $svc->buscarOuCriarRascunhoSetor($this->setor, 'semana', '2026-08-03', $coord);
        $svc->finalizarSetor((int) $reg['id'], $coord);
        $this->db->prepare("INSERT INTO atividades_abonos_setor (setor_id, periodo, data_referencia, justificativa, criado_por) VALUES (?, 'semana', '2026-08-10', 'zz teste', ?)")->execute([$this->setor, $coord]);

        $pend = $prazo->pendenciasRelatorioSetor($this->setor, '2026-08-01', '2026-08-20', $agora);
        $this->assertSame(['2026-08-10'], $datas($pend));
        $this->assertSame('abonado', $pend[0]['status']);
        $this->assertSame('zz Coord', $pend[0]['abonado_por']);
        $this->assertSame('zz teste', $pend[0]['justificativa']);
        $this->assertSame([], $prazo->pendenciasRelatorioSetor($this->outroSetor(), '2026-08-01', '2026-08-20', $agora));
    }

    public function test_setores_do_coordenador_inclui_vigencia_terminada_ha_ate_180_dias(): void {
        $this->servico();
        $coord = (int) $this->db->query("SELECT id FROM usuarios WHERE tipo = 'coordenador' AND setor_id = {$this->setor} ORDER BY id DESC LIMIT 1")->fetchColumn();
        // Sem 'setores_extras' no array (como a linha de usuarios em login/me.php): lê usuario_setores
        $user = ['id' => $coord, 'tipo' => 'coordenador', 'setor_id' => $this->setor];
        $ins = $this->db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim) VALUES (?, 'semanal', ?, ?)");

        $ins->execute([$this->setor, date('Y-m-d', strtotime('-400 days')), date('Y-m-d', strtotime('-200 days'))]);
        $this->assertSame([], RelatorioSetorService::setoresDoCoordenador($this->db, $user));

        $ins->execute([$this->setor, date('Y-m-d', strtotime('-199 days')), date('Y-m-d', strtotime('-100 days'))]);
        $lista = RelatorioSetorService::setoresDoCoordenador($this->db, $user);
        $this->assertSame([$this->setor], array_column($lista, 'setor_id'));
        $this->assertSame('semanal', $lista[0]['periodicidade']);
    }
}
