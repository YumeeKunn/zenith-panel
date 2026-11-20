#!/usr/bin/env bash
# ==============================================================================
# Zenith Panel — Enhanced Pterodactyl Installer
# Based on Pterodactyl 1.15.2 + Zenith enhancements:
#   • Watchdog: crash-loop guard, OOM abuse cutoff, CPU overload auto-suspend
#   • Server Mover: safe node-to-node migration + deadlock rollback
#   • Discord Notifier: node down / high load alerts with fix suggestions
#   • Zenith Control: admin panel page for all of the above
#   • Announcements banner + brand logo (v2-style backports)
#
# Usage: bash zenith-install.sh <fqdn-or-ip>
# Must run as root on Ubuntu 22.04/24.04 (Debian 12 may work).
# ==============================================================================
set -euo pipefail

FQDN="${1:-}"
[[ -z "$FQDN" ]] && { echo "Usage: $0 <fqdn-or-ip>"; exit 1; }
[[ $EUID -ne 0 ]] && { echo "Run as root."; exit 1; }

bold() { echo -e "\e[1m$*\e[0m"; }
step() { bold "==> $*"; }

step "1/9 — Base packages (nginx, PHP 8.3, MariaDB, Redis, certbot)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq software-properties-common curl apt-transport-https ca-certificates gnupg unzip git sudo lsb-release
add-apt-repository -y ppa:ondrej/php >/dev/null
apt-get update -qq
apt-get install -y -qq php8.3 php8.3-cli php8.3-gd php8.3-mysql php8.3-mbstring php8.3-bcmath \
  php8.3-xml php8.3-fpm php8.3-curl php8.3-zip php8.3-intl mariadb-server redis-server \
  certbot python3-certbot-nginx inotify-tools

step "2/9 — Composer"
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet
export COMPOSER_ALLOW_SUPERUSER=1

step "3/9 — Database"
DB_PASS="$(openssl rand -hex 16)"
mysql -e "CREATE USER IF NOT EXISTS 'pterodactyl'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "CREATE DATABASE IF NOT EXISTS panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "GRANT ALL PRIVILEGES ON panel.* TO 'pterodactyl'@'localhost'; FLUSH PRIVILEGES;"

step "4/9 — Panel source (this repository)"
PANEL_DIR="/var/www/pterodactyl"
[[ -d "$PANEL_DIR" ]] && { echo "$PANEL_DIR already exists — aborting to protect data."; exit 1; }
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p "$PANEL_DIR"
cp -r "$SCRIPT_DIR"/. "$PANEL_DIR"/
cd "$PANEL_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

