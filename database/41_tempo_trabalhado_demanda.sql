-- Tempo trabalhado na demanda (17/11/2026). Cada pessoa lança quantas vezes quiser o tempo que trabalhou numa
-- demanda: data + início e fim, ou só a duração. O sistema soma por pessoa e o total da demanda. Opcional (não
-- trava o encerramento). API: api/controllers/demanda-tempos.php; tela: frontend/demanda-tempos.js.
CREATE TABLE ticket_tempos (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    usuario_id INT NOT NULL,
    data_trabalho DATE NOT NULL,
    hora_inicio TIME NULL,            -- vazio quando o lançamento é só a duração
    hora_fim TIME NULL,
    minutos SMALLINT UNSIGNED NOT NULL, -- duração (calculada de início/fim ou informada), 1 a 1440
    descricao VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ticket_tempos_ticket (ticket_id),
    KEY idx_ticket_tempos_usuario_data (usuario_id, data_trabalho),
    CONSTRAINT fk_ticket_tempos_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_tempos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
