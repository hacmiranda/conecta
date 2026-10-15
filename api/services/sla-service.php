<?php
class SLAService {
    private $db;
    private $setorGeralId = null;
    private $cacheHorarios = []; // [setor_id => horários]
    private $cacheFeriados = []; // [setor_id => feriados]

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Setor usado quando a demanda não tem setor (ou o setor não tem expediente cadastrado).
     */
    private function setorGeral() {
        if ($this->setorGeralId === null) {
            $stmt = $this->db->query("SELECT id FROM setores WHERE nome = 'Geral' LIMIT 1");
            $this->setorGeralId = (int) $stmt->fetchColumn();
        }
        return $this->setorGeralId;
    }

    /**
     * Calcula segundos úteis entre duas datas considerando o expediente e os feriados
     * do setor informado (null ou setor sem expediente próprio = setor Geral).
     */
    public function calcularSegundosUteis($dataInicio, $dataFim, $setorId = null) {
        $inicio = new DateTime($dataInicio);
        $fim = new DateTime($dataFim);

        if ($inicio >= $fim) {
            return 0;
        }

        // Buscar horários úteis e feriados configurados para o setor
        $horariosUteis = $this->buscarHorariosUteis($setorId);
        $feriados = $this->buscarFeriados($setorId);

        $segundosTotais = 0;
        $dataAtual = clone $inicio;

        while ($dataAtual < $fim) {
            $diaSemana = (int)$dataAtual->format('w'); // 0=Domingo, 1=Segunda, ..., 6=Sábado
            // Converter para formato da tabela: 1=Domingo, 2=Segunda, ..., 7=Sábado
            $diaSemana = $diaSemana == 0 ? 1 : $diaSemana + 1;
            $dataStr = $dataAtual->format('Y-m-d');

            // Verificar se é feriado
            if ($this->ehFeriado($dataStr, $feriados)) {
                $dataAtual->modify('+1 day');
                $dataAtual->setTime(0, 0, 0);
                continue;
            }

            // Verificar se dia tem horário útil configurado
            if (!isset($horariosUteis[$diaSemana]) || !$horariosUteis[$diaSemana]['ativo']) {
                $dataAtual->modify('+1 day');
                $dataAtual->setTime(0, 0, 0);
                continue;
            }

            $horario = $horariosUteis[$diaSemana];

            // Criar timestamps para início e fim do expediente
            $inicioExpediente = clone $dataAtual;
            list($h, $m, $s) = explode(':', $horario['hora_inicio']);
            $inicioExpediente->setTime((int)$h, (int)$m, (int)$s);

            $fimExpediente = clone $dataAtual;
            list($h, $m, $s) = explode(':', $horario['hora_fim']);
            $fimExpediente->setTime((int)$h, (int)$m, (int)$s);

            // Determinar início e fim efetivos para este dia
            $inicioEfetivo = max($dataAtual, $inicioExpediente);
            $fimEfetivo = min($fim, $fimExpediente);

            // Se há sobreposição, calcular segundos
            if ($inicioEfetivo < $fimEfetivo) {
                $segundosTotais += $fimEfetivo->getTimestamp() - $inicioEfetivo->getTimestamp();
            }

            // Avançar para próximo dia
            $dataAtual->modify('+1 day');
            $dataAtual->setTime(0, 0, 0);
        }

        return $segundosTotais;
    }

    /**
     * Busca os horários úteis do setor (com cache). Setor sem expediente cadastrado usa o do Geral.
     */
    private function buscarHorariosUteis($setorId = null) {
        $setorId = $setorId ? (int) $setorId : $this->setorGeral();

        if (!isset($this->cacheHorarios[$setorId])) {
            $horarios = $this->lerHorarios($setorId);
            if (empty($horarios) && $setorId !== $this->setorGeral()) {
                $horarios = $this->lerHorarios($this->setorGeral());
            }
            $this->cacheHorarios[$setorId] = $horarios;
        }

        return $this->cacheHorarios[$setorId];
    }

    private function lerHorarios($setorId) {
        $query = "SELECT dia_semana, hora_inicio, hora_fim, ativo FROM horarios_uteis WHERE ativo = 1 AND setor_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$setorId]);

        $horarios = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $horarios[$row['dia_semana']] = $row;
        }

        return $horarios;
    }

    /**
     * Busca os feriados cadastrados para o setor (com cache)
     */
    private function buscarFeriados($setorId = null) {
        $setorId = $setorId ? (int) $setorId : $this->setorGeral();

        if (!isset($this->cacheFeriados[$setorId])) {
            $stmt = $this->db->prepare("SELECT data, recorrente FROM feriados WHERE setor_id = ?");
            $stmt->execute([$setorId]);
            $this->cacheFeriados[$setorId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->cacheFeriados[$setorId];
    }

    /**
     * Verifica se uma data é feriado
     */
    private function ehFeriado($data, $feriados) {
        foreach ($feriados as $feriado) {
            if ($feriado['recorrente']) {
                // Feriado recorrente: comparar apenas mês e dia
                $feriadoMesDia = substr($feriado['data'], 5); // MM-DD
                $dataMesDia = substr($data, 5);
                if ($feriadoMesDia === $dataMesDia) {
                    return true;
                }
            } else {
                // Feriado específico: comparar data completa
                if ($feriado['data'] === $data) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Retoma o SLA pausado da demanda: soma o tempo útil em pausa e limpa a marca de pausa.
     * Sem pausa ativa, não faz nada.
     */
    public function retomarPausa(int $ticketId, ?int $setorId): void {
        $stmt = $this->db->prepare("SELECT COALESCE(tempo_pausado_segundos, 0) AS tempo_pausado, pausado_em
                                    FROM ticket_sla WHERE ticket_id = ?");
        $stmt->execute([$ticketId]);
        $sla = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sla || !$sla['pausado_em']) {
            return;
        }
        $pausado = $this->calcularSegundosUteis($sla['pausado_em'], date('Y-m-d H:i:s'), $setorId);
        $this->db->prepare("UPDATE ticket_sla SET pausado = FALSE, tempo_pausado_segundos = ?, pausado_em = NULL WHERE ticket_id = ?")
                 ->execute([(int) $sla['tempo_pausado'] + $pausado, $ticketId]);
    }

    /**
     * Formata segundos em formato legível
     */
    public function formatarTempo($segundos) {
        if ($segundos < 60) {
            return $segundos . 's';
        }

        $minutos = floor($segundos / 60);
        if ($minutos < 60) {
            return $minutos . 'min';
        }

        $horas = floor($minutos / 60);
        $minutosRestantes = $minutos % 60;

        if ($horas < 24) {
            return $horas . 'h' . ($minutosRestantes > 0 ? ' ' . $minutosRestantes . 'min' : '');
        }

        $dias = floor($horas / 24);
        $horasRestantes = $horas % 24;

        return $dias . 'd' . ($horasRestantes > 0 ? ' ' . $horasRestantes . 'h' : '');
    }
}
?>
