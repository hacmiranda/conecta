-- =====================================================
-- Script: Atualizar Status "Aberto" para "Novo" e Adicionar "Aguardando"
-- Data: 2025-01-31
-- Descrição: 
--   1. Substitui todos os status "aberto" por "novo" nos tickets
--   2. Adiciona o status "aguardando" na tabela ticket_status
--   3. Atualiza histórico para refletir a mudança
-- =====================================================

USE crpdesk;

-- 1. Atualizar tickets com status "aberto" para "novo"
UPDATE tickets 
SET status = 'novo' 
WHERE status = 'aberto';

-- 2. Atualizar histórico de tickets (valor_anterior e valor_novo)
UPDATE ticket_historico 
SET valor_anterior = 'novo' 
WHERE acao = 'status' AND valor_anterior = 'aberto';

UPDATE ticket_historico 
SET valor_novo = 'novo' 
WHERE acao = 'status' AND valor_novo = 'aberto';

-- 3. Verificar se a tabela ticket_status existe, se não, criar
CREATE TABLE IF NOT EXISTS ticket_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    cor VARCHAR(20) NOT NULL,
    icone VARCHAR(10),
    congela_sla BOOLEAN DEFAULT FALSE,
    ordem INT DEFAULT 0,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Inserir/Atualizar status "aguardando" se não existir
INSERT INTO ticket_status (nome, label, cor, icone, congela_sla, ordem, ativo) 
VALUES ('aguardando', 'Aguardando', '#ffc107', '⏳', TRUE, 3, TRUE)
ON DUPLICATE KEY UPDATE 
    label = 'Aguardando',
    cor = '#ffc107',
    icone = '⏳',
    congela_sla = TRUE,
    ordem = 3,
    ativo = TRUE;

-- 5. Atualizar status "novo" se já existir na tabela
UPDATE ticket_status 
SET label = 'Novo', 
    cor = '#f79226', 
    icone = '🆕',
    ordem = 1
WHERE nome = 'novo';

-- 6. Inserir status "novo" se não existir
INSERT IGNORE INTO ticket_status (nome, label, cor, icone, congela_sla, ordem, ativo) 
VALUES ('novo', 'Novo', '#f79226', '🆕', FALSE, 1, TRUE);

-- 7. Remover status "aberto" da tabela ticket_status se existir
DELETE FROM ticket_status WHERE nome = 'aberto';

-- =====================================================
-- Verificação
-- =====================================================

-- Contar tickets com cada status
SELECT status, COUNT(*) as total 
FROM tickets 
GROUP BY status 
ORDER BY total DESC;

-- Listar todos os status cadastrados
SELECT * FROM ticket_status ORDER BY ordem;

-- =====================================================
-- Resultado esperado:
-- - Nenhum ticket deve ter status "aberto"
-- - Todos devem ter status "novo" (se eram "aberto")
-- - Status "aguardando" deve estar cadastrado
-- =====================================================
