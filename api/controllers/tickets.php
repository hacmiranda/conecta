<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/auditoria-service.php';
require_once __DIR__ . '/../services/sla-service.php';
require_once __DIR__ . '/../services/acesso-service.php';
require_once __DIR__ . '/../services/timeline-service.php';
require_once __DIR__ . '/../services/html-sanitizer.php';
require_once __DIR__ . '/../services/lembrete-service.php';
require_once __DIR__ . '/../services/catalogo-service.php';
require_once __DIR__ . '/../services/demanda-service.php';

AuthMiddleware::configurarCORS(['GET', 'POST', 'PUT', 'DELETE']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

/**
 * Preenche o campo "sla" (prazo de resposta/resolução restante) de cada linha vinda das queries de
 * listagem/detalhe, que já trazem sla_primeira_resposta_em/sla_resolvido_em/sla_tempo_pausado_segundos
 * via LEFT JOIN ticket_sla. Demandas arquivadas (encerrada/cancelada/fechado) ficam com sla = null.
 */
function anexarSla(array $tickets, PDO $db): array {
    $slaService = new SLAService($db);
    foreach ($tickets as &$ticket) {
        $ticket['sla'] = in_array($ticket['status'], AcessoService::STATUS_ARQUIVO, true) ? null : $slaService->situacao(
            $ticket,
            [
                'primeira_resposta_em' => $ticket['sla_primeira_resposta_em'] ?? null,
                'resolvido_em' => $ticket['sla_resolvido_em'] ?? null,
                'tempo_pausado_segundos' => $ticket['sla_tempo_pausado_segundos'] ?? 0,
                'pausado_em' => $ticket['sla_pausado_em'] ?? null,
            ],
            !empty($ticket['setor_id']) ? (int) $ticket['setor_id'] : null
        );
    }
    unset($ticket);
    return $tickets;
}

/**
 * Listagem paginada: aplica busca, filtros e ordenação no servidor e devolve também os contadores
 * da visão (sem os filtros do usuário). O SELECT pesado (subconsultas por linha) roda só nas linhas da página.
 */
function listarTicketsPaginado(PDO $db, string $selectCompleto, array $where, array $params, string $escopo): array {
    $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
    $por_pagina = (int) ($_GET['por_pagina'] ?? 50);
    if (!in_array($por_pagina, [10, 25, 50, 100], true)) {
        $por_pagina = 50;
    }

    $usuario_id = $params[':usuario_id'];
    unset($params[':usuario_id']);   // só a junção de comentários não lidos usa; ela fica fora das consultas de contagem

    $grupos = [
        'novo'           => ['novo'],
        'em_atendimento' => ['em_atendimento'],
        'aguardando'     => ['aguardando', 'aguardando_resposta', 'aguardando_terceiros', 'aguardando_aprovacao', 'aguardando_data'],
        'nova_resposta'  => ['nova_resposta'],
    ];

    $juncoes = " FROM tickets t
                 LEFT JOIN usuarios u ON t.usuario_id = u.id
                 LEFT JOIN usuarios a ON t.agente_id = a.id
                 LEFT JOIN categorias c ON t.categoria_id = c.id
                 LEFT JOIN macrocategorias mc ON c.macrocategoria_id = mc.id
                 LEFT JOIN setores s ON t.setor_id = s.id";
    $base = implode(' AND ', $where ?: ['1 = 1']);

    // Contadores da visão (independem de busca, aba e "minhas")
    $stmt = $db->prepare("SELECT COUNT(*) AS todos,
                                 COALESCE(SUM(t.agente_id = :c_self), 0) AS meus,
                                 COALESCE(SUM(t.status = 'novo'), 0) AS novo,
                                 COALESCE(SUM(t.status = 'em_atendimento'), 0) AS em_atendimento,
                                 COALESCE(SUM(t.status IN ('aguardando','aguardando_resposta','aguardando_terceiros','aguardando_aprovacao','aguardando_data')), 0) AS aguardando,
                                 COALESCE(SUM(t.status = 'nova_resposta'), 0) AS nova_resposta,
                                 COALESCE(SUM(t.prioridade = 'urgente'), 0) AS urgente
                          FROM tickets t WHERE $base");
    $stmt->execute($params + [':c_self' => $usuario_id]);
    $contadores = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));

    // Filtros escolhidos pelo usuário
    $filtros = [];
    $pf = [];
    $grupo = (string) ($_GET['grupo'] ?? '');
    if ($grupo === 'urgente') {
        $filtros[] = "t.prioridade = 'urgente'";
    } elseif (isset($grupos[$grupo])) {
        $marcas = [];
        foreach ($grupos[$grupo] as $i => $st) {
            $marcas[] = ":g$i";
            $pf[":g$i"] = $st;
        }
        $filtros[] = "t.status IN (" . implode(',', $marcas) . ")";
    }
    if (!empty($_GET['status']) && is_string($_GET['status'])) {
        $filtros[] = "t.status = :f_status";
        $pf[':f_status'] = $_GET['status'];
    }
    if (!empty($_GET['prioridade']) && is_string($_GET['prioridade'])) {
        $filtros[] = "t.prioridade = :f_prioridade";
        $pf[':f_prioridade'] = $_GET['prioridade'];
    }
    if (!empty($_GET['meus'])) {
        $filtros[] = "t.agente_id = :f_meus";
        $pf[':f_meus'] = $usuario_id;
    }
    $busca = trim((string) ($_GET['busca'] ?? ''));
    if ($busca !== '') {
        $termo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($busca, 0, 100)) . '%';
        $numero = '%' . ltrim(str_replace('#', '', $busca), '0') . '%';
        $filtros[] = "(t.titulo LIKE :b1 OR t.descricao LIKE :b2 OR u.nome LIKE :b3 OR t.email_solicitante LIKE :b4
                       OR (:b6 <> '%%' AND CAST(t.id AS CHAR) LIKE :b5))";
        $pf[':b1'] = $pf[':b2'] = $pf[':b3'] = $pf[':b4'] = $termo;
        $pf[':b5'] = $numero;
        $pf[':b6'] = $numero;
    }
    $onde = $base . ($filtros ? ' AND ' . implode(' AND ', $filtros) : '');

    // Ordenação: só colunas conhecidas
    $colunas = [
        'id' => 't.id', 'criado_em' => 't.criado_em', 'titulo' => 't.titulo', 'usuario_nome' => 'u.nome',
        'setor_nome' => 's.nome', 'agente_nome' => 'a.nome', 'categoria_nome' => "CONCAT_WS(' - ', c.nome, mc.nome)",
        'status' => 't.status', 'prioridade' => 't.prioridade', 'fechado_em' => 't.fechado_em',
        'excluido_em' => 't.excluido_em',
    ];
    $padrao = $escopo === 'lixeira' ? 't.excluido_em' : 't.criado_em';
    $ordem = is_string($_GET['ordem'] ?? null) ? $_GET['ordem'] : '';
    $coluna = $colunas[$ordem] ?? $padrao;
    $direcao = isset($colunas[$ordem]) && strtolower((string) ($_GET['direcao'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

    $stmt = $db->prepare("SELECT COUNT(*) $juncoes WHERE $onde");
    $stmt->execute($params + $pf);
    $total = (int) $stmt->fetchColumn();

    $total_paginas = max(1, (int) ceil($total / $por_pagina));
    $pagina = min($pagina, $total_paginas);
    $deslocamento = ($pagina - 1) * $por_pagina;

    $stmt = $db->prepare("SELECT t.id $juncoes WHERE $onde ORDER BY $coluna $direcao, t.id DESC
                          LIMIT $por_pagina OFFSET $deslocamento");
    $stmt->execute($params + $pf);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $itens = [];
    if ($ids) {
        $stmt = $db->prepare($selectCompleto . " WHERE t.id IN (" . implode(',', $ids) . ")");
        $stmt->execute([':usuario_id' => $usuario_id]);
        $por_id = [];
        foreach (anexarSla($stmt->fetchAll(), $db) as $linha) {
            $por_id[(int) $linha['id']] = $linha;
        }
        foreach ($ids as $id) {
            if (isset($por_id[$id])) {
                $itens[] = $por_id[$id];
            }
        }
    }

    return [
        'itens' => $itens,
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $por_pagina,
        'total_paginas' => $total_paginas,
        'contadores' => $contadores,
    ];
}

// GET - Listar tickets ou buscar ticket específico
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = AuthMiddleware::verificarAuth();
    $ticket_id = $_GET['id'] ?? null;

    try {
        if ($ticket_id) {
            // Buscar ticket específico
            $query = "SELECT t.*,
                             u.nome as usuario_nome, u.email as usuario_email,
                             a.nome as agente_nome,
                             NULLIF(CONCAT_WS(' - ', c.nome, mc.nome), '') as categoria_nome,
                             f.nome as fechado_por_nome,
                             ex.nome as excluido_por_nome,
                             st.nome as setor_nome,
                             sla.primeira_resposta_em as sla_primeira_resposta_em,
                             sla.resolvido_em as sla_resolvido_em,
                             sla.tempo_pausado_segundos as sla_tempo_pausado_segundos,
                             sla.pausado_em as sla_pausado_em,
                             (SELECT COUNT(*) FROM ticket_historico WHERE ticket_id = t.id AND acao = 'merge') as eh_mesclado,
                             (SELECT valor_novo FROM ticket_historico WHERE ticket_id = t.id AND acao = 'merge' LIMIT 1) as tickets_mesclados
                      FROM tickets t
                      LEFT JOIN usuarios u ON t.usuario_id = u.id
                      LEFT JOIN usuarios a ON t.agente_id = a.id
                      LEFT JOIN categorias c ON t.categoria_id = c.id
                      LEFT JOIN macrocategorias mc ON c.macrocategoria_id = mc.id
                      LEFT JOIN usuarios f ON t.fechado_por = f.id
                      LEFT JOIN usuarios ex ON t.excluido_por = ex.id
                      LEFT JOIN setores st ON t.setor_id = st.id
                      LEFT JOIN ticket_sla sla ON sla.ticket_id = t.id
                      WHERE t.id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket) {
                http_response_code(404);
                echo json_encode(['error' => 'Demanda não encontrada']);
                exit;
            }

            // Leitura: quem vê a demanda hoje ou quem passou por ela (histórico). Lixeira: só o admin.
            if (!AcessoService::podeLerTicket($db, $user, $ticket)) {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso não autorizado a esta demanda']);
                exit;
            }
            $ticket['na_lixeira'] = !empty($ticket['excluido_em']);
            $ticket['acesso_historico'] = !AcessoService::podeVerTicket($user, $ticket);   // só leitura do histórico
            $ticket['somente_leitura'] = !AcessoService::podeOperarTicket($user, $ticket); // não pode alterar

            // SLA calculado no servidor (prazo de resposta/resolução restante, só demandas abertas)
            $ticket = anexarSla([$ticket], $db)[0];

            // Linha do tempo cronológica (comentários + histórico + abertura)
            $dados_timeline = TimelineService::montar($db, (int) $ticket_id);

            // Anexos do próprio ticket (os dos comentários vêm dentro de cada comentário)
            $anexos_query = "SELECT id, ticket_id, nome_original, nome_arquivo, tamanho, tipo, criado_em FROM anexos WHERE ticket_id = :ticket_id AND comentario_id IS NULL ORDER BY criado_em ASC";
            $anexos_stmt = $db->prepare($anexos_query);
            $anexos_stmt->bindParam(':ticket_id', $ticket_id);
            $anexos_stmt->execute();
            $anexos = $anexos_stmt->fetchAll();

            $ticket['comentarios'] = $dados_timeline['timeline'];
            $ticket['anexos'] = $anexos;
            $ticket['historico'] = $dados_timeline['historico'];

            echo json_encode($ticket);
            
        } else {
            // Listagem por escopo: abertas | arquivo | transferidas | repassadas
            // (sem escopo, devolve tudo que o papel do usuário enxerga)
            $usuario_logado_id = (int) $user['id'];
            $tipo_logado = $user['tipo'] ?? '';
            $escopo = $_GET['escopo'] ?? (isset($_GET['arquivo']) ? 'arquivo' : '');

            if (!in_array($escopo, ['', 'abertas', 'arquivo', 'transferidas', 'repassadas', 'lixeira'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Escopo inválido']);
                exit;
            }

            // Status que movem a demanda para o Arquivo (constantes internas, sem entrada do usuário)
            $arquivados = "'" . implode("','", AcessoService::STATUS_ARQUIVO) . "'";
            $setor_escopo = AcessoService::setorEscopo($user);

            $query = "SELECT t.*,
                             u.nome as usuario_nome, u.email as usuario_email,
                             a.nome as agente_nome,
                             NULLIF(CONCAT_WS(' - ', c.nome, mc.nome), '') as categoria_nome,
                             s.nome as setor_nome,
                             ex.nome as excluido_por_nome,
                             (SELECT COUNT(*) FROM anexos WHERE ticket_id = t.id) as tem_anexos,
                             0 as criado_por_email,
                             CASE WHEN cnl.ticket_id IS NOT NULL THEN 1 ELSE 0 END as tem_comentario_nao_lido,
                             (SELECT CONCAT(data_lembrete, ' ', hora_lembrete) FROM lembretes WHERE ticket_id = t.id AND notificado = FALSE AND cancelado_em IS NULL ORDER BY data_lembrete ASC, hora_lembrete ASC LIMIT 1) as proximo_lembrete,
                             (SELECT COUNT(*) FROM ticket_historico WHERE ticket_id = t.id AND acao = 'merge') as eh_mesclado,
                             (SELECT valor_novo FROM ticket_historico WHERE ticket_id = t.id AND acao = 'merge' LIMIT 1) as tickets_mesclados,
                             sla.primeira_resposta_em as sla_primeira_resposta_em,
                             sla.resolvido_em as sla_resolvido_em,
                             sla.tempo_pausado_segundos as sla_tempo_pausado_segundos,
                             sla.pausado_em as sla_pausado_em
                      FROM tickets t
                      LEFT JOIN usuarios u ON t.usuario_id = u.id
                      LEFT JOIN usuarios a ON t.agente_id = a.id
                      LEFT JOIN categorias c ON t.categoria_id = c.id
                      LEFT JOIN macrocategorias mc ON c.macrocategoria_id = mc.id
                      LEFT JOIN setores s ON t.setor_id = s.id
                      LEFT JOIN usuarios ex ON t.excluido_por = ex.id
                      LEFT JOIN comentarios_nao_lidos cnl ON t.id = cnl.ticket_id AND cnl.usuario_id = :usuario_id
                      LEFT JOIN ticket_sla sla ON sla.ticket_id = t.id";

            $params = [':usuario_id' => $usuario_logado_id];
            $where = [];

            if ($escopo === 'lixeira') {
                // Lixeira: demandas excluídas, visíveis só ao administrador (opcionalmente de um setor)
                if ($tipo_logado !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Apenas administradores acessam a lixeira']);
                    exit;
                }
                $where[] = "t.excluido_em IS NOT NULL";
                if ($setor_escopo !== null) {
                    $where[] = "t.setor_id = :setor_id";
                    $params[':setor_id'] = $setor_escopo;
                }
            } elseif ($escopo === 'transferidas') {
                // Demandas que passaram pelo setore hoje estão em outro (gestor/admin)
                if (!in_array($tipo_logado, ['gestor', 'admin'], true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Escopo disponível apenas para gestores e administradores']);
                    exit;
                }
                if ($setor_escopo !== null) {
                    $where[] = "(t.setor_id IS NULL OR t.setor_id <> :setor_atual) AND EXISTS (
                                    SELECT 1 FROM ticket_setor_log l
                                    WHERE l.ticket_id = t.id AND l.setor_id = :setor_log AND l.saiu_em IS NOT NULL)";
                    $params[':setor_atual'] = $setor_escopo;
                    $params[':setor_log'] = $setor_escopo;
                } elseif ($tipo_logado === 'admin') {
                    $where[] = "EXISTS (SELECT 1 FROM ticket_setor_log l
                                        WHERE l.ticket_id = t.id AND l.saiu_em IS NOT NULL AND l.setor_id <> t.setor_id)";
                } else {
                    $where[] = "1 = 0";
                }
            } elseif ($escopo === 'repassadas') {
                // Demandas que o usuário atendeu e passou adiante, ainda abertas com outra pessoa
                if (!in_array($tipo_logado, ['operador', 'gestor'], true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Escopo disponível apenas para operadores e gestores']);
                    exit;
                }
                $where[] = "EXISTS (SELECT 1 FROM ticket_agente_log l
                                    WHERE l.ticket_id = t.id AND l.usuario_id = :self_log AND l.saiu_em IS NOT NULL)
                            AND (t.agente_id IS NULL OR t.agente_id <> :self_atual)
                            AND t.status NOT IN ($arquivados)";
                $params[':self_log'] = $usuario_logado_id;
                $params[':self_atual'] = $usuario_logado_id;
            } else {
                // Visibilidade base por papel
                if ($tipo_logado === 'admin') {
                    if ($setor_escopo !== null) {
                        $where[] = "t.setor_id = :setor_id";
                        $params[':setor_id'] = $setor_escopo;
                    }
                } elseif ($tipo_logado === 'gestor') {
                    $where[] = "t.setor_id = :setor_id";
                    $params[':setor_id'] = $setor_escopo ?? 0;
                } elseif ($tipo_logado === 'operador') {
                    if ($escopo === 'arquivo') {
                        // Arquivo do operador: o que é/foi dele (responsável atual, solicitante,
                        // quem encerrou ou quem já atendeu — ticket_agente_log)
                        $where[] = "(t.agente_id = :self_id OR t.usuario_id = :self_id2 OR t.fechado_por = :self_id3
                                     OR t.id IN (SELECT ticket_id FROM ticket_agente_log WHERE usuario_id = :self_id4))";
                        $params[':self_id'] = $usuario_logado_id;
                        $params[':self_id2'] = $usuario_logado_id;
                        $params[':self_id3'] = $usuario_logado_id;
                        $params[':self_id4'] = $usuario_logado_id;
                    } else {
                        $where[] = "(t.agente_id = :self_id OR t.usuario_id = :self_id2)";
                        $params[':self_id'] = $usuario_logado_id;
                        $params[':self_id2'] = $usuario_logado_id;
                    }
                } else {
                    // cliente: apenas as próprias demandas
                    $where[] = "t.usuario_id = :self_id";
                    $params[':self_id'] = $usuario_logado_id;
                }

                if ($escopo === 'abertas') {
                    $where[] = "t.status NOT IN ($arquivados)";
                } elseif ($escopo === 'arquivo') {
                    $where[] = "t.status IN ($arquivados)";
                }
            }

            // Demandas na lixeira não aparecem em nenhuma outra lista
            if ($escopo !== 'lixeira') {
                array_unshift($where, "t.excluido_em IS NULL");
            }
            // Paginação, busca, filtros, ordenação e contadores no servidor (?pagina=N).
            // Sem "pagina", a lista inteira é devolvida como antes (Kanban, integrações).
            if (isset($_GET['pagina'])) {
                echo json_encode(listarTicketsPaginado($db, $query, $where, $params, $escopo));
                exit;
            }

            if (!empty($where)) {
                $query .= " WHERE " . implode(' AND ', $where);
            }
            $query .= $escopo === 'lixeira' ? " ORDER BY t.excluido_em DESC" : " ORDER BY t.criado_em DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $tickets = anexarSla($stmt->fetchAll(), $db);

            echo json_encode($tickets);
        }
        
    } catch (Exception $e) {
        error_log('[TICKETS GET] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar demandas']);
    }
}

