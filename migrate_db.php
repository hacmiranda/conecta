<?php
require 'api/config/database.php';
try {
    $db = (new Database())->getConnection();
    $db->exec("ALTER TABLE tickets ADD COLUMN origem_api VARCHAR(50) DEFAULT 'interna' AFTER usuario_id;");
    echo "Coluna origem_api adicionada com sucesso ou ja existe.\n";
} catch (PDOException $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
