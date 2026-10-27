-- Colunas de tickets que nenhum código grava nem lê (herança do CRPDESK; os tempos de SLA ficam em
-- ticket_sla e o "não lido" em comentarios_nao_lidos). Remoção aprovada na auditoria fase 8 (27/10/2026).
ALTER TABLE tickets
    DROP COLUMN status_anterior,
    DROP COLUMN tempo_primeiro_atendimento,
    DROP COLUMN tempo_total_segundos,
    DROP COLUMN tempo_em_espera_segundos,
    DROP COLUMN iniciou_espera_em,
    DROP COLUMN tem_comentario_nao_lido,
    DROP COLUMN sla_vencido;
