-- Protocolo único do Conselho: #CRPRS2026-0001, reinicia a cada ano (ProtocoloService).
-- Nunca muda (transferência de setor, reabertura, mesclagem mantêm o protocolo de cada demanda).
-- Substitui o número interno (tickets.id) como identidade mostrada ao usuário; o id continua
-- sendo a chave primária e a referência interna do sistema.
ALTER TABLE tickets ADD COLUMN protocolo VARCHAR(20) NULL AFTER id;

CREATE TABLE protocolo_sequencia (
    ano SMALLINT NOT NULL PRIMARY KEY,
    ultimo INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Demandas existentes: numeradas por ano de criação, na ordem em que nasceram (inclusive as na
-- lixeira, para manter a identidade de cada uma).
UPDATE tickets t
JOIN (
    SELECT id, YEAR(criado_em) AS ano, ROW_NUMBER() OVER (PARTITION BY YEAR(criado_em) ORDER BY criado_em, id) AS rn
    FROM tickets
) x ON x.id = t.id
SET t.protocolo = CONCAT('CRPRS', x.ano, '-', LPAD(x.rn, 4, '0'));

-- Contador por ano começa de onde a numeração das demandas existentes parou.
INSERT INTO protocolo_sequencia (ano, ultimo)
SELECT YEAR(criado_em), COUNT(*) FROM tickets GROUP BY YEAR(criado_em)
ON DUPLICATE KEY UPDATE ultimo = GREATEST(protocolo_sequencia.ultimo, VALUES(ultimo));

ALTER TABLE tickets ADD UNIQUE KEY uq_tickets_protocolo (protocolo);
