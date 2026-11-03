<?php
/**
 * Regras do módulo Satisfação (tabela pesquisa_config, chave/valor): quarentena, validade do link,
 * reenvio automático, meta de nota, alerta de nota baixa e link do Google. Vieram de "Configurações → Geral"
 * do METRICA360. Quem altera: só o administrador.
 */
class PesquisaConfig {

    /** Valor usado quando a chave ainda não foi gravada */
    const PADROES = [
        'quarentena_ativa'            => '1',
        'quarentena_dias'             => '15',
        'reenvio_horas'               => '48',
        'expiracao_token_dias'        => '7',
        'meta_nota_media'             => '4.5',
        'notificar_avaliacao_critica' => '1',
        'email_avaliacao_critica'     => '',
        'google_review_url'           => '',
    ];

    /** @return array<string,string> todas as regras, com o padrão onde falta */
    public static function todas(PDO $db): array {
        $gravadas = $db->query("SELECT chave, valor FROM pesquisa_config")->fetchAll(PDO::FETCH_KEY_PAIR);
        $regras = [];
        foreach (self::PADROES as $chave => $padrao) {
            $regras[$chave] = isset($gravadas[$chave]) && $gravadas[$chave] !== '' ? (string) $gravadas[$chave] : $padrao;
        }
        // Campos que podem ser vazios de propósito
        foreach (['email_avaliacao_critica', 'google_review_url'] as $chave) {
            $regras[$chave] = (string) ($gravadas[$chave] ?? self::PADROES[$chave]);
        }
        return $regras;
    }

    public static function get(PDO $db, string $chave): string {
        return self::todas($db)[$chave] ?? '';
    }

    /**
     * Regras de convite já convertidas: quarentena ligada, dias de quarentena, horas até o reenvio e dias
     * de validade do link (todos com mínimo de 1).
     */
    public static function regrasConvite(PDO $db): array {
        $r = self::todas($db);
        return [
            'quarentena_ativa'     => $r['quarentena_ativa'] === '1',
            'quarentena_dias'      => max(1, (int) $r['quarentena_dias']),
            'reenvio_horas'        => max(1, (int) $r['reenvio_horas']),
            'expiracao_token_dias' => max(1, (int) $r['expiracao_token_dias']),
        ];
    }

    /**
     * Confere os valores enviados pela tela. Só as chaves conhecidas entram.
     *
     * @return array{0: array<string,string>, 1: string[]} [valores limpos, erros]
     */
    public static function validar(array $entrada): array {
        $limpos = [];
        $erros = [];
        $inteiro = function (string $chave, string $rotulo, int $min, int $max) use ($entrada, &$limpos, &$erros) {
            if (!array_key_exists($chave, $entrada)) return;
            $v = $entrada[$chave];
            if (!is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
                $erros[] = "$rotulo: informe um número inteiro de $min a $max.";
                return;
            }
            $limpos[$chave] = (string) (int) $v;
        };
        $ligado = function (string $chave) use ($entrada, &$limpos) {
            if (array_key_exists($chave, $entrada)) {
                $limpos[$chave] = filter_var($entrada[$chave], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
        };

        $ligado('quarentena_ativa');
        $inteiro('quarentena_dias', 'Dias de quarentena', 1, 365);
        $inteiro('reenvio_horas', 'Horas até o reenvio', 1, 720);
        $inteiro('expiracao_token_dias', 'Validade do link (dias)', 1, 90);
        $ligado('notificar_avaliacao_critica');

        if (array_key_exists('meta_nota_media', $entrada)) {
            $meta = str_replace(',', '.', trim((string) $entrada['meta_nota_media']));
            if (!is_numeric($meta) || (float) $meta < 1 || (float) $meta > 5) {
                $erros[] = 'Meta de nota média: informe um valor de 1 a 5.';
            } else {
                $limpos['meta_nota_media'] = rtrim(rtrim(number_format((float) $meta, 1, '.', ''), '0'), '.');
            }
        }
        if (array_key_exists('email_avaliacao_critica', $entrada)) {
            $email = strtolower(trim((string) $entrada['email_avaliacao_critica']));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erros[] = 'E-mail do alerta de nota baixa inválido.';
            } else {
                $limpos['email_avaliacao_critica'] = $email;
            }
        }
        if (array_key_exists('google_review_url', $entrada)) {
            $url = trim((string) $entrada['google_review_url']);
            if ($url !== '' && (!preg_match('#^https://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 500)) {
                $erros[] = 'Link do Google Avaliações: informe um endereço https:// válido.';
            } else {
                $limpos['google_review_url'] = $url;
            }
        }
        return [$limpos, $erros];
    }

    /** Grava os valores (já validados por validar()). */
    public static function salvar(PDO $db, array $limpos): void {
        $stmt = $db->prepare("INSERT INTO pesquisa_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        foreach ($limpos as $chave => $valor) {
            if (array_key_exists($chave, self::PADROES)) {
                $stmt->execute([$chave, $valor]);
            }
        }
    }
}
