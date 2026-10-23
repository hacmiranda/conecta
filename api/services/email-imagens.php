<?php
/**
 * Imagens do texto dos e-mails.
 *
 * Imagens coladas/inseridas nos editores ficam no texto como <img src="data:image/...;base64,...">.
 * Gmail e Outlook não mostram esse formato, então, na hora do envio, cada imagem é anexada ao e-mail
 * como imagem inline (cid:) e o texto passa a apontar para ela.
 */
use PHPMailer\PHPMailer\PHPMailer;

class EmailImagens {
    const MAX_IMAGENS = 10;
    const MAX_BYTES = 10 * 1024 * 1024; // 10 MB somando todas as imagens

    /**
     * Troca as imagens em base64 por imagens embutidas no `$mail`. O que passar do limite de
     * quantidade/tamanho (ou vier corrompido) vira um aviso, para o e-mail não ficar gigante.
     */
    public static function embutir(PHPMailer $mail, string $html): string {
        if (stripos($html, 'data:image/') === false) {
            return $html;
        }
        $contador = 0;
        $bytes = 0;
        $tipos = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'];

        return preg_replace_callback(
            '/<img\b([^>]*?)\bsrc=(["\'])data:image\/(png|jpe?g|gif);base64,([A-Za-z0-9+\/=\s]+)\2([^>]*)>/i',
            function ($m) use ($mail, &$contador, &$bytes, $tipos) {
                $binario = base64_decode(preg_replace('/\s+/', '', $m[4]), true);
                if ($binario === false || $binario === '' || $contador >= self::MAX_IMAGENS || $bytes + strlen($binario) > self::MAX_BYTES) {
                    return '<em style="color:#8b837b;">[Imagem não incluída: veja no sistema]</em>';
                }
                $contador++;
                $bytes += strlen($binario);
                $extensao = strtolower($m[3]);
                $cid = 'img' . $contador . '-' . bin2hex(random_bytes(4)) . '@conecta';
                $mail->addStringEmbeddedImage(
                    $binario,
                    $cid,
                    'imagem-' . $contador . '.' . ($extensao === 'jpeg' ? 'jpg' : $extensao),
                    PHPMailer::ENCODING_BASE64,
                    $tipos[$extensao] ?? 'image/png'
                );
                // max-width: a imagem nunca passa da largura do e-mail
                return '<img' . $m[1] . 'src="cid:' . $cid . '"' . rtrim($m[5], " /") . ' style="max-width:100%;height:auto;">';
            },
            $html
        );
    }
}
