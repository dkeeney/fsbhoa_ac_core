#!/bin/bash
# kiosk-scanner-reset.sh - reset the lobby kiosk card scanner (USB HID 413d:2107) when it may be hung.
#
# The scanner sometimes stops sending card reads while still looking connected.
# There is no way to tell "hung" from "nobody swiping", so during open hours, if no
# swipe has reached the kiosk for IDLE_MIN minutes, the scanner's USB port is powered
# off for a few seconds and back on (same effect as unplugging it). Resetting a
# working scanner is harmless: it is gone for about 5 seconds.
#
# Run by kiosk-scanner-reset.timer every 10 minutes. Logs to the journal with tag kiosk-scanner-reset.
# Usage: kiosk-scanner-reset.sh [--force] [--check]   (--force: ignore hours/idle; --check: report only)
# Source: fsbhoa_ac/kiosk/scanner_reset/ (installed by install.sh there). Added 2026-11-01.

VID=413d; PID=2107
IDLE_MIN=30          # reset if no swipe for this many minutes
OPEN_HOUR=7          # only between these hours (local time, 24h)
CLOSE_HOUR=21
STATE=/run/kiosk-scanner-reset.last
TAG=kiosk-scanner-reset

FORCE=0; CHECK=0
for a in "$@"; do case $a in --force) FORCE=1;; --check) CHECK=1;; esac; done
log() { logger -t $TAG "$*"; echo "$*"; }

find_dev() {   # prints sysfs dir of the scanner, e.g. /sys/bus/usb/devices/3-5
  for d in /sys/bus/usb/devices/*; do
    [ -f "$d/idVendor" ] && [ "$(cat $d/idVendor)" = $VID ] && [ "$(cat $d/idProduct)" = $PID ] && { echo "$d"; return 0; }
  done; return 1
}

DEV=$(find_dev)
if [ -z "$DEV" ]; then log "scanner $VID:$PID not found on USB (unplugged?) - nothing to reset"; exit 1; fi
NAME=$(basename "$DEV")                       # e.g. 3-5
PARENT=$(readlink -f "$DEV/..")               # hub device dir
PORT=$(ls -d "$PARENT"/*:1.0/*-port"${NAME##*[-.]}" 2>/dev/null | head -1)   # e.g. .../usb3/3-0:1.0/usb3-port5

if [ $FORCE -eq 0 ]; then
  H=$(date +%-H)
  if [ "$H" -lt $OPEN_HOUR ] || [ "$H" -ge $CLOSE_HOUR ]; then [ $CHECK -eq 1 ] && echo "outside hours ($H)"; exit 0; fi
  if journalctl -u fsbhoa_kiosk --no-pager --since "-${IDLE_MIN} min" 2>/dev/null | grep -q manualSwipe; then
    [ $CHECK -eq 1 ] && echo "swipe seen in last $IDLE_MIN min - scanner OK"; exit 0
  fi
  if [ -f $STATE ] && [ $(( $(date +%s) - $(cat $STATE) )) -lt $(( IDLE_MIN * 60 )) ]; then
    [ $CHECK -eq 1 ] && echo "reset done less than $IDLE_MIN min ago - waiting"; exit 0
  fi
fi
if [ $CHECK -eq 1 ]; then echo "would reset scanner at $NAME (port: ${PORT:-none})"; exit 0; fi

date +%s > $STATE
if [ $FORCE -eq 1 ]; then WHY="manual reset (--force)"; else WHY="no swipe for ${IDLE_MIN}+ min"; fi
if [ -n "$PORT" ] && [ -w "$PORT/disable" ]; then
  log "$WHY: power-cycling scanner port $(basename $PORT)"
  echo 1 > "$PORT/disable"; sleep 3; echo 0 > "$PORT/disable"
else
  log "$WHY: port power control not available, re-authorizing $NAME"
  echo 0 > "$DEV/authorized"; sleep 3; echo 1 > "$DEV/authorized"
fi

for i in $(seq 1 10); do sleep 1; find_dev >/dev/null && { log "scanner back after reset ($(basename $(find_dev)))"; exit 0; }; done
log "scanner did not come back after port reset - trying re-authorize"
D2=/sys/bus/usb/devices/$NAME
[ -e "$D2/authorized" ] && { echo 0 > "$D2/authorized"; sleep 3; echo 1 > "$D2/authorized"; }
sleep 5; find_dev >/dev/null && { log "scanner back after re-authorize"; exit 0; }
log "ERROR: scanner $VID:$PID still missing - needs a physical unplug/replug"
exit 2
