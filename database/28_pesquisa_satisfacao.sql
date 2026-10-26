-- Pesquisa de satisfação pelo METRICA360: fila de saída dos pedidos (PesquisaSatisfacaoService).
-- Uma linha por demanda (ticket_id único): só a primeira vez que a demanda de um solicitante externo
-- chega a resolvido/encerrado. O cron (enviar-fila.php) entrega o pedido ao METRICA360, que envia o
-- e-mail e cuida de quarentena, validade, reenvio e notas; aqui fica só o resultado da entrega.
CREATE TABLE pesquisa_satisfacao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    protocolo VARCHAR(20) NULL,
    destinatario_email VARCHAR(255) NOT NULL,
    destinatario_nome VARCHAR(150) NULL,
    responsavel_email VARCHAR(255) NULL,
    setor_nome VARCHAR(100) NULL,
    status ENUM('pendente', 'enviado', 'quarentena', 'sem_funcionario', 'falha') NOT NULL DEFAULT 'pendente',
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_erro VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em DATETIME NULL,
    UNIQUE KEY uq_pesquisa_satisfacao_ticket (ticket_id),
    KEY idx_pesquisa_satisfacao_fila (status, proxima_tentativa_em),
    CONSTRAINT fk_pesquisa_satisfacao_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
