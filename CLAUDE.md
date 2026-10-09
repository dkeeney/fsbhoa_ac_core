# CLAUDE.md

## ⚠️ This is the PRODUCTION server

- Host: `access.fsbhoa.com` (192.168.42.98), on-site at the Lodge. Runs the **V1** software that controls the real HOA gates.
- V2 is being developed separately on **testbed.fsbhoa.com** — experimental work belongs there, not here.
- **Do not change code, the database, controller state, or services without coordinating with David first.** Read-only investigation (reading code/logs, `SELECT` queries, `uhppote-cli get-*` commands) is fine.
- **The WordPress plugin is LIVE from this git checkout**: `/var/www/html/wp-content/plugins/fsbhoa-access-control` is a symlink to `/home/fsbhoa/fsbhoa_ac/wordpress_plugin`. Any edit to a PHP file takes effect immediately for the next request / cron run. Uncommitted changes in the working tree are running in production.
- **Git remote hazard:** `origin` is `github.com:dkeeney/fsbhoa_ac`, but that repo was renamed to `fsbhoa_ac_core` for V2 and GitHub redirects the old name. Its `main` is now V2 code. **Never `git pull`/`fetch`+merge/`push` here** — a pull would deploy V2 into production (the plugin is live from this checkout). A separate home for V1 is still to be set up.
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

## Working conventions (from David)

- Prefer small, targeted edits over whole-file rewrites; preserve existing comments and debug lines.
- All database I/O should check for DB errors.
- Discuss before implementing; David will say "Wait!" if he wants to talk before proceeding.
