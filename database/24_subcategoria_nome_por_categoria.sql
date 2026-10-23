-- Subcategorias com o mesmo nome em categorias diferentes do mesmo setor
-- (ex.: "Inscrição" em "Pessoa Física" e em "Pessoa Jurídica").
-- O nome deixa de ser único no setor e passa a ser único dentro da categoria.
ALTER TABLE categorias
    DROP INDEX uq_categorias_setor_nome,
    ADD UNIQUE KEY uq_categorias_setor_macro_nome (setor_id, macrocategoria_id, nome);
