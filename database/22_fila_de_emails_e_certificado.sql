-- Fase 11: fila de e-mails e validação de certificado por caixa.

-- Fila de envio: a requisição só grava; o cron envia (com novas tentativas em caso de falha).
-- A senha SMTP nunca é copiada para cá: o remetente é resolvido pelo setor_id no momento do envio.
CREATE TABLE email_fila (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NULL,
    ticket_id INT NULL,
    tipo VARCHAR(80) NULL,
    destinatario VARCHAR(255) NOT NULL,
    assunto VARCHAR(500) NOT NULL,
    corpo LONGTEXT NOT NULL,
    eh_html TINYINT(1) NOT NULL DEFAULT 1,
    anexos TEXT NULL,
    status ENUM('pendente','enviando','enviado','falha') NOT NULL DEFAULT 'pendente',
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    erro TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em DATETIME NULL,
    KEY idx_fila_status (status, proxima_tentativa),
    KEY idx_fila_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Validar o certificado do servidor (IMAP e SMTP): padrão ligado; desligar só por decisão explícita por caixa.
ALTER TABLE setor_emails ADD COLUMN validar_certificado TINYINT(1) NOT NULL DEFAULT 1;
