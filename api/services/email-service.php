<?php
require_once __DIR__ . '/acesso-service.php';
date_default_timezone_set('America/Sao_Paulo');

class EmailService {
    private $host;
    private $port;
    private $username;
    private $password;
    private $db;
    private $setorAtualId = null; // setor da caixa sendo processada no momento (null = caixa legada global)
    private $caixaEmailAtualId = null; // id em setor_emails da caixa sendo processada (null = caixa legada global)

    public function __construct($db) {
        $this->host = EmailConfig::get('IMAP_HOST');
        $this->port = EmailConfig::get('IMAP_PORT');
        $this->username = EmailConfig::get('IMAP_USERNAME');
        $this->password = EmailConfig::get('IMAP_PASSWORD');
        $this->db = $db;
    }

    /**
     * Verifica todas as caixas de e-mail cadastradas por setor (setor_emails, ativo=1).
     * Se nenhuma estiver cadastrada, cai para a caixa global legada do .env (compatibilidade
     * com quem ainda não migrou para e-mail por setor).
     */
    public function verificarEmails() {
        require_once __DIR__ . '/crypto-service.php';

        $caixas = [];
        try {
            $stmt = $this->db->query("SELECT id, setor_id, imap_host, imap_port, imap_user, imap_pass_cifrada FROM setor_emails WHERE ativo = 1");
            $caixas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro ao buscar caixas de e-mail por setor: " . $e->getMessage() . "</p>";
        }

        if (empty($caixas)) {
            echo "<p>Nenhuma caixa de e-mail por setor cadastrada — usando caixa global legada (.env).</p>";
            return $this->verificarCaixa($this->host, $this->port, $this->username, $this->password, null, null);
        }

        $sucessoGeral = true;
        foreach ($caixas as $caixa) {
            try {
                $senha = CryptoService::decrypt($caixa['imap_pass_cifrada']);
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Não foi possível decifrar a senha da caixa #{$caixa['id']}: " . $e->getMessage() . "</p>";
                $sucessoGeral = false;
                continue;
            }

            echo "<p>=== Verificando caixa do setor #{$caixa['setor_id']} ({$caixa['imap_user']}) ===</p>";
            $ok = $this->verificarCaixa($caixa['imap_host'], (int) $caixa['imap_port'], $caixa['imap_user'], $senha, (int) $caixa['setor_id'], (int) $caixa['id']);
            $sucessoGeral = $sucessoGeral && $ok;
        }

        return $sucessoGeral;
    }

    /**
     * Conecta numa caixa IMAP específica e processa os e-mails recentes.
     * $setorId identifica em qual setor as demandas criadas a partir desta caixa devem nascer
     * (null = caixa legada global, mantém o comportamento antigo de setor "Geral"/fallback).
     * $caixaId identifica a linha em setor_emails, para o log de e-mails processados por caixa.
     */
    private function verificarCaixa($host, $port, $username, $password, $setorId, $caixaId = null) {
        $this->setorAtualId = $setorId;
        $this->caixaEmailAtualId = $caixaId;
        $log_file = dirname(__DIR__, 2) . '/backend/logs/email-cron.log';

        if (!function_exists('imap_open')) {
            echo "<p style='color: red;'>❌ Extensão PHP IMAP não está habilitada neste servidor.</p>";
            error_log('[EMAIL-SERVICE] Extensão imap não habilitada.');
            return false;
        }

        try {
            // Tentar com novalidate-cert primeiro (Locaweb)
            $mailbox = "{{$host}:{$port}/imap/ssl/novalidate-cert}INBOX";
            echo "<p>Conectando em: $mailbox</p>";
            file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Conectando em: $mailbox\n", FILE_APPEND);

            $inbox = @imap_open($mailbox, $username, $password, 0, 3);

            if (!$inbox) {
                // Tentar sem novalidate-cert
                $mailbox = "{{$host}:{$port}/imap/ssl}INBOX";
                echo "<p>Tentando conexão alternativa: $mailbox</p>";
                file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Tentando conexão alternativa\n", FILE_APPEND);

                $inbox = @imap_open($mailbox, $username, $password);

                if (!$inbox) {
                    $erro = imap_last_error();
                    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] ERRO: $erro\n", FILE_APPEND);
                    throw new Exception('Não foi possível conectar ao email: ' . $erro);
                }
            }

            echo "<p>✅ Conectado com sucesso!</p>";
            file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Conectado com sucesso\n", FILE_APPEND);

                // Buscar emails dos últimos 3 dias para garantir que nenhum foi perdido
                // mesmo se marcado como lido por outro cliente (Outlook/Celular)
                $data_busca = date('d-M-Y', strtotime('-3 days'));
                $search_criteria = 'SINCE "' . $data_busca . '"';

                echo "<p>Buscando emails com critério: $search_criteria</p>";
                file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Buscando emails: $search_criteria\n", FILE_APPEND);

                $emails = imap_search($inbox, $search_criteria, SE_UID);

                echo "<p>Verificando emails recentes...</p>";

                if ($emails) {
                    $count = count($emails);
                    echo "<p>📧 Encontrados $count emails recentes para análise</p>";
                    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Encontrados $count emails\n", FILE_APPEND);

                    // Ordenar para processar dos mais antigos para os mais novos
                    sort($emails);

                    foreach ($emails as $uid) {
                        $msgno = imap_msgno($inbox, $uid);
                    echo "<p>Processando UID: $uid (MSG: $msgno)...</p>";
                    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Processando UID: $uid\n", FILE_APPEND);

                    try {
                        $this->processarEmail($inbox, $msgno, $uid);
                    } catch (Exception $e) {
                        echo "<p style='color: red;'>❌ Erro ao processar email: " . $e->getMessage() . "</p>";
                        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] ERRO ao processar UID $uid: " . $e->getMessage() . "\n", FILE_APPEND);
                        // Não marcar como lido se houver erro
                    }
                }
            } else {
                echo "<p>📄 Nenhum email não lido encontrado</p>";
                file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Nenhum email não lido\n", FILE_APPEND);
                echo "<p>Nenhum email não lido no momento.</p>";
            }

