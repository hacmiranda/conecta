<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../middleware/auth.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// POST - Inicializar histórico (mock)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['success' => true, 'message' => 'Histórico inicializado']);
}
?>