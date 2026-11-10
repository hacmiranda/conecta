<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/pesquisa-convite-service.php';
require_once __DIR__ . '/../../services/pesquisa-avaliacao-service.php';
require_once __DIR__ . '/../../services/pesquisa-processador-service.php';
require_once __DIR__ . '/../../services/pesquisa-painel-service.php';
require_once __DIR__ . '/../../services/pesquisa-satisfacao-service.php';

/**
 * Fluxo completo do módulo Satisfação com banco: convite (quarentena, idempotência), avaliação pelo token,
 * expiração e reenvio, consultas do painel com escopo de setor e o gatilho ao encerrar uma demanda.
 * Banco local em transação desfeita; recusa produção.
 */
final class PesquisaModuloIntegracaoTest extends TestCase {

    private PDO $db;
    private int $setorA;
    private int $setorB;
    private int $servidor;
    /** @var string[] e-mails passados ao envio de teste */
    private array $enviados = [];

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
        $setores = $this->db->query("SELECT id FROM setores WHERE ativo = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if (count($setores) < 2) {
            $this->markTestSkipped('Precisa de dois setores ativos.');
        }
        [$this->setorA, $this->setorB] = array_map('intval', $setores);
        // O teste não depende de como o banco local está configurado: pesquisa ligada nos dois setores
        // (sem linha = ligado) e os dois no Painel de Setores. Tudo desfeito no rollback.
        $this->db->prepare("DELETE FROM setor_notificacoes_email WHERE codigo = 'sol_pesquisa_satisfacao' AND setor_id IN (?, ?)")
                 ->execute([$this->setorA, $this->setorB]);
        $this->db->prepare("UPDATE setores SET pesquisa_painel = 1 WHERE id IN (?, ?)")->execute([$this->setorA, $this->setorB]);
        NotificacaoEmailService::limparCache();
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo, trocar_senha, atendimento_externo)
                            VALUES ('zz Servidor Pesquisa', ?, 'x', 'operador', ?, 1, 0, 1)")
                 ->execute(['zz.pesq.' . bin2hex(random_bytes(4)) . '@teste.local', $this->setorA]);
        $this->servidor = (int) $this->db->lastInsertId();
        $this->enviados = [];
    }

    protected function tearDown(): void {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        NotificacaoEmailService::limparCache();
    }

    private function servico(): PesquisaConviteService {
        return new PesquisaConviteService($this->db, function (string $nome, string $email, string $link, string $protocolo): void {
            $this->enviados[] = [$nome, $email, $link, $protocolo];
        });
    }

    private function email(): string {
        return 'zz.cidadao.' . bin2hex(random_bytes(4)) . '@exemplo.com';
    }

    private function tokenDe(int $conviteId): string {
        return (string) $this->db->query("SELECT token FROM pesquisa_convites WHERE id = $conviteId")->fetchColumn();
    }

    public function test_convite_enviado_grava_token_setor_e_manda_o_link(): void {
        $r = $this->servico()->enviar($this->servidor, 'Maria', $this->email(), ['origem' => 'manual']);
        $this->assertSame('enviado', $r['status']);
        $c = $this->db->query("SELECT * FROM pesquisa_convites WHERE id = " . (int) $r['convite_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($this->servidor, (int) $c['usuario_id']);
        $this->assertSame($this->setorA, (int) $c['setor_id'], 'grava o setor do servidor no envio');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $c['token']);
        $this->assertCount(1, $this->enviados);
        $this->assertStringContainsString('avaliar.html#t=' . $c['token'], $this->enviados[0][2]);
    }

    public function test_quarentena_barra_o_segundo_convite_ao_mesmo_email(): void {
        $email = $this->email();
        $this->assertSame('enviado', $this->servico()->enviar($this->servidor, 'Ana', $email)['status']);
        $r = $this->servico()->enviar($this->servidor, 'Ana', $email);
        $this->assertSame('quarentena', $r['status']);
        $this->assertGreaterThan(time(), $r['proximo_envio']);
        $this->assertCount(1, $this->enviados);
    }

    public function test_chave_de_integracao_nao_duplica(): void {
        $origem = ['origem' => 'demanda', 'chave_integracao' => 'demanda:zz' . bin2hex(random_bytes(3))];
        $a = $this->servico()->enviar($this->servidor, 'Ana', $this->email(), $origem);
        $b = $this->servico()->enviar($this->servidor, 'Ana', $this->email(), $origem);
        $this->assertSame('enviado', $a['status']);
        $this->assertSame('ja_existe', $b['status']);
        $this->assertSame($a['convite_id'], $b['convite_id']);
    }

    public function test_falha_no_email_desfaz_o_convite_quando_pedido(): void {
        $servico = new PesquisaConviteService($this->db, function () { throw new RuntimeException('smtp fora'); });
        $r = $servico->enviar($this->servidor, 'Ana', $this->email(), ['chave_integracao' => 'demanda:zzf' . bin2hex(random_bytes(3))], true);
        $this->assertSame('falha_email', $r['status']);
        $this->assertNull($r['convite_id']);
        $r2 = $servico->enviar($this->servidor, 'Ana', $this->email(), [], false);
        $this->assertSame('falha_email', $r2['status']);
        $this->assertNotNull($r2['convite_id'], 'no envio manual o convite fica gravado');
    }

    public function test_avaliacao_pelo_token_uso_unico_e_triagem_automatica(): void {
        $this->db->exec("INSERT IGNORE INTO pesquisa_categorias (nome, emoji, ordem) VALUES ('zz Rapidez', '⚡', 99)");
        $cat = (int) $this->db->query("SELECT id FROM pesquisa_categorias WHERE nome = 'zz Rapidez'")->fetchColumn();
        $id = $this->servico()->enviar($this->servidor, 'Joana', $this->email())['convite_id'];
        $token = $this->tokenDe($id);

        $this->assertSame('ok', PesquisaAvaliacaoService::estado($this->db, $token)['situacao']);
        $alertas = [];
        $r = PesquisaAvaliacaoService::registrar($this->db, $token, 2, 'Demorou <script>x</script>', [$cat, 999999], function () use (&$alertas) { $alertas[] = 1; });
        $this->assertSame('ok', $r['codigo']);
        $this->assertSame('critico', $r['tipo']);
        $this->assertCount(1, $alertas, 'nota 1 ou 2 dispara o alerta');

        $a = $this->db->query("SELECT * FROM pesquisa_avaliacoes WHERE convite_id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pendente', $a['status'], 'nota baixa fica pendente para a triagem');
        $this->assertStringNotContainsString('<script>', (string) $a['comentario']);
        $n = $this->db->query("SELECT COUNT(*) FROM pesquisa_avaliacao_categorias WHERE avaliacao_id = " . (int) $a['id'])->fetchColumn();
        $this->assertSame(1, (int) $n, 'só a categoria que existe');

        $this->assertSame('ja_avaliado', PesquisaAvaliacaoService::registrar($this->db, $token, 5, '', [])['codigo']);
        $this->assertSame('ja_avaliado', PesquisaAvaliacaoService::estado($this->db, $token)['situacao']);
    }

    public function test_nota_3_vai_para_a_triagem_sem_alerta(): void {
        $id = $this->servico()->enviar($this->servidor, 'Regular', $this->email())['convite_id'];
        $alertas = 0;
        $r = PesquisaAvaliacaoService::registrar($this->db, $this->tokenDe($id), 3, 'demorou', [], function () use (&$alertas) { $alertas++; });
        $this->assertSame('critico', $r['tipo']);
        $this->assertSame('pendente', $this->db->query("SELECT status FROM pesquisa_avaliacoes WHERE convite_id = $id")->fetchColumn(),
            'a página diz ao cidadão que a nota 3 será analisada');
        $this->assertSame(0, $alertas, 'o alerta por e-mail é só para 1 e 2');
    }

    public function test_nota_alta_entra_resolvida_e_devolve_o_link_do_google(): void {
        PesquisaConfig::salvar($this->db, ['google_review_url' => 'https://g.page/r/zz']);
        $id = $this->servico()->enviar($this->servidor, 'Joana', $this->email())['convite_id'];
        $r = PesquisaAvaliacaoService::registrar($this->db, $this->tokenDe($id), 5, '', []);
        $this->assertSame('positivo', $r['tipo']);
        $this->assertSame('https://g.page/r/zz', $r['google_url']);
        $status = $this->db->query("SELECT status FROM pesquisa_avaliacoes WHERE convite_id = $id")->fetchColumn();
        $this->assertSame('resolvido', $status);
    }

    public function test_token_invalido_e_nota_invalida(): void {
        $this->assertSame('invalido', PesquisaAvaliacaoService::registrar($this->db, str_repeat('a', 64), 5, '', [])['codigo']);
        $this->assertSame('invalido', PesquisaAvaliacaoService::estado($this->db, 'curto')['situacao']);
        $id = $this->servico()->enviar($this->servidor, 'Joana', $this->email())['convite_id'];
        $this->assertSame('nota_invalida', PesquisaAvaliacaoService::registrar($this->db, $this->tokenDe($id), 6, '', [])['codigo']);
    }

    public function test_expiracao_e_reenvio_automatico(): void {
        $velho = $this->servico()->enviar($this->servidor, 'Velho', $this->email())['convite_id'];
        $meio = $this->servico()->enviar($this->servidor, 'Meio', $this->email())['convite_id'];
        $this->db->exec("UPDATE pesquisa_convites SET enviado_em = DATE_SUB(NOW(), INTERVAL 20 DAY) WHERE id = $velho");
        $this->db->exec("UPDATE pesquisa_convites SET enviado_em = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = $meio");

        $reenviados = [];
        $r = PesquisaProcessadorService::processar($this->db, function (string $nome) use (&$reenviados) { $reenviados[] = $nome; });
        $this->assertGreaterThanOrEqual(1, $r['expirados']);
        $this->assertSame(1, (int) $this->db->query("SELECT expirado FROM pesquisa_convites WHERE id = $velho")->fetchColumn());
        $this->assertContains('Meio', $reenviados, '3 dias sem resposta (> 48 h): reenvia uma vez');
        $this->assertNotContains('Velho', $reenviados, 'o expirado não é reenviado');

        $segunda = [];
        PesquisaProcessadorService::processar($this->db, function (string $nome) use (&$segunda) { $segunda[] = $nome; });
        $this->assertNotContains('Meio', $segunda, 'um único reenvio por convite');
        $this->assertSame('expirado', PesquisaAvaliacaoService::estado($this->db, $this->tokenDe($velho))['situacao']);
    }

    public function test_reenvio_que_falha_libera_a_reserva_e_para_o_lote(): void {
        $id = $this->servico()->enviar($this->servidor, 'Falha', $this->email())['convite_id'];
        $this->db->exec("UPDATE pesquisa_convites SET enviado_em = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = $id");
        $n = PesquisaProcessadorService::reenviar($this->db, 48, function () { throw new RuntimeException('fora do ar'); });
        $this->assertSame(0, $n);
        $this->assertNull($this->db->query("SELECT reenviado_automatico_em FROM pesquisa_convites WHERE id = $id")->fetchColumn(), 'reserva desfeita');
    }

    public function test_consultas_do_painel_respeitam_o_escopo_de_setor(): void {
        $a = $this->servico()->enviar($this->servidor, 'A', $this->email(), ['setor_id' => $this->setorA])['convite_id'];
        $b = $this->servico()->enviar($this->servidor, 'B', $this->email(), ['setor_id' => $this->setorB])['convite_id'];
        PesquisaAvaliacaoService::registrar($this->db, $this->tokenDe($a), 4, 'ótimo zz', []);
        PesquisaAvaliacaoService::registrar($this->db, $this->tokenDe($b), 1, 'ruim zz', [], function () {});

        $f = PesquisaPainelService::lerFiltros(['busca' => 'zz']);
        $so_a = PesquisaPainelService::listarAvaliacoes($this->db, [$this->setorA], $f, 1);
        $so_b = PesquisaPainelService::listarAvaliacoes($this->db, [$this->setorB], $f, 1);
        $todas = PesquisaPainelService::listarAvaliacoes($this->db, null, $f, 1);
        $nenhuma = PesquisaPainelService::listarAvaliacoes($this->db, [], $f, 1);
        $this->assertSame(['ótimo zz'], array_column($so_a['itens'], 'comentario'));
        $this->assertSame(['ruim zz'], array_column($so_b['itens'], 'comentario'));
        $this->assertSame(2, $todas['total']);
        $this->assertSame(0, $nenhuma['total'], 'sem setor não vê nada');

        // As demais consultas rodam sem erro de SQL, com e sem escopo
        foreach ([null, [$this->setorA], []] as $escopo) {
            PesquisaPainelService::indicadores($this->db, $escopo);
            PesquisaPainelService::evolucao($this->db, $escopo, $f, 30);
            PesquisaPainelService::categorias($this->db, $escopo);
            PesquisaPainelService::listarConvites($this->db, $escopo, PesquisaPainelService::lerFiltros([]), 1);
            PesquisaPainelService::indicadoresConvites($this->db, $escopo, PesquisaPainelService::lerFiltros(['situacao' => 'avaliado']));
            PesquisaPainelService::servidoresComConvites($this->db, $escopo);
            PesquisaPainelService::painelSetores($this->db, $escopo);
        }
        $painel = PesquisaPainelService::painelSetores($this->db, [$this->setorA]);
        $meu = null;
        foreach ($painel['setores'] as $s) {
            foreach ($s['servidores'] as $sv) {
                if ($sv['id'] === $this->servidor) $meu = $sv;
            }
        }
        $this->assertNotNull($meu, 'o servidor aparece no painel do setor dele');
        // Só o convite enviado pelo setor A (o do setor B aparece no painel do B)
        $this->assertSame(1, $meu['total_convites']);
        $this->assertSame(1, $meu['total_avaliados']);

        $sat = PesquisaPainelService::satisfacaoDoServidor($this->db, $this->servidor, date('Y-m-01'), date('Y-m-t'));
        $this->assertSame(2, $sat['total_convites']);
        $this->assertSame(2.5, $sat['media_nota']);
        $meuPainel = PesquisaPainelService::meuPainel($this->db, $this->servidor, '', 1);
        $this->assertSame(2, $meuPainel['indicadores']['total']);
        $this->assertSame(2, $meuPainel['historico']['total']);
    }

    public function test_triagem_muda_status_e_anotacao(): void {
        $id = $this->servico()->enviar($this->servidor, 'T', $this->email())['convite_id'];
        PesquisaAvaliacaoService::registrar($this->db, $this->tokenDe($id), 1, 'x', [], function () {});
        $avaliacao = (int) $this->db->query("SELECT id FROM pesquisa_avaliacoes WHERE convite_id = $id")->fetchColumn();
        $this->assertSame($this->setorA, PesquisaPainelService::setorDaAvaliacao($this->db, $avaliacao)['setor_id']);
        PesquisaPainelService::triar($this->db, $avaliacao, 'em_tratativa', 'ligamos para a pessoa');
        $a = $this->db->query("SELECT status, anotacao FROM pesquisa_avaliacoes WHERE id = $avaliacao")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('em_tratativa', $a['status']);
        $this->assertSame('ligamos para a pessoa', $a['anotacao']);
        $this->assertFalse(PesquisaPainelService::setorDaAvaliacao($this->db, 999999999));
    }

    public function test_demanda_encerrada_gera_o_convite_para_o_responsavel(): void {
        $email = $this->email();
        $this->db->prepare("INSERT INTO tickets (titulo, descricao, usuario_id, email_solicitante, status, prioridade, setor_id, agente_id, fechado_por)
                            VALUES ('zz demanda pesquisa', '<p>zz</p>', ?, ?, 'encerrado', 'normal', ?, ?, ?)")
                 ->execute([$this->servidor, $email, $this->setorA, $this->servidor, $this->servidor]);
        $ticket = (int) $this->db->lastInsertId();

        $this->assertTrue(PesquisaSatisfacaoService::registrar($this->db, $ticket));
        $this->assertFalse(PesquisaSatisfacaoService::registrar($this->db, $ticket), 'uma pesquisa por demanda');

        // Só este pedido: outros pendentes do banco ficam como estão (a transação é desfeita no fim)
        $this->db->exec("UPDATE pesquisa_satisfacao SET status = 'cancelada' WHERE ticket_id <> $ticket AND status = 'pendente'");
        $resumo = PesquisaSatisfacaoService::processarFila($this->db, 20, function (int $usuarioId, string $nome, string $destino, array $origem) {
            return (new PesquisaConviteService($this->db, function (string $n, string $e, string $l, string $p): void {
                $this->enviados[] = [$n, $e, $l, $p];
            }))->enviar($usuarioId, $nome, $destino, $origem, true);
        });
        $this->assertSame(1, $resumo['enviados']);
        $this->assertSame('enviado', $this->db->query("SELECT status FROM pesquisa_satisfacao WHERE ticket_id = $ticket")->fetchColumn());
        $c = $this->db->query("SELECT * FROM pesquisa_convites WHERE ticket_id = $ticket")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('demanda', $c['origem']);
        $this->assertSame($this->servidor, (int) $c['usuario_id']);
        $this->assertSame($this->setorA, (int) $c['setor_id']);
        $this->assertSame('demanda:' . $ticket, $c['chave_integracao']);
        $this->assertSame($email, $this->enviados[0][1]);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM ticket_historico WHERE ticket_id = $ticket AND acao = 'pesquisa_satisfacao'")->fetchColumn());
    }

    public function test_convite_real_entra_na_fila_de_email(): void {
        $email = $this->email();
        $r = (new PesquisaConviteService($this->db))->enviar($this->servidor, 'Real', $email, ['origem' => 'manual']);
        $this->assertSame('enviado', $r['status']);
        $fila = $this->db->prepare("SELECT tipo, assunto, corpo, status FROM email_fila WHERE destinatario = ? ORDER BY id DESC LIMIT 1");
        $fila->execute([$email]);
        $linha = $fila->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($linha, 'o e-mail do convite foi para a fila de envio');
        $this->assertSame('pesquisa_convite', $linha['tipo']);
        $this->assertSame('pendente', $linha['status']);
        $this->assertStringContainsString('avaliar.html#t=', $linha['corpo']);
    }
    /** Demanda encerrada (pela tabela, sem passar pela tela) com o servidor de teste como responsável. */
    private function demandaEncerrada(string $email): int {
        $this->db->prepare("INSERT INTO tickets (titulo, descricao, usuario_id, email_solicitante, status, prioridade, setor_id, agente_id, fechado_por)
                            VALUES ('zz demanda pesquisa', '<p>zz</p>', ?, ?, 'encerrado', 'normal', ?, ?, ?)")
                 ->execute([$this->servidor, $email, $this->setorA, $this->servidor, $this->servidor]);
        return (int) $this->db->lastInsertId();
    }

    public function test_pedido_cancelado_por_reabertura_volta_a_fila_ao_encerrar_de_novo(): void {
        $ticket = $this->demandaEncerrada($this->email());
        $this->assertTrue(PesquisaSatisfacaoService::registrar($this->db, $ticket));

        // Reaberta antes de o cron rodar: o pedido é cancelado
        $this->db->exec("UPDATE tickets SET status = 'reaberto' WHERE id = $ticket");
        $this->db->exec("UPDATE pesquisa_satisfacao SET status = 'cancelada' WHERE ticket_id <> $ticket AND status = 'pendente'");
        PesquisaSatisfacaoService::processarFila($this->db, 20, function () {
            $this->fail('demanda reaberta não gera convite');
        });
        $this->assertSame('cancelada', $this->db->query("SELECT status FROM pesquisa_satisfacao WHERE ticket_id = $ticket")->fetchColumn());

        // Encerrada de novo: o mesmo pedido volta para a fila
        $this->db->exec("UPDATE tickets SET status = 'encerrado' WHERE id = $ticket");
        $this->assertTrue(PesquisaSatisfacaoService::registrar($this->db, $ticket));
        $p = $this->db->query("SELECT status, tentativas, processado_em FROM pesquisa_satisfacao WHERE ticket_id = $ticket")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pendente', $p['status']);
        $this->assertSame(0, (int) $p['tentativas']);
        $this->assertNull($p['processado_em']);
        $this->assertFalse(PesquisaSatisfacaoService::registrar($this->db, $ticket), 'pendente não é registrado duas vezes');
    }

    public function test_reenvio_automatico_pula_demanda_reaberta_ou_com_pesquisa_desligada(): void {
        $email = $this->email();
        $ticket = $this->demandaEncerrada($email);
        $id = $this->servico()->enviar($this->servidor, 'Demanda', $email,
            ['origem' => 'demanda', 'ticket_id' => $ticket, 'setor_id' => $this->setorA, 'chave_integracao' => 'demanda:' . $ticket])['convite_id'];
        $this->db->exec("UPDATE pesquisa_convites SET enviado_em = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = $id");
        $nomes = function () {
            $lista = [];
            PesquisaProcessadorService::reenviar($this->db, 48, function (string $nome) use (&$lista) { $lista[] = $nome; });
            return $lista;
        };

        $this->db->exec("UPDATE tickets SET status = 'reaberto' WHERE id = $ticket");
        $this->assertNotContains('Demanda', $nomes(), 'demanda reaberta: sem reenvio');

        $this->db->exec("UPDATE tickets SET status = 'encerrado' WHERE id = $ticket");
        $this->db->prepare("INSERT INTO setor_notificacoes_email (setor_id, codigo, ativo) VALUES (?, 'sol_pesquisa_satisfacao', 0)")
                 ->execute([$this->setorA]);
        $this->assertNotContains('Demanda', $nomes(), 'pesquisa desligada no setor: sem reenvio');

        $this->db->prepare("DELETE FROM setor_notificacoes_email WHERE setor_id = ? AND codigo = 'sol_pesquisa_satisfacao'")
                 ->execute([$this->setorA]);
        $this->assertContains('Demanda', $nomes(), 'encerrada e ligada: reenvia');
    }

    public function test_painel_de_setores_conta_so_os_convites_do_setor(): void {
        // Servidor lotado no setor A que também enviou um convite pelo setor B
        $this->servico()->enviar($this->servidor, 'Pelo A', $this->email(), ['setor_id' => $this->setorA]);
        $this->servico()->enviar($this->servidor, 'Pelo B', $this->email(), ['setor_id' => $this->setorB]);
        $painel = PesquisaPainelService::painelSetores($this->db, [$this->setorA, $this->setorB]);
        $doServidor = [];
        foreach ($painel['setores'] as $s) {
            foreach ($s['servidores'] as $sv) {
                if ($sv['id'] === $this->servidor) {
                    $doServidor[$s['id']] = $sv['total_convites'];
                }
            }
        }
        ksort($doServidor);
        $esperado = [$this->setorA => 1, $this->setorB => 1];
        ksort($esperado);
        $this->assertSame($esperado, $doServidor);
    }
}
