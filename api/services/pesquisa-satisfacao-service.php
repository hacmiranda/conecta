<?php
/**
 * Pesquisa de satisfação ao fim de uma demanda (módulo Satisfação do Conecta).
 *
 * Quando a demanda de um solicitante externo (e-mail fora dos domínios internos, padrão crprs.org.br)
 * chega pela primeira vez a resolvido/encerrado, registrar() grava um pedido em pesquisa_satisfacao.
 * processarFila() (chamado por api/cron/enviar-fila.php) cria o convite de avaliação
 * (PesquisaConviteService: quarentena, token, e-mail na fila de envio), atribuído ao responsável da
 * demanda (ou a quem a encerrou). A fila de pedidos existe para o gatilho nunca atrapalhar a troca de
 * status e para repetir se o e-mail não puder ser enfileirado.
 *
 * Uma pesquisa por demanda (ticket_id único). A mesclagem e o cancelamento não disparam.
 * Pode ser desligada por setor: código sol_pesquisa_satisfacao do NotificacaoEmailService.
 */
require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/notificacao-email-service.php';
require_once __DIR__ . '/protocolo-service.php';
require_once __DIR__ . '/log-service.php';
require_once __DIR__ . '/pesquisa-convite-service.php';

class PesquisaSatisfacaoService {
    const CODIGO_EMAIL = 'sol_pesquisa_satisfacao';
    const STATUS_GATILHO = ['resolvido', 'encerrado'];
    const MAX_TENTATIVAS = 5;
    const CANAL_LOG = 'pesquisa-satisfacao';

