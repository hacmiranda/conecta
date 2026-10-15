-- Migração 19 — Conecta CRPRS (Fase 8 da auditoria)
-- Rodar UMA vez, depois da 18. Banco local: db_conecta.
--
-- 1) Lembretes podem ser cancelados automaticamente (demanda encerrada/cancelada ou
--    lembrete direcionado em demanda transferida para outro setor). O lembrete fica
--    registrado, com o motivo, em vez de ser apagado.
-- 2) E-mails recebidos que não viram demanda (caixas do próprio sistema, respostas
--    automáticas) ficam registrados como "ignorado", com a observação do motivo.

ALTER TABLE lembretes
    ADD COLUMN cancelado_em DATETIME NULL DEFAULT NULL AFTER notificado,
    ADD COLUMN cancelado_motivo VARCHAR(255) NULL DEFAULT NULL AFTER cancelado_em;

ALTER TABLE emails_processados
    MODIFY COLUMN tipo ENUM('novo_ticket','comentario','erro','ignorado') NOT NULL,
    ADD COLUMN observacao VARCHAR(255) NULL DEFAULT NULL AFTER tipo;
