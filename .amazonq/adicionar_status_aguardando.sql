-- Adicionar status "Aguardando" genérico
-- Executar no PHPMyAdmin da Locaweb e no banco local

INSERT INTO ticket_status (nome, descricao, ordem, congela_sla, cor, ativo) 
VALUES ('aguardando', 'Ticket na fila de atendimento ou aguardando ações gerais', 3, 1, '#ffc107', 1);

-- Reordenar outros status
UPDATE ticket_status SET ordem = 4 WHERE nome = 'aguardando_resposta';
UPDATE ticket_status SET ordem = 5 WHERE nome = 'aguardando_terceiros';
UPDATE ticket_status SET ordem = 6 WHERE nome = 'aguardando_aprovacao';
UPDATE ticket_status SET ordem = 7 WHERE nome = 'aguardando_data';
UPDATE ticket_status SET ordem = 8 WHERE nome = 'resolvido';
UPDATE ticket_status SET ordem = 9 WHERE nome = 'encerrado';
UPDATE ticket_status SET ordem = 10 WHERE nome = 'cancelado';
UPDATE ticket_status SET ordem = 11 WHERE nome = 'reaberto';
