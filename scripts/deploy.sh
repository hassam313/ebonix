#!/bin/bash
# Runs on VPS on every git push via GitHub Actions
set -e

APP_DIR="/var/www/ebonix.ai"

echo "──────────────────────────────────────────"
echo " Ebonix Deploy — $(date)"
echo "──────────────────────────────────────────"

# 1. Pull latest code
cd "$APP_DIR"
git pull origin main

# 2. Permissions
find . -type f -name "*.php" -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
mkdir -p king-include/uploads
chmod -R 775 king-include/uploads
chown -R www-data:www-data king-include/uploads

# 3. Install/update Python gateway dependencies
pip3 install -q -r ebonix_gateway/requirements.txt --break-system-packages 2>/dev/null || \
pip3 install -q -r ebonix_gateway/requirements.txt 2>/dev/null || true

# 4. Restart gateway
systemctl restart ebonix-gateway

# 5. Reload Apache (no downtime)
systemctl reload apache2

echo "✅ Deploy done — $(date)"
