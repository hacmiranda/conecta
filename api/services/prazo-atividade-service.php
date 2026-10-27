<?php
/**
 * Prazos do Registro de Atividades: quais dias/semanas um servidor deveria ter enviado relatório e não enviou.
 * Porte fiel do PrazoRemotoModel + EntregaRelatorio do METRICA360 (27/10/2026), com uma diferença: os
 * feriados são os do setor do servidor (tabela feriados do Conecta, com os recorrentes).
 *
 * Dois tipos de pendência:
 *  - 'remoto'  (só para quem tem trabalho remoto): no modo semanal, cota de N relatórios por semana
 *              (chave semana_ini/semana_fim); no modo diário, cada dia fixo de remoto sem relatório (chave data);
 *  - 'entrega' (remoto ou não): dias de entrega de relatório do setor ou do próprio servidor (chave data).
 * Status: 'pendente' (dentro do prazo), 'atrasado' (prazo vencido) ou 'abonado' (justificado por admin/gestor).
 * Dias no padrão ISO: 1 = segunda … 7 = domingo.
 *
 * O cálculo fica em calcularPendencias(), pura (recebe tudo pronto); o resto só busca os dados no banco.
 */
class PrazoAtividadeService {

    public const DIAS_PADRAO = '1,2,3,4,5';
    public const MODALIDADES = ['remoto', 'presencial'];

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // ── Regras puras ────────────────────────────────────────────────────────────

    /** @return int[] dias válidos (1–7), sem repetição, em ordem crescente */
    public static function normalizarDias($dias): array {
        if ($dias === null || $dias === '') {
            return [];
        }
        $lista = is_array($dias) ? $dias : explode(',', (string) $dias);
        $validos = [];
        foreach ($lista as $dia) {
            $dia = trim((string) $dia);
            if (ctype_digit($dia) && (int) $dia >= 1 && (int) $dia <= 7) {
                $validos[(int) $dia] = true;
            }
        }
        $resultado = array_keys($validos);
        sort($resultado);
        return $resultado;
    }

    public static function diasParaCsv(array $dias): string {
        return implode(',', self::normalizarDias($dias));
    }

    /**
     * Dias em que o servidor entrega relatório. entrega_dias do servidor: NULL = segue o setor,
     * '' = dispensado, '1,3' = dias próprios.
     * @return int[]
     */
    public static function diasDeEntrega(?string $usuarioDias, bool $setorAtivo, string $setorDias): array {
        if ($usuarioDias !== null) {
            return self::normalizarDias($usuarioDias);
        }
        return $setorAtivo ? self::normalizarDias($setorDias) : [];
    }

    /** Data a partir da qual a entrega é cobrada; null = sem corte próprio. */
    public static function inicioDaEntrega(?string $usuarioDias, ?string $usuarioInicio, ?string $setorInicio): ?string {
        $inicio = $usuarioDias !== null ? $usuarioInicio : $setorInicio;
        return $inicio !== null && $inicio !== '' ? $inicio : null;
    }

    /** Soma $prazoDias dias úteis (sem sábado, domingo e feriados) a $data; prazo final às 23:59:59. */
    public static function calcularPrazoFinal(string $data, int $prazoDias, array $feriados = []): DateTimeImmutable {
        $feriadosSet = array_flip($feriados);
        $cursor = new DateTimeImmutable($data);
        $restantes = $prazoDias;
        while ($restantes > 0) {
            $cursor = $cursor->modify('+1 day');
            if ((int) $cursor->format('N') <= 5 && !isset($feriadosSet[$cursor->format('Y-m-d')])) {
                $restantes--;
            }
        }
        return $cursor->setTime(23, 59, 59);
    }

