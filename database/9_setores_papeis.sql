-- Fase 1: Setores, Papéis e Usuários
-- Aplicar com: "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p --default-character-set=utf8mb4 db_conecta < 9_setores_papeis.sql

-- 1. Tabela de setores
CREATE TABLE IF NOT EXISTS `setores` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setores_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Setor padrão para acomodar usuários/tickets existentes sem quebrar nada
INSERT INTO `setores` (`nome`, `ativo`) VALUES ('Geral', 1);
SET @setor_geral_id = LAST_INSERT_ID();

-- 3. Novas colunas em usuarios
ALTER TABLE `usuarios`
  ADD COLUMN `username` VARCHAR(100) DEFAULT NULL AFTER `nome`,
  ADD COLUMN `cargo` VARCHAR(100) DEFAULT NULL AFTER `tipo`,
  ADD COLUMN `setor_id` INT DEFAULT NULL AFTER `cargo`;

ALTER TABLE `usuarios`
  ADD UNIQUE KEY `uq_usuarios_username` (`username`),
  ADD KEY `idx_usuarios_setor_id` (`setor_id`),
  ADD CONSTRAINT `fk_usuarios_setor` FOREIGN KEY (`setor_id`) REFERENCES `setores` (`id`) ON DELETE SET NULL;

-- 4. Expandir enum de papéis: cliente (externo, sem login) / usuario / supervisor / admin
ALTER TABLE `usuarios`
  MODIFY COLUMN `tipo` ENUM('cliente','usuario','supervisor','admin') DEFAULT 'cliente';

-- 5. Migração de dados: agente vira usuario; agente/admin existentes entram no setor "Geral"
--    (admin fica com setor_id NULL — enxerga todos os setores independente de vínculo)
UPDATE `usuarios` SET `tipo` = 'usuario' WHERE `tipo` = 'agente';
UPDATE `usuarios` SET `setor_id` = @setor_geral_id WHERE `tipo` = 'usuario' AND `setor_id` IS NULL;

-- 6. Novas colunas em tickets
ALTER TABLE `tickets`
  ADD COLUMN `setor_id` INT DEFAULT NULL AFTER `categoria_id`,
  ADD COLUMN `atribuido_em` DATETIME DEFAULT NULL AFTER `agente_id`;

ALTER TABLE `tickets`
  ADD KEY `idx_tickets_setor_id` (`setor_id`),
  ADD CONSTRAINT `fk_tickets_setor` FOREIGN KEY (`setor_id`) REFERENCES `setores` (`id`) ON DELETE SET NULL;

-- 7. Tickets existentes entram no setor "Geral" para continuar aparecendo nas telas atuais
UPDATE `tickets` SET `setor_id` = @setor_geral_id WHERE `setor_id` IS NULL;
