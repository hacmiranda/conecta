-- Pesquisa de satisfação: pedido que deixou de valer antes de ser entregue ao METRICA360 (demanda
-- enviada para a lixeira, reaberta, cancelada ou mesclada, ou pesquisa desligada no setor).
ALTER TABLE pesquisa_satisfacao
    MODIFY COLUMN status ENUM('pendente', 'enviado', 'quarentena', 'sem_funcionario', 'falha', 'cancelada') NOT NULL DEFAULT 'pendente';
