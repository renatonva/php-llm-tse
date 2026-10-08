CREATE TABLE IF NOT EXISTS cache_respostas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pergunta_hash BINARY(32) NOT NULL,
    pergunta TEXT NOT NULL,
    resposta MEDIUMTEXT NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cache_respostas_pergunta_hash (pergunta_hash),
    KEY idx_cache_respostas_expira_em (expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;