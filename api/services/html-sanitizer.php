<?php
/**
 * Limpeza de conteúdo enviado por usuários, e-mails e integrações antes de gravar no banco.
 *
 * - rico(): HTML do editor (descrição, comentário, macro) — mantém só tags e estilos seguros
 *   (HTML Purifier, lista fechada). Remove scripts, eventos (onerror...), javascript: etc.
 * - texto(): campos de texto simples (título, nome, descrição de lembrete) — sem nenhuma tag.
 *
 * O frontend também escapa/limpa na exibição; esta é a primeira barreira.
 */
require_once __DIR__ . '/../vendor/autoload.php';

class HtmlSanitizer {

    private static $purifier = null;

    private static function purifier(): HTMLPurifier {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('Core.Encoding', 'UTF-8');
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
            $config->set('HTML.Allowed',
                'p[class|style],br,strong,b,em,i,u,s,strike,h1,h2,h3,h4,h5,h6,ul,ol,li[class],blockquote,pre[class],code,'
                . 'span[style|class],a[href|target|rel],img[src|alt|width|height]');
            $config->set('CSS.AllowedProperties', ['font-size', 'color', 'background-color', 'text-align']);
            // Classes geradas pelo editor Quill (alinhamento, recuo, tamanho, bloco de código)
            $config->set('Attr.AllowedClasses', [
                'ql-align-center', 'ql-align-right', 'ql-align-justify',
                'ql-indent-1', 'ql-indent-2', 'ql-indent-3', 'ql-indent-4',
                'ql-size-small', 'ql-size-large', 'ql-size-huge', 'ql-syntax',
            ]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetNoopener', true);
            $config->set('HTML.TargetNoreferrer', true);
            // Imagens coladas no editor chegam como data:image/... (base64)
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
            $config->set('AutoFormat.RemoveEmpty', false);

            // Cache das definições fora do vendor (pasta gravável e protegida em backend/)
            $cache = dirname(__DIR__, 2) . '/backend/cache/htmlpurifier';
            if (!is_dir($cache)) {
                @mkdir($cache, 0750, true);
            }
            if (is_dir($cache) && is_writable($cache)) {
                $config->set('Cache.SerializerPath', $cache);
            } else {
                $config->set('Cache.DefinitionImpl', null);
            }

            self::$purifier = new HTMLPurifier($config);
        }
        return self::$purifier;
    }

    /**
     * HTML rico do editor: devolve apenas o HTML permitido.
     */
    public static function rico(?string $html): string {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }
        // Imagem corrompida faz o Purifier (exif_imagetype) emitir aviso do PHP, que sairia antes do JSON
        // da resposta: vai para o log, e a imagem inválida é descartada normalmente
        set_error_handler(function (int $nivel, string $msg) {
            error_log('[HtmlSanitizer] ' . $msg);
            return true;
        }, E_WARNING | E_NOTICE);
        try {
            // data: só é aceito para imagens (bloqueia links data:text/html)
            $limpo = self::purifier()->purify($html);
        } finally {
            restore_error_handler();
        }
        return preg_replace('/(<a\b[^>]*\bhref=")data:[^"]*"/i', '$1#"', $limpo);
    }

    /**
     * Texto puro de um HTML, para a busca e o resumo das listas (tickets.descricao_texto): sem tags nem
     * imagens (o base64 delas não entra), entidades decodificadas, espaços normalizados, até $maximo.
     */
    public static function textoParaBusca(?string $html, int $maximo = 5000): string {
        $html = preg_replace('#<(img|style|script)\b[^>]*>(.*?</\1>)?#is', ' ', (string) $html);
        $texto = html_entity_decode(strip_tags(preg_replace('/<[^>]*>/', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $texto)) ?? '');
        return mb_substr($texto, 0, $maximo);
    }

    /**
     * Texto simples: remove qualquer tag, normaliza espaços e limita o tamanho (em caracteres).
     */
    public static function texto(?string $texto, int $maximo = 255): string {
        $texto = strip_tags((string) $texto);
        $texto = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '');
        if ($maximo > 0 && mb_strlen($texto) > $maximo) {
            $texto = mb_substr($texto, 0, $maximo);
        }
        return $texto;
    }
}
