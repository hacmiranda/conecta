<?php
/**
 * Script de migração único — gera `username` (nome.sobrenome) para usuários
 * existentes que ficaram com a coluna vazia após aplicar 9_setores_papeis.sql.
 *
 * Uso (linha de comando apenas, nunca via navegador):
 *   php database/9b_gerar_usernames.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via linha de comando.');
}

require_once __DIR__ . '/../api/config/database.php';

function slugify(string $texto): string {
    $texto = trim($texto);
    $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($transliterado !== false) {
        $texto = $transliterado;
    }
    $texto = strtolower($texto);
    $texto = preg_replace('/[^a-z0-9\s]/', '', $texto);
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    return $texto;
}

function gerarBaseUsername(string $nomeCompleto): string {
    $partes = explode(' ', slugify($nomeCompleto));
    $partes = array_values(array_filter($partes));
    if (count($partes) === 0) {
        return 'usuario';
    }
    if (count($partes) === 1) {
        return $partes[0];
    }
    return $partes[0] . '.' . end($partes);
}

$database = new Database();
$db = $database->getConnection();

$stmt = $db->query("SELECT id, nome FROM usuarios WHERE username IS NULL ORDER BY id");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($usuarios)) {
    echo "Nenhum usuário sem username. Nada a fazer.\n";
    exit(0);
}

$existentes = $db->query("SELECT username FROM usuarios WHERE username IS NOT NULL")
    ->fetchAll(PDO::FETCH_COLUMN);
$existentesSet = array_flip($existentes);

$update = $db->prepare("UPDATE usuarios SET username = :username WHERE id = :id");
$total = 0;

foreach ($usuarios as $usuario) {
    $base = gerarBaseUsername($usuario['nome']);
    $candidato = $base;
    $sufixo = 2;
    while (isset($existentesSet[$candidato])) {
        $candidato = $base . $sufixo;
        $sufixo++;
    }
    $existentesSet[$candidato] = true;

    $update->execute([':username' => $candidato, ':id' => $usuario['id']]);
    echo "#{$usuario['id']} {$usuario['nome']} -> {$candidato}\n";
    $total++;
}

echo "\n{$total} usuário(s) atualizado(s).\n";
