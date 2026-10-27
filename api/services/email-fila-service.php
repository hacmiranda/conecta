<?php
/**
 * Fila de e-mails: as requisições só gravam em email_fila; este serviço (chamado pelo cron) envia.
 * Falhas são repetidas com espera crescente (até MAX_TENTATIVAS); depois a mensagem fica como
 * 'falha' e, havendo demanda, o histórico dela registra que o e-mail não saiu.
 */
require_once __DIR__ . '/email-sender.php';

class EmailFilaService {
    const MAX_TENTATIVAS = 5;
    const LOTE = 30;
    // Mensagem presa em "enviando" (processo interrompido) volta para a fila depois disso
    const MINUTOS_ENVIANDO_PRESO = 10;

    /**
     * Envia as mensagens vencidas da fila. Devolve ['enviados' => n, 'falhas' => n, 'pendentes' => n].
     */
    public static function processar(PDO $db, int $limite = self::LOTE): array {
        $resumo = ['enviados' => 0, 'falhas' => 0, 'pendentes' => 0];

        $db->exec("UPDATE email_fila SET status = 'pendente'
                   WHERE status = 'enviando'
                     AND proxima_tentativa < DATE_SUB(NOW(), INTERVAL " . self::MINUTOS_ENVIANDO_PRESO . " MINUTE)");

        $ids = $db->query("SELECT id FROM email_fila
                           WHERE status = 'pendente' AND proxima_tentativa <= NOW()
                           ORDER BY id LIMIT " . (int) $limite)->fetchAll(PDO::FETCH_COLUMN);

        $sender = new EmailSender($db);
        $sender->usarFila(false);

        $reserva = $db->prepare("UPDATE email_fila SET status = 'enviando', tentativas = tentativas + 1, proxima_tentativa = NOW()
                                 WHERE id = ? AND status = 'pendente'");
        $busca = $db->prepare("SELECT * FROM email_fila WHERE id = ?");
        $ok = $db->prepare("UPDATE email_fila SET status = 'enviado', enviado_em = NOW(), erro = NULL WHERE id = ?");
        $volta = $db->prepare("UPDATE email_fila SET status = 'pendente', erro = ?, proxima_tentativa = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
        $desiste = $db->prepare("UPDATE email_fila SET status = 'falha', erro = ? WHERE id = ?");

        foreach ($ids as $id) {
            // Reserva atômica: dois crons simultâneos não enviam a mesma mensagem
            $reserva->execute([$id]);
            if ($reserva->rowCount() !== 1) {
                continue;
            }
            $busca->execute([$id]);
            $msg = $busca->fetch(PDO::FETCH_ASSOC);
            if (!$msg) {
                continue;
            }

            $erro = null;
            $enviado = false;
            try {
                $sender->usarRemetenteDoSetor($msg['setor_id'] !== null ? (int) $msg['setor_id'] : null);
                $sender->setEmailContext($msg['ticket_id'] !== null ? (int) $msg['ticket_id'] : null, $msg['tipo']);
                $anexos = $msg['anexos'] ? (json_decode($msg['anexos'], true) ?: []) : [];
                $enviado = $sender->enviarAgora($msg['destinatario'], $msg['assunto'], $msg['corpo'], (bool) $msg['eh_html'], $anexos);
                if (!$enviado) {
                    $erro = 'O servidor SMTP recusou ou não respondeu';
                }
            } catch (Throwable $e) {
                $erro = mb_substr($e->getMessage(), 0, 500);
            }

            if ($enviado) {
                $ok->execute([$id]);
                $resumo['enviados']++;
                continue;
            }

            if ((int) $msg['tentativas'] >= self::MAX_TENTATIVAS) {
                $desiste->execute([$erro, $id]);
                $resumo['falhas']++;
                self::registrarFalhaNoHistorico($db, $msg, (string) $erro);
            } else {
                // Espera crescente: 2, 4, 6... minutos
                $volta->execute([$erro, 2 * (int) $msg['tentativas'], $id]);
                $resumo['pendentes']++;
            }
        }

        // Remove da fila o que já saiu há mais de 30 dias (o rastro fica em email_logs)
        $db->exec("DELETE FROM email_fila WHERE status = 'enviado' AND enviado_em < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        // Desistências também saem depois de 30 dias (já ficaram na linha do tempo e em email_logs)
        $db->exec("DELETE FROM email_fila WHERE status = 'falha' AND criado_em < DATE_SUB(NOW(), INTERVAL 30 DAY)");

        return $resumo;
    }

    /**
     * Avisa na linha do tempo da demanda que o e-mail não pôde ser entregue.
     */
    private static function registrarFalhaNoHistorico(PDO $db, array $msg, string $erro): void {
        // Qualquer e-mail ligado a uma demanda (antes só 3 tipos; confirmação, aviso à equipe e
        // reabertura que não saíam não deixavam rastro na linha do tempo)
        if (empty($msg['ticket_id'])) {
            return;
        }
        try {
            // Autor do evento: o usuário que abriu a demanda (a mensagem não guarda quem a enviou)
            $stmt = $db->prepare("SELECT usuario_id FROM tickets WHERE id = ?");
            $stmt->execute([(int) $msg['ticket_id']]);
            $usuarioId = $stmt->fetchColumn();
            if (!$usuarioId) {
                return;
            }
            $texto = 'Não foi possível entregar o e-mail a ' . $msg['destinatario'] . ' após ' . self::MAX_TENTATIVAS . ' tentativas.';
            $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_novo) VALUES (?, ?, 'email_falha', ?)")
               ->execute([(int) $msg['ticket_id'], (int) $usuarioId, mb_substr($texto, 0, 255)]);
        } catch (Throwable $e) {
            error_log('[EMAIL-FILA] Erro ao registrar a falha no histórico: ' . $e->getMessage());
        }
    }
}
