ALTER TABLE log_tokens
    ADD COLUMN origem VARCHAR(20) NOT NULL DEFAULT 'llm' AFTER custo_estimado,
    ADD COLUMN duracao_ms INT UNSIGNED NOT NULL DEFAULT 0 AFTER origem,
    ADD COLUMN finish_reason VARCHAR(30) NULL AFTER duracao_ms;