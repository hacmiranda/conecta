<?php
/**
 * Regra única de anexos (antes copiada em 4 lugares, com listas diferentes):
 * nova demanda (tickets.php), comentário (comentarios.php), e-mail recebido (email-service.php)
 * e integração (integracoes/abrir_chamado.php).
 *
 * - Tipos: extensão da lista E conteúdo real (finfo) compatível com ela. Algumas extensões têm
 *   mais de um MIME válido conforme a versão do libmagic (CSV costuma sair como text/plain;
 *   DOC/XLS antigos como application/CDFV2; DOCX/XLSX às vezes como application/zip).
 * - Limites: até 5 arquivos por envio e 20 MB cada, conferidos no servidor (antes só no navegador).
 * - Nome no disco aleatório, sem o nome original (sem path traversal); permissão 0640.
 */
class UploadService {
    const MAX_ARQUIVOS = 5;
    const MAX_BYTES_ARQUIVO = 20 * 1024 * 1024;

    /** Arquivos gravados desde iniciarLote(): apagados por desfazerLote() quando a transação do banco é desfeita. */
    private static array $lote = [];

    public static function iniciarLote(): void {
        self::$lote = [];
    }

    /** Remove do disco o que foi gravado no lote (a linha em anexos sumiu com o rollback). */
    public static function desfazerLote(): void {
        foreach (self::$lote as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        self::$lote = [];
    }

    const TIPOS = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    /** Pasta dos anexos (backend/anexos/), criada se não existir. */
    public static function pasta(): string {
        $dir = dirname(__DIR__, 2) . '/backend/anexos/';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * Confere nome + MIME real + tamanho. Função pura (o MIME vem de fora: finfo do arquivo ou do conteúdo).
     * @return array{ok:bool, extensao:string, motivo:?string}
     */
    public static function validar(string $nomeOriginal, string $mimeReal, int $tamanho): array {
        $extensao = strtolower(pathinfo(basename($nomeOriginal), PATHINFO_EXTENSION));
        if (!isset(self::TIPOS[$extensao])) {
            return ['ok' => false, 'extensao' => $extensao, 'motivo' => 'Tipo não permitido'];
        }
        if (!in_array($mimeReal, self::TIPOS[$extensao], true)) {
            return ['ok' => false, 'extensao' => $extensao, 'motivo' => 'Conteúdo não corresponde ao tipo do arquivo'];
        }
        if ($tamanho <= 0 || $tamanho > self::MAX_BYTES_ARQUIVO) {
            return ['ok' => false, 'extensao' => $extensao, 'motivo' => 'Tamanho excede 20MB'];
        }
        return ['ok' => true, 'extensao' => $extensao, 'motivo' => null];
    }

    /** MIME real de um conteúdo em memória (e-mail recebido, integração). */
    public static function mimeDoConteudo(string $conteudo): string {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_buffer($finfo, $conteudo);
        finfo_close($finfo);
        return $mime;
    }

    /** Nome aleatório no disco (sem nada do nome original). */
    public static function nomeNoDisco(string $extensao): string {
        return time() . '_' . bin2hex(random_bytes(8)) . '.' . $extensao;
    }

    /**
     * Salva os arquivos de um campo de upload ($_FILES['anexos']) na demanda (e no comentário, se houver).
     * @return array{salvos:int, recusados:array<int, array{nome:string, motivo:string}>}
     */
    public static function salvarEnviados(PDO $db, array $files, int $ticketId, ?int $comentarioId = null): array {
        $resultado = ['salvos' => 0, 'recusados' => []];
        $lista = [];
        $total = is_array($files['name'] ?? null) ? count($files['name']) : (isset($files['name']) ? 1 : 0);
        for ($i = 0; $i < $total; $i++) {
            $pegar = fn($campo) => is_array($files[$campo]) ? ($files[$campo][$i] ?? null) : $files[$campo];
            if ((int) $pegar('error') === UPLOAD_ERR_NO_FILE || (string) $pegar('name') === '') {
                continue;
            }
            $lista[] = ['nome' => basename((string) $pegar('name')), 'tmp' => (string) $pegar('tmp_name'),
                        'tamanho' => (int) $pegar('size'), 'erro' => (int) $pegar('error')];
        }

        $pasta = self::pasta();
        foreach ($lista as $indice => $arq) {
            if ($indice >= self::MAX_ARQUIVOS) {
                $resultado['recusados'][] = ['nome' => $arq['nome'], 'motivo' => 'Máximo 5 arquivos'];
                continue;
            }
            if ($arq['erro'] !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp'])) {
                $motivo = in_array($arq['erro'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Tamanho excede 20MB' : 'Falha no envio';
                $resultado['recusados'][] = ['nome' => $arq['nome'], 'motivo' => $motivo];
                continue;
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string) finfo_file($finfo, $arq['tmp']);
            finfo_close($finfo);

            $validacao = self::validar($arq['nome'], $mime, $arq['tamanho']);
            if (!$validacao['ok']) {
                $resultado['recusados'][] = ['nome' => $arq['nome'], 'motivo' => $validacao['motivo']];
                continue;
            }

            $nomeDisco = self::nomeNoDisco($validacao['extensao']);
            if (!move_uploaded_file($arq['tmp'], $pasta . $nomeDisco)) {
                $resultado['recusados'][] = ['nome' => $arq['nome'], 'motivo' => 'Falha ao gravar'];
                continue;
            }
            chmod($pasta . $nomeDisco, 0640);
            self::$lote[] = $pasta . $nomeDisco;
            self::registrar($db, $ticketId, $comentarioId, $arq['nome'], $nomeDisco, $arq['tamanho'], $mime);
            $resultado['salvos']++;
        }
        return $resultado;
    }

    /**
     * Salva um conteúdo em memória já validado (e-mail recebido, integração). Devolve o nome no disco.
     */
    public static function salvarConteudo(PDO $db, string $conteudo, string $nomeOriginal, string $extensao, string $mime,
                                          int $ticketId, ?int $comentarioId = null): ?string {
        $nomeDisco = self::nomeNoDisco($extensao);
        $caminho = self::pasta() . $nomeDisco;
        if (file_put_contents($caminho, $conteudo) === false) {
            return null;
        }
        chmod($caminho, 0640);
        self::$lote[] = $caminho;
        self::registrar($db, $ticketId, $comentarioId, basename($nomeOriginal), $nomeDisco, strlen($conteudo), $mime);
        return $nomeDisco;
    }

    private static function registrar(PDO $db, int $ticketId, ?int $comentarioId, string $nomeOriginal, string $nomeDisco, int $tamanho, string $mime): void {
        $db->prepare("INSERT INTO anexos (ticket_id, comentario_id, nome_original, nome_arquivo, tamanho, tipo) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$ticketId, $comentarioId, mb_substr($nomeOriginal, 0, 255), $nomeDisco, $tamanho, $mime]);
    }
}
