<?php
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/html-sanitizer.php';

/**
 * Tempo trabalhado na demanda (17/11/2026, migração 41, tabela ticket_tempos). Cada pessoa lança o tempo que
 * trabalhou: data + início e fim, ou só a duração. Usado por api/controllers/demanda-tempos.php.
 *
 * Quem lança: quem pode alterar a demanda (responsável, gestão do setor, admin) e quem já foi responsável por ela
 * ou a encerrou (o tempo pode ser lançado depois de repassar ou encerrar). Quem edita/exclui um lançamento: o
 * autor, a gestão do setor da demanda ou o admin. Perfis de consulta não lançam (trava central de escrita).
 */
class TempoDemandaService {

    const MAX_MINUTOS = 1440;      // um lançamento cobre no máximo um dia
    const DIAS_PARA_TRAS = 366;    // não aceita lançamentos de mais de um ano atrás

    /**
     * Valida e normaliza um lançamento vindo da tela. Pura (testável). $hoje no formato Y-m-d.
     * Entrada: data_trabalho, e hora_inicio + hora_fim (HH:MM) OU duracao (HH:MM) OU minutos; descricao opcional.
     * @return array{data_trabalho:string, hora_inicio:?string, hora_fim:?string, minutos:int, descricao:?string}
     * @throws InvalidArgumentException com a mensagem para a tela
     */
    public static function normalizar(array $e, string $hoje): array {
        $data = trim((string) ($e['data_trabalho'] ?? ''));
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$d || $d->format('Y-m-d') !== $data) {
            throw new InvalidArgumentException('Informe a data do trabalho.');
        }
        if ($data > $hoje) {
            throw new InvalidArgumentException('A data do trabalho não pode ser no futuro.');
        }
        $limite = (new DateTimeImmutable($hoje))->modify('-' . self::DIAS_PARA_TRAS . ' days')->format('Y-m-d');
        if ($data < $limite) {
            throw new InvalidArgumentException('A data do trabalho é de mais de um ano atrás.');
        }

        $inicio = self::hora($e['hora_inicio'] ?? '');
        $fim = self::hora($e['hora_fim'] ?? '');
        if ($inicio !== null || $fim !== null) {
            if ($inicio === null || $fim === null) {
                throw new InvalidArgumentException('Informe o início e o fim (ou só a duração).');
            }
            $minutos = self::minutosDoDia($fim) - self::minutosDoDia($inicio);
            if ($minutos <= 0) {
                throw new InvalidArgumentException('O fim precisa ser depois do início.');
            }
        } else {
            $duracao = trim((string) ($e['duracao'] ?? ''));
            if ($duracao !== '') {
                if (!preg_match('/^(\d{1,2}):([0-5]\d)$/', $duracao, $m)) {
                    throw new InvalidArgumentException('Duração no formato horas:minutos (ex.: 1:30).');
                }
                $minutos = (int) $m[1] * 60 + (int) $m[2];
            } else {
                $minutos = (int) ($e['minutos'] ?? 0);
            }
            if ($minutos <= 0) {
                throw new InvalidArgumentException('Informe o início e o fim, ou a duração.');
            }
        }
        if ($minutos > self::MAX_MINUTOS) {
            throw new InvalidArgumentException('Um lançamento vai até 24 horas.');
        }

        $descricao = HtmlSanitizer::texto((string) ($e['descricao'] ?? ''), 255);
        return [
            'data_trabalho' => $data,
            'hora_inicio'   => $inicio,
            'hora_fim'      => $fim,
            'minutos'       => $minutos,
            'descricao'     => $descricao !== '' ? $descricao : null,
        ];
    }

    /** "08:05" ou "8:05" → "08:05:00"; vazio → null; inválido → exceção */
    private static function hora($valor): ?string {
        $v = trim((string) $valor);
        if ($v === '') return null;
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $v, $m)) {
            throw new InvalidArgumentException('Hora inválida (use horas:minutos, ex.: 08:30).');
        }
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }

    private static function minutosDoDia(string $hora): int {
        [$h, $m] = array_map('intval', explode(':', $hora));
        return $h * 60 + $m;
    }

    /** Quem pode lançar tempo nesta demanda (ver o comentário da classe) */
    public static function podeLancar(PDO $db, array $user, array $ticket): bool {
        if (!empty($ticket['excluido_em']) || AcessoService::ehSomenteLeitura($user)) {
            return false;
        }
        if (AcessoService::podeOperarTicket($user, $ticket)) {
            return true;
        }
        if (!in_array($user['tipo'] ?? '', AcessoService::PAPEIS_ATENDEM, true)) {
            return false;
        }
        if ((int) ($ticket['fechado_por'] ?? 0) === (int) $user['id']) {
            return true;
        }
        $stmt = $db->prepare("SELECT 1 FROM ticket_agente_log WHERE ticket_id = ? AND usuario_id = ? LIMIT 1");
        $stmt->execute([(int) $ticket['id'], (int) $user['id']]);
        return (bool) $stmt->fetchColumn();
    }

    /** Quem pode editar/excluir um lançamento: o autor, a gestão do setor da demanda ou o admin */
    public static function podeEditar(array $user, array $lancamento, array $ticket): bool {
        if (!empty($ticket['excluido_em']) || AcessoService::ehSomenteLeitura($user)) {
            return false;
        }
        if (($user['tipo'] ?? '') === 'admin' || (int) $lancamento['usuario_id'] === (int) $user['id']) {
            return true;
        }
        return AcessoService::ehGestao($user)
            && AcessoService::temAcessoAoSetor($user, isset($ticket['setor_id']) ? (int) $ticket['setor_id'] : null);
    }

    /** Lançamentos da demanda (mais recentes primeiro), totais por pessoa e o total */
    public static function listar(PDO $db, int $ticketId): array {
        $stmt = $db->prepare("SELECT t.id, t.usuario_id, u.nome AS usuario_nome, t.data_trabalho,
                                     TIME_FORMAT(t.hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(t.hora_fim, '%H:%i') AS hora_fim,
                                     t.minutos, t.descricao, t.criado_em
                              FROM ticket_tempos t JOIN usuarios u ON u.id = t.usuario_id
                              WHERE t.ticket_id = ? ORDER BY t.data_trabalho DESC, t.hora_inicio DESC, t.id DESC");
        $stmt->execute([$ticketId]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $porPessoa = [];
        $total = 0;
        foreach ($itens as &$i) {
            $i['id'] = (int) $i['id'];
            $i['usuario_id'] = (int) $i['usuario_id'];
            $i['minutos'] = (int) $i['minutos'];
            $total += $i['minutos'];
            $porPessoa[$i['usuario_id']] ??= ['usuario_id' => $i['usuario_id'], 'nome' => $i['usuario_nome'], 'minutos' => 0];
            $porPessoa[$i['usuario_id']]['minutos'] += $i['minutos'];
        }
        unset($i);
        usort($porPessoa, fn($a, $b) => $b['minutos'] <=> $a['minutos']);
        return ['itens' => $itens, 'por_pessoa' => array_values($porPessoa), 'total_minutos' => $total];
    }
}
