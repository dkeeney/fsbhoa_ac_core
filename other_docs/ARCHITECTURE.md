# FSBHOA Access Control — Shared Architecture & Conventions

Common guidance for the core plugin and all extension plugins. Each repo's `CLAUDE.md` imports this file with `@/home/pi/fsbhoa_ac_core/other_docs/ARCHITECTURE.md`; keep repo-specific details in that repo's `CLAUDE.md`, not here.

## System overview

An HOA access-control system: cardholders, vendors, properties, households/vehicles, schedules & permission groups, live gate monitor, reports, archiving. WordPress/MySQL is the source of truth; Go services talk to hardware and push real-time events.

`[Browser] <=> [WordPress/PHP] <=> [REST fsbhoa/v1] <=> [Go services] <=> [Hardware]`

## Core vs. extension plugins

All repos live in `/home/pi/` and are symlinked into `/var/www/html/wp-content/plugins/`, so PHP/JS edits are live immediately.

- `fsbhoa_ac_core` — core plugin (cardholder/vendor/property management, monitor, reports, schedules)
- Extensions: `fsbhoa_ac_uhppote`, `fsbhoa_ac_doorking`, `fsbhoa_ac_import`, `fsbhoa_ac_kiosk`, `fsbhoa_ac_zebra`, `fsbhoa_ac_vehicle`

Rules (from `fsbhoa_ac_core/fsbhoa-ac-core.php`):
1. **Core must never reference any extension plugin** — no class names, no hardware specifics.
2. Extensions may depend on core.
3. **Extensions must never depend on another extension plugin.** Each extension is self-contained and relies only on core. If two extensions need the same thing, put it in core (or a core hook); otherwise each extension handles it itself. For example, `fsbhoa_ac_vehicle` can't get the DoorKing live event feed through `fsbhoa_ac_doorking`, so it may receive the feed directly.

Core exposes extension points via WordPress hooks; extensions implement them. When an extension needs new behavior from core, add a `do_action`/`apply_filters` hook to core rather than having core call plugin code. Examples:
- Lifecycle events: `fsbhoa_core_cardholder_created/updated/restored`, `fsbhoa_core_cardholders_merged`, `fsbhoa_core_cardholder_household_changed`, `fsbhoa_core_vehicle_saved/deleted`, `fsbhoa_core_group_saved`, `fsbhoa_core_amenities_changed`, `fsbhoa_pending_change_{type}`
- Hardware abstraction filters: `fsbhoa_hardware_set_door_state`, `fsbhoa_hardware_map_event_data`, `fsbhoa_hardware_group_status`, `fsbhoa_validate_credentials`
- Vehicle UI/validation: `fsbhoa_vehicle_table_head`, `fsbhoa_vehicle_table_row_columns`, `fsbhoa_is_vehicle_row_empty`, `fsbhoa_validate_vehicle_row`
- UI injection: `fsbhoa_render_credential_fields`, `fsbhoa_monitor_map_gates` (remove doors from the live monitor map and map editor), `fsbhoa_register_admin_submenus`, `fsbhoa_hardware_management_view_{view}`, `fsbhoa_render_hardware_task_lists`, `fsbhoa_cardholder_list_status_icons`, `fsbhoa_cardholder_list_action_icons`, `fsbhoa_enqueue_cardholder_assets`
- Services/config: `fsbhoa_system_services`, `fsbhoa_update_service_configs`

Cron hooks `fsbhoa_run_nightly_rebuild` and `fsbhoa_run_daily_time_sync` are **handled in extension plugins** (e.g. `fsbhoa_ac_uhppote/includes/fsbhoa-uhppote-sync-service.php`). Two things are needed for them to run:
- **WP-Cron schedule.** `fsbhoa_ac_uhppote` schedules both hooks (`fsbhoa_schedule_sync_events()`, on `init`) at 00:10 and 03:10 local time. It reschedules them if they drift by more than 10 minutes, such as after a DST change. Core does not schedule them.
- **System crontab.** WP-Cron runs only when a request reaches the site, so each server needs a crontab entry that requests `wp-cron.php` just after those times (00:11 and 03:11). The example is in the docblock of `fsbhoa_schedule_sync_events()`. The V2 permission compiler / delta-sync design is documented in `fsbhoa_ac_core/includes/fsbhoa-permissions-V2.md`.

## Environment separation (testbed vs. production)

The testbed (`testbed.fsbhoa.com`) and production share infrastructure such as the NAS and outside services. A testbed test must never reach production hardware or systems, and vice versa.

- **`FSBHOA_AC_ENVIRONMENT`** is defined in each server's `wp-config.php` as `'testbed'` or `'production'`. It is deliberately **not** a WordPress option, so it stays with the server and never travels with a database copy.
- All other settings stay in the FSBHOA AC dashboard settings (WordPress options). This is safe because refreshing the testbed copies only `ac_*` tables from production, never `wp_options` or any other `wp_*` table.
- Controller addresses (shared 192.168.42.x subnet): **testbed** .53 and .54; **production** .50, .51, .52 and .55.
- Any code that could affect real hardware, shared folders, or outside systems must check the environment first and **fail closed**: if the constant is missing or unrecognized, do nothing and log why.
- The rule is that the testbed must not *affect* production. Read-only use of production hardware is allowed when duplicating it isn't practical, as long as reading can't change how production behaves. Example: the testbed reads the production LPR cameras (see `fsbhoa_ac_vehicle`'s `CLAUDE.md`).
- Anything sent to another system (files, API payloads) should say which environment and host it came from, so the receiver can check it or keep the two apart.
- Implemented so far: `fsbhoa_ac_doorking` (RAM sync and vendor-code rotation). See that repo's `CLAUDE.md`.

### Refreshing the testbed from production

Copy the `ac_*` tables **except** these, which describe production's hardware and must stay as the testbed has them:

- `ac_controllers`
- `ac_doors`
- `ac_task_list`
- `ac_group_permissions`

Copying them would point testbed syncs at production's controllers. `uhppote-cli` finds a controller by its serial number and falls back to broadcast, so a production serial in the testbed database reaches the production controller.

After the refresh:
- **Check the testbed's group permissions and tasks.** `ac_group_permissions` refers to `ac_groups` and `ac_schedules`, and `ac_task_list` to `ac_schedules`. Those two are copied from production (cardholder memberships need production's group IDs), so the kept rows may now point at the wrong group or schedule, or at none. Re-create them where needed.
- **Expect unknown doors in testbed reports.** Copied `ac_access_log` rows name production controllers.

