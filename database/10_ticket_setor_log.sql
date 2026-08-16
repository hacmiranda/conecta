-- Fase 2: histórico de passagem de cada demanda pelos setores.
-- Toda vez que uma demanda entra num setor (criação ou transferência), abre uma linha
-- (saiu_em = NULL). Ao sair (transferência ou encerramento), a linha é fechada.
-- O setor "atual" de uma demanda é sempre a linha em aberto (saiu_em IS NULL).

CREATE TABLE IF NOT EXISTS ticket_setor_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    setor_id INT NOT NULL,
    entrou_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    saiu_em DATETIME NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (setor_id) REFERENCES setores(id),
    INDEX idx_setor_log_ticket (ticket_id),
    INDEX idx_setor_log_setor_aberto (setor_id, saiu_em)
);

-- Backfill: abre uma janela para demandas já existentes que tenham setor_id
-- mas ainda não têm nenhuma linha em ticket_setor_log.
INSERT INTO ticket_setor_log (ticket_id, setor_id, entrou_em, saiu_em)
SELECT t.id, t.setor_id, t.criado_em,
       CASE WHEN t.status IN ('encerrado', 'fechado', 'cancelado') THEN t.atualizado_em ELSE NULL END
FROM tickets t
WHERE t.setor_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM ticket_setor_log l WHERE l.ticket_id = t.id);
