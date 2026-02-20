-- Atualizar ENUM da coluna status na tabela tickets
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
