#!/usr/bin/env bash

# Install or deploy AlphaLink on Ubuntu 26.04 LTS. Fresh installation refuses
# to overwrite an existing app, environment file, PostgreSQL role, or database.
set -Eeuo pipefail
umask 077
set +x

readonly APP_ROOT=/var/www/alphalink
readonly SOURCE_ROOT=/var/www/alphalink/source
readonly RELEASES_ROOT=/var/www/alphalink/releases
readonly SHARED_ROOT=/var/www/alphalink/shared
readonly CURRENT_ROOT=/var/www/alphalink/current
readonly API_ROOT=/var/www/alphalink/current/apps/api
readonly ENV_ROOT=/etc/alphalink
readonly BACKUP_ROOT=/var/backups/alphalink
readonly INSTALL_ASSETS_ROOT=/var/www/alphalink/shared/storage/app/private/install-assets
readonly DB_NAME=alphalink
readonly DB_USER=alphalink
readonly PHP_VERSION=8.5

info() { printf '[alphalink-install] %s\n' "$*"; }
fail() { printf '[alphalink-install] ERROR: %s\n' "$*" >&2; exit 1; }

nginx_app_domain() {
    local nginx_config="$1"

    awk '
        /^[[:space:]]*#/ { next }
        $1 == "server_name" {
            for (i = 2; i <= NF; i++) {
                sub(/;$/, "", $i)
                if ($i != "" && $i != "_") {
                    print $i
                    exit
                }
            }
        }
    ' "$nginx_config"
}

valid_app_domain() {
    [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]
}

