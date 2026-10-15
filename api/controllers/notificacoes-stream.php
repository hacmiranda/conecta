<?php
/**
 * Conecta CRPRS - Transmissor Server-Sent Events (SSE)
 * Arquivo Long-Lived responsável por mandar Web-Push ao navegador via protocolo HTTP nativo.
 * Otimizado para PHP 8.2 e livre de dependências como Node.js ou daemons paralelos.
 */

// Define os cabeçalhos cruciais para streaming em tempo real
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, must-revalidate'); // Evita caching do Nginx/Apache
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Flag fundamental para o NGINX da Produção não enfileirar o output (buffering)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

// EventSource nativo do JS não permite enviar Headers de Autorização customizados de forma simples.
// Portanto, recebemos o JWT via URL query e injetamos na memória do script para o middleware validar.
if (isset($_GET['token'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_GET['token'];
}

try {
    $user = AuthMiddleware::verificarAuth();
} catch (Exception $e) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Sessão expirada ou não autorizada']) . "\n\n";
    ob_flush();
    flush();
    exit;
}

// Fechando eventual manipulação de sessão do PHP (se houver via cookie em paralelo)
// Isso impede o travamento recursivo (session locking) de outras guias do sistema
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Injeção de dependência e conexão
$database = new Database();
$conn = $database->getConnection();
$usuario_id = $user['id'];

// Variável para travar envio duplicado se o estado da notificação não mudar
$ultimaContagem = null;

/**
 * Função tipada do PHP 8.2 para retornar apenas a carga necessária do banco de dados
 */
function fetchNaoLidas(PDO $conn, int|string $usuario_id): int {
    $stmtCount = $conn->prepare("SELECT COUNT(*) as nao_lidas FROM notificacoes WHERE usuario_id = ? AND lida = 0");
    $stmtCount->execute([$usuario_id]);
    $result = $stmtCount->fetch(PDO::FETCH_ASSOC);
    return $result ? (int) $result['nao_lidas'] : 0;
}

/**
 * LOOP INFINITO SEGURO:
 * Mantém o túnel HTTP aberto e verifica o banco de dados a cada N segundos.
 */
while (true) {
    // Se a aba do navegador for fechada ou a conexão for abortada pela rede, quebramos o loop imediatamente.
    if (connection_aborted() === 1) {
        break;
    }
    
    // Qual é a contagem exata neste segundo?
    $contagemAtual = fetchNaoLidas($conn, $usuario_id);
    
    // Só consome banda (JSON Payload total) se a contagem tiver sofrido mutação ou na primeira vez
    if ($ultimaContagem === null || $contagemAtual !== $ultimaContagem) {
        
        $stmt = $conn->prepare("SELECT * FROM notificacoes WHERE usuario_id = ? ORDER BY criado_em DESC LIMIT 50");
        $stmt->execute([$usuario_id]);
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $payload = [
            'notificacoes' => $notificacoes,
            'nao_lidas'    => $contagemAtual
        ];

        // "event:" define a chave customizada que o listener do JS deve procurar
        echo "event: update\n";
        echo "data: " . json_encode($payload) . "\n\n";
        
        // Dispara forçadamente da memória pro navegador
        ob_flush();
        flush();
        
        $ultimaContagem = $contagemAtual;
    }
    
    // Heartbeat: Impede que firewalls ou o próprio servidor matem a conexão por ociosidade (Timeout silencioso)
    // Tudo que começa com ":" em SSE é entendido como comentário pelo navegador e ignorado nativamente.
    echo ": heartbeat (" . time() . ")\n\n";
    ob_flush();
    flush();
    
    // Descansa o script e o Banco de Dados (5 Segundos de sleep proximo polling simulado)
    sleep(5);
}
?>
