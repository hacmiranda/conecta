<?php
use PHPUnit\Framework\TestCase;

/**
 * SLAService::calcularSegundosUteis conta só o tempo dentro do expediente do setor,
 * pulando fim de semana e feriados — é o número usado para saber se uma demanda está
 * dentro do prazo. Banco em memória (SQLite) com o mesmo formato das tabelas reais
 * (horarios_uteis, feriados, setores), para não depender do MySQL local.
 */
final class SLAServiceTest extends TestCase {

    private PDO $db;
    private SLAService $sla;
    /** Segunda-feira futura mais próxima, à meia-noite — base de todas as datas do teste. */
    private DateTime $segunda;

    protected function setUp(): void {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE setores (id INTEGER PRIMARY KEY, nome TEXT)');
        $this->db->exec('CREATE TABLE horarios_uteis (setor_id INTEGER, dia_semana INTEGER, hora_inicio TEXT, hora_fim TEXT, ativo INTEGER)');
        $this->db->exec('CREATE TABLE feriados (setor_id INTEGER, data TEXT, recorrente INTEGER)');
        $this->db->exec('CREATE TABLE ticket_status (nome TEXT, congela_sla INTEGER)');
        $this->db->exec('CREATE TABLE ticket_prioridades (nome TEXT, sla_resposta_minutos INTEGER, sla_resolucao_horas INTEGER)');

        $this->db->exec("INSERT INTO setores (id, nome) VALUES (1, 'Geral')");
        // Segunda(2) a sexta(6) das 08:00 às 17:00 — tabela usa 1=domingo .. 7=sábado
        foreach ([2, 3, 4, 5, 6] as $dia) {
            $this->db->prepare('INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo) VALUES (1, ?, "08:00:00", "17:00:00", 1)')
                     ->execute([$dia]);
        }

        // Setor "sempre aberto" (expediente cobre o dia inteiro, todos os dias): usado pelos testes de
        // situacao(), que dependem do relógio real (date()) — assim o tempo decorrido é previsível
        // independente de quando os testes rodam, sem precisar simular o "agora".
        $this->db->exec("INSERT INTO setores (id, nome) VALUES (3, 'SempreAberto')");
        foreach (range(1, 7) as $dia) {
            $this->db->prepare('INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo) VALUES (3, ?, "00:00:00", "23:59:59", 1)')
                     ->execute([$dia]);
        }

        // Setor com expediente de largura zero (não "sem configuração"): usado para testar que
        // tempo fora do horário não conta. Precisa ser ativo=1 com janela vazia, não ativo=0 —
        // um setor com todo dia ativo=0 fica com lerHorarios() vazio e buscarHorariosUteis() cai
        // no fallback do setor "Geral" (comportamento intencional para setor sem config nenhuma),
        // o que tornaria este teste dependente do horário real em que ele é executado.
        $this->db->exec("INSERT INTO setores (id, nome) VALUES (4, 'SempreFechado')");
        foreach (range(1, 7) as $dia) {
            $this->db->prepare('INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo) VALUES (4, ?, "00:00:00", "00:00:00", 1)')
                     ->execute([$dia]);
        }

        // Catálogo global usado por situacao(): status que pausa o SLA e prazos da prioridade "normal".
        $this->db->exec("INSERT INTO ticket_status (nome, congela_sla) VALUES ('novo', 0), ('em_atendimento', 0), ('aguardando', 1), ('resolvido', 0), ('encerrado', 0), ('cancelado', 0)");
        $this->db->exec("INSERT INTO ticket_prioridades (nome, sla_resposta_minutos, sla_resolucao_horas) VALUES ('normal', 60, 2)");

        $this->sla = new SLAService($this->db);

        $this->segunda = new DateTime('monday next week');
        $this->segunda->setTime(0, 0, 0);
    }

    private function dia(int $offset): DateTime {
        return (clone $this->segunda)->modify("+{$offset} day");
    }

