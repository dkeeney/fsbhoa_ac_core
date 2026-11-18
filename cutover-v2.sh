#!/bin/bash
# cutover-v2.sh (FSBHOA AC Core): the one-time switch of production from V1 to V2.
#
#   check <RAM export CSV>     Changes nothing: checks this server is ready, pulls and builds V2.
#   run <RAM export CSV>       The cutover. Backs up first, to ~/cutover_backup_<time>/.
#   rollback <backup folder>   Back to V1 from that backup (the database as it was before the
#                              cutover: anything recorded since is lost).
#
#   --rehearsal   Allows the testbed (FSBHOA_AC_ENVIRONMENT testbed); leaves wp-config and the
#                 kiosk autostart alone.
#   --yes         Don't ask for confirmation.
#
# "run" does, in order: back up (database, binaries, units, kiosk autostart); maintenance mode;
# stop the services; deactivate V1; core's migration.sql; mark the import plugin's one-time
# migrations done (production's addresses were already standardized, dkeen 2026-11-15, and
# migration.sql's households replace the other, as in the rehearsal); turn DoorKing's Send to
# RAM off (on again by hand after the Legacy Keep, checklist step 3); delete the
# retired DoorKing proxy's options; set FSBHOA_AC_ENVIRONMENT to production; link and activate
# the V2 plugins; write the service configs from the settings; deploy-production.sh (installs
# and starts the services); the OFFICE System entry (the Lodge office's directory listing); the
# DoorKing import (dry run, residents, vendors); point the kiosk autostart at the kiosk plugin; end maintenance mode. Then
# it prints the steps left to do by hand (also in the backup folder, CHECKLIST.txt).
#
# Run as the user the services run as (it uses sudo itself), from anywhere.
set -euo pipefail

REPO_DIR="$HOME"
CORE="$REPO_DIR/fsbhoa_ac_core"
WP_DIR=/var/www/html
PLUGINS_DIR="$WP_DIR/wp-content/plugins"
V1_PLUGIN=fsbhoa-access-control
# repo | WordPress plugin folder (core first)
V2=(
    "fsbhoa_ac_core|fsbhoa_ac_core"
    "fsbhoa_ac_uhppote|fsbhoa_ac_uhppote"
    "fsbhoa_ac_kiosk|fsbhoa_ac_kiosk"
    "fsbhoa_ac_zebra|fsbhoa_ac_zebra"
    "fsbhoa_ac_vehicle|fsbhoa_ac_vehicle"
    "fsbhoa_ac_doorking|fsbhoa_ac_doorking"
    "fsbhoa_ac_import|fsbhoa-ac-import"
)
SERVICES=(fsbhoa_events fsbhoa_kiosk fsbhoa_monitor fsbhoa_printer fsbhoa_vehicle)
# Tables migration.sql creates: none may exist before the cutover
NEW_TABLES=(ac_households ac_vehicles ac_credential_types ac_credentials)
# The Lodge office's directory listing: RAM row OFFICE, directory code 9925 (keypad 9925# calls
# 661-873-0580; dkeen 2026-11-16). A special entry like the test cardholders: resident type
# System, at the System property, in a household of its own. Created before the DoorKing import,
# whose alias OFFICE gives it the row's directory code; the export sends it as any household.
OFFICE_PHONE=6618730580
KIOSK_AUTOSTART="$HOME/.config/autostart/start_kiosk.sh.desktop"

MODE="${1:-}"; ARG="${2:-}"
REHEARSAL=0; YES=0
for a in "${@:3}"; do
    case "$a" in
        --rehearsal) REHEARSAL=1 ;;
        --yes) YES=1 ;;
        *) echo "Unknown option $a"; exit 1 ;;
    esac
done
if [ -z "$ARG" ] || { [ "$MODE" != check ] && [ "$MODE" != run ] && [ "$MODE" != rollback ]; }; then
    sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
fi
if [ "$(id -u)" -eq 0 ]; then
    echo "Run this as the user the services run as (it uses sudo itself), not as root."
    exit 1
fi
# Tables left out of the backup. The testbed's vehicle log is gigabytes of photos, and neither
# migration.sql nor the cutover changes an existing one (production has none before V2).
BACKUP_SKIP=()
[ "$REHEARSAL" = 1 ] && BACKUP_SKIP=(ac_vehicle_log)

