<?php
/**
 * Leitura de um e-mail recebido (corpo e anexos), sem depender do IMAP: recebe a estrutura no
 * formato de imap_fetchstructure() e uma função que devolve o conteúdo cru de uma seção
 * ("1", "1.2", ...). Assim dá para testar com amostras .eml (api/tests/Unit/EmailMimeTest.php).
 *
 * Regras:
 * - Corpo: percorre todas as partes, em qualquer profundidade (Outlook com anexo põe o texto em
 *   multipart/mixed > multipart/related > multipart/alternative). Prefere text/plain; sem ele, o
 *   text/html vira texto. Só fica de fora a parte marcada como anexo (disposition=attachment ou
 *   com nome de arquivo): o Apple Mail manda o próprio texto como "inline".
 * - Charset convertido para UTF-8 também no e-mail simples (sem partes).
 * - Anexos: partes com nome de arquivo, disposition=attachment ou imagens. Imagem embutida pequena
 *   (logotipo de assinatura, até LIMITE_IMAGEM_ASSINATURA, sem disposição de anexo ou com Content-ID)
 *   não conta como anexo; print colado no corpo (maior que isso) entra. Imagem pequena anexada de
 *   verdade (attachment sem Content-ID) entra.
 * - Nome do arquivo em RFC 2231 (filename*=UTF-8''...) ou em encoded-word (=?UTF-8?B?...?=).
 * - E-mail encaminhado (message/rfc822) não tem o corpo misturado ao da mensagem.
 */
final class EmailMime {
    const TIPO_TEXTO = 0;
    const TIPO_MULTIPART = 1;
    const TIPO_MENSAGEM = 2;
    const TIPO_IMAGEM = 5;

    const LIMITE_IMAGEM_ASSINATURA = 30 * 1024;

    /** Decodifica o conteúdo cru segundo o Content-Transfer-Encoding (3 = base64, 4 = quoted-printable). */
    public static function decodificar(string $cru, int $encoding): string {
        if ($encoding === 3) {
            return (string) base64_decode(preg_replace('/\s+/', '', $cru));
        }
        if ($encoding === 4) {
            return quoted_printable_decode($cru);
        }
        return $cru;
    }

    /** Valor de um parâmetro MIME (charset, name, filename), juntando as partes de RFC 2231. */
    public static function parametro(object $parte, string $nome, bool $disposicao = false): string {
        $lista = $disposicao ? ($parte->dparameters ?? []) : ($parte->parameters ?? []);
        if (!is_array($lista)) {
            return '';
        }
        $nome = strtolower($nome);
        $simples = '';
        $pedacos = [];
        $codificado = false;
        foreach ($lista as $p) {
            $atributo = strtolower((string) ($p->attribute ?? ''));
            $valor = (string) ($p->value ?? '');
            if ($atributo === $nome) {
                $simples = $valor;
            } elseif ($atributo === $nome . '*') {
                $pedacos[0] = $valor;
                $codificado = true;
            } elseif (preg_match('/^' . preg_quote($nome, '/') . '\*(\d+)(\*?)$/', $atributo, $m)) {
                $pedacos[(int) $m[1]] = $valor;
                $codificado = $codificado || $m[2] === '*';
            }
        }
        if ($pedacos) {
            ksort($pedacos);
            $valor = implode('', $pedacos);
            if ($codificado && preg_match("/^([^']*)'[^']*'(.*)$/s", $valor, $m)) {
                $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
                return self::paraUtf8(rawurldecode($m[2]), $charset);
            }
            return $codificado ? rawurldecode($valor) : $valor;
        }
        return $simples;
    }

    /** Nome do arquivo de uma parte (filename da disposição, senão name do Content-Type), já decodificado. */
    public static function nomeArquivo(object $parte): string {
        $nome = self::parametro($parte, 'filename', true);
        if ($nome === '') {
            $nome = self::parametro($parte, 'name');
        }
        if ($nome === '') {
            return '';
        }
        if (str_contains($nome, '=?')) {
            $decodificado = @iconv_mime_decode($nome, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decodificado !== false) {
                $nome = $decodificado;
            }
        }
        // Nunca um caminho: só o nome (barras do Windows também)
        return trim(basename(str_replace(chr(92), '/', $nome)));
    }

