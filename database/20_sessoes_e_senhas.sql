-- Migração 20 — Conecta CRPRS (Fase 9 da auditoria: contas e sessão)
-- Rodar UMA vez, depois da 19.
--
-- 1) usuarios.senha deixa de ter o padrão '123456' em texto puro; senhas que não são hash
--    (texto puro legado) são apagadas — o usuário redefine pelo "Esqueci a senha" ou o admin/gestor
--    define uma nova.
-- 2) usuarios.token_versao: muda ao trocar/redefinir a senha ou desativar o usuário,
--    invalidando todas as sessões abertas dele.
-- 3) usuarios.trocar_senha: obriga a troca no próximo acesso (senha inicial ou redefinida por
--    admin/gestor).
-- 4) sessoes_revogadas: sessões encerradas pelo botão "Sair" (cada token tem um identificador).
-- 5) tokens_recuperacao passa a guardar só o hash do token; os tokens antigos são descartados.

ALTER TABLE usuarios
    MODIFY COLUMN senha VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN token_versao INT NOT NULL DEFAULT 0 AFTER senha,
    ADD COLUMN trocar_senha TINYINT(1) NOT NULL DEFAULT 0 AFTER token_versao;

UPDATE usuarios SET senha = NULL WHERE senha IS NOT NULL AND senha NOT LIKE '$%';

CREATE TABLE sessoes_revogadas (
    jti CHAR(32) NOT NULL,
    usuario_id INT NOT NULL,
    revogado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (jti),
    KEY idx_sessoes_revogadas_usuario (usuario_id),
    CONSTRAINT fk_sessoes_revogadas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELETE FROM tokens_recuperacao;
ALTER TABLE tokens_recuperacao ADD INDEX idx_tokens_recuperacao_token (token);
