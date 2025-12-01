-- ============================================
-- CORREÇÃO DEFINITIVA - Desabilitar TRIGGER
-- Data: 01/12/2025
-- ============================================

-- O problema está no TRIGGER que tenta buscar SLA
-- Vamos remover o TRIGGER temporariamente

DROP TRIGGER IF EXISTS `tr_ticket_insert_sla`;

-- Verificar
SHOW TRIGGERS LIKE 'tickets';

SELECT 'TRIGGER removido com sucesso!' AS status;
