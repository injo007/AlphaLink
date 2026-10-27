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

usage() {
    cat <<'EOF'
Usage: sudo bash install-ubuntu-26.sh [--help|--plan]

Install AlphaLink on a fresh Ubuntu 26.04 LTS server. The installer asks for
the GitHub repository, application hostname, TLS contact email, and first
workspace owner. A private GitHub token is read without echo and is used only
for the initial clone. It is not written to the server.

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
  2. Install Ubuntu PHP 8.5, Composer, Node.js, Nginx, PostgreSQL 18,
     Valkey (Redis protocol), Certbot, and required PHP extensions.
  3. Clone the selected GitHub branch without saving its access token.
  4. Create a least-privilege PostgreSQL role and a new UTF-8 database.
  5. Generate protected Laravel and contact-lookup keys in /etc/alphalink/.env.
  6. Install locked Composer/npm dependencies, build assets, and run migrations.
  7. Bootstrap the first owner, configure Nginx/TLS, queue workers, and schedule.
  8. Enable UFW rules and a protected daily PostgreSQL dump (14-day retention).

Existing application and database data are never deleted or overwritten.
EOF
}

case "${1:-}" in
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
[[ -t 0 ]] || fail 'Run from an interactive terminal so secrets can be entered safely.'

for path in \
    "$APP_ROOT" "$ENV_ROOT" "$BACKUP_ROOT" \
    /etc/nginx/sites-available/alphalink \
    /etc/php/8.5/fpm/pool.d/zz-alphalink-env.conf \
    /etc/php/8.5/fpm/conf.d/99-alphalink.ini \
    /etc/systemd/system/alphalink-queue.service \
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
[[ "$OWNER_EMAIL" == *@*.* && "$OWNER_EMAIL" != *[[:space:]]* ]] || fail 'Enter a valid first owner email address.'

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

printf '\nRepository: %s\nBranch: %s\nApplication: https://%s\n' \
    "${REPOSITORY#https://github.com/}" "$BRANCH" "$APP_DOMAIN"
printf 'A new PostgreSQL database will be created; SSH port %s, HTTP, and HTTPS will be allowed by UFW.\n' "$SSH_PORT"
read -r -p 'Continue with the Ubuntu package and application installation? [y/N] ' CONFIRM_INSTALL
[[ "$CONFIRM_INSTALL" =~ ^[Yy]([Ee][Ss])?$ ]] || fail 'Installation canceled before system changes.'

info 'Installing Ubuntu packages...'
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends \
    ca-certificates certbot composer curl git nginx openssl postgresql \
    python3-certbot-nginx ufw unzip valkey-server valkey-tools \
    nodejs npm \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-zip"

php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 ? 0 : 1);' || fail 'Ubuntu PHP 8.5 CLI was not installed.'
php -r '$extensions = ["bcmath", "curl", "dom", "mbstring", "openssl", "pdo_pgsql", "redis", "xml", "xmlwriter", "zip"]; foreach ($extensions as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }' || fail 'One or more required PHP extensions are unavailable.'
node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)' || fail 'Node.js 22 or newer is required to build the frontend.'
composer --version --no-ansi | head -n 1

systemctl enable --now postgresql valkey-server php8.5-fpm nginx
pg_isready -q || fail 'PostgreSQL did not become ready.'
[[ "$(valkey-cli ping 2>/dev/null)" == PONG ]] || fail 'Valkey did not answer its Redis-compatible health check.'

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
ROLE_EXISTS="$(runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname = '${DB_USER}'" | tr -d '[:space:]')"
DATABASE_EXISTS="$(runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname = '${DB_NAME}'" | tr -d '[:space:]')"
[[ "$ROLE_EXISTS" != 1 && "$DATABASE_EXISTS" != 1 ]] || fail 'PostgreSQL role or database already exists; refusing to reuse or replace it.'

DB_PASSWORD="$(openssl rand -hex 32)"
export -n DB_PASSWORD 2>/dev/null || true
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
if ! runuser -u postgres -- psql --set ON_ERROR_STOP=1 --file "$DB_SETUP_FILE" >/dev/null; then
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

info 'Applying the fresh PostgreSQL migrations...'
ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan migrate --force --no-interaction
ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan storage:link

info 'Creating the initial owner and workspace...'
runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan alphalink:bootstrap-owner \
    --name="$OWNER_NAME" --email="$OWNER_EMAIL" --workspace="$WORKSPACE_NAME"

info 'Configuring PHP-FPM, Nginx, queue workers, and scheduler...'
cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/zz-alphalink-env.conf" <<POOL
env[ALPHALINK_ENV_DIR] = ${ENV_ROOT}
POOL
cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/99-alphalink.ini" <<PHPINI
expose_php=Off
upload_max_filesize=128M
post_max_size=130M
max_execution_time=120
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
    client_max_body_size 128m;

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

cat > /etc/systemd/system/alphalink-queue.service <<UNIT
[Unit]
Description=AlphaLink default Redis queue worker
After=network.target postgresql.service valkey-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${API_ROOT}
Environment=ALPHALINK_ENV_DIR=${ENV_ROOT}
ExecStart=/usr/bin/php artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=60 --max-time=3600
ExecReload=/usr/bin/php artisan queue:restart
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=75

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
ExecStart=/bin/bash -euc '/usr/bin/pg_dump --format=custom --dbname=alphalink --file="/var/backups/alphalink/alphalink-$(/usr/bin/date -u +%%Y%%m%%dT%%H%%M%%SZ).dump"'
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

chown -R root:www-data "$APP_ROOT"
chown -R www-data:www-data "$API_ROOT/storage" "$API_ROOT/bootstrap/cache"
find "$API_ROOT/storage" "$API_ROOT/bootstrap/cache" -type d -exec chmod 2770 {} +
find "$API_ROOT/storage" "$API_ROOT/bootstrap/cache" -type f -exec chmod 0660 {} +
chmod 0750 "$APP_ROOT" "$API_ROOT"

ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan optimize
nginx -t
systemctl daemon-reload
systemctl restart php8.5-fpm
systemctl enable --now alphalink-queue.service alphalink-drop-suppression.service alphalink-schedule.timer alphalink-db-backup.timer
for service in php8.5-fpm alphalink-queue.service alphalink-drop-suppression.service; do
    systemctl is-active --quiet "$service" || fail "Service did not become active: $service"
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
info 'Database: PostgreSQL; cache and default queue: local Valkey/Redis protocol.'
info 'A protected local PostgreSQL backup is scheduled daily; local backups do not protect against server or disk loss.'
info 'Keep an encrypted off-host copy of /etc/alphalink/.env; its keys are required to read encrypted application data.'
info 'Campaign sending is not enabled by this installer; configure and verify separate mail transport before enabling it.'
