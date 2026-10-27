<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/crypto-service.php';

/**
 * Filtros de e-mail com banco: quais valem para a caixa (setor + globais), o que o EmailService barra e
 * como fica registrado. Banco local em transação desfeita; recusa produção.
 */
final class FiltroEmailTest extends TestCase {

    private PDO $db;
    private int $setorA;
    private int $setorB;
    private int $caixaA;
    private int $admin;

    protected function setUp(): void {
        if (Environment::isProduction()) {
            $this->markTestSkipped('Testes de integração só rodam no banco local.');
        }
        $this->db = (new Database())->getConnection();
        $this->db->beginTransaction();
        FiltroEmailService::limparCache();

        $setores = $this->db->query("SELECT id FROM setores WHERE ativo = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if (count($setores) < 2) {
            $this->markTestSkipped('Precisa de dois setores ativos.');
        }
        [$this->setorA, $this->setorB] = array_map('intval', $setores);
        $this->db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, ativo, trocar_senha) VALUES ('zz Filtro', ?, 'x', 'admin', 1, 0)")
                 ->execute(['zz.filtro.' . bin2hex(random_bytes(4)) . '@teste.local']);
        $this->admin = (int) $this->db->lastInsertId();
        $this->db->prepare("INSERT INTO setor_emails (setor_id, email, imap_host, imap_user, imap_pass_cifrada) VALUES (?, 'zz.filtro@teste.local', 'imap.invalid', 'zz', ?)")
                 ->execute([$this->setorA, CryptoService::encrypt('zz')]);
        $this->caixaA = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void {
        FiltroEmailService::limparCache();
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function criar(?int $setor, string $campo, string $termo, int $ativo = 1): int {
        $v = FiltroEmailService::validarTermo($campo, $termo);
        $this->db->prepare("INSERT INTO filtros_email (setor_id, campo, termo, termo_normalizado, ativo, criado_por) VALUES (?, ?, ?, ?, ?, ?)")
                 ->execute([$setor, $campo, $v['termo'], $v['normalizado'], $ativo, $this->admin]);
        FiltroEmailService::limparCache();
        return (int) $this->db->lastInsertId();
    }

    private function servicoNaCaixa(int $setor, int $caixa): EmailService {
        $s = new EmailService($this->db);
        foreach (['setorAtualId' => $setor, 'caixaEmailAtualId' => $caixa] as $prop => $valor) {
            (new ReflectionProperty(EmailService::class, $prop))->setValue($s, $valor);
        }
        return $s;
    }

    private function email(array $dados = []): array {
        return array_merge(['remetente' => 'Loja <promo@loja.com.br>', 'destinatarios' => ['setor@crprs.org.br'],
            'assunto' => 'Ofertas da semana', 'corpo' => 'Confira as ofertas.'], $dados);
    }

    public function test_vale_o_filtro_do_setor_e_o_global_mas_nao_o_de_outro_setor(): void {
        $doSetor = $this->criar($this->setorA, 'remetente', '@loja.com.br');
        $global = $this->criar(null, 'assunto', 'ofertas da semana');
        $deOutro = $this->criar($this->setorB, 'remetente', '@loja.com.br');

        $ids = array_map('intval', array_column(FiltroEmailService::ativosDaCaixa($this->db, $this->setorA), 'id'));
        $this->assertContains($doSetor, $ids);
        $this->assertContains($global, $ids);
        $this->assertNotContains($deOutro, $ids);
        $this->assertLessThan(array_search($global, $ids), array_search($doSetor, $ids), 'o do setor vem antes do global');
    }

    public function test_caixa_legada_sem_setor_so_usa_os_globais(): void {
        $doSetor = $this->criar($this->setorA, 'assunto', 'zzsoetor');
        $global = $this->criar(null, 'assunto', 'zzglobal');
        $ids = array_map('intval', array_column(FiltroEmailService::ativosDaCaixa($this->db, null), 'id'));
        $this->assertContains($global, $ids);
        $this->assertNotContains($doSetor, $ids);
    }

    public function test_email_service_barra_pelo_filtro_do_setor_da_caixa(): void {
        $id = $this->criar($this->setorA, 'remetente', '@loja.com.br');
        $servico = $this->servicoNaCaixa($this->setorA, $this->caixaA);

        $filtro = $servico->filtroQueBarra($this->email());
        $this->assertSame($id, (int) $filtro['id']);

        // Outra caixa (de outro setor) não é afetada pelo filtro do setor A
        $this->assertNull($this->servicoNaCaixa($this->setorB, $this->caixaA)->filtroQueBarra($this->email()));
        // E-mail que não coincide segue o caminho normal
        $this->assertNull($servico->filtroQueBarra($this->email(['remetente' => 'Maria <maria@cidadao.com>'])));
    }

    public function test_filtro_desligado_deixa_o_email_passar(): void {
        $this->criar($this->setorA, 'assunto', 'ofertas', 0);
        $this->assertNull($this->servicoNaCaixa($this->setorA, $this->caixaA)->filtroQueBarra($this->email()));
    }

    public function test_ocorrencia_e_historico_do_email_barrado(): void {
        $id = $this->criar($this->setorA, 'assunto', 'ofertas da semana');
        FiltroEmailService::registrarOcorrencia($this->db, $id);
        FiltroEmailService::registrarOcorrencia($this->db, $id);
        $linha = $this->db->query("SELECT ocorrencias, ultima_ocorrencia FROM filtros_email WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int) $linha['ocorrencias']);
        $this->assertNotNull($linha['ultima_ocorrencia']);

        // O registro do e-mail barrado liga ao filtro (usado pelo histórico da tela)
        $servico = $this->servicoNaCaixa($this->setorA, $this->caixaA);
        $registrar = new ReflectionMethod(EmailService::class, 'registrarEmailProcessado');
        $registrar->invoke($servico, '<zz-barrado-' . bin2hex(random_bytes(3)) . '@x>', 'promo@loja.com.br', 'Ofertas da semana', null, 'ignorado', 'Filtro: x', $id);
        $this->assertSame($id, (int) $this->db->query("SELECT filtro_email_id FROM emails_processados WHERE observacao = 'Filtro: x' ORDER BY id DESC LIMIT 1")->fetchColumn());
        $this->assertSame('ignorado', $this->db->query("SELECT tipo FROM emails_processados WHERE filtro_email_id = $id")->fetchColumn());
    }

    public function test_excluir_o_filtro_mantem_o_historico_sem_o_vinculo(): void {
        $id = $this->criar($this->setorA, 'assunto', 'ofertas');
        $servico = $this->servicoNaCaixa($this->setorA, $this->caixaA);
        $mid = '<zz-vinculo-' . bin2hex(random_bytes(3)) . '@x>';
        (new ReflectionMethod(EmailService::class, 'registrarEmailProcessado'))->invoke($servico, $mid, 'a@b.com', 'x', null, 'ignorado', 'Filtro: y', $id);
        $this->db->exec("DELETE FROM filtros_email WHERE id = $id");
        $this->assertNull($this->db->query("SELECT filtro_email_id FROM emails_processados WHERE message_id = " . $this->db->quote($mid))->fetchColumn());
    }

    public function test_excluir_o_setor_leva_os_filtros_dele(): void {
        $this->db->prepare("INSERT INTO setores (nome, ativo) VALUES (?, 1)")->execute(['zz setor filtro ' . bin2hex(random_bytes(3))]);
        $setor = (int) $this->db->lastInsertId();
        $id = $this->criar($setor, 'assunto', 'zzpalavra');
        $this->db->exec("DELETE FROM setores WHERE id = $setor");
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM filtros_email WHERE id = $id")->fetchColumn());
    }
}
