#!/usr/bin/env bash
set -euo pipefail

COMPOSE_FILE="docker-compose.mysql-test.yml"
PHPUNIT_CONF="phpunit.mysql.xml"

echo "=== Starting MySQL 8.0.44 Test Container ==="
docker compose -f "${COMPOSE_FILE}" up -d mysql_test

cleanup() {
    echo "=== Tearing down MySQL Test Container ==="
    docker compose -f "${COMPOSE_FILE}" down -v
}
trap cleanup EXIT

echo "=== Waiting for MySQL health check to pass ==="
until [ "$(docker inspect -f '{{.State.Health.Status}}' tiga_saudara_mysql_test 2>/dev/null)" = "healthy" ]; do
    echo "Waiting for MySQL container (status: $(docker inspect -f '{{.State.Health.Status}}' tiga_saudara_mysql_test 2>/dev/null || echo 'starting'))..."
    sleep 2
done

echo "=== Running Migrations on MySQL Test Database ==="
php artisan migrate:fresh --database=mysql_test --force

echo "=== Executing MySQL PHPUnit Concurrency Test Suite ==="
if [ $# -gt 0 ]; then
    ./vendor/bin/phpunit -c "${PHPUNIT_CONF}" "$@"
else
    ./vendor/bin/phpunit -c "${PHPUNIT_CONF}"
fi

echo "=== MySQL Concurrency Test Suite Completed Successfully ==="