# wp-cli, with PHP's deprecation notices left out; keeps wp's exit status
wpq() {
    local out rc=0
    out="$(wp --path="$WP_DIR" "$@" 2>&1)" || rc=$?
    printf '%s\n' "$out" | grep -v -i 'deprecated' | sed '/^$/d' || true
    return $rc
}
wpv() { wp --path="$WP_DIR" --skip-plugins --skip-themes "$@" 2>/dev/null | grep -v -i deprecated || true; }
sql() { wpv db query --skip-column-names "$1"; }
table_exists() { [ -n "$(sql "SHOW TABLES LIKE '$1'")" ]; }
say() { echo ""; echo "--- $* ---"; }
fail() { echo "STOPPED: $*"; exit 1; }
confirm() {
    [ "$YES" = 1 ] && return 0
    [ -t 0 ] || fail "no terminal to confirm on (use --yes)"
    local answer
    read -r -p "$1 Type $2 to go on: " answer
    [ "$answer" = "$2" ] || fail "not confirmed"
}
units_present() { for s in "${SERVICES[@]}" "$@"; do [ -f "/etc/systemd/system/$s.service" ] && echo "$s"; done; return 0; }

check_server() {
    say "Checks"
    for cmd in git go wp mysqldump; do command -v "$cmd" >/dev/null || fail "$cmd is not installed"; done
    [ -x "$CORE/deploy-production.sh" ] || fail "$CORE/deploy-production.sh is missing (first: git clone the core repo into $CORE)"
    local env site
    env="$(wpv config get FSBHOA_AC_ENVIRONMENT)"
    site="$(wpv option get siteurl)"
    if [ "$REHEARSAL" = 1 ]; then
        [ "$env" = testbed ] || fail "--rehearsal is only for the testbed (FSBHOA_AC_ENVIRONMENT is '${env}')"
    else
        [ "$env" = "" ] || [ "$env" = production ] || fail "FSBHOA_AC_ENVIRONMENT is '$env', not production"
        case "$site" in *testbed*) fail "$site looks like the testbed (use --rehearsal there)" ;; esac
    fi
    echo "   [OK] $site, environment '${env:-not set yet}'"
    wp --path="$WP_DIR" --skip-plugins --skip-themes plugin is-active "$V1_PLUGIN" >/dev/null 2>&1 \
        || fail "V1 ($V1_PLUGIN) isn't active: already cut over?"
    echo "   [OK] V1 is active"
    for t in "${NEW_TABLES[@]}"; do
        table_exists "$t" && fail "table $t exists: migration.sql has run already?"
    done
    echo "   [OK] the V2 tables don't exist yet"
    [ -r "$CSV" ] || fail "can't read the RAM export $CSV"
    echo "   [OK] RAM export $CSV ($(wc -l < "$CSV") lines, $(date -r "$CSV" '+%Y-%m-%d %H:%M'))"
    if grep -q '^[^,]*,"\{0,1\}OFFICE"\{0,1\},' "$CSV"; then
        echo "   [OK] RAM export has the OFFICE row: $(grep '^[^,]*,"\{0,1\}OFFICE"\{0,1\},' "$CSV" | head -1 | cut -d, -f2-6)"
    else
        echo "   [WARN] RAM export has no OFFICE row: the OFFICE vendor will have no directory code"
    fi
    local free need
    free="$(df -Pm "$HOME" | awk 'NR==2 {print $4}')"
    need="$(sql "SELECT ROUND(SUM(data_length + index_length) / 1048576) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name NOT IN ($(printf "'%s'," "${BACKUP_SKIP[@]}" "") '')")"
    need=$(( ${need:-0} * 2 + 1024 ))
    [ "$free" -gt "$need" ] || fail "only ${free} MB free in $HOME; the backup needs about ${need} MB"
    echo "   [OK] ${free} MB free (the backup needs about ${need} MB)"
    say "Pull and build V2 (while V1 is active, deploy-production.sh only builds)"
    "$CORE/deploy-production.sh"
}

