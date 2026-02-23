-- Script de Banco para Integracoes API (M2M) 
-- Adiciona rastreabilidade de chamados criados externamente (ex: Ouvidoria)

ALTER TABLE tickets ADD COLUMN origem_api VARCHAR(50) DEFAULT 'interna' AFTER usuario_id;
