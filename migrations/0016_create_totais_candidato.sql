CREATE TABLE IF NOT EXISTS totais_candidato (
    ano SMALLINT UNSIGNED NOT NULL,
    turno TINYINT UNSIGNED NOT NULL,
    uf CHAR(2) NOT NULL,
    cargo_id BIGINT UNSIGNED NOT NULL,
    candidato_id BIGINT UNSIGNED NOT NULL,
    partido_id BIGINT UNSIGNED NULL,
    votos BIGINT UNSIGNED NOT NULL,
    situacao VARCHAR(128) NOT NULL DEFAULT '',
    PRIMARY KEY (ano, turno, uf, cargo_id, candidato_id),
    KEY idx_totais_candidato_partido (ano, turno, uf, cargo_id, partido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
