-- Integração (integracoes/abrir_chamado.php): o sistema satélite pode mandar uma chave_idempotencia.
-- Se ele repetir o pedido (ex.: depois de um timeout), a demanda já criada é devolvida em vez de duplicada.
-- Única por origem (origem_api + chave); NULL quando o satélite não manda a chave.
ALTER TABLE tickets
    ADD COLUMN chave_idempotencia VARCHAR(64) NULL AFTER origem_api,
    ADD UNIQUE KEY uq_tickets_origem_idempotencia (origem_api, chave_idempotencia);
