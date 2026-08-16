-- Fase 3: cada setor pode ativar/desativar (e reordenar) quais itens do catálogo
-- global de categorias, status e prioridades aparecem nos seus formulários.
-- O catálogo em si (categorias, ticket_status, ticket_prioridades) continua único e
-- compartilhado — estas tabelas só guardam a visibilidade por setor, sem duplicar dados.

CREATE TABLE IF NOT EXISTS setor_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NOT NULL,
    categoria_id INT NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_setor_categoria (setor_id, categoria_id),
    FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS setor_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NOT NULL,
    status_id INT NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_setor_status (setor_id, status_id),
    FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE,
    FOREIGN KEY (status_id) REFERENCES ticket_status(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS setor_prioridades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NOT NULL,
    prioridade_id INT NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_setor_prioridade (setor_id, prioridade_id),
    FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE,
    FOREIGN KEY (prioridade_id) REFERENCES ticket_prioridades(id) ON DELETE CASCADE
);

-- Backfill: todo setor já existente começa com o catálogo inteiro ativo,
-- preservando o comportamento atual (nada muda até alguém desativar algo).
INSERT INTO setor_categorias (setor_id, categoria_id, ativo, ordem)
SELECT s.id, c.id, 1, c.id
FROM setores s CROSS JOIN categorias c
WHERE NOT EXISTS (SELECT 1 FROM setor_categorias sc WHERE sc.setor_id = s.id AND sc.categoria_id = c.id);

INSERT INTO setor_status (setor_id, status_id, ativo, ordem)
SELECT s.id, t.id, 1, t.ordem
FROM setores s CROSS JOIN ticket_status t
WHERE NOT EXISTS (SELECT 1 FROM setor_status ss WHERE ss.setor_id = s.id AND ss.status_id = t.id);

INSERT INTO setor_prioridades (setor_id, prioridade_id, ativo, ordem)
SELECT s.id, p.id, 1, p.ordem
FROM setores s CROSS JOIN ticket_prioridades p
WHERE NOT EXISTS (SELECT 1 FROM setor_prioridades sp WHERE sp.setor_id = s.id AND sp.prioridade_id = p.id);
