<?php
/**
 * Regras de senha do Conecta CRPRS (as mesmas exibidas na tela "Alterar senha").
 * Validação sempre feita também no servidor; o navegador apenas antecipa o aviso.
 */
class SenhaService {

    const TAMANHO_MINIMO = 10;

    /**
     * Retorna a mensagem de erro (pt-BR) ou null se a senha atende às regras.
     */
    public static function validar(string $senha): ?string {
        if (mb_strlen($senha) < self::TAMANHO_MINIMO) {
            return 'A senha deve ter no mínimo ' . self::TAMANHO_MINIMO . ' caracteres.';
        }
        if (!preg_match('/[A-Z]/', $senha)) {
            return 'A senha deve conter ao menos 1 letra maiúscula.';
        }
        if (!preg_match('/[a-z]/', $senha)) {
            return 'A senha deve conter ao menos 1 letra minúscula.';
        }
        if (!preg_match('/[0-9]/', $senha)) {
            return 'A senha deve conter ao menos 1 número.';
        }
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $senha)) {
            return 'A senha deve conter ao menos 1 caractere especial (! @ # $ % ^ & * ( ) , . ? " : { } | < >).';
        }
        return null;
    }

    /**
     * Gera o hash para gravar no banco (Argon2id quando disponível).
     */
    public static function hash(string $senha): string {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($senha, $algo);
    }
}
