#!/usr/bin/env bash
#
# Installation and update of the bowling league website on Debian 12/13
# (nginx, PHP-FPM, MariaDB, Let's Encrypt or an existing certificate).
# Requires PHP 8.4 or newer: Debian 13 ships 8.4; on Debian 12 (PHP 8.2)
# install a newer PHP from a third-party source first, e.g. packages.sury.org.
#
# Procedure: copy the project folder to the server, then run inside it
#     sudo bash deploy/install.sh
#
# First run: installs packages, copies the website to $TARGET, creates the
# database, the configuration (incl. TOTP key and base URL) and the first
# admin account, sets up PHP-FPM, nginx and HTTPS.
# Further runs (update): copies the new version, installs dependencies,
# applies new migrations. Configuration and data are kept.
#
# Every installation is an "instance" with its own name; it names the
# database and its user, the directories, the PHP-FPM pool and the nginx site,
# so several leagues can run on one server.
#
# Environment variables (optional):
#   INSTANCE=bowlingliga   name of the instance (a-z, 0-9, _; default: the one
#                          this script installed before, otherwise bowlingliga)
#   TARGET=/var/www.<instance>  installation directory
#   NO_HTTPS=1             no certificate (e.g. behind a proxy doing HTTPS)
#   PHP_VERSION=8.5        PHP version for FPM and command line (default: the
#                          one already installed, else the distribution's)
#
set -euo pipefail

SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_USER=www-data

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
note() { printf '\033[0;33m    %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mError: %s\033[0m\n' "$*" >&2; exit 1; }

ask() {
    # ask <variable> <prompt> [default]
    local name=$1 prompt=$2 default=${3:-} answer
    if [[ -n $default ]]; then
        read -r -p "    $prompt [$default]: " answer
        answer=${answer:-$default}
    else
        read -r -p "    $prompt: " answer
    fi
    printf -v "$name" '%s' "$answer"
}

# --- Prerequisites ---------------------------------------------------------------

[[ $EUID -eq 0 ]] || fail "Please run with sudo or as root."
[[ -f /etc/debian_version ]] || fail "This script is meant for Debian."
[[ -f "$SOURCE/public/index.php" && -f "$SOURCE/composer.json" ]] \
    || fail "Project folder not recognised ($SOURCE). Please start the script from inside the project."

# --- Instance --------------------------------------------------------------------

