<?php
// Script para testar a integração construída
$url = 'http://localhost/crpdesk/api/controllers/integracoes/abrir_chamado.php';
$api_key = 'crpdesk_integracao_secreta_2026';

// Simular payload vindo da Ouvidoria
$payload = json_encode([
    'origem' => 'ouvidoria_publica',
    'titulo' => 'Problema ao acessar o portal da transparência',
    'descricao' => '<p>O portal está apresentando erro 500 ao tentar visualizar os contratos de 2025.</p>',
    'email_solicitante' => 'cidadao.teste@exemplo.com',
    'nome_solicitante' => 'Cidadão Fiscalizador',
    'prioridade' => 'alta',
    'categoria_id' => 1 // Supondo que 1 seja uma categoria válida
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-KEY: ' . $api_key
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $http_code . "\n";
echo "Response: " . $response . "\n";
