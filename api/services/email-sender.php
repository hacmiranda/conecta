<?php
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../config/email-config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/acesso-service.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailSender {
    private $db;
    private $mailer;
    // Contexto atual de envio (preenchido automaticamente ou via setEmailContext)
    private $logContext = ['ticket_id' => null, 'tipo' => null];
    
    public function __construct($db) {
        $this->db = $db;
        $this->configurarMailer();
    }

    /**
     * Define o contexto do próximo e-mail a ser enviado.
     * Deve ser chamado antes de enviarEmail/enviarEmailTexto/enviarEmailComAnexos
     * quando the contexto é conhecido (opcional — o sistema tenta detectar pelo assunto).
     */
    public function setEmailContext(int $ticket_id = null, string $tipo = null): void {
        $this->logContext = [
            'ticket_id' => $ticket_id,
            'tipo'      => $tipo,
        ];
    }
    
    private function configurarMailer() {
        $this->mailer = new PHPMailer(true);
        $this->mailer->isSMTP();
        $this->mailer->SMTPDebug = 2;
        $this->mailer->Debugoutput = function($str, $level) {
            error_log("[PHPMAILER] $str");
        };
        $this->mailer->Host = EmailConfig::get('SMTP_HOST');
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = EmailConfig::get('SMTP_USERNAME');
        $this->mailer->Password = EmailConfig::get('SMTP_PASSWORD');
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $this->mailer->Port = EmailConfig::get('SMTP_PORT');
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->Timeout = 30;
        $this->mailer->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        $this->mailer->setFrom(EmailConfig::get('FROM_EMAIL'), EmailConfig::get('FROM_NAME'));
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
            
            $assunto = "🎫 [#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Demanda criada - " . $ticket['titulo'];
            
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
            
            // Somente os gestores do setor da demanda (admins se o setor não tiver gestor)
            $agentes = AcessoService::destinatariosInternosDoSetor(
                $this->db,
                !empty($ticket['setor_id']) ? (int) $ticket['setor_id'] : null
            );
            $agentes = array_values(array_filter($agentes, function ($a) { return !empty($a['email']); }));
            
            $assunto = "🎫 [NOVA DEMANDA] #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " - " . $ticket['titulo'];

            $mensagem = "🎫 NOVA DEMANDA CRIADA\n\n";
            $mensagem .= "Número: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
            $mensagem .= "Título: " . $ticket['titulo'] . "\n";
            $mensagem .= "Solicitante: " . ($ticket['email_solicitante'] ?: $ticket['usuario_email']) . "\n";
            $mensagem .= "Data: " . date('d/m/Y H:i', strtotime($ticket['criado_em'])) . "\n";
            $mensagem .= "Status: " . ucfirst($ticket['status']) . "\n";
            $mensagem .= "Prioridade: " . ucfirst($ticket['prioridade']) . "\n\n";
            // Limpar HTML/base64 da descrição para evitar emails gigantes (Quill pode gerar imagens base64)
            $descricao_texto = strip_tags($ticket['descricao'] ?? '');
            $descricao_texto = preg_replace('/data:[^;]+;base64,[^\s]+/', '[imagem]', $descricao_texto);
            $descricao_texto = mb_substr(trim($descricao_texto), 0, 500);
            if (mb_strlen($ticket['descricao'] ?? '') > 500) {
                $descricao_texto .= '...';
            }

            $mensagem .= "Descrição:\n" . $descricao_texto . "\n\n";
            $mensagem .= "Acesse o sistema para atribuir e responder a demanda.\n\n";
            $mensagem .= "Atenciosamente,\nSistema Conecta CRPRS";

            $this->mailer->SMTPKeepAlive = true;
            $enviados = 0;
            foreach ($agentes as $agente) {
                if ($this->enviarEmailTexto($agente['email'], $assunto, $mensagem)) {
                    $enviados++;
                }
            }
            $this->mailer->smtpClose();
            
            return $enviados > 0;
            
        } catch (Exception $e) {
            error_log('Erro ao notificar agentes: ' . $e->getMessage());
            return false;
        }
    }
    
    private function enviarEmailComAnexos($para, $assunto, $html, $anexos = []): bool {
        $status = 'falha';
        $erro   = null;
        try {
            $this->configurarMailer();
            
            $this->mailer->setFrom(EmailConfig::get('SMTP_USERNAME'), 'Suporte CRPRS');
            $this->mailer->addAddress($para);
            $this->mailer->Subject = $assunto;
            $this->mailer->Body    = $html;
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';
            
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
            $status    = $resultado ? 'sucesso' : 'falha';
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            return $resultado;
        } catch (Exception $e) {
            $erro = $e->getMessage();
            error_log('Erro ao enviar email com anexos: ' . $erro);
            return false;
        } finally {
            $this->registrarLogEmail($para, $assunto, $status, $erro);
        }
    }

    /**
     * Registra cada tentativa de envio de e-mail na tabela email_logs.
     * Detecta ticket_id e tipo automaticamente pelo assunto quando não configurados via setEmailContext().
     */
    private function registrarLogEmail(string $destinatario, string $assunto, string $status, ?string $erro = null): void {
        try {
            $ticket_id = $this->logContext['ticket_id'];
            $tipo      = $this->logContext['tipo'];

            // Auto-detectar ticket_id pelo assunto se não informado
            if ($ticket_id === null && preg_match('/#(\d{5})/', $assunto, $m)) {
                $ticket_id = (int) $m[1];
            }

            // Auto-detectar tipo pelo assunto se não informado (case-insensitive via mb_strtolower)
            if ($tipo === null) {
                $lower = mb_strtolower($assunto, 'UTF-8');
                $tipo = match(true) {
                    // Mais específicos primeiro para evitar capturas incorretas
                    str_contains($lower, 'primeira resposta')   => 'primeira_resposta',
                    str_contains($lower, 'resposta do suporte') => 'resposta',
                    str_contains($lower, 'novo ticket')         => 'notif_agente',
                    str_contains($lower, 'novo comentário')     => 'notif_agente',
                    str_contains($lower, 'novo comentario')     => 'notif_agente',
                    str_contains($lower, 'criado')              => 'novo_ticket',
                    str_contains($lower, 'fechado')             => 'fechamento',
                    str_contains($lower, 'reaberto')            => 'reabertura',
                    str_contains($lower, 'atribuído')           => 'atribuicao',
                    str_contains($lower, 'atribuido')           => 'atribuicao',
                    str_contains($lower, 'comentário')          => 'comentario',
                    str_contains($lower, 'comentario')          => 'comentario',
                    str_contains($lower, 'encaminhamento')      => 'encaminhamento',
                    str_contains($lower, 'lembrete')            => 'lembrete',
                    str_contains($lower, 'recuperação')         => 'recuperacao_senha',
                    str_contains($lower, 'recuperacao')         => 'recuperacao_senha',
                    default                                     => 'outros',
                };
            }

            $q = "INSERT INTO email_logs (destinatario, assunto, tipo, ticket_id, status, erro)
                  VALUES (:destinatario, :assunto, :tipo, :ticket_id, :status, :erro)";
            $s = $this->db->prepare($q);
            $s->bindParam(':destinatario', $destinatario);
            $s->bindParam(':assunto',      $assunto);
            $s->bindParam(':tipo',         $tipo);
            $s->bindParam(':ticket_id',    $ticket_id);
            $s->bindParam(':status',       $status);
            $s->bindParam(':erro',         $erro);
            $s->execute();

            // Resetar contexto após registrar
            $this->logContext = ['ticket_id' => null, 'tipo' => null];

        } catch (Exception $e) {
            // Log de fallback no arquivo caso o banco falhe
            error_log("[EMAIL-LOG] Falha ao registrar log no banco: " . $e->getMessage());
            error_log("[EMAIL-LOG] $status | Para: $destinatario | Assunto: $assunto | Erro: $erro");
        }
    }
    
    public function enviarEmail($para, $assunto, $html): bool {
        $status = 'falha';
        $erro   = null;
        try {
            set_time_limit(30);
            error_log("[EMAIL] Tentando enviar HTML para: $para");
            
            $mail = clone $this->mailer;
            $mail->clearAddresses();
            $mail->addAddress($para);
            $mail->isHTML(true);
            $mail->Subject = $assunto;
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);
            
            $resultado = $mail->send();
            $status    = $resultado ? 'sucesso' : 'falha';
            error_log("[EMAIL] Resultado envio: " . ($resultado ? 'OK' : 'FALHA'));
            return $resultado;
        } catch (Exception $e) {
            $erro = $e->getMessage();
            error_log("[EMAIL] ERRO ao enviar para $para: $erro");
            return false;
        } finally {
            $this->registrarLogEmail($para, $assunto, $status, $erro);
        }
    }
    
    private function enviarEmailTexto($para, $assunto, $texto): bool {
        $status = 'falha';
        $erro   = null;
        try {
            set_time_limit(30);
            $mail = clone $this->mailer;
            $mail->clearAddresses();
            $mail->addAddress($para);
            $mail->isHTML(false);
            $mail->Subject = $assunto;
            $mail->Body    = $texto;
            
            $resultado = $mail->send();
            $status    = $resultado ? 'sucesso' : 'falha';
            if (!$resultado) {
                error_log("[EMAIL] Falha ao enviar email para: $para");
            }
            return $resultado;
        } catch (Exception $e) {
            $erro = $e->getMessage();
            error_log("[EMAIL] Erro ao enviar email para $para: $erro");
            return false;
        } finally {
            $this->registrarLogEmail($para, $assunto, $status, $erro);
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

            // Remove imagens base64 inline (inseridas no editor Quill) para não "estourar" o e-mail.
            // Imagens anexadas como arquivo continuam sendo enviadas normalmente como attachment.
            $comentario_email = preg_replace(
                '/<img\s[^>]*src=["\']data:[^"\']+["\'][^>]*>/i',
                '<em style="color:#888;">[Imagem disponível no portal de suporte]</em>',
                $dados['comentario']
            );

            // Template para o solicitante
            $template_solicitante = file_get_contents(dirname(__DIR__, 2) . '/backend/email-template-resposta.html');
            $assinatura = file_get_contents(dirname(__DIR__, 2) . '/backend/AssinaturaEmail.html');

            $html_solicitante = str_replace(
                ['{{NOME_USUARIO}}', '{{NUMERO_TICKET}}', '{{TITULO_TICKET}}', '{{NOME_AGENTE}}', '{{DATA_RESPOSTA}}', '{{COMENTARIO_AGENTE}}', '{{ASSINATURA_EMAIL}}'],
                [$dados['usuario_nome'], str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $dados['titulo'], $dados['comentario_autor'], date('d/m/Y H:i'), $comentario_email, $assinatura],
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
                    [str_pad($ticket_id, 5, '0', STR_PAD_LEFT), $dados['titulo'], $dados['comentario_autor'], date('d/m/Y H:i'), $comentario_email, $assinatura],
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
            
            // Autor do comentário (não notificar a si mesmo)
            $query_autor = "SELECT usuario_id FROM comentarios WHERE id = ?";
            $stmt_autor  = $this->db->prepare($query_autor);
            $stmt_autor->execute([$comentario_id]);
            $autor       = $stmt_autor->fetch();
            $autor_id    = $autor ? $autor['usuario_id'] : null;

            $num_ticket = str_pad($ticket_id, 5, '0', STR_PAD_LEFT);
            $assunto    = "🎫 [#$num_ticket] Novo comentário - " . $dados['titulo'];
            $mensagem   = "Novo comentário adicionado à demanda #$num_ticket\n\n";
            $mensagem  .= "Demanda: " . $dados['titulo'] . "\n";
            $mensagem  .= "Autor: " . ($dados['comentario_autor'] ?? 'Solicitante') . "\n";
            $mensagem  .= "Data: " . date('d/m/Y H:i') . "\n\n";
            $mensagem  .= "Acesse o sistema para visualizar o comentário.\n\nAtenciosamente,\nSistema Conecta CRPRS";

            if ($dados['agente_id']) {
                // Notificar agente responsável (se não for o próprio autor)
                if ($dados['agente_email'] && $dados['agente_id'] != $autor_id) {
                    $this->enviarEmailTexto($dados['agente_email'], $assunto, $mensagem);
                }
            } else {
                // Sem responsável: notificar os gestores do setor da demanda (admins se não houver gestor)
                $stmt_setor = $this->db->prepare("SELECT setor_id FROM tickets WHERE id = ?");
                $stmt_setor->execute([$ticket_id]);
                $setor_ticket = $stmt_setor->fetchColumn();
                $agentes = AcessoService::destinatariosInternosDoSetor(
                    $this->db,
                    $setor_ticket ? (int) $setor_ticket : null,
                    $autor_id ? (int) $autor_id : null
                );

                foreach ($agentes as $agente) {
                    if (!empty($agente['email'])) {
                        $this->enviarEmailTexto($agente['email'], $assunto, $mensagem);
                    }
                }
            }

            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao notificar novo comentário: ' . $e->getMessage());
            return false;
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
            
            $mensagem = "Seu comentário foi adicionado à demanda com sucesso!\n\n";
            $mensagem .= "Demanda: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
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
            $mensagem .= "Atenciosamente,\nEquipe Conecta CRPRS";
            
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
                
                // Se foi criado por terceiros (email_solicitante), usar esse nome/email
                $nome_exibir = $ticket['usuario_nome'];
                if (!empty($ticket['email_solicitante'])) {
                    // Tentar achar nome se existir usuário com esse email na base
                    $stmt_nome = $this->db->prepare("SELECT nome FROM usuarios WHERE email = ?");
                    $stmt_nome->execute([$ticket['email_solicitante']]);
                    $user_nome = $stmt_nome->fetchColumn();
                    
                    if ($user_nome) {
                        $nome_exibir = $user_nome;
                    } else {
                        // Senão, usar parte do email antes do @ formatada
                        $partes = explode('@', $ticket['email_solicitante']);
                        $nome_exibir = ucwords(str_replace(['.', '_'], ' ', $partes[0]));
                    }
                }
                
                $template = str_replace('{{NOME_USUARIO}}', $nome_exibir, $template);
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
                    $msg = "🔒 DEMANDA FECHADA POR OUTRO USUÁRIO\n\n";
                    $msg .= "Demanda: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
                    $msg .= "Título: " . $ticket['titulo'] . "\n";
                    $msg .= "Fechado por: " . ($ticket['fechado_por_nome'] ?: 'Sistema') . "\n";
                    $msg .= "Data: " . date('d/m/Y H:i') . "\n";
                    $this->enviarEmailTexto($agente['email'], "🔒 Demanda #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " foi fechada", $msg);
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
                $msg = "🔄 DEMANDA REABERTA\n\n";
                $msg .= "Demanda: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
                $msg .= "Título: " . $ticket['titulo'] . "\n";
                $msg .= "Solicitante: " . $ticket['usuario_nome'] . "\n";
                $msg .= "Data: " . date('d/m/Y H:i') . "\n\n";
                $msg .= "Acesse o sistema para verificar.\n";
                $this->enviarEmailTexto($ticket['agente_email'], "🔄 Demanda #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " foi reaberta", $msg);
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
            
            return $this->enviarEmail($ticket['usuario_email'], $assunto, $template);
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
                $msg = "👤 DEMANDA ATRIBUÍDA PARA VOCÊ\n\n";
                $msg .= "Demanda: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n";
                $msg .= "Título: " . $ticket['titulo'] . "\n";
                $msg .= "Atribuído por: " . $atribuido_por_nome . "\n";
                $msg .= "Data: " . date('d/m/Y H:i') . "\n\n";
                $msg .= "Acesse o sistema para atender esta demanda.\n";

                $this->enviarEmailTexto($agente['email'], "👤 Demanda #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " atribuída para você", $msg);
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
    
    /**
     * Notifica o agente responsável que outro agente/admin comentou no ticket dele.
     * Também altera status para 'nova_resposta'.
     */
    public function notificarComentarioEntreAgentes($ticket_id, $comentario_id, $autor_nome, $agente_responsavel_id) {
        try {
            // Buscar dados do agente responsável
            $q_agente = "SELECT nome, email FROM usuarios WHERE id = :id";
            $s_agente = $this->db->prepare($q_agente);
            $s_agente->bindParam(':id', $agente_responsavel_id);
            $s_agente->execute();
            $agente = $s_agente->fetch(PDO::FETCH_ASSOC);

            if (!$agente || empty($agente['email'])) {
                error_log("[EMAIL] notificarComentarioEntreAgentes: agente sem email (id: $agente_responsavel_id)");
                return false;
            }

            // Buscar dados do ticket e comentário
            $q_ticket = "SELECT t.titulo, c.comentario
                         FROM tickets t
                         LEFT JOIN comentarios c ON c.id = :comentario_id
                         WHERE t.id = :ticket_id";
            $s_ticket = $this->db->prepare($q_ticket);
            $s_ticket->bindParam(':ticket_id', $ticket_id);
            $s_ticket->bindParam(':comentario_id', $comentario_id);
            $s_ticket->execute();
            $dados = $s_ticket->fetch(PDO::FETCH_ASSOC);

            if (!$dados) {
                error_log("[EMAIL] notificarComentarioEntreAgentes: ticket/comentário não encontrado");
                return false;
            }

            $num_ticket  = str_pad($ticket_id, 5, '0', STR_PAD_LEFT);
            $titulo      = $dados['titulo'];
            $trecho      = mb_substr(strip_tags($dados['comentario'] ?? ''), 0, 300);
            if (mb_strlen($dados['comentario'] ?? '') > 300) {
                $trecho .= '...';
            }

            $assunto = "💬 Novo comentário na Demanda #{$num_ticket} - {$titulo}";

            $html = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto;'>
                <div style='background: #1e3a5f; padding: 20px; border-radius: 8px 8px 0 0;'>
                    <h2 style='color: #fff; margin: 0; font-size: 18px;'>💬 Novo Comentário na Sua Demanda</h2>
                </div>
                <div style='background: #f9f9f9; padding: 24px; border-radius: 0 0 8px 8px; border: 1px solid #e0e0e0;'>
                    <p style='margin: 0 0 12px 0;'>Olá, <strong>{$agente['nome']}</strong>!</p>
                    <p style='margin: 0 0 16px 0;'>
                        O agente <strong>{$autor_nome}</strong> adicionou um novo comentário na demanda
                        <strong>#{$num_ticket}</strong> que está atribuída a você.
                    </p>
                    <div style='background: #fff; border-left: 4px solid #e0a800; padding: 14px 18px; border-radius: 4px; margin-bottom: 20px;'>
                        <p style='margin: 0 0 6px 0; font-size: 13px; color: #888;'>Demanda: <strong>#{$num_ticket}</strong> — {$titulo}</p>
                        <p style='margin: 0; font-size: 14px; color: #333;'>{$trecho}</p>
                    </div>
                    <p style='margin: 0 0 20px 0; font-size: 13px; color: #555;'>
                        O status da demanda foi atualizado para <strong>\"Nova Resposta\"</strong>.
                        Acesse o sistema para verificar e responder.
                    </p>
                    " . $this->getAssinatura() . "
                </div>
            </div>";

            return $this->enviarEmail($agente['email'], $assunto, $html);

        } catch (Exception $e) {
            error_log('[EMAIL] Erro em notificarComentarioEntreAgentes: ' . $e->getMessage());
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