# Without INSTANCE: the instance this script set up before (recognised by the
# marker in its nginx site), otherwise a new one called "bowlingliga".
if [[ -z ${INSTANCE:-} ]]; then
    mapfile -t FOUND < <(grep -l '(deploy/install.sh)' /etc/nginx/sites-available/* 2>/dev/null | xargs -r -n1 basename)
    if (( ${#FOUND[@]} == 1 )); then
        INSTANCE=${FOUND[0]}
    elif (( ${#FOUND[@]} > 1 )); then
        fail "Several instances found (${FOUND[*]}). Choose one with INSTANCE=<name>."
    else
        INSTANCE=bowlingliga
    fi
fi
[[ $INSTANCE =~ ^[a-z][a-z0-9_]{1,30}$ ]] \
    || fail "Invalid instance name \"$INSTANCE\" (a-z, 0-9 and _, starting with a letter)."

SETTINGS=/etc/$INSTANCE/install.conf
NO_HTTPS="${NO_HTTPS:-0}"
# Default directory of the original score sheets (part of the backup).
SCORE_SHEETS=/var/lib/$INSTANCE/score-sheets
SESSIONS=/var/lib/$INSTANCE/sessions

# --- Settings (taken over from the first run on updates) -------------------------

# HTTPS either with an existing certificate (CERTIFICATE/CERTIFICATE_KEY, on the
# ports HTTPS_PORTS) or via Let's Encrypt (CERTIFICATE empty).
DOMAIN=""; EMAIL=""; CERTIFICATE=""; CERTIFICATE_KEY=""; HTTPS_PORTS="443"; SITE_LANGUAGE="de"
SAVED_TARGET=""; SAVED_REPO=""
if [[ -f $SETTINGS ]]; then
    # shellcheck source=/dev/null
    source "$SETTINGS"
    SAVED_TARGET=${TARGET_DIR:-}
    SAVED_REPO=${REPO_URL:-}
    info "Updating instance $INSTANCE (domain: $DOMAIN)"
else
    info "New installation of instance $INSTANCE – a few questions:"
    ask DOMAIN "Domain of the website (e.g. league.example.org)"
    [[ -n $DOMAIN ]] || fail "A domain is required."
    if [[ $NO_HTTPS != 1 ]]; then
        ask CERTIFICATE "Existing certificate (fullchain.pem, empty = request one from Let's Encrypt)"
        if [[ -n $CERTIFICATE ]]; then
            ask CERTIFICATE_KEY "Its private key" "$(dirname "$CERTIFICATE")/$DOMAIN.key"
            ask HTTPS_PORTS "HTTPS ports, separated by spaces" "443"
        else
            ask EMAIL "E-mail for Let's Encrypt (expiry warnings)"
        fi
    fi
    # Default language: for visitors whose browser asks for none of the
    # site's languages, and for the score sheets of the first season. One
    # catalog lang/<code>.php per language (not LANGUAGE: that is the locale
    # variable of the shell).
    AVAILABLE="$(find "$SOURCE/lang" -maxdepth 1 -name '[a-z][a-z].php' -printf '%f\n' | sed 's/\.php$//' | sort | tr '\n' ' ')"
    ask SITE_LANGUAGE "Default language of the website (${AVAILABLE% })" "de"
    [[ $SITE_LANGUAGE =~ ^[a-z]{2}$ && -f "$SOURCE/lang/$SITE_LANGUAGE.php" ]] \
        || fail "Unknown language \"$SITE_LANGUAGE\" (available: ${AVAILABLE% })."
fi
TARGET="${TARGET:-${SAVED_TARGET:-/var/www.$INSTANCE}}"
REPO_URL="${REPO:-$SAVED_REPO}"

if [[ $NO_HTTPS == 1 ]]; then
    TLS=none
elif [[ -n $CERTIFICATE ]]; then
    TLS=existing
    [[ -r $CERTIFICATE ]] || fail "Certificate $CERTIFICATE is not readable."
    [[ -r $CERTIFICATE_KEY ]] || fail "Key $CERTIFICATE_KEY is not readable."
    for port in $HTTPS_PORTS; do
        [[ $port =~ ^[0-9]+$ ]] || fail "Invalid port \"$port\"."
    done
    [[ -n ${HTTPS_PORTS// /} ]] || fail "At least one HTTPS port is required."
else
    TLS=letsencrypt
fi

# Address of the website as the visitors see it (QR codes on the score sheets).
if [[ $TLS == none ]]; then
    BASE_URL="http://$DOMAIN"
elif [[ $TLS == existing ]]; then
    FIRST_PORT=${HTTPS_PORTS%% *}
    if [[ $FIRST_PORT == 443 ]]; then BASE_URL="https://$DOMAIN"; else BASE_URL="https://$DOMAIN:$FIRST_PORT"; fi
else
    BASE_URL="https://$DOMAIN"
fi

# --- Packages --------------------------------------------------------------------

info "Installing packages"
export DEBIAN_FRONTEND=noninteractive
# A database server that is already running (with databases of other
# applications) is neither replaced nor upgraded; --no-upgrade also leaves all
# other packages already installed (e.g. PHP of another application) at their
# version.
PACKAGES=(
    nginx
    composer unzip rsync openssl ca-certificates
)
if [[ $TLS == letsencrypt ]]; then
    PACKAGES+=(certbot python3-certbot-nginx)
fi
# If PHP is already there (e.g. 8.5 from a third-party source), install the
# extensions exactly for this version instead of pulling in another PHP
# version next to it via the meta packages.
if [[ -z ${PHP_VERSION:-} ]] && command -v php >/dev/null; then
    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
fi
# Stop before installing anything if the PHP version would be too old: the
# one already present (or requested), otherwise the distribution's (Debian 12:
# 8.2).
if [[ -n ${PHP_VERSION:-} ]]; then
    [[ "$(printf '%s\n' 8.4 "$PHP_VERSION" | sort -V | head -n1)" == 8.4 ]] \
        || fail "PHP $PHP_VERSION is too old, at least 8.4 is required."
elif [[ "$(cut -d. -f1 /etc/debian_version)" == 12 ]]; then
    fail "Debian 12 ships PHP 8.2, at least 8.4 is required. Install a newer PHP first (e.g. from packages.sury.org) or use Debian 13."
fi
for extension in fpm cli mysql mbstring gd xml zip; do
    PACKAGES+=("php${PHP_VERSION:-}-$extension")
done
if mariadb -e 'SELECT 1' >/dev/null 2>&1; then
    DB_SERVER_PRESENT=1
else
    DB_SERVER_PRESENT=0
    PACKAGES+=(mariadb-server)
fi
apt-get update -qq
apt-get install -y -qq --no-upgrade "${PACKAGES[@]}" >/dev/null
if [[ $DB_SERVER_PRESENT == 1 ]]; then
    note "Using the database server that is already running."
else
    systemctl enable --now mariadb >/dev/null
fi

PHP_VERSION="${PHP_VERSION:-$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')}"
# Always call exactly this version, even if "php" points to another one
PHP="php$PHP_VERSION"
command -v "$PHP" >/dev/null || fail "$PHP not found."
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' \
    || fail "PHP $PHP_VERSION is too old, at least 8.4 is required."
"$PHP" -r 'exit(extension_loaded("sodium") ? 0 : 1);' \
    || fail "The PHP extension sodium is missing."
FPM_SERVICE="php${PHP_VERSION}-fpm"
FPM_SOCKET="/run/php/$INSTANCE.sock"
note "PHP $PHP_VERSION"

# --- Copy code and dependencies --------------------------------------------------

info "Copying the website to $TARGET"
mkdir -p "$TARGET"
if [[ $SOURCE != "$TARGET" ]]; then
    # Configuration and dependencies of the target stay untouched
    # (--delete does not remove excluded files).
    rsync -a --delete \
        --exclude '.git/' --exclude '.claude/' --exclude 'vendor/' \
        --exclude 'tests/' --exclude '.local/' --exclude 'stdout.txt' \
        --exclude 'phpunit.xml' --exclude '.phpunit.cache/' \
        --exclude 'config/database.php' \
        "$SOURCE/" "$TARGET/"
fi

info "Installing PHP dependencies (composer)"
COMPOSER_ALLOW_SUPERUSER=1 "$PHP" "$(command -v composer)" install --working-dir="$TARGET" \
    --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

# --- Configuration ---------------------------------------------------------------

CONFIG="$TARGET/config/database.php"
if [[ ! -f $CONFIG ]]; then
    # Take over nothing foreign on a fresh installation: an existing user
    # would otherwise get a new password below.
    EXISTING="$(mariadb -N -B -e "
        SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$INSTANCE')
             + (SELECT COUNT(*) FROM mysql.user WHERE User = '$INSTANCE')")"
    [[ $EXISTING == 0 ]] || fail "The database or database user \"$INSTANCE\" already exists.
    If it belongs to an earlier installation, copy its config/database.php to $CONFIG
    and start the script again. Otherwise remove database and user by hand first,
    or choose another instance with INSTANCE=<name>."
    info "Creating the configuration ($CONFIG)"
    DB_PASSWORD="$(openssl rand -base64 33 | tr -dc 'A-Za-z0-9' | head -c 32)"
    TOTP_KEY="$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
    cat > "$CONFIG" <<PHP
<?php

declare(strict_types=1);

// Generated by deploy/install.sh on $(date '+%Y-%m-%d %H:%M').
// Does not belong in the repository. The TOTP key must not change, otherwise
// two-factor authentications that have been set up can no longer be verified.
return [
    'host'            => 'localhost',
    'port'            => 3306,
    'dbname'          => '$INSTANCE',
    'user'            => '$INSTANCE',
    'password'        => '$DB_PASSWORD',
    'charset'         => 'utf8mb4',
    'connect_timeout' => 10,
    'totp_key'        => '$TOTP_KEY',
    'score_sheet_dir' => '$SCORE_SHEETS',
    'base_url'        => '$BASE_URL',
    'language'        => '$SITE_LANGUAGE',
];
PHP
else
    note "The existing configuration is kept."
fi

config_value() {
    "$PHP" -r '$c = require $argv[1]; echo $c[$argv[2]] ?? "";' "$CONFIG" "$1"
}

# Older configurations: add the keys that came later.
if [[ -z $(config_value score_sheet_dir) ]] && ! grep -q "'score_sheet_dir'" "$CONFIG"; then
    sed -i "s|^];|    'score_sheet_dir' => '$SCORE_SHEETS',\n];|" "$CONFIG"
    note "Score sheet directory $SCORE_SHEETS added to the configuration."
fi
# Without base_url the QR codes would take the address from the request's Host header.
if [[ -z $(config_value base_url) ]]; then
    if grep -q "'base_url'" "$CONFIG"; then
        sed -i "s|'base_url' *=> *''|'base_url'        => '$BASE_URL'|" "$CONFIG"
    else
        sed -i "s|^];|    'base_url'        => '$BASE_URL',\n];|" "$CONFIG"
    fi
    note "Base URL $BASE_URL added to the configuration."
fi

# Default language: older configurations keep German, now written down.
if ! grep -q "'language'" "$CONFIG"; then
    sed -i "s|^];|    'language'        => 'de',\n];|" "$CONFIG"
    note "Default language de added to the configuration (change it there)."
fi

DB_NAME="$(config_value dbname)"
DB_USER="$(config_value user)"
DB_PASSWORD="$(config_value password)"
SCORE_SHEETS="$(config_value score_sheet_dir)"

# --- Permissions: the code belongs to root, the web server may only read ---------

chown -R root:"$WEB_USER" "$TARGET"
find "$TARGET" -type d -exec chmod 750 {} +
find "$TARGET" -type f -exec chmod 640 {} +
chmod 750 "$TARGET/deploy/install.sh" "$TARGET/deploy/update.sh"

# --- Database --------------------------------------------------------------------

info "Setting up the database"
SQL_PASSWORD="${DB_PASSWORD//\'/\'\'}"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$SQL_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$SQL_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

info "Applying migrations"
mkdir -p "$SCORE_SHEETS"
"$PHP" "$TARGET/bin/migrate.php"
# Only the web server writes the score sheets (also repairs files that a
# command line tool run as root has created).
chown -R "$WEB_USER":"$WEB_USER" "$SCORE_SHEETS"
find "$SCORE_SHEETS" -type d -exec chmod 750 {} +
find "$SCORE_SHEETS" -type f -exec chmod 640 {} +
note "Score sheets: $SCORE_SHEETS"

# --- First admin account ---------------------------------------------------------

ADMIN_COUNT="$(mariadb -N -B -e "SELECT COUNT(*) FROM \`$DB_NAME\`.admin_account")"
if [[ $ADMIN_COUNT == 0 ]]; then
    info "Creating the first admin account"
    ask ADMIN_NAME "Username" "admin"
    until "$PHP" "$TARGET/bin/create-admin.php" "$ADMIN_NAME" --only-if-empty; do
        note "Please try again."
    done
fi

# --- PHP settings ----------------------------------------------------------------

info "Configuring PHP-FPM"
# Own pool with its own socket: the settings apply only to this instance,
# other PHP applications on the server keep their own.
mkdir -p "$SESSIONS"
chown "$WEB_USER":"$WEB_USER" "$SESSIONS"
chmod 700 "$SESSIONS"
cat > "/etc/php/$PHP_VERSION/fpm/pool.d/$INSTANCE.conf" <<INI
; Bowlingliga instance $INSTANCE (deploy/install.sh)
[$INSTANCE]
user = $WEB_USER
group = $WEB_USER
listen = $FPM_SOCKET
listen.owner = $WEB_USER
listen.group = $WEB_USER
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 60s

php_admin_value[upload_max_filesize] = 10M      ; photos of score sheets
php_admin_value[post_max_size] = 12M
; Own session directory: Debian's clean-up job only knows the global
; lifetime (24 minutes), so PHP cleans up here itself.
php_admin_value[session.save_path] = $SESSIONS
php_admin_value[session.gc_maxlifetime] = 1800  ; at least as long as the 30-minute timeout
php_admin_value[session.gc_probability] = 1
php_admin_value[session.gc_divisor] = 100
php_admin_flag[session.use_strict_mode] = on    ; only session ids generated by the server
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
INI
php-fpm"$PHP_VERSION" -t >/dev/null 2>&1 || fail "PHP-FPM configuration is broken (php-fpm$PHP_VERSION -t)."
# reload instead of restart: running requests of other applications are not aborted
systemctl reload-or-restart "$FPM_SERVICE"

# --- nginx -----------------------------------------------------------------------

info "Configuring nginx"
SITE=/etc/nginx/sites-available/$INSTANCE

# Throttle the login: at most 10 attempts per minute and IP (short bursts up
# to 5 allowed). Makes trying out passwords and codes and deliberately
# locking an account harder.
cat > "/etc/nginx/conf.d/$INSTANCE-rate-limit.conf" <<NGINX
# Bowlingliga instance $INSTANCE (deploy/install.sh)
limit_req_zone \$binary_remote_addr zone=${INSTANCE}_login:10m rate=10r/m;
limit_req_status 429;
NGINX

# The site is rewritten on every run. With an existing certificate it listens
# directly on the HTTPS ports (next to other sites, distinguished by
# server_name); otherwise on port 80, and the HTTPS step afterwards
# (re)installs a Let's Encrypt certificate.
if [[ $TLS == existing ]]; then
    if ! openssl x509 -in "$CERTIFICATE" -noout -checkhost "$DOMAIN" | grep -q 'does match'; then
        note "Warning: the certificate does not seem to cover $DOMAIN."
    fi
    LISTEN=""
    for port in $HTTPS_PORTS; do
        LISTEN+="    listen $port ssl;
    listen [::]:$port ssl;
"
    done
    LISTEN+="
    ssl_certificate     $CERTIFICATE;
    ssl_certificate_key $CERTIFICATE_KEY;
    ssl_protocols       TLSv1.2 TLSv1.3;"
else
    LISTEN="    listen 80;
    listen [::]:80;"
fi

# The marker "(deploy/install.sh)" in the first line identifies the instance on updates.
cat > "$SITE" <<NGINX
# Bowlingliga instance $INSTANCE (deploy/install.sh)
server {
$LISTEN
    server_name $DOMAIN;

    root $TARGET/public;
    index index.php;
    client_max_body_size 12m;

    # Own log files per instance; Debian's logrotate covers /var/log/nginx/*.log.
    # PHP errors arrive here as well (FastCGI stderr).
    access_log /var/log/nginx/$INSTANCE.access.log;
    error_log  /var/log/nginx/$INSTANCE.error.log;

    # The remaining security headers (CSP, X-Frame-Options, Referrer-Policy,
    # Permissions-Policy, HSTS) are set by the application itself.

    # Never deliver hidden files
    location ~ /\. { deny all; }

    # Stylesheets, scripts, fonts
    location ^~ /assets/ {
        try_files \$uri =404;
        expires 1h;
        add_header X-Content-Type-Options "nosniff" always;
    }

    # Login (steps 1 and 2) throttled
    location ^~ /admin/login {
        limit_req zone=${INSTANCE}_login burst=5 nodelay;
        try_files \$uri /index.php\$is_args\$args;
    }

    # Everything else goes to the front controller
    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$FPM_SOCKET;
        # expose_php cannot be switched off per pool
        fastcgi_hide_header X-Powered-By;
    }

    # Do not execute any other PHP files
    location ~ \.php\$ { return 404; }
}
NGINX
ln -sf "$SITE" "/etc/nginx/sites-enabled/$INSTANCE"
nginx -t -q
systemctl reload nginx

# --- HTTPS -----------------------------------------------------------------------

if [[ $TLS == none ]]; then
    note "HTTPS skipped (NO_HTTPS=1)."
elif [[ $TLS == existing ]]; then
    note "HTTPS with $CERTIFICATE on port(s) $HTTPS_PORTS."
    note "When the certificate is renewed, nginx must reload (systemctl reload nginx)."
elif [[ -d /etc/letsencrypt/live/$DOMAIN ]]; then
    info "Attaching the existing HTTPS certificate"
    certbot install --nginx --cert-name "$DOMAIN" --redirect --non-interactive >/dev/null \
        || note "Could not attach the certificate: certbot install --nginx --cert-name $DOMAIN --redirect"
else
    info "Requesting an HTTPS certificate (Let's Encrypt)"
    if [[ -n $EMAIL ]]; then CONTACT=(-m "$EMAIL"); else CONTACT=(--register-unsafely-without-email); fi
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect "${CONTACT[@]}"; then
        note "HTTPS is set up, renewal runs automatically."
    else
        note "Could not request a certificate (does the domain point to this server yet?)."
        note "Do it later with: certbot --nginx -d $DOMAIN --redirect"
    fi
fi

# --- Remember the settings for updates -------------------------------------------

mkdir -p "$(dirname "$SETTINGS")"
printf 'DOMAIN=%q\nEMAIL=%q\nCERTIFICATE=%q\nCERTIFICATE_KEY=%q\nHTTPS_PORTS=%q\nTARGET_DIR=%q\nREPO_URL=%q\n' \
    "$DOMAIN" "$EMAIL" "$CERTIFICATE" "$CERTIFICATE_KEY" "$HTTPS_PORTS" "$TARGET" "$REPO_URL" > "$SETTINGS"
chmod 600 "$SETTINGS"

info "Done"
echo "    Website:     $BASE_URL/"
echo "    Admin area:  $BASE_URL/admin"
echo "    Then set up the two-factor login under \"Mein Konto\"."
echo "    Update:      sudo bash $TARGET/deploy/update.sh"
