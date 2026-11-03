<?php
/**
 * Regras centrais de acesso às demandas (isolamento por setor e por papel).
 *
 * Papéis: admin (tudo), coordenador_geral e supervisor (veem tudo, só leitura), coordenador
 * (como gestor, em vários setores), gestor (demandas do próprio setor), operador (demandas em que
 * é o responsável ou que ele mesmo abriu) e cliente (somente as próprias).
 * Toda leitura/escrita de demanda deve passar por aqui, para a regra existir em um só lugar.
 */
require_once __DIR__ . '/protocolo-service.php';

class AcessoService {

    /** Papéis que veem todos os setores */
    const PAPEIS_VISAO_TOTAL = ['admin', 'coordenador_geral', 'supervisor'];

    /** Papéis de consulta: veem tudo e não alteram nada (a trava central está em AuthMiddleware) */
    const PAPEIS_SOMENTE_LEITURA = ['coordenador_geral', 'supervisor'];

    /** Papéis de gestão de setor (gestor de um setor; coordenador de um ou mais) */
    const PAPEIS_GESTAO = ['gestor', 'coordenador'];

    /** Papéis que podem ser responsáveis por uma demanda e entram nas listas de equipe */
    const PAPEIS_ATENDEM = ['operador', 'gestor', 'coordenador'];

    /** Toda a equipe interna (tudo menos cliente) */
    const PAPEIS_EQUIPE = ['admin', 'coordenador_geral', 'supervisor', 'coordenador', 'gestor', 'operador'];

    /** Quem registra as próprias atividades (precisa ter setor de lotação); admin não registra */
    const PAPEIS_REGISTRAM_ATIVIDADES = ['coordenador_geral', 'supervisor', 'coordenador', 'gestor', 'operador'];

    /** Quem entra no módulo Satisfação (o gestor e o operador não) */
    const PAPEIS_SATISFACAO = ['admin', 'coordenador_geral', 'supervisor', 'coordenador'];

    public static function ehVisaoTotal(array $user): bool {
        return in_array($user['tipo'] ?? '', self::PAPEIS_VISAO_TOTAL, true);
    }

    public static function ehSomenteLeitura(array $user): bool {
        return in_array($user['tipo'] ?? '', self::PAPEIS_SOMENTE_LEITURA, true);
    }

    /** Gestor ou coordenador (o administrador não conta: ele é tratado à parte em todas as regras) */
    public static function ehGestao(array $user): bool {
        return in_array($user['tipo'] ?? '', self::PAPEIS_GESTAO, true);
    }