valid_public_ipv4() {
    local address="$1" first second third fourth extra octet numeric
    local -a octets=()

    [[ "$address" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
    IFS=. read -r first second third fourth extra <<< "$address"
    [[ -z "${extra:-}" ]] || return 1
    octets=("$first" "$second" "$third" "$fourth")
    for octet in "${octets[@]}"; do
        numeric=$((10#$octet))
        (( numeric <= 255 )) || return 1
        [[ "$octet" == "$numeric" ]] || return 1
    done

    first=$((10#$first))
    second=$((10#$second))
    third=$((10#$third))
    (( first > 0 && first < 224 )) || return 1
    (( first != 10 && first != 127 )) || return 1
    (( first != 169 || second != 254 )) || return 1
    (( first != 172 || second < 16 || second > 31 )) || return 1
    (( first != 192 || second != 168 )) || return 1
    (( first != 100 || second < 64 || second > 127 )) || return 1
    (( first != 192 || second != 0 || third != 0 )) || return 1
    (( first != 192 || second != 0 || third != 2 )) || return 1
    (( first != 198 || second != 51 || third != 100 )) || return 1
    (( first != 203 || second != 0 || third != 113 )) || return 1
}

validate_pmta_relay_ipv4_list() {
    local value="${1//[[:space:]]/}" address
    local -a addresses=()

    [[ -n "$value" ]] || return 1
    [[ "$value" != ,* && "$value" != *, && "$value" != *,,* ]] || return 1
    IFS=, read -r -a addresses <<< "$value"
    (( ${#addresses[@]} > 0 )) || return 1
    for address in "${addresses[@]}"; do
        valid_public_ipv4 "$address" || return 1
    done
}

wait_for_horizon() {
    local attempt horizon_status=''

    for attempt in {1..12}; do
        if horizon_status="$(runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" \
            php "$API_ROOT/artisan" horizon:status 2>&1)" \
            && grep -qi 'running' <<< "$horizon_status"; then
            return 0
        fi

        [[ "$attempt" -eq 12 ]] || sleep 2
    done

    info 'Horizon did not report a running supervisor after 24 seconds; inspect alphalink-horizon.service and Valkey.'
    return 1
}

GIT_ASKPASS_FILE=''
GIT_TOKEN_FILE=''
DEPLOY_STAGING_DIR=''
INSTALL_STAGING_DIR=''
SOURCE_STAGING_DIR=''
ASSET_STAGING_DIR=''
cleanup_temporary_secrets() {
    [[ -z "$GIT_ASKPASS_FILE" ]] || rm -f -- "$GIT_ASKPASS_FILE"
    [[ -z "$GIT_TOKEN_FILE" ]] || rm -f -- "$GIT_TOKEN_FILE"
    GIT_ASKPASS_FILE=''
    GIT_TOKEN_FILE=''
}
cleanup_deploy_staging() {
    cleanup_temporary_secrets
    if [[ "$DEPLOY_STAGING_DIR" == "$RELEASES_ROOT"/.deploy-stage-* && -d "$DEPLOY_STAGING_DIR" ]]; then
        rm -rf -- "$DEPLOY_STAGING_DIR"
    fi
    if [[ "$INSTALL_STAGING_DIR" == "$RELEASES_ROOT"/.install-stage-* && -d "$INSTALL_STAGING_DIR" ]]; then
        rm -rf -- "$INSTALL_STAGING_DIR"
    fi
    if [[ "$SOURCE_STAGING_DIR" == "$APP_ROOT"/.source-stage-* && -d "$SOURCE_STAGING_DIR" ]]; then
        rm -rf -- "$SOURCE_STAGING_DIR"
    fi
    if [[ "$ASSET_STAGING_DIR" == "$SHARED_ROOT"/.install-assets-stage-* && -d "$ASSET_STAGING_DIR" ]]; then
        rm -rf -- "$ASSET_STAGING_DIR"
    fi
}
trap cleanup_deploy_staging EXIT

clone_repository() {
    local repository="$1"
    local branch="$2"
    local destination="$3"
    local token="$4"
    if [[ -n "$token" ]]; then
        GIT_TOKEN_FILE="$(mktemp /run/alphalink-git-token.XXXXXX)"
        GIT_ASKPASS_FILE="$(mktemp /run/alphalink-git-askpass.XXXXXX)"
        chmod 0600 "$GIT_TOKEN_FILE" "$GIT_ASKPASS_FILE"
        printf '%s' "$token" > "$GIT_TOKEN_FILE"
        cat > "$GIT_ASKPASS_FILE" <<'ASKPASS'
#!/usr/bin/env bash
case "${1:-}" in
    *Username*) printf '%s\n' 'x-access-token' ;;
    *) cat -- "${ALPHALINK_INSTALLER_GIT_TOKEN_FILE:?}" ;;
esac
ASKPASS
        chmod 0700 "$GIT_ASKPASS_FILE"
        if ! ALPHALINK_INSTALLER_GIT_TOKEN_FILE="$GIT_TOKEN_FILE" GIT_ASKPASS="$GIT_ASKPASS_FILE" GIT_TERMINAL_PROMPT=0 \
            git -c credential.helper= clone --branch "$branch" --single-branch "$repository" "$destination"; then
            cleanup_temporary_secrets
            fail 'GitHub clone failed; verify the repository, branch, and read-only token.'
        fi
        cleanup_temporary_secrets
    elif ! GIT_TERMINAL_PROMPT=0 git -c credential.helper= clone --branch "$branch" --single-branch "$repository" "$destination"; then
        fail 'GitHub clone failed; verify the repository and branch, or provide a read-only token for a private repository.'
    fi
}

fetch_repository_branch() {
    local repository_root="$1"
    local branch="$2"
    local token="$3"

    if [[ -n "$token" ]]; then
        GIT_TOKEN_FILE="$(mktemp /run/alphalink-git-token.XXXXXX)"
        GIT_ASKPASS_FILE="$(mktemp /run/alphalink-git-askpass.XXXXXX)"
        chmod 0600 "$GIT_TOKEN_FILE" "$GIT_ASKPASS_FILE"
        printf '%s' "$token" > "$GIT_TOKEN_FILE"
        cat > "$GIT_ASKPASS_FILE" <<'ASKPASS'
#!/usr/bin/env bash
case "${1:-}" in
    *Username*) printf '%s\n' 'x-access-token' ;;
    *) cat -- "${ALPHALINK_INSTALLER_GIT_TOKEN_FILE:?}" ;;
esac
ASKPASS
        chmod 0700 "$GIT_ASKPASS_FILE"
        if ! ALPHALINK_INSTALLER_GIT_TOKEN_FILE="$GIT_TOKEN_FILE" GIT_ASKPASS="$GIT_ASKPASS_FILE" GIT_TERMINAL_PROMPT=0 \
            git -C "$repository_root" -c credential.helper= fetch --depth=1 origin "$branch"; then
            cleanup_temporary_secrets
            fail 'GitHub fetch failed; verify the repository, branch, and read-only token.'
        fi
        cleanup_temporary_secrets
    elif ! GIT_TERMINAL_PROMPT=0 git -C "$repository_root" -c credential.helper= fetch --depth=1 origin "$branch"; then
        fail 'GitHub fetch failed; verify the repository and branch, or provide a read-only token for a private repository.'
    fi
}

atomic_switch_release() {
    local release_path="$1"
    local temporary_link="${APP_ROOT}/.current-release-$$"

    ln -s "$release_path" "$temporary_link"
    mv -Tf "$temporary_link" "$CURRENT_ROOT"
}

normalize_release_permissions() {
    local release_path="$1"
    local api_path="${release_path}/apps/api"

    # Composer and npm run as root under the installer's private umask (077).
    # Normalize generated files after building so PHP-FPM can read the
    # autoloader and dependencies while Laravel's runtime cache stays writable.
    chown -R root:www-data "$release_path"
    find "$release_path" -type d -exec chmod 0750 {} +
    find "$release_path" -type f -exec chmod 0640 {} +
    chmod 0750 "$release_path" "$api_path"
    chown -R www-data:www-data "$api_path/bootstrap/cache"
    find "$api_path/bootstrap/cache" -type d -exec chmod 2770 {} +
    find "$api_path/bootstrap/cache" -type f -exec chmod 0660 {} +
}

validate_install_assets_source() {
    local source="$1"
    local invalid_entry asset
    local -a required_assets=(
        pmta/4.0/package.rpm pmta/4.0/httpd-patch pmta/4.0/license.lic pmta/4.0/domains.conf
        pmta/4.5/package.rpm pmta/4.5/pmtad-patch pmta/4.5/license.lic pmta/4.5/domains.conf
        pmta/5.0/package.rpm pmta/5.0/pmtad-patch pmta/5.0/license.lic pmta/5.0/domains.conf
        pmta/shared/source.conf pmta/shared/base.conf
        optional/tracking/mini-app.zip optional/tracking/cloudflare-ips.conf
        optional/imap/dovecot.conf optional/proxy/squid.conf optional/dkim/keys.json
        optional/reverse-proxy/nginx-repo.conf optional/reverse-proxy/nginx.conf
    )
    local -a missing_assets=()

    [[ "$source" == /* && -d "$source" && ! -L "$source" ]] \
        || fail 'Installation assets must come from an existing absolute directory on this server.'
    source="$(realpath -e -- "$source")"
    [[ "$source" != "$INSTALL_ASSETS_ROOT" \
        && "$INSTALL_ASSETS_ROOT" != "$source/"* \
        && "$source" != "$INSTALL_ASSETS_ROOT/"* ]] \
        || fail 'The asset source and private destination must be separate directories.'
    invalid_entry="$(find "$source" -mindepth 1 \( -type l -o \( ! -type d -a ! -type f \) \) -print -quit)"
    [[ -z "$invalid_entry" ]] || fail 'Installation assets cannot contain symlinks or special files.'
    for asset in "${required_assets[@]}"; do
        [[ -s "$source/$asset" ]] || missing_assets+=("$asset")
    done
    (( ${#missing_assets[@]} == 0 )) \
        || fail "The private PMTA bundle is incomplete; missing or empty asset(s): ${missing_assets[*]}"

    printf '%s\n' "$source"
}

normalize_private_install_assets() {
    local root="$1"

    chown -R www-data:www-data "$root"
    find "$root" -type d -exec chmod 0700 {} +
    find "$root" -type f -exec chmod 0600 {} +
}

normalize_shared_storage_permissions() {
    local extra_root="${1:-}"
    local -a roots=("$SHARED_ROOT/storage")

    if [[ -n "$extra_root" ]]; then
        roots+=("$extra_root")
    fi

    chown -R www-data:www-data "${roots[@]}"
    # The installation bundle is private runtime input. Keep it out of the
    # group-writable Laravel storage sweep on every install and deployment.
    find "${roots[@]}" -path "$INSTALL_ASSETS_ROOT" -prune -o -type d -exec chmod 2770 {} +
    find "${roots[@]}" -path "$INSTALL_ASSETS_ROOT" -prune -o -type f -exec chmod 0660 {} +
}

stage_private_install_assets() {
    local source="$1"
    local existing_entry invalid_entry

    source="$(validate_install_assets_source "$source")"
    if [[ -e "$INSTALL_ASSETS_ROOT" || -L "$INSTALL_ASSETS_ROOT" ]]; then
        [[ -d "$INSTALL_ASSETS_ROOT" && ! -L "$INSTALL_ASSETS_ROOT" ]] \
            || fail 'The private asset destination exists but is unsafe; preserving it.'
        existing_entry="$(find "$INSTALL_ASSETS_ROOT" -mindepth 1 -print -quit)"
        [[ -z "$existing_entry" ]] || fail 'The private asset destination already contains files; refusing to replace them.'
        rmdir -- "$INSTALL_ASSETS_ROOT"
    fi

    ASSET_STAGING_DIR="${SHARED_ROOT}/.install-assets-stage-$$"
    [[ ! -e "$ASSET_STAGING_DIR" && ! -L "$ASSET_STAGING_DIR" ]] \
        || fail 'A private asset staging directory already exists; preserving it.'
    install -d -o www-data -g www-data -m 0700 "$ASSET_STAGING_DIR"
    cp -a -- "$source/." "$ASSET_STAGING_DIR/"
    invalid_entry="$(find "$ASSET_STAGING_DIR" -mindepth 1 \( -type l -o \( ! -type d -a ! -type f \) \) -print -quit)"
    [[ -z "$invalid_entry" ]] || fail 'The staged asset source changed during copying; refusing symlinks or special files.'
    normalize_private_install_assets "$ASSET_STAGING_DIR"
    mv -- "$ASSET_STAGING_DIR" "$INSTALL_ASSETS_ROOT"
    ASSET_STAGING_DIR=''
}

write_install_assets_environment() {
    local env_file="$ENV_ROOT/.env"
    local temporary_file="${ENV_ROOT}/.env.install-assets-$$"

    [[ -f "$env_file" ]] || fail 'The protected AlphaLink environment file is missing.'
    if ! awk -v root="$INSTALL_ASSETS_ROOT" '
        BEGIN { written = 0 }
        /^ALPHALINK_INSTALL_ASSETS_DIRECTORY=/ {
            if (!written) print "ALPHALINK_INSTALL_ASSETS_DIRECTORY=" root
            written = 1
            next
        }
        { print }
        END { if (!written) print "ALPHALINK_INSTALL_ASSETS_DIRECTORY=" root }
    ' "$env_file" > "$temporary_file"; then
        rm -f -- "$temporary_file"
        fail 'Could not update the protected AlphaLink asset-directory setting.'
    fi
    chown root:www-data "$temporary_file"
    chmod 0640 "$temporary_file"
    mv -f -- "$temporary_file" "$env_file"
}

ensure_install_assets_environment() {
    local configured

    [[ -f "$ENV_ROOT/.env" ]] || fail 'The protected AlphaLink environment file is missing.'
    configured="$(sed -n 's/^ALPHALINK_INSTALL_ASSETS_DIRECTORY=//p' "$ENV_ROOT/.env" | tail -n 1)"
    configured="${configured#\"}"
    configured="${configured%\"}"
    if [[ -z "$configured" ]]; then
        write_install_assets_environment
        install -d -o www-data -g www-data -m 0700 "$INSTALL_ASSETS_ROOT"
        info 'Configured the private AlphaLink installation asset directory; stage the reviewed asset bundle there before PMTA installation.'
    fi
}

configure_install_assets() {
    local source="${2:-}"
    local existing_entry invalid_entry

    [[ "${EUID}" -eq 0 ]] || fail 'Run asset setup as root with sudo.'
    [[ -L "$CURRENT_ROOT" && -d "$API_ROOT" && -f "$ENV_ROOT/.env" ]] \
        || fail 'A completed release-based AlphaLink installation was not found.'
    install -d -o www-data -g www-data -m 2770 "$SHARED_ROOT/storage/app/private"

    if [[ -n "$source" ]]; then
        stage_private_install_assets "$source"
    else
        [[ -d "$INSTALL_ASSETS_ROOT" && ! -L "$INSTALL_ASSETS_ROOT" ]] \
            || fail "Stage the canonical asset directory first, or pass its path: --configure-install-assets /absolute/path/to/install-assets."
        existing_entry="$(find "$INSTALL_ASSETS_ROOT" -mindepth 1 -print -quit)"
        [[ -n "$existing_entry" ]] || fail 'The private asset directory is empty; stage the reviewed AlphaLink installation bundle first.'
        [[ -s "$INSTALL_ASSETS_ROOT/pmta/shared/source.conf" && -s "$INSTALL_ASSETS_ROOT/pmta/shared/base.conf" ]] \
            || fail 'The private asset directory is missing the shared PMTA templates required by the application.'
        invalid_entry="$(find "$INSTALL_ASSETS_ROOT" -mindepth 1 \( -type l -o \( ! -type d -a ! -type f \) \) -print -quit)"
        [[ -z "$invalid_entry" ]] || fail 'Installation assets cannot contain symlinks or special files.'
        normalize_private_install_assets "$INSTALL_ASSETS_ROOT"
    fi

    write_install_assets_environment
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" config:cache --no-interaction
    systemctl reload php8.5-fpm
    info "Private AlphaLink installation assets are staged at ${INSTALL_ASSETS_ROOT}. The application will still verify every selected file, reviewed digest, template, and target compatibility before an install can run."
}

deployment_plan() {
    cat <<'EOF'
Deployment plan (no changes are made):
  1. Fetch the chosen GitHub branch into a new immutable release directory.
  2. Build locked PHP and frontend dependencies before application downtime.
  3. Put the app into maintenance mode and stop Horizon, suppression, and scheduler work.
  4. Create and verify a PostgreSQL backup before changing the active release or schema.
  5. Atomically switch the current-release symlink, run migrations, and warm Laravel caches.
  6. Restart PHP-FPM, check the app's HTTPS health, resume queues and schedule,
     then verify Horizon and queue/scheduler services.
  7. On a failed migration or health check, restore the database backup and prior release.

The GitHub token is entered interactively for each private-repository deploy and
is kept only in root-readable temporary files while Git runs. Shared Laravel
storage remains outside releases so uploads survive code updates.
EOF
}

deploy_release() {
    local requested_branch="${2:-}"
    local branch repository token='' temporary_release release_path release_sha
    local previous_release backup_file switched=0 migrations_started=0
    local rollback_ok=1 service horizon_status
    local rollback_domain app_domain old_release current_release quarantine_path
    local reuse_built_release=0
    local -a old_releases=()

    [[ "${EUID}" -eq 0 ]] || fail 'Run deployment as root, for example: sudo bash /var/www/alphalink/current/install-ubuntu-26.sh --deploy.'
    [[ -L "$CURRENT_ROOT" && -d "$API_ROOT" && -d "$SOURCE_ROOT/.git" ]] || fail 'No release-based AlphaLink installation was found at /var/www/alphalink/current.'
    [[ -f "$ENV_ROOT/.env" && -r "$ENV_ROOT/.env" ]] || fail 'The protected AlphaLink environment file is missing.'
    grep -Eq '^DB_CONNECTION="?pgsql"?$' "$ENV_ROOT/.env" || fail 'Refusing deploy: the installed application is not configured for PostgreSQL.'
    grep -Eq '^DB_DATABASE="?alphalink"?$' "$ENV_ROOT/.env" || fail 'Refusing deploy: the installed PostgreSQL database name does not match this installer.'
    grep -Eq '^DB_USERNAME="?alphalink"?$' "$ENV_ROOT/.env" || fail 'Refusing deploy: the installed PostgreSQL role does not match this installer.'
    [[ -f "$API_ROOT/artisan" && -f "$API_ROOT/composer.lock" && -f "$API_ROOT/package-lock.json" ]] || fail 'The active release is incomplete.'
    [[ -d "$SHARED_ROOT/storage" ]] || fail 'The shared persistent storage directory is missing; refusing to risk uploaded files.'
    [[ -d "$BACKUP_ROOT" ]] || fail 'The protected PostgreSQL backup directory is missing.'
    [[ -x /usr/lib/postgresql/18/bin/pg_dump && -x /usr/lib/postgresql/18/bin/pg_restore ]] || fail 'PostgreSQL 18 backup utilities are unavailable.'
    [[ ! -f "$SHARED_ROOT/storage/framework/maintenance.php" ]] || fail 'The app is already in maintenance mode; resolve that state before deploying.'
    [[ -t 0 ]] || fail 'Run deployment from an interactive terminal to enter private repository credentials safely.'

    branch="$requested_branch"
    if [[ -z "$branch" ]]; then
        branch="${GITHUB_BRANCH:-$(git -C "$SOURCE_ROOT" branch --show-current)}"
    fi
    [[ "$branch" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$branch" != *..* && "$branch" != */ ]] || fail 'The requested branch name is invalid.'
    git -C "$CURRENT_ROOT" check-ref-format --branch "$branch" >/dev/null || fail 'The requested branch name is invalid.'

    repository="$(git -C "$SOURCE_ROOT" remote get-url origin)"
    [[ "$repository" =~ ^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(\.git)?$ ]] || fail 'The origin must be a GitHub HTTPS URL without embedded credentials.'
    if [[ -n "$(git -C "$SOURCE_ROOT" status --porcelain --untracked-files=normal)" ]]; then
        fail 'The protected Git source checkout has unexpected changes; deployment stopped without touching the active release.'
    fi
    ensure_install_assets_environment
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" migrate:status --no-interaction >/dev/null

    read -r -s -p 'GitHub read-only token (leave blank for a public repository): ' token
    printf '\n'
    install -d -o root -g www-data -m 0750 "$RELEASES_ROOT"
    temporary_release="${RELEASES_ROOT}/.deploy-stage-$$"
    DEPLOY_STAGING_DIR="$temporary_release"
    [[ ! -e "$temporary_release" ]] || fail 'A deployment staging directory already exists.'
    info "Fetching ${repository#https://github.com/} (${branch}) into a separate release..."
    fetch_repository_branch "$SOURCE_ROOT" "$branch" "$token"
    unset token
    release_sha="$(git -C "$SOURCE_ROOT" rev-parse FETCH_HEAD)"
    release_path="${RELEASES_ROOT}/${release_sha}"
    current_release="$(readlink -f "$CURRENT_ROOT")"
    [[ "$current_release" == "$RELEASES_ROOT/"* ]] || fail 'The current release symlink points outside the managed release directory.'

    if [[ "$current_release" == "$release_path" ]]; then
        [[ -d "$release_path" && ! -L "$release_path" \
            && -f "$release_path/apps/api/vendor/autoload.php" \
            && -s "$release_path/apps/api/public/build/manifest.json" \
            && -L "$release_path/apps/api/storage" \
            && "$(readlink -f "$release_path/apps/api/storage")" == "$(readlink -f "$SHARED_ROOT/storage")" ]] \
            || fail "The active release ${release_sha} is incomplete; preserving it and refusing to switch releases."
        install -d -o www-data -g www-data -m 2770 "$release_path/apps/api/bootstrap/cache"
        info "Release ${release_sha} is already active; validating its route cache and HTTPS health instead of rebuilding it..."
        runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$release_path/apps/api/artisan" route:cache
        normalize_release_permissions "$release_path"
        app_domain="$(nginx_app_domain /etc/nginx/sites-available/alphalink)"
        valid_app_domain "$app_domain" || fail 'Could not find a valid AlphaLink hostname in the Nginx site configuration.'
        curl --fail --silent --show-error --output /dev/null \
            --resolve "${app_domain}:443:127.0.0.1" "https://${app_domain}/up"
        info "Release ${release_sha} is already deployed and passed its HTTPS health check; no database migration was needed."
        return 0
    fi

    if [[ -e "$release_path" || -L "$release_path" ]]; then
        if [[ -d "$release_path" && ! -L "$release_path" \
            && -f "$release_path/apps/api/vendor/autoload.php" \
            && -s "$release_path/apps/api/public/build/manifest.json" \
            && -L "$release_path/apps/api/storage" \
            && "$(readlink -f "$release_path/apps/api/storage")" == "$(readlink -f "$SHARED_ROOT/storage")" ]]; then
            reuse_built_release=1
            info "Reusing the complete inactive release ${release_sha} left by an earlier deployment attempt."
        else
            quarantine_path="${RELEASES_ROOT}/.incomplete-${release_sha}-$(date -u +%Y%m%dT%H%M%SZ)-$$"
            mv -- "$release_path" "$quarantine_path"
            info "Preserved the incomplete prior release at ${quarantine_path}; building a fresh copy of ${release_sha}."
        fi
    fi

    if (( reuse_built_release == 0 )); then
        mkdir -m 0750 "$temporary_release"
        git -C "$SOURCE_ROOT" archive --format=tar "$release_sha" | tar -xf - -C "$temporary_release"
        [[ -f "$temporary_release/apps/api/artisan" && -f "$temporary_release/apps/api/composer.lock" && -f "$temporary_release/apps/api/package-lock.json" ]] || fail 'The selected branch is missing Laravel or a committed frontend lockfile.'

        install -d -o www-data -g www-data -m 2770 \
            "$SHARED_ROOT/storage/app/private" "$SHARED_ROOT/storage/app/public" \
            "$SHARED_ROOT/storage/framework/cache/data" "$SHARED_ROOT/storage/framework/sessions" \
            "$SHARED_ROOT/storage/framework/views" "$SHARED_ROOT/storage/logs" \
            "$SHARED_ROOT/storage/framework/uploads" "$SHARED_ROOT/storage/framework/tmp" \
            "$SHARED_ROOT/storage/framework/nginx-body"
        rm -rf -- "$temporary_release/apps/api/storage"
        ln -s "$SHARED_ROOT/storage" "$temporary_release/apps/api/storage"
        chown -R root:www-data "$temporary_release"
        find "$temporary_release" -type d -exec chmod 0750 {} +
        find "$temporary_release" -type f -exec chmod 0640 {} +
        normalize_shared_storage_permissions "$temporary_release/apps/api/bootstrap/cache"
        chmod 0750 "$temporary_release" "$temporary_release/apps/api"

        info "Building release ${release_sha} before downtime..."
        cd "$temporary_release/apps/api"
        ALPHALINK_ENV_DIR="$ENV_ROOT" COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 \
            composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
        composer check-platform-reqs --no-dev
        npm ci --no-audit --no-fund
        VITE_APP_NAME=AlphaLink npm run build
        [[ -s public/build/manifest.json ]] || fail 'Vite did not produce the asset manifest.'
        rm -rf -- node_modules
        install -d -o www-data -g www-data -m 2770 "$temporary_release/apps/api/bootstrap/cache"
        info 'Preflighting Laravel route serialization before downtime...'
        runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" \
            php "$temporary_release/apps/api/artisan" route:cache
        normalize_release_permissions "$temporary_release"
        mv -- "$temporary_release" "$release_path"
        DEPLOY_STAGING_DIR=''
    else
        install -d -o www-data -g www-data -m 2770 "$release_path/apps/api/bootstrap/cache"
        info 'Preflighting reused Laravel release route serialization before downtime...'
        runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" \
            php "$release_path/apps/api/artisan" route:cache
        normalize_release_permissions "$release_path"
    fi

    previous_release="$current_release"
    backup_file="${BACKUP_ROOT}/deploy-${release_sha}-$(date -u +%Y%m%dT%H%M%SZ).dump"
    rollback_deploy() {
        local exit_status=$?
        local failed_line="${1:-unknown}"
        trap - ERR
        set +e
        info "Deployment health step failed near installer line ${failed_line} (exit ${exit_status}); restoring the previous application state..."
        if [[ ! -f "$SHARED_ROOT/storage/framework/maintenance.php" ]]; then
            runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" down --retry=60 >/dev/null 2>&1 || true
        fi
        systemctl stop alphalink-schedule.timer alphalink-schedule.service alphalink-horizon.service \
            alphalink-drop-suppression.service php8.5-fpm >/dev/null 2>&1 || true
        for service in alphalink-schedule.service alphalink-horizon.service alphalink-drop-suppression.service php8.5-fpm; do
            if systemctl is-active --quiet "$service"; then
                rollback_ok=0
            fi
        done
        if (( migrations_started == 1 )); then
            if [[ -s "$backup_file" ]] && (( rollback_ok == 1 )); then
                runuser -u postgres -- /usr/lib/postgresql/18/bin/dropdb --if-exists --force "$DB_NAME" >/dev/null 2>&1 || rollback_ok=0
                runuser -u postgres -- /usr/lib/postgresql/18/bin/createdb --owner="$DB_USER" --encoding=UTF8 \
                    --template=template0 "$DB_NAME" >/dev/null 2>&1 || rollback_ok=0
                runuser -u postgres -- /usr/lib/postgresql/18/bin/pg_restore --no-owner \
                    --role="$DB_USER" --dbname="$DB_NAME" "$backup_file" >/dev/null 2>&1 || rollback_ok=0
            else
                rollback_ok=0
            fi
        fi
        if (( switched == 1 )); then
            atomic_switch_release "$previous_release" || rollback_ok=0
        fi
        if (( rollback_ok == 1 )); then
            runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" optimize:clear >/dev/null 2>&1 || true
            if systemctl restart php8.5-fpm >/dev/null 2>&1 \
                && systemctl start alphalink-horizon.service alphalink-drop-suppression.service >/dev/null 2>&1; then
                :
            else
                rollback_ok=0
            fi
            if (( rollback_ok == 1 )) && [[ -f "$SHARED_ROOT/storage/framework/maintenance.php" ]]; then
                runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" up >/dev/null 2>&1 || rollback_ok=0
            fi
            if (( rollback_ok == 1 )); then
                systemctl start alphalink-schedule.timer >/dev/null 2>&1 || rollback_ok=0
            fi
            if (( rollback_ok == 1 )); then
                for service in php8.5-fpm alphalink-horizon.service alphalink-drop-suppression.service; do
                    systemctl is-active --quiet "$service" || rollback_ok=0
                done
                runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" horizon:status 2>&1 | grep -qi 'running' || rollback_ok=0
                rollback_domain="$(nginx_app_domain /etc/nginx/sites-available/alphalink)"
                if valid_app_domain "$rollback_domain"; then
                    curl --fail --silent --show-error --output /dev/null \
                        --resolve "${rollback_domain}:443:127.0.0.1" "https://${rollback_domain}/up" || rollback_ok=0
                else
                    rollback_ok=0
                fi
            fi
        fi
        if (( rollback_ok == 0 )); then
            systemctl start php8.5-fpm >/dev/null 2>&1 || true
            runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" down --retry=60 >/dev/null 2>&1 || true
            info 'AUTOMATIC ROLLBACK IS INCOMPLETE. Leave the app in maintenance mode and inspect PostgreSQL, the current symlink, and systemd services before reopening traffic.'
        else
            info 'The previous release and pre-deploy PostgreSQL backup have been restored.'
        fi
        exit "$exit_status"
    }
    trap 'rollback_deploy "$LINENO"' ERR

    info 'Entering maintenance mode and pausing background work...'
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" down --retry=60
    systemctl stop alphalink-schedule.timer alphalink-schedule.service alphalink-horizon.service alphalink-drop-suppression.service
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" schedule:interrupt

    info 'Creating and verifying the pre-deploy PostgreSQL backup...'
    runuser -u postgres -- /usr/lib/postgresql/18/bin/pg_dump --format=custom --dbname=alphalink --file="$backup_file"
    [[ -s "$backup_file" ]]
    runuser -u postgres -- /usr/lib/postgresql/18/bin/pg_restore --list "$backup_file" >/dev/null
    chmod 0600 "$backup_file"

    info "Activating release ${release_sha} and applying PostgreSQL migrations..."
    atomic_switch_release "$release_path"
    switched=1
    migrations_started=1
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" migrate --force --no-interaction
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" optimize
    env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" storage:link
    systemctl restart php8.5-fpm
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" up
    app_domain="$(nginx_app_domain /etc/nginx/sites-available/alphalink)"
    valid_app_domain "$app_domain" || fail 'Could not find a valid AlphaLink hostname in the Nginx site configuration.'
    curl --fail --silent --show-error --output /dev/null \
        --resolve "${app_domain}:443:127.0.0.1" "https://${app_domain}/up"
    systemctl start alphalink-horizon.service alphalink-drop-suppression.service alphalink-schedule.timer
    for service in php8.5-fpm alphalink-horizon.service alphalink-drop-suppression.service alphalink-schedule.timer; do
        info "Verifying deployment service ${service}..."
        systemctl is-active --quiet "$service"
    done
    info 'Verifying Horizon supervisor status...'
    wait_for_horizon
    trap - ERR
    mapfile -t old_releases < <(find "$RELEASES_ROOT" -mindepth 1 -maxdepth 1 -type d \
        -regextype posix-extended -regex '.*/[a-f0-9]{40}' -printf '%T@ %p\n' | sort -rn | awk 'NR > 5 { $1 = ""; sub(/^ /, ""); print }')
    for old_release in "${old_releases[@]}"; do
        [[ "$old_release" == "$release_path" || "$old_release" == "$previous_release" ]] || rm -rf -- "$old_release"
    done
    info "Deployment ${release_sha} completed and passed the local HTTPS health check. PostgreSQL backup: ${backup_file}"
}

resume_initial_install() {
    local requested_branch="${2:-master}"
    local branch token='' release_sha release_path current_release staging_path backup_file
    local app_url app_domain acme_email ssh_port detected_ssh_port backup_match
    local switched=0 migrations_started=0 rollback_ok=1

    [[ "${EUID}" -eq 0 ]] || fail 'Run the initial-install recovery as root with sudo.'
    [[ -t 0 ]] || fail 'Run recovery from an interactive terminal so the GitHub token and certificate email can be entered safely.'
    [[ -L "$CURRENT_ROOT" && -d "$API_ROOT" && -d "$SOURCE_ROOT/.git" ]] || fail 'No interrupted release-based AlphaLink installation was found.'
    [[ -f "$ENV_ROOT/.env" && -r "$ENV_ROOT/.env" ]] || fail 'The protected AlphaLink environment file is missing.'
    grep -Eq '^DB_CONNECTION="?pgsql"?$' "$ENV_ROOT/.env" || fail 'Refusing recovery: the installed application is not configured for PostgreSQL.'
    grep -Eq '^DB_DATABASE="?alphalink"?$' "$ENV_ROOT/.env" || fail 'Refusing recovery: the database name does not match this installer.'
    grep -Eq '^DB_USERNAME="?alphalink"?$' "$ENV_ROOT/.env" || fail 'Refusing recovery: the PostgreSQL role does not match this installer.'
    [[ -d "$SHARED_ROOT/storage" && -d "$BACKUP_ROOT" ]] || fail 'The interrupted install is missing persistent storage or its protected backup directory.'
    [[ -f /etc/nginx/sites-available/alphalink && -L /etc/nginx/sites-enabled/alphalink ]] || fail 'The interrupted install did not reach the Nginx configuration stage.'
    [[ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/zz-alphalink-env.conf" ]] || fail 'The interrupted install did not reach the PHP-FPM configuration stage.'
    [[ -f /etc/systemd/system/alphalink-horizon.service && -f /etc/systemd/system/alphalink-drop-suppression.service ]] || fail 'The interrupted install did not finish writing its queue worker units.'
    [[ -f /etc/systemd/system/alphalink-db-backup.service && -f /etc/systemd/system/alphalink-db-backup.timer ]] || fail 'The interrupted install did not finish writing its database backup units.'
    [[ ! -f "$SHARED_ROOT/storage/framework/maintenance.php" ]] || fail 'The app is already in maintenance mode; resolve that state before resuming.'
    [[ -z "$(git -C "$SOURCE_ROOT" status --porcelain --untracked-files=normal)" ]] || fail 'The protected Git source checkout has unexpected changes; recovery stopped without changing the active release.'
    ensure_install_assets_environment

    branch="$requested_branch"
    [[ "$branch" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$branch" != *..* && "$branch" != */ ]] || fail 'The requested branch name is invalid.'
    git -C "$SOURCE_ROOT" check-ref-format --branch "$branch" >/dev/null || fail 'The requested branch name is invalid.'

    app_url="$(sed -n 's/^APP_URL=//p' "$ENV_ROOT/.env" | head -n 1)"
    app_url="${app_url#\"}"
    app_url="${app_url%\"}"
    app_domain="${app_url#https://}"
    app_domain="${app_domain%%/*}"
    [[ "$app_domain" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || fail 'The saved application URL is not a valid HTTPS hostname.'

    acme_email="${ACME_EMAIL:-}"
    if [[ -z "$acme_email" ]]; then
        read -r -p "Let's Encrypt contact email: " acme_email
    fi
    [[ "$acme_email" == *@*.* && "$acme_email" != *[[:space:]]* ]] || fail 'Enter a valid certificate contact email.'

    ssh_port="${ALPHALINK_SSH_PORT:-}"
    if [[ -z "$ssh_port" ]]; then
        detected_ssh_port="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }' || true)"
        ssh_port="${detected_ssh_port:-22}"
    fi
    [[ "$ssh_port" =~ ^[0-9]+$ ]] && (( ssh_port > 0 && ssh_port <= 65535 )) || fail 'The detected SSH port is invalid.'

    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" migrate:status --no-interaction >/dev/null

    read -r -s -p 'GitHub read-only token (leave blank for a public repository): ' token
    printf '\n'
    info "Fetching ${branch} for the interrupted installation..."
    fetch_repository_branch "$SOURCE_ROOT" "$branch" "$token"
    unset token
    release_sha="$(git -C "$SOURCE_ROOT" rev-parse FETCH_HEAD)"
    release_path="${RELEASES_ROOT}/${release_sha}"
    current_release="$(readlink -f "$CURRENT_ROOT")"
    [[ "$current_release" == "$RELEASES_ROOT/"* ]] || fail 'The current release symlink points outside the managed release directory.'

    install -d -o postgres -g postgres -m 0700 "$BACKUP_ROOT"
    backup_file="${BACKUP_ROOT}/pre-resume-${release_sha}-$(date -u +%Y%m%dT%H%M%SZ).dump"
    info 'Creating and verifying a PostgreSQL backup before resuming...'
    runuser -u postgres -- /usr/lib/postgresql/18/bin/pg_dump --format=custom --dbname=alphalink --file="$backup_file"
    [[ -s "$backup_file" ]]
    runuser -u postgres -- /usr/lib/postgresql/18/bin/pg_restore --list "$backup_file" >/dev/null
    chmod 0600 "$backup_file"

    if [[ "$current_release" != "$release_path" ]]; then
        if [[ -e "$release_path" ]]; then
            [[ -d "$release_path" && ! -L "$release_path" \
                && -f "$release_path/apps/api/vendor/autoload.php" \
                && -s "$release_path/apps/api/public/build/manifest.json" \
                && -L "$release_path/apps/api/storage" ]] \
                || fail "Inactive release ${release_sha} exists but is incomplete; preserving it for inspection."
            info "Reusing the complete inactive release ${release_sha} left by an earlier recovery attempt..."
            normalize_release_permissions "$release_path"
        else
            staging_path="${RELEASES_ROOT}/.deploy-stage-resume-$$"
            DEPLOY_STAGING_DIR="$staging_path"
            mkdir -m 0750 "$staging_path"
            git -C "$SOURCE_ROOT" archive --format=tar "$release_sha" | tar -xf - -C "$staging_path"
            [[ -f "$staging_path/apps/api/artisan" && -f "$staging_path/apps/api/composer.lock" && -f "$staging_path/apps/api/package-lock.json" ]] || fail 'The selected branch is missing Laravel or a committed frontend lockfile.'

            install -d -o www-data -g www-data -m 2770 \
                "$SHARED_ROOT/storage/app/private" "$SHARED_ROOT/storage/app/public" \
                "$SHARED_ROOT/storage/framework/cache/data" "$SHARED_ROOT/storage/framework/sessions" \
                "$SHARED_ROOT/storage/framework/views" "$SHARED_ROOT/storage/logs" \
                "$SHARED_ROOT/storage/framework/uploads" "$SHARED_ROOT/storage/framework/tmp" \
                "$SHARED_ROOT/storage/framework/nginx-body"
            rm -rf -- "$staging_path/apps/api/storage"
            ln -s "$SHARED_ROOT/storage" "$staging_path/apps/api/storage"
            chown -R root:www-data "$staging_path"
            find "$staging_path" -type d -exec chmod 0750 {} +
            find "$staging_path" -type f -exec chmod 0640 {} +
            normalize_shared_storage_permissions "$staging_path/apps/api/bootstrap/cache"
            chmod 0750 "$staging_path" "$staging_path/apps/api"

            info "Building corrected release ${release_sha}..."
            cd "$staging_path/apps/api"
            ALPHALINK_ENV_DIR="$ENV_ROOT" COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 \
                composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
            composer check-platform-reqs --no-dev
            npm ci --no-audit --no-fund
            VITE_APP_NAME=AlphaLink npm run build
            [[ -s public/build/manifest.json ]] || fail 'Vite did not produce the asset manifest.'
            rm -rf -- node_modules
            install -d -o www-data -g www-data -m 2770 "$staging_path/apps/api/bootstrap/cache"
            info 'Preflighting Laravel route serialization before resuming...'
            runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" \
                php "$staging_path/apps/api/artisan" route:cache
            normalize_release_permissions "$staging_path"
            mv -- "$staging_path" "$release_path"
            DEPLOY_STAGING_DIR=''
        fi
    elif [[ ! -d "$release_path/apps/api" ]]; then
        fail 'The active release is incomplete.'
    fi

    install -d -o www-data -g www-data -m 2770 "$release_path/apps/api/bootstrap/cache"
    info 'Preflighting Laravel route serialization before resuming...'
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$release_path/apps/api/artisan" route:cache
    normalize_release_permissions "$release_path"

    resume_initial_install_rollback() {
        local exit_status=$?
        trap - ERR
        set +e
        info 'Resume failed; restoring the pre-resume database backup and prior release...'
        if (( migrations_started == 1 )); then
            runuser -u postgres -- /usr/lib/postgresql/18/bin/dropdb --if-exists --force alphalink >/dev/null 2>&1 || rollback_ok=0
            if (( rollback_ok == 1 )); then
                runuser -u postgres -- /usr/lib/postgresql/18/bin/createdb --owner=alphalink --encoding=UTF8 --template=template0 alphalink >/dev/null 2>&1 || rollback_ok=0
            fi
            if (( rollback_ok == 1 )); then
                runuser -u postgres -- /usr/lib/postgresql/18/bin/pg_restore --no-owner --role=alphalink --dbname=alphalink "$backup_file" >/dev/null 2>&1 || rollback_ok=0
            fi
        fi
        if (( switched == 1 )); then
            atomic_switch_release "$current_release" || rollback_ok=0
        fi
        if (( rollback_ok == 1 )); then
            info 'The pre-resume database backup and prior release were restored.'
        else
            info 'AUTOMATIC RECOVERY IS INCOMPLETE. Keep the app closed and inspect the PostgreSQL backup and current release symlink.'
        fi
        exit "$exit_status"
    }

    if [[ "$current_release" != "$release_path" ]]; then
        atomic_switch_release "$release_path"
        switched=1
    fi
    migrations_started=1
    trap resume_initial_install_rollback ERR
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" migrate --force --no-interaction
    runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" optimize
    trap - ERR
    migrations_started=0

    info 'Finishing PHP-FPM, Nginx, Horizon, scheduler, and backup setup...'
    env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" storage:link
    nginx -t
    systemctl daemon-reload
    systemctl restart "php${PHP_VERSION}-fpm"
    systemctl enable --now alphalink-horizon.service alphalink-drop-suppression.service \
        alphalink-schedule.timer alphalink-db-backup.timer
    for service in "php${PHP_VERSION}-fpm" alphalink-horizon.service alphalink-drop-suppression.service; do
        systemctl is-active --quiet "$service" || fail "Service did not become active: ${service}"
    done
    for attempt in {1..12}; do
        if HORIZON_STATUS="$(runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php "$API_ROOT/artisan" horizon:status 2>&1)" && grep -qi 'running' <<< "$HORIZON_STATUS"; then
            break
        fi
        [[ "$attempt" -lt 12 ]] || fail 'Horizon did not report a running supervisor; inspect alphalink-horizon.service and Valkey.'
        sleep 2
    done

    ufw allow "${ssh_port}/tcp" comment 'Preserve AlphaLink server SSH access'
    ufw allow 80/tcp comment 'AlphaLink HTTP and ACME challenge'
    ufw allow 443/tcp comment 'AlphaLink HTTPS'
    ufw --force enable
    systemctl reload nginx

    systemctl start alphalink-db-backup.service
    backup_match="$(find "$BACKUP_ROOT" -maxdepth 1 -type f -name 'alphalink-*.dump' -size +0c -print -quit)"
    [[ -n "$backup_match" ]] || fail 'The PostgreSQL backup check did not produce a non-empty file.'

    info "Requesting the HTTPS certificate for ${app_domain}..."
    certbot --nginx --non-interactive --agree-tos --redirect --email "$acme_email" -d "$app_domain"
    for attempt in {1..12}; do
        if curl --fail --silent --show-error --output /dev/null "https://${app_domain}/up"; then
            break
        fi
        [[ "$attempt" -lt 12 ]] || fail 'The HTTPS health check failed; inspect Nginx, TLS, PHP-FPM, and the application log.'
        sleep 5
    done

    info "Interrupted installation resumed successfully at https://${app_domain}. PostgreSQL backup: ${backup_file}"
    info 'The API token printed before the interrupted install was exposed in chat; revoke it before using the application.'
}

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
Usage:
  sudo bash install-ubuntu-26.sh [--help|--plan|--worker-sizing CPU RAM_MiB PG_MAX_CONNECTIONS]
  sudo bash /var/www/alphalink/current/install-ubuntu-26.sh --deploy [BRANCH]
  sudo bash /var/www/alphalink/current/install-ubuntu-26.sh --deploy-plan
  sudo bash /var/www/alphalink/current/install-ubuntu-26.sh --configure-install-assets [SOURCE_DIR]
  sudo bash install-ubuntu-26.sh --resume-initial-install [BRANCH]

Install AlphaLink on a fresh Ubuntu 26.04 LTS server, then use --deploy for
atomic GitHub releases. The initial installer asks for the GitHub repository,
application hostname, TLS contact email, and first workspace owner. It also
asks you to type and confirm the new PostgreSQL password; the entry is hidden.
Private GitHub tokens are read without echo, used only for the current clone
or fetch, and never saved on the server.

For PMTA-ready setup, upload the private licensed bundle to /root/install-assets
before running this script; it is detected automatically. The installer asks
for AlphaLink's stable public IPv4 egress address(es) and stores them encrypted
as PMTA RELAY SOURCE IPS in the initial workspace. Enter SKIP for the asset
prompt only when this is an app-only deployment without PMTA installation.

Optional non-secret environment inputs:
  GITHUB_REPO       owner/repo or https://github.com/owner/repo[.git]
  GITHUB_BRANCH     branch to install (default: master)
  ALPHALINK_DOMAIN  DNS hostname for the application
  ACME_EMAIL        Let's Encrypt notification email
  ALPHALINK_SSH_PORT SSH port to keep open in UFW (default: detected/22)
  ALPHALINK_PMTA_RELAY_SOURCE_IPS Stable public IPv4 egress address(es), comma-separated

The installer creates a new PostgreSQL database, never SQLite. It refuses to
replace any existing AlphaLink app path, environment, PostgreSQL role, or DB.
Later --deploy runs create immutable releases, preserve shared uploads, back up
and verify PostgreSQL before migrations, and retain a previous release for
automatic rollback. Licensed PMTA files are not stored in GitHub. Upload them
to /root/install-assets or enter their absolute path when prompted. Existing
installs can stage them with --configure-install-assets /root/install-assets.
The PMTA installation gate still verifies selected digests and target
compatibility before remote changes.

If a fresh installation stops after creating its first owner and workspace,
copy the current installer to the server and run --resume-initial-install. It
preserves the existing PostgreSQL database and owner, then completes setup.
EOF
}

plan() {
    cat <<'EOF'
Plan (no changes are made):
  1. Require a fresh Ubuntu 26.04 LTS host and collect deployment settings.
  2. Enable Ubuntu's universe packages; install PHP 8.5, Composer, Node.js,
     Nginx, PostgreSQL 18, Valkey (Redis protocol), Certbot, and required PHP
     extensions.
  3. Clone a protected Git source checkout and export the selected branch into
     an immutable release without saving its access token.
  4. Prompt for and confirm a hidden PostgreSQL password; create a
     least-privilege PostgreSQL role and a new UTF-8 database.
  5. Generate protected Laravel and contact-lookup keys in /etc/alphalink/.env.
  6. Install locked Composer/npm dependencies, build assets, and run migrations.
  7. Activate the first release through an atomic current symlink; keep Laravel
     storage shared outside releases for future code updates.
  8. Size Horizon from the target host's available CPU, memory, and PostgreSQL
     connection budget; bootstrap the first owner and encrypted PMTA relay
     source IP settings; configure Nginx/TLS, the PostgreSQL suppression worker,
     and schedule.
  9. Detect and stage the licensed private PMTA bundle (outside GitHub), then
     keep its files private across installs, resumes, and deployments.
 10. Enable UFW rules and a protected daily PostgreSQL dump (14-day retention).

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
    --plan) [[ "$#" -eq 1 ]] || fail 'Use --plan without additional arguments.'; plan; exit 0 ;;
    --deploy-plan) [[ "$#" -eq 1 ]] || fail 'Use --deploy-plan without additional arguments.'; deployment_plan; exit 0 ;;
    --deploy) [[ "$#" -le 2 ]] || fail 'Use --deploy [BRANCH].'; deploy_release "$@"; exit 0 ;;
    --configure-install-assets) [[ "$#" -le 2 ]] || fail 'Use --configure-install-assets [SOURCE_DIR].'; configure_install_assets "$@"; exit 0 ;;
    --resume-initial-install) [[ "$#" -le 2 ]] || fail 'Use --resume-initial-install [BRANCH].'; resume_initial_install "$@"; exit 0 ;;
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

INSTALL_ASSETS_SOURCE="${ALPHALINK_INSTALL_ASSETS_SOURCE:-}"
if [[ -z "$INSTALL_ASSETS_SOURCE" ]]; then
    if [[ -d /root/install-assets && ! -L /root/install-assets ]]; then
        INSTALL_ASSETS_SOURCE=/root/install-assets
        info 'Detected the uploaded private AlphaLink asset bundle at /root/install-assets.'
    else
        read -r -p 'Private AlphaLink installation asset directory (absolute path, or SKIP for app-only setup): ' INSTALL_ASSETS_SOURCE
    fi
fi
if [[ "${INSTALL_ASSETS_SOURCE,,}" == skip ]]; then
    INSTALL_ASSETS_SOURCE=''
    info 'Continuing without PMTA assets by explicit request; PMTA server installation remains unavailable until the reviewed private bundle is staged.'
elif [[ -n "$INSTALL_ASSETS_SOURCE" ]]; then
    INSTALL_ASSETS_SOURCE="$(validate_install_assets_source "$INSTALL_ASSETS_SOURCE")"
else
    fail 'A private PMTA bundle is required for a PMTA-ready install. Upload it to /root/install-assets or enter SKIP to install AlphaLink without PMTA deployment.'
fi

PMTA_RELAY_SOURCE_IPS="${ALPHALINK_PMTA_RELAY_SOURCE_IPS:-}"
if [[ -z "$PMTA_RELAY_SOURCE_IPS" ]]; then
    read -r -p 'AlphaLink stable public IPv4 egress address(es) for PMTA relay, comma-separated: ' PMTA_RELAY_SOURCE_IPS
fi
PMTA_RELAY_SOURCE_IPS="${PMTA_RELAY_SOURCE_IPS//[[:space:]]/}"
validate_pmta_relay_ipv4_list "$PMTA_RELAY_SOURCE_IPS" \
    || fail 'Enter one or more stable, public IPv4 egress addresses; private, reserved, and IPv6 addresses are not accepted.'

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
if [[ -n "$INSTALL_ASSETS_SOURCE" ]]; then
    printf 'Private installation assets will be copied to %s with owner-only permissions.\n' "$INSTALL_ASSETS_ROOT"
fi
printf 'PMTA relay source IPv4 address(es) will be encrypted in the initial workspace settings.\n'
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
install -d -o root -g www-data -m 0750 "$APP_ROOT" "$RELEASES_ROOT" "$SHARED_ROOT"
SOURCE_STAGING_DIR="${APP_ROOT}/.source-stage-$$"
clone_repository "$REPOSITORY" "$BRANCH" "$SOURCE_STAGING_DIR" "$GITHUB_TOKEN"
unset GITHUB_TOKEN
RELEASE_SHA="$(git -C "$SOURCE_STAGING_DIR" rev-parse HEAD)"
RELEASE_PATH="${RELEASES_ROOT}/${RELEASE_SHA}"
[[ ! -e "$RELEASE_PATH" ]] || fail "Initial release ${RELEASE_SHA} already exists."
INSTALL_STAGING_DIR="${RELEASES_ROOT}/.install-stage-$$"
mkdir -m 0750 "$INSTALL_STAGING_DIR"
git -C "$SOURCE_STAGING_DIR" archive --format=tar "$RELEASE_SHA" | tar -xf - -C "$INSTALL_STAGING_DIR"
mv -- "$SOURCE_STAGING_DIR" "$SOURCE_ROOT"
SOURCE_STAGING_DIR=''
mv -- "$INSTALL_STAGING_DIR" "$RELEASE_PATH"
INSTALL_STAGING_DIR=''
RELEASE_API_ROOT="${RELEASE_PATH}/apps/api"

[[ -f "$RELEASE_API_ROOT/artisan" && -f "$RELEASE_API_ROOT/composer.lock" && -f "$RELEASE_API_ROOT/package-lock.json" ]] || fail 'The selected branch is missing Laravel or a committed frontend lockfile.'
git -C "$SOURCE_ROOT" check-ref-format --branch "$BRANCH" >/dev/null || fail 'The selected branch name is not valid.'

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
    "$SHARED_ROOT/storage/app/private" "$SHARED_ROOT/storage/app/public" \
    "$SHARED_ROOT/storage/framework/cache/data" "$SHARED_ROOT/storage/framework/sessions" \
    "$SHARED_ROOT/storage/framework/views" "$SHARED_ROOT/storage/logs" \
    "$SHARED_ROOT/storage/framework/uploads" "$SHARED_ROOT/storage/framework/tmp" \
    "$SHARED_ROOT/storage/framework/nginx-body" "$RELEASE_API_ROOT/bootstrap/cache"
install -d -o www-data -g www-data -m 0700 "$INSTALL_ASSETS_ROOT"
if [[ -n "$INSTALL_ASSETS_SOURCE" ]]; then
    stage_private_install_assets "$INSTALL_ASSETS_SOURCE"
fi
rm -rf -- "$RELEASE_API_ROOT/storage"
ln -s "$SHARED_ROOT/storage" "$RELEASE_API_ROOT/storage"
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
ALPHALINK_INSTALL_ASSETS_DIRECTORY=${INSTALL_ASSETS_ROOT}
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
cd "$RELEASE_API_ROOT"
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
normalize_release_permissions "$RELEASE_PATH"
normalize_shared_storage_permissions
install -d -o www-data -g www-data -m 0700 \
    "$SHARED_ROOT/storage/framework/uploads" \
    "$SHARED_ROOT/storage/framework/tmp" \
    "$SHARED_ROOT/storage/framework/nginx-body"

info 'Preflighting Laravel route serialization before applying migrations...'
runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php \
    "$RELEASE_API_ROOT/artisan" route:cache

info 'Applying the fresh PostgreSQL migrations...'
atomic_switch_release "$RELEASE_PATH"
runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan migrate --force --no-interaction
ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan storage:link

info 'Creating the initial owner and workspace...'
runuser -u www-data -- env ALPHALINK_ENV_DIR="$ENV_ROOT" php artisan alphalink:bootstrap-owner \
    --name="$OWNER_NAME" --email="$OWNER_EMAIL" --workspace="$WORKSPACE_NAME" \
    --pmta-relay-ips="$PMTA_RELAY_SOURCE_IPS"

info 'Configuring PHP-FPM, Nginx, Horizon, and scheduler...'
cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/zz-alphalink-env.conf" <<POOL
env[ALPHALINK_ENV_DIR] = ${ENV_ROOT}
POOL
cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/99-alphalink.ini" <<PHPINI
expose_php=Off
; Arbitrarily large authenticated imports are staged to private disk and parsed by Horizon in chunks.
upload_max_filesize=0
post_max_size=0
upload_tmp_dir=${SHARED_ROOT}/storage/framework/uploads
sys_temp_dir=${SHARED_ROOT}/storage/framework/tmp
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
    client_body_temp_path ${SHARED_ROOT}/storage/framework/nginx-body 1 2;
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

normalize_shared_storage_permissions "$API_ROOT/bootstrap/cache"
find "$RELEASE_PATH" -type d -exec chmod 0750 {} +
find "$RELEASE_PATH" -type f -exec chmod 0640 {} +
chmod 0750 "$APP_ROOT" "$RELEASES_ROOT" "$SHARED_ROOT" "$API_ROOT"

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
