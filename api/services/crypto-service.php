<?php
/**
 * Cifra/decifra dados sensíveis em repouso (ex: senha IMAP das caixas de e-mail por setor).
 * AES-256-CBC com IV aleatório por valor cifrado. Chave vem exclusivamente do .env
 * (SETOR_EMAIL_ENCRYPTION_KEY_LOCAL/_PROD) — nunca hardcoded.
 */
class CryptoService {

    private static function chave(): string {
        $chave = Environment::get('setor_email_encryption_key');
        if (!$chave || strlen($chave) < 32) {
            error_log('[CRYPTO] SETOR_EMAIL_ENCRYPTION_KEY não configurada ou muito curta. Configure no arquivo .env.');
            throw new Exception('Chave de criptografia não configurada neste ambiente.');
        }
        // Deriva 32 bytes (AES-256) a partir da chave em texto do .env
        return hash('sha256', $chave, true);
    }

    public static function encrypt(string $texto): string {
        $iv = random_bytes(16);
        $cifrado = openssl_encrypt($texto, 'AES-256-CBC', self::chave(), OPENSSL_RAW_DATA, $iv);
        if ($cifrado === false) {
            throw new Exception('Falha ao cifrar dado.');
        }
        return base64_encode($iv . $cifrado);
    }

    public static function decrypt(string $cifradoBase64): string {
        $dados = base64_decode($cifradoBase64, true);
        if ($dados === false || strlen($dados) < 17) {
            throw new Exception('Dado cifrado inválido.');
        }
        $iv = substr($dados, 0, 16);
        $cifrado = substr($dados, 16);
        $texto = openssl_decrypt($cifrado, 'AES-256-CBC', self::chave(), OPENSSL_RAW_DATA, $iv);
        if ($texto === false) {
            throw new Exception('Falha ao decifrar dado.');
        }
        return $texto;
    }
}