backup() {
    B="$HOME/cutover_backup_$(date +%Y%m%d_%H%M)"
    mkdir -m 700 "$B"
    say "Backup to $B"
    # The tables as they are now: a rollback drops any table the cutover adds
    sql "SHOW TABLES" > "$B/tables_before.txt"
    local skip=""
    if [ "${#BACKUP_SKIP[@]}" -gt 0 ]; then
        skip="--exclude_tables=$(IFS=,; echo "${BACKUP_SKIP[*]}")"
    fi
    umask 077
    wp --path="$WP_DIR" --skip-plugins --skip-themes db export "$B/database.sql" $skip >/dev/null 2>&1 \
        || fail "the database export failed (disk full?); nothing has been changed"
    umask 022
    [ -s "$B/database.sql" ] || fail "the database export is empty"
    echo "   [OK] database ($(du -h "$B/database.sql" | cut -f1))"
    if [ -n "$skip" ]; then
        echo "        without ${BACKUP_SKIP[*]}, which the cutover leaves alone"
    fi
    mkdir "$B/bin" "$B/units"
    cp -p /usr/local/bin/fsbhoa_* "$B/bin/" 2>/dev/null || true
    for u in $(units_present fsbhoa_doorking); do cp -p "/etc/systemd/system/$u.service" "$B/units/"; done
    [ -f "$KIOSK_AUTOSTART" ] && cp -p "$KIOSK_AUTOSTART" "$B/"
    wpv plugin list --status=active --field=name > "$B/active_plugins.txt"
    echo "   [OK] binaries: $(ls "$B/bin" | tr '\n' ' ')"
    echo "   [OK] units: $(ls "$B/units" | tr '\n' ' ')"
    echo "Back to V1: $CORE/cutover-v2.sh rollback $B" > "$B/README.txt"
}

checklist() {
    cat <<CHECK
Left to do by hand (fsbhoa_ac_core other_docs/ARCHITECTURE.md, "To do before the production release"):
 1. FSBHOA AC > General settings: generate the Access Verification API Key, set the Discord
    webhook, save (that rewrites the service configs). Then restart the services:
      sudo systemctl restart ${SERVICES[*]}
 2. Save the Event Service (with Enable Scheduled Sync on), Kiosk, Print Service and Vehicle
    settings pages, so their configs get the key.
 3. DoorKing: check the settings (account, To_RAM_Production). Send to RAM is OFF (this script
    turned it off): leave it off until the Keep is done. On the DoorKing Legacy page, upload the
    same RAM export ($(basename "${CSV:-the RAM export}")), check, and Keep with the default ticks
    (not in the database + archived or purged). Then Check only again: no tag or gate code may be
    listed as lost (directory codes "not sent" or "Assigned by RAM" are fine). Only then turn
    Send to RAM on in the DoorKing settings.
 4. Vehicle lanes (ARCHITECTURE.md step 10): settings-import from the testbed's export (without
    the Workbench lane), the NVR password, ffmpeg and tools/install_front_plate.sh, the FTP
    server (lpr_ftp/setup.sh), the cameras' FTP target.
 5. sudo rules for the web server (System Status page) don't cover fsbhoa_vehicle yet: add
    start/stop/restart/status fsbhoa_vehicle and its journalctl line, like the other services'.
 6. Import plugin: the fsbhoa_import_folder option, /mnt/shared/Automation/Import/production/
    and office3's export script (fsbhoa_ac_import TODO #3).
 7. Pages production doesn't have yet, in the main menu under Access Control as on the testbed:
    Vendor ([fsbhoa_vendor_management], slug vendor, after Cardholder; the print page links back
    to it) and Vehicle Log Reports ([fsbhoa_vehicle_report], slug vehicle-log).
 8. RAM PC, after step 3: back up RAM (its only undo: a push replaces all its data); dk_sync.ini
    for production (Environment=production, ExpectedHost, no Imports=0), a Live_Production Disk
    File for each account; start DK_Sync; check the first push reaches both North Gates and
    South Gates (fsbhoa_ac_doorking CLAUDE.md), then export RAM and Check only on the Legacy page.
 9. Lobby kiosk: since 2026-11-18 it is the Raspberry Pi 5 at 192.168.42.64 (ssh kiosk), not
    this server's desktop (its autostart is off: leave it off). On the Kiosk Stations page,
    register a station for 192.168.42.64 (Lobby). Then on the Pi:
      sudo kiosk-mode writable          (reboots)
      sudo nano /etc/fsbhoa-kiosk/kiosk.conf
          KIOSK_URL="https://access.fsbhoa.com:8080/"   (drop ?auto_name=LobbyKiosk: V2
          picks the station by IP address)
      sudo kiosk-mode readonly          (reboots)
    Check: sudo kiosk-watchdog --check says the page is connected, and a test swipe shows on
    the monitor at the Lobby station (fsbhoa_ac_kiosk pi/README.md).
