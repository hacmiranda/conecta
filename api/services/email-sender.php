<?php
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../config/email-config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailSender {
    private $db;
    private $mailer;
    
    public function __construct($db) {
        $this->db = $db;
        $this->configurarMailer();
    }
    
    private function configurarMailer() {
        $this->mailer = new PHPMailer(true);
        $this->mailer->isSMTP();
        $this->mailer->SMTPDebug = 2;
        $this->mailer->Debugoutput = function($str, $level) {
            error_log("[PHPMAILER] $str");
        };
        $this->mailer->Host = EmailConfig::SMTP_HOST;
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = EmailConfig::SMTP_USERNAME;
        $this->mailer->Password = EmailConfig::SMTP_PASSWORD;
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $this->mailer->Port = EmailConfig::SMTP_PORT;
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->Timeout = 10;
        $this->mailer->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        $this->mailer->setFrom(EmailConfig::FROM_EMAIL, EmailConfig::FROM_NAME);
    }
    
    public function enviarEmailNovoTicket($ticket_id, $anexos_rejeitados = []) {
        try {
            error_log("[EMAIL-SENDER] Iniciando envio para ticket #$ticket_id");
            
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email 
                     FROM tickets t 
                     LEFT JOIN usuarios u ON t.usuario_id = u.id 
                     WHERE t.id = :ticket_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket) {
                error_log("[EMAIL-SENDER] Ticket nao encontrado");
                return false;
            }
            
            $email_destino = $ticket['email_solicitante'] ?: $ticket['usuario_email'];
            
            if (!$email_destino) {
                error_log("[EMAIL-SENDER] Sem email de usuario");
                return false;
            }
            
            error_log("[EMAIL-SENDER] Enviando para: " . $email_destino);
            
            $template_path = dirname(__DIR__, 2) . '/backend/email-template.html';
            error_log("[EMAIL-SENDER] Template path: $template_path");
            $template = file_get_contents($template_path);
            $nome_exibir = $ticket['email_solicitante'] ?: $ticket['usuario_nome'];
            $template = str_replace('{{NOME_USUARIO}}', $nome_exibir, $template);
            $template = str_replace('{{NUMERO_TICKET}}', str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $template);
            $template = str_replace('{{TITULO_TICKET}}', $ticket['titulo'], $template);
            $template = str_replace('{{DATA_ABERTURA}}', date('d/m/Y H:i', strtotime($ticket['criado_em'])), $template);
            $template = str_replace('{{ASSINATURA_EMAIL}}', $this->getAssinatura(), $template);
            
            // Adicionar aviso de anexos rejeitados se houver
            if (!empty($anexos_rejeitados)) {
                $aviso = '<div style="background-color:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:20px 0"><h3 style="color:#856404;margin-top:0">⚠️ Anexos rejeitados</h3><ul>';
                foreach ($anexos_rejeitados as $anexo) {
                    $aviso .= "<li><b>{$anexo['nome']}:</b> {$anexo['motivo']}</li>";
                }
                $aviso .= '</ul><p><b>Limites:</b> 5 arquivos, 20MB total, tipos: PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, PNG, JPG</p></div>';
                $template = str_replace('</body>', $aviso . '</body>', $template);
            }
            
            $assunto = "🎫 [#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Ticket criado - " . $ticket['titulo'];
            
            $resultado = $this->enviarEmail($email_destino, $assunto, $template);
            error_log("[EMAIL-SENDER] Resultado: " . ($resultado ? "SUCESSO" : "FALHA"));
            return $resultado;
            
        } catch (Exception $e) {
            error_log('[EMAIL-SENDER] Erro: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarAgentesNovoTicket($ticket_id) {
        try {
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email 
                     FROM tickets t 
                     LEFT JOIN usuarios u ON t.usuario_id = u.id 
                     WHERE t.id = :ticket_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket) {
                return false;
            }
            
            $query_agentes = "SELECT email FROM usuarios WHERE tipo IN ('agente', 'admin') AND email IS NOT NULL AND ativo = 1";
            $stmt_agentes = $this->db->prepare($query_agentes);
            $stmt_agentes->execute();
            $agentes = $stmt_agentes->fetchAll();
            
            $assunto = "🎫 [NOVO TICKET] #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " - " . $ticket['titulo'];
            
            $mensagem = "🎫 NOVO TICKET CRIADO\n\n";
            $mensagem .= "Número: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
            $mensagem .= "Título: " . $ticket['titulo'] . "\n";
            $mensagem .= "Solicitante: " . ($ticket['email_solicitante'] ?: $ticket['usuario_email']) . "\n";
            $mensagem .= "Data: " . date('d/m/Y H:i', strtotime($ticket['criado_em'])) . "\n";
            $mensagem .= "Status: " . ucfirst($ticket['status']) . "\n";
            $mensagem .= "Prioridade: " . ucfirst($ticket['prioridade']) . "\n\n";
            $mensagem .= "Descrição:\n" . $ticket['descricao'] . "\n\n";
            $mensagem .= "Acesse o sistema para atribuir e responder o ticket.\n\n";
            $mensagem .= "Atenciosamente,\nSistema Helpdesk CRPRS";
            
            $enviados = 0;
            foreach ($agentes as $agente) {
                if ($this->enviarEmailTexto($agente['email'], $assunto, $mensagem)) {
                    $enviados++;
                }
            }
            
            return $enviados > 0;
            
        } catch (Exception $e) {
            error_log('Erro ao notificar agentes: ' . $e->getMessage());
            return false;
        }
    }
    
    private function enviarEmailComAnexos($para, $assunto, $html, $anexos = []) {
        try {
            $this->configurarMailer();
            
            $this->mailer->setFrom(EmailConfig::SMTP_USERNAME, 'Suporte CRPRS');
            $this->mailer->addAddress($para);
            $this->mailer->Subject = $assunto;
            $this->mailer->Body = $html;
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';
            
            // Adicionar anexos
            if (!empty($anexos)) {
                $anexos_dir = dirname(__DIR__, 2) . '/backend/anexos/';
                foreach ($anexos as $anexo) {
                    $caminho_arquivo = $anexos_dir . $anexo['nome_arquivo'];
                    if (file_exists($caminho_arquivo)) {
                        $nome_original = $anexo['nome_original'] ?? $anexo['nome_arquivo'];
                        $this->mailer->addAttachment($caminho_arquivo, $nome_original);
                    }
                }
            }
            
            $resultado = $this->mailer->send();
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            
            return $resultado;
            
        } catch (Exception $e) {
            error_log('Erro ao enviar email com anexos: ' . $e->getMessage());
            return false;
        }
    }
    
    public function enviarEmail($para, $assunto, $html) {
        try {
            set_time_limit(30);
            error_log("[EMAIL] Tentando enviar HTML para: $para");
            
            $mail = clone $this->mailer;
            $mail->clearAddresses();
            $mail->addAddress($para);
            $mail->isHTML(true);
            $mail->Subject = $assunto;
            $mail->Body = $html;
            $mail->AltBody = strip_tags($html);
            
            $resultado = $mail->send();
            error_log("[EMAIL] Resultado envio: " . ($resultado ? "OK" : "FALHA"));
            
            return $resultado;
        } catch (Exception $e) {
            error_log("[EMAIL] ERRO ao enviar para $para: " . $e->getMessage());
            return false;
        }
    }
    
    private function enviarEmailTexto($para, $assunto, $texto) {
        try {
            set_time_limit(30);
            $mail = clone $this->mailer;
            $mail->clearAddresses();
            $mail->addAddress($para);
            $mail->isHTML(false);
            $mail->Subject = $assunto;
            $mail->Body = $texto;
            
            $resultado = $mail->send();
            
            if (!$resultado) {
                error_log("[EMAIL] Falha ao enviar email para: $para");
            }
            
            return $resultado;
        } catch (Exception $e) {
            error_log("[EMAIL] Erro ao enviar email para $para: " . $e->getMessage());
            return false;
        }
    }
    
    public function enviarComentarioParaSolicitante($ticket_id, $comentario_id, $com_copia = null) {
        try {
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email,
                             c.comentario, uc.nome as comentario_autor, a.nome as agente_nome
                      FROM tickets t 
                      LEFT JOIN usuarios u ON t.usuario_id = u.id 
                      LEFT JOIN comentarios c ON c.id = ?
                      LEFT JOIN usuarios uc ON c.usuario_id = uc.id
                      LEFT JOIN usuarios a ON t.agente_id = a.id
                      WHERE t.id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$comentario_id, $ticket_id]);
            $dados = $stmt->fetch();
            
            if (!$dados || !$dados['usuario_email']) return false;
            
            $assunto = "🎫 [#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Resposta do Suporte - " . $dados['titulo'];
            
            // Template para o solicitante
            $template_solicitante = file_get_contents(dirname(__DIR__, 2) . '/backend/email-template-resposta.html');
            $assinatura = file_get_contents(dirname(__DIR__, 2) . '/backend/AssinaturaEmail.html');
            
            $html_solicitante = str_replace(
                ['{{NOME_USUARIO}}', '{{NUMERO_TICKET}}', '{{TITULO_TICKET}}', '{{NOME_AGENTE}}', '{{DATA_RESPOSTA}}', '{{COMENTARIO_AGENTE}}', '{{ASSINATURA_EMAIL}}'],
                [$dados['usuario_nome'], str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $dados['titulo'], $dados['comentario_autor'], date('d/m/Y H:i'), nl2br(htmlspecialchars($dados['comentario'])), $assinatura],
                $template_solicitante
            );
            
            // Buscar anexos do comentário
            $anexos = [];
            try {
                $query_anexos = "SELECT * FROM anexos WHERE comentario_id = ?";
                $stmt_anexos = $this->db->prepare($query_anexos);
                $stmt_anexos->execute([$comentario_id]);
                $anexos = $stmt_anexos->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log('Erro ao buscar anexos: ' . $e->getMessage());
            }
            
            // Enviar para o solicitante
            $this->enviarEmailComAnexos($dados['usuario_email'], $assunto, $html_solicitante, $anexos);
            
            // Enviar cópia se fornecido
            if ($com_copia) {
                $template_copia = file_get_contents(dirname(__DIR__, 2) . '/backend/email-template-emcopia.html');
                
                $html_copia = str_replace(
                    ['{{NUMERO_TICKET}}', '{{TITULO_TICKET}}', '{{NOME_AGENTE}}', '{{DATA_RESPOSTA}}', '{{COMENTARIO_AGENTE}}', '{{ASSINATURA_EMAIL}}'],
                    [str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $dados['titulo'], $dados['comentario_autor'], date('d/m/Y H:i'), nl2br(htmlspecialchars($dados['comentario'])), $assinatura],
                    $template_copia
                );
                
                // Adicionar info do solicitante no template de cópia
                $html_copia = str_replace('<strong>Solicitante:</strong> <br>', '<strong>Solicitante:</strong> ' . $dados['usuario_nome'] . ' (' . $dados['usuario_email'] . ')<br>', $html_copia);
                
                $this->enviarEmailComAnexos($com_copia, $assunto, $html_copia, $anexos);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao enviar comentário para solicitante: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarNovoComentario($ticket_id, $comentario_id) {
        try {
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email,
                             a.nome as agente_nome, a.email as agente_email,
                             c.comentario, uc.nome as comentario_autor
                      FROM tickets t 
                      LEFT JOIN usuarios u ON t.usuario_id = u.id 
                      LEFT JOIN usuarios a ON t.agente_id = a.id
                      LEFT JOIN comentarios c ON c.id = :comentario_id
                      LEFT JOIN usuarios uc ON c.usuario_id = uc.id
                      WHERE t.id = :ticket_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->bindParam(':comentario_id', $comentario_id);
            $stmt->execute();
            $dados = $stmt->fetch();
            
            if (!$dados) return false;
            
            // Buscar o autor do comentário (quem enviou o email)
            $query_autor = "SELECT usuario_id FROM comentarios WHERE id = ?";
            $stmt_autor = $this->db->prepare($query_autor);
            $stmt_autor->execute([$comentario_id]);
            $autor = $stmt_autor->fetch();
            $autor_id = $autor ? $autor['usuario_id'] : $dados['usuario_id'];
            
            $this->marcarComentarioNaoLido($ticket_id, $comentario_id, $autor_id);
            
            $assunto = "🎫 [#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Novo comentário - " . $dados['titulo'];
            
            $mensagem = "💬 NOVO COMENTÁRIO\n\n";
            $mensagem .= "Ticket: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
            $mensagem .= "Título: " . $dados['titulo'] . "\n";
            $mensagem .= "Autor do comentário: " . $dados['comentario_autor'] . "\n";
            $mensagem .= "Data: " . date('d/m/Y H:i') . "\n\n";
            $mensagem .= "Comentário:\n" . $dados['comentario'] . "\n\n";
            $mensagem .= "Acesse o sistema para responder.\n\n";
            $mensagem .= "Atenciosamente,\nSistema Helpdesk CRPRS";
            
            if ($dados['agente_id']) {
                if ($dados['agente_email']) {
                    $this->enviarEmailTexto($dados['agente_email'], $assunto, $mensagem);
                }
            } else {
                $query_agentes = "SELECT email FROM usuarios WHERE tipo IN ('agente', 'admin') AND email IS NOT NULL AND ativo = 1";
                $stmt_agentes = $this->db->prepare($query_agentes);
                $stmt_agentes->execute();
                $agentes = $stmt_agentes->fetchAll();
                
                foreach ($agentes as $agente) {
                    $this->enviarEmailTexto($agente['email'], $assunto, $mensagem);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao notificar novo comentário: ' . $e->getMessage());
            return false;
        }
    }
    
    private function marcarComentarioNaoLido($ticket_id, $comentario_id, $autor_id) {
        try {
            error_log("[EMAIL] Marcar não lido - Ticket: $ticket_id, Comentario: $comentario_id, Autor: $autor_id");
            
            // Buscar dados do ticket
            $query_ticket = "SELECT agente_id FROM tickets WHERE id = ?";
            $stmt_ticket = $this->db->prepare($query_ticket);
            $stmt_ticket->execute([$ticket_id]);
            $ticket = $stmt_ticket->fetch();
            
            if (!$ticket) {
                error_log("[EMAIL] Ticket não encontrado");
                return;
            }
            
            error_log("[EMAIL] Agente do ticket: " . ($ticket['agente_id'] ?? 'NULL'));
            
            $agentes_notificar = [];
            
            if ($ticket['agente_id']) {
                // Ticket tem responsável: notificar apenas ele (se não for o autor)
                if ($ticket['agente_id'] != $autor_id) {
                    $agentes_notificar[] = $ticket['agente_id'];
                    error_log("[EMAIL] Notificar responsável: " . $ticket['agente_id']);
                }
            } else {
                // Ticket sem responsável: notificar todos os agentes exceto o autor
                $query_agentes = "SELECT id FROM usuarios WHERE tipo IN ('agente', 'admin') AND id != ?";
                $stmt_agentes = $this->db->prepare($query_agentes);
                $stmt_agentes->execute([$autor_id]);
                $agentes_notificar = $stmt_agentes->fetchAll(PDO::FETCH_COLUMN);
                error_log("[EMAIL] Notificar todos: " . implode(', ', $agentes_notificar));
            }
            
            // Inserir registro para cada agente
            foreach ($agentes_notificar as $agente_id) {
                $query = "INSERT INTO comentarios_nao_lidos (ticket_id, usuario_id, comentario_id) 
                         VALUES (?, ?, ?) 
                         ON DUPLICATE KEY UPDATE comentario_id = ?, criado_em = CURRENT_TIMESTAMP";
                $stmt = $this->db->prepare($query);
                $stmt->execute([$ticket_id, $agente_id, $comentario_id, $comentario_id]);
                error_log("[EMAIL] Inserido para agente: $agente_id");
            }
            
        } catch (Exception $e) {
            error_log('Erro ao marcar comentário não lido: ' . $e->getMessage());
        }
    }
    
    public function confirmarComentarioParaRemetente($ticket_id, $email_remetente, $anexos_rejeitados = []) {
        try {
            $query = "SELECT t.*, u.nome as usuario_nome 
                     FROM tickets t 
                     LEFT JOIN usuarios u ON t.usuario_id = u.id 
                     WHERE t.id = :ticket_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket) return false;
            
            $assunto = "🎫 [#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Comentário recebido - " . $ticket['titulo'];
            
            $mensagem = "Seu comentário foi adicionado ao ticket com sucesso!\n\n";
            $mensagem .= "Ticket: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
            $mensagem .= "Título: " . $ticket['titulo'] . "\n";
            $mensagem .= "Status: " . ucfirst($ticket['status']) . "\n";
            $mensagem .= "Data: " . date('d/m/Y H:i') . "\n\n";
            
            if (!empty($anexos_rejeitados)) {
                $mensagem .= "ATENÇÃO: Alguns anexos foram rejeitados:\n\n";
                foreach ($anexos_rejeitados as $anexo) {
                    $mensagem .= "- {$anexo['nome']}: {$anexo['motivo']}\n";
                }
                $mensagem .= "\nLimites para anexos:\n";
                $mensagem .= "- Máximo 5 arquivos\n";
                $mensagem .= "- Tamanho total máximo: 20MB\n";
                $mensagem .= "- Tipos aceitos: PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, PNG, JPG\n\n";
            }
            
            $mensagem .= "Nossa equipe foi notificada e responderá em breve.\n\n";
            $mensagem .= "Atenciosamente,\nEquipe CRPRS Helpdesk";
            
            // Usar template HTML ao invés de texto
            $template_path = dirname(__DIR__, 2) . '/backend/email-template-comentario.html';
            $template = file_get_contents($template_path);
            $template = str_replace('{{NOME_USUARIO}}', $ticket['usuario_nome'], $template);
            $template = str_replace('{{NUMERO_TICKET}}', str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $template);
            $template = str_replace('{{TITULO_TICKET}}', $ticket['titulo'], $template);
            $template = str_replace('{{DATA_COMENTARIO}}', date('d/m/Y, H:i'), $template);
            $template = str_replace('{{ASSINATURA_EMAIL}}', $this->getAssinatura(), $template);
            
            if (!empty($anexos_rejeitados)) {
                $aviso = '<div style="background-color:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:20px 0"><h3 style="color:#856404;margin-top:0">⚠️ Anexos rejeitados</h3><ul>';
                foreach ($anexos_rejeitados as $anexo) {
                    $aviso .= "<li><b>{$anexo['nome']}:</b> {$anexo['motivo']}</li>";
                }
                $aviso .= '</ul><p><b>Limites:</b> 5 arquivos, 20MB, PDF/DOC/DOCX/TXT/CSV/XLS/XLSX/PNG/JPG</p></div>';
                $template = str_replace('<p>Agradecemos', $aviso . '<p>Agradecemos', $template);
            }
            
            return $this->enviarEmail($email_remetente, $assunto, $template);
            
        } catch (Exception $e) {
            error_log('Erro ao confirmar comentário: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarFechamentoTicket($ticket_id) {
        try {
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email,
                             f.nome as fechado_por_nome
                     FROM tickets t 
                     LEFT JOIN usuarios u ON t.usuario_id = u.id 
                     LEFT JOIN usuarios f ON t.fechado_por = f.id
                     WHERE t.id = :ticket_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket) return false;
            
            // Enviar para solicitante se tiver email
            if (($ticket['email_solicitante'] ?: $ticket['usuario_email']) && strpos(($ticket['email_solicitante'] ?: $ticket['usuario_email']), '@')) {
                $template_path = dirname(__DIR__, 2) . '/backend/email-template-fechamento.html';
                $template = file_get_contents($template_path);
                $template = str_replace('{{NOME_USUARIO}}', $ticket['usuario_nome'], $template);
                $template = str_replace('{{NUMERO_TICKET}}', str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $template);
                $template = str_replace('{{TITULO_TICKET}}', $ticket['titulo'], $template);
                $template = str_replace('{{NOME_AGENTE}}', $ticket['fechado_por_nome'] ?: 'Sistema', $template);
                $template = str_replace('{{DATA_FECHAMENTO}}', date('d/m/Y H:i', strtotime($ticket['fechado_em'])), $template);
                $template = str_replace('{{ASSINATURA_EMAIL}}', $this->getAssinatura(), $template);
                
                $assunto = "🎫 [#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Ticket fechado - " . $ticket['titulo'];
                $this->enviarEmail(($ticket['email_solicitante'] ?: $ticket['usuario_email']), $assunto, $template);
            }
            
            // Notificar agente responsável se for outro que fechou
            if ($ticket['agente_id'] && $ticket['fechado_por'] != $ticket['agente_id']) {
                $query_agente = "SELECT email FROM usuarios WHERE id = :id AND ativo = 1";
                $stmt_agente = $this->db->prepare($query_agente);
                $stmt_agente->bindParam(':id', $ticket['agente_id']);
                $stmt_agente->execute();
                $agente = $stmt_agente->fetch();
                
                if ($agente && $agente['email']) {
                    $msg = "🔒 TICKET FECHADO POR OUTRO USUÁRIO\n\n";
                    $msg .= "Ticket: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
                    $msg .= "Título: " . $ticket['titulo'] . "\n";
                    $msg .= "Fechado por: " . ($ticket['fechado_por_nome'] ?: 'Sistema') . "\n";
                    $msg .= "Data: " . date('d/m/Y H:i') . "\n";
                    $this->enviarEmailTexto($agente['email'], "🔒 Ticket #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " foi fechado", $msg);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao notificar fechamento: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarReaberturaTicket($ticket_id) {
        try {
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email,
                             a.email as agente_email, a.nome as agente_nome
                     FROM tickets t 
                     LEFT JOIN usuarios u ON t.usuario_id = u.id 
                     LEFT JOIN usuarios a ON t.agente_id = a.id
                     WHERE t.id = :ticket_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket) return false;
            
            // Notificar agente responsável
            if ($ticket['agente_email']) {
                $msg = "🔄 TICKET REABERTO\n\n";
                $msg .= "Ticket: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
                $msg .= "Título: " . $ticket['titulo'] . "\n";
                $msg .= "Solicitante: " . $ticket['usuario_nome'] . "\n";
                $msg .= "Data: " . date('d/m/Y H:i') . "\n\n";
                $msg .= "Acesse o sistema para verificar.\n";
                $this->enviarEmailTexto($ticket['agente_email'], "🔄 Ticket #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " foi reaberto", $msg);
            }
            
            return true;
        } catch (Exception $e) {
            error_log('Erro ao notificar reabertura: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarReaberturaParaSolicitante($ticket_id) {
        try {
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email
                     FROM tickets t 
                     LEFT JOIN usuarios u ON t.usuario_id = u.id
                     WHERE t.id = :ticket_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket || !$ticket['usuario_email']) return false;
            
            // Carregar template HTML
            $template_path = dirname(__DIR__, 2) . '/backend/email-template-reabertura.html';
            $template = file_get_contents($template_path);
            
            // Carregar assinatura
            $assinatura_path = dirname(__DIR__, 2) . '/frontend/AssinaturaEmail.html';
            $assinatura = file_exists($assinatura_path) ? file_get_contents($assinatura_path) : '';
            
            // Substituir variáveis
            $template = str_replace('{{NOME_USUARIO}}', $ticket['usuario_nome'], $template);
            $template = str_replace('{{NUMERO_TICKET}}', str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $template);
            $template = str_replace('{{TITULO_TICKET}}', $ticket['titulo'], $template);
            $template = str_replace('{{DATA_REABERTURA}}', date('d/m/Y H:i'), $template);
            $template = str_replace('{{ASSINATURA_EMAIL}}', $assinatura, $template);
            
            $assunto = "🎫 [#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Ticket reaberto - " . $ticket['titulo'];
            
            return $this->enviarEmailHTML($ticket['usuario_email'], $assunto, $template);
        } catch (Exception $e) {
            error_log('Erro ao notificar solicitante sobre reabertura: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarAtribuicaoTicket($ticket_id, $agente_id, $atribuido_por_nome, $era_sem_atribuicao = false) {
        try {
            // Buscar agente
            $query_agente = "SELECT nome, email FROM usuarios WHERE id = :agente_id";
            $stmt_agente = $this->db->prepare($query_agente);
            $stmt_agente->bindParam(':agente_id', $agente_id);
            $stmt_agente->execute();
            $agente = $stmt_agente->fetch();
            
            // Buscar ticket
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email
                     FROM tickets t 
                     LEFT JOIN usuarios u ON t.usuario_id = u.id
                     WHERE t.id = :ticket_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket || !$agente) return false;
            
            // Notificar agente
            if ($agente['email']) {
                $msg = "👤 TICKET ATRIBUÍDO PARA VOCÊ\n\n";
                $msg .= "Ticket: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
                $msg .= "Título: " . $ticket['titulo'] . "\n";
                $msg .= "Atribuído por: " . $atribuido_por_nome . "\n";
                $msg .= "Data: " . date('d/m/Y H:i') . "\n\n";
                $msg .= "Acesse o sistema para atender este ticket.\n";
                
                $this->enviarEmailTexto($agente['email'], "👤 Ticket #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " atribuído para você", $msg);
            }
            
            // Se era sem atribuição, enviar primeira resposta ao solicitante
            if ($era_sem_atribuicao && $ticket['usuario_email'] && $agente['nome']) {
                error_log("[EMAIL] Enviando primeira resposta com agente: {$agente['nome']}");
                $this->enviarPrimeiraRespostaAoSolicitante($ticket_id, $agente['nome']);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao notificar atribuição: ' . $e->getMessage());
            return false;
        }
    }
    
    public function enviarPrimeiraRespostaAoSolicitante($ticket_id, $nome_agente) {
        try {
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email
                     FROM tickets t 
                     LEFT JOIN usuarios u ON t.usuario_id = u.id
                     WHERE t.id = :ticket_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            $ticket = $stmt->fetch();
            
            if (!$ticket || !$ticket['usuario_email']) return false;
            
            $template_path = dirname(__DIR__, 2) . '/backend/email-template-primeira-resposta.html';
            $template = file_get_contents($template_path);
            $assinatura = $this->getAssinatura();
            
            $template = str_replace('{{NOME_USUARIO}}', $ticket['usuario_nome'], $template);
            $template = str_replace('{{NUMERO_TICKET}}', str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $template);
            $template = str_replace('{{TITULO_TICKET}}', $ticket['titulo'], $template);
            $template = str_replace('{{NOME_AGENTE}}', $nome_agente, $template);
            $template = str_replace('{{DATA_RESPOSTA}}', date('d/m/Y H:i'), $template);
            $template = str_replace('{{ASSINATURA_EMAIL}}', $assinatura, $template);
            
            $assunto = "🎫 [#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Primeira resposta - " . $ticket['titulo'];
            
            $resultado = $this->enviarEmail(($ticket['email_solicitante'] ?: $ticket['usuario_email']), $assunto, $template);
            
            // Registrar primeira resposta no SLA
            if ($resultado) {
                $this->registrarPrimeiraResposta($ticket_id);
            }
            
            return $resultado;
            
        } catch (Exception $e) {
            error_log('Erro ao enviar primeira resposta: ' . $e->getMessage());
            return false;
        }
    }
    
    private function registrarPrimeiraResposta($ticket_id) {
        try {
            // Registrar primeira resposta no SLA
            $query = "UPDATE ticket_sla SET primeira_resposta_em = NOW() WHERE ticket_id = :ticket_id AND primeira_resposta_em IS NULL";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->execute();
            error_log("[SLA] Primeira resposta registrada para ticket #$ticket_id");
        } catch (Exception $e) {
            error_log('[EMAIL] Erro ao registrar primeira resposta no SLA: ' . $e->getMessage());
        }
    }

    private function getAssinatura() {
        return '<table width="100%" border="0" cellspacing="3" cellpadding="3" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
  <tr>
    <td width="13%" valign="top"><img src="http://www.crprs.org.br/assets/frontend/img/logo.png" width="150"/></td>
    <td width="87%" valign="top">
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Equipe de Suporte em Tecnologia da Informação</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Setor de TI</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2">suporte@crprs.org.br</font><br /><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Conselho Regional de Psicologia do Rio Grande do Sul</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Av. Protásio Alves, 2854/301 - Porto Alegre/RS</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">(51) 3334-6799</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2">www.crprs.org.br</font>
    </td>
  </tr>
</table>';
    }
}
?>

