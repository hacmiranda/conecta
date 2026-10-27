<?php
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../config/email-config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/acesso-service.php';
require_once __DIR__ . '/crypto-service.php';
require_once __DIR__ . '/email-cabecalho.php';
require_once __DIR__ . '/email-historico.php';
require_once __DIR__ . '/email-tipografia.php';
require_once __DIR__ . '/email-imagens.php';
require_once __DIR__ . '/email-assinatura-resposta.php';
require_once __DIR__ . '/protocolo-service.php';
require_once __DIR__ . '/notificacao-email-service.php';
require_once __DIR__ . '/log-service.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailSender {
    private $db;
    private $mailer;
    // Contexto atual de envio (preenchido automaticamente ou via setEmailContext)
    private $logContext = ['ticket_id' => null, 'tipo' => null];
    // Remetente do setor da demanda (null = remetente global do sistema, definido no .env)
    private $remetenteSetor = null;
    // true: as mensagens vão para a fila (email_fila) e o cron envia; false: envio na hora
    private $filaAtiva = true;

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
    
    /**
     * Liga/desliga a fila. Fora dela o envio é imediato (usado pelo processador da fila e
     * por fluxos que precisam do resultado real, como a recuperação de senha).
     */
    public function usarFila(bool $ativa): void {
        $this->filaAtiva = $ativa;
    }

    /**
     * Valida o certificado do SMTP global? Padrão sim; SMTP_VALIDAR_CERTIFICADO=0 no .env desliga.
     */
    private function validarCertificadoGlobal(): bool {
        $valor = strtolower(trim((string) Environment::get('smtp_validar_certificado', '1')));
        return !in_array($valor, ['0', 'false', 'nao', 'não', 'off'], true);
    }

    /**
     * Lê um modelo HTML de backend/ e já troca o marcador {{CABECALHO:Título}} pelo cabeçalho
     * padrão (antes de entrar qualquer conteúdo do usuário no texto).
     */
    private function carregarModelo(string $caminho, ?int $ticketId = null, ?int $excluirComentarioId = null): string {
        $modelo = EmailCabecalho::aplicar((string) file_get_contents($caminho));
        if (strpos($modelo, '{{DEMANDA_E_HISTORICO}}') !== false) {
            // Demanda registrada + histórico da conversa com o solicitante (sem notas internas)
            $bloco = '';
            if ($ticketId) {
                try {
                    $bloco = EmailHistorico::bloco($this->db, $ticketId, $excluirComentarioId);
                } catch (Exception $e) {
                    error_log('[EMAIL-SENDER] Histórico da demanda indisponível: ' . $e->getMessage());
                }
            }
            // strtr (e não str_replace) para o conteúdo do bloco nunca ser reprocessado
            $modelo = strtr($modelo, ['{{DEMANDA_E_HISTORICO}}' => $bloco]);
        }
        return $modelo;
    }

    private function esc($texto): string {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Aviso amarelo de anexos rejeitados, logo depois do cartão "Informações da Demanda"
     * (modelo sem esse cartão: antes de </body>). Compacto como os demais cartões.
     */
    private function inserirAvisoAnexosRejeitados(string $template, array $anexos_rejeitados): string {
        $aviso = '<div style="background-color:#fff3cd;border-left:4px solid #ffc107;border-radius:0 6px 6px 0;padding:10px 16px;margin:14px 0;line-height:1.4">'
            . '<h3 style="color:#856404;margin:0 0 6px 0;font-size:14px;text-transform:uppercase;letter-spacing:0.3px">⚠️ Anexos rejeitados</h3>'
            . '<ul style="margin:0 0 6px 0;padding-left:20px">';
        foreach ($anexos_rejeitados as $anexo) {
            $aviso .= '<li><b>' . $this->esc($anexo['nome']) . ':</b> ' . $this->esc($anexo['motivo']) . '</li>';
        }
        $aviso .= '</ul><p style="margin:0"><b>Limites:</b> 5 arquivos, 20MB total, tipos: PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, PNG, JPG</p></div>';

        $pos = strpos($template, '<div class="ticket-info">');
        $fim = $pos === false ? false : strpos($template, '</div>', $pos);
        if ($fim !== false) {
            return substr_replace($template, $aviso, $fim + strlen('</div>'), 0);
        }
        return str_replace('</body>', $aviso . '</body>', $template);
    }

    private function configurarMailer() {
        $this->mailer = new PHPMailer(true);
        $this->mailer->isSMTP();
        // Nível 0: o debug do PHPMailer registraria as credenciais (AUTH) no log de erros
        $this->mailer->SMTPDebug = 0;
        $this->mailer->Debugoutput = function($str, $level) {
            error_log("[PHPMAILER] $str");
        };
        $this->mailer->Host = EmailConfig::get('SMTP_HOST');
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = EmailConfig::get('SMTP_USERNAME');
        $this->mailer->Password = EmailConfig::get('SMTP_PASSWORD');
        $this->mailer->SMTPSecure = EmailConfig::segurancaSmtp() === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $this->mailer->Port = EmailConfig::get('SMTP_PORT');
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->Timeout = 30;
        $this->mailer->setFrom(EmailConfig::get('FROM_EMAIL'), EmailConfig::get('FROM_NAME'));
        $validarCertificado = $this->validarCertificadoGlobal();

        // Setor com caixa própria: envia e responde pelo endereço do setor
        if ($this->remetenteSetor !== null) {
            $r = $this->remetenteSetor;
            $this->mailer->Host = $r['host'];
            $this->mailer->Port = $r['port'];
            $this->mailer->Username = $r['user'];
            $this->mailer->Password = $r['pass'];
            $this->mailer->SMTPSecure = $r['seguranca'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $this->mailer->setFrom($r['email'], $r['nome']);
            $this->mailer->addReplyTo($r['email'], $r['nome']);
            $validarCertificado = (bool) $r['validar_certificado'];
        }

        // Certificado validado por padrão; só deixa de ser por decisão explícita (por caixa ou no .env)
        $this->mailer->SMTPOptions = $validarCertificado ? [] : [
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
        ];
    }

    /**
     * Passa a enviar pelo endereço de e-mail do setor (caixa ativa em setor_emails).
     * Sem setor, sem caixa ou com falha ao ler as credenciais, usa o remetente global do sistema.
     */
    public function usarRemetenteDoSetor(?int $setorId): void {
        $this->remetenteSetor = null;

        if ($setorId) {
            try {
                $stmt = $this->db->prepare("SELECT se.*, s.nome AS setor_nome
                                            FROM setor_emails se
                                            INNER JOIN setores s ON s.id = se.setor_id
                                            WHERE se.setor_id = ? AND se.ativo = 1
                                            ORDER BY se.id LIMIT 1");
                $stmt->execute([$setorId]);
                $caixa = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($caixa) {
                    $senhaImap = CryptoService::decrypt($caixa['imap_pass_cifrada']);
                    $senhaSmtp = !empty($caixa['smtp_pass_cifrada']) ? CryptoService::decrypt($caixa['smtp_pass_cifrada']) : $senhaImap;

                    $this->remetenteSetor = [
                        'host'      => $caixa['smtp_host'] !== '' ? $caixa['smtp_host'] : (string) EmailConfig::get('SMTP_HOST'),
                        'port'      => (int) $caixa['smtp_port'] ?: 465,
                        'seguranca' => $caixa['smtp_seguranca'],
                        'user'      => !empty($caixa['smtp_user']) ? $caixa['smtp_user'] : $caixa['imap_user'],
                        'pass'      => $senhaSmtp,
                        'email'     => $caixa['email'],
                        'setor_id'  => (int) $setorId,
                        'validar_certificado' => !isset($caixa['validar_certificado']) || (int) $caixa['validar_certificado'] === 1,
                        'setor_nome' => $caixa['setor_nome'],
                        'nome'      => !empty($caixa['nome_remetente']) ? $caixa['nome_remetente'] : 'Conecta CRPRS - ' . $caixa['setor_nome'],
                    ];
                }
            } catch (Exception $e) {
                // Cai no remetente global; o motivo fica no log (sem credenciais)
                error_log('[EMAIL-SENDER] Caixa do setor ' . $setorId . ' indisponível, usando remetente global: ' . $e->getMessage());
                $this->remetenteSetor = null;
            }
        }

        $this->configurarMailer();
    }

    /**
     * Seleciona o remetente conforme o setor da demanda (e-mails enviados ao solicitante).
     */
    private function prepararRemetenteDoTicket($ticket_id): void {
        $setorId = null;
        try {
            $stmt = $this->db->prepare("SELECT setor_id FROM tickets WHERE id = ?");
            $stmt->execute([(int) $ticket_id]);
            $valor = $stmt->fetchColumn();
            $setorId = $valor ? (int) $valor : null;
        } catch (Exception $e) {
            error_log('[EMAIL-SENDER] Erro ao localizar o setor da demanda: ' . $e->getMessage());
        }
        $this->usarRemetenteDoSetor($setorId);
    }

    /**
     * Volta ao remetente global (notificações internas, recuperação de senha, lembretes).
     */
    private function usarRemetenteGlobal(): void {
        if ($this->remetenteSetor !== null) {
            $this->usarRemetenteDoSetor(null);
        }
    }

    /**
     * Esse e-mail está ligado no setor atual da demanda? (NotificacaoEmailService; sem linha
     * para o setor = ativado). Quando desligado, registra o motivo e quem chamou não envia nada.
     */
    private function emailAtivo(string $codigo, $ticket_id): bool {
        $stmt = $this->db->prepare("SELECT setor_id FROM tickets WHERE id = ?");
        $stmt->execute([(int) $ticket_id]);
        $setorId = $stmt->fetchColumn();
        $ativo = NotificacaoEmailService::ativa($this->db, $setorId ? (int) $setorId : null, $codigo);
        if (!$ativo) {
            LogService::info('email-cron', "E-mail '$codigo' não enviado: desativado no setor da demanda "
                . ProtocoloService::rotuloPorId($this->db, (int) $ticket_id));
        }
        return $ativo;
    }

    public function enviarEmailNovoTicket($ticket_id, $anexos_rejeitados = []) {
        if (!$this->emailAtivo('sol_demanda_criada', $ticket_id)) {
            return false;
        }
        $this->prepararRemetenteDoTicket($ticket_id);
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
            // $rotulo com "#" (assunto/mensagens de texto); $numero sem "#" (os modelos HTML já
            // têm o "#" literal antes de {{NUMERO_TICKET}})
            $rotulo = ProtocoloService::rotulo($ticket['protocolo'] ?? null, (int) $ticket_id);
            $numero = ltrim($rotulo, '#');

            $email_destino = $ticket['email_solicitante'] ?: $ticket['usuario_email'];
            
            if (!$email_destino) {
                error_log("[EMAIL-SENDER] Sem email de usuario");
                return false;
            }
            
            error_log("[EMAIL-SENDER] Enviando para: " . $email_destino);
            
            $template_path = dirname(__DIR__, 2) . '/backend/email-template.html';
            error_log("[EMAIL-SENDER] Template path: $template_path");
            $template = $this->carregarModelo($template_path, (int) $ticket_id);
            $nome_exibir = $ticket['email_solicitante'] ?: $ticket['usuario_nome'];
            $template = str_replace('{{NOME_USUARIO}}', $this->esc($nome_exibir), $template);
            $template = str_replace('{{NUMERO_TICKET}}', $numero, $template);
            $template = str_replace('{{TITULO_TICKET}}', $this->esc($ticket['titulo']), $template);
            $template = str_replace('{{DATA_ABERTURA}}', date('d/m/Y H:i', strtotime($ticket['criado_em'])), $template);
            $template = str_replace('{{ASSINATURA_EMAIL}}', $this->getAssinatura(), $template);

            // Adicionar aviso de anexos rejeitados se houver
            if (!empty($anexos_rejeitados)) {
                $template = $this->inserirAvisoAnexosRejeitados($template, $anexos_rejeitados);
            }
            
            $assunto = "🎫 [" . $rotulo . "] Demanda criada - " . $ticket['titulo'];
            
            $resultado = $this->enviarEmail($email_destino, $assunto, $template);
            error_log("[EMAIL-SENDER] Resultado: " . ($resultado ? "SUCESSO" : "FALHA"));
            return $resultado;
            
        } catch (Exception $e) {
            error_log('[EMAIL-SENDER] Erro: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarAgentesNovoTicket($ticket_id) {
        if (!$this->emailAtivo('int_nova_demanda', $ticket_id)) {
            return false;
        }
        $this->usarRemetenteGlobal();
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
            $rotulo = ProtocoloService::rotulo($ticket['protocolo'] ?? null, (int) $ticket_id);

            // Somente os gestores do setor da demanda (admins se o setor não tiver gestor)
            $agentes = AcessoService::destinatariosInternosDoSetor(
                $this->db,
                !empty($ticket['setor_id']) ? (int) $ticket['setor_id'] : null
            );
            $agentes = array_values(array_filter($agentes, function ($a) { return !empty($a['email']); }));

            $assunto = "🎫 [NOVA DEMANDA] " . $rotulo . " - " . $ticket['titulo'];

            $mensagem = "🎫 NOVA DEMANDA CRIADA\n\n";
            $mensagem .= "Número: " . $rotulo . "\n";
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
        if ($this->filaAtiva) {
            return $this->enfileirar($para, $assunto, $html, true, $anexos);
        }
        return $this->enviarEmailComAnexosAgora($para, $assunto, $html, $anexos);
    }

    private function enviarEmailComAnexosAgora($para, $assunto, $html, $anexos = []): bool {
        $status = 'falha';
        $erro   = null;
        try {
            $this->configurarMailer();
            
            if ($this->remetenteSetor === null) {
                $this->mailer->setFrom(EmailConfig::get('SMTP_USERNAME'), 'Conecta CRPRS');
            }
            $this->mailer->addAddress($para);
            $this->mailer->Subject = $assunto;
            $this->mailer->Body    = EmailImagens::embutir($this->mailer, $html);
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
     * Número da demanda e tipo do e-mail: os do contexto informado ou, na falta, detectados pelo assunto.
     */
    private function detectarContexto(string $assunto): array {
        $ticket_id = $this->logContext['ticket_id'];
        $tipo      = $this->logContext['tipo'];

        // Auto-detectar ticket_id pelo assunto (protocolo #CRPRS2026-0001) se não informado
        if ($ticket_id === null) {
            $protocolo = ProtocoloService::extrair($assunto);
            if ($protocolo !== null) {
                $ticket_id = ProtocoloService::buscarId($this->db, $protocolo);
            }
        }

        // Auto-detectar tipo pelo assunto se não informado (case-insensitive via mb_strtolower)
        if ($tipo === null) {
            $lower = mb_strtolower($assunto, 'UTF-8');
            $tipo = match(true) {
                // Mais específicos primeiro para evitar capturas incorretas
                str_contains($lower, 'primeira resposta')   => 'primeira_resposta',
                str_contains($lower, 'resposta da equipe')  => 'resposta',
                str_contains($lower, 'resposta do suporte') => 'resposta',
                str_contains($lower, 'nova demanda')        => 'notif_agente',
                str_contains($lower, 'novo ticket')         => 'notif_agente',
                str_contains($lower, 'novo comentário')     => 'notif_agente',
                str_contains($lower, 'novo comentario')     => 'notif_agente',
                str_contains($lower, 'criada')              => 'novo_ticket',
                str_contains($lower, 'criado')              => 'novo_ticket',
                str_contains($lower, 'encerrada')           => 'fechamento',
                str_contains($lower, 'fechado')             => 'fechamento',
                str_contains($lower, 'reaberta')            => 'reabertura',
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
        return [$ticket_id, $tipo];
    }

    /**
     * Grava a mensagem na fila; o cron envia. Se a gravação falhar, envia na hora (não perde o e-mail).
     */
    private function enfileirar($para, $assunto, $corpo, bool $html, array $anexos = []): bool {
        try {
            [$ticket_id, $tipo] = $this->detectarContexto((string) $assunto);
            $lista = [];
            foreach ($anexos as $a) {
                $lista[] = ['nome_arquivo' => $a['nome_arquivo'], 'nome_original' => $a['nome_original'] ?? $a['nome_arquivo']];
            }
            $stmt = $this->db->prepare("INSERT INTO email_fila (setor_id, ticket_id, tipo, destinatario, assunto, corpo, eh_html, anexos)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $this->remetenteSetor['setor_id'] ?? null,
                $ticket_id,
                $tipo,
                $para,
                mb_substr((string) $assunto, 0, 500),
                $corpo,
                $html ? 1 : 0,
                $lista ? json_encode($lista) : null,
            ]);
            $this->logContext = ['ticket_id' => null, 'tipo' => null];
            return true;
        } catch (Exception $e) {
            error_log('[EMAIL-FILA] Não foi possível enfileirar, enviando na hora: ' . $e->getMessage());
            return $this->enviarAgora($para, $assunto, $corpo, $html, $anexos);
        }
    }

    /**
     * Envio imediato de uma mensagem (usado pelo processador da fila). O remetente já deve ter sido
     * escolhido com usarRemetenteDoSetor().
     */
    public function enviarAgora($para, $assunto, $corpo, bool $html = true, array $anexos = []): bool {
        if (!empty($anexos)) {
            return $this->enviarEmailComAnexosAgora($para, $assunto, $corpo, $anexos);
        }
        return $html ? $this->enviarEmailAgora($para, $assunto, $corpo)
                     : $this->enviarEmailTextoAgora($para, $assunto, $corpo);
    }

    /**
     * Registra cada tentativa de envio de e-mail na tabela email_logs.
     * Detecta ticket_id e tipo automaticamente pelo assunto quando não configurados via setEmailContext().
     */
    private function registrarLogEmail(string $destinatario, string $assunto, string $status, ?string $erro = null): void {
        try {
            [$ticket_id, $tipo] = $this->detectarContexto($assunto);

            $remetente = $this->remetenteSetor !== null
                ? $this->remetenteSetor['email']
                : (string) EmailConfig::get('FROM_EMAIL');

            $q = "INSERT INTO email_logs (destinatario, remetente, assunto, tipo, ticket_id, status, erro)
                  VALUES (:destinatario, :remetente, :assunto, :tipo, :ticket_id, :status, :erro)";
            $s = $this->db->prepare($q);
            $s->bindParam(':destinatario', $destinatario);
            $s->bindParam(':remetente',    $remetente);
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
    
    public function enviarEmail($para, $assunto, $html, bool $imediato = false): bool {
        if ($this->filaAtiva && !$imediato) {
            return $this->enfileirar($para, $assunto, $html, true);
        }
        return $this->enviarEmailAgora($para, $assunto, $html);
    }

    private function enviarEmailAgora($para, $assunto, $html): bool {
        $status = 'falha';
        $erro   = null;
        try {
            set_time_limit(30);
            
            $mail = clone $this->mailer;
            $mail->clearAddresses();
            $mail->addAddress($para);
            $mail->isHTML(true);
            $mail->Subject = $assunto;
            $html = EmailImagens::embutir($mail, $html);
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);
            
            $resultado = $mail->send();
            $status    = $resultado ? 'sucesso' : 'falha';
            return $resultado;
        } catch (Exception $e) {
            $erro = $e->getMessage();
            error_log('[EMAIL] ERRO ao enviar para ' . LogService::mascararEmail((string) $para) . ': ' . $erro);
            return false;
        } finally {
            $this->registrarLogEmail($para, $assunto, $status, $erro);
        }
    }
    
    private function enviarEmailTexto($para, $assunto, $texto): bool {
        if ($this->filaAtiva) {
            return $this->enfileirar($para, $assunto, $texto, false);
        }
        return $this->enviarEmailTextoAgora($para, $assunto, $texto);
    }

    private function enviarEmailTextoAgora($para, $assunto, $texto): bool {
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
                error_log('[EMAIL] Falha ao enviar email para: ' . LogService::mascararEmail((string) $para));
            }
            return $resultado;
        } catch (Exception $e) {
            $erro = $e->getMessage();
            error_log('[EMAIL] Erro ao enviar email para ' . LogService::mascararEmail((string) $para) . ': ' . $erro);
            return false;
        } finally {
            $this->registrarLogEmail($para, $assunto, $status, $erro);
        }
    }
    
    public function enviarComentarioParaSolicitante($ticket_id, $comentario_id, $com_copia = null) {
        $this->prepararRemetenteDoTicket($ticket_id);
        try {
            $query = "SELECT t.*, u.nome as usuario_nome, u.email as usuario_email,
                             c.comentario, uc.nome as comentario_autor, a.nome as agente_nome,
                             uc.tipo as autor_tipo, su.nome as autor_setor, st.nome as demanda_setor
                      FROM tickets t
                      LEFT JOIN usuarios u ON t.usuario_id = u.id
                      LEFT JOIN comentarios c ON c.id = ?
                      LEFT JOIN usuarios uc ON c.usuario_id = uc.id
                      LEFT JOIN setores su ON su.id = uc.setor_id
                      LEFT JOIN setores st ON st.id = t.setor_id
                      LEFT JOIN usuarios a ON t.agente_id = a.id
                      WHERE t.id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$comentario_id, $ticket_id]);
            $dados = $stmt->fetch();
            
            // Destinatário: o solicitante informado na demanda; senão, o e-mail do usuário dela
            $destino_solicitante = trim((string) ($dados['email_solicitante'] ?? '')) ?: trim((string) ($dados['usuario_email'] ?? ''));
            if (!$dados || $destino_solicitante === '') return false;
            $dados['usuario_email'] = $destino_solicitante;
            $esc = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
            $rotulo = ProtocoloService::rotulo($dados['protocolo'] ?? null, (int) $ticket_id);
            $numero = ltrim($rotulo, '#');

            $assunto = "🎫 [" . $rotulo . "] Resposta da Equipe - " . $dados['titulo'];

            // Imagens coladas/inseridas no editor (base64) ficam no texto e são embutidas no e-mail
            // na hora do envio (EmailImagens::embutir), como imagem inline — o Gmail e o Outlook não
            // mostram <img src="data:...">.
            $comentario_email = (string) $dados['comentario'];
            // Mesma fonte, tamanho e entrelinha em toda resposta da equipe, qualquer que seja o texto colado
            $comentario_email = EmailTipografia::padronizar($comentario_email);

            // Assinatura de quem respondeu, dentro do card (só no e-mail; não fica gravada no comentário).
            // Gestor/operador: o próprio setor (ou o da demanda, se vazio); admin, que não tem setor: o da demanda.
            if (in_array($dados['autor_tipo'] ?? '', ['admin', 'gestor', 'operador'], true)) {
                $setor_assinatura = $dados['autor_tipo'] === 'admin'
                    ? (string) ($dados['demanda_setor'] ?? '')
                    : ((string) ($dados['autor_setor'] ?? '') ?: (string) ($dados['demanda_setor'] ?? ''));
                $comentario_email .= EmailAssinaturaResposta::html((string) $dados['comentario_autor'], $setor_assinatura);
            }

            // Template para o solicitante
            $template_solicitante = $this->carregarModelo(dirname(__DIR__, 2) . '/backend/email-template-resposta.html', (int) $ticket_id, (int) $comentario_id);
            // Assinatura do setor que envia (a mesma dos demais e-mails ao solicitante)
            $assinatura = $this->getAssinatura();

            $html_solicitante = str_replace(
                ['{{NOME_USUARIO}}', '{{NUMERO_TICKET}}', '{{TITULO_TICKET}}', '{{NOME_AGENTE}}', '{{DATA_RESPOSTA}}', '{{COMENTARIO_AGENTE}}', '{{ASSINATURA_EMAIL}}'],
                [$esc($dados['usuario_nome']), $numero, $esc($dados['titulo']), $esc($dados['comentario_autor']), date('d/m/Y H:i'), $comentario_email, $assinatura],
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

            // Enviar para o solicitante (o resultado real do envio é devolvido)
            $enviado = $this->emailAtivo('sol_resposta_equipe', $ticket_id)
                && $this->enviarEmailComAnexos($dados['usuario_email'], $assunto, $html_solicitante, $anexos);

            // Enviar cópia se fornecido (e se o e-mail de cópia estiver ligado no setor)
            if ($com_copia && $this->emailAtivo('sol_copia_resposta', $ticket_id)) {
                $template_copia = $this->carregarModelo(dirname(__DIR__, 2) . '/backend/email-template-emcopia.html', (int) $ticket_id, (int) $comentario_id);

                $html_copia = str_replace(
                    ['{{NUMERO_TICKET}}', '{{TITULO_TICKET}}', '{{NOME_AGENTE}}', '{{DATA_RESPOSTA}}', '{{COMENTARIO_AGENTE}}', '{{ASSINATURA_EMAIL}}'],
                    [$numero, $esc($dados['titulo']), $esc($dados['comentario_autor']), date('d/m/Y H:i'), $comentario_email, $assinatura],
                    $template_copia
                );

                // Adicionar info do solicitante no template de cópia
                $html_copia = str_replace('<strong>Solicitante:</strong> <br>', '<strong>Solicitante:</strong> ' . $esc($dados['usuario_nome']) . ' (' . $esc($dados['usuario_email']) . ')<br>', $html_copia);

                // Cópia para cada endereço informado
                foreach (preg_split('/[,;\s]+/', (string) $com_copia, -1, PREG_SPLIT_NO_EMPTY) as $endereco_copia) {
                    $this->enviarEmailComAnexos($endereco_copia, $assunto, $html_copia, $anexos);
                }
            }

            return (bool) $enviado;
            
        } catch (Exception $e) {
            error_log('Erro ao enviar comentário para solicitante: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarNovoComentario($ticket_id, $comentario_id) {
        if (!$this->emailAtivo('int_novo_comentario_solicitante', $ticket_id)) {
            return false;
        }
        $this->usarRemetenteGlobal();
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

            $rotulo     = ProtocoloService::rotulo($dados['protocolo'] ?? null, (int) $ticket_id);
            $assunto    = "🎫 [$rotulo] Novo comentário - " . $dados['titulo'];
            $mensagem   = "Novo comentário adicionado à demanda $rotulo\n\n";
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
        if (!$this->emailAtivo('sol_comentario_recebido', $ticket_id)) {
            return false;
        }
        $this->prepararRemetenteDoTicket($ticket_id);
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
            $rotulo = ProtocoloService::rotulo($ticket['protocolo'] ?? null, (int) $ticket_id);
            $numero = ltrim($rotulo, '#');

            $assunto = "🎫 [" . $rotulo . "] Comentário recebido - " . $ticket['titulo'];

            $mensagem = "Seu comentário foi adicionado à demanda com sucesso!\n\n";
            $mensagem .= "Demanda: " . $rotulo . "\n";
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
            $template = $this->carregarModelo($template_path, (int) $ticket_id);
            $template = str_replace('{{NOME_USUARIO}}', $this->esc($ticket['usuario_nome']), $template);
            $template = str_replace('{{NUMERO_TICKET}}', $numero, $template);
            $template = str_replace('{{TITULO_TICKET}}', $this->esc($ticket['titulo']), $template);
            $template = str_replace('{{DATA_COMENTARIO}}', date('d/m/Y, H:i'), $template);
            $template = str_replace('{{ASSINATURA_EMAIL}}', $this->getAssinatura(), $template);
            
            if (!empty($anexos_rejeitados)) {
                $template = $this->inserirAvisoAnexosRejeitados($template, $anexos_rejeitados);
            }
            
            return $this->enviarEmail($email_remetente, $assunto, $template);
            
        } catch (Exception $e) {
            error_log('Erro ao confirmar comentário: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarFechamentoTicket($ticket_id) {
        $this->prepararRemetenteDoTicket($ticket_id);
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
            $rotulo = ProtocoloService::rotulo($ticket['protocolo'] ?? null, (int) $ticket_id);
            $numero = ltrim($rotulo, '#');

            // Enviar para solicitante se tiver email
            if (($ticket['email_solicitante'] ?: $ticket['usuario_email']) && strpos(($ticket['email_solicitante'] ?: $ticket['usuario_email']), '@')
                && $this->emailAtivo('sol_demanda_encerrada', $ticket_id)) {
                $template_path = dirname(__DIR__, 2) . '/backend/email-template-fechamento.html';
                $template = $this->carregarModelo($template_path, (int) $ticket_id);
                
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
                
                $template = str_replace('{{NOME_USUARIO}}', $this->esc($nome_exibir), $template);
                $template = str_replace('{{NUMERO_TICKET}}', $numero, $template);
                $template = str_replace('{{TITULO_TICKET}}', $this->esc($ticket['titulo']), $template);
                $template = str_replace('{{NOME_AGENTE}}', $this->esc($ticket['fechado_por_nome'] ?: 'Sistema'), $template);
                $template = str_replace('{{DATA_FECHAMENTO}}', date('d/m/Y H:i', strtotime($ticket['fechado_em'])), $template);
                $template = str_replace('{{ASSINATURA_EMAIL}}', $this->getAssinatura(), $template);

                $assunto = "🎫 [" . $rotulo . "] Demanda encerrada - " . $ticket['titulo'];
                $this->enviarEmail(($ticket['email_solicitante'] ?: $ticket['usuario_email']), $assunto, $template);
            }
            
            // Notificar agente responsável se for outro que fechou
            if ($ticket['agente_id'] && $ticket['fechado_por'] != $ticket['agente_id'] && $this->emailAtivo('int_demanda_fechada_por_outro', $ticket_id)) {
                $query_agente = "SELECT email FROM usuarios WHERE id = :id AND ativo = 1";
                $stmt_agente = $this->db->prepare($query_agente);
                $stmt_agente->bindParam(':id', $ticket['agente_id']);
                $stmt_agente->execute();
                $agente = $stmt_agente->fetch();
                
                if ($agente && $agente['email']) {
                    $msg = "🔒 DEMANDA FECHADA POR OUTRO USUÁRIO\n\n";
                    $msg .= "Demanda: " . $rotulo . "\n";
                    $msg .= "Título: " . $ticket['titulo'] . "\n";
                    $msg .= "Fechado por: " . ($ticket['fechado_por_nome'] ?: 'Sistema') . "\n";
                    $msg .= "Data: " . date('d/m/Y H:i') . "\n";
                    $this->enviarEmailTexto($agente['email'], "🔒 Demanda " . $rotulo . " foi fechada", $msg);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao notificar fechamento: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarReaberturaTicket($ticket_id) {
        if (!$this->emailAtivo('int_demanda_reaberta', $ticket_id)) {
            return false;
        }
        $this->usarRemetenteGlobal();
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
            $rotulo = ProtocoloService::rotulo($ticket['protocolo'] ?? null, (int) $ticket_id);

            // Notificar agente responsável
            if ($ticket['agente_email']) {
                $msg = "🔄 DEMANDA REABERTA\n\n";
                $msg .= "Demanda: " . $rotulo . "\n";
                $msg .= "Título: " . $ticket['titulo'] . "\n";
                $msg .= "Solicitante: " . $ticket['usuario_nome'] . "\n";
                $msg .= "Data: " . date('d/m/Y H:i') . "\n\n";
                $msg .= "Acesse o sistema para verificar.\n";
                $this->enviarEmailTexto($ticket['agente_email'], "🔄 Demanda " . $rotulo . " foi reaberta", $msg);
            }
            
            return true;
        } catch (Exception $e) {
            error_log('Erro ao notificar reabertura: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarReaberturaParaSolicitante($ticket_id) {
        if (!$this->emailAtivo('sol_demanda_reaberta', $ticket_id)) {
            return false;
        }
        $this->prepararRemetenteDoTicket($ticket_id);
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
            $rotulo = ProtocoloService::rotulo($ticket['protocolo'] ?? null, (int) $ticket_id);
            $numero = ltrim($rotulo, '#');

            // Carregar template HTML
            $template_path = dirname(__DIR__, 2) . '/backend/email-template-reabertura.html';
            $template = $this->carregarModelo($template_path, (int) $ticket_id);
            
            // Assinatura do setor que envia
            $assinatura = $this->getAssinatura();
            
            // Substituir variáveis
            $template = str_replace('{{NOME_USUARIO}}', $this->esc($ticket['usuario_nome']), $template);
            $template = str_replace('{{NUMERO_TICKET}}', $numero, $template);
            $template = str_replace('{{TITULO_TICKET}}', $this->esc($ticket['titulo']), $template);
            $template = str_replace('{{DATA_REABERTURA}}', date('d/m/Y H:i'), $template);
            $template = str_replace('{{ASSINATURA_EMAIL}}', $assinatura, $template);

            $assunto = "🎫 [" . $rotulo . "] Demanda reaberta - " . $ticket['titulo'];

            return $this->enviarEmail($ticket['usuario_email'], $assunto, $template);
        } catch (Exception $e) {
            error_log('Erro ao notificar solicitante sobre reabertura: ' . $e->getMessage());
            return false;
        }
    }
    
    public function notificarAtribuicaoTicket($ticket_id, $agente_id, $atribuido_por_nome, $era_sem_atribuicao = false) {
        $this->usarRemetenteGlobal();
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
            $rotulo = ProtocoloService::rotulo($ticket['protocolo'] ?? null, (int) $ticket_id);

            // Notificar agente
            if ($agente['email'] && $this->emailAtivo('int_demanda_atribuida', $ticket_id)) {
                $msg = "👤 DEMANDA ATRIBUÍDA PARA VOCÊ\n\n";
                $msg .= "Demanda: " . $rotulo . "\n";
                $msg .= "Título: " . $ticket['titulo'] . "\n";
                $msg .= "Atribuído por: " . $atribuido_por_nome . "\n";
                $msg .= "Data: " . date('d/m/Y H:i') . "\n\n";
                $msg .= "Acesse o sistema para atender esta demanda.\n";

                $this->enviarEmailTexto($agente['email'], "👤 Demanda " . $rotulo . " atribuída para você", $msg);
            }
            
            // Se era sem atribuição, enviar primeira resposta ao solicitante
            if ($era_sem_atribuicao && $ticket['usuario_email'] && $agente['nome']) {
                $this->enviarPrimeiraRespostaAoSolicitante($ticket_id, $agente['nome']);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao notificar atribuição: ' . $e->getMessage());
            return false;
        }
    }
    
    public function enviarPrimeiraRespostaAoSolicitante($ticket_id, $nome_agente) {
        $this->prepararRemetenteDoTicket($ticket_id);
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
            $rotulo = ProtocoloService::rotulo($ticket['protocolo'] ?? null, (int) $ticket_id);
            $numero = ltrim($rotulo, '#');

            // A primeira resposta do SLA é marcada na atribuição em si, não no envio do e-mail
            // (o e-mail pode estar desligado no setor e o SLA continua precisando do marco)
            $this->registrarPrimeiraResposta($ticket_id);

            if (!$this->emailAtivo('sol_primeira_resposta', $ticket_id)) {
                return false;
            }

            $template_path = dirname(__DIR__, 2) . '/backend/email-template-primeira-resposta.html';
            $template = $this->carregarModelo($template_path, (int) $ticket_id);
            $assinatura = $this->getAssinatura();
            
            $template = str_replace('{{NOME_USUARIO}}', $this->esc($ticket['usuario_nome']), $template);
            $template = str_replace('{{NUMERO_TICKET}}', $numero, $template);
            $template = str_replace('{{TITULO_TICKET}}', $this->esc($ticket['titulo']), $template);
            $template = str_replace('{{NOME_AGENTE}}', $this->esc($nome_agente), $template);
            $template = str_replace('{{DATA_RESPOSTA}}', date('d/m/Y H:i'), $template);
            $template = str_replace('{{ASSINATURA_EMAIL}}', $assinatura, $template);

            $assunto = "🎫 [" . $rotulo . "] Primeira resposta - " . $ticket['titulo'];
            
            return $this->enviarEmail(($ticket['email_solicitante'] ?: $ticket['usuario_email']), $assunto, $template);
            
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
        if (!$this->emailAtivo('int_comentario_entre_agentes', $ticket_id)) {
            return false;
        }
        $this->usarRemetenteGlobal();
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
            $q_ticket = "SELECT t.titulo, t.protocolo, c.comentario
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

            $rotulo      = ProtocoloService::rotulo($dados['protocolo'] ?? null, (int) $ticket_id);
            $titulo      = $dados['titulo'];
            $trecho      = mb_substr(strip_tags($dados['comentario'] ?? ''), 0, 300);
            if (mb_strlen($dados['comentario'] ?? '') > 300) {
                $trecho .= '...';
            }

            $assunto = "💬 Novo comentário na Demanda {$rotulo} - {$titulo}";

            $e_nome = $this->esc($agente['nome']);
            $e_autor = $this->esc($autor_nome);
            $e_titulo = $this->esc($titulo);
            $e_trecho = $this->esc($trecho);

            $html = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto;'>
                <div style='background: #8b1d17; padding: 20px; border-radius: 8px 8px 0 0;'>
                    <h2 style='color: #fff; margin: 0; font-size: 18px;'>💬 Novo Comentário na Sua Demanda</h2>
                </div>
                <div style='background: #f9f9f9; padding: 24px; border-radius: 0 0 8px 8px; border: 1px solid #e0e0e0;'>
                    <p style='margin: 0 0 12px 0;'>Olá, <strong>{$e_nome}</strong>!</p>
                    <p style='margin: 0 0 16px 0;'>
                        O agente <strong>{$e_autor}</strong> adicionou um novo comentário na demanda
                        <strong>{$rotulo}</strong> que está atribuída a você.
                    </p>
                    <div style='background: #fff; border-left: 4px solid #e0a800; padding: 14px 18px; border-radius: 4px; margin-bottom: 20px;'>
                        <p style='margin: 0 0 6px 0; font-size: 13px; color: #888;'>Demanda: <strong>{$rotulo}</strong> — {$e_titulo}</p>
                        <p style='margin: 0; font-size: 14px; color: #333;'>{$e_trecho}</p>
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
        // Setor com caixa própria: assina como a equipe do setor; sem setor, assinatura institucional
        if ($this->remetenteSetor !== null) {
            $linhaEquipe = 'Equipe ' . htmlspecialchars($this->remetenteSetor['setor_nome'], ENT_QUOTES, 'UTF-8');
            $linhaEmail  = htmlspecialchars($this->remetenteSetor['email'], ENT_QUOTES, 'UTF-8');
        } else {
            $linhaEquipe = 'Conecta CRPRS';
            $linhaEmail  = htmlspecialchars((string) EmailConfig::get('FROM_EMAIL'), ENT_QUOTES, 'UTF-8');
        }

        return '<table width="100%" border="0" cellspacing="3" cellpadding="3" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
  <tr>
    <td width="13%" valign="top"><img src="http://www.crprs.org.br/assets/frontend/img/logo.png" width="150"/></td>
    <td width="87%" valign="top">
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">' . $linhaEquipe . '</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2">' . $linhaEmail . '</font><br /><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Conselho Regional de Psicologia do Rio Grande do Sul</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Av. Protásio Alves, 2854/301 - Porto Alegre/RS</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">(51) 3334-6799</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2">www.crprs.org.br</font>
    </td>
  </tr>
</table>';
    }
}
