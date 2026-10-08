CREATE TABLE IF NOT EXISTS eleicoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ano SMALLINT UNSIGNED NOT NULL,
    turno TINYINT UNSIGNED NOT NULL,
    tipo VARCHAR(32) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eleicoes_ano_turno_tipo (ano, turno, tipo),
    UNIQUE KEY uq_eleicoes_id_ano_turno (id, ano, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;