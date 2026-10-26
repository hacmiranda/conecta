<?php
/**
 * Seção "Histórico das Interações" dos e-mails ao solicitante (a demanda registrada + a conversa, numa lista só).
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
require_once __DIR__ . '/protocolo-service.php';

class EmailHistorico {
    // Quantas mensagens entram (as mais recentes); evita e-mail gigante em conversas longas
    const MAX_MENSAGENS = 30;

    /**
     * Busca a demanda e as mensagens visíveis ao solicitante (mais recentes primeiro).
     * @return array{demanda: ?array, mensagens: array, total: int}
     */
    public static function buscar(PDO $db, int $ticketId, ?int $excluirComentarioId = null): array {
        $stmt = $db->prepare("SELECT t.id, t.protocolo, t.titulo, t.descricao, t.criado_em, t.usuario_id, t.email_solicitante,
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
     *
     * Uma seção só, "Histórico das Interações": da mais recente para a mais antiga, com a abertura
     * da demanda (título + descrição) como o último cartão. Respostas do CRPRS em branco com borda
     * laranja (o fundo amarelado fica só para a resposta atual, no corpo do e-mail); solicitante e
     * participantes em cinza neutro. O corpo de cada cartão tem a classe `interacao-corpo`, que os
     * modelos usam para deixar os parágrafos compactos.
     */
    public static function renderizar(array $demanda, array $mensagens, int $total): string {
        $e = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
        $data = function ($d) use ($e) { return $e(date('d/m/Y H:i', strtotime((string) $d))); };
        $fonte = 'font-family:Arial,Helvetica,sans-serif;';
        $idSolicitante = (int) ($demanda['usuario_id'] ?? 0);
        $emailSolicitante = trim((string) ($demanda['email_solicitante'] ?? '')) ?: (string) ($demanda['usuario_email'] ?? '');
        $nomeSolicitante = trim((string) ($demanda['usuario_nome'] ?? '')) ?: $emailSolicitante;

        $cartao = function (bool $equipe, string $nome, string $papel, string $quando, string $corpo) use ($e) {
            [$borda, $bordaLado, $fundoTopo, $corPapel] = $equipe
                ? ['#fbdcb6', '#f89327', '#ffffff', '#a7241c']
                : ['#ddd6cd', '#5f5852', '#f7f4f0', '#5f5852'];
            return '<div style="margin-top:8px;border:1px solid ' . $borda . ';border-left:4px solid ' . $bordaLado . ';border-radius:0 4px 4px 0;background:#ffffff;">'
                . '<div style="padding:6px 12px;background:' . $fundoTopo . ';border-bottom:1px solid ' . $borda . ';font-size:12px;line-height:17px;color:#5f5852;">'
                . '<strong style="color:#2b2523;">' . $e($nome) . '</strong> &middot; '
                . '<span style="font-weight:bold;color:' . $corPapel . ';">' . $papel . '</span> &middot; ' . $quando . '</div>'
                . '<div class="interacao-corpo" style="padding:8px 12px;line-height:1.4;">' . $corpo . '</div>'
                . '</div>';
        };

        $html = '<div style="' . $fonte . 'margin-top:20px;border:1px solid #ddd6cd;border-radius:8px;background:#ffffff;color:#2b2523;font-size:14px;line-height:20px;">';
        $rotulo = ProtocoloService::rotulo($demanda['protocolo'] ?? null, (int) $demanda['id']);
        $html .= '<div style="padding:10px 16px;border-bottom:1px solid #efeae4;">'
            . '<div style="font-size:15px;font-weight:bold;color:#8b1d17;">Histórico das Interações</div>'
            . '<div style="font-size:12px;line-height:17px;color:#5f5852;">Demanda ' . $e($rotulo) . ' &middot; da mais recente para a mais antiga</div>'
            . '</div>';
        $html .= '<div style="padding:2px 16px 12px;">';

        foreach ($mensagens as $m) {
            $equipe = in_array($m['tipo'] ?? '', ['admin', 'gestor', 'operador'], true) && (int) $m['usuario_id'] !== $idSolicitante;
            $papel = $equipe ? 'CRPRS' : ((int) $m['usuario_id'] === $idSolicitante ? 'Solicitante' : 'Participante');
            $anexos = empty($m['anexos']) ? '' : '<div style="padding-top:4px;font-size:12px;color:#5f5852;">Anexos: '
                . implode(', ', array_map($e, $m['anexos'])) . '</div>';
            $html .= $cartao($equipe, (string) $m['nome'], $papel, $data($m['criado_em']), self::texto((string) $m['comentario']) . $anexos);
        }
        if ($total > count($mensagens)) {
            $html .= '<div style="padding-top:8px;font-size:12px;color:#5f5852;">&hellip; ' . ($total - count($mensagens))
                . ' interações anteriores não exibidas.</div>';
        }

        // A abertura da demanda fecha a lista (é a interação mais antiga)
        $abertura = '<div style="font-size:12px;font-weight:bold;letter-spacing:0.3px;text-transform:uppercase;color:#8b1d17;">Demanda registrada</div>'
            . '<div style="font-size:15px;font-weight:bold;color:#2b2523;padding:2px 0 4px;">' . $e($demanda['titulo']) . '</div>'
            . self::texto((string) ($demanda['descricao'] ?? ''));
        $html .= $cartao(false, $nomeSolicitante, 'Solicitante', $data($demanda['criado_em']), $abertura);

        return $html . '</div></div>';
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
