#!/bin/bash
# install.sh - install the kiosk card-scanner auto-reset (see INSTALL.md 3.5).
#   sudo ./install.sh            install/update the script and units, run a dry-run check (timer NOT enabled)
#   sudo ./install.sh --enable   also enable and start the 10-minute timer
#   sudo ./install.sh --remove   disable the timer and remove the installed files

if [ "$EUID" -ne 0 ]; then
  echo "Please run this script with sudo."
  exit 1
fi

SRC="$(cd "$(dirname "$0")" && pwd)"
BIN=/usr/local/bin/kiosk-scanner-reset.sh
UNITS="kiosk-scanner-reset.service kiosk-scanner-reset.timer"

if [ "$1" == "--remove" ]; then
    systemctl disable --now kiosk-scanner-reset.timer 2>/dev/null
    for u in $UNITS; do rm -f "/etc/systemd/system/$u"; done
    rm -f "$BIN"
    systemctl daemon-reload
    echo "Kiosk scanner reset removed."
    exit 0
fi

echo "--- Installing kiosk scanner reset ---"
install -m 755 -o root -g root "$SRC/kiosk-scanner-reset.sh" "$BIN"
for u in $UNITS; do install -m 644 -o root -g root "$SRC/$u" /etc/systemd/system/; done
systemctl daemon-reload

echo "Dry run:"
"$BIN" --check

if [ "$1" == "--enable" ]; then
    systemctl enable --now kiosk-scanner-reset.timer
    systemctl list-timers kiosk-scanner-reset.timer --no-pager
else
    echo "Timer not enabled. Run with --enable to turn it on."
fi
