<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/emails-enviados-service.php';

/**
 * Dashboard → E-mails Enviados com banco: o que conta como resposta enviada, o setor da demanda no momento
 * da resposta e as janelas ontem / 7 dias / 30 dias / período. Banco local em transação desfeita.
 */
final class EmailsEnviadosTest extends TestCase {

    private PDO $db;
    private int $setorA;
    private int $setorB;
    private int $operador;
    private int $gestor;
    private int $cliente;
    private DateTimeImmutable $hoje;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
        $this->hoje = new DateTimeImmutable('today');

        $setores = $this->db->query("SELECT id FROM setores WHERE ativo = 1 AND setor_coordenacao = 0 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if (count($setores) < 2) {
            $this->markTestSkipped('Precisa de dois setores ativos.');
        }
        [$this->setorA, $this->setorB] = array_map('intval', $setores);
        $this->operador = $this->usuario('operador', $this->setorA);
        $this->gestor = $this->usuario('gestor', $this->setorA);
        $this->cliente = $this->usuario('cliente', null);
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function usuario(string $tipo, ?int $setor): int {
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha) VALUES (?, ?, 'x', ?, ?, 1, 0)")
                 ->execute(['zz Emails ' . $tipo, 'zz.emails.' . bin2hex(random_bytes(4)) . '@teste.local', $tipo, $setor]);
        return (int) $this->db->lastInsertId();
    }

    private function dia(int $diasAtras, string $hora = '12:00:00'): string {
        return $this->hoje->modify("-$diasAtras days")->format('Y-m-d') . ' ' . $hora;
    }

    /** Demanda com as passagens por setor: [[setor, desde (dias atrás), até (dias atrás) ou null]] */
    private function demanda(array $passagens, bool $naLixeira = false): int {
        $atual = end($passagens)[0];
        $this->db->prepare("INSERT INTO tickets (titulo, descricao, usuario_id, status, prioridade, setor_id, criado_em, excluido_em)
                            VALUES ('zz demanda', 'zz', ?, 'em_atendimento', 'normal', ?, ?, ?)")
                 ->execute([$this->cliente, $atual, $this->dia(40), $naLixeira ? $this->dia(0) : null]);
        $id = (int) $this->db->lastInsertId();
        foreach ($passagens as [$setor, $desde, $ate]) {
            $this->db->prepare("INSERT INTO ticket_setor_log (ticket_id, setor_id, entrou_em, saiu_em) VALUES (?, ?, ?, ?)")
                     ->execute([$id, $setor, $this->dia($desde), $ate === null ? null : $this->dia($ate)]);
        }
        return $id;
    }

    private function comentario(int $ticket, int $autor, int $diasAtras, int $visivel = 1, int $interno = 0): void {
        $this->db->prepare("INSERT INTO comentarios (ticket_id, usuario_id, comentario, criado_em, interno, visivel_solicitante) VALUES (?, ?, 'zz', ?, ?, ?)")
                 ->execute([$ticket, $autor, $this->dia($diasAtras, '13:00:00'), $interno, $visivel]);
    }

    private static function porId(array $lista, string $chave, int $id): array {
        foreach ($lista as $l) {
            if ($l[$chave] === $id) {
                return $l;
            }
        }
        self::fail("Linha $chave=$id não encontrada");
    }

    private function cenario(): void {
        // Demanda que passou do setor A para o B há 5 dias
        $transferida = $this->demanda([[$this->setorA, 40, 5], [$this->setorB, 5, null]]);
        $this->comentario($transferida, $this->operador, 10);   // no A (antes da transferência)
        $this->comentario($transferida, $this->operador, 1);    // no B, ontem
        // Demanda que sempre esteve no A
        $doA = $this->demanda([[$this->setorA, 40, null]]);
        $this->comentario($doA, $this->gestor, 0);              // hoje
        $this->comentario($doA, $this->gestor, 2, 0);           // não foi por e-mail: não conta
        $this->comentario($doA, $this->gestor, 2, 1, 1);        // nota interna: não conta
        $this->comentario($doA, $this->cliente, 2);             // do solicitante: não conta
        $this->comentario($doA, $this->operador, 40);           // fora das janelas de 30 dias
        // Demanda na lixeira: não conta
        $lixeira = $this->demanda([[$this->setorA, 40, null]], true);
        $this->comentario($lixeira, $this->gestor, 0);
    }

    public function test_conta_por_setor_no_momento_da_resposta_e_por_funcionario(): void {
        $this->cenario();
        [$inicio, $fim] = [$this->hoje->modify('-10 days')->format('Y-m-d'), $this->hoje->format('Y-m-d')];
        $r = EmailsEnviadosService::resumo($this->db, [$this->setorA, $this->setorB], null, $inicio, $fim, $this->hoje);

        $a = self::porId($r['setores'], 'setor_id', $this->setorA);
        $b = self::porId($r['setores'], 'setor_id', $this->setorB);
        $this->assertSame(['ontem' => 0, 'semana' => 1, 'mes' => 2, 'periodo' => 2],
            array_intersect_key($a, array_flip(['ontem', 'semana', 'mes', 'periodo'])));
        $this->assertSame(['ontem' => 1, 'semana' => 1, 'mes' => 1, 'periodo' => 1],
            array_intersect_key($b, array_flip(['ontem', 'semana', 'mes', 'periodo'])));

        $op = self::porId($r['funcionarios'], 'usuario_id', $this->operador);
        $ge = self::porId($r['funcionarios'], 'usuario_id', $this->gestor);
        $this->assertSame([1, 1, 2, 2], [$op['ontem'], $op['semana'], $op['mes'], $op['periodo']]);
        $this->assertSame([0, 1, 1, 1], [$ge['ontem'], $ge['semana'], $ge['mes'], $ge['periodo']]);
        $this->assertNotContains($this->cliente, array_column($r['funcionarios'], 'usuario_id'));
    }

    public function test_escopo_de_um_setor_conta_so_as_respostas_dadas_nele(): void {
        $this->cenario();
        $r = EmailsEnviadosService::resumo($this->db, [$this->setorB], null, $this->hoje->format('Y-m-01'), $this->hoje->format('Y-m-d'), $this->hoje);

        $this->assertSame([$this->setorB], array_column($r['setores'], 'setor_id'));
        $op = self::porId($r['funcionarios'], 'usuario_id', $this->operador); // lotado no A, respondeu no B
        $this->assertSame([1, 1, 1], [$op['ontem'], $op['semana'], $op['mes']]);
        $this->assertNotContains($this->gestor, array_column($r['funcionarios'], 'usuario_id'));
    }

    public function test_operador_ve_so_os_proprios_numeros(): void {
        $this->cenario();
        $r = EmailsEnviadosService::resumo($this->db, null, $this->operador, $this->hoje->format('Y-m-01'), $this->hoje->format('Y-m-d'), $this->hoje);

        $this->assertSame([], $r['setores']);
        $this->assertSame([$this->operador], array_column($r['funcionarios'], 'usuario_id'));
        $this->assertSame(2, $r['funcionarios'][0]['mes']);
    }

    public function test_periodo_escolhido_e_independente_das_janelas_fixas(): void {
        $this->cenario();
        $quarenta = $this->hoje->modify('-40 days')->format('Y-m-d');
        $r = EmailsEnviadosService::resumo($this->db, [$this->setorA], null, $quarenta, $quarenta, $this->hoje);

        $op = self::porId($r['funcionarios'], 'usuario_id', $this->operador);
        $this->assertSame(1, $op['mes']);     // a resposta de 10 dias atrás
        $this->assertSame(1, $op['periodo']); // só a de 40 dias atrás
    }
}
