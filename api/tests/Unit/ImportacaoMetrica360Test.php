<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/importacao-metrica360.php';

/**
 * Plano da importação do METRICA360: papéis convertidos, setores ligados pelo NOME (os ids dos dois
 * sistemas estão trocados), coordenador com vários setores e conta que já existe sem alteração.
 */
final class ImportacaoMetrica360Test extends TestCase {

    /** Setores do METRICA360 com os ids trocados em relação ao Conecta (Cobrança=2, Cadastro=3) */
    private function setoresOrigem(): array {
        return [
            ['id' => 2, 'nome' => 'Cobrança', 'ativo_painel' => 1, 'coordenador_id' => 10],
            ['id' => 3, 'nome' => 'CADASTRO', 'ativo_painel' => 1, 'coordenador_id' => 10],
            ['id' => 4, 'nome' => 'Ouvidoria', 'ativo_painel' => 0, 'coordenador_id' => null],
            ['id' => 5, 'nome' => 'Setor vazio', 'ativo_painel' => 1, 'coordenador_id' => null],
        ];
    }

    private function setoresDestino(): array {
        return [
            ['id' => 1, 'nome' => 'Geral'],
            ['id' => 2, 'nome' => 'Cadastro'],
            ['id' => 3, 'nome' => 'Cobrança'],
        ];
    }

    private function u(int $id, string $email, string $role, ?int $setor, int $externo = 0, string $nome = ''): array {
        return ['id' => $id, 'email' => $email, 'name' => $nome ?: ucfirst(strtok($email, '@')), 'username' => '', 'role' => $role, 'setor_id' => $setor, 'atendimento_externo' => $externo];
    }

    private function planejar(array $usuarios, array $destino = []): array {
        return ImportacaoMetrica360::planejar($this->setoresOrigem(), $usuarios, $this->setoresDestino(), $destino);
    }

    private function linha(array $plano, string $email): array {
        foreach ($plano['usuarios'] as $u) {
            if ($u['email'] === $email) return $u;
        }
        $this->fail("usuário $email fora do plano");
    }

    public function test_normalizar_ignora_maiusculas_acentos_e_espacos(): void {
        $this->assertSame('cobranca', ImportacaoMetrica360::normalizar('  COBRANÇA '));
        $this->assertSame('licitacoes e contratos', ImportacaoMetrica360::normalizar('Licitações  e Contratos'));
    }

    public function test_papeis_sao_convertidos(): void {
        $this->assertSame('operador', ImportacaoMetrica360::papelNoConecta('atendente'));
        $this->assertSame('supervisor', ImportacaoMetrica360::papelNoConecta('supervisora'));
        $this->assertSame('coordenador_geral', ImportacaoMetrica360::papelNoConecta('coordenadora_geral'));
        $this->assertSame('coordenador', ImportacaoMetrica360::papelNoConecta('coordenador'));
        $this->assertSame('admin', ImportacaoMetrica360::papelNoConecta('admin'));
        $this->assertNull(ImportacaoMetrica360::papelNoConecta('qualquer'));
    }

    public function test_setores_sao_ligados_pelo_nome_e_nao_pelo_id(): void {
        $plano = $this->planejar([$this->u(1, 'a@x.org', 'atendente', 3), $this->u(2, 'b@x.org', 'atendente', 2)]);
        $porNome = array_column($plano['setores'], null, 'nome');
        // "CADASTRO" (id 3 na origem) é o "Cadastro" (id 2 no Conecta); "Cobrança" (2 na origem) é o 3 do Conecta
        $this->assertSame(2, $porNome['Cadastro']['destino_id']);
        $this->assertSame(3, $porNome['Cobrança']['destino_id']);
        $this->assertSame('existe', $porNome['Cadastro']['acao']);
        $this->assertSame('cadastro', $this->linha($plano, 'a@x.org')['setor_chave']);
    }

    public function test_so_entram_setores_com_servidor_ativo(): void {
        $plano = $this->planejar([$this->u(1, 'a@x.org', 'atendente', 4)]);
        $nomes = array_column($plano['setores'], 'nome');
        $this->assertSame(['Ouvidoria'], $nomes);
        $this->assertSame('criar', $plano['setores'][0]['acao']);
        $this->assertFalse($plano['setores'][0]['pesquisa_painel'], 'ativo_painel = 0 vira fora do Painel de Setores');
    }

