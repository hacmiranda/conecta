-- Atualização da Coluna Status na Tabela Tickets
-- Adiciona os status ausentes: aguardando, aguardando_resposta, aguardando_terceiros, aguardando_aprovacao, aguardando_data
-- Mantém compatibilidade com os status antigos

ALTER TABLE tickets MODIFY COLUMN status ENUM(
    'novo',
    'aberto',
    'pendente',
    'resolvido',
    'fechado',
    'reaberto',
    'em_atendimento',
    'encerrado',
    'cancelado',
    'nova_resposta',
    'aguardando',
    'aguardando_resposta',
    'aguardando_terceiros',
    'aguardando_aprovacao',
    'aguardando_data'
) NOT NULL DEFAULT 'novo';
