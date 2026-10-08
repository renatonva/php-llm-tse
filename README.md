# Agente Eleitoral

Aplicação PHP 8.2 + MySQL para consultar dados eleitorais importados do TSE.

O Intuito do projeto é para finalidade academica e prática no estudo de integrações PHP com LLM.
Usei modelo free como referencia, e orquestrador a OPEN ROUTER, porém, fique a vontade para alterar no seu arquivo env, qual orquestrador
e qual modelo utilizar.
Eu ainda não implementei e falei no futuro, um gerenciamento por modelos, afim de buscar por tráfego.

Uma opção paga que dá para testar a vontade e funciona muito bem é o openai/gpt-6-luna

MUITO IMPORTANTE, eu utilizei apenas o ano de 2022 como metrica

Se você quiser importar de outros Estado a opção é rodar no terminal

  docker compose exec app php bin/import.php --ano=2018 --uf=MG --tipo=tudo
  docker compose exec app php bin/import.php --ano=2022 --uf=MG --tipo=tudo
  docker compose exec app php bin/import.php --ano=2026 --uf=MG --tipo=tudo

Optei por CSV ao invés de apenas consumir API do TSE, devido ao volume de dados retornado e isso poderia não ser tão benefico para analise e leitura do Modelo, então optei pela importação atraves dos Agent > Tse.

E Sim, parte do projeto eu utilizei o recurso da I.A na construção, programei uma parte, e construir prompt na outra para construção.
Os prompts utilizados podem ser visto dentro da pasta docs.
Não esqueça de confugrar seu .env, hoje eu uso Open Router, fique a vontade para implementar outro orquestrador.

## Subir

```sh
docker compose up -d --build
```

## Migrar, importar e testar

```sh
docker compose exec app php bin/migrate.php
docker compose exec app php bin/import.php --ano=2022 --uf=AC --tipo=tudo
docker compose exec app vendor/bin/phpunit
```

O importador usa os arquivos ZIP TSE já presentes em `data/` ou a URL configurada no ambiente.
Abra o chat em http://localhost:8080/chat.html.



# Agente de IA para dados eleitorais do TSE

Chat que responde perguntas em linguagem natural sobre candidatos e resultados de eleições por Estado, usando dados abertos do TSE.

