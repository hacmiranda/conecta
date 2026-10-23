<?php
/**
 * Protocolo único do Conselho: "CRPRS2026-0001" (exibido sempre com "#" na frente,
 * "#CRPRS2026-0001"). Reinicia a cada ano; uma vez gerado, nunca muda — transferência de setor,
 * reabertura e mesclagem mantêm o protocolo de cada demanda (ProtocoloService não decide isso,
 * só gera e exibe; quem cria a demanda chama gerar() uma única vez).
 *
 * `tickets.id` continua sendo a chave primária e a referência interna (URLs, FKs, código);
 * o protocolo é só a identidade mostrada ao usuário e lida no assunto dos e-mails.
 */
class ProtocoloService {
    // Cache por requisição: evita um SELECT por demanda quando a lista inteira precisa do rótulo
    private static array $cache = [];

    /**
     * Gera e reserva o próximo protocolo do ano corrente. Contador atômico por ano (sem
     * MAX()+1 — duas demandas nascendo no mesmo instante não recebem o mesmo número).
     * Chamar antes do INSERT da demanda (não usa o id dela).
     */
    public static function gerar(PDO $db): string {
        $ano = (int) date('Y');
        $stmt = $db->prepare(
            "INSERT INTO protocolo_sequencia (ano, ultimo) VALUES (:ano, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)"
        );
        $stmt->execute([':ano' => $ano]);
        $numero = (int) $db->lastInsertId();

        return self::formatar($ano, $numero);
    }

    private static function formatar(int $ano, int $numero): string {
        return sprintf('CRPRS%d-%04d', $ano, $numero);
    }

    /**
     * Rótulo para exibição, sempre com "#". `$protocolo` pode ser null (linha antiga ou de teste
     * sem protocolo) — nesse caso cai no número interno, só para não quebrar a tela.
     */
    public static function rotulo(?string $protocolo, int $id): string {
        if ($protocolo !== null && $protocolo !== '') {
            return '#' . $protocolo;
        }
        return '#' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Busca e formata o rótulo de uma demanda pelo id, com cache por requisição (evita repetir a
     * consulta quando o mesmo id aparece várias vezes num mesmo envio/log).
     */
    public static function rotuloPorId(PDO $db, int $id): string {
        if (!array_key_exists($id, self::$cache)) {
            $stmt = $db->prepare('SELECT protocolo FROM tickets WHERE id = ?');
            $stmt->execute([$id]);
            self::$cache[$id] = $stmt->fetchColumn() ?: null;
        }
        return self::rotulo(self::$cache[$id], $id);
    }

    /**
     * Procura um protocolo no assunto de um e-mail ("RE: [#CRPRS2026-0001] ...",
     * "Re: CRPRS2026-0001 ...", minúsculas, com ou sem "#"). Devolve normalizado em maiúsculas,
     * ou null se não achar — nesse caso o e-mail vira uma demanda nova (nunca mais o formato
     * antigo #00123, descontinuado: o sistema ainda não estava em produção).
     */
    public static function extrair(string $assunto): ?string {
        if (preg_match('/#?\s*(CRPRS\s*\d{4}\s*-\s*\d{4,})/i', $assunto, $m)) {
            return strtoupper(preg_replace('/\s+/', '', $m[1]));
        }
        return null;
    }

    /** Busca o id da demanda dona de um protocolo (ou null). */
    public static function buscarId(PDO $db, string $protocolo): ?int {
        $stmt = $db->prepare('SELECT id FROM tickets WHERE protocolo = ?');
        $stmt->execute([$protocolo]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}
