<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/relatorio-setor-service.php';
require_once __DIR__ . '/../../services/registro-atividade-service.php';
require_once __DIR__ . '/../../services/atividades-acompanhamento-service.php';

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
        // Setor próprio do teste (dentro da transação desfeita): um setor real pode já ter coordenador
        $this->setor = $this->criarSetor();
    }

    /** Cria um setor zz com o expediente, os feriados, status e prioridades do modelo (SetorService::criar). */
    private function criarSetor(): int {
        require_once __DIR__ . '/../../services/setor-service.php';
        return (int) SetorService::criar($this->db, 'zz RelSetor ' . bin2hex(random_bytes(4)));
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
        $outro = $this->outroSetor();
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
        return $this->criarSetor();
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

    // ---------- Acompanhamento e abono (Tarefa 7) ----------

    /** Vigência semanal aberta desde 2026-08-03, sem prazo extra nem data base. */
    private function prepararSetorComRelatorio(): void {
        $this->db->prepare("INSERT INTO atividades_config_setor (setor_id, modo_controle_remoto, prazo_dias_atraso, entrega_ativa, data_base) VALUES (?, 'semanal', 0, 0, NULL)
            ON DUPLICATE KEY UPDATE prazo_dias_atraso = 0, data_base = NULL")->execute([$this->setor]);
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $this->db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim) VALUES (?, 'semanal', '2026-08-03', NULL)")->execute([$this->setor]);
    }

    public function test_relatorio_do_setor_soma_no_relatorio_e_no_cruzamento(): void {
        $this->prepararSetorComRelatorio();
        $coord = $this->criarCoordenador($this->setor);
        $op = $this->criarUsuario($this->setor);
        $tipo = $this->criarTipo($this->setor);
        $reg = new RegistroAtividadeService($this->db);
        $acomp = new AtividadesAcompanhamentoService($this->db);

        $antes = $acomp->atividadesDoSetor($this->setor, '2026-08-01', '2026-08-31');
        $antesRel = $acomp->relatorio($this->setor, null, '2026-08-01', '2026-08-31', '', 1, 1000);

        $s = $reg->buscarOuCriarRascunhoSetor($this->setor, 'semana', '2026-08-03', $coord);
        $reg->salvarRascunhoSetor((int) $s['id'], [$this->item($tipo, 7)]);
        $this->assertTrue($reg->finalizarSetor((int) $s['id'], $coord));
        $d = $reg->buscarOuCriarRascunho($op, $this->setor, '2026-08-04');
        $reg->salvarRascunho((int) $d['id'], $op, [$this->item($tipo, 3)], 'remoto');
        $this->assertTrue($reg->finalizar((int) $d['id'], $op, 'remoto'));

        $rel = $acomp->relatorio($this->setor, null, '2026-08-01', '2026-08-31', '', 1, 1000);
        $this->assertSame($antesRel['total'] + 2, $rel['total']);
        $this->assertSame($antesRel['por_modalidade']['setor']['relatorios'] + 1, $rel['por_modalidade']['setor']['relatorios']);
        $this->assertSame($antesRel['por_modalidade']['setor']['tarefas'] + 7, $rel['por_modalidade']['setor']['tarefas']);
        $this->assertSame($antesRel['metricas']['total_usuarios'] + 1, $rel['metricas']['total_usuarios']); // só o servidor

        $itemSetor = current(array_filter($rel['itens'], fn($i) => $i['id'] === (int) $s['id']));
        $this->assertSame('setor', $itemSetor['tipo']);
        $this->assertSame('semana', $itemSetor['periodo']);
        $this->assertSame('2026-08-09', $itemSetor['periodo_fim']);
        $this->assertSame('setor', $itemSetor['modalidade']);
        $itemDia = current(array_filter($rel['itens'], fn($i) => $i['id'] === (int) $d['id']));
        $this->assertSame('servidor', $itemDia['tipo']);

        $soSetor = $acomp->relatorio($this->setor, -1, '2026-08-01', '2026-08-31', '', 1, 1000);
        $this->assertNotContains((int) $d['id'], array_column($soSetor['itens'], 'id'));
        $this->assertContains((int) $s['id'], array_column($soSetor['itens'], 'id'));
        $porModalidade = $acomp->relatorio($this->setor, null, '2026-08-01', '2026-08-31', 'setor', 1, 1000);
        $this->assertSame($soSetor['total'], $porModalidade['total']);
        $remotos = $acomp->relatorio($this->setor, null, '2026-08-01', '2026-08-31', 'remoto', 1, 1000);
        $this->assertNotContains((int) $s['id'], array_column($remotos['itens'], 'id'));

        $det = $acomp->detalhe((int) $s['id'], [$this->setor]);
        $this->assertSame('setor', $det['tipo']);
        $this->assertSame(7, (int) $det['itens'][0]['quantidade']);

        $dep = $acomp->atividadesDoSetor($this->setor, '2026-08-01', '2026-08-31');
        $this->assertSame($antes['total_tarefas'] + 10, $dep['total_tarefas']);
        $this->assertSame($antes['total_enviados'] + 2, $dep['total_enviados']);
        $this->assertTrue($dep['relatorio_setor_na_serie']);
        $uso = $acomp->atividadesDoUsuario($op, '2026-08-01', '2026-08-31'); // conta do servidor: só o relatório diário
        $this->assertSame(3, $uso['total_tarefas']);
        $this->assertSame(1, $uso['total_enviados']);
    }

    public function test_registro_antigo_fica_no_setor_onde_foi_enviado_apos_transferencia(): void {
        $outro = $this->outroSetor();
        $op = $this->criarUsuario($this->setor);
        $reg = new RegistroAtividadeService($this->db);
        $acomp = new AtividadesAcompanhamentoService($this->db);
        $d = $reg->buscarOuCriarRascunho($op, $this->setor, '2026-08-04');
        $this->assertTrue($reg->finalizar((int) $d['id'], $op, 'remoto'));

        $this->db->prepare("UPDATE usuarios SET setor_id = ? WHERE id = ?")->execute([$outro, $op]);

        $noAntigo = $acomp->relatorio($this->setor, null, '2026-08-01', '2026-08-31', '', 1, 1000);
        $noNovo = $acomp->relatorio($outro, null, '2026-08-01', '2026-08-31', '', 1, 1000);
        $this->assertContains((int) $d['id'], array_column($noAntigo['itens'], 'id'));
        $this->assertNotContains((int) $d['id'], array_column($noNovo['itens'], 'id'));
        $this->assertNotNull($acomp->detalhe((int) $d['id'], [$this->setor]));
        $this->assertNull($acomp->detalhe((int) $d['id'], [$outro]));
        // Cruzamento por setor e painel do setor novo não contam o relatório antigo
        $cruz = $acomp->atividadesDoSetor($outro, '2026-08-01', '2026-08-31');
        $servidor = current(array_filter($cruz['servidores'], fn($x) => $x['id'] === $op));
        $this->assertSame(0, $servidor['total_enviados']);
        $painel = $acomp->painel($outro, '2026-08-05');
        $setorNovo = current(array_filter($painel['setores'], fn($x) => $x['id'] === $outro));
        $linha = current(array_filter($setorNovo['usuarios'], fn($x) => $x['id'] === $op));
        $this->assertSame(0, $linha['sem_atual']);
        $this->assertContains('2026-08-04', $linha['dias_sem_envio']);
    }

    public function test_painel_mostra_pendencia_do_setor_e_servidor_desobrigado(): void {
        $this->prepararSetorComRelatorio();
        $coord = $this->criarCoordenador($this->setor);
        $op = $this->criarUsuario($this->setor);
        $reg = new RegistroAtividadeService($this->db);
        $s = $reg->buscarOuCriarRascunhoSetor($this->setor, 'semana', '2026-08-03', $coord);
        $reg->finalizarSetor((int) $s['id'], $coord);

        $acomp = new AtividadesAcompanhamentoService($this->db);
        $painel = $acomp->painel($this->setor, '2026-08-20');
        $setor = current(array_filter($painel['setores'], fn($x) => $x['id'] === $this->setor));
        $rs = $setor['relatorio_setor'];
        $this->assertSame('semanal', $rs['periodicidade']);
        $this->assertSame($coord, $rs['coordenador']['id']);
        $this->assertSame('2026-08-17', $rs['atual']['data_referencia']);
        $this->assertSame('2026-08-23', $rs['atual']['periodo_fim']);
        $this->assertFalse($rs['atual']['enviado']);
        $this->assertSame('2026-08-10', $rs['anterior']['data_referencia']);
        $this->assertFalse($rs['anterior']['enviado']);
        $datas = array_column($rs['pendencias'], 'data_referencia');
        $this->assertContains('2026-08-10', $datas);
        $this->assertNotContains('2026-08-03', $datas); // já enviado
        $servidor = current(array_filter($setor['usuarios'], fn($u) => $u['id'] === $op));
        $this->assertTrue($servidor['desobrigado']);
        // Desobrigado não conta como "sem envio hoje": nem situação de atraso/prazo nem dias sem envio
        $this->assertSame('desobrigado', $servidor['situacao_hoje']);
        $this->assertSame([], $servidor['dias_sem_envio']);

        $alerta = $acomp->pendenciasDoEscopo($this->setor, '2026-08-20');
        $doSetor = array_values(array_filter($alerta, fn($l) => isset($l['setor_id']) && $l['setor_id'] === $this->setor));
        $this->assertNotEmpty($doSetor);
        $this->assertSame('relatorio_setor', $doSetor[0]['pendencia']['tipo']);

        $hist = $acomp->historicoAusencias($this->setor, '2026-08-03', '2026-08-23');
        $linha = current(array_filter($hist, fn($l) => ($l['tipo'] ?? '') === 'setor'));
        $this->assertSame('2026-08-10', $linha['semanas'][0]['de']);
        $this->assertSame(1, $linha['total_enviado']);
    }

    public function test_painel_ordena_os_setores_como_o_banco(): void {
        $painel = (new AtividadesAcompanhamentoService($this->db))->painel(null, '2026-08-20');
        $esperado = array_map('intval', $this->db->query("SELECT id FROM setores ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN));
        $obtido = array_map(fn($x) => (int) $x['id'], $painel['setores']);
        $this->assertSame(array_values(array_intersect($esperado, $obtido)), $obtido);
    }

    public function test_painel_sem_vigencia_nem_pendencia_deixa_relatorio_setor_nulo(): void {
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $this->criarUsuario($this->setor);
        $painel = (new AtividadesAcompanhamentoService($this->db))->painel($this->setor, '2026-08-20');
        $setor = current(array_filter($painel['setores'], fn($x) => $x['id'] === $this->setor));
        $this->assertNull($setor['relatorio_setor']);
    }

    public function test_coordenador_abona_o_proprio_setor(): void {
        $this->prepararSetorComRelatorio();
        $coord = $this->criarCoordenador($this->setor);
        $outroSetor = $this->outroSetor();
        $prazo = new PrazoAtividadeService($this->db);

        $user = ['id' => $coord, 'tipo' => 'coordenador', 'setor_id' => $this->setor, 'setores_extras' => []];
        $this->assertTrue(AcessoService::podeAbonarRelatorioSetor($user, $this->setor));
        $this->assertFalse(AcessoService::podeAbonarRelatorioSetor($user, $outroSetor));
        $this->assertFalse(AcessoService::podeAbonarRelatorioSetor(['id' => 1, 'tipo' => 'gestor', 'setor_id' => $this->setor], $this->setor));

        $this->assertTrue($prazo->abonarRelatorioSetor($this->setor, 'semana', '2026-08-10', 'zz justificativa', $coord));
        $pend = $prazo->pendenciasRelatorioSetor($this->setor, '2026-08-10', '2026-08-16');
        $this->assertSame('abonado', $pend[0]['status']);
        $this->assertSame('zz justificativa', $pend[0]['justificativa']);

        $removido = $prazo->removerAbonoSetor((int) $pend[0]['abono_id']);
        $this->assertSame($this->setor, (int) $removido['setor_id']);
        $this->assertNull($prazo->removerAbonoSetor((int) $pend[0]['abono_id']));
        $pend = $prazo->pendenciasRelatorioSetor($this->setor, '2026-08-10', '2026-08-16');
        $this->assertNotSame('abonado', $pend[0]['status']);
    }

    public function test_abonar_periodo_ja_enviado_recusa(): void {
        $this->prepararSetorComRelatorio();
        $coord = $this->criarCoordenador($this->setor);
        $reg = new RegistroAtividadeService($this->db);
        $prazo = new PrazoAtividadeService($this->db);
        $s = $reg->buscarOuCriarRascunhoSetor($this->setor, 'semana', '2026-08-03', $coord);
        $reg->finalizarSetor((int) $s['id'], $coord);

        $this->assertFalse($prazo->abonarRelatorioSetor($this->setor, 'semana', '2026-08-03', 'zz justificativa', $coord));
        $this->assertTrue($prazo->relatorioSetorEnviado($this->setor, 'semana', '2026-08-03'));
        // Data que não é o início de um período da vigência, período de outra periodicidade e antes da vigência
        $this->assertFalse($prazo->abonarRelatorioSetor($this->setor, 'semana', '2026-08-12', 'zz justificativa', $coord));
        $this->assertFalse($prazo->abonarRelatorioSetor($this->setor, 'mes', '2026-08-10', 'zz justificativa', $coord));
        $this->assertFalse($prazo->abonarRelatorioSetor($this->setor, 'semana', '2026-07-27', 'zz justificativa', $coord));
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM atividades_abonos_setor WHERE setor_id = {$this->setor}")->fetchColumn());
    }

    public function test_abona_o_primeiro_periodo_parcial_depois_de_trocar_a_periodicidade(): void {
        $coord = $this->criarCoordenador($this->setor);
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $ins = $this->db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim) VALUES (?, ?, ?, ?)");
        $ins->execute([$this->setor, 'semanal', '2026-08-03', '2026-08-30']);
        $ins->execute([$this->setor, 'mensal', '2026-08-31', null]);
        $prazo = new PrazoAtividadeService($this->db);
        $this->assertTrue($prazo->abonarRelatorioSetor($this->setor, 'mes', '2026-08-01', 'zz justificativa', $coord));
        $this->assertTrue($prazo->abonarRelatorioSetor($this->setor, 'mes', '2026-09-01', 'zz justificativa', $coord));
        $this->assertFalse($prazo->abonarRelatorioSetor($this->setor, 'mes', '2026-08-15', 'zz justificativa', $coord));
    }

    public function test_nao_abona_periodo_em_curso_nem_futuro(): void {
        $coord = $this->criarCoordenador($this->setor);
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $this->db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim) VALUES (?, 'semanal', '2026-01-05', NULL)")->execute([$this->setor]);
        $prazo = new PrazoAtividadeService($this->db);
        $semanaAtual = PrazoAtividadeService::inicioDoPeriodo('semanal', date('Y-m-d'));
        $proxima = (new DateTimeImmutable($semanaAtual))->modify('+7 days')->format('Y-m-d');
        $this->assertFalse($prazo->abonarRelatorioSetor($this->setor, 'semana', $semanaAtual, 'zz justificativa', $coord));
        $this->assertFalse($prazo->abonarRelatorioSetor($this->setor, 'semana', $proxima, 'zz justificativa', $coord));
        $passada = (new DateTimeImmutable($semanaAtual))->modify('-7 days')->format('Y-m-d');
        $this->assertTrue($prazo->abonarRelatorioSetor($this->setor, 'semana', $passada, 'zz justificativa', $coord));
    }

    public function test_resolve_a_data_do_periodo_parcial_depois_de_trocar_a_periodicidade(): void {
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $ins = $this->db->prepare("INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim) VALUES (?, ?, ?, ?)");
        $ins->execute([$this->setor, 'semanal', '2026-11-02', '2026-11-29']);
        $ins->execute([$this->setor, 'mensal', '2026-11-30', null]);
        $vig = (new PrazoAtividadeService($this->db))->vigenciasDoSetor($this->setor);

        // Início do calendário do mês parcial (antes da vigência mensal): resolve para o mês recortado
        $mes = PrazoAtividadeService::resolverPeriodoDoSetor($vig, '2026-11-01');
        $this->assertSame(['mes', '2026-11-01', '2026-11-30'], [$mes['periodo'], $mes['data_referencia'], $mes['periodo_fim']]);
        // Dia dentro da vigência semanal: a semana
        $sem = PrazoAtividadeService::resolverPeriodoDoSetor($vig, '2026-11-15');
        $this->assertSame(['semana', '2026-11-09'], [$sem['periodo'], $sem['data_referencia']]);
        // Dia dentro da vigência mensal
        $this->assertSame('2026-12-01', PrazoAtividadeService::resolverPeriodoDoSetor($vig, '2026-12-10')['data_referencia']);
        // Troca no meio da semana: o dia 1º (domingo) é o início do mês parcial, não só um dia da semana de 26/10
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $ins->execute([$this->setor, 'semanal', '2026-10-26', '2026-11-11']);
        $ins->execute([$this->setor, 'mensal', '2026-11-12', null]);
        $vig2 = (new PrazoAtividadeService($this->db))->vigenciasDoSetor($this->setor);
        $this->assertSame(['mes', '2026-11-01'], [PrazoAtividadeService::resolverPeriodoDoSetor($vig2, '2026-11-01')['periodo'], '2026-11-01']);
        $this->assertSame('2026-10-26', PrazoAtividadeService::resolverPeriodoDoSetor($vig2, '2026-10-26')['data_referencia']);
        $this->assertSame(['semana', '2026-11-09'], [PrazoAtividadeService::resolverPeriodoDoSetor($vig2, '2026-11-10')['periodo'], PrazoAtividadeService::resolverPeriodoDoSetor($vig2, '2026-11-10')['data_referencia']]);
        // Troca no meio da semana que começa no dia 1º: semana e mês parcial têm a mesma data_referencia; o tipo desempata
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $ins->execute([$this->setor, 'semanal', '2026-06-01', '2026-06-07']);
        $ins->execute([$this->setor, 'mensal', '2026-06-08', null]);
        $vig3 = (new PrazoAtividadeService($this->db))->vigenciasDoSetor($this->setor);
        $m = PrazoAtividadeService::resolverPeriodoDoSetor($vig3, '2026-06-01', 'mes');
        $this->assertSame(['mes', '2026-06-30'], [$m['periodo'], $m['periodo_fim']]);
        $w = PrazoAtividadeService::resolverPeriodoDoSetor($vig3, '2026-06-01', 'semana');
        $this->assertSame(['semana', '2026-06-07'], [$w['periodo'], $w['periodo_fim']]);
        $this->assertSame('semana', PrazoAtividadeService::resolverPeriodoDoSetor($vig3, '2026-06-01')['periodo']);
        $this->assertSame('semana', PrazoAtividadeService::resolverPeriodoDoSetor($vig3, '2026-06-01', 'xx')['periodo']);
        // Antes de qualquer vigência
        $this->assertNull(PrazoAtividadeService::resolverPeriodoDoSetor($vig, '2026-10-15'));
    }

    public function test_setor_sem_relatorio_do_setor_tem_os_mesmos_numeros_de_antes(): void {
        $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE setor_id = ?")->execute([$this->setor]);
        $op = $this->criarUsuario($this->setor);
        $tipo = $this->criarTipo($this->setor);
        $reg = new RegistroAtividadeService($this->db);
        foreach ([['2026-08-04', 'remoto', 3], ['2026-08-05', 'presencial', 5]] as [$data, $mod, $qtd]) {
            $d = $reg->buscarOuCriarRascunho($op, $this->setor, $data);
            $reg->salvarRascunho((int) $d['id'], $op, [$this->item($tipo, $qtd)], $mod);
            $this->assertTrue($reg->finalizar((int) $d['id'], $op, $mod));
        }
        $acomp = new AtividadesAcompanhamentoService($this->db);

        // Referência: a consulta de antes, pelo setor atual do servidor
        $esperado = [];
        foreach (['remoto', 'presencial'] as $m) {
            $ref = $this->db->prepare("SELECT COUNT(*), COALESCE(SUM((SELECT SUM(i.quantidade) FROM registros_atividades_itens i WHERE i.registro_id = r.id)), 0)
                FROM registros_atividades r JOIN usuarios u ON u.id = r.usuario_id
                WHERE u.setor_id = ? AND u.tipo IN ('gestor','operador') AND r.status = 'enviado'
                  AND r.data_referencia BETWEEN '2026-08-01' AND '2026-08-31' AND COALESCE(r.modalidade, 'remoto') = ?");
            $ref->execute([$this->setor, $m]);
            $esperado[$m] = array_map('intval', $ref->fetch(PDO::FETCH_NUM));
        }
        $rel = $acomp->relatorio($this->setor, null, '2026-08-01', '2026-08-31', '', 1, 1000);
        foreach (['remoto', 'presencial'] as $m) {
            $this->assertSame($esperado[$m][0], $rel['por_modalidade'][$m]['relatorios'], $m);
            $this->assertSame($esperado[$m][1], $rel['por_modalidade'][$m]['tarefas'], $m);
        }
        $this->assertSame(0, $rel['por_modalidade']['setor']['relatorios']);
        $this->assertSame($esperado['remoto'][0] + $esperado['presencial'][0], $rel['total']);
        $this->assertSame($rel['total'], $rel['metricas']['total_registros']);

        $cruz = $acomp->atividadesDoSetor($this->setor, '2026-08-01', '2026-08-31');
        $this->assertSame($esperado['remoto'][1] + $esperado['presencial'][1], $cruz['total_tarefas']);
        $this->assertFalse($cruz['relatorio_setor_na_serie']);

        $painel = $acomp->painel($this->setor, '2026-08-05');
        $setor = current(array_filter($painel['setores'], fn($x) => $x['id'] === $this->setor));
        $linha = current(array_filter($setor['usuarios'], fn($x) => $x['id'] === $op));
        $this->assertSame(2, $linha['sem_atual']);
        $this->assertSame(2, $linha['mes_atual']);
        $this->assertFalse($linha['desobrigado']);
        $this->assertNull($setor['relatorio_setor']);
    }
}