// POST - Criar ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Obter usuário logado do token JWT (com fallback)
    try {
        $user = AuthMiddleware::verificarAuth();
        AuthMiddleware::verificarCSRF();
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Acesso não autorizado']);
        exit;
    }
    
    
    $titulo_raw = $_POST['titulo'] ?? '';
    $descricao_raw = $_POST['descricao'] ?? '';
    $prioridade = $_POST['prioridade'] ?? 'normal';
    $categoria_id = $_POST['categoria_id'] ?? null;
    $email_solicitante = $_POST['email_solicitante'] ?? null;
    
    // Sanitização: título como texto puro; descrição com o HTML seguro do editor
    $titulo = HtmlSanitizer::texto($titulo_raw, 200);
    $descricao = HtmlSanitizer::rico($descricao_raw);

    // Se email não informado, usar email do usuário logado
    $email_solicitante = trim((string) $email_solicitante);
    if ($email_solicitante === '') {
        $email_solicitante = $user['email'];
    } elseif (!filter_var($email_solicitante, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail do solicitante inválido']);
        exit;
    }

    
    if (empty($titulo) || empty($descricao)) {
        http_response_code(400);
        echo json_encode(['error' => 'Título e descrição são obrigatórios']);
        exit;
    }
    
    try {
        
        // Prioridade: valor do catálogo (conferido abaixo contra o setor); vazio = normal
        $prioridade = trim((string) $prioridade) !== '' ? trim((string) $prioridade) : 'normal';


        // Determinar setor de destino: quem cria já vinculado a um setor (operador/gestor)
        // abre a demanda direto no próprio setor; sem setor (cliente/admin), a demanda cai no
        // setor "Geral" para triagem — de lá, um gestor a transfere para o setor correto.
        $setor_id = !empty($user['setor_id']) ? (int) $user['setor_id'] : null;

        // Admin pode abrir a demanda direto em um setor (o escolhido no cabeçalho)
        if ($setor_id === null && ($user['tipo'] ?? '') === 'admin' && !empty($_POST['setor_id']) && is_numeric($_POST['setor_id'])) {
            $setor_pedido = $db->prepare("SELECT id FROM setores WHERE id = ? AND ativo = 1");
            $setor_pedido->execute([(int) $_POST['setor_id']]);
            $setor_id = $setor_pedido->fetchColumn() ?: null;
        }

        if ($setor_id === null) {
            $setor_stmt = $db->prepare("SELECT id FROM setores WHERE nome = 'Geral' AND ativo = 1 LIMIT 1");
            $setor_stmt->execute();
            $setor_id = $setor_stmt->fetchColumn() ?: null;
        }

        // A prioridade precisa estar ativa para o setor em que a demanda nasce (senão vira "normal")
        if (!CatalogoService::prioridadePermitida($db, $prioridade, $setor_id ? (int) $setor_id : null)) {
            $prioridade = 'normal';
        }

        // A categoria informada precisa ser do setor em que a demanda nasce (senão fica sem categoria)
        if (!empty($categoria_id)) {
            $chk_cat = $db->prepare("SELECT 1 FROM categorias WHERE id = ? AND setor_id = ?");
            $chk_cat->execute([(int) $categoria_id, (int) $setor_id]);
            if (!$chk_cat->fetchColumn()) {
                $categoria_id = null;
            }
        } else {
            $categoria_id = null;
        }

        $query = "INSERT INTO tickets (titulo, descricao, usuario_id, email_solicitante, prioridade, categoria_id, setor_id, status) VALUES (:titulo, :descricao, :usuario_id, :email_solicitante, :prioridade, :categoria_id, :setor_id, 'novo')";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':titulo', $titulo);
        $stmt->bindParam(':descricao', $descricao);
        $stmt->bindParam(':usuario_id', $user['id']);
        $stmt->bindParam(':email_solicitante', $email_solicitante);
        $stmt->bindParam(':prioridade', $prioridade);
        $stmt->bindParam(':categoria_id', $categoria_id);
        $stmt->bindParam(':setor_id', $setor_id);
        $stmt->execute();

        // SLA, janela de setor e evento de abertura na linha do tempo
        $ticket_id = $db->lastInsertId();
        AcessoService::registrarAbertura($db, (int) $ticket_id, $setor_id ? (int) $setor_id : null, (int) $user['id'], 'no sistema');

        
        // Processar anexos se houver
        if (isset($_FILES['anexos']) && is_array($_FILES['anexos']['name'])) {
            $upload_dir = dirname(__DIR__, 2) . '/backend/anexos/';

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0750, true);
            }

            // Whitelist dupla: extensão + MIME type real
            $whitelist = [
                'pdf'  => 'application/pdf',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'txt'  => 'text/plain',
                'csv'  => 'text/csv',
                'xls'  => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
            ];

            $total_arquivos = count($_FILES['anexos']['name']);

            for ($i = 0; $i < $total_arquivos; $i++) {
                if ($_FILES['anexos']['error'][$i] !== UPLOAD_ERR_OK) continue;

                $nome_original = basename($_FILES['anexos']['name'][$i]);
                $tmp_name      = $_FILES['anexos']['tmp_name'][$i];
                $size          = $_FILES['anexos']['size'][$i];

                // Valida extensão
                $extensao = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
                if (!isset($whitelist[$extensao])) continue;

                // Valida MIME type real do arquivo (não o informado pelo cliente)
                $finfo     = finfo_open(FILEINFO_MIME_TYPE);
                $mime_real = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);
                if ($mime_real !== $whitelist[$extensao]) continue;

                // Nome do arquivo no servidor: sem o nome original para evitar path traversal
                $nome_arquivo    = time() . '_' . uniqid() . '.' . $extensao;
                $caminho_completo = $upload_dir . $nome_arquivo;

                if (move_uploaded_file($tmp_name, $caminho_completo)) {
                    chmod($caminho_completo, 0640);
                    try {
                        $anexo_query = "INSERT INTO anexos (ticket_id, nome_original, nome_arquivo, tamanho, tipo)
                                       VALUES (:ticket_id, :nome_original, :nome_arquivo, :tamanho, :tipo)";
                        $anexo_stmt = $db->prepare($anexo_query);
                        $anexo_stmt->bindParam(':ticket_id',    $ticket_id);
                        $anexo_stmt->bindParam(':nome_original', $nome_original);
                        $anexo_stmt->bindParam(':nome_arquivo',  $nome_arquivo);
                        $anexo_stmt->bindParam(':tamanho',       $size);
                        $anexo_stmt->bindParam(':tipo',          $mime_real);
                        $anexo_stmt->execute();
                    } catch (Exception $e) {
                        error_log('[TICKETS POST] Erro ao inserir anexo: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Enviar email de confirmação e Push
        try {
            require_once __DIR__ . '/../services/email-sender.php';
            $emailSender = new EmailSender($db);
            $emailSender->enviarEmailNovoTicket($ticket_id);
            $emailSender->notificarAgentesNovoTicket($ticket_id);
            
            // Notificação interna: apenas os gestores do setor da demanda (admins se não houver gestor)
            $msg_novo_ticket = "Uma nova demanda (#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ") foi aberta por " . ($user['nome'] ?? 'Desconhecido') . ".";
            $notif_insert = "INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, 'Nova Demanda', ?, ?)";
            $stmt_insert = $db->prepare($notif_insert);

            foreach (AcessoService::destinatariosInternosDoSetor($db, $setor_id, (int) $user['id']) as $destinatario) {
                $stmt_insert->execute([$destinatario['id'], $msg_novo_ticket, $ticket_id]);
            }
        } catch (Exception $emailError) {
            error_log("[WARNING] Erro no envio de email/notificação: " . $emailError->getMessage());
        }
        
        // Buscar ticket criado
        $select_query = "SELECT t.*, u.nome as usuario_nome 
                        FROM tickets t 
                        LEFT JOIN usuarios u ON t.usuario_id = u.id 
                        WHERE t.id = :id";
        $select_stmt = $db->prepare($select_query);
        $select_stmt->bindParam(':id', $ticket_id);
        $select_stmt->execute();
        $ticket = $select_stmt->fetch();
        
        echo json_encode($ticket);
        
    } catch (Exception $e) {
        error_log("[ERROR] Erro ao criar ticket: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar demanda']);
    }
}

// PUT - Atualizar demanda (ou ?acao=restaurar: tirar da lixeira, só administrador)
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarCSRF();

    $ticket_id = (int) ($_GET['id'] ?? 0);
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da demanda é obrigatório']);
        exit;
    }

    $usuario_id = (int) $user['id'];

    if (($_GET['acao'] ?? '') === 'restaurar') {
        AuthMiddleware::verificarAdmin($user);
        $ticket_lixeira = AcessoService::carregarTicketOuFalhar($db, $ticket_id);
        if (empty($ticket_lixeira['excluido_em'])) {
            http_response_code(400);
            echo json_encode(['error' => 'A demanda não está na lixeira']);
            exit;
        }
        try {
            DemandaService::restaurar($db, $ticket_lixeira, $usuario_id);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log('[TICKETS RESTAURAR] Erro: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao restaurar a demanda']);
        }
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // E-mails e avisos só depois do commit
    $pos_commit = ['atribuicao' => null, 'encerrado' => false, 'reaberto' => false];

    try {
        $updates = [];
        $params = [':id' => $ticket_id];

        // Dados atuais ANTES da atualização
        $current_stmt = $db->prepare("SELECT titulo, descricao, status, prioridade, categoria_id, agente_id, setor_id, usuario_id, excluido_em
                                      FROM tickets WHERE id = ?");
        $current_stmt->execute([$ticket_id]);
        $current_data = $current_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current_data) {
            http_response_code(404);
            echo json_encode(['error' => 'Demanda não encontrada']);
            exit;
        }

        // Isolamento: admin, gestor do setor da demanda ou operador responsável (lixeira: ninguém)
        if (!AcessoService::podeOperarTicket($user, $current_data)) {
            http_response_code(403);
            echo json_encode(['error' => !empty($current_data['excluido_em'])
                ? 'A demanda está na lixeira. Restaure-a para alterar.'
                : 'Você não tem permissão para alterar esta demanda']);
            exit;
        }

        $setor_da_demanda = $current_data['setor_id'] ? (int) $current_data['setor_id'] : null;

        // Status e prioridade: do catálogo e ativos para o setor da demanda (manter o valor atual é sempre válido)
        if (isset($input['status']) && $input['status'] !== $current_data['status']
            && !CatalogoService::statusPermitido($db, (string) $input['status'], $setor_da_demanda)) {
            http_response_code(400);
            echo json_encode(['error' => 'Status inválido para o setor desta demanda']);
            exit;
        }
        if (isset($input['prioridade']) && $input['prioridade'] !== $current_data['prioridade']
            && !CatalogoService::prioridadePermitida($db, (string) $input['prioridade'], $setor_da_demanda)) {
            http_response_code(400);
            echo json_encode(['error' => 'Prioridade inválida para o setor desta demanda']);
            exit;
        }

        // A categoria precisa pertencer ao setor da demanda
        if (!empty($input['categoria_id'])) {
            $chk = $db->prepare("SELECT 1 FROM categorias WHERE id = ? AND setor_id = ?");
            $chk->execute([(int) $input['categoria_id'], (int) $current_data['setor_id']]);
            if (!$chk->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'A subcategoria não pertence ao setor da demanda']);
                exit;
            }
        }

        // Atribuir/remover responsável é ato de gestão: operador usa as ações de demanda-acoes.php
        if (array_key_exists('agente_id', $input)) {
            if (!in_array($user['tipo'], ['admin', 'gestor'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Apenas gestores ou administradores atribuem responsável']);
                exit;
            }
            if (!empty($input['agente_id'])) {
                $dest = $db->prepare("SELECT setor_id FROM usuarios WHERE id = ? AND ativo = 1 AND tipo IN ('operador','gestor')");
                $dest->execute([(int) $input['agente_id']]);
                $destRow = $dest->fetch(PDO::FETCH_ASSOC);
                if (!$destRow || (int) $destRow['setor_id'] !== (int) $current_data['setor_id']) {
                    http_response_code(400);
                    echo json_encode(['error' => 'O responsável precisa ser um usuário ativo do setor da demanda']);
                    exit;
                }
            }
        }

        $db->beginTransaction();

        // Auditoria do cancelamento ANTES da alteração
        if (isset($input['status']) && $input['status'] === 'cancelado') {
            $dados_novos = $current_data;
            $dados_novos['status'] = 'cancelado';
            (new AuditoriaService($db))->registrar($usuario_id, 'cancelar_ticket', 'tickets', $ticket_id, json_encode($current_data), json_encode($dados_novos));
        }

        if (isset($input['status'])) {
            $updates[] = 'status = :status';
            $params[':status'] = $input['status'];

            if (in_array($input['status'], AcessoService::STATUS_ARQUIVO, true)) {
                $updates[] = 'fechado_em = NOW()';
                // Quem encerrou vem sempre do token, nunca do corpo da requisição
                $updates[] = 'fechado_por = :fechado_por';
                $params[':fechado_por'] = $usuario_id;
            }
        }

        if (isset($input['prioridade'])) {
            $updates[] = 'prioridade = :prioridade';
            $params[':prioridade'] = $input['prioridade'];
        }

        if (isset($input['categoria_id'])) {
            $updates[] = 'categoria_id = :categoria_id';
            $params[':categoria_id'] = $input['categoria_id'] ?: null;
        }

        if (array_key_exists('agente_id', $input)) {
            $updates[] = 'agente_id = :agente_id';
            $params[':agente_id'] = $input['agente_id'] ?: null;
            $updates[] = 'atribuido_em = ' . ($input['agente_id'] ? 'NOW()' : 'NULL');

            // Atribuir responsável a uma demanda "novo" a põe em atendimento
            if ($input['agente_id'] && $current_data['status'] === 'novo') {
                $updates[] = 'status = :status_atendimento';
                $params[':status_atendimento'] = 'em_atendimento';
                $input['status'] = 'em_atendimento'; // para o histórico
            }
        }

        if (!empty($updates)) {
            $stmt = $db->prepare("UPDATE tickets SET " . implode(', ', $updates) . " WHERE id = :id");
            $stmt->execute($params);

            $auditoria = new AuditoriaService($db);
            $mudou_status = isset($input['status']) && $input['status'] !== $current_data['status'];

            // === SLA ===
            if ($mudou_status) {
                $slaService = new SLAService($db);
                $congela_novo = $slaService->congelaSla($input['status']);
                $congela_anterior = $slaService->congelaSla($current_data['status']);

                if ($congela_novo && !$congela_anterior) {
                    $db->prepare("UPDATE ticket_sla SET pausado = TRUE, pausado_em = NOW() WHERE ticket_id = ?")->execute([$ticket_id]);
                }
                if (!$congela_novo && $congela_anterior) {
                    $slaService->retomarPausa($ticket_id, $setor_da_demanda);
                }

                $criado = $db->prepare("SELECT criado_em FROM tickets WHERE id = ?");
                $criado->execute([$ticket_id]);
                $criado_em = $criado->fetchColumn();

                // Primeira resposta (quando sai de 'novo')
                if ($criado_em && $current_data['status'] === 'novo' && $input['status'] !== 'novo') {
                    $segundos = $slaService->calcularSegundosUteis($criado_em, date('Y-m-d H:i:s'), $setor_da_demanda);
                    $db->prepare("UPDATE ticket_sla SET primeira_resposta_em = NOW(), tempo_primeira_resposta_segundos = ? WHERE ticket_id = ?")
                       ->execute([$segundos, $ticket_id]);
                }

                // Resolução (resolvido/encerrado)
                if ($criado_em && in_array($input['status'], ['resolvido', 'encerrado'], true)) {
                    $pz = $db->prepare("SELECT COALESCE(tempo_pausado_segundos, 0) FROM ticket_sla WHERE ticket_id = ?");
                    $pz->execute([$ticket_id]);
                    $tempo_pausado = (int) $pz->fetchColumn();
                    $totais = $slaService->calcularSegundosUteis($criado_em, date('Y-m-d H:i:s'), $setor_da_demanda);
                    $db->prepare("UPDATE ticket_sla SET resolvido_em = NOW(), tempo_resolucao_segundos = ? WHERE ticket_id = ?")
                       ->execute([max(0, $totais - $tempo_pausado), $ticket_id]);
                }
            }

            // === Histórico, auditoria e janelas ===
            $hist = $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, ?, ?, ?)");

            if ($mudou_status) {
                $hist->execute([$ticket_id, $usuario_id, 'status', $current_data['status'], $input['status']]);
                $auditoria->registrarTicket($ticket_id, $usuario_id, 'status', $current_data['status'], $input['status']);

                // Encerrar/cancelar fecha as janelas de setor e responsável e os lembretes; reabrir as reabre
                $era_arquivada = in_array($current_data['status'], AcessoService::STATUS_ARQUIVO, true);
                $vai_arquivar  = in_array($input['status'], AcessoService::STATUS_ARQUIVO, true);
                if ($vai_arquivar && !$era_arquivada) {
                    AcessoService::fecharJanelasDaDemanda($db, $ticket_id);
                    LembreteService::cancelarPendentes($db, $ticket_id, 'Demanda ' . ($input['status'] === 'cancelado' ? 'cancelada' : 'encerrada'));
                } elseif ($era_arquivada && !$vai_arquivar) {
                    // Se a mesma requisição troca o responsável, a janela dele é aberta mais abaixo
                    $troca_agente = array_key_exists('agente_id', $input) && $input['agente_id'] != $current_data['agente_id'];
                    $agente_atual = $troca_agente ? null : $current_data['agente_id'];
                    AcessoService::reabrirJanelasDaDemanda($db, $ticket_id, $setor_da_demanda, $agente_atual ? (int) $agente_atual : null);
                    $pos_commit['reaberto'] = true;
                }
                if ($input['status'] === 'encerrado') {
                    $pos_commit['encerrado'] = true;
                }
            }

            if (isset($input['prioridade']) && $input['prioridade'] !== $current_data['prioridade']) {
                $hist->execute([$ticket_id, $usuario_id, 'prioridade', $current_data['prioridade'], $input['prioridade']]);
                $auditoria->registrarTicket($ticket_id, $usuario_id, 'prioridade', $current_data['prioridade'], $input['prioridade']);
            }

            if (isset($input['categoria_id']) && (int) $input['categoria_id'] !== (int) $current_data['categoria_id']) {
                $nomeCategoria = function ($id) use ($db) {
                    if (!$id) {
                        return 'Sem subcategoria';
                    }
                    $c = $db->prepare("SELECT CONCAT_WS(' - ', c.nome, mc.nome) FROM categorias c
                                       LEFT JOIN macrocategorias mc ON c.macrocategoria_id = mc.id WHERE c.id = ?");
                    $c->execute([(int) $id]);
                    return $c->fetchColumn() ?: (string) $id;
                };
                $cat_ant = $nomeCategoria($current_data['categoria_id']);
                $cat_nova = $nomeCategoria($input['categoria_id']);
                $hist->execute([$ticket_id, $usuario_id, 'categoria', $cat_ant, $cat_nova]);
                $auditoria->registrarTicket($ticket_id, $usuario_id, 'categoria', $cat_ant, $cat_nova);
            }

            if (array_key_exists('agente_id', $input) && (int) $input['agente_id'] !== (int) $current_data['agente_id']) {
                $nomeUsuario = function ($id) use ($db) {
                    if (!$id) {
                        return 'Não atribuído';
                    }
                    $u = $db->prepare("SELECT nome FROM usuarios WHERE id = ?");
                    $u->execute([(int) $id]);
                    return $u->fetchColumn() ?: (string) $id;
                };
                $agente_ant = $nomeUsuario($current_data['agente_id']);
                $agente_novo = $nomeUsuario($input['agente_id']);
                $era_sem_atribuicao = empty($current_data['agente_id']);

                $hist->execute([$ticket_id, $usuario_id, 'atribuicao', $agente_ant, $agente_novo]);
                $auditoria->registrarTicket($ticket_id, $usuario_id, 'agente', $agente_ant, $agente_novo);

                // Histórico de responsáveis: fecha a janela do anterior e abre a do novo
                if ($current_data['agente_id']) {
                    $db->prepare("UPDATE ticket_agente_log SET saiu_em = NOW() WHERE ticket_id = ? AND usuario_id = ? AND saiu_em IS NULL")
                       ->execute([$ticket_id, $current_data['agente_id']]);
                }
                if ($input['agente_id']) {
                    $db->prepare("INSERT INTO ticket_agente_log (ticket_id, usuario_id) VALUES (?, ?)")
                       ->execute([$ticket_id, (int) $input['agente_id']]);
                    $pos_commit['atribuicao'] = ['agente_id' => (int) $input['agente_id'], 'era_sem_atribuicao' => $era_sem_atribuicao];
                }
            }
        }

        $db->commit();

        // === Avisos (fora da transação: falha de e-mail não desfaz a alteração) ===
        try {
            if ($pos_commit['atribuicao'] || $pos_commit['encerrado'] || $pos_commit['reaberto']) {
                require_once __DIR__ . '/../services/email-sender.php';
                $emailSender = new EmailSender($db);

                if ($pos_commit['atribuicao']) {
                    $autor = $db->prepare("SELECT nome FROM usuarios WHERE id = ?");
                    $autor->execute([$usuario_id]);
                    $autor_nome = $autor->fetchColumn() ?: 'Sistema';
                    $dest_id = $pos_commit['atribuicao']['agente_id'];
                    $emailSender->notificarAtribuicaoTicket($ticket_id, $dest_id, $autor_nome, $pos_commit['atribuicao']['era_sem_atribuicao']);
                    $db->prepare("INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, ?, ?, ?)")
                       ->execute([$dest_id, 'Demanda atribuída',
                           'A demanda (#' . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ') foi atribuída a você por ' . $autor_nome . '.', $ticket_id]);
                }
                if ($pos_commit['encerrado']) {
                    $emailSender->notificarFechamentoTicket($ticket_id);
                }
                if ($pos_commit['reaberto']) {
                    $emailSender->notificarReaberturaTicket($ticket_id);
                }
            }
        } catch (Exception $avisoErro) {
            error_log('[TICKETS PUT] Alteração salva; falha nos avisos: ' . $avisoErro->getMessage());
        }

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[TICKETS PUT] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar demanda']);
    }
}

