-- Migração 21 — Conecta CRPRS (Fase 10 da auditoria: integridade e exclusão lógica)
-- Rodar pelo runner:  php database/migrar.php   (ou uma única vez, à mão, depois da 20)
--
-- 1) tickets.status vira texto livre (validado pelo catálogo ticket_status, como a prioridade);
--    antes era um ENUM fixo e um status novo cadastrado no catálogo não podia ser gravado.
-- 2) Exclusão lógica: tickets.excluido_em / excluido_por (lixeira, visível só ao administrador).
-- 3) Chaves estrangeiras (antes só havia nas tabelas de setor). Registros órfãos são
--    tratados antes: filhos sem demanda são removidos; vínculos inválidos viram NULL.
-- 4) Todas as tabelas em utf8mb4_0900_ai_ci (havia utf8mb3 e utf8mb4_unicode_ci misturados).
-- 5) Macros por setor (NULL = visível a todos os setores).
-- 6) Tabelas legadas (historico, ticket_historico_status, ticket_prioridade_sla): os "merge" de
--    historico passam para ticket_historico e as tabelas são removidas quando não restam dados.

-- 1) e 2) -----------------------------------------------------------------------------------
ALTER TABLE tickets
    MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'novo',
    ADD COLUMN excluido_em DATETIME NULL DEFAULT NULL,
    ADD COLUMN excluido_por INT NULL DEFAULT NULL,
    ADD INDEX idx_tickets_excluido_em (excluido_em);

-- 6) (antes das FKs) mescla do legado para a linha do tempo atual -------------------------------
INSERT INTO ticket_historico (ticket_id, usuario_id, acao, valor_anterior, valor_novo, criado_em)
SELECT h.ticket_id, h.usuario_id, 'merge', NULL, LEFT(h.valor_novo, 255), h.criado_em
FROM historico h
WHERE h.acao = 'merge'
  AND EXISTS (SELECT 1 FROM tickets t WHERE t.id = h.ticket_id)
  AND NOT EXISTS (SELECT 1 FROM ticket_historico th
                  WHERE th.ticket_id = h.ticket_id AND th.acao = 'merge' AND th.valor_novo = LEFT(h.valor_novo, 255));

SET @n := (SELECT COUNT(*) FROM historico WHERE acao <> 'merge');
SET @sql := IF(@n = 0, 'DROP TABLE historico', 'SELECT 1');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM ticket_historico_status);
SET @sql := IF(@n = 0, 'DROP TABLE ticket_historico_status', 'SELECT 1');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @n := (SELECT COUNT(*) FROM ticket_prioridade_sla);
SET @sql := IF(@n = 0, 'DROP TABLE ticket_prioridade_sla', 'SELECT 1');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

-- 3) órfãos --------------------------------------------------------------------------------------
DELETE FROM comentarios_nao_lidos WHERE ticket_id NOT IN (SELECT id FROM tickets);
DELETE FROM lembretes WHERE ticket_id NOT IN (SELECT id FROM tickets);
DELETE FROM ticket_sla WHERE ticket_id NOT IN (SELECT id FROM tickets);
DELETE FROM ticket_historico WHERE ticket_id NOT IN (SELECT id FROM tickets);
DELETE FROM anexos WHERE ticket_id NOT IN (SELECT id FROM tickets);
DELETE FROM comentarios WHERE ticket_id NOT IN (SELECT id FROM tickets);
UPDATE anexos SET comentario_id = NULL WHERE comentario_id IS NOT NULL AND comentario_id NOT IN (SELECT id FROM comentarios);
UPDATE tickets SET usuario_id = NULL WHERE usuario_id IS NOT NULL AND usuario_id NOT IN (SELECT id FROM usuarios);
UPDATE tickets SET agente_id = NULL WHERE agente_id IS NOT NULL AND agente_id NOT IN (SELECT id FROM usuarios);
UPDATE tickets SET fechado_por = NULL WHERE fechado_por IS NOT NULL AND fechado_por NOT IN (SELECT id FROM usuarios);
UPDATE tickets SET categoria_id = NULL WHERE categoria_id IS NOT NULL AND categoria_id NOT IN (SELECT id FROM categorias);

-- índice duplicado em comentarios.ticket_id (o índice "ticket_id" continua)
ALTER TABLE comentarios DROP INDEX idx_comentarios_ticket;

-- 3) chaves estrangeiras ---------------------------------------------------------------------------
ALTER TABLE tickets
    ADD CONSTRAINT fk_tickets_usuario     FOREIGN KEY (usuario_id)   REFERENCES usuarios (id)   ON DELETE RESTRICT,
    ADD CONSTRAINT fk_tickets_agente      FOREIGN KEY (agente_id)    REFERENCES usuarios (id)   ON DELETE SET NULL,
    ADD CONSTRAINT fk_tickets_fechado_por FOREIGN KEY (fechado_por)  REFERENCES usuarios (id)   ON DELETE SET NULL,
    ADD CONSTRAINT fk_tickets_excluido_por FOREIGN KEY (excluido_por) REFERENCES usuarios (id)  ON DELETE SET NULL,
    ADD CONSTRAINT fk_tickets_categoria   FOREIGN KEY (categoria_id) REFERENCES categorias (id) ON DELETE SET NULL;

ALTER TABLE comentarios
    ADD CONSTRAINT fk_comentarios_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE;

ALTER TABLE anexos
    ADD CONSTRAINT fk_anexos_ticket     FOREIGN KEY (ticket_id)     REFERENCES tickets (id)     ON DELETE CASCADE,
    ADD CONSTRAINT fk_anexos_comentario FOREIGN KEY (comentario_id) REFERENCES comentarios (id) ON DELETE CASCADE;

ALTER TABLE ticket_historico
    ADD CONSTRAINT fk_ticket_historico_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE;

ALTER TABLE ticket_sla
    ADD CONSTRAINT fk_ticket_sla_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE;

ALTER TABLE lembretes
    ADD CONSTRAINT fk_lembretes_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE;

ALTER TABLE comentarios_nao_lidos
    ADD CONSTRAINT fk_nao_lidos_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE;

-- 4) charset/collation uniformes --------------------------------------------------------------------
ALTER TABLE setor_emails          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE setor_prioridades     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE setor_status          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE ticket_agente_log     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE ticket_setor_log      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE comentarios_nao_lidos CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE email_logs            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE feriados              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE horarios_uteis        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE lembretes             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE macros                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE notificacoes          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- 5) macros por setor ----------------------------------------------------------------------------------
ALTER TABLE macros
    ADD COLUMN setor_id INT NULL DEFAULT NULL AFTER usuario_id,
    ADD CONSTRAINT fk_macros_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE;

UPDATE macros m INNER JOIN usuarios u ON u.id = m.usuario_id SET m.setor_id = u.setor_id WHERE u.setor_id IS NOT NULL;
