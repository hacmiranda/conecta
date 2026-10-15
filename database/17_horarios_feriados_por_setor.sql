-- Fase 5B (Conecta CRPRS): horários úteis e feriados passam a ser de cada setor.
-- O SLA de uma demanda usa o expediente e os feriados do setor em que ela está.
--
-- Os registros globais existentes são copiados para cada setor já cadastrado e, depois, removidos.
-- Setores criados depois herdam o expediente do setor "Geral" (ver api/controllers/setores.php).
-- Aplicar APÓS 9 a 16, uma única vez (MySQL 8.4 não aceita ADD COLUMN IF NOT EXISTS).
-- Aplicar com: "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p --default-character-set=utf8mb4 db_conecta < 17_horarios_feriados_por_setor.sql

-- 1. Coluna setor_id e remoção das unicidades globais (dia da semana / data)
ALTER TABLE horarios_uteis ADD COLUMN setor_id INT NULL AFTER id, DROP INDEX unique_dia;
ALTER TABLE feriados       ADD COLUMN setor_id INT NULL AFTER id, DROP INDEX `data`;

-- 2. Copia as linhas globais para cada setor existente
INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo)
SELECT s.id, h.dia_semana, h.hora_inicio, h.hora_fim, h.ativo
FROM setores s CROSS JOIN horarios_uteis h
WHERE h.setor_id IS NULL;

INSERT INTO feriados (setor_id, `data`, descricao, recorrente)
SELECT s.id, f.`data`, f.descricao, f.recorrente
FROM setores s CROSS JOIN feriados f
WHERE f.setor_id IS NULL;

-- 3. Remove as linhas globais (agora duplicadas por setor)
DELETE FROM horarios_uteis WHERE setor_id IS NULL;
DELETE FROM feriados       WHERE setor_id IS NULL;

-- 4. Obrigatória + unicidade por setor
ALTER TABLE horarios_uteis
    MODIFY COLUMN setor_id INT NOT NULL,
    ADD UNIQUE KEY uq_horarios_setor_dia (setor_id, dia_semana),
    ADD CONSTRAINT fk_horarios_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE;

ALTER TABLE feriados
    MODIFY COLUMN setor_id INT NOT NULL,
    ADD UNIQUE KEY uq_feriados_setor_data (setor_id, `data`),
    ADD CONSTRAINT fk_feriados_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE;
