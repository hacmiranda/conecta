-- ========================================
-- DEPLOY PRODUÇÃO - CRPDESK
-- ========================================

-- ========================================
-- 1. ADICIONAR COLUNA AVATAR NA TABELA USUARIOS
-- Data: 31/01/2025
-- Ref: Implementação do sistema de avatar
-- ========================================
ALTER TABLE usuarios ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER senha;
