#!/usr/bin/env bash
set -euo pipefail

export MYSQL_TEST_HOST="127.0.0.1"
export MYSQL_TEST_PORT="33066"
export MYSQL_TEST_DATABASE="tiga_saudara_test"
export MYSQL_TEST_USERNAME="test_user"
export MYSQL_TEST_PASSWORD="test_password"

# Fail-fast guard: verify database name ends in _test
if [[ ! "${MYSQL_TEST_DATABASE}" =~ _test$ ]]; then
    echo "ERROR: Target test database '${MYSQL_TEST_DATABASE}' does not end in '_test'. Aborting to protect non-test databases."
    exit 1
fi

COMPOSE_FILE="docker-compose.mysql-test.yml"
PHPUNIT_CONF="phpunit.mysql.xml"

echo "=== Starting MySQL 8.0.44 Test Container ==="
docker compose -f "${COMPOSE_FILE}" up -d mysql_test

cleanup() {
    echo "=== Tearing down MySQL Test Container ==="
    docker compose -f "${COMPOSE_FILE}" down -v
}
trap cleanup EXIT

echo "=== Waiting for MySQL health check to pass (max 60s timeout) ===="
TIMEOUT=60
ELAPSED=0
until [ "$(docker inspect -f '{{.State.Health.Status}}' tiga_saudara_mysql_test 2>/dev/null)" = "healthy" ]; do
    if [ "${ELAPSED}" -ge "${TIMEOUT}" ]; then
        echo "ERROR: MySQL container failed to become healthy within ${TIMEOUT}s. Aborting."
        exit 1
    fi
    echo "Waiting for MySQL container (${ELAPSED}s elapsed)..."
    sleep 2
    ELAPSED=$((ELAPSED + 2))
done

# Wait for TCP port 33066 on host to be actively accepting connections
echo "=== Waiting for MySQL host TCP port (${MYSQL_TEST_HOST}:${MYSQL_TEST_PORT}) to accept connections ==="
until php -r '
    $err = 0;
    $errstr = "";
    $fp = @fsockopen(getenv("MYSQL_TEST_HOST"), (int)getenv("MYSQL_TEST_PORT"), $err, $errstr, 1);
    if ($fp) {
        fclose($fp);
        exit(0);
    }
    exit(1);
'; do
    if [ "${ELAPSED}" -ge "${TIMEOUT}" ]; then
        echo "ERROR: MySQL host port failed to accept connections within ${TIMEOUT}s. Aborting."
        exit 1
    fi
    sleep 1
    ELAPSED=$((ELAPSED + 1))
done

# Brief settling delay for handshake readiness
sleep 2

echo "=== Verifying MySQL Version, Isolation, SQL Mode, and Character Set ==="
php -r '
    $connected = false;
    $res = null;
    for ($i = 0; $i < 30; $i++) {
        try {
            $pdo = new PDO(
                "mysql:host=" . getenv("MYSQL_TEST_HOST") . ";port=" . getenv("MYSQL_TEST_PORT") . ";dbname=" . getenv("MYSQL_TEST_DATABASE"),
                getenv("MYSQL_TEST_USERNAME"),
                getenv("MYSQL_TEST_PASSWORD"),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $stmt = $pdo->query("SELECT VERSION() AS version, @@transaction_isolation AS isolation, @@sql_mode AS sql_mode, @@character_set_server AS charset, @@collation_server AS collation, @@time_zone AS tz");
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $connected = true;
            break;
        } catch (Throwable $e) {
            sleep(1);
        }
    }
    if (!$connected || !$res) {
        fwrite(STDERR, "ERROR: Could not establish PDO handshake with MySQL test container.\n");
        exit(1);
    }

    print_r($res);

    // Explicit validation assertions
    if (!str_starts_with($res["version"], "8.0.44")) {
        fwrite(STDERR, "ERROR: Expected MySQL version 8.0.44*, got: " . $res["version"] . "\n");
        exit(1);
    }
    if ($res["isolation"] !== "REPEATABLE-READ") {
        fwrite(STDERR, "ERROR: Expected transaction isolation REPEATABLE-READ, got: " . $res["isolation"] . "\n");
        exit(1);
    }
    if (!str_contains($res["sql_mode"], "STRICT_TRANS_TABLES")) {
        fwrite(STDERR, "ERROR: Expected strict SQL mode containing STRICT_TRANS_TABLES, got: " . $res["sql_mode"] . "\n");
        exit(1);
    }
    if ($res["charset"] !== "utf8mb4") {
        fwrite(STDERR, "ERROR: Expected character_set_server utf8mb4, got: " . $res["charset"] . "\n");
        exit(1);
    }
    if ($res["collation"] !== "utf8mb4_0900_ai_ci") {
        fwrite(STDERR, "ERROR: Expected collation_server utf8mb4_0900_ai_ci, got: " . $res["collation"] . "\n");
        exit(1);
    }
    if ($res["tz"] !== "+08:00") {
        fwrite(STDERR, "ERROR: Expected time_zone +08:00, got: " . $res["tz"] . "\n");
        exit(1);
    }
    echo "✓ All MySQL parameter assertions passed (8.0.44, REPEATABLE-READ, STRICT, utf8mb4, +08:00).\n";
'

echo "=== Running Migrations on MySQL Test Database (${MYSQL_TEST_DATABASE}) ==="
php artisan migrate:fresh --database=mysql_test --force

echo "=== Executing MySQL PHPUnit Concurrency Test Suite ==="
if [ $# -gt 0 ]; then
    ./vendor/bin/phpunit -c "${PHPUNIT_CONF}" "$@"
else
    ./vendor/bin/phpunit -c "${PHPUNIT_CONF}"
fi

echo "=== MySQL Concurrency Test Suite Completed Successfully ==="
