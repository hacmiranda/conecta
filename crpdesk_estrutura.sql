-- phpMyAdmin SQL Dump
-- version 4.7.7
-- https://www.phpmyadmin.net/
--
-- Host: crpdesk.vpshost0269.mysql.dbaas.com.br
-- Generation Time: 01-Dez-2025 às 14:45
-- Versão do servidor: 5.7.32-35-log
-- PHP Version: 5.6.40-0+deb8u12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `crpdesk`
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `anexos`
--

CREATE TABLE `anexos` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `comentario_id` int(11) DEFAULT NULL,
  `nome_original` varchar(255) NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `tamanho` int(11) NOT NULL,
  `tipo` varchar(100) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `auditoria`
--

CREATE TABLE `auditoria` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `acao` varchar(100) NOT NULL,
  `tabela` varchar(50) NOT NULL,
  `registro_id` int(11) DEFAULT NULL,
  `dados_anteriores` text,
  `dados_novos` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `sessao_id` varchar(100) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `auditoria_login`
--

CREATE TABLE `auditoria_login` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `acao` enum('login_sucesso','login_falha','logout') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `sessao_id` varchar(100) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `auditoria_tickets`
--

CREATE TABLE `auditoria_tickets` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `campo` varchar(50) NOT NULL,
  `valor_anterior` text,
  `valor_novo` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `categorias`
--

CREATE TABLE `categorias` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `cor` varchar(7) DEFAULT '#6c757d',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `macrocategoria_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `comentarios`
--

CREATE TABLE `comentarios` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `comentario` text NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `comentarios_nao_lidos`
--

CREATE TABLE `comentarios_nao_lidos` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `comentario_id` int(11) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `feriados`
--

CREATE TABLE `feriados` (
  `id` int(11) NOT NULL,
  `data` date NOT NULL,
  `descricao` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorrente` tinyint(1) DEFAULT '0' COMMENT 'Se TRUE, repete todo ano',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `historico`
--

CREATE TABLE `historico` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `acao` varchar(50) NOT NULL,
  `valor_anterior` text,
  `valor_novo` text,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `horarios_uteis`
--

