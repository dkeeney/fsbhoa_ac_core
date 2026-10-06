#!/bin/bash

# deploy_services.sh
# CONFIGURATION: Compiles Core Go binaries and deploys to Production locations.

INSTALL_BIN="/usr/local/bin"
INSTALL_ASSETS="/var/lib/fsbhoa"
PLUGIN_DIR="/var/www/html/wp-content/plugins/fsbhoa_ac_core"

echo "--- Starting Core Service Deployment ---"

# 1. MONITOR SERVICE
echo "[1/2] Building Monitor..."
cd monitor_service
go build -o fsbhoa_monitor main.go config.go hub.go ws_client.go notify_handler.go
sudo systemctl stop fsbhoa_monitor
sudo cp fsbhoa_monitor "$INSTALL_BIN/"
sudo chmod +x "$INSTALL_BIN/fsbhoa_monitor"
cd ..

# 2. WORDPRESS CORE PLUGIN
echo "[2/2] Deploying WordPress Core Plugin..."
if [ ! -d "/home/pi/fsbhoa_ac_core" ]; then
    echo "ERROR: Plugin source directory not found!"
    exit 1
fi

sudo mkdir -p "$PLUGIN_DIR"
sudo rsync -av --delete /home/pi/fsbhoa_ac_core/ "$PLUGIN_DIR/" --exclude '.git' --exclude 'deploy_services.sh'
sudo chown -R www-data:www-data "$PLUGIN_DIR"

echo "--- Restarting Core Services ---"
sudo systemctl daemon-reload
sudo systemctl start fsbhoa_monitor

echo "Core Deployment Complete."

