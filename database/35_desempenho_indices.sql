-- Desempenho e integridade (auditoria fase 6, 26/10/2026)

-- Texto puro da descrição, para a busca e para o resumo das listas: a descrição (HTML, pode ter
-- imagens em base64 de vários MB) não é mais varrida pela busca nem enviada nas listagens.
-- O código preenche nas novas demandas (HtmlSanitizer::textoParaBusca); aqui, as existentes.
ALTER TABLE tickets ADD COLUMN descricao_texto MEDIUMTEXT NULL AFTER descricao;
UPDATE tickets
   SET descricao_texto = LEFT(TRIM(REGEXP_REPLACE(REGEXP_REPLACE(descricao, '<[^>]*>', ' '), '[[:space:]]+', ' ')), 5000);

-- Uma linha de SLA por demanda (o MySQL descarta sozinho o índice simples que servia à chave estrangeira)
ALTER TABLE ticket_sla ADD UNIQUE KEY uq_ticket_sla_ticket (ticket_id);

-- Login, e-mail recebido e cópias procuram usuário por e-mail: sem índice, varriam a tabela.
-- Único (a collation já ignora maiúsculas): conferido que não há repetidos.
ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_email (email);

-- Índices repetidos (mesmas colunas de outro índice)
ALTER TABLE anexos DROP INDEX ticket_id;
ALTER TABLE auditoria DROP INDEX idx_auditoria_acao, DROP INDEX idx_criado_em, DROP INDEX idx_tabela, DROP INDEX usuario_id;
ALTER TABLE emails_processados DROP INDEX idx_message_id;

-- Limpeza periódica das notificações lidas antigas
ALTER TABLE notificacoes ADD INDEX idx_notificacoes_lida_criado (lida, criado_em);

-- Excluir um usuário não apaga mais, em cascata, o rastro de quem atendeu cada demanda
ALTER TABLE ticket_agente_log DROP FOREIGN KEY ticket_agente_log_ibfk_2;
ALTER TABLE ticket_agente_log ADD CONSTRAINT fk_ticket_agente_log_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT;
