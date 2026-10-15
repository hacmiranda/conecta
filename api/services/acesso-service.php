<?php
/**
 * Regras centrais de acesso às demandas (isolamento por setor e por papel).
 *
 * Papéis: admin (tudo), gestor (demandas do próprio setor), operador (demandas em que
 * é o responsável ou que ele mesmo abriu) e cliente (somente as próprias).
 * Toda leitura/escrita de demanda deve passar por aqui, para a regra existir em um só lugar.
 */
class AcessoService {

    /** Status que movem a demanda para o Arquivo */
    const STATUS_ARQUIVO = ['encerrado', 'cancelado', 'fechado']; // 'fechado' = valor legado do ENUM

    /**
     * Quem pode VER a demanda.
     */
    public static function podeVerTicket(array $user, array $ticket): bool {
        $tipo = $user['tipo'] ?? '';
        if ($tipo === 'admin') {
            return true;
        }
        if ($tipo === 'gestor') {
            return AuthMiddleware::mesmoSetor($user, isset($ticket['setor_id']) ? (int) $ticket['setor_id'] : null);
        }
        if ($tipo === 'operador') {
            return (int) ($ticket['agente_id'] ?? 0) === (int) $user['id']
                || (int) ($ticket['usuario_id'] ?? 0) === (int) $user['id'];
        }
        // cliente: apenas as próprias demandas
        return (int) ($ticket['usuario_id'] ?? 0) === (int) $user['id'];
    }

    /**
     * Quem pode ALTERAR a demanda (status, prioridade, categoria, comentar como responsável).
     * Operador só altera a demanda em que é o responsável atual; cliente nunca altera.
     */
    public static function podeOperarTicket(array $user, array $ticket): bool {
        $tipo = $user['tipo'] ?? '';
        if ($tipo === 'admin') {
            return true;
        }
        if ($tipo === 'gestor') {
            return AuthMiddleware::mesmoSetor($user, isset($ticket['setor_id']) ? (int) $ticket['setor_id'] : null);
        }
        if ($tipo === 'operador') {
            return (int) ($ticket['agente_id'] ?? 0) === (int) $user['id'];
        }
        return false;
    }

    /**
     * Carrega a demanda (campos de controle de acesso) ou encerra com 404.
     */
    public static function carregarTicketOuFalhar(PDO $db, $ticketId): array {
        $stmt = $db->prepare("SELECT id, titulo, status, prioridade, categoria_id, setor_id, agente_id, usuario_id
                              FROM tickets WHERE id = ?");
        $stmt->execute([(int) $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['error' => 'Demanda não encontrada']);
            exit;
        }
        return $ticket;
    }

    /**
     * Carrega a demanda e exige permissão de leitura (404/403).
     */
    public static function exigirVerTicket(PDO $db, array $user, $ticketId): array {
        $ticket = self::carregarTicketOuFalhar($db, $ticketId);
        if (!self::podeVerTicket($user, $ticket)) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso não autorizado a esta demanda']);
            exit;
        }
        return $ticket;
    }

    /**
     * Carrega a demanda e exige permissão de escrita (404/403).
     */
    public static function exigirOperarTicket(PDO $db, array $user, $ticketId): array {
        $ticket = self::carregarTicketOuFalhar($db, $ticketId);
        if (!self::podeOperarTicket($user, $ticket)) {
            http_response_code(403);
            echo json_encode(['error' => 'Você não tem permissão para alterar esta demanda']);
            exit;
        }
        return $ticket;
    }

    /**
     * Setor de escopo da requisição.
     * Admin: o setor pedido em ?setor_id (ou null = todos os setores).
     * Demais papéis: sempre o próprio setor, ignorando o parâmetro recebido.
     */
    public static function setorEscopo(array $user): ?int {
        if (($user['tipo'] ?? '') === 'admin') {
            $pedido = $_GET['setor_id'] ?? '';
            return $pedido !== '' && is_numeric($pedido) ? (int) $pedido : null;
        }
        return isset($user['setor_id']) && $user['setor_id'] !== null ? (int) $user['setor_id'] : null;
    }

