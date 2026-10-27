-- Mesclagem: a demanda secundária guarda o id da principal. Respostas por e-mail com o protocolo da
-- secundária vão para a principal, e as mescladas ficam fora dos indicadores (não contam como atendidas).
-- (Não havia mesclagens antes desta migração, então não há o que preencher.)
ALTER TABLE tickets
    ADD COLUMN mesclada_em INT NULL AFTER reaberto_contador,
    ADD CONSTRAINT fk_tickets_mesclada_em FOREIGN KEY (mesclada_em) REFERENCES tickets (id) ON DELETE SET NULL;
