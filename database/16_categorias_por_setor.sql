-- Fase 5A (Conecta CRPRS): categorias e subcategorias passam a pertencer a um setor.
-- Antes: catálogo único compartilhado + ativação por setor (setor_categorias).
-- Depois: cada setor cria e edita as suas; o gestor só mexe nas do próprio setor.
--
-- Itens já existentes ficam no setor "Geral" (criado na migração 9). Para que cada setor tenha
-- suas categorias, cadastre-as pelo próprio setor (ou copie manualmente os itens necessários).
-- Aplicar APÓS 9 a 15, uma única vez (MySQL 8.4 não aceita ADD COLUMN IF NOT EXISTS).
-- Aplicar com: "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p --default-character-set=utf8mb4 db_conecta < 16_categorias_por_setor.sql

SET @setor_geral = (SELECT id FROM setores WHERE nome = 'Geral' LIMIT 1);

-- 1. Coluna setor_id (nula até o preenchimento)
ALTER TABLE macrocategorias ADD COLUMN setor_id INT NULL AFTER nome;
ALTER TABLE categorias      ADD COLUMN setor_id INT NULL AFTER nome;

-- 2. Itens existentes vão para o setor Geral
UPDATE macrocategorias SET setor_id = @setor_geral WHERE setor_id IS NULL;
UPDATE categorias      SET setor_id = @setor_geral WHERE setor_id IS NULL;

-- 3. Obrigatória + unicidade do nome por setor (antes era global)
ALTER TABLE macrocategorias
    MODIFY COLUMN setor_id INT NOT NULL,
    ADD UNIQUE KEY uq_macrocategorias_setor_nome (setor_id, nome),
    ADD CONSTRAINT fk_macrocategorias_setor FOREIGN KEY (setor_id) REFERENCES setores (id);

ALTER TABLE categorias
    MODIFY COLUMN setor_id INT NOT NULL,
    DROP INDEX nome,
    ADD UNIQUE KEY uq_categorias_setor_nome (setor_id, nome),
    ADD KEY idx_categorias_macrocategoria (macrocategoria_id),
    ADD CONSTRAINT fk_categorias_setor FOREIGN KEY (setor_id) REFERENCES setores (id);

-- 4. A ativação de categorias por setor deixa de existir
DROP TABLE IF EXISTS setor_categorias;
