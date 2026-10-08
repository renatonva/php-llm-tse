CREATE TABLE IF NOT EXISTS resultados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    eleicao_id BIGINT UNSIGNED NOT NULL,
    candidato_id BIGINT UNSIGNED NOT NULL,
    cargo_id BIGINT UNSIGNED NOT NULL,
    ano SMALLINT UNSIGNED NOT NULL,
    uf CHAR(2) NOT NULL,
    municipio_id BIGINT UNSIGNED NULL,
    turno TINYINT UNSIGNED NOT NULL,
    votos BIGINT UNSIGNED NOT NULL DEFAULT 0,
    situacao VARCHAR(64) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_resultados_ano_uf_cargo (ano, uf, cargo_id),
    KEY idx_resultados_candidato_eleicao (candidato_id, eleicao_id),
    KEY idx_resultados_municipio (municipio_id),
    CONSTRAINT fk_resultados_eleicao_ano_turno
        FOREIGN KEY (eleicao_id, ano, turno) REFERENCES eleicoes (id, ano, turno),
    CONSTRAINT fk_resultados_candidato_eleicao_cargo
        FOREIGN KEY (candidato_id, eleicao_id, cargo_id)
        REFERENCES candidatos (id, eleicao_id, cargo_id),
    CONSTRAINT fk_resultados_municipio FOREIGN KEY (municipio_id) REFERENCES municipios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;