<?php
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/html-sanitizer.php';
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
            echo "<p style='color: red;'>❌ Erro ao buscar caixas de e-mail por setor: " . $e->getMessage() . "</p>";
        }

        if (empty($caixas)) {
            echo "<p>Nenhuma caixa de e-mail por setor cadastrada — usando caixa global legada (.env).</p>";
            $validarGlobal = !in_array(strtolower(trim((string) Environment::get('imap_validar_certificado', '1'))), ['0', 'false', 'nao', 'não', 'off'], true);
            return $this->verificarCaixa($this->host, $this->port, $this->username, $this->password, null, null, $validarGlobal);
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
        $log_file = dirname(__DIR__, 2) . '/backend/logs/email-cron.log';

        if (!function_exists('imap_open')) {
            echo "<p style='color: red;'>❌ Extensão PHP IMAP não está habilitada neste servidor.</p>";
            error_log('[EMAIL-SERVICE] Extensão imap não habilitada.');
            return false;
        }

        try {
            // Certificado do servidor validado por padrão; só é dispensado por decisão explícita da caixa
            $mailbox = "{{$host}:{$port}/imap/ssl" . ($validarCertificado ? '' : '/novalidate-cert') . "}INBOX";
            echo "<p>Conectando em: $mailbox</p>";
            file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Conectando em: $mailbox\n", FILE_APPEND);

            $inbox = @imap_open($mailbox, $username, $password, 0, 3);

            if (!$inbox) {
                $erro = imap_last_error();
                file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] ERRO: $erro\n", FILE_APPEND);
                throw new Exception('Não foi possível conectar ao email: ' . $erro);
            }

            echo "<p>✅ Conectado com sucesso!</p>";
            file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Conectado com sucesso\n", FILE_APPEND);

                // Buscar emails dos últimos N dias (padrão 3) para garantir que nenhum foi perdido
                // mesmo se marcado como lido por outro cliente (Outlook/Celular); o Message-ID evita duplicar
                $data_busca = date('d-M-Y', strtotime('-' . $this->diasBusca . ' days'));
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
            echo "<p>⏭️ Email já processado anteriormente (Message-ID: $message_id)</p>";
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
            echo "<p>🚫 Email ignorado ($motivo_ignorar): $remetente</p>";
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

        echo "<p>Assunto: " . htmlspecialchars($assunto) . "</p>";
        echo "<p>Remetente: $remetente</p>";

        // Resposta a uma demanda existente? Só vira comentário se o remetente participa da demanda
        // e a caixa é de um setor por onde ela passou; senão, abre uma demanda nova.
        $ticket_id = $this->extrairTicketId($assunto);
        $observacao = null;

        try {
            $recusa = $ticket_id ? $this->motivoRecusaComentario((int) $ticket_id, $remetente) : null;

            if ($ticket_id && $recusa === null) {
                echo "<p>✉️ Resposta à demanda #" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . "</p>";
                $anexos_processados = $this->processarAnexosEmail($inbox, $email_number);
                $this->adicionarComentario($ticket_id, $body, $remetente, $anexos_processados, $inbox, $email_number);
                $tipo = 'comentario';
            } else {
                if ($ticket_id) {
                    $observacao = 'Mencionou a demanda #' . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . ', mas ' . $recusa . '; aberta demanda nova.';
                    $body = '<p><em>' . htmlspecialchars($observacao, ENT_QUOTES, 'UTF-8') . '</em></p>' . $body;
                    echo "<p>⚠️ $observacao</p>";
                }
                echo "<p>🆕 Criando nova demanda...</p>";
                $ticket_id = $this->criarTicketPorEmail($assunto, $body, $remetente, $inbox, $email_number);
                $tipo = 'novo_ticket';
            }
        } catch (Exception $e) {
            // Não marca como lido nem como processado: tenta de novo na próxima execução
            $tentativas = $this->registrarFalha($message_id, $remetente, $assunto, $e->getMessage());
            error_log('[EMAIL-SERVICE] Falha ao processar e-mail de ' . $remetente . ' (tentativa ' . $tentativas . '): ' . $e->getMessage());
            echo "<p style='color: red;'>❌ Falha ao processar (tentativa $tentativas): " . htmlspecialchars($e->getMessage()) . "</p>";
            if ($tentativas >= self::MAX_TENTATIVAS) {
                $this->registrarEmailProcessado($message_id, $remetente, $assunto, null, 'erro', 'Desistiu após ' . $tentativas . ' tentativas: ' . mb_substr($e->getMessage(), 0, 180));
                $marcarLido();
            }
            return;
        }

        $this->registrarEmailProcessado($message_id, $remetente, $assunto, $ticket_id, $tipo, $observacao);

        // Marcar email como lido APENAS após sucesso
        $marcarLido();
        echo "<p>✅ Email processado e marcado como lido</p>";
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
            echo "<p>📝 Email registrado no histórico</p>";
        } catch (Exception $e) {
            // Se tabela não existe, apenas logar
            echo "<p>⚠️ Aviso: Não foi possível registrar no histórico</p>";
        }
    }
    
    private function criarTicketPorEmail($assunto, $corpo, $remetente, $inbox, $email_number): int {
            echo "<p>Iniciando criação de demanda...</p>";
            
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
                echo "<p>⚠️ Demanda duplicada recente (mesmo assunto, corpo e usuário): mantida a #$duplicado.</p>";
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
                // A demanda já existe: falha de envio não deve reprocessar o e-mail
                echo "<p>❌ ERRO ao enviar emails: " . $e->getMessage() . "</p>";
                error_log("[EMAIL-SERVICE] Erro ao enviar e-mails da demanda #$ticket_id: " . $e->getMessage());
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
        
        // Nome no disco imprevisível e sem o nome original (que vem de fora e fica só no banco)
        $extensao = strtolower(pathinfo(basename($anexo['nome']), PATHINFO_EXTENSION));
        $nome_arquivo = bin2hex(random_bytes(16)) . ($extensao !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', $extensao) : '');
        $anexo['nome'] = basename(str_replace('\\', '/', $anexo['nome']));
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