### To do before the production release

- **Deployment steps for the 2026-10 testbed work** (until `~/deploy-production.sh` is rewritten; it still calls the deleted `deploy_uhppote.sh`):
  1. Back up the production database.
  2. Run `fsbhoa_ac_core/migration.sql`, including steps 7–10 (TEST door role; cardholder statuses; `REGRESSION_TEST` controller type; unsigned door numbers). Step 10 rebuilds `ac_access_log`, which may take a moment.
  3. Deploy all plugins together. Core and uhppote depend on each other (gate task handler moved, card status column), and the kiosk uses core's key.
  4. In core General settings, generate the Access Verification API Key and save. Save the Event Service, DoorKing, kiosk and monitor settings so their JSON configs get the key.
  5. Rebuild and install the event service (`fsbhoa_ac_uhppote/build.sh install`), install the DoorKing proxy and monitor binaries, and restart all the services.
  6. Check "Enable Scheduled Sync" in the Event Service settings (on for production), restart the event service, and check the crontab requests `wp-cron.php` at 00:11 and 03:11.
  7. Expect the first DoorKing export to add residents with no photo badge or a disabled one.
  8. Watch the sync log (`wp-content/debug.log`) for `timed out`, `had issues`, `SYNC FAILED` and `did not answer`, and the Discord channel for sync alerts.
- **Migration script to set `FSBHOA_AC_ENVIRONMENT` on production.** Production's `wp-config.php` must get `define( 'FSBHOA_AC_ENVIRONMENT', 'production' );` as part of deployment, not by hand.
  - It belongs with the deployment tooling (`~/deploy-production.sh` or this core plugin).
  - It must be idempotent: add the constant only if it's missing, and never overwrite an existing value.
  - It must refuse to run on the testbed.
  - Until the constant is set, environment-guarded features fail closed in production. That's safe, but they won't work.
- **Test `migration.sql` steps 4 and 8 and the import with the new cardholder statuses** (2026-10-08 change: no `inactive` or `disabled` cardholders, badges migrated as `active` or `disabled`). The testbed data was updated by hand, not by running the migration. The first DoorKing export after the migration will add residents without a photo badge, or with a disabled one.
- Consider a shared helper in core (for example `fsbhoa_ac_environment()`) and showing the environment on the main FSBHOA AC settings page, so extensions stop reading the constant themselves.

## Shared conventions

- REST endpoints use namespace `fsbhoa/v1`.
- **REST authentication.** Services calling WordPress's REST API (event services, DoorKing proxy, monitor service, kiosk service, lighting) send the **Access Verification API Key** (`fsbhoa_ac_verify_api_key`, General settings) as `X-API-KEY`. Routes check it with `Fsbhoa_Verification_REST_API::api_key_permission_check`. Our services' config generators copy the key into their JSON configs on settings save; restart the services after a new key is generated. Routes used by the browser require a logged-in admin, and the page's `fetch()` calls send `X-WP-Nonce` (`wp_create_nonce('wp_rest')`). No route uses `__return_true`. Extension plugins' service routes use the same key and check (the kiosk does; the vehicle plugin's Shared Ingest Secret is still to be replaced, see its TODO.md).
- **Cardholder status** (`ac_cardholders.cardholder_status`) says only whether someone is current: `active`, `archived` or `purged`. There is no `inactive` or `disabled` cardholder. Vendors are never archived (there is no restore or merge for them); they go straight to `purged`. Use `cardholder_status = 'active'` for "current".
- **Credentials carry their own status** (`ac_credentials.status`). No badge means no `MIFARE_BADGE` row. A `disabled` badge means no amenity access (UHPPOTE and kiosk), while the cardholder's DoorKing credentials keep working.
- **Only active cardholders are pushed** to the UHPPOTE controllers and DoorKing. Archiving or purging doesn't change credential status, so a restore brings credentials back as they were (a disabled badge stays disabled). On restore, a badge whose number now belongs to another current cardholder is removed. Any query that pushes credentials must join `ac_cardholders` and check `cardholder_status = 'active'`.
- Database tables are unprefixed `ac_*` tables (not `$wpdb->prefix`), accessed directly via `$wpdb`.
- Go services read JSON config from `/var/lib/fsbhoa/`. These files are **generated by WordPress settings pages** — change the settings code, not the JSON. Binaries are named `fsbhoa_<service>`, installed to `/usr/local/bin`, and run as systemd units of the same name.
- No automated test suite; verify with `php -l`, `go build`/`go vet`, and manual testing in the running site.

## Working preferences

- Prefer **small, targeted edits** over full-file rewrites; preserve existing comments and debug/`error_log` lines.
- Any code shown or written must be complete — no placeholders or elided sections.
- Avoid hardcoded values and redundancy; keep modules decoupled so fixing one area doesn't break another.
- Style: 4-space indentation, UTF-8, final newline (`.editorconfig`).
