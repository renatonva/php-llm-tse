CREATE TABLE IF NOT EXISTS resultados_zona (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    eleicao_id BIGINT UNSIGNED NOT NULL,
    candidato_id BIGINT UNSIGNED NOT NULL,
    cargo_id BIGINT UNSIGNED NOT NULL,
    ano SMALLINT UNSIGNED NOT NULL,
    uf CHAR(2) NOT NULL,
    municipio_id BIGINT UNSIGNED NOT NULL,
    turno TINYINT UNSIGNED NOT NULL,
    nr_zona SMALLINT UNSIGNED NOT NULL,
    votos BIGINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resultados_zona_eleicao_turno_cand_mun_zona
        (eleicao_id, turno, candidato_id, municipio_id, nr_zona),
    KEY idx_resultados_zona_eleicao_turno_municipio (eleicao_id, turno, municipio_id),
    CONSTRAINT fk_resultados_zona_eleicao_ano_turno
        FOREIGN KEY (eleicao_id, ano, turno) REFERENCES eleicoes (id, ano, turno),
    CONSTRAINT fk_resultados_zona_candidato_eleicao_cargo
        FOREIGN KEY (candidato_id, eleicao_id, cargo_id)
        REFERENCES candidatos (id, eleicao_id, cargo_id),
    CONSTRAINT fk_resultados_zona_municipio FOREIGN KEY (municipio_id) REFERENCES municipios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
