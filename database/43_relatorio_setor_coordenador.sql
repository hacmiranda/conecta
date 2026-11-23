-- 23/11/2026: relatório de atividades do setor enviado pelo coordenador (desenho 2026-11-23, seção 5.1).
--
-- 1) registros_atividades ganha o setor em que o relatório foi feito (o servidor pode ser transferido de setor
--    sem mudar o que já enviou) e o período (dia = relatório do servidor; semana/mês = relatório do setor,
--    enviado pelo coordenador). A coluna gerada chave_setor garante UM relatório de setor por setor/período/data
--    (NULL nos relatórios diários, que o índice único ignora).
-- 2) atividades_relatorio_setor: vigência da cobrança do relatório do setor (semanal ou mensal).
-- 3) atividades_abonos_setor: abono de um período do relatório do setor, com justificativa.

-- Setor do registro: preenchido a partir do setor atual do usuário
ALTER TABLE registros_atividades
    ADD COLUMN setor_id INT NULL AFTER usuario_id;

UPDATE registros_atividades r
  JOIN usuarios u ON u.id = r.usuario_id
   SET r.setor_id = u.setor_id;

-- Rascunhos de quem não tem setor não servem para nada: saem (os itens vão junto, ON DELETE CASCADE).
-- Relatório ENVIADO sem setor não é apagado: o MODIFY abaixo falha e a migração para (verificar antes com
-- SELECT COUNT(*) FROM registros_atividades WHERE setor_id IS NULL AND status = 'enviado').
DELETE FROM registros_atividades WHERE setor_id IS NULL AND status = 'rascunho';

ALTER TABLE registros_atividades
    MODIFY setor_id INT NOT NULL;

ALTER TABLE registros_atividades
    ADD COLUMN periodo ENUM('dia', 'semana', 'mes') NOT NULL DEFAULT 'dia' AFTER setor_id;

ALTER TABLE registros_atividades
    ADD COLUMN chave_setor VARCHAR(40)
        AS (IF(periodo = 'dia', NULL, CONCAT(setor_id, '-', periodo, '-', data_referencia))) STORED AFTER periodo;

ALTER TABLE registros_atividades
    ADD CONSTRAINT fk_registros_atividades_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE RESTRICT,
    ADD UNIQUE KEY uq_registros_atividades_usuario_setor_periodo_data (usuario_id, setor_id, periodo, data_referencia),
    ADD UNIQUE KEY uq_registros_atividades_chave_setor (chave_setor),
    ADD KEY idx_registros_atividades_setor_data (setor_id, data_referencia, status);

-- O índice antigo sai por último: a chave estrangeira de usuario_id já usa o novo (começa por usuario_id)
ALTER TABLE registros_atividades
    DROP INDEX uq_registros_atividades_usuario_data;

-- Vigência da cobrança do relatório do setor
CREATE TABLE atividades_relatorio_setor (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NOT NULL,
    periodicidade ENUM('semanal', 'mensal') NOT NULL,
    inicio DATE NOT NULL,
    fim DATE NULL,
    criado_por INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    encerrado_por INT NULL,
    encerrado_em DATETIME NULL,
    KEY idx_atividades_relatorio_setor_setor_inicio (setor_id, inicio),
    CONSTRAINT fk_atividades_relatorio_setor_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE,
    CONSTRAINT fk_atividades_relatorio_setor_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT fk_atividades_relatorio_setor_encerrado_por FOREIGN KEY (encerrado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Abono de um período do relatório do setor (admin ou coordenador do setor)
CREATE TABLE atividades_abonos_setor (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NOT NULL,
    periodo ENUM('semana', 'mes') NOT NULL,
    data_referencia DATE NOT NULL,
    justificativa VARCHAR(1000) NOT NULL,
    criado_por INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_atividades_abonos_setor (setor_id, periodo, data_referencia),
    CONSTRAINT fk_atividades_abonos_setor_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE,
    CONSTRAINT fk_atividades_abonos_setor_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
