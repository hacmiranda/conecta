-- Adicionar coluna email_solicitante na tabela tickets
-- Executar no PHPMyAdmin da Locaweb

ALTER TABLE tickets 
ADD COLUMN email_solicitante VARCHAR(255) NULL 
AFTER usuario_id;

-- Atualizar tickets existentes com email do usuário
UPDATE tickets t
INNER JOIN usuarios u ON t.usuario_id = u.id
SET t.email_solicitante = u.email
WHERE t.email_solicitante IS NULL;
