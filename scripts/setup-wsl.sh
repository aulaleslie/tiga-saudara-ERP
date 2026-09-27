#!/usr/bin/env bash
# ============================================================
#  Tiga Saudara ERP — WSL Bootstrap Script
# ============================================================
#  Usage:
#    ./scripts/setup-wsl.sh                       # full setup + serve
#    ./scripts/setup-wsl.sh --backup=b            # use backup slot b
#    ./scripts/setup-wsl.sh --fresh-db            # drop & re-restore DB
#    ./scripts/setup-wsl.sh --fresh-db --backup=b
#    ./scripts/setup-wsl.sh --skip-serve          # setup only, don't serve
# ============================================================

set -euo pipefail

# ── Colours ──────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Colour

# ── Defaults ─────────────────────────────────────────────────
BACKUP_SLOT="a"
FRESH_DB=false
SKIP_SERVE=false
MYSQL_CONTAINER="tiga_saudara_mysql"
MYSQL_VOLUME="tiga_saudara_mysql_data"
MYSQL_IMAGE="mysql:8.0"
MYSQL_PORT="3306"
DB_NAME="tiga_saudara"
RESTORE_TMP_DIR="/tmp/tiga-restore"

# ── Parse arguments ──────────────────────────────────────────
for arg in "$@"; do
    case $arg in
        --backup=*)
            BACKUP_SLOT="${arg#*=}"
            if [[ "$BACKUP_SLOT" != "a" && "$BACKUP_SLOT" != "b" ]]; then
                echo -e "${RED}Error: --backup must be 'a' or 'b'${NC}"
                exit 1
            fi
            ;;
        --fresh-db)
            FRESH_DB=true
            ;;
        --skip-serve)
            SKIP_SERVE=true
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --backup=a|b    Which backup slot to restore (default: a)"
            echo "  --fresh-db      Drop existing DB and re-restore from backup"
            echo "  --skip-serve    Only set up environment, don't start servers"
            echo "  --help, -h      Show this help"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $arg${NC}"
            echo "Run with --help for usage."
            exit 1
            ;;
    esac
done

BACKUP_FILE="backup/database-backup-${BACKUP_SLOT}.zip"

# ── Resolve project root (where this script lives) ───────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

# ── Helper functions ─────────────────────────────────────────
step() {
    echo -e "\n${CYAN}${BOLD}[$1]${NC} ${BOLD}$2${NC}"
}

ok() {
    echo -e "  ${GREEN}✓${NC} $1"
}

warn() {
    echo -e "  ${YELLOW}⚠${NC} $1"
}

fail() {
    echo -e "  ${RED}✗${NC} $1"
    exit 1
}

needs_sudo() {
    if ! sudo -n true 2>/dev/null; then
        echo -e "${YELLOW}This script needs sudo access to install packages.${NC}"
        sudo -v
    fi
}

# ── Banner ───────────────────────────────────────────────────
echo -e "${BOLD}"
echo "╔══════════════════════════════════════════════╗"
echo "║   Tiga Saudara ERP — WSL Setup              ║"
echo "╠══════════════════════════════════════════════╣"
echo "║  Backup slot : ${BACKUP_SLOT}                              ║"
echo "║  Fresh DB    : ${FRESH_DB}                          ║"
echo "║  Skip serve  : ${SKIP_SERVE}                         ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"

# ══════════════════════════════════════════════════════════════
# 1. DOCKER
# ══════════════════════════════════════════════════════════════
step "1/7" "Docker Engine"

if command -v docker &>/dev/null; then
    ok "Docker is already installed: $(docker --version)"
else
    warn "Docker not found. Installing Docker Engine..."
    needs_sudo

    # Prerequisites
    sudo apt-get update -qq
    sudo apt-get install -y -qq ca-certificates curl gnupg lsb-release >/dev/null

    # Add Docker's official GPG key and repo
    sudo install -m 0755 -d /etc/apt/keyrings
    if [ ! -f /etc/apt/keyrings/docker.gpg ]; then
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        sudo chmod a+r /etc/apt/keyrings/docker.gpg
    fi

    # Detect distro — handle Ubuntu and Debian
    DISTRO=$(. /etc/os-release && echo "$ID")
    CODENAME=$(. /etc/os-release && echo "$VERSION_CODENAME")

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/${DISTRO} ${CODENAME} stable" | \
        sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

    sudo apt-get update -qq
    sudo apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin >/dev/null

    # Add current user to docker group (takes effect after re-login)
    if ! groups "$USER" | grep -qw docker; then
        sudo usermod -aG docker "$USER"
        warn "Added $USER to 'docker' group. If docker commands fail, run: newgrp docker"
    fi

    ok "Docker installed: $(docker --version)"