    /**
     * Registra o pedido de pesquisa da demanda, se ela for elegível. Devolve true se registrou agora
     * (false se não é elegível ou se a demanda já tinha pedido). Nunca lança: falha só vai para o log.
     */
    public static function registrar(PDO $db, int $ticketId): bool {
        try {
            $stmt = $db->prepare("SELECT t.id, t.protocolo, t.setor_id, t.status, t.excluido_em, t.email_solicitante,
                                         t.agente_id, t.fechado_por,
                                         u.nome AS usuario_nome, u.email AS usuario_email,
                                         s.nome AS setor_nome,
                                         COALESCE(ag.email, fp.email) AS responsavel_email
                                  FROM tickets t
                                  LEFT JOIN usuarios u ON u.id = t.usuario_id
                                  LEFT JOIN setores s ON s.id = t.setor_id
                                  LEFT JOIN usuarios ag ON ag.id = t.agente_id
                                  LEFT JOIN usuarios fp ON fp.id = t.fechado_por
                                  WHERE t.id = ?");
            $stmt->execute([$ticketId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t || $t['excluido_em'] !== null || !in_array($t['status'], self::STATUS_GATILHO, true)) {
                return false;
            }
            if (!NotificacaoEmailService::ativa($db, $t['setor_id'] ? (int) $t['setor_id'] : null, self::CODIGO_EMAIL)) {
                return false;
            }

            // Destinatário: a mesma regra do e-mail de encerramento (solicitante informado; senão, o dono da demanda)
            $destino = trim((string) ($t['email_solicitante'] ?? '')) ?: trim((string) ($t['usuario_email'] ?? ''));
            if (!self::emailExterno($destino, self::dominiosInternos()) || self::ehCaixaDeSetor($db, $destino)) {
                return false;
            }

            // Nome: o do dono da demanda, se o destinatário for ele; senão, sai do início do e-mail
            $nome = strcasecmp($destino, (string) ($t['usuario_email'] ?? '')) === 0 ? (string) $t['usuario_nome'] : '';

            $ins = $db->prepare("INSERT IGNORE INTO pesquisa_satisfacao
                                    (ticket_id, protocolo, destinatario_email, destinatario_nome, responsavel_email, setor_nome)
                                 VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $ticketId,
                $t['protocolo'],
                mb_substr($destino, 0, 255),
                $nome !== '' ? mb_substr($nome, 0, 150) : null,
                $t['responsavel_email'],
                $t['setor_nome'] !== null ? mb_substr((string) $t['setor_nome'], 0, 100) : null,
            ]);
            return $ins->rowCount() === 1;
        } catch (Throwable $e) {
            error_log('[PESQUISA SATISFACAO] Erro ao registrar a demanda #' . $ticketId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cria o convite dos pedidos pendentes cuja hora chegou.
     *
     * @param callable|null $enviarConvite (usuarioId, nome, e-mail, origem, desfazerSeFalhar): array — troca o envio nos testes
     * @return array{enviados:int, nao_enviados:int, pendentes:int, falhas:int}
     */
    public static function processarFila(PDO $db, int $lote = 20, ?callable $enviarConvite = null): array {
        $resumo = ['enviados' => 0, 'nao_enviados' => 0, 'pendentes' => 0, 'falhas' => 0];

        $enviarConvite ??= fn(int $usuarioId, string $nome, string $email, array $origem, bool $desfazer) =>
            (new PesquisaConviteService($db))->enviar($usuarioId, $nome, $email, $origem, $desfazer);

        // Junto, o estado atual da demanda: o pedido pode ter deixado de valer desde que foi registrado
        $stmt = $db->prepare("SELECT p.*, t.status AS demanda_status, t.excluido_em AS demanda_excluida,
                                     t.mesclada_em AS demanda_mesclada, t.setor_id AS demanda_setor,
                                     t.agente_id AS demanda_agente, t.fechado_por AS demanda_fechado_por
                              FROM pesquisa_satisfacao p
                              LEFT JOIN tickets t ON t.id = p.ticket_id
                              WHERE p.status = 'pendente' AND p.proxima_tentativa_em <= NOW()
                              ORDER BY p.id LIMIT " . max(1, min(100, $lote)));
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $motivoCancelar = self::motivoParaCancelar($db, $p);
            if ($motivoCancelar !== null) {
                self::finalizar($db, (int) $p['id'], 'cancelada', (int) $p['tentativas'], $motivoCancelar);
                $resumo['nao_enviados']++;
                continue;
            }

            // A nota vai para o responsável da demanda; sem responsável, para quem a encerrou
            $usuarioId = (int) ($p['demanda_agente'] ?: $p['demanda_fechado_por']);
            if ($usuarioId <= 0) {
                self::finalizar($db, (int) $p['id'], 'sem_funcionario', (int) $p['tentativas'], 'Demanda sem responsável');
                $resumo['nao_enviados']++;
                continue;
            }

            $tentativa = (int) $p['tentativas'] + 1;
            try {
                $resposta = $enviarConvite(
                    $usuarioId,
                    self::nomeParaConvite((string) ($p['destinatario_nome'] ?? ''), (string) $p['destinatario_email']),
                    (string) $p['destinatario_email'],
                    [
                        'origem'           => 'demanda',
                        'ticket_id'        => (int) $p['ticket_id'],
                        'protocolo'        => ProtocoloService::rotulo($p['protocolo'], (int) $p['ticket_id']),
                        'chave_integracao' => 'demanda:' . (int) $p['ticket_id'],
                        'setor_id'         => $p['demanda_setor'] !== null ? (int) $p['demanda_setor'] : null,
                    ],
                    true
                );
                $resultado = self::interpretarResultado((string) ($resposta['status'] ?? ''));
            } catch (Throwable $e) {
                $resultado = ['status' => 'tentar_de_novo', 'erro' => 'Erro ao criar o convite: ' . $e->getMessage()];
            }

            if ($resultado['status'] === 'tentar_de_novo') {
                if ($tentativa >= self::MAX_TENTATIVAS) {
                    self::finalizar($db, (int) $p['id'], 'falha', $tentativa, $resultado['erro']);
                    LogService::erro(self::CANAL_LOG, "Demanda #{$p['ticket_id']}: desistência após {$tentativa} tentativas ({$resultado['erro']})");
                    $resumo['falhas']++;
                } else {
                    // Espera crescente entre as tentativas: 5, 15, 45, 135 minutos
                    $minutos = 5 * (3 ** ($tentativa - 1));
                    $db->prepare("UPDATE pesquisa_satisfacao SET tentativas = ?, ultimo_erro = ?,
                                         proxima_tentativa_em = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?")
                       ->execute([$tentativa, mb_substr($resultado['erro'], 0, 255), $minutos, (int) $p['id']]);
                    $resumo['pendentes']++;
                }
                continue;
            }

            self::finalizar($db, (int) $p['id'], $resultado['status'], $tentativa, $resultado['erro']);
            if ($resultado['status'] === 'enviado') {
                self::registrarNoHistorico($db, (int) $p['ticket_id']);
                $resumo['enviados']++;
            } else {
                // quarentena: fica registrado, sem nova tentativa
                LogService::info(self::CANAL_LOG, "Demanda #{$p['ticket_id']}: pesquisa não enviada ({$resultado['status']})");
                $resumo['nao_enviados']++;
            }
        }
        return $resumo;
    }

    /**
     * Traduz o resultado de PesquisaConviteService::enviar() em status da fila. Função pura (testável).
     * @return array{status:string, erro:?string}  status: enviado | quarentena | tentar_de_novo
     */
    public static function interpretarResultado(string $status): array {
        return match ($status) {
            'enviado', 'ja_existe' => ['status' => 'enviado', 'erro' => null],
            'quarentena'           => ['status' => 'quarentena', 'erro' => null],
            default                => ['status' => 'tentar_de_novo', 'erro' => $status === 'falha_email'
                                          ? 'Não foi possível enfileirar o e-mail do convite'
                                          : 'Resultado inesperado ao criar o convite (' . ($status ?: 'vazio') . ')'],
        };
    }

    /** Nome para o convite: o informado ou, sem ele, o início do e-mail ("maria.silva@x" → "Maria Silva"). Função pura. */
    public static function nomeParaConvite(string $nome, string $email): string {
        $nome = trim($nome);
        if ($nome !== '') {
            return mb_substr($nome, 0, 150);
        }
        $inicio = trim((string) strstr($email, '@', true));
        $nome = ucwords(trim((string) preg_replace('/[._\-]+/', ' ', $inicio)));
        return $nome !== '' ? mb_substr($nome, 0, 150) : 'Solicitante';
    }

    /**
     * Verdadeiro se o e-mail é válido e não pertence a um domínio interno (nem a um subdomínio dele).
     * Função pura (testável).
     */
    public static function emailExterno(string $email, array $dominiosInternos): bool {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $dominio = strtolower(substr(strrchr($email, '@'), 1));
        foreach ($dominiosInternos as $interno) {
            $interno = strtolower(trim((string) $interno));
            if ($interno !== '' && ($dominio === $interno || str_ends_with($dominio, '.' . $interno))) {
                return false;
            }
        }
        return true;
    }

    /** Domínios internos (PESQUISA_DOMINIOS_INTERNOS no .env, separados por vírgula). Padrão: crprs.org.br. */
    public static function dominiosInternos(): array {
        $valor = (string) Environment::get('pesquisa_dominios_internos', '');
        $lista = array_filter(array_map('trim', explode(',', $valor)));
        return $lista ?: ['crprs.org.br'];
    }

    /**
     * O pedido ainda vale? (null = sim). Reconfere a demanda na hora do envio, que pode ser horas depois
     * do registro (novas tentativas): na lixeira, reaberta/cancelada, mesclada, ou pesquisa desligada no setor.
     */
    private static function motivoParaCancelar(PDO $db, array $p): ?string {
        if ($p['demanda_status'] === null) {
            return 'Demanda excluída';
        }
        if ($p['demanda_excluida'] !== null) {
            return 'Demanda na lixeira';
        }
        if (!empty($p['demanda_mesclada'])) {
            return 'Demanda mesclada em outra';
        }
        if (!in_array($p['demanda_status'], self::STATUS_GATILHO, true)) {
            return 'Demanda não está mais resolvida/encerrada (' . $p['demanda_status'] . ')';
        }
        if (!NotificacaoEmailService::ativa($db, $p['demanda_setor'] !== null ? (int) $p['demanda_setor'] : null, self::CODIGO_EMAIL)) {
            return 'Pesquisa desligada no setor';
        }
        return null;
    }

    /** Caixas de e-mail dos setores nunca recebem pesquisa (e-mail do próprio sistema). */
    private static function ehCaixaDeSetor(PDO $db, string $email): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM setor_emails WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function finalizar(PDO $db, int $id, string $status, int $tentativas, ?string $erro): void {
        $db->prepare("UPDATE pesquisa_satisfacao SET status = ?, tentativas = ?, ultimo_erro = ?, processado_em = NOW() WHERE id = ?")
           ->execute([$status, $tentativas, $erro !== null ? mb_substr($erro, 0, 255) : null, $id]);
    }

    /** Linha do tempo da demanda: autor = responsável (ou quem encerrou), a quem a nota é atribuída. */
    private static function registrarNoHistorico(PDO $db, int $ticketId): void {
        try {
            $stmt = $db->prepare("SELECT COALESCE(agente_id, fechado_por, usuario_id) FROM tickets WHERE id = ?");
            $stmt->execute([$ticketId]);
            $autor = $stmt->fetchColumn();
            if (!$autor) {
                return;
            }
            $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_novo) VALUES (?, ?, 'pesquisa_satisfacao', ?)")
               ->execute([$ticketId, (int) $autor, 'Pesquisa de satisfação enviada ao solicitante']);
        } catch (Throwable $e) {
            error_log('[PESQUISA SATISFACAO] Erro ao registrar no histórico: ' . $e->getMessage());
        }
    }
}