CREATE TABLE `horarios_uteis` (
  `id` int(11) NOT NULL,
  `dia_semana` int(11) NOT NULL COMMENT '1=Domingo, 2=Segunda, ..., 7=Sßbado',
  `hora_inicio` time NOT NULL,
  `hora_fim` time NOT NULL,
  `ativo` tinyint(1) DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `lembretes`
--

CREATE TABLE `lembretes` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `data_lembrete` date NOT NULL,
  `hora_lembrete` time NOT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `agente_id` int(11) DEFAULT NULL,
  `notificado` tinyint(1) DEFAULT '0',
  `criado_por` int(11) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `macrocategorias`
--

CREATE TABLE `macrocategorias` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `email_solicitante` varchar(255) DEFAULT NULL,
  `agente_id` int(11) DEFAULT NULL,
  `status` enum('novo','em_atendimento','aguardando','aguardando_resposta','aguardando_terceiros','aguardando_data','resolvido','encerrado','cancelado','reaberto') DEFAULT 'novo',
  `status_anterior` varchar(50) DEFAULT NULL,
  `prioridade` varchar(50) DEFAULT 'normal',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `categoria_id` int(11) DEFAULT NULL,
  `fechado_por` int(11) DEFAULT NULL,
  `fechado_em` timestamp NULL DEFAULT NULL,
  `tempo_primeiro_atendimento` datetime DEFAULT NULL,
  `tempo_total_segundos` int(11) DEFAULT '0',
  `tempo_em_espera_segundos` int(11) DEFAULT '0',
  `iniciou_espera_em` datetime DEFAULT NULL,
  `tem_comentario_nao_lido` tinyint(1) DEFAULT '0',
  `reaberto_contador` int(11) DEFAULT '0',
  `sla_vencido` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Acionadores `tickets`
--
DELIMITER $$
CREATE TRIGGER `tr_ticket_insert_sla` AFTER INSERT ON `tickets` FOR EACH ROW BEGIN
    DECLARE sla_resp INT;
    DECLARE sla_resol INT;
    
    SELECT sla_resposta_minutos, sla_resolucao_horas 
    INTO sla_resp, sla_resol
    FROM ticket_prioridades 
    WHERE nome = NEW.prioridade;
    
    INSERT INTO ticket_sla (
        ticket_id, prioridade, sla_resposta_minutos, sla_resolucao_horas
    ) VALUES (
        NEW.id, NEW.prioridade, sla_resp, sla_resol
    );
    
    INSERT INTO ticket_historico_status (
        ticket_id, status_anterior, status_novo, usuario_id
    ) VALUES (
        NEW.id, NULL, NEW.status, NEW.usuario_id
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_ticket_update_status` AFTER UPDATE ON `tickets` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        UPDATE ticket_historico_status 
        SET data_fim = NOW()
        WHERE ticket_id = NEW.id AND data_fim IS NULL;
        
        INSERT INTO ticket_historico_status (
            ticket_id, status_anterior, status_novo, usuario_id
        ) VALUES (
            NEW.id, OLD.status, NEW.status, COALESCE(NEW.agente_id, NEW.usuario_id)
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_historico`
--

CREATE TABLE `ticket_historico` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `acao` varchar(50) NOT NULL,
  `valor_anterior` varchar(255) DEFAULT NULL,
  `valor_novo` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_historico_status`
--

CREATE TABLE `ticket_historico_status` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `status_anterior` varchar(50) DEFAULT NULL,
  `status_novo` varchar(50) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `observacoes` text,
  `data_inicio` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_fim` timestamp NULL DEFAULT NULL,
  `tempo_no_status_segundos` int(11) DEFAULT '0',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_prioridades`
--

CREATE TABLE `ticket_prioridades` (
  `id` int(11) NOT NULL,
  `nome` varchar(50) NOT NULL,
  `descricao` text,
  `sla_resposta_minutos` int(11) NOT NULL,
  `sla_resolucao_horas` int(11) NOT NULL,
  `cor` varchar(7) DEFAULT '#6c757d',
  `icone` varchar(10) DEFAULT 'Ô×í´©Å',
  `ordem` int(11) NOT NULL,
  `ativo` tinyint(1) DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_prioridade_sla`
--

CREATE TABLE `ticket_prioridade_sla` (
  `id` int(11) NOT NULL,
  `prioridade` varchar(20) NOT NULL,
  `sla_resposta_horas` int(11) NOT NULL,
  `sla_resolucao_horas` int(11) NOT NULL,
  `descricao` text,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_sla`
--

CREATE TABLE `ticket_sla` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `prioridade` varchar(50) NOT NULL,
  `sla_resposta_minutos` int(11) NOT NULL,
  `sla_resolucao_horas` int(11) NOT NULL,
  `primeira_resposta_em` timestamp NULL DEFAULT NULL,
  `sla_resposta_vencido` tinyint(1) DEFAULT '0',
  `resolvido_em` timestamp NULL DEFAULT NULL,
  `sla_resolucao_vencido` tinyint(1) DEFAULT '0',
  `tempo_pausado_segundos` int(11) DEFAULT '0',
  `pausado_em` timestamp NULL DEFAULT NULL,
  `pausado` tinyint(1) DEFAULT '0',
  `tempo_total_segundos` int(11) DEFAULT '0',
  `tempo_primeira_resposta_segundos` int(11) DEFAULT '0',
  `tempo_resolucao_segundos` int(11) DEFAULT '0',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_status`
--

CREATE TABLE `ticket_status` (
  `id` int(11) NOT NULL,
  `nome` varchar(50) NOT NULL,
  `descricao` text,
  `ordem` int(11) NOT NULL,
  `congela_sla` tinyint(1) DEFAULT '0',
  `cor` varchar(7) DEFAULT '#6c757d',
  `ativo` tinyint(1) DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `tokens_recuperacao`
--

CREATE TABLE `tokens_recuperacao` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expira_em` datetime NOT NULL,
  `usado` tinyint(1) DEFAULT '0',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `anydesk_id` varchar(20) DEFAULT NULL,
  `tipo` enum('cliente','agente','admin') DEFAULT 'cliente',
  `ativo` tinyint(1) DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `senha` varchar(255) DEFAULT '123456',
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expira` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_tickets_sla`
-- (See below for the actual view)
--
CREATE TABLE `vw_tickets_sla` (
);

-- --------------------------------------------------------

--
-- Structure for view `vw_tickets_sla`
--
DROP TABLE IF EXISTS `vw_tickets_sla`;

CREATE ALGORITHM=UNDEFINED DEFINER=`crpdesk`@`%` SQL SECURITY DEFINER VIEW `vw_tickets_sla`  AS  select `t`.`id` AS `id`,`t`.`titulo` AS `titulo`,`t`.`status` AS `status`,`t`.`prioridade` AS `prioridade`,`t`.`criado_em` AS `criado_em`,`u`.`nome` AS `solicitante`,`a`.`nome` AS `agente`,`s`.`sla_resposta_minutos` AS `sla_resposta_minutos`,`s`.`sla_resolucao_horas` AS `sla_resolucao_horas`,`s`.`primeira_resposta_em` AS `primeira_resposta_em`,`s`.`resolvido_em` AS `resolvido_em`,`s`.`sla_resposta_vencido` AS `sla_resposta_vencido`,`s`.`sla_resolucao_vencido` AS `sla_resolucao_vencido`,`s`.`tempo_pausado_segundos` AS `tempo_pausado_segundos`,`s`.`tempo_primeira_resposta_segundos` AS `tempo_primeira_resposta_segundos`,`s`.`tempo_resolucao_segundos` AS `tempo_resolucao_segundos`,(case when (isnull(`s`.`primeira_resposta_em`) and (`s`.`pausado` = 0)) then ((`s`.`sla_resposta_minutos` * 60) - `calcular_segundos_uteis`(`t`.`criado_em`,now())) else NULL end) AS `tempo_restante_resposta_segundos`,(case when (isnull(`s`.`resolvido_em`) and (`s`.`pausado` = 0)) then ((`s`.`sla_resolucao_horas` * 3600) - (`calcular_segundos_uteis`(`t`.`criado_em`,now()) - `s`.`tempo_pausado_segundos`)) else NULL end) AS `tempo_restante_resolucao_segundos` from (((`tickets` `t` left join `usuarios` `u` on((`t`.`usuario_id` = `u`.`id`))) left join `usuarios` `a` on((`t`.`agente_id` = `a`.`id`))) left join `ticket_sla` `s` on((`t`.`id` = `s`.`ticket_id`))) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `anexos`
--
ALTER TABLE `anexos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `idx_comentario_id` (`comentario_id`);

--
-- Indexes for table `auditoria`
--
ALTER TABLE `auditoria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `idx_acao` (`acao`),
  ADD KEY `idx_tabela` (`tabela`),
  ADD KEY `idx_criado_em` (`criado_em`),
  ADD KEY `idx_auditoria_criado_em` (`criado_em`),
  ADD KEY `idx_auditoria_acao` (`acao`),
  ADD KEY `idx_auditoria_usuario` (`usuario_id`),
  ADD KEY `idx_auditoria_tabela` (`tabela`);

--
-- Indexes for table `auditoria_login`
--
ALTER TABLE `auditoria_login`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_usuario` (`usuario_id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_acao` (`acao`),
  ADD KEY `idx_criado` (`criado_em`);

--
-- Indexes for table `auditoria_tickets`
--
ALTER TABLE `auditoria_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket` (`ticket_id`),
  ADD KEY `idx_usuario` (`usuario_id`),
  ADD KEY `idx_criado` (`criado_em`);

--
-- Indexes for table `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Indexes for table `comentarios`
--
ALTER TABLE `comentarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indexes for table `comentarios_nao_lidos`
--
ALTER TABLE `comentarios_nao_lidos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_ticket_usuario` (`ticket_id`,`usuario_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indexes for table `feriados`
--
ALTER TABLE `feriados`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `data` (`data`);

--
-- Indexes for table `historico`
--
ALTER TABLE `historico`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indexes for table `horarios_uteis`
--
ALTER TABLE `horarios_uteis`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_dia` (`dia_semana`);

--
-- Indexes for table `lembretes`
--
ALTER TABLE `lembretes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `agente_id` (`agente_id`),
  ADD KEY `criado_por` (`criado_por`),
  ADD KEY `idx_data_hora` (`data_lembrete`,`hora_lembrete`),
  ADD KEY `idx_notificado` (`notificado`);

--
-- Indexes for table `macrocategorias`
--
ALTER TABLE `macrocategorias`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_historico`
--
ALTER TABLE `ticket_historico`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_historico_status`
--
ALTER TABLE `ticket_historico_status`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_prioridades`
--
ALTER TABLE `ticket_prioridades`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_prioridade_sla`
--
ALTER TABLE `ticket_prioridade_sla`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_sla`
--
ALTER TABLE `ticket_sla`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_status`
--
ALTER TABLE `ticket_status`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tokens_recuperacao`
--
ALTER TABLE `tokens_recuperacao`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_anydesk_id` (`anydesk_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `anexos`
--
ALTER TABLE `anexos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `auditoria`
--
ALTER TABLE `auditoria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;

--
-- AUTO_INCREMENT for table `auditoria_login`
--
ALTER TABLE `auditoria_login`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `auditoria_tickets`
--
ALTER TABLE `auditoria_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=699;

--
-- AUTO_INCREMENT for table `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `comentarios`
--
ALTER TABLE `comentarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=174;

--
-- AUTO_INCREMENT for table `comentarios_nao_lidos`
--
ALTER TABLE `comentarios_nao_lidos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `feriados`
--
ALTER TABLE `feriados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `historico`
--
ALTER TABLE `historico`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `horarios_uteis`
--
ALTER TABLE `horarios_uteis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `lembretes`
--
ALTER TABLE `lembretes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `macrocategorias`
--
ALTER TABLE `macrocategorias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=226;

--
-- AUTO_INCREMENT for table `ticket_historico`
--
ALTER TABLE `ticket_historico`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=797;

--
-- AUTO_INCREMENT for table `ticket_historico_status`
--
ALTER TABLE `ticket_historico_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=714;

--
-- AUTO_INCREMENT for table `ticket_prioridades`
--
ALTER TABLE `ticket_prioridades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `ticket_prioridade_sla`
--
ALTER TABLE `ticket_prioridade_sla`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `ticket_sla`
--
ALTER TABLE `ticket_sla`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=226;

--
-- AUTO_INCREMENT for table `ticket_status`
--
ALTER TABLE `ticket_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `tokens_recuperacao`
--
ALTER TABLE `tokens_recuperacao`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
