#!/usr/bin/env bash

# Fresh-install AlphaLink on Ubuntu 26.04 LTS. This script deliberately refuses
# to overwrite an existing checkout, environment file, PostgreSQL role, or DB.
set -Eeuo pipefail
umask 077
set +x

readonly APP_ROOT=/var/www/alphalink
readonly API_ROOT=/var/www/alphalink/apps/api
readonly ENV_ROOT=/etc/alphalink
readonly BACKUP_ROOT=/var/backups/alphalink
readonly DB_NAME=alphalink
readonly DB_USER=alphalink
readonly PHP_VERSION=8.5

info() { printf '[alphalink-install] %s\n' "$*"; }
fail() { printf '[alphalink-install] ERROR: %s\n' "$*" >&2; exit 1; }

size_horizon_workers() {
    local cpu_count="$1"
    local memory_mib="$2"
    local postgres_max_connections="$3"
    local cpu_budget memory_budget memory_worker_budget database_worker_budget total_workers import_workers default_workers

    [[ "$cpu_count" =~ ^[0-9]+$ && "$memory_mib" =~ ^[0-9]+$ && "$postgres_max_connections" =~ ^[0-9]+$ ]] || fail 'Worker sizing requires positive CPU, memory, and PostgreSQL connection counts.'
    (( cpu_count > 0 && memory_mib > 0 && postgres_max_connections > 0 )) || fail 'Worker sizing requires positive CPU, memory, and PostgreSQL connection counts.'

    # Keep half of target RAM available for PostgreSQL, PHP-FPM, Valkey, Nginx,
    # the OS, and file/database temporary work. Budget 384 MiB per Horizon
    # process (including overhead beyond each supervisor's configured limit).
    cpu_budget="$cpu_count"
    (( cpu_budget >= 2 )) || cpu_budget=2
    memory_budget=$((memory_mib / 2))
    memory_worker_budget=$((memory_budget / 384))

    # Leave ten PostgreSQL slots for web requests, the dedicated suppression
    # worker, scheduler, backups, migrations, and operational access.
    database_worker_budget=$((postgres_max_connections - 10))
    total_workers="$cpu_budget"
    (( total_workers <= memory_worker_budget )) || total_workers="$memory_worker_budget"
    (( total_workers <= database_worker_budget )) || total_workers="$database_worker_budget"
    (( total_workers >= 2 )) || fail 'The target host cannot reserve one default and one audience-import Horizon worker; increase server memory and PostgreSQL max_connections.'

    # Reserve roughly one third of the target-derived queue pool for large,
    # resumable audience imports; the remainder serves default queue work.
    import_workers=$(((total_workers + 2) / 3))
    default_workers=$((total_workers - import_workers))
    printf '%s %s %s\n' "$total_workers" "$default_workers" "$import_workers"
}

detect_cgroup_memory_mib() {
    local host_memory_mib="$1"
    local cgroup_limit_bytes=''
    local cgroup_memory_mib

    if [[ -r /sys/fs/cgroup/memory.max ]]; then
        cgroup_limit_bytes="$(< /sys/fs/cgroup/memory.max)"
    elif [[ -r /sys/fs/cgroup/memory/memory.limit_in_bytes ]]; then
        cgroup_limit_bytes="$(< /sys/fs/cgroup/memory/memory.limit_in_bytes)"
    fi

    if [[ "$cgroup_limit_bytes" =~ ^[0-9]+$ ]] && (( cgroup_limit_bytes > 0 )); then
        cgroup_memory_mib=$((cgroup_limit_bytes / 1024 / 1024))
        (( cgroup_memory_mib < host_memory_mib )) && host_memory_mib="$cgroup_memory_mib"
    fi

    printf '%s\n' "$host_memory_mib"
}

