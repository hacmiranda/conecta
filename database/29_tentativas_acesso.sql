-- Limite de tentativas (login, recuperação/redefinição/troca de senha) no banco, em vez de arquivos.
-- Cada tentativa é gravada ANTES de ser conferida (RateLimitService::consumir), então requisições
-- simultâneas se enxergam e não passam do limite. "chave" é um hash (sem IP ou e-mail em claro).
CREATE TABLE tentativas_acesso (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chave CHAR(40) NOT NULL,
    acao VARCHAR(30) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tentativas_chave (chave, acao, criado_em),
    KEY idx_tentativas_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
