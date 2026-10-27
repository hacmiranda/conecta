-- Registro de Atividades (veio da "Área do Funcionário" do METRICA360, 27/10/2026).
-- O servidor (gestor/operador) registra por dia quantas vezes fez cada atividade do seu setor; admin e gestor
-- acompanham pelo Painel de Atividades, relatórios e histórico de ausências. Regras de prazo (trabalho remoto
-- e dias de entrega de relatório) iguais às do METRICA360 (PrazoAtividadeService); feriados = os do setor.
-- Sem cópia do histórico do METRICA360: o Conecta começa vazio.

-- Atividades que cada setor registra (cadastradas por admin/gestor)
CREATE TABLE atividades_tipos (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem SMALLINT NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_atividades_tipos_setor_nome (setor_id, nome),
    CONSTRAINT fk_atividades_tipos_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Relatório de um dia de um servidor (rascunho até ser enviado; enviado não muda mais)
CREATE TABLE registros_atividades (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    data_referencia DATE NOT NULL,
    status ENUM('rascunho', 'enviado') NOT NULL DEFAULT 'rascunho',
    enviado_em DATETIME NULL,
    modalidade ENUM('remoto', 'presencial') NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_registros_atividades_usuario_data (usuario_id, data_referencia),
    KEY idx_registros_atividades_data_status (data_referencia, status),
    CONSTRAINT fk_registros_atividades_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE registros_atividades_itens (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    registro_id INT NOT NULL,
    atividade_tipo_id INT NOT NULL,
    quantidade SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    observacao VARCHAR(500) NULL,
    UNIQUE KEY uq_registros_atividades_itens_tipo (registro_id, atividade_tipo_id),
    CONSTRAINT fk_registros_atividades_itens_registro FOREIGN KEY (registro_id) REFERENCES registros_atividades (id) ON DELETE CASCADE,
    CONSTRAINT fk_registros_atividades_itens_tipo FOREIGN KEY (atividade_tipo_id) REFERENCES atividades_tipos (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Atividades marcadas com estrela por cada servidor (aparecem primeiro)
CREATE TABLE atividades_favoritas (
    usuario_id INT NOT NULL,
    atividade_tipo_id INT NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, atividade_tipo_id),
    CONSTRAINT fk_atividades_favoritas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_atividades_favoritas_tipo FOREIGN KEY (atividade_tipo_id) REFERENCES atividades_tipos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Regras de prazo do setor. Sem linha = semanal, sem prazo extra, sem dias de entrega.
--  modo_controle_remoto: semanal (cota de N relatórios remotos por semana) ou diario (dias fixos de remoto)
--  prazo_dias_atraso: dias úteis depois do dia (ou da sexta, no semanal) até virar "atrasado"
--  entrega_*: dias (ISO 1=seg..7=dom, CSV) em que todo servidor do setor entrega relatório, a partir de entrega_inicio
--  data_base: antes desta data nada é cobrado (NULL = sem corte)
CREATE TABLE atividades_config_setor (
    setor_id INT NOT NULL PRIMARY KEY,
    modo_controle_remoto ENUM('semanal', 'diario') NOT NULL DEFAULT 'semanal',
    prazo_dias_atraso TINYINT UNSIGNED NOT NULL DEFAULT 0,
    entrega_ativa TINYINT(1) NOT NULL DEFAULT 0,
    entrega_dias VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
    entrega_inicio DATE NULL,
    data_base DATE NULL,
    atualizado_por INT NULL,
    atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_atividades_config_setor_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE,
    CONSTRAINT fk_atividades_config_setor_atualizado_por FOREIGN KEY (atualizado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Regras de cada servidor. Sem linha = trabalho remoto ligado, sem cota, entrega segue o setor (como no METRICA360).
--  entrega_dias: NULL = segue o setor; '' = dispensado; '1,3' = dias próprios
CREATE TABLE atividades_config_usuario (
    usuario_id INT NOT NULL PRIMARY KEY,
    trabalho_remoto TINYINT(1) NOT NULL DEFAULT 1,
    qtd_dias_remoto_semana TINYINT UNSIGNED NULL,
    entrega_dias VARCHAR(20) NULL,
    entrega_inicio DATE NULL,
    atualizado_por INT NULL,
    atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_atividades_config_usuario_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_atividades_config_usuario_atualizado_por FOREIGN KEY (atualizado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dias fixos de trabalho remoto (modo diário), ISO 1=segunda .. 7=domingo
CREATE TABLE atividades_dias_remotos (
    usuario_id INT NOT NULL,
    dia_semana TINYINT NOT NULL,
    PRIMARY KEY (usuario_id, dia_semana),
    CONSTRAINT fk_atividades_dias_remotos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Justificativas de pendência (admin/gestor). data_referencia: o dia; no modo semanal remoto, a segunda-feira da semana
CREATE TABLE atividades_abonos (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    data_referencia DATE NOT NULL,
    tipo ENUM('remoto', 'entrega') NOT NULL DEFAULT 'remoto',
    justificativa VARCHAR(1000) NOT NULL,
    criado_por INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_atividades_abonos (usuario_id, tipo, data_referencia),
    CONSTRAINT fk_atividades_abonos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_atividades_abonos_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
