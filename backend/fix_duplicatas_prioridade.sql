-- ============================================
-- CORREÇÃO - Remover duplicatas de prioridades
-- Data: 01/12/2025
-- ============================================

-- 1. Verificar duplicatas
SELECT nome, COUNT(*) as total 
FROM ticket_prioridades 
GROUP BY nome 
HAVING COUNT(*) > 1;

-- 2. Remover duplicatas, mantendo apenas o primeiro registro
DELETE t1 FROM ticket_prioridades t1
INNER JOIN ticket_prioridades t2 
WHERE t1.id > t2.id AND t1.nome = t2.nome;

-- 3. Adicionar índice UNIQUE para evitar duplicatas futuras
ALTER TABLE ticket_prioridades 
ADD UNIQUE KEY unique_nome (nome);

-- 4. Verificar resultado
SELECT * FROM ticket_prioridades ORDER BY ordem;

SELECT 'Duplicatas removidas com sucesso!' AS status;
