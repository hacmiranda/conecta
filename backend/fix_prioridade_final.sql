-- ============================================
-- CORREÇÃO FINAL - Campo Prioridade
-- Data: 01/12/2025
-- ============================================

-- Garantir que todos os tickets existentes tenham prioridade
UPDATE `tickets` 
SET `prioridade` = 'normal' 
WHERE `prioridade` IS NULL OR `prioridade` = '';

-- Garantir que o campo tenha DEFAULT e NOT NULL
ALTER TABLE `tickets` 
MODIFY COLUMN `prioridade` VARCHAR(50) NOT NULL DEFAULT 'normal';

-- Verificar resultado
SELECT 'Campo prioridade configurado com sucesso!' AS status;
SHOW COLUMNS FROM `tickets` LIKE 'prioridade';
