-- phpMyAdmin SQL Dump
-- version 4.7.7
-- https://www.phpmyadmin.net/
--
-- Host: crpdesk.vpshost0269.mysql.dbaas.com.br
-- Generation Time: 13-Jul-2026 às 21:45
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
CREATE DATABASE IF NOT EXISTS `crpdesk` DEFAULT CHARACTER SET latin1 COLLATE latin1_general_ci;
USE `crpdesk`;

-- --------------------------------------------------------

--
-- Estrutura da tabela `anexos`
--

CREATE TABLE IF NOT EXISTS `anexos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `comentario_id` int(11) DEFAULT NULL,
  `nome_original` varchar(255) NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `tamanho` int(11) NOT NULL,
  `tipo` varchar(100) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `idx_comentario_id` (`comentario_id`),
  KEY `idx_anexos_ticket` (`ticket_id`)
) ENGINE=InnoDB AUTO_INCREMENT=332 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `auditoria`
--

CREATE TABLE IF NOT EXISTS `auditoria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_acao` (`acao`),
  KEY `idx_tabela` (`tabela`),
  KEY `idx_criado_em` (`criado_em`),
  KEY `idx_auditoria_criado_em` (`criado_em`),
  KEY `idx_auditoria_acao` (`acao`),
  KEY `idx_auditoria_usuario` (`usuario_id`),
  KEY `idx_auditoria_tabela` (`tabela`)
) ENGINE=InnoDB AUTO_INCREMENT=188 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `auditoria_login`
--

CREATE TABLE IF NOT EXISTS `auditoria_login` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `acao` enum('login_sucesso','login_falha','logout') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `sessao_id` varchar(100) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_email` (`email`),
  KEY `idx_acao` (`acao`),
  KEY `idx_criado` (`criado_em`)
) ENGINE=InnoDB AUTO_INCREMENT=615 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `auditoria_tickets`
--

CREATE TABLE IF NOT EXISTS `auditoria_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `campo` varchar(50) NOT NULL,
  `valor_anterior` text,
  `valor_novo` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_criado` (`criado_em`)
) ENGINE=InnoDB AUTO_INCREMENT=3006 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `categorias`
--

CREATE TABLE IF NOT EXISTS `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `cor` varchar(7) DEFAULT '#6c757d',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `macrocategoria_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=107 DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `categorias`
--

INSERT INTO `categorias` (`id`, `nome`, `cor`, `criado_em`, `macrocategoria_id`) VALUES
(1, 'Rede', '#007bff', '2025-09-19 22:47:14', 1),
(2, 'Internet', '#28a745', '2025-09-19 22:47:14', 1),
(3, 'Telefonia', '#ffc107', '2025-09-19 22:47:14', 1),
(4, 'Monitor', '#17a2b8', '2025-09-19 22:47:14', 2),
(5, 'Computador', '#6f42c1', '2025-09-19 22:47:14', 2),
(6, 'Notebook', '#e83e8c', '2025-09-19 22:47:14', 2),
(7, 'Windows', '#fd7e14', '2025-09-19 22:47:14', 3),
(8, 'Storage', '#20c997', '2025-09-19 22:47:14', 1),
(9, 'E-mail Marketing', '#dc3545', '2025-09-19 22:47:14', 4),
(10, 'BRC', '#6c757d', '2025-09-19 22:47:14', 3),
(11, 'SEI', '#343a40', '2025-09-19 22:47:14', 3),
(12, 'Impressora', '#f8f9fa', '2025-09-19 22:47:14', 2),
(13, 'Software', '#495057', '2025-09-19 22:47:14', 3),
(14, 'Hardware', '#6c757d', '2025-09-19 22:47:14', 2),
(16, 'Agendamento online', '#6c757d', '2025-09-19 22:47:14', 3),
(17, 'Antivírus', '#6c757d', '2025-09-19 22:47:14', 5),
(18, 'VPN', '#6c757d', '2025-09-19 22:47:14', 5),
(19, 'Suporte SEI', '#6c757d', '2025-09-19 22:47:14', 6),
(20, 'Active Directory (AD)', '#6c757d', '2025-09-19 22:47:14', 3),
(21, 'Backup', '#6c757d', '2025-09-19 22:47:14', 5),
(22, 'Bloqueio/liberação De Sites', '#6c757d', '2025-09-19 22:47:14', 5),
(23, 'Cabeamento', '#6c757d', '2025-09-19 22:47:14', 1),
(24, 'Caixa Postal', '#6c757d', '2025-09-19 22:47:14', 4),
(25, 'Compras', '#6c757d', '2025-09-19 22:47:14', 6),
(26, 'Configuração', '#6c757d', '2025-09-19 22:47:14', 3),
(27, 'Chrome', '#6c757d', '2025-09-19 22:47:14', 3),
(28, 'Criptografia', '#6c757d', '2025-09-19 22:47:14', 5),
(29, 'Dúvida', '#6c757d', '2025-09-19 22:47:14', 6),
(30, 'Edge', '#6c757d', '2025-09-19 22:47:14', 3),
(31, 'E-mail Corporativo', '#6c757d', '2025-09-19 22:47:14', 4),
(32, 'Erro De Sistema', '#6c757d', '2025-09-19 22:47:14', 3),
(33, 'Excel', '#6c757d', '2025-09-19 22:47:14', 3),
(34, 'Firefox', '#6c757d', '2025-09-19 22:47:14', 3),
(35, 'Firewall', '#6c757d', '2025-09-19 22:47:14', 5),
(36, 'Fonte (hardware)', '#6c757d', '2025-09-19 22:47:14', 2),
(37, 'HDD/SSD', '#6c757d', '2025-09-19 22:47:14', 2),
(39, 'Licenciamento', '#6c757d', '2025-09-19 22:47:14', 3),
(40, 'Login', '#6c757d', '2025-09-19 22:47:14', 4),
(41, 'Memória RAM', '#6c757d', '2025-09-19 22:47:14', 2),
(42, 'Mouse', '#6c757d', '2025-09-19 22:47:14', 2),
(43, 'Navegador', '#6c757d', '2025-09-19 22:47:14', 3),
(44, 'Office', '#6c757d', '2025-09-19 22:47:14', 3),
(45, 'Outlook', '#6c757d', '2025-09-19 22:47:14', 3),
(46, 'Permissões', '#6c757d', '2025-09-19 22:47:14', 4),
(47, 'Phishing', '#6c757d', '2025-09-19 22:47:14', 5),
(48, 'Placa-mãe', '#6c757d', '2025-09-19 22:47:14', 2),
(49, 'Powerpoint', '#6c757d', '2025-09-19 22:47:14', 3),
(50, 'Preventiva', '#6c757d', '2025-09-19 22:47:14', 6),
(51, 'Roteador', '#6c757d', '2025-09-19 22:47:14', 1),
(52, 'Scanner', '#6c757d', '2025-09-19 22:47:14', 1),
(53, 'Senha', '#6c757d', '2025-09-19 22:47:14', 4),
(54, 'Servidor', '#6c757d', '2025-09-19 22:47:14', 1),
(55, 'Sistema interno', '#6c757d', '2025-09-19 22:47:14', 3),
(56, 'Sistema Operacional', '#6c757d', '2025-09-19 22:47:14', 3),
(57, 'Suporte Remoto', '#6c757d', '2025-09-19 22:47:14', 6),
(58, 'Switch', '#6c757d', '2025-09-19 22:47:14', 1),
(59, 'Teams', '#6c757d', '2025-09-19 22:47:14', 3),
(60, 'Teclado', '#6c757d', '2025-09-19 22:47:14', 2),
(61, 'Kit Mouse e Teclado', '#6c757d', '2025-09-19 22:47:14', 2),
(62, 'Thunderbird', '#6c757d', '2025-09-19 22:47:14', 3),
(63, 'Travamento', '#6c757d', '2025-09-19 22:47:14', 3),
(64, 'Usuário', '#6c757d', '2025-09-19 22:47:14', 4),
(65, 'Word', '#6c757d', '2025-09-19 22:47:14', 3),
(66, 'Zoom', '#6c757d', '2025-09-19 22:47:14', 3),
(67, 'Outros', '#6c757d', '2025-09-19 22:47:14', 6),
(68, 'Serviço SMTP', '#6c757d', '2025-09-19 22:47:14', 5),
(69, 'Implanta', '#6c757d', '2025-09-19 22:47:14', 6),
(70, 'Sycer', '#6c757d', '2025-09-19 22:47:14', 3),
(71, 'Permissões AD', '#6c757d', '2025-09-19 22:47:14', 4),
(72, 'Permissões BRC', '#6c757d', '2025-09-19 22:47:14', 4),
(73, 'Permissões Storage', '#6c757d', '2025-09-19 22:47:14', 4),
(74, 'Helpdesk CRPRS', '#6c757d', '2025-09-23 20:24:22', 3),
(75, 'Cadastro Geral', '#6c757d', '2025-09-30 22:57:26', 3),
(76, 'Termo De Recebimento', '#6c757d', '2025-10-06 15:32:39', 6),
(77, 'Central Telefônica - PABX', '#6c757d', '2025-10-06 18:00:16', 3),
(78, 'Termos De Responsabilidade', '#6c757d', '2025-10-06 19:03:07', 6),
(80, 'Google Groups', '#6c757d', '2025-10-07 18:27:05', 4),
(81, 'Mudança De Sala', '#6c757d', '2025-10-16 14:53:33', 6),
(82, 'Formulários Google', '#6c757d', '2025-10-20 19:30:29', 6),
(83, 'Banco De Preços', '#6c757d', '2025-10-30 17:59:54', 3),
(84, 'Siscont', '#6c757d', '2025-10-30 18:00:08', 3),
(85, 'Sispad', '#6c757d', '2025-10-30 18:00:19', 3),
(86, 'Sispat', '#6c757d', '2025-10-30 18:00:30', 3),
(87, 'Cucco', '#6c757d', '2025-10-30 18:01:14', 3),
(88, 'Empréstimo De Equipamento', '#6c757d', '2025-11-04 12:20:59', 6),
(89, 'Siscaf', '#6c757d', '2025-11-05 19:05:04', 3),
(90, 'CAV', '#6c757d', '2025-11-05 19:09:41', 3),
(91, 'Gmail', '#6c757d', '2025-11-06 15:47:48', 4),
(92, 'Drive - One Drive - Dropbox', '#6c757d', '2025-11-14 19:28:50', 3),
(93, 'E-cip', '#6c757d', '2025-11-17 12:45:29', 3),
(94, 'Alarme', '#6c757d', '2025-11-26 11:54:21', 5),
(95, 'Portal Transparência', '#6c757d', '2025-11-26 11:55:51', 3),
(96, 'Site Do CRPRS', '#6c757d', '2025-12-19 13:09:39', 3),
(97, 'Informações', '#6c757d', '2025-12-19 13:10:01', 6),
(98, 'Certificado - Instalação', '#6c757d', '2026-01-13 11:50:44', 5),
(99, 'Desenvolvimento', '#6c757d', '2026-02-09 17:52:32', 3),
(100, 'Tablet', '#6c757d', '2026-02-26 13:33:40', 2),
(101, 'Cadastro De Digital', '#6c757d', '2026-03-10 12:55:00', 6),
(102, 'Fechadura Digital', '#6c757d', '2026-03-11 18:18:30', 1),
(103, 'Sistema Ouvidoria Crprs', '#6c757d', '2026-03-16 13:30:06', 3),
(104, 'Sistema Agendamento CRPRS', '#6c757d', '2026-03-16 13:30:37', 3),
(105, 'Sistema CRPDESK', '#6c757d', '2026-03-16 13:31:07', 3),
(106, 'SATEPSI', '#6c757d', '2026-06-16 12:22:03', 3);

-- --------------------------------------------------------

--
-- Estrutura da tabela `comentarios`
--

CREATE TABLE IF NOT EXISTS `comentarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `comentario` longtext NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_comentarios_ticket` (`ticket_id`),
  KEY `idx_comentarios_criado_em` (`criado_em`)
) ENGINE=InnoDB AUTO_INCREMENT=973 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `comentarios_nao_lidos`
--

CREATE TABLE IF NOT EXISTS `comentarios_nao_lidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `comentario_id` int(11) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ticket_usuario` (`ticket_id`,`usuario_id`),
  KEY `usuario_id` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=264 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `emails_erro`
--

