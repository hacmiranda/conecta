<?php
/**
 * Status e prioridades permitidos: catálogo global (ticket_status / ticket_prioridades) filtrado
 * pela ativação de cada setor (setor_status / setor_prioridades). Regra única, usada no servidor
 * em qualquer gravação feita pelo usuário (criar ou alterar demanda).
 *
 * Setor sem nenhuma linha de configuração usa o catálogo global inteiro.
 */
class CatalogoService {

    public static function statusPermitido(PDO $db, string $status, ?int $setorId): bool {
        return self::permitido($db, 'ticket_status', 'setor_status', 'status_id', $status, $setorId);
    }

    public static function prioridadePermitida(PDO $db, string $prioridade, ?int $setorId): bool {
        return self::permitido($db, 'ticket_prioridades', 'setor_prioridades', 'prioridade_id', $prioridade, $setorId);
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
