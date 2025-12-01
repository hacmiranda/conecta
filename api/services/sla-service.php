<?php
class SLAService {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Calcula segundos úteis entre duas datas considerando horários configurados e feriados
     */
    public function calcularSegundosUteis($dataInicio, $dataFim) {
        $inicio = new DateTime($dataInicio);
        $fim = new DateTime($dataFim);
        
        if ($inicio >= $fim) {
            return 0;
        }
        
        // Buscar horários úteis configurados
        $horariosUteis = $this->buscarHorariosUteis();
        
        // Buscar feriados
        $feriados = $this->buscarFeriados();
        
        $segundosTotais = 0;
        $dataAtual = clone $inicio;
        
        while ($dataAtual < $fim) {
            $diaSemana = (int)$dataAtual->format('N'); // 1=Segunda, 7=Domingo
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
     * Busca horários úteis configurados
     */
    private function buscarHorariosUteis() {
        $query = "SELECT dia_semana, hora_inicio, hora_fim, ativo FROM horarios_uteis WHERE ativo = 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        $horarios = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $horarios[$row['dia_semana']] = $row;
        }
        
        return $horarios;
    }
    
    /**
     * Busca feriados cadastrados
     */
    private function buscarFeriados() {
        $query = "SELECT data, recorrente FROM feriados";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
