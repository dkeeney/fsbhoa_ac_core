#!/bin/bash
# setup_logrotate.sh

if [ "$EUID" -ne 0 ]; then
  echo "Please run this script with sudo."
  exit 1
fi

# Detect the service user (fsbhoa on Ubuntu, pi on RPi)
SERVICE_USER=$(logname)
CONFIG_FILE="/etc/logrotate.d/fsbhoa"

echo "Creating logrotate config for user '$SERVICE_USER'..."

cat << EOF > "$CONFIG_FILE"
/var/log/fsbhoa/*.log {
    daily
    rotate 7
    size 5M
    compress
    delaycompress
    missingok
    notifempty
    # Dynamically set ownership based on the current user
    create 0640 $SERVICE_USER $SERVICE_USER
    postrotate
        # Optional service restarts
    endscript
}
EOF

echo "Log rotation configured.
"
