# Arquitetura

## Stack
- PHP 8.2+ puro, Composer e autoload PSR-4.
- Nginx como servidor web e PHP-FPM para executar a aplicação.
- MySQL 8 acessado exclusivamente por PDO e prepared statements, sem ORM.
- Adminer para administração local do banco.
- Docker Compose para orquestrar os serviços.
- Dependências permitidas: phpdotenv, Guzzle e PHPUnit.

## Pastas
- `public/`: ponto de entrada web e arquivos públicos.
- `src/`: código PHP da aplicação, organizado por domínio e PSR-4.
- `config/`: configuração carregada do ambiente.
- `database/`: scripts SQL versionados, sem pressupor esquema do TSE.
- `tests/`: testes automatizados com PHPUnit.
- `docs/`: documentação do projeto.
- `data/`: dados locais importados; não versionar.

## Decisões
- Sem framework, ORM ou contêiner de DI; dependências são montadas manualmente.
- Consultas SQL usam PDO e parâmetros; dados externos nunca são interpolados.
- Credenciais e configurações variáveis vêm do ambiente.
- Endpoints, campos e formatos do TSE só serão implementados após confirmação por documentação ou exemplo fornecido.
- Serviços e configuração de execução ficam em Docker; persistência local deve usar volumes.
- Layout confirmado dos CSVs do TSE 2022 (consulta_cand e votacao_candidato_munzona) está em `docs/TSE_LAYOUT.md`, extraído só do cabeçalho da UF AC.
- Resultados por zona eleitoral ficam na tabela `resultados_zona` (migration 0011), distinta de `resultados`, com chave única (eleicao_id, turno, candidato_id, municipio_id, nr_zona).
- O importador TSE usa `ZipArchive` em streaming, processa lotes de 500 registros por transação e carrega candidatos antes dos votos.
- Códigos TSE de candidato, cargo e município são guardados em chaves próprias; IDs internos permanecem autogerados.
- Somente estados e DF são importados; entradas consolidadas `BR` e `BRASIL` são ignoradas.
- O download automático exige `TSE_DOWNLOAD_URL_TEMPLATE` com `{tipo}`, `{ano}` e/ou `{filename}`; sem URL confirmada, usa-se o ZIP manual em `data/`.
- Dados pessoais não entram no banco nem nos logs de rejeição; estes registram apenas tipo, UF, linha e motivo.
- Ferramentas de consulta ficam em `src/Agent/Tools/` e usam repositórios PDO com consultas parametrizadas.
- Rankings consultam `resultados_zona`, somam votos por candidato/partido e calculam percentuais sobre o total do cargo/turno.
- A base local verificada não contém votos para Presidente; consultas retornam erro de ausência de dados sem inferir resultados.
- Sem turno informado, rankings usam o maior turno disponível para o ano, UF e cargo, mantendo o denominador de votos dentro de um turno.
- O agente usa OpenRouter com roteamento determinístico antes do LLM; cache normalizado/versionado separa consultas e ferramentas, sem armazenar erros.
- `log_tokens` registra tokens, custo estimado, origem (`cache`, `router` ou `llm`) e duração; logs não guardam perguntas, dados pessoais ou credenciais.
- O modelo padrão de baixo custo é `google/gemma-4-26b-a4b-it:free`; chaves permanecem apenas no ambiente.
- `POST /chat` valida mensagem e sessão, limita requisições por IP na tabela `rate_limits` e reutiliza `Agent::answerWithMetadata()`.
- O histórico do chat fica em `chat_history`, por sessão, e mensagens com mais de 24 horas são removidas em requisições válidas.
- A API retorna somente resposta e origem; a interface usa `textContent`, sem CORS aberto, e respostas incluem `nosniff`.
- 2026 usa 04/10 (1º turno) e 25/10/2026 (2º turno), domingos calculados pela Lei 9.504/1997, art. 1º; datas exatas não confirmadas em calendário TSE. Cache ativo curto até 26/10 e invalidação por ano na importação.
- `resultado_eleicao` aceita `ano` único ou `anos` (1–10) e informa separadamente eleição ativa ainda não encerrada e ausência de dados importados.