            // Verificar total de emails na caixa
            $total_emails = imap_num_msg($inbox);
            echo "<p>Total de emails na caixa: $total_emails</p>";
            file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Total na caixa: $total_emails\n", FILE_APPEND);

            imap_close($inbox);
            return true;

        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
            error_log('Erro no EmailService: ' . $e->getMessage());
            return false;
        }
    }
    
    private function processarEmail($inbox, $email_number, $uid = null) {
        echo "<p>--- Processando Email #$email_number ---</p>";
        
        $header = imap_headerinfo($inbox, $email_number);
        $structure = imap_fetchstructure($inbox, $email_number);
        
        // Extrair Message-ID para evitar duplicatas
        $message_id = $header->message_id ?? uniqid('email_', true);
        
        // Verificar se já foi processado
        if ($this->emailJaProcessado($message_id)) {
            echo "<p>⏭️ Email já processado anteriormente (Message-ID: $message_id)</p>";
            // Se já foi processado, garantir que esteja marcado como lido
            if ($uid) {
                imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
            }
            return;
        }
        
        // Extrair corpo do email (texto ou HTML)
        $body = $this->extrairCorpoEmail($inbox, $email_number, $structure);
        
        // Limpar HTML e remover conteúdo base64
        $body = strip_tags($body);
        $body = preg_replace('/[A-Za-z0-9+\/]{100,}={0,2}/', '', $body); // Remove blocos base64
        $body = preg_replace('/Content-Type:.*?\n/i', '', $body); // Remove headers MIME
        $body = preg_replace('/Content-Transfer-Encoding:.*?\n/i', '', $body);
        $body = preg_replace('/Content-Disposition:.*?\n/i', '', $body);
        $body = preg_replace('/Content-Id:.*?\n/i', '', $body);
        $body = trim($body);
        
        // Limitar tamanho se necessário
        if (strlen($body) > 10000) {
            $body = substr($body, 0, 10000) . "\n\n[CONTEÚDO TRUNCADO]";
        }
        
        // Extrair dados do email
        $assunto = $this->decodificarTexto($header->subject);
        $remetente = $header->from[0]->mailbox . '@' . $header->from[0]->host;
        $data = date('Y-m-d H:i:s', strtotime($header->date));

        // Verificar se o email deve ser ignorado (evitar loops)
        $emails_ignorados = ['suporte@crprs.org.br', 'suporte2@crprs.org.br'];
        if (in_array(strtolower($remetente), $emails_ignorados)) {
            echo "<p>🚫 Email ignorado (remetente na blacklist): $remetente</p>";
            // Marcar como lido para não processar novamente
            if ($uid) {
                imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
            } else {
                imap_setflag_full($inbox, $email_number, "\\Seen");
            }
            return;
        }
        
        if (empty($body)) {
            $body = "Email recebido em " . date('d/m/Y H:i:s') . " de " . $remetente;
        }
        
        echo "<p>Assunto: $assunto</p>";
        echo "<p>Remetente: $remetente</p>";
        echo "<p>Data: $data</p>";
        
        // Verificar se é resposta a ticket existente
        $ticket_id = $this->extrairTicketId($assunto);
        
        if ($ticket_id) {
            echo "<p>✉️ Resposta ao ticket #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "</p>";
            
            // Processar anexos para comentário
            $anexos_processados = $this->processarAnexosEmail($inbox, $email_number);
            
            $this->adicionarComentario($ticket_id, $body, $remetente, $anexos_processados, $inbox, $email_number);
            $this->registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, 'comentario');
        } else {
            echo "<p>🆕 Criando novo ticket...</p>";
            $ticket_id = $this->criarTicketPorEmail($assunto, $body, $remetente, $inbox, $email_number);
            $this->registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, 'novo_ticket');
        }
        
        // Marcar email como lido APENAS após sucesso
        if ($uid) {
            imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
        } else {
            imap_setflag_full($inbox, $email_number, "\\Seen");
        }
        echo "<p>✅ Email processado e marcado como lido</p>";
    }
    
    private function emailJaProcessado($message_id) {
        try {
            $query = "SELECT id FROM emails_processados WHERE message_id = :message_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':message_id', $message_id);
            $stmt->execute();
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            // Se tabela não existe, retornar false
            return false;
        }
    }
    
    private function registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, $tipo) {
        try {
            $query = "INSERT INTO emails_processados (message_id, email_remetente, assunto, ticket_id, setor_email_id, tipo)
                      VALUES (:message_id, :remetente, :assunto, :ticket_id, :setor_email_id, :tipo)
                      ON DUPLICATE KEY UPDATE tentativas = tentativas + 1";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':message_id', $message_id);
            $stmt->bindParam(':remetente', $remetente);
            $stmt->bindParam(':assunto', $assunto);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->bindValue(':setor_email_id', $this->caixaEmailAtualId, $this->caixaEmailAtualId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':tipo', $tipo);
            $stmt->execute();
            echo "<p>📝 Email registrado no histórico</p>";
        } catch (Exception $e) {
            // Se tabela não existe, apenas logar
            echo "<p>⚠️ Aviso: Não foi possível registrar no histórico</p>";
        }
    }
    
    private function criarTicketPorEmail($assunto, $corpo, $remetente, $inbox, $email_number) {
        try {
            echo "<p>Iniciando criação de ticket...</p>";
            
            // Buscar ou criar usuário
            echo "<p>Buscando/criando usuário para: $remetente</p>";
            $usuario_id = $this->buscarOuCriarUsuario($remetente);
            echo "<p>Usuário ID: $usuario_id</p>";
            
            // Processar anexos e imagens
            echo "<p>Processando anexos e imagens...</p>";
            $resultado_anexos = $this->processarAnexosEmail($inbox, $email_number);
            
            // Limpar corpo do email
            $corpo_limpo = $corpo;
            
            // Adicionar nota sobre anexos se houver
            $total_anexos = count($resultado_anexos['validos']);
            if ($total_anexos > 0) {
                $corpo_limpo .= "\n\n📎 " . $total_anexos . " anexo(s) do email foram salvos.";
            }

            // Safety net: garante UTF-8 válido para evitar erro no banco (ex: emails em iso-8859-1)
            $corpo_limpo = mb_convert_encoding($corpo_limpo, 'UTF-8', 'UTF-8');
            $assunto     = mb_convert_encoding($assunto, 'UTF-8', 'UTF-8');

            // --- INÍCIO VERIFICAÇÃO EXTRA DE DUPLICIDADE (TICKETS) ---
            $dup_ticket_query = "SELECT id FROM tickets 
                               WHERE usuario_id = :usuario_id 
                               AND titulo = :titulo 
                               AND descricao = :descricao 
                               AND criado_em > DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
            $dup_ticket_stmt = $this->db->prepare($dup_ticket_query);
            $dup_ticket_stmt->bindParam(':usuario_id', $usuario_id);
            $dup_ticket_stmt->bindParam(':titulo', $assunto);
            $dup_ticket_stmt->bindParam(':descricao', $corpo_limpo);
            $dup_ticket_stmt->execute();
            
            if ($dup_ticket_stmt->fetch()) {
                echo "<p>⚠️ Ticket ignorado: Detectado ticket duplicado recente (mesmo assunto, corpo e usuário).</p>";
                return;
            }
            // --- FIM VERIFICAÇÃO EXTRA DE DUPLICIDADE ---
            
            // Determinar setor: o da caixa que recebeu o e-mail; caixa legada global cai no setor "Geral"
            $setor_id = $this->setorAtualId;
            if (!$setor_id) {
                $setor_stmt = $this->db->prepare("SELECT id FROM setores WHERE nome = 'Geral' AND ativo = 1 LIMIT 1");
                $setor_stmt->execute();
                $setor_id = $setor_stmt->fetchColumn() ?: null;
            }

            // Criar ticket
            echo "<p>Criando ticket no banco...</p>";
            $query = "INSERT INTO tickets (titulo, descricao, usuario_id, status, prioridade, setor_id)
                     VALUES (:titulo, :descricao, :usuario_id, 'novo', 'normal', :setor_id)";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':titulo', $assunto);
            $stmt->bindParam(':descricao', $corpo_limpo);
            $stmt->bindParam(':usuario_id', $usuario_id);
            $stmt->bindParam(':setor_id', $setor_id);
            $stmt->execute();

            $ticket_id = $this->db->lastInsertId();
            echo "<p>✅ Ticket criado com ID: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "</p>";

            if ($setor_id) {
                $this->db->prepare("INSERT INTO ticket_setor_log (ticket_id, setor_id) VALUES (?, ?)")->execute([$ticket_id, $setor_id]);
            }
            
            // Salvar todos os anexos
            foreach ($resultado_anexos['validos'] as $anexo) {
                $this->salvarAnexo($ticket_id, $anexo);
                echo "<p>✅ Anexo salvo: {$anexo['nome']}</p>";
            }
            
            // Enviar emails
            echo "<p>Enviando emails...</p>";
            error_log("[EMAIL-SERVICE] Iniciando envio de emails para ticket #$ticket_id");
            
            try {
                $sender_path = __DIR__ . '/email-sender.php';
                error_log("[EMAIL-SERVICE] Tentando incluir: $sender_path");
                error_log("[EMAIL-SERVICE] Arquivo existe? " . (file_exists($sender_path) ? 'SIM' : 'NAO'));
                
                require_once $sender_path;
                error_log("[EMAIL-SERVICE] EmailSender incluído com sucesso");
                
                $emailSender = new EmailSender($this->db);
                error_log("[EMAIL-SERVICE] Objeto EmailSender criado");
                
                // Email para o usuário
                error_log("[EMAIL-SERVICE] Tentando enviar email de confirmação para usuário");
                if ($emailSender->enviarEmailNovoTicket($ticket_id, $resultado_anexos['rejeitados'])) {
                    echo "<p>✅ Email enviado para o usuário</p>";
                    error_log("[EMAIL-SERVICE] Email de confirmação enviado com sucesso");
                } else {
                    echo "<p>⚠️ Falha ao enviar email para o usuário</p>";
                    error_log("[EMAIL-SERVICE] FALHA ao enviar email de confirmação");
                }
                
                // Notificação para agentes
                error_log("[EMAIL-SERVICE] Tentando notificar agentes");
                if ($emailSender->notificarAgentesNovoTicket($ticket_id)) {
                    echo "<p>✅ Agentes notificados</p>";
                    error_log("[EMAIL-SERVICE] Agentes notificados com sucesso");
                } else {
                    echo "<p>⚠️ Falha ao notificar agentes</p>";
                    error_log("[EMAIL-SERVICE] FALHA ao notificar agentes");
                }
            } catch (Exception $e) {
                echo "<p>❌ ERRO ao enviar emails: " . $e->getMessage() . "</p>";
                error_log("[EMAIL-SERVICE] ERRO CRITICO: " . $e->getMessage());
                error_log("[EMAIL-SERVICE] Stack trace: " . $e->getTraceAsString());
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro ao criar ticket: " . $e->getMessage() . "</p>";
            error_log('Erro ao criar ticket por email: ' . $e->getMessage());
        }
    }
    
    private function processarAnexosEmail($inbox, $email_number) {
        $anexos_validos = [];
        $anexos_rejeitados = [];
        $tamanho_total = 0;
        $count_anexos = 0;
        
        $structure = imap_fetchstructure($inbox, $email_number);
        
        if (isset($structure->parts)) {
            $this->processarPartesAnexos($inbox, $email_number, $structure->parts, '', $anexos_validos, $anexos_rejeitados, $tamanho_total, $count_anexos);
        }
        
        echo "<p>Total processado: $count_anexos anexos válidos, " . count($anexos_rejeitados) . " rejeitados</p>";
        
        return [
            'validos' => $anexos_validos,
            'rejeitados' => $anexos_rejeitados
        ];
    }
    
    private function processarPartesAnexos($inbox, $email_number, $parts, $prefix, &$anexos_validos, &$anexos_rejeitados, &$tamanho_total, &$count_anexos) {
        $log_file = dirname(__DIR__, 2) . '/backend/logs/email-cron.log';
        
        foreach ($parts as $part_number => $part) {
            $section = $prefix . ($part_number + 1);
            
            // Verificar se é anexo ou imagem
            $is_attachment = false;
            $filename = '';
            
            // Anexo explícito
            if (isset($part->disposition) && (strtolower($part->disposition) === 'attachment' || strtolower($part->disposition) === 'inline')) {
                $is_attachment = true;
            }
            
            // Imagem inline (type 5 = IMAGE)
            if (isset($part->type) && $part->type == 5) {
                $is_attachment = true;
            }
            
            // Extrair nome do arquivo
            $filename = $this->extrairNomeArquivo($part);
            
            // Se tem filename mas não tem disposition, pode ser anexo
            if ($filename && !$is_attachment) {
                $is_attachment = true;
            }
            
            if ($is_attachment) {
                // Se não tem filename, gerar um
                if (!$filename) {
                    if (isset($part->type) && $part->type == 5 && isset($part->subtype)) {
                        $ext = strtolower($part->subtype);
                        if ($ext === 'jpeg') $ext = 'jpg';
                        $filename = 'imagem_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    } else {
                        // Pular se não conseguir determinar o nome
                        continue;
                    }
                }
                
                if ($filename) {
                    echo "<p>Processando anexo: $filename (seção $section)</p>";
                    
                    $attachment = imap_fetchbody($inbox, $email_number, $section);
                    
                    // Decodificar anexo
                    if ($part->encoding == 3) { // Base64
                        $attachment = base64_decode($attachment);
                    } elseif ($part->encoding == 4) { // Quoted-printable
                        $attachment = quoted_printable_decode($attachment);
                    }
                    
                    $extensao = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $tipos_permitidos = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'zip', 'rar'];
                    $tamanho = strlen($attachment);
                    
                    echo "<p>Arquivo: $filename, Tamanho: " . round($tamanho/1024, 2) . "KB, Extensão: $extensao</p>";
                    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [ANEXO] Processando: $filename | Ext: $extensao | Tamanho: " . round($tamanho/1024, 2) . "KB | Count: $count_anexos\n", FILE_APPEND);
                    
                    // Validar tipo
                    if (!in_array($extensao, $tipos_permitidos)) {
                        $anexos_rejeitados[] = ['nome' => $filename, 'motivo' => 'Tipo não permitido'];
                        echo "<p>❌ Rejeitado: tipo não permitido</p>";
                        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [ANEXO] REJEITADO - Tipo não permitido: $extensao\n", FILE_APPEND);
                        continue;
                    }
                    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [ANEXO] Tipo válido\n", FILE_APPEND);
                    
                    // Validar quantidade
                    if ($count_anexos >= 5) {
                        $anexos_rejeitados[] = ['nome' => $filename, 'motivo' => 'Máximo 5 arquivos'];
                        echo "<p>❌ Rejeitado: máximo 5 arquivos</p>";
                        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [ANEXO] REJEITADO - Máximo 5 arquivos (atual: $count_anexos)\n", FILE_APPEND);
                        continue;
                    }
                    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [ANEXO] Quantidade OK ($count_anexos/5)\n", FILE_APPEND);
                    
                    // Validar tamanho
                    if ($tamanho_total + $tamanho > 20 * 1024 * 1024) { // 20MB
                        $anexos_rejeitados[] = ['nome' => $filename, 'motivo' => 'Tamanho excede 20MB'];
                        echo "<p>❌ Rejeitado: tamanho excede 20MB</p>";
                        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [ANEXO] REJEITADO - Tamanho excede 20MB (atual: " . round(($tamanho_total + $tamanho)/1024/1024, 2) . "MB)\n", FILE_APPEND);
                        continue;
                    }
                    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [ANEXO] Tamanho OK\n", FILE_APPEND);
                    
                    // Anexo válido
                    $tipo_mime = $this->obterTipoMime($extensao);
                    $anexos_validos[] = [
                        'nome' => $filename,
                        'conteudo' => $attachment,
                        'tamanho' => $tamanho,
                        'tipo' => $tipo_mime
                    ];
                    
                    $tamanho_total += $tamanho;
                    $count_anexos++;
                    echo "<p>✅ Anexo aceito</p>";
                    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [ANEXO] ACEITO: $filename | MIME: $tipo_mime\n", FILE_APPEND);
                }
            }
            
            // Processar subpartes recursivamente
            if (isset($part->parts)) {
                $this->processarPartesAnexos($inbox, $email_number, $part->parts, $section . '.', $anexos_validos, $anexos_rejeitados, $tamanho_total, $count_anexos);
            }
        }
    }
    
    private function obterTipoMime($extensao) {
        $tipos = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed'
        ];
        
        return $tipos[$extensao] ?? 'application/octet-stream';
    }
    
    private function extrairNomeArquivo($part) {
        $filename = '';
        
        // Tentar dparameters primeiro
        if (isset($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    $filename = $param->value;
                    break;
                }
            }
        }
        
        // Se não encontrou, tentar parameters
        if (!$filename && isset($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    $filename = $param->value;
                    break;
                }
            }
        }
        
        // Decodificar nome do arquivo se estiver em MIME encoded-word
        if ($filename) {
            $filename = $this->decodificarTexto($filename);
        }
        
        return $filename;
    }
    
    private function salvarAnexo($ticket_id, $anexo, $comentario_id = null) {
        $upload_dir = dirname(__DIR__, 2) . '/backend/anexos/';
        
        // Log de debug
        error_log("[ANEXO SAVE] Ticket ID: $ticket_id");
        error_log("[ANEXO SAVE] Upload dir: $upload_dir");
        error_log("[ANEXO SAVE] Dir existe? " . (is_dir($upload_dir) ? 'SIM' : 'NAO'));
        
        if (!is_dir($upload_dir)) {
            error_log("[ANEXO SAVE] Criando diretório...");
            if (mkdir($upload_dir, 0755, true)) {
                error_log("[ANEXO SAVE] Diretório criado com sucesso");
                echo "<p>📁 Pasta criada: $upload_dir</p>";
            } else {
                error_log("[ANEXO SAVE] ERRO ao criar diretório!");
                echo "<p>❌ Erro ao criar pasta: $upload_dir</p>";
                return;
            }
        }
        
        $nome_arquivo = time() . '_email_' . $anexo['nome'];
        $caminho_completo = $upload_dir . $nome_arquivo;
        
        error_log("[ANEXO SAVE] Nome arquivo: $nome_arquivo");
        error_log("[ANEXO SAVE] Caminho completo: $caminho_completo");
        error_log("[ANEXO SAVE] Tamanho conteúdo: " . strlen($anexo['conteudo']) . " bytes");
        
        echo "<p>💾 Salvando em: $caminho_completo</p>";
        
        $bytes_escritos = file_put_contents($caminho_completo, $anexo['conteudo']);
        if ($bytes_escritos !== false) {
            error_log("[ANEXO SAVE] Arquivo salvo: $bytes_escritos bytes");
            echo "<p>✅ Arquivo salvo fisicamente ($bytes_escritos bytes)</p>";
            
            $query = "INSERT INTO anexos (ticket_id, comentario_id, nome_original, nome_arquivo, tamanho, tipo) 
                     VALUES (:ticket_id, :comentario_id, :nome_original, :nome_arquivo, :tamanho, :tipo)";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->bindParam(':comentario_id', $comentario_id);
            $stmt->bindParam(':nome_original', $anexo['nome']);
            $stmt->bindParam(':nome_arquivo', $nome_arquivo);
            $stmt->bindParam(':tamanho', $anexo['tamanho']);
            $stmt->bindParam(':tipo', $anexo['tipo']);
            $stmt->execute();
            
            $anexo_id = $this->db->lastInsertId();
            error_log("[ANEXO SAVE] Registro no banco criado (ID: $anexo_id)");
            echo "<p>✅ Registro no banco criado (ID: $anexo_id)</p>";
        } else {
            $error = error_get_last();
            error_log("[ANEXO SAVE] ERRO ao salvar arquivo!");
            error_log("[ANEXO SAVE] Erro PHP: " . print_r($error, true));
            echo "<p>❌ Falha ao salvar arquivo fisicamente</p>";
            echo "<p>Erro: " . ($error['message'] ?? 'Desconhecido') . "</p>";
        }
    }
    
    private function buscarOuCriarUsuario($email) {
        // Buscar usuário existente
        $query = "SELECT id FROM usuarios WHERE email = :email";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $usuario = $stmt->fetch();
        
        if ($usuario) {
            return $usuario['id'];
        }
        
        // Criar novo usuário - verificar valores ENUM permitidos
        $nome = explode('@', $email)[0];
        $tipo = 'cliente'; // Valor padrão ENUM: cliente, agente, admin
        
        $query = "INSERT INTO usuarios (nome, email, tipo) VALUES (:nome, :email, :tipo)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->execute();
        
        return $this->db->lastInsertId();
    }
    
    private function extrairTicketId($assunto) {
        // Buscar padrões: [#12345], #00156, ticket 12345, etc.
        // Priorizar #00156 (5 dígitos) sobre outros padrões
        if (preg_match('/#(\d{5})/', $assunto, $matches)) {
            return (int)$matches[1];
        }
        // Fallback para outros padrões
        if (preg_match('/\[#(\d+)\]|ticket\s+(\d+)/i', $assunto, $matches)) {
            return (int)($matches[1] ?? $matches[2]);
        }
        return null;
    }
    
    private function adicionarComentario($ticket_id, $comentario, $email, $anexos_processados = [], $inbox = null, $email_number = null) {
        echo "<p>Adicionando comentário ao ticket #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "</p>";
        
        // Verificar se ticket está fechado - se sim, reabrir
        $this->verificarEReabrirTicket($ticket_id);
        
        $usuario_id = $this->buscarOuCriarUsuario($email);
        
        // Limpar conteúdo do comentário
        $comentario = trim($comentario);
        if (strlen($comentario) > 5000) {
            $comentario = substr($comentario, 0, 5000) . "\n\n[CONTEÚDO TRUNCADO]";
        }
        
        // Adicionar nota sobre anexos
        $total_anexos = count($anexos_processados['validos']);
        if ($total_anexos > 0) {
            $comentario .= "\n\n📎 " . $total_anexos . " anexo(s) incluído(s) neste comentário.";
        }
        
        // --- INÍCIO VERIFICAÇÃO EXTRA DE DUPLICIDADE ---
        // Verificar se já existe um comentário IDÊNTICO deste usuário neste ticket nos últimos 5 minutos
        // Isso previne duplicatas caso o Message-ID tenha mudado mas o conteúdo seja o mesmo (ex: loop de email)
        $duplicado_query = "SELECT id FROM comentarios 
                          WHERE ticket_id = :ticket_id 
                          AND usuario_id = :usuario_id 
                          AND comentario = :comentario 
                          AND criado_em > DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
        $duplicado_stmt = $this->db->prepare($duplicado_query);
        $duplicado_stmt->bindParam(':ticket_id', $ticket_id);
        $duplicado_stmt->bindParam(':usuario_id', $usuario_id);
        $duplicado_stmt->bindParam(':comentario', $comentario);
        $duplicado_stmt->execute();
        
        if ($duplicado_stmt->fetch()) {
            echo "<p>⚠️ Comentário ignorado: Detectado comentário duplicado recente (mesmo conteúdo e usuário).</p>";
            // Se já existe, não insere novamente.
            return;
        }
        // --- FIM VERIFICAÇÃO EXTRA DE DUPLICIDADE ---

        $query = "INSERT INTO comentarios (ticket_id, usuario_id, comentario) 
                 VALUES (:ticket_id, :usuario_id, :comentario)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':comentario', $comentario);
        $stmt->execute();
        
        $comentario_id = $this->db->lastInsertId();
        echo "<p>✅ Comentário adicionado ao ticket #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "</p>";
        
        // Salvar todos os anexos
        foreach ($anexos_processados['validos'] as $anexo) {
            $this->salvarAnexo($ticket_id, $anexo, $comentario_id);
            echo "<p>✅ Anexo salvo: {$anexo['nome']}</p>";
        }
        
        // Notificar sobre novo comentário
        require_once __DIR__ . '/email-sender.php';
        $emailSender = new EmailSender($this->db);
        $emailSender->notificarNovoComentario($ticket_id, $comentario_id);
        $emailSender->confirmarComentarioParaRemetente($ticket_id, $email, $anexos_processados['rejeitados']);

        // Atualizar status para 'nova_resposta' se não estiver fechado/reaberto agora
        // Se foi reaberto em verificarEReabrirTicket, o status já é reaberto, então não mudamos para nova_resposta imediatamente (opcional, mas o usuário pediu "Nova Resposta")
        // Vamos forçar 'nova_resposta' se não estiver fechado, pois é um comentário do solicitante
        $status_atual_query = "SELECT status, agente_id FROM tickets WHERE id = ?";
        $st_stmt = $this->db->prepare($status_atual_query);
        $st_stmt->execute([$ticket_id]);
        $ticket_atual = $st_stmt->fetch();

        // Se o email é do solicitante (e não do agente), mudar para nova_resposta
        // Como adicionarComentario é usado pelo IMAP, assume-se que é o solicitante ou terceiro respondendo
        // Verificar se quem respondeu NÃO é o agente do ticket
        if ($ticket_atual) {
            $is_agente = false;
            if ($ticket_atual['agente_id']) {
                 $ag_q = "SELECT email FROM usuarios WHERE id = ?";
                 $ag_s = $this->db->prepare($ag_q);
                 $ag_s->execute([$ticket_atual['agente_id']]);
                 $ag_email = $ag_s->fetchColumn();
                 if ($ag_email === $email) {
                     $is_agente = true;
                 }
            }
            
            // Só muda status se NÃO for o agente e status não for final
            if (!$is_agente && !in_array($ticket_atual['status'], ['fechado', 'encerrado', 'cancelado'])) {
                if ($ticket_atual['status'] !== 'nova_resposta') {
                    $up_q = "UPDATE tickets SET status = 'nova_resposta' WHERE id = ?";
                    $this->db->prepare($up_q)->execute([$ticket_id]);
                    
                    // Historico
                    $hist_q = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'status', ?, 'nova_resposta')";
                    $this->db->prepare($hist_q)->execute([$ticket_id, $usuario_id, $ticket_atual['status']]);
                    
                    echo "<p>🔄 Status atualizado para 'nova_resposta'</p>";
                }
            }
        }
    }
    
    private function verificarEReabrirTicket($ticket_id) {
        // Buscar status atual do ticket
        $query = "SELECT t.*, u.email as usuario_email FROM tickets t LEFT JOIN usuarios u ON t.usuario_id = u.id WHERE t.id = :ticket_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->execute();
        $ticket = $stmt->fetch();
        
        if ($ticket && ($ticket['status'] === 'fechado' || $ticket['status'] === 'encerrado')) {
            echo "<p>🔄 Reabrindo ticket fechado...</p>";
            
            // Reabrir ticket
            $update_query = "UPDATE tickets SET status = 'reaberto', fechado_por = NULL, fechado_em = NULL WHERE id = :ticket_id";
            $update_stmt = $this->db->prepare($update_query);
            $update_stmt->bindParam(':ticket_id', $ticket_id);
            $update_stmt->execute();
            
            // Registrar no histórico
            $hist_query = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (:ticket_id, :usuario_id, 'status', :valor_anterior, 'reaberto')";
            $hist_stmt = $this->db->prepare($hist_query);
            $hist_stmt->bindParam(':ticket_id', $ticket_id);
            $hist_stmt->bindParam(':usuario_id', $ticket['usuario_id']);
            $hist_stmt->bindParam(':valor_anterior', $ticket['status']);
            $hist_stmt->execute();

            // Reabre as janelas de passagem por setor/responsável (fechadas no encerramento)
            AcessoService::reabrirJanelasDaDemanda(
                $this->db,
                (int) $ticket_id,
                !empty($ticket['setor_id']) ? (int) $ticket['setor_id'] : null,
                !empty($ticket['agente_id']) ? (int) $ticket['agente_id'] : null
            );
            
            echo "<p>✅ Ticket reaberto automaticamente</p>";
            
            // Enviar notificações
            try {
                require_once __DIR__ . '/email-sender.php';
                $emailSender = new EmailSender($this->db);
                
                // Notificar solicitante
                $emailSender->notificarReaberturaParaSolicitante($ticket_id);
                
                // Notificar agente responsável
                $emailSender->notificarReaberturaTicket($ticket_id);
                
                echo "<p>✅ Notificações de reabertura enviadas</p>";
            } catch (Exception $e) {
                echo "<p>⚠️ Erro ao enviar notificações: " . $e->getMessage() . "</p>";
                error_log('Erro ao notificar reabertura: ' . $e->getMessage());
            }
        }
    }
    
    private function enviarConfirmacao($email, $ticket_id, $anexos_rejeitados) {
        $assunto = "[#" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "] Demanda criada - Conecta CRPRS";

        $mensagem = "Sua demanda foi criada com sucesso!\n\n";
        $mensagem .= "Número da demanda: #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "\n\n";
        
        if (!empty($anexos_rejeitados)) {
            $mensagem .= "ATENÇÃO: Alguns anexos foram rejeitados:\n\n";
            foreach ($anexos_rejeitados as $anexo) {
                $mensagem .= "- {$anexo['nome']}: {$anexo['motivo']}\n";
            }
            $mensagem .= "\nLimites para anexos:\n";
            $mensagem .= "- Máximo 5 arquivos\n";
            $mensagem .= "- Tamanho total máximo: 20MB\n";
            $mensagem .= "- Tipos aceitos: PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, PNG, JPG, ZIP, RAR\n\n";
            $mensagem .= "Você pode responder a este email com novos anexos dentro dos limites.\n\n";
        }
        
        $mensagem .= "Para acompanhar o andamento, responda a este email.\n\n";
        $mensagem .= "Atenciosamente,\nEquipe Conecta CRPRS";
        
        // Configurar SMTP antes de enviar
        ini_set('SMTP', EmailConfig::SMTP_HOST);
        ini_set('smtp_port', EmailConfig::SMTP_PORT);
        ini_set('sendmail_from', EmailConfig::FROM_EMAIL);
        
        $headers = "From: " . EmailConfig::FROM_NAME . " <" . EmailConfig::FROM_EMAIL . ">\r\n";
        $headers .= "Reply-To: " . EmailConfig::FROM_EMAIL . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        if (mail($email, $assunto, $mensagem, $headers)) {
            echo "<p>✅ Confirmação enviada para: $email</p>";
        } else {
            echo "<p>⚠️ Não foi possível enviar confirmação para: $email</p>";
        }
    }
    
    
    private function extrairCorpoEmail($inbox, $email_number, $structure) {
        $body = '';
        
        // Tentar extrair parte HTML (1.2 ou 1)
        if (isset($structure->parts)) {
            // Multipart
            foreach ($structure->parts as $part_number => $part) {
                // Pular anexos
                if (isset($part->disposition) && (strtolower($part->disposition) === 'attachment' || strtolower($part->disposition) === 'inline')) {
                    continue;
                }
                
                // Pular imagens
                if (isset($part->type) && $part->type == 5) {
                    continue;
                }
                
                // Buscar texto/html ou text/plain
                if (isset($part->subtype)) {
                    if (strtolower($part->subtype) === 'html' || strtolower($part->subtype) === 'plain') {
                        $section = $part_number + 1;
                        $body = imap_fetchbody($inbox, $email_number, $section);
                        
                        // Decodificar transfer encoding
                        if (isset($part->encoding)) {
                            if ($part->encoding == 3) {
                                $body = base64_decode($body);
                            } elseif ($part->encoding == 4) {
                                $body = quoted_printable_decode($body);
                            }
                        }

                        // Converter charset para UTF-8 (ex: iso-8859-1 → utf-8)
                        $body = $this->converterParaUtf8($body, $this->detectarCharset($part));

                        if (!empty($body)) {
                            // Outlook costuma enviar HTML vazio ou com apenas tags básicas
                            // Verificar se tem conteúdo real
                            $check_content = trim(strip_tags($body));
                            if (!empty($check_content) || strpos($body, '<img') !== false) {
                                break;
                            }
                        }
                    }
                }
                
                // Processar subpartes
                if (isset($part->parts) && empty($body)) {
                    $body = $this->extrairCorpoDeSubpartes($inbox, $email_number, $part->parts, ($part_number + 1) . '.');
                }
            }
        } else {
            // Email simples
            $body = imap_body($inbox, $email_number);
        }
        if (isset($structure->encoding)) {
            if ($structure->encoding == 3) {
                $body = base64_decode($body);
            } elseif ($structure->encoding == 4) {
                $body = quoted_printable_decode($body);
            }
        }

        // Verificação extra para Base64 não detectado (comum em alguns clientes de email mal formatados)
        // Se o corpo parece ser um bloco contínuo de Base64 e decodifica para HTML válido
        if (!empty($body) && !str_contains($body, ' ') && strlen($body) > 50) {
            $decoded_check = base64_decode($body, true);
            if ($decoded_check && (str_contains(strtolower($decoded_check), '<html') || str_contains(strtolower($decoded_check), '<div') || str_contains(strtolower($decoded_check), '<body'))) {
                $body = $decoded_check;
            }
        }
        
        return $body;
    }
    
    private function extrairCorpoDeSubpartes($inbox, $email_number, $parts, $prefix) {
        foreach ($parts as $part_number => $part) {
            // Pular anexos e imagens
            if (isset($part->disposition) || (isset($part->type) && $part->type == 5)) {
                continue;
            }
            
            if (isset($part->subtype) && (strtolower($part->subtype) === 'html' || strtolower($part->subtype) === 'plain')) {
                $section = $prefix . ($part_number + 1);
                $body = imap_fetchbody($inbox, $email_number, $section);
                
                if (isset($part->encoding)) {
                    if ($part->encoding == 3) {
                        $body = base64_decode($body);
                    } elseif ($part->encoding == 4) {
                        $body = quoted_printable_decode($body);
                    }
                }

                // Converter charset para UTF-8
                $body = $this->converterParaUtf8($body, $this->detectarCharset($part));

                if (!empty($body)) {
                    return $body;
                }
            }
        }
        return '';
    }
    
    // Detecta o charset declarado nos parâmetros MIME de uma parte do e-mail
    private function detectarCharset($part): string {
        if (isset($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'charset' && !empty($param->value)) {
                    return $param->value;
                }
            }
        }
        return 'UTF-8';
    }

    // Converte texto de qualquer charset para UTF-8 válido
    private function converterParaUtf8(string $texto, string $charset = 'UTF-8'): string {
        $charset = strtoupper(trim($charset));
        // Normaliza aliases comuns
        if (in_array($charset, ['UTF-8', 'UTF8', 'UTF_8'])) {
            // Já é UTF-8 mas garante que sequências inválidas sejam corrigidas
            return mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        }
        $convertido = @mb_convert_encoding($texto, 'UTF-8', $charset);
        return ($convertido !== false && $convertido !== '') ? $convertido : $texto;
    }

    private function decodificarTexto($texto) {
        $decoded = mb_decode_mimeheader($texto);
        return $decoded;
    }
}
?>