    public function test_setor_com_coordenador_ativo_entra_mesmo_sem_lotados(): void {
        $plano = $this->planejar([$this->u(10, 'coord@x.org', 'coordenador', null)]);
        $this->assertEqualsCanonicalizing(['Cobrança', 'Cadastro'], array_column($plano['setores'], 'nome'));
    }

    public function test_coordenador_fica_com_principal_e_adicionais(): void {
        $plano = $this->planejar([$this->u(10, 'coord@x.org', 'coordenador', 2)]); // lotado em Cobrança (id 2 na origem)
        $c = $this->linha($plano, 'coord@x.org');
        $this->assertSame('coordenador', $c['tipo']);
        $this->assertSame('cobranca', $c['setor_chave']);
        $this->assertSame(['cadastro'], $c['adicionais_chaves']);
    }

    public function test_coordenador_sem_setor_proprio_fica_com_o_primeiro_que_coordena(): void {
        $plano = $this->planejar([$this->u(10, 'coord@x.org', 'coordenador', null)]);
        $c = $this->linha($plano, 'coord@x.org');
        $this->assertSame('cadastro', $c['setor_chave']);          // ordem alfabética
        $this->assertSame(['cobranca'], $c['adicionais_chaves']);
    }

    public function test_admin_nao_tem_setor_e_sem_setor_vai_para_o_geral(): void {
        $plano = $this->planejar([
            $this->u(1, 'adm@x.org', 'admin', 4),
            $this->u(2, 'sup@x.org', 'supervisora', null),
        ]);
        $this->assertNull($this->linha($plano, 'adm@x.org')['setor_chave']);
        $sup = $this->linha($plano, 'sup@x.org');
        $this->assertSame('supervisor', $sup['tipo']);
        $this->assertSame('geral', $sup['setor_chave']);
        $this->assertNotEmpty($sup['avisos']);
    }

    public function test_conta_existente_nao_muda_e_mostra_diferencas(): void {
        $destino = [['id' => 77, 'email' => 'A@X.org', 'nome' => 'A', 'tipo' => 'gestor', 'setor_id' => 2, 'atendimento_externo' => 0]];
        $plano = $this->planejar([$this->u(1, 'a@x.org', 'atendente', 2, 1)], $destino);
        $l = $this->linha($plano, 'a@x.org');
        $this->assertSame('existe', $l['acao']);
        $this->assertSame(77, $l['destino_id']);
        $this->assertNotEmpty($l['diferencas']);                    // gestor x operador, Cadastro x Cobrança
        $this->assertTrue($l['atualizar_atendimento_externo']);
    }

    public function test_um_coordenador_por_setor_no_conecta(): void {
        // Já existe um coordenador de Cadastro no Conecta: o da origem não recebe esse setor
        $destino = [['id' => 90, 'email' => 'outro@x.org', 'nome' => 'Outro', 'tipo' => 'coordenador', 'setor_id' => 2, 'atendimento_externo' => 0]];
        $plano = $this->planejar([$this->u(10, 'coord@x.org', 'coordenador', 2)], $destino);
        $c = $this->linha($plano, 'coord@x.org');
        $this->assertSame('cobranca', $c['setor_chave']);
        $this->assertSame([], $c['adicionais_chaves']);
        $this->assertNotEmpty($c['avisos']);
    }

    public function test_email_invalido_ou_muito_longo_e_papel_desconhecido_sao_ignorados(): void {
        $plano = $this->planejar([
            $this->u(1, 'sem-arroba', 'atendente', 2),
            $this->u(2, str_repeat('a', 95) . '@x.org', 'atendente', 2),
            $this->u(3, 'ok@x.org', 'papel_estranho', 2),
        ]);
        $this->assertSame([], $plano['usuarios']);
        $this->assertCount(3, $plano['avisos']);
    }

    public function test_relatorio_resume_o_plano(): void {
        $plano = $this->planejar([$this->u(1, 'a@x.org', 'atendente', 4)]);
        $texto = ImportacaoMetrica360::relatorio($plano, ['convites' => 214, 'feedbacks' => 221, 'categorias' => 7, 'frases' => 34], false);
        $this->assertStringContainsString('ENSAIO', $texto);
        $this->assertStringContainsString('Ouvidoria', $texto);
        $this->assertStringContainsString('214 convites', $texto);
    }
}
