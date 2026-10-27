<?php
/**
 * Pesquisa de satisfação pelo METRICA360.
 *
 * Quando a demanda de um solicitante externo (e-mail fora dos domínios internos, padrão crprs.org.br)
 * chega pela primeira vez a resolvido/encerrado, registrar() grava um pedido em pesquisa_satisfacao.
 * processarFila() (chamado por api/cron/enviar-fila.php) entrega o pedido ao METRICA360
 * (POST {METRICA360_URL}/api/integracao/convite, cabeçalho X-API-KEY). Daí em diante é tudo com o
 * METRICA360: e-mail ao solicitante, quarentena, validade do link, reenvio, notas e painéis.
 *
 * Uma pesquisa por demanda (ticket_id único). A mesclagem e o cancelamento não disparam.
 * Pode ser desligada por setor: código sol_pesquisa_satisfacao do NotificacaoEmailService.
 */
require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/notificacao-email-service.php';
require_once __DIR__ . '/protocolo-service.php';
require_once __DIR__ . '/log-service.php';

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

            // Nome: o do dono da demanda, se o destinatário for ele; senão, o METRICA360 usa o início do e-mail
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
     * Entrega ao METRICA360 os pedidos pendentes cuja hora chegou.
     * @return array{enviados:int, nao_enviados:int, pendentes:int, falhas:int}
     */
    public static function processarFila(PDO $db, int $lote = 20, ?callable $postar = null): array {
        $resumo = ['enviados' => 0, 'nao_enviados' => 0, 'pendentes' => 0, 'falhas' => 0];

        $url = rtrim((string) Environment::get('metrica360_url', ''), '/');
        $chave = (string) Environment::get('metrica360_api_key', '');
        if ($postar === null && ($url === '' || $chave === '')) {
            $qtd = (int) $db->query("SELECT COUNT(*) FROM pesquisa_satisfacao WHERE status = 'pendente'")->fetchColumn();
            if ($qtd > 0) {
                LogService::aviso(self::CANAL_LOG, "{$qtd} pedido(s) aguardando: METRICA360_URL/METRICA360_API_KEY não configurados no .env");
            }
            return $resumo;
        }
        $postar ??= function (array $corpo) use ($url, $chave): array {
            return self::postar($url . '/api/integracao/convite', $chave, $corpo);
        };

        // Junto, o estado atual da demanda: o pedido pode ter deixado de valer desde que foi registrado
        $stmt = $db->prepare("SELECT p.*, t.status AS demanda_status, t.excluido_em AS demanda_excluida,
                                     t.mesclada_em AS demanda_mesclada, t.setor_id AS demanda_setor
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

            // Sem responsável nem quem encerrou: não há a quem atribuir a nota no METRICA360
            if (trim((string) ($p['responsavel_email'] ?? '')) === '') {
                self::finalizar($db, (int) $p['id'], 'sem_funcionario', (int) $p['tentativas'], 'Demanda sem responsável');
                $resumo['nao_enviados']++;
                continue;
            }

            $resposta = $postar([
                'chave_integracao'   => 'conecta:' . (int) $p['ticket_id'],
                'protocolo'          => ProtocoloService::rotulo($p['protocolo'], (int) $p['ticket_id']),
                'destinatario_email' => $p['destinatario_email'],
                'destinatario_nome'  => (string) ($p['destinatario_nome'] ?? ''),
                'responsavel_email'  => (string) ($p['responsavel_email'] ?? ''),
                'setor_nome'         => (string) ($p['setor_nome'] ?? ''),
            ]);
            $tentativa = (int) $p['tentativas'] + 1;
            $resultado = self::interpretarResposta((int) $resposta['http'], (string) ($resposta['corpo'] ?? ''));

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
                // quarentena / sem_funcionario / falha (pedido recusado): fica registrado, sem nova tentativa
                LogService::info(self::CANAL_LOG, "Demanda #{$p['ticket_id']}: pesquisa não enviada ({$resultado['status']})");
                $resumo['nao_enviados']++;
            }
        }
        return $resumo;
    }

    /**
     * Traduz a resposta do METRICA360 em status da fila. Função pura (testável).
     * @return array{status:string, erro:?string}  status: enviado | quarentena | sem_funcionario | falha | tentar_de_novo
     */
    public static function interpretarResposta(int $http, string $corpo): array {
        $dados = json_decode($corpo, true);
        $status = is_array($dados) ? (string) ($dados['status'] ?? '') : '';

        if (($http === 201 || $http === 200) && in_array($status, ['enviado', 'ja_existe'], true)) {
            return ['status' => 'enviado', 'erro' => null];
        }
        if ($http === 200 && $status === 'quarentena') {
            return ['status' => 'quarentena', 'erro' => null];
        }
        if ($http === 422 && $status === 'sem_funcionario') {
            return ['status' => 'sem_funcionario', 'erro' => 'Responsável sem cadastro no METRICA360'];
        }
        // Pedido recusado de forma definitiva (dados inválidos, chave errada): não adianta repetir
        if (in_array($http, [400, 401, 403, 404, 405, 422], true)) {
            return ['status' => 'falha', 'erro' => "METRICA360 recusou o pedido (HTTP {$http}" . ($status !== '' ? ", {$status}" : '') . ')'];
        }
        // Fora do ar, tempo esgotado, 429, 5xx, resposta inesperada: tenta de novo mais tarde
        return ['status' => 'tentar_de_novo', 'erro' => $http === 0 ? 'METRICA360 inacessível' : "METRICA360 respondeu HTTP {$http}" . ($status !== '' ? " ({$status})" : '')];
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

    /** Linha do tempo da demanda: autor = responsável (ou quem encerrou), a quem a nota é atribuída no METRICA360. */
    private static function registrarNoHistorico(PDO $db, int $ticketId): void {
        try {
            $stmt = $db->prepare("SELECT COALESCE(agente_id, fechado_por, usuario_id) FROM tickets WHERE id = ?");
            $stmt->execute([$ticketId]);
            $autor = $stmt->fetchColumn();
            if (!$autor) {
                return;
            }
            $db->prepare("INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_novo) VALUES (?, ?, 'pesquisa_satisfacao', ?)")
               ->execute([$ticketId, (int) $autor, 'Pesquisa de satisfação enviada ao solicitante pelo METRICA360']);
        } catch (Throwable $e) {
            error_log('[PESQUISA SATISFACAO] Erro ao registrar no histórico: ' . $e->getMessage());
        }
    }

    /** POST JSON ao METRICA360 (sem cliente HTTP no projeto: curl, como api/config/turnstile.php). */
    private static function postar(string $url, string $chave, array $corpo): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($corpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'X-API-KEY: ' . $chave],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resposta = curl_exec($ch);
        $http = $resposta === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resposta === false) {
            error_log('[PESQUISA SATISFACAO] Falha de conexão com o METRICA360: ' . curl_error($ch));
        }
        curl_close($ch);
        return ['http' => $http, 'corpo' => $resposta === false ? '' : (string) $resposta];
    }
}
