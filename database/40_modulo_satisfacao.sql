-- Módulo "Satisfação" (o antigo METRICA360 passa a ser parte do Conecta, 03/11/2026).
-- Convites de avaliação enviados a quem foi atendido, as avaliações recebidas e a triagem delas.
-- Pessoas e setores são os do Conecta (usuarios, setores); nada do METRICA360 é copiado para cá a não ser
-- categorias, frases e regras (database/importar-metrica360.php). Convites e avaliações do METRICA360 eram de teste.
-- Nomes com prefixo pesquisa_ para não colidir com categorias, feriados e configuracoes do Conecta.
-- Rodar uma única vez (MySQL 8.4 não aceita ADD COLUMN IF NOT EXISTS).

-- Etiquetas que o cidadão marca ao avaliar (ex.: "Atendimento", "Rapidez")
CREATE TABLE pesquisa_categorias (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    emoji VARCHAR(10) NOT NULL DEFAULT '',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem SMALLINT NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pesquisa_categorias_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Frase do dia mostrada no painel de avaliações
CREATE TABLE pesquisa_frases (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    texto TEXT NOT NULL,
    autor VARCHAR(120) NOT NULL DEFAULT '',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pesquisa_frases_ativo_ordem (ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Regras do módulo (chave/valor): quarentena, validade do link, reenvio, meta de nota, alerta de nota baixa, link do Google
CREATE TABLE pesquisa_config (
    chave VARCHAR(100) NOT NULL PRIMARY KEY,
    valor TEXT NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO pesquisa_config (chave, valor) VALUES
    ('quarentena_ativa', '1'),
    ('quarentena_dias', '15'),
    ('reenvio_horas', '48'),
    ('expiracao_token_dias', '7'),
    ('meta_nota_media', '4.5'),
    ('notificar_avaliacao_critica', '1'),
    ('email_avaliacao_critica', ''),
    ('google_review_url', ''),
    ('frase_dia_indice', '0'),
    ('frase_dia_data', '');

-- Convite enviado a um cidadão. usuario_id = o servidor atendido (responsável da demanda ou quem enviou);
-- setor_id = o setor dele no envio (fica gravado: o servidor pode mudar de setor depois).
CREATE TABLE pesquisa_convites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    setor_id INT NULL,
    destinatario_nome VARCHAR(150) NOT NULL,
    destinatario_email VARCHAR(255) NOT NULL,
    token CHAR(64) NOT NULL,
    origem ENUM('manual', 'demanda') NOT NULL DEFAULT 'manual',
    ticket_id INT NULL,
    protocolo VARCHAR(40) NULL,
    chave_integracao VARCHAR(64) NULL,
    enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    avaliado_em DATETIME NULL,
    reenviado_automatico_em DATETIME NULL,
    expirado TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_pesquisa_convites_token (token),
    UNIQUE KEY uq_pesquisa_convites_chave (chave_integracao),
    KEY idx_pesquisa_convites_usuario (usuario_id),
    KEY idx_pesquisa_convites_setor (setor_id),
    KEY idx_pesquisa_convites_email (destinatario_email),
    KEY idx_pesquisa_convites_pendentes (avaliado_em, expirado, reenviado_automatico_em, enviado_em),
    CONSTRAINT fk_pesquisa_convites_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pesquisa_convites_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE SET NULL,
    CONSTRAINT fk_pesquisa_convites_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Avaliação recebida (uma por convite). Não guarda IP nem navegador do cidadão.
CREATE TABLE pesquisa_avaliacoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    convite_id BIGINT UNSIGNED NOT NULL,
    nota TINYINT UNSIGNED NOT NULL,
    comentario TEXT NULL,
    anotacao TEXT NULL,
    status ENUM('pendente', 'em_tratativa', 'resolvido') NOT NULL DEFAULT 'pendente',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pesquisa_avaliacoes_convite (convite_id),
    KEY idx_pesquisa_avaliacoes_nota (nota),
    KEY idx_pesquisa_avaliacoes_status (status),
    KEY idx_pesquisa_avaliacoes_criado (criado_em),
    CONSTRAINT fk_pesquisa_avaliacoes_convite FOREIGN KEY (convite_id) REFERENCES pesquisa_convites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE pesquisa_avaliacao_categorias (
    avaliacao_id BIGINT UNSIGNED NOT NULL,
    categoria_id INT NOT NULL,
    PRIMARY KEY (avaliacao_id, categoria_id),
    KEY idx_pesquisa_avaliacao_categorias_categoria (categoria_id),
    CONSTRAINT fk_pesquisa_avcat_avaliacao FOREIGN KEY (avaliacao_id) REFERENCES pesquisa_avaliacoes (id) ON DELETE CASCADE,
    CONSTRAINT fk_pesquisa_avcat_categoria FOREIGN KEY (categoria_id) REFERENCES pesquisa_categorias (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- O setor aparece (ou não) no Painel de Setores da Satisfação
ALTER TABLE setores
    ADD COLUMN pesquisa_painel TINYINT(1) NOT NULL DEFAULT 1 AFTER ativo;
