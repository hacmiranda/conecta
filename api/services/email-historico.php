<?php
/**
 * Bloco "Demanda registrada" + "Histórico da conversa" dos e-mails ao solicitante.
 *
 * O histórico mostra só a conversa entre o CRPRS e o solicitante: comentários com
 * `visivel_solicitante = 1` e `interno = 0`. Notas internas, comentários da equipe que não foram
 * enviados ao solicitante e passagens internas (devolver/repassar/transferir/mesclar) ficam de fora.
 * Os textos já foram limpos ao gravar; passam de novo por HtmlSanitizer::rico() antes de irem
 * para o e-mail, e imagens coladas (base64) viram um aviso para não estourar o tamanho.
 *
 * Os modelos em backend/email-template*.html trazem o marcador {{DEMANDA_E_HISTORICO}};
 * EmailSender::carregarModelo() troca por este bloco.
 */
require_once __DIR__ . '/html-sanitizer.php';
require_once __DIR__ . '/email-tipografia.php';

class EmailHistorico {
    // Quantas mensagens entram (as mais recentes); evita e-mail gigante em conversas longas
    const MAX_MENSAGENS = 30;

    /**
     * Busca a demanda e as mensagens visíveis ao solicitante (mais recentes primeiro).
     * @return array{demanda: ?array, mensagens: array, total: int}
     */
    public static function buscar(PDO $db, int $ticketId, ?int $excluirComentarioId = null): array {
        $stmt = $db->prepare("SELECT t.id, t.titulo, t.descricao, t.criado_em, t.usuario_id, t.email_solicitante,
                                     u.nome AS usuario_nome, u.email AS usuario_email
                              FROM tickets t LEFT JOIN usuarios u ON u.id = t.usuario_id
                              WHERE t.id = ?");
        $stmt->execute([$ticketId]);
        $demanda = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$demanda) {
            return ['demanda' => null, 'mensagens' => [], 'total' => 0];
        }

        $onde = "c.ticket_id = :t AND c.interno = 0 AND c.visivel_solicitante = 1";
        $params = [':t' => $ticketId];
        if ($excluirComentarioId) {
            $onde .= " AND c.id <> :ex";
            $params[':ex'] = $excluirComentarioId;
        }

        $cont = $db->prepare("SELECT COUNT(*) FROM comentarios c WHERE $onde");
        $cont->execute($params);
        $total = (int) $cont->fetchColumn();

        $lista = $db->prepare("SELECT c.id, c.comentario, c.criado_em, c.usuario_id, u.nome, u.tipo
                               FROM comentarios c JOIN usuarios u ON u.id = c.usuario_id
                               WHERE $onde
                               ORDER BY c.criado_em DESC, c.id DESC
                               LIMIT " . self::MAX_MENSAGENS);
        $lista->execute($params);
        $mensagens = $lista->fetchAll(PDO::FETCH_ASSOC);

        // Nomes dos anexos de cada mensagem
        if ($mensagens) {
            $ids = array_map('intval', array_column($mensagens, 'id'));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $anx = $db->prepare("SELECT comentario_id, nome_original FROM anexos WHERE comentario_id IN ($in) ORDER BY id");
            $anx->execute($ids);
            $porComentario = [];
            foreach ($anx->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $porComentario[(int) $a['comentario_id']][] = $a['nome_original'];
            }
            foreach ($mensagens as &$m) {
                $m['anexos'] = $porComentario[(int) $m['id']] ?? [];
            }
            unset($m);
        }

        return ['demanda' => $demanda, 'mensagens' => $mensagens, 'total' => $total];
    }

    /** Busca e já devolve o HTML do bloco (vazio se a demanda não existe). */
    public static function bloco(PDO $db, int $ticketId, ?int $excluirComentarioId = null): string {
        $d = self::buscar($db, $ticketId, $excluirComentarioId);
        return $d['demanda'] ? self::renderizar($d['demanda'], $d['mensagens'], $d['total']) : '';
    }

    /**
     * Monta o HTML (sem acesso a banco, para poder testar). `$mensagens` vem mais recente primeiro.
     */
    public static function renderizar(array $demanda, array $mensagens, int $total): string {
        $e = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
        $fonte = 'font-family:Arial,Helvetica,sans-serif;';
        $idSolicitante = (int) ($demanda['usuario_id'] ?? 0);
        $solicitante = trim((string) ($demanda['email_solicitante'] ?? '')) ?: (string) ($demanda['usuario_email'] ?? '');

        $html = '<div style="' . $fonte . 'margin-top:20px;border:1px solid #ddd6cd;border-radius:8px;background:#ffffff;color:#2b2523;font-size:14px;line-height:21px;">';

        // --- Demanda registrada ---
        $html .= '<div style="padding:12px 20px;border-bottom:1px solid #efeae4;font-size:15px;font-weight:bold;color:#8b1d17;">Demanda registrada</div>';
        $html .= '<div style="padding:16px 20px;">';
        $html .= '<div style="font-size:12px;line-height:18px;color:#5f5852;">Demanda #' . str_pad((string) $demanda['id'], 5, '0', STR_PAD_LEFT)
            . ' &middot; aberta em ' . $e(date('d/m/Y H:i', strtotime((string) $demanda['criado_em'])))
            . ($solicitante !== '' ? ' &middot; solicitante: ' . $e($solicitante) : '') . '</div>';
        $html .= '<div style="font-size:16px;font-weight:bold;color:#2b2523;padding:6px 0 8px;">' . $e($demanda['titulo']) . '</div>';
        $html .= '<div style="border-left:4px solid #a7241c;background:#f7f4f0;padding:10px 14px;">' . self::texto((string) ($demanda['descricao'] ?? '')) . '</div>';
        $html .= '</div>';

        // --- Histórico da conversa ---
        if ($mensagens) {
            $html .= '<div style="padding:12px 20px;border-top:1px solid #efeae4;border-bottom:1px solid #efeae4;font-size:15px;font-weight:bold;color:#8b1d17;">Histórico da conversa</div>';
            $html .= '<div style="padding:2px 20px 16px;">';
            foreach ($mensagens as $m) {
                $equipe = in_array($m['tipo'] ?? '', ['admin', 'gestor', 'operador'], true) && (int) $m['usuario_id'] !== $idSolicitante;
                if ($equipe) {
                    [$papel, $cor] = ['CRPRS', '#a7241c'];
                } elseif ((int) $m['usuario_id'] === $idSolicitante) {
                    [$papel, $cor] = ['Solicitante', '#5f5852'];
                } else {
                    [$papel, $cor] = ['Participante', '#5f5852'];
                }

                $anexos = '';
                if (!empty($m['anexos'])) {
                    $anexos = '<div style="padding-top:6px;font-size:12px;color:#5f5852;">Anexos: '
                        . implode(', ', array_map($e, $m['anexos'])) . '</div>';
                }

                $html .= '<div style="margin-top:10px;border:1px solid #ddd6cd;border-left:4px solid ' . $cor . ';border-radius:4px;background:#ffffff;">'
                    . '<div style="padding:8px 14px;background:#f7f4f0;font-size:12px;line-height:18px;color:#5f5852;">'
                    . '<strong style="color:#2b2523;">' . $e($m['nome']) . '</strong> &middot; '
                    . '<span style="font-weight:bold;color:' . $cor . ';">' . $papel . '</span> &middot; '
                    . $e(date('d/m/Y H:i', strtotime((string) $m['criado_em']))) . '</div>'
                    . '<div style="padding:10px 14px;">' . self::texto((string) $m['comentario']) . $anexos . '</div>'
                    . '</div>';
            }
            if ($total > count($mensagens)) {
                $html .= '<div style="padding-top:10px;font-size:12px;color:#5f5852;">Exibindo as ' . count($mensagens)
                    . ' mensagens mais recentes de ' . $total . '.</div>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Limpa o HTML de um texto. As imagens coladas (base64) ficam: EmailImagens::embutir() as
     * embute no e-mail no envio (com limite de quantidade e tamanho).
     */
    private static function texto(string $conteudo): string {
        $limpo = EmailTipografia::padronizar(HtmlSanitizer::rico($conteudo));
        // Evita que um texto com "{{...}}" seja tratado como marcador do modelo
        return str_replace(['{{', '}}'], ['&#123;&#123;', '&#125;&#125;'], $limpo);
    }
}
