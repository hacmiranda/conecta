-- Quem recebeu cópia de uma resposta da demanda (podem responder por e-mail e virar comentário).
-- Antes isso era lido do texto do histórico (ticket_historico.valor_novo, VARCHAR(255)): com várias
-- cópias o texto passava de 255 caracteres, o registro falhava e a resposta do copiado abria demanda nova.
CREATE TABLE ticket_copias (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    email VARCHAR(254) NOT NULL,
    comentario_id INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_copias (ticket_id, email),
    CONSTRAINT fk_ticket_copias_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_copias_comentario FOREIGN KEY (comentario_id) REFERENCES comentarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
