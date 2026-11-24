<?php
require_once __DIR__ . '/sla-service.php';
require_once __DIR__ . '/demanda-service.php';

/**
 * SLA por setor: leitura, gravação (com validação) e aplicação do modelo "Padrão CRPRS".
 * Usa a transação de quem chama (o controller abre e confirma); nunca abre uma própria.
 * No banco os prazos ficam em dias úteis + minutos; "horas" existe só no formato da tela.
 */
class SlaSetorService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /** Minutos gravados -> {dias, horas, minutos} (a tela mostra horas e minutos separados). */
    private static function partes($dias, $minutos): array {
        $m = (int) $minutos;
        return ['dias' => (int) $dias, 'horas' => intdiv($m, 60), 'minutos' => $m % 60];
    }

    /** Formato do GET (desenho 5.5), sem os campos que dependem do usuário (pode_alterar...). */
    public function ler(int $setorId): array {
        $st = $this->db->prepare("SELECT sla_ativo FROM setores WHERE id = ?");
        $st->execute([$setorId]);
        $ativo = $st->fetchColumn();
        if ($ativo === false) {
            throw new InvalidArgumentException('Setor não encontrado');
        }
        $st = $this->db->prepare(
            "SELECT p.nome, p.sem_sla, sp.ativo, sp.sla_sem_prazo, sp.sla_resposta_dias, sp.sla_resposta_minutos,
                    sp.sla_resolucao_dias, sp.sla_resolucao_minutos
             FROM setor_prioridades sp JOIN ticket_prioridades p ON p.id = sp.prioridade_id
             WHERE sp.setor_id = ? ORDER BY sp.ordem, p.ordem, p.id");
        $st->execute([$setorId]);
        $prioridades = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $semCatalogo = (bool) $l['sem_sla'];
            $prioridades[] = [
                'prioridade' => $l['nome'],
                'nome' => ucfirst($l['nome']),
                'ativa_no_setor' => (bool) $l['ativo'],
                'sem_sla_catalogo' => $semCatalogo,
                'sem_prazo' => $semCatalogo || (bool) $l['sla_sem_prazo'],
                'resposta' => self::partes($l['sla_resposta_dias'], $l['sla_resposta_minutos']),
                'resolucao' => self::partes($l['sla_resolucao_dias'], $l['sla_resolucao_minutos']),
            ];
        }
        $modelo = [];
        $catalogo = $this->db->query("SELECT nome, sla_resposta_minutos, sla_resolucao_horas, sem_sla FROM ticket_prioridades ORDER BY ordem, id");
        foreach ($catalogo->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $modelo[] = [
                'prioridade' => $m['nome'],
                'resposta_minutos' => (int) $m['sla_resposta_minutos'],
                'resolucao_minutos' => (int) $m['sla_resolucao_horas'] * 60,
                'sem_sla' => (bool) $m['sem_sla'],
            ];
        }
        return [
            'setor_id' => $setorId,
            'sla_ativo' => (bool) $ativo,
            'jornada_minutos' => (new SLAService($this->db))->jornadaMinutos($setorId),
            'prioridades' => $prioridades,
            'modelo' => $modelo,
        ];
    }

    /** Aceita só booleano de verdade ou 0/1 inteiro ("false", null e texto são recusados). */
    private static function booleano($valor, string $campo): bool {
        if (is_bool($valor)) {
            return $valor;
        }
        if (is_int($valor) && ($valor === 0 || $valor === 1)) {
            return $valor === 1;
        }
        throw new InvalidArgumentException("$campo deve ser verdadeiro ou falso");
    }

    /** Converte {dias, horas, minutos} validando as faixas; devolve [dias, minutos totais]. */
    private static function converter($campo, string $rotulo): array {
        if (!is_array($campo) || ($campo !== [] && array_is_list($campo))) {
            throw new InvalidArgumentException("$rotulo: informe dias, horas e minutos");
        }
        $limites = ['dias' => 365, 'horas' => 23, 'minutos' => 59];
        $v = [];
        foreach ($limites as $k => $max) {
            $x = $campo[$k] ?? 0;
            if ($x === '' || $x === null) {
                $x = 0;
            }
            if (!is_numeric($x) || (int) $x != $x || $x < 0 || $x > $max) {
                throw new InvalidArgumentException("$rotulo: $k deve ser um número inteiro de 0 a $max");
            }
            $v[$k] = (int) $x;
        }
        return [$v['dias'], $v['horas'] * 60 + $v['minutos']];
    }

    /** Total em minutos úteis de um prazo no formato {dias, horas, minutos}. */
    private static function total(array $parte, int $jornada): int {
        return $parte['dias'] * $jornada + $parte['horas'] * 60 + $parte['minutos'];
    }

    /**
     * Grava o interruptor e os prazos. $entrada = {sla_ativo, prioridades: [{prioridade, sem_prazo, resposta, resolucao}]}.
     * Prioridade que não vem na lista fica como está. Prazo todo zerado = "ainda não definido" (aceito
     * com o setor desligado ou na prioridade inativa); prazo só de um dos dois campos é sempre recusado.
     * Lança InvalidArgumentException (400) com a mensagem.
     *
     * @return array{antes: array, depois: array}
     */
    public function salvar(int $setorId, array $entrada): array {
        $antes = $this->ler($setorId);
        $jornada = $antes['jornada_minutos'];

        $ligar = array_key_exists('sla_ativo', $entrada) ? self::booleano($entrada['sla_ativo'], 'sla_ativo') : $antes['sla_ativo'];
        $porNome = [];
        foreach ($antes['prioridades'] as $p) {
            $porNome[$p['prioridade']] = $p;
        }

        $novo = [];
        foreach ((array) ($entrada['prioridades'] ?? []) as $item) {
            $nome = is_array($item) ? (string) ($item['prioridade'] ?? '') : '';
            if (!isset($porNome[$nome])) {
                throw new InvalidArgumentException('Prioridade desconhecida neste setor: ' . ($nome !== '' ? $nome : '(vazia)'));
            }
            $rotulo = $porNome[$nome]['nome'];
            $semPrazo = array_key_exists('sem_prazo', $item) ? self::booleano($item['sem_prazo'], 'sem_prazo') : false;
            if ($porNome[$nome]['sem_sla_catalogo'] || $semPrazo) {
                $novo[$nome] = ['sem_prazo' => true];
                continue;
            }
            [$rd, $rm] = self::converter($item['resposta'] ?? [], "$rotulo, 1ª resposta");
            [$sd, $sm] = self::converter($item['resolucao'] ?? [], "$rotulo, resolução");
            $respTotal = $rd * $jornada + $rm;
            $resolTotal = $sd * $jornada + $sm;
            if ($respTotal === 0 && $resolTotal === 0) {
                $novo[$nome] = ['sem_prazo' => false, 'vazio' => true];
                continue;
            }
            if ($respTotal === 0 || $resolTotal === 0) {
                throw new InvalidArgumentException("$rotulo: informe o prazo da 1ª resposta e o da resolução, ou marque \"Sem SLA\"");
            }
            if ($resolTotal < $respTotal) {
                throw new InvalidArgumentException("$rotulo: o prazo de resolução não pode ser menor que o da 1ª resposta");
            }
            $novo[$nome] = ['sem_prazo' => false, 'rd' => $rd, 'rm' => $rm, 'sd' => $sd, 'sm' => $sm];
        }

        // Com o setor ligado, toda prioridade ativa precisa de prazo ou "sem prazo" (os valores já gravados valem)
        if ($ligar) {
            foreach ($antes['prioridades'] as $p) {
                if (!$p['ativa_no_setor']) {
                    continue;
                }
                $n = $novo[$p['prioridade']] ?? null;
                if ($n !== null) {
                    $falta = empty($n['sem_prazo']) && !empty($n['vazio']);
                } else {
                    $falta = !$p['sem_prazo']
                        && (self::total($p['resposta'], $jornada) === 0 || self::total($p['resolucao'], $jornada) === 0);
                }
                if ($falta) {
                    throw new InvalidArgumentException('Defina o prazo da prioridade "' . $p['nome'] . '" ou marque "Sem SLA" para ligar o SLA do setor');
                }
            }
        }

        $this->db->prepare("UPDATE setores SET sla_ativo = ? WHERE id = ?")->execute([$ligar ? 1 : 0, $setorId]);
        $upSem = $this->db->prepare(
            "UPDATE setor_prioridades sp JOIN ticket_prioridades p ON p.id = sp.prioridade_id
             SET sp.sla_sem_prazo = 1 WHERE sp.setor_id = ? AND p.nome = ?");
        $upPrazo = $this->db->prepare(
            "UPDATE setor_prioridades sp JOIN ticket_prioridades p ON p.id = sp.prioridade_id
             SET sp.sla_sem_prazo = 0, sp.sla_resposta_dias = ?, sp.sla_resposta_minutos = ?,
                 sp.sla_resolucao_dias = ?, sp.sla_resolucao_minutos = ?
             WHERE sp.setor_id = ? AND p.nome = ?");
        $upVazio = $this->db->prepare(
            "UPDATE setor_prioridades sp JOIN ticket_prioridades p ON p.id = sp.prioridade_id
             SET sp.sla_sem_prazo = 0, sp.sla_resposta_dias = NULL, sp.sla_resposta_minutos = NULL,
                 sp.sla_resolucao_dias = NULL, sp.sla_resolucao_minutos = NULL
             WHERE sp.setor_id = ? AND p.nome = ?");
        $mudouSemPrazo = [];
        foreach ($novo as $nome => $n) {
            if (!empty($n['sem_prazo'])) {
                $upSem->execute([$setorId, $nome]);
            } elseif (!empty($n['vazio'])) {
                $upVazio->execute([$setorId, $nome]);
            } else {
                $upPrazo->execute([$n['rd'], $n['rm'], $n['sd'], $n['sm'], $setorId, $nome]);
            }
            if ((bool) ($n['sem_prazo'] ?? false) !== $porNome[$nome]['sem_prazo']) {
                $mudouSemPrazo[] = $nome;
            }
        }

        $this->reaplicar($setorId, $ligar !== $antes['sla_ativo'], $mudouSemPrazo);
        return ['antes' => $antes, 'depois' => $this->ler($setorId)];
    }

    /** Copia o catálogo global para as prioridades do setor (dias = 0). Não mexe no interruptor. */
    public function aplicarModelo(int $setorId): array {
        $antes = $this->ler($setorId);
        $this->db->prepare(
            "UPDATE setor_prioridades sp JOIN ticket_prioridades p ON p.id = sp.prioridade_id
             SET sp.sla_resposta_dias = 0, sp.sla_resposta_minutos = p.sla_resposta_minutos,
                 sp.sla_resolucao_dias = 0, sp.sla_resolucao_minutos = p.sla_resolucao_horas * 60,
                 sp.sla_sem_prazo = p.sem_sla
             WHERE sp.setor_id = ?")->execute([$setorId]);
        $depois = $this->ler($setorId);
        if ($depois['sla_ativo']) {
            $mudou = [];
            foreach ($depois['prioridades'] as $i => $p) {
                if ($p['sem_prazo'] !== $antes['prioridades'][$i]['sem_prazo']) {
                    $mudou[] = $p['prioridade'];
                }
            }
            $this->reaplicar($setorId, false, $mudou);
        }
        return ['antes' => $antes, 'depois' => $depois];
    }

    /** Confere a pausa das demandas abertas: o setor inteiro se o interruptor mudou, senão só as prioridades afetadas. */
    private function reaplicar(int $setorId, bool $interruptorMudou, array $prioridades): void {
        if ($interruptorMudou) {
            DemandaService::aplicarSlaDoSetor($this->db, $setorId);
            return;
        }
        foreach ($prioridades as $nome) {
            DemandaService::aplicarSlaDoSetor($this->db, $setorId, $nome);
        }
    }
}
