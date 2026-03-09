<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "=== TESTANDO POST COM REAL USER AGENT ===\n";

$url = 'https://crp07.org.br/crpdesk/api/controllers/auth.php';
$data = ['email' => 'suporte@crprs.org.br', 'senha' => '123456', 'cf-turnstile-response' => 'test-safepass'];
$payload = json_encode($data);

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n" .
                     "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36\r\n" .
                     "Accept: application/json\r\n",
        'method'  => 'POST',
        'content' => $payload,
        'ignore_errors' => true
    ]
];

$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

echo "Response Headers:\n";
print_r($http_response_header);
echo "\nResponse Body:\n";
echo $result;
