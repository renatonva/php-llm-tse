.PHONY: up down logs shell migrate import test

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f

shell:
	docker compose exec app sh

migrate:
	docker compose exec app php bin/migrate.php

import:
	docker compose exec app php bin/import.php

test:
	docker compose exec app composer test