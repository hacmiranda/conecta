-- 24/11/2026: SLA por setor (desenho 2026-11-24, seção 5.1).
--
-- 1) setores.sla_ativo: interruptor do SLA do setor (0 = o setor não tem SLA).
-- 2) setor_prioridades ganha os prazos próprios do setor para cada prioridade: resposta e resolução em
--    dias úteis + minutos (dia útil = jornada do setor); sla_sem_prazo = a prioridade não tem prazo no setor.
-- 3) Carga: só o "Setor de TI" continua com SLA, com os prazos de hoje do catálogo global; os demais ficam
--    desligados e as demandas abertas deles têm o SLA pausado.

ALTER TABLE setores
    ADD COLUMN sla_ativo TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE setor_prioridades
    ADD COLUMN sla_resposta_dias SMALLINT UNSIGNED NULL,
    ADD COLUMN sla_resposta_minutos SMALLINT UNSIGNED NULL,
    ADD COLUMN sla_resolucao_dias SMALLINT UNSIGNED NULL,
    ADD COLUMN sla_resolucao_minutos SMALLINT UNSIGNED NULL,
    ADD COLUMN sla_sem_prazo TINYINT(1) NOT NULL DEFAULT 0;

-- Setor de TI: SLA ligado, prazos iguais ao catálogo (resolução em horas x 60 = minutos)
UPDATE setores SET sla_ativo = 1 WHERE nome = 'Setor de TI';

UPDATE setor_prioridades sp
  JOIN setores st ON st.id = sp.setor_id
  JOIN ticket_prioridades p ON p.id = sp.prioridade_id
   SET sp.sla_resposta_dias = 0,
       sp.sla_resposta_minutos = p.sla_resposta_minutos,
       sp.sla_resolucao_dias = 0,
       sp.sla_resolucao_minutos = p.sla_resolucao_horas * 60,
       sp.sla_sem_prazo = p.sem_sla
 WHERE st.nome = 'Setor de TI';

-- Demandas abertas dos setores sem SLA: contagem pausada (mesmo formato de AcessoService::registrarAbertura)
UPDATE ticket_sla s
  JOIN tickets t ON t.id = s.ticket_id
  JOIN setores st ON st.id = t.setor_id
   SET s.pausado = TRUE, s.pausado_em = NOW()
 WHERE s.pausado_em IS NULL
   AND st.sla_ativo = 0
   AND t.excluido_em IS NULL
   AND t.mesclada_em IS NULL
   AND t.status NOT IN ('resolvido','encerrado','cancelado','fechado');