    /**
     * Setores a que o usuário pertence: null = todos (visão total); coordenador = principal + adicionais
     * (`setores_extras`, lidos do banco a cada requisição por AuthMiddleware); demais = só o próprio.
     *
     * @return int[]|null
     */
    public static function setoresDoUsuario(array $user): ?array {
        if (self::ehVisaoTotal($user)) {
            return null;
        }
        $ids = [];
        if (isset($user['setor_id']) && $user['setor_id'] !== null && (int) $user['setor_id'] > 0) {
            $ids[] = (int) $user['setor_id'];
        }
        if (($user['tipo'] ?? '') === 'coordenador') {
            foreach ((array) ($user['setores_extras'] ?? []) as $extra) {
                if ((int) $extra > 0) {
                    $ids[] = (int) $extra;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Setores do usuário com nome, para o navegador montar o seletor do cabeçalho:
     * null = todos (visão total); lista com [{id, nome}] nos demais casos.
     * Lê do banco (login e me.php); no restante do código o payload já traz `setores_extras`.
     *
     * @param array $usuario linha de `usuarios` com id, tipo e setor_id
     * @return array<int, array{id:int, nome:string}>|null
     */
    public static function setoresComNome(PDO $db, array $usuario): ?array {
        if (self::ehVisaoTotal($usuario)) {
            return null;
        }
        $ids = [];
        if (!empty($usuario['setor_id'])) {
            $ids[] = (int) $usuario['setor_id'];
        }
        if (($usuario['tipo'] ?? '') === 'coordenador') {
            $stmt = $db->prepare("SELECT setor_id FROM usuario_setores WHERE usuario_id = ? ORDER BY criado_em");
            $stmt->execute([(int) $usuario['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $extra) {
                $ids[] = (int) $extra;
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }
        $stmt = $db->query("SELECT id, nome FROM setores WHERE id IN (" . implode(',', $ids) . ")");
        $porId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $porId[(int) $s['id']] = $s['nome'];
        }
        $lista = [];
        foreach ($ids as $id) { // mantém a ordem: principal primeiro
            if (isset($porId[$id])) {
                $lista[] = ['id' => $id, 'nome' => $porId[$id]];
            }
        }
        return $lista;
    }

    /**
     * O usuário (linha do banco com id, tipo e setor_id) pertence ao setor? Usado para escolher
     * destinatários (encaminhar, repassar): setor principal ou, no coordenador, um adicional.
     */
    public static function usuarioDoSetor(PDO $db, array $usuario, int $setorId): bool {
        if ((int) ($usuario['setor_id'] ?? 0) === $setorId) {
            return true;
        }
        if (($usuario['tipo'] ?? '') !== 'coordenador') {
            return false;
        }
        $stmt = $db->prepare("SELECT 1 FROM usuario_setores WHERE usuario_id = ? AND setor_id = ?");
        $stmt->execute([(int) $usuario['id'], $setorId]);
        return (bool) $stmt->fetchColumn();
    }
    /** O usuário tem acesso ao setor? (visão total sempre; coordenador em qualquer um dos seus) */
    public static function temAcessoAoSetor(array $user, ?int $setorId): bool {
        $setores = self::setoresDoUsuario($user);
        if ($setores === null) {
            return true;
        }
        return $setorId !== null && in_array($setorId, $setores, true);
    }

    /**
     * Condição SQL "coluna IN (...)" para um escopo de setores (ids inteiros, sem risco de injeção):
     * null = todos ("1=1"); lista vazia = nenhum ("1=0").
     */
    public static function filtroSetorSql(string $coluna, ?array $escopo): string {
        if ($escopo === null) {
            return '1=1';
        }
        if ($escopo === []) {
            return '1=0';
        }
        return $coluna . ' IN (' . implode(',', array_map('intval', $escopo)) . ')';
    }

    /** Status que movem a demanda para o Arquivo */
    const STATUS_ARQUIVO = ['encerrado', 'cancelado', 'fechado']; // 'fechado' = valor legado do ENUM

    /**
     * Demanda concluída (atendida) para SLA e indicadores: resolvido (aguarda só a confirmação do
     * solicitante) e encerrado. Cancelado não é conclusão. Dashboard e relatórios usam esta lista.
     */
    const STATUS_CONCLUIDOS = ['resolvido', 'encerrado', 'fechado'];

    /** Status do grupo "Aguardando" (lista de demandas, contadores e funil do dashboard) */
    const GRUPO_AGUARDANDO = ['aguardando', 'aguardando_resposta', 'aguardando_terceiros', 'aguardando_aprovacao', 'aguardando_data'];

    /** Lista de status para usar dentro de um IN (...) de SQL (valores fixos do código, não do usuário). */
    public static function listaSql(array $status): string {
        return implode(',', array_map(fn($s) => "'" . preg_replace('/[^a-z_]/', '', $s) . "'", $status));
    }

    /**
     * Quem pode VER a demanda.
     */
    public static function podeVerTicket(array $user, array $ticket): bool {
        $tipo = $user['tipo'] ?? '';
        if ($tipo === 'admin') {
            return true;
        }
        if (!empty($ticket['excluido_em'])) {
            return false; // na lixeira: só o administrador
        }
        if (self::ehVisaoTotal($user)) {
            return true; // coordenador geral e supervisor: consulta de todos os setores
        }
        if (self::ehGestao($user)) {
            return self::temAcessoAoSetor($user, isset($ticket['setor_id']) ? (int) $ticket['setor_id'] : null);
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
        if (!empty($ticket['excluido_em'])) {
            return false; // na lixeira: restaurar antes de alterar
        }
        if ($tipo === 'admin') {
            return true;
        }
        if (self::ehSomenteLeitura($user)) {
            return false; // coordenador geral e supervisor só consultam
        }
        if (self::ehGestao($user)) {
            return self::temAcessoAoSetor($user, isset($ticket['setor_id']) ? (int) $ticket['setor_id'] : null);
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
        $stmt = $db->prepare("SELECT id, titulo, status, prioridade, categoria_id, setor_id, agente_id, usuario_id,
                                     fechado_por, excluido_em
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
     * Quem pode LER a demanda (inclui o histórico de quem já passou por ela):
     * - quem pode vê-la hoje (podeVerTicket);
     * - gestor cujo setor teve a demanda (ticket_setor_log), mesmo depois de transferida;
     * - operador que já foi responsável por ela (ticket_agente_log) ou que a encerrou.
     * Demanda na lixeira: só o administrador. Leitura apenas — para agir, use podeOperarTicket.
     */
    public static function podeLerTicket(PDO $db, array $user, array $ticket): bool {
        if (self::podeVerTicket($user, $ticket)) {
            return true;
        }
        if (!empty($ticket['excluido_em'])) {
            return false;
        }
        $tipo = $user['tipo'] ?? '';
        $ticketId = (int) ($ticket['id'] ?? 0);
        if (self::ehGestao($user)) {
            $setores = self::setoresDoUsuario($user) ?? [];
            if ($setores === []) {
                return false;
            }
            $stmt = $db->prepare("SELECT 1 FROM ticket_setor_log WHERE ticket_id = ? AND setor_id IN (" . implode(',', array_map('intval', $setores)) . ") LIMIT 1");
            $stmt->execute([$ticketId]);
            return (bool) $stmt->fetchColumn();
        }
        if ($tipo === 'operador') {
            if ((int) ($ticket['fechado_por'] ?? 0) === (int) $user['id']) {
                return true;
            }
            $stmt = $db->prepare("SELECT 1 FROM ticket_agente_log WHERE ticket_id = ? AND usuario_id = ? LIMIT 1");
            $stmt->execute([$ticketId, (int) $user['id']]);
            return (bool) $stmt->fetchColumn();
        }
        return false;
    }

    /**
     * Carrega a demanda e exige permissão de leitura, inclusive histórica (404/403).
     */
    public static function exigirLerTicket(PDO $db, array $user, $ticketId): array {
        $ticket = self::carregarTicketOuFalhar($db, $ticketId);
        if (!self::podeLerTicket($db, $user, $ticket)) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso não autorizado a esta demanda']);
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
        $pedido = $_GET['setor_id'] ?? '';
        $pedido = $pedido !== '' && is_numeric($pedido) ? (int) $pedido : null;
        if (self::ehVisaoTotal($user)) {
            return $pedido;
        }
        $proprio = isset($user['setor_id']) && $user['setor_id'] !== null ? (int) $user['setor_id'] : null;
        // Coordenador: pode escolher qualquer um dos seus setores; fora da lista, vale o principal
        if (($user['tipo'] ?? '') === 'coordenador' && $pedido !== null && self::temAcessoAoSetor($user, $pedido)) {
            return $pedido;
        }
        return $proprio;
    }

    /**
     * Escopo de setores da requisição, para listagens e indicadores (várias linhas de vários setores).
     * - visão total: ?setor_id= escolhe um setor; vazio = null (todos);
     * - coordenador: ?setor_id= precisa ser um dos seus (senão 403); vazio = todos os seus;
     * - gestor/operador/cliente: sempre o próprio setor, ignorando o parâmetro.
     * Use com filtroSetorSql(). Lista vazia = o usuário não tem setor (não vê nada).
     *
     * @return int[]|null null = todos os setores
     */
    public static function escopoSetores(array $user): ?array {
        $pedido = $_GET['setor_id'] ?? '';
        $pedido = $pedido !== '' && is_numeric($pedido) ? (int) $pedido : null;
        $meus = self::setoresDoUsuario($user);

        if ($meus === null) {
            return $pedido !== null ? [$pedido] : null;
        }
        if (($user['tipo'] ?? '') === 'coordenador' && $pedido !== null) {
            if (!in_array($pedido, $meus, true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Você não tem acesso a este setor']);
                exit;
            }
            return [$pedido];
        }
        return $meus;
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
            // Gestores do setor e o coordenador dele (setor principal ou adicional)
            $stmt = $db->prepare("SELECT id, email, nome FROM usuarios
                                  WHERE ativo = 1 AND (
                                      (tipo = 'gestor' AND setor_id = ?)
                                      OR (tipo = 'coordenador' AND (setor_id = ?
                                          OR id IN (SELECT usuario_id FROM usuario_setores WHERE setor_id = ?)))
                                  )");
            $stmt->execute([$setorId, $setorId, $setorId]);
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
    public static function devolverDemandasDoResponsavel(PDO $db, int $usuarioId, int $autorId, string $motivo, ?int $apenasSetorId = null): int {
        $arquivo = "'" . implode("','", self::STATUS_ARQUIVO) . "'";
        // $apenasSetorId: o coordenador perdeu só um setor adicional; as demandas dos outros setores ficam com ele
        $filtroSetor = $apenasSetorId !== null ? ' AND t.setor_id = ' . (int) $apenasSetorId : '';
        $stmt = $db->prepare("SELECT t.id, t.setor_id, u.nome AS responsavel_nome
                              FROM tickets t INNER JOIN usuarios u ON u.id = t.agente_id
                              WHERE t.agente_id = ? AND t.status NOT IN ($arquivo)$filtroSetor");
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
            $mensagem = 'A demanda (' . ProtocoloService::rotuloPorId($db, $ticketId) . ') voltou para o setor: ' . $motivo . '.';
            foreach (self::destinatariosInternosDoSetor($db, $d['setor_id'] ? (int) $d['setor_id'] : null, $usuarioId) as $dest) {
                $notif->execute([$dest['id'], $mensagem, $ticketId]);
            }
        }

        // Perdeu só um setor: os lembretes das demandas devolvidas saem; os demais continuam valendo
        if ($apenasSetorId === null) {
            $db->prepare("UPDATE lembretes SET cancelado_em = NOW(), cancelado_motivo = ?
                          WHERE agente_id = ? AND notificado = 0 AND cancelado_em IS NULL")
               ->execute([mb_substr('Destinatário ' . $motivo, 0, 255), $usuarioId]);
        } elseif ($demandas) {
            $ids = implode(',', array_map(fn($d) => (int) $d['id'], $demandas));
            $db->prepare("UPDATE lembretes SET cancelado_em = NOW(), cancelado_motivo = ?
                          WHERE agente_id = ? AND notificado = 0 AND cancelado_em IS NULL AND ticket_id IN ($ids)")
               ->execute([mb_substr('Destinatário ' . $motivo, 0, 255), $usuarioId]);
        }

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
