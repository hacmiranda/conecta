-- Fase 6 das melhorias adiadas: nota interna no comentário.
-- Nota interna nunca vai por e-mail para o solicitante; aparece destacada na timeline.
ALTER TABLE comentarios ADD COLUMN interno TINYINT(1) NOT NULL DEFAULT 0;
