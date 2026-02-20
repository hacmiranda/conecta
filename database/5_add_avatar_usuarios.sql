-- ============================================================
-- Adicionar coluna avatar na tabela usuarios
-- Data: 31/01/2025
-- Descrição: Sistema de upload e exibição de avatares
-- ============================================================
ALTER TABLE usuarios 
ADD COLUMN avatar VARCHAR(255) DEFAULT NULL 
AFTER senha;
