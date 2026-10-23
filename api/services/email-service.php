<?php
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/html-sanitizer.php';
require_once __DIR__ . '/log-service.php';
date_default_timezone_set('America/Sao_Paulo');

class EmailService {
    private $host;
    private $port;
    private $username;
    private $password;
    private $db;
    private $setorAtualId = null; // setor da caixa sendo processada no momento (null = caixa legada global)
    private $caixaEmailAtualId = null; // id em setor_emails da caixa sendo processada (null = caixa legada global)
    private $diasBusca = 3; // janela de busca na caixa (dias)
    private $enderecosDoSistema = null; // caixas do próprio sistema (nunca viram demanda)
    const MAX_TENTATIVAS = 5; // falhas seguidas antes de desistir de um e-mail

    /**
     * Mascara o e-mail para log (regra de segurança do projeto: nunca logar dado pessoal em
     * texto puro). O log de e-mail-cron é um arquivo em disco, fora do controle de acesso por
     * setor/papel do sistema — mantém só o domínio, suficiente para depurar.
     */
    private static function mascararEmail(string $email): string {
        $arroba = strpos($email, '@');
        if ($arroba === false) {
            return '(sem @)';
        }
        return substr($email, 0, 1) . '***@' . substr($email, $arroba + 1);
    }

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
    public function verificarEmails(int $dias = 3) {
        require_once __DIR__ . '/crypto-service.php';
        $this->diasBusca = max(1, min(60, $dias));

        $caixas = [];
        try {
            $stmt = $this->db->query("SELECT id, setor_id, imap_host, imap_port, imap_user, imap_pass_cifrada, validar_certificado FROM setor_emails WHERE ativo = 1");
            $caixas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            LogService::erro('email-cron', 'Erro ao buscar caixas de e-mail por setor: ' . $e->getMessage());
        }

        if (empty($caixas)) {
            LogService::info('email-cron', 'Nenhuma caixa de e-mail por setor cadastrada — usando caixa global legada (.env).');
            $validarGlobal = !in_array(strtolower(trim((string) Environment::get('imap_validar_certificado', '1'))), ['0', 'false', 'nao', 'não', 'off'], true);
            return $this->verificarCaixa($this->host, $this->port, $this->username, $this->password, null, null, $validarGlobal);
        }

        $sucessoGeral = true;
        foreach ($caixas as $caixa) {
            try {
                $senha = CryptoService::decrypt($caixa['imap_pass_cifrada']);
            } catch (Exception $e) {
                LogService::erro('email-cron', "Não foi possível decifrar a senha da caixa #{$caixa['id']}: " . $e->getMessage());
                $sucessoGeral = false;
                continue;
            }

            LogService::info('email-cron', "=== Verificando caixa do setor #{$caixa['setor_id']} ({$caixa['imap_user']}) ===");
            $ok = $this->verificarCaixa($caixa['imap_host'], (int) $caixa['imap_port'], $caixa['imap_user'], $senha, (int) $caixa['setor_id'], (int) $caixa['id'], (int) $caixa['validar_certificado'] === 1);
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
    private function verificarCaixa($host, $port, $username, $password, $setorId, $caixaId = null, bool $validarCertificado = true) {
        $this->setorAtualId = $setorId;
        $this->caixaEmailAtualId = $caixaId;

        if (!function_exists('imap_open')) {
            LogService::erro('email-cron', 'Extensão PHP IMAP não está habilitada neste servidor.');
            return false;
        }

        try {
            // Certificado do servidor validado por padrão; só é dispensado por decisão explícita da caixa
            $mailbox = "{{$host}:{$port}/imap/ssl" . ($validarCertificado ? '' : '/novalidate-cert') . "}INBOX";
            LogService::info('email-cron', "Conectando em: $mailbox");

            $inbox = @imap_open($mailbox, $username, $password, 0, 3);

            if (!$inbox) {
                $erro = imap_last_error();
                LogService::erro('email-cron', "ERRO: $erro");
                throw new Exception('Não foi possível conectar ao email: ' . $erro);
            }

            LogService::info('email-cron', 'Conectado com sucesso');

                // Buscar emails dos últimos N dias (padrão 3) para garantir que nenhum foi perdido
                // mesmo se marcado como lido por outro cliente (Outlook/Celular); o Message-ID evita duplicar
                $data_busca = date('d-M-Y', strtotime('-' . $this->diasBusca . ' days'));
                $search_criteria = 'SINCE "' . $data_busca . '"';

                LogService::info('email-cron', "Buscando emails: $search_criteria");

                $emails = imap_search($inbox, $search_criteria, SE_UID);

                if ($emails) {
                    $count = count($emails);
                    LogService::info('email-cron', "Encontrados $count emails recentes para análise");

                    // Ordenar para processar dos mais antigos para os mais novos
                    sort($emails);

                    foreach ($emails as $uid) {
                        $msgno = imap_msgno($inbox, $uid);
                    LogService::info('email-cron', "Processando UID: $uid (MSG: $msgno)");

                    try {
                        $this->processarEmail($inbox, $msgno, $uid);
                    } catch (Exception $e) {
                        LogService::erro('email-cron', "Erro ao processar UID $uid: " . $e->getMessage());
                        // Não marcar como lido se houver erro
                    }
                }
            } else {
                LogService::info('email-cron', 'Nenhum email não lido encontrado');
            }

            // Verificar total de emails na caixa
            $total_emails = imap_num_msg($inbox);
            LogService::info('email-cron', "Total na caixa: $total_emails");

            imap_close($inbox);
            return true;

        } catch (Exception $e) {
            LogService::erro('email-cron', 'Erro: ' . $e->getMessage());
            error_log('Erro no EmailService: ' . $e->getMessage());
            return false;
        }
    }
    
    private function processarEmail($inbox, $email_number, $uid = null) {
        LogService::info('email-cron', "--- Processando Email #$email_number ---");

        $header = imap_headerinfo($inbox, $email_number);
        $structure = imap_fetchstructure($inbox, $email_number);

        // Message-ID evita duplicatas. Sem ele, usa uma identidade estável da mensagem
        // (um id aleatório faria o mesmo e-mail virar demanda nova a cada execução)
        $message_id = $header->message_id ?? null;
        if (!$message_id) {
            $message_id = 'sem-id-' . sha1(($header->date ?? '') . '|' . ($header->fromaddress ?? '') . '|' . ($header->subject ?? '') . '|' . ($this->caixaEmailAtualId ?? 'global'));
        }

        $marcarLido = function () use ($inbox, $uid, $email_number) {
            if ($uid) {
                imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
            } else {
                imap_setflag_full($inbox, $email_number, "\\Seen");
            }
        };

        // Verificar se já foi processado
        if ($this->emailJaProcessado($message_id)) {
            LogService::info('email-cron', "Email já processado anteriormente (Message-ID: $message_id)");
            $marcarLido();
            return;
        }

        // Dados do remetente e assunto (o assunto vira o título: texto puro, sem tags)
        $remetente = strtolower(trim(($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? '')));
        $assunto = HtmlSanitizer::texto($this->decodificarTexto($header->subject ?? ''), 200);
        if ($assunto === '') {
            $assunto = '(sem assunto)';
        }

        // Caixas do próprio sistema e respostas automáticas nunca viram demanda (evita laços)
        $motivo_ignorar = $this->motivoParaIgnorar($inbox, $email_number, $remetente);
        if ($motivo_ignorar !== null) {
            LogService::info('email-cron', "Email ignorado ($motivo_ignorar): " . self::mascararEmail($remetente));
            $this->registrarEmailProcessado($message_id, $remetente, $assunto, null, 'ignorado', $motivo_ignorar);
            $marcarLido();
            return;
        }

        // Extrair corpo do email (texto ou HTML) e reduzir a texto
        $body = $this->extrairCorpoEmail($inbox, $email_number, $structure);
        $body = strip_tags($body);
        $body = preg_replace('/[A-Za-z0-9+\/]{100,}={0,2}/', '', $body); // Remove blocos base64
        $body = preg_replace('/Content-Type:.*?\n/i', '', $body); // Remove headers MIME
        $body = preg_replace('/Content-Transfer-Encoding:.*?\n/i', '', $body);
        $body = preg_replace('/Content-Disposition:.*?\n/i', '', $body);
        $body = preg_replace('/Content-Id:.*?\n/i', '', $body);
        $body = html_entity_decode(trim($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (mb_strlen($body) > 10000) {
            $body = mb_substr($body, 0, 10000) . "\n\n[CONTEÚDO TRUNCADO]";
        }
        if ($body === '') {
            $body = "Email recebido em " . date('d/m/Y H:i:s') . " de " . $remetente;
        }
        // Corpo vindo de fora: gravado como texto escapado, preservando as quebras de linha
        $body = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'), false);

        // Assunto não entra no log (texto livre, pode conter dado pessoal); remetente mascarado
        LogService::info('email-cron', 'Remetente: ' . self::mascararEmail($remetente));

        // Resposta a uma demanda existente? Só vira comentário se o remetente participa da demanda
        // e a caixa é de um setor por onde ela passou; senão, abre uma demanda nova.
        $ticket_id = $this->extrairTicketId($assunto);
        $observacao = null;

        try {
            $recusa = $ticket_id ? $this->motivoRecusaComentario((int) $ticket_id, $remetente) : null;

            if ($ticket_id && $recusa === null) {
                LogService::info('email-cron', 'Resposta à demanda #' . str_pad($ticket_id, 5, '0', STR_PAD_LEFT));
                $anexos_processados = $this->processarAnexosEmail($inbox, $email_number);
                $this->adicionarComentario($ticket_id, $body, $remetente, $anexos_processados, $inbox, $email_number);
                $tipo = 'comentario';
            } else {
                if ($ticket_id) {
                    $observacao = 'Mencionou a demanda #' . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ', mas ' . $recusa . '; aberta demanda nova.';
                    $body = '<p><em>' . htmlspecialchars($observacao, ENT_QUOTES, 'UTF-8') . '</em></p>' . $body;
                    LogService::aviso('email-cron', $observacao);
                }
                LogService::info('email-cron', 'Criando nova demanda...');
                $ticket_id = $this->criarTicketPorEmail($assunto, $body, $remetente, $inbox, $email_number);
                $tipo = 'novo_ticket';
            }
        } catch (Exception $e) {
            // Não marca como lido nem como processado: tenta de novo na próxima execução
            $tentativas = $this->registrarFalha($message_id, $remetente, $assunto, $e->getMessage());
            LogService::erro('email-cron', 'Falha ao processar e-mail de ' . self::mascararEmail($remetente) . " (tentativa $tentativas): " . $e->getMessage());
            if ($tentativas >= self::MAX_TENTATIVAS) {
                $this->registrarEmailProcessado($message_id, $remetente, $assunto, null, 'erro', 'Desistiu após ' . $tentativas . ' tentativas: ' . mb_substr($e->getMessage(), 0, 180));
                $marcarLido();
            }
            return;
        }

        $this->registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, $tipo, $observacao);

        // Marcar email como lido APENAS após sucesso
        $marcarLido();
        LogService::info('email-cron', 'Email processado e marcado como lido');
    }

    /**
     * Endereços que pertencem ao próprio sistema (caixas dos setores e remetente global).
     */
    private function enderecosDoSistema(): array {
        if ($this->enderecosDoSistema === null) {
            $lista = ['suporte@crprs.org.br', 'suporte2@crprs.org.br'];
            foreach (['FROM_EMAIL', 'SMTP_USERNAME', 'IMAP_USERNAME'] as $chave) {
                $valor = EmailConfig::get($chave);
                if ($valor) {
                    $lista[] = $valor;
                }
            }
            try {
                foreach ($this->db->query("SELECT email, imap_user, smtp_user FROM setor_emails")->fetchAll(PDO::FETCH_ASSOC) as $cx) {
                    $lista[] = $cx['email'];
                    $lista[] = $cx['imap_user'];
                    $lista[] = $cx['smtp_user'];
                }
            } catch (Exception $e) {
                error_log('[EMAIL-SERVICE] Não foi possível listar as caixas dos setores: ' . $e->getMessage());
            }
            $this->enderecosDoSistema = array_values(array_unique(array_filter(array_map(function ($e) {
                return strtolower(trim((string) $e));
            }, $lista))));
        }
        return $this->enderecosDoSistema;
    }

    /**
     * Motivo para ignorar o e-mail (null = processar): remetente do próprio sistema ou mensagem automática.
     */
    private function motivoParaIgnorar($inbox, $email_number, string $remetente): ?string {
        if ($remetente === '@' || $remetente === '') {
            return 'remetente ausente';
        }
        if (in_array($remetente, $this->enderecosDoSistema(), true)) {
            return 'remetente é uma caixa do próprio sistema';
        }
        $cabecalho = (string) imap_fetchheader($inbox, $email_number);
        if (preg_match('/^Auto-Submitted:\s*(?!no\b)\S+/im', $cabecalho)
            || preg_match('/^X-(Autoreply|Autorespond|Autoresponder|Auto-Reply):/im', $cabecalho)) {
            return 'resposta automática';
        }
        if (preg_match('/^Precedence:\s*(bulk|junk|auto_reply)\b/im', $cabecalho)) {
            return 'mensagem automática ou em massa';
        }
        return null;
    }

    /**
     * Motivo para NÃO aceitar o e-mail como comentário na demanda (null = aceitar).
     * Aceita se: a demanda existe; a caixa é global ou de um setor por onde a demanda passou;
     * e o remetente é o solicitante, alguém que recebeu cópia, ou a equipe do setor atual.
     */
    private function motivoRecusaComentario(int $ticket_id, string $remetente): ?string {
        $stmt = $this->db->prepare("SELECT t.id, t.setor_id, t.email_solicitante, t.excluido_em, u.email AS usuario_email
                                    FROM tickets t LEFT JOIN usuarios u ON u.id = t.usuario_id
                                    WHERE t.id = ?");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket || !empty($ticket['excluido_em'])) {
            return 'a demanda não existe ou foi excluída';
        }

        if ($this->setorAtualId !== null && (int) $ticket['setor_id'] !== (int) $this->setorAtualId) {
            $passou = $this->db->prepare("SELECT 1 FROM ticket_setor_log WHERE ticket_id = ? AND setor_id = ? LIMIT 1");
            $passou->execute([$ticket_id, $this->setorAtualId]);
            if (!$passou->fetchColumn()) {
                return 'a demanda nunca passou pelo setor desta caixa';
            }
        }

        $participantes = [strtolower((string) $ticket['email_solicitante']), strtolower((string) $ticket['usuario_email'])];
        if (in_array($remetente, $participantes, true)) {
            return null;
        }

        // Quem recebeu cópia de alguma resposta da demanda
        $copias = $this->db->prepare("SELECT valor_novo FROM ticket_historico WHERE ticket_id = ? AND acao = 'email_copia'");
        $copias->execute([$ticket_id]);
        foreach ($copias->fetchAll(PDO::FETCH_COLUMN) as $texto) {
            preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $texto, $m);
            if (in_array($remetente, array_map('strtolower', $m[0]), true)) {
                return null;
            }
        }

        // Equipe interna ativa do setor atual da demanda (ou administradores)
        $equipe = $this->db->prepare("SELECT 1 FROM usuarios WHERE LOWER(email) = ? AND ativo = 1
                                      AND (tipo = 'admin' OR (tipo IN ('operador','gestor') AND setor_id = ?)) LIMIT 1");
        $equipe->execute([$remetente, (int) $ticket['setor_id']]);
        if ($equipe->fetchColumn()) {
            return null;
        }

        return 'o remetente não participa dela';
    }

    /**
     * Registra a falha no processamento de um e-mail e devolve quantas tentativas já houve.
     */
    private function registrarFalha(string $message_id, string $remetente, string $assunto, string $erro): int {
        try {
            $sel = $this->db->prepare("SELECT id, tentativas FROM emails_erro WHERE message_id = ? ORDER BY id DESC LIMIT 1");
            $sel->execute([$message_id]);
            $linha = $sel->fetch(PDO::FETCH_ASSOC);
            if ($linha) {
                $this->db->prepare("UPDATE emails_erro SET tentativas = tentativas + 1, erro_mensagem = ?, ultima_tentativa = NOW() WHERE id = ?")
                         ->execute([mb_substr($erro, 0, 2000), $linha['id']]);
                return (int) $linha['tentativas'] + 1;
            }
            $this->db->prepare("INSERT INTO emails_erro (message_id, email_remetente, assunto, erro_mensagem) VALUES (?, ?, ?, ?)")
                     ->execute([$message_id, $remetente, $assunto, mb_substr($erro, 0, 2000)]);
        } catch (Exception $e) {
            error_log('[EMAIL-SERVICE] Não foi possível registrar a falha: ' . $e->getMessage());
        }
        return 1;
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
    
    private function registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, $tipo, $observacao = null) {
        try {
            $query = "INSERT INTO emails_processados (message_id, email_remetente, assunto, ticket_id, setor_email_id, tipo, observacao)
                      VALUES (:message_id, :remetente, :assunto, :ticket_id, :setor_email_id, :tipo, :observacao)
                      ON DUPLICATE KEY UPDATE tentativas = tentativas + 1";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':message_id', $message_id);
            $stmt->bindParam(':remetente', $remetente);
            $stmt->bindParam(':assunto', $assunto);
            $stmt->bindParam(':ticket_id', $ticket_id);
            $stmt->bindValue(':setor_email_id', $this->caixaEmailAtualId, $this->caixaEmailAtualId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':tipo', $tipo);
            $stmt->bindValue(':observacao', $observacao);
            $stmt->execute();
            LogService::info('email-cron', 'Email registrado no histórico');
        } catch (Exception $e) {
            // Se tabela não existe, apenas logar
            LogService::aviso('email-cron', 'Não foi possível registrar no histórico');
        }
    }

    private function criarTicketPorEmail($assunto, $corpo, $remetente, $inbox, $email_number): int {
            LogService::info('email-cron', 'Iniciando criação de demanda...');

            // Buscar ou criar usuário
            LogService::info('email-cron', 'Buscando/criando usuário para: ' . self::mascararEmail($remetente));
            $usuario_id = $this->buscarOuCriarUsuario($remetente);
            LogService::info('email-cron', "Usuário ID: $usuario_id");

            // Processar anexos e imagens
            LogService::info('email-cron', 'Processando anexos e imagens...');
            $resultado_anexos = $this->processarAnexosEmail($inbox, $email_number);
            
            // Limpar corpo do email
            $corpo_limpo = $corpo;
            
            // Adicionar nota sobre anexos se houver
            $total_anexos = count($resultado_anexos['validos']);
            if ($total_anexos > 0) {
                $corpo_limpo .= "<br><br>📎 " . $total_anexos . " anexo(s) do email foram salvos.";
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
            
            $duplicado = $dup_ticket_stmt->fetchColumn();
            if ($duplicado) {
                LogService::aviso('email-cron', "Demanda duplicada recente (mesmo assunto, corpo e usuário): mantida a #$duplicado.");
                return (int) $duplicado;
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
            LogService::info('email-cron', 'Criando ticket no banco...');
            $query = "INSERT INTO tickets (titulo, descricao, usuario_id, status, prioridade, setor_id)
                     VALUES (:titulo, :descricao, :usuario_id, 'novo', 'normal', :setor_id)";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':titulo', $assunto);
            $stmt->bindParam(':descricao', $corpo_limpo);
            $stmt->bindParam(':usuario_id', $usuario_id);
            $stmt->bindParam(':setor_id', $setor_id);
            $stmt->execute();

            $ticket_id = $this->db->lastInsertId();
            LogService::info('email-cron', 'Ticket criado com ID: #' . str_pad($ticket_id, 5, '0', STR_PAD_LEFT));

            // SLA, janela de setor e evento de abertura (com a caixa de origem, quando houver)
            $detalhe_abertura = 'via e-mail';
            if ($this->caixaEmailAtualId !== null) {
                $cx = $this->db->prepare("SELECT email FROM setor_emails WHERE id = ?");
                $cx->execute([$this->caixaEmailAtualId]);
                $caixa_email = $cx->fetchColumn();
                if ($caixa_email) {
                    $detalhe_abertura = 'via e-mail (' . $caixa_email . ')';
                }
            }
            AcessoService::registrarAbertura($this->db, (int) $ticket_id, $setor_id ? (int) $setor_id : null, (int) $usuario_id, $detalhe_abertura);
            
            // Salvar todos os anexos
            foreach ($resultado_anexos['validos'] as $anexo) {
                $this->salvarAnexo($ticket_id, $anexo);
                LogService::info('email-cron', "Anexo salvo: {$anexo['nome']}");
            }

            // Enviar emails
            LogService::info('email-cron', 'Enviando emails...');
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
                    LogService::info('email-cron', 'Email enviado para o usuário');
                    error_log("[EMAIL-SERVICE] Email de confirmação enviado com sucesso");
                } else {
                    LogService::aviso('email-cron', 'Falha ao enviar email para o usuário');
                    error_log("[EMAIL-SERVICE] FALHA ao enviar email de confirmação");
                }

                // Notificação para agentes
                error_log("[EMAIL-SERVICE] Tentando notificar agentes");
                if ($emailSender->notificarAgentesNovoTicket($ticket_id)) {
                    LogService::info('email-cron', 'Agentes notificados');
                    error_log("[EMAIL-SERVICE] Agentes notificados com sucesso");
                } else {
                    LogService::aviso('email-cron', 'Falha ao notificar agentes');
                    error_log("[EMAIL-SERVICE] FALHA ao notificar agentes");
                }
            } catch (Exception $e) {
                // A demanda já existe: falha de envio não deve reprocessar o e-mail
                LogService::erro('email-cron', "ERRO ao enviar emails da demanda #$ticket_id: " . $e->getMessage());
            }

            return (int) $ticket_id;
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
        
        LogService::info('email-cron', "Total processado: $count_anexos anexos válidos, " . count($anexos_rejeitados) . " rejeitados");
        
        return [
            'validos' => $anexos_validos,
            'rejeitados' => $anexos_rejeitados
        ];
    }
    
    private function processarPartesAnexos($inbox, $email_number, $parts, $prefix, &$anexos_validos, &$anexos_rejeitados, &$tamanho_total, &$count_anexos) {
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

                    LogService::info('email-cron', "[ANEXO] Processando: $filename (seção $section) | Ext: $extensao | Tamanho: " . round($tamanho/1024, 2) . "KB | Count: $count_anexos");

                    // Validar tipo
                    if (!in_array($extensao, $tipos_permitidos)) {
                        $anexos_rejeitados[] = ['nome' => $filename, 'motivo' => 'Tipo não permitido'];
                        LogService::aviso('email-cron', "[ANEXO] REJEITADO - Tipo não permitido: $extensao");
                        continue;
                    }

                    // Validar quantidade
                    if ($count_anexos >= 5) {
                        $anexos_rejeitados[] = ['nome' => $filename, 'motivo' => 'Máximo 5 arquivos'];
                        LogService::aviso('email-cron', "[ANEXO] REJEITADO - Máximo 5 arquivos (atual: $count_anexos)");
                        continue;
                    }

                    // Validar tamanho
                    if ($tamanho_total + $tamanho > 20 * 1024 * 1024) { // 20MB
                        $anexos_rejeitados[] = ['nome' => $filename, 'motivo' => 'Tamanho excede 20MB'];
                        LogService::aviso('email-cron', "[ANEXO] REJEITADO - Tamanho excede 20MB (atual: " . round(($tamanho_total + $tamanho)/1024/1024, 2) . "MB)");
                        continue;
                    }

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
                    LogService::info('email-cron', "[ANEXO] ACEITO: $filename | MIME: $tipo_mime");
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
                LogService::info('email-cron', "Pasta criada: $upload_dir");
            } else {
                error_log("[ANEXO SAVE] ERRO ao criar diretório!");
                LogService::erro('email-cron', "Erro ao criar pasta: $upload_dir");
                return;
            }
        }
        
        // Nome no disco imprevisível e sem o nome original (que vem de fora e fica só no banco)
        $extensao = strtolower(pathinfo(basename($anexo['nome']), PATHINFO_EXTENSION));
        $nome_arquivo = bin2hex(random_bytes(16)) . ($extensao !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', $extensao) : '');
        $anexo['nome'] = basename(str_replace('\\', '/', $anexo['nome']));
        $caminho_completo = $upload_dir . $nome_arquivo;
        
        error_log("[ANEXO SAVE] Nome arquivo: $nome_arquivo");
        error_log("[ANEXO SAVE] Caminho completo: $caminho_completo");
        error_log("[ANEXO SAVE] Tamanho conteúdo: " . strlen($anexo['conteudo']) . " bytes");
        
        LogService::info('email-cron', "Salvando em: $caminho_completo");

        $bytes_escritos = file_put_contents($caminho_completo, $anexo['conteudo']);
        if ($bytes_escritos !== false) {
            error_log("[ANEXO SAVE] Arquivo salvo: $bytes_escritos bytes");
            LogService::info('email-cron', "Arquivo salvo fisicamente ($bytes_escritos bytes)");
            
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
            LogService::info('email-cron', "Registro no banco criado (ID: $anexo_id)");
        } else {
            $error = error_get_last();
            error_log("[ANEXO SAVE] ERRO ao salvar arquivo!");
            error_log("[ANEXO SAVE] Erro PHP: " . print_r($error, true));
            LogService::erro('email-cron', 'Falha ao salvar arquivo fisicamente: ' . ($error['message'] ?? 'Desconhecido'));
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
        LogService::info('email-cron', 'Adicionando comentário ao ticket #' . str_pad($ticket_id, 5, '0', STR_PAD_LEFT));
        
        // Verificar se ticket está fechado - se sim, reabrir
        $this->verificarEReabrirTicket($ticket_id);
        
        $usuario_id = $this->buscarOuCriarUsuario($email);
        
        // Limpar conteúdo do comentário
        $comentario = trim($comentario);
        if (mb_strlen($comentario) > 20000) {
            $comentario = mb_substr($comentario, 0, 20000) . "<br>[CONTEÚDO TRUNCADO]";
        }
        
        // Adicionar nota sobre anexos
        $total_anexos = count($anexos_processados['validos']);
        if ($total_anexos > 0) {
            $comentario .= "<br><br>📎 " . $total_anexos . " anexo(s) incluído(s) neste comentário.";
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
            LogService::aviso('email-cron', 'Comentário ignorado: detectado comentário duplicado recente (mesmo conteúdo e usuário).');
            // Se já existe, não insere novamente.
            return;
        }
        // --- FIM VERIFICAÇÃO EXTRA DE DUPLICIDADE ---

        // Entra no histórico mostrado ao solicitante quando quem escreveu é o próprio solicitante ou um
        // cliente copiado; resposta de um membro da equipe por e-mail continua só interna
        $visivel_stmt = $this->db->prepare("SELECT (u.tipo = 'cliente' OR u.id = t.usuario_id)
                                            FROM usuarios u JOIN tickets t ON t.id = ? WHERE u.id = ?");
        $visivel_stmt->execute([(int) $ticket_id, (int) $usuario_id]);
        $visivel_solicitante = (int) $visivel_stmt->fetchColumn();

        $query = "INSERT INTO comentarios (ticket_id, usuario_id, comentario, visivel_solicitante)
                 VALUES (:ticket_id, :usuario_id, :comentario, :visivel)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':comentario', $comentario);
        $stmt->bindValue(':visivel', $visivel_solicitante, PDO::PARAM_INT);
        $stmt->execute();
        
        $comentario_id = $this->db->lastInsertId();
        LogService::info('email-cron', 'Comentário adicionado ao ticket #' . str_pad($ticket_id, 5, '0', STR_PAD_LEFT));

        // Salvar todos os anexos
        foreach ($anexos_processados['validos'] as $anexo) {
            $this->salvarAnexo($ticket_id, $anexo, $comentario_id);
            LogService::info('email-cron', "Anexo salvo: {$anexo['nome']}");
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
            if (!$is_agente && !in_array($ticket_atual['status'], AcessoService::STATUS_ARQUIVO, true)) {
                if ($ticket_atual['status'] !== 'nova_resposta') {
                    $up_q = "UPDATE tickets SET status = 'nova_resposta' WHERE id = ?";
                    $this->db->prepare($up_q)->execute([$ticket_id]);
                    
                    // Historico
                    $hist_q = "INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo) VALUES (?, ?, 'status', ?, 'nova_resposta')";
                    $this->db->prepare($hist_q)->execute([$ticket_id, $usuario_id, $ticket_atual['status']]);
                    
                    LogService::info('email-cron', "Status atualizado para 'nova_resposta'");
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
            LogService::info('email-cron', 'Reabrindo ticket fechado...');
            
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
            
            LogService::info('email-cron', 'Ticket reaberto automaticamente');

            // Enviar notificações
            try {
                require_once __DIR__ . '/email-sender.php';
                $emailSender = new EmailSender($this->db);

                // Notificar solicitante
                $emailSender->notificarReaberturaParaSolicitante($ticket_id);

                // Notificar agente responsável
                $emailSender->notificarReaberturaTicket($ticket_id);

                LogService::info('email-cron', 'Notificações de reabertura enviadas');
            } catch (Exception $e) {
                LogService::erro('email-cron', 'Erro ao notificar reabertura: ' . $e->getMessage());
            }
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
        // iconv_mime_decode trata o Q-encoding corretamente ("_" = espaço); mb_decode_mimeheader
        // deixava o "_" no título (ex.: "Teste_de_criação"). Fallback para o método antigo se falhar.
        $decoded = @iconv_mime_decode((string) $texto, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $decoded !== false ? $decoded : mb_decode_mimeheader($texto);
    }
}
?>