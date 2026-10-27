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
            // E-mails ainda na fila sobre a demanda excluída não saem mais
            $db->prepare("UPDATE email_fila SET status = 'falha', erro = 'Cancelado: demanda enviada para a lixeira'
                          WHERE ticket_id = ? AND status = 'pendente'")
               ->execute([(int) $ticket['id']]);
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

    /**
     * Troca o status de uma demanda fora da tela (resposta do solicitante, reabertura por e-mail,
     * mesclagem): trava a linha, grava o status e aplica os mesmos efeitos do PUT de tickets.php.
     * Usa a transação de quem chamou ou abre uma própria. Devolve as marcas de efeitos para depois
     * do commit (reaberto/encerrado/pesquisa) ou [] se o status já era esse.
     * $opcoes: veja efeitosDaMudancaDeStatus().
     */
    public static function mudarStatus(PDO $db, int $ticketId, string $novo, int $autorId, array $opcoes = []): array {
        $propria = !$db->inTransaction();
        if ($propria) {
            $db->beginTransaction();
        }
        try {
            $stmt = $db->prepare("SELECT status, setor_id FROM tickets WHERE id = ? FOR UPDATE");
            $stmt->execute([$ticketId]);
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$atual || $atual['status'] === $novo) {
                if ($propria) {
                    $db->commit();
                }
                return [];
            }

            if (in_array($novo, AcessoService::STATUS_ARQUIVO, true)) {
                $db->prepare("UPDATE tickets SET status = ?, fechado_em = NOW(), fechado_por = ? WHERE id = ?")
                   ->execute([$novo, $autorId, $ticketId]);
            } else {
                $db->prepare("UPDATE tickets SET status = ? WHERE id = ?")->execute([$novo, $ticketId]);
            }

            $marcas = self::efeitosDaMudancaDeStatus($db, $ticketId, (string) $atual['status'], $novo, $autorId,
                $atual['setor_id'] !== null ? (int) $atual['setor_id'] : null, $opcoes);
            if ($propria) {
                $db->commit();
            }
            return $marcas;
        } catch (Exception $e) {
            if ($propria && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Efeitos de uma troca de status já gravada em tickets.status (chamar na mesma transação, depois
     * do UPDATE). Regra única para tela, e-mail e mesclagem:
     * - SLA: pausa ao entrar num status que congela; retoma (somando o tempo pausado) ao sair dele;
     * - primeira resposta: ao sair de "novo" por ação da equipe (não pela resposta do solicitante);
     * - resolução: ao chegar a resolvido/encerrado (a primeira vez; resolvido -> encerrado mantém);
     * - reabertura: ao sair de concluída/arquivada, apaga a resolução (o SLA volta a contar) e, se
     *   estava no Arquivo, limpa fechado_em/fechado_por e soma reaberto_contador;
     * - histórico "status", auditoria, janelas de setor/responsável e lembretes.
     * $opcoes: 'resposta_da_equipe' (bool, padrão true), 'agente_reaberto' (?int: responsável ao reabrir;
     * padrão o atual), 'auditar' (bool, padrão true).
     * @return array{reaberto:bool, encerrado:bool, pesquisa:bool}
     */
    public static function efeitosDaMudancaDeStatus(PDO $db, int $ticketId, string $anterior, string $novo, int $autorId,
                                                    ?int $setorId, array $opcoes = []): array {
        require_once __DIR__ . '/sla-service.php';
        require_once __DIR__ . '/pesquisa-satisfacao-service.php';
        $marcas = ['reaberto' => false, 'encerrado' => false, 'pesquisa' => false];
        if ($anterior === $novo) {
            return $marcas;
        }
        $sla = new SLAService($db);

        // SLA: pausa / retomada
        $congelaNovo = $sla->congelaSla($novo);
        $congelaAnterior = $sla->congelaSla($anterior);
        if ($congelaNovo && !$congelaAnterior) {
            $db->prepare("UPDATE ticket_sla SET pausado = TRUE, pausado_em = NOW() WHERE ticket_id = ? AND pausado_em IS NULL")->execute([$ticketId]);
        } elseif (!$congelaNovo && $congelaAnterior) {
            $sla->retomarPausa($ticketId, $setorId);
        }

        // Primeira resposta: a equipe tirou a demanda de "novo"
        if ($anterior === 'novo' && ($opcoes['resposta_da_equipe'] ?? true)) {
            self::marcarPrimeiraResposta($db, $ticketId, $setorId);
        }

        $eraConcluida = in_array($anterior, array_merge(AcessoService::STATUS_CONCLUIDOS, AcessoService::STATUS_ARQUIVO), true);
        $vaiConcluir = in_array($novo, AcessoService::STATUS_CONCLUIDOS, true);
        $vaiArquivar = in_array($novo, AcessoService::STATUS_ARQUIVO, true);
        $eraArquivada = in_array($anterior, AcessoService::STATUS_ARQUIVO, true);

        // Resolução: a primeira vez que chega a resolvido/encerrado (desconta o tempo pausado)
        if ($vaiConcluir) {
            $dados = $db->prepare("SELECT t.criado_em, COALESCE(s.tempo_pausado_segundos, 0) AS pausado
                                   FROM tickets t LEFT JOIN ticket_sla s ON s.ticket_id = t.id WHERE t.id = ?");
            $dados->execute([$ticketId]);
            $d = $dados->fetch(PDO::FETCH_ASSOC);
            if ($d && $d['criado_em']) {
                $total = $sla->calcularSegundosUteis($d['criado_em'], date('Y-m-d H:i:s'), $setorId);
                $db->prepare("UPDATE ticket_sla SET resolvido_em = NOW(), tempo_resolucao_segundos = ? WHERE ticket_id = ? AND resolvido_em IS NULL")
                   ->execute([max(0, $total - (int) $d['pausado']), $ticketId]);
            }
        }

        // Reabertura: saiu de concluída/arquivada para um status em andamento
        if ($eraConcluida && !$vaiConcluir && !$vaiArquivar) {
            $db->prepare("UPDATE ticket_sla SET resolvido_em = NULL, tempo_resolucao_segundos = 0 WHERE ticket_id = ?")->execute([$ticketId]);
            if ($eraArquivada) {
                $db->prepare("UPDATE tickets SET fechado_em = NULL, fechado_por = NULL, reaberto_contador = COALESCE(reaberto_contador, 0) + 1 WHERE id = ?")
                   ->execute([$ticketId]);
            }
        }

        // Histórico e auditoria
        $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'status', ?, ?)")
           ->execute([$ticketId, $autorId, $anterior, $novo]);
        if ($opcoes['auditar'] ?? true) {
            (new AuditoriaService($db))->registrarTicket($ticketId, $autorId, 'status', $anterior, $novo);
        }

        // Janelas de setor/responsável e lembretes
        if ($vaiArquivar && !$eraArquivada) {
            AcessoService::fecharJanelasDaDemanda($db, $ticketId);
            LembreteService::cancelarPendentes($db, $ticketId, 'Demanda ' . ($novo === 'cancelado' ? 'cancelada' : 'encerrada'));
        } elseif ($eraArquivada && !$vaiArquivar) {
            if (array_key_exists('agente_reaberto', $opcoes)) {
                $agente = $opcoes['agente_reaberto'];
            } else {
                $ag = $db->prepare("SELECT agente_id FROM tickets WHERE id = ?");
                $ag->execute([$ticketId]);
                $agente = $ag->fetchColumn() ?: null;
            }
            AcessoService::reabrirJanelasDaDemanda($db, $ticketId, $setorId, $agente ? (int) $agente : null);
            $marcas['reaberto'] = true;
        }

        $marcas['encerrado'] = $novo === 'encerrado';
        $marcas['pesquisa'] = in_array($novo, PesquisaSatisfacaoService::STATUS_GATILHO, true);
        return $marcas;
    }

    /**
     * Primeira resposta da equipe (SLA de resposta cumprido): grava só se ainda não havia,
     * descontando o tempo pausado. Chamada ao sair de "novo", ao atribuir um responsável e no
     * primeiro comentário da equipe enviado ao solicitante.
     */
    public static function marcarPrimeiraResposta(PDO $db, int $ticketId, ?int $setorId): void {
        require_once __DIR__ . '/sla-service.php';
        $dados = $db->prepare("SELECT t.criado_em, COALESCE(s.tempo_pausado_segundos, 0) AS pausado, s.primeira_resposta_em
                               FROM tickets t LEFT JOIN ticket_sla s ON s.ticket_id = t.id WHERE t.id = ?");
        $dados->execute([$ticketId]);
        $d = $dados->fetch(PDO::FETCH_ASSOC);
        if (!$d || !$d['criado_em'] || !empty($d['primeira_resposta_em'])) {
            return;
        }
        $segundos = (new SLAService($db))->calcularSegundosUteis($d['criado_em'], date('Y-m-d H:i:s'), $setorId);
        $db->prepare("UPDATE ticket_sla SET primeira_resposta_em = NOW(), tempo_primeira_resposta_segundos = ?
                      WHERE ticket_id = ? AND primeira_resposta_em IS NULL")
           ->execute([max(0, $segundos - (int) $d['pausado']), $ticketId]);
    }
}
