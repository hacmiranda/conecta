<?php
date_default_timezone_set('America/Sao_Paulo');

class EmailServiceV2 {
    private $host;
    private $port;
    private $username;
    private $password;
    private $db;
    private $log_file;
    
    public function __construct($db) {
        $this->host = EmailConfig::IMAP_HOST;
        $this->port = EmailConfig::IMAP_PORT;
        $this->username = EmailConfig::IMAP_USERNAME;
        $this->password = EmailConfig::IMAP_PASSWORD;
        $this->db = $db;
        $this->log_file = dirname(__DIR__, 2) . '/backend/logs/email-cron.log';
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->log_file, "[$timestamp] $message\n", FILE_APPEND);
        echo "<p>$message</p>";
    }
    
    public function verificarEmails() {
        try {
            $mailbox = "{{$this->host}:{$this->port}/imap/ssl/novalidate-cert}INBOX";
            $this->log("🔌 Conectando: $mailbox");
            
            $inbox = @imap_open($mailbox, $this->username, $this->password, 0, 3);
            
            if (!$inbox) {
                $erro = imap_last_error();
                $this->log("❌ ERRO CONEXÃO: $erro");
                
                // Tentar sem novalidate-cert
                $mailbox = "{{$this->host}:{$this->port}/imap/ssl}INBOX";
                $inbox = @imap_open($mailbox, $this->username, $this->password);
                
                if (!$inbox) {
                    throw new Exception('Falha na conexão IMAP: ' . imap_last_error());
                }
            }
            
            $this->log("✅ Conectado com sucesso");
            
            // Buscar TODOS os emails não lidos (incluindo de domínios específicos)
            $emails = imap_search($inbox, 'UNSEEN', SE_UID);
            
            if (!$emails) {
                $this->log("📭 Nenhum email não lido");
                
                // Verificar emails com erro para reprocessar
                $this->reprocessarEmailsComErro();
                
                imap_close($inbox);
                return true;
            }
            
            $total = count($emails);
            $this->log("📧 Encontrados $total emails não lidos");
            
            foreach ($emails as $uid) {
                try {
                    $this->processarEmailPorUID($inbox, $uid);
                } catch (Exception $e) {
                    $this->log("❌ Erro ao processar UID $uid: " . $e->getMessage());
                    $this->salvarEmailComErro($inbox, $uid, $e->getMessage());
                }
            }
            
            imap_close($inbox);
            
            // Segunda verificação (dupla checagem)
            $this->log("🔄 Iniciando segunda verificação...");
            sleep(2);
            $this->verificarEmailsPerdidos();
            
            return true;
            
        } catch (Exception $e) {
            $this->log("❌ ERRO CRÍTICO: " . $e->getMessage());
            return false;
        }
    }
    
    private function processarEmailPorUID($inbox, $uid) {
        $msgno = imap_msgno($inbox, $uid);
        $this->log("--- Processando UID: $uid (MSG: $msgno) ---");
        
        $header = imap_headerinfo($inbox, $msgno);
        $structure = imap_fetchstructure($inbox, $msgno);
        
        // Extrair Message-ID único
        $message_id = $header->message_id ?? uniqid('email_', true);
        
        // Verificar se já foi processado
        if ($this->emailJaProcessado($message_id)) {
            $this->log("⏭️ Email já processado (Message-ID: $message_id)");
            imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
            return;
        }
        
        // Extrair dados
        $assunto = $this->decodificarTexto($header->subject ?? 'Sem assunto');
        $remetente = $header->from[0]->mailbox . '@' . $header->from[0]->host;
        $corpo = $this->extrairCorpoEmail($inbox, $msgno, $structure);
        
        // Limpar corpo
        $corpo = $this->limparCorpoEmail($corpo);
        
        $this->log("📨 De: $remetente");
        $this->log("📋 Assunto: $assunto");
        
        // Verificar se é resposta a ticket
        $ticket_id = $this->extrairTicketId($assunto);
        
        try {
            if ($ticket_id) {
                $this->log("💬 Resposta ao ticket #$ticket_id");
                $anexos = $this->processarAnexosEmail($inbox, $msgno);
                $this->adicionarComentario($ticket_id, $corpo, $remetente, $anexos);
                $this->registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, 'comentario');
            } else {
                $this->log("🆕 Novo ticket");
                $ticket_id = $this->criarTicketPorEmail($assunto, $corpo, $remetente, $inbox, $msgno);
                $this->registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, 'novo_ticket');
            }
            
            // Marcar como lido APENAS após sucesso
            imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
            $this->log("✅ Email processado com sucesso");
            
        } catch (Exception $e) {
            $this->log("❌ Erro no processamento: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function emailJaProcessado($message_id) {
        $query = "SELECT id FROM emails_processados WHERE message_id = :message_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':message_id', $message_id);
        $stmt->execute();
        return $stmt->fetch() !== false;
    }
    
    private function registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, $tipo) {
        $query = "INSERT INTO emails_processados (message_id, email_remetente, assunto, ticket_id, tipo) 
                  VALUES (:message_id, :remetente, :assunto, :ticket_id, :tipo)
                  ON DUPLICATE KEY UPDATE tentativas = tentativas + 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':message_id', $message_id);
        $stmt->bindParam(':remetente', $remetente);
        $stmt->bindParam(':assunto', $assunto);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->execute();
    }
    
    private function salvarEmailComErro($inbox, $uid, $erro) {
        try {
            $msgno = imap_msgno($inbox, $uid);
            $header = imap_headerinfo($inbox, $msgno);
            $structure = imap_fetchstructure($inbox, $msgno);
            
            $message_id = $header->message_id ?? uniqid('email_', true);
            $assunto = $this->decodificarTexto($header->subject ?? 'Sem assunto');
            $remetente = $header->from[0]->mailbox . '@' . $header->from[0]->host;
            $corpo = $this->extrairCorpoEmail($inbox, $msgno, $structure);
            
            $query = "INSERT INTO emails_erro (message_id, email_remetente, assunto, corpo, erro_mensagem) 
                      VALUES (:message_id, :remetente, :assunto, :corpo, :erro)
                      ON DUPLICATE KEY UPDATE 
                        tentativas = tentativas + 1,
                        ultima_tentativa = NOW(),
                        erro_mensagem = :erro";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':message_id', $message_id);
            $stmt->bindParam(':remetente', $remetente);
            $stmt->bindParam(':assunto', $assunto);
            $stmt->bindParam(':corpo', $corpo);
            $stmt->bindParam(':erro', $erro);
            $stmt->execute();
            
            $this->log("💾 Email salvo na fila de erro");
        } catch (Exception $e) {
            $this->log("❌ Erro ao salvar email com erro: " . $e->getMessage());
        }
    }
    
    private function reprocessarEmailsComErro() {
        try {
            // Buscar emails com erro (máximo 3 tentativas)
            $query = "SELECT * FROM emails_erro WHERE tentativas < 3 ORDER BY criado_em ASC LIMIT 10";
            $stmt = $this->db->query($query);
            $emails_erro = $stmt->fetchAll();
            
            if (empty($emails_erro)) {
                return;
            }
            
            $this->log("🔄 Reprocessando " . count($emails_erro) . " emails com erro");
            
            foreach ($emails_erro as $email) {
                try {
                    $ticket_id = $this->extrairTicketId($email['assunto']);
                    
                    if ($ticket_id) {
                        $this->adicionarComentario($ticket_id, $email['corpo'], $email['email_remetente'], ['validos' => [], 'rejeitados' => []]);
                        $this->registrarEmailProcessado($email['message_id'], $email['email_remetente'], $email['assunto'], $ticket_id, 'comentario');
                    } else {
                        $ticket_id = $this->criarTicketSimples($email['assunto'], $email['corpo'], $email['email_remetente']);
                        $this->registrarEmailProcessado($email['message_id'], $email['email_remetente'], $email['assunto'], $ticket_id, 'novo_ticket');
                    }
                    
                    // Remover da fila de erro
                    $del_query = "DELETE FROM emails_erro WHERE id = :id";
                    $del_stmt = $this->db->prepare($del_query);
                    $del_stmt->bindParam(':id', $email['id']);
                    $del_stmt->execute();
                    
                    $this->log("✅ Email reprocessado com sucesso");
                    
                } catch (Exception $e) {
                    $this->log("❌ Falha ao reprocessar: " . $e->getMessage());
                    
                    // Atualizar tentativas
                    $upd_query = "UPDATE emails_erro SET tentativas = tentativas + 1, ultima_tentativa = NOW() WHERE id = :id";
                    $upd_stmt = $this->db->prepare($upd_query);
                    $upd_stmt->bindParam(':id', $email['id']);
                    $upd_stmt->execute();
                }
            }
        } catch (Exception $e) {
            $this->log("❌ Erro ao reprocessar emails: " . $e->getMessage());
        }
    }
    
    private function verificarEmailsPerdidos() {
        try {
            $mailbox = "{{$this->host}:{$this->port}/imap/ssl/novalidate-cert}INBOX";
            $inbox = @imap_open($mailbox, $this->username, $this->password);
            
            if (!$inbox) {
                return;
            }
            
            // Buscar emails dos últimos 7 dias
            $data_limite = date('d-M-Y', strtotime('-7 days'));
            $emails = imap_search($inbox, "SINCE \"$data_limite\"", SE_UID);
            
            if (!$emails) {
                imap_close($inbox);
                return;
            }
            
            $perdidos = 0;
            foreach ($emails as $uid) {
                $msgno = imap_msgno($inbox, $uid);
                $header = imap_headerinfo($inbox, $msgno);
                $message_id = $header->message_id ?? null;
                
                if ($message_id && !$this->emailJaProcessado($message_id)) {
                    $this->log("⚠️ Email perdido detectado: $message_id");
                    
                    // Marcar como não lido para reprocessar
                    imap_clearflag_full($inbox, $uid, "\\Seen", ST_UID);
                    $perdidos++;
                }
            }
            
            if ($perdidos > 0) {
                $this->log("🔍 Encontrados $perdidos emails perdidos - serão reprocessados");
            }
            
            imap_close($inbox);
        } catch (Exception $e) {
            $this->log("❌ Erro na verificação de emails perdidos: " . $e->getMessage());
        }
    }
    
    // Métodos auxiliares (usar os mesmos do EmailService original)
    private function limparCorpoEmail($corpo) {
        $corpo = strip_tags($corpo);
        $corpo = preg_replace('/[A-Za-z0-9+\/]{100,}={0,2}/', '', $corpo);
        $corpo = preg_replace('/Content-Type:.*?\n/i', '', $corpo);
        $corpo = preg_replace('/Content-Transfer-Encoding:.*?\n/i', '', $corpo);
        $corpo = trim($corpo);
        
        if (strlen($corpo) > 10000) {
            $corpo = substr($corpo, 0, 10000) . "\n\n[CONTEÚDO TRUNCADO]";
        }
        
        return $corpo ?: "Email recebido em " . date('d/m/Y H:i:s');
    }
    
    private function extrairTicketId($assunto) {
        if (preg_match('/#(\d{5})/', $assunto, $matches)) {
            return (int)$matches[1];
        }
        if (preg_match('/\[#(\d+)\]|ticket\s+(\d+)/i', $assunto, $matches)) {
            return (int)($matches[1] ?? $matches[2]);
        }
        return null;
    }
    
    private function decodificarTexto($texto) {
        return str_replace('_', ' ', mb_decode_mimeheader($texto));
    }
    
    // Incluir outros métodos do EmailService original aqui
    // (criarTicketPorEmail, adicionarComentario, processarAnexosEmail, etc.)
}
