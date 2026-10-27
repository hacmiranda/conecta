<?php
require_once __DIR__ . '/prazo-atividade-service.php';
require_once __DIR__ . '/html-sanitizer.php';

/**
 * Registro de Atividades do servidor (veio do METRICA360, 27/10/2026): um relatório por dia, em rascunho até
 * ser enviado; enviado não muda mais. Quem chama confere que o registro é do usuário (dono()).
 */
class RegistroAtividadeService {

    public const QUANTIDADE_MAXIMA = 9999;
    public const OBSERVACAO_MAXIMA = 500;
    public const DATA_MINIMA = '2020-01-01';

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /** Atividades ativas do setor, favoritas primeiro (as marcadas por último antes), depois por nome. */
    public function atividadesDoSetor(int $setorId, int $usuarioId): array {
        $stmt = $this->db->prepare("
            SELECT t.id, t.nome, t.ordem, IF(f.usuario_id IS NULL, 0, 1) AS favorito
            FROM atividades_tipos t
            LEFT JOIN atividades_favoritas f ON f.atividade_tipo_id = t.id AND f.usuario_id = ?
            WHERE t.setor_id = ? AND t.ativo = 1
            ORDER BY favorito DESC, f.criado_em DESC, t.nome ASC");
        $stmt->execute([$usuarioId, $setorId]);
        return array_map(fn($r) => ['id' => (int) $r['id'], 'nome' => $r['nome'], 'favorito' => (int) $r['favorito'] === 1],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Rascunho do dia (criado se não existir; INSERT IGNORE por causa do índice único usuario+data). */
    public function buscarOuCriarRascunho(int $usuarioId, string $data): array {
        $this->db->prepare("INSERT IGNORE INTO registros_atividades (usuario_id, data_referencia, status) VALUES (?, ?, 'rascunho')")
                 ->execute([$usuarioId, $data]);
        $stmt = $this->db->prepare("SELECT * FROM registros_atividades WHERE usuario_id = ? AND data_referencia = ?");
        $stmt->execute([$usuarioId, $data]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function buscar(int $registroId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM registros_atividades WHERE id = ?");
        $stmt->execute([$registroId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function dono(?array $registro, int $usuarioId): bool {
        return $registro !== null && (int) $registro['usuario_id'] === $usuarioId;
    }

    /** Itens gravados do registro, por atividade_tipo_id. */
    public function itens(int $registroId, bool $soPreenchidos = false): array {
        $stmt = $this->db->prepare("
            SELECT i.atividade_tipo_id, i.quantidade, i.observacao, t.nome AS atividade_nome
            FROM registros_atividades_itens i JOIN atividades_tipos t ON t.id = i.atividade_tipo_id
            WHERE i.registro_id = ?" . ($soPreenchidos ? " AND i.quantidade > 0" : "") . "
            ORDER BY t.ordem, t.nome");
        $stmt->execute([$registroId]);
        return array_map(fn($r) => [
            'atividade_tipo_id' => (int) $r['atividade_tipo_id'],
            'atividade_nome'    => $r['atividade_nome'],
            'quantidade'        => (int) $r['quantidade'],
            'observacao'        => (string) ($r['observacao'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Normaliza os itens vindos da tela: só atividades ativas do setor, quantidade 0..9999, observação em texto
     * puro até 500 caracteres. Itens de outra atividade são descartados (pura, para testar).
     * @param int[] $tiposPermitidos
     */
    public static function normalizarItens($itens, array $tiposPermitidos): array {
        if (!is_array($itens)) return [];
        $permitidos = array_flip($tiposPermitidos);
        $resultado = [];
        foreach ($itens as $item) {
            if (!is_array($item)) continue;
            $tipo = (int) ($item['atividade_tipo_id'] ?? 0);
            if (!isset($permitidos[$tipo])) continue;
            $obs = trim(HtmlSanitizer::texto((string) ($item['observacao'] ?? ''), self::OBSERVACAO_MAXIMA));
            $resultado[$tipo] = [
                'atividade_tipo_id' => $tipo,
                'quantidade'        => max(0, min(self::QUANTIDADE_MAXIMA, (int) ($item['quantidade'] ?? 0))),
                'observacao'        => $obs,
            ];
        }
        return array_values($resultado);
    }

    /** IDs das atividades ativas do setor (o que a tela pode gravar). */
    public function tiposAtivosDoSetor(int $setorId): array {
        $stmt = $this->db->prepare("SELECT id FROM atividades_tipos WHERE setor_id = ? AND ativo = 1");
        $stmt->execute([$setorId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Grava itens e modalidade de um rascunho. Devolve false se o registro já foi enviado. */
    public function salvarRascunho(int $registroId, int $usuarioId, array $itens, ?string $modalidade): bool {
        $stmt = $this->db->prepare("SELECT status FROM registros_atividades WHERE id = ? AND usuario_id = ? FOR UPDATE");
        $stmt->execute([$registroId, $usuarioId]);
        if ($stmt->fetchColumn() !== 'rascunho') return false;

        $ins = $this->db->prepare("
            INSERT INTO registros_atividades_itens (registro_id, atividade_tipo_id, quantidade, observacao)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade), observacao = VALUES(observacao)");
        foreach ($itens as $i) {
            $ins->execute([$registroId, $i['atividade_tipo_id'], $i['quantidade'], $i['observacao'] !== '' ? $i['observacao'] : null]);
        }
        if (in_array($modalidade, PrazoAtividadeService::MODALIDADES, true)) {
            $this->db->prepare("UPDATE registros_atividades SET modalidade = ? WHERE id = ?")->execute([$modalidade, $registroId]);
        }
        return true;
    }

    /** Envia o relatório (rascunho → enviado). Sem modalidade (quem não tem dias de entrega), fica remoto. */
    public function finalizar(int $registroId, int $usuarioId, ?string $modalidade): bool {
        if (!in_array($modalidade, PrazoAtividadeService::MODALIDADES, true)) $modalidade = null;
        $stmt = $this->db->prepare("
            UPDATE registros_atividades
            SET status = 'enviado', enviado_em = NOW(), modalidade = COALESCE(?, modalidade, 'remoto')
            WHERE id = ? AND usuario_id = ? AND status = 'rascunho'");
        $stmt->execute([$modalidade, $registroId, $usuarioId]);
        return $stmt->rowCount() > 0;
    }

    /** Últimos relatórios enviados, com atividades (quantidade > 0) e tarefas (soma das quantidades). */
    public function historico(int $usuarioId, int $limite = 10): array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.data_referencia, r.enviado_em, r.modalidade,
                   COUNT(CASE WHEN i.quantidade > 0 THEN 1 END) AS total_itens,
                   COALESCE(SUM(i.quantidade), 0) AS total_tarefas
            FROM registros_atividades r LEFT JOIN registros_atividades_itens i ON i.registro_id = r.id
            WHERE r.usuario_id = ? AND r.status = 'enviado'
            GROUP BY r.id
            ORDER BY r.data_referencia DESC
            LIMIT " . max(1, min(50, $limite)));
        $stmt->execute([$usuarioId]);
        return array_map(fn($r) => [
            'id'              => (int) $r['id'],
            'data_referencia' => $r['data_referencia'],
            'enviado_em'      => $r['enviado_em'],
            'modalidade'      => $r['modalidade'],
            'total_itens'     => (int) $r['total_itens'],
            'total_tarefas'   => (int) $r['total_tarefas'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Liga/desliga a estrela. Só atividade do setor do usuário. Devolve o novo estado, ou null se não pode. */
    public function alternarFavorito(int $usuarioId, int $setorId, int $tipoId): ?bool {
        $stmt = $this->db->prepare("SELECT 1 FROM atividades_tipos WHERE id = ? AND setor_id = ?");
        $stmt->execute([$tipoId, $setorId]);
        if (!$stmt->fetchColumn()) return null;

        $del = $this->db->prepare("DELETE FROM atividades_favoritas WHERE usuario_id = ? AND atividade_tipo_id = ?");
        $del->execute([$usuarioId, $tipoId]);
        if ($del->rowCount() > 0) return false;
        $this->db->prepare("INSERT INTO atividades_favoritas (usuario_id, atividade_tipo_id) VALUES (?, ?)")->execute([$usuarioId, $tipoId]);
        return true;
    }

    /** Data válida (Y-m-d) entre 2020-01-01 e hoje, ou null. */
    public static function dataValida(?string $data, ?string $hoje = null): ?string {
        $hoje ??= date('Y-m-d');
        if (!is_string($data) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) return null;
        [$a, $m, $d] = array_map('intval', explode('-', $data));
        if (!checkdate($m, $d, $a)) return null;
        return ($data >= self::DATA_MINIMA && $data <= $hoje) ? $data : null;
    }
}
