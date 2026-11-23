<?php
require_once __DIR__ . '/prazo-atividade-service.php';

/**
 * Relatório do setor enviado pelo coordenador: vigências (liga, desliga, troca a periodicidade) e
 * setores do coordenador. Desenho: seções 5.2 e 5.4 de
 * docs/superpowers/specs/2026-11-23-relatorio-setor-coordenador-design.md.
 *
 * Invariante: as vigências de um setor NUNCA se sobrepõem (a cobrança duplicaria períodos). Num setor há,
 * no máximo, uma vigência "atual" (cobre hoje) e uma "próxima" (começa depois de hoje). Este serviço não
 * grava auditoria (o controller grava) e usa a transação de quem chama, se houver.
 */
class RelatorioSetorService {

    public const PERIODICIDADES = ['semanal', 'mensal'];

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private static function hoje(?string $hoje): string {
        return $hoje ?? date('Y-m-d');
    }

    private static function formatar(array $l): array {
        return [
            'id'            => (int) $l['id'],
            'periodicidade' => $l['periodicidade'],
            'inicio'        => $l['inicio'],
            'fim'           => $l['fim'],
        ];
    }

    /** A vigência que cobre hoje (id, periodicidade, inicio, fim), ou null. */
    public function vigenciaAtual(int $setorId, ?string $hoje = null): ?array {
        $hoje = self::hoje($hoje);
        $stmt = $this->db->prepare(
            "SELECT id, periodicidade, inicio, fim FROM atividades_relatorio_setor
              WHERE setor_id = ? AND inicio <= ? AND (fim IS NULL OR fim >= ?)
              ORDER BY inicio DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$setorId, $hoje, $hoje]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        return $l ? self::formatar($l) : null;
    }

    /** A vigência que ainda vai começar (inicio depois de hoje), ou null. */
    public function proximaVigencia(int $setorId, ?string $hoje = null): ?array {
        $hoje = self::hoje($hoje);
        $stmt = $this->db->prepare(
            "SELECT id, periodicidade, inicio, fim FROM atividades_relatorio_setor
              WHERE setor_id = ? AND inicio > ? ORDER BY inicio, id LIMIT 1"
        );
        $stmt->execute([$setorId, $hoje]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        return $l ? self::formatar($l) : null;
    }

    /** Coordenador ativo do setor (principal ou adicional): ['id', 'nome'] ou null. */
    public function coordenadorDoSetor(int $setorId): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.nome FROM usuarios u
              WHERE u.tipo = 'coordenador' AND u.ativo = 1
                AND (u.setor_id = ? OR EXISTS (SELECT 1 FROM usuario_setores us WHERE us.usuario_id = u.id AND us.setor_id = ?))
              ORDER BY u.id LIMIT 1"
        );
        $stmt->execute([$setorId, $setorId]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        return $l ? ['id' => (int) $l['id'], 'nome' => $l['nome']] : null;
    }

    private function estado(int $setorId, string $hoje): array {
        return ['atual' => $this->vigenciaAtual($setorId, $hoje), 'proxima' => $this->proximaVigencia($setorId, $hoje)];
    }

    private function apagarProxima(?array $proxima): void {
        if ($proxima) {
            $this->db->prepare("DELETE FROM atividades_relatorio_setor WHERE id = ?")->execute([$proxima['id']]);
        }
    }

    private function inserir(int $setorId, string $periodicidade, string $inicio, int $porUsuarioId): void {
        $this->db->prepare(
            "INSERT INTO atividades_relatorio_setor (setor_id, periodicidade, inicio, fim, criado_por) VALUES (?, ?, ?, NULL, ?)"
        )->execute([$setorId, $periodicidade, $inicio, $porUsuarioId]);
    }

