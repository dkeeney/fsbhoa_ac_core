# Migration test on the testbed

A rehearsal of the production release on the testbed, using a copy of production's data taken at the same time as a fresh RAM export. It tests `migration.sql` on production's real (pre-release) tables, the DoorKing resident and vendor imports, and the DoorKing Legacy check (`fsbhoa_ac_doorking`'s CLAUDE.md, "Legacy RAM entries"). It also gives the numbers to expect at the real cutover.

Written 2026-11-10. Production: access.fsbhoa.com. Testbed: testbed.fsbhoa.com. Both keep WordPress in `/var/www/html`; run `wp` from there.

Nothing here writes to production. The dump and the RAM export only read.

## What the refresh copies, and what it keeps

The usual testbed refresh (`ARCHITECTURE.md`, "Refreshing the testbed from production") copies the `ac_*` tables, never `wp_options` or any other `wp_*` table. For this test:

| Table | Action | Why |
|---|---|---|
| `ac_controllers`, `ac_doors`, `ac_task_list`, `ac_group_permissions` | Keep the testbed's | Production's hardware; copying them points testbed syncs at production controllers. |
| `ac_vehicle_log` | Keep the testbed's | 3.7 GB of the testbed's own lane data. Production has no vehicle functions yet. |
| `ac_dk_legacy_entries`, `ac_dk_legacy_values` | Keep the testbed's (empty) | New in this release; production doesn't have them. |
| `ac_credentials`, `ac_credential_types`, `ac_households`, `ac_vehicles` | **Drop** the testbed's before loading | New in this release. `migration.sql` step 1 creates them only `IF NOT EXISTS`, so the testbed's would survive, and testbed credentials would be mixed with production's cardholder IDs. |
| Every other `ac_*` table | Replaced by production's | |

## 1. Before the trip: save what the testbed has

On the testbed:

1. **Back up the whole testbed database** and check the file isn't empty. This is the only way back.
   ```
   cd /var/www/html
   wp db export ~/testbed_before_migration_test_$(date +%Y%m%d).sql
   ls -lh ~/testbed_before_migration_test_*.sql
   ```
   Keep it in `/home/pi`, not on the NAS: it holds every resident's data and photos.
2. **Save the testbed's DoorKing credentials**, the result of the earlier imports, to compare with this test's and to re-enter the workbench test tags afterwards:
   ```
   wp db query "SELECT c.id, c.first_name, c.last_name, c.cardholder_status, cr.credential_type, cr.credential_value, cr.status
                FROM ac_credentials cr JOIN ac_cardholders c ON c.id = cr.cardholder_id
                WHERE cr.credential_type LIKE 'DK\_%' ORDER BY c.last_name, c.first_name" > ~/testbed_dk_credentials_before.tsv
   ```
3. **Note the testbed-only cardholders** that production won't have (for example System Test User #883 and Kiosk Test User #884) and anything else you set up by hand for tests: they are gone after the refresh.
4. **Check the DoorKing settings:** Send to RAM is **off**, and note the DoorKing Account Name (`WORKBENCH`) to restore later.
   ```
   wp option get fsbhoa_dk_send_to_ram
   wp option get fsbhoa_dk_account_name
   ```

## 2. At the Lodge: take both copies together

Take them one right after the other, when nobody is editing cardholders in the access control system or RAM.

1. **RAM export:** in RAM on the office PC, File → Export, CSV. Save it to the NAS as `IT_Docs\DoorKing\Backups\<yyyymmdd>_DKEXPORT.CSV`, beside `20260914_DKEXPORT.CSV`. Note the time.
2. **Production baseline counts**, read-only, for checking the migration later (production still has the old columns `card_status` and `rfid_id`). Save the output.
   ```
   ssh access.fsbhoa.com
   cd /var/www/html
   wp db tables 'ac_*' --all-tables
   wp db query "SELECT card_status, resident_type = 'Vendor' AS vendor, property_id = 480 AS lodge, COUNT(*) FROM ac_cardholders GROUP BY 1, 2, 3"
   wp db query "SELECT COUNT(*) AS badges FROM ac_cardholders WHERE rfid_id IS NOT NULL AND rfid_id != ''"
   wp db query "SELECT COUNT(DISTINCT property_id) AS properties FROM ac_cardholders WHERE property_id IS NOT NULL AND property_id != 480"
   wp db query "SELECT COUNT(*) AS lodge FROM ac_cardholders WHERE property_id = 480"
   ```
3. **Dump production's `ac_*` tables**, without the ones the testbed keeps:
   ```
   TABLES=$(wp db tables 'ac_*' --all-tables --format=csv | tr ',' '\n' \
            | grep -vxE 'ac_controllers|ac_doors|ac_task_list|ac_group_permissions|ac_vehicle_log|ac_dk_legacy_entries|ac_dk_legacy_values' \
            | paste -sd,)
   echo "$TABLES"
   wp db export ~/prod_ac_$(date +%Y%m%d_%H%M).sql --tables="$TABLES" --single-transaction
   ```
4. **Copy it to the testbed** (from the testbed), then delete it on production:
   ```
   scp access.fsbhoa.com:prod_ac_*.sql ~/
   ls -lh ~/prod_ac_*.sql
   ssh access.fsbhoa.com 'rm ~/prod_ac_*.sql'
   ```
   Keep it off the NAS paths that are copied offsite. Delete it when the test is done.

## 3. On the testbed: load production's data

1. **Stop the services that write to the database**, so nothing lands in a half-loaded table:
   ```
   sudo systemctl stop fsbhoa_events fsbhoa_vehicle fsbhoa_monitor fsbhoa_kiosk fsbhoa_printer
   ```
   Do this during the day, not near the 00:11 or 03:11 cron runs.
2. **Compare the table lists.** Every table in the dump replaces the testbed's. Any testbed `ac_*` table that is neither in the dump nor in the "keep" rows above keeps testbed data that refers to testbed cardholder IDs. Empty those (for example `ac_pending_changes`, and `ac_print_log` if production has none):
   ```
   grep -oE 'CREATE TABLE `ac_[a-z_]+`' ~/prod_ac_*.sql
   wp db tables 'ac_*' --all-tables
   ```
3. **Drop the testbed's copies of the tables new in this release:**
   ```
   wp db query "SET FOREIGN_KEY_CHECKS = 0;
                DROP TABLE IF EXISTS ac_credentials, ac_vehicles, ac_households, ac_credential_types;
                SET FOREIGN_KEY_CHECKS = 1;"
   ```
4. **Load the dump** (it drops and re-creates each table it holds):
   ```
   wp db import ~/prod_ac_<timestamp>.sql
   ```
5. **Check the load:** `ac_cardholders` should have production's old columns again.
   ```
   wp db query "SHOW COLUMNS FROM ac_cardholders LIKE 'card_status'"
   wp db query "SHOW COLUMNS FROM ac_cardholders LIKE 'rfid_id'"
   ```

## 4. Run the migration (deployment step 2)

1. **Run `migration.sql` in one go**, and time it (step 10 rebuilds `ac_access_log`):
   ```
   time wp db query < /home/pi/fsbhoa_ac_core/migration.sql
   ```
   **If it stops with an error, don't run it again.** It isn't repeatable (step 2 renames columns). Note the error, restore the backup from section 1 (`wp db import ~/testbed_before_migration_test_<date>.sql`), fix `migration.sql`, and start again from section 3.
2. **Check the result against the production baseline** (section 2, step 2):
   - Cardholders: the same total. `inactive` and `disabled` became `active`; archived vendors became `purged`.
     ```
     wp db query "SELECT cardholder_status, cardholder_type, COUNT(*) FROM ac_cardholders GROUP BY 1, 2"
     ```
   - Badges: as many `MIFARE_BADGE` rows as production had `rfid_id`s. `disabled` only where the cardholder was `disabled`.
     ```
     wp db query "SELECT credential_type, status, COUNT(*) FROM ac_credentials GROUP BY 1, 2"
     ```
   - Households: one per property plus one per Lodge (property 480) cardholder, and every cardholder with a property has one.
     ```
     wp db query "SELECT COUNT(*) AS households FROM ac_households"
     wp db query "SELECT COUNT(*) AS without_household FROM ac_cardholders WHERE property_id IS NOT NULL AND household_id IS NULL"
     ```
   - Expiry: no NULL dates.
     ```
     wp db query "SELECT COUNT(*) FROM ac_credentials WHERE expiration_date IS NULL OR expiration_date < '1000-01-01'"
     ```
   - The old columns are gone: `SHOW COLUMNS FROM ac_cardholders LIKE 'rfid_id'` returns nothing.
3. **Restart the services:**
   ```
   sudo systemctl start fsbhoa_events fsbhoa_vehicle fsbhoa_monitor fsbhoa_kiosk fsbhoa_printer
   ```
4. **Check the group permissions and tasks**, which refer to production's `ac_groups` and `ac_schedules` now (`ARCHITECTURE.md`, "After the refresh"). Re-create any that point at the wrong group or schedule, or at none.
5. **Look around the site:** the cardholder list loads with the right statuses and badge icons, a cardholder opens and saves, and the live monitor runs. Watch `wp-content/debug.log` for database errors.

## 5. DoorKing: imports and the Legacy check

The order matters: the check compares RAM's file with what the export would send after both imports.

1. **Find the file's account names:**
   ```
   tail -n +2 "/mnt/shared/IT_Docs/DoorKing/Backups/<yyyymmdd>_DKEXPORT.CSV" | cut -d, -f1 | sort | uniq -c
   ```
   Shared accounts have the same number of rows each.
2. **Set the DoorKing Account Name** (DoorKing settings) to one of them, e.g. `NORTH GATE NEW`. Send to RAM stays **off**, so nothing is sent. The banner shows pending changes, which is expected.
3. **Resident import** (DoorKing Backfill, Residents): first a dry run with "Exceptions Only Log", and save the log (the not-found list). Then run it for real and note the count.
4. **Vendor import** (DoorKing Backfill, Vendors & Contractors): a dry run, save the log, then for real.
5. **DoorKing Legacy → Check a RAM export**, Check only, with the same file. Save the counts:
   - RAM rows and values; next push rows, values and card slots (under 3000)
   - not sent, by reason
   - new values
   - "Only in other accounts": should be none, since the accounts are shared.
6. **Review "Not sent: Not in the database".** Where an alias in the importers would place a value on the right cardholder, add the alias and re-run that import (section 5 steps 3–4) rather than keeping it as legacy. Every alias added here also applies at the real cutover.
7. **Keep** with the default (Not in the database). The page checks again. Only what you chose to let go should be listed: archived owners' tags, disabled or expired credentials, test-account rows.
8. **Look at the legacy list:** the entries look like real people or vendors, and "Last used" shows recent use for some (the testbed's vehicle log has production's lanes since October).
9. **Compare with the earlier testbed import:**
   ```
   wp db query "SELECT c.id, c.first_name, c.last_name, c.cardholder_status, cr.credential_type, cr.credential_value, cr.status
                FROM ac_credentials cr JOIN ac_cardholders c ON c.id = cr.cardholder_id
                WHERE cr.credential_type LIKE 'DK\_%' ORDER BY c.last_name, c.first_name" > ~/testbed_dk_credentials_after.tsv
   wc -l ~/testbed_dk_credentials_before.tsv ~/testbed_dk_credentials_after.tsv
   ```
   Differences beyond what RAM changed since September point at the importers.
10. **Set the DoorKing Account Name back** to `WORKBENCH`.

## 6. The other plugins (optional, as time allows)

- **UHPPOTE:** run the full rebuild to the testbed controllers and check its log for `timed out`, `had issues`, `SYNC FAILED` and `did not answer`.
- **Kiosk:** a badge from the copied data opens the right cardholder.
- **Vehicle:** the live monitor's vehicle cards name production's residents for windshield tags and gate codes.

## 7. Afterwards

1. **Write down the numbers** (production baseline, migration counts, import counts, legacy counts, migration time) for the cutover, where the same steps run on production. Any gap between the two runs should be explained by changes made in between.
2. **Re-create the testbed's test data:** the test cardholders and workbench windshield tags from section 1 (the workbench reader's tags are in `testbed_dk_credentials_before.tsv`).
3. **Delete the copies holding resident data** once they're no longer needed: `~/prod_ac_*.sql`, `~/testbed_dk_credentials_*.tsv`, and the testbed backup when you're sure you won't go back.
4. **Report** what failed or surprised you, so `migration.sql`, the importers or this checklist can be fixed before the real release.
