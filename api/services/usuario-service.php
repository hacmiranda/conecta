<?php
/**
 * Regras de cadastro de usuário compartilhadas entre a tela (usuarios.php) e o importador do METRICA360.
 */
class UsuarioService {

    /**
     * Nome de usuário: minúsculas, números, ponto, hífen e sublinhado, de 3 a 50 caracteres. Nunca tem "@":
     * o login decide pelo "@" se o texto é e-mail ou nome de usuário (16/11/2026).
     */
    const PADRAO_USERNAME = '/^[a-z0-9._-]{3,50}$/';

    public static function usernameValido(string $username): bool {
        return preg_match(self::PADRAO_USERNAME, $username) === 1;
    }

    /** Coluna procurada no login: "email" se o texto tem "@", senão "username" */
    public static function campoDoLogin(string $identificador): string {
        return strpos($identificador, '@') !== false ? 'email' : 'username';
    }

    /** Texto do campo do login como é comparado no banco (e-mail e nome de usuário são gravados em minúsculas) */
    public static function normalizarLogin(string $identificador): string {
        return strtolower(trim($identificador));
    }

    /**
     * Nome de usuário livre a partir do nome completo: "primeiro.ultimo" sem acentos; com repetição,
     * acrescenta 2, 3, ... ($reservados = nomes já escolhidos nesta mesma carga, ainda não gravados).
     */
    public static function gerarUsernameUnico(PDO $db, string $nomeCompleto, array $reservados = []): string {
        $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nomeCompleto);
        $base = strtolower(trim($transliterado !== false ? $transliterado : $nomeCompleto));
        $base = preg_replace('/[^a-z0-9\s]/', '', $base);
        $partes = array_values(array_filter(preg_split('/\s+/', trim($base))));

        if (count($partes) === 0) {
            $base = 'usuario';
        } elseif (count($partes) === 1) {
            $base = $partes[0];
        } else {
            $base = $partes[0] . '.' . end($partes);
        }

        $candidato = $base;
        $sufixo = 2;
        $check = $db->prepare("SELECT id FROM usuarios WHERE username = :username");
        while (true) {
            $check->execute([':username' => $candidato]);
            if (!$check->fetch() && !in_array($candidato, $reservados, true)) {
                break;
            }
            $candidato = $base . $sufixo;
            $sufixo++;
        }
        return $candidato;
    }
}
