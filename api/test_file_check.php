<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain');
echo "=== CRPDESK FILE CHECK DEBUG ===\n\n";

$caminho_auth = __DIR__ . '/controllers/auth.php';

echo "1. Procurando por [ {$caminho_auth} ]...\n";
if (file_exists($caminho_auth)) {
    echo "-> O arquivo EXISTE fisicamente.\n";
    echo "-> Permissões CHMOD: " . substr(sprintf('%o', fileperms($caminho_auth)), -4) . "\n";
} else {
    echo "-> ERRO: O arquivo auth.php NÃO EXISTE neste local.\n";
}

echo "\n2. Listando conteúdo dentro de crpdesk/api/controllers/ :\n";
$dir_controllers = __DIR__ . '/controllers/';
if (is_dir($dir_controllers)) {
    $arquivos = scandir($dir_controllers);
    foreach ($arquivos as $arq) {
        if ($arq !== '.' && $arq !== '..') {
            echo "- {$arq}\n";
        }
    }
} else {
    echo "-> ERRO FATAL: A pasta 'controllers' não existe dentro da pasta 'api'!\n";
}

echo "\n3. Listando conteúdo dentro de crpdesk/api/ :\n";
$arquivos_api = scandir(__DIR__);
foreach ($arquivos_api as $arq) {
    if ($arq !== '.' && $arq !== '..') {
        echo "- {$arq}\n";
    }
}

echo "\n=== FIM ===\n";