// DELETE - Excluir demanda: vai para a lixeira (qualquer perfil que opere a demanda);
//          ?definitivo=1 apaga de vez (somente administrador, somente o que já está na lixeira)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = AuthMiddleware::verificarAuth();
    AuthMiddleware::verificarCSRF();

    $ticket_id = (int) ($_GET['id'] ?? 0);
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da demanda é obrigatório']);
        exit;
    }

    $ticket = AcessoService::carregarTicketOuFalhar($db, $ticket_id);

    try {
        if (!empty($_GET['definitivo'])) {
            AuthMiddleware::verificarAdmin($user);
            if (empty($ticket['excluido_em'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Envie a demanda para a lixeira antes de excluí-la definitivamente']);
                exit;
            }
            DemandaService::excluirDefinitivo($db, $ticket, (int) $user['id']);
            echo json_encode(['success' => true, 'definitivo' => true]);
            exit;
        }

        if (!empty($ticket['excluido_em'])) {
            http_response_code(400);
            echo json_encode(['error' => 'A demanda já está na lixeira']);
            exit;
        }
        if (!AcessoService::podeOperarTicket($user, $ticket)) {
            http_response_code(403);
            echo json_encode(['error' => 'Você não tem permissão para excluir esta demanda']);
            exit;
        }

        DemandaService::enviarParaLixeira($db, $ticket, (int) $user['id']);
        echo json_encode(['success' => true, 'lixeira' => true]);
    } catch (Exception $e) {
        error_log('[TICKETS DELETE] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao excluir demanda']);
    }
}
