<?php
/**
 * Linha do tempo da demanda: junta comentários e histórico de ações (status, prioridade,
 * categoria, atribuições, devoluções, transferências de setor, mesclagens, e-mails enviados)
 * em uma única lista ordenada do evento mais antigo para o mais recente.
 *
 * Cada item traz: tipo ('comentario' | 'historico'), autor_nome, criado_em e, para o
 * histórico, acao/valor_anterior/valor_novo. A abertura da demanda sempre aparece como o
 * primeiro evento (para demandas antigas, sem registro de abertura, ela é sintetizada).
 */
class TimelineService {

    /**
     * @return array{timeline: array, historico: array, comentarios: array}
     */
    public static function montar(PDO $db, int $ticketId): array {
        // Comentários (com anexos de cada um)
        $stmt = $db->prepare("SELECT c.*, u.nome as autor_nome, 'comentario' as tipo
                              FROM comentarios c
                              LEFT JOIN usuarios u ON c.usuario_id = u.id
                              WHERE c.ticket_id = ?
                              ORDER BY c.criado_em ASC, c.id ASC");
        $stmt->execute([$ticketId]);
        $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $anexosStmt = $db->prepare("SELECT * FROM anexos WHERE comentario_id = ?");
        foreach ($comentarios as &$comentario) {
            $anexosStmt->execute([$comentario['id']]);
            $comentario['anexos'] = $anexosStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($comentario);

        // Histórico de ações
        $historico = [];
        try {
            $stmt = $db->prepare("SELECT h.*, u.nome as autor_nome, 'historico' as tipo
                                  FROM ticket_historico h
                                  LEFT JOIN usuarios u ON h.usuario_id = u.id
                                  WHERE h.ticket_id = ?
                                  ORDER BY h.criado_em ASC, h.id ASC");
            $stmt->execute([$ticketId]);
            $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[TIMELINE] Erro ao ler histórico: ' . $e->getMessage());
        }

        // Evento de abertura: sintetizado para demandas anteriores ao registro explícito
        $temAbertura = false;
        foreach ($historico as $h) {
            if (($h['acao'] ?? '') === 'abertura') {
                $temAbertura = true;
                break;
            }
        }
        if (!$temAbertura) {
            $stmt = $db->prepare("SELECT t.criado_em, t.usuario_id, t.origem_api, t.email_solicitante, u.nome as autor_nome
                                  FROM tickets t
                                  LEFT JOIN usuarios u ON t.usuario_id = u.id
                                  WHERE t.id = ?");
            $stmt->execute([$ticketId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($t) {
                $origem = (!empty($t['origem_api']) && $t['origem_api'] !== 'interna')
                    ? 'via integração (' . $t['origem_api'] . ')'
                    : 'no sistema';
                array_unshift($historico, [
                    'id'             => 'abertura-' . $ticketId,
                    'ticket_id'      => $ticketId,
                    'usuario_id'     => $t['usuario_id'],
                    'acao'           => 'abertura',
                    'valor_anterior' => null,
                    'valor_novo'     => $origem,
                    'criado_em'      => $t['criado_em'],
                    'autor_nome'     => $t['autor_nome'],
                    'tipo'           => 'historico',
                ]);
            }
        }

        // Ordem cronológica única (desempate estável: abertura primeiro, depois por tipo)
        $timeline = array_merge($comentarios, $historico);
        usort($timeline, function ($a, $b) {
            $diff = strtotime($a['criado_em']) - strtotime($b['criado_em']);
            if ($diff !== 0) {
                return $diff;
            }
            $aAbertura = ($a['acao'] ?? '') === 'abertura' ? 0 : 1;
            $bAbertura = ($b['acao'] ?? '') === 'abertura' ? 0 : 1;
            return $aAbertura - $bAbertura;
        });

        return ['timeline' => $timeline, 'historico' => $historico, 'comentarios' => $comentarios];
    }
}