    public static function charset(object $parte): string {
        $c = self::parametro($parte, 'charset');
        return $c !== '' ? $c : 'UTF-8';
    }

    /** Converte para UTF-8 válido (charset desconhecido: tenta Windows-1252, o mais comum sem declaração). */
    public static function paraUtf8(string $texto, string $charset = 'UTF-8'): string {
        $charset = strtoupper(trim($charset, " \t\"'"));
        if (in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII', ''], true)) {
            return mb_check_encoding($texto, 'UTF-8') ? $texto : mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        if ($charset === 'ISO-8859-1' || $charset === 'LATIN1') {
            $charset = 'Windows-1252'; // superconjunto: aspas e travessões do Outlook
        }
        // Charset que o PHP não conhece lança ValueError (antes derrubava o processamento do e-mail)
        try {
            $convertido = mb_convert_encoding($texto, 'UTF-8', $charset);
        } catch (ValueError $e) {
            $convertido = @iconv($charset, 'UTF-8//IGNORE', $texto);
        }
        if ($convertido !== false && $convertido !== '' && mb_check_encoding($convertido, 'UTF-8')) {
            return $convertido;
        }
        return mb_check_encoding($texto, 'UTF-8') ? $texto : mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }

    private static function subtipo(object $parte): string {
        return strtolower((string) ($parte->subtype ?? ''));
    }

    private static function disposicao(object $parte): string {
        return !empty($parte->ifdisposition) || isset($parte->disposition) ? strtolower((string) ($parte->disposition ?? '')) : '';
    }

    /** A parte é um arquivo anexado (e não o texto da mensagem)? */
    private static function ehArquivo(object $parte): bool {
        return self::disposicao($parte) === 'attachment' || self::nomeArquivo($parte) !== '';
    }

    /**
     * Lista as partes folha com a seção IMAP. O e-mail simples (sem partes) é a seção "1".
     * Não entra no conteúdo de e-mail encaminhado (message/rfc822): ele é anexo da mensagem.
     * @return array<int, array{secao:string, parte:object}>
     */
    public static function folhas(object $estrutura): array {
        if (empty($estrutura->parts)) {
            return [['secao' => '1', 'parte' => $estrutura]];
        }
        $saida = [];
        $andar = function (array $partes, string $prefixo) use (&$andar, &$saida) {
            foreach (array_values($partes) as $i => $parte) {
                $secao = $prefixo . ($i + 1);
                $tipo = (int) ($parte->type ?? self::TIPO_TEXTO);
                if ($tipo === self::TIPO_MULTIPART && !empty($parte->parts)) {
                    $andar($parte->parts, $secao . '.');
                    continue;
                }
                $saida[] = ['secao' => $secao, 'parte' => $parte];
            }
        };
        $andar($estrutura->parts, '');
        return $saida;
    }

    /**
     * Texto da mensagem, em UTF-8 e sem HTML (o chamador escapa antes de gravar).
     * @param callable(string):string $buscar conteúdo cru (ainda codificado) de uma seção
     */
    public static function corpoTexto(object $estrutura, callable $buscar): string {
        $texto = null;
        $html = null;
        foreach (self::folhas($estrutura) as $folha) {
            $parte = $folha['parte'];
            if ((int) ($parte->type ?? self::TIPO_TEXTO) !== self::TIPO_TEXTO || self::ehArquivo($parte)) {
                continue;
            }
            $sub = self::subtipo($parte);
            if ($sub !== 'plain' && $sub !== 'html') {
                continue;
            }
            $conteudo = self::paraUtf8(self::decodificar((string) $buscar($folha['secao']), (int) ($parte->encoding ?? 0)), self::charset($parte));
            if ($sub === 'plain' && $texto === null && trim($conteudo) !== '') {
                $texto = $conteudo;
            } elseif ($sub === 'html' && $html === null && trim(strip_tags($conteudo)) !== '') {
                $html = $conteudo;
            }
        }

        if ($texto !== null) {
            $saida = $texto;
        } elseif ($html !== null) {
            $saida = self::htmlParaTexto($html);
        } else {
            return '';
        }
        return self::arrumarLinhas($saida);
    }

    /** HTML de e-mail para texto: tira <style>/<script>/<head>, mantém as quebras de linha. */
    public static function htmlParaTexto(string $html): string {
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $html = preg_replace('#<(head|style|script|title|xml)\b[^>]*>.*?</\1\s*>#is', '', $html);
        // No HTML a quebra de linha do código-fonte é só espaço; as quebras vêm das tags
        $html = preg_replace('/\s+/', ' ', $html);
        // <br> = quebra explícita; </p> = parágrafo (linha em branco); demais blocos (<div> por linha,
        // como no Gmail/Outlook) = uma quebra só, mesmo quando vários se encostam
        $bloco = "\x02";
        $html = preg_replace('#<br\b[^>]*>#i', "\n", $html);
        $html = preg_replace('#</p\s*>#i', "\n\n", $html);
        $html = preg_replace('#<li\b[^>]*>#i', $bloco . '• ', $html);
        $html = preg_replace('#</?(p|div|tr|li|h[1-6]|blockquote|table|ul|ol|pre)\b[^>]*>#i', $bloco, $html);
        $html = preg_replace('#</t[dh]\s*>#i', ' ', $html);
        $html = preg_replace('/[' . $bloco . ' ]*' . $bloco . '[' . $bloco . ' ]*/', "\n", $html);
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = str_replace("\u{00A0}", ' ', $texto);
        return preg_replace('/[ \t]{2,}/', ' ', $texto);
    }

    /** Normaliza quebras: CRLF → LF, sem espaços no fim, no máximo uma linha em branco seguida. */
    private static function arrumarLinhas(string $texto): string {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[ \t]+\n/', "\n", $texto);
        $texto = preg_replace('/^[ \t]+/m', '', $texto);
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);
        return trim($texto);
    }