Feito em **PHP 8 puro (sem framework)**, **MySQL 8** e **Docker**. O agente chama um modelo de linguagem via [OpenRouter](https://openrouter.ai), mas nunca lê os arquivos brutos do TSE: ele consulta o banco local por meio de ferramentas com parâmetros tipados.

## Como funciona

```
ZIPs do TSE → bin/import.php → MySQL → ferramentas → agente (LLM) → POST /chat → chat.html
```

- **Importador:** lê os CSVs direto do ZIP, em lotes e de forma idempotente (pode rodar de novo sem duplicar dados).
- **Ferramentas:** `buscar_candidato`, `resultado_eleicao` e `ranking_partidos`, com validação rígida e limite de linhas.
- **Economia de tokens:** perguntas simples são resolvidas por um *router* sem chamar o modelo; as respostas são guardadas em *cache*; o histórico enviado ao modelo é limitado; o consumo é registrado em `log_tokens`.
- **Proteções:** rate limit por IP, limite de tamanho da mensagem, SQL somente com *prepared statements*.

## Requisitos

- Docker Desktop (com Docker Compose v2)
- Chave de API do OpenRouter (só é necessária para perguntas que o router não resolve)
- Um navegador, para baixar os arquivos do TSE

## Instalação

1. Clone o repositório e entre na pasta.
2. Crie o arquivo de ambiente e edite os valores:
```bash
   cp .env.example .env
```
3. Suba os containers:
```bash
   docker compose up -d --build
```
4. Instale as dependências, se a pasta `vendor/` não existir:
```bash
   docker compose exec app composer install
```
5. Crie as tabelas:
```bash
   docker compose exec app php bin/migrate.php
```
6. Baixe os dados do TSE **pelo navegador** e salve em `data/` (veja a seção "Dados").
7. Importe uma UF e um ano:
```bash
   docker compose exec app php bin/import.php --ano=2022 --uf=AC --tipo=tudo
```
8. Abra `http://localhost:8080/chat.html`.

> No Windows sem `make`, use os comandos acima diretamente.

## Dados

O CDN do TSE bloqueia downloads automatizados, então baixe os arquivos pelo navegador e salve em `data/` com estes nomes:

| Arquivo | URL (troque `{ANO}`) |
| --- | --- |
| `consulta_cand_{ANO}.zip` | `https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_{ANO}.zip` |
| `votacao_candidato_munzona_{ANO}.zip` | `https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_candidato_munzona/votacao_candidato_munzona_{ANO}.zip` |

- Formato: CSV dentro de ZIP, separador `;`, codificação ISO-8859-1.
- Os dados de votação ficam no nível de **zona eleitoral**; os totais são calculados com `SUM` nas consultas.
- **Dados pessoais não são importados** (CPF, e-mail, data de nascimento etc.).
- Fonte: [Portal de Dados Abertos do TSE](https://dadosabertos.tse.jus.br), licença Creative Commons Attribution.
- Caso necessite inserir Prefeitos e Vereados deixei como false em Tools > BuscarCandidatoTool, habilite para true

Opções do importador: `--ano` (obrigatório), `--uf` (opcional) e `--tipo=candidatos|votacao|tudo`.

## Configuração (`.env`)

| Variável | Descrição |
| --- | --- |
| `NGINX_PORT`, `ADMINER_PORT`, `MYSQL_PORT` | Portas expostas no host (padrão 8080, 8081 e 3306) |
| `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | Credenciais do banco. **Troque as senhas de desenvolvimento** |
| `OPENROUTER_API_KEY` | Sua chave do OpenRouter |
| `OPENROUTER_MODEL` | Modelo com suporte a *tool calling* |
| `OPENROUTER_MAX_TOKENS`, `OPENROUTER_TEMPERATURE` | Limite e temperatura das respostas |
| `OPENROUTER_MAX_STEPS` | Máximo de passos do loop do agente |
| `OPENROUTER_HISTORY_MESSAGES` | Quantas mensagens do histórico são enviadas ao modelo |
| `OPENROUTER_INPUT_COST_PER_MILLION`, `OPENROUTER_OUTPUT_COST_PER_MILLION` | Preço por milhão de tokens, para estimar o custo |
| `CACHE_TTL_SECONDS`, `CACHE_PAST_ELECTION_TTL_SECONDS` | Validade do cache (mais longa para eleições passadas) |
| `RATE_LIMIT_MAX_REQUESTS`, `RATE_LIMIT_WINDOW_SECONDS` | Rate limit por IP |

**Nunca versione o `.env` nem compartilhe sua chave.**

## Uso

**Pela tela:** `http://localhost:8080/chat.html`

Outro ponto importante aqui, eu optei em não fazer via langchain, diretamente um html meu mesmo sendo chamado para depois repassar os dados para LLM

**Pela API:**
```bash
curl -X POST http://localhost:8080/chat \
  -H "Content-Type: application/json" \
  -d '{"message":"Quem ganhou para governador do Acre em 2022?","session_id":"teste1"}'
```

**Pelo terminal:**
```bash
docker compose exec app php bin/ask.php "Quem ganhou para governador do Acre em 2022?"
```
A última linha mostra a origem da resposta (`router`, `cache` ou `llm`), os tokens e o custo.

**Banco de dados:** Adminer em `http://localhost:8081`.

## Testes

```bash
docker compose exec app vendor/bin/phpunit
```

## Limitações conhecidas

- Só existem dados das UFs e anos que você importar.
- Cargo de **Presidente** ainda não é importado (arquivos `BR`/`BRASIL` ignorados).
- Modelos gratuitos do OpenRouter têm limite diário de requisições e podem retornar erro 429. Perguntas resolvidas pelo router ou pelo cache não gastam esse limite.
- Resultados de 2026 só poderão ser importados depois de publicados pelo TSE.

## Segurança

- Entradas validadas; SQL apenas com *prepared statements*.
- Antes de expor na internet: use HTTPS, senhas fortes no banco, `APP_ENV` de produção e revise o rate limit atrás de proxy.

## Estrutura

```
bin/          migrate.php, import.php, ask.php
docker/       configuração do nginx e do PHP
docs/         ARQUITETURA.md, TSE_LAYOUT.md
migrations/   SQL versionado
public/       index.php, chat.html
src/          Agent, Tse, Repository, Support
tests/        PHPUnit
data/         ZIPs do TSE (não