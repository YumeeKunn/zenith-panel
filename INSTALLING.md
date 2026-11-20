# Zenith Panel — Detailed Installation

## Requirements
- Ubuntu 22.04 / 24.04 (Debian 12 probably works)
- Root access
- 2GB+ RAM (4GB recommended)
- Domain (optional — IP pe self-signed SSL hota hai)

## Automatic Install (recommended)

```bash
git clone https://github.com/YumeeKunn/zenith-panel.git
cd zenith-panel
bash zenith-install.sh your-domain.com
```

Script kya karta hai:
1. nginx, PHP 8.3 (+extensions), MariaDB, Redis install
2. Composer, panel source, dependencies
3. Database `panel` + random password user
4. `.env` config + app key
5. Migrations + seed
6. Queue worker (pteroq) + scheduler cron
7. Frontend build (Node 22 + yarn)
8. nginx vhost + SSL (certbot ya self-signed)
9. Zenith: watchdog timer, notifier timer, sudoers, DB backup cron

## Manual Install (steps breakdown)

### 1. Packages
```bash
apt install -y nginx mariadb-server redis-server certbot python3-certbot-nginx inotify-tools
add-apt-repository ppa:ondrej/php && apt update
apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-mbstring php8.3-bcmath php8.3-xml php8.3-curl php8.3-zip php8.3-intl
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

### 2. Database
```bash
mysql -e "CREATE USER 'pterodactyl'@'localhost' IDENTIFIED BY 'STRONG_PASS';"
mysql -e "CREATE DATABASE panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "GRANT ALL ON panel.* TO 'pterodactyl'@'localhost'; FLUSH PRIVILEGES;"
```

### 3. Panel
```bash
git clone https://github.com/YumeeKunn/zenith-panel.git /var/www/pterodactyl
cd /var/www/pterodactyl
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate --force
# .env me DB_PASSWORD, APP_URL, CACHE_STORE=redis, QUEUE_CONNECTION=redis, SESSION_DRIVER=redis set karo
php artisan migrate --force && php artisan db:seed --force
chown -R www-data:www-data /var/www/pterodactyl/*
php artisan storage:link --force
```

### 4. Frontend build
```bash
# Node 22+
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs
npm i -g yarn
yarn install && yarn build:production
chown -R www-data:www-data public/assets resources
```

### 5. Zenith services
```bash
cp zenith-systemd/*.service zenith-systemd/*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now ptero-watchdog.timer ptero-notify.timer
# sudoers (admin panel se service control ke liye):
cp docs/sudoers-zenith /etc/sudoers.d/zenith-admin && chmod 440 /etc/sudoers.d/zenith-admin
```

### 6. nginx + SSL
Standard Pterodactyl nginx config use karo (docs/nginx.conf example repo me). SSL:
```bash
certbot --nginx -d your-domain.com
# ya IP pe self-signed:
openssl req -x509 -nodes -days 825 -newkey rsa:2048 -keyout /etc/nginx/ssl/panel.key \
  -out /etc/nginx/ssl/panel.crt -subj "/CN=YOUR_IP" -addext "subjectAltName=IP:YOUR_IP"
cp /etc/nginx/ssl/panel.crt /usr/local/share/ca-certificates/ && update-ca-certificates
```

### 7. Admin user
```bash
php artisan p:user:make --no-interaction --name-first=Admin --name-last=User \
  --username=admin --email=you@example.com --password='Strong!' --admin=1
```

### 8. Wings (game server node)
Official Wings install follow karo: https://pterodactyl.io/wings/1.0/installing.html
- Wings API :8080 + SFTP :2022
- Panel me Admin → Nodes → create node → config export → /etc/wings/config.yml
- Zenith notifier/watchdog automatically naye node ko monitor karenge

## Post-install checklist
- [ ] Admin → Zenith Control → Discord webhook set + Test message
- [ ] Admin 2FA enable (Account page)
- [ ] `zenith:notify --test` se notification verify
- [ ] Watchdog/Notifier thresholds tune (panel se)
- [ ] MariaDB root password set (security!)
- [ ] UFW firewall — sirf 80, 443, SSH, 8080, 2022, game ports khule

## Updating
```bash
cd /var/www/pterodactyl
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
yarn install && yarn build:production
chown -R www-data:www-data public/assets resources
php artisan queue:restart
```
⚠️ Custom theme/Blueprint addons upgrade pe reapply karne pad sakte hain.
