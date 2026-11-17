#!/bin/bash
# revert-ram-hold.sh (FSBHOA AC Core): PANIC LEVEL 1, a problem at the VEHICLE gates after V2's
# push to RAM. The server stays on V2; this only stops V2 sending anything more to RAM, so the
# RAM backup restored by hand on the RAM PC isn't overwritten by the next push (after an edit,
# or the nightly export at 00:05).
#
#   ~/revert-ram-hold.sh          turn DoorKing's Send to RAM off, and hold any push already
#                                 waiting for the RAM PC (its trigger file is renamed .held)
#   ~/revert-ram-hold.sh status   show Send to RAM and what is waiting, change nothing
#
# Then, on the office RAM PC (the card "Back to V1, level 1"): exit DK_Sync (tray icon, Exit),
# restore the RAM backup taken before V2's first push, Send All Data to North Gates and South
# Gates, and listen for both panels. To send from V2 again later: fix the cause, turn Send to RAM
# on in the DoorKing settings, start DK_Sync.
#
# Run as the user the services run as. Safe to run more than once.

set -uo pipefail
WP_DIR=/var/www/html
MODE="${1:-hold}"
case "$MODE" in hold|status) ;; *) sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;; esac

wpv() { wp --path="$WP_DIR" --skip-plugins --skip-themes "$@" 2>/dev/null | grep -v -i deprecated; }
# The plugin's own paths (settings, else its defaults); empty if the DoorKing plugin isn't active
wpp() { wp --path="$WP_DIR" "$@" 2>/dev/null | grep -v -i deprecated; }

state="$(wpv option get fsbhoa_dk_send_to_ram)"
flag="$(wpp eval 'if ( class_exists( "Fsbhoa_DoorKing_Export" ) ) echo get_option( "fsbhoa_dk_lock_path", Fsbhoa_DoorKing_Export::default_flag_path() );')"
env="$(wpv config get FSBHOA_AC_ENVIRONMENT)"

echo "Environment: ${env:-(not set)}"
echo "Send to RAM: $([ "$state" = 0 ] && echo OFF || echo "ON (${state:-default})")"
if [ -n "$flag" ]; then
    dir="$(dirname "$flag")"
    if [ -f "$flag" ]; then echo "Waiting for the RAM PC: a push (trigger $flag)"; else echo "Nothing waiting for the RAM PC in $dir"; fi
    ls "$dir"/*.held 2>/dev/null | sed 's/^/Held earlier: /'
else
    echo "The DoorKing plugin isn't active (V1?): nothing on this server sends to RAM."
fi
[ "$MODE" = status ] && exit 0

echo ""
wpv option update fsbhoa_dk_send_to_ram 0 >/dev/null
[ "$(wpv option get fsbhoa_dk_send_to_ram)" = 0 ] && echo "[OK] Send to RAM is OFF: V2 sends nothing more to RAM." || { echo "FAILED to turn Send to RAM off"; exit 1; }
if [ -n "$flag" ] && [ -f "$flag" ]; then
    held="$flag.$(date +%Y%m%d_%H%M%S).held"
    if mv "$flag" "$held" 2>/dev/null; then
        echo "[OK] The push that was waiting is held ($held); DK_Sync ignores it."
    else
        echo "WARNING: couldn't rename $flag. Exit DK_Sync on the RAM PC NOW, before it imports it."
    fi
fi
cat <<'NEXT'

NEXT, ON THE OFFICE RAM PC:
  1. Tray icon (green H) > right-click > Exit, so DK_Sync can't import anything.
  2. RAM: restore the RAM backup taken just before V2's first push.
  3. RAM: Action > Send Data Now, tick North Gates and South Gates, OK; wait for both
     sessions to finish (both panels beep; RAM's events.log says Successful for each).
  4. Test a windshield tag at each entry lane.
The pedestrian gates and the website stay on V2. For those: ~/revert-to-v1.sh (level 2).
NEXT