    public function test_dentro_do_mesmo_dia_util_conta_so_o_intervalo(): void {
        $inicio = $this->dia(0)->setTime(9, 0);
        $fim = $this->dia(0)->setTime(10, 30);
        $this->assertSame(5400, $this->sla->calcularSegundosUteis($inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s'), 1));
    }

    public function test_antes_e_depois_do_expediente_sao_cortados_para_as_bordas(): void {
        // 06:00 (antes de abrir) até 20:00 (depois de fechar) = só as 9h de expediente
        $inicio = $this->dia(0)->setTime(6, 0);
        $fim = $this->dia(0)->setTime(20, 0);
        $this->assertSame(9 * 3600, $this->sla->calcularSegundosUteis($inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s'), 1));
    }

    public function test_fim_de_semana_nao_conta(): void {
        // Sexta 16:00 até a Segunda seguinte 09:00: só a 1h de sexta + 1h de segunda
        $inicio = $this->dia(4)->setTime(16, 0);   // sexta
        $fim = $this->dia(7)->setTime(9, 0);       // segunda seguinte
        $this->assertSame(2 * 3600, $this->sla->calcularSegundosUteis($inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s'), 1));
    }

    public function test_feriado_especifico_e_descontado(): void {
        $terca = $this->dia(1);
        $this->db->prepare('INSERT INTO feriados (setor_id, data, recorrente) VALUES (1, ?, 0)')->execute([$terca->format('Y-m-d')]);

        // Segunda 08:00 até Quarta 08:00: 9h (segunda) + 0h (terça, feriado) + 0h (quarta, só chega às 08:00) = 9h
        $inicio = $this->dia(0)->setTime(8, 0);
        $fim = $this->dia(2)->setTime(8, 0);
        $this->assertSame(9 * 3600, $this->sla->calcularSegundosUteis($inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s'), 1));
    }

    public function test_feriado_recorrente_vale_todo_ano_so_pelo_mes_e_dia(): void {
        $terca = $this->dia(1);
        // Ano bem diferente do da terça real do teste — só mês/dia precisam coincidir
        $this->db->prepare('INSERT INTO feriados (setor_id, data, recorrente) VALUES (1, ?, 1)')->execute(['2000-' . $terca->format('m-d')]);

        $inicio = $this->dia(1)->setTime(8, 0);
        $fim = $this->dia(1)->setTime(17, 0);
        $this->assertSame(0, $this->sla->calcularSegundosUteis($inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s'), 1));
    }

    public function test_setor_sem_expediente_proprio_usa_o_do_setor_geral(): void {
        $this->db->exec("INSERT INTO setores (id, nome) VALUES (2, 'Cadastro')"); // sem linha em horarios_uteis
        $inicio = $this->dia(0)->setTime(9, 0);
        $fim = $this->dia(0)->setTime(10, 0);
        $this->assertSame(3600, $this->sla->calcularSegundosUteis($inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s'), 2));
    }

    public function test_inicio_igual_ou_depois_do_fim_da_zero(): void {
        $t = $this->dia(0)->setTime(10, 0)->format('Y-m-d H:i:s');
        $this->assertSame(0, $this->sla->calcularSegundosUteis($t, $t, 1));
    }

    public function test_formatar_tempo(): void {
        $this->assertSame('45s', $this->sla->formatarTempo(45));
        $this->assertSame('30min', $this->sla->formatarTempo(1800));
        $this->assertSame('2h 15min', $this->sla->formatarTempo(2 * 3600 + 15 * 60));
        $this->assertSame('3h', $this->sla->formatarTempo(3 * 3600));
        $this->assertSame('1d 2h', $this->sla->formatarTempo(26 * 3600));
    }

    // --- situacao() ---------------------------------------------------------------------
    // Usa o setor 3 ("SempreAberto", expediente o dia inteiro todos os dias) para que o tempo
    // decorrido seja previsível a partir do relógio real (situacao() usa date() internamente).

    private function slaVazio(): array {
        return ['primeira_resposta_em' => null, 'resolvido_em' => null, 'tempo_pausado_segundos' => 0];
    }

    public function test_situacao_resposta_dentro_do_prazo(): void {
        $criado = (new DateTime('-5 minutes'))->format('Y-m-d H:i:s');
        $ticket = ['criado_em' => $criado, 'status' => 'novo', 'prioridade' => 'normal', 'setor_id' => 3];

        $situacao = $this->sla->situacao($ticket, $this->slaVazio(), 3);

        $this->assertSame('resposta', $situacao['tipo']);
        $this->assertFalse($situacao['pausado']);
        $this->assertFalse($situacao['vencido']);
        // Prazo de 60min (3600s) menos ~5min (300s) decorridos = ~3300s restantes
        $this->assertEqualsWithDelta(3300, $situacao['segundos_restantes'], 5);
    }

    public function test_situacao_resolucao_apos_primeira_resposta(): void {
        $criado = (new DateTime('-90 minutes'))->format('Y-m-d H:i:s');
        $ticket = ['criado_em' => $criado, 'status' => 'em_atendimento', 'prioridade' => 'normal', 'setor_id' => 3];
        $sla = ['primeira_resposta_em' => '2020-01-01 00:00:00', 'resolvido_em' => null, 'tempo_pausado_segundos' => 0];

        $situacao = $this->sla->situacao($ticket, $sla, 3);

        $this->assertSame('resolucao', $situacao['tipo']);
        $this->assertFalse($situacao['vencido']);
        // Prazo de 2h (7200s) menos ~90min (5400s) decorridos = ~1800s restantes
        $this->assertEqualsWithDelta(1800, $situacao['segundos_restantes'], 5);
    }

    public function test_situacao_pausado_nao_vence_mesmo_com_prazo_esgotado(): void {
        // 10h decorridas, prazo de resposta de 1h: venceria, mas o status "aguardando" congela o SLA
        $criado = (new DateTime('-10 hours'))->format('Y-m-d H:i:s');
        $ticket = ['criado_em' => $criado, 'status' => 'aguardando', 'prioridade' => 'normal', 'setor_id' => 3];

        $situacao = $this->sla->situacao($ticket, $this->slaVazio(), 3);

        $this->assertTrue($situacao['pausado']);
        $this->assertFalse($situacao['vencido']);
    }

    public function test_situacao_vencida_quando_prazo_passa_sem_pausa(): void {
        // 2h decorridas, prazo de resposta de 1h, status não pausa o SLA
        $criado = (new DateTime('-2 hours'))->format('Y-m-d H:i:s');
        $ticket = ['criado_em' => $criado, 'status' => 'em_atendimento', 'prioridade' => 'normal', 'setor_id' => 3];

        $situacao = $this->sla->situacao($ticket, $this->slaVazio(), 3);

        $this->assertTrue($situacao['vencido']);
        $this->assertLessThan(0, $situacao['segundos_restantes']);
    }

    public function test_situacao_fora_do_horario_nao_conta_contra_o_prazo(): void {
        // Setor 4 não tem nenhum expediente ativo: por mais que o tempo real passe, 0s são úteis
        $criado = (new DateTime('-2 hours'))->format('Y-m-d H:i:s');
        $ticket = ['criado_em' => $criado, 'status' => 'novo', 'prioridade' => 'normal', 'setor_id' => 4];

        $situacao = $this->sla->situacao($ticket, $this->slaVazio(), 4);

        $this->assertFalse($situacao['vencido']);
        // Nada decorrido em horário útil: resta o prazo cheio (60min = 3600s)
        $this->assertSame(3600, $situacao['segundos_restantes']);
    }

    public function test_situacao_retorna_null_sem_criado_em(): void {
        $ticket = ['criado_em' => null, 'status' => 'novo', 'prioridade' => 'normal', 'setor_id' => 3];
        $this->assertNull($this->sla->situacao($ticket, $this->slaVazio(), 3));
    }

    public function test_situacao_retorna_null_quando_ja_resolvida(): void {
        $criado = (new DateTime('-5 minutes'))->format('Y-m-d H:i:s');
        $ticket = ['criado_em' => $criado, 'status' => 'resolvido', 'prioridade' => 'normal', 'setor_id' => 3];
        $sla = ['primeira_resposta_em' => '2020-01-01 00:00:00', 'resolvido_em' => '2020-01-01 00:10:00', 'tempo_pausado_segundos' => 0];
        $this->assertNull($this->sla->situacao($ticket, $sla, 3));
    }

    public function test_situacao_retorna_null_quando_resolvida_sem_primeira_resposta(): void {
        // Dado legado/importado: concluída sem primeira_resposta_em nunca gravada — não deve
        // cair no cálculo de SLA de resposta (achado da revisão da Fase 8: a assimetria entre os
        // dois ramos deixava passar uma demanda já concluída).
        $criado = (new DateTime('-5 minutes'))->format('Y-m-d H:i:s');
        $ticket = ['criado_em' => $criado, 'status' => 'resolvido', 'prioridade' => 'normal', 'setor_id' => 3];
        $sla = ['primeira_resposta_em' => null, 'resolvido_em' => '2020-01-01 00:10:00', 'tempo_pausado_segundos' => 0];
        $this->assertNull($this->sla->situacao($ticket, $sla, 3));
    }

    public function test_situacao_desconta_a_pausa_em_andamento(): void {
        // 20min decorridos, dos quais os últimos 10min já estão com o SLA pausado (pausado_em);
        // tempo_pausado_segundos ainda não foi atualizado (só é somado quando a pausa termina,
        // em retomarPausa) — achado da revisão: sem descontar a pausa em andamento, os 10min
        // pausados contariam contra o prazo.
        $criado = (new DateTime('-20 minutes'))->format('Y-m-d H:i:s');
        $pausadoEm = (new DateTime('-10 minutes'))->format('Y-m-d H:i:s');
        $ticket = ['criado_em' => $criado, 'status' => 'aguardando', 'prioridade' => 'normal', 'setor_id' => 3];
        $sla = ['primeira_resposta_em' => null, 'resolvido_em' => null, 'tempo_pausado_segundos' => 0, 'pausado_em' => $pausadoEm];

        $situacao = $this->sla->situacao($ticket, $sla, 3);

        $this->assertTrue($situacao['pausado']);
        // 20min decorridos - 10min pausados = 10min (600s) úteis de fato; prazo de 60min (3600s)
        $this->assertEqualsWithDelta(3000, $situacao['segundos_restantes'], 5);
    }
}
