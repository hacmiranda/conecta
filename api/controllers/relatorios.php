<?php
header('Content-Type: application/json');

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

// GET - Gerar relatório com filtros
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();

    try {
        // Parâmetros de filtro
        $dataInicio = $_GET['dataInicio'] ?? null;
        $dataFim = $_GET['dataFim'] ?? null;
        $status = $_GET['status'] ?? 'todos';
        $prioridade = $_GET['prioridade'] ?? 'todas';
        $categoria = $_GET['categoria'] ?? 'todas';
        $agente = $_GET['agente'] ?? 'todos';
        
        // Query base
        $query = "SELECT t.*, 
                  u.nome as usuario_nome, u.email as usuario_email,
                  a.nome as agente_nome,
                  c.nome as categoria_nome
                  FROM tickets t
                  LEFT JOIN usuarios u ON t.usuario_id = u.id
                  LEFT JOIN usuarios a ON t.agente_id = a.id
                  LEFT JOIN categorias c ON t.categoria_id = c.id
                  WHERE t.excluido_em IS NULL";
        
        $params = [];
        
        // Filtro de data
        if ($dataInicio) {
            $query .= " AND DATE(t.criado_em) >= :dataInicio";
            $params[':dataInicio'] = $dataInicio;
        }
        if ($dataFim) {
            $query .= " AND DATE(t.criado_em) <= :dataFim";
            $params[':dataFim'] = $dataFim;
        }
        
        // Filtro de status
        if ($status !== 'todos') {
            $query .= " AND t.status = :status";
            $params[':status'] = $status;
        }
        
        // Filtro de prioridade
        if ($prioridade !== 'todas') {
            $query .= " AND t.prioridade = :prioridade";
            $params[':prioridade'] = $prioridade;
        }
        
        // Filtro de categoria
        if ($categoria !== 'todas') {
            $query .= " AND t.categoria_id = :categoria";
            $params[':categoria'] = $categoria;
        }
        
        // Filtro de agente
        if ($agente !== 'todos') {
            $query .= " AND t.agente_id = :agente";
            $params[':agente'] = $agente;
        }

        // Escopo por papel: admin vê tudo; gestor só o próprio setor; usuario/cliente só o que é seu
        $tipo = $user['tipo'] ?? '';
        // Admin: recorte pelo setor escolhido no cabeçalho (?setor_id; vazio = todos os setores)
        $setorAdmin = $tipo === 'admin' ? AcessoService::setorEscopo($user) : null;
        if ($tipo === 'gestor') {
            $query .= " AND t.setor_id = :setor_id_escopo";
            $params[':setor_id_escopo'] = $user['setor_id'] ?? 0;
        } elseif ($setorAdmin !== null) {
            $query .= " AND t.setor_id = :setor_id_escopo";
            $params[':setor_id_escopo'] = $setorAdmin;
        } elseif ($tipo === 'operador') {
            $query .= " AND (t.agente_id = :self_id_escopo OR t.usuario_id = :self_id_escopo2)";
            $params[':self_id_escopo'] = $user['id'];
            $params[':self_id_escopo2'] = $user['id'];
        } elseif ($tipo === 'cliente') {
            $query .= " AND t.usuario_id = :self_id_escopo";
            $params[':self_id_escopo'] = $user['id'];
        }

        $query .= " ORDER BY t.criado_em DESC";
        
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular estatísticas
        $totalTickets = count($tickets);
        $ticketsEncerradosCount = count(array_filter($tickets, function($t) {
            return $t['status'] === 'encerrado';
        }));
        
        // Calcular tempo médio usando horas úteis (apenas dos tickets filtrados)
        $tempoMedioSegundos = 0;
        $tempoPrimeiroAtendimentoSegundos = 0;
        
        try {
            $slaService = new SLAService($db);
            
            // Tempo médio de primeira resposta (apenas tickets filtrados)
            $ticketsComResposta = [];
            foreach ($tickets as $ticket) {
                // Buscar primeira resposta de agente para este ticket
                $queryPrimeiraResposta = "SELECT MIN(c.criado_em) as primeira_resposta
                                         FROM comentarios c
                                         INNER JOIN usuarios u ON c.usuario_id = u.id
                                         WHERE c.ticket_id = :ticket_id AND u.tipo IN ('operador', 'gestor', 'admin')";
                $stmtPR = $db->prepare($queryPrimeiraResposta);
                $stmtPR->bindParam(':ticket_id', $ticket['id']);
                $stmtPR->execute();
                $resultado = $stmtPR->fetch(PDO::FETCH_ASSOC);
                
                if ($resultado['primeira_resposta']) {
                    $ticketsComResposta[] = [
                        'criado_em' => $ticket['criado_em'],
                        'primeira_resposta' => $resultado['primeira_resposta'],
                        'setor_id' => $ticket['setor_id'] ?? null
                    ];
                }
            }
            
            if (count($ticketsComResposta) > 0) {
                $soma = 0;
                foreach ($ticketsComResposta as $tempo) {
                    // Usar SLAService PHP para calcular segundos úteis
                    $segundosUteis = $slaService->calcularSegundosUteis($tempo['criado_em'], $tempo['primeira_resposta'], $tempo['setor_id']);
                    $soma += $segundosUteis;
                }
                $tempoPrimeiroAtendimentoSegundos = round($soma / count($ticketsComResposta));
            }
            
            // Tempo médio de resolução (apenas tickets encerrados filtrados)
            $ticketsEncerradosFiltrados = array_filter($tickets, function($t) {
                return $t['status'] === 'encerrado' && $t['fechado_em'] !== null;
            });
            
            if (count($ticketsEncerradosFiltrados) > 0) {
                $soma = 0;
                foreach ($ticketsEncerradosFiltrados as $ticket) {
                    // Usar SLAService PHP para calcular segundos úteis
                    $segundosUteis = $slaService->calcularSegundosUteis($ticket['criado_em'], $ticket['fechado_em'], $ticket['setor_id'] ?? null);
                    $soma += $segundosUteis;
                }
                $tempoMedioSegundos = round($soma / count($ticketsEncerradosFiltrados));
            }
        } catch (Exception $e) {
            // Manter valores padrão em caso de erro
        }
        
        $resultado = [
            'tickets' => $tickets,
            'estatisticas' => [
                'totalTickets' => $totalTickets,
                'ticketsEncerrados' => $ticketsEncerradosCount,
                'tempoMedioSegundos' => $tempoMedioSegundos,
                'tempoMedioPrimeiroAtendimentoSegundos' => $tempoPrimeiroAtendimentoSegundos
            ]
        ];
        
        echo json_encode($resultado);
        
    } catch (Exception $e) {
        http_response_code(500);
        error_log('[RELATORIOS] Erro: ' . $e->getMessage());
        echo json_encode(['error' => 'Erro ao gerar relatório']);
    }
}
?>
