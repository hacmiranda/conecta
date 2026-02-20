-- Arquivo: 1_update_status_tickets.sql
-- Descrição: Atualiza o ENUM de status da tabela tickets e insere o novo status 'nova_resposta'

-- 1. Atualizar o ENUM da tabela tickets para incluir 'nova_resposta'
ALTER TABLE tickets MODIFY COLUMN status ENUM('novo', 'aberto', 'pendente', 'resolvido', 'fechado', 'reaberto', 'em_atendimento', 'encerrado', 'cancelado', 'nova_resposta') NOT NULL DEFAULT 'novo';

-- 2. Inserir o novo status na tabela auxiliar ticket_status (se ela existir e for usada)
-- Usamos INSERT IGNORE para evitar erro se já existir
INSERT IGNORE INTO ticket_status (nome, descricao, cor, ativo, congela_sla) 
VALUES ('nova_resposta', 'Cliente respondeu ao ticket', '#17a2b8', 1, 0);
