<?php
/**
 * Exclusão de demandas: lixeira (exclusão lógica) e exclusão definitiva.
 *
 * - Qualquer perfil que possa operar a demanda a envia para a lixeira (excluido_em/excluido_por):
 *   some das listas, relatórios e painéis; só o administrador a vê, restaura ou exclui de vez.
 * - A exclusão definitiva (somente administrador, somente de itens já na lixeira) apaga a
 *   demanda e, por cascata, comentários, anexos, histórico, SLA, lembretes e leituras; os
 *   arquivos dos anexos também são removidos do disco.
 */
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/lembrete-service.php';
require_once __DIR__ . '/auditoria-service.php';

class DemandaService {

    public static function enviarParaLixeira(PDO $db, array $ticket, int $autorId): void {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE tickets SET excluido_em = NOW(), excluido_por = ? WHERE id = ? AND excluido_em IS NULL")
               ->execute([$autorId, (int) $ticket['id']]);
            $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo)
                          VALUES (?, ?, 'exclusao', NULL, 'Enviada para a lixeira')")
               ->execute([(int) $ticket['id'], $autorId]);
            LembreteService::cancelarPendentes($db, (int) $ticket['id'], 'Demanda excluída');
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        (new AuditoriaService($db))->registrar($autorId, 'excluir_ticket', 'tickets', (int) $ticket['id'],
            json_encode(['titulo' => $ticket['titulo'] ?? null, 'status' => $ticket['status'] ?? null, 'setor_id' => $ticket['setor_id'] ?? null]),
            json_encode(['lixeira' => true]));
    }

    public static function restaurar(PDO $db, array $ticket, int $autorId): void {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE tickets SET excluido_em = NULL, excluido_por = NULL WHERE id = ?")->execute([(int) $ticket['id']]);
            $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo)
                          VALUES (?, ?, 'restauracao', NULL, 'Restaurada da lixeira')")
               ->execute([(int) $ticket['id'], $autorId]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        (new AuditoriaService($db))->registrar($autorId, 'restaurar_ticket', 'tickets', (int) $ticket['id'], json_encode(['lixeira' => true]), null);
    }

    /**
     * Apaga de vez uma demanda que está na lixeira, junto com os arquivos dos anexos.
     */
    public static function excluirDefinitivo(PDO $db, array $ticket, int $autorId): void {
        $ticketId = (int) $ticket['id'];

        $stmt = $db->prepare("SELECT nome_arquivo FROM anexos WHERE ticket_id = ?");
        $stmt->execute([$ticketId]);
        $arquivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $dados = $db->prepare("SELECT titulo, descricao, status, prioridade, setor_id, categoria_id, agente_id, usuario_id, criado_em
                               FROM tickets WHERE id = ?");
        $dados->execute([$ticketId]);
        $resumo = $dados->fetch(PDO::FETCH_ASSOC);

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM notificacoes WHERE ticket_id = ?")->execute([$ticketId]);
            $db->prepare("DELETE FROM tickets WHERE id = ? AND excluido_em IS NOT NULL")->execute([$ticketId]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        // Só depois do commit: remove os arquivos do disco
        $base = realpath(dirname(__DIR__, 2) . '/backend/anexos');
        if ($base !== false) {
            foreach ($arquivos as $nome) {
                $caminho = $base . DIRECTORY_SEPARATOR . basename((string) $nome);
                if (is_file($caminho)) {
                    @unlink($caminho);
                }
            }
        }

        (new AuditoriaService($db))->registrar($autorId, 'excluir_ticket_definitivo', 'tickets', $ticketId, json_encode($resumo), null);
    }
}
