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
        // Datas só no formato AAAA-MM-DD (o resto é ignorado)
        $dataValida = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        $dataInicio = $dataValida($_GET['dataInicio'] ?? null);
        $dataFim = $dataValida($_GET['dataFim'] ?? null);
        $status = $_GET['status'] ?? 'todos';
        $prioridade = $_GET['prioridade'] ?? 'todas';
        $categoria = $_GET['categoria'] ?? 'todas';
        $agente = $_GET['agente'] ?? 'todos';

        // Query base
        // Só as colunas que o relatório usa (sem a descrição, que pode ter imagens em base64) e os
        // tempos de SLA numa consulta só (antes: uma consulta de primeira resposta por demanda)
        $query = "SELECT t.id, t.protocolo, t.titulo, t.status, t.prioridade, t.criado_em, t.fechado_em,
                  t.setor_id, t.agente_id, t.usuario_id, t.categoria_id, t.mesclada_em,
                  u.nome as usuario_nome, u.email as usuario_email,
                  a.nome as agente_nome,
                  NULLIF(CONCAT_WS(' - ', c.nome, mc.nome), '') as categoria_nome,
                  ts.primeira_resposta_em, ts.tempo_primeira_resposta_segundos,
                  ts.resolvido_em, ts.tempo_resolucao_segundos
                  FROM tickets t
                  LEFT JOIN usuarios u ON t.usuario_id = u.id
                  LEFT JOIN usuarios a ON t.agente_id = a.id
                  LEFT JOIN categorias c ON t.categoria_id = c.id
                  LEFT JOIN macrocategorias mc ON c.macrocategoria_id = mc.id
                  LEFT JOIN ticket_sla ts ON ts.ticket_id = t.id
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

        // Estatísticas com as mesmas regras do dashboard: concluída = resolvido/encerrado
        // (AcessoService::STATUS_CONCLUIDOS), mesclada não conta, tempos de ticket_sla
        $totalTickets = count($tickets);
        $concluidas = array_values(array_filter($tickets, function ($t) {
            return in_array($t['status'], AcessoService::STATUS_CONCLUIDOS, true) && empty($t['mesclada_em']);
        }));
        $ticketsEncerradosCount = count($concluidas);

        $tempoMedioSegundos = 0;
        $tempoPrimeiroAtendimentoSegundos = 0;
        try {
            $slaService = new SLAService($db);
            $semMescladas = array_filter($tickets, fn($t) => empty($t['mesclada_em']));
            $tempoPrimeiroAtendimentoSegundos = $slaService->mediaPrimeiraResposta($semMescladas);
            $tempoMedioSegundos = $slaService->mediaResolucao($concluidas);
        } catch (Exception $e) {
            error_log('[RELATORIOS] Erro ao calcular tempos médios: ' . $e->getMessage());
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
