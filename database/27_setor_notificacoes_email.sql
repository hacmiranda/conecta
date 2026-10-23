-- Ativar/desativar cada e-mail do sistema por setor (NotificacaoEmailService). Só guarda o que
-- foi desligado: setor sem nenhuma linha aqui (atual ou futuro) envia tudo, por padrão.
CREATE TABLE setor_notificacoes_email (
    setor_id INT NOT NULL,
    codigo VARCHAR(50) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    atualizado_por INT NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setor_id, codigo),
    CONSTRAINT fk_setor_notif_email_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE,
    CONSTRAINT fk_setor_notif_email_usuario FOREIGN KEY (atualizado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
