-- Um e-mail enviado a duas caixas de setor (To: setor A; Cc: setor B) abre uma demanda em cada setor.
-- Antes o Message-ID era único no sistema inteiro: a segunda caixa achava que já tinha processado.
-- A caixa global legada (.env) grava setor_email_id NULL; ali a duplicidade é conferida pelo código
-- (message_id + setor_email_id <=> NULL), já que o MySQL trata NULLs como distintos no índice único.
ALTER TABLE emails_processados
    DROP INDEX message_id,
    ADD UNIQUE KEY uq_emails_processados_caixa (message_id, setor_email_id);

-- Tentativas com falha também contadas por caixa
ALTER TABLE emails_erro
    ADD COLUMN setor_email_id INT NULL AFTER message_id;