detect_cgroup_cpu_count() {
    local cpu_count="$1"
    local quota period quota_cpus

    if [[ -r /sys/fs/cgroup/cpu.max ]]; then
        read -r quota period < /sys/fs/cgroup/cpu.max
        if [[ "$quota" =~ ^[0-9]+$ && "$period" =~ ^[0-9]+$ ]] && (( period > 0 )); then
            quota_cpus=$(((quota + period - 1) / period))
            (( quota_cpus > 0 && quota_cpus < cpu_count )) && cpu_count="$quota_cpus"
        fi
    elif [[ -r /sys/fs/cgroup/cpu/cpu.cfs_quota_us && -r /sys/fs/cgroup/cpu/cpu.cfs_period_us ]]; then
        read -r quota < /sys/fs/cgroup/cpu/cpu.cfs_quota_us
        read -r period < /sys/fs/cgroup/cpu/cpu.cfs_period_us
        if [[ "$quota" =~ ^[0-9]+$ && "$period" =~ ^[0-9]+$ ]] && (( quota > 0 && period > 0 )); then
            quota_cpus=$(((quota + period - 1) / period))
            (( quota_cpus > 0 && quota_cpus < cpu_count )) && cpu_count="$quota_cpus"
        fi
    fi

    printf '%s\n' "$cpu_count"
}

usage() {
    cat <<'EOF'
Usage: sudo bash install-ubuntu-26.sh [--help|--plan|--worker-sizing CPU RAM_MiB PG_MAX_CONNECTIONS]

Install AlphaLink on a fresh Ubuntu 26.04 LTS server. The installer asks for
the GitHub repository, application hostname, TLS contact email, and first
workspace owner. It also asks you to type and confirm the new PostgreSQL
password; the entry is hidden while typing. A private GitHub token is read
without echo and is used only for the initial clone. It is not written to the
server.

Optional non-secret environment inputs:
  GITHUB_REPO       owner/repo or https://github.com/owner/repo[.git]
  GITHUB_BRANCH     branch to install (default: master)
  ALPHALINK_DOMAIN  DNS hostname for the application
  ACME_EMAIL        Let's Encrypt notification email
  ALPHALINK_SSH_PORT SSH port to keep open in UFW (default: detected/22)

The installer creates a new PostgreSQL database, never SQLite. It refuses to
replace any existing AlphaLink app path, environment, PostgreSQL role, or DB.
EOF
}

plan() {
    cat <<'EOF'
Plan (no changes are made):
  1. Require a fresh Ubuntu 26.04 LTS host and collect deployment settings.
  2. Enable Ubuntu's universe packages; install PHP 8.5, Composer, Node.js,
     Nginx, PostgreSQL 18, Valkey (Redis protocol), Certbot, and required PHP
     extensions.
  3. Clone the selected GitHub branch without saving its access token.
  4. Prompt for and confirm a hidden PostgreSQL password; create a
     least-privilege PostgreSQL role and a new UTF-8 database.
  5. Generate protected Laravel and contact-lookup keys in /etc/alphalink/.env.
  6. Install locked Composer/npm dependencies, build assets, and run migrations.
  7. Size Horizon from the target host's available CPU, memory, and PostgreSQL
     connection budget; bootstrap the first owner; configure Nginx/TLS, the
     PostgreSQL suppression worker, and schedule.
  8. Enable UFW rules and a protected daily PostgreSQL dump (14-day retention).

Existing application and database data are never deleted or overwritten.
EOF
}

case "${1:-}" in
    --worker-sizing)
        [[ "$#" -eq 4 ]] || fail 'Use --worker-sizing CPU RAM_MiB PG_MAX_CONNECTIONS to calculate production Horizon counts.'
        read -r TOTAL_QUEUE_WORKERS HORIZON_MAX_PROCESSES AUDIENCE_IMPORT_MAX_PROCESSES \
            < <(size_horizon_workers "$2" "$3" "$4")
        printf 'Total Horizon workers: %s\nDefault queue workers: %s\nAudience import workers: %s\n' \
            "$TOTAL_QUEUE_WORKERS" "$HORIZON_MAX_PROCESSES" "$AUDIENCE_IMPORT_MAX_PROCESSES"
        exit 0
        ;;
    --help|-h) usage; exit 0 ;;
    --plan) plan; exit 0 ;;
    "") ;;
    *) usage >&2; exit 2 ;;
esac

[[ "${EUID}" -eq 0 ]] || fail 'Run as root, for example: sudo bash install-ubuntu-26.sh.'
[[ -r /etc/os-release ]] || fail 'Cannot identify the operating system.'
# shellcheck disable=SC1091
. /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 26.04 ]] || fail 'This installer supports Ubuntu 26.04 LTS only.'
case "$(dpkg --print-architecture)" in
    amd64|arm64) ;;
    *) fail 'Ubuntu 26.04 amd64 or arm64 is required.' ;;