fi

# Ensure Docker daemon is running
if ! docker info &>/dev/null; then
    warn "Docker daemon not running. Starting..."
    if command -v systemctl &>/dev/null && systemctl is-system-running &>/dev/null; then
        sudo systemctl start docker
    else
        # WSL without systemd
        sudo dockerd &>/dev/null &
        sleep 3
    fi

    if docker info &>/dev/null; then
        ok "Docker daemon started"
    else
        # Retry with sudo — user may not be in docker group yet in this session
        if sudo docker info &>/dev/null; then
            warn "Docker works with sudo. You may need to run 'newgrp docker' or re-open your terminal."
            warn "Continuing with sudo for docker commands in this session..."
            # Create a wrapper so the rest of the script works
            docker() { sudo docker "$@"; }
            export -f docker 2>/dev/null || true
        else
            fail "Could not start Docker daemon. Please check manually."
        fi
    fi
fi

ok "Docker daemon is running"

# ══════════════════════════════════════════════════════════════
# 2. PHP 8.2
# ══════════════════════════════════════════════════════════════
step "2/7" "PHP 8.2"

install_php() {
    needs_sudo
    sudo apt-get update -qq

    # Add ondrej/php PPA if not present
    if ! apt-cache policy 2>/dev/null | grep -q "ondrej/php"; then
        sudo apt-get install -y -qq software-properties-common >/dev/null
        sudo add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1
        sudo apt-get update -qq
    fi

    sudo apt-get install -y -qq \
        php8.2-cli \
        php8.2-common \
        php8.2-mbstring \
        php8.2-xml \
        php8.2-curl \
        php8.2-mysql \
        php8.2-sqlite3 \
        php8.2-zip \
        php8.2-gd \
        php8.2-bcmath \
        php8.2-intl \
        php8.2-readline \
        php8.2-soap \
        php8.2-ffi \
        php8.2-opcache \
        unzip \
        >/dev/null

    ok "PHP 8.2 installed: $(php --version | head -1)"
}

if command -v php &>/dev/null; then
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    PHP_MAJOR=$(echo "$PHP_VER" | cut -d. -f1)
    PHP_MINOR=$(echo "$PHP_VER" | cut -d. -f2)

    if [[ "$PHP_MAJOR" -ge 8 && "$PHP_MINOR" -ge 1 ]]; then
        ok "PHP already installed: $(php --version | head -1)"
    else
        warn "PHP $PHP_VER found but need >= 8.1. Installing PHP 8.2..."
        install_php
    fi
else
    warn "PHP not found. Installing PHP 8.2..."
    install_php
fi

# ── Composer ─────────────────────────────────────────────────
if command -v composer &>/dev/null; then
    ok "Composer already installed: $(composer --version 2>/dev/null | head -1)"
else
    warn "Composer not found. Installing..."
    needs_sudo
    curl -sS https://getcomposer.org/installer | php -- --quiet
    sudo mv composer.phar /usr/local/bin/composer
    ok "Composer installed: $(composer --version 2>/dev/null | head -1)"
fi

# ══════════════════════════════════════════════════════════════
# 3. NODE 22 LTS
# ══════════════════════════════════════════════════════════════
step "3/7" "Node.js 22 LTS"

if command -v node &>/dev/null; then
    NODE_MAJOR=$(node --version | sed 's/v//' | cut -d. -f1)
    if [[ "$NODE_MAJOR" -ge 18 ]]; then
        ok "Node.js already installed: $(node --version)"
    else
        warn "Node.js v${NODE_MAJOR} found but need >= 18. Installing Node 22..."
        needs_sudo
        curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - >/dev/null 2>&1
        sudo apt-get install -y -qq nodejs >/dev/null
        ok "Node.js installed: $(node --version)"
    fi
else
    warn "Node.js not found. Installing Node 22 LTS..."
    needs_sudo
    curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - >/dev/null 2>&1
    sudo apt-get install -y -qq nodejs >/dev/null
    ok "Node.js installed: $(node --version)"
fi

ok "npm version: $(npm --version)"

# ══════════════════════════════════════════════════════════════
# 4. MYSQL CONTAINER
# ══════════════════════════════════════════════════════════════
step "4/7" "MySQL Docker Container"

CONTAINER_EXISTS=$(docker ps -a --filter "name=^${MYSQL_CONTAINER}$" --format '{{.Names}}' 2>/dev/null || true)
CONTAINER_RUNNING=$(docker ps --filter "name=^${MYSQL_CONTAINER}$" --format '{{.Names}}' 2>/dev/null || true)

