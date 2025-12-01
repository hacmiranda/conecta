-- ============================================
-- CORREÇÃO FINAL - Campo prioridade na ticket_sla
-- Data: 01/12/2025
-- ============================================

-- Adicionar DEFAULT ao campo prioridade da tabela ticket_sla
ALTER TABLE `ticket_sla` 
MODIFY COLUMN `prioridade` VARCHAR(50) NOT NULL DEFAULT 'normal';

-- Adicionar DEFAULT aos campos de SLA também
ALTER TABLE `ticket_sla` 
MODIFY COLUMN `sla_resposta_minutos` INT(11) NOT NULL DEFAULT 480;

ALTER TABLE `ticket_sla` 
MODIFY COLUMN `sla_resolucao_horas` INT(11) NOT NULL DEFAULT 24;

-- Verificar
SHOW COLUMNS FROM `ticket_sla` LIKE 'prioridade';
SHOW COLUMNS FROM `ticket_sla` LIKE 'sla_%';

SELECT 'Campos ticket_sla corrigidos com sucesso!' AS status;