esac
TARGET_CPU_COUNT="$(detect_cgroup_cpu_count "$(nproc)")"
TARGET_MEMORY_MIB="$(awk '$1 == "MemTotal:" { print int($2 / 1024); exit }' /proc/meminfo)"
[[ "$TARGET_MEMORY_MIB" =~ ^[0-9]+$ ]] || fail 'Cannot determine target host memory.'
TARGET_MEMORY_MIB="$(detect_cgroup_memory_mib "$TARGET_MEMORY_MIB")"
# A fresh Ubuntu PostgreSQL cluster starts with max_connections=100; the
# installed server value is read and checked again before writing .env.
read -r PREVIEW_QUEUE_WORKERS PREVIEW_HORIZON_WORKERS PREVIEW_IMPORT_WORKERS \
    < <(size_horizon_workers "$TARGET_CPU_COUNT" "$TARGET_MEMORY_MIB" 100)
info "Target sizing preview: ${TARGET_CPU_COUNT} effective CPU(s), ${TARGET_MEMORY_MIB} MiB RAM; Horizon will run about ${PREVIEW_QUEUE_WORKERS} total (${PREVIEW_HORIZON_WORKERS} default and ${PREVIEW_IMPORT_WORKERS} audience-import) worker(s)."
[[ -t 0 ]] || fail 'Run from an interactive terminal so secrets can be entered safely.'
# Never accept a database password from the environment or a command line.
# The installer requires a fresh, interactive secret entry on every run.
unset DB_PASSWORD

for path in \
    "$APP_ROOT" "$ENV_ROOT" "$BACKUP_ROOT" \
    /etc/nginx/sites-available/alphalink \
    /etc/php/8.5/fpm/pool.d/zz-alphalink-env.conf \
    /etc/php/8.5/fpm/conf.d/99-alphalink.ini \
    /etc/systemd/system/alphalink-queue.service \
    /etc/systemd/system/alphalink-horizon.service \
    /etc/systemd/system/alphalink-drop-suppression.service \
    /etc/systemd/system/alphalink-schedule.service \
    /etc/systemd/system/alphalink-schedule.timer \
    /etc/systemd/system/alphalink-db-backup.service \
    /etc/systemd/system/alphalink-db-backup.timer; do
    [[ ! -e "$path" && ! -L "$path" ]] || fail "Refusing to overwrite existing path: $path"
done

REPOSITORY_INPUT="${GITHUB_REPO:-}"
if [[ -z "$REPOSITORY_INPUT" ]]; then
    read -r -p 'GitHub repository (owner/repo or HTTPS URL): ' REPOSITORY_INPUT
fi
if [[ "$REPOSITORY_INPUT" =~ ^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)(\.git)?$ ]]; then
    REPOSITORY="https://github.com/${BASH_REMATCH[1]}/${BASH_REMATCH[2]%.git}.git"
elif [[ "$REPOSITORY_INPUT" =~ ^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)(\.git)?/?$ ]]; then
    REPOSITORY="https://github.com/${BASH_REMATCH[1]}/${BASH_REMATCH[2]%.git}.git"
else
    fail 'Repository must be a GitHub owner/repo slug or an HTTPS GitHub URL without embedded credentials.'
fi