if [[ -n "$CONTAINER_RUNNING" ]]; then
    ok "MySQL container '${MYSQL_CONTAINER}' is already running"
elif [[ -n "$CONTAINER_EXISTS" ]]; then
    warn "MySQL container exists but stopped. Starting..."
    docker start "$MYSQL_CONTAINER" >/dev/null
    ok "MySQL container started"
else
    warn "Creating MySQL container..."
    docker volume create "$MYSQL_VOLUME" >/dev/null 2>&1 || true

    docker run -d \
        --name "$MYSQL_CONTAINER" \
        -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
        -e TZ=Asia/Makassar \
        -p "${MYSQL_PORT}:3306" \
        -v "${MYSQL_VOLUME}:/var/lib/mysql" \
        --restart unless-stopped \
        "$MYSQL_IMAGE" \
        --default-authentication-plugin=mysql_native_password \
        --character-set-server=utf8mb4 \
        --collation-server=utf8mb4_0900_ai_ci \
        --default-time-zone=+08:00 \
        >/dev/null

    ok "MySQL container created"
fi

# Wait for MySQL to be ready
echo -e "  Waiting for MySQL to accept connections..."
RETRIES=0
MAX_RETRIES=60
until docker exec "$MYSQL_CONTAINER" mysqladmin ping -u root --silent 2>/dev/null; do
    RETRIES=$((RETRIES + 1))
    if [[ $RETRIES -ge $MAX_RETRIES ]]; then
        fail "MySQL did not become ready after ${MAX_RETRIES}s"
    fi
    sleep 1
done
ok "MySQL is ready (took ~${RETRIES}s)"

# Create database if it doesn't exist
docker exec "$MYSQL_CONTAINER" mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;" 2>/dev/null
ok "Database '${DB_NAME}' exists"

# ══════════════════════════════════════════════════════════════
# 5. DB RESTORE
# ══════════════════════════════════════════════════════════════
step "5/7" "Database Restore"

# Check if DB has tables
TABLE_COUNT=$(docker exec "$MYSQL_CONTAINER" mysql -u root -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null || echo "0")

NEED_RESTORE=false

