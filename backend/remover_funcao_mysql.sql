-- ========================================
-- REMOVER FUNÇÃO MYSQL PROIBIDA
-- Data: 28/11/2025
-- Motivo: Função no banco viola regra técnica
--         Lógica já implementada em PHP (SLAService)
-- ========================================

-- 1. Remover função calcular_segundos_uteis
DROP FUNCTION IF EXISTS `calcular_segundos_uteis`;

-- 2. Verificar se foi removida (deve retornar vazio)
SHOW FUNCTION STATUS WHERE Db = 'crpdesk';
