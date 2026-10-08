ALTER TABLE log_tokens
    ADD COLUMN custo_estimado DECIMAL(12, 8) NOT NULL DEFAULT 0 AFTER tokens_saida;