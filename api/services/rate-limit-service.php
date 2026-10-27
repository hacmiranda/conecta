<?php
/**
 * Limite de tentativas — Conecta CRPRS (tabela tentativas_acesso, migração 29).
 *
 * - consumir(): grava a tentativa e só depois conta as da janela. Como cada requisição se registra
 *   antes de conferir, requisições simultâneas se enxergam e o limite vale de verdade (a versão
 *   antiga, em arquivos, lia e gravava em passos separados e deixava passar rajadas).
 * - registrar()/contar(): para quem só quer somar falhas e decidir depois (ex.: atraso por conta).
 * - limpar(): zera a chave (depois de um acerto).
 * A chave guardada é o sha1 de "chave|ação": nenhum IP ou e-mail em claro na tabela.
 */
require_once __DIR__ . '/../config/database.php';

class RateLimitService {

    private static ?PDO $db = null;

    private static function db(): PDO {
        if (self::$db === null) {
            self::$db = (new Database())->getConnectionPropria(); // fora da transação da requisição
        }
        return self::$db;
    }

    private static function hash(string $chave, string $acao): string {
        return sha1($chave . '|' . $acao);
    }

    /**
     * Registra a tentativa e diz se ela passou do limite da janela.
     * @return array ['bloqueado' => bool, 'tentativas' => int, 'segundos_restantes' => int]
     */
    public static function consumir(string $chave, string $acao, int $limite, int $janela_segundos): array {
        self::registrar($chave, $acao);
        $tentativas = self::contar($chave, $acao, $janela_segundos);
        if ($tentativas <= $limite) {
            return ['bloqueado' => false, 'tentativas' => $tentativas, 'segundos_restantes' => 0];
        }
        // Libera quando a tentativa mais antiga da janela sair dela
        $stmt = self::db()->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MIN(criado_em), INTERVAL ? SECOND))
                                     FROM tentativas_acesso
                                     WHERE chave = ? AND acao = ? AND criado_em > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$janela_segundos, self::hash($chave, $acao), $acao, $janela_segundos]);
        return ['bloqueado' => true, 'tentativas' => $tentativas, 'segundos_restantes' => max(1, (int) $stmt->fetchColumn())];
    }

    /**
     * Só confere (sem registrar): bloqueado se já há $limite tentativas na janela.
     * @return array ['bloqueado' => bool, 'tentativas' => int, 'segundos_restantes' => int]
     */
    public static function verificar(string $chave, string $acao, int $limite = 5, int $janela_segundos = 900): array {
        $tentativas = self::contar($chave, $acao, $janela_segundos);
        return [
            'bloqueado'          => $tentativas >= $limite,
            'tentativas'         => $tentativas,
            'segundos_restantes' => $tentativas >= $limite ? $janela_segundos : 0,
        ];
    }

    /** Registra uma tentativa (falha) para a chave/ação. */
    public static function registrar(string $chave, string $acao): void {
        self::db()->prepare("INSERT INTO tentativas_acesso (chave, acao) VALUES (?, ?)")
            ->execute([self::hash($chave, $acao), $acao]);

        // Faxina ocasional: nenhuma janela usada passa de 1 dia
        if (random_int(1, 50) === 1) {
            self::db()->exec("DELETE FROM tentativas_acesso WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    }

    /** Quantas tentativas a chave tem na janela. */
    public static function contar(string $chave, string $acao, int $janela_segundos): int {
        $stmt = self::db()->prepare("SELECT COUNT(*) FROM tentativas_acesso
                                     WHERE chave = ? AND acao = ? AND criado_em > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([self::hash($chave, $acao), $acao, $janela_segundos]);
        return (int) $stmt->fetchColumn();
    }

    /** Zera o contador da chave/ação (chamado após sucesso). */
    public static function limpar(string $chave, string $acao): void {
        self::db()->prepare("DELETE FROM tentativas_acesso WHERE chave = ? AND acao = ?")
            ->execute([self::hash($chave, $acao), $acao]);
    }
}
