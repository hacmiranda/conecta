<?php
/**
 * Cabeçalho padrão dos e-mails enviados ao solicitante ("papel timbrado": logotipo à esquerda,
 * título à direita, faixa tricolor laranja/verde/vinho embaixo). Mesmo tamanho e mesmas cores em todos os modelos;
 * só o título muda. Tabelas e estilos embutidos de propósito: é o que Outlook/Gmail interpretam.
 *
 * Os modelos em backend/email-template-*.html trazem o marcador {{CABECALHO:Título}}, que
 * EmailSender::carregarModelo() troca por este HTML. Também usado pelos mockups (mockups/).
 */
class EmailCabecalho {
    const LOGO = 'http://www.crprs.org.br/assets/frontend/img/logo.png';
    const INSTITUICAO = 'Conselho Regional de Psicologia do RS';

    // Paleta oficial CRPRS (a mesma do Sycer e do frontend)
    const VINHO_600 = '#a7241c';
    const VINHO_700 = '#8b1d17';
    const LARANJA_500 = '#f89327';
    const VERDE_500 = '#70ab2a';
    const NEUTRO_200 = '#ddd6cd';
    const NEUTRO_600 = '#5f5852';

    public static function html(string $titulo): string {
        $e = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
        $fonte = 'font-family:Arial,Helvetica,sans-serif;';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
            . '<tr><td bgcolor="#ffffff" style="' . $fonte . 'padding:14px 24px;border:1px solid ' . self::NEUTRO_200 . ';border-top:none;border-bottom:none;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
            . '<td valign="middle" width="40%" style="padding:0;"><img src="' . self::LOGO . '" alt="CRPRS" width="110" style="display:block;border:0;height:auto;"></td>'
            . '<td valign="middle" align="right" style="' . $fonte . 'padding:0;">'
            . '<div style="' . $fonte . 'font-size:11px;line-height:14px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:' . self::NEUTRO_600 . ';">' . self::INSTITUICAO . '</div>'
            . '<div style="' . $fonte . 'font-size:20px;line-height:26px;font-weight:bold;color:' . self::VINHO_700 . ';padding-top:4px;">' . $e($titulo) . '</div>'
            . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td style="padding:0;">' . self::faixaTricolor() . '</td></tr></table>';
    }

    /**
     * Faixa tricolor embaixo do cabeçalho (laranja, verde, vinho), como nos e-mails institucionais do CRPRS.
     * Células com bgcolor + altura fixa: é o que o Outlook respeita (div com altura ele ignora).
     */
    public static function faixaTricolor(): string {
        $celula = function (string $cor, string $largura) {
            return '<td width="' . $largura . '" height="5" bgcolor="' . $cor . '" style="height:5px;font-size:0;line-height:0;background:' . $cor . ';">&nbsp;</td>';
        };
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
            . $celula(self::LARANJA_500, '34%') . $celula(self::VERDE_500, '33%') . $celula(self::VINHO_600, '33%')
            . '</tr></table>';
    }

    /**
     * Troca todos os marcadores {{CABECALHO:Título}} do modelo pelo cabeçalho padrão.
     */
    public static function aplicar(string $modelo): string {
        return preg_replace_callback('/\{\{CABECALHO:([^}]*)\}\}/', function ($m) {
            return self::html(trim($m[1]));
        }, $modelo);
    }
}
