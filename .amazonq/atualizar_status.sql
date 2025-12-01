-- Atualizar status do sistema
-- Executar no PHPMyAdmin da Locaweb e no banco local

-- 1. Renomear status 'aberto' para 'novo'
UPDATE ticket_status SET 
    nome = 'novo',
    descricao = 'Ticket recém criado, aguardando atribuição ou início de atendimento',
    ordem = 1
WHERE nome = 'aberto';

-- 2. Atualizar tickets existentes com status 'aberto' para 'novo'
UPDATE tickets SET status = 'novo' WHERE status = 'aberto';

-- 3. Atualizar histórico
UPDATE ticket_historico SET valor_anterior = 'novo' WHERE valor_anterior = 'aberto';
UPDATE ticket_historico SET valor_novo = 'novo' WHERE valor_novo = 'aberto';
