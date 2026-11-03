<?php
require_once __DIR__ . '/pesquisa-config-service.php';
require_once __DIR__ . '/email-cabecalho.php';

/**
 * Regra única de criação e envio do convite de avaliação (módulo Satisfação), usada pelo envio manual do
 * "Meu Painel" e pelo pedido que nasce quando uma demanda é resolvida/encerrada. Porte fiel do
 * ConviteEnvioService do METRICA360: quarentena por e-mail do destinatário, token de uso único, chave de
 * idempotência e e-mail. Expiração e reenvio automático ficam em PesquisaProcessadorService.
 *
 * Resultado de enviar(): ['status' => ..., 'convite_id' => ?int, 'proximo_envio' => ?int (timestamp)]
 *   enviado     — convite criado e e-mail entregue à fila de envio
 *   quarentena  — o e-mail recebeu convite há menos de N dias (nada é criado)
 *   ja_existe   — já há convite com a mesma chave de integração (nada é criado nem enviado)
 *   falha_email — o e-mail não pôde nem ser enfileirado; no envio manual o convite fica gravado,
 *                 no pedido de uma demanda ($desfazerSeFalhar) ele é desfeito para tentar de novo depois
 */
class PesquisaConviteService {

    const ASSUNTO = 'Como foi seu atendimento no CRPRS? Avalie em 1 minuto';

    private PDO $db;
    /** @var callable(string,string,string,string):void  (nome, e-mail, link, protocolo) */
    private $enviarEmail;

    public function __construct(PDO $db, ?callable $enviarEmail = null) {
        $this->db = $db;
        $this->enviarEmail = $enviarEmail ?? function (string $nome, string $email, string $link, string $protocolo) use ($db): void {
            self::enviarEmailDoConvite($db, $nome, $email, $link, $protocolo);
        };
    }

    /** Endereço da página pública de avaliação; o token vai no fragmento (#), que não aparece em logs de servidor. */
    public static function linkDeAvaliacao(string $token): string {
        return rtrim((string) Environment::get('base_url'), '/') . '/frontend/avaliar.html#t=' . $token;
    }

    /** Frase de abertura: cita o protocolo da demanda quando o convite nasce de uma. */
    public static function frase(string $protocolo, bool $html): string {
        $protocolo = ltrim(trim($protocolo), '#');
        if ($protocolo === '') {
            return 'Informamos que a sua recente solicitação junto ao CRPRS foi finalizada.';
        }
        $rotulo = '#' . $protocolo;
        if ($html) {
            $rotulo = '<strong>' . htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') . '</strong>';
        }
        return "Informamos que a sua solicitação {$rotulo} junto ao CRPRS foi finalizada.";
    }

    /** Monta o e-mail do convite a partir do modelo de backend/. @return array{0:string,1:string} [assunto, html] */
    public static function montarEmail(string $nome, string $link, string $protocolo): array {
        $modelo = EmailCabecalho::aplicar((string) file_get_contents(dirname(__DIR__, 2) . '/backend/email-template-pesquisa-convite.html'));
        $e = fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $html = str_replace(
            ['{{NOME}}', '{{FRASE}}', '{{LINK}}'],
            [$e($nome), self::frase($protocolo, true), $e($link)],
            $modelo
        );
        return [self::ASSUNTO, $html];
    }

    /** Entrega o e-mail do convite à fila de envio. Lança exceção se nem isso foi possível. */
    public static function enviarEmailDoConvite(PDO $db, string $nome, string $email, string $link, string $protocolo): void {
        require_once __DIR__ . '/email-sender.php';
        [$assunto, $html] = self::montarEmail($nome, $link, $protocolo);
        $sender = new EmailSender($db);
        $sender->setEmailContext(null, 'pesquisa_convite');
        if (!$sender->enviarEmail($email, $assunto, $html)) {
            throw new RuntimeException('Não foi possível enfileirar o e-mail do convite');
        }
    }