    /**
     * Liga, desliga ou troca a periodicidade do relatório do setor.
     *
     * @return array{antes: array, depois: array} cada um no formato ['atual' => ?array, 'proxima' => ?array]
     * @throws InvalidArgumentException periodicidade inválida ou ligar sem coordenador ativo
     */
    public function alterar(int $setorId, bool $ligado, string $periodicidade, int $porUsuarioId, ?string $hoje = null): array {
        if (!in_array($periodicidade, self::PERIODICIDADES, true)) {
            throw new InvalidArgumentException('Periodicidade inválida: use semanal ou mensal.');
        }
        $hoje = self::hoje($hoje);

        $transacaoPropria = !$this->db->inTransaction();
        if ($transacaoPropria) {
            $this->db->beginTransaction();
        }
        try {
            // Trava o setor: duas alterações simultâneas não criam vigências sobrepostas
            $stmt = $this->db->prepare("SELECT id FROM setores WHERE id = ? FOR UPDATE");
            $stmt->execute([$setorId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('Setor não encontrado.');
            }

            $antes = $this->estado($setorId, $hoje);
            $atual = $antes['atual'];
            $proxima = $antes['proxima'];

            if ($ligado) {
                if ($this->coordenadorDoSetor($setorId) === null) {
                    throw new InvalidArgumentException('O setor não tem coordenador ativo: cadastre um coordenador antes de ligar o relatório.');
                }
                if ($atual === null) {
                    // Nunca começa antes do dia seguinte ao fim da última vigência (sem sobreposição)
                    $stmt = $this->db->prepare("SELECT MAX(fim) FROM atividades_relatorio_setor WHERE setor_id = ?");
                    $stmt->execute([$setorId]);
                    $ultimoFim = $stmt->fetchColumn();
                    $inicio = PrazoAtividadeService::inicioDoPeriodo($periodicidade, $hoje);
                    if ($ultimoFim) {
                        $dia = (new DateTimeImmutable($ultimoFim))->modify('+1 day')->format('Y-m-d');
                        $inicio = max($inicio, $dia);
                    }
                    $this->apagarProxima($proxima);
                    $this->inserir($setorId, $periodicidade, $inicio, $porUsuarioId);
                } elseif ($atual['periodicidade'] === $periodicidade) {
                    // Mesma periodicidade: cancela um fim já programado e a troca pendente
                    $this->apagarProxima($proxima);
                    if ($atual['fim'] !== null) {
                        $this->db->prepare("UPDATE atividades_relatorio_setor SET fim = NULL, encerrado_por = NULL, encerrado_em = NULL WHERE id = ?")
                                 ->execute([$atual['id']]);
                    }
                } else {
                    // Troca: a atual fecha no fim do período corrente e a nova começa no dia seguinte
                    $fimAtual = PrazoAtividadeService::fimDoPeriodo($atual['periodicidade'], $hoje);
                    $inicioNova = (new DateTimeImmutable($fimAtual))->modify('+1 day')->format('Y-m-d');
                    $jaEmVigor = $proxima && $proxima['periodicidade'] === $periodicidade
                        && $proxima['inicio'] === $inicioNova && $atual['fim'] === $fimAtual;
                    if (!$jaEmVigor) {
                        $this->apagarProxima($proxima);
                        $this->db->prepare("UPDATE atividades_relatorio_setor SET fim = ?, encerrado_por = ?, encerrado_em = NOW() WHERE id = ?")
                                 ->execute([$fimAtual, $porUsuarioId, $atual['id']]);
                        $this->inserir($setorId, $periodicidade, $inicioNova, $porUsuarioId);
                    }
                }
            } else {
                $this->apagarProxima($proxima);
                if ($atual !== null && $atual['fim'] === null) {
                    $fim = PrazoAtividadeService::fimDoPeriodo($atual['periodicidade'], $hoje);
                    $this->db->prepare("UPDATE atividades_relatorio_setor SET fim = ?, encerrado_por = ?, encerrado_em = NOW() WHERE id = ?")
                             ->execute([$fim, $porUsuarioId, $atual['id']]);
                }
            }

            $depois = $this->estado($setorId, $hoje);
            if ($transacaoPropria) {
                $this->db->commit();
            }
            return ['antes' => $antes, 'depois' => $depois];
        } catch (Throwable $e) {
            if ($transacaoPropria && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Setores em que o usuário é coordenador e que têm o relatório do setor vigente hoje.
     * Devolve [] para quem não é coordenador. Ordenado pelo nome do setor.
     *
     * @return array<int, array{setor_id:int, nome:string, periodicidade:string}>
     */
    public static function setoresDoCoordenador(PDO $db, array $user): array {
        if (($user['tipo'] ?? '') !== 'coordenador') {
            return [];
        }
        // A linha de `usuarios` (login e me.php) não traz setores_extras: lê de usuario_setores
        if (!array_key_exists('setores_extras', $user)) {
            $stmt = $db->prepare("SELECT setor_id FROM usuario_setores WHERE usuario_id = ?");
            $stmt->execute([(int) $user['id']]);
            $user['setores_extras'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $setores = AcessoService::setoresDoUsuario($user) ?? [];
        if (!$setores) {
            return [];
        }
        $hoje = date('Y-m-d');
        $corte = (new DateTimeImmutable($hoje))->modify('-180 days')->format('Y-m-d');
        $marcas = implode(',', array_fill(0, count($setores), '?'));
        // Vigência cobrindo hoje ou terminada há até 180 dias (janela de pendências do Registro)
        $stmt = $db->prepare(
            "SELECT s.id AS setor_id, s.nome, v.periodicidade
               FROM setores s
               JOIN atividades_relatorio_setor v ON v.setor_id = s.id
                AND v.inicio <= ? AND (v.fim IS NULL OR v.fim >= ?)
              WHERE s.id IN ($marcas)
              ORDER BY s.nome, s.id, v.inicio DESC, v.id DESC"
        );
        $stmt->execute(array_merge([$hoje, $corte], $setores));
        $resultado = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            // A primeira linha de cada setor é a vigência mais recente (a atual, se houver)
            if (isset($resultado[$l['setor_id']])) continue;
            $resultado[$l['setor_id']] = [
                'setor_id'      => (int) $l['setor_id'],
                'nome'          => $l['nome'],
                'periodicidade' => $l['periodicidade'],
            ];
        }
        return array_values($resultado);
    }
}
