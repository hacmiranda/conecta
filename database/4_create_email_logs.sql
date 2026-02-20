-- ============================================================
-- Tabela de Log de E-mails Enviados pelo sistema CRPDesk
-- ============================================================
CREATE TABLE IF NOT EXISTS email_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enviado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    destinatario  VARCHAR(255) NOT NULL,
    assunto       VARCHAR(500) NOT NULL,
    tipo          VARCHAR(80)  NOT NULL COMMENT 'ex: novo_ticket, comentario, fechamento, notif_agente…',
    ticket_id     INT UNSIGNED NULL,
    status        ENUM('sucesso', 'falha') NOT NULL,
    erro          TEXT         NULL COMMENT 'Mensagem de erro quando status = falha',
    INDEX idx_enviado_em  (enviado_em),
    INDEX idx_ticket_id   (ticket_id),
    INDEX idx_status       (status),
    INDEX idx_tipo         (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
