<?php
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/html-sanitizer.php';
require_once __DIR__ . '/log-service.php';
require_once __DIR__ . '/upload-service.php';
require_once __DIR__ . '/demanda-service.php';
require_once __DIR__ . '/protocolo-service.php';
require_once __DIR__ . '/email-mime.php';
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
        return LogService::mascararEmail($email);
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

            // Tempo-limite: servidor travado não segura o cron (e a trava dele) indefinidamente
            imap_timeout(IMAP_OPENTIMEOUT, 20);
            imap_timeout(IMAP_READTIMEOUT, 60);
            imap_timeout(IMAP_WRITETIMEOUT, 60);
            imap_timeout(IMAP_CLOSETIMEOUT, 20);
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

        // Texto do e-mail (EmailMime: texto em qualquer nível das partes, charset convertido, HTML sem
        // CSS/scripts e com as quebras de linha)
        $body = EmailMime::corpoTexto($structure, $this->buscadorDeSecoes($inbox, $email_number, $structure));
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

        // Autenticidade do remetente (SPF/DKIM/DMARC) segundo o servidor que recebeu a mensagem
        $autenticacao = self::avaliarAutenticacao((string) imap_fetchheader($inbox, $email_number));
        if ($autenticacao === 'desconhecido') {
            LogService::aviso('email-cron', 'Sem resultado de SPF/DKIM/DMARC no cabeçalho (Authentication-Results)');
        }

        // Resposta a uma demanda existente? Só vira comentário se o remetente participa da demanda
        // e a caixa é de um setor por onde ela passou; senão, abre uma demanda nova.
        $ticket_id = $this->extrairTicketId($assunto);
        $observacao = null;

        try {
            $recusa = $ticket_id ? $this->motivoRecusaComentario((int) $ticket_id, $remetente, $autenticacao) : null;

            if ($ticket_id && $recusa === null) {
                LogService::info('email-cron', 'Resposta à demanda ' . ProtocoloService::rotuloPorId($this->db, (int) $ticket_id));
                $anexos_processados = $this->processarAnexosEmail($inbox, $email_number);
                $this->adicionarComentario($ticket_id, $body, $remetente, $anexos_processados);
                $tipo = 'comentario';
            } else {
                if ($ticket_id) {
                    $observacao = 'Mencionou a demanda ' . ProtocoloService::rotuloPorId($this->db, (int) $ticket_id) . ', mas ' . $recusa . '; aberta demanda nova.';
                    $body = '<p><em>' . htmlspecialchars($observacao, ENT_QUOTES, 'UTF-8') . '</em></p>' . $body;
                    LogService::aviso('email-cron', $observacao);
                }
                LogService::info('email-cron', 'Criando nova demanda...');
                $ticket_id = $this->criarTicketPorEmail($assunto, $body, $remetente, $this->processarAnexosEmail($inbox, $email_number));
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
    private function motivoRecusaComentario(int $ticket_id, string $remetente, string $autenticacao = 'desconhecido'): ?string {
        // Remetente forjado (o servidor de e-mail reprovou SPF/DMARC): nunca vira comentário de ninguém
        if ($autenticacao === 'falhou') {
            return 'o e-mail não passou na verificação de autenticidade do remetente (SPF/DMARC)';
        }

        $stmt = $this->db->prepare("SELECT t.id, t.setor_id, t.status, t.email_solicitante, t.excluido_em, u.email AS usuario_email
                                    FROM tickets t LEFT JOIN usuarios u ON u.id = t.usuario_id
                                    WHERE t.id = ?");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket || !empty($ticket['excluido_em'])) {
            return 'a demanda não existe ou foi excluída';
        }
        if (($ticket['status'] ?? '') === 'cancelado') {
            return 'a demanda foi cancelada';
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
        $copia = $this->db->prepare("SELECT 1 FROM ticket_copias WHERE ticket_id = ? AND email = ? LIMIT 1");
        $copia->execute([$ticket_id, $remetente]);
        if ($copia->fetchColumn()) {
            return null;
        }
        // Cópias registradas antes da tabela ticket_copias (só no texto do histórico)
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
     * Autenticidade do remetente segundo o cabeçalho Authentication-Results. Só vale o PRIMEIRO desses
     * cabeçalhos (o que o servidor que recebeu a mensagem acrescentou no topo); os de baixo podem ter
     * vindo prontos de quem enviou. Função pura (testável).
     *   'falhou'      — dmarc=fail, ou spf=fail/softfail sem dkim=pass
     *   'passou'      — dmarc, dkim ou spf = pass
     *   'desconhecido'— sem o cabeçalho ou sem resultado conclusivo
     */
    public static function avaliarAutenticacao(string $cabecalhos): string {
        // Desdobra cabeçalhos de várias linhas (linhas de continuação começam com espaço/tab)
        $cabecalhos = preg_replace("/\r?\n[ \t]+/", ' ', $cabecalhos);
        if (!preg_match('/^Authentication-Results:(.*)$/mi', (string) $cabecalhos, $m)) {
            return 'desconhecido';
        }
        $resultado = strtolower($m[1]);
        $dkimPassou = (bool) preg_match('/\bdkim=pass\b/', $resultado);
        if (preg_match('/\bdmarc=fail\b/', $resultado)
            || (preg_match('/\bspf=(fail|softfail)\b/', $resultado) && !$dkimPassou)) {
            return 'falhou';
        }
        if ($dkimPassou || preg_match('/\b(dmarc|spf)=pass\b/', $resultado)) {
            return 'passou';
        }
        return 'desconhecido';
    }

    /**
     * Registra a falha no processamento de um e-mail e devolve quantas tentativas já houve.
     */
    private function registrarFalha(string $message_id, string $remetente, string $assunto, string $erro): int {
        try {
            $sel = $this->db->prepare("SELECT id, tentativas FROM emails_erro WHERE message_id = ? AND setor_email_id <=> ? ORDER BY id DESC LIMIT 1");
            $sel->execute([$message_id, $this->caixaEmailAtualId]);
            $linha = $sel->fetch(PDO::FETCH_ASSOC);
            if ($linha) {
                $this->db->prepare("UPDATE emails_erro SET tentativas = tentativas + 1, erro_mensagem = ?, ultima_tentativa = NOW() WHERE id = ?")
                         ->execute([mb_substr($erro, 0, 2000), $linha['id']]);
                return (int) $linha['tentativas'] + 1;
            }
            $this->db->prepare("INSERT INTO emails_erro (message_id, setor_email_id, email_remetente, assunto, erro_mensagem) VALUES (?, ?, ?, ?, ?)")
                     ->execute([$message_id, $this->caixaEmailAtualId, $remetente, $assunto, mb_substr($erro, 0, 2000)]);
        } catch (Exception $e) {
            error_log('[EMAIL-SERVICE] Não foi possível registrar a falha: ' . $e->getMessage());
        }
        return 1;
    }

    private function emailJaProcessado($message_id) {
        try {
            // Por caixa: o mesmo e-mail enviado a dois setores abre uma demanda em cada um
            $stmt = $this->db->prepare("SELECT id FROM emails_processados WHERE message_id = ? AND setor_email_id <=> ?");
            $stmt->execute([$message_id, $this->caixaEmailAtualId]);
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

    /** @param array{validos: array, rejeitados: array} $resultado_anexos já lidos do IMAP (fora da transação) */
    private function criarTicketPorEmail($assunto, $corpo, $remetente, array $resultado_anexos): int {

        $corpo_limpo = $corpo;
        $total_anexos = count($resultado_anexos['validos']);
        if ($total_anexos > 0) {
            $corpo_limpo .= "<br><br>📎 " . $total_anexos . " anexo(s) do email foram salvos.";
        }
        // Garante UTF-8 válido (o banco recusa sequências inválidas)
        $corpo_limpo = mb_convert_encoding($corpo_limpo, 'UTF-8', 'UTF-8');
        $assunto     = mb_convert_encoding($assunto, 'UTF-8', 'UTF-8');

        // Setor: o da caixa que recebeu o e-mail; a caixa global legada cai no setor "Geral"
        $setor_id = $this->setorAtualId;
        if (!$setor_id) {
            $setor_stmt = $this->db->prepare("SELECT id FROM setores WHERE nome = 'Geral' AND ativo = 1 LIMIT 1");
            $setor_stmt->execute();
            $setor_id = $setor_stmt->fetchColumn() ?: null;
        }

        $detalhe_abertura = 'via e-mail';
        if ($this->caixaEmailAtualId !== null) {
            $cx = $this->db->prepare("SELECT email FROM setor_emails WHERE id = ?");
            $cx->execute([$this->caixaEmailAtualId]);
            $caixa_email = $cx->fetchColumn();
            if ($caixa_email) {
                $detalhe_abertura = 'via e-mail (' . $caixa_email . ')';
            }
        }

        // Tudo ou nada: solicitante, demanda, protocolo, SLA, janela do setor, abertura e anexos.
        // Se algo falhar, nada fica pela metade (o e-mail é tentado de novo na próxima execução).
        // Usa a transação de quem chama, se houver (testes de integração)
        $propria = !$this->db->inTransaction();
        if ($propria) {
            $this->db->beginTransaction();
        }
        UploadService::iniciarLote();
        try {
            $usuario_id = $this->buscarOuCriarUsuario($remetente);

            // Mesmo e-mail reprocessado com outro Message-ID (ex.: laço de encaminhamento): mantém a demanda
            $dup = $this->db->prepare("SELECT id FROM tickets WHERE usuario_id = ? AND titulo = ? AND descricao = ? AND setor_id <=> ?
                                         AND criado_em > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
            $dup->execute([$usuario_id, $assunto, $corpo_limpo, $setor_id]);
            $duplicado = $dup->fetchColumn();
            if ($duplicado) {
                if ($propria) {
                    $this->db->commit();
                }
                LogService::aviso('email-cron', "Demanda duplicada recente (mesmo assunto, corpo, usuário e setor): mantida a #$duplicado.");
                return (int) $duplicado;
            }

            $protocolo = ProtocoloService::gerar($this->db);
            $stmt = $this->db->prepare("INSERT INTO tickets (protocolo, titulo, descricao, usuario_id, status, prioridade, setor_id)
                                        VALUES (?, ?, ?, ?, 'novo', 'normal', ?)");
            $stmt->execute([$protocolo, $assunto, $corpo_limpo, $usuario_id, $setor_id]);
            $ticket_id = (int) $this->db->lastInsertId();

            AcessoService::registrarAbertura($this->db, $ticket_id, $setor_id ? (int) $setor_id : null, (int) $usuario_id, $detalhe_abertura);

            foreach ($resultado_anexos['validos'] as $anexo) {
                $this->salvarAnexo($ticket_id, $anexo);
            }
            if ($propria) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($propria && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            UploadService::desfazerLote();
            throw $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e);
        }
        LogService::info('email-cron', 'Demanda criada: ' . ProtocoloService::rotulo($protocolo, $ticket_id));

        // E-mails só depois do commit (a demanda já existe: falha de envio não reprocessa o e-mail)
        try {
            require_once __DIR__ . '/email-sender.php';
            $emailSender = new EmailSender($this->db);
            if (!$emailSender->enviarEmailNovoTicket($ticket_id, $resultado_anexos['rejeitados'])) {
                LogService::aviso('email-cron', "Confirmação ao solicitante da demanda #$ticket_id não enfileirada");
            }
            if (!$emailSender->notificarAgentesNovoTicket($ticket_id)) {
                LogService::aviso('email-cron', "Aviso à equipe da demanda #$ticket_id não enfileirado");
            }
        } catch (Exception $e) {
            LogService::erro('email-cron', "ERRO ao enviar emails da demanda #$ticket_id: " . $e->getMessage());
        }

        return $ticket_id;
    }
    /**
     * Conteúdo cru de uma seção da mensagem. FT_PEEK: ler não marca o e-mail como lido (só depois de
     * processado com sucesso). O e-mail simples (sem partes) é a seção "1" = imap_body.
     */
    private function buscadorDeSecoes($inbox, $email_number, $structure): callable {
        $simples = empty($structure->parts);
        return function (string $secao) use ($inbox, $email_number, $simples): string {
            $conteudo = ($simples && $secao === '1')
                ? imap_body($inbox, $email_number, FT_PEEK)
                : imap_fetchbody($inbox, $email_number, $secao, FT_PEEK);
            return (string) $conteudo;
        };
    }

    /** Anexos da mensagem, validados pela mesma regra da tela (UploadService): tipo real, 5 arquivos, 20 MB cada. */
    private function processarAnexosEmail($inbox, $email_number) {
        $anexos_validos = [];
        $anexos_rejeitados = [];

        $structure = imap_fetchstructure($inbox, $email_number);
        foreach (EmailMime::anexos($structure, $this->buscadorDeSecoes($inbox, $email_number, $structure)) as $anexo) {
            $mime_real = UploadService::mimeDoConteudo($anexo['conteudo']);
            $validacao = UploadService::validar($anexo['nome'], $mime_real, $anexo['tamanho']);
            if (!$validacao['ok']) {
                $anexos_rejeitados[] = ['nome' => $anexo['nome'], 'motivo' => $validacao['motivo']];
                LogService::aviso('email-cron', "[ANEXO] Recusado (seção {$anexo['secao']}): {$validacao['motivo']} ({$validacao['extensao']})");
                continue;
            }
            if (count($anexos_validos) >= UploadService::MAX_ARQUIVOS) {
                $anexos_rejeitados[] = ['nome' => $anexo['nome'], 'motivo' => 'Máximo 5 arquivos'];
                continue;
            }
            // MIME gravado = o do conteúdo real, não o declarado no e-mail
            $anexos_validos[] = ['nome' => $anexo['nome'], 'conteudo' => $anexo['conteudo'], 'tamanho' => $anexo['tamanho'], 'tipo' => $mime_real];
        }

        LogService::info('email-cron', 'Anexos: ' . count($anexos_validos) . ' aceito(s), ' . count($anexos_rejeitados) . ' recusado(s)');
        return ['validos' => $anexos_validos, 'rejeitados' => $anexos_rejeitados];
    }
    private function salvarAnexo($ticket_id, $anexo, $comentario_id = null) {
        // Mesma gravação dos anexos da tela: nome aleatório no disco (o original, que vem de fora, fica só
        // no banco), permissão 0640. O anexo já foi validado em processarPartesAnexos.
        $nome = basename(str_replace(chr(92), '/', $anexo['nome'])); // chr(92) = "\": caminhos do Windows
        $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        $salvo = UploadService::salvarConteudo($this->db, $anexo['conteudo'], $nome, $extensao, (string) $anexo['tipo'],
            (int) $ticket_id, $comentario_id !== null ? (int) $comentario_id : null);
        if ($salvo === null) {
            LogService::erro('email-cron', "Falha ao gravar anexo da demanda #{$ticket_id}");
        } else {
            LogService::info('email-cron', "Anexo gravado na demanda #{$ticket_id} (" . strlen($anexo['conteudo']) . " bytes)");
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
        // Protocolo único (#CRPRS2026-0001), com ou sem colchetes/RE:/espaços internos. O formato
        // antigo (#00123, número interno de 5 dígitos) não é mais reconhecido: o sistema ainda não
        // estava em produção quando o protocolo foi introduzido.
        $protocolo = ProtocoloService::extrair((string) $assunto);
        if ($protocolo === null) {
            return null;
        }
        $id = ProtocoloService::buscarId($this->db, $protocolo);

        // Demanda mesclada: a conversa continua na principal (segue a cadeia, no máximo 5 saltos)
        $principal = $this->db->prepare("SELECT mesclada_em FROM tickets WHERE id = ?");
        for ($salto = 0; $id && $salto < 5; $salto++) {
            $principal->execute([(int) $id]);
            $destino = $principal->fetchColumn();
            if (!$destino) {
                break;
            }
            LogService::info('email-cron', 'Demanda mesclada: resposta segue para ' . ProtocoloService::rotuloPorId($this->db, (int) $destino));
            $id = (int) $destino;
        }
        return $id;
    }

    private function adicionarComentario($ticket_id, $comentario, $email, array $anexos_processados = ['validos' => [], 'rejeitados' => []]) {
        $ticket_id = (int) $ticket_id;
        LogService::info('email-cron', 'Adicionando comentário à demanda ' . ProtocoloService::rotuloPorId($this->db, $ticket_id));

        $comentario = trim($comentario);
        if (mb_strlen($comentario) > 20000) {
            $comentario = mb_substr($comentario, 0, 20000) . "<br>[CONTEÚDO TRUNCADO]";
        }
        $total_anexos = count($anexos_processados['validos']);
        if ($total_anexos > 0) {
            $comentario .= "<br><br>📎 " . $total_anexos . " anexo(s) incluído(s) neste comentário.";
        }

        // Tudo ou nada: reabertura, comentário, anexos e troca de status. Os avisos saem depois do commit.
        // Usa a transação de quem chama, se houver (testes de integração)
        $propria = !$this->db->inTransaction();
        if ($propria) {
            $this->db->beginTransaction();
        }
        UploadService::iniciarLote();
        try {
            $usuario_id = (int) $this->buscarOuCriarUsuario($email);

            // Trava a demanda: duas caixas (ou dois crons) não reabrem nem comentam ao mesmo tempo
            $trava = $this->db->prepare("SELECT t.status, t.agente_id, t.usuario_id, u.email AS agente_email
                                         FROM tickets t LEFT JOIN usuarios u ON u.id = t.agente_id
                                         WHERE t.id = ? FOR UPDATE");
            $trava->execute([$ticket_id]);
            $ticket_atual = $trava->fetch(PDO::FETCH_ASSOC);
            if (!$ticket_atual) {
                throw new Exception('Demanda não encontrada');
            }

            // Mesmo conteúdo do mesmo usuário nos últimos 5 minutos (ex.: resposta enviada a duas caixas
            // do sistema, ou laço de e-mail com outro Message-ID): não grava de novo
            $dup = $this->db->prepare("SELECT id FROM comentarios WHERE ticket_id = ? AND usuario_id = ? AND comentario = ?
                                         AND criado_em > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1");
            $dup->execute([$ticket_id, $usuario_id, $comentario]);
            if ($dup->fetchColumn()) {
                if ($propria) {
                    $this->db->commit();
                }
                LogService::aviso('email-cron', 'Comentário ignorado: duplicado recente (mesmo conteúdo e usuário).');
                return;
            }

            // Demanda encerrada que recebe resposta: reabre pela regra única (o SLA volta a contar)
            $reabertaAgora = in_array($ticket_atual['status'], ['fechado', 'encerrado'], true);
            if ($reabertaAgora) {
                DemandaService::mudarStatus($this->db, $ticket_id, 'reaberto', (int) $ticket_atual['usuario_id'], ['resposta_da_equipe' => false]);
                LogService::info('email-cron', 'Demanda reaberta pela resposta por e-mail');
            }

            // Entra no histórico mostrado ao solicitante quando quem escreveu é o próprio solicitante ou um
            // cliente copiado; resposta de um membro da equipe por e-mail continua só interna
            $visivel_stmt = $this->db->prepare("SELECT (u.tipo = 'cliente' OR u.id = t.usuario_id)
                                                FROM usuarios u JOIN tickets t ON t.id = ? WHERE u.id = ?");
            $visivel_stmt->execute([$ticket_id, $usuario_id]);
            $visivel_solicitante = (int) $visivel_stmt->fetchColumn();

            $this->db->prepare("INSERT INTO comentarios (ticket_id, usuario_id, comentario, visivel_solicitante) VALUES (?, ?, ?, ?)")
                     ->execute([$ticket_id, $usuario_id, $comentario, $visivel_solicitante]);
            $comentario_id = (int) $this->db->lastInsertId();

            foreach ($anexos_processados['validos'] as $anexo) {
                $this->salvarAnexo($ticket_id, $anexo, $comentario_id);
            }

            // Resposta de fora (não do responsável): "nova resposta", que também retoma o SLA pausado.
            // Não conta como primeira resposta da equipe.
            $eh_responsavel = !empty($ticket_atual['agente_email']) && strtolower((string) $ticket_atual['agente_email']) === strtolower((string) $email);
            if (!$eh_responsavel && !$reabertaAgora
                && !in_array($ticket_atual['status'], AcessoService::STATUS_ARQUIVO, true)
                && $ticket_atual['status'] !== 'nova_resposta') {
                DemandaService::mudarStatus($this->db, $ticket_id, 'nova_resposta', $usuario_id, ['resposta_da_equipe' => false]);
            }

            if ($propria) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($propria && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            UploadService::desfazerLote();
            throw $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e);
        }
        LogService::info('email-cron', 'Comentário adicionado à demanda ' . ProtocoloService::rotuloPorId($this->db, $ticket_id));

        // Avisos depois do commit: falha de envio não desfaz o comentário nem reprocessa o e-mail
        try {
            require_once __DIR__ . '/email-sender.php';
            $emailSender = new EmailSender($this->db);
            if ($reabertaAgora) {
                $emailSender->notificarReaberturaParaSolicitante($ticket_id);
                $emailSender->notificarReaberturaTicket($ticket_id);
            }
            $emailSender->notificarNovoComentario($ticket_id, $comentario_id);
            $emailSender->confirmarComentarioParaRemetente($ticket_id, $email, $anexos_processados['rejeitados']);
        } catch (Exception $e) {
            LogService::erro('email-cron', "Erro ao enviar os avisos do comentário na demanda #$ticket_id: " . $e->getMessage());
        }
    }
    private function decodificarTexto($texto) {
        // iconv_mime_decode trata o Q-encoding corretamente ("_" = espaço); mb_decode_mimeheader
        // deixava o "_" no título (ex.: "Teste_de_criação"). Fallback para o método antigo se falhar.
        $decoded = @iconv_mime_decode((string) $texto, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $decoded !== false ? $decoded : mb_decode_mimeheader($texto);
    }
}
