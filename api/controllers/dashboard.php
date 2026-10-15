<?php
header('Content-Type: application/json');
header('Cache-Control: private, max-age=60'); // Cache no navegador por 60s para aliviar carga pesada de dezenas de SUM() e COUNT() do MySQL

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/sla-service.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/acesso-service.php';

AuthMiddleware::configurarCORS(['GET']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

// GET - Dados do dashboard
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();

    // Escopo por papel: admin vê tudo; gestor só o próprio setor; usuario/cliente só o que é seu.
    // Interpolado direto (não bindParam) pois setor_id/id vêm do JWT já validado, não de input do usuário.
    $tipo = $user['tipo'] ?? '';
    $filtroT = ''; // aplica-se a queries que usam alias "t" para tickets

    // Setor do recorte: o do gestor, ou o escolhido pelo admin no cabeçalho (?setor_id; vazio = todos)
    $setorFiltro = null;
    if ($tipo === 'gestor') {
        $setorFiltro = (int) ($user['setor_id'] ?? 0);
    } elseif ($tipo === 'admin') {
        $setorFiltro = AcessoService::setorEscopo($user);
    }

    if ($setorFiltro !== null) {
        $filtroT = ' AND t.setor_id = ' . $setorFiltro;
    } elseif ($tipo === 'operador') {
        $filtroT = ' AND (t.agente_id = ' . (int) $user['id'] . ' OR t.usuario_id = ' . (int) $user['id'] . ')';
    } elseif ($tipo === 'cliente') {
        $filtroT = ' AND t.usuario_id = ' . (int) $user['id'];
    }
    // admin sem setor escolhido: $filtroT permanece vazio (enxerga tudo)

    try {
        $dashboard = [];

        // Total de tickets (excluindo cancelados)
        $query = "SELECT COUNT(*) as total FROM tickets t WHERE t.status != 'cancelado'{$filtroT}";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['total_tickets'] = $stmt->fetch()['total'];

        // Tickets por status (apenas ativos)
        $query = "SELECT t.status, COUNT(*) as total FROM tickets t WHERE t.status NOT IN ('encerrado', 'cancelado'){$filtroT} GROUP BY t.status";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $status_data = $stmt->fetchAll();
        $dashboard['por_status'] = [];
        foreach ($status_data as $row) {
            $dashboard['por_status'][$row['status']] = $row['total'];
        }

        // Adicionar contagem de encerrados separadamente para o card (não aparece no gráfico)
        $query_encerrado = "SELECT COUNT(*) as total FROM tickets t WHERE t.status = 'encerrado'{$filtroT}";
        $stmt_encerrado = $db->prepare($query_encerrado);
        $stmt_encerrado->execute();
        $dashboard['total_encerrados'] = $stmt_encerrado->fetch()['total'];

        // Tickets por prioridade (apenas ativos)
        $query = "SELECT t.prioridade, COUNT(*) as total FROM tickets t WHERE t.status NOT IN ('encerrado', 'cancelado'){$filtroT} GROUP BY t.prioridade";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $prioridade_data = $stmt->fetchAll();
        $dashboard['por_prioridade'] = [];
        foreach ($prioridade_data as $row) {
            $dashboard['por_prioridade'][$row['prioridade']] = $row['total'];
        }

        // Top 5 categorias (excluindo cancelados)
        $query = "SELECT c.nome, COUNT(t.id) as total
                  FROM categorias c
                  LEFT JOIN tickets t ON c.id = t.categoria_id
                  WHERE t.status != 'cancelado'{$filtroT}
                  GROUP BY c.id, c.nome
                  ORDER BY total DESC
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['top_categorias'] = $stmt->fetchAll();

        // Top 5 solicitantes (excluindo cancelados)
        $query = "SELECT u.nome, COUNT(t.id) as total
                  FROM tickets t
                  JOIN usuarios u ON t.usuario_id = u.id
                  WHERE t.status != 'cancelado'{$filtroT}
                  GROUP BY u.id, u.nome
                  ORDER BY total DESC
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['top_solicitantes'] = $stmt->fetchAll();

        // Tickets por agente (apenas tickets ativos) — escopo aplicado na condição do LEFT JOIN,
        // não no WHERE, para não excluir agentes sem nenhum ticket no recorte (ficam com total 0)
        $filtroAgenteJoin = $setorFiltro !== null ? (' AND t.setor_id = ' . $setorFiltro) : '';
        $filtroAgenteWhere = $tipo === 'operador' ? (' AND u.id = ' . (int) $user['id']) : '';
        // Com recorte por setor, a lista de agentes é a equipe daquele setor
        if ($setorFiltro !== null) {
            $filtroAgenteWhere .= ' AND u.setor_id = ' . $setorFiltro;
        }
        $query = "SELECT u.nome, COUNT(t.id) as total
                  FROM usuarios u
                  LEFT JOIN tickets t ON u.id = t.agente_id AND t.status NOT IN ('encerrado', 'cancelado'){$filtroAgenteJoin}
                  WHERE u.tipo IN ('admin', 'gestor', 'operador') AND u.ativo = 1{$filtroAgenteWhere}
                  GROUP BY u.id, u.nome
                  ORDER BY total DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['por_agente'] = $stmt->fetchAll();

        // Tickets por mês (ano atual) - excluindo cancelados
        $query = "SELECT MONTH(t.criado_em) as mes, COUNT(*) as total
                  FROM tickets t
                  WHERE YEAR(t.criado_em) = YEAR(NOW()) AND t.status != 'cancelado'{$filtroT}
                  GROUP BY MONTH(t.criado_em)
                  ORDER BY mes";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $mes_data = $stmt->fetchAll();
        $dashboard['por_mes'] = [];
        foreach ($mes_data as $row) {
            $dashboard['por_mes'][$row['mes']] = $row['total'];
        }

        // Tickets por macrocategoria (excluindo cancelados)
        $query = "SELECT m.nome, COUNT(t.id) as quantidade
                  FROM macrocategorias m
                  LEFT JOIN categorias c ON m.id = c.macrocategoria_id
                  LEFT JOIN tickets t ON c.id = t.categoria_id
                  WHERE t.status != 'cancelado'{$filtroT}
                  GROUP BY m.id, m.nome
                  ORDER BY quantidade DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['ticketsPorMacrocategoria'] = $stmt->fetchAll();

        // Taxa de resolução (abertos vs fechados) - excluindo cancelados da conta total
        $query = "SELECT
                    SUM(CASE WHEN t.status = 'encerrado' THEN 1 ELSE 0 END) as fechados,
                    SUM(CASE WHEN t.status NOT IN ('encerrado', 'cancelado') THEN 1 ELSE 0 END) as abertos
                  FROM tickets t WHERE t.status != 'cancelado'{$filtroT}";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['taxa_resolucao'] = $stmt->fetch();

        // Tickets acumulados por mês (últimos 12 meses) - excluindo cancelados
        $query = "SELECT DATE_FORMAT(t.criado_em, '%Y-%m') as mes, COUNT(*) as total
                  FROM tickets t
                  WHERE t.criado_em >= DATE_SUB(NOW(), INTERVAL 12 MONTH) AND t.status != 'cancelado'{$filtroT}
                  GROUP BY DATE_FORMAT(t.criado_em, '%Y-%m')
                  ORDER BY mes";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['acumulados_mes'] = $stmt->fetchAll();

        // Tickets por dia da semana (excluindo cancelados)
        $query = "SELECT DAYOFWEEK(t.criado_em) as dia, COUNT(*) as total
                  FROM tickets t
                  WHERE t.status != 'cancelado'{$filtroT}
                  GROUP BY DAYOFWEEK(t.criado_em)
                  ORDER BY dia";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['por_dia_semana'] = $stmt->fetchAll();

        // Status por agente (apenas tickets ativos) - todos os status aguardando especificados
        $filtroStatusPorAgente = $tipo === 'operador' ? (' AND u.id = ' . (int) $user['id']) : '';
        $query = "SELECT u.nome as agente, t.status, COUNT(*) as total
                  FROM tickets t
                  JOIN usuarios u ON t.agente_id = u.id
                  WHERE u.tipo IN ('operador', 'gestor', 'admin') AND u.ativo = 1
                    AND t.status NOT IN ('encerrado', 'cancelado', 'resolvido'){$filtroT}{$filtroStatusPorAgente}
                  GROUP BY u.nome, t.status
                  ORDER BY u.nome, t.status";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['status_por_agente'] = $stmt->fetchAll();

        // Indicadores de SLA (excluindo cancelados)
        $query = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN t.status = 'encerrado' THEN 1 ELSE 0 END) as concluidos
                  FROM tickets t WHERE t.status != 'cancelado'{$filtroT}";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $sla_data = $stmt->fetch();
        $dashboard['sla_indicadores'] = [
            'total' => $sla_data['total'],
            'concluidos' => $sla_data['concluidos'],
            'taxa_conclusao' => $sla_data['total'] > 0 ? round(($sla_data['concluidos'] / $sla_data['total']) * 100, 1) : 0
        ];

        // Funil de conversão (exclui encerrado e cancelado)
        $query = "SELECT t.status, COUNT(*) as total FROM tickets t WHERE t.status NOT IN ('encerrado', 'cancelado'){$filtroT} GROUP BY t.status";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $funil_data = $stmt->fetchAll();
        $dashboard['funil_conversao'] = [];
        $aguardando_total = 0;
        foreach ($funil_data as $row) {
            if (in_array($row['status'], ['aguardando_resposta', 'aguardando_terceiros', 'aguardando_aprovacao'])) {
                $aguardando_total += $row['total'];
            } else {
                $dashboard['funil_conversao'][$row['status']] = $row['total'];
            }
        }
        $dashboard['funil_conversao']['aguardando'] = $aguardando_total;

        // First Contact Resolution (FCR)
        $query = "SELECT
                    COUNT(t.id) as total_resolvidos,
                    SUM(CASE WHEN (
                        SELECT COUNT(c.id)
                        FROM comentarios c
                        WHERE c.ticket_id = t.id AND c.usuario_id = t.usuario_id
                    ) = 0 THEN 1 ELSE 0 END) as resolvidos_primeiro_contato
                  FROM tickets t
                  WHERE t.status IN ('encerrado', 'resolvido'){$filtroT}";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $fcr_data = $stmt->fetch();

        $dashboard['fcr'] = [
            'total_resolvidos' => $fcr_data['total_resolvidos'] ?? 0,
            'resolvidos_primeiro_contato' => $fcr_data['resolvidos_primeiro_contato'] ?? 0,
            'taxa' => ($fcr_data['total_resolvidos'] > 0) ? round(($fcr_data['resolvidos_primeiro_contato'] / $fcr_data['total_resolvidos']) * 100, 1) : 0
        ];

        // Calcular tempos médios em HORAS ÚTEIS (valores padrão)
        $dashboard['tempoPrimeiroAtendimentoSegundos'] = 0;
        $dashboard['tempoMedioSegundos'] = 0;

        try {
            $slaService = new SLAService($db);

            // Tempo médio de primeira resposta (considera atribuição, mudança de status ou comentário)
            $query = "SELECT t.id, t.criado_em, t.setor_id,
LEAST(
                          COALESCE((SELECT MIN(h.criado_em) FROM ticket_historico h
                                    WHERE h.ticket_id = t.id
                                    AND h.acao IN ('atribuicao', 'status')), '9999-12-31'),
                          COALESCE((SELECT MIN(c.criado_em) FROM comentarios c
                                    JOIN usuarios u ON c.usuario_id = u.id
                                    WHERE c.ticket_id = t.id
                                    AND u.tipo IN ('operador', 'gestor', 'admin')), '9999-12-31')
                      ) as primeiro_atendimento
                      FROM tickets t
                      WHERE t.status != 'cancelado'{$filtroT}
                      HAVING primeiro_atendimento < '9999-12-31'";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $tempos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($tempos) > 0) {
                $soma = 0;
                foreach ($tempos as $tempo) {
                    // Usar SLAService PHP para calcular segundos úteis
                    $segundosUteis = $slaService->calcularSegundosUteis($tempo['criado_em'], $tempo['primeiro_atendimento'], $tempo['setor_id']);
                    $soma += $segundosUteis;
                }
                $dashboard['tempoPrimeiroAtendimentoSegundos'] = round($soma / count($tempos));
            }

            // Tempo médio total (abertura até fechamento em horas úteis)
            $query = "SELECT t.criado_em, t.fechado_em, t.setor_id
                      FROM tickets t
                      WHERE t.status = 'encerrado' AND t.fechado_em IS NOT NULL{$filtroT}";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $tickets_fechados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($tickets_fechados) > 0) {
                $soma = 0;
                foreach ($tickets_fechados as $ticket) {
                    // Usar SLAService PHP para calcular segundos úteis
                    $segundosUteis = $slaService->calcularSegundosUteis($ticket['criado_em'], $ticket['fechado_em'], $ticket['setor_id']);
                    $soma += $segundosUteis;
                }
                $dashboard['tempoMedioSegundos'] = round($soma / count($tickets_fechados));
            }
        } catch (Exception $e) {
            // Manter valores padrão em caso de erro
        }

        // Tickets com SLA vencido (resposta ou resolução, apenas ativos)
        $query = "SELECT COUNT(DISTINCT t.id) as total
                  FROM tickets t
                  JOIN ticket_sla ts ON t.id = ts.ticket_id
                  WHERE t.status NOT IN ('encerrado', 'cancelado')
                    AND (ts.sla_resposta_vencido = 1 OR ts.sla_resolucao_vencido = 1){$filtroT}";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['sla_vencido'] = (int) $stmt->fetch()['total'];

        // Tickets via origem externa (não 'interna'), excluindo cancelados
        $query = "SELECT COUNT(*) as total
                  FROM tickets t
                  WHERE t.origem_api IS NOT NULL AND t.origem_api != 'interna'
                    AND t.status != 'cancelado'{$filtroT}";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['total_origem_externa'] = (int) $stmt->fetch()['total'];

        // Evolução diária — últimos 30 dias, excluindo cancelados
        $query = "SELECT DATE(t.criado_em) as dia, COUNT(*) as total
                  FROM tickets t
                  WHERE t.criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                    AND t.status != 'cancelado'{$filtroT}
                  GROUP BY DATE(t.criado_em)
                  ORDER BY dia";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['diarios_30d'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Status empilhado por mês (ano atual), excluindo cancelados
        $query = "SELECT MONTH(t.criado_em) as mes, t.status, COUNT(*) as total
                  FROM tickets t
                  WHERE YEAR(t.criado_em) = YEAR(NOW()) AND t.status != 'cancelado'{$filtroT}
                  GROUP BY MONTH(t.criado_em), t.status
                  ORDER BY mes, t.status";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['status_por_mes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Heatmap: dia da semana × hora do dia (excluindo cancelados)
        $query = "SELECT DAYOFWEEK(t.criado_em) as dia, HOUR(t.criado_em) as hora, COUNT(*) as total
                  FROM tickets t
                  WHERE t.status != 'cancelado'{$filtroT}
                  GROUP BY DAYOFWEEK(t.criado_em), HOUR(t.criado_em)
                  ORDER BY dia, hora";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['heatmap_dia_hora'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($dashboard);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar dados do dashboard']);
    }
}
?>
