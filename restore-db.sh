#!/bin/bash

# Database Restore Script
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

echo "🗄️  Starting database restore from $BACKUP_FILE..."
echo ""

# Step 1: Drop and recreate database
echo "1️⃣  Dropping existing database and creating fresh database..."
docker exec mysql-local mysql -u root -e "DROP DATABASE IF EXISTS tiga_saudara; CREATE DATABASE tiga_saudara;"
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to drop/recreate database"
    exit 1
fi
echo "✅ Database cleaned up"
echo ""

# Step 2: Restore from backup
echo "2️⃣  Restoring from backup (this may take a few minutes)..."
unzip -p "$BACKUP_FILE" | docker exec -i mysql-local mysql -u root tiga_saudara
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to restore database"
    exit 1
fi
echo "✅ Backup restored"
echo ""

# Step 3: Verify restoration
echo "3️⃣  Verifying restoration..."
RESULT=$(docker exec mysql-local mysql -u root tiga_saudara -e "SELECT COUNT(*) as table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA='tiga_saudara';" 2>/dev/null | tail -1)
TABLE_COUNT=$RESULT

if [ "$TABLE_COUNT" -eq 0 ]; then
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
docker exec mysql-local mysql -u root tiga_saudara -e "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA='tiga_saudara' AND TABLE_ROWS > 0 ORDER BY TABLE_ROWS DESC LIMIT 5;" 2>/dev/null
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Database restore complete! You're ready to work."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
