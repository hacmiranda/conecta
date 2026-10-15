-- Fase 1 (Conecta CRPRS): renomeia os papéis para o vocabulário do negócio.
--   supervisor        -> gestor
--   usuario / agente  -> operador
-- Aplicar APÓS 9_setores_papeis.sql.
-- Aplicar com: "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p db_conecta < 14_papeis_gestor_operador.sql
-- Rodar uma única vez (MySQL 8.4 não aceita ADD COLUMN IF NOT EXISTS).

-- 1. Amplia o enum para aceitar valores antigos e novos ao mesmo tempo
ALTER TABLE `usuarios`
  MODIFY COLUMN `tipo` ENUM('cliente','agente','usuario','supervisor','operador','gestor','admin') DEFAULT 'cliente';

-- 2. Migra os dados existentes
UPDATE `usuarios` SET `tipo` = 'gestor'   WHERE `tipo` = 'supervisor';
UPDATE `usuarios` SET `tipo` = 'operador' WHERE `tipo` IN ('usuario', 'agente');

-- 3. Restringe o enum aos papéis finais
ALTER TABLE `usuarios`
  MODIFY COLUMN `tipo` ENUM('cliente','operador','gestor','admin') DEFAULT 'cliente';