    /**
     * @param array $origem origem ('manual'|'demanda'), ticket_id, protocolo, chave_integracao, setor_id
     *                      (sem setor_id vale o setor atual do servidor)
     */
    public function enviar(int $usuarioId, string $nome, string $email, array $origem = [], bool $desfazerSeFalhar = false): array {
        $chave = (string) ($origem['chave_integracao'] ?? '');
        if ($chave !== '') {
            $existente = $this->buscarPorChave($chave);
            if ($existente) {
                return ['status' => 'ja_existe', 'convite_id' => (int) $existente['id'], 'proximo_envio' => null];
            }
        }

        $regras = PesquisaConfig::regrasConvite($this->db);
        if ($regras['quarentena_ativa'] && $this->jaEnviadoRecentemente($email, $regras['quarentena_dias'])) {
            return ['status' => 'quarentena', 'convite_id' => null,
                    'proximo_envio' => $this->proximoEnvioPermitido($email, $regras['quarentena_dias'])];
        }

        $setorId = isset($origem['setor_id']) && (int) $origem['setor_id'] > 0 ? (int) $origem['setor_id'] : $this->setorDoUsuario($usuarioId);
        $token = bin2hex(random_bytes(32));
        try {
            $stmt = $this->db->prepare("INSERT INTO pesquisa_convites
                    (usuario_id, setor_id, destinatario_nome, destinatario_email, token, origem, ticket_id, protocolo, chave_integracao)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $usuarioId,
                $setorId,
                mb_substr($nome, 0, 150),
                mb_substr($email, 0, 255),
                $token,
                ($origem['origem'] ?? 'manual') === 'demanda' ? 'demanda' : 'manual',
                !empty($origem['ticket_id']) ? (int) $origem['ticket_id'] : null,
                ($origem['protocolo'] ?? '') !== '' ? mb_substr((string) $origem['protocolo'], 0, 40) : null,
                $chave !== '' ? $chave : null,
            ]);
            $id = (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            // Pedido simultâneo com a mesma chave (índice único): o outro já criou o convite
            if ($chave !== '' && $e->getCode() === '23000' && ($existente = $this->buscarPorChave($chave))) {
                return ['status' => 'ja_existe', 'convite_id' => (int) $existente['id'], 'proximo_envio' => null];
            }
            throw $e;
        }

        try {
            ($this->enviarEmail)($nome, $email, self::linkDeAvaliacao($token), (string) ($origem['protocolo'] ?? ''));
        } catch (Throwable $e) {
            error_log('[PESQUISA] Falha no e-mail do convite #' . $id . ': ' . $e->getMessage());
            if ($desfazerSeFalhar) {
                $this->db->prepare("DELETE FROM pesquisa_convites WHERE id = ? AND avaliado_em IS NULL")->execute([$id]);
            }
            return ['status' => 'falha_email', 'convite_id' => $desfazerSeFalhar ? null : $id, 'proximo_envio' => null];
        }

        return ['status' => 'enviado', 'convite_id' => $id, 'proximo_envio' => null];
    }

    private function buscarPorChave(string $chave): array|false {
        $stmt = $this->db->prepare("SELECT id FROM pesquisa_convites WHERE chave_integracao = ? LIMIT 1");
        $stmt->execute([$chave]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function setorDoUsuario(int $usuarioId): ?int {
        $stmt = $this->db->prepare("SELECT setor_id FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $setor = $stmt->fetchColumn();
        return $setor ? (int) $setor : null;
    }

    /** Já houve convite para este e-mail dentro do período de quarentena? (qualquer servidor, avaliado ou não) */
    public function jaEnviadoRecentemente(string $email, int $dias): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pesquisa_convites
                                    WHERE destinatario_email = ? AND enviado_em > DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$email, $dias]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Timestamp Unix do fim da quarentena de novos convites para o e-mail (null se nunca houve convite). */
    public function proximoEnvioPermitido(string $email, int $dias): ?int {
        $stmt = $this->db->prepare("SELECT MAX(enviado_em) FROM pesquisa_convites WHERE destinatario_email = ?");
        $stmt->execute([$email]);
        $ultimo = $stmt->fetchColumn();
        return $ultimo ? strtotime((string) $ultimo) + ($dias * 86400) : null;
    }
}
