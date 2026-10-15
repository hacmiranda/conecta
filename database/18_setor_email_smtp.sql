-- Fase 6 (Conecta CRPRS): e-mail por setor passa a RECEBER (IMAP) e ENVIAR (SMTP).
-- Cada setor tem 1 endereço; as respostas às demandas do setor saem por ele.
-- Campos SMTP em branco = usa o servidor SMTP global (.env) e as credenciais do IMAP da caixa.
--
-- Aplicar APÓS 9 a 17, uma única vez (MySQL 8.4 não aceita ADD COLUMN IF NOT EXISTS).
-- Aplicar com: "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p --default-character-set=utf8mb4 db_conecta < 18_setor_email_smtp.sql

ALTER TABLE setor_emails
    ADD COLUMN smtp_host VARCHAR(255) NOT NULL DEFAULT '' AFTER imap_pass_cifrada,
    ADD COLUMN smtp_port INT NOT NULL DEFAULT 465 AFTER smtp_host,
    ADD COLUMN smtp_seguranca ENUM('ssl','tls') NOT NULL DEFAULT 'ssl' AFTER smtp_port,
    ADD COLUMN smtp_user VARCHAR(255) NULL AFTER smtp_seguranca,
    ADD COLUMN smtp_pass_cifrada TEXT NULL AFTER smtp_user,
    ADD COLUMN nome_remetente VARCHAR(100) NULL AFTER smtp_pass_cifrada;

-- Um endereço por setor: onde o setor já tinha mais de uma caixa, só a mais antiga continua ativa
-- (as demais ficam inativas, sem apagar nada; o administrador decide se as exclui).
UPDATE setor_emails se
INNER JOIN (SELECT setor_id, MIN(id) AS id_principal FROM setor_emails GROUP BY setor_id) p
        ON p.setor_id = se.setor_id
SET se.ativo = 0
WHERE se.id <> p.id_principal;

-- Registro do remetente de cada e-mail enviado (auditoria do endereço usado)
ALTER TABLE email_logs ADD COLUMN remetente VARCHAR(255) NULL AFTER destinatario;
