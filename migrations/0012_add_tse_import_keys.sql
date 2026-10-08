ALTER TABLE partidos
    ADD UNIQUE KEY uq_partidos_sigla (sigla);

ALTER TABLE cargos
    ADD COLUMN codigo_tse INT UNSIGNED NULL AFTER id,
    ADD UNIQUE KEY uq_cargos_codigo_tse (codigo_tse);

ALTER TABLE municipios
    ADD COLUMN codigo_tse VARCHAR(16) NULL AFTER id,
    ADD UNIQUE KEY uq_municipios_uf_codigo_tse (uf, codigo_tse);

ALTER TABLE candidatos
    ADD COLUMN sq_candidato BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN numero VARCHAR(16) NULL AFTER sq_candidato,
    ADD COLUMN uf CHAR(2) NOT NULL DEFAULT '' AFTER numero,
    ADD COLUMN situacao VARCHAR(128) NOT NULL DEFAULT '' AFTER uf,
    ADD UNIQUE KEY uq_candidatos_eleicao_sq (eleicao_id, sq_candidato);

ALTER TABLE resultados_zona
    ADD COLUMN situacao VARCHAR(128) NOT NULL DEFAULT '' AFTER votos;