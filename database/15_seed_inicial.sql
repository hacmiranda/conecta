-- Fase 1 (Conecta CRPRS): dados iniciais mínimos para um banco novo.
-- Aplicar APÓS 9 a 14. O administrador é criado à parte (php database/seed_admin.php).
-- Aplicar com: "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p --default-character-set=utf8mb4 db_conecta < 15_seed_inicial.sql

-- Status das demandas (os nomes são usados pelo código; não renomear)
INSERT INTO `ticket_status` (`id`, `nome`, `descricao`, `ordem`, `congela_sla`, `cor`, `ativo`) VALUES
(1,  'novo',                 'Demanda recém-criada, aguardando atribuição ou início do atendimento', 1, 0, '#f79226', 1),
(2,  'em_atendimento',       'A demanda está sendo analisada ou trabalhada por um responsável', 2, 0, '#17a2b8', 1),
(3,  'aguardando_resposta',  'O responsável precisa de mais informações do solicitante para continuar', 4, 1, '#ffc107', 1),
(4,  'aguardando_terceiros', 'Depende de outro setor ou de um agente externo para resolução', 5, 1, '#6f42c1', 1),
(6,  'resolvido',            'A solução foi aplicada, mas aguarda a confirmação do solicitante para encerrar', 8, 1, '#28a745', 1),
(7,  'encerrado',            'A demanda foi atendida e oficialmente concluída', 9, 0, '#6c757d', 1),
(8,  'cancelado',            'A demanda foi aberta por engano ou a solicitação foi retirada pelo solicitante', 10, 0, '#dc3545', 1),
(9,  'reaberto',             'Uma demanda já encerrada ou resolvida voltou a precisar de tratamento', 11, 0, '#e83e8c', 1),
(11, 'aguardando_data',      'Aguardando data agendada para retomar o atendimento', 7, 1, '#9c27b0', 1),
(12, 'aguardando',           'Demanda na fila de atendimento ou aguardando ações gerais', 3, 1, '#ffc107', 1),
(13, 'nova_resposta',        'O solicitante respondeu à demanda', 12, 0, '#17a2b8', 1);

-- Prioridades com SLA (valores herdados do CRPDESK; descrições a revisar para o contexto de demandas)
INSERT INTO `ticket_prioridades` (`id`, `nome`, `descricao`, `sla_resposta_minutos`, `sla_resolucao_horas`, `cor`, `icone`, `ordem`, `ativo`) VALUES
(1, 'urgente', 'Falha crítica que impede o trabalho', 120, 4, '#dc3545', 'Urgente', 1, 1),
(2, 'alta',    'Item importante que afeta a produtividade', 240, 8, '#fd7e14', 'Alta', 2, 1),
(3, 'normal',  'Dúvidas e solicitações rotineiras', 720, 24, '#17a2b8', 'Normal', 3, 1),
(4, 'baixa',   'Melhorias e sugestões não urgentes', 1440, 40, '#28a745', 'Baixa', 4, 1);

-- Expediente padrão: segunda a sexta, 08:00-17:00 (1=Domingo ... 7=Sábado)
INSERT INTO `horarios_uteis` (`dia_semana`, `hora_inicio`, `hora_fim`, `ativo`) VALUES
(1, '08:00:00', '17:00:00', 0),
(2, '08:00:00', '17:00:00', 1),
(3, '08:00:00', '17:00:00', 1),
(4, '08:00:00', '17:00:00', 1),
(5, '08:00:00', '17:00:00', 1),
(6, '08:00:00', '17:00:00', 1),
(7, '08:00:00', '17:00:00', 0);

-- O setor "Geral" (criado na migração 9) ativa todo o catálogo, como o setores.php faz para setores novos
INSERT INTO `setor_status` (`setor_id`, `status_id`, `ativo`, `ordem`)
SELECT s.id, t.id, 1, t.ordem FROM `setores` s CROSS JOIN `ticket_status` t
WHERE NOT EXISTS (SELECT 1 FROM `setor_status` ss WHERE ss.setor_id = s.id AND ss.status_id = t.id);

INSERT INTO `setor_prioridades` (`setor_id`, `prioridade_id`, `ativo`, `ordem`)
SELECT s.id, p.id, 1, p.ordem FROM `setores` s CROSS JOIN `ticket_prioridades` p
WHERE NOT EXISTS (SELECT 1 FROM `setor_prioridades` sp WHERE sp.setor_id = s.id AND sp.prioridade_id = p.id);
