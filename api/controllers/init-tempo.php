<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// POST - Inicializar tempo (mock)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['success' => true, 'message' => 'Tempo inicializado']);
}
?>