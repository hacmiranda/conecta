<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/sla-service.php';
// Remover autenticação temporariamente para debug
// require_once __DIR__ . '/../middleware/auth.php';

$database = new Database();
$db = $database->getConnection();

// GET - Dados do dashboard
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // $user = AuthMiddleware::verificarAuth();
    
    try {
        $dashboard = [];
        
        // Total de tickets (excluindo cancelados)
        $query = "SELECT COUNT(*) as total FROM tickets WHERE status != 'cancelado'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['total_tickets'] = $stmt->fetch()['total'];
        
        // Tickets por status (apenas ativos)
        $query = "SELECT status, COUNT(*) as total FROM tickets WHERE status NOT IN ('encerrado', 'cancelado') GROUP BY status";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $status_data = $stmt->fetchAll();
        $dashboard['por_status'] = [];
        foreach ($status_data as $row) {
            $dashboard['por_status'][$row['status']] = $row['total'];
        }
        
        // Adicionar contagem de encerrados separadamente para o card (não aparece no gráfico)
        $query_encerrado = "SELECT COUNT(*) as total FROM tickets WHERE status = 'encerrado'";
        $stmt_encerrado = $db->prepare($query_encerrado);
        $stmt_encerrado->execute();
        $dashboard['total_encerrados'] = $stmt_encerrado->fetch()['total'];
        
        // Tickets por prioridade (apenas ativos)
        $query = "SELECT prioridade, COUNT(*) as total FROM tickets WHERE status NOT IN ('encerrado', 'cancelado') GROUP BY prioridade";
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
                  WHERE t.status != 'cancelado'
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
                  WHERE t.status != 'cancelado' 
                  GROUP BY u.id, u.nome 
                  ORDER BY total DESC 
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['top_solicitantes'] = $stmt->fetchAll();
        
        // Tickets por agente (apenas tickets ativos)
        $query = "SELECT u.nome, COUNT(t.id) as total 
                  FROM usuarios u 
                  LEFT JOIN tickets t ON u.id = t.agente_id AND t.status NOT IN ('encerrado', 'cancelado')
                  WHERE u.tipo IN ('admin', 'agente') AND u.ativo = 1
                  GROUP BY u.id, u.nome 
                  ORDER BY total DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['por_agente'] = $stmt->fetchAll();
        
        // Tickets por mês (ano atual) - excluindo cancelados
        $query = "SELECT MONTH(criado_em) as mes, COUNT(*) as total 
                  FROM tickets 
                  WHERE YEAR(criado_em) = YEAR(NOW()) AND status != 'cancelado'
                  GROUP BY MONTH(criado_em) 
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
                  WHERE t.status != 'cancelado'
                  GROUP BY m.id, m.nome 
                  ORDER BY quantidade DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['ticketsPorMacrocategoria'] = $stmt->fetchAll();
        
        // Taxa de resolução (abertos vs fechados) - excluindo cancelados da conta total
        $query = "SELECT 
                    SUM(CASE WHEN status = 'encerrado' THEN 1 ELSE 0 END) as fechados,
                    SUM(CASE WHEN status NOT IN ('encerrado', 'cancelado') THEN 1 ELSE 0 END) as abertos
                  FROM tickets WHERE status != 'cancelado'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['taxa_resolucao'] = $stmt->fetch();
        
        // Tickets acumulados por mês (últimos 12 meses) - excluindo cancelados
        $query = "SELECT DATE_FORMAT(criado_em, '%Y-%m') as mes, COUNT(*) as total
                  FROM tickets
                  WHERE criado_em >= DATE_SUB(NOW(), INTERVAL 12 MONTH) AND status != 'cancelado'
                  GROUP BY DATE_FORMAT(criado_em, '%Y-%m')
                  ORDER BY mes";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['acumulados_mes'] = $stmt->fetchAll();
        
        // Tickets por dia da semana (excluindo cancelados)
        $query = "SELECT DAYOFWEEK(criado_em) as dia, COUNT(*) as total
                  FROM tickets
                  WHERE status != 'cancelado'
                  GROUP BY DAYOFWEEK(criado_em)
                  ORDER BY dia";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['por_dia_semana'] = $stmt->fetchAll();
        
        // Status por agente (apenas tickets ativos) - todos os status aguardando especificados
        $query = "SELECT u.nome as agente, t.status, COUNT(*) as total
                  FROM tickets t
                  JOIN usuarios u ON t.agente_id = u.id
                  WHERE u.tipo IN ('agente', 'admin') AND u.ativo = 1 
                    AND t.status NOT IN ('encerrado', 'cancelado', 'resolvido')
                  GROUP BY u.nome, t.status
                  ORDER BY u.nome, t.status";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $dashboard['status_por_agente'] = $stmt->fetchAll();
        
        // Indicadores de SLA (excluindo cancelados)
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'encerrado' THEN 1 ELSE 0 END) as concluidos
                  FROM tickets WHERE status != 'cancelado'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $sla_data = $stmt->fetch();
        $dashboard['sla_indicadores'] = [
            'total' => $sla_data['total'],
            'concluidos' => $sla_data['concluidos'],
            'taxa_conclusao' => $sla_data['total'] > 0 ? round(($sla_data['concluidos'] / $sla_data['total']) * 100, 1) : 0
        ];
        
        // Funil de conversão (exclui encerrado e cancelado)
        $query = "SELECT status, COUNT(*) as total FROM tickets WHERE status NOT IN ('encerrado', 'cancelado') GROUP BY status";
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
        
        // Calcular tempos médios em HORAS ÚTEIS (valores padrão)
        $dashboard['tempoPrimeiroAtendimentoSegundos'] = 0;
        $dashboard['tempoMedioSegundos'] = 0;
        
        try {
            $slaService = new SLAService($db);
            
            // Tempo médio de primeira resposta (considera atribuição, mudança de status ou comentário)
            $query = "SELECT t.id, t.criado_em,
                      LEAST(
                          COALESCE((SELECT MIN(h.criado_em) FROM ticket_historico h 
                                    WHERE h.ticket_id = t.id 
                                    AND h.acao IN ('atribuicao', 'status')), '9999-12-31'),
                          COALESCE((SELECT MIN(c.criado_em) FROM comentarios c 
                                    JOIN usuarios u ON c.usuario_id = u.id 
                                    WHERE c.ticket_id = t.id 
                                    AND u.tipo IN ('agente', 'admin')), '9999-12-31')
                      ) as primeiro_atendimento
                      FROM tickets t
                      WHERE t.status != 'cancelado'
                      HAVING primeiro_atendimento < '9999-12-31'";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $tempos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($tempos) > 0) {
                $soma = 0;
                foreach ($tempos as $tempo) {
                    // Usar SLAService PHP para calcular segundos úteis
                    $segundosUteis = $slaService->calcularSegundosUteis($tempo['criado_em'], $tempo['primeiro_atendimento']);
                    $soma += $segundosUteis;
                }
                $dashboard['tempoPrimeiroAtendimentoSegundos'] = round($soma / count($tempos));
            }
            
            // Tempo médio total (abertura até fechamento em horas úteis)
            $query = "SELECT criado_em, fechado_em
                      FROM tickets
                      WHERE status = 'encerrado' AND fechado_em IS NOT NULL";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $tickets_fechados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($tickets_fechados) > 0) {
                $soma = 0;
                foreach ($tickets_fechados as $ticket) {
                    // Usar SLAService PHP para calcular segundos úteis
                    $segundosUteis = $slaService->calcularSegundosUteis($ticket['criado_em'], $ticket['fechado_em']);
                    $soma += $segundosUteis;
                }
                $dashboard['tempoMedioSegundos'] = round($soma / count($tickets_fechados));
            }
        } catch (Exception $e) {
            // Manter valores padrão em caso de erro
        }
        
        echo json_encode($dashboard);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar dados do dashboard']);
    }
}
?>