BRANCH="${GITHUB_BRANCH:-master}"
[[ "$BRANCH" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$BRANCH" != *..* && "$BRANCH" != */ ]] || fail 'The requested branch name is invalid.'

APP_DOMAIN="${ALPHALINK_DOMAIN:-}"
if [[ -z "$APP_DOMAIN" ]]; then
    read -r -p 'Application hostname (DNS A/AAAA must point to this server): ' APP_DOMAIN
fi
APP_DOMAIN="${APP_DOMAIN,,}"
[[ "$APP_DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || fail 'Enter a fully qualified DNS hostname, such as app.example.com.'

ACME_EMAIL="${ACME_EMAIL:-}"
if [[ -z "$ACME_EMAIL" ]]; then
    read -r -p "Let's Encrypt contact email: " ACME_EMAIL
fi
[[ "$ACME_EMAIL" == *@*.* && "$ACME_EMAIL" != *[[:space:]]* ]] || fail 'Enter a valid certificate contact email.'

OWNER_NAME="${ALPHALINK_OWNER_NAME:-}"
if [[ -z "$OWNER_NAME" ]]; then
    read -r -p 'Initial owner name: ' OWNER_NAME
fi
[[ -n "$OWNER_NAME" ]] || fail 'The first owner name is required.'

OWNER_EMAIL="${ALPHALINK_OWNER_EMAIL:-}"
if [[ -z "$OWNER_EMAIL" ]]; then
    read -r -p 'Initial owner email: ' OWNER_EMAIL
fi
[[ ${#OWNER_EMAIL} -le 254 && "$OWNER_EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$ ]] || fail 'Enter a valid first owner email address.'

WORKSPACE_NAME="${ALPHALINK_WORKSPACE_NAME:-}"
if [[ -z "$WORKSPACE_NAME" ]]; then
    read -r -p 'Initial workspace name: ' WORKSPACE_NAME
fi
[[ -n "$WORKSPACE_NAME" ]] || fail 'The first workspace name is required.'

SSH_PORT="${ALPHALINK_SSH_PORT:-}"
if [[ -z "$SSH_PORT" ]]; then
    DETECTED_SSH_PORT=22
    if command -v sshd >/dev/null 2>&1; then
        DETECTED_SSH_PORT="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }' || true)"
        DETECTED_SSH_PORT="${DETECTED_SSH_PORT:-22}"
    fi
    read -r -p "SSH port to preserve in UFW rules [${DETECTED_SSH_PORT}]: " SSH_PORT
    SSH_PORT="${SSH_PORT:-$DETECTED_SSH_PORT}"
fi
[[ "$SSH_PORT" =~ ^[0-9]{1,5}$ ]] && (( SSH_PORT > 0 && SSH_PORT < 65536 )) || fail 'SSH port must be in the range 1-65535.'

GITHUB_TOKEN="${GITHUB_TOKEN:-}"
if [[ -z "$GITHUB_TOKEN" ]]; then
    read -r -s -p 'GitHub token (leave blank for a public repository): ' GITHUB_TOKEN
    printf '\n'
fi
export -n GITHUB_TOKEN 2>/dev/null || true

read -r -s -p 'New PostgreSQL password (hidden; 32+ letters, numbers, _ or -): ' DB_PASSWORD
printf '\n'
[[ "$DB_PASSWORD" =~ ^[A-Za-z0-9_-]{32,}$ ]] || fail 'Use a PostgreSQL password with at least 32 characters, limited to letters, numbers, underscore, and hyphen.'
DB_PASSWORD_CONFIRM=''
read -r -s -p 'Confirm PostgreSQL password: ' DB_PASSWORD_CONFIRM
printf '\n'
[[ "$DB_PASSWORD" == "$DB_PASSWORD_CONFIRM" ]] || fail 'The PostgreSQL passwords did not match.'
unset DB_PASSWORD_CONFIRM

printf '\nRepository: %s\nBranch: %s\nApplication: https://%s\n' \
    "${REPOSITORY#https://github.com/}" "$BRANCH" "$APP_DOMAIN"
printf 'A new PostgreSQL database will be created; SSH port %s, HTTP, and HTTPS will be allowed by UFW.\n' "$SSH_PORT"
read -r -p 'Continue with the Ubuntu package and application installation? [y/N] ' CONFIRM_INSTALL
[[ "$CONFIRM_INSTALL" =~ ^[Yy]([Ee][Ss])?$ ]] || fail 'Installation canceled before system changes.'

info 'Enabling Ubuntu universe packages and installing deployment dependencies...'
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends software-properties-common
add-apt-repository -y universe >/dev/null
apt-get update
apt-get install -y --no-install-recommends \
    ca-certificates certbot composer curl git nginx openssl postgresql-18 postgresql-client-18 \
    python3-certbot-nginx ufw unzip valkey-server valkey-tools \
    nodejs npm \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-zip"

php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 ? 0 : 1);' || fail 'Ubuntu PHP 8.5 CLI was not installed.'
php -r '$extensions = ["bcmath", "curl", "dom", "mbstring", "openssl", "pcntl", "pdo_pgsql", "posix", "redis", "xml", "xmlwriter", "zip"]; foreach ($extensions as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }' || fail 'One or more required PHP extensions are unavailable.'
node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)' || fail 'Node.js 22 or newer is required to build the frontend.'
composer --version --no-ansi | head -n 1

systemctl enable --now postgresql valkey-server php8.5-fpm nginx
pg_isready -q || fail 'PostgreSQL did not become ready.'
[[ "$(valkey-cli ping 2>/dev/null)" == PONG ]] || fail 'Valkey did not answer its Redis-compatible health check.'

PSQL=/usr/lib/postgresql/18/bin/psql
TARGET_CPU_COUNT="$(detect_cgroup_cpu_count "$(nproc)")"
TARGET_MEMORY_MIB="$(awk '$1 == "MemTotal:" { print int($2 / 1024); exit }' /proc/meminfo)"
[[ "$TARGET_MEMORY_MIB" =~ ^[0-9]+$ ]] || fail 'Cannot determine target host memory.'
TARGET_MEMORY_MIB="$(detect_cgroup_memory_mib "$TARGET_MEMORY_MIB")"
POSTGRES_MAX_CONNECTIONS="$(runuser -u postgres -- "$PSQL" -tAc 'SHOW max_connections' | tr -d '[:space:]')"
read -r TOTAL_QUEUE_WORKERS HORIZON_MAX_PROCESSES AUDIENCE_IMPORT_MAX_PROCESSES \
    < <(size_horizon_workers "$TARGET_CPU_COUNT" "$TARGET_MEMORY_MIB" "$POSTGRES_MAX_CONNECTIONS")
info "Target sizing: ${TARGET_CPU_COUNT} effective CPU(s), ${TARGET_MEMORY_MIB} MiB RAM; Horizon will run ${HORIZON_MAX_PROCESSES} default and ${AUDIENCE_IMPORT_MAX_PROCESSES} audience-import worker(s)."

info "Cloning ${REPOSITORY#https://github.com/} (${BRANCH})..."
GIT_ASKPASS_FILE=''
GIT_TOKEN_FILE=''
cleanup() {
    [[ -z "$GIT_ASKPASS_FILE" ]] || rm -f -- "$GIT_ASKPASS_FILE"
    [[ -z "$GIT_TOKEN_FILE" ]] || rm -f -- "$GIT_TOKEN_FILE"
}
trap cleanup EXIT
if [[ -n "$GITHUB_TOKEN" ]]; then
    GIT_TOKEN_FILE="$(mktemp /run/alphalink-git-token.XXXXXX)"
    GIT_ASKPASS_FILE="$(mktemp /run/alphalink-git-askpass.XXXXXX)"
    chmod 0600 "$GIT_TOKEN_FILE" "$GIT_ASKPASS_FILE"
    printf '%s' "$GITHUB_TOKEN" > "$GIT_TOKEN_FILE"
    cat > "$GIT_ASKPASS_FILE" <<'ASKPASS'
#!/usr/bin/env bash
case "${1:-}" in
    *Username*) printf '%s\n' 'x-access-token' ;;
    *) cat -- "${ALPHALINK_INSTALLER_GIT_TOKEN_FILE:?}" ;;
esac
ASKPASS
    chmod 0700 "$GIT_ASKPASS_FILE"
    ALPHALINK_INSTALLER_GIT_TOKEN_FILE="$GIT_TOKEN_FILE" GIT_ASKPASS="$GIT_ASKPASS_FILE" GIT_TERMINAL_PROMPT=0 \
        git -c credential.helper= clone --branch "$BRANCH" --single-branch "$REPOSITORY" "$APP_ROOT"
else
    GIT_TERMINAL_PROMPT=0 git -c credential.helper= clone --branch "$BRANCH" --single-branch "$REPOSITORY" "$APP_ROOT"
fi
unset GITHUB_TOKEN
cleanup
GIT_ASKPASS_FILE=''
GIT_TOKEN_FILE=''
trap - EXIT

[[ -f "$API_ROOT/artisan" && -f "$API_ROOT/composer.lock" && -f "$API_ROOT/package-lock.json" ]] || fail 'The selected branch is missing Laravel or a committed frontend lockfile.'
git -C "$APP_ROOT" check-ref-format --branch "$BRANCH" >/dev/null || fail 'The selected branch name is not valid.'

info 'Creating a new, private PostgreSQL database and application environment...'
ROLE_EXISTS="$(runuser -u postgres -- "$PSQL" -tAc "SELECT 1 FROM pg_roles WHERE rolname = '${DB_USER}'" | tr -d '[:space:]')"
DATABASE_EXISTS="$(runuser -u postgres -- "$PSQL" -tAc "SELECT 1 FROM pg_database WHERE datname = '${DB_NAME}'" | tr -d '[:space:]')"
[[ "$ROLE_EXISTS" != 1 && "$DATABASE_EXISTS" != 1 ]] || fail 'PostgreSQL role or database already exists; refusing to reuse or replace it.'

DB_SETUP_FILE="$(mktemp /run/alphalink-pg-setup.XXXXXX)"
chown postgres:postgres "$DB_SETUP_FILE"
chmod 0600 "$DB_SETUP_FILE"
cat > "$DB_SETUP_FILE" <<SQL
\\set db_user ${DB_USER}
\\set db_name ${DB_NAME}
\\set db_password ${DB_PASSWORD}
CREATE ROLE :"db_user" LOGIN PASSWORD :'db_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE :"db_name" OWNER :"db_user" ENCODING 'UTF8' TEMPLATE template0;
SQL
if ! runuser -u postgres -- "$PSQL" --set ON_ERROR_STOP=1 --file "$DB_SETUP_FILE" >/dev/null; then
    rm -f -- "$DB_SETUP_FILE"
    fail 'PostgreSQL role/database creation failed; nothing existing was changed.'
fi
rm -f -- "$DB_SETUP_FILE"

install -d -o root -g www-data -m 0750 "$ENV_ROOT"
install -d -o www-data -g www-data -m 2770 \
    "$API_ROOT/storage/app/private" "$API_ROOT/storage/app/public" \
    "$API_ROOT/storage/framework/cache/data" "$API_ROOT/storage/framework/sessions" \
    "$API_ROOT/storage/framework/views" "$API_ROOT/storage/logs" "$API_ROOT/bootstrap/cache"
APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
CONTACT_LOOKUP_KEY="$(openssl rand -hex 32)"
cat > "$ENV_ROOT/.env" <<ENVFILE
APP_NAME=AlphaLink
APP_ENV=production
APP_KEY="${APP_KEY}"
APP_DEBUG=false
APP_URL=https://${APP_DOMAIN}
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
CACHE_STORE=redis
CONTACT_LOOKUP_KEY=${CONTACT_LOOKUP_KEY}
CONTACT_LOOKUP_KEY_VERSION=1
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
MAIL_MAILER=log
MAIL_FROM_ADDRESS=notifications@${APP_DOMAIN}
MAIL_FROM_NAME=AlphaLink
HORIZON_AUTHORIZED_EMAILS="${OWNER_EMAIL}"
HORIZON_MAX_PROCESSES=${HORIZON_MAX_PROCESSES}
AUDIENCE_IMPORT_MAX_PROCESSES=${AUDIENCE_IMPORT_MAX_PROCESSES}
ENVFILE
unset DB_PASSWORD APP_KEY CONTACT_LOOKUP_KEY
chown root:www-data "$ENV_ROOT/.env"
chmod 0640 "$ENV_ROOT/.env"

info 'Installing locked PHP and frontend dependencies...'
cd "$API_ROOT"
ALPHALINK_ENV_DIR="$ENV_ROOT" COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 \
    composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
composer check-platform-reqs --no-dev
npm ci --no-audit --no-fund
VITE_APP_NAME=AlphaLink npm run build
[[ -s public/build/manifest.json ]] || fail 'Vite did not produce the asset manifest.'
rm -rf -- node_modules

# The installer's restrictive umask keeps transient secrets private. Normalize
# cloned/built code permissions so PHP-FPM and Nginx can read it, while only
# Laravel's runtime directories remain writable by www-data.
chown -R root:www-data "$APP_ROOT"
find "$APP_ROOT" -type d -exec chmod 0750 {} +
find "$APP_ROOT" -type f -exec chmod 0640 {} +
chown -R www-data:www-data "$API_ROOT/storage" "$API_ROOT/bootstrap/cache"
find "$API_ROOT/storage" "$API_ROOT/bootstrap/cache" -type d -exec chmod 2770 {} +
find "$API_ROOT/storage" "$API_ROOT/bootstrap/cache" -type f -exec chmod 0660 {} +
install -d -o www-data -g www-data -m 0700 \
    "$API_ROOT/storage/framework/uploads" \
    "$API_ROOT/storage/framework/tmp" \
    "$API_ROOT/storage/framework/nginx-body"

info 'Applying the fresh PostgreSQL migrations...'
runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan migrate --force --no-interaction
ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan storage:link

info 'Creating the initial owner and workspace...'
runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan alphalink:bootstrap-owner \
    --name="$OWNER_NAME" --email="$OWNER_EMAIL" --workspace="$WORKSPACE_NAME"

info 'Configuring PHP-FPM, Nginx, Horizon, and scheduler...'
cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/zz-alphalink-env.conf" <<POOL
env[ALPHALINK_ENV_DIR] = ${ENV_ROOT}
POOL
cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/99-alphalink.ini" <<PHPINI
expose_php=Off
; Arbitrarily large authenticated imports are staged to private disk and parsed by Horizon in chunks.
upload_max_filesize=0
post_max_size=0
upload_tmp_dir=${API_ROOT}/storage/framework/uploads
sys_temp_dir=${API_ROOT}/storage/framework/tmp
max_execution_time=0
max_input_time=0
# PHP interprets 0 as accepting zero variables; use its largest supported value instead.
max_input_vars=2147483647
memory_limit=512M
PHPINI

cat > /etc/nginx/sites-available/alphalink <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${APP_DOMAIN};
    root ${API_ROOT}/public;
    index index.php;
    server_tokens off;
    client_max_body_size 0;
    client_body_temp_path ${API_ROOT}/storage/framework/nginx-body 1 2;
    client_body_timeout 3600s;

    charset utf-8;
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }
    location ~ \.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_param ALPHALINK_ENV_DIR ${ENV_ROOT};
        fastcgi_send_timeout 3600s;
        fastcgi_read_timeout 3600s;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ /\.(?!well-known).* { deny all; }
    location ~* \.(?:css|js|png|jpg|jpeg|gif|ico|svg|woff2?)\$ {
        expires 7d;
        add_header Cache-Control "public";
        try_files \$uri =404;
    }
}
NGINX
ln -s /etc/nginx/sites-available/alphalink /etc/nginx/sites-enabled/alphalink

cat > /etc/systemd/system/alphalink-horizon.service <<UNIT
[Unit]
Description=AlphaLink Horizon Redis queue supervisor
After=network.target postgresql.service valkey-server.service
Wants=postgresql.service valkey-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${API_ROOT}
Environment=ALPHALINK_ENV_DIR=${ENV_ROOT}
ExecStart=/usr/bin/php artisan horizon
ExecReload=/usr/bin/php artisan horizon:terminate
Restart=always
RestartSec=5
KillSignal=SIGTERM
KillMode=mixed
TimeoutStopSec=300

[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/alphalink-drop-suppression.service <<UNIT
[Unit]
Description=AlphaLink drop suppression queue worker
After=network.target postgresql.service valkey-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${API_ROOT}
Environment=ALPHALINK_ENV_DIR=${ENV_ROOT}
ExecStart=/usr/bin/php artisan queue:work drop_suppression --queue=drop-suppression --sleep=1 --tries=1 --timeout=0 --max-time=3600
ExecReload=/usr/bin/php artisan queue:restart
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=86400

[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/alphalink-schedule.service <<UNIT
[Unit]
Description=Run AlphaLink's Laravel scheduler
After=network.target postgresql.service valkey-server.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=${API_ROOT}
Environment=ALPHALINK_ENV_DIR=${ENV_ROOT}
ExecStart=/usr/bin/php artisan schedule:run
UNIT
cat > /etc/systemd/system/alphalink-schedule.timer <<'UNIT'
[Unit]
Description=Run AlphaLink's Laravel scheduler every minute

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
Unit=alphalink-schedule.service

[Install]
WantedBy=timers.target
UNIT

install -d -o postgres -g postgres -m 0700 "$BACKUP_ROOT"
cat > /etc/systemd/system/alphalink-db-backup.service <<'UNIT'
[Unit]
Description=Create a protected AlphaLink PostgreSQL backup
After=postgresql.service

[Service]
Type=oneshot
User=postgres
Group=postgres
UMask=0077
ExecStart=/bin/bash -euc '/usr/lib/postgresql/18/bin/pg_dump --format=custom --dbname=alphalink --file="/var/backups/alphalink/alphalink-$(/usr/bin/date -u +%%Y%%m%%dT%%H%%M%%SZ).dump"'
ExecStartPost=/usr/bin/find /var/backups/alphalink -maxdepth 1 -type f -name alphalink-*.dump -mtime +14 -delete
UNIT
cat > /etc/systemd/system/alphalink-db-backup.timer <<'UNIT'
[Unit]
Description=Daily AlphaLink PostgreSQL backup

[Timer]
OnCalendar=*-*-* 02:15:00
Persistent=true
Unit=alphalink-db-backup.service

[Install]
WantedBy=timers.target
UNIT

chown -R www-data:www-data "$API_ROOT/storage" "$API_ROOT/bootstrap/cache"
find "$APP_ROOT" -type d -exec chmod 0750 {} +
find "$APP_ROOT" -type f -exec chmod 0640 {} +
find "$API_ROOT/storage" "$API_ROOT/bootstrap/cache" -type d -exec chmod 2770 {} +
find "$API_ROOT/storage" "$API_ROOT/bootstrap/cache" -type f -exec chmod 0660 {} +
chmod 0750 "$APP_ROOT" "$API_ROOT"

runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan optimize
nginx -t
systemctl daemon-reload
systemctl restart php8.5-fpm
systemctl enable --now alphalink-horizon.service alphalink-drop-suppression.service alphalink-schedule.timer alphalink-db-backup.timer
for service in php8.5-fpm alphalink-horizon.service alphalink-drop-suppression.service; do
    systemctl is-active --quiet "$service" || fail "Service did not become active: $service"
done
for attempt in {1..12}; do
    if HORIZON_STATUS="$(runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan horizon:status 2>&1)" && grep -qi 'running' <<< "$HORIZON_STATUS"; then
        break
    fi
    [[ "$attempt" -lt 12 ]] || fail 'Horizon did not report a running supervisor; inspect alphalink-horizon.service and Valkey.'
    sleep 2
done

ufw allow "${SSH_PORT}/tcp" comment 'Preserve AlphaLink server SSH access'
ufw allow 80/tcp comment 'AlphaLink HTTP and ACME challenge'
ufw allow 443/tcp comment 'AlphaLink HTTPS'
ufw --force enable
systemctl reload nginx

info 'Creating and verifying the first PostgreSQL backup...'
systemctl start alphalink-db-backup.service
find "$BACKUP_ROOT" -maxdepth 1 -type f -name 'alphalink-*.dump' -size +0c -print -quit | grep -q . || fail 'The PostgreSQL backup check did not produce a non-empty file.'

info 'Requesting the HTTPS certificate...'
certbot --nginx --non-interactive --agree-tos --redirect --email "$ACME_EMAIL" -d "$APP_DOMAIN"

info 'Verifying the application health endpoint...'
for attempt in {1..12}; do
    if curl --fail --silent --show-error --output /dev/null "https://${APP_DOMAIN}/up"; then
        break
    fi
    [[ "$attempt" -lt 12 ]] || fail 'The HTTPS health check failed; inspect Nginx, TLS, PHP-FPM, and the application log.'
    sleep 5
done

info "AlphaLink installation completed at https://${APP_DOMAIN}."
info 'Database: PostgreSQL; cache and Horizon-managed default queue: local Valkey/Redis protocol.'
info 'The PostgreSQL-backed drop-suppression queue runs under its own systemd worker.'
info 'A protected local PostgreSQL backup is scheduled daily; local backups do not protect against server or disk loss.'
info 'Keep an encrypted off-host copy of /etc/alphalink/.env; its keys are required to read encrypted application data.'
info 'Campaign sending is not enabled by this installer; configure and verify separate mail transport before enabling it.'
