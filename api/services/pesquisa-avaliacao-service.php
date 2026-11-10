<?php
require_once __DIR__ . '/pesquisa-config-service.php';
require_once __DIR__ . '/html-sanitizer.php';
require_once __DIR__ . '/log-service.php';

/**
 * Lado do cidadão: o que a página pública de avaliação mostra e como a avaliação é gravada
 * (porte do FeedbackController do METRICA360). O token do convite é a única chave: liga a avaliação ao
 * servidor, vale uma vez e expira. Não guarda IP nem navegador do cidadão.
 */
class PesquisaAvaliacaoService {

    const COMENTARIO_MAX = 1000;

    /** O convite venceu? (marcado como expirado ou passou o prazo de validade do link) */
    public static function conviteExpirado(array $convite, int $diasValidade, ?int $agora = null): bool {
        if (!empty($convite['expirado'])) {
            return true;
        }
        $limite = strtotime((string) $convite['enviado_em']) + (max(1, $diasValidade) * 86400);
        return ($agora ?? time()) > $limite;
    }

    /** Situação do token para a página pública: invalido | ja_avaliado | expirado | ok. */
    public static function situacao(?array $convite, int $diasValidade, ?int $agora = null): string {
        if (!$convite) return 'invalido';
        if ($convite['avaliado_em'] !== null) return 'ja_avaliado';
        if (self::conviteExpirado($convite, $diasValidade, $agora)) return 'expirado';
        return 'ok';
    }

    private static function buscarConvite(PDO $db, string $token, bool $travar = false): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $db->prepare("SELECT id, enviado_em, avaliado_em, expirado, usuario_id, setor_id, protocolo
                              FROM pesquisa_convites WHERE token = ? LIMIT 1" . ($travar ? ' FOR UPDATE' : ''));
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * O que a página pública precisa: a situação do token e, quando ok, as categorias para marcar.
     * Nunca devolve dados do servidor nem do convite.
     */
    public static function estado(PDO $db, string $token): array {
        $regras = PesquisaConfig::regrasConvite($db);
        $situacao = self::situacao(self::buscarConvite($db, $token), $regras['expiracao_token_dias']);
        $categorias = [];
        if ($situacao === 'ok') {
            $categorias = $db->query("SELECT id, nome, emoji FROM pesquisa_categorias WHERE ativo = 1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($categorias as &$c) {
                $c['id'] = (int) $c['id'];
            }
            unset($c);
        }
        return ['situacao' => $situacao, 'categorias' => $categorias, 'expiracao_dias' => $regras['expiracao_token_dias']];
    }

    /**
     * Grava a avaliação. Notas 4 e 5 entram como "resolvido"; 1, 2 e 3 ficam "pendente" para a triagem
     * (a página diz ao cidadão, de 1 a 3, que a avaliação será analisada; decisão de 10/11/2026).
     * O alerta por e-mail continua só para 1 e 2.
     *
     * @param int[] $categoriaIds
     * @param callable|null $notificar (avaliacaoId, nota, comentario, usuarioId, setorId): void — troca o alerta nos testes
     * @return array{codigo:string, tipo?:string, google_url?:string}
     *         codigo: ok | invalido | ja_avaliado | expirado | nota_invalida
     */
    public static function registrar(PDO $db, string $token, int $nota, string $comentario, array $categoriaIds, ?callable $notificar = null): array {
        if ($nota < 1 || $nota > 5) {
            return ['codigo' => 'nota_invalida'];
        }
        $comentario = HtmlSanitizer::texto($comentario, self::COMENTARIO_MAX);
        $regras = PesquisaConfig::regrasConvite($db);

        $propria = !$db->inTransaction();
        if ($propria) {
            $db->beginTransaction();
        }
        try {
            $convite = self::buscarConvite($db, $token, true);
            $situacao = self::situacao($convite, $regras['expiracao_token_dias']);
            if ($situacao !== 'ok') {
                if ($propria) $db->rollBack();
                return ['codigo' => $situacao];
            }

            $db->prepare("INSERT INTO pesquisa_avaliacoes (convite_id, nota, comentario, status) VALUES (?, ?, ?, ?)")
               ->execute([$convite['id'], $nota, $comentario !== '' ? $comentario : null, $nota >= 4 ? 'resolvido' : 'pendente']);
            $avaliacaoId = (int) $db->lastInsertId();

            // Só categorias que existem e estão ativas
            $ids = array_values(array_unique(array_filter(array_map('intval', $categoriaIds), fn($i) => $i > 0)));
            if ($ids) {
                $validas = $db->query("SELECT id FROM pesquisa_categorias WHERE ativo = 1 AND id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_COLUMN);
                $ins = $db->prepare("INSERT IGNORE INTO pesquisa_avaliacao_categorias (avaliacao_id, categoria_id) VALUES (?, ?)");
                foreach ($validas as $categoriaId) {
                    $ins->execute([$avaliacaoId, $categoriaId]);
                }
            }
            $db->prepare("UPDATE pesquisa_convites SET avaliado_em = NOW() WHERE id = ?")->execute([$convite['id']]);
            if ($propria) $db->commit();
        } catch (Throwable $e) {
            if ($propria && $db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Alerta de nota baixa (1 e 2), depois de gravar: uma falha no e-mail não perde a avaliação
        if ($nota <= 2) {
            try {
                ($notificar ?? [self::class, 'notificarNotaBaixa'])($db, $avaliacaoId, $nota, $comentario);
            } catch (Throwable $e) {
                LogService::erro('pesquisa-satisfacao', 'Falha no alerta de nota baixa (avaliação #' . $avaliacaoId . '): ' . $e->getMessage());
            }
        }

        if ($nota >= 4) {
            return ['codigo' => 'ok', 'tipo' => 'positivo', 'google_url' => PesquisaConfig::get($db, 'google_review_url')];
        }
        return ['codigo' => 'ok', 'tipo' => 'critico'];
    }

    /** E-mail interno avisando da nota baixa (se ligado e com destinatário), pela fila de envio. */
    public static function notificarNotaBaixa(PDO $db, int $avaliacaoId, int $nota, string $comentario): void {
        $r = PesquisaConfig::todas($db);
        if ($r['notificar_avaliacao_critica'] !== '1' || $r['email_avaliacao_critica'] === '') {
            return;
        }
        require_once __DIR__ . '/email-sender.php';
        $e = fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $estrelas = str_repeat('★', $nota) . str_repeat('☆', 5 - $nota);
        $painel = rtrim((string) Environment::get('base_url'), '/') . '/frontend/index.html';
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;color:#333;">'
            . '<h2 style="color:#a7241c;">Avaliação com nota baixa recebida</h2>'
            . '<p style="font-size:24px;color:#a7241c;margin:0 0 12px;">' . $estrelas . '</p>'
            . '<p>Avaliação #' . $avaliacaoId . ' da pesquisa de satisfação:</p>'
            . '<blockquote style="background:#fdf2f1;border-left:4px solid #a7241c;padding:12px 16px;margin:0 0 16px;">'
            . ($comentario !== '' ? nl2br($e($comentario)) : '<em>Sem comentário</em>') . '</blockquote>'
            . '<p>Faça a triagem no Conecta: <a href="' . $e($painel) . '">Satisfação → Avaliações</a>.</p></div>';
        $sender = new EmailSender($db);
        $sender->setEmailContext(null, 'pesquisa_alerta');
        $sender->enviarEmail($r['email_avaliacao_critica'], "Avaliação com nota baixa recebida ({$nota}★) — CRPRS", $html);
    }
}
