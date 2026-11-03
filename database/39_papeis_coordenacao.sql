-- Papéis de coordenação (unificação com o METRICA360, 03/11/2026).
--   coordenador        gestor em vários setores: o principal (usuarios.setor_id) + os adicionais (usuario_setores)
--   supervisor         visão total, somente leitura (exceto a triagem das avaliações)
--   coordenador_geral  visão total, somente leitura (exceto a triagem das avaliações)
-- Um setor tem no máximo um coordenador (conferido pelo código; a UNIQUE abaixo cobre só os adicionais).
-- Também traz o "atendimento externo" do METRICA360 (libera o envio de convites no Meu Painel).
-- Rodar uma única vez (MySQL 8.4 não aceita ADD COLUMN IF NOT EXISTS).

ALTER TABLE `usuarios`
  MODIFY COLUMN `tipo` ENUM('cliente','operador','gestor','coordenador','supervisor','coordenador_geral','admin') DEFAULT 'cliente';

ALTER TABLE `usuarios`
  ADD COLUMN `atendimento_externo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cargo`;

-- Setores adicionais de um coordenador (o setor principal continua em usuarios.setor_id)
CREATE TABLE usuario_setores (
    usuario_id INT NOT NULL,
    setor_id INT NOT NULL,
    criado_por INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, setor_id),
    UNIQUE KEY uq_usuario_setores_setor (setor_id),
    CONSTRAINT fk_usuario_setores_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_usuario_setores_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE CASCADE,
    CONSTRAINT fk_usuario_setores_autor FOREIGN KEY (criado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
