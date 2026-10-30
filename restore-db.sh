#!/bin/bash

# Database Restore Script
# Uses DB_DATABASE, DB_USERNAME and DB_PASSWORD from the project .env.
set -o pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1
# Usage: ./restore-db.sh [a|b]
# Example: ./restore-db.sh a  (restores from backup/database-backup-a.zip)
#          ./restore-db.sh b  (restores from backup/database-backup-b.zip)

if [ -z "$1" ]; then
    echo "Usage: $0 [a|b]"
    echo "  a - Restore from backup/database-backup-a.zip"
    echo "  b - Restore from backup/database-backup-b.zip"
    exit 1
fi

BACKUP_FILE="backup/database-backup-$1.zip"

# Verify backup file exists
if [ ! -f "$BACKUP_FILE" ]; then
    echo "Error: $BACKUP_FILE not found!"
    exit 1
fi

# Parse Laravel dotenv syntax without executing .env as shell code.
mapfile -d '' -t DB_SETTINGS < <(php -r '
    require "vendor/autoload.php";
    try {
        $settings = Dotenv\Dotenv::createArrayBacked(getcwd())->load();
        foreach (["DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"] as $key) {
            if (!array_key_exists($key, $settings) || ($key !== "DB_PASSWORD" && $settings[$key] === "")) {
                throw new RuntimeException("Missing database setting");
            }
        }
        foreach (["DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"] as $key) {
            echo $settings[$key], "\0";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: Unable to read database settings from .env.\n");
        exit(1);
    }
')
if [ "${#DB_SETTINGS[@]}" -ne 3 ]; then
    echo "Error: PHP, Composer dependencies and valid .env database settings are required."
    exit 1
fi

DB_DATABASE="${DB_SETTINGS[0]}"
DB_USERNAME="${DB_SETTINGS[1]}"
export MYSQL_PWD="${DB_SETTINGS[2]}"
unset DB_SETTINGS
# Escape backticks for a MySQL database identifier.
DB_IDENTIFIER="${DB_DATABASE//\`/\`\`}"

mysql_restore() {
    docker exec -i -e MYSQL_PWD wl-mysql mysql --user="$DB_USERNAME" "$@"
}

echo "🗄️  Starting database restore from $BACKUP_FILE..."
echo ""

# Step 1: Drop and recreate database
echo "1️⃣  Dropping existing database and creating fresh database..."
mysql_restore -e "DROP DATABASE IF EXISTS \`$DB_IDENTIFIER\`; CREATE DATABASE \`$DB_IDENTIFIER\`;"
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to drop/recreate database"
    exit 1
fi
echo "✅ Database cleaned up"
echo ""

# Step 2: Restore from backup
echo "2️⃣  Restoring from backup (this may take a few minutes)..."
unzip -p "$BACKUP_FILE" | mysql_restore --database="$DB_DATABASE"
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to restore database"
    exit 1
fi
echo "✅ Backup restored"
echo ""

# Step 3: Verify restoration
echo "3️⃣  Verifying restoration..."
RESULT=$(mysql_restore --database="$DB_DATABASE" -e "SELECT COUNT(*) as table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();" 2>/dev/null | tail -1)
TABLE_COUNT=$RESULT

if ! [[ "$TABLE_COUNT" =~ ^[0-9]+$ ]] || [ "$TABLE_COUNT" -eq 0 ]; then
    echo "❌ Error: No tables found in restored database!"
    exit 1
fi

echo "✅ Database verification complete"
echo ""

# Display restoration summary
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📊 Restoration Summary:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Total Tables: $TABLE_COUNT"
echo ""
echo "📈 Top 5 tables by row count:"
mysql_restore --database="$DB_DATABASE" -e "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_ROWS > 0 ORDER BY TABLE_ROWS DESC LIMIT 5;" 2>/dev/null
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Database restore complete! You're ready to work."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