CREATE TABLE IF NOT EXISTS `emails_erro` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` varchar(255) NOT NULL,
  `email_remetente` varchar(255) NOT NULL,
  `assunto` text,
  `corpo` text,
  `erro_mensagem` text,
  `tentativas` int(11) DEFAULT '1',
  `ultima_tentativa` datetime DEFAULT CURRENT_TIMESTAMP,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_tentativas` (`tentativas`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `emails_processados`
--

CREATE TABLE IF NOT EXISTS `emails_processados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` varchar(255) NOT NULL,
  `email_remetente` varchar(255) NOT NULL,
  `assunto` text,
  `ticket_id` int(11) DEFAULT NULL,
  `tipo` enum('novo_ticket','comentario','erro') NOT NULL,
  `processado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  `tentativas` int(11) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_id` (`message_id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_ticket_id` (`ticket_id`),
  KEY `idx_processado_em` (`processado_em`)
) ENGINE=InnoDB AUTO_INCREMENT=379 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `email_logs`
--

CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `enviado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `destinatario` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `assunto` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ex: novo_ticket, comentario, fechamento, notif_agente',
  `ticket_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('sucesso','falha') COLLATE utf8mb4_unicode_ci NOT NULL,
  `erro` text COLLATE utf8mb4_unicode_ci COMMENT 'Mensagem de erro quando status = falha',
  PRIMARY KEY (`id`),
  KEY `idx_enviado_em` (`enviado_em`),
  KEY `idx_ticket_id` (`ticket_id`),
  KEY `idx_status` (`status`),
  KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=2392 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `feriados`
--

CREATE TABLE IF NOT EXISTS `feriados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `data` date NOT NULL,
  `descricao` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorrente` tinyint(1) DEFAULT '0' COMMENT 'Se TRUE, repete todo ano',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `data` (`data`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Extraindo dados da tabela `feriados`
--

INSERT INTO `feriados` (`id`, `data`, `descricao`, `recorrente`, `criado_em`, `atualizado_em`) VALUES
(1, '2025-10-27', 'Antecipação do Dia do Servidor Público', 0, '2025-10-27 17:24:26', '2025-10-27 17:24:26'),
(2, '2025-11-20', 'Consciência Negra', 1, '2025-11-20 18:18:16', '2025-11-20 18:18:16'),
(3, '2025-11-21', 'Ponte Consciência Negra', 0, '2025-11-20 18:18:41', '2025-11-20 18:18:41'),
(4, '2026-02-02', 'Navegantes', 1, '2026-01-30 20:16:45', '2026-01-30 20:16:45'),
(5, '2026-02-16', 'Carnaval', 0, '2026-02-13 20:01:54', '2026-02-13 20:01:54'),
(6, '2026-02-17', 'Carnaval', 0, '2026-02-13 20:02:53', '2026-02-13 20:02:53'),
(7, '2026-02-18', 'Carnaval', 0, '2026-02-13 20:03:27', '2026-02-13 20:03:27');

-- --------------------------------------------------------

--
-- Estrutura da tabela `historico`
--

CREATE TABLE IF NOT EXISTS `historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `acao` varchar(50) NOT NULL,
  `valor_anterior` text,
  `valor_novo` text,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `usuario_id` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `horarios_uteis`
--

CREATE TABLE IF NOT EXISTS `horarios_uteis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dia_semana` int(11) NOT NULL COMMENT '1=Domingo, 2=Segunda, ..., 7=Sßbado',
  `hora_inicio` time NOT NULL,
  `hora_fim` time NOT NULL,
  `ativo` tinyint(1) DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dia` (`dia_semana`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Extraindo dados da tabela `horarios_uteis`
--

INSERT INTO `horarios_uteis` (`id`, `dia_semana`, `hora_inicio`, `hora_fim`, `ativo`, `criado_em`, `atualizado_em`) VALUES
(1, 2, '08:00:00', '17:00:00', 1, '2025-10-27 17:16:33', '2025-10-27 17:16:33'),
(2, 3, '08:00:00', '17:00:00', 1, '2025-10-27 17:16:33', '2025-10-27 17:16:33'),
(3, 4, '08:00:00', '17:00:00', 1, '2025-10-27 17:16:33', '2025-10-27 17:16:33'),
(4, 5, '08:00:00', '17:00:00', 1, '2025-10-27 17:16:33', '2025-10-27 17:16:33'),
(5, 6, '08:00:00', '17:00:00', 1, '2025-10-27 17:16:33', '2025-10-29 19:07:42'),
(6, 1, '08:00:00', '17:00:00', 0, '2025-11-28 15:33:42', '2025-11-28 19:19:12'),
(7, 7, '08:00:00', '17:00:00', 0, '2025-11-28 15:33:42', '2025-11-28 19:19:16');

-- --------------------------------------------------------

--
-- Estrutura da tabela `lembretes`
--

CREATE TABLE IF NOT EXISTS `lembretes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `data_lembrete` date NOT NULL,
  `hora_lembrete` time NOT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `agente_id` int(11) DEFAULT NULL,
  `notificado` tinyint(1) DEFAULT '0',
  `criado_por` int(11) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `agente_id` (`agente_id`),
  KEY `criado_por` (`criado_por`),
  KEY `idx_data_hora` (`data_lembrete`,`hora_lembrete`),
  KEY `idx_notificado` (`notificado`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `macrocategorias`
--

CREATE TABLE IF NOT EXISTS `macrocategorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `macrocategorias`
--

INSERT INTO `macrocategorias` (`id`, `nome`, `criado_em`) VALUES
(1, 'Infraestrutura', '2025-09-19 22:47:14'),
(2, 'Hardware', '2025-09-19 22:47:14'),
(3, 'Software e Sistemas', '2025-09-19 22:47:14'),
(4, 'Contas e Acessos', '2025-09-19 22:47:14'),
(5, 'Segurança', '2025-09-19 22:47:14'),
(6, 'Suporte e Serviços', '2025-09-19 22:47:14');

-- --------------------------------------------------------

--
-- Estrutura da tabela `macros`
--

CREATE TABLE IF NOT EXISTS `macros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `texto` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Extraindo dados da tabela `macros`
--

INSERT INTO `macros` (`id`, `titulo`, `texto`, `usuario_id`, `criado_em`) VALUES
(1, 'Seu chamado foi atendido conforme solicitado', '<p>Prezado(a):</p><p>Seu chamado foi atendido conforme solicitado. Em vista disso, o ticket será encerrado por nosso equipe.</p><p><br></p>', 1, '2026-03-08 23:52:21'),
(2, 'E-cip - envia manual de captura e informa dados do suporte da Thomas Greg', '<p><span style=\"background-color: rgb(248, 249, 250); color: rgb(51, 51, 51);\">                            </span></p><p>Prezada(o):</p><p><br></p><p>Segue anexo o manual de captura da e-cip. Caso sua dificuldade de emissão da e-cip persista, por favor, entre em contato com o suporte do aplicativo através dos seguintes canais:</p><p>E-mail: suportecfp@thomasgreg.com.br&nbsp;</p><p>Telefone: 0800-011-8222</p>', 1, '2026-05-07 17:07:10'),
(3, 'E-cip - Informações sobre carteira e segunda via', '<p>Prezada(o):</p><p><br></p><p>Não encontramos solicitação de segunda via da Carteira em seu cadastro.</p><p>Destacamos que o novo modelo de Carteira de Identidade Profissional (<strong>CIP</strong>) está sendo emitido automaticamente para as/os novas/os inscritas/os no Conselho, mas Psicólogas/os que já tinham a carteira antiga e queiram o novo modelo da CIP, precisam solicitar segunda via da carteira.</p><p>Mais informações sobre a nova carteira em <a href=\"https://crprs.org.br/noticias/nova-carteira-de-identificacao-profissional-ja-esta-disponivel-no-crprs\" target=\"_blank\">https://crprs.org.br/noticias/nova-carteira-de-identificacao-profissional-ja-esta-disponivel-no-crprs</a>.</p><p>Veja informações sobre segunda via da carteira em <em><u>https://crprs.org.br/servicos/servicos-para-pessoa-fisica</u></em>, no <strong>item 09 - Segunda Via da Cédula de Identidade Profissional.</strong></p>', 1, '2026-05-07 17:14:45'),
(4, 'Sextortion Scam (ou golpe da sextorsão)', '<p>Esse e-mail é um golpe 100% falso. Trata-se de uma campanha massiva de phishing e extorsão conhecida tecnicamente como Sextortion Scam (ou golpe da sextorsão).</p><p>O fato de o e-mail parecer ter sido enviado da tia própria conta é o truque psicológico mais antigo desse golpe, usado justamente para gerar pânico. Na realidade o golpista não invadiu tua conta. Ele simplesmente utilizou uma técnica chamada Email Spoofing (falsificação de cabeçalho). O protocolo SMTP (usado para envio de e-mails) permite que qualquer pessoa especifique um remetente falso se o servidor de destino não tiver regras rígidas de validação.</p><p>Fique tranquila, somente delete o e-mail.</p>', 1, '2026-06-16 18:36:34');

-- --------------------------------------------------------

--
-- Estrutura da tabela `notificacoes`
--

CREATE TABLE IF NOT EXISTS `notificacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mensagem` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `ticket_id` int(11) DEFAULT NULL,
  `lida` tinyint(1) DEFAULT '0',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `idx_notificacoes_user_lida` (`usuario_id`,`lida`)
) ENGINE=InnoDB AUTO_INCREMENT=412 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Extraindo dados da tabela `notificacoes`
--

INSERT INTO `notificacoes` (`id`, `usuario_id`, `titulo`, `mensagem`, `ticket_id`, `lida`, `criado_em`) VALUES
(117, 4, 'Novo Comentário', 'Novo comentário no ticket (#00550) adicionado por Hugo Miranda.', 550, 0, '2026-04-09 19:02:08'),
(130, 4, '🔔 Lembrete: Ticket #00543 - Direcionamento_de_emails._Férias.', 'Seu lembrete agendado: Retirar monitoramento dos e-mails caroline.terragno@ e licitacoes@', 543, 0, '2026-04-13 11:00:13'),
(143, 4, 'Novo Comentário', 'Novo comentário no ticket (#00574) adicionado por Hugo Miranda.', 574, 0, '2026-04-16 18:22:27'),
(157, 4, 'Novo Ticket', 'Um novo ticket (#00583) foi aberto pelo usuário Desconhecido.', 583, 0, '2026-04-23 17:26:00'),
(173, 3, 'Ticket Atribuído', 'O ticket (#00593) foi atribuído a você por Mateus Pereira.', 593, 0, '2026-04-29 16:35:37'),
(177, 3, '🔔 Lembrete: Ticket #00592 - redirecionamento_de_e-mail_-_férias_Danuza', 'Seu lembrete agendado: Direcionar e-mails da danuza para cadastro@', 592, 0, '2026-04-30 12:00:11'),
(178, 4, '🔔 Lembrete: Ticket #00592 - redirecionamento_de_e-mail_-_férias_Danuza', 'Seu lembrete agendado: Direcionar e-mails da danuza para cadastro@', 592, 0, '2026-04-30 12:00:13'),
(180, 3, 'Novo Ticket', 'Um novo ticket (#00597) foi aberto pelo usuário Desconhecido.', 597, 0, '2026-04-30 18:59:39'),
(181, 4, 'Novo Ticket', 'Um novo ticket (#00597) foi aberto pelo usuário Desconhecido.', 597, 0, '2026-04-30 18:59:39'),
(182, 3, 'Ticket Atribuído', 'O ticket (#00595) foi atribuído a você por Hugo Miranda.', 595, 0, '2026-04-30 19:00:05'),
(185, 3, 'Ticket Atribuído', 'O ticket (#00597) foi atribuído a você por Hugo Miranda.', 597, 0, '2026-04-30 19:33:21'),
(188, 3, 'Novo Comentário', 'Novo comentário no ticket (#00595) adicionado por Rafael Rueda.', 595, 0, '2026-04-30 19:43:31'),
(189, 3, 'Ticket Atribuído', 'O ticket (#00599) foi atribuído a você por Mateus Pereira.', 599, 0, '2026-05-04 12:34:30'),
(197, 3, 'Novo Comentário', 'Novo comentário no ticket (#00595) adicionado por Hugo Miranda.', 595, 0, '2026-05-07 15:24:29'),
(209, 3, 'Novo Comentário', 'Novo comentário no ticket (#00599) adicionado por Rafael Rueda.', 599, 0, '2026-05-08 16:40:59'),
(211, 3, 'Novo Comentário', 'Novo comentário no ticket (#00597) adicionado por Rafael Rueda.', 597, 0, '2026-05-08 17:35:38'),
(213, 4, 'Novo Ticket', 'Um novo ticket (#00618) foi aberto pelo usuário Desconhecido.', 618, 0, '2026-05-08 18:05:04'),
(215, 4, 'Novo Ticket', 'Um novo ticket (#00619) foi aberto pelo usuário Desconhecido.', 619, 0, '2026-05-08 18:45:18'),
(223, 4, 'Novo Ticket', 'Um novo ticket (#00625) foi aberto pelo usuário Desconhecido.', 625, 0, '2026-05-12 12:43:21'),
(236, 4, '🔔 Lembrete: Ticket #00617 - Envio_da_Cópia_de_emails_recebidos', 'Seu lembrete agendado: Retirar encaminhamento de e-mail.', 617, 0, '2026-05-14 19:30:09'),
(239, 4, '🔔 Lembrete: Ticket #00620 - Encaminhamento de email - licitacao@ e caroline@', 'Seu lembrete agendado: Retirar encaminhamento de e-mail.\nHabilitar redirecionamento do Licitações para a Carol.', 620, 0, '2026-05-14 19:30:18'),
(254, 4, 'Novo Ticket', 'Um novo ticket (#00642) foi aberto pelo usuário Desconhecido.', 642, 0, '2026-05-19 13:10:13'),
(256, 4, 'Novo Ticket', 'Um novo ticket (#00643) foi aberto pelo usuário Desconhecido.', 643, 0, '2026-05-19 13:11:06'),
(260, 4, 'Novo Ticket', 'Um novo ticket (#00644) foi aberto pelo usuário Desconhecido.', 644, 0, '2026-05-19 14:29:23'),
(266, 4, 'Novo Ticket', 'Um novo ticket (#00646) foi aberto pelo usuário Desconhecido.', 646, 0, '2026-05-19 20:00:49'),
(270, 4, 'Novo Ticket', 'Um novo ticket (#00648) foi aberto pelo usuário Desconhecido.', 648, 0, '2026-05-20 13:07:32'),
(273, 4, 'Novo Ticket', 'Um novo ticket (#00653) foi aberto pelo usuário Desconhecido.', 653, 0, '2026-05-22 12:46:47'),
(277, 3, 'Novo Comentário', 'Novo comentário no ticket (#00654) adicionado por Rafael Rueda.', 654, 0, '2026-05-22 13:44:43'),
(278, 4, 'Novo Comentário', 'Novo comentário no ticket (#00654) adicionado por Rafael Rueda.', 654, 0, '2026-05-22 13:44:43'),
(283, 3, 'Novo Comentário', 'Novo comentário no ticket (#00584) adicionado por Rafael Rueda.', 584, 0, '2026-05-22 19:08:33'),
(289, 2, '🔔 Lembrete: Ticket #00649 - e-mail_férias_eliz', 'Seu lembrete agendado: Colocar monitoramento.', 649, 1, '2026-05-25 19:00:10'),
(290, 4, '🔔 Lembrete: Ticket #00649 - e-mail_férias_eliz', 'Seu lembrete agendado: Colocar monitoramento.', 649, 0, '2026-05-25 19:00:11'),
(291, 2, 'Ticket Atribuído', 'O ticket (#00660) foi atribuído a você por Rafael Rueda.', 660, 1, '2026-05-26 12:44:12'),
(292, 2, 'Ticket Atribuído', 'O ticket (#00661) foi atribuído a você por Rafael Rueda.', 661, 1, '2026-05-26 12:44:18'),
(294, 2, '🔔 Lembrete: Ticket #00636 - Encaminhamento de emails', 'Seu lembrete agendado: Retirar monitoramento de patricia.cristina@', 636, 1, '2026-06-01 11:00:10'),
(295, 4, '🔔 Lembrete: Ticket #00636 - Encaminhamento de emails', 'Seu lembrete agendado: Retirar monitoramento de patricia.cristina@', 636, 0, '2026-06-01 11:00:11'),
(299, 2, 'Novo Comentário', 'Novo comentário no ticket (#00636) adicionado por Hugo Miranda.', 636, 1, '2026-06-01 11:19:02'),
(300, 2, 'Ticket Atribuído', 'O ticket (#00662) foi atribuído a você por Hugo Miranda.', 662, 1, '2026-06-01 13:06:06'),
(303, 3, 'Novo Comentário', 'Novo comentário no ticket (#00666) adicionado por Rafael Rueda.', 666, 0, '2026-06-01 19:58:52'),
(304, 4, 'Novo Comentário', 'Novo comentário no ticket (#00666) adicionado por Rafael Rueda.', 666, 0, '2026-06-01 19:58:52'),
(306, 2, 'Novo Comentário', 'Novo comentário no ticket (#00667) adicionado por Hugo Miranda.', 667, 1, '2026-06-03 11:55:34'),
(307, 3, 'Novo Comentário', 'Novo comentário no ticket (#00667) adicionado por Hugo Miranda.', 667, 0, '2026-06-03 11:55:34'),
(308, 4, 'Novo Comentário', 'Novo comentário no ticket (#00667) adicionado por Hugo Miranda.', 667, 0, '2026-06-03 11:55:34'),
(314, 2, 'Novo Ticket', 'Um novo ticket (#00671) foi aberto pelo usuário Desconhecido.', 671, 1, '2026-06-08 13:19:49'),
(315, 4, 'Novo Ticket', 'Um novo ticket (#00671) foi aberto pelo usuário Desconhecido.', 671, 0, '2026-06-08 13:19:49'),
(325, 2, 'Ticket Atribuído', 'O ticket (#00679) foi atribuído a você por Hugo Miranda.', 679, 1, '2026-06-12 17:04:30'),
(347, 4, 'Novo Ticket', 'Um novo ticket (#00700) foi aberto pelo usuário Desconhecido.', 700, 0, '2026-06-25 13:02:00'),
(349, 4, 'Novo Ticket', 'Um novo ticket (#00701) foi aberto pelo usuário Desconhecido.', 701, 0, '2026-06-25 13:02:47'),
(355, 2, 'Ticket Atribuído', 'O ticket (#00704) foi atribuído a você por Hugo Miranda.', 704, 1, '2026-06-26 12:51:23'),
(356, 2, 'Novo Ticket', 'Um novo ticket (#00710) foi aberto pelo usuário Desconhecido.', 710, 1, '2026-06-30 20:43:50'),
(357, 4, 'Novo Ticket', 'Um novo ticket (#00710) foi aberto pelo usuário Desconhecido.', 710, 0, '2026-06-30 20:43:50'),
(364, 4, 'Novo Ticket', 'Um novo ticket (#00711) foi aberto pelo usuário Desconhecido.', 711, 0, '2026-07-01 14:21:01'),
(372, 1, 'Ticket Atribuído', 'O ticket (#00717) foi atribuído a você por Hugo Miranda.', 717, 1, '2026-07-02 13:40:41'),
(373, 1, 'Ticket Atribuído', 'O ticket (#00718) foi atribuído a você por Hugo Miranda.', 718, 1, '2026-07-02 13:45:18'),
(374, 1, 'Ticket Atribuído', 'O ticket (#00719) foi atribuído a você por Hugo Miranda.', 719, 1, '2026-07-02 20:11:11'),
(375, 1, 'Ticket Atribuído', 'O ticket (#00721) foi atribuído a você por Hugo Miranda.', 721, 1, '2026-07-02 20:13:48'),
(376, 1, 'Ticket Atribuído', 'O ticket (#00722) foi atribuído a você por Hugo Miranda.', 722, 1, '2026-07-02 20:18:03'),
(377, 1, 'Ticket Atribuído', 'O ticket (#00720) foi atribuído a você por Hugo Miranda.', 720, 1, '2026-07-03 12:53:28'),
(378, 2, 'Novo Comentário', 'Novo comentário no ticket (#00727) adicionado por Hugo Miranda.', 727, 1, '2026-07-03 19:45:42'),
(379, 3, 'Novo Comentário', 'Novo comentário no ticket (#00727) adicionado por Hugo Miranda.', 727, 0, '2026-07-03 19:45:42'),
(380, 4, 'Novo Comentário', 'Novo comentário no ticket (#00727) adicionado por Hugo Miranda.', 727, 0, '2026-07-03 19:45:42'),
(381, 1, 'Ticket Atribuído', 'O ticket (#00725) foi atribuído a você por Hugo Miranda.', 725, 1, '2026-07-03 19:45:59'),
(382, 1, 'Ticket Atribuído', 'O ticket (#00724) foi atribuído a você por Hugo Miranda.', 724, 1, '2026-07-03 19:46:40'),
(383, 2, 'Ticket Atribuído', 'O ticket (#00723) foi atribuído a você por Hugo Miranda.', 723, 1, '2026-07-03 20:11:07'),
(384, 1, 'Ticket Atribuído', 'O ticket (#00726) foi atribuído a você por Hugo Miranda.', 726, 1, '2026-07-03 20:11:56'),
(385, 2, 'Ticket Atribuído', 'O ticket (#00709) foi atribuído a você por Hugo Miranda.', 709, 1, '2026-07-03 20:25:15'),
(386, 1, 'Novo Comentário', 'Novo comentário no ticket (#00726) adicionado por Rafael Rueda.', 726, 1, '2026-07-06 13:08:34'),
(387, 1, 'Novo Comentário', 'Novo comentário no ticket (#00728) adicionado por Rafael Rueda.', 728, 1, '2026-07-06 14:33:58'),
(388, 3, 'Novo Comentário', 'Novo comentário no ticket (#00728) adicionado por Rafael Rueda.', 728, 0, '2026-07-06 14:33:58'),
(389, 4, 'Novo Comentário', 'Novo comentário no ticket (#00728) adicionado por Rafael Rueda.', 728, 0, '2026-07-06 14:33:58'),
(390, 1, 'Novo Comentário', 'Novo comentário no ticket (#00729) adicionado por Rafael Rueda.', 729, 1, '2026-07-06 17:20:47'),
(391, 3, 'Novo Comentário', 'Novo comentário no ticket (#00729) adicionado por Rafael Rueda.', 729, 0, '2026-07-06 17:20:47'),
(392, 4, 'Novo Comentário', 'Novo comentário no ticket (#00729) adicionado por Rafael Rueda.', 729, 0, '2026-07-06 17:20:47'),
(393, 1, 'Novo Comentário', 'Novo comentário no ticket (#00730) adicionado por Rafael Rueda.', 730, 1, '2026-07-08 19:56:42'),
(394, 3, 'Novo Comentário', 'Novo comentário no ticket (#00730) adicionado por Rafael Rueda.', 730, 0, '2026-07-08 19:56:42'),
(395, 4, 'Novo Comentário', 'Novo comentário no ticket (#00730) adicionado por Rafael Rueda.', 730, 0, '2026-07-08 19:56:42'),
(396, 2, 'Ticket Atribuído', 'O ticket (#00731) foi atribuído a você por Rafael Rueda.', 731, 1, '2026-07-08 19:57:05'),
(397, 2, 'Ticket Atribuído', 'O ticket (#00732) foi atribuído a você por Rafael Rueda.', 732, 1, '2026-07-08 19:57:22'),
(398, 1, 'Novo Comentário', 'Novo comentário no ticket (#00736) adicionado por Rafael Rueda.', 736, 1, '2026-07-09 17:01:36'),
(399, 3, 'Novo Comentário', 'Novo comentário no ticket (#00736) adicionado por Rafael Rueda.', 736, 0, '2026-07-09 17:01:36'),
(400, 4, 'Novo Comentário', 'Novo comentário no ticket (#00736) adicionado por Rafael Rueda.', 736, 0, '2026-07-09 17:01:36'),
(401, 1, '🔔 Lembrete: Ticket #00715 - Redirecionamento e-mails 03 a 10/07', 'Seu lembrete agendado: Retirar monitoramento dos e-mails nadia.miola@ e comunicacao@.\nObs: Somente retirar monitoramento da página de gerenciamento de e-mails.', 715, 1, '2026-07-10 19:30:15'),
(402, 2, '🔔 Lembrete: Ticket #00715 - Redirecionamento e-mails 03 a 10/07', 'Seu lembrete agendado: Retirar monitoramento dos e-mails nadia.miola@ e comunicacao@.\nObs: Somente retirar monitoramento da página de gerenciamento de e-mails.', 715, 1, '2026-07-10 19:30:16'),
(403, 4, '🔔 Lembrete: Ticket #00715 - Redirecionamento e-mails 03 a 10/07', 'Seu lembrete agendado: Retirar monitoramento dos e-mails nadia.miola@ e comunicacao@.\nObs: Somente retirar monitoramento da página de gerenciamento de e-mails.', 715, 0, '2026-07-10 19:30:18'),
(404, 1, 'Novo Comentário', 'Novo comentário no ticket (#00715) adicionado por Rafael Rueda.', 715, 1, '2026-07-10 19:50:56'),
(405, 1, 'Ticket Atribuído', 'O ticket (#00735) foi atribuído a você por Hugo Miranda.', 735, 1, '2026-07-13 12:26:56'),
(406, 1, 'Ticket Atribuído', 'O ticket (#00737) foi atribuído a você por Hugo Miranda.', 737, 1, '2026-07-13 12:28:02'),
(407, 1, 'Ticket Atribuído', 'O ticket (#00734) foi atribuído a você por Hugo Miranda.', 734, 1, '2026-07-13 12:28:37'),
(408, 2, 'Ticket Atribuído', 'O ticket (#00733) foi atribuído a você por Hugo Miranda.', 733, 0, '2026-07-13 12:29:10'),
(409, 1, 'Ticket Atribuído', 'O ticket (#00738) foi atribuído a você por Hugo Miranda.', 738, 1, '2026-07-13 14:50:10'),
(410, 1, 'Ticket Atribuído', 'O ticket (#00739) foi atribuído a você por Hugo Miranda.', 739, 1, '2026-07-13 14:51:55'),
(411, 1, 'Ticket Atribuído', 'O ticket (#00740) foi atribuído a você por Hugo Miranda.', 740, 0, '2026-07-13 19:50:56');

-- --------------------------------------------------------

--
-- Estrutura da tabela `tickets`
--

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(200) NOT NULL,
  `descricao` longtext NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `origem_api` varchar(50) DEFAULT 'interna',
  `email_solicitante` varchar(255) DEFAULT NULL,
  `agente_id` int(11) DEFAULT NULL,
  `status` enum('novo','aberto','pendente','resolvido','fechado','reaberto','em_atendimento','encerrado','cancelado','nova_resposta','aguardando','aguardando_resposta','aguardando_terceiros','aguardando_aprovacao','aguardando_data') NOT NULL DEFAULT 'novo',
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
  `sla_vencido` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_tickets_status` (`status`),
  KEY `idx_tickets_usuario_id` (`usuario_id`),
  KEY `idx_tickets_agente_id` (`agente_id`),
  KEY `idx_tickets_criado_em` (`criado_em`),
  KEY `idx_tickets_prioridade` (`prioridade`)
) ENGINE=InnoDB AUTO_INCREMENT=741 DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS `ticket_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `acao` varchar(50) NOT NULL,
  `valor_anterior` varchar(255) DEFAULT NULL,
  `valor_novo` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_historico_ticket` (`ticket_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3539 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_historico_status`
--

CREATE TABLE IF NOT EXISTS `ticket_historico_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `status_anterior` varchar(50) DEFAULT NULL,
  `status_novo` varchar(50) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `observacoes` text,
  `data_inicio` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_fim` timestamp NULL DEFAULT NULL,
  `tempo_no_status_segundos` int(11) DEFAULT '0',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2765 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_prioridades`
--

CREATE TABLE IF NOT EXISTS `ticket_prioridades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `descricao` text,
  `sla_resposta_minutos` int(11) NOT NULL,
  `sla_resolucao_horas` int(11) NOT NULL,
  `cor` varchar(7) DEFAULT '#6c757d',
  `icone` varchar(10) DEFAULT 'Ô×í´©Å',
  `ordem` int(11) NOT NULL,
  `ativo` tinyint(1) DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `ticket_prioridades`
--

INSERT INTO `ticket_prioridades` (`id`, `nome`, `descricao`, `sla_resposta_minutos`, `sla_resolucao_horas`, `cor`, `icone`, `ordem`, `ativo`, `criado_em`) VALUES
(1, 'urgente', 'Sistema fora do ar, falha critica que impede o trabalho', 120, 4, '#dc3545', 'Urgente', 1, 1, '2025-10-02 01:26:23'),
(2, 'alta', 'Funcionalidade importante indisponivel que afeta a produtividade', 240, 8, '#fd7e14', 'Alta', 2, 1, '2025-10-02 01:26:23'),
(3, 'normal', 'Duvidas, solicitacoes rotineiras', 720, 24, '#17a2b8', 'Normal', 3, 1, '2025-10-02 01:26:23'),
(4, 'baixa', 'Melhorias, sugestoes nao urgentes', 1440, 40, '#28a745', 'Baixa', 4, 1, '2025-10-02 01:26:23');

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_prioridade_sla`
--

CREATE TABLE IF NOT EXISTS `ticket_prioridade_sla` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prioridade` varchar(20) NOT NULL,
  `sla_resposta_horas` int(11) NOT NULL,
  `sla_resolucao_horas` int(11) NOT NULL,
  `descricao` text,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `ticket_prioridade_sla`
--

INSERT INTO `ticket_prioridade_sla` (`id`, `prioridade`, `sla_resposta_horas`, `sla_resolucao_horas`, `descricao`, `criado_em`) VALUES
(1, 'urgente', 2, 4, 'Sistema fora do ar, falha critica', '2025-10-02 21:43:54'),
(2, 'alta', 4, 8, 'Funcionalidade importante indisponivel', '2025-10-02 21:43:54'),
(3, 'normal', 12, 24, 'Duvidas, solicitacoes rotineiras', '2025-10-02 21:43:54'),
(4, 'baixa', 24, 40, 'Melhorias, sugestoes', '2025-10-02 21:43:54');

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_sla`
--

CREATE TABLE IF NOT EXISTS `ticket_sla` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `prioridade` varchar(50) NOT NULL DEFAULT 'normal',
  `sla_resposta_minutos` int(11) NOT NULL DEFAULT '480',
  `sla_resolucao_horas` int(11) NOT NULL DEFAULT '24',
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
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=811 DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `ticket_sla`
--

INSERT INTO `ticket_sla` (`id`, `ticket_id`, `prioridade`, `sla_resposta_minutos`, `sla_resolucao_horas`, `primeira_resposta_em`, `sla_resposta_vencido`, `resolvido_em`, `sla_resolucao_vencido`, `tempo_pausado_segundos`, `pausado_em`, `pausado`, `tempo_total_segundos`, `tempo_primeira_resposta_segundos`, `tempo_resolucao_segundos`, `criado_em`, `atualizado_em`) VALUES
(1, 1, 'normal', 720, 24, NULL, 0, '2025-10-29 20:19:08', 0, 0, NULL, 0, 0, 0, 11039, '2025-10-29 17:15:09', '2025-10-29 20:19:08'),
(2, 2, 'normal', 720, 24, NULL, 0, '2025-10-29 20:19:23', 0, 1212, NULL, 0, 0, 0, 9837, '2025-10-29 17:15:14', '2025-10-29 20:19:23'),
(3, 3, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-10-29 17:15:19', '2025-10-29 17:15:19'),
(4, 4, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-10-29 17:15:24', '2025-10-29 17:15:24'),
(5, 5, 'normal', 720, 24, '2025-10-30 11:25:13', 0, '2025-10-30 14:58:29', 0, 0, NULL, 0, 0, 2069, 14865, '2025-10-29 20:50:44', '2025-10-30 14:58:29'),
(6, 6, 'normal', 720, 24, '2025-10-30 11:41:16', 0, '2025-10-31 16:48:22', 0, 54426, NULL, 0, 0, 50, 50, '2025-10-30 11:40:26', '2025-10-31 16:48:22'),
(7, 7, 'normal', 720, 24, '2025-10-30 11:49:42', 0, '2025-10-31 16:49:19', 0, 53977, NULL, 0, 0, 114, 114, '2025-10-30 11:47:48', '2025-10-31 16:49:19'),
(8, 8, 'normal', 720, 24, '2025-10-30 11:56:41', 0, '2025-10-31 16:50:12', 0, 53611, NULL, 0, 0, 92, 92, '2025-10-30 11:55:09', '2025-10-31 16:50:12'),
(9, 9, 'normal', 720, 24, '2025-10-30 12:08:21', 0, NULL, 0, 0, '2025-10-30 12:08:21', 1, 0, 527, 0, '2025-10-30 11:59:34', '2025-10-30 12:08:21'),
(10, 10, 'normal', 720, 24, '2025-10-30 14:02:01', 0, '2026-05-20 18:26:15', 0, 4487054, NULL, 0, 0, 42, 42, '2025-10-30 14:01:19', '2026-05-20 18:26:15'),
(11, 11, 'normal', 720, 24, '2025-10-30 14:08:11', 0, NULL, 0, 0, NULL, 0, 0, 21, 0, '2025-10-30 14:07:50', '2025-10-30 14:08:11'),
(12, 12, 'normal', 720, 24, '2025-10-30 14:10:33', 0, '2025-11-05 12:50:04', 0, 139171, NULL, 0, 0, 19, 19, '2025-10-30 14:10:14', '2025-11-05 12:50:04'),
(13, 13, 'normal', 720, 24, '2025-10-30 14:18:39', 0, '2026-05-08 17:47:38', 0, 4224539, NULL, 0, 0, 338, 338, '2025-10-30 14:13:01', '2026-05-08 17:47:38'),
(14, 14, 'normal', 720, 24, '2025-10-30 14:18:20', 0, '2025-11-04 16:48:25', 0, 71724, NULL, 0, 0, 185, 45466, '2025-10-30 14:15:15', '2025-11-04 16:48:25'),
(15, 15, 'normal', 720, 24, '2025-10-30 14:18:09', 0, '2025-10-31 16:44:59', 0, 44797, NULL, 0, 0, 25, 38, '2025-10-30 14:17:44', '2025-10-31 16:44:59'),
(16, 16, 'normal', 720, 24, '2025-10-30 14:20:51', 0, '2025-11-07 15:34:03', 0, 220392, NULL, 0, 0, 20, 20, '2025-10-30 14:20:31', '2025-11-07 15:34:03'),
(17, 17, 'normal', 720, 24, '2025-10-30 14:22:38', 0, '2026-03-11 11:45:45', 0, 2841787, NULL, 0, 0, 22, 22, '2025-10-30 14:22:16', '2026-03-11 11:45:45'),
(18, 18, 'normal', 720, 24, '2025-10-30 14:24:56', 0, '2026-05-22 14:59:48', 0, 4535561, NULL, 0, 0, 30, 2561, '2025-10-30 14:24:26', '2026-05-22 14:59:48'),
(19, 19, 'normal', 720, 24, '2025-10-30 14:58:10', 0, '2025-10-30 17:28:06', 0, 0, NULL, 0, 0, 1822, 10818, '2025-10-30 14:27:48', '2025-10-30 17:28:06'),
(20, 20, 'normal', 720, 24, '2025-10-31 16:57:40', 0, '2025-11-07 19:12:27', 0, 188087, NULL, 0, 0, 44797, 44797, '2025-10-30 14:31:03', '2025-11-07 19:12:27'),
(21, 21, 'normal', 720, 24, '2025-10-30 14:38:06', 0, '2025-10-30 14:38:10', 0, 0, NULL, 0, 0, 332, 336, '2025-10-30 14:32:34', '2025-10-30 14:38:10'),
(22, 22, 'normal', 720, 24, '2025-10-30 15:11:07', 0, '2025-10-30 17:33:46', 0, 0, NULL, 0, 0, 1849, 10408, '2025-10-30 14:40:18', '2025-10-30 17:33:46'),
(23, 23, 'normal', 720, 24, '2025-10-30 15:12:15', 0, '2025-10-31 16:57:23', 0, 0, NULL, 0, 0, 1821, 44129, '2025-10-30 14:41:54', '2025-10-31 16:57:23'),
(24, 24, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-10-30 15:16:04', '2025-10-30 15:16:04'),
(25, 25, 'normal', 720, 24, NULL, 0, '2025-10-30 16:51:47', 0, 0, NULL, 0, 0, 0, 100, '2025-10-30 16:50:07', '2025-10-30 16:51:47'),
(26, 26, 'normal', 720, 24, NULL, 0, '2025-10-30 17:54:22', 0, 0, NULL, 0, 0, 0, 3687, '2025-10-30 16:52:55', '2025-10-30 17:54:22'),
(27, 27, 'normal', 720, 24, NULL, 0, '2025-11-03 12:09:01', 0, 54011, NULL, 0, 0, 0, 950, '2025-10-30 16:53:00', '2025-11-03 12:09:01'),
(28, 28, 'normal', 720, 24, NULL, 0, '2025-11-06 17:43:41', 0, 181182, NULL, 0, 0, 0, 1854, '2025-10-30 16:53:05', '2025-11-06 17:43:41'),
(29, 29, 'normal', 720, 24, '2025-10-30 17:09:12', 0, NULL, 0, 0, NULL, 0, 0, 46, 0, '2025-10-30 17:08:26', '2025-10-30 17:09:12'),
(30, 30, 'normal', 720, 24, NULL, 0, '2025-11-03 19:14:04', 0, 74708, NULL, 0, 0, 0, 4255, '2025-10-30 17:18:01', '2025-11-03 19:14:04'),
(31, 31, 'normal', 720, 24, NULL, 0, '2025-12-02 12:51:11', 0, 511670, NULL, 0, 0, 0, 146597, '2025-10-30 19:00:04', '2025-12-02 12:51:11'),
(32, 32, 'normal', 720, 24, NULL, 0, '2025-10-31 12:23:18', 0, 8, NULL, 0, 0, 0, 11709, '2025-10-30 19:08:01', '2025-10-31 12:23:18'),
(33, 33, 'normal', 720, 24, NULL, 0, '2025-10-31 14:35:27', 0, 3830, NULL, 0, 0, 0, 13656, '2025-10-30 19:44:01', '2025-10-31 14:35:27'),
(34, 34, 'normal', 720, 24, '2025-10-31 12:40:01', 0, NULL, 0, 385099, '2025-11-21 14:02:54', 1, 0, 20, 0, '2025-10-31 12:39:41', '2025-11-21 14:02:54'),
(35, 35, 'normal', 720, 24, '2025-10-31 12:43:02', 0, NULL, 0, 0, NULL, 0, 0, 46, 0, '2025-10-31 12:42:16', '2025-10-31 12:43:02'),
(36, 36, 'normal', 720, 24, '2025-10-31 12:49:13', 0, '2026-05-22 14:58:07', 0, 4511298, NULL, 0, 0, 14, 50, '2025-10-31 12:48:59', '2026-05-22 14:58:07'),
(37, 37, 'normal', 720, 24, '2025-10-31 12:50:59', 0, '2025-11-04 16:52:47', 0, 86502, NULL, 0, 0, 9, 15, '2025-10-31 12:50:50', '2025-11-04 16:52:47'),
(38, 38, 'normal', 720, 24, '2025-10-31 12:52:17', 0, NULL, 0, 0, '2025-10-31 12:52:28', 1, 0, 8, 0, '2025-10-31 12:52:09', '2025-10-31 12:52:28'),
(39, 39, 'normal', 720, 24, '2025-10-31 12:54:35', 0, '2025-12-01 14:00:55', 0, 641120, NULL, 0, 0, 13, 18, '2025-10-31 12:54:22', '2025-12-01 14:00:55'),
(40, 40, 'normal', 720, 24, '2025-10-31 12:56:35', 0, NULL, 0, 0, '2025-10-31 12:56:40', 1, 0, 12, 0, '2025-10-31 12:56:23', '2025-10-31 12:56:40'),
(41, 41, 'normal', 720, 24, '2025-10-31 12:58:12', 0, NULL, 0, 0, '2025-10-31 12:58:18', 1, 0, 12, 0, '2025-10-31 12:58:00', '2025-10-31 12:58:18'),
(42, 42, 'normal', 720, 24, '2025-10-31 12:59:43', 0, '2026-05-08 19:31:46', 0, 4203117, NULL, 0, 0, 9, 15, '2025-10-31 12:59:34', '2026-05-08 19:31:46'),
(43, 43, 'normal', 720, 24, NULL, 0, '2025-11-10 11:28:40', 0, 0, '2025-10-31 20:05:35', 1, 0, 0, 203198, '2025-10-31 15:02:02', '2025-11-10 11:28:40'),
(44, 44, 'normal', 720, 24, NULL, 0, '2025-11-24 12:53:34', 0, 549721, NULL, 0, 0, 0, 2852, '2025-10-31 19:24:01', '2025-11-24 12:53:34'),
(45, 45, 'normal', 720, 24, NULL, 0, NULL, 0, 0, '2025-11-04 12:35:48', 1, 0, 0, 0, '2025-11-03 16:06:01', '2025-11-04 12:35:48'),
(46, 46, 'normal', 720, 24, NULL, 0, '2025-11-04 12:34:56', 0, 0, NULL, 0, 0, 0, 16735, '2025-11-03 17:56:01', '2025-11-04 12:34:56'),
(47, 47, 'normal', 720, 24, NULL, 0, '2025-11-07 15:22:36', 0, 115565, NULL, 0, 0, 0, 4587, '2025-11-04 12:00:04', '2025-11-07 15:22:36'),
(48, 48, 'normal', 720, 24, '2025-11-04 13:08:13', 0, '2025-11-04 13:28:25', 0, 0, NULL, 0, 0, 14, 1226, '2025-11-04 13:07:59', '2025-11-04 13:28:25'),
(49, 49, 'normal', 720, 24, NULL, 0, '2025-11-11 13:43:52', 0, 179489, NULL, 0, 0, 0, 1822, '2025-11-04 13:22:01', '2025-11-11 13:43:52'),
(50, 50, 'normal', 720, 24, NULL, 0, '2025-11-04 20:43:10', 0, 11075, NULL, 0, 0, 0, 14794, '2025-11-04 13:32:01', '2025-11-04 20:43:10'),
(51, 51, 'normal', 720, 24, NULL, 0, '2025-11-04 14:19:13', 0, 0, NULL, 0, 0, 0, 1632, '2025-11-04 13:52:01', '2025-11-04 14:19:13'),
(52, 52, 'normal', 720, 24, NULL, 0, '2025-11-07 14:30:45', 0, 46183, NULL, 0, 0, 0, 50101, '2025-11-04 17:46:01', '2025-11-07 14:30:45'),
(53, 53, 'normal', 720, 24, NULL, 0, '2025-11-14 19:35:53', 0, 244467, NULL, 0, 0, 0, 48205, '2025-11-04 18:18:01', '2025-11-14 19:35:53'),
(54, 54, 'normal', 720, 24, NULL, 0, '2025-11-07 15:03:02', 0, 87849, NULL, 0, 0, 0, 5692, '2025-11-04 19:04:01', '2025-11-07 15:03:02'),
(55, 55, 'normal', 720, 24, NULL, 0, '2025-11-06 17:40:56', 0, 21630, NULL, 0, 0, 0, 41905, '2025-11-04 20:02:01', '2025-11-06 17:40:56'),
(56, 56, 'normal', 720, 24, NULL, 0, '2025-11-05 12:28:32', 0, 0, NULL, 0, 0, 0, 1471, '2025-11-05 12:04:01', '2025-11-05 12:28:32'),
(57, 57, 'normal', 720, 24, '2025-11-05 12:43:53', 0, '2025-11-05 14:04:32', 0, 0, NULL, 0, 0, 80, 4919, '2025-11-05 12:42:33', '2025-11-05 14:04:32'),
(58, 58, 'normal', 720, 24, '2025-11-05 12:46:55', 0, '2025-11-06 14:10:14', 0, 0, NULL, 0, 0, 15, 41014, '2025-11-05 12:46:40', '2025-11-06 14:10:14'),
(59, 59, 'normal', 720, 24, NULL, 0, '2025-11-06 14:20:26', 0, 0, NULL, 0, 0, 0, 39865, '2025-11-05 13:16:01', '2025-11-06 14:20:26'),
(60, 60, 'normal', 720, 24, NULL, 0, '2025-11-05 14:06:05', 0, 0, NULL, 0, 0, 0, 1924, '2025-11-05 13:34:01', '2025-11-05 14:06:05'),
(61, 61, 'normal', 720, 24, NULL, 0, '2026-06-15 17:04:23', 0, 4844176, NULL, 0, 0, 0, 88926, '2025-11-05 14:46:01', '2026-06-15 17:04:23'),
(62, 62, 'normal', 720, 24, NULL, 0, '2025-11-06 14:55:55', 0, 2261, NULL, 0, 0, 0, 32773, '2025-11-05 15:12:01', '2025-11-06 14:55:55'),
(63, 63, 'normal', 720, 24, NULL, 0, '2025-11-07 15:21:50', 0, 39856, NULL, 0, 0, 0, 29613, '2025-11-05 16:04:01', '2025-11-07 15:21:50'),
(64, 64, 'normal', 720, 24, NULL, 0, '2025-11-06 14:54:13', 0, 0, NULL, 0, 0, 0, 31332, '2025-11-05 16:12:01', '2025-11-06 14:54:13'),
(65, 65, 'normal', 720, 24, NULL, 0, '2025-11-06 14:16:22', 0, 0, NULL, 0, 0, 0, 10101, '2025-11-06 11:28:01', '2025-11-06 14:16:22'),
(66, 66, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-06 11:30:03', '2025-11-06 11:30:03'),
(67, 67, 'normal', 720, 24, NULL, 0, '2025-11-10 14:31:32', 0, 72844, NULL, 0, 0, 0, 1407, '2025-11-06 13:54:01', '2025-11-10 14:31:32'),
(68, 68, 'normal', 720, 24, NULL, 0, '2025-11-17 14:32:13', 0, 250611, NULL, 0, 0, 0, 1280, '2025-11-06 14:34:02', '2025-11-17 14:32:13'),
(69, 69, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-06 14:34:07', '2025-11-06 14:34:07'),
(70, 70, 'baixa', 1440, 40, '2025-11-06 14:53:09', 0, '2026-06-15 17:05:20', 0, 397303, NULL, 0, 0, 17, 4503045, '2025-11-06 14:52:52', '2026-06-15 17:05:20'),
(71, 71, 'normal', 720, 24, NULL, 0, '2025-11-07 15:13:14', 0, 0, NULL, 0, 0, 0, 36192, '2025-11-06 15:10:02', '2025-11-07 15:13:14'),
(72, 72, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-06 15:32:01', '2025-11-06 15:32:01'),
(73, 73, 'normal', 720, 24, '2025-11-06 15:48:11', 0, '2025-11-06 17:49:39', 0, 0, NULL, 0, 0, 81, 7369, '2025-11-06 15:46:50', '2025-11-06 17:49:39'),
(74, 74, 'normal', 720, 24, NULL, 0, '2025-11-19 17:25:17', 0, 284206, NULL, 0, 0, 0, 29309, '2025-11-06 20:20:02', '2025-11-19 17:25:17'),
(75, 75, 'normal', 720, 24, NULL, 0, '2025-11-17 14:33:36', 0, 203715, NULL, 0, 0, 0, 25101, '2025-11-06 21:02:01', '2025-11-17 14:33:36'),
(76, 76, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-06 21:06:01', '2025-11-06 21:06:01'),
(77, 77, 'normal', 720, 24, NULL, 0, '2025-11-07 11:20:06', 0, 0, NULL, 0, 0, 0, 365, '2025-11-07 11:14:01', '2025-11-07 11:20:06'),
(78, 78, 'normal', 720, 24, NULL, 0, '2025-11-07 15:38:19', 0, 0, NULL, 0, 0, 0, 858, '2025-11-07 15:24:01', '2025-11-07 15:38:19'),
(79, 79, 'normal', 720, 24, NULL, 0, '2025-11-17 12:35:11', 0, 195960, NULL, 0, 0, 0, 4390, '2025-11-07 16:56:01', '2025-11-17 12:35:11'),
(80, 80, 'normal', 720, 24, '2025-11-10 11:45:15', 0, '2025-11-11 13:13:34', 0, 0, NULL, 0, 0, 40, 41339, '2025-11-10 11:44:35', '2025-11-11 13:13:34'),
(81, 81, 'normal', 720, 24, NULL, 0, '2025-11-12 14:12:27', 0, 0, NULL, 0, 0, 0, 76226, '2025-11-10 13:02:01', '2025-11-12 14:12:27'),
(82, 82, 'normal', 720, 24, NULL, 0, '2025-11-10 14:31:14', 0, 0, NULL, 0, 0, 0, 4633, '2025-11-10 13:14:01', '2025-11-10 14:31:14'),
(83, 83, 'normal', 720, 24, NULL, 0, '2025-11-10 14:40:00', 0, 0, NULL, 0, 0, 0, 1798, '2025-11-10 14:10:02', '2025-11-10 14:40:00'),
(84, 84, 'normal', 720, 24, '2025-11-10 14:33:39', 0, '2025-11-13 17:29:02', 0, 85308, NULL, 0, 0, 21, 33236, '2025-11-10 14:33:18', '2025-11-13 17:29:02'),
(85, 85, 'normal', 720, 24, NULL, 0, '2025-11-11 14:07:28', 0, 0, NULL, 0, 0, 0, 28646, '2025-11-10 16:10:02', '2025-11-11 14:07:28'),
(86, 86, 'normal', 720, 24, NULL, 0, '2025-11-11 13:10:13', 0, 0, NULL, 0, 0, 0, 8172, '2025-11-10 20:54:01', '2025-11-11 13:10:13'),
(87, 87, 'normal', 720, 24, NULL, 0, '2025-11-11 14:04:54', 0, 0, NULL, 0, 0, 0, 8572, '2025-11-11 11:42:02', '2025-11-11 14:04:54'),
(88, 88, 'normal', 720, 24, '2025-11-11 12:19:53', 0, '2025-11-12 13:43:35', 0, 0, NULL, 0, 0, 52, 41074, '2025-11-11 12:19:01', '2025-11-12 13:43:35'),
(89, 89, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:06:01', '2025-11-11 14:06:01'),
(90, 90, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:08:01', '2025-11-11 14:08:01'),
(91, 91, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:10:02', '2025-11-11 14:10:02'),
(92, 92, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:12:01', '2025-11-11 14:12:01'),
(93, 93, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:14:01', '2025-11-11 14:14:01'),
(94, 94, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:16:01', '2025-11-11 14:16:01'),
(95, 95, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:18:01', '2025-11-11 14:18:01'),
(96, 96, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:20:02', '2025-11-11 14:20:02'),
(97, 97, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:22:01', '2025-11-11 14:22:01'),
(98, 98, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:24:01', '2025-11-11 14:24:01'),
(99, 99, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:26:01', '2025-11-11 14:26:01'),
(100, 100, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:28:01', '2025-11-11 14:28:01'),
(101, 101, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:30:03', '2025-11-11 14:30:03'),
(102, 102, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:32:01', '2025-11-11 14:32:01'),
(103, 103, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:34:01', '2025-11-11 14:34:01'),
(104, 104, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:36:01', '2025-11-11 14:36:01'),
(105, 105, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:38:01', '2025-11-11 14:38:01'),
(106, 106, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:40:02', '2025-11-11 14:40:02'),
(107, 107, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:42:01', '2025-11-11 14:42:01'),
(108, 108, 'normal', 720, 24, NULL, 0, '2025-11-11 15:08:30', 0, 0, NULL, 0, 0, 0, 1469, '2025-11-11 14:44:01', '2025-11-11 15:08:30'),
(109, 109, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:46:02', '2025-11-11 14:46:02'),
(110, 110, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:48:01', '2025-11-11 14:48:01'),
(111, 111, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:50:02', '2025-11-11 14:50:02'),
(112, 112, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:52:01', '2025-11-11 14:52:01'),
(113, 113, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:54:01', '2025-11-11 14:54:01'),
(114, 114, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:56:01', '2025-11-11 14:56:01'),
(115, 115, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 14:58:01', '2025-11-11 14:58:01'),
(116, 116, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:00:05', '2025-11-11 15:00:05'),
(117, 117, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:02:01', '2025-11-11 15:02:01'),
(118, 118, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:04:01', '2025-11-11 15:04:01'),
(119, 119, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:06:02', '2025-11-11 15:06:02'),
(120, 120, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:08:01', '2025-11-11 15:08:01'),
(121, 121, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:10:02', '2025-11-11 15:10:02'),
(122, 122, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:10:07', '2025-11-11 15:10:07'),
(123, 123, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:10:12', '2025-11-11 15:10:12'),
(124, 124, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:12:01', '2025-11-11 15:12:01'),
(125, 125, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:12:08', '2025-11-11 15:12:08'),
(126, 126, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:12:13', '2025-11-11 15:12:13'),
(127, 127, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:14:01', '2025-11-11 15:14:01'),
(128, 128, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:14:06', '2025-11-11 15:14:06'),
(129, 129, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:14:11', '2025-11-11 15:14:11'),
(130, 130, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:16:01', '2025-11-11 15:16:01'),
(131, 131, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:16:06', '2025-11-11 15:16:06'),
(132, 132, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:16:11', '2025-11-11 15:16:11'),
(133, 133, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:18:01', '2025-11-11 15:18:01'),
(134, 134, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:18:06', '2025-11-11 15:18:06'),
(135, 135, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:18:11', '2025-11-11 15:18:11'),
(136, 136, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:20:02', '2025-11-11 15:20:02'),
(137, 137, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:20:07', '2025-11-11 15:20:07'),
(138, 138, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:20:12', '2025-11-11 15:20:12'),
(139, 139, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:22:01', '2025-11-11 15:22:01'),
(140, 140, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:22:06', '2025-11-11 15:22:06'),
(141, 141, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:22:11', '2025-11-11 15:22:11'),
(142, 142, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:24:01', '2025-11-11 15:24:01'),
(143, 143, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:24:06', '2025-11-11 15:24:06'),
(144, 144, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:24:11', '2025-11-11 15:24:11'),
(145, 145, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:26:02', '2025-11-11 15:26:02'),
(146, 146, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:26:06', '2025-11-11 15:26:06'),
(147, 147, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:26:11', '2025-11-11 15:26:11'),
(148, 148, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:28:01', '2025-11-11 15:28:01'),
(149, 149, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:28:06', '2025-11-11 15:28:06'),
(150, 150, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:28:11', '2025-11-11 15:28:11'),
(151, 151, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:30:03', '2025-11-11 15:30:03'),
(152, 152, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:30:08', '2025-11-11 15:30:08'),
(153, 153, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:30:13', '2025-11-11 15:30:13'),
(154, 154, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:32:01', '2025-11-11 15:32:01'),
(155, 155, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:32:06', '2025-11-11 15:32:06'),
(156, 156, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:32:11', '2025-11-11 15:32:11'),
(157, 157, 'normal', 720, 24, '2025-11-11 16:11:28', 0, NULL, 0, 0, NULL, 0, 0, 2247, 0, '2025-11-11 15:34:01', '2025-11-11 16:11:28'),
(158, 158, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:34:06', '2025-11-11 15:34:06'),
(159, 159, 'normal', 720, 24, '2025-11-11 16:11:25', 0, NULL, 0, 0, NULL, 0, 0, 2234, 0, '2025-11-11 15:34:11', '2025-11-11 16:11:25'),
(160, 160, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:36:01', '2025-11-11 15:36:01'),
(161, 161, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:36:06', '2025-11-11 15:36:06'),
(162, 162, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:38:01', '2025-11-11 15:38:01'),
(163, 163, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:38:06', '2025-11-11 15:38:06'),
(164, 164, 'normal', 720, 24, '2025-11-11 16:11:21', 0, NULL, 0, 0, NULL, 0, 0, 1879, 0, '2025-11-11 15:40:02', '2025-11-11 16:11:21'),
(165, 165, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:40:07', '2025-11-11 15:40:07'),
(166, 166, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:42:01', '2025-11-11 15:42:01'),
(167, 167, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:42:06', '2025-11-11 15:42:06'),
(168, 168, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:44:02', '2025-11-11 15:44:02'),
(169, 169, 'normal', 720, 24, '2025-11-11 16:04:50', 0, NULL, 0, 0, NULL, 0, 0, 1243, 0, '2025-11-11 15:44:07', '2025-11-11 16:04:50'),
(170, 170, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:46:01', '2025-11-11 15:46:01'),
(171, 171, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:46:06', '2025-11-11 15:46:06'),
(172, 172, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:48:01', '2025-11-11 15:48:01'),
(173, 173, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:48:06', '2025-11-11 15:48:06'),
(174, 174, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:50:02', '2025-11-11 15:50:02'),
(175, 175, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-11 15:52:01', '2025-11-11 15:52:01'),
(176, 176, 'normal', 720, 24, NULL, 0, '2025-11-12 14:06:14', 0, 0, NULL, 0, 0, 0, 21612, '2025-11-11 18:06:02', '2025-11-12 14:06:14'),
(177, 177, 'normal', 720, 24, NULL, 0, '2025-11-12 13:55:19', 0, 0, NULL, 0, 0, 0, 19757, '2025-11-11 18:26:02', '2025-11-12 13:55:19'),
(178, 178, 'normal', 720, 24, NULL, 0, '2025-12-03 14:49:49', 0, 240589, NULL, 0, 0, 0, 147239, '2025-11-13 15:06:01', '2025-12-03 14:49:49'),
(179, 179, 'normal', 720, 24, NULL, 0, '2025-12-12 19:17:03', 0, 483423, NULL, 0, 0, 0, 131039, '2025-11-13 19:36:01', '2025-12-12 19:17:03'),
(180, 180, 'normal', 720, 24, NULL, 0, '2025-11-14 13:35:10', 0, 3691, NULL, 0, 0, 0, 5619, '2025-11-13 21:22:01', '2025-11-14 13:35:10'),
(181, 181, 'normal', 720, 24, NULL, 0, '2025-11-14 19:24:41', 0, 0, NULL, 0, 0, 0, 23920, '2025-11-14 12:46:01', '2025-11-14 19:24:41'),
(182, 182, 'normal', 720, 24, NULL, 0, '2025-11-14 14:30:55', 0, 0, NULL, 0, 0, 0, 3652, '2025-11-14 13:30:03', '2025-11-14 14:30:55'),
(183, 183, 'normal', 720, 24, NULL, 0, '2025-12-02 12:50:17', 0, 247249, NULL, 0, 0, 0, 64766, '2025-11-14 16:10:02', '2025-12-02 12:50:17'),
(184, 184, 'normal', 720, 24, NULL, 0, '2025-11-19 14:35:04', 0, 90217, NULL, 0, 0, 0, 685, '2025-11-14 19:20:02', '2025-11-19 14:35:04'),
(185, 185, 'normal', 720, 24, NULL, 0, '2025-11-17 14:02:43', 0, 4332, NULL, 0, 0, 0, 7829, '2025-11-14 20:40:02', '2025-11-17 14:02:43'),
(186, 186, 'normal', 720, 24, NULL, 0, '2026-01-05 12:08:31', 0, 1066676, NULL, 0, 0, 0, 6635, '2025-11-14 22:44:01', '2026-01-05 12:08:31'),
(187, 187, 'normal', 720, 24, NULL, 0, '2025-11-17 14:38:36', 0, 0, NULL, 0, 0, 0, 8914, '2025-11-17 12:10:02', '2025-11-17 14:38:36'),
(188, 188, 'normal', 720, 24, NULL, 0, '2025-11-18 17:50:38', 0, 0, NULL, 0, 0, 0, 21070, '2025-11-18 11:59:28', '2025-11-18 17:50:38'),
(189, 189, 'normal', 720, 24, NULL, 0, '2025-11-18 18:12:03', 0, 0, NULL, 0, 0, 0, 18962, '2025-11-18 12:56:01', '2025-11-18 18:12:03'),
(190, 190, 'normal', 720, 24, NULL, 0, '2025-12-02 12:49:45', 0, 208613, NULL, 0, 0, 0, 37222, '2025-11-18 16:32:30', '2025-12-02 12:49:45'),
(191, 191, 'normal', 720, 24, NULL, 0, '2025-11-19 17:12:59', 0, 0, NULL, 0, 0, 0, 26577, '2025-11-18 19:50:02', '2025-11-19 17:12:59'),
(192, 192, 'normal', 720, 24, NULL, 0, '2025-11-19 17:24:24', 0, 0, NULL, 0, 0, 0, 23064, '2025-11-19 10:23:28', '2025-11-19 17:24:24'),
(193, 193, 'normal', 720, 24, NULL, 0, '2025-12-12 14:29:31', 0, 466171, NULL, 0, 0, 0, 23073, '2025-11-19 13:35:27', '2025-12-12 14:29:31'),
(194, 194, 'normal', 720, 24, NULL, 0, '2025-12-01 12:12:06', 0, 207249, NULL, 0, 0, 0, 8149, '2025-11-19 14:10:02', '2025-12-01 12:12:06'),
(195, 195, 'normal', 720, 24, NULL, 0, '2025-12-12 12:41:31', 0, 1119, NULL, 0, 0, 0, 479303, '2025-11-19 14:14:29', '2025-12-12 12:41:31'),
(196, 196, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-11-20 03:29:28', '2025-11-20 03:29:28'),
(197, 197, 'normal', 720, 24, NULL, 0, '2025-11-24 19:40:56', 0, 8242, NULL, 0, 0, 0, 23014, '2025-11-22 12:10:02', '2025-11-24 19:40:56'),
(198, 198, 'normal', 720, 24, NULL, 0, '2025-12-02 12:51:43', 0, 168703, NULL, 0, 0, 0, 0, '2025-11-24 14:30:03', '2025-12-02 12:51:43'),
(199, 199, 'normal', 720, 24, NULL, 0, '2025-11-24 17:23:05', 0, 0, NULL, 0, 0, 0, 6183, '2025-11-24 15:40:02', '2025-11-24 17:23:05'),
(200, 200, 'normal', 720, 24, NULL, 0, '2025-11-24 18:05:36', 0, 0, NULL, 0, 0, 0, 2435, '2025-11-24 17:25:01', '2025-11-24 18:05:36'),
(201, 201, 'normal', 720, 24, NULL, 0, '2026-01-05 12:07:16', 0, 930660, NULL, 0, 0, 0, 12976, '2025-11-24 20:35:01', '2026-01-05 12:07:16'),
(202, 202, 'normal', 720, 24, NULL, 0, '2025-11-25 12:50:21', 0, 0, NULL, 0, 0, 0, 7371, '2025-11-24 20:47:30', '2025-11-25 12:50:21'),
(203, 203, 'normal', 720, 24, NULL, 0, '2025-11-26 12:59:58', 0, 4929, NULL, 0, 0, 0, 16664, '2025-11-25 17:00:05', '2025-11-26 12:59:58'),
(204, 204, 'normal', 720, 24, NULL, 0, '2025-11-27 13:35:06', 0, 42013, NULL, 0, 0, 0, 14391, '2025-11-25 17:55:02', '2025-11-27 13:35:06'),
(205, 205, 'normal', 720, 24, NULL, 0, '2025-12-02 12:48:41', 0, 132407, NULL, 0, 0, 0, 5812, '2025-11-25 19:25:02', '2025-12-02 12:48:41'),
(206, 206, 'normal', 720, 24, NULL, 0, '2025-12-12 14:30:02', 0, 394340, NULL, 0, 0, 0, 7062, '2025-11-25 20:50:02', '2025-12-12 14:30:02'),
(207, 207, 'normal', 720, 24, NULL, 0, '2026-03-11 17:22:39', 0, 2276775, NULL, 0, 0, 0, 35182, '2025-11-26 14:10:02', '2026-03-11 17:22:39'),
(208, 208, 'normal', 720, 24, NULL, 0, '2025-12-01 19:47:10', 0, 95695, NULL, 0, 0, 0, 12003, '2025-11-26 17:05:02', '2025-12-01 19:47:10'),
(209, 209, 'normal', 720, 24, NULL, 0, '2025-11-27 12:28:07', 0, 0, NULL, 0, 0, 0, 7985, '2025-11-26 20:15:02', '2025-11-27 12:28:07'),
(210, 210, 'normal', 720, 24, NULL, 0, '2025-12-09 14:38:02', 0, 257991, NULL, 0, 0, 0, 13389, '2025-11-27 11:15:02', '2025-12-09 14:38:02'),
(211, 211, 'normal', 720, 24, NULL, 0, '2025-11-27 16:46:17', 0, 0, NULL, 0, 0, 0, 1276, '2025-11-27 16:25:01', '2025-11-27 16:46:17'),
(212, 212, 'normal', 720, 24, NULL, 0, '2025-12-01 13:54:32', 0, 0, NULL, 0, 0, 0, 70798, '2025-11-27 18:20:02', '2025-12-01 13:54:32'),
(213, 213, 'normal', 720, 24, NULL, 0, '2025-12-03 11:39:31', 0, 34771, NULL, 0, 0, 0, 67498, '2025-11-27 19:15:02', '2025-12-03 11:39:31'),
(214, 214, 'normal', 720, 24, NULL, 0, '2026-03-11 18:26:32', 0, 2234679, NULL, 0, 0, 0, 30111, '2025-11-27 19:20:02', '2026-03-11 18:26:32'),
(215, 215, 'normal', 720, 24, NULL, 0, '2025-12-09 14:40:10', 0, 227687, NULL, 0, 0, 0, 9321, '2025-11-28 11:50:02', '2025-12-09 14:40:10'),
(216, 216, 'normal', 720, 24, NULL, 0, '2026-01-05 12:08:23', 0, 834174, NULL, 0, 0, 0, 627, '2025-11-28 14:15:02', '2026-01-05 12:08:23'),
(217, 217, 'normal', 720, 24, '2025-12-01 16:25:51', 0, '2025-12-12 12:47:20', 0, 0, NULL, 0, 0, 32400, 298040, '2025-11-28 21:05:01', '2025-12-12 12:47:20'),
(218, 218, 'normal', 720, 24, '2025-12-01 15:03:13', 0, '2025-12-03 15:19:51', 0, 47991, NULL, 0, 0, 0, 0, '2025-12-01 11:37:12', '2025-12-03 15:19:51'),
(219, 219, 'normal', 720, 24, '2025-12-01 13:46:13', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 12:08:12', '2025-12-01 13:46:13'),
(220, 220, 'normal', 720, 24, '2025-12-01 18:07:18', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 16:31:44', '2025-12-01 18:07:18'),
(221, 221, 'normal', 720, 24, '2025-12-01 18:07:22', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 16:32:21', '2025-12-01 18:07:22'),
(222, 222, 'baixa', 1440, 40, '2025-12-01 18:07:26', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 16:32:35', '2025-12-01 18:07:26'),
(223, 223, 'normal', 720, 24, '2025-12-01 18:07:32', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 17:19:36', '2025-12-01 18:07:32'),
(224, 224, 'normal', 720, 24, '2025-12-01 17:29:43', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 17:29:15', '2025-12-01 17:29:43'),
(225, 225, 'normal', 720, 24, '2025-12-01 18:07:43', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 17:31:57', '2025-12-01 18:07:43'),
(226, 226, 'normal', 720, 24, '2025-12-01 18:07:52', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 17:48:36', '2025-12-01 18:07:52'),
(227, 228, 'normal', 720, 24, '2025-12-01 19:09:08', 0, '2025-12-01 19:09:19', 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 18:13:15', '2025-12-01 19:09:19'),
(228, 229, 'normal', 720, 24, '2025-12-01 18:20:00', 0, '2025-12-01 19:09:25', 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 18:19:00', '2025-12-01 19:09:25'),
(229, 229, 'normal', 480, 24, '2025-12-01 18:20:00', 0, '2025-12-01 19:09:25', 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 18:19:00', '2025-12-01 19:09:25'),
(230, 230, 'normal', 720, 24, '2025-12-01 19:24:35', 0, '2025-12-01 19:29:16', 0, 0, NULL, 0, 0, 0, 0, '2025-12-01 19:23:32', '2025-12-01 19:29:16'),
(231, 231, 'normal', 720, 24, '2025-12-02 15:00:19', 0, '2025-12-03 16:50:25', 0, 36907, NULL, 0, 0, 3637, 5736, '2025-12-02 13:59:42', '2025-12-03 16:50:25'),
(232, 232, 'normal', 720, 24, '2025-12-02 14:47:50', 0, '2025-12-02 14:57:52', 0, 0, NULL, 0, 0, 467, 1069, '2025-12-02 14:40:03', '2025-12-02 14:57:52'),
(233, 233, 'normal', 720, 24, '2025-12-02 17:36:49', 0, '2025-12-02 17:44:21', 0, 0, NULL, 0, 0, 426, 878, '2025-12-02 17:29:43', '2025-12-02 17:44:21'),
(234, 234, 'normal', 720, 24, '2025-12-03 12:27:52', 0, NULL, 0, 0, NULL, 0, 0, 2463, 0, '2025-12-03 11:46:49', '2025-12-03 12:27:52'),
(235, 234, 'normal', 480, 24, '2025-12-03 12:27:52', 0, NULL, 0, 0, NULL, 0, 0, 2463, 0, '2025-12-03 11:46:49', '2025-12-03 12:27:52'),
(236, 235, 'normal', 720, 24, '2025-12-03 12:27:46', 0, NULL, 0, 0, NULL, 0, 0, 963, 0, '2025-12-03 12:11:43', '2025-12-03 12:27:46'),
(237, 236, 'normal', 720, 24, '2025-12-03 12:29:34', 0, '2026-05-14 11:24:44', 0, 3624898, NULL, 0, 0, 52, 64, '2025-12-03 12:28:42', '2026-05-14 11:24:44'),
(238, 236, 'normal', 480, 24, '2025-12-03 12:29:34', 0, '2026-05-14 11:24:44', 0, 3624898, NULL, 0, 0, 52, 64, '2025-12-03 12:28:42', '2026-05-14 11:24:44'),
(239, 237, 'normal', 720, 24, '2025-12-04 16:32:35', 0, '2025-12-05 18:42:46', 0, 40197, NULL, 0, 0, 36892, 36906, '2025-12-03 15:17:43', '2025-12-05 18:42:46'),
(240, 238, 'normal', 720, 24, '2025-12-03 17:47:24', 0, '2025-12-03 17:53:54', 0, 0, NULL, 0, 0, 7901, 8291, '2025-12-03 15:35:43', '2025-12-03 17:53:54'),
(241, 239, 'normal', 720, 24, '2025-12-04 16:32:40', 0, '2025-12-05 18:42:37', 0, 40191, NULL, 0, 0, 6758, 6764, '2025-12-04 14:40:02', '2025-12-05 18:42:37'),
(242, 240, 'normal', 720, 24, '2025-12-04 16:22:42', 0, '2025-12-04 16:33:11', 0, 0, NULL, 0, 0, 1199, 1828, '2025-12-04 16:02:43', '2025-12-04 16:33:11'),
(243, 241, 'normal', 720, 24, '2025-12-09 14:39:36', 0, '2026-05-22 19:07:17', 0, 3601590, NULL, 0, 0, 83753, 191824, '2025-12-04 18:23:43', '2026-05-22 19:07:17'),
(244, 242, 'normal', 720, 24, '2025-12-12 12:27:48', 0, NULL, 0, 0, '2025-12-12 12:28:07', 1, 0, 171065, 0, '2025-12-04 18:56:43', '2025-12-12 12:28:07'),
(245, 243, 'normal', 720, 24, '2025-12-09 14:39:23', 0, '2026-01-05 17:01:23', 0, 516041, NULL, 0, 0, 77963, 186042, '2025-12-04 20:45:02', '2026-01-05 17:01:23'),
(246, 244, 'normal', 720, 24, '2025-12-12 12:28:36', 0, '2025-12-12 12:30:21', 0, 0, NULL, 0, 0, 167316, 167421, '2025-12-04 21:40:02', '2025-12-12 12:30:21'),
(247, 245, 'normal', 720, 24, '2025-12-09 14:39:11', 0, '2026-01-05 16:35:30', 0, 514502, NULL, 0, 0, 77951, 186028, '2025-12-04 21:40:07', '2026-01-05 16:35:30'),
(248, 246, 'normal', 720, 24, '2025-12-09 14:39:05', 0, '2025-12-10 14:49:32', 0, 0, NULL, 0, 0, 69682, 102709, '2025-12-05 13:17:43', '2025-12-10 14:49:32'),
(249, 247, 'normal', 720, 24, '2025-12-09 14:38:54', 0, '2025-12-16 13:11:13', 0, 60617, NULL, 0, 0, 66430, 162552, '2025-12-05 14:11:44', '2025-12-16 13:11:13'),
(250, 248, 'normal', 720, 24, '2025-12-09 14:38:44', 0, '2025-12-11 17:44:10', 0, 0, NULL, 0, 0, 45720, 121646, '2025-12-05 19:56:44', '2025-12-11 17:44:10'),
(251, 249, 'normal', 720, 24, '2025-12-09 14:38:31', 0, '2025-12-12 19:12:18', 0, 17233, NULL, 0, 0, 13111, 109505, '2025-12-08 14:29:45', '2025-12-12 19:12:18'),
(252, 250, 'normal', 720, 24, '2025-12-09 14:38:26', 0, '2025-12-10 14:30:43', 0, 0, NULL, 0, 0, 13106, 45043, '2025-12-08 17:08:45', '2025-12-10 14:30:43'),
(253, 251, 'normal', 720, 24, '2025-12-09 14:38:19', 0, '2025-12-16 18:27:24', 0, 70227, NULL, 0, 0, 7097, 112615, '2025-12-09 12:40:02', '2025-12-16 18:27:24'),
(254, 252, 'normal', 720, 24, '2025-12-09 14:38:13', 0, '2025-12-10 14:55:33', 0, 0, NULL, 0, 0, 1408, 34848, '2025-12-09 14:14:45', '2025-12-10 14:55:33'),
(255, 253, 'normal', 720, 24, '2025-12-10 14:56:46', 0, '2025-12-11 17:40:09', 0, 0, NULL, 0, 0, 2880, 45083, '2025-12-10 14:08:46', '2025-12-11 17:40:09'),
(256, 254, 'normal', 720, 24, '2025-12-10 14:56:31', 0, '2025-12-11 11:39:52', 0, 0, NULL, 0, 0, 1965, 22566, '2025-12-10 14:23:46', '2025-12-11 11:39:52'),
(257, 255, 'normal', 720, 24, '2025-12-10 14:56:40', 0, '2025-12-11 17:39:59', 0, 0, NULL, 0, 0, 1299, 43498, '2025-12-10 14:35:01', '2025-12-11 17:39:59'),
(258, 256, 'normal', 720, 24, '2025-12-10 17:01:16', 0, '2025-12-12 19:12:06', 0, 17452, NULL, 0, 0, 4950, 60148, '2025-12-10 15:38:46', '2025-12-12 19:12:06'),
(259, 257, 'normal', 720, 24, '2025-12-12 12:47:54', 0, NULL, 0, 0, '2026-03-17 18:46:06', 1, 0, 43208, 0, '2025-12-10 18:47:46', '2026-03-17 18:46:06'),
(260, 258, 'normal', 720, 24, '2025-12-11 11:37:55', 0, '2025-12-11 17:25:12', 0, 0, NULL, 0, 0, 2873, 23710, '2025-12-10 19:50:02', '2025-12-11 17:25:12'),
(261, 259, 'normal', 720, 24, '2025-12-11 11:37:00', 0, '2025-12-11 11:37:17', 0, 0, NULL, 0, 0, 2220, 2237, '2025-12-10 20:14:45', '2025-12-11 11:37:17'),
(262, 260, 'normal', 720, 24, '2025-12-11 17:59:03', 0, '2025-12-11 17:59:18', 0, 0, NULL, 0, 0, 19141, 19156, '2025-12-11 12:40:02', '2025-12-11 17:59:18'),
(263, 261, 'normal', 720, 24, '2025-12-11 17:26:30', 0, '2025-12-12 11:51:51', 0, 0, NULL, 0, 0, 1784, 14105, '2025-12-11 16:56:46', '2025-12-12 11:51:51'),
(264, 262, 'normal', 720, 24, '2025-12-12 12:50:07', 0, '2025-12-15 11:26:25', 0, 57538, NULL, 0, 0, 13506, 14161, '2025-12-11 18:05:01', '2025-12-15 11:26:25'),
(265, 263, 'normal', 720, 24, '2025-12-12 13:09:37', 0, '2025-12-15 11:29:03', 0, 56972, NULL, 0, 0, 14091, 14142, '2025-12-11 18:14:46', '2025-12-15 11:29:03'),
(266, 264, 'normal', 720, 24, '2025-12-12 13:11:23', 0, '2025-12-12 13:19:10', 0, 0, NULL, 0, 0, 12397, 12864, '2025-12-11 18:44:46', '2025-12-12 13:19:10'),
(267, 265, 'normal', 720, 24, '2025-12-12 13:22:44', 0, '2025-12-12 13:45:18', 0, 0, NULL, 0, 0, 13071, 14425, '2025-12-11 18:44:53', '2025-12-12 13:45:18'),
(268, 266, 'normal', 720, 24, '2025-12-12 13:46:15', 0, '2026-01-07 12:57:58', 0, 568825, NULL, 0, 0, 13409, 24887, '2025-12-11 19:02:46', '2026-01-07 12:57:58'),
(269, 267, 'normal', 720, 24, '2025-12-12 14:01:15', 0, '2026-01-06 18:57:05', 0, 558053, NULL, 0, 0, 13409, 23906, '2025-12-11 19:17:46', '2026-01-06 18:57:05'),
(270, 268, 'normal', 720, 24, '2025-12-12 14:03:30', 0, '2025-12-12 14:24:54', 0, 0, NULL, 0, 0, 1709, 2993, '2025-12-12 13:35:01', '2025-12-12 14:24:54'),
(271, 269, 'normal', 720, 24, '2025-12-12 18:23:17', 0, '2025-12-16 18:27:48', 0, 54922, NULL, 0, 0, 4249, 14398, '2025-12-12 17:12:28', '2025-12-16 18:27:48'),
(272, 269, 'normal', 480, 24, '2025-12-12 18:23:17', 0, '2025-12-16 18:27:48', 0, 54922, NULL, 0, 0, 4249, 14398, '2025-12-12 17:12:28', '2025-12-16 18:27:48'),
(273, 270, 'normal', 720, 24, '2025-12-12 18:23:08', 0, '2025-12-17 19:29:13', 0, 91013, NULL, 0, 0, 3382, 13534, '2025-12-12 17:26:46', '2025-12-17 19:29:13'),
(274, 271, 'normal', 720, 24, '2025-12-12 19:49:02', 0, '2025-12-12 19:51:03', 0, 0, NULL, 0, 0, 74, 195, '2025-12-12 19:47:48', '2025-12-12 19:51:03'),
(275, 272, 'normal', 720, 24, '2025-12-16 13:29:03', 0, NULL, 0, 0, NULL, 0, 0, 41343, 0, '2025-12-12 20:17:48', '2025-12-16 13:29:03'),
(276, 273, 'normal', 720, 24, '2025-12-16 13:29:20', 0, NULL, 0, 0, NULL, 0, 0, 41360, 0, '2025-12-12 20:20:48', '2025-12-16 13:29:20'),
(277, 274, 'normal', 720, 24, '2025-12-16 13:29:26', 0, NULL, 0, 0, NULL, 0, 0, 8966, 0, '2025-12-15 19:14:49', '2025-12-16 13:29:26'),
(278, 275, 'normal', 720, 24, '2025-12-16 13:15:51', 0, '2025-12-16 13:27:33', 0, 0, NULL, 0, 0, 8151, 8853, '2025-12-15 19:38:01', '2025-12-16 13:27:33'),
(279, 276, 'normal', 720, 24, '2025-12-16 18:12:54', 0, '2026-01-20 12:21:24', 0, 0, '2025-12-19 15:29:31', 1, 0, 1493, 790403, '2025-12-16 17:48:01', '2026-01-20 12:21:24'),
(280, 277, 'normal', 720, 24, '2025-12-17 18:52:17', 0, '2026-01-21 19:06:34', 0, 810836, NULL, 0, 0, 29296, 29317, '2025-12-16 19:44:01', '2026-01-21 19:06:34'),
(281, 278, 'normal', 720, 24, '2025-12-17 18:53:01', 0, '2026-01-16 11:51:46', 0, 687506, NULL, 0, 0, 28381, 28400, '2025-12-16 20:08:02', '2026-01-16 11:51:46'),
(282, 279, 'normal', 720, 24, '2025-12-17 18:53:44', 0, '2026-02-03 12:12:23', 0, 1045081, NULL, 0, 0, 28424, 28462, '2025-12-16 20:30:03', '2026-02-03 12:12:23'),
(283, 280, 'normal', 720, 24, '2025-12-17 18:55:02', 0, '2025-12-17 19:15:13', 0, 1194, NULL, 0, 0, 28502, 28519, '2025-12-16 20:50:02', '2025-12-17 19:15:13'),
(284, 281, 'normal', 720, 24, '2025-12-17 18:56:05', 0, '2026-01-21 19:06:30', 0, 810612, NULL, 0, 0, 24604, 24617, '2025-12-17 12:06:01', '2026-01-21 19:06:30'),
(285, 282, 'normal', 720, 24, '2025-12-17 18:56:36', 0, '2025-12-17 19:26:06', 0, 1748, NULL, 0, 0, 15155, 15177, '2025-12-17 14:44:01', '2025-12-17 19:26:06'),
(286, 283, 'normal', 720, 24, '2025-12-17 18:57:16', 0, '2025-12-19 12:59:07', 0, 43291, NULL, 0, 0, 6795, 6815, '2025-12-17 17:04:01', '2025-12-19 12:59:07'),
(287, 284, 'normal', 720, 24, '2025-12-19 13:07:03', 0, '2026-02-03 12:11:36', 0, 991881, NULL, 0, 0, 30302, 39494, '2025-12-18 13:42:01', '2026-02-03 12:11:36'),
(288, 285, 'normal', 720, 24, '2025-12-19 12:55:02', 0, '2025-12-19 12:56:48', 0, 0, NULL, 0, 0, 4500, 4605, '2025-12-19 11:40:02', '2025-12-19 12:56:48'),
(289, 286, 'normal', 720, 24, '2025-12-19 13:07:47', 0, '2025-12-19 13:11:15', 0, 193, NULL, 0, 0, 2986, 3001, '2025-12-19 12:18:01', '2025-12-19 13:11:15'),
(290, 287, 'normal', 720, 24, '2025-12-19 13:08:19', 0, '2025-12-19 15:23:40', 0, 0, NULL, 0, 0, 1097, 9218, '2025-12-19 12:50:02', '2025-12-19 15:23:40'),
(291, 288, 'normal', 720, 24, '2025-12-19 15:31:11', 0, '2026-01-07 14:43:39', 0, 418342, NULL, 0, 0, 18, 24, '2025-12-19 15:30:53', '2026-01-07 14:43:39'),
(292, 288, 'normal', 480, 24, '2025-12-19 15:31:11', 0, '2026-01-07 14:43:39', 0, 418342, NULL, 0, 0, 18, 24, '2025-12-19 15:30:53', '2026-01-07 14:43:39'),
(293, 289, 'normal', 720, 24, '2025-12-19 15:39:14', 0, '2026-01-21 14:29:14', 0, 970, NULL, 0, 0, 313, 740343, '2025-12-19 15:34:01', '2026-01-21 14:29:14'),
(294, 290, 'normal', 720, 24, '2026-01-05 13:23:33', 0, '2026-01-05 13:30:46', 0, 0, NULL, 0, 0, 4411, 4844, '2026-01-05 12:10:02', '2026-01-05 13:30:46'),
(295, 291, 'urgente', 120, 4, '2026-01-05 13:23:21', 0, '2026-02-05 15:03:35', 0, 717913, NULL, 0, 0, 22, 923, '2026-01-05 13:22:59', '2026-02-05 15:03:35'),
(296, 291, 'normal', 480, 24, '2026-01-05 13:23:21', 0, '2026-02-05 15:03:35', 0, 717913, NULL, 0, 0, 22, 923, '2026-01-05 13:22:59', '2026-02-05 15:03:35'),
(297, 292, 'alta', 240, 8, '2026-01-05 13:37:13', 0, '2026-01-05 16:19:35', 0, 5, NULL, 0, 0, 18, 9755, '2026-01-05 13:36:55', '2026-01-05 16:19:35'),
(298, 292, 'normal', 480, 24, '2026-01-05 13:37:13', 0, '2026-01-05 16:19:35', 0, 5, NULL, 0, 0, 18, 9755, '2026-01-05 13:36:55', '2026-01-05 16:19:35'),
(299, 293, 'normal', 720, 24, '2026-01-05 16:52:59', 0, '2026-01-05 16:53:05', 0, 0, NULL, 0, 0, 7258, 7264, '2026-01-05 14:52:01', '2026-01-05 16:53:05'),
(300, 294, 'normal', 720, 24, '2026-01-05 20:09:50', 0, '2026-01-08 16:33:27', 0, 84807, NULL, 0, 0, 9359, 9359, '2026-01-05 17:24:01', '2026-01-08 16:33:27'),
(301, 295, 'normal', 720, 24, '2026-01-05 20:15:01', 0, NULL, 0, 0, '2026-01-05 20:47:50', 1, 0, 0, 0, '2026-01-05 20:10:02', '2026-01-05 20:47:50'),
(302, 296, 'normal', 720, 24, '2026-01-06 14:29:59', 0, '2026-01-08 16:30:49', 0, 72041, NULL, 0, 0, 21, 30, '2026-01-06 14:29:38', '2026-01-08 16:30:49'),
(303, 296, 'normal', 480, 24, '2026-01-06 14:29:59', 0, '2026-01-08 16:30:49', 0, 72041, NULL, 0, 0, 21, 30, '2026-01-06 14:29:38', '2026-01-08 16:30:49'),
(304, 297, 'normal', 720, 24, '2026-01-06 14:38:48', 0, '2026-01-06 14:38:54', 0, 0, NULL, 0, 0, 20, 26, '2026-01-06 14:38:28', '2026-01-06 14:38:54'),
(305, 297, 'normal', 480, 24, '2026-01-06 14:38:48', 0, '2026-01-06 14:38:54', 0, 0, NULL, 0, 0, 20, 26, '2026-01-06 14:38:28', '2026-01-06 14:38:54'),
(306, 298, 'normal', 720, 24, '2026-01-06 17:52:05', 0, '2026-01-07 14:27:51', 0, 791, NULL, 0, 0, 9364, 28719, '2026-01-06 15:16:01', '2026-01-07 14:27:51'),
(307, 299, 'normal', 720, 24, '2026-01-07 14:56:58', 0, '2026-01-09 14:04:13', 0, 61466, NULL, 0, 0, 1680, 1849, '2026-01-07 14:28:58', '2026-01-09 14:04:13'),
(308, 299, 'normal', 480, 24, '2026-01-07 14:56:58', 0, '2026-01-09 14:04:13', 0, 61466, NULL, 0, 0, 1680, 1849, '2026-01-07 14:28:58', '2026-01-09 14:04:13'),
(309, 300, 'normal', 720, 24, '2026-01-07 16:53:52', 0, '2026-01-07 17:46:09', 0, 3111, NULL, 0, 0, 38, 64, '2026-01-07 16:53:14', '2026-01-07 17:46:09'),
(310, 300, 'normal', 480, 24, '2026-01-07 16:53:52', 0, '2026-01-07 17:46:09', 0, 3111, NULL, 0, 0, 38, 64, '2026-01-07 16:53:14', '2026-01-07 17:46:09'),
(311, 301, 'normal', 720, 24, '2026-01-07 18:36:57', 0, '2026-01-07 18:37:32', 0, 35, NULL, 0, 0, 5336, 5336, '2026-01-07 17:08:01', '2026-01-07 18:37:32'),
(312, 302, 'normal', 720, 24, '2026-01-08 11:57:29', 0, '2026-01-08 12:20:41', 0, 1278, NULL, 0, 0, 9447, 9561, '2026-01-07 18:20:02', '2026-01-08 12:20:41'),
(313, 303, 'normal', 720, 24, '2026-01-08 11:58:03', 0, '2026-01-09 14:50:26', 0, 42652, NULL, 0, 0, 2402, 2493, '2026-01-08 11:18:01', '2026-01-09 14:50:26'),
(314, 304, 'normal', 720, 24, '2026-01-08 11:58:52', 0, '2026-01-09 12:07:27', 0, 2459, NULL, 0, 0, 2330, 32786, '2026-01-08 11:20:02', '2026-01-09 12:07:27'),
(315, 305, 'normal', 720, 24, '2026-01-08 12:19:08', 0, '2026-05-14 11:23:55', 0, 2783063, NULL, 0, 0, 33, 57, '2026-01-08 12:18:35', '2026-05-14 11:23:55'),
(316, 305, 'normal', 480, 24, '2026-01-08 12:19:08', 0, '2026-05-14 11:23:55', 0, 2783063, NULL, 0, 0, 33, 57, '2026-01-08 12:18:35', '2026-05-14 11:23:55'),
(317, 306, 'normal', 720, 24, '2026-01-08 15:52:26', 0, '2026-01-08 15:59:36', 0, 0, NULL, 0, 0, 1705, 2135, '2026-01-08 15:24:01', '2026-01-08 15:59:36'),
(318, 307, 'normal', 720, 24, '2026-01-09 14:16:01', 0, '2026-01-09 17:30:48', 0, 9152, NULL, 0, 0, 240, 2775, '2026-01-09 14:12:01', '2026-01-09 17:30:48'),
(319, 308, 'normal', 720, 24, '2026-01-09 14:33:49', 0, '2026-01-09 16:03:22', 0, 0, NULL, 0, 0, 1188, 6561, '2026-01-09 14:14:01', '2026-01-09 16:03:22'),
(320, 309, 'normal', 720, 24, '2026-01-09 14:51:37', 0, '2026-01-09 14:52:40', 0, 0, NULL, 0, 0, 2251, 2314, '2026-01-09 14:14:06', '2026-01-09 14:52:40'),
(321, 310, 'normal', 720, 24, '2026-01-13 11:49:44', 0, '2026-01-13 11:52:12', 0, 0, NULL, 0, 0, 35384, 35532, '2026-01-09 20:42:01', '2026-01-13 11:52:12'),
(322, 311, 'normal', 720, 24, '2026-01-14 14:42:06', 0, '2026-02-13 12:38:17', 0, 0, NULL, 0, 0, 1205, 674176, '2026-01-14 14:22:01', '2026-02-13 12:38:17'),
(323, 312, 'normal', 720, 24, '2026-01-14 14:48:06', 0, '2026-01-14 14:49:54', 0, 0, NULL, 0, 0, 14, 122, '2026-01-14 14:47:52', '2026-01-14 14:49:54'),
(324, 312, 'normal', 480, 24, '2026-01-14 14:48:06', 0, '2026-01-14 14:49:54', 0, 0, NULL, 0, 0, 14, 122, '2026-01-14 14:47:52', '2026-01-14 14:49:54'),
(325, 313, 'normal', 720, 24, '2026-01-14 17:34:26', 0, '2026-01-20 14:18:16', 0, 117518, NULL, 0, 0, 4464, 4776, '2026-01-14 16:20:02', '2026-01-20 14:18:16'),
(326, 314, 'normal', 720, 24, '2026-01-14 17:38:51', 0, '2026-01-23 17:06:41', 0, 212592, NULL, 0, 0, 1490, 13768, '2026-01-14 17:14:01', '2026-01-23 17:06:41'),
(327, 315, 'normal', 720, 24, '2026-01-15 12:17:21', 0, '2026-02-13 12:38:40', 0, 0, NULL, 0, 0, 680, 649959, '2026-01-15 12:06:01', '2026-02-13 12:38:40'),
(328, 316, 'normal', 720, 24, '2026-01-15 13:05:33', 0, '2026-01-15 13:19:22', 0, 0, NULL, 0, 0, 35, 864, '2026-01-15 13:04:58', '2026-01-15 13:19:22'),
(329, 316, 'normal', 480, 24, '2026-01-15 13:05:33', 0, '2026-01-15 13:19:22', 0, 0, NULL, 0, 0, 35, 864, '2026-01-15 13:04:58', '2026-01-15 13:19:22'),
(330, 317, 'normal', 720, 24, '2026-01-15 16:22:15', 0, '2026-02-03 12:25:14', 0, 0, '2026-01-15 16:24:32', 1, 0, 854, 375433, '2026-01-15 16:08:01', '2026-02-03 12:25:14'),
(331, 318, 'normal', 720, 24, '2026-01-16 11:47:57', 0, '2026-01-16 11:50:18', 0, 0, NULL, 0, 0, 4195, 4336, '2026-01-15 19:38:02', '2026-01-16 11:50:18'),
(332, 319, 'normal', 720, 24, '2026-01-16 12:35:53', 0, '2026-01-16 13:33:14', 0, 0, NULL, 0, 0, 349, 3790, '2026-01-16 12:30:04', '2026-01-16 13:33:14'),
(333, 320, 'normal', 720, 24, '2026-01-16 13:37:36', 0, '2026-01-16 14:02:28', 0, 0, NULL, 0, 0, 215, 1707, '2026-01-16 13:34:01', '2026-01-16 14:02:28'),
(334, 321, 'normal', 720, 24, '2026-01-16 16:17:37', 0, '2026-01-23 16:45:10', 0, 162063, NULL, 0, 0, 336, 1926, '2026-01-16 16:12:01', '2026-01-23 16:45:10'),
(335, 322, 'normal', 720, 24, '2026-01-19 12:51:20', 0, '2026-01-26 14:27:48', 0, 162049, NULL, 0, 0, 19878, 25617, '2026-01-16 16:20:02', '2026-01-26 14:27:48'),
(336, 323, 'normal', 720, 24, '2026-01-19 12:52:43', 0, NULL, 0, 0, NULL, 0, 0, 6763, 0, '2026-01-19 06:32:01', '2026-01-19 12:52:43'),
(337, 324, 'normal', 720, 24, '2026-01-19 12:31:37', 0, NULL, 0, 0, NULL, 0, 0, 5497, 0, '2026-01-19 06:34:01', '2026-01-19 12:31:37'),
(338, 325, 'normal', 720, 24, '2026-01-19 12:31:33', 0, NULL, 0, 0, NULL, 0, 0, 5493, 0, '2026-01-19 06:36:01', '2026-01-19 12:31:33'),
(339, 326, 'normal', 720, 24, '2026-01-19 12:31:30', 0, NULL, 0, 0, NULL, 0, 0, 5490, 0, '2026-01-19 06:38:01', '2026-01-19 12:31:30'),
(340, 327, 'normal', 720, 24, '2026-01-19 12:31:26', 0, NULL, 0, 0, NULL, 0, 0, 5486, 0, '2026-01-19 06:40:02', '2026-01-19 12:31:26'),
(341, 328, 'normal', 720, 24, '2026-01-19 12:31:22', 0, NULL, 0, 0, NULL, 0, 0, 5482, 0, '2026-01-19 06:42:01', '2026-01-19 12:31:22'),
(342, 329, 'normal', 720, 24, '2026-01-19 12:31:19', 0, NULL, 0, 0, NULL, 0, 0, 5479, 0, '2026-01-19 06:44:01', '2026-01-19 12:31:19'),
(343, 330, 'normal', 720, 24, '2026-01-19 12:31:15', 0, NULL, 0, 0, NULL, 0, 0, 5475, 0, '2026-01-19 06:46:01', '2026-01-19 12:31:15'),
(344, 331, 'normal', 720, 24, '2026-01-19 12:31:10', 0, NULL, 0, 0, NULL, 0, 0, 5470, 0, '2026-01-19 06:48:01', '2026-01-19 12:31:10'),
(345, 332, 'normal', 720, 24, '2026-01-20 11:17:20', 0, NULL, 0, 0, NULL, 0, 0, 1040, 0, '2026-01-20 06:32:01', '2026-01-20 11:17:20'),
(346, 333, 'normal', 720, 24, '2026-01-20 11:17:44', 0, '2026-01-21 12:25:05', 0, 36425, NULL, 0, 0, 1064, 1080, '2026-01-20 06:32:02', '2026-01-21 12:25:05'),
(347, 334, 'normal', 720, 24, '2026-01-20 11:18:21', 0, '2026-01-20 12:37:51', 0, 4754, NULL, 0, 0, 1101, 1117, '2026-01-20 06:32:03', '2026-01-20 12:37:51'),
(348, 335, 'normal', 720, 24, '2026-01-21 14:04:17', 0, '2026-01-21 14:07:14', 0, 0, NULL, 0, 0, 7096, 7273, '2026-01-21 12:06:01', '2026-01-21 14:07:14'),
(349, 336, 'normal', 720, 24, '2026-01-21 14:09:33', 0, '2026-05-14 11:23:14', 0, 2484814, NULL, 0, 0, 19, 26, '2026-01-21 14:09:14', '2026-05-14 11:23:14'),
(350, 336, 'normal', 480, 24, '2026-01-21 14:09:33', 0, '2026-05-14 11:23:14', 0, 2484814, NULL, 0, 0, 19, 26, '2026-01-21 14:09:14', '2026-05-14 11:23:14'),
(351, 337, 'normal', 720, 24, '2026-01-21 14:35:34', 0, '2026-05-08 18:38:59', 0, 2379797, NULL, 0, 0, 23, 31, '2026-01-21 14:35:11', '2026-05-08 18:38:59'),
(352, 337, 'normal', 480, 24, '2026-01-21 14:35:34', 0, '2026-05-08 18:38:59', 0, 2379797, NULL, 0, 0, 23, 31, '2026-01-21 14:35:11', '2026-05-08 18:38:59'),
(353, 338, 'normal', 720, 24, '2026-01-21 15:33:54', 0, '2026-01-21 15:42:09', 0, 487, NULL, 0, 0, 23, 31, '2026-01-21 15:33:31', '2026-01-21 15:42:09'),
(354, 338, 'normal', 480, 24, '2026-01-21 15:33:54', 0, '2026-01-21 15:42:09', 0, 487, NULL, 0, 0, 23, 31, '2026-01-21 15:33:31', '2026-01-21 15:42:09'),
(355, 339, 'alta', 240, 8, '2026-01-21 16:00:32', 0, '2026-05-07 15:11:16', 0, 2310891, NULL, 0, 0, 55, 19008, '2026-01-21 15:59:37', '2026-05-07 15:11:16'),
(356, 339, 'normal', 480, 24, '2026-01-21 16:00:32', 0, '2026-05-07 15:11:16', 0, 2310891, NULL, 0, 0, 55, 19008, '2026-01-21 15:59:37', '2026-05-07 15:11:16'),
(357, 340, 'normal', 720, 24, '2026-01-22 12:59:49', 0, '2026-01-26 12:47:04', 0, 64025, NULL, 0, 0, 156, 166, '2026-01-22 12:57:13', '2026-01-26 12:47:04'),
(358, 340, 'normal', 480, 24, '2026-01-22 12:59:49', 0, '2026-01-26 12:47:04', 0, 64025, NULL, 0, 0, 156, 166, '2026-01-22 12:57:13', '2026-01-26 12:47:04');
INSERT INTO `ticket_sla` (`id`, `ticket_id`, `prioridade`, `sla_resposta_minutos`, `sla_resolucao_horas`, `primeira_resposta_em`, `sla_resposta_vencido`, `resolvido_em`, `sla_resolucao_vencido`, `tempo_pausado_segundos`, `pausado_em`, `pausado`, `tempo_total_segundos`, `tempo_primeira_resposta_segundos`, `tempo_resolucao_segundos`, `criado_em`, `atualizado_em`) VALUES
(359, 341, 'normal', 720, 24, '2026-01-22 13:00:13', 0, '2026-01-22 17:10:53', 0, 15034, NULL, 0, 0, 78, 84, '2026-01-22 12:58:55', '2026-01-22 17:10:53'),
(360, 341, 'normal', 480, 24, '2026-01-22 13:00:13', 0, '2026-01-22 17:10:53', 0, 15034, NULL, 0, 0, 78, 84, '2026-01-22 12:58:55', '2026-01-22 17:10:53'),
(361, 342, 'normal', 720, 24, '2026-01-22 18:01:34', 0, '2026-01-22 18:01:49', 0, 0, NULL, 0, 0, 2492, 2507, '2026-01-22 17:20:02', '2026-01-22 18:01:49'),
(362, 343, 'normal', 720, 24, '2026-01-23 11:49:57', 0, '2026-01-27 13:50:16', 0, 71080, NULL, 0, 0, 10076, 11015, '2026-01-22 18:02:01', '2026-01-27 13:50:16'),
(363, 344, 'normal', 720, 24, '2026-01-23 11:50:31', 0, '2026-01-23 13:20:17', 0, 526, NULL, 0, 0, 1227, 6087, '2026-01-23 11:30:04', '2026-01-23 13:20:17'),
(364, 345, 'normal', 720, 24, '2026-01-23 13:10:54', 0, '2026-01-23 13:25:26', 0, 831, NULL, 0, 0, 2693, 2734, '2026-01-23 12:26:01', '2026-01-23 13:25:26'),
(365, 346, 'normal', 720, 24, '2026-01-23 13:20:02', 0, '2026-01-23 15:08:39', 0, 0, NULL, 0, 0, 24, 6541, '2026-01-23 13:19:38', '2026-01-23 15:08:39'),
(366, 346, 'normal', 480, 24, '2026-01-23 13:20:02', 0, '2026-01-23 15:08:39', 0, 0, NULL, 0, 0, 24, 6541, '2026-01-23 13:19:38', '2026-01-23 15:08:39'),
(367, 347, 'normal', 720, 24, '2026-01-23 20:01:31', 0, '2026-01-23 20:06:21', 0, 0, NULL, 0, 0, 15839, 15839, '2026-01-23 15:36:01', '2026-01-23 20:06:21'),
(368, 348, 'normal', 720, 24, '2026-01-23 16:42:29', 0, '2026-02-19 13:08:38', 0, 0, '2026-01-23 17:04:45', 1, 0, 988, 474157, '2026-01-23 16:26:01', '2026-02-19 13:08:38'),
(369, 349, 'normal', 720, 24, '2026-01-23 20:07:22', 0, '2026-01-23 20:14:26', 0, 0, NULL, 0, 0, 1319, 1319, '2026-01-23 19:38:01', '2026-01-23 20:14:26'),
(370, 350, 'normal', 720, 24, '2026-01-23 20:07:03', 0, NULL, 0, 0, NULL, 0, 0, 1314, 0, '2026-01-23 19:38:06', '2026-01-23 20:07:03'),
(371, 351, 'normal', 720, 24, '2026-01-26 11:20:05', 0, '2026-01-26 17:12:49', 0, 21138, NULL, 0, 0, 124, 150, '2026-01-26 11:18:01', '2026-01-26 17:12:49'),
(372, 352, 'alta', 240, 8, '2026-01-26 11:19:06', 0, '2026-01-26 12:09:08', 0, 2990, NULL, 0, 0, 15, 27, '2026-01-26 11:18:51', '2026-01-26 12:09:08'),
(373, 352, 'normal', 480, 24, '2026-01-26 11:19:06', 0, '2026-01-26 12:09:08', 0, 2990, NULL, 0, 0, 15, 27, '2026-01-26 11:18:51', '2026-01-26 12:09:08'),
(374, 353, 'normal', 720, 24, '2026-01-26 12:04:20', 0, '2026-02-19 13:07:22', 0, 457368, NULL, 0, 0, 255, 269, '2026-01-26 12:00:05', '2026-02-19 13:07:22'),
(375, 354, 'normal', 720, 24, '2026-01-26 12:51:07', 0, '2026-01-28 16:30:49', 0, 77725, NULL, 0, 0, 1506, 1763, '2026-01-26 12:26:01', '2026-01-28 16:30:49'),
(376, 355, 'normal', 720, 24, '2026-01-26 13:13:52', 0, '2026-01-26 14:11:24', 0, 3338, NULL, 0, 0, 351, 465, '2026-01-26 13:08:01', '2026-01-26 14:11:24'),
(377, 356, 'normal', 720, 24, '2026-01-26 17:28:57', 0, '2026-02-19 13:01:59', 0, 0, '2026-01-26 17:29:29', 1, 0, 5576, 443158, '2026-01-26 15:56:01', '2026-02-19 13:01:59'),
(378, 357, 'normal', 720, 24, '2026-01-27 16:52:03', 0, '2026-01-28 14:53:01', 0, 24927, NULL, 0, 0, 25442, 25773, '2026-01-26 18:48:01', '2026-01-28 14:53:01'),
(379, 358, 'normal', 720, 24, '2026-01-27 12:33:28', 0, '2026-01-27 15:18:25', 0, 5662, NULL, 0, 0, 8127, 12362, '2026-01-26 19:18:01', '2026-01-27 15:18:25'),
(380, 359, 'normal', 720, 24, '2026-01-27 12:10:36', 0, '2026-01-29 13:49:10', 0, 24135, NULL, 0, 0, 515, 47094, '2026-01-27 12:02:01', '2026-01-29 13:49:10'),
(381, 360, 'normal', 720, 24, '2026-01-28 12:00:42', 0, '2026-01-28 13:14:11', 0, 0, NULL, 0, 0, 9039, 13448, '2026-01-27 18:30:03', '2026-01-28 13:14:11'),
(382, 361, 'normal', 720, 24, '2026-01-28 16:01:03', 0, '2026-01-28 16:40:26', 0, 0, NULL, 0, 0, 1742, 4105, '2026-01-28 15:32:01', '2026-01-28 16:40:26'),
(383, 362, 'normal', 720, 24, '2026-01-29 12:34:56', 0, '2026-01-29 12:36:26', 0, 0, NULL, 0, 0, 15415, 15505, '2026-01-28 17:18:01', '2026-01-29 12:36:26'),
(384, 363, 'normal', 720, 24, '2026-01-29 12:04:30', 0, '2026-01-29 12:19:56', 0, 0, NULL, 0, 0, 11065, 11991, '2026-01-28 18:00:05', '2026-01-29 12:19:56'),
(385, 364, 'normal', 720, 24, '2026-01-29 12:21:00', 0, '2026-01-29 12:26:38', 0, 0, NULL, 0, 0, 3056, 3394, '2026-01-29 11:30:04', '2026-01-29 12:26:38'),
(386, 365, 'normal', 720, 24, '2026-01-29 12:26:39', 0, '2026-01-29 12:30:42', 0, 0, NULL, 0, 0, 758, 1001, '2026-01-29 12:14:01', '2026-01-29 12:30:42'),
(387, 366, 'normal', 720, 24, '2026-01-29 12:32:29', 0, NULL, 0, 0, '2026-01-29 12:34:26', 1, 0, 1103, 0, '2026-01-29 12:14:06', '2026-01-29 12:34:26'),
(388, 367, 'normal', 720, 24, '2026-01-29 14:38:55', 0, '2026-02-23 12:39:45', 0, 362385, NULL, 0, 0, 531, 52196, '2026-01-29 14:30:04', '2026-02-23 12:39:45'),
(389, 368, 'normal', 720, 24, '2026-01-29 14:38:39', 0, '2026-01-29 14:44:35', 0, 0, NULL, 0, 0, 158, 514, '2026-01-29 14:36:01', '2026-01-29 14:44:35'),
(390, 369, 'normal', 720, 24, '2026-01-29 20:49:45', 0, '2026-01-29 21:04:59', 0, 0, NULL, 0, 0, 0, 0, '2026-01-29 20:40:44', '2026-01-29 21:04:59'),
(391, 369, 'normal', 480, 24, '2026-01-29 20:49:45', 0, '2026-01-29 21:04:59', 0, 0, NULL, 0, 0, 0, 0, '2026-01-29 20:40:44', '2026-01-29 21:04:59'),
(392, 370, 'normal', 720, 24, '2026-01-29 20:50:18', 0, '2026-01-30 12:48:14', 0, 6494, NULL, 0, 0, 0, 0, '2026-01-29 20:48:13', '2026-01-30 12:48:14'),
(393, 370, 'normal', 480, 24, '2026-01-29 20:50:18', 0, '2026-01-30 12:48:14', 0, 6494, NULL, 0, 0, 0, 0, '2026-01-29 20:48:13', '2026-01-30 12:48:14'),
(394, 371, 'normal', 720, 24, '2026-01-30 13:17:51', 0, '2026-02-05 15:01:46', 0, 102782, NULL, 0, 0, 230, 883, '2026-01-30 13:14:01', '2026-02-05 15:01:46'),
(395, 372, 'normal', 720, 24, '2026-01-30 15:33:44', 0, '2026-02-04 13:29:19', 0, 45881, NULL, 0, 0, 583, 12037, '2026-01-30 15:24:01', '2026-02-04 13:29:19'),
(396, 373, 'normal', 720, 24, '2026-01-30 20:14:49', 0, '2026-03-11 17:03:30', 0, 767098, NULL, 0, 0, 15359, 15271, '2026-01-30 15:44:01', '2026-03-11 17:03:30'),
(397, 374, 'normal', 720, 24, '2026-01-30 20:10:00', 0, '2026-02-19 12:58:17', 0, 0, '2026-01-30 20:11:40', 1, 0, 0, 298697, '2026-01-30 20:04:01', '2026-02-19 12:58:17'),
(398, 375, 'normal', 720, 24, '2026-02-03 11:30:24', 0, '2026-02-05 14:55:42', 0, 77073, NULL, 0, 0, 1824, 1869, '2026-02-02 17:56:01', '2026-02-05 14:55:42'),
(399, 376, 'normal', 720, 24, '2026-02-03 18:56:13', 0, '2026-04-02 14:48:50', 0, 0, '2026-02-03 18:57:45', 1, 0, 21612, 1270369, '2026-02-03 12:56:01', '2026-04-02 14:48:50'),
(400, 377, 'normal', 720, 24, '2026-02-03 18:54:28', 0, '2026-02-03 18:55:41', 0, 0, NULL, 0, 0, 12507, 12580, '2026-02-03 15:26:01', '2026-02-03 18:55:41'),
(401, 378, 'normal', 720, 24, '2026-02-04 15:37:38', 0, '2026-03-11 18:54:45', 0, 724608, NULL, 0, 0, 11497, 11516, '2026-02-04 12:26:01', '2026-03-11 18:54:45'),
(402, 379, 'urgente', 120, 4, '2026-02-04 15:38:13', 0, '2026-02-04 16:39:07', 0, 0, NULL, 0, 0, 6916, 10570, '2026-02-04 13:42:57', '2026-02-04 16:39:07'),
(403, 379, 'normal', 480, 24, '2026-02-04 15:38:13', 0, '2026-02-04 16:39:07', 0, 0, NULL, 0, 0, 6916, 10570, '2026-02-04 13:42:57', '2026-02-04 16:39:07'),
(404, 380, 'normal', 720, 24, '2026-02-04 15:39:02', 0, '2026-03-11 17:00:00', 0, 717470, NULL, 0, 0, 4261, 4449, '2026-02-04 14:28:01', '2026-03-11 17:00:00'),
(405, 381, 'normal', 720, 24, '2026-02-04 15:21:38', 0, NULL, 0, 0, '2026-03-25 14:03:57', 1, 0, 2617, 0, '2026-02-04 14:38:01', '2026-03-25 14:03:57'),
(406, 382, 'normal', 720, 24, '2026-02-04 15:33:45', 0, '2026-02-05 14:48:07', 0, 29457, NULL, 0, 0, 944, 1149, '2026-02-04 15:18:01', '2026-02-05 14:48:07'),
(407, 383, 'alta', 240, 8, '2026-02-04 16:22:44', 0, '2026-02-04 17:43:27', 0, 4833, NULL, 0, 0, 25, 35, '2026-02-04 16:22:19', '2026-02-04 17:43:27'),
(408, 383, 'normal', 480, 24, '2026-02-04 16:22:44', 0, '2026-02-04 17:43:27', 0, 4833, NULL, 0, 0, 25, 35, '2026-02-04 16:22:19', '2026-02-04 17:43:27'),
(409, 384, 'normal', 720, 24, '2026-02-04 18:05:56', 0, '2026-02-04 18:14:20', 0, 0, NULL, 0, 0, 2152, 2656, '2026-02-04 17:30:04', '2026-02-04 18:14:20'),
(410, 385, 'normal', 720, 24, '2026-02-04 17:34:58', 0, '2026-02-05 14:47:40', 0, 22157, NULL, 0, 0, 177, 382, '2026-02-04 17:32:01', '2026-02-05 14:47:40'),
(411, 386, 'normal', 720, 24, '2026-02-05 13:39:33', 0, '2026-02-05 14:46:42', 0, 4016, NULL, 0, 0, 1652, 1665, '2026-02-05 13:12:01', '2026-02-05 14:46:42'),
(412, 387, 'normal', 720, 24, '2026-02-05 15:16:26', 0, '2026-02-05 16:50:49', 0, 5653, NULL, 0, 0, 594, 604, '2026-02-05 15:06:32', '2026-02-05 16:50:49'),
(413, 387, 'normal', 480, 24, '2026-02-05 15:16:26', 0, '2026-02-05 16:50:49', 0, 5653, NULL, 0, 0, 594, 604, '2026-02-05 15:06:32', '2026-02-05 16:50:49'),
(414, 388, 'normal', 720, 24, '2026-02-05 15:16:54', 0, '2026-02-06 15:35:01', 0, 11472, NULL, 0, 0, 328, 22343, '2026-02-05 15:11:26', '2026-02-06 15:35:01'),
(415, 388, 'normal', 480, 24, '2026-02-05 15:16:54', 0, '2026-02-06 15:35:01', 0, 11472, NULL, 0, 0, 328, 22343, '2026-02-05 15:11:26', '2026-02-06 15:35:01'),
(416, 389, 'normal', 720, 24, '2026-02-05 15:17:19', 0, '2026-02-11 19:39:03', 0, 145161, NULL, 0, 0, 79, 222, '2026-02-05 15:16:00', '2026-02-11 19:39:03'),
(417, 389, 'normal', 480, 24, '2026-02-05 15:17:19', 0, '2026-02-11 19:39:03', 0, 145161, NULL, 0, 0, 79, 222, '2026-02-05 15:16:00', '2026-02-11 19:39:03'),
(418, 390, 'normal', 720, 24, '2026-02-05 15:19:29', 0, '2026-02-20 18:14:54', 0, 262611, NULL, 0, 0, 21, 7135, '2026-02-05 15:19:08', '2026-02-20 18:14:54'),
(419, 390, 'normal', 480, 24, '2026-02-05 15:19:29', 0, '2026-02-20 18:14:54', 0, 262611, NULL, 0, 0, 21, 7135, '2026-02-05 15:19:08', '2026-02-20 18:14:54'),
(420, 391, 'normal', 720, 24, '2026-02-06 13:02:10', 0, '2026-02-06 17:20:31', 0, 15494, NULL, 0, 0, 439, 446, '2026-02-06 12:54:51', '2026-02-06 17:20:31'),
(421, 391, 'normal', 480, 24, '2026-02-06 13:02:10', 0, '2026-02-06 17:20:31', 0, 15494, NULL, 0, 0, 439, 446, '2026-02-06 12:54:51', '2026-02-06 17:20:31'),
(422, 392, 'normal', 720, 24, '2026-02-06 13:03:51', 0, NULL, 0, 0, NULL, 0, 0, 350, 0, '2026-02-06 12:58:01', '2026-02-06 13:03:51'),
(423, 393, 'normal', 720, 24, '2026-02-06 13:22:45', 0, '2026-02-06 13:35:33', 0, 0, NULL, 0, 0, 403, 1171, '2026-02-06 13:16:02', '2026-02-06 13:35:33'),
(424, 394, 'normal', 720, 24, '2026-02-06 14:24:02', 0, '2026-03-11 18:59:55', 0, 664521, NULL, 0, 0, 1175, 1207, '2026-02-06 14:04:27', '2026-03-11 18:59:55'),
(425, 394, 'normal', 480, 24, '2026-02-06 14:24:02', 0, '2026-03-11 18:59:55', 0, 664521, NULL, 0, 0, 1175, 1207, '2026-02-06 14:04:27', '2026-03-11 18:59:55'),
(426, 395, 'normal', 720, 24, '2026-02-06 14:14:00', 0, NULL, 0, 0, '2026-02-06 14:15:23', 1, 0, 30, 0, '2026-02-06 14:13:30', '2026-02-06 14:15:23'),
(427, 395, 'normal', 480, 24, '2026-02-06 14:14:00', 0, NULL, 0, 0, '2026-02-06 14:15:23', 1, 0, 30, 0, '2026-02-06 14:13:30', '2026-02-06 14:15:23'),
(428, 396, 'normal', 720, 24, '2026-02-09 11:24:30', 0, NULL, 0, 0, NULL, 0, 0, 12989, 0, '2026-02-06 16:48:01', '2026-02-09 11:24:30'),
(429, 397, 'normal', 720, 24, '2026-02-09 17:41:46', 0, '2026-02-13 12:44:59', 0, 111633, NULL, 0, 0, 4540, 4700, '2026-02-09 16:26:06', '2026-02-13 12:44:59'),
(430, 398, 'normal', 720, 24, '2026-02-09 17:51:35', 0, '2026-03-19 14:00:18', 0, 795548, NULL, 0, 0, 334, 909, '2026-02-09 17:46:01', '2026-03-19 14:00:18'),
(431, 399, 'normal', 720, 24, '2026-02-09 19:37:17', 0, '2026-02-10 12:44:15', 0, 7608, NULL, 0, 0, 15, 25, '2026-02-09 19:37:02', '2026-02-10 12:44:15'),
(432, 399, 'normal', 480, 24, '2026-02-09 19:37:17', 0, '2026-02-10 12:44:15', 0, 7608, NULL, 0, 0, 15, 25, '2026-02-09 19:37:02', '2026-02-10 12:44:15'),
(433, 400, 'normal', 720, 24, '2026-02-11 15:12:00', 0, '2026-02-11 15:23:36', 0, 0, NULL, 0, 0, 839, 1535, '2026-02-11 14:58:01', '2026-02-11 15:23:36'),
(434, 401, 'normal', 720, 24, '2026-02-12 18:00:04', 0, '2026-02-13 17:19:41', 0, 29958, NULL, 0, 0, 36123, 36142, '2026-02-11 16:58:01', '2026-02-13 17:19:41'),
(435, 402, 'normal', 720, 24, '2026-02-12 18:00:39', 0, '2026-02-19 13:15:34', 0, 43408, NULL, 0, 0, 29437, 33724, '2026-02-11 18:50:02', '2026-02-19 13:15:34'),
(436, 403, 'normal', 720, 24, '2026-02-12 18:01:09', 0, '2026-02-20 17:45:23', 0, 0, '2026-02-12 18:09:23', 1, 0, 26948, 123202, '2026-02-11 19:32:01', '2026-02-20 17:45:23'),
(437, 404, 'normal', 720, 24, '2026-02-12 15:11:44', 0, '2026-02-12 16:02:15', 0, 0, NULL, 0, 0, 1543, 4574, '2026-02-12 14:46:01', '2026-02-12 16:02:15'),
(438, 405, 'normal', 720, 24, '2026-02-13 12:15:06', 0, '2026-02-13 12:20:16', 0, 0, NULL, 0, 0, 4745, 5055, '2026-02-12 19:56:01', '2026-02-13 12:20:16'),
(439, 406, 'normal', 720, 24, '2026-02-13 12:49:01', 0, NULL, 0, 0, NULL, 0, 0, 1259, 0, '2026-02-13 12:28:02', '2026-02-13 12:49:01'),
(440, 407, 'normal', 720, 24, '2026-02-13 12:49:27', 0, NULL, 0, 0, NULL, 0, 0, 1280, 0, '2026-02-13 12:28:07', '2026-02-13 12:49:27'),
(441, 408, 'normal', 720, 24, '2026-02-13 12:49:48', 0, NULL, 0, 0, NULL, 0, 0, 1183, 0, '2026-02-13 12:30:05', '2026-02-13 12:49:48'),
(442, 409, 'normal', 720, 24, '2026-02-13 12:49:56', 0, NULL, 0, 0, NULL, 0, 0, 1185, 0, '2026-02-13 12:30:11', '2026-02-13 12:49:56'),
(443, 410, 'normal', 720, 24, '2026-02-13 20:10:47', 0, '2026-02-13 20:12:36', 0, 0, NULL, 0, 0, 4198, 4198, '2026-02-13 18:50:02', '2026-02-13 20:12:36'),
(444, 411, 'normal', 720, 24, '2026-02-19 13:06:17', 0, '2026-02-19 14:17:06', 0, 0, NULL, 0, 0, 10336, 14585, '2026-02-13 19:14:01', '2026-02-19 14:17:06'),
(445, 412, 'normal', 720, 24, '2026-02-13 20:14:04', 0, '2026-02-20 12:30:29', 0, 0, NULL, 0, 0, 0, 37829, '2026-02-13 20:02:01', '2026-02-20 12:30:29'),
(446, 413, 'normal', 720, 24, '2026-02-13 20:13:50', 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-02-13 20:08:01', '2026-02-13 20:13:50'),
(447, 414, 'normal', 720, 24, '2026-02-19 13:11:14', 0, '2026-02-19 13:22:57', 0, 0, NULL, 0, 0, 7874, 8577, '2026-02-13 20:22:01', '2026-02-19 13:22:57'),
(448, 415, 'normal', 720, 24, '2026-02-19 13:11:53', 0, '2026-03-05 14:55:31', 0, 300972, NULL, 0, 0, 7913, 37159, '2026-02-13 20:26:01', '2026-03-05 14:55:31'),
(449, 416, 'normal', 720, 24, '2026-02-19 13:12:13', 0, '2026-03-05 14:54:38', 0, 300903, NULL, 0, 0, 7933, 37175, '2026-02-16 16:48:01', '2026-03-05 14:54:38'),
(450, 417, 'normal', 720, 24, '2026-02-19 13:12:21', 0, '2026-03-20 13:24:29', 0, 624482, NULL, 0, 0, 4700, 61346, '2026-02-19 11:54:01', '2026-03-20 13:24:29'),
(451, 418, 'normal', 720, 24, '2026-02-19 12:54:43', 0, '2026-02-19 12:55:00', 0, 0, NULL, 0, 0, 2322, 2339, '2026-02-19 12:16:01', '2026-02-19 12:55:00'),
(452, 419, 'normal', 720, 24, '2026-02-19 13:03:41', 0, NULL, 0, 0, NULL, 0, 0, 198, 0, '2026-02-19 13:00:23', '2026-02-19 13:03:41'),
(453, 419, 'normal', 480, 24, '2026-02-19 13:03:41', 0, NULL, 0, 0, NULL, 0, 0, 198, 0, '2026-02-19 13:00:23', '2026-02-19 13:03:41'),
(454, 420, 'normal', 720, 24, '2026-02-20 12:40:42', 0, '2026-02-20 13:03:30', 0, 0, NULL, 0, 0, 11321, 12689, '2026-02-19 18:32:01', '2026-02-20 13:03:30'),
(455, 421, 'normal', 720, 24, '2026-02-20 12:25:26', 0, '2026-02-20 14:01:46', 0, 0, NULL, 0, 0, 8721, 14501, '2026-02-19 19:00:05', '2026-02-20 14:01:46'),
(456, 422, 'normal', 720, 24, '2026-02-20 18:19:46', 0, '2026-02-23 12:32:41', 0, 7692, NULL, 0, 0, 19545, 23428, '2026-02-20 12:54:01', '2026-02-23 12:32:41'),
(457, 423, 'normal', 720, 24, '2026-02-20 18:18:29', 0, '2026-05-22 19:09:04', 0, 2105086, NULL, 0, 0, 19103, 23052, '2026-02-20 13:00:06', '2026-05-22 19:09:04'),
(458, 424, 'normal', 720, 24, '2026-02-20 18:18:40', 0, '2026-02-24 12:45:41', 0, 0, NULL, 0, 0, 5199, 50020, '2026-02-20 16:52:01', '2026-02-24 12:45:41'),
(459, 425, 'normal', 720, 24, '2026-02-23 12:21:11', 0, '2026-02-23 12:21:49', 0, 0, NULL, 0, 0, 4990, 5028, '2026-02-20 19:58:01', '2026-02-23 12:21:49'),
(460, 426, 'normal', 720, 24, '2026-02-23 14:05:52', 0, '2026-02-23 14:15:37', 0, 0, NULL, 0, 0, 1071, 1656, '2026-02-23 13:48:01', '2026-02-23 14:15:37'),
(461, 427, 'normal', 720, 24, '2026-02-25 12:14:24', 0, '2026-04-15 16:13:35', 0, 0, '2026-02-25 12:38:18', 1, 0, 54743, 1203094, '2026-02-23 15:02:01', '2026-04-15 16:13:35'),
(462, 428, 'normal', 720, 24, '2026-02-23 15:50:46', 0, '2026-02-25 12:23:03', 0, 51350, NULL, 0, 0, 1484, 2471, '2026-02-23 15:26:02', '2026-02-25 12:23:03'),
(463, 429, 'normal', 720, 24, '2026-02-24 12:18:01', 0, '2026-02-25 12:22:51', 0, 30553, NULL, 0, 0, 10800, 12937, '2026-02-23 18:18:01', '2026-02-25 12:22:51'),
(464, 430, 'normal', 720, 24, '2026-02-24 12:55:02', 0, '2026-02-24 13:45:36', 0, 2279, NULL, 0, 0, 6902, 7657, '2026-02-23 22:00:05', '2026-02-24 13:45:36'),
(465, 431, 'normal', 720, 24, '2026-02-24 13:07:37', 0, '2026-03-09 17:38:46', 0, 307611, NULL, 0, 0, 6816, 7074, '2026-02-24 11:14:01', '2026-03-09 17:38:46'),
(466, 432, 'normal', 720, 24, '2026-02-24 13:12:15', 0, '2026-02-24 13:14:50', 0, 0, NULL, 0, 0, 4329, 4484, '2026-02-24 12:00:06', '2026-02-24 13:14:50'),
(467, 433, 'normal', 720, 24, '2026-02-24 12:49:04', 0, '2026-03-11 17:00:58', 0, 305698, NULL, 0, 0, 1863, 67679, '2026-02-24 12:18:01', '2026-03-11 17:00:58'),
(468, 434, 'normal', 720, 24, '2026-02-24 12:47:32', 0, NULL, 0, 0, NULL, 0, 0, 1650, 0, '2026-02-24 12:20:02', '2026-02-24 12:47:32'),
(469, 435, 'normal', 720, 24, '2026-02-24 13:06:38', 0, NULL, 0, 0, NULL, 0, 0, 22, 0, '2026-02-24 13:06:16', '2026-02-24 13:06:38'),
(470, 435, 'normal', 480, 24, '2026-02-24 13:06:38', 0, NULL, 0, 0, NULL, 0, 0, 22, 0, '2026-02-24 13:06:16', '2026-02-24 13:06:38'),
(471, 436, 'normal', 720, 24, '2026-02-26 15:42:04', 0, '2026-02-26 15:42:17', 0, 0, NULL, 0, 0, 60843, 60856, '2026-02-24 16:48:01', '2026-02-26 15:42:17'),
(472, 437, 'normal', 720, 24, '2026-02-25 12:23:19', 0, '2026-02-25 13:06:38', 0, 2064, NULL, 0, 0, 12078, 12613, '2026-02-24 18:02:01', '2026-02-25 13:06:38'),
(473, 438, 'normal', 720, 24, '2026-02-25 12:32:39', 0, '2026-02-25 13:07:03', 0, 1815, NULL, 0, 0, 5559, 5808, '2026-02-24 20:22:01', '2026-02-25 13:07:03'),
(474, 439, 'normal', 720, 24, '2026-02-25 12:37:51', 0, '2026-02-25 14:28:56', 0, 5391, NULL, 0, 0, 5871, 7145, '2026-02-24 20:24:02', '2026-02-25 14:28:56'),
(475, 440, 'normal', 720, 24, '2026-02-25 12:50:15', 0, '2026-03-05 14:49:17', 0, 201018, NULL, 0, 0, 99, 623, '2026-02-25 12:48:36', '2026-03-05 14:49:17'),
(476, 440, 'normal', 480, 24, '2026-02-25 12:50:15', 0, '2026-03-05 14:49:17', 0, 201018, NULL, 0, 0, 99, 623, '2026-02-25 12:48:36', '2026-03-05 14:49:17'),
(477, 441, 'normal', 720, 24, '2026-03-02 13:24:05', 0, '2026-03-02 14:09:23', 0, 0, NULL, 0, 0, 98764, 101482, '2026-02-25 12:58:01', '2026-03-02 14:09:23'),
(478, 442, 'normal', 720, 24, '2026-02-25 14:10:05', 0, NULL, 0, 0, NULL, 0, 0, 2401, 0, '2026-02-25 13:30:04', '2026-02-25 14:10:05'),
(479, 443, 'alta', 240, 8, '2026-02-25 15:19:17', 0, '2026-03-11 17:01:50', 0, 330145, NULL, 0, 0, 12, 20, '2026-02-25 15:19:05', '2026-03-11 17:01:50'),
(480, 443, 'normal', 480, 24, '2026-02-25 15:19:17', 0, '2026-03-11 17:01:50', 0, 330145, NULL, 0, 0, 12, 20, '2026-02-25 15:19:05', '2026-03-11 17:01:50'),
(481, 444, 'normal', 720, 24, '2026-02-25 16:41:40', 0, '2026-02-26 14:04:40', 0, 0, NULL, 0, 0, 579, 23559, '2026-02-25 16:32:01', '2026-02-26 14:04:40'),
(482, 445, 'normal', 720, 24, '2026-02-26 13:32:37', 0, '2026-02-26 18:02:52', 0, 2493, NULL, 0, 0, 19475, 33197, '2026-02-25 17:08:02', '2026-02-26 18:02:52'),
(483, 446, 'normal', 720, 24, '2026-02-26 13:36:20', 0, '2026-02-26 13:37:00', 0, 0, NULL, 0, 0, 11778, 11818, '2026-02-25 19:20:02', '2026-02-26 13:37:00'),
(484, 447, 'normal', 720, 24, '2026-02-26 12:14:05', 0, '2026-03-05 14:55:55', 0, 167011, NULL, 0, 0, 4445, 9144, '2026-02-25 21:58:01', '2026-03-05 14:55:55'),
(485, 448, 'normal', 720, 24, '2026-02-26 13:31:28', 0, '2026-02-27 12:11:15', 0, 27567, NULL, 0, 0, 3327, 3347, '2026-02-26 12:36:01', '2026-02-27 12:11:15'),
(486, 449, 'normal', 720, 24, '2026-02-26 14:02:23', 0, '2026-02-26 15:10:55', 0, 3687, NULL, 0, 0, 1581, 2006, '2026-02-26 13:36:02', '2026-02-26 15:10:55'),
(487, 450, 'normal', 720, 24, '2026-02-26 14:02:30', 0, '2026-02-26 15:11:18', 0, 3574, NULL, 0, 0, 268, 822, '2026-02-26 13:58:02', '2026-02-26 15:11:18'),
(488, 451, 'normal', 720, 24, '2026-02-26 15:07:06', 0, '2026-02-26 15:07:42', 0, 0, NULL, 0, 0, 1014, 1050, '2026-02-26 14:50:12', '2026-02-26 15:07:42'),
(489, 452, 'normal', 720, 24, '2026-02-26 15:07:50', 0, '2026-02-26 15:08:20', 0, 0, NULL, 0, 0, 949, 979, '2026-02-26 14:52:01', '2026-02-26 15:08:20'),
(490, 453, 'normal', 720, 24, '2026-02-26 16:03:17', 0, '2026-02-26 16:49:20', 0, 2680, NULL, 0, 0, 436, 519, '2026-02-26 15:56:01', '2026-02-26 16:49:20'),
(491, 454, 'normal', 720, 24, '2026-02-26 16:49:48', 0, '2026-03-05 14:49:30', 0, 148477, NULL, 0, 0, 2386, 8691, '2026-02-26 16:10:02', '2026-03-05 14:49:30'),
(492, 455, 'normal', 720, 24, '2026-02-27 17:21:14', 0, '2026-03-17 12:14:42', 0, 0, '2026-02-27 17:55:15', 1, 0, 8353, 378761, '2026-02-27 15:02:01', '2026-03-17 12:14:42'),
(493, 456, 'normal', 720, 24, '2026-03-02 12:37:21', 0, '2026-03-02 12:37:57', 0, 0, NULL, 0, 0, 5841, 5877, '2026-03-02 02:14:01', '2026-03-02 12:37:57'),
(494, 457, 'normal', 720, 24, '2026-03-09 00:56:32', 0, '2026-03-12 20:06:37', 0, 129600, NULL, 0, 0, 152759, 152759, '2026-03-02 13:34:01', '2026-03-12 20:06:37'),
(495, 458, 'normal', 720, 24, '2026-03-03 12:39:30', 0, '2026-03-11 12:23:58', 0, 191988, NULL, 0, 0, 17489, 18969, '2026-03-02 16:48:01', '2026-03-11 12:23:58'),
(496, 459, 'normal', 720, 24, '2026-03-02 18:24:53', 0, '2026-03-02 18:25:47', 0, 0, NULL, 0, 0, 891, 945, '2026-03-02 18:10:02', '2026-03-02 18:25:47'),
(497, 460, 'normal', 720, 24, '2026-03-03 12:19:10', 0, '2026-03-03 12:20:55', 0, 0, NULL, 0, 0, 3909, 4014, '2026-03-03 11:14:01', '2026-03-03 12:20:55'),
(498, 461, 'normal', 720, 24, '2026-03-04 12:53:12', 0, '2026-03-10 13:07:15', 0, 35429, NULL, 0, 0, 1511, 96525, '2026-03-04 12:28:01', '2026-03-10 13:07:15'),
(499, 462, 'normal', 720, 24, '2026-03-04 13:04:06', 0, '2026-03-09 19:49:24', 0, 104736, NULL, 0, 0, 485, 17267, '2026-03-04 12:56:01', '2026-03-09 19:49:24'),
(500, 463, 'normal', 720, 24, '2026-03-05 13:20:19', 0, NULL, 0, 0, '2026-03-05 13:20:26', 1, 0, 30610, 0, '2026-03-04 13:50:09', '2026-03-05 13:20:26'),
(501, 463, 'normal', 480, 24, '2026-03-05 13:20:19', 0, NULL, 0, 0, '2026-03-05 13:20:26', 1, 0, 30610, 0, '2026-03-04 13:50:09', '2026-03-05 13:20:26'),
(502, 464, 'normal', 720, 24, '2026-03-04 13:54:35', 0, NULL, 0, 0, '2026-03-04 13:54:41', 1, 0, 13, 0, '2026-03-04 13:54:22', '2026-03-04 13:54:41'),
(503, 464, 'normal', 480, 24, '2026-03-04 13:54:35', 0, NULL, 0, 0, '2026-03-04 13:54:41', 1, 0, 13, 0, '2026-03-04 13:54:22', '2026-03-04 13:54:41'),
(504, 465, 'normal', 720, 24, '2026-03-04 14:25:58', 0, '2026-03-11 12:19:56', 0, 154235, NULL, 0, 0, 11, 214, '2026-03-04 14:25:47', '2026-03-11 12:19:56'),
(505, 465, 'normal', 480, 24, '2026-03-04 14:25:58', 0, '2026-03-11 12:19:56', 0, 154235, NULL, 0, 0, 11, 214, '2026-03-04 14:25:47', '2026-03-11 12:19:56'),
(506, 466, 'normal', 720, 24, '2026-03-04 17:38:40', 0, '2026-03-04 17:43:30', 0, 0, NULL, 0, 0, 516, 806, '2026-03-04 17:30:04', '2026-03-04 17:43:30'),
(507, 467, 'normal', 720, 24, '2026-03-08 20:14:15', 0, NULL, 0, 0, '2026-03-08 20:14:24', 1, 0, 73439, 0, '2026-03-04 17:36:01', '2026-03-08 20:14:24'),
(508, 468, 'normal', 720, 24, '2026-03-05 12:22:13', 0, '2026-03-05 12:58:15', 0, 0, NULL, 0, 0, 7452, 9614, '2026-03-04 19:18:01', '2026-03-05 12:58:15'),
(509, 469, 'normal', 720, 24, '2026-03-05 12:16:04', 0, '2026-03-05 12:19:50', 0, 0, NULL, 0, 0, 5643, 5869, '2026-03-04 19:42:01', '2026-03-05 12:19:50'),
(510, 470, 'normal', 720, 24, '2026-03-08 20:13:39', 0, '2026-03-11 19:20:18', 0, 94818, NULL, 0, 0, 57839, 57839, '2026-03-05 12:56:01', '2026-03-11 19:20:18'),
(511, 471, 'normal', 720, 24, '2026-03-05 15:16:28', 0, '2026-03-11 12:19:44', 0, 62880, NULL, 0, 0, 747, 56863, '2026-03-05 15:04:01', '2026-03-11 12:19:44'),
(512, 472, 'normal', 720, 24, '2026-03-08 20:07:01', 0, '2026-03-25 14:00:34', 0, 399634, NULL, 0, 0, 45959, 45959, '2026-03-05 16:14:01', '2026-03-25 14:00:34'),
(513, 473, 'normal', 720, 24, '2026-03-05 17:24:14', 0, '2026-03-09 13:22:31', 0, 8551, NULL, 0, 0, 1693, 43439, '2026-03-05 16:56:01', '2026-03-09 13:22:31'),
(514, 474, 'normal', 720, 24, '2026-03-08 20:05:26', 0, '2026-04-16 17:22:26', 0, 660202, NULL, 0, 0, 38639, 308583, '2026-03-05 18:16:01', '2026-04-16 17:22:26'),
(515, 475, 'normal', 720, 24, '2026-03-06 12:09:58', 0, '2026-03-09 12:54:50', 0, 0, '2026-03-06 14:00:13', 1, 0, 4198, 39290, '2026-03-05 20:28:01', '2026-03-09 12:54:50'),
(516, 476, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-03-08 20:36:01', '2026-03-08 20:36:01'),
(517, 477, 'normal', 720, 24, '2026-03-08 20:38:17', 0, '2026-03-11 12:22:22', 0, 32117, NULL, 0, 0, 0, 37625, '2026-03-08 20:36:06', '2026-03-11 12:22:22'),
(518, 478, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-03-08 20:38:01', '2026-03-08 20:38:01'),
(519, 479, 'normal', 720, 24, '2026-03-09 13:24:04', 0, '2026-03-11 19:25:06', 0, 57494, NULL, 0, 0, 2403, 31371, '2026-03-09 12:44:01', '2026-03-11 19:25:06'),
(520, 480, 'normal', 720, 24, '2026-03-10 12:26:14', 0, '2026-03-10 13:52:59', 0, 0, '2026-03-10 12:26:56', 1, 0, 27493, 32698, '2026-03-09 13:48:01', '2026-03-10 13:52:59'),
(521, 481, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-03-09 19:48:01', '2026-03-09 19:48:01'),
(522, 482, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-03-10 11:20:02', '2026-03-10 11:20:02'),
(523, 483, 'normal', 720, 24, '2026-03-10 13:11:58', 0, '2026-03-10 13:55:19', 0, 1970, NULL, 0, 0, 597, 1228, '2026-03-10 13:02:01', '2026-03-10 13:55:19'),
(524, 484, 'normal', 720, 24, '2026-03-10 13:12:25', 0, '2026-03-11 11:25:12', 0, 25352, NULL, 0, 0, 264, 879, '2026-03-10 13:08:01', '2026-03-11 11:25:12'),
(525, 485, 'normal', 720, 24, '2026-03-10 13:46:32', 0, '2026-03-11 17:49:19', 0, 0, NULL, 0, 0, 109, 47076, '2026-03-10 13:44:43', '2026-03-11 17:49:19'),
(526, 485, 'normal', 480, 24, '2026-03-10 13:46:32', 0, '2026-03-11 17:49:19', 0, 0, NULL, 0, 0, 109, 47076, '2026-03-10 13:44:43', '2026-03-11 17:49:19'),
(527, 486, 'normal', 720, 24, '2026-03-11 11:30:50', 0, '2026-03-12 15:23:36', 0, 46349, NULL, 0, 0, 20088, 20105, '2026-03-10 14:56:02', '2026-03-12 15:23:36'),
(528, 487, 'normal', 720, 24, '2026-03-12 12:31:16', 0, '2026-03-17 12:17:08', 0, 96336, NULL, 0, 0, 19034, 19050, '2026-03-11 16:14:02', '2026-03-17 12:17:08'),
(529, 488, 'normal', 720, 24, '2026-03-11 18:22:54', 0, '2026-03-11 18:23:45', 0, 0, NULL, 0, 0, 24, 75, '2026-03-11 18:22:30', '2026-03-11 18:23:45'),
(530, 488, 'normal', 480, 24, '2026-03-11 18:22:54', 0, '2026-03-11 18:23:45', 0, 0, NULL, 0, 0, 24, 75, '2026-03-11 18:22:30', '2026-03-11 18:23:45'),
(531, 489, 'normal', 720, 24, '2026-03-12 12:31:53', 0, '2026-03-13 17:05:20', 0, 48799, NULL, 0, 0, 21, 29, '2026-03-12 12:31:32', '2026-03-13 17:05:20'),
(532, 489, 'normal', 480, 24, '2026-03-12 12:31:53', 0, '2026-03-13 17:05:20', 0, 48799, NULL, 0, 0, 21, 29, '2026-03-12 12:31:32', '2026-03-13 17:05:20'),
(533, 490, 'normal', 720, 24, '2026-03-12 12:59:47', 0, '2026-03-31 16:09:34', 0, 0, NULL, 0, 0, 584, 433171, '2026-03-12 12:50:03', '2026-03-31 16:09:34'),
(534, 491, 'normal', 720, 24, '2026-03-13 11:23:44', 0, '2026-05-14 12:37:38', 0, 1429915, NULL, 0, 0, 7063, 7182, '2026-03-12 18:26:01', '2026-05-14 12:37:38'),
(535, 492, 'normal', 720, 24, '2026-03-13 11:24:46', 0, '2026-03-17 14:31:38', 0, 75951, NULL, 0, 0, 4484, 4545, '2026-03-12 19:10:02', '2026-03-17 14:31:38'),
(536, 493, 'normal', 720, 24, '2026-03-13 13:42:12', 0, '2026-03-13 13:46:22', 0, 0, NULL, 0, 0, 6127, 6377, '2026-03-13 12:00:05', '2026-03-13 13:46:22'),
(537, 494, 'normal', 720, 24, '2026-03-16 13:15:42', 0, '2026-03-16 14:14:00', 0, 3356, NULL, 0, 0, 24220, 24362, '2026-03-13 15:32:02', '2026-03-16 14:14:00'),
(538, 495, 'normal', 720, 24, '2026-03-16 12:15:55', 0, '2026-03-16 12:17:01', 0, 0, NULL, 0, 0, 15834, 15900, '2026-03-13 16:52:01', '2026-03-16 12:17:01'),
(539, 496, 'normal', 720, 24, '2026-03-16 13:17:38', 0, '2026-03-18 14:41:56', 0, 0, '2026-03-16 13:18:07', 1, 0, 16537, 86395, '2026-03-13 17:42:01', '2026-03-18 14:41:56'),
(540, 497, 'normal', 720, 24, '2026-03-16 12:09:28', 0, '2026-03-16 12:09:28', 0, 0, NULL, 0, 0, 4168, 4168, '2026-03-15 20:06:01', '2026-03-16 12:09:28'),
(541, 498, 'normal', 720, 24, '2026-03-16 12:09:14', 0, '2026-03-16 12:09:14', 0, 0, NULL, 0, 0, 433, 433, '2026-03-16 12:02:01', '2026-03-16 12:09:14'),
(542, 499, 'normal', 720, 24, '2026-03-16 13:33:06', 0, '2026-03-17 14:03:54', 0, 34237, NULL, 0, 0, 44, 55, '2026-03-16 13:32:22', '2026-03-17 14:03:54'),
(543, 499, 'normal', 480, 24, '2026-03-16 13:33:06', 0, '2026-03-17 14:03:54', 0, 34237, NULL, 0, 0, 44, 55, '2026-03-16 13:32:22', '2026-03-17 14:03:54'),
(544, 500, 'normal', 720, 24, '2026-03-16 18:06:11', 0, '2026-03-16 18:06:54', 0, 0, NULL, 0, 0, 14, 57, '2026-03-16 18:05:57', '2026-03-16 18:06:54'),
(545, 500, 'normal', 480, 24, '2026-03-16 18:06:11', 0, '2026-03-16 18:06:54', 0, 0, NULL, 0, 0, 14, 57, '2026-03-16 18:05:57', '2026-03-16 18:06:54'),
(546, 501, 'normal', 720, 24, '2026-03-17 12:43:46', 0, '2026-03-20 12:15:41', 0, 95504, NULL, 0, 0, 16, 27, '2026-03-17 12:43:30', '2026-03-20 12:15:41'),
(547, 501, 'normal', 480, 24, '2026-03-17 12:43:46', 0, '2026-03-20 12:15:41', 0, 95504, NULL, 0, 0, 16, 27, '2026-03-17 12:43:30', '2026-03-20 12:15:41'),
(548, 502, 'normal', 720, 24, '2026-03-17 14:43:54', 0, '2026-03-17 18:31:49', 0, 13663, NULL, 0, 0, 27, 39, '2026-03-17 14:43:27', '2026-03-17 18:31:49'),
(549, 502, 'normal', 480, 24, '2026-03-17 14:43:54', 0, '2026-03-17 18:31:49', 0, 13663, NULL, 0, 0, 27, 39, '2026-03-17 14:43:27', '2026-03-17 18:31:49'),
(550, 503, 'normal', 720, 24, '2026-03-17 18:16:13', 0, NULL, 0, 0, '2026-03-20 16:16:37', 1, 0, 0, 0, '2026-03-17 15:00:07', '2026-03-20 16:16:37'),
(551, 504, 'normal', 720, 24, '2026-03-18 11:40:39', 0, '2026-03-18 13:25:37', 0, 4601, NULL, 0, 0, 8558, 10255, '2026-03-17 18:18:01', '2026-03-18 13:25:37'),
(552, 505, 'normal', 720, 24, '2026-03-18 11:40:30', 0, '2026-03-18 13:25:55', 0, 4624, NULL, 0, 0, 8543, 10244, '2026-03-17 18:18:07', '2026-03-18 13:25:55'),
(553, 506, 'normal', 720, 24, '2026-03-17 18:32:28', 0, '2026-03-19 13:54:44', 0, 40519, NULL, 0, 0, 413, 8030, '2026-03-17 18:25:35', '2026-03-19 13:54:44'),
(554, 506, 'normal', 480, 24, '2026-03-17 18:32:28', 0, '2026-03-19 13:54:44', 0, 40519, NULL, 0, 0, 413, 8030, '2026-03-17 18:25:35', '2026-03-19 13:54:44'),
(555, 507, 'normal', 720, 24, '2026-03-18 12:06:01', 0, '2026-04-13 11:36:30', 0, 581384, NULL, 0, 0, 1112, 1157, '2026-03-18 11:47:29', '2026-04-13 11:36:30'),
(556, 507, 'normal', 480, 24, '2026-03-18 12:06:01', 0, '2026-04-13 11:36:30', 0, 581384, NULL, 0, 0, 1112, 1157, '2026-03-18 11:47:29', '2026-04-13 11:36:30'),
(557, 508, 'normal', 720, 24, '2026-03-18 19:02:52', 0, '2026-03-19 13:37:29', 0, 7747, NULL, 0, 0, 9411, 14541, '2026-03-18 16:26:01', '2026-03-19 13:37:29'),
(558, 509, 'normal', 720, 24, '2026-03-18 19:01:44', 0, '2026-03-20 12:23:57', 0, 40925, NULL, 0, 0, 21, 29, '2026-03-18 19:01:23', '2026-03-20 12:23:57'),
(559, 509, 'normal', 480, 24, '2026-03-18 19:01:44', 0, '2026-03-20 12:23:57', 0, 40925, NULL, 0, 0, 21, 29, '2026-03-18 19:01:23', '2026-03-20 12:23:57'),
(560, 510, 'normal', 720, 24, '2026-03-19 11:28:05', 0, '2026-03-19 11:48:42', 0, 1215, NULL, 0, 0, 1685, 1707, '2026-03-19 04:00:05', '2026-03-19 11:48:42'),
(561, 511, 'normal', 720, 24, '2026-03-19 13:32:55', 0, '2026-03-26 12:57:26', 0, 0, '2026-03-19 14:35:33', 1, 0, 2211, 162082, '2026-03-19 12:56:04', '2026-03-26 12:57:26'),
(562, 512, 'normal', 720, 24, '2026-03-19 13:38:09', 0, '2026-03-19 13:44:56', 0, 384, NULL, 0, 0, 968, 991, '2026-03-19 13:22:01', '2026-03-19 13:44:56'),
(563, 513, 'normal', 720, 24, '2026-03-19 17:26:59', 0, '2026-03-20 12:07:04', 0, 1737, NULL, 0, 0, 4858, 16326, '2026-03-19 16:06:01', '2026-03-20 12:07:04'),
(564, 514, 'normal', 720, 24, '2026-03-20 11:36:51', 0, '2026-03-23 14:04:29', 0, 0, '2026-03-20 11:40:12', 1, 0, 9649, 50907, '2026-03-19 17:56:02', '2026-03-23 14:04:29'),
(565, 515, 'normal', 720, 24, '2026-03-20 12:07:17', 0, '2026-03-20 12:12:53', 0, 0, NULL, 0, 0, 675, 1011, '2026-03-20 11:56:02', '2026-03-20 12:12:53'),
(566, 516, 'normal', 720, 24, '2026-03-23 12:06:06', 0, '2026-03-24 13:39:01', 0, 0, NULL, 0, 0, 9243, 47218, '2026-03-20 18:32:03', '2026-03-24 13:39:01'),
(567, 517, 'normal', 720, 24, '2026-03-23 12:13:08', 0, '2026-03-31 16:12:46', 0, 171330, NULL, 0, 0, 4388, 41836, '2026-03-23 02:10:02', '2026-03-31 16:12:46'),
(568, 518, 'normal', 720, 24, '2026-03-23 12:16:14', 0, '2026-03-23 15:06:57', 0, 2, NULL, 0, 0, 1333, 11574, '2026-03-23 11:54:01', '2026-03-23 15:06:57'),
(569, 519, 'normal', 720, 24, '2026-03-23 14:07:33', 0, '2026-03-25 13:52:15', 0, 63545, NULL, 0, 0, 13, 350, '2026-03-23 14:07:20', '2026-03-25 13:52:15'),
(570, 519, 'normal', 480, 24, '2026-03-23 14:07:33', 0, '2026-03-25 13:52:15', 0, 63545, NULL, 0, 0, 13, 350, '2026-03-23 14:07:20', '2026-03-25 13:52:15'),
(571, 520, 'normal', 720, 24, '2026-03-25 13:53:03', 0, '2026-03-26 13:02:23', 0, 361, NULL, 0, 0, 29822, 58821, '2026-03-24 14:36:01', '2026-03-26 13:02:23'),
(572, 521, 'normal', 720, 24, '2026-03-24 16:25:18', 0, '2026-03-24 16:31:03', 0, 0, NULL, 0, 0, 5357, 5702, '2026-03-24 14:56:01', '2026-03-24 16:31:03'),
(573, 522, 'normal', 720, 24, '2026-03-25 13:56:41', 0, '2026-03-25 14:55:24', 0, 0, '2026-03-25 13:56:55', 1, 0, 26680, 30203, '2026-03-24 15:32:01', '2026-03-25 14:55:24'),
(574, 523, 'normal', 720, 24, '2026-03-25 13:57:27', 0, '2026-03-31 16:11:53', 0, 137648, NULL, 0, 0, 13886, 13904, '2026-03-24 19:06:01', '2026-03-31 16:11:53'),
(575, 524, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-03-25 13:26:01', '2026-03-25 13:26:01'),
(576, 525, 'normal', 720, 24, '2026-03-25 13:58:58', 0, '2026-03-25 16:37:23', 0, 9498, NULL, 0, 0, 219, 226, '2026-03-25 13:55:19', '2026-03-25 16:37:23'),
(577, 525, 'normal', 480, 24, '2026-03-25 13:58:58', 0, '2026-03-25 16:37:23', 0, 9498, NULL, 0, 0, 219, 226, '2026-03-25 13:55:19', '2026-03-25 16:37:23'),
(578, 526, 'normal', 720, 24, '2026-03-26 13:17:52', 0, '2026-03-31 15:16:21', 0, 104291, NULL, 0, 0, 18351, 18369, '2026-03-25 17:12:01', '2026-03-31 15:16:21'),
(579, 527, 'normal', 720, 24, '2026-03-26 13:56:09', 0, '2026-04-17 12:37:21', 0, 92398, NULL, 0, 0, 2647, 423921, '2026-03-26 13:12:02', '2026-04-17 12:37:21'),
(580, 528, 'normal', 720, 24, '2026-03-30 18:34:10', 0, '2026-04-07 11:45:31', 0, 169865, NULL, 0, 0, 18489, 18505, '2026-03-30 13:26:01', '2026-04-07 11:45:31'),
(581, 529, 'normal', 720, 24, '2026-03-31 12:28:01', 0, '2026-03-31 13:58:37', 0, 5367, NULL, 0, 0, 9960, 10029, '2026-03-30 18:42:01', '2026-03-31 13:58:37'),
(582, 530, 'normal', 720, 24, '2026-03-31 14:36:43', 0, '2026-03-31 14:48:45', 0, 698, NULL, 0, 0, 6642, 6666, '2026-03-31 12:46:01', '2026-03-31 14:48:45'),
(583, 531, 'normal', 720, 24, '2026-03-31 14:37:27', 0, '2026-05-08 17:37:42', 0, 917982, NULL, 0, 0, 1406, 1439, '2026-03-31 14:14:01', '2026-05-08 17:37:42'),
(584, 532, 'normal', 720, 24, '2026-03-31 15:27:32', 0, '2026-04-28 14:19:43', 0, 643910, NULL, 0, 0, 211, 232, '2026-03-31 15:24:01', '2026-04-28 14:19:43'),
(585, 533, 'normal', 720, 24, '2026-03-31 15:37:36', 0, '2026-03-31 15:50:54', 0, 658, NULL, 0, 0, 95, 235, '2026-03-31 15:36:01', '2026-03-31 15:50:54'),
(586, 534, 'normal', 720, 24, '2026-03-31 15:59:35', 0, '2026-04-22 11:17:53', 0, 0, '2026-04-02 19:53:02', 1, 0, 934, 502432, '2026-03-31 15:44:01', '2026-04-22 11:17:53'),
(587, 535, 'normal', 720, 24, '2026-03-31 16:00:02', 0, '2026-04-16 17:19:13', 0, 393544, NULL, 0, 0, 116, 123, '2026-03-31 15:58:06', '2026-04-16 17:19:13'),
(588, 535, 'normal', 480, 24, '2026-03-31 16:00:02', 0, '2026-04-16 17:19:13', 0, 393544, NULL, 0, 0, 116, 123, '2026-03-31 15:58:06', '2026-04-16 17:19:13'),
(589, 536, 'normal', 720, 24, '2026-03-31 16:06:13', 0, NULL, 0, 0, NULL, 0, 0, 132, 0, '2026-03-31 16:04:01', '2026-03-31 16:06:13'),
(590, 537, 'normal', 720, 24, '2026-03-31 16:27:31', 0, '2026-03-31 16:28:17', 0, 0, NULL, 0, 0, 569, 615, '2026-03-31 16:18:02', '2026-03-31 16:28:17'),
(591, 538, 'normal', 720, 24, '2026-03-31 18:38:12', 0, '2026-04-01 12:56:19', 0, 11864, NULL, 0, 0, 1090, 1113, '2026-03-31 18:20:02', '2026-04-01 12:56:19'),
(592, 539, 'normal', 720, 24, '2026-03-31 19:15:50', 0, '2026-05-07 15:10:29', 0, 860072, NULL, 0, 0, 49, 56, '2026-03-31 19:15:01', '2026-05-07 15:10:29'),
(593, 539, 'normal', 480, 24, '2026-03-31 19:15:50', 0, '2026-05-07 15:10:29', 0, 860072, NULL, 0, 0, 49, 56, '2026-03-31 19:15:01', '2026-05-07 15:10:29'),
(594, 540, 'normal', 720, 24, '2026-04-02 14:39:32', 0, '2026-04-02 14:48:11', 0, 0, NULL, 0, 0, 37531, 38050, '2026-04-01 13:14:01', '2026-04-02 14:48:11'),
(595, 541, 'normal', 720, 24, '2026-04-02 16:40:44', 0, '2026-04-02 16:42:17', 0, 0, NULL, 0, 0, 163, 256, '2026-04-02 16:38:01', '2026-04-02 16:42:17'),
(596, 542, 'normal', 720, 24, '2026-04-02 18:21:49', 0, NULL, 0, 0, NULL, 0, 0, 106, 0, '2026-04-02 18:20:03', '2026-04-02 18:21:49'),
(597, 543, 'normal', 720, 24, '2026-04-02 19:33:08', 0, '2026-04-13 11:10:35', 0, 196634, NULL, 0, 0, 3784, 3797, '2026-04-02 18:30:04', '2026-04-13 11:10:35'),
(598, 544, 'normal', 720, 24, '2026-04-06 14:18:05', 0, '2026-04-06 14:19:47', 0, 0, NULL, 0, 0, 6481, 6583, '2026-04-06 12:30:04', '2026-04-06 14:19:47'),
(599, 545, 'normal', 720, 24, '2026-04-07 13:49:39', 0, '2026-04-13 11:33:08', 0, 84303, NULL, 0, 0, 5018, 42124, '2026-04-07 12:26:01', '2026-04-13 11:33:08'),
(600, 546, 'normal', 720, 24, '2026-04-07 13:49:53', 0, '2026-04-07 15:49:22', 0, 0, NULL, 0, 0, 3472, 10641, '2026-04-07 12:52:01', '2026-04-07 15:49:22'),
(601, 547, 'normal', 720, 24, '2026-04-08 15:08:12', 0, '2026-04-09 19:37:30', 0, 48532, NULL, 0, 0, 9966, 9992, '2026-04-08 12:22:06', '2026-04-09 19:37:30'),
(602, 548, 'normal', 720, 24, '2026-04-08 15:08:48', 0, '2026-04-08 15:17:05', 0, 475, NULL, 0, 0, 2807, 2829, '2026-04-08 14:22:01', '2026-04-08 15:17:05'),
(603, 549, 'normal', 720, 24, '2026-04-09 18:23:51', 0, '2026-04-14 12:40:53', 0, 76615, NULL, 0, 0, 32510, 32517, '2026-04-08 18:22:01', '2026-04-14 12:40:53'),
(604, 550, 'normal', 720, 24, '2026-04-09 19:02:09', 0, '2026-04-09 19:02:09', 0, 0, NULL, 0, 0, 32887, 32887, '2026-04-08 18:54:02', '2026-04-09 19:02:09'),
(605, 551, 'normal', 720, 24, '2026-04-09 19:07:46', 0, '2026-04-09 19:27:43', 0, 0, NULL, 0, 0, 14258, 15455, '2026-04-09 15:10:08', '2026-04-09 19:27:43'),
(606, 552, 'normal', 720, 24, '2026-04-09 19:28:19', 0, '2026-04-13 11:25:43', 0, 35830, NULL, 0, 0, 8658, 8672, '2026-04-09 17:04:01', '2026-04-13 11:25:43'),
(607, 553, 'normal', 720, 24, '2026-04-10 11:31:13', 0, '2026-04-10 13:20:30', 0, 0, NULL, 0, 0, 1873, 8430, '2026-04-09 20:08:01', '2026-04-10 13:20:30'),
(608, 554, 'normal', 720, 24, '2026-04-10 11:31:36', 0, '2026-04-10 13:15:38', 0, 0, NULL, 0, 0, 0, 8138, '2026-04-10 10:26:01', '2026-04-10 13:15:38'),
(609, 555, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-04-10 13:24:02', '2026-04-10 13:24:02'),
(610, 556, 'normal', 720, 24, '2026-04-10 19:22:46', 0, '2026-04-28 17:06:18', 0, 380580, NULL, 0, 0, 14205, 14237, '2026-04-10 15:26:01', '2026-04-28 17:06:18'),
(611, 557, 'normal', 720, 24, '2026-04-10 19:23:33', 0, '2026-04-10 19:27:09', 0, 199, NULL, 0, 0, 3331, 3348, '2026-04-10 18:28:02', '2026-04-10 19:27:09'),
(612, 558, 'normal', 720, 24, '2026-04-10 19:27:24', 0, '2026-04-28 14:19:24', 0, 370167, NULL, 0, 0, 2241, 2394, '2026-04-10 18:50:03', '2026-04-28 14:19:24'),
(613, 559, 'normal', 720, 24, '2026-04-10 19:30:26', 0, '2026-04-10 19:37:22', 0, 400, NULL, 0, 0, 744, 760, '2026-04-10 19:18:02', '2026-04-10 19:37:22'),
(614, 560, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-04-14 16:00:08', '2026-04-14 16:00:08'),
(615, 561, 'normal', 720, 24, '2026-04-14 16:49:11', 0, '2026-04-15 17:05:04', 0, 33319, NULL, 0, 0, 190, 224, '2026-04-14 16:46:01', '2026-04-15 17:05:04'),
(616, 562, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-04-15 12:02:01', '2026-04-15 12:02:01'),
(617, 563, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-04-15 12:12:01', '2026-04-15 12:12:01'),
(618, 564, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-04-15 12:12:08', '2026-04-15 12:12:08'),
(619, 565, 'normal', 720, 24, '2026-04-15 19:51:09', 0, '2026-04-16 17:16:32', 0, 23104, NULL, 0, 0, 20348, 20367, '2026-04-15 14:12:01', '2026-04-16 17:16:32'),
(620, 566, 'normal', 720, 24, '2026-04-15 17:18:42', 0, '2026-04-15 17:31:32', 0, 0, NULL, 0, 0, 3519, 4289, '2026-04-15 16:20:03', '2026-04-15 17:31:32'),
(621, 567, 'normal', 720, 24, '2026-04-15 19:51:52', 0, '2026-04-28 17:06:41', 0, 281682, NULL, 0, 0, 4790, 4797, '2026-04-15 18:32:02', '2026-04-28 17:06:41'),
(622, 568, 'normal', 720, 24, '2026-04-15 19:53:43', 0, NULL, 0, 0, '2026-04-15 19:54:04', 1, 0, 1661, 0, '2026-04-15 19:26:02', '2026-04-15 19:54:04'),
(623, 569, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-04-16 11:36:01', '2026-04-16 11:36:01'),
(624, 570, 'normal', 720, 24, '2026-04-16 14:08:19', 0, '2026-04-27 16:00:39', 0, 233509, NULL, 0, 0, 8178, 8209, '2026-04-16 11:52:01', '2026-04-27 16:00:39'),
(625, 571, 'normal', 720, 24, '2026-04-16 14:08:05', 0, '2026-04-16 16:33:11', 0, 8664, NULL, 0, 0, 4924, 4966, '2026-04-16 12:46:01', '2026-04-16 16:33:11'),
(626, 572, 'normal', 720, 24, '2026-04-16 16:33:24', 0, '2026-04-16 18:17:51', 0, 4294, NULL, 0, 0, 7398, 9371, '2026-04-16 14:30:06', '2026-04-16 18:17:51'),
(627, 573, 'normal', 720, 24, '2026-04-16 18:18:06', 0, '2026-05-04 14:08:57', 0, 0, '2026-04-17 19:32:28', 1, 0, 2283, 376134, '2026-04-16 17:40:03', '2026-05-04 14:08:57'),
(628, 574, 'normal', 720, 24, '2026-04-16 18:22:29', 0, '2026-04-16 18:22:29', 0, 0, NULL, 0, 0, 2539, 2539, '2026-04-16 17:40:10', '2026-04-16 18:22:29'),
(629, 575, 'normal', 720, 24, '2026-04-17 11:55:10', 0, '2026-04-22 12:16:01', 0, 98437, NULL, 0, 0, 3310, 3324, '2026-04-17 10:34:02', '2026-04-22 12:16:01'),
(630, 576, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-04-17 11:38:02', '2026-04-17 11:38:02'),
(631, 577, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-04-17 16:08:02', '2026-04-17 16:08:02'),
(632, 578, 'normal', 720, 24, '2026-04-23 14:42:26', 0, NULL, 0, 0, '2026-04-23 14:43:57', 1, 0, 40465, 0, '2026-04-22 12:28:01', '2026-04-23 14:43:57'),
(633, 579, 'normal', 720, 24, '2026-04-22 16:40:53', 0, '2026-04-22 16:51:19', 0, 0, NULL, 0, 0, 7611, 8237, '2026-04-22 14:34:02', '2026-04-22 16:51:19'),
(634, 580, 'normal', 720, 24, '2026-04-22 16:31:40', 0, '2026-04-23 14:42:10', 0, 0, NULL, 0, 0, 218, 26048, '2026-04-22 16:28:02', '2026-04-23 14:42:10'),
(635, 581, 'normal', 720, 24, '2026-04-23 14:55:03', 0, '2026-04-23 15:05:15', 0, 0, NULL, 0, 0, 24895, 25507, '2026-04-22 17:00:08', '2026-04-23 15:05:15'),
(636, 582, 'normal', 720, 24, '2026-04-23 13:02:46', 0, '2026-04-23 13:06:58', 0, 0, NULL, 0, 0, 2685, 2937, '2026-04-23 12:18:01', '2026-04-23 13:06:58'),
(637, 583, 'normal', 720, 24, '2026-04-23 17:26:11', 0, '2026-04-23 17:26:20', 0, 0, NULL, 0, 0, 18, 27, '2026-04-23 17:25:53', '2026-04-23 17:26:20'),
(638, 583, 'normal', 480, 24, '2026-04-23 17:26:11', 0, '2026-04-23 17:26:20', 0, 0, NULL, 0, 0, 18, 27, '2026-04-23 17:25:53', '2026-04-23 17:26:20'),
(639, 584, 'normal', 720, 24, '2026-04-23 23:15:07', 0, '2026-05-22 19:08:33', 0, 0, '2026-04-23 23:15:22', 1, 0, 8639, 685952, '2026-04-23 17:36:01', '2026-05-22 19:08:33'),
(640, 585, 'normal', 720, 24, '2026-04-23 23:15:51', 0, '2026-05-18 11:19:34', 0, 0, '2026-04-23 23:16:06', 1, 0, 8519, 528093, '2026-04-23 17:38:01', '2026-05-18 11:19:34'),
(641, 586, 'normal', 720, 24, '2026-04-23 23:16:43', 0, '2026-04-27 16:17:27', 0, 47355, NULL, 0, 0, 0, 4092, '2026-04-23 20:04:01', '2026-04-27 16:17:27'),
(642, 587, 'normal', 720, 24, '2026-04-24 19:40:50', 0, '2026-04-28 17:02:05', 0, 55217, NULL, 0, 0, 4244, 4302, '2026-04-24 18:30:06', '2026-04-28 17:02:05'),
(643, 588, 'normal', 720, 24, '2026-04-27 11:34:16', 0, '2026-04-28 16:43:58', 0, 0, '2026-04-27 13:01:25', 1, 0, 2056, 53038, '2026-04-25 12:26:02', '2026-04-28 16:43:58'),
(644, 589, 'normal', 720, 24, '2026-04-27 20:41:09', 0, '2026-04-28 17:01:33', 0, 21693, NULL, 0, 0, 17639, 17639, '2026-04-27 15:06:01', '2026-04-28 17:01:33'),
(645, 590, 'normal', 720, 24, '2026-04-27 20:41:32', 0, '2026-04-28 14:19:10', 0, 11950, NULL, 0, 0, 0, 0, '2026-04-27 20:18:01', '2026-04-28 14:19:10'),
(646, 591, 'normal', 720, 24, '2026-04-28 14:40:55', 0, '2026-04-29 12:09:43', 0, 0, '2026-04-28 14:53:49', 1, 0, 7133, 30461, '2026-04-28 12:42:02', '2026-04-29 12:09:43'),
(647, 592, 'normal', 720, 24, '2026-04-28 14:20:20', 0, '2026-05-18 11:16:51', 0, 0, '2026-04-30 19:38:51', 1, 0, 2538, 445129, '2026-04-28 13:38:02', '2026-05-18 11:16:51'),
(648, 593, 'normal', 720, 24, '2026-04-29 16:35:34', 0, '2026-04-30 13:51:32', 0, 22233, NULL, 0, 0, 2252, 2577, '2026-04-29 15:58:02', '2026-04-30 13:51:32'),
(649, 594, 'normal', 720, 24, '2026-04-29 16:39:40', 0, '2026-05-08 17:35:12', 0, 230105, NULL, 0, 0, 938, 965, '2026-04-29 16:24:02', '2026-05-08 17:35:12'),
(650, 595, 'normal', 720, 24, '2026-04-30 19:00:02', 0, '2026-05-07 15:24:31', 0, 0, '2026-05-04 12:56:16', 1, 0, 2999, 152068, '2026-04-30 18:10:03', '2026-05-07 15:24:31'),
(651, 596, 'normal', 720, 24, '2026-04-30 19:26:34', 0, '2026-04-30 19:32:55', 0, 0, NULL, 0, 0, 3389, 3770, '2026-04-30 18:30:05', '2026-04-30 19:32:55'),
(652, 597, 'normal', 720, 24, '2026-04-30 19:33:18', 0, '2026-05-08 17:35:39', 0, 0, '2026-04-30 19:33:25', 1, 0, 2025, 189366, '2026-04-30 18:59:33', '2026-05-08 17:35:39'),
(653, 597, 'normal', 480, 24, '2026-04-30 19:33:18', 0, '2026-05-08 17:35:39', 0, 0, '2026-04-30 19:33:25', 1, 0, 2025, 189366, '2026-04-30 18:59:33', '2026-05-08 17:35:39'),
(654, 598, 'normal', 720, 24, '2026-04-30 19:39:07', 0, '2026-05-04 14:08:22', 0, 44940, NULL, 0, 0, 2106, 2121, '2026-04-30 19:04:01', '2026-05-04 14:08:22'),
(655, 599, 'normal', 720, 24, '2026-05-04 12:34:27', 0, '2026-05-08 16:40:59', 0, 0, '2026-05-04 12:43:06', 1, 0, 262, 144654, '2026-05-04 12:30:05', '2026-05-08 16:40:59'),
(656, 600, 'normal', 720, 24, '2026-05-04 14:13:54', 0, '2026-05-04 14:14:20', 0, 0, NULL, 0, 0, 2153, 2179, '2026-05-04 13:38:01', '2026-05-04 14:14:20'),
(657, 601, 'normal', 720, 24, '2026-05-05 19:28:08', 0, NULL, 0, 0, '2026-05-05 19:28:27', 1, 0, 42607, 0, '2026-05-04 16:38:01', '2026-05-05 19:28:27'),
(658, 602, 'normal', 720, 24, '2026-05-06 15:52:18', 0, '2026-05-08 14:16:42', 0, 0, '2026-05-06 15:52:43', 1, 0, 52457, 111521, '2026-05-04 19:18:01', '2026-05-08 14:16:42'),
(659, 603, 'normal', 720, 24, '2026-05-06 15:52:52', 0, '2026-05-06 16:37:18', 0, 553, NULL, 0, 0, 49972, 52085, '2026-05-04 20:10:02', '2026-05-06 16:37:18'),
(660, 604, 'normal', 720, 24, '2026-05-06 15:53:17', 0, '2026-05-07 17:04:12', 0, 36640, NULL, 0, 0, 46756, 46771, '2026-05-05 11:54:01', '2026-05-07 17:04:12'),
(661, 605, 'normal', 720, 24, '2026-05-06 15:53:38', 0, '2026-05-07 15:04:35', 0, 29445, NULL, 0, 0, 37297, 37309, '2026-05-05 14:32:01', '2026-05-07 15:04:35'),
(662, 606, 'normal', 720, 24, '2026-05-06 15:53:56', 0, '2026-05-06 16:20:25', 0, 1563, NULL, 0, 0, 1555, 1581, '2026-05-06 15:28:01', '2026-05-06 16:20:25'),
(663, 607, 'normal', 720, 24, '2026-05-07 17:04:39', 0, '2026-05-22 19:11:27', 0, 357746, NULL, 0, 0, 29438, 35700, '2026-05-06 17:54:01', '2026-05-22 19:11:27'),
(664, 608, 'normal', 720, 24, '2026-05-07 17:17:06', 0, '2026-05-07 18:43:06', 0, 5148, NULL, 0, 0, 25264, 25276, '2026-05-06 19:16:02', '2026-05-07 18:43:06'),
(665, 609, 'normal', 720, 24, '2026-05-07 17:17:36', 0, '2026-05-13 17:08:28', 0, 0, '2026-05-08 14:15:16', 1, 0, 21094, 150146, '2026-05-07 11:26:02', '2026-05-13 17:08:28'),
(666, 610, 'normal', 720, 24, '2026-05-07 17:18:11', 0, '2026-05-14 11:51:06', 0, 0, '2026-05-07 17:21:09', 1, 0, 12130, 154505, '2026-05-07 13:56:01', '2026-05-14 11:51:06'),
(667, 611, 'normal', 720, 24, '2026-05-07 17:19:45', 0, '2026-05-07 17:20:45', 0, 0, NULL, 0, 0, 11024, 11084, '2026-05-07 14:16:01', '2026-05-07 17:20:45'),
(668, 612, 'normal', 720, 24, '2026-05-07 17:37:22', 0, '2026-05-08 14:31:32', 0, 21238, NULL, 0, 0, 9920, 9932, '2026-05-07 14:52:02', '2026-05-08 14:31:32'),
(669, 613, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-05-07 15:42:02', '2026-05-07 15:42:02'),
(670, 614, 'normal', 720, 24, '2026-05-07 21:06:11', 0, '2026-05-08 17:34:11', 0, 0, NULL, 0, 0, 1679, 25330, '2026-05-07 19:32:01', '2026-05-08 17:34:11'),
(671, 615, 'normal', 720, 24, '2026-05-07 21:04:58', 0, '2026-05-07 21:05:39', 0, 0, NULL, 0, 0, 0, 0, '2026-05-07 20:14:01', '2026-05-07 21:05:39'),
(672, 616, 'normal', 720, 24, '2026-05-08 13:56:47', 0, '2026-05-08 13:57:10', 0, 0, NULL, 0, 0, 3285, 3308, '2026-05-08 13:02:02', '2026-05-08 13:57:10'),
(673, 617, 'normal', 720, 24, '2026-05-08 19:30:10', 0, '2026-05-26 13:11:51', 0, 235459, NULL, 0, 0, 23168, 153810, '2026-05-08 13:04:02', '2026-05-26 13:11:51'),
(674, 618, 'normal', 720, 24, '2026-05-11 15:39:05', 0, '2026-05-14 11:22:38', 0, 81802, NULL, 0, 0, 23647, 23658, '2026-05-08 18:04:58', '2026-05-14 11:22:38'),
(675, 618, 'normal', 480, 24, '2026-05-11 15:39:05', 0, '2026-05-14 11:22:38', 0, 81802, NULL, 0, 0, 23647, 23658, '2026-05-08 18:04:58', '2026-05-14 11:22:38'),
(676, 619, 'normal', 720, 24, '2026-05-11 15:39:25', 0, '2026-05-14 11:22:10', 0, 81756, NULL, 0, 0, 21253, 21262, '2026-05-08 18:45:12', '2026-05-14 11:22:10'),
(677, 619, 'normal', 480, 24, '2026-05-11 15:39:25', 0, '2026-05-14 11:22:10', 0, 81756, NULL, 0, 0, 21253, 21262, '2026-05-08 18:45:12', '2026-05-14 11:22:10'),
(678, 620, 'normal', 720, 24, '2026-05-08 19:47:23', 0, '2026-05-18 11:27:56', 0, 0, '2026-05-14 19:47:07', 1, 0, 322, 164755, '2026-05-08 19:42:01', '2026-05-18 11:27:56'),
(679, 621, 'normal', 720, 24, '2026-05-11 15:39:46', 0, '2026-05-11 15:40:20', 0, 0, NULL, 0, 0, 8025, 8059, '2026-05-11 13:26:01', '2026-05-11 15:40:20');
INSERT INTO `ticket_sla` (`id`, `ticket_id`, `prioridade`, `sla_resposta_minutos`, `sla_resolucao_horas`, `primeira_resposta_em`, `sla_resposta_vencido`, `resolvido_em`, `sla_resolucao_vencido`, `tempo_pausado_segundos`, `pausado_em`, `pausado`, `tempo_total_segundos`, `tempo_primeira_resposta_segundos`, `tempo_resolucao_segundos`, `criado_em`, `atualizado_em`) VALUES
(680, 622, 'normal', 720, 24, '2026-05-11 15:40:37', 0, '2026-05-11 17:14:35', 0, 0, NULL, 0, 0, 7476, 13114, '2026-05-11 13:36:01', '2026-05-11 17:14:35'),
(681, 623, 'normal', 720, 24, '2026-05-12 13:02:46', 0, '2026-05-13 17:07:39', 0, 0, NULL, 0, 0, 0, 54459, '2026-05-11 21:14:01', '2026-05-13 17:07:39'),
(682, 624, 'normal', 720, 24, '2026-05-12 12:44:39', 0, NULL, 0, 0, '2026-05-12 12:50:43', 1, 0, 6279, 0, '2026-05-12 00:02:01', '2026-05-12 12:50:43'),
(683, 625, 'normal', 720, 24, '2026-05-12 12:43:32', 0, '2026-05-12 12:44:01', 0, 0, NULL, 0, 0, 16, 45, '2026-05-12 12:43:16', '2026-05-12 12:44:01'),
(684, 625, 'normal', 480, 24, '2026-05-12 12:43:32', 0, '2026-05-12 12:44:01', 0, 0, NULL, 0, 0, 16, 45, '2026-05-12 12:43:16', '2026-05-12 12:44:01'),
(685, 626, 'normal', 720, 24, '2026-05-12 20:06:55', 0, '2026-05-19 14:25:47', 0, 108445, NULL, 0, 0, 21839, 55341, '2026-05-12 13:56:01', '2026-05-19 14:25:47'),
(686, 627, 'normal', 720, 24, '2026-05-12 20:07:22', 0, '2026-06-15 16:57:30', 0, 527660, NULL, 0, 0, 8159, 247149, '2026-05-12 17:44:01', '2026-06-15 16:57:30'),
(687, 628, 'normal', 720, 24, '2026-05-14 11:19:13', 0, NULL, 0, 0, '2026-05-14 11:19:27', 1, 0, 23471, 0, '2026-05-13 13:48:02', '2026-05-14 11:19:27'),
(688, 629, 'normal', 720, 24, '2026-05-14 11:19:53', 0, NULL, 0, 0, '2026-05-14 11:20:26', 1, 0, 18590, 0, '2026-05-13 15:10:03', '2026-05-14 11:20:26'),
(689, 630, 'normal', 720, 24, '2026-05-14 11:20:37', 0, '2026-05-15 12:08:32', 0, 35263, NULL, 0, 0, 17676, 17688, '2026-05-13 15:26:01', '2026-05-15 12:08:32'),
(690, 631, 'normal', 720, 24, '2026-05-14 19:44:42', 0, '2026-05-28 11:56:16', 0, 295868, NULL, 0, 0, 23921, 23947, '2026-05-14 13:06:01', '2026-05-28 11:56:16'),
(691, 632, 'normal', 720, 24, '2026-05-14 19:45:16', 0, '2026-06-03 12:35:37', 0, 427808, NULL, 0, 0, 19395, 19408, '2026-05-14 14:22:01', '2026-06-03 12:35:37'),
(692, 633, 'normal', 720, 24, '2026-05-15 13:13:16', 0, '2026-05-15 13:15:05', 0, 0, NULL, 0, 0, 1154, 1263, '2026-05-15 12:54:02', '2026-05-15 13:15:05'),
(693, 634, 'normal', 720, 24, '2026-05-15 14:03:29', 0, '2026-05-15 15:29:40', 0, 0, NULL, 0, 0, 3328, 8499, '2026-05-15 13:08:01', '2026-05-15 15:29:40'),
(694, 635, 'normal', 720, 24, '2026-05-15 13:13:09', 0, NULL, 0, 0, NULL, 0, 0, 68, 0, '2026-05-15 13:12:01', '2026-05-15 13:13:09'),
(695, 636, 'normal', 720, 24, '2026-05-15 15:30:13', 0, '2026-06-01 11:19:14', 0, 0, '2026-05-15 20:14:04', 1, 0, 6372, 347713, '2026-05-15 13:44:01', '2026-06-01 11:19:14'),
(696, 637, 'normal', 720, 24, '2026-05-15 15:29:50', 0, '2026-05-20 17:17:50', 0, 103670, NULL, 0, 0, 5629, 5639, '2026-05-15 13:56:01', '2026-05-20 17:17:50'),
(697, 638, 'normal', 720, 24, '2026-05-18 11:20:08', 0, '2026-05-26 13:16:38', 0, 201088, '2026-07-13 20:07:47', 1, 0, 1208, 1510, '2026-05-15 20:16:01', '2026-07-13 20:07:47'),
(698, 639, 'normal', 720, 24, '2026-05-18 19:35:06', 0, '2026-05-19 13:16:57', 0, 0, NULL, 0, 0, 19144, 28855, '2026-05-18 14:16:02', '2026-05-19 13:16:57'),
(699, 640, 'normal', 720, 24, '2026-05-19 13:12:28', 0, '2026-05-20 17:00:36', 0, 46075, NULL, 0, 0, 23186, 23199, '2026-05-18 15:46:02', '2026-05-20 17:00:36'),
(700, 641, 'normal', 720, 24, '2026-05-19 14:26:49', 0, NULL, 0, 0, '2026-05-19 14:31:51', 1, 0, 24888, 0, '2026-05-18 16:32:01', '2026-05-19 14:31:51'),
(701, 642, 'normal', 720, 24, '2026-05-19 14:33:45', 0, NULL, 0, 0, '2026-05-19 14:33:45', 1, 0, 5018, 0, '2026-05-19 13:10:07', '2026-05-19 14:33:45'),
(702, 642, 'normal', 480, 24, '2026-05-19 14:33:45', 0, NULL, 0, 0, '2026-05-19 14:33:45', 1, 0, 5018, 0, '2026-05-19 13:10:07', '2026-05-19 14:33:45'),
(703, 643, 'normal', 720, 24, '2026-05-19 14:33:43', 0, NULL, 0, 0, '2026-05-19 14:33:43', 1, 0, 4964, 0, '2026-05-19 13:10:59', '2026-05-19 14:33:43'),
(704, 643, 'normal', 480, 24, '2026-05-19 14:33:43', 0, NULL, 0, 0, '2026-05-19 14:33:43', 1, 0, 4964, 0, '2026-05-19 13:10:59', '2026-05-19 14:33:43'),
(705, 644, 'normal', 720, 24, '2026-05-19 14:30:25', 0, NULL, 0, 0, '2026-05-19 14:30:31', 1, 0, 68, 0, '2026-05-19 14:29:17', '2026-05-19 14:30:31'),
(706, 644, 'normal', 480, 24, '2026-05-19 14:30:25', 0, NULL, 0, 0, '2026-05-19 14:30:31', 1, 0, 68, 0, '2026-05-19 14:29:17', '2026-05-19 14:30:31'),
(707, 645, 'normal', 720, 24, '2026-05-19 19:53:52', 0, '2026-05-22 14:21:36', 0, 76896, NULL, 0, 0, 15110, 15478, '2026-05-19 15:42:02', '2026-05-22 14:21:36'),
(708, 646, 'normal', 720, 24, '2026-05-19 20:02:04', 0, NULL, 0, 0, '2026-05-19 20:02:24', 1, 0, 0, 0, '2026-05-19 20:00:44', '2026-05-19 20:02:24'),
(709, 646, 'normal', 480, 24, '2026-05-19 20:02:04', 0, NULL, 0, 0, '2026-05-19 20:02:24', 1, 0, 0, 0, '2026-05-19 20:00:44', '2026-05-19 20:02:24'),
(710, 647, 'normal', 720, 24, '2026-05-20 12:38:14', 0, '2026-05-20 13:04:47', 0, 1570, NULL, 0, 0, 2413, 2436, '2026-05-20 11:58:01', '2026-05-20 13:04:47'),
(711, 648, 'normal', 720, 24, '2026-05-20 13:29:12', 0, '2026-06-03 12:33:58', 0, 320686, NULL, 0, 0, 1305, 1305, '2026-05-20 13:07:27', '2026-06-03 12:33:58'),
(712, 648, 'normal', 480, 24, '2026-05-20 13:29:12', 0, '2026-06-03 12:33:58', 0, 320686, NULL, 0, 0, 1305, 1305, '2026-05-20 13:07:27', '2026-06-03 12:33:58'),
(713, 649, 'normal', 720, 24, '2026-05-22 12:58:55', 0, '2026-06-09 21:03:25', 0, 414065, NULL, 0, 0, 28494, 28494, '2026-05-21 14:04:01', '2026-06-09 21:03:25'),
(714, 650, 'normal', 720, 24, '2026-05-21 17:28:40', 0, '2026-05-21 17:31:44', 0, 0, NULL, 0, 0, 6038, 6222, '2026-05-21 15:48:02', '2026-05-21 17:31:44'),
(715, 651, 'normal', 720, 24, '2026-05-22 12:59:07', 0, '2026-05-22 14:31:08', 0, 5521, NULL, 0, 0, 11585, 11585, '2026-05-21 18:46:02', '2026-05-22 14:31:08'),
(716, 652, 'normal', 720, 24, '2026-05-22 12:53:12', 0, '2026-05-22 12:58:44', 0, 0, NULL, 0, 0, 10870, 11202, '2026-05-21 18:52:02', '2026-05-22 12:58:44'),
(717, 653, 'normal', 720, 24, '2026-05-22 12:49:30', 0, '2026-05-22 12:49:47', 0, 0, NULL, 0, 0, 168, 185, '2026-05-22 12:46:42', '2026-05-22 12:49:47'),
(718, 653, 'normal', 480, 24, '2026-05-22 12:49:30', 0, '2026-05-22 12:49:47', 0, 0, NULL, 0, 0, 168, 185, '2026-05-22 12:46:42', '2026-05-22 12:49:47'),
(719, 654, 'normal', 720, 24, '2026-05-22 13:44:45', 0, '2026-05-22 13:44:45', 0, 0, NULL, 0, 0, 163, 163, '2026-05-22 13:42:02', '2026-05-22 13:44:45'),
(720, 655, 'normal', 720, 24, '2026-05-22 14:35:29', 0, '2026-06-15 16:56:20', 0, 526834, NULL, 0, 0, 808, 825, '2026-05-22 14:22:01', '2026-06-15 16:56:20'),
(721, 656, 'normal', 720, 24, '2026-05-22 19:38:29', 0, '2026-06-01 13:06:27', 0, 170856, NULL, 0, 0, 5067, 5089, '2026-05-22 18:14:02', '2026-06-01 13:06:27'),
(722, 657, 'normal', 720, 24, '2026-05-22 19:50:10', 0, '2026-05-22 20:02:00', 0, 574, NULL, 0, 0, 489, 505, '2026-05-22 19:42:01', '2026-05-22 20:02:00'),
(723, 658, 'normal', 720, 24, '2026-05-25 13:54:21', 0, '2026-06-22 13:45:08', 0, 470435, NULL, 0, 0, 4694, 181706, '2026-05-25 12:36:07', '2026-06-22 13:45:08'),
(724, 659, 'normal', 720, 24, '2026-05-25 13:55:37', 0, '2026-05-25 13:58:00', 0, 112, NULL, 0, 0, 4534, 4565, '2026-05-25 12:40:03', '2026-05-25 13:58:00'),
(725, 660, 'normal', 720, 24, '2026-05-26 12:44:09', 0, '2026-05-26 12:52:10', 0, 0, NULL, 0, 0, 26763, 27244, '2026-05-25 14:18:06', '2026-05-26 12:52:10'),
(726, 661, 'normal', 720, 24, '2026-05-26 12:44:15', 0, '2026-05-26 13:06:15', 0, 0, NULL, 0, 0, 734, 2054, '2026-05-26 12:32:01', '2026-05-26 13:06:15'),
(727, 662, 'normal', 720, 24, '2026-06-01 11:15:10', 0, '2026-06-01 12:05:00', 0, 2976, '2026-06-01 12:09:07', 1, 0, 88149, 88163, '2026-05-27 13:46:01', '2026-06-01 12:09:07'),
(728, 663, 'normal', 720, 24, '2026-06-01 11:11:22', 0, '2026-06-01 11:12:07', 0, 0, NULL, 0, 0, 47961, 48006, '2026-05-28 15:52:01', '2026-06-01 11:12:07'),
(729, 664, 'normal', 720, 24, '2026-06-01 11:13:15', 0, '2026-06-01 12:05:23', 0, 3076, NULL, 0, 0, 19634, 19686, '2026-05-29 14:46:01', '2026-06-01 12:05:23'),
(730, 665, 'normal', 720, 24, '2026-06-02 11:56:44', 0, '2026-06-19 20:18:23', 0, 20654, NULL, 0, 0, 17201, 446743, '2026-06-01 16:10:03', '2026-06-19 20:18:23'),
(731, 666, 'normal', 720, 24, '2026-06-01 19:58:52', 0, '2026-06-01 19:58:52', 0, 0, NULL, 0, 0, 7971, 7971, '2026-06-01 17:46:01', '2026-06-01 19:58:52'),
(732, 667, 'normal', 720, 24, '2026-06-03 11:55:35', 0, '2026-06-03 11:55:35', 0, 0, NULL, 0, 0, 27213, 27213, '2026-06-02 13:22:02', '2026-06-03 11:55:35'),
(733, 668, 'normal', 720, 24, '2026-06-03 17:16:18', 0, '2026-06-11 11:43:01', 0, 170707, NULL, 0, 0, 5175, 8871, '2026-06-03 15:50:03', '2026-06-11 11:43:01'),
(734, 669, 'normal', 720, 24, '2026-06-03 20:06:36', 0, '2026-06-10 16:03:59', 0, 147839, NULL, 0, 0, 4078, 4078, '2026-06-03 18:52:02', '2026-06-10 16:03:59'),
(735, 670, 'normal', 720, 24, '2026-06-08 11:54:52', 0, '2026-06-23 12:53:54', 0, 0, '2026-06-08 12:06:18', 1, 0, 1131, 361073, '2026-06-08 11:36:01', '2026-06-23 12:53:54'),
(736, 671, 'normal', 720, 24, '2026-06-08 13:20:02', 0, '2026-06-08 13:20:59', 0, 0, NULL, 0, 0, 19, 76, '2026-06-08 13:19:43', '2026-06-08 13:20:59'),
(737, 671, 'normal', 480, 24, '2026-06-08 13:20:02', 0, '2026-06-08 13:20:59', 0, 0, NULL, 0, 0, 19, 76, '2026-06-08 13:19:43', '2026-06-08 13:20:59'),
(738, 672, 'normal', 720, 24, '2026-06-09 21:03:37', 0, '2026-06-10 13:40:05', 0, 9605, NULL, 0, 0, 47638, 47638, '2026-06-08 15:46:02', '2026-06-10 13:40:05'),
(739, 673, 'normal', 720, 24, '2026-06-09 21:03:59', 0, '2026-06-10 12:04:37', 0, 3877, NULL, 0, 0, 20279, 20279, '2026-06-09 14:22:01', '2026-06-10 12:04:37'),
(740, 674, 'normal', 720, 24, '2026-06-10 11:53:40', 0, '2026-06-10 11:58:52', 0, 0, NULL, 0, 0, 3220, 3532, '2026-06-10 01:08:01', '2026-06-10 11:58:52'),
(741, 675, 'normal', 720, 24, '2026-06-10 13:40:58', 0, '2026-06-10 13:43:29', 0, 0, NULL, 0, 0, 1017, 1168, '2026-06-10 13:24:01', '2026-06-10 13:43:29'),
(742, 676, 'normal', 720, 24, '2026-06-10 13:44:02', 0, '2026-06-10 15:18:32', 0, 5618, NULL, 0, 0, 601, 653, '2026-06-10 13:34:01', '2026-06-10 15:18:32'),
(743, 677, 'normal', 720, 24, '2026-06-10 14:06:47', 0, '2026-06-10 15:20:21', 0, 4397, NULL, 0, 0, 286, 303, '2026-06-10 14:02:01', '2026-06-10 15:20:21'),
(744, 678, 'normal', 720, 24, '2026-06-10 15:20:53', 0, '2026-06-10 16:02:11', 0, 2464, NULL, 0, 0, 772, 786, '2026-06-10 15:08:01', '2026-06-10 16:02:11'),
(745, 679, 'normal', 720, 24, '2026-06-12 17:04:28', 0, '2026-06-25 20:38:39', 0, 81729, NULL, 0, 0, 48146, 268549, '2026-06-11 12:42:02', '2026-06-25 20:38:39'),
(746, 680, 'normal', 720, 24, '2026-06-12 17:04:55', 0, '2026-07-03 20:23:49', 0, 496482, NULL, 0, 0, 44213, 44236, '2026-06-11 13:48:02', '2026-07-03 20:23:49'),
(747, 681, 'normal', 720, 24, '2026-06-12 17:05:31', 0, '2026-06-15 17:08:46', 0, 31531, NULL, 0, 0, 0, 29234, '2026-06-11 18:16:01', '2026-06-15 17:08:46'),
(748, 682, 'normal', 720, 24, '2026-06-15 17:13:40', 0, NULL, 0, 0, '2026-06-15 17:54:27', 1, 0, 21098, 0, '2026-06-15 11:22:02', '2026-06-15 17:54:27'),
(749, 683, 'normal', 720, 24, '2026-06-15 17:54:33', 0, '2026-06-15 18:07:00', 0, 0, NULL, 0, 0, 9751, 10498, '2026-06-15 15:12:02', '2026-06-15 18:07:00'),
(750, 684, 'normal', 720, 24, '2026-06-16 12:17:55', 0, '2026-06-16 21:08:48', 0, 21859, NULL, 0, 0, 11153, 17019, '2026-06-15 18:12:02', '2026-06-16 21:08:48'),
(751, 685, 'normal', 720, 24, '2026-06-16 12:20:42', 0, '2026-06-22 12:56:21', 0, 131634, NULL, 0, 0, 9280, 9385, '2026-06-15 18:46:02', '2026-06-22 12:56:21'),
(752, 686, 'normal', 720, 24, '2026-06-16 18:33:26', 0, '2026-06-16 18:37:26', 0, 0, NULL, 0, 0, 15202, 15442, '2026-06-16 14:20:04', '2026-06-16 18:37:26'),
(753, 687, 'normal', 720, 24, '2026-06-16 18:51:57', 0, '2026-06-22 17:52:38', 0, 126020, NULL, 0, 0, 14395, 14416, '2026-06-16 14:52:02', '2026-06-22 17:52:38'),
(754, 688, 'normal', 720, 24, '2026-06-16 18:52:43', 0, '2026-06-23 15:43:29', 0, 150617, NULL, 0, 0, 2081, 2110, '2026-06-16 18:18:02', '2026-06-23 15:43:29'),
(755, 689, 'normal', 720, 24, '2026-06-17 11:47:13', 0, '2026-06-19 14:15:05', 0, 73620, NULL, 0, 0, 2833, 2885, '2026-06-16 20:08:02', '2026-06-19 14:15:05'),
(756, 690, 'normal', 720, 24, '2026-06-17 11:47:30', 0, '2026-06-24 12:06:58', 0, 163130, NULL, 0, 0, 2850, 2888, '2026-06-16 20:10:07', '2026-06-24 12:06:58'),
(757, 691, 'normal', 720, 24, '2026-06-18 11:32:26', 0, '2026-07-02 13:06:04', 0, 0, '2026-06-19 20:14:55', 1, 0, 28584, 358202, '2026-06-17 12:36:02', '2026-07-02 13:06:04'),
(758, 692, 'normal', 720, 24, '2026-06-18 11:35:15', 0, '2026-06-18 13:54:46', 0, 0, NULL, 0, 0, 11353, 19724, '2026-06-17 17:26:02', '2026-06-18 13:54:46'),
(759, 693, 'normal', 720, 24, '2026-06-19 20:18:35', 0, '2026-06-22 17:48:59', 0, 24539, NULL, 0, 0, 0, 0, '2026-06-19 20:06:02', '2026-06-22 17:48:59'),
(760, 694, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-06-22 16:42:02', '2026-06-22 16:42:02'),
(761, 695, 'normal', 720, 24, '2026-06-23 15:17:17', 0, NULL, 0, 0, '2026-06-23 15:17:30', 1, 0, 7515, 0, '2026-06-23 13:12:02', '2026-06-23 15:17:30'),
(762, 696, 'normal', 720, 24, '2026-06-23 14:50:31', 0, '2026-06-23 20:24:04', 0, 18542, NULL, 0, 0, 149, 176, '2026-06-23 14:48:02', '2026-06-23 20:24:04'),
(763, 697, 'normal', 720, 24, '2026-06-23 20:07:13', 0, '2026-06-23 20:23:39', 0, 0, NULL, 0, 0, 0, 0, '2026-06-23 20:00:10', '2026-06-23 20:23:39'),
(764, 698, 'normal', 720, 24, '2026-06-26 12:41:39', 0, '2026-06-26 12:42:51', 0, 60, NULL, 0, 0, 65137, 65149, '2026-06-24 12:36:02', '2026-06-26 12:42:51'),
(765, 699, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-06-24 15:48:02', '2026-06-24 15:48:02'),
(766, 700, 'normal', 720, 24, '2026-06-26 12:45:11', 0, '2026-07-03 12:46:21', 0, 162047, NULL, 0, 0, 31397, 31420, '2026-06-25 13:01:54', '2026-07-03 12:46:21'),
(767, 700, 'normal', 480, 24, '2026-06-26 12:45:11', 0, '2026-07-03 12:46:21', 0, 162047, NULL, 0, 0, 31397, 31420, '2026-06-25 13:01:54', '2026-07-03 12:46:21'),
(768, 701, 'normal', 720, 24, '2026-06-26 12:45:47', 0, '2026-07-02 17:26:08', 0, 146414, NULL, 0, 0, 31385, 31392, '2026-06-25 13:02:42', '2026-07-02 17:26:08'),
(769, 701, 'normal', 480, 24, '2026-06-26 12:45:47', 0, '2026-07-02 17:26:08', 0, 146414, NULL, 0, 0, 31385, 31392, '2026-06-25 13:02:42', '2026-07-02 17:26:08'),
(770, 702, 'normal', 720, 24, '2026-06-26 12:46:13', 0, '2026-07-03 20:18:37', 0, 188008, NULL, 0, 0, 27963, 27982, '2026-06-25 14:00:10', '2026-07-03 20:18:37'),
(771, 703, 'normal', 720, 24, '2026-06-26 12:46:50', 0, NULL, 0, 0, '2026-06-26 12:47:23', 1, 0, 20328, 0, '2026-06-25 16:08:02', '2026-06-26 12:47:23'),
(772, 704, 'normal', 720, 24, '2026-06-26 12:51:20', 0, NULL, 0, 0, '2026-06-26 12:51:33', 1, 0, 20238, 0, '2026-06-25 16:14:02', '2026-06-26 12:51:33'),
(773, 705, 'normal', 720, 24, NULL, 0, NULL, 0, 0, NULL, 0, 0, 0, 0, '2026-06-26 12:18:02', '2026-06-26 12:18:02'),
(774, 706, 'normal', 720, 24, '2026-06-30 20:44:24', 0, '2026-07-02 17:25:24', 0, 46727, NULL, 0, 0, 84358, 93155, '2026-06-26 14:34:02', '2026-07-02 17:25:24'),
(775, 707, 'normal', 720, 24, '2026-06-30 20:45:28', 0, '2026-06-30 20:47:46', 0, 0, NULL, 0, 0, 63958, 63958, '2026-06-29 11:14:02', '2026-06-30 20:47:46'),
(776, 708, 'normal', 720, 24, '2026-06-30 20:56:06', 0, '2026-07-02 13:07:31', 0, 0, '2026-07-01 18:08:43', 1, 0, 40796, 80847, '2026-06-29 17:40:04', '2026-07-02 13:07:31'),
(777, 709, 'normal', 720, 24, '2026-06-30 21:04:06', 0, NULL, 0, 0, '2026-07-02 13:21:25', 1, 0, 30958, 0, '2026-06-30 11:24:02', '2026-07-02 13:21:25'),
(778, 710, 'normal', 720, 24, '2026-06-30 21:04:32', 0, '2026-07-03 20:24:42', 0, 97200, NULL, 0, 0, 0, 0, '2026-06-30 20:43:45', '2026-07-03 20:24:42'),
(779, 710, 'normal', 480, 24, '2026-06-30 21:04:32', 0, '2026-07-03 20:24:42', 0, 97200, NULL, 0, 0, 0, 0, '2026-06-30 20:43:45', '2026-07-03 20:24:42'),
(780, 711, 'normal', 720, 24, '2026-07-01 19:14:59', 0, NULL, 0, 0, '2026-07-01 19:15:10', 1, 0, 17643, 0, '2026-07-01 14:20:56', '2026-07-01 19:15:10'),
(781, 711, 'normal', 480, 24, '2026-07-01 19:14:59', 0, NULL, 0, 0, '2026-07-01 19:15:10', 1, 0, 17643, 0, '2026-07-01 14:20:56', '2026-07-01 19:15:10'),
(782, 712, 'normal', 720, 24, '2026-07-01 19:20:55', 0, '2026-07-02 17:24:17', 0, 24669, NULL, 0, 0, 15533, 16266, '2026-07-01 15:02:02', '2026-07-02 17:24:17'),
(783, 713, 'normal', 720, 24, '2026-07-01 19:15:21', 0, '2026-07-01 19:19:58', 0, 262, NULL, 0, 0, 12916, 12931, '2026-07-01 15:40:05', '2026-07-01 19:19:58'),
(784, 714, 'normal', 720, 24, '2026-07-02 12:42:47', 0, '2026-07-02 17:21:20', 0, 16699, NULL, 0, 0, 13485, 13499, '2026-07-01 17:58:02', '2026-07-02 17:21:20'),
(785, 715, 'normal', 720, 24, '2026-07-02 12:43:16', 0, '2026-07-10 19:50:56', 0, 0, '2026-07-02 12:43:27', 1, 0, 11714, 231774, '2026-07-01 18:28:02', '2026-07-10 19:50:56'),
(786, 716, 'normal', 720, 24, '2026-07-02 12:43:40', 0, '2026-07-02 17:22:30', 0, 0, NULL, 0, 0, 6220, 22950, '2026-07-01 20:56:02', '2026-07-02 17:22:30'),
(787, 717, 'normal', 720, 24, '2026-07-02 13:40:36', 0, '2026-07-02 17:23:10', 0, 13293, NULL, 0, 0, 2674, 2735, '2026-07-02 12:56:02', '2026-07-02 17:23:10'),
(788, 718, 'normal', 720, 24, '2026-07-02 13:45:15', 0, '2026-07-03 20:06:19', 0, 53734, NULL, 0, 0, 1753, 2904, '2026-07-02 13:16:02', '2026-07-03 20:06:19'),
(789, 719, 'normal', 720, 24, '2026-07-02 20:11:08', 0, NULL, 0, 0, '2026-07-02 20:11:27', 1, 0, 21958, 0, '2026-07-02 13:54:02', '2026-07-02 20:11:27'),
(790, 720, 'normal', 720, 24, '2026-07-03 12:53:25', 0, '2026-07-03 19:44:49', 0, 0, '2026-07-03 13:05:00', 1, 0, 22643, 47327, '2026-07-02 15:36:02', '2026-07-03 19:44:49'),
(791, 721, 'normal', 720, 24, '2026-07-02 20:13:45', 0, NULL, 0, 0, '2026-07-02 20:14:09', 1, 0, 7792, 0, '2026-07-02 17:50:08', '2026-07-02 20:14:09'),
(792, 722, 'normal', 720, 24, '2026-07-02 20:18:00', 0, NULL, 0, 0, '2026-07-03 19:48:02', 1, 0, 4918, 0, '2026-07-02 18:38:02', '2026-07-03 19:48:02'),
(793, 723, 'normal', 720, 24, '2026-07-03 20:11:05', 0, '2026-07-06 13:09:41', 0, 7781, NULL, 0, 0, 25438, 25438, '2026-07-03 12:56:02', '2026-07-06 13:09:41'),
(794, 724, 'normal', 720, 24, '2026-07-03 19:46:37', 0, '2026-07-03 19:46:56', 0, 0, NULL, 0, 0, 15874, 15893, '2026-07-03 15:22:03', '2026-07-03 19:46:56'),
(795, 725, 'normal', 720, 24, '2026-07-03 19:45:56', 0, '2026-07-03 19:46:23', 0, 0, NULL, 0, 0, 13541, 13568, '2026-07-03 16:00:15', '2026-07-03 19:46:23'),
(796, 726, 'normal', 720, 24, '2026-07-03 20:11:53', 0, '2026-07-06 13:08:34', 0, 0, '2026-07-03 20:12:07', 1, 0, 6478, 14192, '2026-07-03 18:12:02', '2026-07-06 13:08:34'),
(797, 727, 'normal', 720, 24, '2026-07-03 19:45:43', 0, '2026-07-03 19:45:43', 0, 0, NULL, 0, 0, 1661, 1661, '2026-07-03 19:18:02', '2026-07-03 19:45:43'),
(798, 728, 'normal', 720, 24, '2026-07-06 14:34:00', 0, '2026-07-06 14:34:00', 0, 0, NULL, 0, 0, 2398, 2398, '2026-07-06 13:54:02', '2026-07-06 14:34:00'),
(799, 729, 'normal', 720, 24, '2026-07-06 17:20:48', 0, '2026-07-06 17:20:48', 0, 0, NULL, 0, 0, 9766, 9766, '2026-07-06 14:38:02', '2026-07-06 17:20:48'),
(800, 730, 'normal', 720, 24, '2026-07-08 19:56:42', 0, '2026-07-08 19:56:42', 0, 0, NULL, 0, 0, 68560, 68560, '2026-07-06 18:54:02', '2026-07-08 19:56:42'),
(801, 731, 'normal', 720, 24, '2026-07-08 19:57:02', 0, NULL, 0, 0, '2026-07-08 19:57:39', 1, 0, 11940, 0, '2026-07-08 16:38:02', '2026-07-08 19:57:39'),
(802, 732, 'normal', 720, 24, '2026-07-08 19:57:09', 0, NULL, 0, 0, '2026-07-08 19:57:36', 1, 0, 2107, 0, '2026-07-08 19:22:02', '2026-07-08 19:57:36'),
(803, 733, 'normal', 720, 24, '2026-07-10 19:48:50', 0, NULL, 0, 0, '2026-07-10 19:48:50', 1, 0, 55608, 0, '2026-07-09 13:22:02', '2026-07-10 19:48:50'),
(804, 734, 'normal', 720, 24, '2026-07-10 19:48:45', 0, '2026-07-13 19:55:01', 0, 32776, NULL, 0, 0, 54163, 54163, '2026-07-09 13:46:02', '2026-07-13 19:55:01'),
(805, 735, 'normal', 720, 24, '2026-07-10 19:48:42', 0, '2026-07-13 14:46:17', 0, 14255, NULL, 0, 0, 45880, 45880, '2026-07-09 16:04:02', '2026-07-13 14:46:17'),
(806, 736, 'normal', 720, 24, '2026-07-09 17:01:38', 0, '2026-07-09 17:01:38', 0, 0, NULL, 0, 0, 3090, 3090, '2026-07-09 16:10:08', '2026-07-09 17:01:38'),
(807, 737, 'normal', 720, 24, '2026-07-13 12:27:59', 0, '2026-07-13 14:42:55', 0, 8080, NULL, 0, 0, 20037, 20053, '2026-07-10 15:54:02', '2026-07-13 14:42:55'),
(808, 738, 'normal', 720, 24, '2026-07-13 14:50:07', 0, '2026-07-13 14:51:02', 0, 0, NULL, 0, 0, 8165, 8220, '2026-07-13 12:34:02', '2026-07-13 14:51:02'),
(809, 739, 'normal', 720, 24, '2026-07-13 14:51:52', 0, '2026-07-13 14:55:11', 0, 0, NULL, 0, 0, 1670, 1869, '2026-07-13 14:24:02', '2026-07-13 14:55:11'),
(810, 740, 'normal', 720, 24, '2026-07-13 19:50:51', 0, '2026-07-13 19:54:11', 0, 0, NULL, 0, 0, 17089, 17289, '2026-07-13 15:06:02', '2026-07-13 19:54:11');

-- --------------------------------------------------------

--
-- Estrutura da tabela `ticket_status`
--

CREATE TABLE IF NOT EXISTS `ticket_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `descricao` text,
  `ordem` int(11) NOT NULL,
  `congela_sla` tinyint(1) DEFAULT '0',
  `cor` varchar(7) DEFAULT '#6c757d',
  `ativo` tinyint(1) DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `ticket_status`
--

INSERT INTO `ticket_status` (`id`, `nome`, `descricao`, `ordem`, `congela_sla`, `cor`, `ativo`, `criado_em`) VALUES
(1, 'novo', 'Ticket recém criado, aguardando atribuição ou início de atendimento', 1, 0, '#f79226', 1, '2025-10-02 01:26:23'),
(2, 'em_atendimento', 'O ticket está sendo analisado ou trabalhado por um atendente do helpdesk', 2, 0, '#17a2b8', 1, '2025-10-02 01:26:23'),
(3, 'aguardando_resposta', 'O atendente precisa de mais informa├º├Áes do usu├írio/cliente para continuar', 4, 1, '#ffc107', 1, '2025-10-02 01:26:23'),
(4, 'aguardando_terceiros', 'Quando depende de outro setor ou fornecedor externo para resolução', 5, 1, '#6f42c1', 1, '2025-10-02 01:26:23'),
(6, 'resolvido', 'A solução foi aplicada, mas aguarda a confirmação do usuário/cliente para encerrar', 8, 1, '#28a745', 1, '2025-10-02 01:26:23'),
(7, 'encerrado', 'O problema foi solucionado, e o atendimento foi oficialmente conclu├¡do', 9, 0, '#6c757d', 1, '2025-10-02 01:26:23'),
(8, 'cancelado', 'O ticket foi aberto por engano, ou a solicitação foi retirada pelo solicitante', 10, 0, '#dc3545', 1, '2025-10-02 01:26:23'),
(9, 'reaberto', 'Quando um ticket já encerrado ou resolvido volta a apresentar problemas e precisa ser tratado novamente', 11, 0, '#e83e8c', 1, '2025-10-02 01:26:23'),
(11, 'aguardando_data', 'Aguardando data agendada para retomar o atendimento', 7, 1, '#9c27b0', 1, '2025-10-20 19:46:55'),
(12, 'aguardando', 'Ticket na fila de atendimento ou aguardando ações gerais', 3, 1, '#ffc107', 1, '2025-11-28 15:23:50'),
(13, 'nova_resposta', 'Cliente respondeu ao ticket', 12, 0, '#17a2b8', 1, '2026-03-08 01:24:02');

-- --------------------------------------------------------

--
-- Estrutura da tabela `tokens_recuperacao`
--

CREATE TABLE IF NOT EXISTS `tokens_recuperacao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expira_em` datetime NOT NULL,
  `usado` tinyint(1) DEFAULT '0',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `usuarios`
--

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `anydesk_id` varchar(20) DEFAULT NULL,
  `tipo` enum('cliente','agente','admin') DEFAULT 'cliente',
  `ativo` tinyint(1) DEFAULT '1',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `senha` varchar(255) DEFAULT '123456',
  `avatar` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expira` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_anydesk_id` (`anydesk_id`)
) ENGINE=InnoDB AUTO_INCREMENT=105 DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `usuarios`
--

INSERT INTO `usuarios` (`id`, `nome`, `email`, `anydesk_id`, `tipo`, `ativo`, `criado_em`, `senha`, `avatar`, `reset_token`, `reset_token_expira`) VALUES
(1, 'Hugo Miranda', 'hugo.miranda@crprs.org.br', NULL, 'admin', 1, '2025-09-16 01:26:43', '$2y$10$t750aqZREpa3p948qmIWROtIs8bIrvYf/uD41AkjZr7matuT2vgqa', 'avatar_1_1772995287.png', NULL, NULL),
(2, 'Rafael Rueda', 'rafael.rueda@crprs.org.br', NULL, 'admin', 1, '2025-09-19 22:47:14', '$2y$10$WqmTljR.CPSYKOWN2DZGCOtDjH6o6LfNC2ciiNo.pRLdKuWA7PD92', NULL, NULL, NULL),
(3, 'Mateus Pereira', 'mateus.pereira@crprs.org.br', NULL, 'admin', 0, '2025-09-19 22:47:14', '$2y$10$9UgCUpHB1t6RiGhblG6Bwu.cJKxHmfgmBiwS1uYRudUKxodVzWHA.', NULL, NULL, NULL),
(4, 'administrador', 'administrador@crprs.org.br', NULL, 'admin', 1, '2025-10-30 16:50:07', '$2y$10$srHzgxzfq3vF9wOY/kmoMeCcURCxacCDCNMdG4psraNgkx1S3MacO', NULL, NULL, NULL),
(5, 'marcelo.teixeira', 'marcelo.teixeira@crprs.org.br', NULL, 'cliente', 1, '2025-10-30 16:52:55', '123456', NULL, NULL, NULL),
(6, 'andre.bertao', 'andre.bertao@crprs.org.br', NULL, 'cliente', 1, '2025-10-30 16:53:00', '123456', NULL, NULL, NULL),
(7, 'guilherme.roesler', 'guilherme.roesler@crprs.org.br', NULL, 'cliente', 1, '2025-10-30 17:18:01', '123456', NULL, NULL, NULL),
(8, 'geovana.ferreira', 'geovana.ferreira@crprs.org.br', NULL, 'cliente', 1, '2025-10-30 19:00:04', '123456', NULL, NULL, NULL),
(9, 'direcao', 'direcao@crprs.org.br', NULL, 'cliente', 1, '2025-10-30 19:08:01', '123456', NULL, NULL, NULL),
(10, 'nadia.miola', 'nadia.miola@crprs.org.br', NULL, 'cliente', 1, '2025-10-31 12:22:02', '123456', NULL, NULL, NULL),
(11, 'hanna.fagundes', 'hanna.fagundes@crprs.org.br', NULL, 'cliente', 1, '2025-10-31 15:02:02', '123456', NULL, NULL, NULL),
(12, 'cicero.bortolini', 'cicero.bortolini@crprs.org.br', NULL, 'cliente', 1, '2025-11-04 12:00:04', '123456', NULL, NULL, NULL),
(13, 'luis.silva', 'luis.silva@crprs.org.br', NULL, 'cliente', 1, '2025-11-04 13:22:01', '123456', NULL, NULL, NULL),
(14, 'flavia.mattos', 'flavia.mattos@crprs.org.br', NULL, 'cliente', 1, '2025-11-04 17:46:01', '123456', NULL, NULL, NULL),
(15, 'cadastro2', 'cadastro2@crprs.org.br', NULL, 'cliente', 1, '2025-11-04 18:18:01', '123456', NULL, NULL, NULL),
(16, 'keli.rossi', 'keli.rossi@crprs.org.br', NULL, 'cliente', 1, '2025-11-04 20:02:01', '123456', NULL, NULL, NULL),
(17, 'aline.victorino', 'aline.victorino@crprs.org.br', NULL, 'cliente', 1, '2025-11-05 15:12:01', '123456', NULL, NULL, NULL),
(18, 'marcia.castro', 'marcia.castro@crprs.org.br', NULL, 'cliente', 1, '2025-11-05 16:12:01', '123456', NULL, NULL, NULL),
(19, 'larissa.pierry', 'larissa.pierry@crprs.org.br', NULL, 'cliente', 1, '2025-11-06 14:34:02', '123456', NULL, NULL, NULL),
(20, 'menelique', 'menelique@hotmail.com', NULL, 'cliente', 1, '2025-11-06 15:10:02', '123456', NULL, NULL, NULL),
(21, 'cadastro', 'cadastro@crprs.org.br', NULL, 'cliente', 1, '2025-11-06 20:20:02', '123456', NULL, NULL, NULL),
(22, 'postmaster', 'postmaster@mail49232.delibird0004.locaweb.com.br', NULL, 'cliente', 1, '2025-11-11 14:06:01', '123456', NULL, NULL, NULL),
(23, 'postmaster', 'postmaster@mail49204.delibird0004.locaweb.com.br', NULL, 'cliente', 1, '2025-11-11 14:08:01', '123456', NULL, NULL, NULL),
(24, 'orientec', 'orientec@crprs.org.br', NULL, 'cliente', 1, '2025-11-11 18:26:02', '123456', NULL, NULL, NULL),
(25, 'celia.vitt', 'celia.vitt@crprs.org.br', NULL, 'cliente', 1, '2025-11-13 15:06:01', '123456', NULL, NULL, NULL),
(26, 'renata.severgnini', 'renata.severgnini@crprs.org.br', NULL, 'cliente', 1, '2025-11-13 19:36:01', '123456', NULL, NULL, NULL),
(27, 'vargasclaudeonorvargas', 'vargasclaudeonorvargas@gmail.com', NULL, 'cliente', 1, '2025-11-14 22:44:01', '123456', NULL, NULL, NULL),
(28, 'evelise.campos', 'evelise.campos@crprs.org.br', NULL, 'cliente', 1, '2025-11-17 14:49:09', '123456', NULL, NULL, NULL),
(29, 'patricia.cristina', 'patricia.cristina@crprs.org.br', NULL, 'cliente', 1, '2025-11-18 12:56:01', '123456', NULL, NULL, NULL),
(30, 'rh', 'rh@crprs.org.br', NULL, 'cliente', 1, '2025-11-19 10:23:28', '123456', NULL, NULL, NULL),
(31, 'info', 'info@webmail-seguro.com.br', NULL, 'cliente', 1, '2025-11-20 03:29:28', '123456', NULL, NULL, NULL),
(32, 'processo', 'processo@crprs.org.br', NULL, 'cliente', 1, '2025-11-24 14:30:03', '123456', NULL, NULL, NULL),
(33, 'danuza.daudt', 'danuza.daudt@crprs.org.br', NULL, 'cliente', 1, '2025-11-24 15:40:02', '123456', NULL, NULL, NULL),
(34, 'valquiriapsico', 'valquiriapsico@live.com', NULL, 'cliente', 1, '2025-11-25 17:55:02', '123456', NULL, NULL, NULL),
(35, 'ouvidoria', 'ouvidoria@crprs.org.br', NULL, 'cliente', 1, '2025-11-25 19:25:02', '123456', NULL, NULL, NULL),
(36, 'patricia.silva', 'patricia.silva@crprs.org.br', NULL, 'cliente', 1, '2025-11-26 13:20:02', '123456', NULL, NULL, NULL),
(37, 'caroline.terragno', 'caroline.terragno@crprs.org.br', NULL, 'cliente', 1, '2025-11-28 11:50:02', '123456', NULL, NULL, NULL),
(38, 'liliane.ores', 'liliane.ores@gmail.com', NULL, 'cliente', 1, '2025-11-28 14:15:02', '123456', NULL, NULL, NULL),
(39, 'lucio', 'lucio@crprs.org.br', NULL, 'cliente', 1, '2025-11-28 16:30:03', '123456', NULL, NULL, NULL),
(40, 'antonieta.bridi', 'antonieta.bridi@crprs.org.br', NULL, 'cliente', 1, '2025-12-03 15:35:43', '123456', NULL, NULL, NULL),
(41, 'helenanunes.crprs', 'helenanunes.crprs@gmail.com', NULL, 'cliente', 1, '2025-12-08 17:08:45', '123456', NULL, NULL, NULL),
(42, 'kwala.rosa', 'kwala.rosa@crprs.org.br', NULL, 'cliente', 1, '2025-12-12 13:35:01', '123456', NULL, NULL, NULL),
(43, 'adri', 'adri@crprs.org.br', NULL, 'cliente', 1, '2025-12-12 13:50:02', '123456', NULL, NULL, NULL),
(44, 'esabhe.nunes', 'esabhe.nunes@gmail.com', NULL, 'cliente', 1, '2025-12-15 19:38:01', '123456', NULL, NULL, NULL),
(45, 'vitoriagappel', 'vitoriagappel@gmail.com', NULL, 'cliente', 1, '2025-12-16 20:30:03', '123456', NULL, NULL, NULL),
(46, 'aacamila.psico', 'aacamila.psico@gmail.com', NULL, 'cliente', 1, '2025-12-18 13:42:01', '123456', NULL, NULL, NULL),
(47, 'suporte', 'suporte@crprs.org.br', NULL, 'cliente', 1, '2025-12-19 15:34:01', '123456', NULL, NULL, NULL),
(48, 'deborah.daitschman', 'deborah.daitschman@hotmail.com', NULL, 'cliente', 1, '2026-01-05 13:48:01', '123456', NULL, NULL, NULL),
(49, 'cadastro5', 'cadastro5@crprs.org.br', NULL, 'cliente', 1, '2026-01-08 15:24:01', '123456', NULL, NULL, NULL),
(50, 'cadastro3', 'cadastro3@crprs.org.br', NULL, 'cliente', 1, '2026-01-09 14:12:01', '123456', NULL, NULL, NULL),
(51, 'marcia.souza', 'marcia.souza@crprs.org.br', NULL, 'cliente', 1, '2026-01-09 20:42:01', '123456', NULL, NULL, NULL),
(52, 'anap.eid', 'anap.eid@gmail.com', NULL, 'cliente', 1, '2026-01-14 14:22:01', '123456', NULL, NULL, NULL),
(53, 'diego_lima40', 'diego_lima40@mzg.distribuidoralopes.net', NULL, 'cliente', 1, '2026-01-19 06:32:01', '123456', NULL, NULL, NULL),
(54, 'postmaster', 'postmaster@delibird0001-1.locaweb.com.br', NULL, 'cliente', 1, '2026-01-19 06:34:01', '123456', NULL, NULL, NULL),
(55, 'postmaster', 'postmaster@delibird0001-n-211.locaweb.com.br', NULL, 'cliente', 1, '2026-01-19 06:36:01', '123456', NULL, NULL, NULL),
(56, 'leticia.giannechini', 'leticia.giannechini@crprs.org.br', NULL, 'cliente', 1, '2026-01-23 12:26:01', '123456', NULL, NULL, NULL),
(57, 'caxias', 'caxias@crprs.org.br', NULL, 'cliente', 1, '2026-01-23 15:36:01', '123456', NULL, NULL, NULL),
(58, 'jose.junior', 'jose.junior@crprs.org.br', NULL, 'cliente', 1, '2026-01-26 12:26:01', '123456', NULL, NULL, NULL),
(59, 'londeromario', 'londeromario@gmail.com', NULL, 'cliente', 1, '2026-01-26 13:08:01', '123456', NULL, NULL, NULL),
(60, 'evelise', 'evelise@crprs.org.br', NULL, 'cliente', 1, '2026-01-29 14:34:01', '123456', NULL, NULL, NULL),
(61, 'luis.felicio', 'luis.felicio@crprs.org.br', NULL, 'cliente', 1, '2026-02-02 17:56:01', '123456', NULL, NULL, NULL),
(62, 'daiaportinho', 'daiaportinho@gmail.com', NULL, 'cliente', 1, '2026-02-04 14:28:01', '123456', NULL, NULL, NULL),
(63, 'carinatopolski', 'carinatopolski@yahoo.com.br', NULL, 'cliente', 1, '2026-02-06 16:48:01', '123456', NULL, NULL, NULL),
(64, 'raquel.lopes', 'raquel.lopes@crprs.org.br', NULL, 'cliente', 1, '2026-02-11 14:58:01', '123456', NULL, NULL, NULL),
(65, 'arianebochi1', 'arianebochi1@gmail.com', NULL, 'cliente', 1, '2026-02-11 16:58:01', '123456', NULL, NULL, NULL),
(66, 'imprensa2', 'imprensa2@crprs.org.br', NULL, 'cliente', 1, '2026-02-12 14:46:01', '123456', NULL, NULL, NULL),
(67, 'regiapsico', 'regiapsico@hotmail.com', NULL, 'cliente', 1, '2026-02-12 20:34:01', '123456', NULL, NULL, NULL),
(68, 'jessicabvazbarreto', 'jessicabvazbarreto@gmail.com', NULL, 'cliente', 1, '2026-02-16 16:48:01', '123456', NULL, NULL, NULL),
(69, 'c.carminatti', 'c.carminatti@hotmail.com', NULL, 'cliente', 1, '2026-02-24 16:06:01', '123456', NULL, NULL, NULL),
(70, 'gabriela.justinad', 'gabriela.justinad@gmail.com', NULL, 'cliente', 1, '2026-03-02 02:14:01', '123456', NULL, NULL, NULL),
(71, 'mila.camilaschneider', 'mila.camilaschneider@gmail.com', NULL, 'cliente', 1, '2026-03-02 16:48:01', '123456', NULL, NULL, NULL),
(72, 'crprs', 'crprs@crprs.org.br', NULL, 'cliente', 1, '2026-03-03 11:14:01', '123456', NULL, NULL, NULL),
(73, 'luciara.rochak', 'luciara.rochak@gmail.com', NULL, 'cliente', 1, '2026-03-05 18:16:01', '123456', NULL, NULL, NULL),
(74, 'karensouzapsicologia', 'karensouzapsicologia@gmail.com', NULL, 'cliente', 1, '2026-03-13 12:00:05', '123456', NULL, NULL, NULL),
(75, 'cristina.lucero', 'cristina.lucero@crprs.org.br', NULL, 'cliente', 1, '2026-03-13 16:52:01', '123456', NULL, NULL, NULL),
(76, 'assistencia3', 'assistencia3@finatto.com', NULL, 'cliente', 1, '2026-03-17 18:52:01', '123456', NULL, NULL, NULL),
(77, 'hacmiranda73', 'hacmiranda73@gmail.com', NULL, 'cliente', 1, '2026-03-19 04:00:05', '123456', NULL, NULL, NULL),
(78, 'priscilakrause15', 'priscilakrause15@gmail.com', NULL, 'cliente', 1, '2026-03-19 16:06:01', '123456', NULL, NULL, NULL),
(79, 'marcia.luz', 'marcia.luz@crprs.org.br', NULL, 'cliente', 1, '2026-03-19 17:56:02', '123456', NULL, NULL, NULL),
(80, 'jessicaribeiro', 'jessicaribeiro@sou.faccat.br', NULL, 'cliente', 1, '2026-03-23 02:10:02', '123456', NULL, NULL, NULL),
(81, 'cadastro', 'cadastro@exataonline.com.br', NULL, 'cliente', 1, '2026-03-31 13:40:03', '123456', NULL, NULL, NULL),
(82, 'contato.tmi', 'contato.tmi@gmail.com', NULL, 'cliente', 1, '2026-03-31 18:20:02', '123456', NULL, NULL, NULL),
(83, 'rss.cepog', 'rss.cepog@gmail.com', NULL, 'cliente', 1, '2026-04-09 15:10:08', '123456', NULL, NULL, NULL),
(84, 'lavytmello', 'lavytmello@gmail.com', NULL, 'cliente', 1, '2026-04-10 15:26:01', '123456', NULL, NULL, NULL),
(85, 'elizandra.caprini', 'elizandra.caprini@crprs.org.br', NULL, 'cliente', 1, '2026-04-15 16:20:03', '123456', NULL, NULL, NULL),
(86, 'eduardacapelaopsi', 'eduardacapelaopsi@gmail.com', NULL, 'cliente', 1, '2026-04-17 16:08:02', '123456', NULL, NULL, NULL),
(87, 'matheus.dionisio', 'matheus.dionisio@crprs.org.br', NULL, 'cliente', 1, '2026-04-23 20:04:01', '123456', NULL, NULL, NULL),
(88, 'anarutiele.psico', 'anarutiele.psico@gmail.com', NULL, 'cliente', 1, '2026-04-25 12:26:02', '123456', NULL, NULL, NULL),
(89, 'larissamarques', 'larissamarques@uri.edu.br', NULL, 'cliente', 1, '2026-04-28 12:42:02', '123456', NULL, NULL, NULL),
(90, 'jennischardosim', 'jennischardosim@gmail.com', NULL, 'cliente', 1, '2026-04-30 18:30:05', '123456', NULL, NULL, NULL),
(91, 'leticiabarcellos.psi', 'leticiabarcellos.psi@gmail.com', NULL, 'cliente', 1, '2026-05-04 20:10:02', '123456', NULL, NULL, NULL),
(92, 'andi0807', 'andi0807@gmail.com', NULL, 'cliente', 1, '2026-05-06 15:28:01', '123456', NULL, NULL, NULL),
(93, 'cruzreni99', 'cruzreni99@gmail.com', NULL, 'cliente', 1, '2026-05-06 17:54:01', '123456', NULL, NULL, NULL),
(94, 'amandamquinzen', 'amandamquinzen@gmail.com', NULL, 'cliente', 1, '2026-05-07 14:16:01', '123456', NULL, NULL, NULL),
(95, 'sokroeff', 'sokroeff@gmail.com', NULL, 'cliente', 1, '2026-05-11 21:14:01', '123456', NULL, NULL, NULL),
(96, 'lucasabreuconceicao', 'lucasabreuconceicao@outlook.com', NULL, 'cliente', 1, '2026-05-18 16:32:01', '123456', NULL, NULL, NULL),
(97, 'rozana', 'rozana@unimedvaledasantas.coop.br', NULL, 'cliente', 1, '2026-06-01 17:46:01', '123456', NULL, NULL, NULL),
(98, 'psico.ronan', 'psico.ronan@gmail.com', NULL, 'cliente', 1, '2026-06-10 01:08:01', '123456', NULL, NULL, NULL),
(99, 'no-reply', 'no-reply@marcioluizflorentino.com', NULL, 'cliente', 1, '2026-06-22 16:42:02', '123456', NULL, NULL, NULL),
(100, 'cadastro4', 'cadastro4@crprs.org.br', NULL, 'cliente', 1, '2026-06-25 14:00:10', '123456', NULL, NULL, NULL),
(101, 'adrianafreitasrgd', 'adrianafreitasrgd@gmail.com', NULL, 'cliente', 1, '2026-06-29 17:40:04', '123456', NULL, NULL, NULL),
(102, 'sarah.schamann', 'sarah.schamann@crprs.org.br', NULL, 'cliente', 1, '2026-07-01 15:02:01', '123456', NULL, NULL, NULL),
(103, 'almeidaketli0', 'almeidaketli0@gmail.com', NULL, 'cliente', 1, '2026-07-02 18:38:02', '123456', NULL, NULL, NULL),
(104, 'giovana.andrade', 'giovana.andrade@thomasgreg.com.br', NULL, 'cliente', 1, '2026-07-03 14:04:03', '123456', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_tickets_sla`
-- (See below for the actual view)
--
CREATE TABLE IF NOT EXISTS `vw_tickets_sla` (
);

-- --------------------------------------------------------

--
-- Structure for view `vw_tickets_sla`
--
DROP TABLE IF EXISTS `vw_tickets_sla`;

CREATE ALGORITHM=UNDEFINED DEFINER=`crpdesk`@`%` SQL SECURITY DEFINER VIEW `vw_tickets_sla`  AS  select `t`.`id` AS `id`,`t`.`titulo` AS `titulo`,`t`.`status` AS `status`,`t`.`prioridade` AS `prioridade`,`t`.`criado_em` AS `criado_em`,`u`.`nome` AS `solicitante`,`a`.`nome` AS `agente`,`s`.`sla_resposta_minutos` AS `sla_resposta_minutos`,`s`.`sla_resolucao_horas` AS `sla_resolucao_horas`,`s`.`primeira_resposta_em` AS `primeira_resposta_em`,`s`.`resolvido_em` AS `resolvido_em`,`s`.`sla_resposta_vencido` AS `sla_resposta_vencido`,`s`.`sla_resolucao_vencido` AS `sla_resolucao_vencido`,`s`.`tempo_pausado_segundos` AS `tempo_pausado_segundos`,`s`.`tempo_primeira_resposta_segundos` AS `tempo_primeira_resposta_segundos`,`s`.`tempo_resolucao_segundos` AS `tempo_resolucao_segundos`,(case when (isnull(`s`.`primeira_resposta_em`) and (`s`.`pausado` = 0)) then ((`s`.`sla_resposta_minutos` * 60) - `calcular_segundos_uteis`(`t`.`criado_em`,now())) else NULL end) AS `tempo_restante_resposta_segundos`,(case when (isnull(`s`.`resolvido_em`) and (`s`.`pausado` = 0)) then ((`s`.`sla_resolucao_horas` * 3600) - (`calcular_segundos_uteis`(`t`.`criado_em`,now()) - `s`.`tempo_pausado_segundos`)) else NULL end) AS `tempo_restante_resolucao_segundos` from (((`tickets` `t` left join `usuarios` `u` on((`t`.`usuario_id` = `u`.`id`))) left join `usuarios` `a` on((`t`.`agente_id` = `a`.`id`))) left join `ticket_sla` `s` on((`t`.`id` = `s`.`ticket_id`))) ;

--
-- Constraints for dumped tables
--

--
-- Limitadores para a tabela `macros`
--
ALTER TABLE `macros`
  ADD CONSTRAINT `macros_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Limitadores para a tabela `notificacoes`
--
ALTER TABLE `notificacoes`
  ADD CONSTRAINT `notificacoes_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notificacoes_ibfk_2` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