if [[ "$FRESH_DB" == true ]]; then
    warn "--fresh-db: Dropping and recreating database..."
    docker exec "$MYSQL_CONTAINER" mysql -u root -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\`;" 2>/dev/null
    NEED_RESTORE=true
    ok "Database dropped and recreated"
elif [[ "$TABLE_COUNT" -eq 0 ]]; then
    warn "Database is empty. Will restore from backup."
    NEED_RESTORE=true
else
    ok "Database already has ${TABLE_COUNT} tables. Skipping restore."
    ok "Use --fresh-db to force a re-restore."
fi

if [[ "$NEED_RESTORE" == true ]]; then
    if [[ ! -f "$BACKUP_FILE" ]]; then
        fail "Backup file not found: ${BACKUP_FILE}"
    fi

    BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo -e "  Restoring from ${BOLD}${BACKUP_FILE}${NC} (${BACKUP_SIZE} compressed)..."
    echo -e "  ${YELLOW}This may take several minutes for large databases.${NC}"

    # Extract dump
    rm -rf "$RESTORE_TMP_DIR"
    mkdir -p "$RESTORE_TMP_DIR"
    unzip -o -q "$BACKUP_FILE" -d "$RESTORE_TMP_DIR"

    DUMP_FILE="$RESTORE_TMP_DIR/dump.sql"
    if [[ ! -f "$DUMP_FILE" ]]; then
        # Fallback: look for any .sql file
        DUMP_FILE=$(find "$RESTORE_TMP_DIR" -name "*.sql" -type f | head -1)
        if [[ -z "$DUMP_FILE" ]]; then
            rm -rf "$RESTORE_TMP_DIR"
            fail "No .sql file found inside ${BACKUP_FILE}"
        fi
    fi

    DUMP_SIZE=$(du -h "$DUMP_FILE" | cut -f1)
    echo -e "  Importing ${DUMP_SIZE} dump into MySQL..."

    # Import with progress indicator
    if command -v pv &>/dev/null; then
        pv "$DUMP_FILE" | docker exec -i "$MYSQL_CONTAINER" mysql -u root "$DB_NAME" 2>/dev/null
    else
        # Simple progress: show elapsed time
        START_TIME=$SECONDS
        docker exec -i "$MYSQL_CONTAINER" mysql -u root \
            --max-allowed-packet=512M \
            "$DB_NAME" < "$DUMP_FILE" 2>/dev/null &
        IMPORT_PID=$!

        while kill -0 "$IMPORT_PID" 2>/dev/null; do
            ELAPSED=$((SECONDS - START_TIME))
            printf "\r  Importing... %d:%02d elapsed" $((ELAPSED / 60)) $((ELAPSED % 60))
            sleep 5
        done
        wait "$IMPORT_PID"
        ELAPSED=$((SECONDS - START_TIME))
        printf "\r"
    fi

    # Cleanup
    rm -rf "$RESTORE_TMP_DIR"

    FINAL_TABLE_COUNT=$(docker exec "$MYSQL_CONTAINER" mysql -u root -N -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null || echo "?")

    ok "Database restored — ${FINAL_TABLE_COUNT} tables (${ELAPSED:-0}s)"
fi

# ══════════════════════════════════════════════════════════════
# 6. LARAVEL SETUP
# ══════════════════════════════════════════════════════════════
step "6/7" "Laravel Application Setup"

# .env file
if [[ ! -f ".env" ]]; then
    cp .env.example .env
    ok "Created .env from .env.example"
else
    ok ".env already exists"
fi

# Ensure DB settings match our docker container
# Patch in-place: DB_HOST, DB_PORT, DB_PASSWORD, DB_DATABASE
sed -i 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' .env
sed -i 's/^DB_PORT=.*/DB_PORT=3306/' .env
sed -i 's/^DB_DATABASE=.*/DB_DATABASE=tiga_saudara/' .env
sed -i 's/^DB_USERNAME=.*/DB_USERNAME=root/' .env
sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=/' .env
ok "Verified .env DB settings match Docker MySQL"

# Composer install
echo -e "  Running composer install..."
COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --prefer-dist --quiet 2>&1
ok "Composer dependencies installed"

# Generate app key if needed
APP_KEY=$(grep '^APP_KEY=' .env | cut -d= -f2-)
if [[ -z "$APP_KEY" || "$APP_KEY" == "base64:" ]]; then
    php artisan key:generate --no-interaction --quiet
    ok "Application key generated"
else
    ok "Application key already set"
fi

# NPM install
echo -e "  Running npm install..."
npm install --silent 2>&1
ok "NPM dependencies installed"

# Run migrations
echo -e "  Running migrations..."
php artisan migrate --no-interaction --force 2>&1 || warn "Migrations had warnings (may be OK if DB was restored from backup)"
ok "Migrations complete"

# Clear caches
php artisan config:clear --quiet 2>/dev/null || true
php artisan cache:clear --quiet 2>/dev/null || true
php artisan view:clear --quiet 2>/dev/null || true
ok "Caches cleared"

# ══════════════════════════════════════════════════════════════
# 7. SERVE
# ══════════════════════════════════════════════════════════════
step "7/7" "Development Servers"

if [[ "$SKIP_SERVE" == true ]]; then
    ok "Skipping dev servers (--skip-serve)"
    echo ""
    echo -e "${GREEN}${BOLD}Setup complete!${NC} To start the servers manually:"
    echo -e "  ${CYAN}php artisan serve &${NC}"
    echo -e "  ${CYAN}npm run dev &${NC}"
    exit 0
fi

# Trap to clean up background processes on Ctrl+C
ARTISAN_PID=""
VITE_PID=""

cleanup() {
    echo ""
    echo -e "${YELLOW}Shutting down dev servers...${NC}"
    [[ -n "$ARTISAN_PID" ]] && kill "$ARTISAN_PID" 2>/dev/null && echo -e "  Stopped php artisan serve (PID $ARTISAN_PID)"
    [[ -n "$VITE_PID" ]] && kill "$VITE_PID" 2>/dev/null && echo -e "  Stopped npm run dev (PID $VITE_PID)"
    echo -e "${GREEN}Servers stopped. MySQL container is still running.${NC}"
    echo -e "To stop MySQL: ${CYAN}docker stop ${MYSQL_CONTAINER}${NC}"
    exit 0
}

trap cleanup SIGINT SIGTERM

# Start servers
php artisan serve &
ARTISAN_PID=$!

npm run dev &
VITE_PID=$!

echo ""
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}${BOLD}║   🚀 Tiga Saudara ERP is running!            ║${NC}"
echo -e "${GREEN}${BOLD}╠══════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}${BOLD}║                                              ║${NC}"
echo -e "${GREEN}${BOLD}║   App:   http://127.0.0.1:8000               ║${NC}"
echo -e "${GREEN}${BOLD}║   Vite:  http://127.0.0.1:5173               ║${NC}"
echo -e "${GREEN}${BOLD}║                                              ║${NC}"
echo -e "${GREEN}${BOLD}║   Press Ctrl+C to stop all servers           ║${NC}"
echo -e "${GREEN}${BOLD}║                                              ║${NC}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════════╝${NC}"
echo ""

# Wait for both processes
wait
