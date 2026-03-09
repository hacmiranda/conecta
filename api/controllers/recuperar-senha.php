<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

// CORS restrito e sem display_errors em produção
AuthMiddleware::configurarCORS(['POST']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? '';
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email é obrigatório']);
        exit;
    }
    
    try {
        // Verificar se o email existe
        $query = "SELECT id, nome, email FROM usuarios WHERE email = :email AND ativo = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            http_response_code(404);
            echo json_encode(['error' => 'Email não encontrado no sistema']);
            exit;
        }
        
        // Deletar tokens antigos do usuário
        $delete_query = "DELETE FROM tokens_recuperacao WHERE usuario_id = :usuario_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':usuario_id', $usuario['id']);
        $delete_stmt->execute();
        
        // Gerar token de recuperação
        $token = bin2hex(random_bytes(32));
        $expiracao = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Salvar token na tabela tokens_recuperacao
        $insert_query = "INSERT INTO tokens_recuperacao (usuario_id, token, expira_em, usado) VALUES (:usuario_id, :token, :expira_em, 0)";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':usuario_id', $usuario['id']);
        $insert_stmt->bindParam(':token', $token);
        $insert_stmt->bindParam(':expira_em', $expiracao);
        $insert_stmt->execute();
        
        // Enviar email com link de recuperação
        require_once __DIR__ . '/../services/email-sender.php';
        $emailSender = new EmailSender($db);
        
        $link = "https://crp07.org.br/crpdesk/frontend/redefinir-senha.html?token=$token";
        
        $assunto = "Recuperação de Senha - CRPDESK";
        $mensagem = "
            <h2>Recuperação de Senha</h2>
            <p>Olá, {$usuario['nome']}!</p>
            <p>Você solicitou a recuperação de senha do sistema CRPDESK.</p>
            <p>Clique no link abaixo para redefinir sua senha:</p>
            <p><a href='$link' style='background-color: #f79226; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Redefinir Senha</a></p>
            <p>Ou copie e cole este link no navegador:</p>
            <p>$link</p>
            <p><strong>Este link expira em 1 hora.</strong></p>
            <p>Se você não solicitou esta recuperação, ignore este email.</p>
        ";
        
        $emailEnviado = $emailSender->enviarEmail($email, $assunto, $mensagem);
        
        if ($emailEnviado) {
            echo json_encode(['success' => true, 'message' => 'Email de recuperação enviado com sucesso!']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao enviar email de recuperação']);
        }
        
    } catch (Exception $e) {
        error_log('Erro recuperar senha: ' . $e->getMessage());
        http_response_code(500);
        
        // Em caso de erro de email/SMTP, retornar mensagem clara pro usuário
        $msg = $e->getMessage();
        if (strpos(strtolower($msg), 'smtp') !== false || strpos(strtolower($msg), 'authenticate') !== false) {
            echo json_encode(['error' => 'Erro ao enviar o e-mail: falha de autenticação no servidor de mensagens. Contate o suporte técnico.']);
        } else {
            // Esconde detalhes técnicos em produção
            $displayMsg = strpos($msg, 'Invalid address') !== false ? 'Erro de configuração no servidor de e-mail.' : 'Erro interno ao processar a recuperação.';
            echo json_encode(['error' => $displayMsg]);
        }
    }
}
?>
