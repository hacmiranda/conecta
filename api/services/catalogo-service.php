<?php
/**
 * Status e prioridades permitidos: catálogo global (ticket_status / ticket_prioridades) filtrado
 * pela ativação de cada setor (setor_status / setor_prioridades). Regra única, usada no servidor
 * em qualquer gravação feita pelo usuário (criar ou alterar demanda).
 *
 * Setor sem nenhuma linha de configuração usa o catálogo global inteiro.
 */
class CatalogoService {

    /**
     * Status que o próprio sistema aplica (abertura, resposta do solicitante, reabertura e
     * "Enviar e Fechar"): valem em todo setor e não podem ser desativados.
     */
    public const STATUS_ESSENCIAIS = ['novo', 'nova_resposta', 'reaberto', 'encerrado'];

    public static function statusPermitido(PDO $db, string $status, ?int $setorId): bool {
        if (in_array($status, self::STATUS_ESSENCIAIS, true)) {
            $setorId = null; // só confere o catálogo global
        }
        return self::permitido($db, 'ticket_status', 'setor_status', 'status_id', $status, $setorId);
    }

    public static function prioridadePermitida(PDO $db, string $prioridade, ?int $setorId): bool {
        return self::permitido($db, 'ticket_prioridades', 'setor_prioridades', 'prioridade_id', $prioridade, $setorId);
    }

    /** Primeira prioridade ativa do setor, na ordem dele (null se o setor não tem nenhuma ativa). */
    public static function primeiraPrioridadeAtiva(PDO $db, int $setorId): ?string {
        $stmt = $db->prepare("SELECT p.nome FROM setor_prioridades sp
                              JOIN ticket_prioridades p ON p.id = sp.prioridade_id AND p.ativo = 1
                              WHERE sp.setor_id = ? AND sp.ativo = 1 ORDER BY sp.ordem, p.id LIMIT 1");
        $stmt->execute([$setorId]);
        $nome = $stmt->fetchColumn();
        return $nome === false ? null : (string) $nome;
    }

    /**
     * Nomes de tabela/coluna vêm só das chamadas acima (constantes), nunca de entrada do usuário.
     */
    private static function permitido(PDO $db, string $tabela, string $tabelaSetor, string $colunaItem, string $nome, ?int $setorId): bool {
        $stmt = $db->prepare("SELECT id FROM {$tabela} WHERE nome = ? AND ativo = 1");
        $stmt->execute([$nome]);
        $itemId = $stmt->fetchColumn();
        if (!$itemId) {
            return false;
        }
        if (!$setorId) {
            return true;
        }

        $total = $db->prepare("SELECT COUNT(*) FROM {$tabelaSetor} WHERE setor_id = ?");
        $total->execute([$setorId]);
        if ((int) $total->fetchColumn() === 0) {
            return true;
        }

        $ativo = $db->prepare("SELECT 1 FROM {$tabelaSetor} WHERE setor_id = ? AND {$colunaItem} = ? AND ativo = 1");
        $ativo->execute([$setorId, (int) $itemId]);
        return (bool) $ativo->fetchColumn();
    }
}
