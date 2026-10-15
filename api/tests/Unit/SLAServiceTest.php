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

        $this->db->exec("INSERT INTO setores (id, nome) VALUES (1, 'Geral')");
        // Segunda(2) a sexta(6) das 08:00 às 17:00 — tabela usa 1=domingo .. 7=sábado
        foreach ([2, 3, 4, 5, 6] as $dia) {
            $this->db->prepare('INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo) VALUES (1, ?, "08:00:00", "17:00:00", 1)')
                     ->execute([$dia]);
        }

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
}
