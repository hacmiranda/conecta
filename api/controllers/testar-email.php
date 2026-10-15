<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../config/email-config.php';

AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user);

// Testa sempre o remetente global configurado no .env (a tela é somente leitura).
// Não aceita servidor/credenciais vindos do navegador.
AuthMiddleware::verificarCSRF();

$resultados = [
    'imap' => ['sucesso' => false, 'mensagem' => ''],
    'smtp' => ['sucesso' => false, 'mensagem' => '']
];

// Testar IMAP
try {
    if (!function_exists('imap_open')) {
        $resultados['imap']['mensagem'] = "Extensão PHP IMAP não está habilitada. Descomente 'extension=imap' no php.ini e reinicie o Apache.";
    } else {
        $imap_host = EmailConfig::get('IMAP_HOST');
        $imap_port = EmailConfig::get('IMAP_PORT');
        $imap_user = EmailConfig::get('IMAP_USERNAME');
        $imap_pass = EmailConfig::get('IMAP_PASSWORD');
        
        imap_errors();
        imap_alerts();
        
        $mailbox = "{{$imap_host}:{$imap_port}/imap/ssl}INBOX";
        $inbox = @imap_open($mailbox, $imap_user, $imap_pass);
        
        if ($inbox) {
            $total = imap_num_msg($inbox);
            imap_close($inbox);
            $resultados['imap']['sucesso'] = true;
            $resultados['imap']['mensagem'] = "Conexão IMAP bem-sucedida! {$total} emails na caixa.";
        } else {
            $erro = imap_last_error();
            $resultados['imap']['mensagem'] = $erro ? "Falha na conexão IMAP: {$erro}" : "Falha na conexão IMAP";
        }
        
        imap_errors();
        imap_alerts();
    }
} catch (Exception $e) {
    $resultados['imap']['mensagem'] = "Erro IMAP: " . $e->getMessage();
}

// Testar SMTP
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $smtp_host = EmailConfig::get('SMTP_HOST');
    $smtp_port = EmailConfig::get('SMTP_PORT');
    $smtp_user = EmailConfig::get('SMTP_USERNAME');
    $smtp_pass = EmailConfig::get('SMTP_PASSWORD');
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $smtp_host;
    $mail->SMTPAuth = true;
    $mail->Username = $smtp_user;
    $mail->Password = $smtp_pass;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $smtp_port;
    $mail->Timeout = 10;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
    
    if ($mail->smtpConnect()) {
        $mail->smtpClose();
        $resultados['smtp']['sucesso'] = true;
        $resultados['smtp']['mensagem'] = "Conexão SMTP bem-sucedida!";
    } else {
        $resultados['smtp']['mensagem'] = "Falha na conexão SMTP.";
    }
} catch (Exception $e) {
    $resultados['smtp']['mensagem'] = "Erro SMTP: " . $e->getMessage();
}

echo json_encode($resultados);
