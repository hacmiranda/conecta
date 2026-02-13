-- ========================================
-- SISTEMA DE RASTREAMENTO DE EMAILS
-- Data: 31/01/2025
-- Ref: Dupla verificação e prevenção de duplicatas
-- ========================================

-- Tabela para rastrear emails processados
CREATE TABLE IF NOT EXISTS emails_processados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NOT NULL UNIQUE,
    email_remetente VARCHAR(255) NOT NULL,
    assunto TEXT,
    ticket_id INT,
    tipo ENUM('novo_ticket', 'comentario', 'erro') NOT NULL,
    processado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    tentativas INT DEFAULT 1,
    INDEX idx_message_id (message_id),
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_processado_em (processado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela para emails com erro (fila de reprocessamento)
CREATE TABLE IF NOT EXISTS emails_erro (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NOT NULL,
    email_remetente VARCHAR(255) NOT NULL,
    assunto TEXT,
    corpo TEXT,
    erro_mensagem TEXT,
    tentativas INT DEFAULT 1,
    ultima_tentativa DATETIME DEFAULT CURRENT_TIMESTAMP,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_id (message_id),
    INDEX idx_tentativas (tentativas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
