# 1. Configurar o ambiente
copy .env.example .env
# edite o .env: OPENROUTER_API_KEY, OPENROUTER_MODEL e as credenciais do MySQL

# 2. Subir os containers
# make up
docker compose up -d --build

# make down
docker compose down

# make logs
docker compose logs -f

# make shell
docker compose exec app sh

# make migrate
docker compose exec app php bin/migrate.php

# make import
docker compose exec app php bin/import.php --ano=2022 --uf=AC --tipo=tudo

# make test
docker compose exec app vendor/bin/phpunit