-- 19/11/2026: setores de coordenação e prioridade "Baixíssima (Sem SLA)".
--
-- 1) setores.setor_coordenacao: setor onde só trabalha o(a) coordenador(a), que não envia relatório de
--    atividades nem pesquisa de satisfação. Some do seletor de setor do cabeçalho e das listas de setores do
--    Cruzamento de Dados. O admin liga e desliga em Configurações → Setores. Começam marcados os três setores
--    de coordenação sem equipe; "Coordenação Geral" fica de fora (tem operador que envia relatórios).
ALTER TABLE setores
    ADD COLUMN setor_coordenacao TINYINT(1) NOT NULL DEFAULT 0 AFTER pesquisa_painel;

UPDATE setores SET setor_coordenacao = 1
 WHERE nome IN ('Coordenação Administrativa', 'Coordenação Financeira', 'Coordenação Técnica');

-- 2) ticket_prioridades.sem_sla: a prioridade não tem SLA. Enquanto a demanda está nela, o SLA fica pausado
--    (como num status que congela) e a demanda não entra nas médias de tempo do Dashboard e dos Relatórios.
ALTER TABLE ticket_prioridades
    ADD COLUMN sem_sla TINYINT(1) NOT NULL DEFAULT 0 AFTER sla_resolucao_horas;

INSERT INTO ticket_prioridades (nome, descricao, sla_resposta_minutos, sla_resolucao_horas, sem_sla, cor, icone, ordem, ativo)
VALUES ('baixissima', 'Sem prazo: o SLA não conta e a demanda fica fora dos tempos médios', 0, 0, 1, '#8b837b', 'Sem SLA', 5, 1);

-- Ativa a nova prioridade em todos os setores que já têm a ativação configurada (setor novo recebe todas
-- pelo SetorService::criar)
INSERT INTO setor_prioridades (setor_id, prioridade_id, ativo, ordem)
SELECT DISTINCT sp.setor_id, p.id, 1, 5
  FROM setor_prioridades sp
  JOIN ticket_prioridades p ON p.nome = 'baixissima'
 WHERE NOT EXISTS (SELECT 1 FROM setor_prioridades x WHERE x.setor_id = sp.setor_id AND x.prioridade_id = p.id);
