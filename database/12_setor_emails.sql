-- Fase 4: caixas de e-mail (IMAP) por setor. Cada setor pode ter até 2 caixas
-- cadastradas (limite validado na aplicação, não aqui); e-mails recebidos nelas
-- viram demandas já no setor correto. Senha IMAP fica cifrada em repouso
-- (ver api/services/crypto-service.php) — nunca em texto plano no banco.

CREATE TABLE IF NOT EXISTS setor_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setor_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    imap_host VARCHAR(255) NOT NULL,
    imap_port INT NOT NULL DEFAULT 993,
    imap_user VARCHAR(255) NOT NULL,
    imap_pass_cifrada TEXT NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE,
    INDEX idx_setor_emails_setor (setor_id)
);
