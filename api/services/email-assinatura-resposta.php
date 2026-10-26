<?php
/**
 * Assinatura de quem responde ao solicitante, logo abaixo do texto, dentro do card "Resposta da equipe":
 *
 *   Atenciosamente,
 *   Nome completo
 *   Nome do setor
 *
 * Montada só na hora do envio (EmailSender::enviarComentarioParaSolicitante), nunca gravada no comentário:
 * a tela da demanda e o Histórico das Interações já mostram o autor. Estilos embutidos por causa do Outlook;
 * fonte e tamanho vêm do card (.resposta-box). Sem acesso a banco, para poder testar.
 */
class EmailAssinaturaResposta {

    public static function html(string $nome, string $setor): string {
        $nome = trim($nome);
        $setor = trim($setor);
        if ($nome === '') {
            return '';
        }
        $e = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };

        return '<div class="assinatura-resposta" style="margin-top:14px;">'
            . '<p style="margin:0 0 10px 0;">Atenciosamente,</p>'
            . '<p style="margin:0;font-weight:bold;">' . $e($nome) . '</p>'
            . ($setor !== '' ? '<p style="margin:0;">' . $e($setor) . '</p>' : '')
            . '</div>';
    }
}
