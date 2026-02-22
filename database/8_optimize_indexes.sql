-- CRPDESK - Otimização de Performance no Banco de Dados (Índices)
-- Arquivo: 8_optimize_indexes.sql
-- Propósito: Adicionada indexação agressiva às tabelas que sofrem maior tráfego 
-- em requisições de frontend (Listagem de Tickets e Painel Kanban) devido à ausência de
-- uma camada de cache local como Redis.

-- 1. Indexação Otimizada para Tickets
-- Auxilia profundamente nas buscas via Polling/SSE, Filtros e Ordenação 
ALTER TABLE `tickets` ADD INDEX `idx_tickets_status` (`status`);
ALTER TABLE `tickets` ADD INDEX `idx_tickets_usuario_id` (`usuario_id`);
ALTER TABLE `tickets` ADD INDEX `idx_tickets_agente_id` (`agente_id`);
ALTER TABLE `tickets` ADD INDEX `idx_tickets_criado_em` (`criado_em`);
ALTER TABLE `tickets` ADD INDEX `idx_tickets_prioridade` (`prioridade`);

-- 2. Indexação para Comentários
-- Otimiza a mesclagem e extração da Timeline dos tickets
ALTER TABLE `comentarios` ADD INDEX `idx_comentarios_ticket` (`ticket_id`);
ALTER TABLE `comentarios` ADD INDEX `idx_comentarios_criado_em` (`criado_em`);

-- 3. Otimizações para SSE e Carga do Kanban
-- Sempre que Server-Sent Events rodar o fetchNaoLidas(), 
-- será instantâneo usar um índice composto
ALTER TABLE `notificacoes` ADD INDEX `idx_notificacoes_user_lida` (`usuario_id`, `lida`);

-- 4. Anexos e Histórico
-- Otimiza a verificação diária de auditoria e a busca de arquivos atrelados ao Ticket
ALTER TABLE `anexos` ADD INDEX `idx_anexos_ticket` (`ticket_id`);
ALTER TABLE `ticket_historico` ADD INDEX `idx_historico_ticket` (`ticket_id`);
