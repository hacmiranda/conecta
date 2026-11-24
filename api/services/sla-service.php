<?php
class SLAService {
    private $db;
    private $setorGeralId = null;
    private $cacheHorarios = []; // [setor_id => horários]
    private $cacheFeriados = []; // [setor_id => feriados]
    private $cacheCongelaSla = []; // [status => bool]
    private $cacheSemSla = []; // [prioridade => bool]
    private $cacheJornada = []; // [setor_id => minutos da jornada]
    private $cacheSlaAtivo = []; // [setor_id => bool]
    private $cachePrazosSetor = []; // ["setor|prioridade" => ['resposta'=>s,'resolucao'=>s]|null]

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

        // Setor com SLA desligado, prioridade sem SLA ou sem prazo definido no setor: não há prazo a mostrar
        $prazos = $this->prazosDoSetor($setorId, (string) ($ticket['prioridade'] ?? 'normal'));
        if ($prazos === null) {
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

        // SLA de resposta: enquanto a demanda não teve a primeira resposta do time (e não foi
        // concluída sem uma — dado legado/importado, já que o fluxo normal sempre grava a resposta)
        if (empty($sla['primeira_resposta_em']) && !$jaConcluida) {
            return $this->montarSituacao('resposta', $prazos['resposta'], $tempoDecorrido, $pausadoAgora);
        }

        // SLA de resolução: enquanto não foi resolvida/encerrada/cancelada
        if (empty($sla['resolvido_em']) && !$jaConcluida) {
            return $this->montarSituacao('resolucao', $prazos['resolucao'], $tempoDecorrido, $pausadoAgora);
        }

        return null;
    }

    /**
     * Média, em segundos úteis, da primeira resposta (definição única: ticket_sla.primeira_resposta_em,
     * gravada pela regra do DemandaService). Usa o tempo já calculado e, em dado antigo sem ele, calcula
     * pelas datas. $linhas: criado_em, setor_id, primeira_resposta_em, tempo_primeira_resposta_segundos.
     */
    public function mediaPrimeiraResposta(array $linhas): int {
        return $this->mediaTempos($linhas, 'primeira_resposta_em', 'tempo_primeira_resposta_segundos');
    }

    /** O mesmo para a resolução: resolvido_em, tempo_resolucao_segundos (já sem o tempo pausado). */
    public function mediaResolucao(array $linhas): int {
        return $this->mediaTempos($linhas, 'resolvido_em', 'tempo_resolucao_segundos');
    }

