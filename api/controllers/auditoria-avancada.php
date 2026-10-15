<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['GET', 'POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarAdmin($user); // Auditoria: exclusiva de administradores

    $ehGestor = ($user['tipo'] ?? '') === 'gestor';
    $setorEscopo = $user['setor_id'] ?? 0;

    $database = new Database();
    $db = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $tipo = $_GET['tipo'] ?? 'geral';
        $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
        $limite = isset($_GET['limite']) ? (int)$_GET['limite'] : 50;
        $offset = ($pagina - 1) * $limite;

        $filtros = [];
        $params = [];

        // Gestor só vê ações praticadas por gente do próprio setor; admin vê tudo
        if ($ehGestor) {
            $filtros[] = "a.usuario_id IN (SELECT id FROM usuarios WHERE setor_id = :setor_id_escopo)";
            $params[':setor_id_escopo'] = $setorEscopo;
        }

        if (isset($_GET['acao'])) {
            $filtros[] = "a.acao = :acao";
            $params[':acao'] = $_GET['acao'];
        }
        
        if (isset($_GET['tabela'])) {
            $filtros[] = "a.tabela = :tabela";
            $params[':tabela'] = $_GET['tabela'];
        }
        
        if (isset($_GET['usuario_id'])) {
            $filtros[] = "a.usuario_id = :usuario_id";
            $params[':usuario_id'] = $_GET['usuario_id'];
        }
        
        if (isset($_GET['data_inicio'])) {
            $filtros[] = "DATE(a.criado_em) >= :data_inicio";
            $params[':data_inicio'] = $_GET['data_inicio'];
        }
        
        if (isset($_GET['data_fim'])) {
            $filtros[] = "DATE(a.criado_em) <= :data_fim";
            $params[':data_fim'] = $_GET['data_fim'];
        }
        
        if (isset($_GET['busca'])) {
            $filtros[] = "(a.dados_anteriores LIKE :busca OR a.dados_novos LIKE :busca OR u.nome LIKE :busca OR u.email LIKE :busca)";
            $params[':busca'] = '%' . $_GET['busca'] . '%';
        }
        
        $where = count($filtros) > 0 ? 'WHERE ' . implode(' AND ', $filtros) : '';
        
        // Auditoria Geral
        if ($tipo === 'geral') {
            // Contar total
            $queryCount = "SELECT COUNT(*) as total FROM auditoria a $where";
            $stmtCount = $db->prepare($queryCount);
            foreach ($params as $key => $value) {
                $stmtCount->bindValue($key, $value);
            }
            $stmtCount->execute();
            $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Buscar registros
            $query = "SELECT a.*, 
                             u.nome as usuario_nome, 
                             u.email as usuario_email,
                             CASE 
                                 WHEN a.acao IN ('ativar_usuario', 'desativar_usuario', 'excluir_usuario') THEN
                                     CONCAT(
                                         COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dados_anteriores, '$.nome')), ''),
                                         ' (',
                                         COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dados_anteriores, '$.email')), ''),
                                         ')'
                                     )
                                 ELSE NULL
                             END as usuario_afetado
                      FROM auditoria a
                      LEFT JOIN usuarios u ON a.usuario_id = u.id
                      $where
                      ORDER BY a.criado_em DESC
                      LIMIT :limite OFFSET :offset";
            
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'registros' => $registros,
                'total' => $total,
                'pagina' => $pagina,
                'total_paginas' => ceil($total / $limite)
            ]);
        }
        
        // Auditoria de Tickets
        elseif ($tipo === 'tickets') {
            $filtrosTicket = [];
            $paramsTicket = [];

            // Gestor só vê alterações de demandas do próprio setor; admin vê tudo
            if ($ehGestor) {
                $filtrosTicket[] = "t.setor_id = :setor_id_escopo";
                $paramsTicket[':setor_id_escopo'] = $setorEscopo;
            }

            if (isset($_GET['ticket_id'])) {
                $filtrosTicket[] = "at.ticket_id = :ticket_id";
                $paramsTicket[':ticket_id'] = $_GET['ticket_id'];
            }
            
            if (isset($_GET['usuario_id'])) {
                $filtrosTicket[] = "at.usuario_id = :usuario_id";
                $paramsTicket[':usuario_id'] = $_GET['usuario_id'];
            }
            
            if (isset($_GET['campo'])) {
                $filtrosTicket[] = "at.campo = :campo";
                $paramsTicket[':campo'] = $_GET['campo'];
            }
            
            if (isset($_GET['data_inicio'])) {
                $filtrosTicket[] = "DATE(at.criado_em) >= :data_inicio";
                $paramsTicket[':data_inicio'] = $_GET['data_inicio'];
            }
            
            if (isset($_GET['data_fim'])) {
                $filtrosTicket[] = "DATE(at.criado_em) <= :data_fim";
                $paramsTicket[':data_fim'] = $_GET['data_fim'];
            }
            
            $whereTicket = count($filtrosTicket) > 0 ? 'WHERE ' . implode(' AND ', $filtrosTicket) : '';
            
            // Contar total
            $queryCount = "SELECT COUNT(*) as total FROM auditoria_tickets at LEFT JOIN tickets t ON at.ticket_id = t.id $whereTicket";
            $stmtCount = $db->prepare($queryCount);
            foreach ($paramsTicket as $key => $value) {
                $stmtCount->bindValue($key, $value);
            }
            $stmtCount->execute();
            $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

            // Buscar registros
            $query = "SELECT at.*, u.nome as usuario_nome, t.titulo as ticket_titulo
                      FROM auditoria_tickets at
                      LEFT JOIN usuarios u ON at.usuario_id = u.id
                      LEFT JOIN tickets t ON at.ticket_id = t.id
                      $whereTicket
                      ORDER BY at.criado_em DESC
                      LIMIT :limite OFFSET :offset";
            
            $stmt = $db->prepare($query);
            foreach ($paramsTicket as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'registros' => $registros,
                'total' => $total,
                'pagina' => $pagina,
                'total_paginas' => ceil($total / $limite)
            ]);
        }
        
        // Auditoria de Login
        elseif ($tipo === 'login') {
            $filtrosLogin = [];
            $paramsLogin = [];

            // Gestor só vê logins de gente do próprio setor; admin vê tudo
            if ($ehGestor) {
                $filtrosLogin[] = "al.usuario_id IN (SELECT id FROM usuarios WHERE setor_id = :setor_id_escopo)";
                $paramsLogin[':setor_id_escopo'] = $setorEscopo;
            }

            if (isset($_GET['usuario_id'])) {
                $filtrosLogin[] = "al.usuario_id = :usuario_id";
                $paramsLogin[':usuario_id'] = $_GET['usuario_id'];
            }
            
            if (isset($_GET['acao'])) {
                $filtrosLogin[] = "al.acao = :acao";
                $paramsLogin[':acao'] = $_GET['acao'];
            }
            
            if (isset($_GET['data_inicio'])) {
                $filtrosLogin[] = "DATE(al.criado_em) >= :data_inicio";
                $paramsLogin[':data_inicio'] = $_GET['data_inicio'];
            }
            
            if (isset($_GET['data_fim'])) {
                $filtrosLogin[] = "DATE(al.criado_em) <= :data_fim";
                $paramsLogin[':data_fim'] = $_GET['data_fim'];
            }
            
            $whereLogin = count($filtrosLogin) > 0 ? 'WHERE ' . implode(' AND ', $filtrosLogin) : '';
            
            // Contar total
            $queryCount = "SELECT COUNT(*) as total FROM auditoria_login al $whereLogin";
            $stmtCount = $db->prepare($queryCount);
            foreach ($paramsLogin as $key => $value) {
                $stmtCount->bindValue($key, $value);
            }
            $stmtCount->execute();
            $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Buscar registros
            $query = "SELECT al.*, u.nome as usuario_nome
                      FROM auditoria_login al
                      LEFT JOIN usuarios u ON al.usuario_id = u.id
                      $whereLogin
                      ORDER BY al.criado_em DESC
                      LIMIT :limite OFFSET :offset";
            
            $stmt = $db->prepare($query);
            foreach ($paramsLogin as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'registros' => $registros,
                'total' => $total,
                'pagina' => $pagina,
                'total_paginas' => ceil($total / $limite)
            ]);
        }
        
        // Estatísticas
        elseif ($tipo === 'estatisticas') {
            // Gestor só vê estatísticas da gente/demandas do próprio setor; admin vê tudo
            $filtroAcao = $ehGestor ? "WHERE usuario_id IN (SELECT id FROM usuarios WHERE setor_id = :setor_id_escopo)" : "";
            $filtroAcaoA = $ehGestor ? "WHERE a.usuario_id IN (SELECT id FROM usuarios WHERE setor_id = :setor_id_escopo)" : "";
            $filtroLogin = $ehGestor
                ? "AND usuario_id IN (SELECT id FROM usuarios WHERE setor_id = :setor_id_escopo)"
                : "";
            $filtroTicketCampo = $ehGestor ? "WHERE t.setor_id = :setor_id_escopo" : "";

            // Ações por tipo
            $queryAcoes = "SELECT acao, COUNT(*) as total FROM auditoria $filtroAcao GROUP BY acao ORDER BY total DESC";
            $stmtAcoes = $db->prepare($queryAcoes);
            if ($ehGestor) $stmtAcoes->bindValue(':setor_id_escopo', $setorEscopo);
            $stmtAcoes->execute();
            $acoesPorTipo = $stmtAcoes->fetchAll(PDO::FETCH_ASSOC);

            // Ações por usuário
            $queryUsuarios = "SELECT u.nome, COUNT(*) as total FROM auditoria a
                             LEFT JOIN usuarios u ON a.usuario_id = u.id
                             $filtroAcaoA
                             GROUP BY a.usuario_id ORDER BY total DESC LIMIT 10";
            $stmtUsuarios = $db->prepare($queryUsuarios);
            if ($ehGestor) $stmtUsuarios->bindValue(':setor_id_escopo', $setorEscopo);
            $stmtUsuarios->execute();
            $acoesPorUsuario = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);

            // Logins por dia (últimos 30 dias)
            $queryLogins = "SELECT DATE(criado_em) as data,
                           SUM(CASE WHEN acao = 'login_sucesso' THEN 1 ELSE 0 END) as sucessos,
                           SUM(CASE WHEN acao = 'login_falha' THEN 1 ELSE 0 END) as falhas
                           FROM auditoria_login
                           WHERE criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY) $filtroLogin
                           GROUP BY DATE(criado_em) ORDER BY data DESC";
            $stmtLogins = $db->prepare($queryLogins);
            if ($ehGestor) $stmtLogins->bindValue(':setor_id_escopo', $setorEscopo);
            $stmtLogins->execute();
            $loginsPorDia = $stmtLogins->fetchAll(PDO::FETCH_ASSOC);

            // Alterações de tickets por campo
            $queryTickets = "SELECT at.campo, COUNT(*) as total FROM auditoria_tickets at
                            LEFT JOIN tickets t ON at.ticket_id = t.id
                            $filtroTicketCampo
                            GROUP BY at.campo ORDER BY total DESC";
            $stmtTickets = $db->prepare($queryTickets);
            if ($ehGestor) $stmtTickets->bindValue(':setor_id_escopo', $setorEscopo);
            $stmtTickets->execute();
            $alteracoesPorCampo = $stmtTickets->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'acoes_por_tipo' => $acoesPorTipo,
                'acoes_por_usuario' => $acoesPorUsuario,
                'logins_por_dia' => $loginsPorDia,
                'alteracoes_por_campo' => $alteracoesPorCampo
            ]);
        }
    }
    
    // Exportar para CSV
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['exportar'])) {
        $tipo = $_GET['tipo'] ?? 'geral';

        if ($tipo === 'geral') {
            $filtroExport = $ehGestor ? "WHERE a.usuario_id IN (SELECT id FROM usuarios WHERE setor_id = :setor_id_escopo)" : "";
            $query = "SELECT a.*,
                             u.nome as usuario_nome,
                             u.email as usuario_email,
                             CASE
                                 WHEN a.acao IN ('ativar_usuario', 'desativar_usuario', 'excluir_usuario') THEN
                                     CONCAT(
                                         COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dados_anteriores, '$.nome')), ''),
                                         ' (',
                                         COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dados_anteriores, '$.email')), ''),
                                         ')'
                                     )
                                 ELSE NULL
                             END as usuario_afetado
                      FROM auditoria a
                      LEFT JOIN usuarios u ON a.usuario_id = u.id
                      $filtroExport
                      ORDER BY a.criado_em DESC";
        } elseif ($tipo === 'tickets') {
            $filtroExport = $ehGestor ? "WHERE t.setor_id = :setor_id_escopo" : "";
            $query = "SELECT at.*, u.nome as usuario_nome, t.titulo as ticket_titulo
                      FROM auditoria_tickets at
                      LEFT JOIN usuarios u ON at.usuario_id = u.id
                      LEFT JOIN tickets t ON at.ticket_id = t.id
                      $filtroExport
                      ORDER BY at.criado_em DESC";
        } elseif ($tipo === 'login') {
            $filtroExport = $ehGestor ? "WHERE al.usuario_id IN (SELECT id FROM usuarios WHERE setor_id = :setor_id_escopo)" : "";
            $query = "SELECT al.*, u.nome as usuario_nome
                      FROM auditoria_login al
                      LEFT JOIN usuarios u ON al.usuario_id = u.id
                      $filtroExport
                      ORDER BY al.criado_em DESC";
        }

        $stmt = $db->prepare($query);
        if ($ehGestor) $stmt->bindValue(':setor_id_escopo', $setorEscopo);
        $stmt->execute();
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['registros' => $registros]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    error_log('[AUDITORIA-AVANCADA] Erro: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro ao consultar a auditoria']);
}
