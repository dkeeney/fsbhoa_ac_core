#!/bin/bash
# revert-to-v1.sh (FSBHOA AC Core): PANIC LEVEL 2, back to V1 (a problem at the PEDESTRIAN gates,
# or anything else on the server). One command, nothing to look up:
#   1. finds the newest cutover backup (~/cutover_backup_<time>) and says how old it is;
#   2. asks once (y), unless --yes;
#   3. cutover-v2.sh rollback: V1 and the database from before the cutover (anything recorded
#      since is lost), V1's binaries and units, the kiosk autostart;
#   3b. the lobby kiosk Pi (ssh kiosk, 192.168.42.64) back to V1's page (?auto_name=LobbyKiosk),
#      without a reboot; a problem there is reported, not fatal. --no-kiosk skips it;
#      --kiosk-only does only this (by hand, or to test it: no change if already on V1's page);
#   4. V1's card sync to the door controllers (the UHPPOTE controllers keep V2's cards until V1
#      syncs): V1's nightly sync, whose loader writes only the cards that differ (no wipe, no gap
#      at the doors). --wipe instead wipes each controller and reloads it ("Force Full
#      Rebuild"; cards don't work for about 15 seconds per controller). --no-sync skips it.
#
#   ~/revert-to-v1.sh [--yes] [--wipe | --no-sync] [--no-kiosk | --kiosk-only] [--rehearsal]
#
# --rehearsal: on the testbed (its own controllers only; never the lobby kiosk). The vehicle gates (RAM) are not
# touched: V1 never sends to RAM, so the panels keep what RAM has. If the vehicle gates are the
# problem too, also do level 1 on the RAM PC (restore the RAM backup, Send All Data); see the
# card "Back to V1".
#
# Run as the user the services run as (it uses sudo itself).

set -uo pipefail
CORE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
WP_DIR=/var/www/html
LOG="$WP_DIR/wp-content/debug.log"
YES=0; SYNC=nightly; REHEARSAL=(); KIOSK=1; KIOSK_ONLY=0
KIOSK_V1_URL="https://access.fsbhoa.com:8080/?auto_name=LobbyKiosk"
for a in "$@"; do
    case "$a" in
        --yes) YES=1 ;;
        --wipe) SYNC=wipe ;;
        --no-sync) SYNC=none ;;
        --rehearsal) REHEARSAL=(--rehearsal); KIOSK=0 ;;
        --no-kiosk) KIOSK=0 ;;
        --kiosk-only) KIOSK_ONLY=1 ;;
        *) sed -n '2,23p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
    esac
done
fail() { echo "STOPPED: $*"; exit 1; }

# The lobby kiosk Pi: its SD card is read-only (overlay), so the setting is changed both live and
# on the card underneath (/media/root-ro, unlocked for a few seconds), then the browser restarts.
kiosk_to_v1() {
    echo ""
    echo "--- Lobby kiosk (ssh kiosk) back to V1's page ---"
    if ssh -o BatchMode=yes -o ConnectTimeout=10 kiosk "bash -s -- '$KIOSK_V1_URL'" <<'REMOTE'
set -e
U="$1"; C=/etc/fsbhoa-kiosk/kiosk.conf; L=/media/root-ro$C
want="KIOSK_URL=\"$U\""
files=("$C"); mountpoint -q /media/root-ro && files+=("$L")
todo=0; for f in "${files[@]}"; do grep -qxF "$want" "$f" || todo=1; done
if [ "$todo" = 0 ]; then echo "   already on V1's page: no change"; exit 0; fi
sudo sed -i "s|^KIOSK_URL=.*|$want|" "$C"
if [ "${#files[@]}" = 2 ]; then
    trap 'sudo mount -o remount,ro /media/root-ro' EXIT
    sudo mount -o remount,rw /media/root-ro
    sudo sed -i "s|^KIOSK_URL=.*|$want|" "$L"
    sync
fi
for f in "${files[@]}"; do grep -qxF "$want" "$f" || { echo "   $f not changed"; exit 1; }; done
sudo systemctl restart kiosk-browser
echo "   KIOSK_URL set; browser restarted"
for i in $(seq 1 12); do
    sleep 5
    sudo kiosk-watchdog --check 2>/dev/null | grep -q "page connected" && { echo "   page connected"; exit 0; }
done
echo "   page not connected after 60 s"; exit 1
REMOTE
    then
        echo "KIOSK: OK"
    else
        echo "KIOSK: PROBLEM. By hand (card \"Back to V1\", level 2, step 4): ssh kiosk; sudo kiosk-mode writable;"
        echo "       set $KIOSK_V1_URL as KIOSK_URL in /etc/fsbhoa-kiosk/kiosk.conf; sudo kiosk-mode readonly"
    fi
}
if [ "$KIOSK_ONLY" = 1 ]; then kiosk_to_v1; exit 0; fi
[ "$(id -u)" -ne 0 ] || fail "run this as the user the services run as, not as root"
[ -x "$CORE/cutover-v2.sh" ] || fail "$CORE/cutover-v2.sh not found"

