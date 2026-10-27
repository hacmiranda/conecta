<?php
/**
 * Apoio dos testes: lê um arquivo .eml e devolve o que o IMAP devolveria para ele, ou seja a
 * estrutura no formato de imap_fetchstructure() (type, subtype, encoding, parameters,
 * dparameters, disposition, id, parts) e o conteúdo cru de cada seção ("1", "1.2"...).
 * Assim o EmailMime é testado com e-mails reais sem servidor IMAP.
 * Os parâmetros saem com o nome cru (ex.: "filename*0*"), como o IMAP os entrega.
 */
final class EmlParaEstrutura {
    const TIPOS = ['text' => 0, 'multipart' => 1, 'message' => 2, 'application' => 3, 'audio' => 4, 'image' => 5, 'video' => 6];
    const CODIFICACOES = ['7bit' => 0, '8bit' => 1, 'binary' => 2, 'base64' => 3, 'quoted-printable' => 4];

    /** @return array{0: object, 1: array<string, string>} */
    public static function ler(string $arquivo): array {
        $secoes = [];
        $estrutura = self::parte((string) file_get_contents($arquivo), '', $secoes, true);
        return [$estrutura, $secoes];
    }

    private static function parte(string $cru, string $secao, array &$secoes, bool $raiz = false): object {
        $cru = str_replace("\r\n", "\n", $cru);
        $pos = strpos($cru, "\n\n");
        $cabecalho = $pos === false ? $cru : substr($cru, 0, $pos);
        $corpo = $pos === false ? '' : substr($cru, $pos + 2);
        $cab = self::cabecalhos($cabecalho);

        [$tipoCompleto, $params] = self::valorComParametros($cab['content-type'] ?? 'text/plain; charset=us-ascii');
        [$tipo, $subtipo] = array_pad(explode('/', strtolower($tipoCompleto), 2), 2, '');

        $p = new stdClass();
        $p->type = self::TIPOS[$tipo] ?? 7;
        $p->subtype = strtoupper($subtipo);
        $p->encoding = self::CODIFICACOES[strtolower(trim($cab['content-transfer-encoding'] ?? '7bit'))] ?? 0;
        $p->parameters = self::objetos($params);
        $p->ifdisposition = 0;
        if (isset($cab['content-disposition'])) {
            [$disp, $dparams] = self::valorComParametros($cab['content-disposition']);
            $p->ifdisposition = 1;
            $p->disposition = strtoupper($disp);
            $p->dparameters = self::objetos($dparams);
        }
        if (isset($cab['content-id'])) {
            $p->ifid = 1;
            $p->id = trim($cab['content-id']);
        }

        if ($p->type === 1 && isset($params['boundary'])) {
            $p->parts = [];
            $pedacos = explode('--' . $params['boundary'], $corpo);
            array_shift($pedacos); // preâmbulo
            foreach ($pedacos as $i => $pedaco) {
                if (str_starts_with($pedaco, '--')) {
                    break; // fim do multipart
                }
                $sub = ($secao === '' ? '' : $secao . '.') . ($i + 1);
                $p->parts[] = self::parte(ltrim($pedaco, "\n"), $sub, $secoes);
            }
        } else {
            $secoes[$raiz ? '1' : $secao] = rtrim($corpo, "\n");
        }
        return $p;
    }

    private static function cabecalhos(string $bloco): array {
        $bloco = preg_replace("/\n[ \t]+/", ' ', $bloco);
        $saida = [];
        foreach (explode("\n", $bloco) as $linha) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $linha, $m)) {
                $saida[strtolower($m[1])] = $m[2];
            }
        }
        return $saida;
    }

    /** "text/plain; charset="iso-8859-1"; name=x" → ['text/plain', ['charset' => 'iso-8859-1', 'name' => 'x']] */
    private static function valorComParametros(string $valor): array {
        $partes = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $valor);
        $principal = trim((string) array_shift($partes));
        $params = [];
        foreach ($partes as $par) {
            if (preg_match('/^\s*([^=\s]+)\s*=\s*(.*)$/s', $par, $m)) {
                $params[strtolower($m[1])] = trim(trim($m[2]), '"');
            }
        }
        return [$principal, $params];
    }

    private static function objetos(array $params): array {
        $lista = [];
        foreach ($params as $atributo => $valor) {
            $o = new stdClass();
            $o->attribute = strtoupper($atributo);
            $o->value = $valor;
            $lista[] = $o;
        }
        return $lista;
    }
}
