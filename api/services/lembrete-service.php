<?php
/**
 * Lembretes de demanda: disparo dos vencidos e cancelamentos automáticos.
 *
 * O disparo roda só pelo agendador (api/cron/verificar-lembretes.php, chamado também ao
 * final de verificar-emails.php). Regras:
 * - Lembrete de demanda encerrada/cancelada é cancelado (não envia).
 * - Lembrete direcionado a alguém que não é mais do setor da demanda (ex.: demanda
 *   transferida) é cancelado.
 * - Cada lembrete é "reservado" com um UPDATE atômico antes de enviar: execuções
 *   simultâneas nunca enviam o mesmo lembrete duas vezes.
 */
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/sla-service.php';
require_once __DIR__ . '/auditoria-service.php';
require_once __DIR__ . '/protocolo-service.php';
require_once __DIR__ . '/notificacao-email-service.php';

class LembreteService {

    /**
     * Cancela os lembretes pendentes de uma demanda.
     * $somenteDirecionados = true cancela só os que têm destinatário escolhido (transferência de setor).
     *
     * @return int quantidade cancelada
     */
    public static function cancelarPendentes(PDO $db, int $ticketId, string $motivo, bool $somenteDirecionados = false): int {
        $sql = "UPDATE lembretes SET cancelado_em = NOW(), cancelado_motivo = ?
                WHERE ticket_id = ? AND notificado = 0 AND cancelado_em IS NULL";
        if ($somenteDirecionados) {
            $sql .= " AND agente_id IS NOT NULL";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([mb_substr($motivo, 0, 255), $ticketId]);
        return $stmt->rowCount();
    }

    /**
     * Envia os lembretes vencidos. Devolve um resumo (enviados, cancelados, erros).
     */
    public static function dispararVencidos(PDO $db, $emailSender): array {
        $resumo = ['enviados' => 0, 'cancelados' => 0, 'erros' => 0];

        $stmt = $db->query("SELECT l.*, t.titulo AS ticket_titulo, t.protocolo AS ticket_protocolo, t.status AS ticket_status, t.excluido_em AS ticket_excluido,
                                   t.setor_id AS ticket_setor_id, t.agente_id AS ticket_agente_id,
                                   u.nome AS agente_nome, u.email AS agente_email, u.ativo AS agente_ativo,
                                   u.setor_id AS agente_setor_id
                            FROM lembretes l
                            INNER JOIN tickets t ON l.ticket_id = t.id
                            LEFT JOIN usuarios u ON l.agente_id = u.id
                            WHERE l.notificado = 0 AND l.cancelado_em IS NULL
                              AND TIMESTAMP(l.data_lembrete, l.hora_lembrete) <= NOW()
                            ORDER BY l.data_lembrete, l.hora_lembrete");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lembrete) {
            $id = (int) $lembrete['id'];
            $ticketId = (int) $lembrete['ticket_id'];

            // Demanda arquivada ou excluída: cancela em vez de avisar
            if (in_array($lembrete['ticket_status'], AcessoService::STATUS_ARQUIVO, true) || !empty($lembrete['ticket_excluido'])) {
                if (self::cancelar($db, $id, 'Demanda encerrada, cancelada ou excluída antes do lembrete')) {
                    $resumo['cancelados']++;
                }
                continue;
            }

            // Direcionado a quem não está mais (ativo) no setor da demanda: cancela
            if (!empty($lembrete['agente_id'])
                && (!(int) $lembrete['agente_ativo'] || (int) $lembrete['agente_setor_id'] !== (int) $lembrete['ticket_setor_id'])) {
                if (self::cancelar($db, $id, 'Destinatário não pertence mais ao setor da demanda')) {
                    $resumo['cancelados']++;
                }
                continue;
            }

            // Reserva atômica: só quem conseguir marcar como notificado envia
            $reserva = $db->prepare("UPDATE lembretes SET notificado = 1 WHERE id = ? AND notificado = 0 AND cancelado_em IS NULL");
            $reserva->execute([$id]);
            if ($reserva->rowCount() === 0) {
                continue;
            }

            try {
                self::avisar($db, $emailSender, $lembrete);
                self::retomarSeAguardandoData($db, $lembrete);
                $resumo['enviados']++;
            } catch (Exception $e) {
                $resumo['erros']++;
                error_log('[LEMBRETES] Erro ao enviar o lembrete #' . $id . ': ' . $e->getMessage());
                // Não avisou: devolve o lembrete para a próxima execução (antes ficava marcado como enviado)
                $db->prepare("UPDATE lembretes SET notificado = 0 WHERE id = ?")->execute([$id]);
            }
        }

        return $resumo;
    }

    private static function cancelar(PDO $db, int $id, string $motivo): bool {
        $stmt = $db->prepare("UPDATE lembretes SET cancelado_em = NOW(), cancelado_motivo = ?
                              WHERE id = ? AND notificado = 0 AND cancelado_em IS NULL");
        $stmt->execute([$motivo, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * E-mail + notificação no sino para o destinatário escolhido ou, sem destinatário,
     * para o responsável da demanda (ou os gestores do setor).
     */
    private static function avisar(PDO $db, $emailSender, array $lembrete): void {
        $rotulo = ProtocoloService::rotulo($lembrete['ticket_protocolo'] ?? null, (int) $lembrete['ticket_id']);
        $titulo = (string) $lembrete['ticket_titulo'];
        $descricao = (string) $lembrete['descricao'];
        $quando = date('d/m/Y H:i', strtotime($lembrete['data_lembrete'] . ' ' . $lembrete['hora_lembrete']));
        $e = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };

        $assunto = "🔔 [Lembrete] Demanda {$rotulo} - {$titulo}";
        $mensagem = "
            <h2>🔔 Lembrete de Demanda</h2>
            <p><strong>Demanda:</strong> {$e($rotulo)} - {$e($titulo)}</p>
            <p><strong>Lembrete:</strong> {$e($descricao)}</p>
            <p><strong>Data/Hora:</strong> {$quando}</p>
            <p><a href='" . $e(rtrim((string) Environment::get('base_url'), '/') . '/frontend/index.html') . "'>Acessar o Conecta CRPRS</a></p>
        ";
        $notifTitulo = mb_substr("🔔 Lembrete: Demanda {$rotulo} - {$titulo}", 0, 255);
        $notifMsg = 'Seu lembrete agendado: ' . $descricao;

        if (!empty($lembrete['agente_id'])) {
            $destinatarios = [['id' => (int) $lembrete['agente_id'], 'email' => $lembrete['agente_email']]];
        } else {
            $destinatarios = AcessoService::destinatariosDaDemanda($db, [
                'agente_id' => $lembrete['ticket_agente_id'],
                'setor_id'  => $lembrete['ticket_setor_id'],
            ]);
        }

        // E-mail desligado no setor atual da demanda? O sino continua avisando de qualquer jeito.
        $setorAtual = $lembrete['ticket_setor_id'] ? (int) $lembrete['ticket_setor_id'] : null;
        $emailLigado = NotificacaoEmailService::ativa($db, $setorAtual, 'int_lembrete');

        $notif = $db->prepare("INSERT INTO notificacoes (usuario_id, titulo, mensagem, ticket_id) VALUES (?, ?, ?, ?)");
        foreach ($destinatarios as $dest) {
            if ($emailLigado && !empty($dest['email'])) {
                $emailSender->enviarEmail($dest['email'], $assunto, $mensagem);
            }
            $notif->execute([$dest['id'], $notifTitulo, $notifMsg, $lembrete['ticket_id']]);
        }
    }

    /**
     * Demanda em "Aguardando data" volta para "Em atendimento" quando o lembrete vence:
     * retoma o SLA pausado e registra no histórico e na auditoria.
     */
    private static function retomarSeAguardandoData(PDO $db, array $lembrete): void {
        if ($lembrete['ticket_status'] !== 'aguardando_data') {
            return;
        }
        $ticketId = (int) $lembrete['ticket_id'];
        $upd = $db->prepare("UPDATE tickets SET status = 'em_atendimento' WHERE id = ? AND status = 'aguardando_data'");
        $upd->execute([$ticketId]);
        if ($upd->rowCount() === 0) {
            return;
        }

        $setorId = $lembrete['ticket_setor_id'] ? (int) $lembrete['ticket_setor_id'] : null;
        (new SLAService($db))->retomarPausa($ticketId, $setorId);

        // Autor do evento: quem criou o lembrete (a mudança é consequência dele)
        $autor = (int) $lembrete['criado_por'];
        $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo)
                      VALUES (?, ?, 'status', 'aguardando_data', 'em_atendimento')")
           ->execute([$ticketId, $autor]);
        (new AuditoriaService($db))->registrarTicket($ticketId, $autor, 'status', 'aguardando_data', 'em_atendimento');
    }
}