B="$(ls -1dt "$HOME"/cutover_backup_* 2>/dev/null | while read -r d; do [ -s "$d/database.sql" ] && { echo "$d"; break; }; done)"
[ -n "$B" ] || fail "no cutover backup with a database.sql in $HOME (cutover_backup_<time>)"
taken="$(date -r "$B/database.sql" '+%Y-%m-%d %H:%M')"
age=$(( ( $(date +%s) - $(date -r "$B/database.sql" +%s) ) / 3600 ))

echo "BACK TO V1"
echo "  Backup:  $B"
echo "  Taken:   $taken ($age hours ago). Everything recorded since then is LOST."
echo "  Then:    V1 card sync to the door controllers: $SYNC"
if [ "$YES" = 0 ]; then
    [ -t 0 ] || fail "no terminal to confirm on (use --yes)"
    read -r -p "Go back to V1 now? [y/N] " answer
    case "$answer" in y|Y|yes|YES) ;; *) fail "not confirmed" ;; esac
fi

start=$(date +%s)
"$CORE/cutover-v2.sh" rollback "$B" --yes "${REHEARSAL[@]}" || fail "the rollback stopped (see $B/rollback.log). Run it again, or: $CORE/cutover-v2.sh rollback $B"
echo ""
echo "--- Rollback done in $(( $(date +%s) - start )) s ---"
[ "$KIOSK" = 1 ] && kiosk_to_v1

if [ "$SYNC" = none ]; then
    echo "Card sync skipped (--no-sync). In V1: Access Control > sync page > Sync Now."
    exit 0
fi

echo ""
echo "--- V1 card sync to the door controllers ($SYNC) ---"
active="$(wp --path="$WP_DIR" plugin list --status=active --field=name 2>/dev/null | grep -x fsbhoa-access-control)"
[ -n "$active" ] || fail "V1 (fsbhoa-access-control) isn't active after the rollback; sync by hand in V1"
dry="$(wp --path="$WP_DIR" --skip-plugins option get fsbhoa_ac_sync_dry_run 2>/dev/null)"
[ "$dry" = on ] && echo "NOTE: V1's sync dry-run setting is ON: nothing will be written to the controllers."
before=$(sudo stat -c %s "$LOG" 2>/dev/null || echo 0)
fn=fsbhoa_perform_nightly_rebuild_sync
[ "$SYNC" = wipe ] && fn=fsbhoa_perform_full_wipe_rebuild
# As the web server, as V1's own syncs run (its temp files and uhppote-cli's config)
sudo -u www-data env WP_CLI_CACHE_DIR=/tmp/wp-cli-cache-www-data wp --path="$WP_DIR" eval "if ( ! function_exists( '$fn' ) ) { echo 'missing $fn'; exit( 1 ); } $fn();" 2>&1 | grep -v -i deprecated
rc=${PIPESTATUS[0]}
lines="$(sudo tail -c +$(( before + 1 )) "$LOG" 2>/dev/null | grep -E 'NIGHTLY REBUILD|SYNC SUCCESS|SYNC WARNING|SYNC FATAL|SYNC FAILED|DRY RUN')"
echo "$lines" | sed 's/^/   /'
ok=$(printf '%s\n' "$lines" | grep -c 'SYNC SUCCESS')
bad=$(printf '%s\n' "$lines" | grep -c -E 'SYNC FATAL|SYNC FAILED')
echo ""
if [ "$rc" -ne 0 ] || [ "$bad" -gt 0 ] || [ "$ok" -eq 0 ]; then
    echo "CARD SYNC: PROBLEM ($ok controller(s) loaded, $bad failure(s)). V1 is back; sync again in V1"
    echo "(Access Control > sync page > Force Full Rebuild) or: $0 --no-sync first, then that page."
    exit 2
fi
echo "CARD SYNC: OK, $ok controller(s) loaded. V1 is back in $(( $(date +%s) - start )) s."
echo "Test a badge at each pedestrian gate. The vehicle gates keep what RAM has (level 1 if needed)."
