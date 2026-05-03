#!/bin/bash
# Run this ONCE on the VPS to set up the full server.
# Usage: bash /var/www/ebonix.ai/scripts/server-setup.sh
set -e

APP_DIR="/var/www/ebonix.ai"
DOMAIN="ebonix.ai"
DB_NAME="ebonix"
DB_USER="root"

echo "══════════════════════════════════════════════"
echo " Ebonix VPS Server Setup"
echo "══════════════════════════════════════════════"

# ── 1. System packages ─────────────────────────────
echo "[1/9] Installing system packages..."
apt-get update -q
apt-get install -y apache2 php8.4 php8.4-mysql php8.4-curl php8.4-gd \
    php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath \
    libapache2-mod-php8.4 mysql-server python3 python3-pip \
    python3-venv git curl certbot python3-certbot-apache

a2enmod rewrite headers ssl
systemctl enable apache2 mysql
systemctl start apache2 mysql

# ── 2. Clone or update repo ─────────────────────────
echo "[2/9] Setting up project files..."
if [ ! -d "$APP_DIR/.git" ]; then
    git clone https://github.com/hassam313/ebonix.git "$APP_DIR"
else
    cd "$APP_DIR" && git pull origin main
fi
cd "$APP_DIR"

# ── 3. Permissions ──────────────────────────────────
echo "[3/9] Setting permissions..."
chown -R www-data:www-data "$APP_DIR"
find . -type f -name "*.php" -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
mkdir -p king-include/uploads
chmod -R 775 king-include/uploads
chown -R www-data:www-data king-include/uploads
chmod +x scripts/deploy.sh

# ── 4. Create king-config.php ───────────────────────
echo "[4/9] Creating king-config.php..."
if [ ! -f "$APP_DIR/king-config.php" ]; then
    echo "Enter MySQL password for the VPS (leave blank if none):"
    read -s DB_PASS
    cat > "$APP_DIR/king-config.php" <<EOF
<?php
define('QA_MYSQL_HOSTNAME', '127.0.0.1');
define('QA_MYSQL_USERNAME', '$DB_USER');
define('QA_MYSQL_PASSWORD', '$DB_PASS');
define('QA_MYSQL_DATABASE', '$DB_NAME');
define('QA_MYSQL_TABLE_PREFIX', 'qa_');
define('QA_EXTERNAL_USERS', false);
define('QA_HTML_COMPRESSION', true);
define('QA_MAX_LIMIT_START', 19999);
define('QA_IGNORED_WORDS_FREQ', 10000);
define('QA_ALLOW_UNINDEXED_QUERIES', false);
define('QA_OPTIMIZE_LOCAL_DB', false);
define('QA_OPTIMIZE_DISTANT_DB', false);
define('QA_PERSISTENT_CONN_DB', false);
define('QA_DEBUG_PERFORMANCE', false);
EOF
    chmod 640 "$APP_DIR/king-config.php"
    chown root:www-data "$APP_DIR/king-config.php"
    echo "king-config.php created."
else
    echo "king-config.php already exists — skipping."
fi

# ── 5. Create .env for gateway ──────────────────────
echo "[5/9] Creating gateway .env..."
if [ ! -f "$APP_DIR/.env" ]; then
    cat > "$APP_DIR/.env" <<EOF
# Ebonix Gateway — only the auth token lives here
GATEWAY_AUTH_TOKEN=ebonix_secret_12345
EOF
    chmod 640 "$APP_DIR/.env"
    echo ".env created."
else
    echo ".env already exists — skipping."
fi

# ── 6. Import database ──────────────────────────────
echo "[6/9] Importing database..."
echo "This will REPLACE the existing '$DB_NAME' database."
read -p "Are you sure? (yes/no): " CONFIRM
if [ "$CONFIRM" = "yes" ]; then
    echo "Enter MySQL password:"
    read -s DB_PASS_IMPORT
    mysql -u "$DB_USER" -p"$DB_PASS_IMPORT" "$DB_NAME" < "$APP_DIR/ebonix.sql"
    echo "Database imported."
else
    echo "Database import skipped."
fi

# ── 7. Python gateway dependencies ─────────────────
echo "[7/9] Installing Python dependencies..."
pip3 install -r "$APP_DIR/ebonix_gateway/requirements.txt" --break-system-packages 2>/dev/null || \
pip3 install -r "$APP_DIR/ebonix_gateway/requirements.txt"

# ── 8. Install gateway as systemd service ──────────
echo "[8/9] Setting up gateway service..."
cp "$APP_DIR/scripts/ebonix-gateway.service" /etc/systemd/system/ebonix-gateway.service
systemctl daemon-reload
systemctl enable ebonix-gateway
systemctl start ebonix-gateway
echo "Gateway service started."

# ── 9. Apache virtual host ──────────────────────────
echo "[9/9] Configuring Apache..."
cat > "/etc/apache2/sites-available/$DOMAIN.conf" <<APACHECONF
<VirtualHost *:80>
    ServerName $DOMAIN
    ServerAlias www.$DOMAIN
    DocumentRoot $APP_DIR

    <Directory $APP_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block direct access to sensitive dirs
    <DirectoryMatch "^$APP_DIR/(ebonix_gateway|scripts|\\.github)">
        Require all denied
    </DirectoryMatch>

    # Block .env and config files
    <FilesMatch "^(\.env|king-config\.php|CLAUDE\.md)$">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/$DOMAIN-error.log
    CustomLog \${APACHE_LOG_DIR}/$DOMAIN-access.log combined
</VirtualHost>
APACHECONF

a2ensite "$DOMAIN.conf"
a2dissite 000-default.conf 2>/dev/null || true
systemctl reload apache2

echo ""
echo "══════════════════════════════════════════════"
echo " ✅ Setup complete!"
echo " Site: http://$DOMAIN"
echo " Run: certbot --apache -d $DOMAIN -d www.$DOMAIN"
echo " to enable HTTPS (free SSL)"
echo "══════════════════════════════════════════════"