step "5/9 — Environment + key"
cp .env.example .env
php artisan key:generate --force --no-interaction >/dev/null || true
# write config into .env
set_env() {
  if grep -qP "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
set_env APP_URL "https://$FQDN"
set_env DB_PASSWORD "$DB_PASS"
set_env CACHE_STORE redis
set_env QUEUE_CONNECTION redis
set_env SESSION_DRIVER redis
set_env SESSION_SECURE_COOKIE true
set_env MAIL_MAILER log
set_env RECAPTCHA_ENABLED false

step "6/9 — Migrations + cache"
php artisan config:clear >/dev/null 2>&1 || true
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
chown -R www-data:www-data "$PANEL_DIR"/*
chmod -R 755 "$PANEL_DIR/storage" "$PANEL_DIR/bootstrap/cache"
php artisan storage:link --force

step "7/9 — Queue worker, scheduler, frontend build"
cat > /etc/systemd/system/pteroq.service <<'UNIT'
[Unit]
Description=Pterodactyl Panel Queue Worker
After=redis-server.service mariadb.service
[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php8.3 /var/www/pterodactyl/artisan queue:work --queue=high,standard,low --sleep=3 --tries=3 --max-time=3600
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now pteroq
( crontab -l -u www-data 2>/dev/null; echo "* * * * * php /var/www/pterodactyl/artisan schedule:run >> /dev/null 2>&1" ) | crontab -u www-data -
# frontend build (node 22+ + yarn required)
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
apt-get install -y -qq nodejs >/dev/null
npm install -g yarn --silent
cd "$PANEL_DIR" && yarn install --silent && yarn build:production
chown -R www-data:www-data public/assets resources

step "8/9 — nginx + SSL"
cat > /etc/nginx/sites-available/pterodactyl.conf <<NGINX
server {
    listen 80;
    server_name $FQDN;
    return 301 https://\$host\$request_uri;
}
server {
    listen 443 ssl http2;
    server_name $FQDN;
    root $PANEL_DIR/public;
    index index.php;
    access_log /var/log/nginx/pterodactyl.access.log;
    error_log  /var/log/nginx/pterodactyl.error.log;
    client_max_body_size 100m;
    sendfile off;
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options SAMEORIGIN;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }
    location ~ /\.ht { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/pterodactyl.conf /etc/nginx/sites-enabled/pterodactyl.conf
rm -f /etc/nginx/sites-enabled/default
systemctl enable --now php8.3-fpm nginx
# SSL: domain = certbot; IP = self-signed
if [[ "$FQDN" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  mkdir -p /etc/nginx/ssl
  openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
    -keyout /etc/nginx/ssl/panel.key -out /etc/nginx/ssl/panel.crt \
    -subj "/CN=$FQDN" -addext "subjectAltName=IP:$FQDN" >/dev/null 2>&1
  # insert ssl lines cleanly after the listen directive (robust against future edits)
  python3 - "$FQDN" <<'SSLFIX'
import sys, re
fqdn = sys.argv[1]
p = "/etc/nginx/sites-available/pterodactyl.conf"
s = open(p).read()
ssl_block = f"    ssl_certificate /etc/nginx/ssl/panel.crt;\n    ssl_certificate_key /etc/nginx/ssl/panel.key;\n"
if "ssl_certificate" not in s:
    s = s.replace("    server_name " + fqdn + ";\n", "    server_name " + fqdn + ";\n" + ssl_block, 1)
    open(p, "w").write(s)
SSLFIX
  cp /etc/nginx/ssl/panel.crt /usr/local/share/ca-certificates/zenithpanel.crt
  update-ca-certificates
  echo "  (self-signed cert — browser warning expected on IP installs)"
else
  certbot --nginx -d "$FQDN" --non-interactive --agree-tos -m "admin@$FQDN" || echo "  (certbot failed — SSL manually configure karo)"
fi
nginx -t && systemctl reload nginx

step "9/9 — Zenith systems (watchdog, notifier, sudoers, timers)"
cp "$SCRIPT_DIR/zenith-systemd/"*.service /etc/systemd/system/
cp "$SCRIPT_DIR/zenith-systemd/"*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now ptero-watchdog.timer ptero-notify.timer

cat > /etc/sudoers.d/zenith-admin <<'SUDO'
Cmnd_Alias ZENITH_SVC = /usr/bin/systemctl start ptero-watchdog.timer, /usr/bin/systemctl stop ptero-watchdog.timer, /usr/bin/systemctl restart ptero-watchdog.timer, /usr/bin/systemctl start ptero-watchdog.service, /usr/bin/systemctl stop ptero-watchdog.service, /usr/bin/systemctl restart ptero-watchdog.service, /usr/bin/systemctl is-active ptero-watchdog.timer, /usr/bin/systemctl is-active ptero-watchdog.service
Cmnd_Alias ZENITH_NOTIFY = /usr/bin/systemctl start ptero-notify.timer, /usr/bin/systemctl stop ptero-notify.timer, /usr/bin/systemctl restart ptero-notify.timer, /usr/bin/systemctl start ptero-notify.service, /usr/bin/systemctl stop ptero-notify.service, /usr/bin/systemctl restart ptero-notify.service, /usr/bin/systemctl is-active ptero-notify.timer, /usr/bin/systemctl is-active ptero-notify.service
Cmnd_Alias ZENITH_LOGS = /usr/bin/journalctl
www-data ALL=(root) NOPASSWD: ZENITH_SVC, ZENITH_NOTIFY, ZENITH_LOGS
SUDO
chmod 440 /etc/sudoers.d/zenith-admin

# DB backup + health monitor (bonus from Zenith)
cat > /usr/local/bin/ptero-db-backup.sh <<'BK'
#!/bin/bash
STAMP=$(date +%Y%m%d_%H%M%S); DIR=/var/backups/pterodactyl; mkdir -p $DIR
mysqldump --single-transaction --routines --triggers panel | gzip > "$DIR/panel_$STAMP.sql.gz"
cp /var/www/pterodactyl/.env "$DIR/env_$STAMP.bak"
find "$DIR" -name "panel_*.sql.gz" -mtime +14 -delete
find "$DIR" -name "env_*.bak" -mtime +14 -delete
BK
chmod +x /usr/local/bin/ptero-db-backup.sh
( crontab -l 2>/dev/null; echo "0 3 * * * /usr/local/bin/ptero-db-backup.sh >> /var/log/ptero-backup.log 2>&1" ) | crontab -

bold "=============================================="
bold " Zenith Panel installed!"
echo "  URL:      https://$FQDN"
echo "  Admin:    php artisan p:user:make --no-interaction --name-first=Admin --name-last=User --username=admin --email=admin@$(echo $FQDN | tr '.' '-') --password='CHANGE_ME' --admin=1"
echo "  DB pass:  $DB_PASS (also in /var/www/pterodactyl/.env)"
echo ""
echo "  Next:"
echo "   1. Admin login banao (command upar)"
echo "   2. Admin → Zenith Control → Discord webhook set karo"
echo "   3. Wings install (optional): official guide ya docker"
bold "=============================================="