    /**
     * Datas (Y-m-d) de feriado no intervalo, a partir das linhas da tabela feriados ({data, recorrente}).
     * Recorrente vale todo ano no mesmo dia e mês.
     * @return string[]
     */
    public static function datasDeFeriado(array $linhas, string $ini, string $fim): array {
        $datas = [];
        $anoIni = (int) substr($ini, 0, 4);
        $anoFim = (int) substr($fim, 0, 4);
        foreach ($linhas as $f) {
            $data = substr((string) $f['data'], 0, 10);
            if (!empty($f['recorrente'])) {
                for ($ano = $anoIni; $ano <= $anoFim; $ano++) {
                    $d = $ano . substr($data, 4);
                    if (checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), $ano) && $d >= $ini && $d <= $fim) {
                        $datas[$d] = true;
                    }
                }
            } elseif ($data >= $ini && $data <= $fim) {
                $datas[$data] = true;
            }
        }
        $resultado = array_keys($datas);
        sort($resultado);
        return $resultado;
    }

    /**
     * Pendências de um servidor no período (pura).
     *
     * @param array $setor   ['modo' => semanal|diario, 'prazo' => int, 'entrega_ativa' => bool,
     *                        'entrega_dias' => string, 'entrega_inicio' => ?string, 'data_base' => ?string]
     * @param array $usuario ['trabalho_remoto' => bool, 'qtd_semana' => ?int, 'dias_remotos' => int[],
     *                        'entrega_dias' => ?string, 'entrega_inicio' => ?string]
     * @param string[] $feriados  datas de feriado (de $ini até bem depois de $fim, por causa do prazo)
     * @param array $enviados     [data => modalidade] dos relatórios enviados (da segunda da 1ª semana ao domingo da última)
     * @param array $abonos       ['remoto' => [data => info], 'entrega' => [data => info]]
     */
    public static function calcularPendencias(array $setor, array $usuario, string $ini, string $fim,
                                              array $feriados, array $enviados, array $abonos,
                                              ?DateTimeImmutable $agora = null): array {
        $agora ??= new DateTimeImmutable();

        // Nunca cobrar antes da data base do setor
        $dataBase = $setor['data_base'] ?? null;
        if ($dataBase) {
            if ($fim < $dataBase) return [];
            if ($ini < $dataBase) $ini = $dataBase;
        }

        // Relatório antigo sem modalidade conta como remoto
        $todos = [];
        $remotos = [];
        foreach ($enviados as $data => $modalidade) {
            $todos[$data] = true;
            if (($modalidade ?: 'remoto') === 'remoto') {
                $remotos[$data] = true;
            }
        }

        $diario  = ($setor['modo'] ?? 'semanal') === 'diario';
        $prazo   = (int) ($setor['prazo'] ?? 0);
        $remotas = [];
        if (!empty($usuario['trabalho_remoto'])) {
            $remotas = $diario
                ? self::pendenciasModoDiario($ini, $fim, $usuario['dias_remotos'] ?? [], $prazo, $feriados, $todos, $agora)
                : self::pendenciasModoSemanal($ini, $fim, (int) ($usuario['qtd_semana'] ?? 0), $prazo, $feriados, $remotos, $agora);
        }

        $entregas = self::pendenciasEntrega($setor, $usuario, $ini, $fim, $feriados, $todos, $agora);

        // Sem cobrança dupla: dia remoto que também é de entrega vale uma pendência só; no semanal,
        // dias de entrega sem envio já cobrem a falta da cota remota daquela semana.
        if ($diario) {
            $datasRemotas = array_column($remotas, 'data');
            $entregas = array_values(array_filter($entregas, fn($e) => !in_array($e['data'], $datasRemotas, true)));
        } else {
            $remotas = self::descontarEntregaDaCota($remotas, $entregas);
        }

        $remotas  = self::aplicarAbonos($remotas, $abonos['remoto'] ?? [], $diario ? 'data' : 'semana_ini');
        $entregas = self::aplicarAbonos($entregas, $abonos['entrega'] ?? [], 'data');

        $todas = array_merge($remotas, $entregas);
        usort($todas, fn($a, $b) => ($a['data'] ?? $a['semana_ini']) <=> ($b['data'] ?? $b['semana_ini']));
        return $todas;
    }

    private static function pendenciasEntrega(array $setor, array $usuario, string $ini, string $fim,
                                              array $feriados, array $enviados, DateTimeImmutable $agora): array {
        $usuarioDias = $usuario['entrega_dias'] ?? null;
        $dias = self::diasDeEntrega($usuarioDias, !empty($setor['entrega_ativa']), (string) ($setor['entrega_dias'] ?? self::DIAS_PADRAO));
        if (empty($dias)) return [];

        $inicio = self::inicioDaEntrega($usuarioDias, $usuario['entrega_inicio'] ?? null, $setor['entrega_inicio'] ?? null);
        if ($inicio !== null && $inicio > $ini) $ini = $inicio;

        $diasSet     = array_flip($dias);
        $feriadosSet = array_flip($feriados);
        $prazo       = (int) ($setor['prazo'] ?? 0);
        $cursor      = new DateTimeImmutable($ini);
        $fimObj      = new DateTimeImmutable($fim);
        $pendencias  = [];

        while ($cursor <= $fimObj) {
            $data = $cursor->format('Y-m-d');
            if (isset($diasSet[(int) $cursor->format('N')]) && !isset($feriadosSet[$data]) && !isset($enviados[$data])) {
                $prazoFinal = self::calcularPrazoFinal($data, $prazo, $feriados);
                $pendencias[] = [
                    'tipo'     => 'entrega',
                    'data'     => $data,
                    'status'   => $agora > $prazoFinal ? 'atrasado' : 'pendente',
                    'deadline' => $prazoFinal->format('Y-m-d H:i:s'),
                ];
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $pendencias;
    }

    private static function descontarEntregaDaCota(array $remotas, array $entregas): array {
        $resultado = [];
        foreach ($remotas as $p) {
            $cobertas = 0;
            foreach ($entregas as $e) {
                if ($e['data'] >= $p['semana_ini'] && $e['data'] <= $p['semana_fim']) $cobertas++;
            }
            $cobertas = min($cobertas, $p['faltam']);
            $p['esperado'] -= $cobertas;
            $p['faltam']   -= $cobertas;
            if ($p['faltam'] > 0) $resultado[] = $p;
        }
        return $resultado;
    }

    private static function pendenciasModoDiario(string $ini, string $fim, array $diasSemana, int $prazo,
                                                 array $feriados, array $enviados, DateTimeImmutable $agora): array {
        if (empty($diasSemana)) return [];

        $diasSet = array_flip(array_map('intval', $diasSemana));
        $cursor  = new DateTimeImmutable($ini);
        $fimObj  = new DateTimeImmutable($fim);
        $pendencias = [];

        while ($cursor <= $fimObj) {
            $data = $cursor->format('Y-m-d');
            if (isset($diasSet[(int) $cursor->format('N')]) && !isset($enviados[$data])) {
                $prazoFinal = self::calcularPrazoFinal($data, $prazo, $feriados);
                $pendencias[] = [
                    'tipo'     => 'remoto',
                    'data'     => $data,
                    'status'   => $agora > $prazoFinal ? 'atrasado' : 'pendente',
                    'deadline' => $prazoFinal->format('Y-m-d H:i:s'),
                ];
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $pendencias;
    }

    private static function pendenciasModoSemanal(string $ini, string $fim, int $qtdSemana, int $prazo,
                                                  array $feriados, array $enviados, DateTimeImmutable $agora): array {
        if ($qtdSemana <= 0) return [];

        $cursor = (new DateTimeImmutable($ini))->modify('Monday this week');
        $fimObj = new DateTimeImmutable($fim);
        $pendencias = [];

        while ($cursor <= $fimObj) {
            $semanaIni = $cursor->format('Y-m-d');
            $semanaFim = $cursor->modify('+6 days')->format('Y-m-d');
            $sexta     = $cursor->modify('+4 days')->format('Y-m-d');

            $enviadoSemana = 0;
            foreach ($enviados as $data => $_) {
                if ($data >= $semanaIni && $data <= $semanaFim) $enviadoSemana++;
            }

            if ($enviadoSemana < $qtdSemana) {
                $prazoFinal = self::calcularPrazoFinal($sexta, $prazo, $feriados);
                $pendencias[] = [
                    'tipo'       => 'remoto',
                    'semana_ini' => $semanaIni,
                    'semana_fim' => $semanaFim,
                    'esperado'   => $qtdSemana,
                    'enviado'    => $enviadoSemana,
                    'faltam'     => $qtdSemana - $enviadoSemana,
                    'status'     => $agora > $prazoFinal ? 'atrasado' : 'pendente',
                    'deadline'   => $prazoFinal->format('Y-m-d H:i:s'),
                ];
            }
            $cursor = $cursor->modify('+7 days');
        }
        return $pendencias;
    }

    private static function aplicarAbonos(array $pendencias, array $abonos, string $chave): array {
        foreach ($pendencias as &$p) {
            if (isset($abonos[$p[$chave]])) {
                $a = $abonos[$p[$chave]];
                $p['status']        = 'abonado';
                $p['abono_id']      = $a['abono_id'];
                $p['justificativa'] = $a['justificativa'];
                $p['abonado_por']   = $a['abonado_por'];
                $p['abonado_em']    = $a['abonado_em'];
            }
        }
        return $pendencias;
    }

    /**
     * Modalidade pré-selecionada na tela: a do rascunho; no controle diário de quem trabalha remoto,
     * dia fixo de remoto sugere "remoto" e os demais "presencial". No semanal o servidor escolhe.
     */
    public static function modalidadeSugerida(?string $doRascunho, array $setor, array $usuario, string $data): ?string {
        if (in_array($doRascunho, self::MODALIDADES, true)) {
            return $doRascunho;
        }
        if (($setor['modo'] ?? 'semanal') !== 'diario' || empty($usuario['trabalho_remoto']) || empty($usuario['dias_remotos'])) {
            return null;
        }
        $dia = (int) (new DateTimeImmutable($data))->format('N');
        return in_array($dia, array_map('intval', $usuario['dias_remotos']), true) ? 'remoto' : 'presencial';
    }

    // ── Dados do banco ──────────────────────────────────────────────────────────

    /** Regras do setor; sem linha, os padrões da tabela. */
    public function configSetor(int $setorId): array {
        $stmt = $this->db->prepare("SELECT * FROM atividades_config_setor WHERE setor_id = ?");
        $stmt->execute([$setorId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'modo'           => $r['modo_controle_remoto'] ?? 'semanal',
            'prazo'          => (int) ($r['prazo_dias_atraso'] ?? 0),
            'entrega_ativa'  => (int) ($r['entrega_ativa'] ?? 0) === 1,
            'entrega_dias'   => (string) ($r['entrega_dias'] ?? self::DIAS_PADRAO),
            'entrega_inicio' => $r['entrega_inicio'] ?? null,
            'data_base'      => $r['data_base'] ?? null,
        ];
    }

    /** Regras do servidor (com setor); sem linha, trabalho remoto ligado e entrega seguindo o setor. */
    public function configUsuario(int $usuarioId): array {
        $stmt = $this->db->prepare("
            SELECT u.setor_id, c.trabalho_remoto, c.qtd_dias_remoto_semana, c.entrega_dias, c.entrega_inicio
            FROM usuarios u LEFT JOIN atividades_config_usuario c ON c.usuario_id = u.id
            WHERE u.id = ?");
        $stmt->execute([$usuarioId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $dias = $this->db->prepare("SELECT dia_semana FROM atividades_dias_remotos WHERE usuario_id = ? ORDER BY dia_semana");
        $dias->execute([$usuarioId]);

        return [
            'setor_id'        => isset($r['setor_id']) ? (int) $r['setor_id'] : null,
            'trabalho_remoto' => !array_key_exists('trabalho_remoto', $r) || $r['trabalho_remoto'] === null || (int) $r['trabalho_remoto'] === 1,
            'qtd_semana'      => isset($r['qtd_dias_remoto_semana']) ? (int) $r['qtd_dias_remoto_semana'] : null,
            'dias_remotos'    => array_map('intval', $dias->fetchAll(PDO::FETCH_COLUMN)),
            'entrega_dias'    => $r['entrega_dias'] ?? null,
            'entrega_inicio'  => $r['entrega_inicio'] ?? null,
        ];
    }

    /** Dias de entrega efetivos do servidor (vazio = não precisa informar a modalidade). */
    public function diasDeEntregaDoUsuario(int $usuarioId): array {
        $u = $this->configUsuario($usuarioId);
        if (!$u['setor_id']) return [];
        $s = $this->configSetor($u['setor_id']);
        return self::diasDeEntrega($u['entrega_dias'], $s['entrega_ativa'], $s['entrega_dias']);
    }

    /** Pendências de um servidor no período (ver calcularPendencias). */
    public function pendenciasUsuario(int $usuarioId, string $ini, string $fim): array {
        $usuario = $this->configUsuario($usuarioId);
        if (!$usuario['setor_id']) return [];
        $setor = $this->configSetor($usuario['setor_id']);

        // Feriados um pouco além de $fim: o prazo (até 15 dias úteis) pode passar do período
        $fimFeriados = (new DateTimeImmutable($fim))->modify('+30 days')->format('Y-m-d');
        $stmt = $this->db->prepare("SELECT data, recorrente FROM feriados WHERE setor_id = ?");
        $stmt->execute([$usuario['setor_id']]);
        $feriados = self::datasDeFeriado($stmt->fetchAll(PDO::FETCH_ASSOC), $ini, $fimFeriados);

        // Envios alinhados às semanas (segunda a domingo) para não subcontar a 1ª semana
        $iniSemana = (new DateTimeImmutable($ini))->modify('Monday this week')->format('Y-m-d');
        $fimSemana = (new DateTimeImmutable($fim))->modify('Sunday this week')->format('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT data_referencia, modalidade FROM registros_atividades
            WHERE usuario_id = ? AND status = 'enviado' AND data_referencia BETWEEN ? AND ?");
        $stmt->execute([$usuarioId, $iniSemana, $fimSemana]);
        $enviados = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $enviados[$l['data_referencia']] = $l['modalidade'];
        }

        return self::calcularPendencias($setor, $usuario, $ini, $fim, $feriados, $enviados,
            ['remoto' => $this->abonos($usuarioId, $ini, $fim, 'remoto'), 'entrega' => $this->abonos($usuarioId, $ini, $fim, 'entrega')]);
    }

    /** @return array<string, array> chave = data_referencia */
    private function abonos(int $usuarioId, string $ini, string $fim, string $tipo): array {
        $stmt = $this->db->prepare("
            SELECT a.id, a.data_referencia, a.justificativa, a.criado_em, u.nome AS abonado_por
            FROM atividades_abonos a LEFT JOIN usuarios u ON u.id = a.criado_por
            WHERE a.usuario_id = ? AND a.tipo = ? AND a.data_referencia BETWEEN ? AND ?");
        $stmt->execute([$usuarioId, $tipo, $ini, $fim]);
        $abonos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $abonos[$r['data_referencia']] = [
                'abono_id'      => (int) $r['id'],
                'justificativa' => $r['justificativa'],
                'abonado_por'   => $r['abonado_por'],
                'abonado_em'    => $r['criado_em'],
            ];
        }
        return $abonos;
    }

    /**
     * Servidores do setor com alguma regra de envio (trabalho remoto ou entrega) e suas pendências.
     * Entram gestores e operadores ativos (quem registra atividades no Conecta).
     */
    public function statusSetor(int $setorId, string $ini, string $fim): array {
        $setor = $this->configSetor($setorId);
        $stmt = $this->db->prepare("
            SELECT u.id, u.nome
            FROM usuarios u LEFT JOIN atividades_config_usuario c ON c.usuario_id = u.id
            WHERE u.setor_id = ? AND u.ativo = 1 AND u.tipo IN ('gestor', 'operador')
              AND (COALESCE(c.trabalho_remoto, 1) = 1
                   OR c.entrega_dias <> ''
                   OR (c.entrega_dias IS NULL AND ? = 1))
            ORDER BY u.nome");
        $stmt->execute([$setorId, $setor['entrega_ativa'] ? 1 : 0]);

        $resultado = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $resultado[] = [
                'usuario_id'   => (int) $u['id'],
                'usuario_nome' => $u['nome'],
                'pendencias'   => $this->pendenciasUsuario((int) $u['id'], $ini, $fim),
            ];
        }
        return $resultado;
    }

    /**
     * Abona uma pendência. Recusa (false) se, nesse meio-tempo, o relatório foi enviado ou a cota da semana
     * foi atingida. $tipo: 'remoto' ou 'entrega'.
     */
    public function abonar(int $usuarioId, string $data, string $justificativa, int $criadoPor, string $tipo): bool {
        $tipo = $tipo === 'entrega' ? 'entrega' : 'remoto';
        $usuario = $this->configUsuario($usuarioId);
        $modo = $usuario['setor_id'] ? $this->configSetor($usuario['setor_id'])['modo'] : 'semanal';

        if ($tipo === 'entrega' || $modo === 'diario') {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM registros_atividades WHERE usuario_id = ? AND data_referencia = ? AND status = 'enviado'");
            $stmt->execute([$usuarioId, $data]);
            if ((int) $stmt->fetchColumn() > 0) return false;
        } else {
            $fimSemana = (new DateTimeImmutable($data))->modify('+6 days')->format('Y-m-d');
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM registros_atividades WHERE usuario_id = ? AND status = 'enviado' AND data_referencia BETWEEN ? AND ?");
            $stmt->execute([$usuarioId, $data, $fimSemana]);
            if ((int) $stmt->fetchColumn() >= (int) ($usuario['qtd_semana'] ?? 0)) return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO atividades_abonos (usuario_id, data_referencia, tipo, justificativa, criado_por)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE justificativa = VALUES(justificativa), criado_por = VALUES(criado_por)");
        return $stmt->execute([$usuarioId, $data, $tipo, $justificativa, $criadoPor]);
    }
}
