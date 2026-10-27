<?php
/**
 * Filtros de e-mail por setor: e-mail que coincide com um filtro ativo não abre demanda.
 *
 * - Comparação "contém", sem diferenciar maiúsculas/minúsculas nem acentos ("Promoção" barra "PROMOCAO").
 * - Campos: remetente (endereço e nome), destinatário (Para e Cc), assunto e corpo.
 * - Os filtros do setor da caixa valem junto com os globais (setor_id NULL, só o admin cadastra); os do
 *   setor são avaliados primeiro.
 * - Só vale para o que abriria demanda nova (ver EmailService::processarEmail): resposta de quem
 *   participa de uma demanda continua virando comentário.
 */
require_once __DIR__ . '/html-sanitizer.php';

class FiltroEmailService {
    const CAMPOS = [
        'remetente'    => 'Remetente',
        'destinatario' => 'Destinatário',
        'assunto'      => 'Assunto',
        'corpo'        => 'Corpo do e-mail',
    ];
    const TAMANHO_MINIMO = 3;
    const TAMANHO_MAXIMO = 255;

    /** Cache por execução: setor (ou 'global') => filtros ativos */
    private static array $cache = [];

    /** Minúsculas, sem acento e com espaços seguidos reduzidos a um. Função pura. */
    public static function normalizar(string $texto): string {
        static $de = null, $para = null;
        if ($de === null) {
            $mapa = [
                'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
                'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
                'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
            ];
            $de = array_keys($mapa);
            $para = array_values($mapa);
        }
        $texto = mb_strtolower($texto, 'UTF-8');
        $texto = str_replace($de, $para, $texto);
        return trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    }

    /**
     * Valida e limpa um termo. Devolve ['termo' => texto limpo, 'normalizado' => ...] ou ['erro' => mensagem].
     * Mínimo de 3 caracteres: um termo como "a" barraria quase todos os e-mails.
     */
    public static function validarTermo(string $campo, string $termo): array {
        if (!isset(self::CAMPOS[$campo])) {
            return ['erro' => 'Campo inválido'];
        }
        $limpo = HtmlSanitizer::texto($termo, self::TAMANHO_MAXIMO);
        $normalizado = self::normalizar($limpo);
        if (mb_strlen($normalizado) < self::TAMANHO_MINIMO) {
            return ['erro' => 'O texto do filtro precisa ter pelo menos ' . self::TAMANHO_MINIMO . ' caracteres'];
        }
        return ['termo' => $limpo, 'normalizado' => $normalizado];
    }

    /**
     * Primeiro filtro ativo que coincide com o e-mail, ou null. Função pura (os filtros vêm de fora).
     * $filtros: linhas de filtros_email (campo, termo_normalizado, ativo...), na ordem de prioridade.
     * $email: ['remetente' => 'Nome <a@b.com>', 'destinatarios' => ['x@y.com', ...], 'assunto' => '', 'corpo' => ''].
     */
    public static function coincide(array $filtros, array $email): ?array {
        $campos = [
            'remetente'    => self::normalizar((string) ($email['remetente'] ?? '')),
            'destinatario' => self::normalizar(implode(' ', (array) ($email['destinatarios'] ?? []))),
            'assunto'      => self::normalizar((string) ($email['assunto'] ?? '')),
            'corpo'        => null, // normalizado só se algum filtro de corpo existir (pode ser grande)
        ];
        foreach ($filtros as $filtro) {
            if (isset($filtro['ativo']) && !(int) $filtro['ativo']) {
                continue;
            }
            $campo = (string) $filtro['campo'];
            if (!array_key_exists($campo, $campos)) {
                continue;
            }
            if ($campo === 'corpo' && $campos['corpo'] === null) {
                $campos['corpo'] = self::normalizar((string) ($email['corpo'] ?? ''));
            }
            $termo = (string) $filtro['termo_normalizado'];
            if ($termo !== '' && str_contains((string) $campos[$campo], $termo)) {
                return $filtro;
            }
        }
        return null;
    }

    /** Filtros ativos que valem para a caixa: os do setor primeiro, depois os globais. */
    public static function ativosDaCaixa(PDO $db, ?int $setorId): array {
        $chave = $setorId === null ? 'global' : (string) $setorId;
        if (isset(self::$cache[$chave])) {
            return self::$cache[$chave];
        }
        $sql = "SELECT id, setor_id, campo, termo, termo_normalizado, ativo FROM filtros_email WHERE ativo = 1 AND (setor_id IS NULL"
             . ($setorId !== null ? " OR setor_id = :setor" : "") . ")
                ORDER BY (setor_id IS NULL), id";
        try {
            $stmt = $db->prepare($sql);
            $setorId !== null ? $stmt->execute([':setor' => $setorId]) : $stmt->execute();
            return self::$cache[$chave] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Tabela ausente (migração 37 pendente): sem filtros, o e-mail segue o caminho normal
            error_log('[FILTRO-EMAIL] ' . $e->getMessage());
            return [];
        }
    }

    /** Esquece o cache (testes e telas que acabaram de alterar filtros). */
    public static function limparCache(): void {
        self::$cache = [];
    }

    /** Soma uma ocorrência ao filtro que barrou o e-mail. */
    public static function registrarOcorrencia(PDO $db, int $filtroId): void {
        $db->prepare("UPDATE filtros_email SET ocorrencias = ocorrencias + 1, ultima_ocorrencia = NOW() WHERE id = ?")
           ->execute([$filtroId]);
    }

    /** Texto para o histórico: "Remetente contém "@exemplo.com"". */
    public static function descrever(array $filtro): string {
        return (self::CAMPOS[$filtro['campo']] ?? $filtro['campo']) . ' contém "' . $filtro['termo'] . '"';
    }
}
