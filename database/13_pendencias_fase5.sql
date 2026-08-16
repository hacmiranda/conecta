-- Resolve pendências registradas ao final da Fase 5:
-- 1) histórico de atribuições (ticket_agente_log), espelhando ticket_setor_log mas para responsável
-- 2) log de e-mails processados por caixa (liga emails_processados a setor_emails)

CREATE TABLE IF NOT EXISTS ticket_agente_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    usuario_id INT NOT NULL,
    entrou_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    saiu_em DATETIME NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_agente_log_ticket (ticket_id),
    INDEX idx_agente_log_usuario_aberto (usuario_id, saiu_em)
);

-- Backfill: abre uma janela para toda demanda já atribuída hoje (comportamento anterior preservado)
INSERT INTO ticket_agente_log (ticket_id, usuario_id, entrou_em, saiu_em)
SELECT t.id, t.agente_id, COALESCE(t.atribuido_em, t.criado_em), NULL
FROM tickets t
WHERE t.agente_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM ticket_agente_log l WHERE l.ticket_id = t.id AND l.saiu_em IS NULL);

ALTER TABLE emails_processados
    ADD COLUMN setor_email_id INT NULL AFTER ticket_id,
    ADD FOREIGN KEY (setor_email_id) REFERENCES setor_emails(id) ON DELETE SET NULL;
