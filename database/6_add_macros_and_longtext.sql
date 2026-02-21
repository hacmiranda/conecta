-- ============================================================
-- Criação da Tabela de Macros e Ajustes LONGTEXT
-- Data: 20/02/2026
-- Descrição: Tabela p/ as respostas rápidas (macros) e campos ricos nativos
-- ============================================================

-- Modifica as colunas descritivas para aceitar enormes cargas de Base64 embutidas no WYSIWYG
ALTER TABLE tickets MODIFY descricao LONGTEXT NOT NULL;
ALTER TABLE comentarios MODIFY comentario LONGTEXT NOT NULL;

-- Criação da tabela independente de Respostas Injetáveis
CREATE TABLE IF NOT EXISTS macros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    texto LONGTEXT NOT NULL,
    usuario_id INT DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);