    private function mediaTempos(array $linhas, string $campoData, string $campoSegundos): int {
        $soma = 0;
        $quantos = 0;
        foreach ($linhas as $l) {
            if (empty($l[$campoData]) || empty($l['criado_em'])) {
                continue;
            }
            // Demanda sem SLA (setor desligado, prioridade sem SLA ou sem prazo) não entra nos tempos médios
            if ($this->prazosDoSetor($l['setor_id'] ?? null, (string) ($l['prioridade'] ?? '')) === null) {
                continue;
            }
            $segundos = (int) ($l[$campoSegundos] ?? 0);
            if ($segundos <= 0) {
                $segundos = $this->calcularSegundosUteis($l['criado_em'], $l[$campoData], $l['setor_id'] ?? null);
            }
            $soma += max(0, $segundos);
            $quantos++;
        }
        return $quantos > 0 ? (int) round($soma / $quantos) : 0;
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
     * A prioridade não tem SLA (`ticket_prioridades.sem_sla`, ex.: "baixissima", migração 42)? Com cache.
     */
    public function semSla(string $prioridade): bool {
        if ($prioridade === '') {
            return false;
        }
        if (!isset($this->cacheSemSla[$prioridade])) {
            $stmt = $this->db->prepare("SELECT sem_sla FROM ticket_prioridades WHERE nome = ?");
            $stmt->execute([$prioridade]);
            $this->cacheSemSla[$prioridade] = (bool) ($stmt->fetchColumn() ?: false);
        }
        return $this->cacheSemSla[$prioridade];
    }

    /**
     * O SLA fica pausado com este status e esta prioridade? Pausa se o status congela o SLA ou se não
     * há prazo de SLA. Com o setor informado vale a regra do setor (desligado, prioridade sem prazo...);
     * sem setor, só a prioridade sem SLA do catálogo (comportamento antigo). Regra única de
     * DemandaService::ajustarPausaSla.
     */
    public function pausaSla(string $status, string $prioridade, ?int $setorId = null): bool {
        if ($setorId === null) {
            return $this->congelaSla($status) || $this->semSla($prioridade);
        }
        return $this->congelaSla($status) || $this->prazosDoSetor($setorId, $prioridade) === null;
    }

    /**
     * Duração, em minutos, de um "dia útil" do setor: o expediente ativo mais comum entre os dias
     * (empate: o maior). Setor sem expediente usa o do Geral; sem nada, 540 (9 h).
     */
    public function jornadaMinutos(?int $setorId): int {
        $chave = $setorId ? (int) $setorId : 0;
        if (!isset($this->cacheJornada[$chave])) {
            $contagem = [];
            foreach ($this->buscarHorariosUteis($setorId) as $h) {
                [$h1, $m1] = array_map('intval', explode(':', (string) $h['hora_inicio']));
                [$h2, $m2] = array_map('intval', explode(':', (string) $h['hora_fim']));
                $minutos = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
                if ($minutos > 0) {
                    $contagem[$minutos] = ($contagem[$minutos] ?? 0) + 1;
                }
            }
            $melhor = 540;
            $maior = 0;
            foreach ($contagem as $minutos => $qtd) {
                if ($qtd > $maior || ($qtd === $maior && $minutos > $melhor)) {
                    $maior = $qtd;
                    $melhor = $minutos;
                }
            }
            $this->cacheJornada[$chave] = $melhor;
        }
        return $this->cacheJornada[$chave];
    }

    /** O setor tem o SLA ligado (`setores.sla_ativo`)? Setor nulo ou inexistente = não. Com cache. */
    public function slaAtivo(?int $setorId): bool {
        if (!$setorId) {
            return false;
        }
        if (!isset($this->cacheSlaAtivo[$setorId])) {
            $stmt = $this->db->prepare("SELECT sla_ativo FROM setores WHERE id = ?");
            $stmt->execute([$setorId]);
            $this->cacheSlaAtivo[$setorId] = (bool) ($stmt->fetchColumn() ?: false);
        }
        return $this->cacheSlaAtivo[$setorId];
    }

    /**
     * Prazos do SLA da prioridade neste setor, em segundos úteis: ['resposta' => int, 'resolucao' => int].
     * null quando não há SLA: setor desligado, prioridade sem SLA no catálogo, "sem prazo" no setor
     * ou prazo não definido (dias e minutos vazios, ou soma zero). "1 dia" = a jornada do setor.
     */
    public function prazosDoSetor(?int $setorId, string $prioridade): ?array {
        $chave = (int) $setorId . '|' . $prioridade;
        if (array_key_exists($chave, $this->cachePrazosSetor)) {
            return $this->cachePrazosSetor[$chave];
        }
        $prazos = null;
        if ($this->slaAtivo($setorId)) {
            $stmt = $this->db->prepare(
                "SELECT p.sem_sla, sp.sla_sem_prazo, sp.sla_resposta_dias, sp.sla_resposta_minutos,
                        sp.sla_resolucao_dias, sp.sla_resolucao_minutos
                 FROM ticket_prioridades p
                 JOIN setor_prioridades sp ON sp.prioridade_id = p.id AND sp.setor_id = ?
                 WHERE p.nome = ?");
            $stmt->execute([(int) $setorId, $prioridade]);
            $l = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($l && empty($l['sem_sla']) && empty($l['sla_sem_prazo'])) {
                $jornada = $this->jornadaMinutos($setorId);
                $resposta = ((int) $l['sla_resposta_dias'] * $jornada + (int) $l['sla_resposta_minutos']) * 60;
                $resolucao = ((int) $l['sla_resolucao_dias'] * $jornada + (int) $l['sla_resolucao_minutos']) * 60;
                if ($resposta > 0 && $resolucao > 0) {
                    $prazos = ['resposta' => $resposta, 'resolucao' => $resolucao];
                }
            }
        }
        return $this->cachePrazosSetor[$chave] = $prazos;
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
