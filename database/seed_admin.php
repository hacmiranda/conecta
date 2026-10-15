<?php
/**
 * Cria (ou promove) o administrador inicial do Conecta CRPRS.
 * Uso (somente linha de comando):
 *   php database/seed_admin.php "email@crprs.org.br" "Nome Completo"
 * A senha é pedida no terminal e nunca é gravada no repositório.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../api/config/database.php';

$email = trim($argv[1] ?? '');
$nome  = trim($argv[2] ?? '');

if ($email === '' || $nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php database/seed_admin.php \"email@crprs.org.br\" \"Nome Completo\"\n");
    exit(1);
}

// Lê a senha sem ecoar no terminal (Windows usa PowerShell; demais sistemas usam stty)
function lerSenhaOculta(string $prompt): string {
    echo $prompt;
    if (stripos(PHP_OS, 'WIN') === 0) {
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
             . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $senha = rtrim((string) shell_exec($cmd), "\r\n");
    } else {
        shell_exec('stty -echo');
        $senha = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        echo "\n";
    }
    return $senha;
}

$senha = lerSenhaOculta('Senha do administrador (mín. 8 caracteres): ');
if (strlen($senha) < 8) {
    fwrite(STDERR, "Senha muito curta.\n");
    exit(1);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($senha, $algo);

    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $existente = $stmt->fetchColumn();

    if ($existente) {
        // Já existe: promove a admin, sem setor, e redefine a senha
        $upd = $db->prepare("UPDATE usuarios SET nome = ?, senha = ?, tipo = 'admin', setor_id = NULL, ativo = 1 WHERE id = ?");
        $upd->execute([$nome, $hash, $existente]);
        echo "Usuário existente (id {$existente}) promovido a administrador.\n";
    } else {
        $ins = $db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, setor_id, ativo) VALUES (?, ?, ?, 'admin', NULL, 1)");
        $ins->execute([$nome, $email, $hash]);
        echo "Administrador criado (id " . $db->lastInsertId() . ").\n";
    }
} catch (Exception $e) {
    error_log("[SEED ADMIN] Erro: " . $e->getMessage());
    fwrite(STDERR, "Erro ao criar o administrador. Verifique api/config/.env e o log.\n");
    exit(1);
}
