<?php
require 'api/config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query('DESCRIBE usuarios');
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($res as $r) {
    echo $r['Field'] . "\n";
}
