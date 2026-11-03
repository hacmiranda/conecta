<?php
/**
 * Regras de cadastro de usuário compartilhadas entre a tela (usuarios.php) e o importador do METRICA360.
 */
class UsuarioService {

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