    /**
     * Destinatários internos de uma demanda nova/sem responsável: os gestores ativos do setor.
     * Se o setor não tiver gestor (ou a demanda não tiver setor), cai nos administradores,
     * para que o aviso nunca se perca.
     *
     * @return array<int, array{id:int, email:string, nome:string}>
     */
    public static function destinatariosInternosDoSetor(PDO $db, ?int $setorId, ?int $excluirUsuarioId = null): array {
        $destinatarios = [];

        if ($setorId !== null) {
            $stmt = $db->prepare("SELECT id, email, nome FROM usuarios
                                  WHERE tipo = 'gestor' AND setor_id = ? AND ativo = 1");
            $stmt->execute([$setorId]);
            $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($destinatarios)) {
            $stmt = $db->query("SELECT id, email, nome FROM usuarios WHERE tipo = 'admin' AND ativo = 1");
            $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($excluirUsuarioId !== null) {
            $destinatarios = array_values(array_filter($destinatarios, function ($d) use ($excluirUsuarioId) {
                return (int) $d['id'] !== $excluirUsuarioId;
            }));
        }

        return $destinatarios;
    }

    /**
     * Quem deve ser avisado sobre uma atividade na demanda: o responsável, se houver;
     * senão, os gestores do setor.
     *
     * @return array<int, array{id:int, email:string, nome:string}>
     */
    public static function destinatariosDaDemanda(PDO $db, array $ticket, ?int $excluirUsuarioId = null): array {
        if (!empty($ticket['agente_id'])) {
            $stmt = $db->prepare("SELECT id, email, nome FROM usuarios WHERE id = ? AND ativo = 1");
            $stmt->execute([(int) $ticket['agente_id']]);
            $responsavel = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($excluirUsuarioId !== null) {
                $responsavel = array_values(array_filter($responsavel, function ($d) use ($excluirUsuarioId) {
                    return (int) $d['id'] !== $excluirUsuarioId;
                }));
            }
            return $responsavel;
        }
        $setorId = isset($ticket['setor_id']) ? (int) $ticket['setor_id'] : null;
        return self::destinatariosInternosDoSetor($db, $setorId, $excluirUsuarioId);
    }

    /**
     * Registros iniciais de toda demanda nova (qualquer origem: portal, e-mail ou integração):
     * linha de SLA (somente se ainda não existir; em produção um trigger pode tê-la criado),
     * janela de passagem pelo setor e o evento "abertura" na linha do tempo.
     *
     * @param string $detalhe texto do evento (ex.: "no sistema", "via e-mail", "via integração (x)")
     */
    public static function registrarAbertura(PDO $db, int $ticketId, ?int $setorId, int $autorId, string $detalhe): void {
        $db->prepare("INSERT INTO ticket_sla (ticket_id)
                      SELECT ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ticket_sla WHERE ticket_id = ?)")
           ->execute([$ticketId, $ticketId]);

        if ($setorId) {
            $db->prepare("INSERT INTO ticket_setor_log (ticket_id, setor_id) VALUES (?, ?)")
               ->execute([$ticketId, $setorId]);
        }

        $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo)
                      VALUES (?, ?, 'abertura', NULL, ?)")
           ->execute([$ticketId, $autorId, mb_substr($detalhe, 0, 255)]);
    }

    /**
     * Fecha as janelas abertas de passagem por setor/responsável (encerramento/cancelamento).
     */
    public static function fecharJanelasDaDemanda(PDO $db, int $ticketId): void {
        $db->prepare("UPDATE ticket_setor_log SET saiu_em = NOW() WHERE ticket_id = ? AND saiu_em IS NULL")
           ->execute([$ticketId]);
        $db->prepare("UPDATE ticket_agente_log SET saiu_em = NOW() WHERE ticket_id = ? AND saiu_em IS NULL")
           ->execute([$ticketId]);
    }

    /**
     * Demandas abertas em que o usuário é o responsável.
     */
    public static function contarDemandasAbertasDoResponsavel(PDO $db, int $usuarioId): int {
        $arquivo = "'" . implode("','", self::STATUS_ARQUIVO) . "'";
        $stmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE agente_id = ? AND status NOT IN ($arquivo)");
        $stmt->execute([$usuarioId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Devolve aos gestores do setor as demandas abertas de um responsável que foi desativado,
     * mudou de setor ou deixou de atender (registra no histórico e avisa os gestores) e cancela
     * os lembretes direcionados a ele. Devolve quantas demandas foram devolvidas.
     */
    public static function devolverDemandasDoResponsavel(PDO $db, int $usuarioId, int $autorId, string $motivo): int {
        $arquivo = "'" . implode("','", self::STATUS_ARQUIVO) . "'";
        $stmt = $db->prepare("SELECT t.id, t.setor_id, u.nome AS responsavel_nome
                              FROM tickets t INNER JOIN usuarios u ON u.id = t.agente_id
                              WHERE t.agente_id = ? AND t.status NOT IN ($arquivo)");
        $stmt->execute([$usuarioId]);
        $demandas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $limpar = $db->prepare("UPDATE tickets SET agente_id = NULL, atribuido_em = NULL WHERE id = ? AND agente_id = ?");
        $janela = $db->prepare("UPDATE ticket_agente_log SET saiu_em = NOW() WHERE ticket_id = ? AND usuario_id = ? AND saiu_em IS NULL");
        $hist = $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'devolucao', ?, 'Não atribuído')");
        $notif = $db->prepare("INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, 'Demanda devolvida', ?, ?)");

        foreach ($demandas as $d) {
            $ticketId = (int) $d['id'];
            $limpar->execute([$ticketId, $usuarioId]);
            $janela->execute([$ticketId, $usuarioId]);
            $hist->execute([$ticketId, $autorId, $d['responsavel_nome']]);
            $mensagem = 'A demanda (#' . str_pad($ticketId, 5, '0', STR_PAD_LEFT) . ') voltou para o setor: ' . $motivo . '.';
            foreach (self::destinatariosInternosDoSetor($db, $d['setor_id'] ? (int) $d['setor_id'] : null, $usuarioId) as $dest) {
                $notif->execute([$dest['id'], $mensagem, $ticketId]);
            }
        }

        $db->prepare("UPDATE lembretes SET cancelado_em = NOW(), cancelado_motivo = ?
                      WHERE agente_id = ? AND notificado = 0 AND cancelado_em IS NULL")
           ->execute([mb_substr('Destinatário ' . $motivo, 0, 255), $usuarioId]);

        return count($demandas);
    }

    /**
     * Reabre as janelas de setor/responsável quando uma demanda arquivada volta a ser tratada.
     */
    public static function reabrirJanelasDaDemanda(PDO $db, int $ticketId, ?int $setorId, ?int $agenteId): void {
        if ($setorId) {
            $existe = $db->prepare("SELECT 1 FROM ticket_setor_log WHERE ticket_id = ? AND saiu_em IS NULL LIMIT 1");
            $existe->execute([$ticketId]);
            if (!$existe->fetchColumn()) {
                $db->prepare("INSERT INTO ticket_setor_log (ticket_id, setor_id) VALUES (?, ?)")
                   ->execute([$ticketId, $setorId]);
            }
        }
        if ($agenteId) {
            $existe = $db->prepare("SELECT 1 FROM ticket_agente_log WHERE ticket_id = ? AND saiu_em IS NULL LIMIT 1");
            $existe->execute([$ticketId]);
            if (!$existe->fetchColumn()) {
                $db->prepare("INSERT INTO ticket_agente_log (ticket_id, usuario_id) VALUES (?, ?)")
                   ->execute([$ticketId, $agenteId]);
            }
        }
    }
}
