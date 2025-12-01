-- ========================================
-- CORRIGIR CAMPO PRIORIDADE NA TABELA TICKETS
-- Data: 01/12/2025
-- Problema: Campo prioridade sem valor padrão
-- Erro: Field 'prioridade' doesn't have a default value
-- ========================================

-- 1. Adicionar valor padrão ao campo prioridade
ALTER TABLE `tickets` 
MODIFY COLUMN `prioridade` VARCHAR(50) DEFAULT 'normal';

-- 2. Verificar alteração (deve mostrar DEFAULT 'normal')
SHOW COLUMNS FROM `tickets` LIKE 'prioridade';

-- 3. Atualizar tickets existentes sem prioridade (se houver)
UPDATE `tickets` 
SET `prioridade` = 'normal' 
WHERE `prioridade` IS NULL OR `prioridade` = '';
