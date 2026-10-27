<?php
/**
 * Regrava as senhas das caixas de e-mail dos setores (setor_emails.imap_pass_cifrada / smtp_pass_cifrada)
 * do formato antigo (AES-256-CBC) para o atual (AES-256-GCM, prefixo "v2:"). Idempotente: só mexe no
 * que ainda está no formato antigo e confere cada valor (decifra o novo e compara) antes de gravar.
 *
 * Uso (linha de comando, depois do deploy da versão com CryptoService v2):
 *   php database/recifrar-senhas-caixas.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/config/database.php';
require_once __DIR__ . '/../api/services/crypto-service.php';

$db = (new Database())->getConnection();
$caixas = $db->query("SELECT id, imap_pass_cifrada, smtp_pass_cifrada FROM setor_emails")->fetchAll(PDO::FETCH_ASSOC);

$regravadas = 0;
$erros = 0;
foreach ($caixas as $caixa) {
    $novos = [];
    foreach (['imap_pass_cifrada', 'smtp_pass_cifrada'] as $coluna) {
        if (!CryptoService::formatoAntigo($caixa[$coluna])) {
            continue;
        }
        try {
            $senha = CryptoService::decrypt($caixa[$coluna]);
            $novo = CryptoService::encrypt($senha);
            if (CryptoService::decrypt($novo) !== $senha) {
                throw new Exception('conferência do valor novo falhou');
            }
            $novos[$coluna] = $novo;
        } catch (Exception $e) {
            $erros++;
            echo "Caixa #{$caixa['id']} ({$coluna}): não regravada — " . $e->getMessage() . "\n";
        }
    }
    if ($novos) {
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($novos)));
        $db->prepare("UPDATE setor_emails SET $sets WHERE id = ?")->execute([...array_values($novos), (int) $caixa['id']]);
        $regravadas++;
        echo "Caixa #{$caixa['id']}: " . implode(', ', array_keys($novos)) . " regravada(s)\n";
    }
}
echo "Concluído: $regravadas caixa(s) regravada(s), $erros erro(s).\n";
exit($erros > 0 ? 1 : 0);
