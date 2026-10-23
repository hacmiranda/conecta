-- Histórico da conversa nos e-mails ao solicitante: só entram comentários marcados como
-- visíveis ao solicitante (e nunca notas internas). Marca:
--   1 = escrito pelo solicitante (ou por alguém copiado) ou comentário da equipe enviado a ele por e-mail
--   0 = comentário só da equipe (sem e-mail ao solicitante), fluxos internos (devolver/repassar/transferir), mesclagem
ALTER TABLE comentarios ADD COLUMN visivel_solicitante TINYINT(1) NOT NULL DEFAULT 0 AFTER interno;

-- Dados antigos: o que o solicitante (ou um cliente copiado) escreveu já era dele; comentários
-- antigos da equipe ficam ocultos, pois não há como saber quais foram enviados por e-mail.
UPDATE comentarios c
  JOIN usuarios u ON u.id = c.usuario_id
  JOIN tickets t ON t.id = c.ticket_id
   SET c.visivel_solicitante = 1
 WHERE c.interno = 0
   AND (u.tipo = 'cliente' OR c.usuario_id = t.usuario_id);
