-- ============================================================
-- Criação da Tabela de Notificações Internas
-- Data: 20/02/2026
-- Descrição: Tabela para gerenciar os alertas (sininho) de usuários e agentes
-- ============================================================

CREATE TABLE IF NOT EXISTS notificacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    mensagem TEXT NOT NULL,
    ticket_id INT DEFAULT NULL,
    lida TINYINT(1) DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);
