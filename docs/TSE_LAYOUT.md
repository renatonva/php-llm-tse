# Layout TSE 2022 (CSV ";", ISO-8859-1) -> Banco

Header lido só da UF AC. Dados sensíveis (CPF, e-mail, nascimento) não são mapeados nem armazenados.

## consulta_cand_2022_AC.csv -> candidatos / cargos / partidos / eleicoes
| Coluna CSV | Coluna banco |
|---|---|
| ANO_ELEICAO | eleicoes.ano |
| NR_TURNO | eleicoes.turno |
| NM_TIPO_ELEICAO | eleicoes.tipo |
| CD_CARGO / DS_CARGO | cargos.id / cargos.nome |
| SG_PARTIDO / NM_PARTIDO | partidos.sigla / partidos.nome |
| SQ_CANDIDATO | candidatos.id (origem externa, não mapear 1:1 direto) |
| NM_CANDIDATO | candidatos.nome |
| NM_URNA_CANDIDATO | candidatos.nome_normalizado |

## votacao_candidato_munzona_2022_AC.csv -> resultados_zona
| Coluna CSV | Coluna banco |
|---|---|
| ANO_ELEICAO | eleicoes.ano (via eleicao_id) |
| NR_TURNO | resultados_zona.turno |
| SQ_CANDIDATO | resultados_zona.candidato_id (via candidatos) |
| CD_CARGO | resultados_zona.cargo_id (via cargos) |
| SG_UF | resultados_zona.uf |
| CD_MUNICIPIO / NM_MUNICIPIO | resultados_zona.municipio_id (via municipios) |
| NR_ZONA | resultados_zona.nr_zona |
| QT_VOTOS_NOMINAIS_VALIDOS | resultados_zona.votos |

Colunas não mapeadas por conter dado sensível: NR_CPF_CANDIDATO, DS_EMAIL, DT_NASCIMENTO.

Diferenças vs 2022 (somente UF MG): consulta 2018/2026 sem diferenças de colunas ou cargos; 2018 acrescenta `NR_TURNO=2`, 2026 sem diferença de turno.
Votação 2018: `CD_SITUACAO_DIPLOMA`/`DS_SITUACAO_DIPLOMA` substituem `CD_SITUACAO_DCONST_DIPLOMA`/`DS_SITUACAO_DCONST_DIPLOMA`; `NR_TURNO=2` adicional, sem diferença de códigos de cargo.
O importador lê por nome de coluna e não usa as duas colunas de situação de diploma; mantém os campos úteis compatíveis sem aliases. Votação 2026: ZIP ausente, não comparada.
