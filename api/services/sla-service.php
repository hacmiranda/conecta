<?php
class SLAService {
    private $db;
    private $setorGeralId = null;
    private $cacheHorarios = []; // [setor_id => horários]
    private $cacheFeriados = []; // [setor_id => feriados]
    private $cacheCongelaSla = []; // [status => bool]
    private $cachePrazosPrioridade = []; // [prioridade => [minutos_resposta, horas_resolucao]]

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Situação atual do SLA de uma demanda: {tipo, segundos_restantes, percentual, vencido, pausado}
     * ou null se não há SLA para mostrar (sem criado_em, ou já com resposta/resolução cumpridas).
     *
     * $ticket precisa de: criado_em, status, prioridade (campos de `tickets`).
     * $sla é a linha de `ticket_sla` (ou [] se ainda não existir): primeira_resposta_em,
     * resolvido_em, tempo_pausado_segundos, pausado_em. $setorId define o expediente/feriados usados.
     */
    public function situacao(array $ticket, array $sla, ?int $setorId): ?array {
        if (empty($ticket['criado_em'])) {
            return null;
        }

        $status = (string) ($ticket['status'] ?? '');
        $jaConcluida = in_array($status, ['resolvido', 'encerrado', 'cancelado'], true);
        $pausadoAgora = $this->congelaSla($status);
        $tempoPausado = (int) ($sla['tempo_pausado_segundos'] ?? 0);

        // Pausa em andamento (ainda não somada em tempo_pausado_segundos, que só é atualizado
        // quando a pausa termina — ver SLAService::retomarPausa): desconta também o trecho
        // corrente, senão o tempo decorrido continua crescendo enquanto a demanda está pausada.
        if ($pausadoAgora && !empty($sla['pausado_em'])) {
            $tempoPausado += $this->calcularSegundosUteis($sla['pausado_em'], date('Y-m-d H:i:s'), $setorId);
        }

        $tempoDecorrido = $this->calcularSegundosUteis($ticket['criado_em'], date('Y-m-d H:i:s'), $setorId) - $tempoPausado;

        [$slaRespostaMinutos, $slaResolucaoHoras] = $this->prazosDaPrioridade((string) ($ticket['prioridade'] ?? 'normal'));

        // SLA de resposta: enquanto a demanda não teve a primeira resposta do time (e não foi
        // concluída sem uma — dado legado/importado, já que o fluxo normal sempre grava a resposta)
        if (empty($sla['primeira_resposta_em']) && !$jaConcluida) {
            return $this->montarSituacao('resposta', $slaRespostaMinutos * 60, $tempoDecorrido, $pausadoAgora);
        }

        // SLA de resolução: enquanto não foi resolvida/encerrada/cancelada
        if (empty($sla['resolvido_em']) && !$jaConcluida) {
            return $this->montarSituacao('resolucao', $slaResolucaoHoras * 3600, $tempoDecorrido, $pausadoAgora);
        }

        return null;
    }

    private function montarSituacao(string $tipo, int $segundosPrazo, int $tempoDecorrido, bool $pausado): array {
        $segundosRestantes = $segundosPrazo - $tempoDecorrido;
        return [
            'tipo' => $tipo,
            'segundos_restantes' => $segundosRestantes,
            'percentual' => min(100, ($tempoDecorrido / max(1, $segundosPrazo)) * 100),
            'vencido' => $segundosRestantes <= 0 && !$pausado,
            'pausado' => $pausado,
        ];
    }

    /**
     * Se o status informado pausa o SLA (catálogo global `ticket_status.congela_sla`), com cache
     * por instância. Pública para ser a única leitura desse campo (reaproveitada por
     * tickets.php ao decidir se pausa/retoma o SLA numa troca de status).
     */
    public function congelaSla(string $status): bool {
        if (!isset($this->cacheCongelaSla[$status])) {
            $stmt = $this->db->prepare("SELECT congela_sla FROM ticket_status WHERE nome = ?");
            $stmt->execute([$status]);
            $this->cacheCongelaSla[$status] = (bool) ($stmt->fetchColumn() ?: false);
        }
        return $this->cacheCongelaSla[$status];
    }

    /**
     * Prazos (minutos de resposta, horas de resolução) da prioridade no catálogo global
     * `ticket_prioridades`, com cache por request. Prioridade desconhecida cai no prazo "normal".
     */
    private function prazosDaPrioridade(string $prioridade): array {
        if (!isset($this->cachePrazosPrioridade[$prioridade])) {
            $stmt = $this->db->prepare("SELECT sla_resposta_minutos, sla_resolucao_horas FROM ticket_prioridades WHERE nome = ?");
            $stmt->execute([$prioridade]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->cachePrazosPrioridade[$prioridade] = $row
                ? [(int) $row['sla_resposta_minutos'], (int) $row['sla_resolucao_horas']]
                : [720, 24];
        }
        return $this->cachePrazosPrioridade[$prioridade];
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
