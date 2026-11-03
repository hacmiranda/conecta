<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/crypto-service.php';

/**
 * E-mail recebido (EmailService), sem servidor IMAP: os métodos de criação e de comentário recebem
 * o texto e os anexos já lidos. Roda no banco local dentro de uma transação desfeita no fim de cada
 * teste (os métodos usam a transação de quem chama); arquivos gravados são apagados. Recusa produção.
 */
final class EmailRecebidoTest extends TestCase {

    private PDO $db;
    private EmailService $servico;
    private int $setorA;
    private int $setorB;
    private int $caixaA;
    private int $caixaB;
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

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
        $caixa = $this->db->prepare("INSERT INTO setor_emails (setor_id, email, imap_host, imap_user, imap_pass_cifrada) VALUES (?, ?, 'imap.invalid', 'zz', ?)");
        $senha = CryptoService::encrypt('zz-senha-falsa');
        $caixa->execute([$this->setorA, 'zz.caixa.a@teste.local', $senha]);
        $this->caixaA = (int) $this->db->lastInsertId();
        $caixa->execute([$this->setorB, 'zz.caixa.b@teste.local', $senha]);
        $this->caixaB = (int) $this->db->lastInsertId();

        $this->servico = new EmailService($this->db);
    }

    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
        UploadService::desfazerLote();
    }

    /** Simula a leitura da caixa de um setor. */
    private function naCaixa(int $setor, int $caixa): void {
        foreach (['setorAtualId' => $setor, 'caixaEmailAtualId' => $caixa] as $prop => $valor) {
            $p = new ReflectionProperty(EmailService::class, $prop);
            $p->setValue($this->servico, $valor);
        }
    }

    private function chamar(string $metodo, ...$args) {
        $m = new ReflectionMethod(EmailService::class, $metodo);
        return $m->invoke($this->servico, ...$args);
    }

    private function semAnexos(): array {
        return ['validos' => [], 'rejeitados' => []];
    }

    private function valor(string $sql, array $params = []) {
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s->fetchColumn();
    }

    public function test_cria_a_demanda_completa_com_anexo(): void {
        $this->naCaixa($this->setorA, $this->caixaA);
        $anexos = ['validos' => [['nome' => 'comprovante.pdf', 'conteudo' => self::PDF, 'tamanho' => strlen(self::PDF), 'tipo' => 'application/pdf']], 'rejeitados' => []];
        $id = $this->chamar('criarTicketPorEmail', 'zz pedido', 'zz corpo', 'zz.solicitante@exemplo.com', $anexos);

        $this->assertNotEmpty($this->valor("SELECT protocolo FROM tickets WHERE id = ? AND setor_id = ?", [$id, $this->setorA]));
        $this->assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM ticket_sla WHERE ticket_id = ?", [$id]), 'SLA criado junto');
        $this->assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM ticket_setor_log WHERE ticket_id = ?", [$id]));
        $arquivo = $this->valor("SELECT nome_arquivo FROM anexos WHERE ticket_id = ?", [$id]);
        $this->assertNotEmpty($arquivo);
        $this->assertFileExists(UploadService::pasta() . $arquivo);
        if (NotificacaoEmailService::ativa($this->db, $this->setorA, 'sol_demanda_criada')) {
            $this->assertGreaterThan(0, (int) $this->valor("SELECT COUNT(*) FROM email_fila WHERE ticket_id = ?", [$id]), 'confirmação enfileirada depois de criar');
        }
    }

    public function test_mesmo_email_em_duas_caixas_abre_uma_demanda_em_cada_setor(): void {
        $mid = '<zz-duas-caixas-' . bin2hex(random_bytes(4)) . '@exemplo.com>';

        $this->naCaixa($this->setorA, $this->caixaA);
        $a = $this->chamar('criarTicketPorEmail', 'zz assunto igual', 'zz corpo igual', 'zz.duas@exemplo.com', $this->semAnexos());
        $this->chamar('registrarEmailProcessado', $mid, 'zz.duas@exemplo.com', 'zz', $a, 'novo_ticket');
        $this->assertTrue($this->chamar('emailJaProcessado', $mid));

        // Antes: o Message-ID era único no sistema e a segunda caixa achava que já tinha processado
        $this->naCaixa($this->setorB, $this->caixaB);
        $this->assertFalse($this->chamar('emailJaProcessado', $mid), 'a outra caixa ainda não processou');
        $b = $this->chamar('criarTicketPorEmail', 'zz assunto igual', 'zz corpo igual', 'zz.duas@exemplo.com', $this->semAnexos());
        $this->chamar('registrarEmailProcessado', $mid, 'zz.duas@exemplo.com', 'zz', $b, 'novo_ticket');

        $this->assertNotSame($a, $b, 'a checagem de duplicidade considera o setor');
        $this->assertSame($this->setorB, (int) $this->valor("SELECT setor_id FROM tickets WHERE id = ?", [$b]));
        $this->assertSame(2, (int) $this->valor("SELECT COUNT(*) FROM emails_processados WHERE message_id = ?", [$mid]));

        // Na mesma caixa, o mesmo conteúdo em 10 minutos continua sendo a mesma demanda
        $this->assertSame($b, $this->chamar('criarTicketPorEmail', 'zz assunto igual', 'zz corpo igual', 'zz.duas@exemplo.com', $this->semAnexos()));
    }

    public function test_resposta_a_demanda_encerrada_reabre_e_comenta_numa_operacao_so(): void {
        $this->naCaixa($this->setorA, $this->caixaA);
        $id = $this->chamar('criarTicketPorEmail', 'zz reabrir', 'zz corpo', 'zz.reabre@exemplo.com', $this->semAnexos());
        $autor = (int) $this->valor("SELECT usuario_id FROM tickets WHERE id = ?", [$id]);
        DemandaService::mudarStatus($this->db, $id, 'encerrado', $autor);

        $this->chamar('adicionarComentario', $id, 'zz obrigado, mas ainda falta', 'zz.reabre@exemplo.com', $this->semAnexos());

        $this->assertSame('reaberto', $this->valor("SELECT status FROM tickets WHERE id = ?", [$id]), 'reaberta, não "nova resposta"');
        $this->assertSame(1, (int) $this->valor("SELECT reaberto_contador FROM tickets WHERE id = ?", [$id]));
        $this->assertSame(1, (int) $this->valor("SELECT visivel_solicitante FROM comentarios WHERE ticket_id = ?", [$id]));

        // O mesmo e-mail chegando de novo (outra caixa ou laço) não duplica o comentário
        $this->chamar('adicionarComentario', $id, 'zz obrigado, mas ainda falta', 'zz.reabre@exemplo.com', $this->semAnexos());
        $this->assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM comentarios WHERE ticket_id = ?", [$id]));
    }

    public function test_quem_recebeu_copia_pode_responder_mesmo_com_lista_longa(): void {
        $this->naCaixa($this->setorA, $this->caixaA);
        $id = $this->chamar('criarTicketPorEmail', 'zz cópia', 'zz corpo', 'zz.titular@exemplo.com', $this->semAnexos());
        $copiado = 'zz.um-endereco-bem-comprido-para-passar-dos-255-caracteres@subdominio.exemplo.com.br';
        $this->db->prepare("INSERT INTO ticket_copias (ticket_id, email) VALUES (?, ?)")->execute([$id, $copiado]);

        $this->assertNull($this->chamar('motivoRecusaComentario', $id, $copiado, 'passou'), 'copiado vira comentário');
        $this->assertSame('o remetente não participa dela', $this->chamar('motivoRecusaComentario', $id, 'zz.estranho@exemplo.com', 'passou'));
    }

    public function test_falha_no_meio_nao_deixa_nada_pela_metade(): void {
        // Sem a transação do teste: o método abre e desfaz a sua própria
        $this->db->rollBack();
        $email = 'zz.falha.' . bin2hex(random_bytes(3)) . '@exemplo.com';
        $protocoloAntes = $this->valor("SELECT COALESCE(MAX(ultimo), 0) FROM protocolo_sequencia");
        $anexosAntes = count(glob(UploadService::pasta() . '*'));

        $this->naCaixa(999999, $this->caixaA); // setor inexistente: o INSERT da demanda falha (chave estrangeira)
        $anexos = ['validos' => [['nome' => 'a.pdf', 'conteudo' => self::PDF, 'tamanho' => strlen(self::PDF), 'tipo' => 'application/pdf']], 'rejeitados' => []];
        try {
            $this->chamar('criarTicketPorEmail', 'zz falha', 'zz corpo', $email, $anexos);
            $this->fail('deveria lançar');
        } catch (Exception $e) {
            // esperado
        }
        $usuarios = (int) $this->valor("SELECT COUNT(*) FROM usuarios WHERE email = ?", [$email]);
        $this->db->prepare("DELETE FROM usuarios WHERE email = ?")->execute([$email]); // limpeza, caso o teste falhe

        $this->assertSame(0, $usuarios, 'o solicitante novo também foi desfeito');
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM tickets WHERE titulo = 'zz falha'"));
        $this->assertSame($protocoloAntes, $this->valor("SELECT COALESCE(MAX(ultimo), 0) FROM protocolo_sequencia"), 'o número do protocolo não foi gasto');
        $this->assertSame($anexosAntes, count(glob(UploadService::pasta() . '*')), 'nenhum arquivo órfão no disco');
    }

    /**
     * Auditoria 01/11/2026, BUG-08: a tentativa é contada antes de processar, então um erro fatal (que
     * nenhum catch pega) também conta, e depois de MAX_TENTATIVAS o e-mail é deixado de lado.
     */
    public function test_tentativa_contada_antes_de_processar(): void {
        $this->naCaixa($this->setorA, $this->caixaA);
        $mid = '<zz-tentativa-' . bin2hex(random_bytes(4)) . '@teste.local>';
        $linhas = fn() => (int) $this->valor("SELECT COUNT(*) FROM emails_erro WHERE message_id = ?", [$mid]);

        // Sucesso: não sobra nada em emails_erro
        $this->assertSame(1, $this->chamar('iniciarTentativa', $mid, 'zz@exemplo.com', 'zz assunto'));
        $this->assertSame(EmailService::MARCA_INTERROMPIDA, $this->valor("SELECT erro_mensagem FROM emails_erro WHERE message_id = ?", [$mid]));
        $this->chamar('concluirTentativa');
        $this->assertSame(0, $linhas());

        // Erro capturado: guarda a mensagem; a tentativa seguinte que der certo devolve a contagem e o erro de antes
        $this->chamar('iniciarTentativa', $mid, 'zz@exemplo.com', 'zz assunto');
        $this->chamar('anotarFalha', 'TypeError: estrutura inválida');
        $this->assertSame(2, $this->chamar('iniciarTentativa', $mid, 'zz@exemplo.com', 'zz assunto'));
        $this->chamar('concluirTentativa');
        $this->assertSame(1, (int) $this->valor("SELECT tentativas FROM emails_erro WHERE message_id = ?", [$mid]));
        $this->assertSame('TypeError: estrutura inválida', $this->valor("SELECT erro_mensagem FROM emails_erro WHERE message_id = ?", [$mid]));

        // Processo morto no meio (sem concluir nem anotar) quatro vezes: a sexta tentativa já passa do limite
        for ($i = 2; $i <= EmailService::MAX_TENTATIVAS; $i++) {
            $this->assertSame($i, $this->chamar('iniciarTentativa', $mid, 'zz@exemplo.com', 'zz assunto'));
        }
        $this->assertSame(EmailService::MAX_TENTATIVAS + 1, $this->chamar('iniciarTentativa', $mid, 'zz@exemplo.com', 'zz assunto'));
        $this->assertSame(1, $linhas(), 'uma linha por mensagem e caixa');
    }
}
