-- Filtros de e-mail por setor: palavras no remetente, destinatário, assunto ou corpo que fazem o sistema
-- NÃO abrir demanda (newsletters, propagandas, avisos automáticos). setor_id NULL = vale para todos os
-- setores (só o admin cadastra). Vale só para o que abriria demanda nova: resposta de quem participa de
-- uma demanda continua virando comentário. A repetição (setor, campo, termo) é conferida no código,
-- porque o índice único do MySQL não pega repetição com setor_id NULL.
CREATE TABLE filtros_email (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NULL,
    campo ENUM('remetente', 'destinatario', 'assunto', 'corpo') NOT NULL,
    termo VARCHAR(255) NOT NULL,
    termo_normalizado VARCHAR(255) NOT NULL,
    observacao VARCHAR(255) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ocorrencias INT NOT NULL DEFAULT 0,
    ultima_ocorrencia DATETIME NULL,
    criado_por INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_por INT NULL,
    atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_filtros_email_setor (setor_id, ativo),
    CONSTRAINT fk_filtros_email_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE,
    CONSTRAINT fk_filtros_email_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT fk_filtros_email_atualizado_por FOREIGN KEY (atualizado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- E-mail barrado ligado ao filtro que o barrou (histórico na tela de filtros)
ALTER TABLE emails_processados
    ADD COLUMN filtro_email_id INT NULL AFTER setor_email_id,
    ADD CONSTRAINT fk_emails_processados_filtro FOREIGN KEY (filtro_email_id) REFERENCES filtros_email (id) ON DELETE SET NULL;
