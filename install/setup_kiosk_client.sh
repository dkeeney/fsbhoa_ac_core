#!/bin/bash
# setup_kiosk_client.sh

if [ "$EUID" -ne 0 ]; then
  echo "Please run this script with sudo."
  exit 1
fi

# Detect who ran sudo so we configure the desktop for THEM
REAL_USER="${SUDO_USER:-$USER}"
USER_HOME=$(getent passwd "$REAL_USER" | cut -d: -f6)
AUTOSTART_DIR="$USER_HOME/.config/autostart"
WRAPPER_SCRIPT="$USER_HOME/fsbhoa_ac/start_kiosk.sh"

echo "--- Configuring Kiosk Client for user: $REAL_USER ---"

# 1. Install Dependencies
echo "[1/3] Installing Dependencies (jq, browser, unclutter)..."
apt-get update
apt-get install -y jq unclutter

# Ensure a browser exists (Prefer Firefox, fallback to Chromium if needed)
if ! command -v firefox >/dev/null && ! command -v firefox-esr >/dev/null; then
    apt-get install -y firefox-esr || snap install firefox
fi

# 2. Configure Autostart Launcher
# CRITICAL: Points to the start_kiosk.sh wrapper, not the browser directly.
echo "[2/3] Creating Autostart Launcher..."
mkdir -p "$AUTOSTART_DIR"

cat << EOF > "$AUTOSTART_DIR/fsbhoa_kiosk.desktop"
[Desktop Entry]
Type=Application
Name=FSBHOA Kiosk
Comment=Start Kiosk Wrapper Script
Exec=$WRAPPER_SCRIPT
X-GNOME-Autostart-enabled=true
EOF

# Fix permissions
chown -R "$REAL_USER":"$REAL_USER" "$AUTOSTART_DIR"

# Ensure the wrapper script is executable
if [ -f "$WRAPPER_SCRIPT" ]; then
    chmod +x "$WRAPPER_SCRIPT"
    chown "$REAL_USER":"$REAL_USER" "$WRAPPER_SCRIPT"
else
    echo "WARNING: $WRAPPER_SCRIPT not found! Autostart will fail until this file exists."
fi

# 2b. Keep Ubuntu pop-ups off the kiosk screen (added 2026-11-01)
# The "new release available" window opened over the kiosk browser and took keyboard
# focus, so card swipes (the scanner types like a keyboard) went into it instead.
echo "[2b/3] Disabling release-upgrade and update-notifier pop-ups..."
if [ -f /etc/update-manager/release-upgrades ]; then
    sed -i 's/^Prompt=.*/Prompt=never/' /etc/update-manager/release-upgrades
fi
cat << EOF > "$AUTOSTART_DIR/update-notifier.desktop"
[Desktop Entry]
Type=Application
Name=Update Notifier
Hidden=true
X-GNOME-Autostart-enabled=false
EOF
chown "$REAL_USER":"$REAL_USER" "$AUTOSTART_DIR/update-notifier.desktop"
# The release window itself comes from these systemd user units. Masking works without a running session.
USER_UNIT_DIR="$USER_HOME/.config/systemd/user"
mkdir -p "$USER_UNIT_DIR"
ln -sf /dev/null "$USER_UNIT_DIR/update-notifier-release.path"
ln -sf /dev/null "$USER_UNIT_DIR/update-notifier-release.service"
chown -R "$REAL_USER":"$REAL_USER" "$USER_HOME/.config/systemd"

# 3. Configure Auto-Login
echo "[3/3] Configuring Auto-Login..."

if command -v raspi-config >/dev/null; then
    echo ">> Detected Raspberry Pi OS. Configuring via raspi-config..."
    # B4 = Boot to Desktop, Auto-Login
    raspi-config nonint do_boot_behaviour B4
    echo "Raspberry Pi Auto-Login configured."

elif command -v lsb_release >/dev/null; then
    OS_ID=$(lsb_release -si)
    if [ "$OS_ID" == "Ubuntu" ]; then
        echo ">> Detected Ubuntu. Configuring GDM3..."
        CONF_FILE="/etc/gdm3/custom.conf"
        if [ -f "$CONF_FILE" ]; then
             sed -i "s/.*AutomaticLoginEnable =.*/AutomaticLoginEnable = true/" "$CONF_FILE"
             sed -i "s/.*AutomaticLogin =.*/AutomaticLogin = $REAL_USER/" "$CONF_FILE"
             
             if ! grep -q "AutomaticLoginEnable = true" "$CONF_FILE"; then
                 sed -i "/\[daemon\]/a AutomaticLoginEnable = true" "$CONF_FILE"
                 sed -i "/\[daemon\]/a AutomaticLogin = $REAL_USER" "$CONF_FILE"
             fi
             echo "Ubuntu Auto-Login configured."
        fi
    fi
else
    echo "WARNING: Could not detect OS type. Auto-login skipped."
fi

echo "--- Kiosk Setup Complete ---"
