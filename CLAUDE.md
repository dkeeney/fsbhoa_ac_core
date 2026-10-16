# CLAUDE.md

## ⚠️ This is the PRODUCTION server

- Host: `access.fsbhoa.com` (192.168.42.98), on-site at the Lodge. Runs the **V1** software that controls the real HOA gates.
- V2 is being developed separately on **testbed.fsbhoa.com** — experimental work belongs there, not here.
- **Do not change code, the database, controller state, or services without coordinating with David first.** Read-only investigation (reading code/logs, `SELECT` queries, `uhppote-cli get-*` commands) is fine.
- **The WordPress plugin is LIVE from this git checkout**: `/var/www/html/wp-content/plugins/fsbhoa-access-control` is a symlink to `/home/fsbhoa/fsbhoa_ac/wordpress_plugin`. Any edit to a PHP file takes effect immediately for the next request / cron run. Uncommitted changes in the working tree are running in production.
- **Git: V1 lives on the `v1-production` branch** of `github.com:dkeeney/fsbhoa_ac_core` (the old `fsbhoa_ac` repo was renamed for V2). That repo's `main` is **V2 code** — never merge, pull, or check out `main` here, and never merge `v1-production` into `main`: the plugin is live from this checkout, so V2 code here would deploy it to production. `origin` is configured to fetch and push only `v1-production`; keep it that way. V2 split from V1 at commit `2261e18`.
- Never run commands that write to the controllers (`delete-all`, `put-card`, `load-acl`, `set-*`, `clear-*`, `restore-default-parameters`, `open`, …) unless David explicitly asks for that specific command.

## Architecture

`[Browser] <=> [WordPress plugin (PHP, MySQL)] <=> [REST/WebSocket] <=> [Go services] <=> [UHPPOTE controllers / printer]`

- `wordpress_plugin/` — admin UI and source of truth (DB `fsbhoa_db`, tables `ac_*`). Pushes config to controllers by shelling out to `uhppote-cli` (v0.8.12, config `/etc/uhppoted/uhppoted.conf`).
- `event_service/` — Go; listens for controller events, WebSocket hub for the live monitor. Binary `fsbhoa_events`.
- `monitor_service/`, `kiosk/`, `zebra_print_service/` — Go services (`fsbhoa_monitor`, `fsbhoa_kiosk`, `fsbhoa_printer`).
- Services run under systemd (`fsbhoa_events`, `fsbhoa_monitor`, `fsbhoa_kiosk`, `fsbhoa_printer`; `fsbhoa-lighting` is a separate project). Binaries live in `/usr/local/bin`; generated JSON configs in `/var/lib/fsbhoa/`.
- Build: `./rebuild.sh` (build only) / `./rebuild.sh install` (installs + restarts services — production-impacting).

## Card sync (uhppote)

- `includes/uhppote/fsbhoa-uhppote-sync-service.php` — `fsbhoa_execute_sync_logic()`: per controller → set-time, (optional wipe), time profiles, door delays, cards, tasks.
- `includes/class-fsbhoa-permission-compiler.php` — turns groups/permissions/schedule into time profiles and per-card door permission strings.
- `includes/uhppote/fsbhoa-uhppote-bulk-sync.php` — writes a temp `.conf` + `.tsv` in `wp-content/uploads/fsbhoa_ac/` and runs `uhppote-cli load-acl`, with a retry loop.
- Triggers: manual "Sync Now" (delta, no wipe) and WP-cron nightly rebuild at 00:10 PT (wipe ON), plus daily time sync 03:10 PT. System crontab hits `wp-cron.php` at 00:11 and 03:11.
- Controllers: South Gate 425045111 (.50), North Gate 425043833 (.51), Lodge A 425045084 (.52), Lodge B 425049931 (.55). 900000/900001 are virtual (not UHPPOTE).
- Logs: `error_log()` goes to `/var/www/html/wp-content/debug.log` (large; grep it, don't cat it). Grep for `SYNC SUCCESS|SYNC WARNING|SYNC FATAL|NIGHTLY REBUILD`.
- Read-only diagnostics: `uhppote-cli get-card <controller> <card>`, `get-cards <controller>` (slow, ~1–2 min per controller), `get-time-profiles <controller>`.

## Backported from V2 (2026-10-16)

Cards were sometimes denied ("No Permissions") until a later sync; the cause isn't known yet (fsbhoa_ac_uhppote TODO #28 on the V2 side). These changes add evidence and tighten the card load:

- **Card loader `fsbhoa_load_acl`** (`acl_loader/`, built by `rebuild.sh`, installed to `/usr/local/bin`). The bulk sync uses it instead of `uhppote-cli load-acl` when it's installed (same arguments, TSV and summary line). It paces requests, checks each time profile once, retries a rejected card, and prints two diagnostic lines that end up in the `SYNC SUCCESS`/`SYNC RESORT` log lines: `table before the load:` (cards, last slot, gaps, cards out of order) and `changes:` (card numbers added, updated, deleted).
- **Re-sort after updates too.** The controller moves a changed card to the end of its table (old slot marked deleted), so any load that changes a table that had cards now wipes and reloads it in order, not only one that adds or deletes cards.
- **Every wipe checks the controller is empty** (`Fsbhoa_Uhppote_Bulk_Sync::wipe_cards()`, used by the recovery wipe and the nightly wipe): `fsbhoa_load_acl --config /etc/uhppoted/uhppoted.conf --count <serial>` after the 4 s settle, and `delete-all` again if any card is left (it often leaves the card in slot 1), up to 3 times.
- **Denial check** (`event_service/denial_check.go`). When a controller denies a card with "no access rights" (reason 6), the event service looks the card up at once and logs a `DENIAL CHECK:` line in the event service log (`logFile` in `/var/lib/fsbhoa/event_service.json`, on production `/home/fsbhoa/fsbhoa_ac/event_service/event.log`; `grep "DENIAL CHECK"` it): found (with the door's setting), `NO RECORD` but stored in slot N (the controller can't find a card it holds), or `NO RECORD` and not stored (missing). V1 has no controller locks, so a check may overlap a sync; compare with the sync log.

To deploy: `git pull` here (the PHP is live at once), then build and install only the event service and the loader (`cd event_service && go build -o fsbhoa_events .`, `cd acl_loader && go build -o fsbhoa_load_acl .`, copy both to `/usr/local/bin`, `sudo systemctl restart fsbhoa_events`). Until the loader is installed, the bulk sync falls back to `uhppote-cli load-acl` and skips the empty check, with a log line.

## Working conventions (from David)

- Prefer small, targeted edits over whole-file rewrites; preserve existing comments and debug lines.
- All database I/O should check for DB errors.
- Discuss before implementing; David will say "Wait!" if he wants to talk before proceeding.
