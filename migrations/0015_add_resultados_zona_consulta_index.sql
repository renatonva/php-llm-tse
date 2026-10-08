ALTER TABLE resultados_zona
    ADD KEY idx_resultados_zona_consulta (eleicao_id, turno, uf, cargo_id, candidato_id, votos);
