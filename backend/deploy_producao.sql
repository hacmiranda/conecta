-- ========================================
-- SCRIPT CONSOLIDADO DE CORREÇÕES - CRPDESK
-- Data: 01/12/2025
-- Aplicar no banco de PRODUÇÃO via PHPMyAdmin
-- ========================================

-- ========================================
-- 1. REMOVER FUNÇÃO MYSQL PROIBIDA
-- ========================================
DROP FUNCTION IF EXISTS `calcular_segundos_uteis`;

-- Verificar remoção (deve retornar vazio)
SHOW FUNCTION STATUS WHERE Db = 'crpdesk';


-- ========================================
-- 2. CORRIGIR CAMPO PRIORIDADE
-- ========================================
ALTER TABLE `tickets` 
MODIFY COLUMN `prioridade` VARCHAR(50) DEFAULT 'normal';

-- Atualizar tickets existentes sem prioridade
UPDATE `tickets` 
SET `prioridade` = 'normal' 
WHERE `prioridade` IS NULL OR `prioridade` = '';

-- Verificar alteração
SHOW COLUMNS FROM `tickets` LIKE 'prioridade';


-- ========================================
-- VERIFICAÇÕES FINAIS
-- ========================================

-- 1. Confirmar que não há funções no banco
SELECT COUNT(*) as total_funcoes 
FROM information_schema.ROUTINES 
WHERE ROUTINE_SCHEMA = DATABASE() 
AND ROUTINE_TYPE = 'FUNCTION';
-- Resultado esperado: 0

-- 2. Confirmar estrutura do campo prioridade
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'tickets'
AND COLUMN_NAME = 'prioridade';
-- Resultado esperado: DEFAULT = 'normal'
