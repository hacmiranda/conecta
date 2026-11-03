<?php
require_once __DIR__ . '/pesquisa-config-service.php';
require_once __DIR__ . '/pesquisa-convite-service.php';
require_once __DIR__ . '/log-service.php';

/**
 * Expiração e reenvio automático dos convites de avaliação (porte do ConviteProcessador do METRICA360).
 * Roda no fim de api/cron/enviar-fila.php (a cada ~2 minutos), nunca pelo navegador.
 *
 *  - Expiração: convite sem resposta há mais dias que a validade do link passa a "expirado".
 *  - Reenvio: um único reenvio automático, depois de N horas sem resposta e antes de expirar. Cada convite é
 *    reservado ANTES do envio (UPDATE condicional atômico: dois crons ao mesmo tempo não mandam duas vezes);
 *    se o envio falha a reserva é desfeita e o lote para na primeira falha.
 */
class PesquisaProcessadorService {

    const LOTE_REENVIO = 10;

    /**
     * @param callable|null $enviar (nome, e-mail, link, protocolo): void — troca o envio nos testes
     * @return array{expirados:int, reenviados:int}
     */
    public static function processar(PDO $db, ?callable $enviar = null): array {
        $regras = PesquisaConfig::regrasConvite($db);
        return [
            'expirados'  => self::expirar($db, $regras['expiracao_token_dias']),
            'reenviados' => self::reenviar($db, $regras['reenvio_horas'], $enviar),
        ];
    }

    public static function expirar(PDO $db, int $dias): int {
        $stmt = $db->prepare("UPDATE pesquisa_convites SET expirado = 1
                              WHERE avaliado_em IS NULL AND expirado = 0 AND enviado_em < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([max(1, $dias)]);
        return $stmt->rowCount();
    }

    public static function reenviar(PDO $db, int $horas, ?callable $enviar = null): int {
        $stmt = $db->prepare("SELECT id, destinatario_nome, destinatario_email, token, protocolo
                              FROM pesquisa_convites
                              WHERE avaliado_em IS NULL AND reenviado_automatico_em IS NULL AND expirado = 0
                                AND enviado_em < DATE_SUB(NOW(), INTERVAL ? HOUR)
                              ORDER BY enviado_em ASC LIMIT " . self::LOTE_REENVIO);
        $stmt->execute([max(1, $horas)]);
        $convites = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$convites) {
            return 0;
        }

        $enviar ??= function (string $nome, string $email, string $link, string $protocolo) use ($db): void {
            PesquisaConviteService::enviarEmailDoConvite($db, $nome, $email, $link, $protocolo);
        };
        $reservar = $db->prepare("UPDATE pesquisa_convites SET reenviado_automatico_em = NOW() WHERE id = ? AND reenviado_automatico_em IS NULL");
        $liberar = $db->prepare("UPDATE pesquisa_convites SET reenviado_automatico_em = NULL WHERE id = ?");

        $enviados = 0;
        foreach ($convites as $c) {
            $reservar->execute([$c['id']]);
            if ($reservar->rowCount() !== 1) {
                continue; // outro processo já cuida deste convite
            }
            try {
                $enviar($c['destinatario_nome'], $c['destinatario_email'], PesquisaConviteService::linkDeAvaliacao($c['token']), (string) ($c['protocolo'] ?? ''));
                $enviados++;
            } catch (Throwable $e) {
                $liberar->execute([$c['id']]);
                LogService::erro('pesquisa-satisfacao', 'Falha no reenvio do convite #' . $c['id'] . ': ' . $e->getMessage());
                break;
            }
        }
        return $enviados;
    }
}
