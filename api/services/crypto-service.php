<?php
/**
 * Cifra/decifra dados sensíveis em repouso (ex: senha IMAP/SMTP das caixas de e-mail por setor).
 * Chave vem exclusivamente do .env (SETOR_EMAIL_ENCRYPTION_KEY_LOCAL/_PROD) — nunca hardcoded.
 *
 * Formato atual "v2:" + base64(IV 12 bytes + tag 16 bytes + cifrado): AES-256-GCM, que além de cifrar
 * detecta adulteração (o CBC antigo decifrava lixo sem perceber). Valores antigos, sem prefixo
 * (base64(IV 16 + cifrado), AES-256-CBC), continuam legíveis; database/recifrar-senhas-caixas.php
 * os regrava no formato novo.
 */
class CryptoService {
    const PREFIXO = 'v2:';

    private static function segredo(): string {
        $chave = Environment::get('setor_email_encryption_key');
        if (!$chave || strlen($chave) < 32) {
            error_log('[CRYPTO] SETOR_EMAIL_ENCRYPTION_KEY não configurada ou muito curta. Configure no arquivo .env.');
            throw new Exception('Chave de criptografia não configurada neste ambiente.');
        }
        return (string) $chave;
    }

    /** Chave do formato antigo (CBC): sha256 do texto do .env. */
    private static function chaveLegada(): string {
        return hash('sha256', self::segredo(), true);
    }

    /** Chave do formato v2 (GCM): derivada com HKDF, própria para este uso. */
    private static function chaveV2(): string {
        return hash_hkdf('sha256', self::segredo(), 32, 'conecta-setor-email-v2');
    }

    public static function encrypt(string $texto): string {
        $iv = random_bytes(12);
        $tag = '';
        $cifrado = openssl_encrypt($texto, 'aes-256-gcm', self::chaveV2(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cifrado === false) {
            throw new Exception('Falha ao cifrar dado.');
        }
        return self::PREFIXO . base64_encode($iv . $tag . $cifrado);
    }

    public static function decrypt(string $cifradoBase64): string {
        if (str_starts_with($cifradoBase64, self::PREFIXO)) {
            $dados = base64_decode(substr($cifradoBase64, strlen(self::PREFIXO)), true);
            if ($dados === false || strlen($dados) < 29) {
                throw new Exception('Dado cifrado inválido.');
            }
            $texto = openssl_decrypt(substr($dados, 28), 'aes-256-gcm', self::chaveV2(), OPENSSL_RAW_DATA,
                substr($dados, 0, 12), substr($dados, 12, 16));
            if ($texto === false) {
                throw new Exception('Falha ao decifrar dado (alterado ou chave errada).');
            }
            return $texto;
        }

        // Formato antigo (AES-256-CBC, sem verificação de integridade)
        $dados = base64_decode($cifradoBase64, true);
        if ($dados === false || strlen($dados) < 17) {
            throw new Exception('Dado cifrado inválido.');
        }
        $texto = openssl_decrypt(substr($dados, 16), 'AES-256-CBC', self::chaveLegada(), OPENSSL_RAW_DATA, substr($dados, 0, 16));
        if ($texto === false) {
            throw new Exception('Falha ao decifrar dado.');
        }
        return $texto;
    }

    /** Ainda no formato antigo (precisa ser regravado)? */
    public static function formatoAntigo(?string $cifrado): bool {
        return $cifrado !== null && $cifrado !== '' && !str_starts_with($cifrado, self::PREFIXO);
    }
}