10. Watch wp-content/debug.log, the journal (journalctl -u 'fsbhoa_*') and Discord.
BACK TO V1 (the card "Back to V1"):
  Vehicle gates wrong after a push to RAM:  ~/revert-ram-hold.sh, then restore the RAM backup
                                            on the RAM PC and Send All Data (level 1)
  Pedestrian gates, or anything else:       ~/revert-to-v1.sh  (level 2: V1, its database from
                                            ${B:-<backup folder>}, and V1's card sync to the controllers)
  After level 2, the lobby kiosk Pi:        ssh kiosk; sudo kiosk-mode writable; set KIOSK_URL back
                                            to https://access.fsbhoa.com:8080/?auto_name=LobbyKiosk
                                            in /etc/fsbhoa-kiosk/kiosk.conf; sudo kiosk-mode readonly
CHECK
}

do_run() {
    check_server
    echo ""
    confirm "This switches $(wpv option get siteurl) from V1 to V2." CUTOVER
    backup
    exec > >(tee -a "$B/cutover.log") 2>&1

    say "Maintenance mode on; stop the services; deactivate V1"
    wpv maintenance-mode activate >/dev/null || true
    for u in $(units_present fsbhoa_doorking); do sudo systemctl stop "$u"; done
    wpq plugin deactivate "$V1_PLUGIN"

    say "migration.sql"
    if ! wpq db query < "$CORE/migration.sql" > "$B/migration.log"; then
        cat "$B/migration.log"
        fail "migration.sql failed. Back to V1: $CORE/cutover-v2.sh rollback $B"
    fi
    for t in "${NEW_TABLES[@]}"; do table_exists "$t" || fail "migration.sql didn't create $t"; done
    echo "   [OK] $(sql 'SELECT COUNT(*) FROM ac_households') households, $(sql 'SELECT COUNT(*) FROM ac_credentials') credentials"

    say "Options"
    wpv option update fsbhoa_ac_address_standardization_complete yes >/dev/null
    wpv option update fsbhoa_household_migration_completed 1 >/dev/null
    echo "   [OK] the import plugin's one-time migrations marked done"
    # No export may reach RAM before the Legacy Keep (checklist step 3): one written before it
    # leaves out every legacy value (241 windshield tags and 104 gate codes in the 2026-11-16
    # rehearsal), and its trigger file waits in To_RAM_Production for the RAM PC's DK_Sync.
    # Send to RAM is on by default, so turn it off; checklist step 3 turns it on again.
    wpv option update fsbhoa_dk_send_to_ram 0 >/dev/null
    echo "   [OK] DoorKing Send to RAM off (on again after the Legacy Keep, checklist step 3)"
    for o in fsbhoa_dk_proxy_port fsbhoa_dk_gate_ip fsbhoa_dk_controller_name; do wpv option delete "$o" >/dev/null || true; done
    if [ -f /etc/systemd/system/fsbhoa_doorking.service ]; then
        sudo systemctl disable --quiet fsbhoa_doorking || true
        sudo rm -f /etc/systemd/system/fsbhoa_doorking.service /usr/local/bin/fsbhoa_doorking
        [ -f /var/lib/fsbhoa/doorking_proxy.json ] && sudo mv /var/lib/fsbhoa/doorking_proxy.json "$B/"
        sudo systemctl daemon-reload
        echo "   [OK] the retired DoorKing proxy removed"
    fi
    if [ "$REHEARSAL" = 0 ] && [ -z "$(wpv config get FSBHOA_AC_ENVIRONMENT)" ]; then
        wpv config set FSBHOA_AC_ENVIRONMENT production --type=constant >/dev/null
        [ "$(wpv config get FSBHOA_AC_ENVIRONMENT)" = production ] || fail "couldn't set FSBHOA_AC_ENVIRONMENT"
        echo "   [OK] FSBHOA_AC_ENVIRONMENT = production (wp-config.php)"
    fi

    say "Link and activate the V2 plugins"
    for r in "${V2[@]}"; do
        IFS='|' read -r repo folder <<< "$r"
        [ -L "$PLUGINS_DIR/$folder" ] || { [ -e "$PLUGINS_DIR/$folder" ] && fail "$PLUGINS_DIR/$folder is not a link"; sudo ln -s "$REPO_DIR/$repo" "$PLUGINS_DIR/$folder"; }
        wpq plugin activate "$folder"
    done

    say "Service configs, from the settings (as the web server writes them)"
    # As an admin page: the kiosk and UHPPOTE plugins load their config writers only there
    sudo -u www-data env WP_CLI_CACHE_DIR=/tmp/wp-cli-cache-www-data wp --path="$WP_DIR" \
        --exec='define( "WP_ADMIN", true );' eval '
        $p = new Fsbhoa_Ac_Settings_Page();
        $m = new ReflectionMethod( $p, "update_all_service_configs" );
        $m->setAccessible( true );
        $m->invoke( $p );' 2>&1 | grep -v -i deprecated || true
    ls -l /var/lib/fsbhoa/*.json | sed 's/^/   /'

    say "Install and start the services"
    "$CORE/deploy-production.sh" --no-pull

    say "OFFICE System entry (the Lodge office's directory listing)"
    if [ "$(sql "SELECT COUNT(*) FROM ac_cardholders WHERE last_name = 'OFFICE' AND resident_type = 'System'")" = 0 ]; then
        wpq db query "START TRANSACTION;
INSERT INTO ac_property (house_number, street_name, street_address, origin)
 SELECT '0', 'System', 'System', 'system' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ac_property WHERE street_address = 'System');
SET @prop = (SELECT MIN(property_id) FROM ac_property WHERE street_address = 'System');
INSERT INTO ac_households (household_name) VALUES ('OFFICE Household');
SET @hh = LAST_INSERT_ID();
INSERT INTO ac_cardholders (first_name, last_name, property_id, household_id, phone, phone_type, cardholder_status, cardholder_type, resident_type, origin, notes, created_at, updated_at)
 VALUES ('', 'OFFICE', @prop, @hh, '$OFFICE_PHONE', 'Office', 'active', 'resident', 'System', 'manual', 'Lodge office directory listing (RAM row OFFICE): keypad 9925# calls the office. A System entry like the test cardholders; holds the directory code, no badge.', NOW(), NOW());
UPDATE ac_households SET primary_cardholder_id = LAST_INSERT_ID() WHERE household_id = @hh;
COMMIT;"
        echo "   [OK] created"
    else
        echo "   [OK] already there"
    fi

    say "DoorKing import from $CSV"
    local imp="$REPO_DIR/fsbhoa_ac_doorking/tools/cli_import.php" dry="$B/dk_import_dry_run.log"
    wpq eval-file "$imp" "$CSV" dry residents > "$dry" || true
    echo "   dry run: skip $(grep -c '^\[SKIP' "$dry" || true), conflict $(grep -c '^\[CONFLICT' "$dry" || true), former $(grep -c '^\[FORMER' "$dry" || true), current $(grep -c '^\[CURRENT' "$dry" || true) ($dry)"
    for target in residents vendors; do
        wpq eval-file "$imp" "$CSV" real "$target" > "$B/dk_import_$target.log" || true
        echo "   $target: $(grep -o 'imported=[0-9]*' "$B/dk_import_$target.log" || echo "see $B/dk_import_$target.log")"
    done

    echo "   OFFICE holds: $(sql "SELECT IFNULL(GROUP_CONCAT(credential_type, ' ', credential_value), 'nothing: check it') FROM ac_credentials cr JOIN ac_cardholders c ON c.id = cr.cardholder_id WHERE c.last_name = 'OFFICE' AND c.resident_type = 'System'")"

    if [ "$REHEARSAL" = 0 ] && [ -f "$KIOSK_AUTOSTART" ] && grep -q "$HOME/fsbhoa_ac/start_kiosk.sh" "$KIOSK_AUTOSTART"; then
        sed -i "s|$HOME/fsbhoa_ac/start_kiosk.sh|$REPO_DIR/fsbhoa_ac_kiosk/start_kiosk.sh|" "$KIOSK_AUTOSTART"
        say "Kiosk autostart now runs $REPO_DIR/fsbhoa_ac_kiosk/start_kiosk.sh"
    fi

    wpv maintenance-mode deactivate >/dev/null || true
    # The panic scripts, where they are easy to find (the card "Back to V1")
    ln -sfn "$CORE/revert-ram-hold.sh" "$HOME/revert-ram-hold.sh"
    ln -sfn "$CORE/revert-to-v1.sh" "$HOME/revert-to-v1.sh"
    say "V2 is running; maintenance mode off"
    checklist | tee "$B/CHECKLIST.txt"
}

do_rollback() {
    B="$(cd "$ARG" && pwd)"
    [ -s "$B/database.sql" ] || fail "$B/database.sql is missing"
    if [ "$REHEARSAL" = 0 ]; then
        case "$(wpv option get siteurl)" in *testbed*) fail "this looks like the testbed (use --rehearsal there)" ;; esac
    fi
    confirm "This puts back V1 and the database of $(date -r "$B/database.sql" '+%Y-%m-%d %H:%M'); anything recorded since is lost." ROLLBACK
    exec > >(tee -a "$B/rollback.log") 2>&1
    say "Maintenance mode on; stop the services"
    wpv maintenance-mode activate >/dev/null || true
    for u in $(units_present); do sudo systemctl stop "$u"; done
    say "Database"
    # Drop the tables the cutover added, then load the backup (it brings back V1's active
    # plugin list too)
    [ -s "$B/tables_before.txt" ] || fail "$B/tables_before.txt is missing"
    local drop=()
    for t in $(sql "SHOW TABLES"); do
        grep -qx "$t" "$B/tables_before.txt" || drop+=("$t")
    done
    if [ "${#drop[@]}" -gt 0 ]; then
        sql "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE $(printf '`%s`,' "${drop[@]}" | sed 's/,$//'); SET FOREIGN_KEY_CHECKS = 1;" >/dev/null
        echo "   dropped: ${drop[*]}"
    fi
    wpv db import "$B/database.sql" >/dev/null
    echo "   [OK] database restored; active plugins: $(wpv plugin list --status=active --field=name | tr '\n' ' ')"
    say "Binaries and units"
    for f in "$B"/bin/*; do sudo install -o root -g root -m 755 "$f" "/usr/local/bin/$(basename "$f")"; done
    for u in $(units_present); do
        if [ ! -f "$B/units/$u.service" ]; then
            sudo systemctl disable --quiet "$u" || true
            sudo rm -f "/etc/systemd/system/$u.service"
            echo "   removed $u (not in V1)"
        fi
    done
    for f in "$B"/units/*.service; do sudo install -o root -g root -m 644 "$f" "/etc/systemd/system/$(basename "$f")"; done
    sudo systemctl daemon-reload
    for f in "$B"/units/*.service; do u="$(basename "$f" .service)"; sudo systemctl restart "$u"; echo "   $u $(systemctl is-active "$u" || true)"; done
    if [ -f "$B/$(basename "$KIOSK_AUTOSTART")" ]; then
        cp -p "$B/$(basename "$KIOSK_AUTOSTART")" "$KIOSK_AUTOSTART"
    fi
    wpv maintenance-mode deactivate >/dev/null || true
    say "V1 is back. The V2 plugin links stay (inactive); FSBHOA_AC_ENVIRONMENT stays in wp-config.php (V1 ignores it)"
}

case "$MODE" in
    check) CSV="$ARG"; check_server; echo ""; echo "Ready. The cutover: $0 run $CSV" ;;
    run) CSV="$ARG"; do_run ;;
    rollback) do_rollback ;;
esac
