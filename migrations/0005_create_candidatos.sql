CREATE TABLE IF NOT EXISTS candidatos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    eleicao_id BIGINT UNSIGNED NOT NULL,
    partido_id BIGINT UNSIGNED NULL,
    cargo_id BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(255) NOT NULL,
    nome_normalizado VARCHAR(191) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_candidatos_id_eleicao_cargo (id, eleicao_id, cargo_id),
    KEY idx_candidatos_nome_normalizado (nome_normalizado),
    KEY idx_candidatos_eleicao_cargo (eleicao_id, cargo_id),
    CONSTRAINT fk_candidatos_eleicao FOREIGN KEY (eleicao_id) REFERENCES eleicoes (id),
    CONSTRAINT fk_candidatos_partido FOREIGN KEY (partido_id) REFERENCES partidos (id),
    CONSTRAINT fk_candidatos_cargo FOREIGN KEY (cargo_id) REFERENCES cargos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;