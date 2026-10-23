<?php
/**
 * Tipografia padrão das respostas da equipe nos e-mails ao solicitante.
 *
 * O texto escrito no editor pode trazer fontes e tamanhos próprios (estilos embutidos, títulos
 * grandes, texto colado de outros programas). Aqui tudo isso é descartado e o conteúdo é
 * envolvido numa única fonte, tamanho e entrelinha — a resposta chega sempre com a mesma
 * aparência. Negrito, itálico, listas, links e cores continuam. A mesma fonte vale no sistema
 * (tokens --fonte-resposta/--tamanho-resposta em frontend/style.css).
 *
 * Fonte: Segoe UI (Windows) com Verdana/Arial de reserva — comuns nos clientes de e-mail.
 */
class EmailTipografia {
    const FONTE = "'Segoe UI',Verdana,Arial,sans-serif";
    const TAMANHO = '15px';
    const ENTRELINHA = '1.6';
    const COR = '#2b2523';

    // Declarações de estilo que deixam de valer (tipo, tamanho e espaçamento de letra)
    private const REMOVER = ['font-size', 'font-family', 'font', 'line-height', 'letter-spacing'];

    public static function padronizar(string $html): string {
        // 1) Tira fonte/tamanho/entrelinha dos estilos embutidos, mantendo o resto (cor, alinhamento)
        $html = preg_replace_callback('/\sstyle=("|\')(.*?)\1/is', function ($m) {
            $mantidas = [];
            foreach (explode(';', $m[2]) as $decl) {
                $decl = trim($decl);
                if ($decl === '') {
                    continue;
                }
                $prop = strtolower(trim(explode(':', $decl, 2)[0]));
                if (!in_array($prop, self::REMOVER, true)) {
                    $mantidas[] = $decl;
                }
            }
            return $mantidas ? ' style="' . htmlspecialchars(implode('; ', $mantidas), ENT_QUOTES, 'UTF-8') . '"' : '';
        }, $html);

        // 2) Tags <font> e atributos antigos de tamanho/fonte
        $html = preg_replace('/<\/?font\b[^>]*>/i', '', $html);
        $html = preg_replace('/\s(size|face)=("[^"]*"|\'[^\']*\')/i', '', $html);

        // 3) Títulos (h1–h6) viram parágrafo em negrito, no mesmo tamanho do texto
        $html = preg_replace('/<h[1-6]\b[^>]*>/i', '<p style="margin:0 0 8px 0;font-weight:bold;">', $html);
        $html = preg_replace('/<\/h[1-6]>/i', '</p>', $html);

        // 4) Fonte única para tudo
        return '<div style="font-family:' . self::FONTE . ';font-size:' . self::TAMANHO . ';line-height:' . self::ENTRELINHA . ';color:' . self::COR . ';">'
            . $html . '</div>';
    }
}
