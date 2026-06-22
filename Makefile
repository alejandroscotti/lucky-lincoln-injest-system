.PHONY: start stop reset logs test build

start:
	./start.sh

stop:
	docker compose down

reset:
	docker compose down -v

logs:
	docker compose logs -f

build:
	docker compose build

test:
	DB_HOST=$${DB_HOST:-127.0.0.1} DB_PORT=$${DB_PORT:-18432} DB_USER=$${DB_USER:-revenue} DB_PASSWORD=$${DB_PASSWORD:-revenue_secret} DB_NAME=$${DB_NAME:-revenue_db} npm test