    /**
     * Arquivos da mensagem (ainda não validados: o chamador passa pelo UploadService).
     * @param callable(string):string $buscar conteúdo cru de uma seção
     * @return array<int, array{secao:string, nome:string, conteudo:string, tamanho:int}>
     */
    public static function anexos(object $estrutura, callable $buscar): array {
        $saida = [];
        if (empty($estrutura->parts)) {
            return $saida; // e-mail simples: é só texto
        }
        $sequencia = 0;
        foreach (self::folhas($estrutura) as $folha) {
            $parte = $folha['parte'];
            $tipo = (int) ($parte->type ?? self::TIPO_TEXTO);
            $nome = self::nomeArquivo($parte);
            $anexoExplicito = self::disposicao($parte) === 'attachment';
            $imagem = $tipo === self::TIPO_IMAGEM;

            if ($nome === '' && !$anexoExplicito && !$imagem) {
                continue; // texto/HTML da mensagem
            }

            $conteudo = self::decodificar((string) $buscar($folha['secao']), (int) ($parte->encoding ?? 0));
            $tamanho = strlen($conteudo);
            if ($tamanho === 0) {
                continue;
            }

            // Logotipo/ícone da assinatura: imagem pequena embutida no corpo (sem disposição de anexo, ou
            // com Content-ID, que é como o HTML a referencia; o Outlook às vezes a marca como "attachment")
            $embutida = !$anexoExplicito || !empty($parte->id);
            if ($imagem && $embutida && $tamanho <= self::LIMITE_IMAGEM_ASSINATURA) {
                continue;
            }

            if ($nome === '') {
                $sequencia++;
                if ($tipo === self::TIPO_MENSAGEM) {
                    $nome = 'mensagem_encaminhada_' . $sequencia . '.eml';
                } else {
                    $ext = self::subtipo($parte) === 'jpeg' ? 'jpg' : (self::subtipo($parte) ?: 'bin');
                    $nome = ($imagem ? 'imagem_' : 'anexo_') . $sequencia . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
                }
            }
            $saida[] = ['secao' => $folha['secao'], 'nome' => $nome, 'conteudo' => $conteudo, 'tamanho' => $tamanho];
        }
        return $saida;
    }
}
